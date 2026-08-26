<?php
/**
 * AJAX central endpoints.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers global CRM AJAX actions.
 */
class CRM_Ajax {
	/**
	 * Boot AJAX routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_app_bootstrap', array( __CLASS__, 'bootstrap' ) );
	}

	/**
	 * Return current user and shell state.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		check_ajax_referer( 'crm_nonce', 'nonce' );

		if ( ! current_user_can( 'crm_view_dashboard' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unauthorized request.', 'ds-prod-import-crm' ),
				),
				403
			);
		}

		$current_user = wp_get_current_user();

		wp_send_json_success(
			array(
				'user' => array(
					'id'    => $current_user->ID,
					'name'  => $current_user->display_name,
					'email' => $current_user->user_email,
				),
				'role' => isset( $current_user->roles[0] ) ? $current_user->roles[0] : '',
			)
		);
	}
}
