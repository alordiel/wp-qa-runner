<?php
/**
 * Uninstall routine.
 *
 * The role, capabilities and options always go. The tables only go when the site has
 * explicitly opted in: QA history must never vanish because someone removed the plugin to
 * troubleshoot something else.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner;

use QARunner\Install\Roles;
use QARunner\Install\Schema;
use QARunner\Notification\DigestCron;
use QARunner\Support\Settings;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The main plugin file is not loaded during uninstall, and requiring it would re-register
// hooks for no reason, so the classes this routine needs are autoloaded directly.
defined( 'QA_RUNNER_PATH' ) || define( 'QA_RUNNER_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$path = QA_RUNNER_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

$qa_runner_drop_tables = Settings::delete_data_on_uninstall();

DigestCron::unschedule();
Roles::uninstall();

if ( $qa_runner_drop_tables ) {
	Schema::drop();
}

Settings::delete_all();
