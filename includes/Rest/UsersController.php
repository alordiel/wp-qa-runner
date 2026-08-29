<?php
/**
 * User routes for the assignee pickers.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the people who can be assigned to a run.
 */
final class UsersController extends Controller {

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);
	}

	/**
	 * GET /users
	 *
	 * Only users who hold qa_run_tests: assigning anyone else would produce an email they
	 * cannot act on.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function index(): array {
		$users = get_users(
			array(
				'capability' => Roles::CAP_TEST,
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);

		return array_map(
			static function ( $user ): array {
				$id = (int) $user->ID;

				return array(
					'id'     => $id,
					'name'   => (string) $user->display_name,
					'avatar' => get_avatar_url( $id, array( 'size' => 48 ) ),
				);
			},
			$users
		);
	}
}
