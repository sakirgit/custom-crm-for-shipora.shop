<?php
/**
 * Shared CRM application shell renderer.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Renders CRM UI on the public frontend page.
 */
class CRM_App {
	/**
	 * Output CRM application markup.
	 *
	 * @param string $context frontend (only).
	 * @return void
	 */
	public static function render( $context = 'frontend' ) {
		if ( ! self::user_can_open_crm() ) {
			self::render_access_denied();
			return;
		}

		$crm_context    = 'frontend';
		$default_module = self::resolve_default_module();
		$active_module  = isset( $_GET['crm_module'] ) ? sanitize_key( wp_unslash( $_GET['crm_module'] ) ) : $default_module;
		$base_url       = crm_get_app_base_url( 'frontend' );
		$logout_url     = wp_logout_url( crm_module_url( $default_module, 'frontend' ) );

		if ( ! CRM_Access::user_can_access_module( $active_module ) ) {
			$active_module = $default_module;
		}

		$template = DS_CRM_PATH . 'templates/crm-page-wrapper.php';

		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Whether the current user may load the CRM shell.
	 *
	 * @return bool
	 */
	private static function user_can_open_crm() {
		return CRM_Access::user_can_open_frontend_crm();
	}

	/**
	 * Landing module after login or when no crm_module query arg is set.
	 *
	 * @return string
	 */
	private static function resolve_default_module() {
		if ( CRM_Client_Portal::is_client_user() ) {
			return 'orders';
		}

		if ( CRM_China_Office::is_china_office_user() ) {
			return 'shipments';
		}

		return 'dashboard';
	}

	/**
	 * Frontend access denied message.
	 *
	 * @return void
	 */
	private static function render_access_denied() {
		echo '<div class="ds-crm-access-denied"><p>';
		esc_html_e( 'You do not have permission to use the CRM. Contact your administrator.', 'ds-prod-import-crm' );
		echo '</p></div>';
	}
}
