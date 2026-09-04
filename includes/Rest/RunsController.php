<?php
/**
 * Run routes.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Notification\Mailer;
use QARunner\Repository\CaseRepository;
use QARunner\Repository\ResultRepository;
use QARunner\Repository\RunRepository;
use QARunner\Support\Enum;
use QARunner\Support\Sanitize;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Runs, their case selection, their assignees and their working result list.
 *
 * Runs are independent of one another: nothing here touches another run.
 */
final class RunsController extends Controller {

	/**
	 * Run repository.
	 *
	 * @var RunRepository
	 */
	private RunRepository $runs;

	/**
	 * Result repository.
	 *
	 * @var ResultRepository
	 */
	private ResultRepository $results;

	/**
	 * Case repository.
	 *
	 * @var CaseRepository
	 */
	private CaseRepository $cases;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param RunRepository    $runs    Run repository.
	 * @param ResultRepository $results Result repository.
	 * @param CaseRepository   $cases   Case repository.
	 * @param Mailer           $mailer  Mailer.
	 */
	public function __construct( RunRepository $runs, ResultRepository $results, CaseRepository $cases, Mailer $mailer ) {
		$this->runs    = $runs;
		$this->results = $results;
		$this->cases   = $cases;
		$this->mailer  = $mailer;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/runs',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'status' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => Enum::validator( Enum::RUN_STATUSES, true ),
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array(
						'name'         => $this->text_arg( true ),
						'environment'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => Enum::validator( Enum::ENVIRONMENTS ),
						),
						'version'      => $this->text_arg( true ),
						'notes'        => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'case_ids'     => $this->id_list_arg(),
						'assignee_ids' => $this->id_list_arg(),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)',
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
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array(
						'id'          => $this->id_arg(),
						'name'        => $this->text_arg(),
						'environment' => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => Enum::validator( Enum::ENVIRONMENTS, true ),
						),
						'version'     => $this->text_arg(),
						'notes'       => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'status'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => Enum::validator( Enum::RUN_STATUSES, true ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/cases',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_cases' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'       => $this->id_arg(),
					'case_ids' => $this->id_list_arg( true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/cases/(?P<case_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_case' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'      => $this->id_arg(),
					'case_id' => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/assignees',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_assignees' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'           => $this->id_arg(),
					'assignee_ids' => $this->id_list_arg( true ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/assignees/(?P<user_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_assignee' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'      => $this->id_arg(),
					'user_id' => $this->id_arg(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/clone',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'clone_run' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'          => $this->id_arg(),
					'name'        => $this->text_arg(),
					'environment' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => Enum::validator( Enum::ENVIRONMENTS, true ),
					),
					'version'     => $this->text_arg(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/results',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'results' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/runs/(?P<id>\d+)/previous-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'previous_status' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);
	}

	/**
	 * GET /runs
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>
	 */
	public function index( WP_REST_Request $request ): array {
		$status = (string) $request->get_param( 'status' );

		return $this->runs->query( '' !== $status ? $status : null );
	}

	/**
	 * GET /runs/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$run = $this->runs->find_with_detail( (int) $request->get_param( 'id' ) );

		if ( null === $run ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		return $run;
	}

	/**
	 * POST /runs
	 *
	 * Results are created upfront, one untested row per selected case, so every later query
	 * can assume the row exists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$case_ids = $this->cases->filter_active( Sanitize::id_list( $request->get_param( 'case_ids' ) ) );

		if ( empty( $case_ids ) ) {
			return $this->bad_request( __( 'Select at least one active case for this run.', 'qa-runner' ) );
		}

		$run_id = $this->runs->create(
			array(
				'name'        => (string) $request->get_param( 'name' ),
				'environment' => (string) $request->get_param( 'environment' ),
				'version'     => (string) $request->get_param( 'version' ),
				'notes'       => (string) $request->get_param( 'notes' ),
				'created_by'  => get_current_user_id(),
			)
		);

		if ( 0 === $run_id ) {
			return $this->write_failed( __( 'The run could not be created.', 'qa-runner' ) );
		}

		$added = $this->runs->add_cases( $run_id, $case_ids );
		$this->results->seed( $run_id, $added );

		$assignee_ids = $this->valid_testers( Sanitize::id_list( $request->get_param( 'assignee_ids' ) ) );

		if ( ! empty( $assignee_ids ) ) {
			$this->runs->add_assignees( $run_id, $assignee_ids );
			$this->mailer->send_assignments( $run_id );
		}

		return $this->runs->find_with_detail( $run_id );
	}

	/**
	 * PUT /runs/{id}
	 *
	 * Completion is always an explicit action here — nothing completes a run automatically
	 * when the last case is set, because testers revisit results.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$run = $this->runs->find( $id );

		if ( null === $run ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		$data = array();

		foreach ( array( 'name', 'environment', 'version', 'notes', 'status' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		if ( isset( $data['status'] ) && 'abandoned' === $data['status'] && ! $this->can_manage() ) {
			return $this->forbidden( __( 'You do not have permission to abandon a run.', 'qa-runner' ) );
		}

		if ( ! $this->runs->update( $id, $data ) ) {
			return $this->write_failed( __( 'The run could not be saved.', 'qa-runner' ) );
		}

		return $this->runs->find_with_detail( $id );
	}

	/**
	 * POST /runs/{id}/cases
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_cases( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->runs->find( $id ) ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		if ( ! $this->runs->is_open( $id ) ) {
			return $this->run_closed();
		}

		$case_ids = $this->cases->filter_active( Sanitize::id_list( $request->get_param( 'case_ids' ) ) );
		$added    = $this->runs->add_cases( $id, $case_ids );

		$this->results->seed( $id, $added );

		return array(
			'added'   => $added,
			'results' => $this->results->for_run( $id ),
		);
	}

	/**
	 * DELETE /runs/{id}/cases/{case_id}
	 *
	 * A case can only leave a run while its result is still untested; once someone has
	 * recorded an outcome, removing the case would destroy that record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_case( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$case_id = (int) $request->get_param( 'case_id' );

		if ( null === $this->runs->find( $id ) ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		if ( ! $this->runs->is_open( $id ) ) {
			return $this->run_closed();
		}

		$result = $this->results->find_by_run_case( $id, $case_id );

		if ( null === $result ) {
			return $this->not_found( __( 'That case is not part of this run.', 'qa-runner' ) );
		}

		if ( 'untested' !== $result['status'] ) {
			return $this->bad_request( __( 'This case already has a result, so it cannot be removed from the run.', 'qa-runner' ) );
		}

		$this->results->delete_by_run_case( $id, $case_id );
		$this->runs->remove_case( $id, $case_id );

		return array( 'removed' => true );
	}

	/**
	 * POST /runs/{id}/assignees
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function add_assignees( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->runs->find( $id ) ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		$user_ids = $this->valid_testers( Sanitize::id_list( $request->get_param( 'assignee_ids' ) ) );

		if ( empty( $user_ids ) ) {
			return $this->bad_request( __( 'Choose at least one person who can run tests.', 'qa-runner' ) );
		}

		$this->runs->add_assignees( $id, $user_ids );
		$sent = $this->mailer->send_assignments( $id );

		$run         = $this->runs->find_with_detail( $id );
		$run['sent'] = $sent;

		return $run;
	}

	/**
	 * DELETE /runs/{id}/assignees/{user_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function remove_assignee( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->runs->find( $id ) ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		$this->runs->remove_assignee( $id, (int) $request->get_param( 'user_id' ) );

		return $this->runs->find_with_detail( $id );
	}

	/**
	 * POST /runs/{id}/clone
	 *
	 * Same case selection, fresh untested results. The source run is untouched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function clone_run( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$source = $this->runs->find( $id );

		if ( null === $source ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		$name = (string) $request->get_param( 'name' );

		if ( '' === trim( $name ) ) {
			/* translators: %s: name of the run being cloned. */
			$name = sprintf( __( '%s (retest)', 'qa-runner' ), $source['name'] );
		}

		$environment = (string) $request->get_param( 'environment' );
		$version     = (string) $request->get_param( 'version' );

		$new_id = $this->runs->create(
			array(
				'name'        => $name,
				'environment' => '' !== $environment ? $environment : $source['environment'],
				'version'     => '' !== $version ? $version : $source['version'],
				'notes'       => $source['notes'],
				'created_by'  => get_current_user_id(),
			)
		);

		if ( 0 === $new_id ) {
			return $this->write_failed( __( 'The run could not be cloned.', 'qa-runner' ) );
		}

		// Inactive cases are dropped from the clone: they cannot join a new run.
		$case_ids = $this->cases->filter_active( $this->runs->case_ids( $id ) );
		$added    = $this->runs->add_cases( $new_id, $case_ids );

		$this->results->seed( $new_id, $added );

		return $this->runs->find_with_detail( $new_id );
	}

	/**
	 * GET /runs/{id}/results
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function results( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$run = $this->runs->find_with_detail( $id );

		if ( null === $run ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		return array(
			'run'     => $run,
			'results' => $this->results->for_run( $id ),
		);
	}

	/**
	 * GET /runs/{id}/previous-status
	 *
	 * @param WP_REST_Request $request Request.
	 * @return object|\WP_Error Map of case_id => { status, run_id, run_name, tested_at }.
	 */
	public function previous_status( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->runs->find( $id ) ) {
			return $this->not_found( __( 'That run no longer exists.', 'qa-runner' ) );
		}

		// An empty map has to serialise as {} rather than [], so the client can index it.
		return (object) $this->runs->previous_statuses( $id );
	}

	/**
	 * Filters user identifiers down to those who can actually run tests.
	 *
	 * @param int[] $user_ids Candidate identifiers.
	 * @return int[]
	 */
	private function valid_testers( array $user_ids ): array {
		return array_values(
			array_filter(
				$user_ids,
				static fn( int $user_id ): bool => user_can( $user_id, 'qa_run_tests' )
			)
		);
	}

	/**
	 * Arg definition for a list of identifiers.
	 *
	 * @param bool $required Whether the list must be present.
	 * @return array<string, mixed>
	 */
	private function id_list_arg( bool $required = false ): array {
		return array(
			'required'          => $required,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => array( Sanitize::class, 'id_list' ),
			'validate_callback' => array( Sanitize::class, 'is_id_list' ),
		);
	}
}
