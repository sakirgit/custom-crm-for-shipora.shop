<?php
/**
 * Order statuses settings.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_manage_settings' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to manage order statuses.', 'ds-prod-import-crm' ) . '</p>';
	return;
}
?>
<div class="ds-crm-module-page" data-crm-module="order-statuses">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Order Statuses', 'ds-prod-import-crm' ); ?></h1>
		<button type="button" class="button button-primary ds-crm-btn-add-status"><?php esc_html_e( 'Add Custom Status', 'ds-prod-import-crm' ); ?></button>
	</div>
	<p class="description"><?php esc_html_e( 'System statuses cannot be deleted. Use “Blocks workflow” for statuses that hold orders until admin acceptance (e.g. Awaiting acceptance). Mark one status as “Auto when fully paid”.', 'ds-prod-import-crm' ); ?></p>
	<div class="ds-crm-table-wrap">
		<table class="ds-crm-table ds-crm-statuses-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Key', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Color', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Auto on paid', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Blocks workflow', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Closed', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="7"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<div class="ds-crm-modal" id="ds-crm-status-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-md">
		<div class="ds-crm-modal-header">
			<h2><?php esc_html_e( 'Order Status', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close">&times;</button>
		</div>
		<form class="ds-crm-form ds-crm-status-form" novalidate>
			<input type="hidden" name="id" value="" />
			<div class="ds-crm-form-error" hidden></div>
			<div class="ds-crm-modal-body">
				<p>
					<label for="status-label"><?php esc_html_e( 'Label', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="status-label" name="label" required />
				</p>
				<p class="ds-crm-status-slug-field">
					<label for="status-slug"><?php esc_html_e( 'Key (slug)', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="status-slug" name="slug" placeholder="e.g. ready_to_ship" />
				</p>
				<p class="ds-crm-color-field">
					<label for="status-color"><?php esc_html_e( 'Badge color', 'ds-prod-import-crm' ); ?></label>
					<input type="color" id="status-color" name="color" value="#6b7280" />
				</p>
				<p>
					<label for="status-auto-on-paid">
						<input type="checkbox" id="status-auto-on-paid" name="auto_on_paid" value="1" />
						<?php esc_html_e( 'Set automatically when order is fully paid', 'ds-prod-import-crm' ); ?>
					</label>
				</p>
				<p>
					<label for="status-blocks-workflow">
						<input type="checkbox" id="status-blocks-workflow" name="blocks_workflow" value="1" />
						<?php esc_html_e( 'Blocks workflow (order inactive until status changes — use for awaiting acceptance)', 'ds-prod-import-crm' ); ?>
					</label>
				</p>
				<p>
					<label for="status-is-closed">
						<input type="checkbox" id="status-is-closed" name="is_closed" value="1" />
						<?php esc_html_e( 'Closed status (no further edits expected)', 'ds-prod-import-crm' ); ?>
					</label>
				</p>
			</div>
			<div class="ds-crm-modal-footer">
				<button type="button" class="button ds-crm-modal-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ds-prod-import-crm' ); ?></button>
			</div>
		</form>
	</div>
</div>
