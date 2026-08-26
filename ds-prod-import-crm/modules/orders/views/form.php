<?php
/**
 * Full-page order composer (new / edit).
 *
 * @var bool $is_edit
 * @var int  $order_id
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$is_edit  = ! empty( $is_edit );
$order_id = isset( $order_id ) ? absint( $order_id ) : 0;
$list_url = crm_module_url( 'orders', 'frontend' );
?>
<div class="ds-crm-module-page ds-crm-order-form-page" data-crm-module="orders-form" data-mode="<?php echo $is_edit ? 'edit' : 'new'; ?>" data-order-id="<?php echo esc_attr( (string) $order_id ); ?>">
	<div class="ds-crm-page-header ds-crm-order-form-header">
		<div class="ds-crm-order-form-heading">
			<a class="ds-crm-back-link" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to orders', 'ds-prod-import-crm' ); ?></a>
			<h1 id="ds-crm-order-form-title"><?php echo $is_edit ? esc_html__( 'Edit order', 'ds-prod-import-crm' ) : esc_html__( 'New order', 'ds-prod-import-crm' ); ?></h1>
		</div>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'Search catalog products or add new ones with a photo. Stock is not required to place an order — deliver when available.', 'ds-prod-import-crm' ); ?>
		<?php if ( CRM_Client_Portal::is_client_user() && ! current_user_can( 'crm_orders_edit' ) ) : ?>
			<?php esc_html_e( 'Unit price is optional when placing an order — the China office will set final prices before approval.', 'ds-prod-import-crm' ); ?>
		<?php endif; ?>
	</div>

	<form class="ds-crm-form ds-crm-order-form" novalidate>
		<input type="hidden" name="id" value="<?php echo $is_edit ? esc_attr( (string) $order_id ) : ''; ?>" />

		<div class="ds-crm-form-error" hidden></div>
		<p class="ds-crm-form-hint description" hidden></p>

		<div class="ds-crm-order-form-card">
			<h2 class="ds-crm-order-form-section-title"><?php esc_html_e( 'Order details', 'ds-prod-import-crm' ); ?></h2>
			<div class="ds-crm-form-grid">
				<p class="ds-crm-field-full ds-crm-order-client-field">
					<label for="order-client-search"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="hidden" name="client_id" value="" />
					<input type="search" id="order-client-search" class="ds-crm-client-search" placeholder="<?php esc_attr_e( 'Type 2+ letters (name or phone)…', 'ds-prod-import-crm' ); ?>" autocomplete="off" />
					<span class="ds-crm-selected-client" hidden></span>
					<div class="ds-crm-autocomplete-list ds-crm-client-suggestions" hidden></div>
				</p>
				<p>
					<label for="order-date"><?php esc_html_e( 'Order date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="date" id="order-date" name="order_date" required />
				</p>
				<p class="ds-crm-field-full">
					<label for="order-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
					<textarea id="order-notes" name="notes" rows="3"></textarea>
				</p>
			</div>
		</div>

		<div class="ds-crm-order-form-card">
			<div class="ds-crm-order-form-section-head">
				<h2 class="ds-crm-order-form-section-title"><?php esc_html_e( 'Line items', 'ds-prod-import-crm' ); ?></h2>
				<button type="button" class="button ds-crm-add-order-line"><?php esc_html_e( '+ Add line', 'ds-prod-import-crm' ); ?></button>
			</div>
			<div class="ds-crm-table-wrap ds-crm-line-items-wrap ds-crm-order-line-items-wrap">
				<table class="ds-crm-table ds-crm-order-lines">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Color', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Size', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Delivery priority', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'ds-prod-import-crm' ); ?></th>
							<th class="ds-crm-order-price-header"><?php esc_html_e( 'Unit price', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Line total', 'ds-prod-import-crm' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody></tbody>
					<tfoot>
						<tr class="ds-crm-order-lines-total">
							<td colspan="6" class="ds-crm-order-total-label"><?php esc_html_e( 'Total', 'ds-prod-import-crm' ); ?></td>
							<td class="ds-crm-order-total-value">৳0.00</td>
							<td></td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>

		<div class="ds-crm-order-form-actions-bar">
			<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></a>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save order', 'ds-prod-import-crm' ); ?></button>
		</div>
	</form>
</div>
