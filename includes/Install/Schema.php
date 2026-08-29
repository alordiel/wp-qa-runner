<?php
/**
 * Table definitions and version migrations.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin tables with dbDelta().
 *
 * Every status and priority column is VARCHAR(20) rather than ENUM: dbDelta rewrites ENUM
 * definitions on each run, producing a permanent phantom diff. The allowed values live in
 * QARunner\Support\Enum instead.
 */
final class Schema {

	public const VERSION_OPTION = 'qa_runner_db_version';

	/**
	 * Table base names, without the WordPress prefix.
	 */
	public const TABLES = array(
		'suites',
		'cases',
		'runs',
		'run_cases',
		'run_assignees',
		'results',
		'comments',
		'issues',
	);

	/**
	 * Fully qualified table name for a base name.
	 *
	 * Table names cannot be passed through $wpdb->prepare(), so they are always built here
	 * from $wpdb->prefix and a hard-coded suffix, never from request input.
	 *
	 * @param string $name One of self::TABLES.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		if ( ! in_array( $name, self::TABLES, true ) ) {
			return '';
		}

		return $wpdb->prefix . 'qa_' . $name;
	}

	/**
	 * Runs dbDelta for every table and stores the schema version.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, QA_RUNNER_DB_VERSION, false );
	}

	/**
	 * Re-runs the installer when the stored schema version lags the plugin constant.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $installed >= QA_RUNNER_DB_VERSION ) {
			return;
		}

		// dbDelta is additive and idempotent, so replaying every definition is the migration.
		self::install();
	}

	/**
	 * Drops every plugin table. Only ever called from uninstall.php behind an opt-in.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			// Table name is built from a hard-coded whitelist above; it cannot be prepared.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * CREATE TABLE statements in dbDelta's expected format.
	 *
	 * @return string[]
	 */
	private static function definitions(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();

		$suites        = self::table( 'suites' );
		$cases         = self::table( 'cases' );
		$runs          = self::table( 'runs' );
		$run_cases     = self::table( 'run_cases' );
		$run_assignees = self::table( 'run_assignees' );
		$results       = self::table( 'results' );
		$comments      = self::table( 'comments' );
		$issues        = self::table( 'issues' );

		return array(
			"CREATE TABLE {$suites} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL,
				slug varchar(190) NOT NULL,
				description text NULL,
				sort_order int NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY sort_order (sort_order)
			) {$collate};",

			"CREATE TABLE {$cases} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				suite_id bigint(20) unsigned NOT NULL,
				title varchar(255) NOT NULL,
				steps longtext NULL,
				expected longtext NULL,
				priority varchar(20) NOT NULL DEFAULT 'normal',
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY suite_active (suite_id,is_active),
				KEY priority (priority)
			) {$collate};",

			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(255) NOT NULL,
				environment varchar(100) NOT NULL,
				version varchar(100) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				notes text NULL,
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$collate};",

			"CREATE TABLE {$run_cases} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				case_id bigint(20) unsigned NOT NULL,
				sort_order int NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY run_case (run_id,case_id),
				KEY case_id (case_id)
			) {$collate};",

			"CREATE TABLE {$run_assignees} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				assigned_at datetime NOT NULL,
				notified_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_user (run_id,user_id),
				KEY user_id (user_id)
			) {$collate};",

			"CREATE TABLE {$results} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				case_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'untested',
				tested_by bigint(20) unsigned NULL,
				tested_at datetime NULL,
				in_progress_by bigint(20) unsigned NULL,
				in_progress_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_case (run_id,case_id),
				KEY run_status (run_id,status),
				KEY case_status (case_id,status)
			) {$collate};",

			"CREATE TABLE {$comments} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				result_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				content longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY result_id (result_id)
			) {$collate};",

			"CREATE TABLE {$issues} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				case_id bigint(20) unsigned NOT NULL,
				origin_run_id bigint(20) unsigned NULL,
				title varchar(255) NOT NULL,
				description longtext NULL,
				github_url varchar(500) NULL,
				status varchar(20) NOT NULL DEFAULT 'open',
				created_by bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				resolved_by bigint(20) unsigned NULL,
				resolved_at datetime NULL,
				resolution_note text NULL,
				PRIMARY KEY  (id),
				KEY case_status (case_id,status),
				KEY status (status)
			) {$collate};",
		);
	}
}
