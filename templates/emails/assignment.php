<?php
/**
 * Assignment email body.
 *
 * Plain HTML tables only — no external CSS, no flexbox, nothing Outlook will drop.
 *
 * @package MandragoraQAManager
 *
 * @var array<string, mixed> $run        Run record.
 * @var WP_User              $user       Recipient.
 * @var int                  $case_count Number of cases in the run.
 * @var string               $run_url    Deep link into the Mandragora QA Test Manager screen.
 */

defined( 'ABSPATH' ) || exit;

$mqatm_rows = array(
	__( 'Environment', 'mandragora-qa-test-manager' ) => $run['environment'],
	__( 'Version', 'mandragora-qa-test-manager' )     => $run['version'],
	__( 'Cases', 'mandragora-qa-test-manager' )       => (string) $case_count,
);

if ( ! empty( $run['notes'] ) ) {
	$mqatm_rows[ __( 'Notes', 'mandragora-qa-test-manager' ) ] = $run['notes'];
}
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f0f0f1;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
	<tr>
		<td align="center">
			<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:100%;background-color:#ffffff;border:1px solid #dcdcde;">
				<tr>
					<td style="padding:24px 24px 8px 24px;font-size:13px;color:#1d2327;">
						<p style="margin:0 0 16px 0;font-size:16px;font-weight:600;color:#1d2327;">
							<?php esc_html_e( 'You have been assigned to a QA run', 'mandragora-qa-test-manager' ); ?>
						</p>
						<p style="margin:0 0 16px 0;">
							<?php
							printf(
								/* translators: %s: recipient display name. */
								esc_html__( 'Hello %s,', 'mandragora-qa-test-manager' ),
								esc_html( $user->display_name )
							);
							?>
						</p>
						<p style="margin:0 0 20px 0;">
							<?php
							printf(
								/* translators: %s: run name. */
								esc_html__( 'You have been added to the run %s.', 'mandragora-qa-test-manager' ),
								'<strong>' . esc_html( $run['name'] ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:13px;color:#1d2327;">
							<?php foreach ( $mqatm_rows as $mqatm_label => $mqatm_value ) : ?>
								<tr>
									<td style="padding:8px 12px;border:1px solid #dcdcde;background-color:#f6f7f7;width:130px;font-weight:600;">
										<?php echo esc_html( $mqatm_label ); ?>
									</td>
									<td style="padding:8px 12px;border:1px solid #dcdcde;">
										<?php echo esc_html( (string) $mqatm_value ); ?>
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
									<a href="<?php echo esc_url( $run_url ); ?>" style="color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;display:inline-block;">
										<?php esc_html_e( 'Open the run', 'mandragora-qa-test-manager' ); ?>
									</a>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px 24px 24px;font-size:12px;color:#787c82;">
						<?php esc_html_e( 'Assignment is informational — anyone on the QA team can test any case in an open run.', 'mandragora-qa-test-manager' ); ?>
					</td>
				</tr>
			</table>
		</td>
	</tr>
</table>
