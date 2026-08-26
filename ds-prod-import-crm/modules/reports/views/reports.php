<?php
/**
 * Reports hub view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_view_reports' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to view reports.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$branding = crm_get_branding();
?>
<div class="ds-crm-module-page" data-crm-module="reports">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Reports', 'ds-prod-import-crm' ); ?></h1>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'Generate ledgers and stock reports. Use Print for PDF (save as PDF in the browser) or Export CSV for spreadsheets.', 'ds-prod-import-crm' ); ?>
	</div>

	<div class="ds-crm-reports-tabs" role="tablist">
		<button type="button" class="ds-crm-reports-tab is-active" data-report="client" role="tab" aria-selected="true">
			<?php esc_html_e( 'Client ledger', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-reports-tab" data-report="supplier" role="tab">
			<?php esc_html_e( 'Supplier ledger', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-reports-tab" data-report="stock" role="tab">
			<?php esc_html_e( 'Stock report', 'ds-prod-import-crm' ); ?>
		</button>
	</div>

	<div class="ds-crm-reports-panels">
		<div class="ds-crm-reports-panel is-active" data-panel="client">
			<form class="ds-crm-reports-filters ds-crm-reports-filters-client">
				<div class="ds-crm-form-grid">
					<p>
						<label for="report-client-id"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<select id="report-client-id" name="client_id" required>
							<option value=""><?php esc_html_e( '— Select client —', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<p>
						<label for="report-client-from"><?php esc_html_e( 'From date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-client-from" name="date_from" />
					</p>
					<p>
						<label for="report-client-to"><?php esc_html_e( 'To date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-client-to" name="date_to" />
					</p>
				</div>
				<p class="description"><?php esc_html_e( 'Leave dates empty for all-time. Opening balance is calculated when a start date is set.', 'ds-prod-import-crm' ); ?></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run report', 'ds-prod-import-crm' ); ?></button>
			</form>
		</div>

		<div class="ds-crm-reports-panel" data-panel="supplier" hidden>
			<form class="ds-crm-reports-filters ds-crm-reports-filters-supplier">
				<div class="ds-crm-form-grid">
					<p>
						<label for="report-company-id"><?php esc_html_e( 'Company / supplier', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<select id="report-company-id" name="company_id" required>
							<option value=""><?php esc_html_e( '— Select company —', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<p>
						<label for="report-supplier-from"><?php esc_html_e( 'From date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-supplier-from" name="date_from" />
					</p>
					<p>
						<label for="report-supplier-to"><?php esc_html_e( 'To date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-supplier-to" name="date_to" />
					</p>
				</div>
				<p class="description"><?php esc_html_e( 'Includes warehouse receives, manual bills, and payments to the supplier.', 'ds-prod-import-crm' ); ?></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run report', 'ds-prod-import-crm' ); ?></button>
			</form>
		</div>

		<div class="ds-crm-reports-panel" data-panel="stock" hidden>
			<form class="ds-crm-reports-filters ds-crm-reports-filters-stock">
				<div class="ds-crm-form-grid">
					<p>
						<label for="report-stock-search"><?php esc_html_e( 'Search product', 'ds-prod-import-crm' ); ?></label>
						<input type="search" id="report-stock-search" name="search" placeholder="<?php esc_attr_e( 'Product name…', 'ds-prod-import-crm' ); ?>" />
					</p>
					<p class="ds-crm-reports-checkboxes">
						<label><input type="checkbox" name="low_stock_only" value="1" /> <?php esc_html_e( 'Low stock only', 'ds-prod-import-crm' ); ?></label>
						<label><input type="checkbox" name="hide_zero" value="1" checked /> <?php esc_html_e( 'Hide zero quantity', 'ds-prod-import-crm' ); ?></label>
					</p>
				</div>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run report', 'ds-prod-import-crm' ); ?></button>
			</form>
		</div>
	</div>

	<div class="ds-crm-reports-output" hidden>
		<div class="ds-crm-reports-output-toolbar no-print">
			<button type="button" class="button ds-crm-reports-print"><?php esc_html_e( 'Print / PDF', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="button ds-crm-reports-export-csv"><?php esc_html_e( 'Export CSV', 'ds-prod-import-crm' ); ?></button>
		</div>
		<div class="ds-crm-reports-print-area" id="ds-crm-reports-print-area">
			<header class="ds-crm-report-print-header">
				<?php if ( ! empty( $branding['logo_url'] ) ) : ?>
					<img src="<?php echo esc_url( $branding['logo_url'] ); ?>" alt="" class="ds-crm-report-print-logo" />
				<?php endif; ?>
				<div>
					<strong class="ds-crm-report-print-company"><?php echo esc_html( $branding['company_name'] ); ?></strong>
					<span class="ds-crm-report-print-meta"></span>
				</div>
			</header>
			<div class="ds-crm-reports-result"></div>
		</div>
	</div>
</div>
