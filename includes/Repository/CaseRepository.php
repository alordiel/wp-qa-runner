<?php
/**
 * Case library persistence.
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
 * Reads and writes qa_cases.
 *
 * A case carries no status of its own — only results do. That is what makes one run
 * comparable to another.
 */
final class CaseRepository extends BaseRepository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return 'cases';
	}

	/**
	 * Queries the case library.
	 *
	 * @param array<string, mixed> $filters suite_id, priority, search, active.
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $filters = array() ): array {
		$cases  = $this->table();
		$suites = Schema::table( 'suites' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['suite_id'] ) ) {
			$where[]  = 'c.suite_id = %d';
			$params[] = (int) $filters['suite_id'];
		}

		if ( ! empty( $filters['priority'] ) && Enum::is_valid( (string) $filters['priority'], Enum::CASE_PRIORITIES ) ) {
			$where[]  = 'c.priority = %s';
			$params[] = (string) $filters['priority'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$where[]  = 'c.title LIKE %s';
			$params[] = '%' . $this->db()->esc_like( (string) $filters['search'] ) . '%';
		}

		if ( array_key_exists( 'active', $filters ) && null !== $filters['active'] ) {
			$where[]  = 'c.is_active = %d';
			$params[] = $filters['active'] ? 1 : 0;
		}

		$sql = "SELECT c.*, s.name AS suite_name, s.sort_order AS suite_sort
				FROM {$cases} c
				INNER JOIN {$suites} s ON s.id = c.suite_id
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY s.sort_order ASC, s.name ASC, c.title ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $params
			? $this->db()->get_results( $this->db()->prepare( $sql, ...$params ), ARRAY_A )
			: $this->db()->get_results( $sql, ARRAY_A );

		return array_map(
			fn( array $row ): array => $this->to_array( $row, false ),
			$rows ?? array()
		);
	}

	/**
	 * Finds one case, including its steps and expected result.
	 *
	 * @param int $id Case identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$cases  = $this->table();
		$suites = Schema::table( 'suites' );

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT c.*, s.name AS suite_name
				 FROM {$cases} c
				 INNER JOIN {$suites} s ON s.id = c.suite_id
				 WHERE c.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ? $this->to_array( $row, true ) : null;
	}

	/**
	 * Finds several cases by identifier, keyed by identifier.
	 *
	 * @param int[] $ids Case identifiers.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_many( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		$cases  = $this->table();
		$suites = Schema::table( 'suites' );
		$holder = $this->placeholders( $ids );

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT c.*, s.name AS suite_name
				 FROM {$cases} c
				 INNER JOIN {$suites} s ON s.id = c.suite_id
				 WHERE c.id IN ({$holder})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ( $rows ?? array() as $row ) {
			$out[ (int) $row['id'] ] = $this->to_array( $row, false );
		}

		return $out;
	}

	/**
	 * Filters a list of identifiers down to active cases that exist.
	 *
	 * @param int[] $ids Candidate identifiers.
	 * @return int[]
	 */
	public function filter_active( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		$table  = $this->table();
		$holder = $this->placeholders( $ids );

		$found = $this->db()->get_col(
			$this->db()->prepare(
				"SELECT id FROM {$table} WHERE is_active = 1 AND id IN ({$holder})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$found = array_map( 'intval', $found ?? array() );

		// Preserve the caller's ordering, which becomes the run's sort order.
		return array_values( array_intersect( $ids, $found ) );
	}

	/**
	 * Inserts a case.
	 *
	 * @param array<string, mixed> $data Case fields.
	 * @return int Insert ID, or 0 on failure.
	 */
	public function create( array $data ): int {
		$now = Dates::now();

		$inserted = $this->db()->insert(
			$this->table(),
			array(
				'suite_id'   => (int) ( $data['suite_id'] ?? 0 ),
				'title'      => Sanitize::text( $data['title'] ?? '' ),
				'steps'      => Sanitize::rich_text( $data['steps'] ?? '' ),
				'expected'   => Sanitize::rich_text( $data['expected'] ?? '' ),
				'priority'   => Enum::coerce( $data['priority'] ?? 'normal', Enum::CASE_PRIORITIES, 'normal' ),
				'is_active'  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
				'created_by' => (int) ( $data['created_by'] ?? get_current_user_id() ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $this->db()->insert_id : 0;
	}

	/**
	 * Updates a case.
	 *
	 * @param int                  $id   Case identifier.
	 * @param array<string, mixed> $data Fields to change.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$fields  = array();
		$formats = array();

		$map = array(
			'suite_id'  => array( '%d', static fn( $v ): int => (int) $v ),
			'title'     => array( '%s', static fn( $v ): string => Sanitize::text( $v ) ),
			'steps'     => array( '%s', static fn( $v ): string => Sanitize::rich_text( $v ) ),
			'expected'  => array( '%s', static fn( $v ): string => Sanitize::rich_text( $v ) ),
			'priority'  => array( '%s', static fn( $v ): string => Enum::coerce( $v, Enum::CASE_PRIORITIES, 'normal' ) ),
			'is_active' => array( '%d', static fn( $v ): int => (int) (bool) $v ),
		);

		foreach ( $map as $key => [$format, $filter] ) {
			if ( array_key_exists( $key, $data ) ) {
				$fields[ $key ] = $filter( $data[ $key ] );
				$formats[]      = $format;
			}
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = Dates::now();
		$formats[]            = '%s';

		return false !== $this->db()->update( $this->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Soft-deletes a case so historical results keep their subject.
	 *
	 * @param int $id Case identifier.
	 * @return bool
	 */
	public function deactivate( int $id ): bool {
		return $this->update( $id, array( 'is_active' => 0 ) );
	}

	/**
	 * Whether a case has ever been included in a run.
	 *
	 * @param int $id Case identifier.
	 * @return bool
	 */
	public function has_results( int $id ): bool {
		$results = Schema::table( 'results' );

		return (bool) $this->db()->get_var(
			$this->db()->prepare( "SELECT id FROM {$results} WHERE case_id = %d LIMIT 1", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Casts a raw database row to the API shape.
	 *
	 * @param array<string, mixed> $row       Raw row.
	 * @param bool                 $with_body Whether to include steps and expected HTML.
	 * @return array<string, mixed>
	 */
	public function to_array( array $row, bool $with_body = false ): array {
		$case = array(
			'id'         => (int) $row['id'],
			'suite_id'   => (int) $row['suite_id'],
			'suite_name' => (string) ( $row['suite_name'] ?? '' ),
			'title'      => (string) $row['title'],
			'priority'   => (string) $row['priority'],
			'is_active'  => (bool) $row['is_active'],
			'created_by' => (int) $row['created_by'],
			'created_at' => Dates::to_iso( $row['created_at'] ?? null ),
			'updated_at' => Dates::to_iso( $row['updated_at'] ?? null ),
		);

		if ( $with_body ) {
			$case['steps']    = (string) ( $row['steps'] ?? '' );
			$case['expected'] = (string) ( $row['expected'] ?? '' );
		}

		return $case;
	}
}
