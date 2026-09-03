<?php
/**
 * Result routes: status and the soft lock.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Install\Roles;
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
			'/results/(?P<id>\d+)/assignees',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'      => $this->id_arg(),
					'user_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/results/(?P<id>\d+)/assignees/(?P<user_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'unassign' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'      => $this->id_arg(),
					'user_id' => $this->id_arg(),
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
	 * POST /results/{id}/assignees
	 *
	 * Self-assignment is the normal path: a tester on the run claims a case so the rest of
	 * the team can see it is spoken for. Assigning somebody else is a manager action.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function assign( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->guard( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user_id = (int) ( $request->get_param( 'user_id' ) ?? 0 );
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		$denied = $this->guard_assignment( (int) $result['run_id'], $user_id );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		if ( ! $this->results->assign( $id, $user_id ) ) {
			return $this->write_failed( __( 'That assignment could not be saved.', 'qa-runner' ) );
		}

		return $this->reload( $id );
	}

	/**
	 * DELETE /results/{id}/assignees/{user_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function unassign( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->guard( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$user_id = (int) $request->get_param( 'user_id' );

		// Dropping a case you no longer intend to test is always allowed, even if you have
		// since been taken off the run. Clearing somebody else is a call for the run's own
		// testers to make between themselves.
		if ( get_current_user_id() !== $user_id ) {
			$denied = $this->guard_caller( (int) $result['run_id'] );

			if ( is_wp_error( $denied ) ) {
				return $denied;
			}
		}

		if ( ! $this->results->unassign( $id, $user_id ) ) {
			return $this->write_failed( __( 'That assignment could not be removed.', 'qa-runner' ) );
		}

		return $this->reload( $id );
	}

	/**
	 * Confirms the caller may put this user on this run's case.
	 *
	 * Dividing the run's cases is the team's own business: anyone on the run may hand a case
	 * to anyone else on it. The boundary is the run, not the individual — what a tester
	 * cannot do is pull in somebody who was never put on the run in the first place.
	 *
	 * @param int $run_id  Run identifier.
	 * @param int $user_id Proposed assignee.
	 * @return true|\WP_Error
	 */
	private function guard_assignment( int $run_id, int $user_id ) {
		$denied = $this->guard_caller( $run_id );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		if ( ! user_can( $user_id, Roles::CAP_TEST ) ) {
			return $this->bad_request( __( 'That person cannot run tests.', 'qa-runner' ) );
		}

		// Managers are exempt: they pick up cases on runs they oversee without being listed
		// as an assignee on every one of them.
		if ( ! $this->runs->is_assignee( $run_id, $user_id ) && ! $this->can_manage() ) {
			return $this->bad_request( __( 'That person is not assigned to this run.', 'qa-runner' ) );
		}

		return true;
	}

	/**
	 * Confirms the caller has a stake in this run at all.
	 *
	 * @param int $run_id Run identifier.
	 * @return true|\WP_Error
	 */
	private function guard_caller( int $run_id ) {
		if ( ! $this->runs->is_assignee( $run_id, get_current_user_id() ) && ! $this->can_manage() ) {
			return $this->forbidden( __( 'You are not assigned to this run.', 'qa-runner' ) );
		}

		return true;
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
