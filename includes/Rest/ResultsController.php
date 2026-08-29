<?php
/**
 * Result routes: status and the soft lock.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Repository\ResultRepository;
use QARunner\Repository\RunRepository;
use QARunner\Support\Enum;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to a single result.
 *
 * Every write here first checks that the owning run is still open and returns 409 if it is
 * not. That check, and the identical one on comments, is what "a run is never edited once
 * completed" actually means.
 */
final class ResultsController extends Controller {

	/**
	 * Result repository.
	 *
	 * @var ResultRepository
	 */
	private ResultRepository $results;

	/**
	 * Run repository.
	 *
	 * @var RunRepository
	 */
	private RunRepository $runs;

	/**
	 * Constructor.
	 *
	 * @param ResultRepository $results Result repository.
	 * @param RunRepository    $runs    Run repository.
	 */
	public function __construct( ResultRepository $results, RunRepository $runs ) {
		$this->results = $results;
		$this->runs    = $runs;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/results/(?P<id>\d+)',
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'     => $this->id_arg(),
					'status' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => Enum::validator( Enum::RESULT_STATUSES ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/results/(?P<id>\d+)/lock',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'lock' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unlock' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
			)
		);
	}

	/**
	 * PUT /results/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->guard( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$updated = $this->results->set_status(
			$id,
			(string) $request->get_param( 'status' ),
			get_current_user_id()
		);

		if ( ! $updated ) {
			return $this->write_failed( __( 'The result could not be saved.', 'qa-runner' ) );
		}

		return $this->reload( $id );
	}

	/**
	 * PUT /results/{id}/lock
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function lock( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->guard( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->results->lock( $id, get_current_user_id() );

		return $this->reload( $id );
	}

	/**
	 * DELETE /results/{id}/lock
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function unlock( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->guard( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->results->unlock( $id, get_current_user_id() );

		return $this->reload( $id );
	}

	/**
	 * Loads a result and confirms its run is still open.
	 *
	 * @param int $id Result identifier.
	 * @return array<string, mixed>|\WP_Error Raw result row on success.
	 */
	private function guard( int $id ) {
		$result = $this->results->find_raw( $id );

		if ( null === $result ) {
			return $this->not_found( __( 'That result no longer exists.', 'qa-runner' ) );
		}

		if ( ! $this->runs->is_open( (int) $result['run_id'] ) ) {
			return $this->run_closed();
		}

		return $result;
	}

	/**
	 * Re-reads one result in the shape the list view uses.
	 *
	 * @param int $id Result identifier.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function reload( int $id ) {
		$result = $this->results->find_for_api( $id );

		if ( null === $result ) {
			return $this->not_found( __( 'That result no longer exists.', 'qa-runner' ) );
		}

		return $result;
	}
}
