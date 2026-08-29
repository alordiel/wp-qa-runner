<?php
/**
 * Shared REST controller behaviour.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Rest;

use QARunner\Install\Roles;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the plugin's REST controllers.
 *
 * Every route registered by a subclass must supply a permission_callback that calls
 * current_user_can(). No route in this plugin uses __return_true.
 */
abstract class Controller {

	/**
	 * REST namespace shared by every route.
	 */
	public const REST_NAMESPACE = 'qa-runner/v1';

	/**
	 * Registers this controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback: may see the QA screens at all.
	 *
	 * @return bool
	 */
	public function can_view(): bool {
		return current_user_can( Roles::CAP_VIEW );
	}

	/**
	 * Permission callback: may set results, comment, raise issues and create runs.
	 *
	 * @return bool
	 */
	public function can_test(): bool {
		return current_user_can( Roles::CAP_TEST );
	}

	/**
	 * Permission callback: may edit the case library and settings.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Roles::CAP_MANAGE );
	}

	/**
	 * Builds a 404 for a missing record.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	protected function not_found( string $message ): WP_Error {
		return new WP_Error( 'qa_runner_not_found', $message, array( 'status' => 404 ) );
	}

	/**
	 * Builds a 400 for invalid input.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	protected function bad_request( string $message ): WP_Error {
		return new WP_Error( 'qa_runner_bad_request', $message, array( 'status' => 400 ) );
	}

	/**
	 * Builds a 403 for a permission failure inside a handler.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	protected function forbidden( string $message ): WP_Error {
		return new WP_Error( 'qa_runner_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * Builds a 409 for a write against a run that is no longer open.
	 *
	 * This is how "a run is never edited once completed" is enforced.
	 *
	 * @return WP_Error
	 */
	protected function run_closed(): WP_Error {
		return new WP_Error(
			'qa_runner_run_closed',
			__( 'This run is no longer open, so its results cannot be changed.', 'qa-runner' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Builds a 500 for a failed write.
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	protected function write_failed( string $message ): WP_Error {
		return new WP_Error( 'qa_runner_write_failed', $message, array( 'status' => 500 ) );
	}

	/**
	 * Reads a boolean-ish request parameter that may legitimately be absent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Parameter name.
	 * @return bool|null Null when the parameter was not sent.
	 */
	protected function optional_bool( WP_REST_Request $request, string $key ): ?bool {
		if ( ! $request->has_param( $key ) ) {
			return null;
		}

		return rest_sanitize_boolean( $request->get_param( $key ) );
	}

	/**
	 * Standard arg definition for a required plain text field.
	 *
	 * @param bool $required Whether the field must be present.
	 * @return array<string, mixed>
	 */
	protected function text_arg( bool $required = false ): array {
		return array(
			'required'          => $required,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ),
		);
	}

	/**
	 * Standard arg definition for a rich-text field.
	 *
	 * Sanitisation deliberately happens in the repository via wp_kses_post(); the callback
	 * here only guarantees the type.
	 *
	 * @return array<string, mixed>
	 */
	protected function rich_text_arg(): array {
		return array(
			'required'          => false,
			'type'              => 'string',
			'validate_callback' => static fn( $value ): bool => is_string( $value ),
		);
	}

	/**
	 * Standard arg definition for a positive integer route parameter.
	 *
	 * @return array<string, mixed>
	 */
	protected function id_arg(): array {
		return array(
			'required'          => true,
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
		);
	}
}
