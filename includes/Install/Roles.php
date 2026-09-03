<?php
/**
 * Custom roles and capability registration.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the two QA roles and grants the full capability set to existing elevated roles.
 *
 * The split is between the library and the run. A QA Admin owns the durable things —
 * suites, cases, settings — and is the only one who may create or archive them. A QA Tester
 * owns the work in flight: they see everything, run and manage runs, and claim cases within
 * a run, but the case library is read-only to them.
 *
 * Everything in the plugin checks capabilities, never role names, so a site can move these
 * caps onto any role without touching the code.
 */
final class Roles {

	public const ROLE_ADMIN  = 'qa_admin';
	public const ROLE_TESTER = 'qa_tester';

	/**
	 * Option holding the role version last written to the database.
	 */
	public const VERSION_OPTION = 'qa_runner_roles_version';

	/**
	 * Bumped whenever the capability sets below change, to force a re-install.
	 */
	public const VERSION = 2;

	public const CAP_VIEW   = 'qa_view_qa';
	public const CAP_TEST   = 'qa_run_tests';
	public const CAP_MANAGE = 'qa_manage_cases';

	/**
	 * Capabilities granted to the QA Admin role and to self::ELEVATED_ROLES.
	 */
	private const ADMIN_CAPS = array( self::CAP_VIEW, self::CAP_TEST, self::CAP_MANAGE );

	/**
	 * Capabilities granted to the QA Tester role.
	 *
	 * CAP_MANAGE is the whole difference: without it a tester cannot create, edit or archive
	 * a case or suite, cannot abandon a run and cannot change settings.
	 */
	private const TESTER_CAPS = array( self::CAP_VIEW, self::CAP_TEST );

	/**
	 * WordPress roles that receive the full capability set alongside the QA Admin role.
	 */
	private const ELEVATED_ROLES = array( 'administrator' );

	/**
	 * Installs the roles when the stored version lags the constant above.
	 *
	 * Activation hooks are not guaranteed to fire: a database restored from an environment
	 * where the plugin was already in active_plugins, a symlinked plugin directory, or a
	 * per-site activation on a network all leave register_activation_hook() silent. Without
	 * the roles nobody holds CAP_VIEW, so the admin menu disappears for everyone including
	 * administrators, which reads as "the plugin does nothing". Schema::maybe_upgrade()
	 * guards the tables the same way; this is the matching guard for capabilities.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Registers both roles and their capabilities. Called on activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		// remove_role() then add_role() keeps each capability list in step across upgrades.
		remove_role( self::ROLE_ADMIN );
		add_role( self::ROLE_ADMIN, __( 'QA Admin', 'qa-runner' ), self::role_caps( self::ADMIN_CAPS ) );

		remove_role( self::ROLE_TESTER );
		add_role( self::ROLE_TESTER, __( 'QA Tester', 'qa-runner' ), self::role_caps( self::TESTER_CAPS ) );

		// add_cap() writes to the stored role definition, so a role dropped from
		// ELEVATED_ROLES would keep its capabilities forever unless they are taken back
		// here. Every other role is visited on each install, which makes these constants the
		// single source of truth rather than a log of what was granted historically.
		foreach ( wp_roles()->role_objects as $role_name => $role ) {
			if ( in_array( $role_name, self::plugin_roles(), true ) || ! $role instanceof \WP_Role ) {
				continue;
			}

			$elevated = in_array( $role_name, self::ELEVATED_ROLES, true );

			foreach ( self::ADMIN_CAPS as $cap ) {
				if ( $elevated ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * Removes both roles and every granted capability. Called from uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( self::plugin_roles() as $role_name ) {
			remove_role( $role_name );
		}

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::ADMIN_CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * The roles this plugin owns outright, and therefore rewrites rather than reconciles.
	 *
	 * @return string[]
	 */
	private static function plugin_roles(): array {
		return array( self::ROLE_ADMIN, self::ROLE_TESTER );
	}

	/**
	 * Builds a role's capability map: subscriber's baseline plus the QA capabilities.
	 *
	 * Cloning subscriber is what gives these roles 'read', without which the user cannot
	 * reach wp-admin at all and so cannot reach the QA screens either.
	 *
	 * @param string[] $qa_caps QA capabilities to grant.
	 * @return array<string, bool>
	 */
	private static function role_caps( array $qa_caps ): array {
		$subscriber = get_role( 'subscriber' );
		$caps       = $subscriber instanceof \WP_Role ? $subscriber->capabilities : array( 'read' => true );

		foreach ( $qa_caps as $cap ) {
			$caps[ $cap ] = true;
		}

		return $caps;
	}
}
