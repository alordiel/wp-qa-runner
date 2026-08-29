<?php
/**
 * Daily digest email body.
 *
 * Plain HTML tables only — no external CSS, no flexbox, nothing Outlook will drop.
 *
 * @package QARunner
 *
 * @var WP_User                          $user      Recipient.
 * @var array<int, array<string, mixed>> $runs      Run summaries with remaining counts.
 * @var int                              $remaining Total outstanding cases.
 * @var string                           $base_url  QA Runner screen URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f1;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
	<tr>
		<td align="center">
			<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:100%;background-color:#ffffff;border:1px solid #dcdcde;">
				<tr>
					<td style="padding:24px 24px 8px 24px;font-size:13px;color:#1d2327;">
						<p style="margin:0 0 16px 0;font-size:16px;font-weight:600;">
							<?php
							printf(
								/* translators: %d: number of outstanding cases. */
								esc_html( _n( '%d case still to test', '%d cases still to test', $remaining, 'qa-runner' ) ),
								absint( $remaining )
							);
							?>
						</p>
						<p style="margin:0 0 20px 0;">
							<?php
							printf(
								/* translators: %s: recipient display name. */
								esc_html__( 'Hello %s, here is what is outstanding on the runs you are assigned to.', 'qa-runner' ),
								esc_html( $user->display_name )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px 24px 24px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;color:#1d2327;">
							<tr>
								<td style="padding:8px 12px;border:1px solid #dcdcde;background-color:#f6f7f7;font-weight:600;">
									<?php esc_html_e( 'Run', 'qa-runner' ); ?>
								</td>
								<td style="padding:8px 12px;border:1px solid #dcdcde;background-color:#f6f7f7;font-weight:600;width:90px;">
									<?php esc_html_e( 'Environment', 'qa-runner' ); ?>
								</td>
								<td style="padding:8px 12px;border:1px solid #dcdcde;background-color:#f6f7f7;font-weight:600;width:80px;" align="right">
									<?php esc_html_e( 'Remaining', 'qa-runner' ); ?>
								</td>
							</tr>
							<?php foreach ( $runs as $qa_runner_run ) : ?>
								<tr>
									<td style="padding:8px 12px;border:1px solid #dcdcde;">
										<a href="<?php echo esc_url( $base_url . '#/runs/' . (int) $qa_runner_run['run_id'] ); ?>" style="color:#2271b1;text-decoration:none;">
											<?php echo esc_html( (string) $qa_runner_run['run_name'] ); ?>
										</a>
									</td>
									<td style="padding:8px 12px;border:1px solid #dcdcde;">
										<?php echo esc_html( (string) $qa_runner_run['environment'] ); ?>
									</td>
									<td style="padding:8px 12px;border:1px solid #dcdcde;" align="right">
										<?php echo absint( $qa_runner_run['remaining'] ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
