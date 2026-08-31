<?php
/**
 * Daily digest WP-Cron event.
 *
 * @package QARunner
 */

declare( strict_types=1 );

namespace QARunner\Notification;

use QARunner\Repository\ResultRepository;
use QARunner\Support\Dates;
use QARunner\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs the daily digest.
 *
 * WP-Cron schedules relative to the timestamp it is handed, so changing the digest time
 * has to clear and reschedule the hook — saving the option alone does nothing.
 */
final class DigestCron {

	public const HOOK = 'qa_runner_daily_digest';

	/**
	 * Result repository.
	 *
	 * @var ResultRepository
	 */
	private ResultRepository $results;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private Mailer $mailer;

	/**
	 * Constructor.
	 *
	 * @param ResultRepository $results Result repository.
	 * @param Mailer           $mailer  Mailer.
	 */
	public function __construct( ResultRepository $results, Mailer $mailer ) {
		$this->results = $results;
		$this->mailer  = $mailer;
	}

	/**
	 * Schedules the event only when nothing is scheduled yet.
	 *
	 * The unguarded schedule() clears before it books, so calling it on every request would
	 * push the next occurrence forward forever and the digest would never send. This is the
	 * variant safe to run on init, so a missed activation still ends up with a schedule.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		self::schedule();
	}

	/**
	 * Schedules the event at the configured send time, replacing any existing schedule.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		wp_clear_scheduled_hook( self::HOOK );

		wp_schedule_event(
			Dates::next_occurrence_utc( Settings::digest_time() ),
			'daily',
			self::HOOK
		);
	}

	/**
	 * Clears the event. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Cron callback: one email per user with outstanding untested cases.
	 *
	 * Users with nothing outstanding are skipped entirely. A digest that says "nothing to
	 * do" trains people to filter the sender.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( Settings::notifications_paused() ) {
			return;
		}

		foreach ( $this->results->outstanding_by_assignee() as $user_id => $runs ) {
			$this->mailer->send_digest( (int) $user_id, $runs );
		}
	}
}
