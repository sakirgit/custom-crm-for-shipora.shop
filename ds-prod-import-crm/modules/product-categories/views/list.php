<?php
/**
 * Product categories list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

	if ( ! current_user_can( 'crm_products_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to manage product categories.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_create = CRM_Capability_Registry::user_can_module_action( 'crm_products_create', 'crm_manage_products' );
$can_edit   = CRM_Capability_Registry::user_can_module_action( 'crm_products_edit', 'crm_manage_products' );
$can_delete = CRM_Capability_Registry::user_can_module_action( 'crm_products_delete', 'crm_manage_products' );
?>
<div class="ds-crm-module-page" data-crm-module="product-categories"
	data-can-create="<?php echo $can_create ? '1' : '0'; ?>"
	data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"
	data-can-delete="<?php echo $can_delete ? '1' : '0'; ?>">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Product Categories', 'ds-prod-import-crm' ); ?></h1>
		<?php if ( $can_create ) : ?>
		<button type="button" class="button button-primary ds-crm-btn-add-category">
			<?php esc_html_e( 'Add Category', 'ds-prod-import-crm' ); ?>
		</button>
		<?php endif; ?>
	</div>

	<div class="ds-crm-toolbar">
		<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search categories…', 'ds-prod-import-crm' ); ?>" />
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
		<table class="ds-crm-table ds-crm-product-categories-table">
			<thead>
				<tr>
					<th data-sort="name"><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Description', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="status"><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="created_at"><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td colspan="5"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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

<div class="ds-crm-modal" id="ds-crm-product-category-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-md" role="dialog" aria-modal="true" aria-labelledby="ds-crm-product-category-modal-title">
		<div class="ds-crm-modal-header">
			<h2 id="ds-crm-product-category-modal-title"><?php esc_html_e( 'Category', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<form class="ds-crm-form ds-crm-product-category-form">
			<input type="hidden" name="id" value="" />
			<div class="ds-crm-form-error" hidden></div>
			<div class="ds-crm-modal-body">
			<p>
				<label for="category-name"><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
				<input type="text" id="category-name" name="name" required />
			</p>
			<p>
				<label for="category-description"><?php esc_html_e( 'Description', 'ds-prod-import-crm' ); ?></label>
				<textarea id="category-description" name="description" rows="2"></textarea>
			</p>
			<p>
				<label for="category-status"><?php esc_html_e( 'Status', 'ds-prod-import-crm' ); ?></label>
				<select id="category-status" name="status">
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
