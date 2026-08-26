<?php
/**
 * Orders list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_orders_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to view orders.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_create   = current_user_can( 'crm_orders_create' );
$can_edit_any = current_user_can( 'crm_orders_edit' );
$can_cancel   = current_user_can( 'crm_orders_cancel' );
$can_status   = current_user_can( 'crm_orders_status' );
$can_accept   = current_user_can( 'crm_orders_accept' );
$is_client    = CRM_Client_Portal::is_client_user();
?>
<div class="ds-crm-module-page" data-crm-module="orders"
	data-can-create="<?php echo $can_create ? '1' : '0'; ?>"
	data-can-edit-any="<?php echo $can_edit_any ? '1' : '0'; ?>"
	data-can-cancel="<?php echo $can_cancel ? '1' : '0'; ?>"
	data-can-status="<?php echo $can_status ? '1' : '0'; ?>"
	data-is-client="<?php echo $is_client ? '1' : '0'; ?>">
	<div class="ds-crm-page-header ds-crm-page-header--list">
		<div class="ds-crm-page-header-main">
			<h1><?php esc_html_e( 'Orders', 'ds-prod-import-crm' ); ?></h1>
			<p class="ds-crm-page-subtitle"><?php esc_html_e( 'Customer orders, deliveries, and billing in one place.', 'ds-prod-import-crm' ); ?></p>
		</div>
		<?php if ( $can_create ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( crm_order_form_url() ); ?>">
				<?php esc_html_e( 'New Order', 'ds-prod-import-crm' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( CRM_Client_Portal::is_client_user() && $can_create && ! $can_edit_any ) : ?>
	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'You can edit orders you created yourself only while they are awaiting acceptance. Orders placed on your behalf by staff cannot be changed here. Unit price is optional — the China office will confirm final prices before approval.', 'ds-prod-import-crm' ); ?>
	</div>
	<?php endif; ?>

	<?php if ( $can_accept ) : ?>
	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'New orders start as Awaiting acceptance. Open an order, set unit prices on each line if needed, then click Accept order. Admins and China office staff can approve orders.', 'ds-prod-import-crm' ); ?>
	</div>
	<?php endif; ?>

	<div class="ds-crm-list-controls">
		<div class="ds-crm-toolbar ds-crm-toolbar--list">
			<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search order # or client…', 'ds-prod-import-crm' ); ?>" />
			<?php if ( ! CRM_Client_Portal::is_client_user() || 'all' === CRM_Client_Portal::orders_scope() ) : ?>
			<select class="ds-crm-filter-client" aria-label="<?php esc_attr_e( 'Filter by client', 'ds-prod-import-crm' ); ?>">
				<option value=""><?php esc_html_e( 'All clients', 'ds-prod-import-crm' ); ?></option>
			</select>
			<?php endif; ?>
			<select class="ds-crm-filter-status">
				<option value=""><?php esc_html_e( 'All statuses', 'ds-prod-import-crm' ); ?></option>
			</select>
			<select class="ds-crm-filter-tracking" aria-label="<?php esc_attr_e( 'Filter by tracking', 'ds-prod-import-crm' ); ?>">
				<option value=""><?php esc_html_e( 'All tracking', 'ds-prod-import-crm' ); ?></option>
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

	<div class="ds-crm-notice ds-crm-notice-info ds-crm-notice--compact ds-crm-notice--dismissible" data-notice-key="orders-help"<?php echo $is_client ? ' hidden' : ''; ?>>
		<p><?php esc_html_e( 'Orders can be placed before stock arrives. Receive stock under Warehouse, deliver under Delivery, and record payments under Payments.', 'ds-prod-import-crm' ); ?></p>
		<button type="button" class="ds-crm-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'ds-prod-import-crm' ); ?>">&times;</button>
	</div>

	<div class="ds-crm-table-wrap ds-crm-orders-table-wrap">
		<table class="ds-crm-table ds-crm-orders-table">
			<thead>
				<tr>
					<th data-sort="order_number"><?php esc_html_e( 'Order #', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="order_date"><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="client_name"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></th>
					<th class="ds-crm-amount-col" data-sort="total_amount"><?php esc_html_e( 'Order Bill', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="status"><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Tracking', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td colspan="8"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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
