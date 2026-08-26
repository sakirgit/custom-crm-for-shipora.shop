<?php
/**
 * WP-admin tools for CRM data maintenance.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX for the data reset panel on the CRM Settings admin page.
 */
class CRM_Admin_Data {
	/**
	 * Confirmation phrase required to run a reset.
	 */
	const CONFIRM_PHRASE = 'RESET';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_admin_data_stats', array( __CLASS__, 'stats' ) );
		add_action( 'wp_ajax_crm_admin_reset_data', array( __CLASS__, 'reset_data' ) );
	}

	/**
	 * Verify admin + capability + nonce.
	 *
	 * @return void
	 */
	private static function verify_admin_request() {
		if ( ! is_admin() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ds-prod-import-crm' ) ), 403 );
		}

		check_ajax_referer( 'crm_nonce', 'nonce' );

		if ( ! current_user_can( 'crm_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized request.', 'ds-prod-import-crm' ) ), 403 );
		}
	}

	/**
	 * Current data counts for the reset panel.
	 *
	 * @return void
	 */
	public static function stats() {
		self::verify_admin_request();

		$counts = CRM_Data_Reset::get_counts();

		wp_send_json_success(
			array(
				'counts'    => $counts,
				'preserved' => CRM_Data_Reset::preserved_labels(),
				'confirm'   => self::CONFIRM_PHRASE,
			)
		);
	}

	/**
	 * Wipe operational CRM data (settings preserved).
	 *
	 * @return void
	 */
	public static function reset_data() {
		self::verify_admin_request();

		$phrase = isset( $_POST['confirm_phrase'] )
			? sanitize_text_field( wp_unslash( $_POST['confirm_phrase'] ) )
			: '';

		if ( self::CONFIRM_PHRASE !== $phrase ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: confirmation word */
						__( 'Type %s exactly to confirm.', 'ds-prod-import-crm' ),
						self::CONFIRM_PHRASE
					),
				)
			);
		}

		$result = CRM_Data_Reset::reset_operational_data();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of rows removed */
					__( 'All operational CRM data has been cleared (%d rows removed). Settings were kept.', 'ds-prod-import-crm' ),
					(int) $result['total_rows_before']
				),
				'result'  => $result,
				'counts'  => CRM_Data_Reset::get_counts(),
			)
		);
	}
}
