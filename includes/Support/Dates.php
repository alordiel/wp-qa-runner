<?php
/**
 * Date helpers. Everything stored is UTC; everything emitted is ISO 8601 UTC.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Support;

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

	/**
	 * Next occurrence of a wall-clock time in the site timezone, as a UTC timestamp.
	 *
	 * WP-Cron schedules relative to the timestamp it is given, so the digest time has to be
	 * resolved in the site timezone and converted before wp_schedule_event() sees it.
	 *
	 * @param string $time_of_day 24-hour "HH:MM".
	 * @return int Unix timestamp.
	 */
	public static function next_occurrence_utc( string $time_of_day ): int {
		$timezone = wp_timezone();

		if ( ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time_of_day, $matches ) ) {
			$matches = array( '', '09', '00' );
		}

		$now  = new DateTimeImmutable( 'now', $timezone );
		$next = $now->setTime( (int) $matches[1], (int) $matches[2], 0 );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}
}
