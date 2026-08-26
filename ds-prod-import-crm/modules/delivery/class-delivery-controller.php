<?php
/**
 * Customer delivery module.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Create deliveries against orders, decrement stock, update order status.
 */
class Delivery_Controller extends CRM_Controller_Base {
	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_deliveries_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_deliveries_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_deliveries_orders', array( __CLASS__, 'orders_for_delivery' ) );
		add_action( 'wp_ajax_crm_deliveries_order_lines', array( __CLASS__, 'order_lines' ) );
		add_action( 'wp_ajax_crm_deliveries_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_deliveries_void', array( __CLASS__, 'void_item' ) );
	}

	/**
	 * List deliveries with filters.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_module_action( 'crm_delivery_view', 'crm_manage_delivery' );

		global $wpdb;

		$table         = crm_table( 'deliveries' );
		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$pagination    = self::pagination_from_request();
		$dates         = self::date_range_from_request();
		$search        = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$order_id      = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$period        = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : '';
		if ( ! in_array( $period, array( 'today', 'week', 'month', 'all' ), true ) ) {
			$period = '';
		}
		if ( 'all' === $period ) {
			$period = '';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(d.delivery_number LIKE %s OR o.order_number LIKE %s OR cl.name LIKE %s OR d.receiver_name LIKE %s
				OR EXISTS (
					SELECT 1 FROM ' . crm_table( 'delivery_items' ) . ' di_s
					WHERE di_s.delivery_id = d.id AND di_s.product_name LIKE %s
				))';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $order_id ) {
			$where[]  = 'd.order_id = %d';
			$params[] = $order_id;
		}

		CRM_Client_Portal::apply_delivery_list_scope( $where, $params );

		// Base scope (no date/period) for period KPI cards + order filter options.
		$base_where  = $where;
		$base_params = $params;

		if ( $period ) {
			$bounds = CRM_Module_Summary::delivery_period_bounds( $period );
			if ( $bounds['from'] && $bounds['to'] ) {
				$where[]  = 'd.delivery_date >= %s';
				$params[] = $bounds['from'];
				$where[]  = 'd.delivery_date <= %s';
				$params[] = $bounds['to'];
			}
		} else {
			if ( $dates['date_from'] ) {
				$where[]  = 'd.delivery_date >= %s';
				$params[] = $dates['date_from'];
			}

			if ( $dates['date_to'] ) {
				$where[]  = 'd.delivery_date <= %s';
				$params[] = $dates['date_to'];
			}
		}

		$where_sql = implode( ' AND ', $where );
		$base_sql  = implode( ' AND ', $base_where );

		$count_sql = "SELECT COUNT(*) FROM {$table} d
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT d.*, o.order_number, cl.name AS client_name,
			(SELECT COUNT(*) FROM " . crm_table( 'delivery_items' ) . " di WHERE di.delivery_id = d.id) AS item_count
			FROM {$table} d
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$where_sql}
			ORDER BY d.delivery_date DESC, d.id DESC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		if ( $items ) {
			crm_attach_delivery_product_previews( $items );
		}

		wp_send_json_success(
			array(
				'items'         => $items ? $items : array(),
				'total'         => $total,
				'page'          => $pagination['page'],
				'per_page'      => $pagination['per_page'],
				'total_pages'   => max( 1, $total_pages ),
				'period'        => $period ? $period : 'all',
				'order_options' => self::order_filter_options( $base_sql, $base_params ),
				'summary'       => CRM_Module_Summary::deliveries( $where_sql, $params, $base_sql, $base_params ),
			)
		);
	}

	/**
	 * Orders that already have deliveries (for list filter dropdown).
	 *
	 * @param string            $where_sql Base WHERE (d/o/cl).
	 * @param array<int, mixed> $params    Bound params.
	 * @return array<int, array{id:int,label:string}>
	 */
	private static function order_filter_options( $where_sql, array $params ) {
		global $wpdb;

		$sql = 'SELECT DISTINCT o.id, o.order_number
			FROM ' . crm_table( 'deliveries' ) . ' d
			LEFT JOIN ' . crm_table( 'orders' ) . ' o ON o.id = d.order_id
			LEFT JOIN ' . crm_table( 'clients' ) . ' cl ON cl.id = d.client_id
			WHERE ' . $where_sql . '
			AND o.id IS NOT NULL
			ORDER BY o.order_date DESC, o.id DESC
			LIMIT 100';

		if ( ! empty( $params ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		$options = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id < 1 ) {
				continue;
			}
			$options[] = array(
				'id'    => $id,
				'label' => (string) ( $row['order_number'] ?? ( '#' . $id ) ),
			);
		}

		return $options;
	}

	/**
	 * Single delivery with line items.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_module_action( 'crm_delivery_view', 'crm_manage_delivery' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid delivery ID.', 'ds-prod-import-crm' ) ) );
		}

		$delivery = self::fetch_delivery_row( $id );
		if ( ! $delivery ) {
			wp_send_json_error( array( 'message' => __( 'Delivery not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! CRM_Client_Portal::user_can_view_delivery( $delivery ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this delivery.', 'ds-prod-import-crm' ) ) );
		}

		$is_client = CRM_Client_Portal::is_client_user();

		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT di.*, ' . crm_sql_product_image_url( 'p' ) . ' AS product_image_url
				FROM ' . crm_table( 'delivery_items' ) . ' di
				LEFT JOIN ' . crm_table( 'order_items' ) . ' oi ON oi.id = di.order_item_id
				LEFT JOIN ' . crm_table( 'products' ) . ' p ON p.id = oi.product_id
				WHERE di.delivery_id = %d ORDER BY di.id ASC',
				$id
			),
			ARRAY_A
		);

		if ( $is_client && $items ) {
			foreach ( $items as &$item ) {
				unset( $item['shipping_rate_per_kg'], $item['notes'] );
			}
			unset( $item );
		}

		$delivered_by_name = '';
		if ( ! $is_client && ! empty( $delivery['delivered_by'] ) ) {
			$user              = get_userdata( (int) $delivery['delivered_by'] );
			$delivered_by_name = $user ? $user->display_name : '';
		}

		if ( $is_client ) {
			unset( $delivery['notes'], $delivery['delivered_by'], $delivery['created_by'] );
		}

		$can_void = ! $is_client && (
			current_user_can( 'crm_delivery_void' ) || current_user_can( 'crm_manage_delivery' )
		);

		wp_send_json_success(
			array(
				'delivery'          => $delivery,
				'items'             => $items ? $items : array(),
				'delivered_by_name' => $delivered_by_name,
				'can_void'          => $can_void,
				'is_client'         => $is_client,
				'view_ui'           => self::delivery_view_ui(),
			)
		);
	}

	/**
	 * Client vs staff delivery detail UI flags.
	 *
	 * @return array<string, bool>
	 */
	private static function delivery_view_ui() {
		$is_client = CRM_Client_Portal::is_client_user();

		return array(
			'show_client'        => ! $is_client,
			'show_delivered_by'  => ! $is_client,
			'show_notes'         => ! $is_client,
			'show_shipping_rate' => ! $is_client,
			'show_void'          => ! $is_client,
		);
	}

	/**
	 * Void delivery — restore stock, remove record, resync order.
	 *
	 * @return void
	 */
	public static function void_item() {
		self::verify_module_action( 'crm_delivery_void', 'crm_manage_delivery' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid delivery ID.', 'ds-prod-import-crm' ) ) );
		}

		$delivery = self::fetch_delivery_row( $id );
		if ( ! $delivery ) {
			wp_send_json_error( array( 'message' => __( 'Delivery not found.', 'ds-prod-import-crm' ) ) );
		}

		$items_table      = crm_table( 'delivery_items' );
		$deliveries_table = crm_table( 'deliveries' );
		$order_items_table = crm_table( 'order_items' );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT di.*, oi.product_id
				FROM {$items_table} di
				LEFT JOIN {$order_items_table} oi ON oi.id = di.order_item_id
				WHERE di.delivery_id = %d",
				$id
			),
			ARRAY_A
		);

		$order_id = (int) $delivery['order_id'];

		self::begin_transaction();

		foreach ( $items as $item ) {
			CRM_Stock::increment(
				(int) ( $item['product_id'] ?? 0 ),
				$item['product_name'],
				$item['color'] ?? '',
				$item['size'] ?? '',
				(int) $item['quantity']
			);
		}

		$wpdb->delete( $items_table, array( 'delivery_id' => $id ), array( '%d' ) );
		$wpdb->delete( $deliveries_table, array( 'id' => $id ), array( '%d' ) );

		self::commit_transaction();

		crm_sync_client_order_shipping_bill( $order_id );

		CRM_Order_Status::sync_delivery_status( $order_id );
		CRM_Order_Status::maybe_set_paid_status( $order_id );

		self::log_activity(
			'void',
			'delivery',
			$id,
			sprintf( 'Voided delivery %s for order %s', $delivery['delivery_number'], $delivery['order_number'] ),
			array(
				'order_id' => $order_id,
			)
		);

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: delivery number */
					__( 'Delivery %s voided. Stock restored and order totals updated.', 'ds-prod-import-crm' ),
					$delivery['delivery_number']
				),
			)
		);
	}

	/**
	 * Search orders that still have quantity due (delivery form autocomplete).
	 *
	 * @return void
	 */
	public static function orders_for_delivery() {
		self::verify_module_action( 'crm_delivery_create', 'crm_manage_delivery' );

		global $wpdb;

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$prep   = crm_prepare_order_autocomplete_search( $search );
		if ( empty( $prep['ok'] ) ) {
			wp_send_json_success(
				array(
					'orders' => array(),
					'hint'   => $prep['hint'] ?? __( 'Type at least 2 characters to search orders.', 'ds-prod-import-crm' ),
				)
			);
		}

		$orders_table         = crm_table( 'orders' );
		$clients_table        = crm_table( 'clients' );
		$items_table          = crm_table( 'order_items' );
		$products_table       = crm_table( 'products' );
		$delivery_items_table = crm_table( 'delivery_items' );
		$limit                = max( 1, min( 25, (int) ( $prep['limit'] ?? 20 ) ) );
		$fetch_limit          = $limit + 1;
		$contains             = $prep['contains_like'] ?? ( '%' . $wpdb->esc_like( $search ) . '%' );
		$order_like           = $prep['order_number_like'] ?? $contains;

		$sql = "SELECT o.id, o.order_number, o.order_date, o.status, o.client_id,
			cl.name AS client_name, cl.phone AS client_phone,
			(SELECT COUNT(*) FROM {$items_table} oi_due
				WHERE oi_due.order_id = o.id
				AND oi_due.quantity > COALESCE((
					SELECT SUM(di.quantity) FROM {$delivery_items_table} di WHERE di.order_item_id = oi_due.id
				), 0)
			) AS lines_due
			FROM {$orders_table} o
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE o.status != 'cancelled'
			AND " . CRM_Order_Status::workflow_active_sql( 'o' ) . "
			AND EXISTS (
				SELECT 1 FROM {$items_table} oi
				WHERE oi.order_id = o.id
				AND oi.quantity > COALESCE((
					SELECT SUM(di.quantity) FROM {$delivery_items_table} di WHERE di.order_item_id = oi.id
				), 0)
			)
			AND (
				o.order_number LIKE %s
				OR cl.name LIKE %s
				OR cl.phone LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$items_table} oi_p
					LEFT JOIN {$products_table} p ON p.id = oi_p.product_id
					WHERE oi_p.order_id = o.id
					AND (oi_p.product_name LIKE %s OR p.name LIKE %s OR p.sku LIKE %s)
				)
			)
			ORDER BY o.order_date DESC, o.id DESC
			LIMIT %d";

		$orders = $wpdb->get_results(
			$wpdb->prepare( $sql, $order_like, $contains, $contains, $contains, $contains, $contains, $fetch_limit ),
			ARRAY_A
		);

		$truncated = false;
		if ( $orders && count( $orders ) > $limit ) {
			$truncated = true;
			$orders    = array_slice( $orders, 0, $limit );
		}

		wp_send_json_success(
			array(
				'orders'    => $orders ? $orders : array(),
				'truncated' => $truncated,
				'hint'      => $truncated
					? __( 'Showing the first matches. Keep typing to narrow the list.', 'ds-prod-import-crm' )
					: '',
			)
		);
	}

	/**
	 * Order line items with due qty and stock for delivery form.
	 *
	 * @return void
	 */
	public static function order_lines() {
		self::verify_module_action( 'crm_delivery_create', 'crm_manage_delivery' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Order is required.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_header( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'cancelled' === $order['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be delivered.', 'ds-prod-import-crm' ) ) );
		}

		if ( CRM_Order_Status::blocks_workflow( $order['status'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This order must be accepted by an admin before delivery.', 'ds-prod-import-crm' ) ) );
		}

		$lines = self::get_order_lines_with_due( $order_id );

		wp_send_json_success(
			array(
				'order' => $order,
				'lines' => $lines,
			)
		);
	}

	/**
	 * Create delivery (immutable — no edit/delete).
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::verify_module_save( 'crm_delivery_create', 'crm_delivery_edit', 'crm_manage_delivery', $id );

		$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$delivery_date  = isset( $_POST['delivery_date'] ) ? crm_normalize_date( wp_unslash( $_POST['delivery_date'] ) ) : '';
		$receiver_name  = isset( $_POST['receiver_name'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_name'] ) ) : '';
		$receiver_phone = isset( $_POST['receiver_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['receiver_phone'] ) ) : '';
		$receiver_addr  = isset( $_POST['receiver_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['receiver_address'] ) ) : '';
		$notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $order_id || ! $delivery_date ) {
			wp_send_json_error( array( 'message' => __( 'Order and delivery date are required.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_header( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'cancelled' === $order['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be delivered.', 'ds-prod-import-crm' ) ) );
		}

		if ( CRM_Order_Status::blocks_workflow( $order['status'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This order must be accepted by an admin before delivery.', 'ds-prod-import-crm' ) ) );
		}

		$lines_to_deliver = self::parse_items_from_request( $order_id );
		if ( is_wp_error( $lines_to_deliver ) ) {
			wp_send_json_error( array( 'message' => $lines_to_deliver->get_error_message() ) );
		}

		$total_kg         = 0;
		$shipping_bill    = 0;
		$order_items_table = crm_table( 'order_items' );

		foreach ( $lines_to_deliver as &$line ) {
			$ordered    = max( 1, (int) $line['quantity_ordered'] );
			$stored     = (float) ( $line['order_line_weight_kg'] ?? 0 );
			$product_id = (int) ( $line['product_id'] ?? 0 );

			if ( $stored <= 0 ) {
				$line_weight = crm_order_line_weight_kg(
					0,
					$ordered,
					$product_id,
					$line['product_name'],
					$line['color'] ?? '',
					$line['size'] ?? ''
				);

				if ( $line_weight > 0 ) {
					$wpdb->update(
						$order_items_table,
						array( 'weight_kg' => $line_weight ),
						array( 'id' => (int) $line['order_item_id'] ),
						array( '%f' ),
						array( '%d' )
					);
					$line['order_line_weight_kg'] = $line_weight;
				}
			}

			$total_kg      += (float) $line['weight_kg'];
			$shipping_bill += (float) $line['shipping_share'];
		}
		unset( $line );

		$total_kg      = crm_parse_weight( $total_kg );
		$shipping_bill = crm_parse_amount( $shipping_bill );

		$delivery_number = crm_generate_sequence_number( 'DLV', 'deliveries', 'delivery_number' );
		$deliveries_table = crm_table( 'deliveries' );
		$items_table      = crm_table( 'delivery_items' );

		self::begin_transaction();

		$inserted = $wpdb->insert(
			$deliveries_table,
			array(
				'delivery_number'  => $delivery_number,
				'order_id'         => $order_id,
				'client_id'        => (int) $order['client_id'],
				'delivery_date'    => $delivery_date,
				'delivered_by'     => get_current_user_id(),
				'receiver_name'    => $receiver_name,
				'receiver_phone'   => $receiver_phone,
				'receiver_address' => $receiver_addr,
				'total_kg'         => $total_kg,
				'shipping_bill'    => max( 0, $shipping_bill ),
				'notes'            => $notes,
				'created_by'       => CRM_Audit::current_user_id(),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => __( 'Failed to save delivery.', 'ds-prod-import-crm' ) ) );
		}

		$delivery_id = (int) $wpdb->insert_id;

		foreach ( $lines_to_deliver as $line ) {
			$item_inserted = $wpdb->insert(
				$items_table,
				array(
					'delivery_id'   => $delivery_id,
					'order_item_id' => $line['order_item_id'],
					'product_name'  => $line['product_name'],
					'color'         => $line['color'],
					'size'          => $line['size'],
					'quantity'      => $line['deliver_qty'],
					'weight_kg'     => $line['weight_kg'],
					'shipping_rate_per_kg' => $line['shipping_rate_per_kg'],
					'shipping_share'       => $line['shipping_share'],
					'notes'         => $line['notes'],
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%s', '%s' )
			);

			if ( ! $item_inserted ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => __( 'Failed to save delivery items.', 'ds-prod-import-crm' ) ) );
			}

			$stock_result = CRM_Stock::decrement_for_delivery(
				(int) ( $line['product_id'] ?? 0 ),
				$line['product_name'],
				$line['color'],
				$line['size'],
				$line['deliver_qty']
			);

			if ( is_wp_error( $stock_result ) ) {
				self::rollback_transaction();
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: product name, 2: error */
							__( 'Stock error for %1$s: %2$s', 'ds-prod-import-crm' ),
							$line['product_name'],
							$stock_result->get_error_message()
						),
					)
				);
			}
		}

		self::commit_transaction();

		crm_sync_client_order_shipping_bill( $order_id );

		CRM_Order_Status::sync_delivery_status( $order_id );

		$deliver_qty = 0;
		foreach ( $lines_to_deliver as $line ) {
			$deliver_qty += (int) ( $line['deliver_qty'] ?? 0 );
		}

		self::log_activity(
			'create',
			'delivery',
			$delivery_id,
			sprintf(
				'Created delivery %s for order %s (%d pcs, %s shipping)',
				$delivery_number,
				$order['order_number'],
				$deliver_qty,
				crm_format_amount( $shipping_bill )
			),
			array(
				'order_id'      => $order_id,
				'quantity'      => $deliver_qty,
				'shipping_bill' => $shipping_bill,
				'total_kg'      => $total_kg,
			)
		);

		wp_send_json_success(
			array(
				'message'         => __( 'Delivery saved successfully.', 'ds-prod-import-crm' ),
				'id'              => $delivery_id,
				'delivery_number' => $delivery_number,
			)
		);
	}

	/**
	 * Parse delivery line items from JSON request.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_items_from_request( $order_id ) {
		$raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items = json_decode( $raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'items_required', __( 'Add at least one line with a delivery quantity.', 'ds-prod-import-crm' ) );
		}

		$lines_by_id = array();
		foreach ( self::get_order_lines_with_due( $order_id ) as $line ) {
			$lines_by_id[ (int) $line['id'] ] = $line;
		}

		$parsed = array();

		foreach ( $items as $index => $item ) {
			$order_item_id = isset( $item['order_item_id'] ) ? absint( $item['order_item_id'] ) : 0;
			$deliver_qty   = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

			if ( $deliver_qty < 1 ) {
				continue;
			}

			if ( ! isset( $lines_by_id[ $order_item_id ] ) ) {
				return new \WP_Error(
					'invalid_line',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: invalid order item.', 'ds-prod-import-crm' ),
						$index + 1
					)
				);
			}

			$line = $lines_by_id[ $order_item_id ];

			if ( $deliver_qty > (int) $line['qty_due'] ) {
				return new \WP_Error(
					'qty_exceeds_due',
					sprintf(
						/* translators: 1: product name, 2: due qty */
						__( '%1$s: cannot deliver more than %2$d (quantity due).', 'ds-prod-import-crm' ),
						$line['product_name'],
						(int) $line['qty_due']
					)
				);
			}

			$ordered_qty   = max( 1, (int) $line['quantity'] );
			$product_id    = (int) ( $line['product_id'] ?? 0 );
			$default_rate  = (float) ( $line['shipping_rate_per_kg'] ?? 0 );
			$weight_kg     = isset( $item['weight_kg'] ) ? crm_parse_weight( $item['weight_kg'] ) : 0;
			$shipping_rate = isset( $item['shipping_rate_per_kg'] ) ? crm_parse_amount( $item['shipping_rate_per_kg'] ) : 0;

			if ( $weight_kg <= 0 ) {
				$weight_kg = crm_delivery_line_weight_kg(
					$line['weight_kg'],
					$ordered_qty,
					$deliver_qty,
					$product_id,
					$line['product_name'],
					$line['color'] ?? '',
					$line['size'] ?? ''
				);
			}

			if ( $shipping_rate <= 0 ) {
				$shipping_rate = $default_rate;
			}

			if ( $weight_kg <= 0 ) {
				return new \WP_Error(
					'weight_required',
					sprintf(
						/* translators: 1: product name */
						__( '%1$s: enter weight in kg for this delivery line.', 'ds-prod-import-crm' ),
						$line['product_name']
					)
				);
			}

			if ( $shipping_rate <= 0 ) {
				return new \WP_Error(
					'shipping_rate_required',
					sprintf(
						/* translators: 1: product name */
						__( '%1$s: enter shipping rate (BDT / kg).', 'ds-prod-import-crm' ),
						$line['product_name']
					)
				);
			}

			$shipping_share = crm_parse_amount( $weight_kg * $shipping_rate );

			$parsed[] = array(
				'order_item_id'        => $order_item_id,
				'product_id'           => $product_id,
				'product_name'         => $line['product_name'],
				'color'                => $line['color'],
				'size'                 => $line['size'],
				'order_line_weight_kg' => (float) $line['weight_kg'],
				'quantity_ordered'     => $ordered_qty,
				'deliver_qty'          => $deliver_qty,
				'weight_kg'            => $weight_kg,
				'shipping_rate_per_kg' => $shipping_rate,
				'shipping_share'       => $shipping_share,
				'notes'                => isset( $item['notes'] ) ? sanitize_text_field( $item['notes'] ) : '',
			);
		}

		if ( empty( $parsed ) ) {
			return new \WP_Error( 'items_required', __( 'Add at least one line with a delivery quantity.', 'ds-prod-import-crm' ) );
		}

		return $parsed;
	}

	/**
	 * Order items with delivered/due/stock.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_order_lines_with_due( $order_id ) {
		global $wpdb;

		$items_table          = crm_table( 'order_items' );
		$delivery_items_table = crm_table( 'delivery_items' );
		$stock_table          = crm_table( 'stock' );
		$products_table       = crm_table( 'products' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.*, " . crm_sql_product_image_url( 'p' ) . " AS product_image_url,
				COALESCE((
					SELECT SUM(di.quantity) FROM {$delivery_items_table} di WHERE di.order_item_id = oi.id
				), 0) AS qty_delivered
				FROM {$items_table} oi
				LEFT JOIN {$products_table} p ON p.id = oi.product_id
				WHERE oi.order_id = %d
				ORDER BY " . CRM_Order_Item_Priority::sql_order_by( 'oi' ),
				$order_id
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['qty_delivered'] = (int) $row['qty_delivered'];
			$row['qty_due']       = max( 0, crm_order_item_accepted_qty( $row ) - $row['qty_delivered'] );
			$row['weight_kg']     = crm_order_line_weight_kg(
				$row['weight_kg'] ?? 0,
				(int) $row['quantity'],
				(int) ( $row['product_id'] ?? 0 ),
				$row['product_name'],
				$row['color'] ?? '',
				$row['size'] ?? ''
			);

			$stock = CRM_Stock::get_availability(
				(int) ( $row['product_id'] ?? 0 ),
				$row['product_name'],
				$row['color'] ?? '',
				$row['size'] ?? ''
			);

			$row['stock_qty']          = $stock['variant'];
			$row['stock_product_total'] = $stock['total'];

			$ordered_qty = max( 1, (int) $row['quantity'] );
			$row['weight_per_unit']    = crm_parse_weight( (float) $row['weight_kg'] / $ordered_qty );
			$row['shipping_rate_per_kg'] = crm_receive_shipping_rate_per_kg(
				(int) ( $row['product_id'] ?? 0 ),
				$row['product_name'],
				$row['color'] ?? '',
				$row['size'] ?? ''
			);
			CRM_Order_Item_Priority::enrich( $row );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Order header row.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_order_header( $order_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT o.*, cl.name AS client_name, cl.phone AS client_phone, cl.address AS client_address
				FROM ' . crm_table( 'orders' ) . ' o
				LEFT JOIN ' . crm_table( 'clients' ) . ' cl ON cl.id = o.client_id
				WHERE o.id = %d',
				$order_id
			),
			ARRAY_A
		);
	}

	/**
	 * Delivery row with order/client labels.
	 *
	 * @param int $id Delivery ID.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_delivery_row( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT d.*, o.order_number, cl.name AS client_name
				FROM ' . crm_table( 'deliveries' ) . ' d
				LEFT JOIN ' . crm_table( 'orders' ) . ' o ON o.id = d.order_id
				LEFT JOIN ' . crm_table( 'clients' ) . ' cl ON cl.id = d.client_id
				WHERE d.id = %d',
				$id
			),
			ARRAY_A
		);
	}

	/**
	 * Deliveries for an order (used by orders module).
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_deliveries_for_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.id, d.delivery_number, d.delivery_date, d.total_kg, d.shipping_bill,
				d.receiver_name, d.receiver_phone,
				(SELECT COUNT(*) FROM ' . crm_table( 'delivery_items' ) . ' di WHERE di.delivery_id = d.id) AS item_count
				FROM ' . crm_table( 'deliveries' ) . ' d
				WHERE d.order_id = %d
				ORDER BY d.delivery_date DESC, d.id DESC',
				$order_id
			),
			ARRAY_A
		) ?: array();
	}
}
