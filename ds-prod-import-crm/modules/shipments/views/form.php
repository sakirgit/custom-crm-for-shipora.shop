<?php
/**
 * Unified order workspace — approve, confirm supply, assign shipper.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$preset_order_id   = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
$can_record_export = current_user_can( 'crm_shipments_create' );
$can_accept        = current_user_can( 'crm_orders_accept' );
$can_edit_prices   = current_user_can( 'crm_orders_edit' );
$can_open_order    = $preset_order_id > 0 && current_user_can( 'crm_shipments_view' ) && ( $can_accept || $can_edit_prices || $can_record_export );
$is_workspace      = $preset_order_id > 0;

if ( ! $can_record_export && ! $can_open_order ) {
	echo '<p>' . esc_html__( 'You do not have permission to open this page.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$export_companies = array();
if ( $can_record_export ) {
	global $wpdb;
	$export_companies = $wpdb->get_results(
		'SELECT id, name FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' AND company_type = 'cargo' ORDER BY name ASC",
		ARRAY_A
	);
	if ( ! $export_companies ) {
		// Fallback: any active company (legacy rows without type).
		$export_companies = $wpdb->get_results(
			'SELECT id, name FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);
	}
	$export_companies = $export_companies ? $export_companies : array();
}

$list_url     = crm_module_url( 'shipments', 'frontend' );
$module_label = crm_shipments_module_label();
$page_title   = $is_workspace
	? __( 'Order workspace', 'ds-prod-import-crm' )
	: __( 'Record export shipment', 'ds-prod-import-crm' );

/**
 * Render export company <option> list.
 *
 * @param array<int, array<string, mixed>> $companies Companies.
 * @return void
 */
