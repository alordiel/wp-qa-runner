<?php
/**
 * Suite routes.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Repository\SuiteRepository;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for test suites.
 */
final class SuitesController extends Controller {

	/**
	 * Suite repository.
	 *
	 * @var SuiteRepository
	 */
	private SuiteRepository $suites;

	/**
	 * Constructor.
	 *
	 * @param SuiteRepository $suites Suite repository.
	 */
	public function __construct( SuiteRepository $suites ) {
		$this->suites = $suites;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/suites',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'name'        => $this->text_arg( true ),
						'description' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'sort_order'  => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/suites/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'id'          => $this->id_arg(),
						'name'        => $this->text_arg(),
						'description' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'sort_order'  => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
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
	 * GET /suites
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function index(): array {
		return $this->suites->all();
	}

	/**
	 * POST /suites
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$id = $this->suites->create(
			array(
				'name'        => (string) $request->get_param( 'name' ),
				'description' => (string) $request->get_param( 'description' ),
				'sort_order'  => (int) $request->get_param( 'sort_order' ),
			)
		);

		if ( 0 === $id ) {
			return $this->write_failed( __( 'The suite could not be saved.', 'qa-runner' ) );
		}

		return $this->suites->find( $id );
	}

	/**
	 * PUT /suites/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->suites->find( $id ) ) {
			return $this->not_found( __( 'That suite no longer exists.', 'qa-runner' ) );
		}

		$data = array();

		foreach ( array( 'name', 'description', 'sort_order' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		if ( ! $this->suites->update( $id, $data ) ) {
			return $this->write_failed( __( 'The suite could not be saved.', 'qa-runner' ) );
		}

		return $this->suites->find( $id );
	}

	/**
	 * DELETE /suites/{id}
	 *
	 * Only an empty suite can be deleted: cases carry the history that makes runs
	 * comparable, so they are never removed as a side effect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->suites->find( $id ) ) {
			return $this->not_found( __( 'That suite no longer exists.', 'qa-runner' ) );
		}

		if ( $this->suites->case_count( $id ) > 0 ) {
			return $this->bad_request( __( 'Move or delete this suite\'s cases before deleting the suite.', 'qa-runner' ) );
		}

		if ( ! $this->suites->delete( $id ) ) {
			return $this->write_failed( __( 'The suite could not be deleted.', 'qa-runner' ) );
		}

		return array( 'deleted' => true );
	}
}
