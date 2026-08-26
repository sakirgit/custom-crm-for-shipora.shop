<?php
/**
 * Companies list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_companies_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to manage companies.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_create = CRM_Capability_Registry::user_can_module_action( 'crm_companies_create', 'crm_manage_companies' );
$can_edit   = CRM_Capability_Registry::user_can_module_action( 'crm_companies_edit', 'crm_manage_companies' );
$can_delete = CRM_Capability_Registry::user_can_module_action( 'crm_companies_delete', 'crm_manage_companies' );
$can_manage_billing = CRM_Capability_Registry::user_can_manage_billing();
?>
<div class="ds-crm-module-page" data-crm-module="companies"
	data-can-create="<?php echo $can_create ? '1' : '0'; ?>"
	data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"
	data-can-delete="<?php echo $can_delete ? '1' : '0'; ?>">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Companies', 'ds-prod-import-crm' ); ?></h1>
		<?php if ( $can_create ) : ?>
		<button type="button" class="button button-primary ds-crm-btn-add-company">
			<?php esc_html_e( 'Add Company', 'ds-prod-import-crm' ); ?>
		</button>
		<?php endif; ?>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php if ( $can_manage_billing ) : ?>
			<?php esc_html_e( 'Cargo companies and suppliers you pay for imports. Open Ledger for bills, warehouse receives, and totals. Record payments out under Payments.', 'ds-prod-import-crm' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Cargo companies and suppliers used for imports. Open Ledger to view bills, payments, and warehouse receives. Customer and supplier payments are recorded under Payments.', 'ds-prod-import-crm' ); ?>
		<?php endif; ?>
		<a class="ds-crm-notice-link" href="<?php echo esc_url( crm_payments_url( 'suppliers' ) ); ?>"><?php esc_html_e( 'Go to supplier payments →', 'ds-prod-import-crm' ); ?></a>
	</div>

	<div class="ds-crm-toolbar">
		<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search name, contact, phone…', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-filter-status">
			<option value=""><?php esc_html_e( 'All statuses', 'ds-prod-import-crm' ); ?></option>
			<option value="active"><?php esc_html_e( 'Active', 'ds-prod-import-crm' ); ?></option>
			<option value="inactive"><?php esc_html_e( 'Inactive', 'ds-prod-import-crm' ); ?></option>
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
		<table class="ds-crm-table ds-crm-companies-table">
			<thead>
				<tr>
					<th data-sort="name"><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="contact_person"><?php esc_html_e( 'Contact', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="phone"><?php esc_html_e( 'Phone', 'ds-prod-import-crm' ); ?></th>
					<th class="ds-crm-amount-col"><?php esc_html_e( 'Bill', 'ds-prod-import-crm' ); ?></th>
					<th class="ds-crm-amount-col"><?php esc_html_e( 'Paid', 'ds-prod-import-crm' ); ?></th>
					<th class="ds-crm-amount-col"><?php esc_html_e( 'Due', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="status"><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="created_at"><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td colspan="9"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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

<div class="ds-crm-modal" id="ds-crm-company-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-md" role="dialog" aria-modal="true" aria-labelledby="ds-crm-company-modal-title">
		<div class="ds-crm-modal-header">
			<h2 id="ds-crm-company-modal-title"><?php esc_html_e( 'Company', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<form class="ds-crm-form ds-crm-company-form">
			<input type="hidden" name="id" value="" />
			<div class="ds-crm-form-error" hidden></div>
			<div class="ds-crm-modal-body">
			<p>
				<label for="company-name"><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
				<input type="text" id="company-name" name="name" required />
			</p>
			<p>
				<label for="company-contact"><?php esc_html_e( 'Contact person', 'ds-prod-import-crm' ); ?></label>
				<input type="text" id="company-contact" name="contact_person" />
			</p>
			<p>
				<label for="company-phone"><?php esc_html_e( 'Phone', 'ds-prod-import-crm' ); ?></label>
				<input type="text" id="company-phone" name="phone" />
			</p>
			<p>
				<label for="company-address"><?php esc_html_e( 'Address', 'ds-prod-import-crm' ); ?></label>
				<textarea id="company-address" name="address" rows="2"></textarea>
			</p>
			<p>
				<label for="company-notes"><?php esc_html_e( 'Notes', 'ds-prod-import-crm' ); ?></label>
				<textarea id="company-notes" name="notes" rows="2"></textarea>
			</p>
			<p>
				<label for="company-status"><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></label>
				<select id="company-status" name="status">
					<option value="active"><?php esc_html_e( 'Active', 'ds-prod-import-crm' ); ?></option>
					<option value="inactive"><?php esc_html_e( 'Inactive', 'ds-prod-import-crm' ); ?></option>
				</select>
			</p>
			</div>
			<div class="ds-crm-modal-footer">
				<button type="button" class="button ds-crm-modal-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ds-prod-import-crm' ); ?></button>
			</div>
		</form>
	</div>
</div>
