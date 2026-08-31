<?php
/**
 * Suite persistence.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Repository;

use QARunner\Install\Schema;
use QARunner\Support\Dates;
use QARunner\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes qa_suites.
 */
final class SuiteRepository extends BaseRepository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return 'suites';
	}

	/**
	 * All suites in display order, each with a case count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$suites   = Schema::table( 'suites' );
		$cases    = Schema::table( 'cases' );
		$retained = $this->retained_predicate();

		$rows = $this->db()->get_results(
			"SELECT s.*,
					( SELECT COUNT(*) FROM {$cases} c WHERE c.suite_id = s.id AND c.is_active = 1 ) AS case_count,
					( SELECT COUNT(*) FROM {$cases} c WHERE c.suite_id = s.id AND c.is_active = 0 ) AS archived_case_count,
					( SELECT COUNT(*) FROM {$cases} c WHERE c.suite_id = s.id AND c.is_active = 0 AND {$retained} ) AS retained_case_count
			 FROM {$suites} s
			 ORDER BY s.sort_order ASC, s.name ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'to_array' ), $rows ?? array() );
	}

	/**
	 * Finds one suite by identifier.
	 *
	 * @param int $id Suite identifier.
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
	 * Inserts a suite.
	 *
	 * @param array<string, mixed> $data name, description, sort_order.
	 * @return int Insert ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$name = Sanitize::text( $data['name'] ?? '' );

		$inserted = $this->db()->insert(
			$this->table(),
			array(
				'name'        => $name,
				'slug'        => $this->unique_slug( $name ),
				'description' => Sanitize::textarea( $data['description'] ?? '' ),
				'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
				'created_at'  => Dates::now(),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		return $inserted ? (int) $this->db()->insert_id : 0;
	}

	/**
	 * Updates a suite.
	 *
	 * @param int                  $id   Suite identifier.
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

		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = Sanitize::textarea( $data['description'] );
			$formats[]             = '%s';
		}

		if ( array_key_exists( 'sort_order', $data ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
			$formats[]            = '%d';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		return false !== $this->db()->update( $this->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Number of live cases in a suite.
	 *
	 * @param int $suite_id Suite identifier.
	 * @return int
	 */
	public function active_case_count( int $suite_id ): int {
		$cases = Schema::table( 'cases' );

		return (int) $this->db()->get_var(
			$this->db()->prepare( "SELECT COUNT(*) FROM {$cases} WHERE suite_id = %d AND is_active = 1", $suite_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Number of archived cases in a suite that history still points at.
	 *
	 * These rows cannot be dropped along with the suite: run cases, results and issues
	 * reference them, and every read of a case joins its suite.
	 *
	 * @param int $suite_id Suite identifier.
	 * @return int
	 */
	public function retained_case_count( int $suite_id ): int {
		$cases    = Schema::table( 'cases' );
		$retained = $this->retained_predicate();

		return (int) $this->db()->get_var(
			$this->db()->prepare(
				"SELECT COUNT(*) FROM {$cases} c WHERE c.suite_id = %d AND c.is_active = 0 AND {$retained}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$suite_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Hard-deletes the archived cases of a suite that no history refers to.
	 *
	 * A case that never reached a run is not the subject of any history, so nothing is
	 * lost by removing it with the suite it belonged to.
	 *
	 * @param int $suite_id Suite identifier.
	 * @return int Rows removed.
	 */
	public function purge_unused_cases( int $suite_id ): int {
		$cases    = Schema::table( 'cases' );
		$retained = $this->retained_predicate();

		return (int) $this->db()->query(
			$this->db()->prepare(
				"DELETE c FROM {$cases} c WHERE c.suite_id = %d AND c.is_active = 0 AND NOT {$retained}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$suite_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Moves every case of a suite into another suite.
	 *
	 * @param int $suite_id  Source suite.
	 * @param int $target_id Destination suite.
	 * @return bool
	 */
	public function move_cases( int $suite_id, int $target_id ): bool {
		return false !== $this->db()->update(
			Schema::table( 'cases' ),
			array( 'suite_id' => $target_id ),
			array( 'suite_id' => $suite_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * SQL predicate, against a cases alias of "c", for a case history still points at.
	 *
	 * @return string
	 */
	private function retained_predicate(): string {
		$run_cases = Schema::table( 'run_cases' );
		$results   = Schema::table( 'results' );
		$issues    = Schema::table( 'issues' );

		return "( EXISTS ( SELECT 1 FROM {$run_cases} rc WHERE rc.case_id = c.id )
			OR EXISTS ( SELECT 1 FROM {$results} r WHERE r.case_id = c.id )
			OR EXISTS ( SELECT 1 FROM {$issues} i WHERE i.case_id = c.id ) )";
	}

	/**
	 * Generates a slug that is unique within the table.
	 *
	 * @param string $name Suite name.
	 * @return string
	 */
	private function unique_slug( string $name ): string {
		$table = $this->table();
		$base  = sanitize_title( $name );
		$base  = '' !== $base ? $base : 'suite';
		$slug  = $base;
		$index = 2;

		while ( null !== $this->db()->get_var( $this->db()->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$slug = $base . '-' . $index;
			++$index;
		}

		return $slug;
	}

	/**
	 * Casts a raw database row to the API shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function to_array( array $row ): array {
		return array(
			'id'                  => (int) $row['id'],
			'name'                => (string) $row['name'],
			'slug'                => (string) $row['slug'],
			'description'         => (string) ( $row['description'] ?? '' ),
			'sort_order'          => (int) $row['sort_order'],
			'created_at'          => Dates::to_iso( $row['created_at'] ?? null ),
			'case_count'          => isset( $row['case_count'] ) ? (int) $row['case_count'] : null,
			'archived_case_count' => isset( $row['archived_case_count'] ) ? (int) $row['archived_case_count'] : null,
			'retained_case_count' => isset( $row['retained_case_count'] ) ? (int) $row['retained_case_count'] : null,
		);
	}
}
