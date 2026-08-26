<?php
/**
 * Development data reset panel (wp-admin CRM Settings).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_manage_settings' ) ) {
	return;
}
?>
<div class="ds-crm-settings-card ds-crm-data-reset-card" data-crm-panel="data-reset">
	<div class="ds-crm-settings-card-header">
		<h2><?php esc_html_e( 'Reset CRM data', 'ds-prod-import-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Remove all operational records so you can enter fresh test data. Use this during development and UAT — not on a live production database with real customers.', 'ds-prod-import-crm' ); ?>
		</p>
	</div>
	<div class="ds-crm-settings-card-body">
		<div class="ds-crm-data-reset-notice notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'This cannot be undone.', 'ds-prod-import-crm' ); ?></strong>
				<?php esc_html_e( 'Orders, clients, products, stock, payments, deliveries, warehouse receives, and the activity log will be permanently deleted.', 'ds-prod-import-crm' ); ?>
			</p>
		</div>

		<div class="ds-crm-data-reset-grid">
			<div class="ds-crm-data-reset-column">
				<h3><?php esc_html_e( 'Will be deleted', 'ds-prod-import-crm' ); ?></h3>
				<ul class="ds-crm-data-reset-counts" aria-live="polite">
					<li class="ds-crm-data-reset-loading"><?php esc_html_e( 'Loading counts…', 'ds-prod-import-crm' ); ?></li>
				</ul>
				<p class="ds-crm-data-reset-total description" hidden></p>
			</div>
			<div class="ds-crm-data-reset-column">
				<h3><?php esc_html_e( 'Will be kept', 'ds-prod-import-crm' ); ?></h3>
				<ul class="ds-crm-data-reset-preserved">
					<?php foreach ( CRM_Data_Reset::preserved_labels() as $label ) : ?>
						<li><?php echo esc_html( $label ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<div class="ds-crm-data-reset-form">
			<label for="crm-data-reset-confirm">
				<?php
				printf(
					/* translators: %s: confirmation word */
					esc_html__( 'Type %s to confirm', 'ds-prod-import-crm' ),
					'<code>RESET</code>'
				);
				?>
			</label>
			<input
				type="text"
				id="crm-data-reset-confirm"
				class="regular-text ds-crm-data-reset-confirm-input"
				autocomplete="off"
				spellcheck="false"
				placeholder="RESET"
			/>
			<p class="ds-crm-data-reset-actions">
				<button type="button" class="button button-secondary ds-crm-data-reset-refresh">
					<?php esc_html_e( 'Refresh counts', 'ds-prod-import-crm' ); ?>
				</button>
				<button type="button" class="button button-link-delete ds-crm-data-reset-submit" disabled>
					<?php esc_html_e( 'Delete all CRM data', 'ds-prod-import-crm' ); ?>
				</button>
			</p>
			<div class="ds-crm-data-reset-error notice notice-error inline" hidden></div>
			<div class="ds-crm-data-reset-success notice notice-success inline" hidden></div>
		</div>
	</div>
</div>
