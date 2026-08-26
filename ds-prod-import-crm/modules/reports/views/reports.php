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

$branding      = crm_get_branding();
$is_portal     = CRM_Client_Portal::is_client_user();
$linked_id     = $is_portal ? CRM_Client_Portal::get_linked_client_id() : 0;
$preset_client = ( ! $is_portal && isset( $_GET['client_id'] ) ) ? absint( wp_unslash( $_GET['client_id'] ) ) : 0;
$preset_report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
if ( $is_portal ) {
	$preset_report = 'full';
} elseif ( ! in_array( $preset_report, array( 'full', 'client', 'statement', 'supplier', 'stock' ), true ) ) {
	$preset_report = 'full';
}
?>
<div class="ds-crm-module-page" data-crm-module="reports"
	data-is-portal="<?php echo $is_portal ? '1' : '0'; ?>"
	data-linked-client-id="<?php echo esc_attr( (string) $linked_id ); ?>"
	data-preset-client-id="<?php echo esc_attr( (string) $preset_client ); ?>"
	data-preset-report="<?php echo esc_attr( $preset_report ); ?>">
	<div class="ds-crm-page-header">
		<h1><?php echo $is_portal ? esc_html__( 'My account report', 'ds-prod-import-crm' ) : esc_html__( 'Reports', 'ds-prod-import-crm' ); ?></h1>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php
		echo $is_portal
			? esc_html__( 'Your full billing report: order & delivery bills, payments, and dues. Use Download PDF to save or print a copy.', 'ds-prod-import-crm' )
			: esc_html__( 'Generate full client reports, ledgers, and stock reports. Use Download PDF (browser print → Save as PDF) or Export CSV.', 'ds-prod-import-crm' );
		?>
	</div>

	<?php if ( ! $is_portal ) : ?>
	<div class="ds-crm-reports-tabs" role="tablist">
		<button type="button" class="ds-crm-reports-tab<?php echo 'full' === $preset_report ? ' is-active' : ''; ?>" data-report="full" role="tab" aria-selected="<?php echo 'full' === $preset_report ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Full client report', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-reports-tab<?php echo 'client' === $preset_report ? ' is-active' : ''; ?>" data-report="client" role="tab" aria-selected="<?php echo 'client' === $preset_report ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Client ledger', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-reports-tab<?php echo 'statement' === $preset_report ? ' is-active' : ''; ?>" data-report="statement" role="tab" aria-selected="<?php echo 'statement' === $preset_report ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Client billing statement', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-reports-tab<?php echo 'supplier' === $preset_report ? ' is-active' : ''; ?>" data-report="supplier" role="tab" aria-selected="<?php echo 'supplier' === $preset_report ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Supplier ledger', 'ds-prod-import-crm' ); ?>
		</button>
		<button type="button" class="ds-crm-reports-tab<?php echo 'stock' === $preset_report ? ' is-active' : ''; ?>" data-report="stock" role="tab" aria-selected="<?php echo 'stock' === $preset_report ? 'true' : 'false'; ?>">
			<?php esc_html_e( 'Stock report', 'ds-prod-import-crm' ); ?>
		</button>
	</div>
	<?php endif; ?>

	<div class="ds-crm-reports-panels">
		<div class="ds-crm-reports-panel<?php echo 'full' === $preset_report || $is_portal ? ' is-active' : ''; ?>" data-panel="full"<?php echo 'full' === $preset_report || $is_portal ? '' : ' hidden'; ?>>
			<form class="ds-crm-reports-filters ds-crm-reports-filters-full">
				<div class="ds-crm-form-grid">
					<?php if ( ! $is_portal ) : ?>
					<p>
						<label for="report-full-client-id"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<select id="report-full-client-id" name="client_id" required>
							<option value=""><?php esc_html_e( '— Select client —', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<?php else : ?>
					<input type="hidden" id="report-full-client-id" name="client_id" value="<?php echo esc_attr( (string) $linked_id ); ?>" />
					<?php endif; ?>
					<p>
						<label for="report-full-from"><?php esc_html_e( 'From date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-full-from" name="date_from" />
					</p>
					<p>
						<label for="report-full-to"><?php esc_html_e( 'To date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-full-to" name="date_to" />
					</p>
				</div>
				<p class="description"><?php esc_html_e( 'Leave dates empty for the complete account history. Includes order/delivery billing, payment purpose, and a running ledger.', 'ds-prod-import-crm' ); ?></p>
				<button type="submit" class="button button-primary"><?php echo $is_portal ? esc_html__( 'Show my report', 'ds-prod-import-crm' ) : esc_html__( 'Run full report', 'ds-prod-import-crm' ); ?></button>
			</form>
		</div>

		<?php if ( ! $is_portal ) : ?>
		<div class="ds-crm-reports-panel<?php echo 'client' === $preset_report ? ' is-active' : ''; ?>" data-panel="client"<?php echo 'client' === $preset_report ? '' : ' hidden'; ?>>
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

		<div class="ds-crm-reports-panel<?php echo 'statement' === $preset_report ? ' is-active' : ''; ?>" data-panel="statement"<?php echo 'statement' === $preset_report ? '' : ' hidden'; ?>>
			<form class="ds-crm-reports-filters ds-crm-reports-filters-statement">
				<div class="ds-crm-form-grid">
					<p>
						<label for="report-statement-client-id"><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
						<select id="report-statement-client-id" name="client_id" required>
							<option value=""><?php esc_html_e( '— Select client —', 'ds-prod-import-crm' ); ?></option>
						</select>
					</p>
					<p>
						<label for="report-statement-from"><?php esc_html_e( 'From date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-statement-from" name="date_from" />
					</p>
					<p>
						<label for="report-statement-to"><?php esc_html_e( 'To date', 'ds-prod-import-crm' ); ?></label>
						<input type="date" id="report-statement-to" name="date_to" />
					</p>
				</div>
				<p class="description"><?php esc_html_e( 'Client-ready billing breakdown: order/delivery bills, paid/due (full or partial), and each payment’s purpose.', 'ds-prod-import-crm' ); ?></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run statement', 'ds-prod-import-crm' ); ?></button>
			</form>
		</div>

		<div class="ds-crm-reports-panel<?php echo 'supplier' === $preset_report ? ' is-active' : ''; ?>" data-panel="supplier"<?php echo 'supplier' === $preset_report ? '' : ' hidden'; ?>>
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

		<div class="ds-crm-reports-panel<?php echo 'stock' === $preset_report ? ' is-active' : ''; ?>" data-panel="stock"<?php echo 'stock' === $preset_report ? '' : ' hidden'; ?>>
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
		<?php endif; ?>
	</div>

	<div class="ds-crm-reports-output" hidden>
		<div class="ds-crm-reports-output-toolbar no-print">
			<button type="button" class="button button-primary ds-crm-reports-print"><?php esc_html_e( 'Download PDF', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="button ds-crm-reports-export-csv"><?php esc_html_e( 'Export CSV', 'ds-prod-import-crm' ); ?></button>
			<span class="description ds-crm-reports-print-hint"><?php esc_html_e( 'In the print dialog, choose “Save as PDF”. Wide tables are reformatted for the page — no scrollbars.', 'ds-prod-import-crm' ); ?></span>
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
