<?php
/**
 * Companies module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Cargo/shipping companies CRUD.
 */
class Companies_Controller extends CRM_Controller_Base {
	/**
	 * Allowed sort columns.
	 *
	 * @var array<int, string>
	 */
	private static $sort_columns = array( 'name', 'contact_person', 'phone', 'status', 'created_at' );

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_companies_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_companies_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_companies_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_companies_delete', array( __CLASS__, 'delete_item' ) );
		add_action( 'wp_ajax_crm_companies_ledger', array( __CLASS__, 'ledger' ) );
		add_action( 'wp_ajax_crm_companies_ledger_entries', array( __CLASS__, 'ledger_entries' ) );
		add_action( 'wp_ajax_crm_companies_payment_save', array( __CLASS__, 'payment_save' ) );
		add_action( 'wp_ajax_crm_companies_payment_delete', array( __CLASS__, 'payment_delete' ) );
		add_action( 'wp_ajax_crm_companies_bill_save', array( __CLASS__, 'bill_save' ) );
	}

	/**
	 * List companies with search, sort, pagination.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_module_action( 'crm_companies_view', 'crm_manage_companies' );

		global $wpdb;

		$table      = crm_table( 'companies' );
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
			$where[]  = '(name LIKE %s OR contact_person LIKE %s OR phone LIKE %s)';
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

		$list_sql = "SELECT id, name, company_type, contact_person, phone, address, notes, status, created_at, updated_at
			FROM {$table}
			WHERE {$where_sql}
			ORDER BY {$sort_by} {$sort_dir}
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages   = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		if ( $items ) {
			foreach ( $items as $index => $item ) {
				$summary = CRM_Ledger::get_company_summary( (int) $item['id'] );
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
				'summary'     => CRM_Module_Summary::companies( $where_sql, $params ),
				'financial'   => CRM_Ledger::get_total_supplier_summary(),
			)
		);
	}

	/**
	 * Get single company.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_module_action( 'crm_companies_view', 'crm_manage_companies' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid company ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'companies' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success( array( 'item' => $row ) );
	}

	/**
	 * Create or update company.
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::verify_module_save( 'crm_companies_create', 'crm_companies_edit', 'crm_manage_companies', $id );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Company name is required.', 'ds-prod-import-crm' ) ) );
		}

		$company_type = isset( $_POST['company_type'] ) && 'local_supplier' === sanitize_key( wp_unslash( $_POST['company_type'] ) )
			? 'local_supplier'
			: 'cargo';

		$data = array(
			'name'           => $name,
			'company_type'   => $company_type,
			'contact_person' => isset( $_POST['contact_person'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_person'] ) ) : '',
			'phone'          => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'address'        => isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '',
			'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'status'         => isset( $_POST['status'] ) && 'inactive' === sanitize_key( wp_unslash( $_POST['status'] ) ) ? 'inactive' : 'active',
			'updated_at'     => current_time( 'mysql' ),
		);

		$table  = crm_table( 'companies' );
		$labels = array(
			'name'           => __( 'Name', 'ds-prod-import-crm' ),
			'company_type'   => __( 'Type', 'ds-prod-import-crm' ),
			'contact_person' => __( 'Contact', 'ds-prod-import-crm' ),
			'phone'          => __( 'Phone', 'ds-prod-import-crm' ),
			'address'        => __( 'Address', 'ds-prod-import-crm' ),
			'status'         => __( 'Status', 'ds-prod-import-crm' ),
		);

		if ( $id ) {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			$data['updated_by'] = CRM_Audit::current_user_id();

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update company.', 'ds-prod-import-crm' ) ) );
			}

			$changes = $before ? CRM_Audit::describe_changes( $before, $data, $labels ) : array();
			self::log_activity(
				'update',
				'companies',
				$id,
				sprintf( 'Updated company: %s', $name ),
				array( 'changes' => $changes )
			);

			wp_send_json_success(
				array(
					'message' => __( 'Company updated successfully.', 'ds-prod-import-crm' ),
					'id'      => $id,
				)
			);
		}

		$data['created_at'] = current_time( 'mysql' );
		$data['created_by'] = CRM_Audit::current_user_id();

		$inserted = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create company.', 'ds-prod-import-crm' ) ) );
		}

		$new_id = (int) $wpdb->insert_id;
		self::log_activity( 'create', 'companies', $new_id, sprintf( 'Created company: %s', $name ) );

		wp_send_json_success(
			array(
				'message' => __( 'Company created successfully.', 'ds-prod-import-crm' ),
				'id'      => $new_id,
			)
		);
	}

	/**
	 * Delete company when not referenced.
	 *
	 * @return void
	 */
	public static function delete_item() {
		self::verify_module_action( 'crm_companies_delete', 'crm_manage_companies' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid company ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'companies' );
		$name  = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) );

		if ( ! $name ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'ds-prod-import-crm' ) ) );
		}

		$receive_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'warehouse_receives' ) . ' WHERE company_id = %d',
				$id
			)
		);

		if ( $receive_count > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot delete: this company has warehouse receive records. Set status to inactive instead.', 'ds-prod-import-crm' ),
				)
			);
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete company.', 'ds-prod-import-crm' ) ) );
		}

		self::log_activity( 'delete', 'companies', $id, sprintf( 'Deleted company: %s', $name ) );

		wp_send_json_success(
			array(
				'message' => __( 'Company deleted successfully.', 'ds-prod-import-crm' ),
			)
		);
	}

	/**
	 * Supplier/importer ledger header: company + totals only.
	 *
	 * @return void
	 */
	public static function ledger() {
		self::verify_request_any(
			array(
				'crm_companies_view',
				'crm_manage_companies',
				'crm_billing_view',
				'crm_manage_billing',
			)
		);

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid company ID.', 'ds-prod-import-crm' ) ) );
		}

		$company = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . crm_table( 'companies' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		if ( ! $company ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success(
			array(
				'company'     => $company,
				'summary'     => CRM_Ledger::get_company_summary( $id ),
				'permissions' => array(
					'can_manage_billing' => CRM_Capability_Registry::user_can_manage_billing(),
					'can_record_payment' => CRM_Capability_Registry::user_can_manage_billing(),
				),
				'payments_url' => crm_payments_url( 'suppliers', $id ),
			)
		);
	}

	/**
	 * Paginated ledger tables (payments, receives, or bills).
	 *
	 * @return void
	 */
	public static function ledger_entries() {
		self::verify_request_any(
			array(
				'crm_companies_view',
				'crm_manage_companies',
				'crm_billing_view',
				'crm_manage_billing',
			)
		);

		global $wpdb;

		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$section    = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : 'payments';
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$pagination = self::pagination_from_request();
		$dates      = self::date_range_from_request();

		if ( $company_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid company ID.', 'ds-prod-import-crm' ) ) );
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . crm_table( 'companies' ) . ' WHERE id = %d', $company_id )
		);
		if ( $exists < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'ds-prod-import-crm' ) ) );
		}

		$allowed = array( 'payments', 'receives', 'bills' );
		if ( ! in_array( $section, $allowed, true ) ) {
			$section = 'payments';
		}

		$where  = array( '1=1' );
		$params = array( $company_id );

		if ( 'payments' === $section ) {
			$table    = crm_table( 'company_payments' );
			$where[0] = 'p.company_id = %d';
			$date_col = 'p.payment_date';
			if ( $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(p.payment_number LIKE %s OR p.payment_method LIKE %s OR p.reference LIKE %s OR p.notes LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
			if ( $dates['date_from'] ) {
				$where[]  = "{$date_col} >= %s";
				$params[] = $dates['date_from'];
			}
			if ( $dates['date_to'] ) {
				$where[]  = "{$date_col} <= %s";
				$params[] = $dates['date_to'];
			}
			$where_sql = implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} p WHERE {$where_sql}";
			$list_sql  = "SELECT p.* FROM {$table} p WHERE {$where_sql} ORDER BY p.payment_date DESC, p.id DESC LIMIT %d OFFSET %d";
		} elseif ( 'receives' === $section ) {
			$table         = crm_table( 'warehouse_receives' );
			$clients_table = crm_table( 'clients' );
			$ship_table    = crm_table( 'export_shipments' );
			$orders_table  = crm_table( 'orders' );
			$client_id     = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
			$where[0]      = 'r.company_id = %d';
			$date_col      = 'r.receive_date';
			// Source of truth: China shipment → order → client (not denormalized receive.client_id).
			$order_expr    = 'COALESCE(NULLIF(s.order_id, 0), NULLIF(r.order_id, 0))';

			$from_sql = "FROM {$table} r
				LEFT JOIN {$ship_table} s ON s.id = r.shipment_id
				LEFT JOIN {$orders_table} o ON o.id = {$order_expr}
				LEFT JOIN {$clients_table} cl ON cl.id = o.client_id";

			if ( $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(r.receive_number LIKE %s OR cl.name LIKE %s OR s.shipment_number LIKE %s OR o.order_number LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
			if ( $client_id > 0 ) {
				$where[]  = 'o.client_id = %d';
				$params[] = $client_id;
			}
			if ( $dates['date_from'] ) {
				$where[]  = "{$date_col} >= %s";
				$params[] = $dates['date_from'];
			}
			if ( $dates['date_to'] ) {
				$where[]  = "{$date_col} <= %s";
				$params[] = $dates['date_to'];
			}
			$where_sql = implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}";
			$list_sql  = "SELECT r.id, r.receive_number, r.receive_date, r.total_kg, r.shipping_bill,
				r.shipment_id, s.shipment_number,
				o.id AS order_id, o.order_number, o.client_id, cl.name AS client_name
				{$from_sql}
				WHERE {$where_sql}
				ORDER BY r.receive_date DESC, r.id DESC
				LIMIT %d OFFSET %d";
		} else {
			$table    = crm_table( 'company_bills' );
			$where[0] = 'b.company_id = %d';
			$date_col = 'b.bill_date';
			if ( $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(b.reference LIKE %s OR b.notes LIKE %s)';
				$params[] = $like;
				$params[] = $like;
			}
			if ( $dates['date_from'] ) {
				$where[]  = "{$date_col} >= %s";
				$params[] = $dates['date_from'];
			}
			if ( $dates['date_to'] ) {
				$where[]  = "{$date_col} <= %s";
				$params[] = $dates['date_to'];
			}
			$where_sql = implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} b WHERE {$where_sql}";
			$list_sql  = "SELECT b.* FROM {$table} b WHERE {$where_sql} ORDER BY b.bill_date DESC, b.id DESC LIMIT %d OFFSET %d";
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		$filter_clients = array();
		if ( 'receives' === $section ) {
			$filter_clients = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT DISTINCT cl.id, cl.name
					FROM ' . crm_table( 'warehouse_receives' ) . ' r
					INNER JOIN ' . crm_table( 'export_shipments' ) . ' s ON s.id = r.shipment_id
					INNER JOIN ' . crm_table( 'orders' ) . ' o ON o.id = s.order_id
					INNER JOIN ' . crm_table( 'clients' ) . ' cl ON cl.id = o.client_id
					WHERE r.company_id = %d
					ORDER BY cl.name ASC',
					$company_id
				),
				ARRAY_A
			);
		}

		wp_send_json_success(
			array(
				'section'         => $section,
				'items'           => $items ? $items : array(),
				'total'           => $total,
				'page'            => $pagination['page'],
				'per_page'        => $pagination['per_page'],
				'total_pages'     => max( 1, $total_pages ),
				'filter_clients'  => $filter_clients ? $filter_clients : array(),
			)
		);
	}

	/**
	 * Record or update a payment to supplier/importer.
	 *
	 * @return void
	 */
	public static function payment_save() {
		self::verify_request_any(
			array(
				'crm_billing_edit',
				'crm_manage_billing',
			)
		);

		global $wpdb;

		$id            = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$company_id    = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$payment_date  = isset( $_POST['payment_date'] ) ? crm_normalize_date( wp_unslash( $_POST['payment_date'] ) ) : '';
		$amount        = isset( $_POST['amount'] ) ? crm_parse_amount( wp_unslash( $_POST['amount'] ) ) : 0;

		if ( ! $company_id || ! $payment_date || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Company, date, and amount are required.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'company_payments' );
		$data  = array(
			'company_id'     => $company_id,
			'payment_date'   => $payment_date,
			'amount'         => $amount,
			'payment_method' => isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '',
			'reference'      => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
			'notes'          => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		);

		if ( $id > 0 ) {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $before ) {
				wp_send_json_error( array( 'message' => __( 'Supplier payment not found.', 'ds-prod-import-crm' ) ) );
			}

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id ),
				array( '%d', '%s', '%f', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update payment.', 'ds-prod-import-crm' ) ) );
			}

			self::log_activity( 'update', 'company_payments', $id, sprintf( 'Updated supplier payment %s', $before['payment_number'] ?? $id ) );

			wp_send_json_success(
				array(
					'message' => __( 'Payment updated.', 'ds-prod-import-crm' ),
					'id'      => $id,
				)
			);
		}

		$payment_number = crm_generate_sequence_number( 'SPAY', 'company_payments', 'payment_number' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'payment_number' => $payment_number,
				'company_id'     => $company_id,
				'payment_date'   => $payment_date,
				'amount'         => $amount,
				'payment_method' => $data['payment_method'],
				'reference'      => $data['reference'],
				'notes'          => $data['notes'],
				'created_by'     => CRM_Audit::current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save payment.', 'ds-prod-import-crm' ) ) );
		}

		$id = (int) $wpdb->insert_id;
		self::log_activity( 'create', 'company_payments', $id, sprintf( 'Supplier payment %s', $payment_number ) );

		wp_send_json_success(
			array(
				'message' => __( 'Payment recorded.', 'ds-prod-import-crm' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * Delete a supplier payment.
	 *
	 * @return void
	 */
	public static function payment_delete() {
		self::verify_request_any(
			array(
				'crm_billing_edit',
				'crm_manage_billing',
			)
		);

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid payment ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'company_payments' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT payment_number, amount FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Supplier payment not found.', 'ds-prod-import-crm' ) ) );
		}

		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		self::log_activity(
			'delete',
			'company_payments',
			$id,
			sprintf( 'Deleted supplier payment %s (%s)', $row['payment_number'] ?? $id, crm_format_amount( $row['amount'] ?? 0 ) )
		);

		wp_send_json_success( array( 'message' => __( 'Payment deleted.', 'ds-prod-import-crm' ) ) );
	}

	/**
	 * Manual bill from supplier (non-receive).
	 *
	 * @return void
	 */
	public static function bill_save() {
		self::verify_request_any(
			array(
				'crm_billing_edit',
				'crm_manage_billing',
			)
		);

		global $wpdb;

		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$bill_date  = isset( $_POST['bill_date'] ) ? crm_normalize_date( wp_unslash( $_POST['bill_date'] ) ) : '';
		$amount     = isset( $_POST['amount'] ) ? crm_parse_amount( wp_unslash( $_POST['amount'] ) ) : 0;

		if ( ! $company_id || ! $bill_date || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Company, date, and amount are required.', 'ds-prod-import-crm' ) ) );
		}

		$wpdb->insert(
			crm_table( 'company_bills' ),
			array(
				'company_id' => $company_id,
				'bill_date'  => $bill_date,
				'amount'     => $amount,
				'reference'  => isset( $_POST['reference'] ) ? sanitize_text_field( wp_unslash( $_POST['reference'] ) ) : '',
				'notes'      => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
				'created_by' => CRM_Audit::current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%f', '%s', '%s', '%d', '%s' )
		);

		wp_send_json_success( array( 'message' => __( 'Bill recorded.', 'ds-prod-import-crm' ) ) );
	}
}
