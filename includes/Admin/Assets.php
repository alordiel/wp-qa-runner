<?php
/**
 * Admin asset loading and bootstrap data.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Admin;

use QARunner\Install\Roles;
use QARunner\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Vite build on the plugin's own screen and nowhere else.
 *
 * The Vite config in this repo emits an IIFE bundle at a fixed path rather than a hashed
 * ES module, so there is no manifest to read and no script_loader_tag filter adding
 * type="module" — WordPress can enqueue it as an ordinary classic script. Cache busting
 * comes from QA_RUNNER_VERSION.
 */
final class Assets {

	public const HANDLE = 'qa-runner-app';

	private const SCRIPT = 'build/qa-admin-page.js';
	private const STYLE  = 'build/qa-admin-page.css';

	/**
	 * The plugin's menu page.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Constructor.
	 *
	 * @param Menu $menu The plugin's menu page.
	 */
	public function __construct( Menu $menu ) {
		$this->menu = $menu;
	}

	/**
	 * Enqueues the app, but only on the QA Runner screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->menu->hook_suffix() || '' === $this->menu->hook_suffix() ) {
			return;
		}

		$script = QA_RUNNER_PATH . self::SCRIPT;
        error_log($this->asset_version( $script ));
		if ( ! is_readable( $script ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );

			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			QA_RUNNER_URL . self::SCRIPT,
			array(),
			$this->asset_version( $script ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$style = QA_RUNNER_PATH . self::STYLE;

		if ( is_readable( $style ) ) {
			wp_enqueue_style(
				self::HANDLE,
				QA_RUNNER_URL . self::STYLE,
				array(),
				$this->asset_version( $style )
			);
		}

		// wp_localize_script() casts every scalar to a string, which would turn the caps into
		// "1"/"" and the user ID into "3" and break strict comparisons in the app.
		wp_add_inline_script(
			self::HANDLE,
			'window.qaRunner = ' . wp_json_encode( $this->bootstrap_data() ) . ';',
			'before'
		);

		wp_set_script_translations( self::HANDLE, 'qa-runner', QA_RUNNER_PATH . 'languages' );
	}

	/**
	 * Cache-busting version string for one built asset.
	 *
	 * The bundle lives at a fixed, unhashed path, so this query string is the only thing
	 * that tells a browser the file changed. QA_RUNNER_VERSION on its own is not enough:
	 * shipping a rebuilt bundle without remembering to bump the constant leaves every
	 * browser, proxy and page cache serving the copy it already has, and reinstalling the
	 * plugin does not help because the URL never changes. The file's own mtime cannot be
	 * forgotten, and an rsync or archive deploy moves it forward on its own.
	 *
	 * @param string $path Absolute path to the asset.
	 * @return string
	 */
	private function asset_version( string $path ): string {
		$mtime = is_readable( $path ) ? filemtime( $path ) : false;

		return false !== $mtime ? (string) $mtime : QA_RUNNER_VERSION;
	}

	/**
	 * Data handed to the app on window.qaRunner.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap_data(): array {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		return array(
			'root'         => esc_url_raw( rest_url( Controller::REST_NAMESPACE . '/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'currentUser'  => array(
				'id'     => (int) $user_id,
				'name'   => $user->display_name,
				'avatar' => get_avatar_url( $user_id, array( 'size' => 48 ) ),
			),
			'caps'         => array(
				'manageCases' => current_user_can( Roles::CAP_MANAGE ),
				'runTests'    => current_user_can( Roles::CAP_TEST ),
			),
			'adminUrl'     => admin_url( 'admin.php?page=' . QA_RUNNER_SLUG ),
			'environments' => \QARunner\Support\Enum::ENVIRONMENTS,
			'timezone'     => wp_timezone_string(),
		);
	}

	/**
	 * Warns an administrator when the committed build is missing.
	 *
	 * @return void
	 */
	public function render_missing_build_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: npm command to run. */
					esc_html__( 'QA Runner cannot find its built assets. Run %s in the plugin directory.', 'qa-runner' ),
					'<code>npm run build</code>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
