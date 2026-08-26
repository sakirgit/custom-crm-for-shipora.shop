<?php
/**
 * Client portal settings section.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$portal_users = CRM_Client_Portal::get_portal_users_overview();
$clients_url  = crm_get_public_app_url() ? crm_module_url( 'clients', 'frontend' ) : '';
?>
<div class="ds-crm-settings-card">
	<div class="ds-crm-settings-card-header">
		<h2><?php esc_html_e( 'Client portal', 'ds-prod-import-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Let customers log in with the CRM Client role, place their own orders, and optionally view other clients’ orders.', 'ds-prod-import-crm' ); ?>
		</p>
	</div>
	<div class="ds-crm-settings-card-body">
		<div class="ds-crm-field-table">
			<div class="ds-crm-field-row">
				<span class="ds-crm-field-label"><?php esc_html_e( 'Order visibility', 'ds-prod-import-crm' ); ?></span>
				<div class="ds-crm-field-control">
					<fieldset class="ds-crm-pricing-mode-fieldset">
						<label class="ds-crm-radio-option">
							<input type="radio" name="client_orders_scope" value="own" checked />
							<?php esc_html_e( 'Own orders only — each client user sees orders for their linked client record', 'ds-prod-import-crm' ); ?>
						</label>
						<label class="ds-crm-radio-option">
							<input type="radio" name="client_orders_scope" value="all" />
							<?php esc_html_e( 'All orders — client users can browse every customer order', 'ds-prod-import-crm' ); ?>
						</label>
					</fieldset>
				</div>
			</div>
			<div class="ds-crm-field-row">
				<span class="ds-crm-field-label"><?php esc_html_e( 'CRM Client users', 'ds-prod-import-crm' ); ?></span>
				<div class="ds-crm-field-control">
					<p class="description">
						<?php esc_html_e( 'When you assign the CRM Client role in WordPress, a matching client record is created automatically in the CRM Clients module (if one does not exist yet).', 'ds-prod-import-crm' ); ?>
					</p>
					<?php if ( $portal_users ) : ?>
						<table class="ds-crm-portal-users-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'WordPress user', 'ds-prod-import-crm' ); ?></th>
									<th><?php esc_html_e( 'Clients module', 'ds-prod-import-crm' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $portal_users as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row['user_label'] ); ?></td>
										<td>
											<?php if ( ! empty( $row['is_linked'] ) ) : ?>
												<?php if ( $clients_url ) : ?>
													<a href="<?php echo esc_url( $clients_url ); ?>"><?php echo esc_html( $row['client_name'] ); ?></a>
												<?php else : ?>
													<?php echo esc_html( $row['client_name'] ); ?>
												<?php endif; ?>
											<?php else : ?>
												<span class="ds-crm-hint-warn"><?php esc_html_e( 'Not linked yet — save settings or re-save the user role to sync.', 'ds-prod-import-crm' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="ds-crm-portal-users-empty"><?php esc_html_e( 'No CRM Client users yet. Create a WordPress user and assign the CRM Client role.', 'ds-prod-import-crm' ); ?></p>
					<?php endif; ?>
					<p style="margin-top:12px">
						<button type="button" class="button ds-crm-sync-portal-users"><?php esc_html_e( 'Sync CRM Client users now', 'ds-prod-import-crm' ); ?></button>
						<?php if ( $clients_url ) : ?>
							<a class="button" href="<?php echo esc_url( $clients_url ); ?>"><?php esc_html_e( 'Open Clients in CRM', 'ds-prod-import-crm' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
			<div class="ds-crm-field-row">
				<span class="ds-crm-field-label"><?php esc_html_e( 'Setup steps', 'ds-prod-import-crm' ); ?></span>
				<div class="ds-crm-field-control">
					<ol class="ds-crm-settings-steps">
						<li><?php esc_html_e( 'Create a WordPress user and assign the CRM Client role.', 'ds-prod-import-crm' ); ?></li>
						<li><?php esc_html_e( 'The CRM creates a client record automatically — review name, phone, and email under Clients.', 'ds-prod-import-crm' ); ?></li>
						<li><?php esc_html_e( 'The client logs in on your public CRM page and can create orders under their account.', 'ds-prod-import-crm' ); ?></li>
					</ol>
					<p>
						<a class="button" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Add WordPress user', 'ds-prod-import-crm' ); ?>
						</a>
					</p>
				</div>
			</div>
		</div>
	</div>
</div>
