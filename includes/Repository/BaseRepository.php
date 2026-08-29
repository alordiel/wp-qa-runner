<?php
/**
 * Shared repository behaviour.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Repository;

use QARunner\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the table repositories.
 *
 * Table names come from Schema::table(), which builds them from $wpdb->prefix and a
 * hard-coded suffix. Every value in a query goes through $wpdb->prepare().
 */
abstract class BaseRepository {

	/**
	 * Base table name, without the WordPress prefix.
	 *
	 * @return string
	 */
	abstract protected function table_name(): string;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	protected function table(): string {
		return Schema::table( $this->table_name() );
	}

	/**
	 * The global $wpdb instance.
	 *
	 * @return \wpdb
	 */
	protected function db(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Builds a prepared "IN (...)" placeholder list.
	 *
	 * @param int[] $ids Identifiers.
	 * @return string Comma-separated %d placeholders, or an empty string for an empty list.
	 */
	protected function placeholders( array $ids ): string {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * Deletes a single row by primary key.
	 *
	 * @param int $id Row identifier.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return (bool) $this->db()->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}
}
