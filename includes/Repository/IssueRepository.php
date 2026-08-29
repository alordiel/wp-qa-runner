<?php
/**
 * Issue persistence. Issues attach to a case, which is what makes them cross-run.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Repository;

use QARunner\Support\Dates;
use QARunner\Support\Enum;
use QARunner\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes qa_issues.
 *
 * Only open issues are ever surfaced during testing. Resolved and wontfix issues stay in
 * the table for audit and are reachable from the case library, but a tester only needs to
 * know what is currently broken.
 */
final class IssueRepository extends BaseRepository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return 'issues';
	}

	/**
	 * Issues on a case, newest first.
	 *
	 * @param int         $case_id Case identifier.
	 * @param string|null $status  One of Enum::ISSUE_STATUSES, or null for every status.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_case( int $case_id, ?string $status = 'open' ): array {
		$table = $this->table();

		if ( null !== $status ) {
			$rows = $this->db()->get_results(
				$this->db()->prepare(
					"SELECT * FROM {$table} WHERE case_id = %d AND status = %s ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$case_id,
					$status
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$rows = $this->db()->get_results(
				$this->db()->prepare(
					"SELECT * FROM {$table} WHERE case_id = %d ORDER BY created_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$case_id
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return array_map( array( $this, 'to_array' ), $rows ?? array() );
	}

	/**
	 * Finds one issue.
	 *
	 * @param int $id Issue identifier.
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
	 * Open issue counts for a set of cases.
	 *
	 * @param int[] $case_ids Case identifiers.
	 * @return array<int, int> Keyed by case identifier.
	 */
	public function open_counts( array $case_ids ): array {
		if ( empty( $case_ids ) ) {
			return array();
		}

		$table  = $this->table();
		$holder = $this->placeholders( $case_ids );

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT case_id, COUNT(*) AS total
				 FROM {$table}
				 WHERE status = 'open' AND case_id IN ({$holder})
				 GROUP BY case_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$case_ids
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$out[ (int) $row['case_id'] ] = (int) $row['total'];
		}

		return $out;
	}

	/**
	 * Raises an issue against a case.
	 *
	 * @param array<string, mixed> $data case_id, title, description, github_url, origin_run_id.
	 * @return int Insert ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$origin = isset( $data['origin_run_id'] ) ? (int) $data['origin_run_id'] : 0;

		$inserted = $this->db()->insert(
			$this->table(),
			array(
				'case_id'       => (int) ( $data['case_id'] ?? 0 ),
				'origin_run_id' => $origin > 0 ? $origin : null,
				'title'         => Sanitize::text( $data['title'] ?? '' ),
				'description'   => Sanitize::rich_text( $data['description'] ?? '' ),
				'github_url'    => Sanitize::github_url( $data['github_url'] ?? '' ),
				'status'        => 'open',
				'created_by'    => (int) ( $data['created_by'] ?? get_current_user_id() ),
				'created_at'    => Dates::now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $this->db()->insert_id : 0;
	}

	/**
	 * Updates an issue, including its resolution.
	 *
	 * @param int                  $id      Issue identifier.
	 * @param array<string, mixed> $data    Fields to change.
	 * @param int                  $user_id User making the change, recorded on resolution.
	 * @return bool
	 */
	public function update( int $id, array $data, int $user_id ): bool {
		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'title', $data ) ) {
			$fields['title'] = Sanitize::text( $data['title'] );
			$formats[]       = '%s';
		}

		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = Sanitize::rich_text( $data['description'] );
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'github_url', $data ) ) {
			$fields['github_url'] = Sanitize::github_url( $data['github_url'] );
			$formats[]            = '%s';
		}

		if ( array_key_exists( 'resolution_note', $data ) ) {
			$fields['resolution_note'] = Sanitize::textarea( $data['resolution_note'] );
			$formats[]                 = '%s';
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status           = Enum::coerce( $data['status'], Enum::ISSUE_STATUSES, 'open' );
			$fields['status'] = $status;
			$formats[]        = '%s';

			// Reopening an issue clears the resolution so the audit trail is not misleading.
			$closed                = 'open' !== $status;
			$fields['resolved_by'] = $closed ? $user_id : null;
			$formats[]             = '%d';
			$fields['resolved_at'] = $closed ? Dates::now() : null;
			$formats[]             = '%s';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		return false !== $this->db()->update( $this->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Casts a raw database row to the API shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function to_array( array $row ): array {
		$created_by  = (int) $row['created_by'];
		$creator     = get_userdata( $created_by );
		$resolved_by = ! empty( $row['resolved_by'] ) ? (int) $row['resolved_by'] : 0;
		$resolver    = $resolved_by ? get_userdata( $resolved_by ) : null;

		return array(
			'id'              => (int) $row['id'],
			'case_id'         => (int) $row['case_id'],
			'origin_run_id'   => ! empty( $row['origin_run_id'] ) ? (int) $row['origin_run_id'] : null,
			'title'           => (string) $row['title'],
			'description'     => (string) ( $row['description'] ?? '' ),
			'github_url'      => (string) ( $row['github_url'] ?? '' ),
			'status'          => (string) $row['status'],
			'created_by'      => array(
				'id'     => $created_by,
				'name'   => $creator ? $creator->display_name : __( 'Unknown user', 'qa-runner' ),
				'avatar' => get_avatar_url( $created_by, array( 'size' => 48 ) ),
			),
			'created_at'      => Dates::to_iso( $row['created_at'] ?? null ),
			'resolved_by'     => $resolved_by
				? array(
					'id'   => $resolved_by,
					'name' => $resolver ? $resolver->display_name : __( 'Unknown user', 'qa-runner' ),
				)
				: null,
			'resolved_at'     => Dates::to_iso( $row['resolved_at'] ?? null ),
			'resolution_note' => (string) ( $row['resolution_note'] ?? '' ),
		);
	}

	/**
	 * Deletes every issue attached to a case. Used only when a case is hard-deleted.
	 *
	 * @param int $case_id Case identifier.
	 * @return void
	 */
	public function delete_for_case( int $case_id ): void {
		$this->db()->delete( $this->table(), array( 'case_id' => $case_id ), array( '%d' ) );
	}
}
