<?php
/**
 * Clients module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Customer clients CRUD.
 */
class Clients_Controller extends CRM_Controller_Base {
	/**
	 * Allowed sort columns.
	 *
	 * @var array<int, string>
	 */
	private static $sort_columns = array( 'name', 'phone', 'email', 'status', 'created_at' );

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_clients_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_clients_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_clients_form_data', array( __CLASS__, 'form_data' ) );
		add_action( 'wp_ajax_crm_clients_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_clients_delete', array( __CLASS__, 'delete_item' ) );
		add_action( 'wp_ajax_crm_clients_ledger', array( __CLASS__, 'ledger' ) );
	}

	/**
	 * Form metadata (portal user options).
	 *
	 * @return void
	 */
	public static function form_data() {
		self::verify_module_action( 'crm_clients_view', 'crm_manage_clients' );

		wp_send_json_success(
			array(
				'portal_users' => CRM_Client_Portal::portal_user_options(),
			)
		);
	}

	/**
	 * List clients with search, sort, pagination.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_module_action( 'crm_clients_view', 'crm_manage_clients' );

		global $wpdb;

		$table      = crm_table( 'clients' );
		$pagination = self::pagination_from_request();
		$dates      = self::date_range_from_request();
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$sort_by    = isset( $_POST['sort_by'] ) ? sanitize_key( wp_unslash( $_POST['sort_by'] ) ) : 'created_at';
		$sort_dir   = crm_sort_direction( isset( $_POST['sort_dir'] ) ? wp_unslash( $_POST['sort_dir'] ) : 'DESC' );

		if ( ! in_array( $sort_by, self::$sort_columns, true ) ) {
			$sort_by = 'created_at';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(name LIKE %s OR phone LIKE %s OR email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $dates['date_from'] ) {
			$where[]  = 'DATE(created_at) >= %s';
			$params[] = $dates['date_from'];
		}

		if ( $dates['date_to'] ) {
			$where[]  = 'DATE(created_at) <= %s';
			$params[] = $dates['date_to'];
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT id, name, phone, email, address, notes, status, created_at, updated_at
			FROM {$table}
			WHERE {$where_sql}
			ORDER BY {$sort_by} {$sort_dir}
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		if ( $items ) {
			foreach ( $items as $index => $item ) {
				$summary = CRM_Ledger::get_client_summary( (int) $item['id'] );
				$items[ $index ]['ledger'] = array(
					'total_bill' => $summary['total_bill'],
					'total_paid' => $summary['total_paid'],
					'total_due'  => $summary['total_due'],
				);
			}
		}

		wp_send_json_success(
			array(
				'items'       => $items ? $items : array(),
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
				'summary'     => CRM_Module_Summary::clients( $where_sql, $params ),
				'financial'   => CRM_Ledger::get_total_client_summary(),
			)
		);
	}

	/**
	 * Get single client.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_module_action( 'crm_clients_view', 'crm_manage_clients' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid client ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'clients' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$row['wp_user_id'] = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;

		wp_send_json_success(
			array(
				'item'         => $row,
				'portal_users' => CRM_Client_Portal::portal_user_options(),
			)
		);
	}

	/**
	 * Create or update client.
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::verify_module_save( 'crm_clients_create', 'crm_clients_edit', 'crm_manage_clients', $id );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Client name is required.', 'ds-prod-import-crm' ) ) );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( $email && ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'ds-prod-import-crm' ) ) );
		}

		$data = array(
			'name'       => $name,
			'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'email'      => $email,
			'address'    => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
			'notes'      => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'status'     => isset( $_POST['status'] ) && 'inactive' === sanitize_key( wp_unslash( $_POST['status'] ) ) ? 'inactive' : 'active',
			'updated_at' => current_time( 'mysql' ),
		);

		$table  = crm_table( 'clients' );
		$labels = array(
			'name'    => __( 'Name', 'ds-prod-import-crm' ),
			'phone'   => __( 'Phone', 'ds-prod-import-crm' ),
			'email'   => __( 'Email', 'ds-prod-import-crm' ),
			'address' => __( 'Address', 'ds-prod-import-crm' ),
			'status'  => __( 'Status', 'ds-prod-import-crm' ),
		);

		if ( $id ) {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			$data['updated_by'] = CRM_Audit::current_user_id();

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update client.', 'ds-prod-import-crm' ) ) );
			}

			$changes = $before ? CRM_Audit::describe_changes( $before, $data, $labels ) : array();
			self::log_activity(
				'update',
				'clients',
				$id,
				sprintf( 'Updated client: %s', $name ),
				array( 'changes' => $changes )
			);

			if ( isset( $_POST['wp_user_id'] ) ) {
				CRM_Client_Portal::assign_portal_user( $id, absint( $_POST['wp_user_id'] ) );
			}

			wp_send_json_success(
				array(
					'message' => __( 'Client updated successfully.', 'ds-prod-import-crm' ),
					'id'      => $id,
				)
			);
		}

		$data['created_at'] = current_time( 'mysql' );
		$data['created_by'] = CRM_Audit::current_user_id();

		$inserted = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create client.', 'ds-prod-import-crm' ) ) );
		}

		$new_id = (int) $wpdb->insert_id;
		self::log_activity( 'create', 'clients', $new_id, sprintf( 'Created client: %s', $name ) );

		if ( isset( $_POST['wp_user_id'] ) ) {
			CRM_Client_Portal::assign_portal_user( $new_id, absint( $_POST['wp_user_id'] ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Client created successfully.', 'ds-prod-import-crm' ),
				'id'      => $new_id,
			)
		);
	}

	/**
	 * Delete client when not referenced.
	 *
	 * @return void
	 */
	public static function delete_item() {
		self::verify_module_action( 'crm_clients_delete', 'crm_manage_clients' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid client ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'clients' );
		$name  = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) );

		if ( ! $name ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$order_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'orders' ) . ' WHERE client_id = %d',
				$id
			)
		);

		$delivery_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'deliveries' ) . ' WHERE client_id = %d',
				$id
			)
		);

		$payment_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'payments' ) . ' WHERE client_id = %d',
				$id
			)
		);

		if ( $order_count > 0 || $delivery_count > 0 || $payment_count > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot delete: this client has orders, deliveries, or payments. Set status to inactive instead.', 'ds-prod-import-crm' ),
				)
			);
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete client.', 'ds-prod-import-crm' ) ) );
		}

		self::log_activity( 'delete', 'clients', $id, sprintf( 'Deleted client: %s', $name ) );

		wp_send_json_success(
			array(
				'message' => __( 'Client deleted successfully.', 'ds-prod-import-crm' ),
			)
		);
	}

	/**
	 * Client financial ledger.
	 *
	 * @return void
	 */
	public static function ledger() {
		self::verify_request_any(
			array(
				'crm_clients_view',
				'crm_manage_clients',
				'crm_payments_view',
				'crm_manage_payments',
				'crm_orders_view',
				'crm_manage_orders',
			)
		);

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid client ID.', 'ds-prod-import-crm' ) ) );
		}

		$client = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . crm_table( 'clients' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, order_number, order_date, status FROM ' . crm_table( 'orders' ) . ' WHERE client_id = %d ORDER BY order_date DESC LIMIT 30',
				$id
			),
			ARRAY_A
		);

		if ( $orders ) {
			foreach ( $orders as $index => $order ) {
				$orders[ $index ]['summary'] = CRM_Ledger::get_order_summary( (int) $order['id'] );
			}
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.*, o.order_number FROM ' . crm_table( 'payments' ) . ' p
				LEFT JOIN ' . crm_table( 'orders' ) . ' o ON o.id = p.order_id
				WHERE p.client_id = %d ORDER BY p.payment_date DESC LIMIT 50',
				$id
			),
			ARRAY_A
		);

		if ( $payments ) {
			foreach ( $payments as $index => $payment ) {
				$purpose = CRM_Ledger::normalize_payment_purpose( $payment['payment_purpose'] ?? 'auto' );
				$payments[ $index ]['payment_purpose']       = $purpose;
				$payments[ $index ]['payment_purpose_label'] = CRM_Ledger::payment_purpose_label( $purpose );
			}
		}

		wp_send_json_success(
			array(
				'client'   => $client,
				'summary'  => CRM_Ledger::get_client_summary( $id ),
				'orders'   => $orders ? $orders : array(),
				'payments' => $payments ? $payments : array(),
				'permissions' => array(
					'can_record_payment' => ! CRM_Client_Portal::is_client_user() && CRM_Capability_Registry::user_can_record_payments(),
				),
			)
		);
	}
}