$render_company_options = static function ( $companies ) {
	foreach ( $companies as $company ) {
		printf(
			'<option value="%1$d">%2$s</option>',
			(int) ( $company['id'] ?? 0 ),
			esc_html( (string) ( $company['name'] ?? '' ) )
		);
	}
};
?>
<div class="ds-crm-module-page ds-crm-receive-form-page ds-crm-shipment-form-page<?php echo $is_workspace ? ' ds-crm-shipment-form-page--workspace' : ''; ?>"
	data-crm-module="shipment-form"
	data-preset-order-id="<?php echo esc_attr( (string) $preset_order_id ); ?>"
	data-lock-order="<?php echo $is_workspace ? '1' : '0'; ?>"
	data-can-accept="<?php echo $can_accept ? '1' : '0'; ?>"
	data-can-edit-prices="<?php echo $can_edit_prices ? '1' : '0'; ?>"
	data-can-record-export="<?php echo $can_record_export ? '1' : '0'; ?>"
	data-can-amend="<?php echo current_user_can( 'crm_shipments_amend' ) ? '1' : '0'; ?>"
	data-can-review="<?php echo current_user_can( 'crm_shipments_review' ) ? '1' : '0'; ?>">
	<div class="ds-crm-page-header ds-crm-receive-form-header">
		<div class="ds-crm-receive-form-heading">
			<a class="ds-crm-back-link" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s: module menu label */ __( 'Back to %s', 'ds-prod-import-crm' ), $module_label ) ); ?></a>
			<h1 id="ds-crm-shipment-page-title"><?php echo esc_html( $page_title ); ?></h1>
		</div>
	</div>

	<div class="ds-crm-shipment-page-loading" hidden>
		<p><?php esc_html_e( 'Loading order…', 'ds-prod-import-crm' ); ?></p>
	</div>

	<?php if ( $is_workspace ) : ?>
	<div class="ds-crm-shipment-workspace-shell">
		<div class="ds-crm-receive-form-card ds-crm-shipment-workspace-header" hidden>
			<div class="ds-crm-shipment-workspace-header-inner"></div>
		</div>
		<ol class="ds-crm-shipment-workspace-steps" aria-label="<?php esc_attr_e( 'China sourcing workflow', 'ds-prod-import-crm' ); ?>">
			<li class="ds-crm-shipment-workspace-step ds-crm-shipment-workspace-step--approve" data-step="approve">
				<span class="ds-crm-shipment-workspace-step-num" aria-hidden="true">1</span>
				<span class="ds-crm-shipment-workspace-step-copy">
					<span class="ds-crm-shipment-workspace-step-label"><?php esc_html_e( 'Approve order', 'ds-prod-import-crm' ); ?></span>
					<span class="ds-crm-shipment-workspace-step-desc"><?php esc_html_e( 'Accept qty & price', 'ds-prod-import-crm' ); ?></span>
				</span>
			</li>
			<li class="ds-crm-shipment-workspace-step ds-crm-shipment-workspace-step--supply" data-step="supply">
				<span class="ds-crm-shipment-workspace-step-num" aria-hidden="true">2</span>
				<span class="ds-crm-shipment-workspace-step-copy">
					<span class="ds-crm-shipment-workspace-step-label"><?php esc_html_e( 'Confirm supply', 'ds-prod-import-crm' ); ?></span>
					<span class="ds-crm-shipment-workspace-step-desc"><?php esc_html_e( 'Ship now & company', 'ds-prod-import-crm' ); ?></span>
				</span>
			</li>
		</ol>
	</div>
	<?php endif; ?>

	<section class="ds-crm-receive-form-card ds-crm-shipment-order-review-card" hidden>
		<div class="ds-crm-shipment-order-review-head">
			<h2 class="ds-crm-receive-form-section-title">
				<?php if ( $is_workspace ) : ?>
					<span class="ds-crm-shipment-step-badge" aria-hidden="true">1</span>
				<?php endif; ?>
				<?php esc_html_e( 'Approve order', 'ds-prod-import-crm' ); ?>
			</h2>
			<p class="ds-crm-shipment-order-review-hint description"></p>
		</div>
		<div class="ds-crm-shipment-order-review-meta" hidden></div>
		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-shipment-order-review-items">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Color / Size', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Ordered qty', 'ds-prod-import-crm' ); ?></th>
						<?php if ( $is_workspace ) : ?>
						<th><?php esc_html_e( 'Accept qty', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Unit price', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Line total', 'ds-prod-import-crm' ); ?></th>
						<?php else : ?>
						<th class="ds-crm-shipment-price-col"><?php esc_html_e( 'Unit price', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Line total', 'ds-prod-import-crm' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody></tbody>
				<?php if ( $is_workspace ) : ?>
				<tfoot hidden>
					<tr class="ds-crm-order-lines-total"></tr>
				</tfoot>
				<?php elseif ( ! $is_workspace ) : ?>
				<tfoot hidden>
					<tr class="ds-crm-order-lines-total"></tr>
				</tfoot>
				<?php endif; ?>
			</table>
		</div>
		<p class="ds-crm-shipment-review-pricing-hint description" hidden></p>
		<p class="ds-crm-shipment-approval-note-field" hidden>
			<label for="shipment-order-approval-note"><?php esc_html_e( 'Approval note', 'ds-prod-import-crm' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'ds-prod-import-crm' ); ?></span></label>
			<textarea id="shipment-order-approval-note" name="approval_note" class="ds-crm-shipment-approval-note" rows="3" placeholder="<?php esc_attr_e( 'Add a note for this approval…', 'ds-prod-import-crm' ); ?>"></textarea>
		</p>
		<div class="ds-crm-shipment-approval-note-saved" hidden></div>
		<div class="ds-crm-shipment-order-review-actions" hidden>
			<?php if ( ! $is_workspace ) : ?>
			<button type="button" class="button ds-crm-shipment-save-prices"><?php esc_html_e( 'Save prices', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="button button-primary ds-crm-shipment-save-accept-order"><?php esc_html_e( 'Save prices & approve', 'ds-prod-import-crm' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button button-primary ds-crm-shipment-accept-order"><?php esc_html_e( 'Approve order', 'ds-prod-import-crm' ); ?></button>
		</div>
	</section>

	<div class="ds-crm-shipment-export-wrap"<?php echo ! $can_record_export ? ' hidden' : ''; ?>>
		<div class="ds-crm-notice ds-crm-notice-info ds-crm-shipment-export-notice"<?php echo $is_workspace ? ' hidden' : ''; ?>>
			<?php esc_html_e( 'Search and select the order, set the ship date, then choose the export company and enter how many of each product are in this shipment. You can record multiple partial exports for the same order over time.', 'ds-prod-import-crm' ); ?>
		</div>

		<?php if ( $is_workspace ) : ?>
		<div class="ds-crm-receive-form-card ds-crm-shipment-next-steps-hint" hidden>
			<p class="description" style="margin:0;">
				<?php esc_html_e( 'Approve accepted quantity and unit price above to unlock Confirm supply.', 'ds-prod-import-crm' ); ?>
			</p>
		</div>
		<?php else : ?>
		<div class="ds-crm-receive-form-card ds-crm-shipment-export-locked-card" hidden>
			<div class="ds-crm-shipment-export-locked-head">
				<span class="ds-crm-shipment-export-locked-step" aria-hidden="true">2</span>
				<div>
					<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Record export shipment', 'ds-prod-import-crm' ); ?></h2>
					<p class="description ds-crm-shipment-export-locked-text"><?php esc_html_e( 'Approve the order first to unlock export recording.', 'ds-prod-import-crm' ); ?></p>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="ds-crm-shipment-export-form-body">
			<form class="ds-crm-form ds-crm-shipment-form" novalidate>
				<div class="ds-crm-form-error" hidden></div>

				<?php if ( ! $is_workspace ) : ?>
				<div class="ds-crm-receive-form-card">
					<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Record export shipment', 'ds-prod-import-crm' ); ?></h2>
					<div class="ds-crm-shipment-order-preview" hidden>
						<div class="ds-crm-shipment-order-preview-inner"></div>
					</div>
					<div class="ds-crm-shipment-details-rows">
						<div class="ds-crm-shipment-details-row ds-crm-shipment-details-row--order-date">
							<p class="ds-crm-shipment-order-field">
								<label for="shipment-order-search"><?php esc_html_e( 'Order', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
								<input type="hidden" name="order_id" value="" />
								<input type="search" id="shipment-order-search" class="ds-crm-shipment-order-search" placeholder="<?php esc_attr_e( 'Type 2+ letters (order #, client, product)…', 'ds-prod-import-crm' ); ?>" autocomplete="off" />
								<span class="ds-crm-selected-shipment-order" hidden></span>
								<div class="ds-crm-autocomplete-list ds-crm-shipment-order-suggestions" hidden></div>
							</p>
							<p class="ds-crm-shipment-date-field">
								<label for="shipment-date"><?php esc_html_e( 'Ship date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
								<input type="date" id="shipment-date" name="ship_date" required />
							</p>
						</div>
						<div class="ds-crm-shipment-details-row ds-crm-shipment-details-row--company-notes">
							<p class="ds-crm-shipment-company-field">
								<label for="shipment-company"><?php esc_html_e( 'Export company', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
								<select id="shipment-company" name="company_id" required>
									<option value=""><?php esc_html_e( '— Select company —', 'ds-prod-import-crm' ); ?></option>
									<?php $render_company_options( $export_companies ); ?>
								</select>
								<?php if ( $can_record_export && empty( $export_companies ) ) : ?>
									<span class="description ds-crm-notice-warning" style="display:block;margin-top:6px;">
										<?php esc_html_e( 'No active cargo companies found. Add a company under Companies (type: Cargo) first.', 'ds-prod-import-crm' ); ?>
									</span>
								<?php else : ?>
								<span class="description"><?php esc_html_e( 'Cargo / export company handling this shipment from China.', 'ds-prod-import-crm' ); ?></span>
								<?php endif; ?>
							</p>
							<p class="ds-crm-shipment-notes-field">
								<label for="shipment-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
								<textarea id="shipment-notes" name="notes" rows="4"></textarea>
							</p>
						</div>
					</div>
				</div>
				<?php else : ?>
				<input type="hidden" name="order_id" value="" />
				<div class="ds-crm-shipment-order-preview" hidden>
					<div class="ds-crm-shipment-order-preview-inner"></div>
				</div>
				<input type="search" id="shipment-order-search" class="ds-crm-shipment-order-search" hidden tabindex="-1" aria-hidden="true" />
				<span class="ds-crm-selected-shipment-order" hidden></span>
				<div class="ds-crm-autocomplete-list ds-crm-shipment-order-suggestions" hidden></div>
				<?php endif; ?>

				<?php if ( $is_workspace ) : ?>
				<div class="ds-crm-receive-form-card ds-crm-shipment-progress-card" hidden>
					<div class="ds-crm-shipment-order-review-head">
						<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Supply progress', 'ds-prod-import-crm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Confirmed quantity is the accepted commitment. Track how much is already on the way.', 'ds-prod-import-crm' ); ?></p>
					</div>
					<div class="ds-crm-shipment-progress-metrics" aria-live="polite"></div>
					<div class="ds-crm-shipment-progress-track" hidden>
						<div class="ds-crm-shipment-progress-bar" style="width:0%"></div>
					</div>
					<p class="ds-crm-shipment-progress-caption description" hidden></p>
				</div>

				<div class="ds-crm-receive-form-card ds-crm-shipment-history-card" hidden>
					<div class="ds-crm-shipment-order-review-head">
						<h2 class="ds-crm-receive-form-section-title"><?php esc_html_e( 'Supply history', 'ds-prod-import-crm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Each batch keeps its company, products, quantities, weight, and notes. Use Change on a product row to request a qty/weight update for that product only — after supervisor approval, freed qty can be supplied again below.', 'ds-prod-import-crm' ); ?></p>
					</div>
					<div class="ds-crm-shipment-history-list"></div>
				</div>
				<?php endif; ?>

				<div class="ds-crm-receive-form-card ds-crm-shipment-supply-card">
					<div class="ds-crm-shipment-supply-complete" hidden></div>
					<div class="ds-crm-shipment-supply-active">
						<div class="ds-crm-shipment-order-review-head">
							<h2 class="ds-crm-receive-form-section-title">
								<?php if ( $is_workspace ) : ?>
									<span class="ds-crm-shipment-step-badge" aria-hidden="true">2</span>
								<?php endif; ?>
								<?php echo $is_workspace ? esc_html__( 'Confirm supply', 'ds-prod-import-crm' ) : esc_html__( 'Products in this shipment', 'ds-prod-import-crm' ); ?>
							</h2>
							<p class="description ds-crm-shipment-supply-hint">
								<?php
								echo $is_workspace
									? esc_html__( 'Ship against the accepted quantity. You can supply part now and the rest later. Choose a shipping company and submit to mark goods on the way.', 'ds-prod-import-crm' )
									: esc_html__( 'Enter ship qty (max = still to export). Weight is optional but helps track cargo.', 'ds-prod-import-crm' );
								?>
							</p>
						</div>
						<div class="ds-crm-notice ds-crm-notice-info ds-crm-shipment-supply-confirmed-notice" hidden>
							<?php esc_html_e( 'Remaining accepted quantity can be shipped in a later supply.', 'ds-prod-import-crm' ); ?>
						</div>
						<div class="ds-crm-table-wrap ds-crm-line-items-wrap ds-crm-receive-line-items-wrap">
							<table class="ds-crm-table ds-crm-shipment-lines">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
										<th><?php esc_html_e( 'Priority', 'ds-prod-import-crm' ); ?></th>
										<th><?php esc_html_e( 'Color / Size', 'ds-prod-import-crm' ); ?></th>
										<th><?php esc_html_e( 'Ordered', 'ds-prod-import-crm' ); ?></th>
										<?php if ( $is_workspace ) : ?>
										<th><?php esc_html_e( 'Accepted', 'ds-prod-import-crm' ); ?></th>
										<?php endif; ?>
										<th><?php esc_html_e( 'Already shipped', 'ds-prod-import-crm' ); ?></th>
										<th><?php esc_html_e( 'Still to ship', 'ds-prod-import-crm' ); ?></th>
										<th><?php echo $is_workspace ? esc_html__( 'Supply now', 'ds-prod-import-crm' ) : esc_html__( 'Ship now', 'ds-prod-import-crm' ); ?></th>
										<th><?php esc_html_e( 'Weight (kg)', 'ds-prod-import-crm' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td colspan="<?php echo $is_workspace ? '9' : '8'; ?>" class="ds-crm-empty"><?php esc_html_e( 'Select an order above.', 'ds-prod-import-crm' ); ?></td>
									</tr>
								</tbody>
							</table>
						</div>

						<div class="ds-crm-receive-totals ds-crm-shipment-supply-totals">
							<?php if ( $is_workspace ) : ?>
							<div class="ds-crm-receive-total-card">
								<span class="ds-crm-receive-total-label"><?php esc_html_e( 'Supplying now', 'ds-prod-import-crm' ); ?></span>
								<span class="ds-crm-receive-total-value ds-crm-shipment-supply-now-qty">0 pcs</span>
							</div>
							<?php endif; ?>
							<div class="ds-crm-receive-total-card">
								<span class="ds-crm-receive-total-label"><?php esc_html_e( 'Shipment weight', 'ds-prod-import-crm' ); ?></span>
								<span class="ds-crm-receive-total-value ds-crm-shipment-total-kg">0.00 kg</span>
							</div>
						</div>

						<?php if ( $is_workspace ) : ?>
						<div class="ds-crm-shipment-details-rows ds-crm-shipment-supply-shipper-fields">
							<div class="ds-crm-shipment-details-row ds-crm-shipment-details-row--order-date">
								<p class="ds-crm-shipment-date-field">
									<label for="shipment-date"><?php esc_html_e( 'Ship date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
									<input type="date" id="shipment-date" name="ship_date" required />
								</p>
								<p class="ds-crm-shipment-company-field">
									<label for="shipment-company"><?php esc_html_e( 'Shipping company', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
									<select id="shipment-company" name="company_id" required>
										<option value=""><?php esc_html_e( '— Select company —', 'ds-prod-import-crm' ); ?></option>
										<?php $render_company_options( $export_companies ); ?>
									</select>
									<?php if ( $can_record_export && empty( $export_companies ) ) : ?>
										<span class="description" style="display:block;margin-top:6px;color:#b45309;">
											<?php esc_html_e( 'No active cargo companies found. Add a company under Companies (type: Cargo) first.', 'ds-prod-import-crm' ); ?>
										</span>
									<?php endif; ?>
								</p>
							</div>
							<div class="ds-crm-shipment-details-row">
								<p class="ds-crm-shipment-notes-field" style="grid-column: 1 / -1;">
									<label for="shipment-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
									<textarea id="shipment-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional — tracking notes, special handling…', 'ds-prod-import-crm' ); ?>"></textarea>
								</p>
							</div>
						</div>
						<div class="ds-crm-shipment-supply-actions">
							<button type="submit" class="button button-primary ds-crm-shipment-submit-ship">
								<?php esc_html_e( 'Confirm supply — on the way', 'ds-prod-import-crm' ); ?>
							</button>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="ds-crm-shipment-shipper-card" hidden></div>

				<div class="ds-crm-receive-form-actions-bar"<?php echo $is_workspace ? ' hidden' : ''; ?>>
					<a class="button" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></a>
					<button type="submit" class="button button-primary ds-crm-shipment-submit-ship">
						<?php esc_html_e( 'Save export shipment', 'ds-prod-import-crm' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>
