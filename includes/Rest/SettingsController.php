<?php
/**
 * Settings routes.
 *
 * @package MandragoraQAManager
 */

declare( strict_types=1 );

namespace MandragoraQAManager\Rest;

use MandragoraQAManager\Support\Settings;
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
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function update( WP_REST_Request $request ): array {
		if ( $request->has_param( 'notificationsPaused' ) ) {
			update_option( Settings::OPTION_PAUSED, (bool) $request->get_param( 'notificationsPaused' ), false );
		}

		if ( $request->has_param( 'deleteDataOnUninstall' ) ) {
			update_option( Settings::OPTION_DELETE_ON_UNINSTALL, (bool) $request->get_param( 'deleteDataOnUninstall' ), false );
		}

		return Settings::all();
	}
}
