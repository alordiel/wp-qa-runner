<?php
/**
 * Result persistence: the intersection of a run and a case.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Repository;

use QARunner\Install\Schema;
use QARunner\Support\Dates;
use QARunner\Support\Enum;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes qa_results.
 *
 * One row exists per run/case pair from the moment the run is created; nothing here
 * creates results lazily.
 */
final class ResultRepository extends BaseRepository {

	/**
	 * A soft lock older than this is treated as stale and ignored on read.
	 */
	public const LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return 'results';
	}

	/**
	 * Creates untested rows for every case in a run.
	 *
	 * @param int   $run_id   Run identifier.
	 * @param int[] $case_ids Case identifiers.
	 * @return void
	 */
	public function seed( int $run_id, array $case_ids ): void {
		foreach ( $case_ids as $case_id ) {
			$this->db()->insert(
				$this->table(),
				array(
					'run_id'  => $run_id,
					'case_id' => $case_id,
					'status'  => 'untested',
				),
				array( '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Finds one result.
	 *
	 * @param int $id Result identifier.
	 * @return array<string, mixed>|null Raw row.
	 */
	public function find_raw( int $id ): ?array {
		$table = $this->table();

		$row = $this->db()->get_row(
			$this->db()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ?? null;
	}

	/**
	 * Finds the result for one case in one run.
	 *
	 * @param int $run_id  Run identifier.
	 * @param int $case_id Case identifier.
	 * @return array<string, mixed>|null Raw row.
	 */
	public function find_by_run_case( int $run_id, int $case_id ): ?array {
		$table = $this->table();

		$row = $this->db()->get_row(
			$this->db()->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND case_id = %d", $run_id, $case_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ?? null;
	}

	/**
	 * The working list for a run: case data, result, comment count and open issue count.
	 *
	 * This is the hot path, so it is one query plus the run's case ordering. Case steps and
	 * expected HTML are deliberately excluded — the detail view fetches those.
	 *
	 * @param int $run_id Run identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_run( int $run_id ): array {
		$sql = $this->api_select() . '
				WHERE r.run_id = %d
				ORDER BY s.sort_order ASC, s.name ASC, rc.sort_order ASC, c.title ASC';

		$rows = $this->db()->get_results(
			$this->db()->prepare( $sql, $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'to_array' ), $rows ?? array() );
	}

	/**
	 * Re-reads one result in exactly the shape the list view uses.
	 *
	 * Sharing the SELECT with for_run() is the point: a status write returns a row the
	 * client can drop straight into the list without a second, different code path.
	 *
	 * @param int $id Result identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_for_api( int $id ): ?array {
		$row = $this->db()->get_row(
			$this->db()->prepare( $this->api_select() . ' WHERE r.id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $this->to_array( $row ) : null;
	}

	/**
	 * The shared SELECT and FROM clauses behind for_run() and find_for_api().
	 *
	 * Case steps and expected HTML are deliberately absent: shipping the full body of forty
	 * cases in the list payload is the difference between a fast screen and a slow one.
	 *
	 * @return string SQL fragment with no placeholders of its own.
	 */
	private function api_select(): string {
		$results   = $this->table();
		$cases     = Schema::table( 'cases' );
		$suites    = Schema::table( 'suites' );
		$comments  = Schema::table( 'comments' );
		$issues    = Schema::table( 'issues' );
		$run_cases = Schema::table( 'run_cases' );

		return "SELECT r.id, r.run_id, r.case_id, r.status, r.tested_by, r.tested_at,
					   r.in_progress_by, r.in_progress_at,
					   c.title, c.priority, c.is_active,
					   s.id AS suite_id, s.name AS suite_name, s.sort_order AS suite_sort,
					   rc.sort_order AS case_sort,
					   ( SELECT COUNT(*) FROM {$comments} cm WHERE cm.result_id = r.id ) AS comment_count,
					   ( SELECT COUNT(*) FROM {$issues} i WHERE i.case_id = r.case_id AND i.status = 'open' ) AS open_issue_count
				FROM {$results} r
				INNER JOIN {$cases} c ON c.id = r.case_id
				INNER JOIN {$suites} s ON s.id = c.suite_id
				LEFT JOIN {$run_cases} rc ON rc.run_id = r.run_id AND rc.case_id = r.case_id";
	}

	/**
	 * Sets a result status.
	 *
	 * Clearing back to untested drops the tester attribution too, so the row reads as
	 * genuinely untouched rather than as tested-then-blanked.
	 *
	 * @param int    $id      Result identifier.
	 * @param string $status  One of Enum::RESULT_STATUSES.
	 * @param int    $user_id Tester identifier.
	 * @return bool
	 */
	public function set_status( int $id, string $status, int $user_id ): bool {
		$status = Enum::coerce( $status, Enum::RESULT_STATUSES, 'untested' );

		if ( 'untested' === $status ) {
			$fields = array(
				'status'    => 'untested',
				'tested_by' => null,
				'tested_at' => null,
			);
		} else {
			$fields = array(
				'status'    => $status,
				'tested_by' => $user_id,
				'tested_at' => Dates::now(),
			);
		}

		return false !== $this->db()->update(
			$this->table(),
			$fields,
			array( 'id' => $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Takes or refreshes the soft lock on a result.
	 *
	 * The lock is passive: it drives a "someone is testing this" label and never prevents a
	 * second tester from submitting.
	 *
	 * @param int $id      Result identifier.
	 * @param int $user_id Tester identifier.
	 * @return bool
	 */
	public function lock( int $id, int $user_id ): bool {
		return false !== $this->db()->update(
			$this->table(),
			array(
				'in_progress_by' => $user_id,
				'in_progress_at' => Dates::now(),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Releases the soft lock, but only if the caller holds it.
	 *
	 * @param int $id      Result identifier.
	 * @param int $user_id Tester identifier.
	 * @return bool
	 */
	public function unlock( int $id, int $user_id ): bool {
		return false !== $this->db()->update(
			$this->table(),
			array(
				'in_progress_by' => null,
				'in_progress_at' => null,
			),
			array(
				'id'             => $id,
				'in_progress_by' => $user_id,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Deletes every result for a run/case pair.
	 *
	 * @param int $run_id  Run identifier.
	 * @param int $case_id Case identifier.
	 * @return bool
	 */
	public function delete_by_run_case( int $run_id, int $case_id ): bool {
		return (bool) $this->db()->delete(
			$this->table(),
			array(
				'run_id'  => $run_id,
				'case_id' => $case_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Outstanding untested counts per user, for the daily digest.
	 *
	 * Only open runs the user is assigned to are considered.
	 *
	 * @return array<int, array<int, array<string, mixed>>> user_id => list of run summaries.
	 */
	public function outstanding_by_assignee(): array {
		$results   = $this->table();
		$runs      = Schema::table( 'runs' );
		$assignees = Schema::table( 'run_assignees' );

		$sql = "SELECT a.user_id, run.id AS run_id, run.name AS run_name, run.environment,
					   COUNT(r.id) AS remaining
				FROM {$assignees} a
				INNER JOIN {$runs} run ON run.id = a.run_id AND run.status = 'open'
				INNER JOIN {$results} r ON r.run_id = run.id AND r.status = 'untested'
				GROUP BY a.user_id, run.id, run.name, run.environment
				HAVING remaining > 0
				ORDER BY a.user_id ASC, run.created_at DESC";

		$rows = $this->db()->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$out[ (int) $row['user_id'] ][] = array(
				'run_id'      => (int) $row['run_id'],
				'run_name'    => (string) $row['run_name'],
				'environment' => (string) $row['environment'],
				'remaining'   => (int) $row['remaining'],
			);
		}

		return $out;
	}

	/**
	 * Casts a raw joined row to the API shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function to_array( array $row ): array {
		$tested_by = null;

		if ( ! empty( $row['tested_by'] ) ) {
			$user      = get_userdata( (int) $row['tested_by'] );
			$tested_by = array(
				'id'   => (int) $row['tested_by'],
				'name' => $user ? $user->display_name : __( 'Unknown user', 'qa-runner' ),
			);
		}

		$in_progress_by = null;
		$lock_age       = Dates::age_in_seconds( $row['in_progress_at'] ?? null );

		if ( ! empty( $row['in_progress_by'] ) && null !== $lock_age && $lock_age < self::LOCK_TTL ) {
			$user           = get_userdata( (int) $row['in_progress_by'] );
			$in_progress_by = array(
				'id'   => (int) $row['in_progress_by'],
				'name' => $user ? $user->display_name : __( 'Unknown user', 'qa-runner' ),
			);
		}

		return array(
			'id'               => (int) $row['id'],
			'run_id'           => (int) $row['run_id'],
			'case'             => array(
				'id'         => (int) $row['case_id'],
				'title'      => (string) ( $row['title'] ?? '' ),
				'priority'   => (string) ( $row['priority'] ?? 'normal' ),
				'is_active'  => isset( $row['is_active'] ) ? (bool) $row['is_active'] : true,
				'suite_id'   => isset( $row['suite_id'] ) ? (int) $row['suite_id'] : 0,
				'suite_name' => (string) ( $row['suite_name'] ?? '' ),
			),
			'status'           => (string) $row['status'],
			'tested_by'        => $tested_by,
			'tested_at'        => Dates::to_iso( $row['tested_at'] ?? null ),
			'in_progress_by'   => $in_progress_by,
			'comment_count'    => isset( $row['comment_count'] ) ? (int) $row['comment_count'] : 0,
			'open_issue_count' => isset( $row['open_issue_count'] ) ? (int) $row['open_issue_count'] : 0,
		);
	}
}
