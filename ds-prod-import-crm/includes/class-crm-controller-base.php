<?php
/**
 * Base controller for CRM modules.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Shared AJAX and logging helpers.
 */
abstract class CRM_Controller_Base {
	/**
	 * Verify nonce and capability.
	 *
	 * @param string $capability Required capability.
	 * @return void
	 */
	protected static function verify_request( $capability ) {
		check_ajax_referer( 'crm_nonce', 'nonce' );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unauthorized request.', 'ds-prod-import-crm' ),
				),
				403
			);
		}
	}

	/**
	 * Verify nonce and at least one capability.
	 *
	 * @param array<int, string> $capabilities Allowed capabilities.
	 * @return void
	 */
	protected static function verify_request_any( array $capabilities ) {
		check_ajax_referer( 'crm_nonce', 'nonce' );

		foreach ( $capabilities as $capability ) {
			if ( current_user_can( $capability ) ) {
				return;
			}
		}

		wp_send_json_error(
			array(
				'message' => __( 'Unauthorized request.', 'ds-prod-import-crm' ),
			),
			403
		);
	}

	/**
	 * Verify nonce and a module action (granular action and/or legacy bundle).
	 *
	 * @param string $action_cap Granular action capability.
	 * @param string $legacy_cap Optional legacy bundle capability.
	 * @return void
	 */
	protected static function verify_module_action( $action_cap, $legacy_cap = '' ) {
		$caps = array( $action_cap );
		if ( $legacy_cap ) {
			$caps[] = $legacy_cap;
		}
		self::verify_request_any( $caps );
	}

	/**
	 * Verify nonce for create vs edit saves.
	 *
	 * @param string $create_cap Create capability.
	 * @param string $edit_cap   Edit capability.
	 * @param string $legacy_cap Optional legacy bundle.
	 * @param int    $id         Existing record ID (0 = create).
	 * @return void
	 */
	protected static function verify_module_save( $create_cap, $edit_cap, $legacy_cap = '', $id = 0 ) {
		$caps = $id > 0 ? array( $edit_cap ) : array( $create_cap );
		if ( $legacy_cap ) {
			$caps[] = $legacy_cap;
		}
		self::verify_request_any( $caps );
	}

	/**
	 * Read pagination params from request.
	 *
	 * @return array{page:int,per_page:int,offset:int}
	 */
	protected static function pagination_from_request() {
		$page     = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 10;

		if ( ! in_array( $per_page, array( 10, 25, 50 ), true ) ) {
			$per_page = 10;
		}

		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * Read optional date range from request.
	 *
	 * @return array{date_from:string,date_to:string}
	 */
	protected static function date_range_from_request() {
		$date_from = isset( $_POST['date_from'] ) ? crm_normalize_date( wp_unslash( $_POST['date_from'] ) ) : '';
		$date_to   = isset( $_POST['date_to'] ) ? crm_normalize_date( wp_unslash( $_POST['date_to'] ) ) : '';

		return array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}

	/**
	 * Start DB transaction.
	 *
	 * @return void
	 */
	protected static function begin_transaction() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Roll back DB transaction.
	 *
	 * @return void
	 */
	protected static function rollback_transaction() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Commit DB transaction.
	 *
	 * @return void
	 */
	protected static function commit_transaction() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Write activity log row.
	 *
	 * @param string $action Action slug.
	 * @param string $module Module slug.
	 * @param int    $record_id Record ID.
	 * @param string $description Human-readable text.
	 * @return void
	 */
	protected static function log_activity( $action, $module, $record_id, $description, $meta = array() ) {
		CRM_Audit::log( $action, $module, $record_id, $description, is_array( $meta ) ? $meta : array() );
	}
}
