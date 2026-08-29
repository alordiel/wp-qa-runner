<?php
/**
 * Settings routes.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Notification\DigestCron;
use QARunner\Support\Sanitize;
use QARunner\Support\Settings;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the handful of plugin options.
 */
final class SettingsController extends Controller {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'digestTime'            => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => array( Sanitize::class, 'time_of_day' ),
							'validate_callback' => static fn( $value ): bool => is_string( $value )
								&& (bool) preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value ),
						),
						'notificationsPaused'   => array(
							'required'          => false,
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'deleteDataOnUninstall' => array(
							'required'          => false,
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /settings
	 *
	 * @return array<string, mixed>
	 */
	public function show(): array {
		return Settings::all();
	}

	/**
	 * PUT /settings
	 *
	 * Changing the send time reschedules the cron: WP-Cron fires relative to the timestamp
	 * it was handed, so writing the option alone would change nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function update( WP_REST_Request $request ): array {
		if ( $request->has_param( 'digestTime' ) ) {
			$time     = (string) $request->get_param( 'digestTime' );
			$previous = Settings::digest_time();

			update_option( Settings::OPTION_DIGEST_TIME, $time, false );

			if ( $time !== $previous ) {
				DigestCron::schedule();
			}
		}

		if ( $request->has_param( 'notificationsPaused' ) ) {
			update_option( Settings::OPTION_PAUSED, (bool) $request->get_param( 'notificationsPaused' ), false );
		}

		if ( $request->has_param( 'deleteDataOnUninstall' ) ) {
			update_option( Settings::OPTION_DELETE_ON_UNINSTALL, (bool) $request->get_param( 'deleteDataOnUninstall' ), false );
		}

		return Settings::all();
	}
}
