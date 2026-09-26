<?php
/**
 * Plugin bootstrap.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner;

use QARunner\Admin\Assets;
use QARunner\Admin\Menu;
use QARunner\Install\Roles;
use QARunner\Install\Schema;
use QARunner\Install\Seeder;
use QARunner\Notification\Mailer;
use QARunner\Repository\CaseRepository;
use QARunner\Repository\CommentRepository;
use QARunner\Repository\IssueRepository;
use QARunner\Repository\ResultRepository;
use QARunner\Repository\RunRepository;
use QARunner\Repository\SuiteRepository;
use QARunner\Rest\CasesController;
use QARunner\Rest\CommentsController;
use QARunner\Rest\Controller;
use QARunner\Rest\IssuesController;
use QARunner\Rest\PingController;
use QARunner\Rest\ResultsController;
use QARunner\Rest\RunsController;
use QARunner\Rest\SettingsController;
use QARunner\Rest\SuitesController;
use QARunner\Rest\TeamController;
use QARunner\Rest\UsersController;
use QARunner\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's hooks together.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The admin menu page.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Returns the singleton, booting it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: use instance().
	 */
	private function __construct() {
		$this->menu = new Menu();
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	private function boot(): void {
		// The activation hook is best-effort, so every install step that a missing table or
		// role would break is replayed here behind its own version guard.
		add_action( 'init', array( Schema::class, 'maybe_upgrade' ) );
		add_action( 'init', array( Roles::class, 'maybe_install' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( 'admin_menu', array( $this->menu, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( new Assets( $this->menu ), 'enqueue' ) );
	}

	/**
	 * Registers every REST controller.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$suites   = new SuiteRepository();
		$cases    = new CaseRepository();
		$runs     = new RunRepository();
		$results  = new ResultRepository();
		$comments = new CommentRepository();
		$issues   = new IssueRepository();
		$mailer   = new Mailer( $runs, $results );

		$controllers = array(
			new PingController(),
			new SuitesController( $suites ),
			new CasesController( $cases, $issues ),
			new RunsController( $runs, $results, $cases, $comments, $issues, $mailer ),
			new ResultsController( $results, $runs, $mailer ),
			new CommentsController( $comments, $results, $runs ),
			new IssuesController( $issues, $cases ),
			new UsersController(),
			new SettingsController(),
			new TeamController(),
		);

		foreach ( $controllers as $controller ) {
			/**
			 * Every controller extends the shared base, which enforces a capability check
			 * on each route it registers.
			 *
			 * @var Controller $controller
			 */
			$controller->register_routes();
		}
	}

	/**
	 * Activation: schema, roles and options.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::install();
		Roles::install();
		Settings::install_defaults();
		Seeder::maybe_seed();
	}
}
