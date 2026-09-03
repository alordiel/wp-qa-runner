<?php
/**
 * Plugin Name:       QA Runner
 * Plugin URI:        https://example.com/qa-runner
 * Description:       Manual QA test runs for a small internal team: suites, cases, runs, results, comments and issues.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Internal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       qa-runner
 * Domain Path:       /languages
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner;

defined( 'ABSPATH' ) || exit;

define( 'QA_RUNNER_VERSION', '1.1.0' );
define( 'QA_RUNNER_DB_VERSION', 2 );
define( 'QA_RUNNER_FILE', __FILE__ );
define( 'QA_RUNNER_PATH', plugin_dir_path( __FILE__ ) );
define( 'QA_RUNNER_URL', plugin_dir_url( __FILE__ ) );
define( 'QA_RUNNER_SLUG', 'qa-runner' );

/**
 * PSR-4 autoloader for the QARunner namespace.
 *
 * Composer is optional here: the plugin ships without a vendor directory so an rsync
 * deploy needs no install step. If a vendor autoloader exists it is loaded as well.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function autoload( string $class_name ): void {
	$prefix = __NAMESPACE__ . '\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$path     = QA_RUNNER_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( __NAMESPACE__ . '\\autoload' );

if ( is_readable( QA_RUNNER_PATH . 'vendor/autoload.php' ) ) {
	require_once QA_RUNNER_PATH . 'vendor/autoload.php';
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'instance' ) );
