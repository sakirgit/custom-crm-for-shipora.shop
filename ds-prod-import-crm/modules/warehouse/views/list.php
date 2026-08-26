<?php
/**
 * Warehouse receive list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_stock_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to receive stock.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_receive = current_user_can( 'crm_stock_receive' ) || current_user_can( 'crm_receive_stock' );
?>
<div class="ds-crm-module-page" data-crm-module="warehouse">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Warehouse / Receive', 'ds-prod-import-crm' ); ?></h1>
		<?php if ( $can_receive ) : ?>
			<a class="button" href="<?php echo esc_url( crm_receive_form_url() ); ?>">
				<?php esc_html_e( 'Manual receive', 'ds-prod-import-crm' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'China export shipments appear under Awaiting arrival. When cargo reaches Bangladesh, receive against each shipment — mark received qty and any missing qty per product. Shipping bills bill the cargo company.', 'ds-prod-import-crm' ); ?>
	</div>

	<div class="ds-crm-subnav ds-crm-warehouse-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Warehouse views', 'ds-prod-import-crm' ); ?>">
		<button type="button" class="ds-crm-subnav-tab is-active" data-warehouse-tab="awaiting" role="tab" aria-selected="true">
			<?php esc_html_e( 'Awaiting arrival', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-subnav-tab" data-warehouse-tab="history" role="tab" aria-selected="false">
			<?php esc_html_e( 'Receive history', 'ds-prod-import-crm' ); ?>
		</button>
	</div>

	<div class="ds-crm-toolbar">
		<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search shipment, receive #, client, company…', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-filter-client">
			<option value=""><?php esc_html_e( 'All clients', 'ds-prod-import-crm' ); ?></option>
		</select>
		<select class="ds-crm-filter-company">
			<option value=""><?php esc_html_e( 'All companies', 'ds-prod-import-crm' ); ?></option>
		</select>
		<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" hidden />
		<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" hidden />
		<select class="ds-crm-per-page">
			<option value="10">10</option>
			<option value="25">25</option>
			<option value="50">50</option>
		</select>
	</div>

	<div class="ds-crm-warehouse-panel" data-warehouse-panel="awaiting">
		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-warehouse-awaiting-table">
				<thead>
					<tr>
						<th data-sort="shipment_number"><?php esc_html_e( 'Shipment #', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="ship_date"><?php esc_html_e( 'Ship date', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="client_name"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="company_name"><?php esc_html_e( 'Company', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="order_number"><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Remaining', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="status"><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="9"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<div class="ds-crm-warehouse-panel" data-warehouse-panel="history" hidden>
		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-warehouse-table">
				<thead>
					<tr>
						<th data-sort="receive_number"><?php esc_html_e( 'Receive #', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="receive_date"><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="client_name"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="company_name"><?php esc_html_e( 'Company', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Shipment', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="total_kg"><?php esc_html_e( 'Total KG', 'ds-prod-import-crm' ); ?></th>
						<th data-sort="shipping_bill"><?php esc_html_e( 'Shipping Bill', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="9"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<div class="ds-crm-pagination" hidden>
		<button type="button" class="button ds-crm-page-prev" disabled><?php esc_html_e( 'Previous', 'ds-prod-import-crm' ); ?></button>
		<span class="ds-crm-page-info"></span>
		<button type="button" class="button ds-crm-page-next" disabled><?php esc_html_e( 'Next', 'ds-prod-import-crm' ); ?></button>
	</div>
</div>

<div class="ds-crm-modal" id="ds-crm-receive-view-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-lg" role="dialog" aria-modal="true">
		<div class="ds-crm-modal-header">
			<h2 class="ds-crm-receive-view-title"><?php esc_html_e( 'Receive details', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<div class="ds-crm-receive-view-body"></div>
	</div>
</div>
