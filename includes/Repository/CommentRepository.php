<?php
/**
 * Comment persistence. Comments hang off a result, so they are scoped to one run.
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager\Repository;

use MandragoraQAManager\Support\Dates;
use MandragoraQAManager\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes mqatm_comments.
 */
final class CommentRepository extends BaseRepository {

	/**
	 * Deepest level a thread may reach: a top-level comment is level 1, a reply to it
	 * level 2, and a reply to that level 3.
	 */
	public const MAX_DEPTH = 3;

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
	 * Level of a comment in its thread, counting a top-level comment as 1.
	 *
	 * @param int $id Comment identifier.
	 * @return int 0 when the comment does not exist.
	 */
	public function depth( int $id ): int {
		$table = $this->table();
		$depth = 0;

		// Bounded by MAX_DEPTH, so a corrupt parent cycle cannot spin forever.
		while ( $id > 0 && $depth <= self::MAX_DEPTH ) {
			$row = $this->db()->get_row(
				$this->db()->prepare( "SELECT parent_id FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( null === $row ) {
				break;
			}

			++$depth;
			$id = (int) $row['parent_id'];
		}

		return $depth;
	}

	/**
	 * Inserts a comment.
	 *
	 * @param int    $result_id Result identifier.
	 * @param int    $user_id   Author identifier.
	 * @param string $content   Rich-text body.
	 * @param int    $parent_id Comment being replied to, or 0 for a top-level comment.
	 * @return int Insert ID, or 0 on failure.
	 */
	public function create( int $result_id, int $user_id, string $content, int $parent_id = 0 ): int {
		$now = Dates::now();

		$inserted = $this->db()->insert(
			$this->table(),
			array(
				'result_id'  => $result_id,
				'parent_id'  => $parent_id > 0 ? $parent_id : null,
				'user_id'    => $user_id,
				'content'    => Sanitize::rich_text( $content ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s' )
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
	 * Deletes a comment together with every reply beneath it, so no reply is left
	 * pointing at a parent that no longer exists.
	 *
	 * @param int $id Comment identifier.
	 * @return int[] Identifiers of every deleted comment, or an empty array on failure.
	 */
	public function delete_thread( int $id ): array {
		$table = $this->table();
		$ids   = array( $id );
		$level = array( $id );

		// array_diff drops ids already collected, so a corrupt parent cycle still terminates.
		while ( $level ) {
			$placeholders = implode( ',', array_fill( 0, count( $level ), '%d' ) );
			$children     = $this->db()->get_col(
				$this->db()->prepare( "SELECT id FROM {$table} WHERE parent_id IN ({$placeholders})", $level ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$level = array_values( array_diff( array_map( 'intval', $children ?? array() ), $ids ) );
			$ids   = array_merge( $ids, $level );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $this->db()->query(
			$this->db()->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $deleted ? $ids : array();
	}

	/**
	 * Deletes every comment on a result. Used when a case leaves a run and its result goes
	 * with it — the rows have no foreign key, so an orphan would otherwise be inherited by
	 * whatever result id the table recycles next.
	 *
	 * @param int $result_id Result identifier.
	 * @return void
	 */
	public function delete_for_result( int $result_id ): void {
		$this->db()->delete( $this->table(), array( 'result_id' => $result_id ), array( '%d' ) );
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
			'parent_id'  => empty( $row['parent_id'] ) ? null : (int) $row['parent_id'],
			'author'     => array(
				'id'     => $user_id,
				'name'   => $user ? $user->display_name : __( 'Unknown user', 'mandragora-qa-test-manager' ),
				'avatar' => get_avatar_url( $user_id, array( 'size' => 48 ) ),
			),
			'content'    => (string) $row['content'],
			'created_at' => Dates::to_iso( $row['created_at'] ?? null ),
			'updated_at' => Dates::to_iso( $row['updated_at'] ?? null ),
		);
	}
}
