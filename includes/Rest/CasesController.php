<?php
/**
 * Case library routes.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Repository\CaseRepository;
use QARunner\Repository\IssueRepository;
use QARunner\Support\Enum;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the case library.
 *
 * A case never carries a status. GET /cases/{id} attaches the case's open issues so the
 * test view can show them without a second round trip.
 */
final class CasesController extends Controller {

	/**
	 * Case repository.
	 *
	 * @var CaseRepository
	 */
	private CaseRepository $cases;

	/**
	 * Issue repository.
	 *
	 * @var IssueRepository
	 */
	private IssueRepository $issues;

	/**
	 * Constructor.
	 *
	 * @param CaseRepository  $cases  Case repository.
	 * @param IssueRepository $issues Issue repository.
	 */
	public function __construct( CaseRepository $cases, IssueRepository $issues ) {
		$this->cases  = $cases;
		$this->issues = $issues;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/cases',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'suite_id' => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'priority' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => Enum::validator( Enum::CASE_PRIORITIES, true ),
						),
						'search'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'active'   => array(
							'required'          => false,
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $this->write_args( true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cases/(?P<id>\d+)/clone',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clone_case' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'    => $this->id_arg(),
					'title' => $this->text_arg(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cases/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => $this->id_arg() ) + $this->write_args( false ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
			)
		);
	}

	/**
	 * GET /cases
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	public function index( WP_REST_Request $request ): array {
		return $this->cases->query(
			array(
				'suite_id' => (int) $request->get_param( 'suite_id' ),
				'priority' => (string) $request->get_param( 'priority' ),
				'search'   => (string) $request->get_param( 'search' ),
				'active'   => $this->optional_bool( $request, 'active' ),
			)
		);
	}

	/**
	 * GET /cases/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$case = $this->cases->find( $id );

		if ( null === $case ) {
			return $this->not_found( __( 'That case no longer exists.', 'qa-runner' ) );
		}

		// Only open issues: a tester needs to know what is currently broken, not everything
		// that was ever wrong with this case.
		$case['issues'] = $this->issues->for_case( $id, 'open' );

		return $case;
	}

	/**
	 * POST /cases
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$id = $this->cases->create(
			array(
				'suite_id'   => (int) $request->get_param( 'suite_id' ),
				'title'      => (string) $request->get_param( 'title' ),
				'steps'      => (string) $request->get_param( 'steps' ),
				'expected'   => (string) $request->get_param( 'expected' ),
				'priority'   => (string) $request->get_param( 'priority' ),
				'created_by' => get_current_user_id(),
			)
		);

		if ( 0 === $id ) {
			return $this->write_failed( __( 'The case could not be saved.', 'qa-runner' ) );
		}

		return $this->cases->find( $id );
	}

	/**
	 * PUT /cases/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->cases->find( $id ) ) {
			return $this->not_found( __( 'That case no longer exists.', 'qa-runner' ) );
		}

		$data = array();

		foreach ( array( 'suite_id', 'title', 'steps', 'expected', 'priority', 'is_active' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		if ( ! $this->cases->update( $id, $data ) ) {
			return $this->write_failed( __( 'The case could not be saved.', 'qa-runner' ) );
		}

		return $this->cases->find( $id );
	}

	/**
	 * POST /cases/{id}/clone
	 *
	 * A copy of the instructions under a new title, ready to be edited into a variant. The
	 * source case is untouched, and the clone starts active whatever the source's state is:
	 * cloning an archived case is how a retired case gets revived as something new.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function clone_case( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$source = $this->cases->find( $id );

		if ( null === $source ) {
			return $this->not_found( __( 'That case no longer exists.', 'qa-runner' ) );
		}

		$title = (string) $request->get_param( 'title' );

		if ( '' === trim( $title ) ) {
			$title = $this->cloned_title( (string) $source['title'] );
		}

		$new_id = $this->cases->create(
			array(
				'suite_id'   => (int) $source['suite_id'],
				'title'      => $title,
				'steps'      => (string) ( $source['steps'] ?? '' ),
				'expected'   => (string) ( $source['expected'] ?? '' ),
				'priority'   => (string) $source['priority'],
				'is_active'  => true,
				'created_by' => get_current_user_id(),
			)
		);

		if ( 0 === $new_id ) {
			return $this->write_failed( __( 'The case could not be cloned.', 'qa-runner' ) );
		}

		return $this->cases->find( $new_id );
	}

	/**
	 * DELETE /cases/{id}
	 *
	 * Soft delete only. A case with results is the subject of that history, so its row has
	 * to survive; is_active = 0 keeps it out of new runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->cases->find( $id ) ) {
			return $this->not_found( __( 'That case no longer exists.', 'qa-runner' ) );
		}

		if ( ! $this->cases->deactivate( $id ) ) {
			return $this->write_failed( __( 'The case could not be archived.', 'qa-runner' ) );
		}

		return $this->cases->find( $id );
	}

	/**
	 * Shared arg definitions for create and update.
	 *
	 * @param bool $creating Whether this is the create route.
	 * @return array<string, array<string, mixed>>
	 */
	private function write_args( bool $creating ): array {
		return array(
			'suite_id'  => array(
				'required'          => $creating,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
			),
			'title'     => $this->text_arg( $creating ),
			'steps'     => $this->rich_text_arg(),
			'expected'  => $this->rich_text_arg(),
			// The default is only declared when creating. Declaring it on update — even as
			// null — makes has_param() true for every request, which would quietly reset the
			// priority of any case saved without one.
			'priority'  => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => Enum::validator( Enum::CASE_PRIORITIES, true ),
			) + ( $creating ? array( 'default' => 'normal' ) : array() ),
			'is_active' => array(
				'required'          => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Builds the title of a clone.
	 *
	 * The column holds 255 characters, so the source title is trimmed rather than the
	 * suffix: a clone the user cannot tell apart from its source is worse than a short one.
	 *
	 * @param string $title Source case title.
	 * @return string
	 */
	private function cloned_title( string $title ): string {
		$suffix = __( ' (cloned)', 'qa-runner' );
		$room   = 255 - mb_strlen( $suffix );

		if ( mb_strlen( $title ) > $room ) {
			$title = rtrim( mb_substr( $title, 0, $room ) );
		}

		return $title . $suffix;
	}
}
