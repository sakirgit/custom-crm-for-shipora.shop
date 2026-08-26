<?php
/**
 * CRM settings module.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings (frontend page, thresholds, branding).
 */
class Settings_Controller extends CRM_Controller_Base {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_settings_get', array( __CLASS__, 'get_settings' ) );
		add_action( 'wp_ajax_crm_settings_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'wp_ajax_crm_settings_sync_portal_users', array( __CLASS__, 'sync_portal_users' ) );
	}

	/**
	 * Return settings for admin UI.
	 *
	 * @return void
	 */
	public static function get_settings() {
		self::verify_request( 'crm_manage_settings' );

		$settings = crm_get_settings();
		$page_id  = (int) ( $settings['frontend_page_id'] ?? 0 );

		$pages = get_pages(
			array(
				'sort_column' => 'post_title',
				'sort_order'  => 'ASC',
			)
		);

		$page_options = array();
		foreach ( $pages as $page ) {
			$has_shortcode = CRM_Frontend::post_has_shortcode( $page );
			$page_options[] = array(
				'id'            => (int) $page->ID,
				'title'         => $page->post_title,
				'has_shortcode' => $has_shortcode,
				'url'           => get_permalink( $page ),
			);
		}

		$warnings = array();
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			$selected = get_post( $page_id );
			if ( $selected && ! CRM_Frontend::post_has_shortcode( $selected ) ) {
				$warnings[] = __( 'The selected page does not contain the CRM shortcode yet. Add it to the page content.', 'ds-prod-import-crm' );
			}
		} else {
			$warnings[] = __( 'Select a published page and add the shortcode to present the CRM to users.', 'ds-prod-import-crm' );
		}

