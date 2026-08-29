<?php
/**
 * Admin menu entry and app mount point.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Admin;

use QARunner\Install\Roles;

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
			__( 'QA Runner', 'qa-runner' ),
			__( 'QA Runner', 'qa-runner' ),
			Roles::CAP_VIEW,
			QA_RUNNER_SLUG,
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
		<div class="wrap qa-runner-wrap">
			<div id="qa-runner-app">
				<p class="qa-runner-boot"><?php esc_html_e( 'Loading QA Runner…', 'qa-runner' ); ?></p>
			</div>
			<noscript>
				<p><?php esc_html_e( 'QA Runner needs JavaScript enabled.', 'qa-runner' ); ?></p>
			</noscript>
		</div>
		<?php
	}
}
