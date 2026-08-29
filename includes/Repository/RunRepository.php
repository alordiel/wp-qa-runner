<?php
/**
 * Run persistence: runs, their case selection and their assignees.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Repository;

use QARunner\Install\Schema;
use QARunner\Support\Dates;
use QARunner\Support\Enum;
use QARunner\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes qa_runs, qa_run_cases and qa_run_assignees.
 *
 * Runs are independent: nothing here closes, supersedes or archives one run because
 * another was created or completed.
 */
final class RunRepository extends BaseRepository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return 'runs';
	}

	/**
	 * Lists runs, newest first, each with status counts and assignees.
	 *
	 * @param string|null $status One of Enum::RUN_STATUSES, or null for all.
	 * @return array<int, array<string, mixed>>
	 */
	public function query( ?string $status = null ): array {
		$runs = $this->table();

		if ( null !== $status && Enum::is_valid( $status, Enum::RUN_STATUSES ) ) {
			$rows = $this->db()->get_results(
				$this->db()->prepare( "SELECT * FROM {$runs} WHERE status = %s ORDER BY created_at DESC", $status ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$rows = $this->db()->get_results( "SELECT * FROM {$runs} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$rows = $rows ?? array();
		$ids  = array_map( static fn( array $row ): int => (int) $row['id'], $rows );

		$counts      = $this->counts_for_runs( $ids );
		$assignees   = $this->assignees_for_runs( $ids );
		$open_issues = $this->open_issue_counts_for_runs( $ids );

		return array_map(
			function ( array $row ) use ( $counts, $assignees, $open_issues ): array {
				$id                      = (int) $row['id'];
				$run                     = $this->to_array( $row );
				$run['counts']           = $counts[ $id ] ?? $this->empty_counts();
				$run['assignees']        = $assignees[ $id ] ?? array();
				$run['open_issue_count'] = $open_issues[ $id ] ?? 0;

				return $run;
			},
			$rows
		);
	}

	/**
	 * Finds one run.
	 *
	 * @param int $id Run identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$table = $this->table();

		$row = $this->db()->get_row(
			$this->db()->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $this->to_array( $row ) : null;
	}

	/**
	 * Finds one run with its assignees and status counts attached.
	 *
	 * @param int $id Run identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_with_detail( int $id ): ?array {
		$run = $this->find( $id );

		if ( null === $run ) {
			return null;
		}

		$counts      = $this->counts_for_runs( array( $id ) );
		$assignees   = $this->assignees_for_runs( array( $id ) );
		$open_issues = $this->open_issue_counts_for_runs( array( $id ) );

		$run['counts']           = $counts[ $id ] ?? $this->empty_counts();
		$run['assignees']        = $assignees[ $id ] ?? array();
		$run['open_issue_count'] = $open_issues[ $id ] ?? 0;

		return $run;
	}

	/**
	 * Whether a run is open and therefore writable.
	 *
	 * @param int $id Run identifier.
	 * @return bool
	 */
	public function is_open( int $id ): bool {
		$table = $this->table();

		$status = $this->db()->get_var(
			$this->db()->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return 'open' === $status;
	}

	/**
	 * Inserts a run.
	 *
	 * @param array<string, mixed> $data name, environment, version, notes, created_by.
	 * @return int Insert ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$inserted = $this->db()->insert(
			$this->table(),
			array(
				'name'        => Sanitize::text( $data['name'] ?? '' ),
				'environment' => Enum::coerce( $data['environment'] ?? '', Enum::ENVIRONMENTS, 'staging' ),
				'version'     => Sanitize::text( $data['version'] ?? '' ),
				'status'      => 'open',
				'notes'       => Sanitize::textarea( $data['notes'] ?? '' ),
				'created_by'  => (int) ( $data['created_by'] ?? get_current_user_id() ),
				'created_at'  => Dates::now(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $this->db()->insert_id : 0;
	}

	/**
	 * Updates a run. Setting the status to completed stamps completed_at; any other status
	 * clears it, so reopening a run really does reopen it.
	 *
	 * @param int                  $id   Run identifier.
	 * @param array<string, mixed> $data Fields to change.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'name', $data ) ) {
			$fields['name'] = Sanitize::text( $data['name'] );
			$formats[]      = '%s';
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$fields['notes'] = Sanitize::textarea( $data['notes'] );
			$formats[]       = '%s';
		}

		if ( array_key_exists( 'environment', $data ) ) {
			$fields['environment'] = Enum::coerce( $data['environment'], Enum::ENVIRONMENTS, 'staging' );
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'version', $data ) ) {
			$fields['version'] = Sanitize::text( $data['version'] );
			$formats[]         = '%s';
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status                 = Enum::coerce( $data['status'], Enum::RUN_STATUSES, 'open' );
			$fields['status']       = $status;
			$formats[]              = '%s';
			$fields['completed_at'] = 'completed' === $status ? Dates::now() : null;
			$formats[]              = '%s';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		return false !== $this->db()->update( $this->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Adds cases to a run's selection, skipping any already present.
	 *
	 * @param int   $run_id   Run identifier.
	 * @param int[] $case_ids Case identifiers, in the order they should appear.
	 * @return int[] The case identifiers actually added.
	 */
	public function add_cases( int $run_id, array $case_ids ): array {
		if ( empty( $case_ids ) ) {
			return array();
		}

		$table    = Schema::table( 'run_cases' );
		$existing = $this->case_ids( $run_id );
		$to_add   = array_values( array_diff( $case_ids, $existing ) );

		if ( empty( $to_add ) ) {
			return array();
		}

		$sort = count( $existing );

		foreach ( $to_add as $case_id ) {
			$this->db()->insert(
				$table,
				array(
					'run_id'     => $run_id,
					'case_id'    => $case_id,
					'sort_order' => $sort,
				),
				array( '%d', '%d', '%d' )
			);
			++$sort;
		}

		return $to_add;
	}

	/**
	 * Removes a case from a run's selection.
	 *
	 * @param int $run_id  Run identifier.
	 * @param int $case_id Case identifier.
	 * @return bool
	 */
	public function remove_case( int $run_id, int $case_id ): bool {
		$table = Schema::table( 'run_cases' );

		return (bool) $this->db()->delete(
			$table,
			array(
				'run_id'  => $run_id,
				'case_id' => $case_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Case identifiers in a run, in selection order.
	 *
	 * @param int $run_id Run identifier.
	 * @return int[]
	 */
	public function case_ids( int $run_id ): array {
		$table = Schema::table( 'run_cases' );

		$ids = $this->db()->get_col(
			$this->db()->prepare( "SELECT case_id FROM {$table} WHERE run_id = %d ORDER BY sort_order ASC, id ASC", $run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids ?? array() );
	}

	/**
	 * Adds assignees to a run.
	 *
	 * @param int   $run_id   Run identifier.
	 * @param int[] $user_ids User identifiers.
	 * @return int[] The user identifiers actually added.
	 */
	public function add_assignees( int $run_id, array $user_ids ): array {
		if ( empty( $user_ids ) ) {
			return array();
		}

		$table    = Schema::table( 'run_assignees' );
		$existing = array_map(
			static fn( array $row ): int => $row['id'],
			$this->assignees_for_runs( array( $run_id ) )[ $run_id ] ?? array()
		);
		$to_add   = array_values( array_diff( $user_ids, $existing ) );
		$added    = array();

		foreach ( $to_add as $user_id ) {
			$inserted = $this->db()->insert(
				$table,
				array(
					'run_id'      => $run_id,
					'user_id'     => $user_id,
					'assigned_at' => Dates::now(),
				),
				array( '%d', '%d', '%s' )
			);

			if ( $inserted ) {
				$added[] = $user_id;
			}
		}

		return $added;
	}

	/**
	 * Removes an assignee from a run.
	 *
	 * @param int $run_id  Run identifier.
	 * @param int $user_id User identifier.
	 * @return bool
	 */
	public function remove_assignee( int $run_id, int $user_id ): bool {
		$table = Schema::table( 'run_assignees' );

		return (bool) $this->db()->delete(
			$table,
			array(
				'run_id'  => $run_id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Stamps notified_at so re-saving a run never sends the assignment email twice.
	 *
	 * @param int   $run_id   Run identifier.
	 * @param int[] $user_ids User identifiers.
	 * @return void
	 */
	public function mark_notified( int $run_id, array $user_ids ): void {
		if ( empty( $user_ids ) ) {
			return;
		}

		$table = Schema::table( 'run_assignees' );

		foreach ( $user_ids as $user_id ) {
			$this->db()->update(
				$table,
				array( 'notified_at' => Dates::now() ),
				array(
					'run_id'  => $run_id,
					'user_id' => $user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Assignees who have not yet had an assignment email.
	 *
	 * @param int $run_id Run identifier.
	 * @return int[]
	 */
	public function unnotified_assignees( int $run_id ): array {
		$table = Schema::table( 'run_assignees' );

		$ids = $this->db()->get_col(
			$this->db()->prepare( "SELECT user_id FROM {$table} WHERE run_id = %d AND notified_at IS NULL", $run_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids ?? array() );
	}

	/**
	 * Result status counts, grouped by run.
	 *
	 * @param int[] $run_ids Run identifiers.
	 * @return array<int, array<string, int>>
	 */
	public function counts_for_runs( array $run_ids ): array {
		if ( empty( $run_ids ) ) {
			return array();
		}

		$results = Schema::table( 'results' );
		$holder  = $this->placeholders( $run_ids );

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT run_id, status, COUNT(*) AS total
				 FROM {$results}
				 WHERE run_id IN ({$holder})
				 GROUP BY run_id, status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$run_ids
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$run_id = (int) $row['run_id'];

			if ( ! isset( $out[ $run_id ] ) ) {
				$out[ $run_id ] = $this->empty_counts();
			}

			$status = (string) $row['status'];

			if ( isset( $out[ $run_id ][ $status ] ) ) {
				$out[ $run_id ][ $status ] = (int) $row['total'];
			}

			$out[ $run_id ]['total'] += (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Open issues on the cases each run covers, grouped by run.
	 *
	 * Issues attach to a case, not a run, so this is a count of what is currently broken
	 * among this run's cases — the same set the tester sees banners for while working
	 * through it. COUNT(DISTINCT) because one issue can only be counted once even though
	 * the join is by case.
	 *
	 * @param int[] $run_ids Run identifiers.
	 * @return array<int, int> Keyed by run identifier.
	 */
	public function open_issue_counts_for_runs( array $run_ids ): array {
		if ( empty( $run_ids ) ) {
			return array();
		}

		$run_cases = Schema::table( 'run_cases' );
		$issues    = Schema::table( 'issues' );
		$holder    = $this->placeholders( $run_ids );

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT rc.run_id, COUNT(DISTINCT i.id) AS total
				 FROM {$run_cases} rc
				 INNER JOIN {$issues} i ON i.case_id = rc.case_id AND i.status = 'open'
				 WHERE rc.run_id IN ({$holder})
				 GROUP BY rc.run_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$run_ids
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$out[ (int) $row['run_id'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Assignees, grouped by run.
	 *
	 * @param int[] $run_ids Run identifiers.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function assignees_for_runs( array $run_ids ): array {
		if ( empty( $run_ids ) ) {
			return array();
		}

		$table  = Schema::table( 'run_assignees' );
		$holder = $this->placeholders( $run_ids );

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT run_id, user_id, assigned_at, notified_at
				 FROM {$table}
				 WHERE run_id IN ({$holder})
				 ORDER BY assigned_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$run_ids
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$run_id  = (int) $row['run_id'];
			$user_id = (int) $row['user_id'];
			$user    = get_userdata( $user_id );

			$out[ $run_id ][] = array(
				'id'          => $user_id,
				'name'        => $user ? $user->display_name : __( 'Unknown user', 'qa-runner' ),
				'avatar'      => get_avatar_url( $user_id, array( 'size' => 48 ) ),
				'assigned_at' => Dates::to_iso( $row['assigned_at'] ),
				'notified_at' => Dates::to_iso( $row['notified_at'] ),
			);
		}

		return $out;
	}

	/**
	 * Most recent tested result for each case in a run, from any other run.
	 *
	 * Powers the "only failed last run" filter and the regression view. Cases that have
	 * never been tested elsewhere are omitted.
	 *
	 * @param int $run_id Run identifier to exclude.
	 * @return array<int, array<string, mixed>> Keyed by case identifier.
	 */
	public function previous_statuses( int $run_id ): array {
		$results   = Schema::table( 'results' );
		$runs      = $this->table();
		$run_cases = Schema::table( 'run_cases' );

		$sql = "SELECT ranked.case_id, ranked.status, ranked.run_id, ranked.run_name, ranked.tested_at
				FROM (
					SELECT r.case_id,
						   r.status,
						   r.run_id,
						   r.tested_at,
						   run.name AS run_name,
						   ROW_NUMBER() OVER (
							   PARTITION BY r.case_id
							   ORDER BY run.created_at DESC, run.id DESC
						   ) AS rn
					FROM {$results} r
					INNER JOIN {$runs} run ON run.id = r.run_id
					WHERE r.run_id <> %d
					  AND r.status <> 'untested'
					  AND r.case_id IN ( SELECT case_id FROM {$run_cases} WHERE run_id = %d )
				) ranked
				WHERE ranked.rn = 1";

		$rows = $this->db()->get_results(
			$this->db()->prepare( $sql, $run_id, $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$out[ (int) $row['case_id'] ] = array(
				'status'    => (string) $row['status'],
				'run_id'    => (int) $row['run_id'],
				'run_name'  => (string) $row['run_name'],
				'tested_at' => Dates::to_iso( $row['tested_at'] ),
			);
		}

		return $out;
	}

	/**
	 * A zeroed status count map.
	 *
	 * @return array<string, int>
	 */
	public function empty_counts(): array {
		$counts = array( 'total' => 0 );

		foreach ( Enum::RESULT_STATUSES as $status ) {
			$counts[ $status ] = 0;
		}

		return $counts;
	}

	/**
	 * Casts a raw database row to the API shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function to_array( array $row ): array {
		$creator = get_userdata( (int) $row['created_by'] );

		return array(
			'id'           => (int) $row['id'],
			'name'         => (string) $row['name'],
			'environment'  => (string) $row['environment'],
			'version'      => (string) $row['version'],
			'status'       => (string) $row['status'],
			'notes'        => (string) ( $row['notes'] ?? '' ),
			'created_by'   => array(
				'id'   => (int) $row['created_by'],
				'name' => $creator ? $creator->display_name : __( 'Unknown user', 'qa-runner' ),
			),
			'created_at'   => Dates::to_iso( $row['created_at'] ?? null ),
			'completed_at' => Dates::to_iso( $row['completed_at'] ?? null ),
		);
	}
}
