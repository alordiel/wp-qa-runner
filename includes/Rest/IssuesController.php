<?php
/**
 * Issue routes.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Repository\CaseRepository;
use QARunner\Repository\IssueRepository;
use QARunner\Support\Enum;
use QARunner\Support\Sanitize;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Issues raised against a case.
 *
 * The case is the anchor, not the run: that is what lets the next tester of this case, in
 * any later run, see what is currently broken.
 */
final class IssuesController extends Controller {

	/**
	 * Issue repository.
	 *
	 * @var IssueRepository
	 */
	private IssueRepository $issues;

	/**
	 * Case repository.
	 *
	 * @var CaseRepository
	 */
	private CaseRepository $cases;

	/**
	 * Constructor.
	 *
	 * @param IssueRepository $issues Issue repository.
	 * @param CaseRepository  $cases  Case repository.
	 */
	public function __construct( IssueRepository $issues, CaseRepository $cases ) {
		$this->issues = $issues;
		$this->cases  = $cases;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/cases/(?P<id>\d+)/issues',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'id'     => $this->id_arg(),
						'status' => array(
							'required'          => false,
							'default'           => 'open',
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'validate_callback' => static fn( $value ): bool => 'all' === $value
								|| Enum::is_valid( (string) $value, Enum::ISSUE_STATUSES ),
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_test' ),
					'args'                => array(
						'id'            => $this->id_arg(),
						'title'         => $this->text_arg( true ),
						'description'   => $this->rich_text_arg(),
						'github_url'    => $this->github_url_arg(),
						'origin_run_id' => array(
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
			'/issues/(?P<id>\d+)',
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'can_test' ),
				'args'                => array(
					'id'              => $this->id_arg(),
					'title'           => $this->text_arg(),
					'description'     => $this->rich_text_arg(),
					'github_url'      => $this->github_url_arg(),
					'status'          => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => Enum::validator( Enum::ISSUE_STATUSES, true ),
					),
					'resolution_note' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * GET /cases/{id}/issues
	 *
	 * Defaults to open issues. status=all is the audit view, used by the case library and
	 * never by the run or case-test screens.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function index( WP_REST_Request $request ) {
		$case_id = (int) $request->get_param( 'id' );

		if ( null === $this->cases->find( $case_id ) ) {
			return $this->not_found( __( 'That case no longer exists.', 'qa-runner' ) );
		}

		$status = (string) $request->get_param( 'status' );

		return $this->issues->for_case( $case_id, 'all' === $status ? null : $status );
	}

	/**
	 * POST /cases/{id}/issues
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		$case_id = (int) $request->get_param( 'id' );

		if ( null === $this->cases->find( $case_id ) ) {
			return $this->not_found( __( 'That case no longer exists.', 'qa-runner' ) );
		}

		$issue_id = $this->issues->create(
			array(
				'case_id'       => $case_id,
				'title'         => (string) $request->get_param( 'title' ),
				'description'   => (string) $request->get_param( 'description' ),
				'github_url'    => (string) $request->get_param( 'github_url' ),
				'origin_run_id' => (int) $request->get_param( 'origin_run_id' ),
				'created_by'    => get_current_user_id(),
			)
		);

		if ( 0 === $issue_id ) {
			return $this->write_failed( __( 'The issue could not be saved.', 'qa-runner' ) );
		}

		return $this->issues->find( $issue_id );
	}

	/**
	 * PUT /issues/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->issues->find( $id ) ) {
			return $this->not_found( __( 'That issue no longer exists.', 'qa-runner' ) );
		}

		$data = array();

		foreach ( array( 'title', 'description', 'github_url', 'status', 'resolution_note' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		if ( ! $this->issues->update( $id, $data, get_current_user_id() ) ) {
			return $this->write_failed( __( 'The issue could not be saved.', 'qa-runner' ) );
		}

		return $this->issues->find( $id );
	}

	/**
	 * Arg definition for the GitHub link.
	 *
	 * An empty string is allowed — not every issue has been filed yet.
	 *
	 * @return array<string, mixed>
	 */
	private function github_url_arg(): array {
		return array(
			'required'          => false,
			'type'              => 'string',
			'sanitize_callback' => array( Sanitize::class, 'github_url' ),
			'validate_callback' => static fn( $value ): bool => ! is_string( $value )
				|| '' === trim( $value )
				|| Sanitize::is_github_url( esc_url_raw( trim( $value ), array( 'https' ) ) ),
		);
	}
}
