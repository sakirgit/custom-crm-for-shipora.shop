<?php
/**
 * Delivery list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_delivery_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to view deliveries.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$is_client  = CRM_Client_Portal::is_client_user();
$can_create = ! $is_client && ( current_user_can( 'crm_delivery_create' ) || current_user_can( 'crm_manage_delivery' ) );
$col_count  = $is_client ? 8 : 9;
?>
<div class="ds-crm-module-page" data-crm-module="delivery"
	data-is-client="<?php echo $is_client ? '1' : '0'; ?>"
	data-can-create="<?php echo $can_create ? '1' : '0'; ?>">
	<div class="ds-crm-page-header">
		<h1><?php echo $is_client ? esc_html__( 'My deliveries', 'ds-prod-import-crm' ) : esc_html__( 'Delivery', 'ds-prod-import-crm' ); ?></h1>
		<?php if ( $can_create ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( crm_delivery_form_url() ); ?>">
				<?php esc_html_e( 'New Delivery', 'ds-prod-import-crm' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( $is_client ) : ?>
	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'Deliveries sent to you from your orders. Open a delivery to see items, receiver details, and shipping bill.', 'ds-prod-import-crm' ); ?>
	</div>
	<?php else : ?>
	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'Record outgoing deliveries against customer orders. Shipping is billed per line (weight × rate from warehouse receive). Deliveries cannot be edited after save — use Void on the delivery details view to reverse stock and remove the record.', 'ds-prod-import-crm' ); ?>
	</div>
	<?php endif; ?>

	<div class="ds-crm-list-controls">
		<div class="ds-crm-filter-pills ds-crm-delivery-period-pills" role="group" aria-label="<?php esc_attr_e( 'Filter by period', 'ds-prod-import-crm' ); ?>">
			<button type="button" class="ds-crm-filter-pill is-active" data-period="" aria-pressed="true"><?php esc_html_e( 'All', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-filter-pill" data-period="today" aria-pressed="false"><?php esc_html_e( 'Today', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-filter-pill" data-period="week" aria-pressed="false"><?php esc_html_e( 'This week', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-filter-pill" data-period="month" aria-pressed="false"><?php esc_html_e( 'This month', 'ds-prod-import-crm' ); ?></button>
		</div>
		<div class="ds-crm-toolbar ds-crm-toolbar--list">
			<input type="search" class="ds-crm-search" placeholder="<?php echo $is_client
				? esc_attr__( 'Search delivery #, order, product, receiver…', 'ds-prod-import-crm' )
				: esc_attr__( 'Search delivery #, order, client, product, receiver…', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-filter-order" aria-label="<?php esc_attr_e( 'Filter by order', 'ds-prod-import-crm' ); ?>">
				<option value=""><?php esc_html_e( 'All orders', 'ds-prod-import-crm' ); ?></option>
			</select>
			<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" />
			<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-per-page" aria-label="<?php esc_attr_e( 'Rows per page', 'ds-prod-import-crm' ); ?>">
				<option value="10">10</option>
				<option value="25">25</option>
				<option value="50">50</option>
			</select>
		</div>
		<p class="ds-crm-list-filter-hint" hidden></p>
	</div>

	<div class="ds-crm-table-wrap">
		<table class="ds-crm-table ds-crm-deliveries-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Delivery #', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?></th>
					<?php if ( ! $is_client ) : ?>
					<th><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Items', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'KG', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Shipping', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td colspan="<?php echo (int) $col_count; ?>"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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

<div class="ds-crm-modal" id="ds-crm-delivery-view-modal" hidden data-is-client="<?php echo $is_client ? '1' : '0'; ?>">
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-lg" role="dialog" aria-modal="true" aria-labelledby="ds-crm-delivery-view-title">
		<div class="ds-crm-modal-header">
			<h2 id="ds-crm-delivery-view-title"><?php esc_html_e( 'Delivery details', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<div class="ds-crm-delivery-view-body">
			<div class="ds-crm-delivery-view-actions"></div>
			<div class="ds-crm-delivery-view-meta"></div>
			<div class="ds-crm-table-wrap">
				<table class="ds-crm-table ds-crm-delivery-view-items">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Color / Size', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Weight (kg)', 'ds-prod-import-crm' ); ?></th>
							<?php if ( ! $is_client ) : ?>
							<th><?php esc_html_e( 'Ship. rate / kg', 'ds-prod-import-crm' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Line shipping', 'ds-prod-import-crm' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>
