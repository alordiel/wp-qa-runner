<?php
/**
 * Plugin bootstrap.
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager;

use MandragoraQAManager\Admin\Assets;
use MandragoraQAManager\Admin\Menu;
use MandragoraQAManager\Install\Roles;
use MandragoraQAManager\Install\Schema;
use MandragoraQAManager\Install\Seeder;
use MandragoraQAManager\Notification\Mailer;
use MandragoraQAManager\Repository\CaseRepository;
use MandragoraQAManager\Repository\CommentRepository;
use MandragoraQAManager\Repository\IssueRepository;
use MandragoraQAManager\Repository\ResultRepository;
use MandragoraQAManager\Repository\RunRepository;
use MandragoraQAManager\Repository\SuiteRepository;
use MandragoraQAManager\Rest\CasesController;
use MandragoraQAManager\Rest\CommentsController;
use MandragoraQAManager\Rest\Controller;
use MandragoraQAManager\Rest\IssuesController;
use MandragoraQAManager\Rest\PingController;
use MandragoraQAManager\Rest\ResultsController;
use MandragoraQAManager\Rest\RunsController;
use MandragoraQAManager\Rest\SettingsController;
use MandragoraQAManager\Rest\SuitesController;
use MandragoraQAManager\Rest\TeamController;
use MandragoraQAManager\Rest\UsersController;
use MandragoraQAManager\Support\Settings;

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
