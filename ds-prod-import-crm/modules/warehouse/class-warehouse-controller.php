<?php
/**
 * Warehouse receive module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Cargo receive entries and stock intake (driven by China export shipments).
 */
class Warehouse_Controller extends CRM_Controller_Base {
	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_warehouse_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_warehouse_awaiting', array( __CLASS__, 'list_awaiting' ) );
		add_action( 'wp_ajax_crm_warehouse_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_warehouse_shipment_for_receive', array( __CLASS__, 'get_shipment_for_receive' ) );
		add_action( 'wp_ajax_crm_warehouse_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_warehouse_form_data', array( __CLASS__, 'form_data' ) );
		add_action( 'wp_ajax_crm_warehouse_products_search', array( __CLASS__, 'products_search' ) );
		add_action( 'wp_ajax_crm_warehouse_void', array( __CLASS__, 'void_item' ) );
	}

	/**
	 * Companies + clients for receive form and list filters.
	 *
	 * @return void
	 */
	public static function form_data() {
		self::verify_request_any( array( 'crm_stock_view', 'crm_stock_receive', 'crm_receive_stock', 'crm_view_stock' ) );

		global $wpdb;

		$companies = $wpdb->get_results(
			'SELECT id, name FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		$clients = $wpdb->get_results(
			'SELECT id, name FROM ' . crm_table( 'clients' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'companies' => $companies ? $companies : array(),
				'clients'   => $clients ? $clients : array(),
			)
		);
	}

	/**
	 * Search products for receive line items (manual receive).
	 *
	 * @return void
	 */
	public static function products_search() {
		self::verify_module_action( 'crm_stock_receive', 'crm_receive_stock' );

		global $wpdb;

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( strlen( $search ) < 1 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$table = crm_table( 'products' );
		$like  = '%' . $wpdb->esc_like( $search ) . '%';

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, sku, unit_price, purchase_rate, color, size,
				COALESCE(NULLIF(thumbnail_url, ''), image_url) AS image_url
				FROM {$table}
				WHERE name LIKE %s OR sku LIKE %s
				ORDER BY CASE WHEN name LIKE %s OR sku LIKE %s THEN 0 ELSE 1 END, name ASC
				LIMIT 20",
				$like,
				$like,
				$wpdb->esc_like( $search ) . '%',
				$wpdb->esc_like( $search ) . '%'
			),
			ARRAY_A
		);

		wp_send_json_success( array( 'items' => $items ? $items : array() ) );
	}

	/**
	 * China export shipments still awaiting warehouse receive (full or partial).
	 *
	 * @return void
	 */
	public static function list_awaiting() {
		self::verify_request_any( array( 'crm_stock_view', 'crm_stock_receive', 'crm_receive_stock', 'crm_view_stock' ) );

		global $wpdb;

		$ship_table    = crm_table( 'export_shipments' );
		$items_table   = crm_table( 'export_shipment_items' );
		$company_table = crm_table( 'companies' );
		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$pagination    = self::pagination_from_request();
		$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$company_id    = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$client_id     = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$sort_by       = isset( $_POST['sort_by'] ) ? sanitize_key( wp_unslash( $_POST['sort_by'] ) ) : 'ship_date';
		$sort_dir      = crm_sort_direction( isset( $_POST['sort_dir'] ) ? wp_unslash( $_POST['sort_dir'] ) : 'DESC' );
		$allowed_sort  = array( 'shipment_number', 'ship_date', 'company_name', 'client_name', 'order_number', 'status' );
		$sort_by       = in_array( $sort_by, $allowed_sort, true ) ? $sort_by : 'ship_date';

		$sort_map = array(
			'shipment_number' => 's.shipment_number',
			'ship_date'       => 's.ship_date',
			'company_name'    => 'co.name',
			'client_name'     => 'cl.name',
			'order_number'    => 'o.order_number',
			'status'          => 's.status',
		);
		$sort_column = $sort_map[ $sort_by ];

		$where  = array( "s.status IN ('in_transit','partially_received')" );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.shipment_number LIKE %s OR co.name LIKE %s OR cl.name LIKE %s OR o.order_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $company_id > 0 ) {
			$where[]  = 's.company_id = %d';
			$params[] = $company_id;
		}

		if ( $client_id > 0 ) {
			$where[]  = 'o.client_id = %d';
			$params[] = $client_id;
		}

		$where_sql = implode( ' AND ', $where );

		$list_sql = "SELECT s.id, s.shipment_number, s.company_id, s.order_id, s.ship_date, s.status, s.total_kg,
			co.name AS company_name, o.order_number, o.client_id, cl.name AS client_name,
			(SELECT COUNT(*) FROM {$items_table} si WHERE si.shipment_id = s.id) AS item_count
			FROM {$ship_table} s
			LEFT JOIN {$company_table} co ON co.id = s.company_id
			LEFT JOIN {$orders_table} o ON o.id = s.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE {$where_sql}
			ORDER BY {$sort_column} {$sort_dir}";

		if ( ! empty( $params ) ) {
			$all_rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A );
		} else {
			$all_rows = $wpdb->get_results( $list_sql, ARRAY_A );
		}

		$filtered = array();
		foreach ( (array) $all_rows as $row ) {
			$progress = crm_shipment_receive_progress( (int) $row['id'] );
			if ( $progress['qty_remaining'] < 1 ) {
				continue;
			}
			$row['qty_shipped']      = $progress['qty_shipped'];
			$row['qty_received']     = $progress['qty_received'];
			$row['qty_missing']      = $progress['qty_missing'];
			$row['qty_remaining']    = $progress['qty_remaining'];
			$row['lines_pending']    = $progress['lines_pending'];
			$row['receive_form_url'] = crm_receive_form_url( (int) $row['id'] );
			$filtered[]              = $row;
		}

		$total       = count( $filtered );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;
		$offset      = $pagination['offset'];
		$items       = array_slice( $filtered, $offset, $pagination['per_page'] );

		if ( $items ) {
			crm_attach_export_shipment_product_previews( $items );
		}

		wp_send_json_success(
			array(
				'items'       => $items,
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
			)
		);
	}

	/**
	 * Load a China export shipment for the warehouse receive form.
	 *
	 * @return void
	 */
	public static function get_shipment_for_receive() {
		self::verify_module_action( 'crm_stock_receive', 'crm_receive_stock' );

		global $wpdb;

		$shipment_id = isset( $_POST['shipment_id'] ) ? absint( $_POST['shipment_id'] ) : 0;
		if ( $shipment_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shipment.', 'ds-prod-import-crm' ) ) );
		}

		$ship_table    = crm_table( 'export_shipments' );
		$items_table   = crm_table( 'export_shipment_items' );
		$company_table = crm_table( 'companies' );
		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$oi_table      = crm_table( 'order_items' );

		$shipment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, co.name AS company_name, o.order_number, o.client_id, cl.name AS client_name
				FROM {$ship_table} s
				LEFT JOIN {$company_table} co ON co.id = s.company_id
				LEFT JOIN {$orders_table} o ON o.id = s.order_id
				LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
				WHERE s.id = %d",
				$shipment_id
			),
			ARRAY_A
		);

		if ( ! $shipment ) {
			wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'void' === $shipment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'This shipment was voided and cannot be received.', 'ds-prod-import-crm' ) ) );
		}

		$lines = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT si.*, oi.product_id,
				" . crm_sql_product_image_url( 'p' ) . " AS product_image_url
				FROM {$items_table} si
				LEFT JOIN {$oi_table} oi ON oi.id = si.order_item_id
				LEFT JOIN " . crm_table( 'products' ) . " p ON p.id = oi.product_id
				WHERE si.shipment_id = %d
				ORDER BY si.id ASC",
				$shipment_id
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $lines as $line ) {
			$shipped   = (int) $line['quantity'];
			$received  = crm_shipment_item_qty_received( (int) $line['id'] );
			$missing   = crm_shipment_item_qty_missing( (int) $line['id'] );
			$remaining = crm_shipment_item_qty_remaining( $shipped, $received, $missing );

			$items[] = array(
				'export_shipment_item_id' => (int) $line['id'],
				'order_item_id'           => (int) $line['order_item_id'],
				'product_id'              => isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
				'product_name'            => $line['product_name'],
				'product_image_url'       => $line['product_image_url'] ?? '',
				'color'                   => $line['color'] ?? '',
				'size'                    => $line['size'] ?? '',
				'qty_shipped'             => $shipped,
				'qty_received'            => $received,
				'qty_missing'             => $missing,
				'qty_remaining'           => $remaining,
				'weight_kg_shipped'       => (float) $line['weight_kg'],
				'weight_kg_suggested'     => $shipped > 0 && $remaining > 0
					? crm_parse_weight( ( (float) $line['weight_kg'] ) * ( $remaining / $shipped ) )
					: 0,
			);
		}

		$progress = crm_shipment_receive_progress( $shipment_id );
		if ( $progress['qty_remaining'] < 1 ) {
			wp_send_json_error(
				array(
					'message' => __( 'This shipment is already fully received (or marked missing).', 'ds-prod-import-crm' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'shipment' => $shipment,
				'items'    => $items,
				'progress' => $progress,
			)
		);
	}

	/**
	 * List receive records.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_request_any( array( 'crm_stock_view', 'crm_stock_receive', 'crm_receive_stock', 'crm_view_stock' ) );

		global $wpdb;

		$receive_table  = crm_table( 'warehouse_receives' );
		$company_table  = crm_table( 'companies' );
		$clients_table  = crm_table( 'clients' );
		$ship_table     = crm_table( 'export_shipments' );
		$orders_table   = crm_table( 'orders' );
		$items_table    = crm_table( 'receive_items' );
		$pagination     = self::pagination_from_request();
		$dates          = self::date_range_from_request();
		$search         = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$company_id     = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$client_id      = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$sort_by        = isset( $_POST['sort_by'] ) ? sanitize_key( wp_unslash( $_POST['sort_by'] ) ) : 'receive_date';
		$sort_dir       = crm_sort_direction( isset( $_POST['sort_dir'] ) ? wp_unslash( $_POST['sort_dir'] ) : 'DESC' );
		$allowed_sort   = array( 'receive_number', 'receive_date', 'company_name', 'client_name', 'total_kg', 'shipping_bill' );
		$sort_by        = in_array( $sort_by, $allowed_sort, true ) ? $sort_by : 'receive_date';

		$sort_map = array(
			'receive_number' => 'r.receive_number',
			'receive_date'   => 'r.receive_date',
			'company_name'   => 'co.name',
			'client_name'    => 'cl.name',
			'total_kg'       => 'r.total_kg',
			'shipping_bill'  => 'r.shipping_bill',
		);
		$sort_column = $sort_map[ $sort_by ];

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(r.receive_number LIKE %s OR co.name LIKE %s OR cl.name LIKE %s OR s.shipment_number LIKE %s OR o.order_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $company_id > 0 ) {
			$where[]  = 'r.company_id = %d';
			$params[] = $company_id;
		}

		if ( $client_id > 0 ) {
			$where[]  = 'o.client_id = %d';
			$params[] = $client_id;
		}

		if ( $dates['date_from'] ) {
			$where[]  = 'r.receive_date >= %s';
			$params[] = $dates['date_from'];
		}

		if ( $dates['date_to'] ) {
			$where[]  = 'r.receive_date <= %s';
			$params[] = $dates['date_to'];
		}

		$where_sql = implode( ' AND ', $where );
		$from_sql  = "FROM {$receive_table} r
			LEFT JOIN {$company_table} co ON co.id = r.company_id
			LEFT JOIN {$ship_table} s ON s.id = r.shipment_id
			LEFT JOIN {$orders_table} o ON o.id = COALESCE(NULLIF(s.order_id, 0), NULLIF(r.order_id, 0))
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id";

		$count_sql = "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT r.id, r.receive_number, r.company_id, r.shipment_id,
			co.name AS company_name, cl.name AS client_name, o.client_id, s.shipment_number, o.order_number,
			r.receive_date, r.total_kg, r.shipping_bill, r.notes, r.created_at,
			(SELECT COUNT(*) FROM {$items_table} ri WHERE ri.receive_id = r.id) AS item_count,
			(SELECT COALESCE(SUM(ri.missing_quantity), 0) FROM {$items_table} ri WHERE ri.receive_id = r.id) AS missing_qty
			{$from_sql}
			WHERE {$where_sql}
			ORDER BY {$sort_column} {$sort_dir}
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		if ( $items ) {
			crm_attach_receive_product_previews( $items );
		}

		wp_send_json_success(
			array(
				'items'       => $items ? $items : array(),
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
				'summary'     => CRM_Module_Summary::warehouse( $where_sql, $params ),
			)
		);
	}

	/**
	 * Get receive with line items.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_request_any( array( 'crm_stock_view', 'crm_stock_receive', 'crm_receive_stock', 'crm_view_stock' ) );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid receive ID.', 'ds-prod-import-crm' ) ) );
		}

		$receive_table = crm_table( 'warehouse_receives' );
		$company_table = crm_table( 'companies' );
		$clients_table = crm_table( 'clients' );
		$ship_table    = crm_table( 'export_shipments' );
		$orders_table  = crm_table( 'orders' );
		$items_table   = crm_table( 'receive_items' );

		$receive = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, co.name AS company_name, cl.name AS client_name,
				s.shipment_number, o.order_number, o.client_id AS order_client_id
				FROM {$receive_table} r
				LEFT JOIN {$company_table} co ON co.id = r.company_id
				LEFT JOIN {$ship_table} s ON s.id = r.shipment_id
				LEFT JOIN {$orders_table} o ON o.id = COALESCE(NULLIF(s.order_id, 0), NULLIF(r.order_id, 0))
				LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
				WHERE r.id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $receive ) {
			wp_send_json_error( array( 'message' => __( 'Receive record not found.', 'ds-prod-import-crm' ) ) );
		}

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ri.*, " . crm_sql_product_image_url( 'p' ) . " AS product_image_url
				FROM {$items_table} ri
				LEFT JOIN " . crm_table( 'products' ) . " p ON p.id = ri.product_id
				WHERE ri.receive_id = %d ORDER BY ri.id ASC",
				$id
			),
			ARRAY_A
		);

		$can_void_cap = current_user_can( 'crm_stock_void' );
		$can_void     = $can_void_cap ? crm_receive_can_void( $id ) : new \WP_Error(
			'forbidden',
			__( 'You do not have permission to void stock receives.', 'ds-prod-import-crm' )
		);
		$void_message = is_wp_error( $can_void ) ? $can_void->get_error_message() : '';

		wp_send_json_success(
			array(
				'receive'      => $receive,
				'items'        => $items ? $items : array(),
				'can_void'     => $can_void_cap && ! is_wp_error( $can_void ),
				'void_message' => $void_message,
			)
		);
	}

	/**
	 * Void receive — reverse stock and remove record.
	 *
	 * @return void
	 */
	public static function void_item() {
		self::verify_request( 'crm_stock_void' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid receive ID.', 'ds-prod-import-crm' ) ) );
		}

		$receive_table = crm_table( 'warehouse_receives' );
		$items_table   = crm_table( 'receive_items' );

		$receive = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, receive_number, shipment_id FROM {$receive_table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $receive ) {
			wp_send_json_error( array( 'message' => __( 'Receive record not found.', 'ds-prod-import-crm' ) ) );
		}

		$can_void = crm_receive_can_void( $id );
		if ( is_wp_error( $can_void ) ) {
			wp_send_json_error( array( 'message' => $can_void->get_error_message() ) );
		}

		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$items_table} WHERE receive_id = %d", $id ),
			ARRAY_A
		);

		self::begin_transaction();

		foreach ( $items as $item ) {
			$qty = (int) $item['quantity'];
			if ( $qty < 1 ) {
				continue;
			}

			$result = CRM_Stock::decrement(
				$item['product_name'],
				$item['color'] ?? '',
				$item['size'] ?? '',
				$qty
			);

			if ( is_wp_error( $result ) ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		$wpdb->delete( $items_table, array( 'receive_id' => $id ), array( '%d' ) );
		$wpdb->delete( $receive_table, array( 'id' => $id ), array( '%d' ) );

		self::commit_transaction();

		$shipment_id = isset( $receive['shipment_id'] ) ? (int) $receive['shipment_id'] : 0;
		if ( $shipment_id > 0 ) {
			crm_sync_shipment_receive_status( $shipment_id );
		}

		self::log_activity( 'void', 'warehouse', $id, sprintf( 'Voided receive %s', $receive['receive_number'] ) );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: receive number */
					__( 'Receive %s voided. Stock has been reversed.', 'ds-prod-import-crm' ),
					$receive['receive_number']
				),
			)
		);
	}

	/**
	 * Parse and validate receive line items from request.
	 *
	 * @param int $shipment_id When > 0, lines must map to export shipment items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_items_from_request( $shipment_id = 0 ) {
		$raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items = json_decode( $raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'items_required', __( 'Add at least one product line item.', 'ds-prod-import-crm' ) );
		}

		if ( $shipment_id > 0 ) {
			return self::parse_shipment_items_from_request( $shipment_id, $items );
		}

		$parsed = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$product_name = isset( $item['product_name'] ) ? sanitize_text_field( $item['product_name'] ) : '';
			$quantity     = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

			if ( '' === $product_name || $quantity < 1 ) {
				continue;
			}

			$weight_kg = isset( $item['weight_kg'] ) ? crm_parse_weight( $item['weight_kg'] ) : 0;
			if ( $weight_kg <= 0 ) {
				return new \WP_Error(
					'weight_required',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: enter weight in kg for each item.', 'ds-prod-import-crm' ),
						count( $parsed ) + 1
					)
				);
			}

			$shipping_rate = isset( $item['shipping_rate_per_kg'] ) ? crm_parse_amount( $item['shipping_rate_per_kg'] ) : 0;
			if ( $shipping_rate <= 0 ) {
				return new \WP_Error(
					'shipping_rate_required',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: enter shipping rate (BDT / kg).', 'ds-prod-import-crm' ),
						count( $parsed ) + 1
					)
				);
			}

			$line_shipping = crm_parse_amount( $weight_kg * $shipping_rate );

			$parsed[] = array(
				'export_shipment_item_id' => 0,
				'product_id'              => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'product_name'            => $product_name,
				'color'                   => isset( $item['color'] ) ? sanitize_text_field( $item['color'] ) : '',
				'size'                    => isset( $item['size'] ) ? sanitize_text_field( $item['size'] ) : '',
				'quantity'                => $quantity,
				'missing_quantity'        => 0,
				'weight_kg'               => $weight_kg,
				'shipping_rate_per_kg'    => $shipping_rate,
				'shipping_share'          => $line_shipping,
				'rate'                    => 0,
				'bill_amount'             => 0,
				'notes'                   => isset( $item['notes'] ) ? sanitize_textarea_field( $item['notes'] ) : '',
			);
		}

		if ( empty( $parsed ) ) {
			return new \WP_Error( 'items_invalid', __( 'Each line item needs a product name and quantity.', 'ds-prod-import-crm' ) );
		}

		foreach ( $parsed as $index => $item ) {
			if ( $item['product_id'] < 1 ) {
				return new \WP_Error(
					'product_catalog_required',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: pick a product from the catalog list (do not type a new name).', 'ds-prod-import-crm' ),
						$index + 1
					)
				);
			}

			$catalog = crm_get_catalog_product( $item['product_id'] );
			if ( ! $catalog ) {
				return new \WP_Error(
					'product_catalog_invalid',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: selected product is not in the catalog.', 'ds-prod-import-crm' ),
						$index + 1
					)
				);
			}

			$parsed[ $index ]['product_name'] = $catalog['name'];
		}

		return $parsed;
	}

	/**
	 * Parse receive lines against a China export shipment (supports missing qty).
	 *
	 * @param int                  $shipment_id Export shipment ID.
	 * @param array<int, mixed>    $items       Raw POST items.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_shipment_items_from_request( $shipment_id, array $items ) {
		global $wpdb;

		$ship_items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT si.*, oi.product_id
				FROM ' . crm_table( 'export_shipment_items' ) . ' si
				LEFT JOIN ' . crm_table( 'order_items' ) . ' oi ON oi.id = si.order_item_id
				WHERE si.shipment_id = %d',
				$shipment_id
			),
			ARRAY_A
		);

		if ( ! $ship_items ) {
			return new \WP_Error( 'shipment_empty', __( 'This shipment has no product lines.', 'ds-prod-import-crm' ) );
		}

		$by_id = array();
		foreach ( $ship_items as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		$parsed = array();
		$line_n = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$esi_id   = isset( $item['export_shipment_item_id'] ) ? absint( $item['export_shipment_item_id'] ) : 0;
			$quantity = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
			$missing  = isset( $item['missing_quantity'] ) ? absint( $item['missing_quantity'] ) : 0;

			if ( $esi_id < 1 || ( $quantity < 1 && $missing < 1 ) ) {
				continue;
			}

			++$line_n;

			if ( ! isset( $by_id[ $esi_id ] ) ) {
				return new \WP_Error(
					'shipment_line_invalid',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: product is not part of this shipment.', 'ds-prod-import-crm' ),
						$line_n
					)
				);
			}

			$ship_line = $by_id[ $esi_id ];
			$shipped   = (int) $ship_line['quantity'];
			$already_r = crm_shipment_item_qty_received( $esi_id );
			$already_m = crm_shipment_item_qty_missing( $esi_id );
			$remaining = crm_shipment_item_qty_remaining( $shipped, $already_r, $already_m );

			if ( ( $quantity + $missing ) > $remaining ) {
				return new \WP_Error(
					'qty_exceeds_remaining',
					sprintf(
						/* translators: 1: product name 2: remaining qty */
						__( '%1$s: receive + missing (%2$d) exceeds remaining qty (%3$d).', 'ds-prod-import-crm' ),
						$ship_line['product_name'],
						$quantity + $missing,
						$remaining
					)
				);
			}

			$weight_kg     = 0.0;
			$shipping_rate = 0.0;
			$line_shipping = 0.0;

			if ( $quantity > 0 ) {
				$weight_kg = isset( $item['weight_kg'] ) ? crm_parse_weight( $item['weight_kg'] ) : 0;
				if ( $weight_kg <= 0 ) {
					return new \WP_Error(
						'weight_required',
						sprintf(
							/* translators: %s: product name */
							__( '%s: enter weight (kg) for quantity received into stock.', 'ds-prod-import-crm' ),
							$ship_line['product_name']
						)
					);
				}

				$shipping_rate = isset( $item['shipping_rate_per_kg'] ) ? crm_parse_amount( $item['shipping_rate_per_kg'] ) : 0;
				if ( $shipping_rate <= 0 ) {
					return new \WP_Error(
						'shipping_rate_required',
						sprintf(
							/* translators: %s: product name */
							__( '%s: enter shipping rate (BDT / kg) for received stock.', 'ds-prod-import-crm' ),
							$ship_line['product_name']
						)
					);
				}

				$line_shipping = crm_parse_amount( $weight_kg * $shipping_rate );
			}

			$product_id = isset( $ship_line['product_id'] ) ? absint( $ship_line['product_id'] ) : 0;
			if ( $product_id < 1 && $quantity > 0 ) {
				return new \WP_Error(
					'product_link_missing',
					sprintf(
						/* translators: %s: product name */
						__( '%s: order line is not linked to a catalog product; cannot receive into stock.', 'ds-prod-import-crm' ),
						$ship_line['product_name']
					)
				);
			}

			$parsed[] = array(
				'export_shipment_item_id' => $esi_id,
				'product_id'              => $product_id,
				'product_name'            => $ship_line['product_name'],
				'color'                   => $ship_line['color'] ?? '',
				'size'                    => $ship_line['size'] ?? '',
				'quantity'                => $quantity,
				'missing_quantity'        => $missing,
				'weight_kg'               => $weight_kg,
				'shipping_rate_per_kg'    => $shipping_rate,
				'shipping_share'          => $line_shipping,
				'rate'                    => 0,
				'bill_amount'             => 0,
				'notes'                   => isset( $item['notes'] ) ? sanitize_textarea_field( $item['notes'] ) : '',
			);
		}

		if ( empty( $parsed ) ) {
			return new \WP_Error(
				'items_invalid',
				__( 'Enter received and/or missing quantity for at least one product.', 'ds-prod-import-crm' )
			);
		}

		return $parsed;
	}

	/**
	 * Create receive entry and update stock.
	 *
	 * @return void
	 */
	public static function save_item() {
		self::verify_module_action( 'crm_stock_receive', 'crm_receive_stock' );

		global $wpdb;

		$shipment_id = isset( $_POST['shipment_id'] ) ? absint( $_POST['shipment_id'] ) : 0;
		$company_id  = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$order_id    = 0;
		$client_id   = 0;

		if ( $shipment_id > 0 ) {
			$shipment = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, company_id, order_id, status FROM ' . crm_table( 'export_shipments' ) . ' WHERE id = %d',
					$shipment_id
				),
				ARRAY_A
			);

			if ( ! $shipment || 'void' === $shipment['status'] ) {
				wp_send_json_error( array( 'message' => __( 'Shipment not found or voided.', 'ds-prod-import-crm' ) ) );
			}

			$company_id = (int) $shipment['company_id'];
			$order_id   = (int) $shipment['order_id'];
			$client_id  = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT client_id FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
					$order_id
				)
			);

			$progress = crm_shipment_receive_progress( $shipment_id );
			if ( $progress['qty_remaining'] < 1 ) {
				wp_send_json_error( array( 'message' => __( 'This shipment is already fully received.', 'ds-prod-import-crm' ) ) );
			}
		}

		if ( ! $company_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a cargo company.', 'ds-prod-import-crm' ) ) );
		}

		$company_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'companies' ) . ' WHERE id = %d AND status = %s',
				$company_id,
				'active'
			)
		);

		if ( ! $company_exists ) {
			wp_send_json_error( array( 'message' => __( 'Selected company is not valid.', 'ds-prod-import-crm' ) ) );
		}

		$receive_date = isset( $_POST['receive_date'] ) ? crm_normalize_date( wp_unslash( $_POST['receive_date'] ) ) : '';
		if ( ! $receive_date ) {
			wp_send_json_error( array( 'message' => __( 'Receive date is required.', 'ds-prod-import-crm' ) ) );
		}

		$items = self::parse_items_from_request( $shipment_id );
		if ( is_wp_error( $items ) ) {
			wp_send_json_error( array( 'message' => $items->get_error_message() ) );
		}

		$total_kg      = 0;
		$shipping_bill = 0;
		$stock_qty     = 0;
		foreach ( $items as $item ) {
			$total_kg      += (float) $item['weight_kg'];
			$shipping_bill += (float) $item['shipping_share'];
			$stock_qty     += (int) $item['quantity'];
		}
		$total_kg      = crm_parse_weight( $total_kg );
		$shipping_bill = crm_parse_amount( $shipping_bill );

		$posted_shipping = isset( $_POST['shipping_bill'] ) ? crm_parse_amount( wp_unslash( $_POST['shipping_bill'] ) ) : 0;
		if ( $posted_shipping > 0 ) {
			$shipping_bill = $posted_shipping;
		}

		if ( $stock_qty > 0 && $shipping_bill <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Shipping bill must be greater than zero when stock is received.', 'ds-prod-import-crm' ) ) );
		}

		$avg_shipping_rate = $total_kg > 0 ? crm_parse_amount( $shipping_bill / $total_kg ) : 0;
		$notes             = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$receive_table  = crm_table( 'warehouse_receives' );
		$items_table    = crm_table( 'receive_items' );
		$receive_number = crm_generate_sequence_number( 'RCV', 'warehouse_receives', 'receive_number' );

		self::begin_transaction();

		$inserted = $wpdb->insert(
			$receive_table,
			array(
				'receive_number'       => $receive_number,
				'company_id'           => $company_id,
				'shipment_id'          => $shipment_id > 0 ? $shipment_id : 0,
				'order_id'             => $order_id > 0 ? $order_id : 0,
				'client_id'            => $client_id > 0 ? $client_id : 0,
				'receive_date'         => $receive_date,
				'total_kg'             => $total_kg,
				'shipping_rate_per_kg' => $avg_shipping_rate,
				'shipping_bill'        => $shipping_bill,
				'product_bill_total'   => 0,
				'notes'                => $notes,
				'created_by'           => CRM_Audit::current_user_id(),
				'created_at'           => current_time( 'mysql' ),
				'updated_at'           => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%f', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => __( 'Failed to save receive record.', 'ds-prod-import-crm' ) ) );
		}

		$receive_id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			$item_inserted = $wpdb->insert(
				$items_table,
				array(
					'receive_id'              => $receive_id,
					'export_shipment_item_id' => $item['export_shipment_item_id'] > 0 ? $item['export_shipment_item_id'] : 0,
					'product_id'              => $item['product_id'] > 0 ? $item['product_id'] : 0,
					'product_name'            => $item['product_name'],
					'color'                   => $item['color'],
					'size'                    => $item['size'],
					'quantity'                => $item['quantity'],
					'missing_quantity'        => $item['missing_quantity'],
					'weight_kg'               => $item['weight_kg'],
					'shipping_rate_per_kg'    => $item['shipping_rate_per_kg'],
					'rate'                    => $item['rate'],
					'bill_amount'             => $item['bill_amount'],
					'shipping_share'          => $item['shipping_share'],
					'notes'                   => $item['notes'],
					'created_at'              => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s' )
			);

			if ( ! $item_inserted ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => __( 'Failed to save receive items.', 'ds-prod-import-crm' ) ) );
			}

			if ( (int) $item['quantity'] < 1 ) {
				continue;
			}

			$stock_ok = CRM_Stock::increment(
				$item['product_id'],
				$item['product_name'],
				$item['color'],
				$item['size'],
				$item['quantity']
			);

			if ( ! $stock_ok ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => __( 'Failed to update warehouse stock.', 'ds-prod-import-crm' ) ) );
			}
		}

		self::commit_transaction();

		if ( $shipment_id > 0 ) {
			crm_sync_shipment_receive_status( $shipment_id );
		}

		self::log_activity(
			'create',
			'warehouse',
			$receive_id,
			sprintf(
				'Created receive %s with %d item(s)%s',
				$receive_number,
				count( $items ),
				$shipment_id > 0 ? ' from shipment #' . $shipment_id : ''
			)
		);

		wp_send_json_success(
			array(
				'message'        => __( 'Stock received successfully.', 'ds-prod-import-crm' ),
				'id'             => $receive_id,
				'receive_number' => $receive_number,
			)
		);
	}
}
