<?php
/**
 * Sanitisation helpers shared by the REST layer and the repositories.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Input sanitisers. Nothing reaches the database as raw input.
 */
final class Sanitize {

	/**
	 * Sanitises a rich-text field from the editor.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function rich_text( $value ): string {
		return wp_kses_post( is_string( $value ) ? $value : '' );
	}

	/**
	 * Sanitises a plain single-line field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function text( $value ): string {
		return sanitize_text_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * Sanitises a multi-line plain-text field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function textarea( $value ): string {
		return sanitize_textarea_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * Sanitises a GitHub issue URL.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty string when the value is not a github.com URL.
	 */
	public static function github_url( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$url = esc_url_raw( trim( $value ), array( 'https' ) );

		return self::is_github_url( $url ) ? $url : '';
	}

	/**
	 * Whether a URL is an https github.com address.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_github_url( $value ): bool {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}

		$parts = wp_parse_url( $value );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || 'https' !== $parts['scheme'] ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		return 'github.com' === $host || 'www.github.com' === $host;
	}

	/**
	 * Sanitises a list of positive integer IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return int[] Unique, re-indexed, positive integers.
	 */
	public static function id_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array_map( 'absint', $value );
		$ids = array_filter(
			$ids,
			static fn( int $id ): bool => $id > 0
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Validate_callback for a list of IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function is_id_list( $value ): bool {
		return is_array( $value ) || is_string( $value );
	}

	/**
	 * Sanitises a 24-hour "HH:MM" time string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function time_of_day( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		return preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value ) ? $value : '09:00';
	}
}
