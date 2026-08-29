<?php
/**
 * Outgoing mail.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Notification;

use QARunner\Repository\RunRepository;
use QARunner\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_mail() for the plugin's two templates.
 *
 * The HTML content type filter is attached immediately before each send and removed
 * immediately after; leaving it attached would silently turn every other plugin's mail
 * into HTML.
 */
final class Mailer {

	/**
	 * Run repository.
	 *
	 * @var RunRepository
	 */
	private RunRepository $runs;

	/**
	 * Constructor.
	 *
	 * @param RunRepository $runs Run repository.
	 */
	public function __construct( RunRepository $runs ) {
		$this->runs = $runs;
	}

	/**
	 * Sends the assignment email to every assignee who has not had one.
	 *
	 * The notified_at column is stamped on success, so re-saving a run never double-sends.
	 *
	 * @param int $run_id Run identifier.
	 * @return int Number of emails sent.
	 */
	public function send_assignments( int $run_id ): int {
		if ( Settings::notifications_paused() ) {
			return 0;
		}

		$run = $this->runs->find( $run_id );

		if ( null === $run || 'open' !== $run['status'] ) {
			return 0;
		}

		$user_ids   = $this->runs->unnotified_assignees( $run_id );
		$case_count = count( $this->runs->case_ids( $run_id ) );
		$notified   = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user || empty( $user->user_email ) ) {
				continue;
			}

			/* translators: %s: run name. */
			$subject = sprintf( __( '[QA] You\'ve been assigned to: %s', 'qa-runner' ), $run['name'] );

			$body = $this->render(
				'assignment',
				array(
					'run'        => $run,
					'user'       => $user,
					'case_count' => $case_count,
					'run_url'    => $this->run_url( $run_id ),
				)
			);

			if ( $this->send( $user->user_email, $subject, $body ) ) {
				$notified[] = $user_id;
			}
		}

		$this->runs->mark_notified( $run_id, $notified );

		return count( $notified );
	}

	/**
	 * Sends one digest email.
	 *
	 * @param int                              $user_id User identifier.
	 * @param array<int, array<string, mixed>> $runs    Run summaries with remaining counts.
	 * @return bool
	 */
	public function send_digest( int $user_id, array $runs ): bool {
		if ( Settings::notifications_paused() || empty( $runs ) ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$remaining = array_sum( array_column( $runs, 'remaining' ) );

		$subject = sprintf(
			/* translators: %d: number of outstanding test cases. */
			_n( '[QA] %d case still to test', '[QA] %d cases still to test', $remaining, 'qa-runner' ),
			$remaining
		);

		$body = $this->render(
			'digest',
			array(
				'user'      => $user,
				'runs'      => $runs,
				'remaining' => $remaining,
				'base_url'  => admin_url( 'admin.php?page=' . QA_RUNNER_SLUG ),
			)
		);

		return $this->send( $user->user_email, $subject, $body );
	}

	/**
	 * Deep link into the SPA for a run.
	 *
	 * @param int $run_id Run identifier.
	 * @return string
	 */
	public function run_url( int $run_id ): string {
		return admin_url( 'admin.php?page=' . QA_RUNNER_SLUG ) . '#/runs/' . $run_id;
	}

	/**
	 * Renders an email template to a string.
	 *
	 * @param string               $template Template base name.
	 * @param array<string, mixed> $data     Variables extracted into the template scope.
	 * @return string
	 */
	private function render( string $template, array $data ): string {
		$path = QA_RUNNER_PATH . 'templates/emails/' . $template . '.php';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data, EXTR_SKIP );
		include $path;

		return (string) ob_get_clean();
	}

	/**
	 * Sends one HTML email.
	 *
	 * @param string $to      Recipient address.
	 * @param string $subject Subject line.
	 * @param string $body    HTML body.
	 * @return bool
	 */
	private function send( string $to, string $subject, string $body ): bool {
		if ( '' === trim( $body ) ) {
			return false;
		}

		$content_type = static fn(): string => 'text/html';

		add_filter( 'wp_mail_content_type', $content_type );
		$sent = wp_mail( $to, $subject, $body );
		remove_filter( 'wp_mail_content_type', $content_type );

		return (bool) $sent;
	}
}
