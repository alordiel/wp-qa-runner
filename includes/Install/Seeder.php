<?php
/**
 * Optional demo content for local development.
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager\Install;

use MandragoraQAManager\Repository\CaseRepository;
use MandragoraQAManager\Repository\SuiteRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds one demo suite and three cases so a fresh install is not an empty screen.
 *
 * Only runs when WP_DEBUG is on and the case library is empty.
 */
final class Seeder {

	/**
	 * Inserts the demo suite and cases if it is safe to do so.
	 *
	 * @return void
	 */
	public static function maybe_seed(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$suites = new SuiteRepository();
		$cases  = new CaseRepository();

		if ( ! empty( $suites->all() ) ) {
			return;
		}

		$suite_id = $suites->create(
			array(
				'name'        => __( 'Login', 'mandragora-qa-test-manager' ),
				'description' => __( 'Authentication and session handling.', 'mandragora-qa-test-manager' ),
				'sort_order'  => 0,
			)
		);

		if ( 0 === $suite_id ) {
			return;
		}

		$user_id = get_current_user_id();

		$demo = array(
			array(
				'title'    => __( 'Log in with a valid account', 'mandragora-qa-test-manager' ),
				'steps'    => '<ol><li>' . esc_html__( 'Open the login page.', 'mandragora-qa-test-manager' ) . '</li><li>' . esc_html__( 'Enter a valid email and password.', 'mandragora-qa-test-manager' ) . '</li><li>' . esc_html__( 'Submit the form.', 'mandragora-qa-test-manager' ) . '</li></ol>',
				'expected' => '<p>' . esc_html__( 'You land on the dashboard and your name appears in the header.', 'mandragora-qa-test-manager' ) . '</p>',
				'priority' => 'critical',
			),
			array(
				'title'    => __( 'Reject an incorrect password', 'mandragora-qa-test-manager' ),
				'steps'    => '<ol><li>' . esc_html__( 'Open the login page.', 'mandragora-qa-test-manager' ) . '</li><li>' . esc_html__( 'Enter a valid email with the wrong password.', 'mandragora-qa-test-manager' ) . '</li></ol>',
				'expected' => '<p>' . esc_html__( 'An inline error appears and no session is created.', 'mandragora-qa-test-manager' ) . '</p>',
				'priority' => 'critical',
			),
			array(
				'title'    => __( 'Request a password reset email', 'mandragora-qa-test-manager' ),
				'steps'    => '<ol><li>' . esc_html__( 'Choose "Lost your password?".', 'mandragora-qa-test-manager' ) . '</li><li>' . esc_html__( 'Enter a registered email address.', 'mandragora-qa-test-manager' ) . '</li></ol>',
				'expected' => '<p>' . esc_html__( 'A reset email arrives within a minute and its link opens the reset form.', 'mandragora-qa-test-manager' ) . '</p>',
				'priority' => 'normal',
			),
		);

		foreach ( $demo as $index => $case ) {
			$cases->create(
				array(
					'suite_id'   => $suite_id,
					'title'      => $case['title'],
					'steps'      => $case['steps'],
					'expected'   => $case['expected'],
					'priority'   => $case['priority'],
					'created_by' => $user_id,
				)
			);
			unset( $index );
		}
	}
}
