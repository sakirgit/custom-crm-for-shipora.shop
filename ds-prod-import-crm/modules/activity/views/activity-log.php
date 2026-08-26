<?php
/**
 * Activity log (audit trail) — admins only.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_manage_settings' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to view the activity log.', 'ds-prod-import-crm' ) . '</p>';
	return;
}
?>
<div class="ds-crm-module-page" data-crm-module="activity">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Activity log', 'ds-prod-import-crm' ); ?></h1>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'Track who created or changed CRM data across orders, clients, payments, warehouse, and more. Use filters to investigate a user or record.', 'ds-prod-import-crm' ); ?>
	</div>

	<div class="ds-crm-toolbar ds-crm-activity-toolbar">
		<input type="search" class="ds-crm-search ds-crm-activity-search" placeholder="<?php esc_attr_e( 'Search description or user…', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-activity-module">
			<option value=""><?php esc_html_e( 'All modules', 'ds-prod-import-crm' ); ?></option>
		</select>
		<select class="ds-crm-activity-action">
			<option value=""><?php esc_html_e( 'All actions', 'ds-prod-import-crm' ); ?></option>
		</select>
		<select class="ds-crm-activity-user">
			<option value=""><?php esc_html_e( 'All users', 'ds-prod-import-crm' ); ?></option>
		</select>
		<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" />
		<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-per-page" aria-label="<?php esc_attr_e( 'Items per page', 'ds-prod-import-crm' ); ?>">
			<option value="25">25</option>
			<option value="10">10</option>
			<option value="50">50</option>
			<option value="100">100</option>
		</select>
	</div>

	<div class="ds-crm-table-wrap">
		<table class="ds-crm-table ds-crm-activity-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'User', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Module', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Action', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Record', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Details', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td colspan="6"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ds-crm-pagination" hidden>
		<button type="button" class="button ds-crm-page-prev" disabled><?php esc_html_e( 'Previous', 'ds-prod-import-crm' ); ?></button>
		<nav class="ds-crm-page-numbers" aria-label="<?php esc_attr_e( 'Page numbers', 'ds-prod-import-crm' ); ?>"></nav>
		<span class="ds-crm-page-info"></span>
		<button type="button" class="button ds-crm-page-next" disabled><?php esc_html_e( 'Next', 'ds-prod-import-crm' ); ?></button>
	</div>
</div>
