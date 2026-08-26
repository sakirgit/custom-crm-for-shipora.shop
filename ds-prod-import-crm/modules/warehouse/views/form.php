<?php
/**
 * Full-page warehouse receive composer.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_stock_receive' ) && ! current_user_can( 'crm_receive_stock' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to record receives.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$list_url     = crm_module_url( 'warehouse', 'frontend' );
$shipment_id  = isset( $_GET['shipment_id'] ) ? absint( wp_unslash( $_GET['shipment_id'] ) ) : 0;
$is_shipment  = $shipment_id > 0;
?>
<div
	class="ds-crm-module-page ds-crm-receive-form-page"
	data-crm-module="warehouse-form"
	data-mode="new"
	data-shipment-id="<?php echo esc_attr( (string) $shipment_id ); ?>"
>
	<div class="ds-crm-page-header ds-crm-receive-form-header">
		<div class="ds-crm-receive-form-heading">
			<a class="ds-crm-back-link" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to warehouse', 'ds-prod-import-crm' ); ?></a>
			<h1>
				<?php
				echo $is_shipment
					? esc_html__( 'Receive from China shipment', 'ds-prod-import-crm' )
					: esc_html__( 'Manual receive', 'ds-prod-import-crm' );
				?>
			</h1>
		</div>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php
		if ( $is_shipment ) {
			esc_html_e( 'Products are loaded from the China export shipment. Enter how many pcs arrived into stock and mark any missing qty per product. Weight and shipping rate apply only to received stock.', 'ds-prod-import-crm' );
		} else {
			esc_html_e( 'Manual receive (not linked to a China shipment). Prefer receiving from Awaiting arrival when cargo was exported from China.', 'ds-prod-import-crm' );
		}
		?>
	</div>

	<form class="ds-crm-form ds-crm-receive-form" novalidate>
		<input type="hidden" name="shipment_id" value="<?php echo esc_attr( (string) $shipment_id ); ?>" />
		<div class="ds-crm-form-error" hidden></div>

		<div class="ds-crm-receive-form-card">
			<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Shipment details', 'ds-prod-import-crm' ); ?></h2>
			<div class="ds-crm-form-grid ds-crm-receive-form-grid">
				<?php if ( $is_shipment ) : ?>
					<p>
						<label><?php esc_html_e( 'Export shipment', 'ds-prod-import-crm' ); ?></label>
						<input type="text" class="ds-crm-shipment-number" readonly value="" />
					</p>
					<p>
						<label><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></label>
						<input type="text" class="ds-crm-shipment-client" readonly value="" />
					</p>
					<p>
						<label><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?></label>
						<input type="text" class="ds-crm-shipment-order" readonly value="" />
					</p>
				<?php endif; ?>
				<p>
					<label for="receive-company"><?php esc_html_e( 'Cargo / supplier', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<?php if ( $is_shipment ) : ?>
						<input type="text" id="receive-company-display" class="ds-crm-shipment-company" readonly value="" />
						<input type="hidden" name="company_id" class="ds-crm-company-id-hidden" value="" />
					<?php else : ?>
						<select id="receive-company" name="company_id" required>
							<option value=""><?php esc_html_e( '— Select company —', 'ds-prod-import-crm' ); ?></option>
						</select>
					<?php endif; ?>
				</p>
				<p>
					<label for="receive-date"><?php esc_html_e( 'Receive date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="date" id="receive-date" name="receive_date" required />
				</p>
				<p>
					<label for="receive-default-shipping-rate"><?php esc_html_e( 'Default shipping rate (BDT / kg)', 'ds-prod-import-crm' ); ?></label>
					<input type="number" id="receive-default-shipping-rate" class="ds-crm-default-shipping-rate ds-crm-money-input" min="0" step="0.01" value="0" />
					<span class="description"><?php esc_html_e( 'Prefills new / received lines. Each line can still use its own rate.', 'ds-prod-import-crm' ); ?></span>
				</p>
				<p class="ds-crm-field-full">
					<label for="receive-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
					<textarea id="receive-notes" name="notes" rows="2"></textarea>
				</p>
			</div>
			<?php if ( $is_shipment ) : ?>
				<p class="description ds-crm-shipment-progress" hidden></p>
			<?php endif; ?>
		</div>

		<div class="ds-crm-receive-form-card">
			<div class="ds-crm-receive-form-section-head">
				<h2 class="ds-crm-receive-form-section-title">
					<?php
					echo $is_shipment
						? esc_html__( 'Shipment products', 'ds-prod-import-crm' )
						: esc_html__( 'Received items', 'ds-prod-import-crm' );
					?>
				</h2>
				<?php if ( ! $is_shipment ) : ?>
					<button type="button" class="button ds-crm-add-receive-line"><?php esc_html_e( '+ Add line', 'ds-prod-import-crm' ); ?></button>
				<?php endif; ?>
			</div>
			<p class="description">
				<?php
				if ( $is_shipment ) {
					esc_html_e( 'Receive qty goes into warehouse stock. Missing qty closes that portion of the shipment without stock. Leave both at 0 to skip a product for a later receive.', 'ds-prod-import-crm' );
				} else {
					esc_html_e( 'Enter weight and shipping rate per line (kg). Line shipping = weight × rate.', 'ds-prod-import-crm' );
				}
				?>
			</p>
			<div class="ds-crm-table-wrap ds-crm-line-items-wrap ds-crm-receive-line-items-wrap">
				<table class="ds-crm-table ds-crm-receive-lines">
					<thead>
						<?php if ( $is_shipment ) : ?>
							<tr>
								<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Shipped', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Already OK', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Already missing', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Remaining', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Receive now', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Missing now', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Weight (kg)', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Ship. rate / kg', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Line shipping', 'ds-prod-import-crm' ); ?></th>
							</tr>
						<?php else : ?>
							<tr>
								<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Color', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Size', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Qty', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Weight (kg)', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Ship. rate / kg', 'ds-prod-import-crm' ); ?></th>
								<th><?php esc_html_e( 'Line shipping', 'ds-prod-import-crm' ); ?></th>
								<th></th>
							</tr>
						<?php endif; ?>
					</thead>
					<tbody></tbody>
				</table>
			</div>

			<div class="ds-crm-receive-totals">
				<div class="ds-crm-receive-total-card">
					<span class="ds-crm-receive-total-label"><?php esc_html_e( 'Total weight', 'ds-prod-import-crm' ); ?></span>
					<span class="ds-crm-receive-total-value ds-crm-receive-total-kg">0.00 kg</span>
				</div>
				<div class="ds-crm-receive-total-card ds-crm-receive-total-card--accent">
					<span class="ds-crm-receive-total-label"><?php esc_html_e( 'Shipping bill', 'ds-prod-import-crm' ); ?></span>
					<span class="ds-crm-receive-total-value ds-crm-receive-total-shipping">৳0.00</span>
				</div>
			</div>
		</div>

		<div class="ds-crm-receive-form-actions-bar">
			<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></a>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save receive', 'ds-prod-import-crm' ); ?></button>
		</div>
	</form>
</div>
