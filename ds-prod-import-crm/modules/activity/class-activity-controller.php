<?php
/**
 * Activity log (admin audit trail).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX for global and per-record activity history.
 */
class Activity_Controller extends CRM_Controller_Base {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_activity_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_activity_record', array( __CLASS__, 'record_history' ) );
		add_action( 'wp_ajax_crm_activity_filters', array( __CLASS__, 'filter_options' ) );
	}

	/**
	 * Paginated activity feed.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_request( 'crm_manage_settings' );

		$result = CRM_Audit::query_list(
			array(
				'page'      => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1,
				'per_page'  => isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 25,
				'module'    => isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '',
				'action'    => isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '',
				'user_id'   => isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0,
				'record_id' => isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0,
				'search'    => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
				'date_from' => isset( $_POST['date_from'] ) ? wp_unslash( $_POST['date_from'] ) : '',
				'date_to'   => isset( $_POST['date_to'] ) ? wp_unslash( $_POST['date_to'] ) : '',
			)
		);

		wp_send_json_success( $result );
	}

	/**
	 * History for one record.
	 *
	 * @return void
	 */
	public static function record_history() {
		self::verify_request_any(
			array(
				'crm_manage_settings',
				'crm_orders_view',
				'crm_clients_view',
				'crm_payments_view',
				'crm_delivery_view',
				'crm_stock_view',
			)
		);

		$module    = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$record_id = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;

		if ( '' === $module || ! $record_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success(
			array(
				'items' => CRM_Audit::get_for_record( $module, $record_id, 30 ),
			)
		);
	}

	/**
	 * Filter dropdown data.
	 *
	 * @return void
	 */
	public static function filter_options() {
		self::verify_request( 'crm_manage_settings' );

		wp_send_json_success(
			array(
				'modules' => CRM_Audit::module_labels(),
				'actions' => CRM_Audit::action_labels(),
				'users'   => CRM_Audit::get_crm_users_for_filter(),
			)
		);
	}
}
