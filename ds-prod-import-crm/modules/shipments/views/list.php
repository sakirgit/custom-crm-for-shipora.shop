<?php
/**
 * China export shipments list.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_shipments_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to view export shipments.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_record_export = current_user_can( 'crm_shipments_create' );
$can_accept        = current_user_can( 'crm_orders_accept' );
$can_edit_prices   = current_user_can( 'crm_orders_edit' );
$can_amend         = current_user_can( 'crm_shipments_amend' );
$can_review        = current_user_can( 'crm_shipments_review' );
$supervisor_focus  = $can_review && ! $can_record_export && ! $can_accept && ! $can_edit_prices;
$show_orders_tab   = $can_record_export || $can_accept || $can_edit_prices;
$colspan           = 8;
$amend_colspan     = 10;
?>
<div class="ds-crm-module-page" data-crm-module="shipments"
	data-can-record-export="<?php echo $can_record_export ? '1' : '0'; ?>"
	data-can-accept="<?php echo $can_accept ? '1' : '0'; ?>"
	data-can-edit-prices="<?php echo $can_edit_prices ? '1' : '0'; ?>"
	data-can-amend="<?php echo $can_amend ? '1' : '0'; ?>"
	data-can-review="<?php echo $can_review ? '1' : '0'; ?>"
	data-supervisor-focus="<?php echo $supervisor_focus ? '1' : '0'; ?>">
	<div class="ds-crm-page-header">
		<h1><?php echo esc_html( crm_shipments_module_label() ); ?></h1>
		<?php if ( $can_record_export ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( crm_shipment_form_url() ); ?>">
				<?php esc_html_e( 'Record export', 'ds-prod-import-crm' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php if ( $supervisor_focus ) : ?>
			<?php esc_html_e( 'Review board: all pending product quantity/weight change requests appear together below. Accept or decline each product — you cannot edit quantities yourself.', 'ds-prod-import-crm' ); ?>
		<?php elseif ( $can_accept || $can_edit_prices ) : ?>
			<?php esc_html_e( 'Click any order to open its workspace: set final prices, approve if needed, then record export shipments. If a shipping company issue affects only some products, use Change on that product row — after supervisor approval, freed qty can be exported again with another shipper.', 'ds-prod-import-crm' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Click an order to open its workspace. Export history lists shipments already recorded from China.', 'ds-prod-import-crm' ); ?>
		<?php endif; ?>
	</div>

	<nav class="ds-crm-shipments-nav-row" aria-label="<?php esc_attr_e( 'China operations views and filters', 'ds-prod-import-crm' ); ?>">
		<div class="ds-crm-subnav ds-crm-shipments-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Export shipments views', 'ds-prod-import-crm' ); ?>">
			<?php if ( $show_orders_tab ) : ?>
				<button type="button" class="ds-crm-subnav-tab<?php echo $supervisor_focus ? '' : ' is-active'; ?>" role="tab" id="ds-crm-shipments-tab-ready" data-tab="ready" aria-selected="<?php echo $supervisor_focus ? 'false' : 'true'; ?>" aria-controls="ds-crm-shipments-panel-ready">
					<?php esc_html_e( 'Orders', 'ds-prod-import-crm' ); ?>
					<span class="ds-crm-subnav-tab-count ds-crm-shipments-ready-count" hidden></span>
				</button>
			<?php endif; ?>
			<button type="button" class="ds-crm-subnav-tab<?php echo ( ! $show_orders_tab && ! $supervisor_focus ) ? ' is-active' : ''; ?>" role="tab" id="ds-crm-shipments-tab-history" data-tab="history" aria-selected="<?php echo ( ! $show_orders_tab && ! $supervisor_focus ) ? 'true' : 'false'; ?>" aria-controls="ds-crm-shipments-panel-history">
				<?php esc_html_e( 'Export history', 'ds-prod-import-crm' ); ?>
			</button>
			<button type="button" class="ds-crm-subnav-tab<?php echo $supervisor_focus ? ' is-active' : ''; ?>" role="tab" id="ds-crm-shipments-tab-amendments" data-tab="amendments" aria-selected="<?php echo $supervisor_focus ? 'true' : 'false'; ?>" aria-controls="ds-crm-shipments-panel-amendments">
				<?php echo $supervisor_focus ? esc_html__( 'Review board', 'ds-prod-import-crm' ) : esc_html__( 'Qty change requests', 'ds-prod-import-crm' ); ?>
				<span class="ds-crm-subnav-tab-count ds-crm-shipments-amend-count" hidden></span>
			</button>
		</div>
		<div class="ds-crm-shipments-ready-filters" data-ready-filters<?php echo $supervisor_focus ? ' hidden' : ''; ?>>
			<div class="ds-crm-filter-pills ds-crm-filter-status-pills" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'ds-prod-import-crm' ); ?>">
				<button type="button" class="ds-crm-filter-pill is-active" data-status="" aria-pressed="true"><?php esc_html_e( 'All', 'ds-prod-import-crm' ); ?></button>
			</div>
			<select class="ds-crm-filter-tracking" aria-label="<?php esc_attr_e( 'Filter by tracking', 'ds-prod-import-crm' ); ?>">
				<option value=""><?php esc_html_e( 'All tracking', 'ds-prod-import-crm' ); ?></option>
			</select>
		</div>
	</nav>

	<?php if ( $show_orders_tab ) : ?>
	<div class="ds-crm-shipments-panel" id="ds-crm-shipments-panel-ready" data-panel="ready" role="tabpanel" aria-labelledby="ds-crm-shipments-tab-ready"<?php echo $supervisor_focus ? ' hidden' : ''; ?>>
		<div class="ds-crm-toolbar">
			<input type="search" class="ds-crm-search ds-crm-shipments-search-ready" placeholder="<?php esc_attr_e( 'Search order, client, product…', 'ds-prod-import-crm' ); ?>" />
		</div>

		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-shipments-table ds-crm-shipments-table--ready">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Date', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Order bill', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Tracking', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>

	<div class="ds-crm-shipments-panel" id="ds-crm-shipments-panel-history" data-panel="history" role="tabpanel" aria-labelledby="ds-crm-shipments-tab-history"<?php echo ( $show_orders_tab || $supervisor_focus ) ? ' hidden' : ''; ?>>
		<div class="ds-crm-toolbar">
			<input type="search" class="ds-crm-search ds-crm-shipments-search-history" placeholder="<?php esc_attr_e( 'Search shipment #, order, client, company…', 'ds-prod-import-crm' ); ?>" />
			<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'Ship from date', 'ds-prod-import-crm' ); ?>" />
			<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'Ship to date', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-per-page" aria-label="<?php esc_attr_e( 'Shipments per page', 'ds-prod-import-crm' ); ?>">
				<option value="10">10</option>
				<option value="25">25</option>
				<option value="50">50</option>
			</select>
		</div>

		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-shipments-table ds-crm-shipments-table--history">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Export company', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Date', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Shipment / export', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="<?php echo (int) $colspan; ?>"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ds-crm-pagination" hidden>
			<button type="button" class="button ds-crm-page-prev" disabled><?php esc_html_e( 'Previous', 'ds-prod-import-crm' ); ?></button>
			<span class="ds-crm-page-info"></span>
			<button type="button" class="button ds-crm-page-next" disabled><?php esc_html_e( 'Next', 'ds-prod-import-crm' ); ?></button>
		</div>
	</div>

	<div class="ds-crm-shipments-panel" id="ds-crm-shipments-panel-amendments" data-panel="amendments" role="tabpanel" aria-labelledby="ds-crm-shipments-tab-amendments"<?php echo $supervisor_focus ? '' : ' hidden'; ?>>
		<div class="ds-crm-toolbar">
			<input type="search" class="ds-crm-search ds-crm-shipments-search-amendments" placeholder="<?php esc_attr_e( 'Search product, shipment #, order, company, reason…', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-amend-status-filter" aria-label="<?php esc_attr_e( 'Filter change requests', 'ds-prod-import-crm' ); ?>">
				<option value="pending"><?php esc_html_e( 'Pending review', 'ds-prod-import-crm' ); ?></option>
				<option value="approved"><?php esc_html_e( 'Approved', 'ds-prod-import-crm' ); ?></option>
				<option value="declined"><?php esc_html_e( 'Declined', 'ds-prod-import-crm' ); ?></option>
				<option value="all"><?php esc_html_e( 'All requests', 'ds-prod-import-crm' ); ?></option>
			</select>
			<select class="ds-crm-amend-per-page" aria-label="<?php esc_attr_e( 'Requests per page', 'ds-prod-import-crm' ); ?>">
				<option value="25" selected>25</option>
				<option value="50">50</option>
				<option value="100">100</option>
			</select>
		</div>

		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-shipments-table ds-crm-shipments-table--amendments">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Qty change', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Weight', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Shipment', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Company', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Requested', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="<?php echo (int) $amend_colspan; ?>"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="ds-crm-pagination ds-crm-amend-pagination" hidden>
			<button type="button" class="button ds-crm-amend-page-prev" disabled><?php esc_html_e( 'Previous', 'ds-prod-import-crm' ); ?></button>
			<span class="ds-crm-amend-page-info"></span>
			<button type="button" class="button ds-crm-amend-page-next" disabled><?php esc_html_e( 'Next', 'ds-prod-import-crm' ); ?></button>
		</div>
	</div>
</div>

<div class="ds-crm-modal" id="ds-crm-shipment-view-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-lg" role="dialog" aria-modal="true" aria-labelledby="ds-crm-shipment-view-title">
		<div class="ds-crm-modal-header ds-crm-modal-header--split">
			<h2 id="ds-crm-shipment-view-title"><?php esc_html_e( 'Export shipment details', 'ds-prod-import-crm' ); ?></h2>
			<div class="ds-crm-modal-header-actions">
				<span class="ds-crm-shipment-view-toolbar"></span>
				<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
			</div>
		</div>
		<div class="ds-crm-shipment-view-body">
			<div class="ds-crm-shipment-amend-banner" hidden></div>
			<div class="ds-crm-shipment-view-meta"></div>
			<div class="ds-crm-table-wrap">
				<table class="ds-crm-table ds-crm-shipment-view-items">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Color / Size', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Qty shipped', 'ds-prod-import-crm' ); ?></th>
							<th class="ds-crm-shipment-amend-col" hidden><?php esc_html_e( 'New qty', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Weight (kg)', 'ds-prod-import-crm' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
			<div class="ds-crm-shipment-amend-form" hidden>
				<label class="ds-crm-shipment-amend-reason-label" for="ds-crm-shipment-amend-reason"><?php esc_html_e( 'Reason for quantity change', 'ds-prod-import-crm' ); ?></label>
				<textarea id="ds-crm-shipment-amend-reason" class="ds-crm-shipment-amend-reason" rows="3" placeholder="<?php esc_attr_e( 'e.g. Shipping company cannot take these products — need another shipper for this qty.', 'ds-prod-import-crm' ); ?>"></textarea>
				<div class="ds-crm-shipment-amend-form-actions">
					<button type="button" class="button button-primary ds-crm-shipment-amend-submit"><?php esc_html_e( 'Submit request', 'ds-prod-import-crm' ); ?></button>
					<button type="button" class="button ds-crm-shipment-amend-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
				</div>
			</div>
			<div class="ds-crm-shipment-amend-review" hidden>
				<label class="ds-crm-shipment-amend-reason-label" for="ds-crm-shipment-amend-review-notes"><?php esc_html_e( 'Review notes (optional)', 'ds-prod-import-crm' ); ?></label>
				<textarea id="ds-crm-shipment-amend-review-notes" class="ds-crm-shipment-amend-review-notes" rows="2"></textarea>
				<div class="ds-crm-shipment-amend-form-actions">
					<button type="button" class="button button-primary ds-crm-shipment-amend-approve"><?php esc_html_e( 'Accept change', 'ds-prod-import-crm' ); ?></button>
					<button type="button" class="button ds-crm-shipment-amend-decline"><?php esc_html_e( 'Decline', 'ds-prod-import-crm' ); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>
