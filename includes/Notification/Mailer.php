<?php
/**
 * Outgoing mail.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Notification;

use QARunner\Repository\ResultRepository;
use QARunner\Repository\RunRepository;
use QARunner\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_mail() for the plugin's two templates: being added to a run, and being given a
 * case within one. Both go only to people assigned by somebody else — assigning yourself
 * is a decision you already know about.
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
	 * Result repository.
	 *
	 * @var ResultRepository
	 */
	private ResultRepository $results;

	/**
	 * Constructor.
	 *
	 * @param RunRepository    $runs    Run repository.
	 * @param ResultRepository $results Result repository.
	 */
	public function __construct( RunRepository $runs, ResultRepository $results ) {
		$this->runs    = $runs;
		$this->results = $results;
	}

	/**
	 * Sends the assignment email to every assignee who has not had one.
	 *
	 * The notified_at column is stamped on success, so re-saving a run never double-sends.
	 * The person who made the change is stamped without an email: adding yourself to a run
	 * needs no notice, now or when somebody else is added later.
	 *
	 * @param int $run_id   Run identifier.
	 * @param int $actor_id User who made the assignment.
	 * @return int Number of emails sent.
	 */
	public function send_assignments( int $run_id, int $actor_id ): int {
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

		if ( in_array( $actor_id, $user_ids, true ) ) {
			$this->runs->mark_notified( $run_id, array( $actor_id ) );
			$user_ids = array_values( array_diff( $user_ids, array( $actor_id ) ) );
		}

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
	 * Tells a tester somebody else has given them a case in a run.
	 *
	 * @param int $result_id Result identifier — the case as it sits in that run.
	 * @param int $user_id   Assignee.
	 * @param int $actor_id  User who made the assignment.
	 * @return bool Whether an email was sent.
	 */
	public function send_case_assignment( int $result_id, int $user_id, int $actor_id ): bool {
		if ( $user_id === $actor_id || Settings::notifications_paused() ) {
			return false;
		}

		$result = $this->results->find_for_api( $result_id );
		$run    = $result ? $this->runs->find( (int) $result['run_id'] ) : null;
		$user   = get_userdata( $user_id );

		if ( null === $run || 'open' !== $run['status'] || ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$actor = get_userdata( $actor_id );

		/* translators: %s: case title. */
		$subject = sprintf( __( '[QA] You\'ve been assigned to test: %s', 'qa-runner' ), $result['case']['title'] );

		$body = $this->render(
			'case-assignment',
			array(
				'run'        => $run,
				'case'       => $result['case'],
				'user'       => $user,
				'actor_name' => $actor ? $actor->display_name : __( 'A teammate', 'qa-runner' ),
				'case_url'   => $this->run_url( (int) $run['id'] ) . '/cases/' . $result['case']['id'],
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
