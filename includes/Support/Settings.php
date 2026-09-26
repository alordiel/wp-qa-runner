<?php
/**
 * Plugin settings, stored as individual options.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors for the handful of options the plugin owns.
 */
final class Settings {

	public const OPTION_PAUSED              = 'qa_runner_notifications_paused';
	public const OPTION_DELETE_ON_UNINSTALL = 'qa_runner_delete_data_on_uninstall';

	/**
	 * Every option the plugin creates, with its default.
	 */
	public const DEFAULTS = array(
		self::OPTION_PAUSED              => false,
		self::OPTION_DELETE_ON_UNINSTALL => false,
	);

	/**
	 * Whether all outgoing notifications are paused.
	 *
	 * @return bool
	 */
	public static function notifications_paused(): bool {
		return (bool) get_option( self::OPTION_PAUSED, self::DEFAULTS[ self::OPTION_PAUSED ] );
	}

	/**
	 * Whether uninstalling should drop the QA tables.
	 *
	 * Off by default: QA history must never vanish from a routine plugin removal.
	 *
	 * @return bool
	 */
	public static function delete_data_on_uninstall(): bool {
		return (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, self::DEFAULTS[ self::OPTION_DELETE_ON_UNINSTALL ] );
	}

	/**
	 * The full settings payload for the API.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return array(
			'notificationsPaused'   => self::notifications_paused(),
			'deleteDataOnUninstall' => self::delete_data_on_uninstall(),
		);
	}

	/**
	 * Writes the defaults on activation without overwriting existing values.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		foreach ( self::DEFAULTS as $option => $value ) {
			add_option( $option, $value, '', false );
		}
	}

	/**
	 * Removes every option the plugin created.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		foreach ( array_keys( self::DEFAULTS ) as $option ) {
			delete_option( $option );
		}
	}
}
