<?php
/**
 * Case assignment email body: somebody else has given the recipient a case in a run.
 *
 * Plain HTML tables only — no external CSS, no flexbox, nothing Outlook will drop.
 *
 * @package QARunner
 *
 * @var array<string, mixed> $run        Run record.
 * @var array<string, mixed> $case       Case as it sits in the run (id, title, suite_name…).
 * @var WP_User              $user       Recipient.
 * @var string               $actor_name Display name of whoever made the assignment.
 * @var string               $case_url   Deep link to the case in the QA Runner screen.
 */

defined( 'ABSPATH' ) || exit;

$qa_runner_rows = array(
	__( 'Case', 'qa-runner' )        => $case['title'],
	__( 'Suite', 'qa-runner' )       => $case['suite_name'],
	__( 'Run', 'qa-runner' )         => $run['name'],
	__( 'Environment', 'qa-runner' ) => $run['environment'],
	__( 'Version', 'qa-runner' )     => $run['version'],
);
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f1;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
	<tr>
		<td align="center">
			<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:100%;background-color:#ffffff;border:1px solid #dcdcde;">
				<tr>
					<td style="padding:24px 24px 8px 24px;font-size:13px;color:#1d2327;">
						<p style="margin:0 0 16px 0;font-size:16px;font-weight:600;color:#1d2327;">
							<?php esc_html_e( 'You have been assigned a case to test', 'qa-runner' ); ?>
						</p>
						<p style="margin:0 0 16px 0;">
							<?php
							printf(
								/* translators: %s: recipient display name. */
								esc_html__( 'Hello %s,', 'qa-runner' ),
								esc_html( $user->display_name )
							);
							?>
						</p>
						<p style="margin:0 0 20px 0;">
							<?php
							printf(
								/* translators: 1: assigning user's display name, 2: case title. */
								esc_html__( '%1$s assigned you to test %2$s.', 'qa-runner' ),
								esc_html( $actor_name ),
								'<strong>' . esc_html( $case['title'] ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;color:#1d2327;">
							<?php foreach ( $qa_runner_rows as $qa_runner_label => $qa_runner_value ) : ?>
								<?php
								if ( '' === (string) $qa_runner_value ) {
									continue;
								}
								?>
								<tr>
									<td style="padding:8px 12px;border:1px solid #dcdcde;background-color:#f6f7f7;width:130px;font-weight:600;">
										<?php echo esc_html( $qa_runner_label ); ?>
									</td>
									<td style="padding:8px 12px;border:1px solid #dcdcde;">
										<?php echo esc_html( (string) $qa_runner_value ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<tr>
					<td style="padding:24px;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0">
							<tr>
								<td style="background-color:#2271b1;padding:10px 20px;">
									<a href="<?php echo esc_url( $case_url ); ?>" style="color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;display:inline-block;">
										<?php esc_html_e( 'Open the case', 'qa-runner' ); ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
