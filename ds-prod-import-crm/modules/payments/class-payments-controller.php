<?php
/**
 * Customer payments module.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Record and list client payments.
 */
class Payments_Controller extends CRM_Controller_Base {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_payments_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_payments_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_payments_delete', array( __CLASS__, 'delete_item' ) );
		add_action( 'wp_ajax_crm_payments_form_data', array( __CLASS__, 'form_data' ) );
		add_action( 'wp_ajax_crm_payments_order_preview', array( __CLASS__, 'order_preview' ) );
		add_action( 'wp_ajax_crm_payments_client_preview', array( __CLASS__, 'client_preview' ) );
		add_action( 'wp_ajax_crm_payments_supplier_list', array( __CLASS__, 'supplier_list' ) );
		add_action( 'wp_ajax_crm_payments_supplier_form_data', array( __CLASS__, 'supplier_form_data' ) );
		add_action( 'wp_ajax_crm_payments_supplier_preview', array( __CLASS__, 'supplier_preview' ) );
		add_action( 'wp_ajax_crm_payments_supplier_save', array( __CLASS__, 'supplier_save' ) );
		add_action( 'wp_ajax_crm_payments_supplier_delete', array( __CLASS__, 'supplier_delete' ) );
	}

	/**
	 * Order summary for the payment form when an order is selected.
	 *
	 * @return void
	 */
	public static function order_preview() {
		self::verify_module_action( 'crm_payments_view', 'crm_manage_payments' );

		global $wpdb;

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'ds-prod-import-crm' ) ) );
		}

		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$items_table   = crm_table( 'order_items' );

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.id, o.order_number, o.order_date, o.status, o.notes, o.client_id,
				cl.name AS client_name, cl.phone AS client_phone
				FROM {$orders_table} o
				LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
				WHERE o.id = %d",
				$order_id
			),
			ARRAY_A
		);

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		$item_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$items_table} WHERE order_id = %d", $order_id )
		);

		$order['status_label'] = $order['status'];
		foreach ( CRM_Order_Status::get_all_active() as $status_row ) {
			if ( $status_row['slug'] === $order['status'] ) {
				$order['status_label'] = $status_row['label'];
				break;
			}
		}

		wp_send_json_success(
			array(
				'order'       => $order,
				'item_count'  => $item_count,
				'summary'     => CRM_Ledger::get_order_summary( $order_id ),
				'client_summary' => CRM_Ledger::get_client_summary( (int) $order['client_id'] ),
			)
		);
	}

	/**
	 * Client balance for payment form (lump-sum payments without a specific order).
	 *
	 * @return void
	 */
	public static function client_preview() {
		self::verify_module_action( 'crm_payments_view', 'crm_manage_payments' );

		global $wpdb;

		$client_id = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid client ID.', 'ds-prod-import-crm' ) ) );
		}

		$client = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, phone, email, address FROM ' . crm_table( 'clients' ) . ' WHERE id = %d',
				$client_id
			),
			ARRAY_A
		);

		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$open_orders = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . crm_table( 'orders' ) . " WHERE client_id = %d AND status != 'cancelled'",
				$client_id
			)
		);

		wp_send_json_success(
			array(
				'client'       => $client,
				'open_orders'  => $open_orders,
				'summary'      => CRM_Ledger::get_client_summary( $client_id ),
			)
		);
	}

	/**
	 * Clients and open orders for payment form.
	 *
	 * @return void
	 */
	public static function form_data() {
		self::verify_module_action( 'crm_payments_view', 'crm_manage_payments' );

		if ( CRM_Client_Portal::is_client_user() ) {
			wp_send_json_error( array( 'message' => __( 'Not available in the client portal.', 'ds-prod-import-crm' ) ), 403 );
		}

		global $wpdb;

		$clients   = $wpdb->get_results(
			"SELECT id, name, phone FROM " . crm_table( 'clients' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);
		$client_id = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$orders    = array();

		if ( $client_id ) {
			$orders_table = crm_table( 'orders' );
			$orders       = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, order_number, order_date, status FROM {$orders_table}
					WHERE client_id = %d AND status != 'cancelled'
					ORDER BY order_date DESC, id DESC
					LIMIT 50",
					$client_id
				),
				ARRAY_A
			);
		}

		wp_send_json_success(
			array(
				'clients' => $clients ? $clients : array(),
				'orders'  => $orders ? $orders : array(),
			)
		);
	}

	/**
	 * List payments.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_module_action( 'crm_payments_view', 'crm_manage_payments' );

		global $wpdb;

		$table         = crm_table( 'payments' );
		$clients_table = crm_table( 'clients' );
		$orders_table  = crm_table( 'orders' );
		$pagination    = self::pagination_from_request();
		$dates         = self::date_range_from_request();
		$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$period        = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '';
		if ( ! in_array( $period, array( 'today', 'week', 'month' ), true ) ) {
			$period = '';
		}

		$where  = array( '1=1' );
		$params = array();

		CRM_Client_Portal::apply_payment_list_scope( $where, $params );

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(p.payment_number LIKE %s OR cl.name LIKE %s OR p.reference LIKE %s OR o.order_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Staff may filter by client; portal users are already scoped to their linked client.
		if ( $client_id && ! CRM_Client_Portal::is_client_user() ) {
			$where[]  = 'p.client_id = %d';
			$params[] = $client_id;
		}

		$base_sql    = implode( ' AND ', $where );
		$base_params = $params;

		if ( $period ) {
			$bounds   = CRM_Module_Summary::delivery_period_bounds( $period );
			$where[]  = 'p.payment_date >= %s';
			$params[] = $bounds['from'];
			$where[]  = 'p.payment_date <= %s';
			$params[] = $bounds['to'];
		} else {
			if ( $dates['date_from'] ) {
				$where[]  = 'p.payment_date >= %s';
				$params[] = $dates['date_from'];
			}

			if ( $dates['date_to'] ) {
				$where[]  = 'p.payment_date <= %s';
				$params[] = $dates['date_to'];
			}
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} p
			LEFT JOIN {$clients_table} cl ON cl.id = p.client_id
			LEFT JOIN {$orders_table} o ON o.id = p.order_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT p.*, cl.name AS client_name, o.order_number
			FROM {$table} p
			LEFT JOIN {$clients_table} cl ON cl.id = p.client_id
			LEFT JOIN {$orders_table} o ON o.id = p.order_id
			WHERE {$where_sql}
			ORDER BY p.payment_date DESC, p.id DESC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		$is_client     = CRM_Client_Portal::is_client_user();
		$client_balance = null;
		if ( $is_client ) {
			$linked_id = CRM_Client_Portal::get_linked_client_id();
			if ( $linked_id > 0 ) {
				$client_balance = CRM_Ledger::get_client_summary( $linked_id );
			}
		}

		wp_send_json_success(
			array(
				'items'          => $items ? $items : array(),
				'total'          => $total,
				'page'           => $pagination['page'],
				'per_page'       => $pagination['per_page'],
				'total_pages'    => max( 1, $total_pages ),
				'summary'        => $is_client ? array() : CRM_Module_Summary::payments( $where_sql, $params, $base_sql, $base_params, $client_id ),
				'is_client'      => $is_client,
				'client_balance' => $client_balance,
				'can_create'     => ! $is_client && ( current_user_can( 'crm_payments_create' ) || current_user_can( 'crm_manage_payments' ) ),
				'can_edit'       => ! $is_client && ( current_user_can( 'crm_payments_edit' ) || current_user_can( 'crm_manage_payments' ) ),
				'can_delete'     => ! $is_client && ( current_user_can( 'crm_payments_delete' ) || current_user_can( 'crm_manage_payments' ) ),
			)
		);
	}

	/**
	 * Save payment and sync order status when fully paid.
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		if ( CRM_Client_Portal::is_client_user() ) {
			wp_send_json_error( array( 'message' => __( 'You cannot record payments from the client portal.', 'ds-prod-import-crm' ) ), 403 );
		}

		$id            = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::verify_module_save( 'crm_payments_create', 'crm_payments_edit', 'crm_manage_payments', $id );
		$client_id     = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$order_id      = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$payment_date  = isset( $_POST['payment_date'] ) ? crm_normalize_date( wp_unslash( $_POST['payment_date'] ) ) : '';
		$amount        = isset( $_POST['amount'] ) ? crm_parse_amount( wp_unslash( $_POST['amount'] ) ) : 0;
		$method        = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		$reference     = isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '';
		$notes         = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $client_id || ! $payment_date || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Client, date, and amount are required.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'payments' );
		$data  = array(
			'client_id'      => $client_id,
			'order_id'       => $order_id > 0 ? $order_id : null,
			'payment_date'   => $payment_date,
			'amount'         => $amount,
			'payment_method' => $method,
			'reference'      => $reference,
			'notes'          => $notes,
		);

		$labels = array(
			'payment_date'   => __( 'Date', 'ds-prod-import-crm' ),
			'amount'         => __( 'Amount', 'ds-prod-import-crm' ),
			'payment_method' => __( 'Method', 'ds-prod-import-crm' ),
			'reference'      => __( 'Reference', 'ds-prod-import-crm' ),
		);

		if ( $id ) {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			$data['updated_by'] = CRM_Audit::current_user_id();

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id ),
				array( '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update payment.', 'ds-prod-import-crm' ) ) );
			}

			$payment_number = $before['payment_number'] ?? '';
			$changes        = $before ? CRM_Audit::describe_changes( $before, $data, $labels ) : array();
			$meta           = array(
				'order_id' => $order_id > 0 ? $order_id : (int) ( $before['order_id'] ?? 0 ),
				'amount'   => $amount,
				'changes'  => $changes,
			);
			self::log_activity(
				'update',
				'payments',
				$id,
				sprintf(
					'Updated payment %s (%s)',
					$payment_number,
					crm_format_amount( $amount )
				),
				$meta
			);
		} else {
			$payment_number = crm_generate_sequence_number( 'PAY', 'payments', 'payment_number' );

			$inserted = $wpdb->insert(
				$table,
				array(
					'payment_number' => $payment_number,
					'client_id'      => $client_id,
					'order_id'       => $order_id > 0 ? $order_id : 0,
					'payment_date'   => $payment_date,
					'amount'         => $amount,
					'payment_method' => $method,
					'reference'      => $reference,
					'notes'          => $notes,
					'created_by'     => CRM_Audit::current_user_id(),
					'created_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' )
			);

			if ( ! $inserted ) {
				wp_send_json_error( array( 'message' => __( 'Failed to save payment.', 'ds-prod-import-crm' ) ) );
			}

			$id         = (int) $wpdb->insert_id;
			$order_ref  = '';
			if ( $order_id > 0 ) {
				$order_ref = (string) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT order_number FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
						$order_id
					)
				);
			}
			$desc = $order_ref
				? sprintf( 'Recorded payment %s of %s for %s', $payment_number, crm_format_amount( $amount ), $order_ref )
				: sprintf( 'Recorded payment %s of %s', $payment_number, crm_format_amount( $amount ) );
			self::log_activity(
				'create',
				'payments',
				$id,
				$desc,
				array(
					'order_id' => $order_id,
					'amount'   => $amount,
				)
			);
		}

		if ( $order_id ) {
			CRM_Order_Status::maybe_set_paid_status( $order_id );
		}

		CRM_Ledger::sync_client_paid_statuses( $client_id );

		wp_send_json_success(
			array(
				'message' => __( 'Payment saved successfully.', 'ds-prod-import-crm' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * Delete payment.
	 *
	 * @return void
	 */
	public static function delete_item() {
		if ( CRM_Client_Portal::is_client_user() ) {
			wp_send_json_error( array( 'message' => __( 'You cannot delete payments from the client portal.', 'ds-prod-import-crm' ) ), 403 );
		}

		self::verify_module_action( 'crm_payments_delete', 'crm_manage_payments' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'payments' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT client_id, order_id, payment_number, amount FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( $row && ! empty( $row['client_id'] ) ) {
			CRM_Ledger::sync_client_paid_statuses( (int) $row['client_id'] );
		}

		$order_id = (int) ( $row['order_id'] ?? 0 );
		self::log_activity(
			'delete',
			'payments',
			$id,
			sprintf(
				'Deleted payment %s (%s)',
				$row['payment_number'] ?? $id,
				crm_format_amount( $row['amount'] ?? 0 )
			),
			array(
				'order_id' => $order_id,
				'amount'   => (float) ( $row['amount'] ?? 0 ),
			)
		);

		wp_send_json_success( array( 'message' => __( 'Payment deleted.', 'ds-prod-import-crm' ) ) );
	}

	/**
	 * Ensure staff may view supplier payments in this module.
	 *
	 * @return void
	 */
	protected static function verify_supplier_payments_view() {
		self::verify_module_action( 'crm_payments_view', 'crm_manage_payments' );

		if ( ! CRM_Capability_Registry::user_can_view_supplier_payments() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to view supplier payments.', 'ds-prod-import-crm' ),
				),
				403
			);
		}
	}

	/**
	 * List payments to suppliers / cargo companies.
	 *
	 * @return void
	 */
	public static function supplier_list() {
		self::verify_supplier_payments_view();

		global $wpdb;

		$table           = crm_table( 'company_payments' );
		$companies_table = crm_table( 'companies' );
		$pagination      = self::pagination_from_request();
		$dates           = self::date_range_from_request();
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$period     = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '';
		if ( ! in_array( $period, array( 'today', 'week', 'month' ), true ) ) {
			$period = '';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(p.payment_number LIKE %s OR c.name LIKE %s OR p.reference LIKE %s OR p.payment_method LIKE %s OR p.notes LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $company_id ) {
			$where[]  = 'p.company_id = %d';
			$params[] = $company_id;
		}

		$base_sql    = implode( ' AND ', $where );
		$base_params = $params;

		if ( $period ) {
			$bounds   = CRM_Module_Summary::delivery_period_bounds( $period );
			$where[]  = 'p.payment_date >= %s';
			$params[] = $bounds['from'];
			$where[]  = 'p.payment_date <= %s';
			$params[] = $bounds['to'];
		} else {
			if ( $dates['date_from'] ) {
				$where[]  = 'p.payment_date >= %s';
				$params[] = $dates['date_from'];
			}

			if ( $dates['date_to'] ) {
				$where[]  = 'p.payment_date <= %s';
				$params[] = $dates['date_to'];
			}
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} p
			LEFT JOIN {$companies_table} c ON c.id = p.company_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT p.*, c.name AS company_name, c.company_type, c.phone AS company_phone
			FROM {$table} p
			LEFT JOIN {$companies_table} c ON c.id = p.company_id
			WHERE {$where_sql}
			ORDER BY p.payment_date DESC, p.id DESC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		wp_send_json_success(
			array(
				'items'       => $items ? $items : array(),
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
				'summary'     => CRM_Module_Summary::supplier_payments( $where_sql, $params, $base_sql, $base_params, $company_id ),
			)
		);
	}

	/**
	 * Companies for the supplier payment form and list filter.
	 *
	 * @return void
	 */
	public static function supplier_form_data() {
		self::verify_supplier_payments_view();

		global $wpdb;

		$companies = $wpdb->get_results(
			"SELECT id, name, company_type, phone, status FROM " . crm_table( 'companies' ) . " ORDER BY name ASC",
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'companies' => $companies ? $companies : array(),
			)
		);
	}

	/**
	 * Company balance preview while recording a supplier payment.
	 *
	 * @return void
	 */
	public static function supplier_preview() {
		self::verify_supplier_payments_view();

		global $wpdb;

		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		if ( $company_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid company ID.', 'ds-prod-import-crm' ) ) );
		}

		$company = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, company_type, phone, contact_person FROM ' . crm_table( 'companies' ) . ' WHERE id = %d',
				$company_id
			),
			ARRAY_A
		);

		if ( ! $company ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success(
			array(
				'company' => $company,
				'summary' => CRM_Ledger::get_company_summary( $company_id ),
			)
		);
	}

	/**
	 * Create or update a supplier payment.
	 *
	 * @return void
	 */
	public static function supplier_save() {
		Companies_Controller::payment_save();
	}

	/**
	 * Delete a supplier payment.
	 *
	 * @return void
	 */
	public static function supplier_delete() {
		Companies_Controller::payment_delete();
	}
}
