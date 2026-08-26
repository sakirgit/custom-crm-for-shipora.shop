<?php
/**
 * Payments list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_payments_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to view payments.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$is_client       = CRM_Client_Portal::is_client_user();
$can_create      = ! $is_client && ( current_user_can( 'crm_payments_create' ) || current_user_can( 'crm_manage_payments' ) );
$can_edit        = ! $is_client && ( current_user_can( 'crm_payments_edit' ) || current_user_can( 'crm_manage_payments' ) );
$can_delete      = ! $is_client && ( current_user_can( 'crm_payments_delete' ) || current_user_can( 'crm_manage_payments' ) );
$show_suppliers  = ! $is_client && CRM_Capability_Registry::user_can_view_supplier_payments();
$can_record_sup  = $show_suppliers && CRM_Capability_Registry::user_can_manage_billing();
$requested_tab   = isset( $_GET['payments_tab'] ) ? sanitize_key( wp_unslash( $_GET['payments_tab'] ) ) : 'clients';
$active_tab      = ( $show_suppliers && 'suppliers' === $requested_tab ) ? 'suppliers' : 'clients';
$preset_company  = isset( $_GET['company_id'] ) ? absint( $_GET['company_id'] ) : 0;
$col_count       = $is_client ? 6 : 8;
$supplier_cols   = 8;
?>
<div class="ds-crm-module-page" data-crm-module="payments"
	data-is-client="<?php echo $is_client ? '1' : '0'; ?>"
	data-can-create="<?php echo $can_create ? '1' : '0'; ?>"
	data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"
	data-can-delete="<?php echo $can_delete ? '1' : '0'; ?>"
	data-show-suppliers="<?php echo $show_suppliers ? '1' : '0'; ?>"
	data-can-record-supplier="<?php echo $can_record_sup ? '1' : '0'; ?>"
	data-payments-tab="<?php echo esc_attr( $active_tab ); ?>"
	data-preset-company-id="<?php echo esc_attr( (string) $preset_company ); ?>">
	<div class="ds-crm-page-header">
		<h1><?php echo $is_client ? esc_html__( 'My payments', 'ds-prod-import-crm' ) : esc_html__( 'Payments', 'ds-prod-import-crm' ); ?></h1>
		<?php if ( $can_create ) : ?>
			<button type="button" class="button button-primary ds-crm-btn-add-payment"<?php echo 'suppliers' === $active_tab ? ' hidden' : ''; ?>>
				<?php esc_html_e( 'Record client payment', 'ds-prod-import-crm' ); ?>
			</button>
		<?php endif; ?>
		<?php if ( $can_record_sup ) : ?>
			<button type="button" class="button button-primary ds-crm-btn-add-supplier-payment"<?php echo 'clients' === $active_tab ? ' hidden' : ''; ?>>
				<?php esc_html_e( 'Record payment to supplier', 'ds-prod-import-crm' ); ?>
			</button>
		<?php endif; ?>
	</div>

	<?php if ( $show_suppliers ) : ?>
	<div class="ds-crm-subnav ds-crm-payments-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Payment types', 'ds-prod-import-crm' ); ?>">
		<button type="button" class="ds-crm-subnav-tab<?php echo 'clients' === $active_tab ? ' is-active' : ''; ?>" role="tab" data-tab="clients" aria-selected="<?php echo 'clients' === $active_tab ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'From clients', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-subnav-tab<?php echo 'suppliers' === $active_tab ? ' is-active' : ''; ?>" role="tab" data-tab="suppliers" aria-selected="<?php echo 'suppliers' === $active_tab ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'To suppliers', 'ds-prod-import-crm' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<div class="ds-crm-payments-panel ds-crm-payments-clients" data-panel="clients"<?php echo 'suppliers' === $active_tab ? ' hidden' : ''; ?>>
		<?php if ( $is_client ) : ?>
		<div class="ds-crm-notice ds-crm-notice-info">
			<?php esc_html_e( 'Payments recorded against your account. Your balance is shared across all orders — oldest orders are covered first (product bill, then delivery).', 'ds-prod-import-crm' ); ?>
		</div>
		<div class="ds-crm-client-payment-balance" hidden>
			<div class="ds-crm-order-stats ds-crm-order-stats--compact ds-crm-client-payment-balance-stats"></div>
		</div>
		<?php else : ?>
		<div class="ds-crm-notice ds-crm-notice-info">
			<?php esc_html_e( 'Money received from clients. Payments are pooled per client — you do not need to link each payment to a specific order. Optional order reference is for your notes only. Balance is allocated oldest-order-first (product bill, then delivery bill).', 'ds-prod-import-crm' ); ?>
		</div>
		<?php endif; ?>

		<div class="ds-crm-toolbar">
			<input type="search" class="ds-crm-search" placeholder="<?php echo $is_client
				? esc_attr__( 'Search payment #, order, reference…', 'ds-prod-import-crm' )
				: esc_attr__( 'Search payment #, client, reference…', 'ds-prod-import-crm' ); ?>" />
			<?php if ( ! $is_client ) : ?>
			<select class="ds-crm-filter-client">
				<option value=""><?php esc_html_e( 'All clients', 'ds-prod-import-crm' ); ?></option>
			</select>
			<?php endif; ?>
			<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" />
			<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-per-page">
				<option value="10">10</option>
				<option value="25">25</option>
				<option value="50">50</option>
			</select>
		</div>

		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-payments-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Payment #', 'ds-prod-import-crm' ); ?></th>
						<?php if ( ! $is_client ) : ?>
						<th><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Ref. order', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
						<th class="ds-crm-amount-col"><?php esc_html_e( 'Amount', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Method', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Reference', 'ds-prod-import-crm' ); ?></th>
						<?php if ( ! $is_client ) : ?>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="<?php echo esc_attr( (string) $col_count ); ?>"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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

	<?php if ( $show_suppliers ) : ?>
	<div class="ds-crm-payments-panel ds-crm-payments-suppliers" data-panel="suppliers"<?php echo 'clients' === $active_tab ? ' hidden' : ''; ?>>
		<div class="ds-crm-notice ds-crm-notice-info">
			<?php esc_html_e( 'Money paid to cargo companies and suppliers. Totals update the company ledger (receive shipping + manual bills − payments). Manual bills stay on the company ledger page.', 'ds-prod-import-crm' ); ?>
			<a class="ds-crm-notice-link" href="<?php echo esc_url( crm_module_url( 'companies', 'frontend' ) ); ?>"><?php esc_html_e( 'Open companies →', 'ds-prod-import-crm' ); ?></a>
		</div>

		<div class="ds-crm-toolbar">
			<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search payment #, company, reference…', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-filter-company">
				<option value=""><?php esc_html_e( 'All companies', 'ds-prod-import-crm' ); ?></option>
			</select>
			<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" />
			<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" />
			<select class="ds-crm-per-page">
				<option value="10">10</option>
				<option value="25">25</option>
				<option value="50">50</option>
			</select>
		</div>

		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table ds-crm-supplier-payments-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Payment #', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Company', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
						<th class="ds-crm-amount-col"><?php esc_html_e( 'Amount', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Method', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Reference', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="ds-crm-loading-row">
						<td colspan="<?php echo esc_attr( (string) $supplier_cols ); ?>"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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
	<?php endif; ?>
</div>

<?php if ( ! $is_client ) : ?>
<div class="ds-crm-modal" id="ds-crm-payment-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-md" role="dialog" aria-modal="true" aria-labelledby="ds-crm-payment-modal-title">
		<div class="ds-crm-modal-header">
			<h2 id="ds-crm-payment-modal-title"><?php esc_html_e( 'Client payment', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<form class="ds-crm-form ds-crm-payment-form">
			<input type="hidden" name="id" value="" />
			<div class="ds-crm-form-error" hidden></div>
			<div class="ds-crm-modal-body">
				<div class="ds-crm-form-grid ds-crm-payment-form-grid">
					<p>
						<label for="payment-client"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<select id="payment-client" name="client_id" required>
							<option value=""><?php esc_html_e( 'Select client…', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<p>
						<label for="payment-order"><?php esc_html_e( 'Reference order (optional)', 'ds-prod-import-crm' ); ?></label>
						<select id="payment-order" name="order_id">
							<option value=""><?php esc_html_e( 'General client payment', 'ds-prod-import-crm' ); ?></option>
						</select>
						<span class="description"><?php esc_html_e( 'For your records only — balance is shared across all client orders.', 'ds-prod-import-crm' ); ?></span>
					</p>
				</div>
				<div class="ds-crm-payment-client-preview" hidden>
					<div class="ds-crm-payment-client-preview-inner"></div>
				</div>
				<div class="ds-crm-payment-order-preview" hidden>
					<div class="ds-crm-payment-order-preview-inner"></div>
				</div>
				<div class="ds-crm-form-grid ds-crm-payment-form-grid">
					<p>
						<label for="payment-date"><?php esc_html_e( 'Payment date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<input type="date" id="payment-date" name="payment_date" required />
					</p>
					<p>
						<label for="payment-amount"><?php esc_html_e( 'Amount', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<input type="number" id="payment-amount" class="ds-crm-money-input" name="amount" min="0.01" step="0.01" placeholder="0.00" required />
					</p>
				</div>
				<div class="ds-crm-form-grid ds-crm-payment-form-grid">
					<p>
						<label for="payment-method"><?php esc_html_e( 'Method', 'ds-prod-import-crm' ); ?></label>
						<select id="payment-method" name="payment_method">
							<option value=""><?php esc_html_e( 'Select method…', 'ds-prod-import-crm' ); ?></option>
							<option value="cash"><?php esc_html_e( 'Cash', 'ds-prod-import-crm' ); ?></option>
							<option value="bank_transfer"><?php esc_html_e( 'Bank transfer', 'ds-prod-import-crm' ); ?></option>
							<option value="mobile_banking"><?php esc_html_e( 'Mobile banking', 'ds-prod-import-crm' ); ?></option>
							<option value="cheque"><?php esc_html_e( 'Cheque', 'ds-prod-import-crm' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<p>
						<label for="payment-reference"><?php esc_html_e( 'Reference', 'ds-prod-import-crm' ); ?></label>
						<input type="text" id="payment-reference" name="reference" placeholder="<?php esc_attr_e( 'Txn ID, cheque #, etc.', 'ds-prod-import-crm' ); ?>" />
					</p>
				</div>
				<p class="ds-crm-field-full">
					<label for="payment-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
					<textarea id="payment-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional payment notes…', 'ds-prod-import-crm' ); ?>"></textarea>
				</p>
			</div>
			<div class="ds-crm-modal-footer">
				<button type="button" class="button ds-crm-modal-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save payment', 'ds-prod-import-crm' ); ?></button>
			</div>
		</form>
	</div>
</div>
<?php endif; ?>

<?php if ( $can_record_sup ) : ?>
<div class="ds-crm-modal" id="ds-crm-supplier-payment-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-md" role="dialog" aria-modal="true" aria-labelledby="ds-crm-supplier-payment-modal-title">
		<div class="ds-crm-modal-header">
			<h2 id="ds-crm-supplier-payment-modal-title"><?php esc_html_e( 'Payment to supplier', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<form class="ds-crm-form ds-crm-supplier-payment-form">
			<input type="hidden" name="id" value="" />
			<div class="ds-crm-form-error" hidden></div>
			<div class="ds-crm-modal-body">
				<p>
					<label for="supplier-payment-company"><?php esc_html_e( 'Company / supplier', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<select id="supplier-payment-company" name="company_id" required>
						<option value=""><?php esc_html_e( 'Select company…', 'ds-prod-import-crm' ); ?></option>
					</select>
				</p>
				<div class="ds-crm-payment-supplier-preview" hidden>
					<div class="ds-crm-payment-supplier-preview-inner"></div>
				</div>
				<div class="ds-crm-form-grid ds-crm-payment-form-grid">
					<p>
						<label for="supplier-payment-date"><?php esc_html_e( 'Payment date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<input type="date" id="supplier-payment-date" name="payment_date" required />
					</p>
					<p>
						<label for="supplier-payment-amount"><?php esc_html_e( 'Amount', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<input type="number" id="supplier-payment-amount" class="ds-crm-money-input" name="amount" min="0.01" step="0.01" placeholder="0.00" required />
					</p>
				</div>
				<div class="ds-crm-form-grid ds-crm-payment-form-grid">
					<p>
						<label for="supplier-payment-method"><?php esc_html_e( 'Method', 'ds-prod-import-crm' ); ?></label>
						<select id="supplier-payment-method" name="payment_method">
							<option value=""><?php esc_html_e( 'Select method…', 'ds-prod-import-crm' ); ?></option>
							<option value="cash"><?php esc_html_e( 'Cash', 'ds-prod-import-crm' ); ?></option>
							<option value="bank_transfer"><?php esc_html_e( 'Bank transfer', 'ds-prod-import-crm' ); ?></option>
							<option value="mobile_banking"><?php esc_html_e( 'Mobile banking', 'ds-prod-import-crm' ); ?></option>
							<option value="cheque"><?php esc_html_e( 'Cheque', 'ds-prod-import-crm' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<p>
						<label for="supplier-payment-reference"><?php esc_html_e( 'Reference', 'ds-prod-import-crm' ); ?></label>
						<input type="text" id="supplier-payment-reference" name="reference" placeholder="<?php esc_attr_e( 'Txn ID, cheque #, etc.', 'ds-prod-import-crm' ); ?>" />
					</p>
				</div>
				<p class="ds-crm-field-full">
					<label for="supplier-payment-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
					<textarea id="supplier-payment-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Optional payment notes…', 'ds-prod-import-crm' ); ?>"></textarea>
				</p>
			</div>
			<div class="ds-crm-modal-footer">
				<button type="button" class="button ds-crm-modal-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save payment', 'ds-prod-import-crm' ); ?></button>
			</div>
		</form>
	</div>
</div>
<?php endif; ?>
