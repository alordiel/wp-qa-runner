<?php
/**
 * Allowed-value maps for every status and priority field.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for enumerated column values.
 *
 * The schema uses VARCHAR(20) rather than ENUM because dbDelta mangles ENUM columns on
 * subsequent migrations, so validation lives here and is shared by the repositories and
 * the REST validate_callbacks.
 */
final class Enum {

	public const CASE_PRIORITIES = array( 'critical', 'normal', 'low' );

	public const RUN_STATUSES = array( 'open', 'completed', 'abandoned' );

	public const RESULT_STATUSES = array( 'untested', 'pass', 'fail', 'blocked', 'skipped' );

	public const ISSUE_STATUSES = array( 'open', 'resolved', 'wontfix' );

	public const ENVIRONMENTS = array( 'local', 'staging', 'production' );

	/**
	 * Checks a value against one of the maps above.
	 *
	 * @param string   $value   Candidate value.
	 * @param string[] $allowed One of the class constants.
	 * @return bool
	 */
	public static function is_valid( string $value, array $allowed ): bool {
		return in_array( $value, $allowed, true );
	}

	/**
	 * Returns a validate_callback for register_rest_route args.
	 *
	 * @param string[] $allowed  One of the class constants.
	 * @param bool     $nullable Whether an empty string is acceptable.
	 * @return callable
	 */
	public static function validator( array $allowed, bool $nullable = false ): callable {
		return static function ( $value ) use ( $allowed, $nullable ): bool {
			if ( $nullable && ( null === $value || '' === $value ) ) {
				return true;
			}

			return is_string( $value ) && in_array( $value, $allowed, true );
		};
	}

	/**
	 * Coerces a value to a member of the allowed set, falling back to a default.
	 *
	 * @param mixed    $value    Candidate value.
	 * @param string[] $allowed  One of the class constants.
	 * @param string   $fallback Value used when the candidate is not allowed.
	 * @return string
	 */
	public static function coerce( $value, array $allowed, string $fallback ): string {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
