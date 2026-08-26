<?php
/**
 * Custom order statuses (admin).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for order status labels used on orders.
 */
class Order_Statuses_Controller extends CRM_Controller_Base {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_order_statuses_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_order_statuses_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_order_statuses_delete', array( __CLASS__, 'delete_item' ) );
	}

	/**
	 * List statuses.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_request( 'crm_manage_settings' );

		$items = CRM_Order_Status::get_all_active();

		wp_send_json_success(
			array(
				'items'   => $items,
				'summary' => CRM_Module_Summary::order_statuses( $items ? $items : array() ),
			)
		);
	}

	/**
	 * Create or update custom status.
	 *
	 * @return void
	 */
	public static function save_item() {
		self::verify_request( 'crm_manage_settings' );

		global $wpdb;

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$color = isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '#6b7280';
		$slug  = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

		if ( '' === $label ) {
			wp_send_json_error( array( 'message' => __( 'Label is required.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! $color ) {
			$color = '#6b7280';
		}

		$table = crm_table( 'order_statuses' );
		$data  = array(
			'color'           => $color,
			'sort_order'      => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 50,
			'auto_on_paid'    => ! empty( $_POST['auto_on_paid'] ) ? 1 : 0,
			'is_closed'       => ! empty( $_POST['is_closed'] ) ? 1 : 0,
			'blocks_workflow' => ! empty( $_POST['blocks_workflow'] ) ? 1 : 0,
			'status'          => 'active',
			'updated_at'      => current_time( 'mysql' ),
		);

		if ( ! empty( $data['auto_on_paid'] ) ) {
			if ( $id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET auto_on_paid = 0 WHERE auto_on_paid = 1 AND id != %d", $id ) );
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "UPDATE {$table} SET auto_on_paid = 0 WHERE auto_on_paid = 1" );
			}
		}

		if ( $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT is_system, slug FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $row ) {
				wp_send_json_error( array( 'message' => __( 'Status not found.', 'ds-prod-import-crm' ) ) );
			}

			if ( (int) $row['is_system'] ) {
				unset( $data['status'] );
				$data['auto_on_paid'] = ! empty( $_POST['auto_on_paid'] ) ? 1 : 0;
			}

			$wpdb->update( $table, $data, array( 'id' => $id ) );
			CRM_Order_Status::flush_cache();
			wp_send_json_success( array( 'message' => __( 'Status updated.', 'ds-prod-import-crm' ) ) );
		}

		if ( '' === $slug ) {
			$slug = sanitize_title( $label );
		}

		if ( CRM_Order_Status::is_valid_slug( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'This status key already exists.', 'ds-prod-import-crm' ) ) );
		}

		$data['slug']       = $slug;
		$data['is_system']  = 0;
		$data['created_at'] = current_time( 'mysql' );

		$wpdb->insert( $table, $data );
		CRM_Order_Status::flush_cache();
		wp_send_json_success( array( 'message' => __( 'Status created.', 'ds-prod-import-crm' ) ) );
	}

	/**
	 * Delete custom status only.
	 *
	 * @return void
	 */
	public static function delete_item() {
		self::verify_request( 'crm_manage_settings' );

		global $wpdb;

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$table = crm_table( 'order_statuses' );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT slug, is_system FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Status not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( (int) $row['is_system'] ) {
			wp_send_json_error( array( 'message' => __( 'System statuses cannot be deleted.', 'ds-prod-import-crm' ) ) );
		}

		$in_use = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'orders' ) . ' WHERE status = %s',
				$row['slug']
			)
		);

		if ( $in_use > 0 ) {
			wp_send_json_error( array( 'message' => __( 'Status is used on orders. Set inactive instead.', 'ds-prod-import-crm' ) ) );
		}

		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		CRM_Order_Status::flush_cache();
		wp_send_json_success( array( 'message' => __( 'Status deleted.', 'ds-prod-import-crm' ) ) );
	}
}
