<?php
/**
 * WordPress admin settings screen (frontend page + shortcode setup).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$frontend_ready    = crm_frontend_is_ready();
$frontend_url      = $frontend_ready ? crm_get_public_app_url() : '';
$crm_admin_section = isset( $crm_admin_section ) ? sanitize_key( $crm_admin_section ) : CRM_Admin::current_section();
$sections          = CRM_Admin::settings_sections();
$section_titles    = array(
	'general'    => __( 'General options', 'ds-prod-import-crm' ),
	'appearance' => __( 'Appearance & public page', 'ds-prod-import-crm' ),
	'team'       => __( 'Team & access', 'ds-prod-import-crm' ),
	'data'       => __( 'Data tools', 'ds-prod-import-crm' ),
);
?>
<div class="wrap ds-crm-admin-settings">
	<h1><?php echo esc_html( $section_titles[ $crm_admin_section ] ?? __( 'CRM Settings', 'ds-prod-import-crm' ) ); ?></h1>
	<p class="description"><?php esc_html_e( 'Configure branding, the public CRM page, and general options. Day-to-day CRM work happens on your site front end.', 'ds-prod-import-crm' ); ?></p>

	<nav class="nav-tab-wrapper ds-crm-settings-tabs" aria-label="<?php esc_attr_e( 'CRM settings sections', 'ds-prod-import-crm' ); ?>">
		<?php foreach ( $sections as $slug => $label ) : ?>
			<a
				href="<?php echo esc_url( crm_admin_settings_url( $slug ) ); ?>"
				class="nav-tab<?php echo $slug === $crm_admin_section ? ' nav-tab-active' : ''; ?>"
			><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( $frontend_ready ) : ?>
		<div class="notice notice-success inline">
			<p>
				<?php esc_html_e( 'The CRM runs on your public site. Staff should use the link below — not this wp-admin screen — for daily work.', 'ds-prod-import-crm' ); ?>
				<a href="<?php echo esc_url( $frontend_url ); ?>" target="_blank" rel="noopener"><strong><?php esc_html_e( 'Open CRM on site', 'ds-prod-import-crm' ); ?></strong></a>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'Create a page, add the shortcode, publish it, then select that page under Appearance & page.', 'ds-prod-import-crm' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	if ( 'data' === $crm_admin_section ) {
		echo '<div class="ds-crm-module-page" data-crm-module="settings">';
		include DS_CRM_PATH . 'modules/settings/views/data-reset-panel.php';
		echo '</div>';
	} else {
		include DS_CRM_PATH . 'modules/settings/views/list.php';
	}
	?>
</div>
