<?php
/**
 * Date helpers. Everything stored is UTC; everything emitted is ISO 8601 UTC.
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager\Support;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Date conversion helpers.
 *
 * The plugin never mixes current_time( 'mysql' ) with gmdate(): storage is UTC only,
 * and the client formats for display.
 */
final class Dates {

	/**
	 * Current UTC timestamp in MySQL DATETIME format, for writing to the database.
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Converts a stored UTC DATETIME to ISO 8601 for an API response.
	 *
	 * @param string|null $datetime Stored UTC DATETIME, or null.
	 * @return string|null
	 */
	public static function to_iso( ?string $datetime ): ?string {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat(
			'Y-m-d H:i:s',
			$datetime,
			new DateTimeZone( 'UTC' )
		);

		if ( false === $date ) {
			return null;
		}

		return $date->format( 'c' );
	}

	/**
	 * Age in seconds of a stored UTC DATETIME.
	 *
	 * @param string|null $datetime Stored UTC DATETIME, or null.
	 * @return int|null Null when the input is empty or unparseable.
	 */
	public static function age_in_seconds( ?string $datetime ): ?int {
		if ( empty( $datetime ) ) {
			return null;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( false === $timestamp ) {
			return null;
		}

		return time() - $timestamp;
	}
}
