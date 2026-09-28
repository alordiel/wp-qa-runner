<?php
/**
 * Admin menu entry and app mount point.
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager\Admin;

use MandragoraQAManager\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level QA menu and renders the container the Vue app mounts into.
 */
final class Menu {

	/**
	 * Hook suffix returned by add_menu_page(), used to scope asset loading.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Registers the menu page.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Mandragora QA Test Manager', 'mandragora-qa-test-manager' ),
			__( 'Mandragora QA Test Manager', 'mandragora-qa-test-manager' ),
			Roles::CAP_VIEW,
			MQATM_SLUG,
			array( $this, 'render' ),
			'dashicons-yes-alt',
			58
		);
	}

	/**
	 * The hook suffix for the plugin's own screen.
	 *
	 * @return string
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Renders the mount point.
	 *
	 * The noscript block is the only server-rendered copy: everything else is the app.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap mqatm-wrap">
			<div id="mqatm-app">
				<p class="mqatm-boot"><?php esc_html_e( 'Loading Mandragora QA Test Manager…', 'mandragora-qa-test-manager' ); ?></p>
			</div>
			<noscript>
				<p><?php esc_html_e( 'Mandragora QA Test Manager needs JavaScript enabled.', 'mandragora-qa-test-manager' ); ?></p>
			</noscript>
		</div>
		<?php
	}
}
