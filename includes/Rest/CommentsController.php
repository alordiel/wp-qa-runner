<?php
/**
 * Comment routes. Comments are scoped to one result, and so to one run.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Repository\CommentRepository;
use QARunner\Repository\ResultRepository;
use QARunner\Repository\RunRepository;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for result comments.
 */
final class CommentsController extends Controller {

	/**
	 * Comment repository.
	 *
	 * @var CommentRepository
	 */
	private CommentRepository $comments;

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
	 * @param CommentRepository $comments Comment repository.
	 * @param ResultRepository  $results  Result repository.
	 * @param RunRepository     $runs     Run repository.
	 */
	public function __construct( CommentRepository $comments, ResultRepository $results, RunRepository $runs ) {
		$this->comments = $comments;
		$this->results  = $results;
		$this->runs     = $runs;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/results/(?P<id>\d+)/comments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array(
						'id'      => $this->id_arg(),
						'content' => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( wp_strip_all_tags( $value ) ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/comments/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array(
						'id'      => $this->id_arg(),
						'content' => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( wp_strip_all_tags( $value ) ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array( 'id' => $this->id_arg() ),
				),
			)
		);
	}

	/**
	 * GET /results/{id}/comments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function index( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->results->find_raw( $id ) ) {
			return $this->not_found( __( 'That result no longer exists.', 'qa-runner' ) );
		}

		return $this->comments->for_result( $id );
	}

	/**
	 * POST /results/{id}/comments
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = $this->results->find_raw( $id );

		if ( null === $result ) {
			return $this->not_found( __( 'That result no longer exists.', 'qa-runner' ) );
		}

		if ( ! $this->runs->is_open( (int) $result['run_id'] ) ) {
			return $this->run_closed();
		}

		$comment_id = $this->comments->create( $id, get_current_user_id(), (string) $request->get_param( 'content' ) );

		if ( 0 === $comment_id ) {
			return $this->write_failed( __( 'The comment could not be saved.', 'qa-runner' ) );
		}

		return $this->comments->find( $comment_id );
	}

	/**
	 * PUT /comments/{id}
	 *
	 * A comment is only ever edited by its author.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$comment = $this->comments->find( $id );

		if ( null === $comment ) {
			return $this->not_found( __( 'That comment no longer exists.', 'qa-runner' ) );
		}

		if ( get_current_user_id() !== $comment['author']['id'] ) {
			return $this->forbidden( __( 'You can only edit your own comments.', 'qa-runner' ) );
		}

		$guard = $this->guard_open_run( (int) $comment['result_id'] );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! $this->comments->update( $id, (string) $request->get_param( 'content' ) ) ) {
			return $this->write_failed( __( 'The comment could not be saved.', 'qa-runner' ) );
		}

		return $this->comments->find( $id );
	}

	/**
	 * DELETE /comments/{id}
	 *
	 * Authors delete their own; qa_manage_cases holders delete any.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$comment = $this->comments->find( $id );

		if ( null === $comment ) {
			return $this->not_found( __( 'That comment no longer exists.', 'qa-runner' ) );
		}

		if ( get_current_user_id() !== $comment['author']['id'] && ! $this->can_manage() ) {
			return $this->forbidden( __( 'You can only delete your own comments.', 'qa-runner' ) );
		}

		$guard = $this->guard_open_run( (int) $comment['result_id'] );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! $this->comments->delete( $id ) ) {
			return $this->write_failed( __( 'The comment could not be deleted.', 'qa-runner' ) );
		}

		return array( 'deleted' => true );
	}

	/**
	 * Confirms the run owning a result is still open.
	 *
	 * @param int $result_id Result identifier.
	 * @return true|\WP_Error
	 */
	private function guard_open_run( int $result_id ) {
		$result = $this->results->find_raw( $result_id );

		if ( null === $result ) {
			return $this->not_found( __( 'That result no longer exists.', 'qa-runner' ) );
		}

		if ( ! $this->runs->is_open( (int) $result['run_id'] ) ) {
			return $this->run_closed();
		}

		return true;
	}
}
