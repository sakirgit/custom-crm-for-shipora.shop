<?php
/**
 * Full-page customer delivery composer.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_delivery_create' ) && ! current_user_can( 'crm_delivery_edit' ) && ! current_user_can( 'crm_manage_delivery' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to record deliveries.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$list_url         = crm_module_url( 'delivery', 'frontend' );
$preset_order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
?>
<div class="ds-crm-module-page ds-crm-receive-form-page ds-crm-delivery-form-page" data-crm-module="delivery-form" data-preset-order-id="<?php echo esc_attr( (string) $preset_order_id ); ?>">
	<div class="ds-crm-page-header ds-crm-receive-form-header">
		<div class="ds-crm-receive-form-heading">
			<a class="ds-crm-back-link" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to delivery', 'ds-prod-import-crm' ); ?></a>
			<h1><?php esc_html_e( 'New delivery', 'ds-prod-import-crm' ); ?></h1>
		</div>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'Ship products to the customer against an order. Enter deliver quantity, weight, and shipping rate per line — rates are prefilled from warehouse receive history. Line shipping = weight × rate. Deliveries cannot be edited after save.', 'ds-prod-import-crm' ); ?>
	</div>

	<form class="ds-crm-form ds-crm-delivery-form" novalidate>
		<div class="ds-crm-form-error" hidden></div>

		<div class="ds-crm-receive-form-card">
			<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Delivery details', 'ds-prod-import-crm' ); ?></h2>
			<div class="ds-crm-delivery-order-preview" hidden>
				<div class="ds-crm-delivery-order-preview-inner"></div>
			</div>
			<div class="ds-crm-form-grid ds-crm-receive-form-grid">
				<p class="ds-crm-delivery-order-field">
					<label for="delivery-order-search"><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="hidden" id="delivery-order" name="order_id" value="" />
					<input type="search" id="delivery-order-search" class="ds-crm-delivery-order-search" placeholder="<?php esc_attr_e( 'Search order #, client, or product (not just ORD-)…', 'ds-prod-import-crm' ); ?>" autocomplete="off" />
					<span class="ds-crm-selected-delivery-order" hidden></span>
					<div class="ds-crm-autocomplete-list ds-crm-delivery-order-suggestions" hidden></div>
					<span class="description"><?php esc_html_e( 'Search orders that still have items due for delivery.', 'ds-prod-import-crm' ); ?></span>
				</p>
				<p>
					<label for="delivery-date"><?php esc_html_e( 'Delivery date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="date" id="delivery-date" name="delivery_date" required />
				</p>
				<p>
					<label for="delivery-receiver-name"><?php esc_html_e( 'Receiver name', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="delivery-receiver-name" name="receiver_name" />
				</p>
				<p>
					<label for="delivery-receiver-phone"><?php esc_html_e( 'Receiver phone', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="delivery-receiver-phone" name="receiver_phone" />
				</p>
				<p class="ds-crm-field-full">
					<label for="delivery-receiver-address"><?php esc_html_e( 'Receiver address', 'ds-prod-import-crm' ); ?></label>
					<textarea id="delivery-receiver-address" name="receiver_address" rows="2"></textarea>
				</p>
				<p class="ds-crm-field-full">
					<label for="delivery-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
					<textarea id="delivery-notes" name="notes" rows="2"></textarea>
				</p>
			</div>
		</div>

		<div class="ds-crm-receive-form-card">
			<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Items to deliver', 'ds-prod-import-crm' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Enter deliver qty (max = due). Weight and shipping rate are prefilled from warehouse receive data; adjust if needed. Stock shows this variant, or total warehouse stock if the variant row is empty.', 'ds-prod-import-crm' ); ?>
			</p>
			<div class="ds-crm-table-wrap ds-crm-line-items-wrap ds-crm-receive-line-items-wrap">
				<table class="ds-crm-table ds-crm-delivery-lines">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Priority', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Color / Size', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Ordered', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Delivered', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Due', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Deliver now', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Weight (kg)', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Ship. rate / kg', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Line shipping', 'ds-prod-import-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td colspan="11" class="ds-crm-empty"><?php esc_html_e( 'Select an order above.', 'ds-prod-import-crm' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="ds-crm-receive-totals">
				<div class="ds-crm-receive-total-card">
					<span class="ds-crm-receive-total-label"><?php esc_html_e( 'Total weight', 'ds-prod-import-crm' ); ?></span>
					<span class="ds-crm-receive-total-value ds-crm-delivery-total-kg">0.00 kg</span>
				</div>
				<div class="ds-crm-receive-total-card ds-crm-receive-total-card--accent">
					<span class="ds-crm-receive-total-label"><?php esc_html_e( 'Shipping bill', 'ds-prod-import-crm' ); ?></span>
					<span class="ds-crm-receive-total-value ds-crm-delivery-total-shipping">৳0.00</span>
				</div>
			</div>
		</div>

		<div class="ds-crm-receive-form-actions-bar">
			<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></a>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save delivery', 'ds-prod-import-crm' ); ?></button>
		</div>
	</form>
</div>