		wp_send_json_success(
			array(
				'settings'      => $settings,
				'branding'      => crm_get_branding(),
				'shortcode'     => crm_shortcode_example(),
				'shortcode_tag' => crm_shortcode_tag(),
				'pages'         => $page_options,
				'frontend_url'  => $page_id ? get_permalink( $page_id ) : '',
				'warnings'      => $warnings,
			)
		);
	}

	/**
	 * Keep an existing media URL when the form submits an empty hidden field by mistake.
	 *
	 * @param string $post_key       POST field name.
	 * @param string $current_value  Stored value.
	 * @return string
	 */
	private static function merge_media_url_setting( $post_key, $current_value ) {
		if ( ! isset( $_POST[ $post_key ] ) ) {
			return $current_value;
		}

		$url = esc_url_raw( wp_unslash( $_POST[ $post_key ] ) );

		if ( '' !== $url || '' === (string) $current_value ) {
			return $url;
		}

		return $current_value;
	}

	/**
	 * Save settings.
	 *
	 * @return void
	 */
	public static function save_settings() {
		self::verify_request( 'crm_manage_settings' );

		$section  = isset( $_POST['settings_section'] ) ? sanitize_key( wp_unslash( $_POST['settings_section'] ) ) : 'general';
		$settings = crm_get_settings();
		$old_mode = crm_pricing_mode();

		switch ( $section ) {
			case 'appearance':
			case 'branding':
				$settings['company_name']    = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : $settings['company_name'];
				$settings['company_tagline'] = isset( $_POST['company_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['company_tagline'] ) ) : $settings['company_tagline'];
				$settings['company_logo_url'] = self::merge_media_url_setting( 'company_logo_url', (string) $settings['company_logo_url'] );
				$settings['favicon_url']      = self::merge_media_url_setting( 'favicon_url', (string) $settings['favicon_url'] );
				$settings['color_sidebar'] = crm_sanitize_hex_color(
					isset( $_POST['color_sidebar'] ) ? wp_unslash( $_POST['color_sidebar'] ) : $settings['color_sidebar'],
					'#1a1f2e'
				);
				$settings['color_accent'] = crm_sanitize_hex_color(
					isset( $_POST['color_accent'] ) ? wp_unslash( $_POST['color_accent'] ) : $settings['color_accent'],
					'#2563eb'
				);
				$settings['color_accent_secondary'] = crm_sanitize_hex_color(
					isset( $_POST['color_accent_secondary'] ) ? wp_unslash( $_POST['color_accent_secondary'] ) : $settings['color_accent_secondary'],
					'#7c3aed'
				);
				if ( 'appearance' === $section || isset( $_POST['frontend_page_id'] ) ) {
					$frontend_page_id = isset( $_POST['frontend_page_id'] ) ? absint( $_POST['frontend_page_id'] ) : 0;
					if ( $frontend_page_id && 'publish' !== get_post_status( $frontend_page_id ) ) {
						wp_send_json_error( array( 'message' => __( 'Please select a valid published page.', 'ds-prod-import-crm' ) ) );
					}
					$settings['frontend_page_id'] = $frontend_page_id;
				}
				break;

			case 'frontend':
				$frontend_page_id = isset( $_POST['frontend_page_id'] ) ? absint( $_POST['frontend_page_id'] ) : 0;
				if ( $frontend_page_id && 'publish' !== get_post_status( $frontend_page_id ) ) {
					wp_send_json_error( array( 'message' => __( 'Please select a valid published page.', 'ds-prod-import-crm' ) ) );
				}
				$settings['frontend_page_id'] = $frontend_page_id;
				break;

			case 'client-portal':
				$settings['client_orders_scope'] = isset( $_POST['client_orders_scope'] ) && 'all' === sanitize_key( wp_unslash( $_POST['client_orders_scope'] ) ) ? 'all' : 'own';
				break;

			case 'general':
			default:
				$new_mode = isset( $_POST['pricing_mode'] ) && 'single' === sanitize_key( wp_unslash( $_POST['pricing_mode'] ) ) ? 'single' : 'dual';
				$settings['low_stock_threshold'] = max( 1, isset( $_POST['low_stock_threshold'] ) ? absint( $_POST['low_stock_threshold'] ) : (int) $settings['low_stock_threshold'] );
				$settings['currency_symbol']     = isset( $_POST['currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ) ) : $settings['currency_symbol'];
				if ( '' === $settings['currency_symbol'] ) {
					$settings['currency_symbol'] = '৳';
				}
				$settings['pricing_mode'] = $new_mode;
				crm_apply_pricing_mode_transition( $old_mode, $new_mode );
				if ( isset( $_POST['shipments_module_label'] ) ) {
					$label = sanitize_text_field( wp_unslash( $_POST['shipments_module_label'] ) );
					$settings['shipments_module_label'] = $label;
				}
				$settings['china_timezone'] = crm_sanitize_tracking_timezone(
					isset( $_POST['china_timezone'] ) ? wp_unslash( $_POST['china_timezone'] ) : ( $settings['china_timezone'] ?? 'Asia/Shanghai' ),
					'Asia/Shanghai'
				);
				$settings['bangladesh_timezone'] = crm_sanitize_tracking_timezone(
					isset( $_POST['bangladesh_timezone'] ) ? wp_unslash( $_POST['bangladesh_timezone'] ) : ( $settings['bangladesh_timezone'] ?? 'Asia/Dhaka' ),
					'Asia/Dhaka'
				);
				$settings['tracking_show_dual_tz'] = ! empty( $_POST['tracking_show_dual_tz'] ) ? 1 : 0;
				if ( 'general' === $section || isset( $_POST['client_orders_scope'] ) ) {
					$settings['client_orders_scope'] = isset( $_POST['client_orders_scope'] ) && 'all' === sanitize_key( wp_unslash( $_POST['client_orders_scope'] ) ) ? 'all' : 'own';
				}
				break;
		}

		update_option( 'ds_crm_settings', $settings );

		self::log_activity( 'update', 'settings', 0, 'Updated CRM settings' );

		wp_send_json_success(
			array(
				'message'      => __( 'Settings saved.', 'ds-prod-import-crm' ),
				'frontend_url' => ! empty( $settings['frontend_page_id'] ) ? get_permalink( (int) $settings['frontend_page_id'] ) : '',
				'branding'     => crm_get_branding(),
			)
		);
	}

	/**
	 * Create missing client records for all CRM Client users.
	 *
	 * @return void
	 */
	public static function sync_portal_users() {
		self::verify_request( 'crm_manage_settings' );

		$created = CRM_Client_Portal::sync_all_portal_users();

		wp_send_json_success(
			array(
				'message' => $created > 0
					? sprintf(
						/* translators: %d: number of client records created */
						_n( '%d client record created.', '%d client records created.', $created, 'ds-prod-import-crm' ),
						$created
					)
					: __( 'All CRM Client users are already linked to client records.', 'ds-prod-import-crm' ),
				'created' => $created,
			)
		);
	}
}
