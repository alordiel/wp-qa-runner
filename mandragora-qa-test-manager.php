<?php
/**
 * Plugin Name:       Mandragora QA Test Manager
 * Plugin URI:        https://github.com/alordiel/mandragora-qa-test-manager
 * Description:       Manual QA test runs for a small internal team: suites, cases, runs, results, comments and issues.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Alexander Vasilev
 * Author URI:        https://profiles.wordpress.org/alordiel/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mandragora-qa-test-manager
 * Domain Path:       /languages
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager;

defined( 'ABSPATH' ) || exit;

define( 'MQATM_VERSION', '1.0.0' );
define( 'MQATM_DB_VERSION', 3 );
define( 'MQATM_FILE', __FILE__ );
define( 'MQATM_PATH', plugin_dir_path( __FILE__ ) );
define( 'MQATM_URL', plugin_dir_url( __FILE__ ) );
define( 'MQATM_SLUG', 'mandragora-qa-test-manager' );

/**
 * PSR-4 autoloader for the MandragoraQAManager namespace.
 *
 * The plugin has no runtime Composer dependencies, so it ships without a vendor directory
 * and needs no install step.
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
	$path     = MQATM_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( __NAMESPACE__ . '\\autoload' );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'instance' ) );
