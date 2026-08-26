<?php
/**
 * Company / supplier ledger page.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$company_id = isset( $_GET['company_id'] ) ? absint( $_GET['company_id'] ) : 0;
if ( $company_id < 1 ) {
	echo '<p>' . esc_html__( 'Invalid company.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_manage_billing = CRM_Capability_Registry::user_can_manage_billing();
$payments_url       = crm_payments_url( 'suppliers', $company_id );
$companies_url      = crm_module_url( 'companies', 'frontend' );
$section            = isset( $_GET['ledger_section'] ) ? sanitize_key( wp_unslash( $_GET['ledger_section'] ) ) : 'payments';
if ( ! in_array( $section, array( 'payments', 'receives', 'bills' ), true ) ) {
	$section = 'payments';
}
?>
<div class="ds-crm-module-page ds-crm-company-ledger-page" data-crm-module="companies-ledger"
	data-company-id="<?php echo esc_attr( (string) $company_id ); ?>"
	data-can-manage-billing="<?php echo $can_manage_billing ? '1' : '0'; ?>"
	data-ledger-section="<?php echo esc_attr( $section ); ?>"
	data-payments-url="<?php echo esc_url( $payments_url ); ?>">
	<div class="ds-crm-page-header ds-crm-order-form-heading">
		<a class="ds-crm-back-link" href="<?php echo esc_url( $companies_url ); ?>">&larr; <?php esc_html_e( 'Back to companies', 'ds-prod-import-crm' ); ?></a>
		<h1 class="ds-crm-ledger-page-title"><?php esc_html_e( 'Company ledger', 'ds-prod-import-crm' ); ?></h1>
		<?php if ( $can_manage_billing ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $payments_url ); ?>"><?php esc_html_e( 'Record payment to supplier', 'ds-prod-import-crm' ); ?></a>
		<?php endif; ?>
	</div>

	<div class="ds-crm-ledger-meta ds-crm-ledger-page-meta">Loading company…</div>

	<div class="ds-crm-ledger-summary ds-crm-ledger-page-totals" hidden>
		<h3 class="ds-crm-ledger-summary-title"><?php esc_html_e( 'Company totals', 'ds-prod-import-crm' ); ?></h3>
		<div class="ds-crm-order-stats ds-crm-order-stats--compact ds-crm-ledger-totals-stats"></div>
	</div>
	<div class="ds-crm-ledger-summary ds-crm-ledger-page-breakdown" hidden>
		<h3 class="ds-crm-ledger-summary-title"><?php esc_html_e( 'Bill breakdown', 'ds-prod-import-crm' ); ?></h3>
		<div class="ds-crm-order-stats ds-crm-order-stats--compact ds-crm-ledger-breakdown-stats"></div>
	</div>

	<?php if ( $can_manage_billing ) : ?>
	<div class="ds-crm-ledger-action-card ds-crm-ledger-bill-card">
		<h4><?php esc_html_e( 'Record manual bill', 'ds-prod-import-crm' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Use this for charges that are not created by warehouse receives. Payments to this company are recorded under Payments.', 'ds-prod-import-crm' ); ?></p>
		<form class="ds-crm-form ds-crm-ledger-bill-form">
			<div class="ds-crm-form-grid ds-crm-form-grid--inline">
				<p>
					<label for="ledger-bill-date"><?php esc_html_e( 'Bill date', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="date" id="ledger-bill-date" name="bill_date" required />
				</p>
				<p>
					<label for="ledger-bill-amount"><?php esc_html_e( 'Amount', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
					<input type="number" id="ledger-bill-amount" class="ds-crm-money-input" name="amount" min="0.01" step="0.01" placeholder="0.00" required />
				</p>
			</div>
			<div class="ds-crm-form-grid ds-crm-form-grid--inline">
				<p>
					<label for="ledger-bill-reference"><?php esc_html_e( 'Reference', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="ledger-bill-reference" name="reference" />
				</p>
				<p>
					<label for="ledger-bill-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="ledger-bill-notes" name="notes" />
				</p>
			</div>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save bill', 'ds-prod-import-crm' ); ?></button>
			</p>
		</form>
	</div>
	<?php endif; ?>

	<div class="ds-crm-subnav ds-crm-ledger-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Ledger tables', 'ds-prod-import-crm' ); ?>">
		<button type="button" class="ds-crm-subnav-tab<?php echo 'payments' === $section ? ' is-active' : ''; ?>" data-section="payments" role="tab">
			<?php esc_html_e( 'Payments to supplier', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-subnav-tab<?php echo 'receives' === $section ? ' is-active' : ''; ?>" data-section="receives" role="tab">
			<?php esc_html_e( 'Warehouse receives', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-subnav-tab<?php echo 'bills' === $section ? ' is-active' : ''; ?>" data-section="bills" role="tab">
			<?php esc_html_e( 'Manual bills', 'ds-prod-import-crm' ); ?>
		</button>
	</div>

	<div class="ds-crm-toolbar">
		<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search this table…', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-filter-client" hidden aria-label="<?php esc_attr_e( 'Filter by client', 'ds-prod-import-crm' ); ?>">
			<option value=""><?php esc_html_e( 'All clients', 'ds-prod-import-crm' ); ?></option>
		</select>
		<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" />
		<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-per-page">
			<option value="10">10</option>
			<option value="25" selected>25</option>
			<option value="50">50</option>
		</select>
	</div>

	<div class="ds-crm-table-wrap">
		<table class="ds-crm-table ds-crm-ledger-entries-table">
			<thead></thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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
