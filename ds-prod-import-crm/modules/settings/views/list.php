<?php
/**
 * CRM settings view (wp-admin).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_manage_settings' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to manage settings.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$crm_admin_section = isset( $crm_admin_section ) ? sanitize_key( $crm_admin_section ) : 'general';
?>
<div class="ds-crm-module-page" data-crm-module="settings" data-settings-section="<?php echo esc_attr( $crm_admin_section ); ?>">
	<form class="ds-crm-form ds-crm-settings-form">
		<input type="hidden" name="settings_section" value="<?php echo esc_attr( $crm_admin_section ); ?>" />
		<div class="ds-crm-form-error" hidden></div>
		<div class="ds-crm-settings-notices"></div>

		<?php if ( 'appearance' === $crm_admin_section ) : ?>
		<div class="ds-crm-settings-card">
			<div class="ds-crm-settings-card-header">
				<h2><?php esc_html_e( 'Company branding', 'ds-prod-import-crm' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Shown on the public CRM: sidebar, dashboard, login screen, and browser tab.', 'ds-prod-import-crm' ); ?>
				</p>
			</div>
			<div class="ds-crm-settings-card-body">
				<div class="ds-crm-field-table">
					<div class="ds-crm-field-row">
						<label for="crm-company-name"><?php esc_html_e( 'Company name', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<input type="text" class="regular-text" id="crm-company-name" name="company_name" maxlength="120" placeholder="<?php esc_attr_e( 'Your company name', 'ds-prod-import-crm' ); ?>" />
						</div>
					</div>
					<div class="ds-crm-field-row">
						<label for="crm-company-tagline"><?php esc_html_e( 'Tagline', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<input type="text" class="regular-text" id="crm-company-tagline" name="company_tagline" maxlength="160" placeholder="<?php esc_attr_e( 'Optional subtitle', 'ds-prod-import-crm' ); ?>" />
							<p class="description"><?php esc_html_e( 'Displayed under the company name on the dashboard header.', 'ds-prod-import-crm' ); ?></p>
						</div>
					</div>
				</div>

				<div class="ds-crm-media-grid">
					<div class="ds-crm-media-field" data-media-type="logo" data-media-title="<?php esc_attr_e( 'Select company logo', 'ds-prod-import-crm' ); ?>" data-media-button="<?php esc_attr_e( 'Use this image', 'ds-prod-import-crm' ); ?>">
						<span class="ds-crm-media-field-label"><?php esc_html_e( 'Company logo', 'ds-prod-import-crm' ); ?></span>
						<div class="ds-crm-media-preview-box is-empty">
							<p class="ds-crm-media-placeholder"><?php esc_html_e( 'No logo selected', 'ds-prod-import-crm' ); ?></p>
							<img class="ds-crm-media-preview-img" src="" alt="" />
						</div>
						<input type="hidden" name="company_logo_url" value="" />
						<div class="ds-crm-media-actions">
							<button type="button" class="button ds-crm-media-select"><?php esc_html_e( 'Select logo', 'ds-prod-import-crm' ); ?></button>
							<button type="button" class="button-link-delete ds-crm-media-remove" hidden><?php esc_html_e( 'Remove', 'ds-prod-import-crm' ); ?></button>
						</div>
					</div>
					<div class="ds-crm-media-field" data-media-type="favicon" data-media-title="<?php esc_attr_e( 'Select favicon', 'ds-prod-import-crm' ); ?>" data-media-button="<?php esc_attr_e( 'Use this image', 'ds-prod-import-crm' ); ?>">
						<span class="ds-crm-media-field-label"><?php esc_html_e( 'Favicon', 'ds-prod-import-crm' ); ?></span>
						<div class="ds-crm-media-preview-box ds-crm-media-preview-box--favicon is-empty">
							<p class="ds-crm-media-placeholder"><?php esc_html_e( 'No favicon selected', 'ds-prod-import-crm' ); ?></p>
							<img class="ds-crm-media-preview-img" src="" alt="" />
						</div>
						<input type="hidden" name="favicon_url" value="" />
						<div class="ds-crm-media-actions">
							<button type="button" class="button ds-crm-media-select"><?php esc_html_e( 'Select favicon', 'ds-prod-import-crm' ); ?></button>
							<button type="button" class="button-link-delete ds-crm-media-remove" hidden><?php esc_html_e( 'Remove', 'ds-prod-import-crm' ); ?></button>
						</div>
					</div>
				</div>

				<div class="ds-crm-color-settings">
					<h3 class="ds-crm-color-settings-title"><?php esc_html_e( 'Brand colors', 'ds-prod-import-crm' ); ?></h3>
					<div class="ds-crm-color-grid">
						<div class="ds-crm-color-field">
							<label for="crm-color-sidebar"><?php esc_html_e( 'Sidebar background', 'ds-prod-import-crm' ); ?></label>
							<div class="ds-crm-color-input-row">
								<input type="color" id="crm-color-sidebar" name="color_sidebar" value="#1a1f2e" />
								<input type="text" class="ds-crm-color-hex" data-color-for="crm-color-sidebar" maxlength="7" placeholder="#1a1f2e" />
							</div>
						</div>
						<div class="ds-crm-color-field">
							<label for="crm-color-accent"><?php esc_html_e( 'Primary color', 'ds-prod-import-crm' ); ?></label>
							<div class="ds-crm-color-input-row">
								<input type="color" id="crm-color-accent" name="color_accent" value="#2563eb" />
								<input type="text" class="ds-crm-color-hex" data-color-for="crm-color-accent" maxlength="7" placeholder="#2563eb" />
							</div>
						</div>
						<div class="ds-crm-color-field">
							<label for="crm-color-accent-secondary"><?php esc_html_e( 'Secondary accent', 'ds-prod-import-crm' ); ?></label>
							<div class="ds-crm-color-input-row">
								<input type="color" id="crm-color-accent-secondary" name="color_accent_secondary" value="#7c3aed" />
								<input type="text" class="ds-crm-color-hex" data-color-for="crm-color-accent-secondary" maxlength="7" placeholder="#7c3aed" />
							</div>
						</div>
					</div>
					<div class="ds-crm-color-preview" aria-hidden="true">
						<div class="ds-crm-color-preview-sidebar">
							<span class="ds-crm-color-preview-logo"></span>
							<span class="ds-crm-color-preview-nav is-active"></span>
							<span class="ds-crm-color-preview-nav"></span>
						</div>
						<div class="ds-crm-color-preview-main">
							<span class="ds-crm-color-preview-tab is-active"></span>
							<span class="ds-crm-color-preview-tab"></span>
							<span class="ds-crm-color-preview-kpi"></span>
							<span class="ds-crm-color-preview-kpi is-secondary"></span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="ds-crm-settings-card">
			<div class="ds-crm-settings-card-header">
				<h2><?php esc_html_e( 'Public CRM page', 'ds-prod-import-crm' ); ?></h2>
			</div>
			<div class="ds-crm-settings-card-body">
				<div class="ds-crm-field-table">
					<div class="ds-crm-field-row">
						<span class="ds-crm-field-label"><?php esc_html_e( 'Shortcode', 'ds-prod-import-crm' ); ?></span>
						<div class="ds-crm-field-control">
							<div class="ds-crm-shortcode-row">
								<code class="ds-crm-shortcode-display"><?php echo esc_html( crm_shortcode_example() ); ?></code>
								<button type="button" class="button button-small ds-crm-copy-shortcode"><?php esc_html_e( 'Copy', 'ds-prod-import-crm' ); ?></button>
							</div>
						</div>
					</div>
					<div class="ds-crm-field-row">
						<label for="crm-frontend-page"><?php esc_html_e( 'CRM page', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<select id="crm-frontend-page" name="frontend_page_id" class="ds-crm-frontend-page-select regular-text">
								<option value=""><?php esc_html_e( '— Select page —', 'ds-prod-import-crm' ); ?></option>
							</select>
							<p class="ds-crm-frontend-link-wrap" hidden>
								<strong><?php esc_html_e( 'Open CRM:', 'ds-prod-import-crm' ); ?></strong>
								<a href="#" class="ds-crm-frontend-link" target="_blank" rel="noopener"></a>
							</p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( 'team' === $crm_admin_section ) : ?>
		<div class="ds-crm-settings-card">
			<div class="ds-crm-settings-card-body">
				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Add user (WordPress)', 'ds-prod-import-crm' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'All users', 'ds-prod-import-crm' ); ?></a>
					<?php if ( crm_get_public_app_url() ) : ?>
						<a class="button" href="<?php echo esc_url( crm_module_url( 'team', 'frontend' ) ); ?>"><?php esc_html_e( 'Team guide in CRM', 'ds-prod-import-crm' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( 'general' === $crm_admin_section ) : ?>
		<div class="ds-crm-settings-card">
			<div class="ds-crm-settings-card-body">
				<div class="ds-crm-field-table">
					<div class="ds-crm-field-row">
						<label for="crm-low-stock"><?php esc_html_e( 'Low stock threshold', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<input type="number" class="small-text" id="crm-low-stock" name="low_stock_threshold" min="1" step="1" value="5" />
							<span class="description"><?php esc_html_e( 'pieces', 'ds-prod-import-crm' ); ?></span>
						</div>
					</div>
					<div class="ds-crm-field-row">
						<label for="crm-currency"><?php esc_html_e( 'Currency symbol', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<input type="text" class="small-text" id="crm-currency" name="currency_symbol" maxlength="5" value="৳" />
						</div>
					</div>
					<div class="ds-crm-field-row">
						<span class="ds-crm-field-label"><?php esc_html_e( 'Product pricing', 'ds-prod-import-crm' ); ?></span>
						<div class="ds-crm-field-control">
							<fieldset class="ds-crm-pricing-mode-fieldset">
								<label class="ds-crm-radio-option">
									<input type="radio" name="pricing_mode" value="single" checked />
									<?php esc_html_e( 'Single price — one amount for orders, receives, and catalog', 'ds-prod-import-crm' ); ?>
								</label>
								<label class="ds-crm-radio-option">
									<input type="radio" name="pricing_mode" value="dual" />
									<?php esc_html_e( 'Dual price — sell price for customers, purchase rate for imports/receives', 'ds-prod-import-crm' ); ?>
								</label>
							</fieldset>
						</div>
					</div>
					<div class="ds-crm-field-row">
						<label for="crm-shipments-module-label"><?php esc_html_e( 'China export menu label', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<input type="text" class="regular-text" id="crm-shipments-module-label" name="shipments_module_label" maxlength="80" placeholder="<?php echo esc_attr( __( 'China Export', 'ds-prod-import-crm' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Sidebar and page title for the China office export shipments module. Assign users the CRM China Office role to limit them to this section only.', 'ds-prod-import-crm' ); ?></p>
						</div>
					</div>
					<div class="ds-crm-field-row">
						<label for="crm-china-timezone"><?php esc_html_e( 'China timezone', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<select id="crm-china-timezone" name="china_timezone">
								<?php foreach ( crm_tracking_timezone_choices() as $tz => $label ) : ?>
									<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $tz, 'Asia/Shanghai' ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used on order tracking for China office times.', 'ds-prod-import-crm' ); ?></p>
						</div>
					</div>
					<div class="ds-crm-field-row">
						<label for="crm-bangladesh-timezone"><?php esc_html_e( 'Bangladesh timezone', 'ds-prod-import-crm' ); ?></label>
						<div class="ds-crm-field-control">
							<select id="crm-bangladesh-timezone" name="bangladesh_timezone">
								<?php foreach ( crm_tracking_timezone_choices() as $tz => $label ) : ?>
									<option value="<?php echo esc_attr( $tz ); ?>" <?php selected( $tz, 'Asia/Dhaka' ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Default display timezone for admin, staff, and clients. China office users see China time instead.', 'ds-prod-import-crm' ); ?></p>
						</div>
					</div>
					<div class="ds-crm-field-row">
						<span class="ds-crm-field-label"><?php esc_html_e( 'Dual timezone display', 'ds-prod-import-crm' ); ?></span>
						<div class="ds-crm-field-control">
							<label>
								<input type="checkbox" name="tracking_show_dual_tz" value="1" checked />
								<?php esc_html_e( 'Show both Bangladesh and China times on tracking steps', 'ds-prod-import-crm' ); ?>
							</label>
						</div>
					</div>
				</div>
			</div>
		</div>

			<?php include DS_CRM_PATH . 'modules/settings/views/sections/client-portal.php'; ?>
		<?php endif; ?>

		<?php if ( ! in_array( $crm_admin_section, array( 'data', 'team' ), true ) ) : ?>
		<p class="ds-crm-settings-submit">
			<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'ds-prod-import-crm' ); ?></button>
		</p>
		<?php endif; ?>
	</form>
</div>
