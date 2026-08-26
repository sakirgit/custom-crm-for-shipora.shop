<?php
/**
 * Products list view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

	if ( ! current_user_can( 'crm_products_view' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to manage products.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$can_create = CRM_Capability_Registry::user_can_module_action( 'crm_products_create', 'crm_manage_products' );
$can_edit   = CRM_Capability_Registry::user_can_module_action( 'crm_products_edit', 'crm_manage_products' );
$can_delete = CRM_Capability_Registry::user_can_module_action( 'crm_products_delete', 'crm_manage_products' );
?>
<div class="ds-crm-module-page" data-crm-module="products" data-pricing-mode="<?php echo esc_attr( crm_pricing_mode() ); ?>"
	data-can-create="<?php echo $can_create ? '1' : '0'; ?>"
	data-can-edit="<?php echo $can_edit ? '1' : '0'; ?>"
	data-can-delete="<?php echo $can_delete ? '1' : '0'; ?>">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Products', 'ds-prod-import-crm' ); ?></h1>
		<div class="ds-crm-header-actions">
			<a class="button" href="<?php echo esc_url( crm_module_url( 'product-categories', 'frontend' ) ); ?>">
				<?php esc_html_e( 'Manage Categories', 'ds-prod-import-crm' ); ?>
			</a>
			<?php if ( $can_create ) : ?>
			<button type="button" class="button button-primary ds-crm-btn-add-product">
				<?php esc_html_e( 'Add Product', 'ds-prod-import-crm' ); ?>
			</button>
			<?php endif; ?>
		</div>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php if ( crm_is_single_price_mode() ) : ?>
			<?php esc_html_e( 'Single price mode: one catalog price is used for customer orders and warehouse receives. Stock is added when shipments arrive — orders do not require stock upfront.', 'ds-prod-import-crm' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Dual price mode: sell price for customer orders; purchase rate for import/receive costs. Stock is added when shipments arrive — orders do not require stock upfront.', 'ds-prod-import-crm' ); ?>
		<?php endif; ?>
	</div>

	<div class="ds-crm-toolbar">
		<input type="search" class="ds-crm-search" placeholder="<?php esc_attr_e( 'Search name, SKU, category…', 'ds-prod-import-crm' ); ?>" />
		<input type="date" class="ds-crm-date-from" aria-label="<?php esc_attr_e( 'From date', 'ds-prod-import-crm' ); ?>" />
		<input type="date" class="ds-crm-date-to" aria-label="<?php esc_attr_e( 'To date', 'ds-prod-import-crm' ); ?>" />
		<select class="ds-crm-per-page">
			<option value="10">10</option>
			<option value="25">25</option>
			<option value="50">50</option>
		</select>
	</div>

	<div class="ds-crm-table-wrap">
		<table class="ds-crm-table ds-crm-products-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Image', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="sku"><?php esc_html_e( 'SKU', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="name"><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Color', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Size', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="category_name"><?php esc_html_e( 'Category', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="unit_price" class="ds-crm-col-unit-price"><?php echo crm_is_single_price_mode() ? esc_html__( 'Price', 'ds-prod-import-crm' ) : esc_html__( 'Sell price', 'ds-prod-import-crm' ); ?></th>
					<th class="ds-crm-col-purchase-rate"<?php echo crm_is_single_price_mode() ? ' hidden' : ''; ?>><?php esc_html_e( 'Purchase rate', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Stock (pcs)', 'ds-prod-import-crm' ); ?></th>
					<th data-sort="created_at"><?php esc_html_e( 'Date & time', 'ds-prod-import-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="ds-crm-loading-row">
					<td colspan="11"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td>
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

<div class="ds-crm-modal" id="ds-crm-product-modal" hidden>
	<div class="ds-crm-modal-overlay"></div>
	<div class="ds-crm-modal-dialog ds-crm-modal-md" role="dialog" aria-modal="true" aria-labelledby="ds-crm-product-modal-title">
		<div class="ds-crm-modal-header">
			<h2 id="ds-crm-product-modal-title"><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></h2>
			<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
		</div>
		<form class="ds-crm-form ds-crm-product-form" enctype="multipart/form-data">
			<input type="hidden" name="id" value="" />
			<input type="hidden" name="image_url_current" value="" />
			<input type="hidden" name="thumbnail_url_current" value="" />
			<div class="ds-crm-form-error" hidden></div>
			<div class="ds-crm-modal-body">
			<p>
				<label for="product-name"><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?> <span class="required">*</span></label>
				<input type="text" id="product-name" name="name" required />
			</p>
			<p>
				<label for="product-sku"><?php esc_html_e( 'SKU', 'ds-prod-import-crm' ); ?></label>
				<input type="text" id="product-sku" name="sku" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. SHIRT-001', 'ds-prod-import-crm' ); ?>" />
			</p>
			<p class="ds-crm-form-grid ds-crm-form-grid--inline">
				<span>
					<label for="product-color"><?php esc_html_e( 'Color', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="product-color" name="color" placeholder="<?php esc_attr_e( 'e.g. Red', 'ds-prod-import-crm' ); ?>" />
				</span>
				<span>
					<label for="product-size"><?php esc_html_e( 'Size', 'ds-prod-import-crm' ); ?></label>
					<input type="text" id="product-size" name="size" placeholder="<?php esc_attr_e( 'e.g. M', 'ds-prod-import-crm' ); ?>" />
				</span>
			</p>
			<p>
				<label for="product-category"><?php esc_html_e( 'Category', 'ds-prod-import-crm' ); ?></label>
				<select id="product-category" name="category_id">
					<option value=""><?php esc_html_e( '— Select category —', 'ds-prod-import-crm' ); ?></option>
				</select>
			</p>
			<p>
				<label for="product-unit-price" class="ds-crm-label-unit-price"><?php echo crm_is_single_price_mode() ? esc_html__( 'Price (BDT)', 'ds-prod-import-crm' ) : esc_html__( 'Selling price (BDT)', 'ds-prod-import-crm' ); ?></label>
				<input type="number" id="product-unit-price" name="unit_price" class="ds-crm-money-input" min="0" step="0.01" value="0" />
			</p>
			<p class="ds-crm-purchase-rate-field"<?php echo crm_is_single_price_mode() ? ' hidden' : ''; ?>>
				<label for="product-purchase-rate"><?php esc_html_e( 'Purchase rate (BDT / unit)', 'ds-prod-import-crm' ); ?></label>
				<input type="number" id="product-purchase-rate" name="purchase_rate" class="ds-crm-money-input" min="0" step="0.01" value="0" />
			</p>
			<p>
				<label for="product-description"><?php esc_html_e( 'Description', 'ds-prod-import-crm' ); ?></label>
				<textarea id="product-description" name="description" rows="3"></textarea>
			</p>
			<p class="ds-crm-image-field">
				<label><?php esc_html_e( 'Image (JPG, PNG, WebP — max 3MB)', 'ds-prod-import-crm' ); ?></label>
				<div class="ds-crm-image-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Upload product image', 'ds-prod-import-crm' ); ?>">
					<input type="file" id="product-image" class="ds-crm-image-dropzone-input" name="image" accept="image/jpeg,image/png,image/webp" />
					<div class="ds-crm-image-dropzone-prompt">
						<strong><?php esc_html_e( 'Drop image here', 'ds-prod-import-crm' ); ?></strong>
						<span><?php esc_html_e( 'or paste (Ctrl+V), or click to browse', 'ds-prod-import-crm' ); ?></span>
					</div>
				</div>
				<div class="ds-crm-image-preview" hidden>
					<img src="" alt="" />
					<label><input type="checkbox" name="remove_image" value="1" /> <?php esc_html_e( 'Remove current image', 'ds-prod-import-crm' ); ?></label>
				</div>
			</p>
			</div>
			<div class="ds-crm-modal-footer">
				<button type="button" class="button ds-crm-modal-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'ds-prod-import-crm' ); ?></button>
			</div>
		</form>
	</div>
</div>
