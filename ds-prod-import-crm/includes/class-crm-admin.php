<?php
/**
 * Admin menu — configuration only; daily CRM runs on the public page.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wp-admin settings entry and redirects legacy CRM URLs to the frontend.
 */
class CRM_Admin {
	/**
	 * Settings tab definitions (single wp-admin page with in-page tabs).
	 *
	 * @return array<string, string>
	 */
	public static function settings_sections() {
		return array(
			'general'    => __( 'General', 'ds-prod-import-crm' ),
			'appearance' => __( 'Appearance & page', 'ds-prod-import-crm' ),
			'team'       => __( 'Team & access', 'ds-prod-import-crm' ),
			'data'       => __( 'Data tools', 'ds-prod-import-crm' ),
		);
	}

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_to_frontend' ) );
	}

	/**
	 * Register WordPress menu entry (settings only).
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'CRM Settings', 'ds-prod-import-crm' ),
			__( 'CRM Settings', 'ds-prod-import-crm' ),
			'crm_manage_settings',
			'ds-prod-import-crm',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			30
		);

		// WordPress duplicates the top-level item as a submenu — remove it.
		remove_submenu_page( 'ds-prod-import-crm', 'ds-prod-import-crm' );
	}

	/**
	 * Resolve active settings section from query arg.
	 *
	 * @return string
	 */
	public static function current_section() {
		$allowed = array_keys( self::settings_sections() );
		$section = isset( $_GET['crm_section'] ) ? sanitize_key( wp_unslash( $_GET['crm_section'] ) ) : 'general';

		if ( in_array( $section, $allowed, true ) ) {
			return $section;
		}

		return 'general';
	}

	/**
	 * Redirect old wp-admin CRM module URLs to the public CRM page.
	 *
	 * @return void
	 */
	public static function maybe_redirect_to_frontend() {
		if ( ! is_admin() || ! isset( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );

		if ( 'ds-prod-import-crm' !== $page && 0 !== strpos( $page, 'ds-prod-import-crm-' ) ) {
			return;
		}

		// Legacy submenu slugs → tabbed settings page.
		if ( 0 === strpos( $page, 'ds-prod-import-crm-' ) ) {
			$legacy = substr( $page, strlen( 'ds-prod-import-crm-' ) );
			$map    = array(
				'branding'      => 'appearance',
				'frontend'      => 'appearance',
				'client-portal' => 'general',
			);
			$section = isset( $map[ $legacy ] ) ? $map[ $legacy ] : $legacy;
			wp_safe_redirect( crm_admin_settings_url( $section ) );
			exit;
		}

		if ( ! crm_frontend_is_ready() ) {
			return;
		}

		$module = isset( $_GET['crm_module'] ) ? sanitize_key( wp_unslash( $_GET['crm_module'] ) ) : '';

		if ( current_user_can( 'crm_view_dashboard' ) && ! current_user_can( 'crm_manage_settings' ) ) {
			wp_safe_redirect( crm_module_url( $module ? $module : 'dashboard', 'frontend' ) );
			exit;
		}

		if ( CRM_Client_Portal::is_client_user() && ! current_user_can( 'crm_manage_settings' ) ) {
			wp_safe_redirect( crm_module_url( 'orders', 'frontend' ) );
			exit;
		}

		if ( ! $module || 'settings' === $module ) {
			return;
		}

		wp_safe_redirect( crm_module_url( $module, 'frontend' ) );
		exit;
	}

	/**
	 * Render wp-admin settings screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'crm_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage CRM settings.', 'ds-prod-import-crm' ) );
		}

		$crm_admin_section = self::current_section();
		$template          = DS_CRM_PATH . 'templates/crm-admin-settings.php';

		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}
