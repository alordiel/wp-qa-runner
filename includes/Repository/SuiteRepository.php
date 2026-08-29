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
		$suites = Schema::table( 'suites' );
		$cases  = Schema::table( 'cases' );

		$rows = $this->db()->get_results(
			"SELECT s.*, ( SELECT COUNT(*) FROM {$cases} c WHERE c.suite_id = s.id AND c.is_active = 1 ) AS case_count
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
	 * Number of cases in a suite, including inactive ones.
	 *
	 * @param int $suite_id Suite identifier.
	 * @return int
	 */
	public function case_count( int $suite_id ): int {
		$cases = Schema::table( 'cases' );

		return (int) $this->db()->get_var(
			$this->db()->prepare( "SELECT COUNT(*) FROM {$cases} WHERE suite_id = %d", $suite_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			'id'          => (int) $row['id'],
			'name'        => (string) $row['name'],
			'slug'        => (string) $row['slug'],
			'description' => (string) ( $row['description'] ?? '' ),
			'sort_order'  => (int) $row['sort_order'],
			'created_at'  => Dates::to_iso( $row['created_at'] ?? null ),
			'case_count'  => isset( $row['case_count'] ) ? (int) $row['case_count'] : null,
		);
	}
}
