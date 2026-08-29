<?php
/**
 * Comment persistence. Comments hang off a result, so they are scoped to one run.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Repository;

use QARunner\Support\Dates;
use QARunner\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes qa_comments.
 */
final class CommentRepository extends BaseRepository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return 'comments';
	}

	/**
	 * Comments on a result, oldest first.
	 *
	 * @param int $result_id Result identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_result( int $result_id ): array {
		$table = $this->table();

		$rows = $this->db()->get_results(
			$this->db()->prepare( "SELECT * FROM {$table} WHERE result_id = %d ORDER BY created_at ASC, id ASC", $result_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'to_array' ), $rows ?? array() );
	}

	/**
	 * Finds one comment.
	 *
	 * @param int $id Comment identifier.
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
	 * Inserts a comment.
	 *
	 * @param int    $result_id Result identifier.
	 * @param int    $user_id   Author identifier.
	 * @param string $content   Rich-text body.
	 * @return int Insert ID, or 0 on failure.
	 */
	public function create( int $result_id, int $user_id, string $content ): int {
		$now = Dates::now();

		$inserted = $this->db()->insert(
			$this->table(),
			array(
				'result_id'  => $result_id,
				'user_id'    => $user_id,
				'content'    => Sanitize::rich_text( $content ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $this->db()->insert_id : 0;
	}

	/**
	 * Updates a comment body.
	 *
	 * @param int    $id      Comment identifier.
	 * @param string $content Rich-text body.
	 * @return bool
	 */
	public function update( int $id, string $content ): bool {
		return false !== $this->db()->update(
			$this->table(),
			array(
				'content'    => Sanitize::rich_text( $content ),
				'updated_at' => Dates::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Casts a raw database row to the API shape.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	public function to_array( array $row ): array {
		$user_id = (int) $row['user_id'];
		$user    = get_userdata( $user_id );

		return array(
			'id'         => (int) $row['id'],
			'result_id'  => (int) $row['result_id'],
			'author'     => array(
				'id'     => $user_id,
				'name'   => $user ? $user->display_name : __( 'Unknown user', 'qa-runner' ),
				'avatar' => get_avatar_url( $user_id, array( 'size' => 48 ) ),
			),
			'content'    => (string) $row['content'],
			'created_at' => Dates::to_iso( $row['created_at'] ?? null ),
			'updated_at' => Dates::to_iso( $row['updated_at'] ?? null ),
		);
	}
}
