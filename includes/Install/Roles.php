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

	public const CAP_VIEW   = 'qa_view_qa';
	public const CAP_TEST   = 'qa_run_tests';
	public const CAP_MANAGE = 'qa_manage_cases';

	/**
	 * Capabilities granted to administrators and editors.
	 */
	private const ELEVATED_CAPS = array( self::CAP_VIEW, self::CAP_TEST, self::CAP_MANAGE );

	/**
	 * Capabilities granted to the qa_tester role.
	 */
	private const TESTER_CAPS = array( self::CAP_VIEW, self::CAP_TEST );

	/**
	 * Roles that receive the full capability set.
	 */
	private const ELEVATED_ROLES = array( 'administrator', 'editor' );

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

		foreach ( self::ELEVATED_ROLES as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( self::ELEVATED_CAPS as $cap ) {
				$role->add_cap( $cap );
			}
		}
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
	}
}
