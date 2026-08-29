<?php
/**
 * Connectivity smoke test.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * A trivial authenticated route used to prove the REST namespace is reachable.
 *
 * If this 403s from the browser with a valid nonce, the cause is a server-side filter on
 * the REST API rather than anything in this plugin — worth resolving before debugging the
 * app itself.
 */
final class PingController extends Controller {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);
	}

	/**
	 * GET /ping
	 *
	 * @return array<string, mixed>
	 */
	public function ping(): array {
		return array(
			'ok'      => true,
			'user'    => get_current_user_id(),
			'version' => QA_RUNNER_VERSION,
		);
	}
}
