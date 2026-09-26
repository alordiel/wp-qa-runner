<?php
/**
 * QA team routes: who holds a QA role, and granting or revoking one on existing users.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Install\Roles;
use WP_REST_Request;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Manages QA roles on existing WordPress users. It never creates a user.
 *
 * QA roles are added alongside whatever WordPress role the person already has, so revoking
 * one hands them back exactly the site access they had before. Administrators hold every QA
 * capability through their WordPress role and are listed read-only.
 *
 * Gated on promote_users rather than qa_manage_cases: handing out a role is a site
 * administrator's decision, and a QA Admin able to mint other QA Admins would make the
 * capability split meaningless.
 */
final class TeamController extends Controller {

	/**
	 * QA role reported for users whose access comes from their WordPress role.
	 */
	private const ROLE_SITE_ADMIN = 'administrator';

	/**
	 * Most candidates returned by one search.
	 */
	private const CANDIDATE_LIMIT = 50;

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/team',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array(
						'user_id' => $this->id_arg(),
						'role'    => $this->role_arg(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/team/candidates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'candidates' ),
				'permission_callback' => array( $this, 'can_manage_team' ),
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/team/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array(
						'id'   => $this->id_arg(),
						'role' => $this->role_arg(),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'can_manage_team' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
			)
		);
	}

	/**
	 * Permission callback: may grant and revoke QA roles.
	 *
	 * @return bool
	 */
	public function can_manage_team(): bool {
		return current_user_can( 'promote_users' );
	}

	/**
	 * GET /team
	 *
	 * Everyone with QA access: site administrators first, then by name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function index(): array {
		$users = get_users(
			array(
				'role__in' => array( self::ROLE_SITE_ADMIN, Roles::ROLE_ADMIN, Roles::ROLE_TESTER ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$members = array_map( array( $this, 'to_array' ), $users );

		usort(
			$members,
			static fn( array $a, array $b ): int => ( self::ROLE_SITE_ADMIN !== $a['qa_role'] ) <=> ( self::ROLE_SITE_ADMIN !== $b['qa_role'] )
		);

		return $members;
	}

	/**
	 * GET /team/candidates
	 *
	 * Existing users with no QA access yet, optionally narrowed by a search term.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates( WP_REST_Request $request ): array {
		$args = array(
			'role__not_in' => array( self::ROLE_SITE_ADMIN, Roles::ROLE_ADMIN, Roles::ROLE_TESTER ),
			'orderby'      => 'display_name',
			'order'        => 'ASC',
			'number'       => self::CANDIDATE_LIMIT,
		);

		$search = trim( (string) $request->get_param( 'search' ) );

		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		return array_map( array( $this, 'to_array' ), get_users( $args ) );
	}

	/**
	 * POST /team
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add( WP_REST_Request $request ) {
		$user = $this->editable_user( (int) $request->get_param( 'user_id' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( null !== $this->qa_role( $user ) ) {
			return $this->bad_request( __( 'That person is already on the QA team.', 'qa-runner' ) );
		}

		$this->set_role( $user, (string) $request->get_param( 'role' ) );

		return $this->to_array( $user );
	}

	/**
	 * PUT /team/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$user = $this->editable_user( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( null === $this->qa_role( $user ) ) {
			return $this->not_found( __( 'That person is not on the QA team.', 'qa-runner' ) );
		}

		$this->set_role( $user, (string) $request->get_param( 'role' ) );

		return $this->to_array( $user );
	}

	/**
	 * DELETE /team/{id}
	 *
	 * Takes away the QA role only. Run and case assignments are left as history. Someone
	 * left with no role at all falls back to subscriber, so they can still sign in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove( WP_REST_Request $request ) {
		$user = $this->editable_user( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$user->remove_role( Roles::ROLE_ADMIN );
		$user->remove_role( Roles::ROLE_TESTER );

		if ( empty( $user->roles ) ) {
			$user->set_role( 'subscriber' );
		}

		return array( 'removed' => true );
	}

	/**
	 * Loads a user whose QA role this request may change.
	 *
	 * @param int $user_id User identifier.
	 * @return WP_User|\WP_Error
	 */
	private function editable_user( int $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || ( is_multisite() && ! is_user_member_of_blog( $user_id ) ) ) {
			return $this->not_found( __( 'That user no longer exists.', 'qa-runner' ) );
		}

		if ( get_current_user_id() === $user_id ) {
			return $this->forbidden( __( 'You cannot change your own QA role.', 'qa-runner' ) );
		}

		if ( self::ROLE_SITE_ADMIN === $this->qa_role( $user ) ) {
			return $this->bad_request( __( 'Administrators have full QA access through their WordPress role.', 'qa-runner' ) );
		}

		return $user;
	}

	/**
	 * Gives a user exactly one QA role, keeping their other roles.
	 *
	 * @param WP_User $user User.
	 * @param string  $role Roles::ROLE_ADMIN or Roles::ROLE_TESTER.
	 * @return void
	 */
	private function set_role( WP_User $user, string $role ): void {
		$other = Roles::ROLE_ADMIN === $role ? Roles::ROLE_TESTER : Roles::ROLE_ADMIN;

		$user->remove_role( $other );

		if ( ! in_array( $role, $user->roles, true ) ) {
			$user->add_role( $role );
		}
	}

	/**
	 * The user's QA role, most powerful first.
	 *
	 * @param WP_User $user User.
	 * @return string|null 'administrator', a Roles::ROLE_* constant, or null for no access.
	 */
	private function qa_role( WP_User $user ): ?string {
		foreach ( array( self::ROLE_SITE_ADMIN, Roles::ROLE_ADMIN, Roles::ROLE_TESTER ) as $role ) {
			if ( in_array( $role, $user->roles, true ) ) {
				return $role;
			}
		}

		return null;
	}

	/**
	 * Casts a user to the API shape.
	 *
	 * @param WP_User $user User.
	 * @return array<string, mixed>
	 */
	private function to_array( WP_User $user ): array {
		$names    = wp_roles()->get_names();
		$wp_roles = array_values(
			array_diff( $user->roles, array( Roles::ROLE_ADMIN, Roles::ROLE_TESTER ) )
		);

		return array(
			'id'       => (int) $user->ID,
			'name'     => (string) $user->display_name,
			'email'    => (string) $user->user_email,
			'avatar'   => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
			'qa_role'  => $this->qa_role( $user ),
			'wp_roles' => array_map(
				static fn( string $role ): string => isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role,
				$wp_roles
			),
		);
	}

	/**
	 * Arg definition for an assignable QA role.
	 *
	 * @return array<string, mixed>
	 */
	private function role_arg(): array {
		return array(
			'required' => true,
			'type'     => 'string',
			'enum'     => array( Roles::ROLE_ADMIN, Roles::ROLE_TESTER ),
		);
	}
}
