<?php
/**
 * Optional demo content for local development.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Install;

use QARunner\Repository\CaseRepository;
use QARunner\Repository\SuiteRepository;

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
				'name'        => __( 'Login', 'qa-runner' ),
				'description' => __( 'Authentication and session handling.', 'qa-runner' ),
				'sort_order'  => 0,
			)
		);

		if ( 0 === $suite_id ) {
			return;
		}

		$user_id = get_current_user_id();

		$demo = array(
			array(
				'title'    => __( 'Log in with a valid account', 'qa-runner' ),
				'steps'    => '<ol><li>' . esc_html__( 'Open the login page.', 'qa-runner' ) . '</li><li>' . esc_html__( 'Enter a valid email and password.', 'qa-runner' ) . '</li><li>' . esc_html__( 'Submit the form.', 'qa-runner' ) . '</li></ol>',
				'expected' => '<p>' . esc_html__( 'You land on the dashboard and your name appears in the header.', 'qa-runner' ) . '</p>',
				'priority' => 'critical',
			),
			array(
				'title'    => __( 'Reject an incorrect password', 'qa-runner' ),
				'steps'    => '<ol><li>' . esc_html__( 'Open the login page.', 'qa-runner' ) . '</li><li>' . esc_html__( 'Enter a valid email with the wrong password.', 'qa-runner' ) . '</li></ol>',
				'expected' => '<p>' . esc_html__( 'An inline error appears and no session is created.', 'qa-runner' ) . '</p>',
				'priority' => 'critical',
			),
			array(
				'title'    => __( 'Request a password reset email', 'qa-runner' ),
				'steps'    => '<ol><li>' . esc_html__( 'Choose "Lost your password?".', 'qa-runner' ) . '</li><li>' . esc_html__( 'Enter a registered email address.', 'qa-runner' ) . '</li></ol>',
				'expected' => '<p>' . esc_html__( 'A reset email arrives within a minute and its link opens the reset form.', 'qa-runner' ) . '</p>',
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
