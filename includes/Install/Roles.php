<?php
/**
 * Custom role and capability registration.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the qa_tester role and grants QA capabilities to existing roles.
 *
 * Everything in the plugin checks capabilities, never role names, so a site can move these
 * caps onto any role without touching the code.
 */
final class Roles {

	public const ROLE = 'qa_tester';

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
	 * Capabilities granted to the roles in self::ELEVATED_ROLES.
	 */
	private const ELEVATED_CAPS = array( self::CAP_VIEW, self::CAP_TEST, self::CAP_MANAGE );

	/**
	 * Capabilities granted to the qa_tester role.
	 */
	private const TESTER_CAPS = array( self::CAP_VIEW, self::CAP_TEST );

	/**
	 * Roles that receive the full capability set.
	 */
	private const ELEVATED_ROLES = array( 'administrator' );

	/**
	 * Installs the role when the stored version lags the constant above.
	 *
	 * Activation hooks are not guaranteed to fire: a database restored from an environment
	 * where the plugin was already in active_plugins, a symlinked plugin directory, or a
	 * per-site activation on a network all leave register_activation_hook() silent. Without
	 * the role nobody holds CAP_VIEW, so the admin menu disappears for everyone including
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
	 * Registers the role and capabilities. Called on activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		$subscriber = get_role( 'subscriber' );
		$caps       = $subscriber instanceof \WP_Role ? $subscriber->capabilities : array( 'read' => true );

		foreach ( self::TESTER_CAPS as $cap ) {
			$caps[ $cap ] = true;
		}

		// remove_role() then add_role() keeps the capability list in step across upgrades.
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'QA Tester', 'qa-runner' ), $caps );

		// add_cap() writes to the stored role definition, so a role dropped from
		// ELEVATED_ROLES would keep its capabilities forever unless they are taken back
		// here. Every role is visited on each version bump, which makes this constant the
		// single source of truth rather than a log of what was granted historically.
		foreach ( wp_roles()->role_objects as $role_name => $role ) {
			if ( self::ROLE === $role_name || ! $role instanceof \WP_Role ) {
				continue;
			}

			$elevated = in_array( $role_name, self::ELEVATED_ROLES, true );

			foreach ( self::ELEVATED_CAPS as $cap ) {
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
	 * Removes the role and every granted capability. Called from uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		remove_role( self::ROLE );

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::ELEVATED_CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}

		delete_option( self::VERSION_OPTION );
	}
}
