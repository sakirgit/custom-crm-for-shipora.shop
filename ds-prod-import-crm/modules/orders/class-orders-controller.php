<?php
/**
 * Orders module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Customer orders CRUD.
 */
class Orders_Controller extends CRM_Controller_Base {
	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_orders_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_orders_list_filters', array( __CLASS__, 'list_filters' ) );
		add_action( 'wp_ajax_crm_orders_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_orders_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_orders_update_status', array( __CLASS__, 'update_status' ) );
		add_action( 'wp_ajax_crm_orders_form_data', array( __CLASS__, 'form_data' ) );
		add_action( 'wp_ajax_crm_orders_clients_search', array( __CLASS__, 'clients_search' ) );
		add_action( 'wp_ajax_crm_orders_products_search', array( __CLASS__, 'products_search' ) );
		add_action( 'wp_ajax_crm_orders_cancel', array( __CLASS__, 'cancel_order' ) );
		add_action( 'wp_ajax_crm_orders_accept', array( __CLASS__, 'accept_order' ) );
		add_action( 'wp_ajax_crm_orders_save_prices', array( __CLASS__, 'save_prices' ) );
	}

	/**
	 * Statuses and clients for the orders list filters.
	 *
	 * @return void
	 */
	public static function list_filters() {
		self::verify_request( 'crm_orders_view' );

		global $wpdb;

		$clients = array();

		if ( ! CRM_Client_Portal::is_client_user() || 'all' === CRM_Client_Portal::orders_scope() ) {
			$clients = $wpdb->get_results(
				"SELECT id, name, phone FROM " . crm_table( 'clients' ) . " WHERE status = 'active' ORDER BY name ASC",
				ARRAY_A
			);
		}

		wp_send_json_success(
			array(
				'statuses'        => CRM_Order_Status::get_all_active(),
				'tracking_steps'  => CRM_Order_Tracking::get_list_filter_options(),
				'clients'         => $clients ? $clients : array(),
			)
		);
	}

	/**
	 * Statuses for order forms.
	 *
	 * @return void
	 */
	public static function form_data() {
		self::verify_request_any( array( 'crm_orders_create', 'crm_orders_edit' ) );

		global $wpdb;

		$categories = $wpdb->get_results(
			'SELECT id, name FROM ' . crm_table( 'product_categories' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'statuses'   => CRM_Order_Status::get_all_active(),
				'categories' => $categories ? $categories : array(),
				'portal'     => array(
					'is_client_user' => CRM_Client_Portal::is_client_user(),
					'client_id'      => CRM_Client_Portal::get_linked_client_id(),
					'client'         => self::get_portal_client_profile(),
				),
			)
		);
	}

	/**
	 * Linked client profile for portal order forms.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function get_portal_client_profile() {
		$client_id = CRM_Client_Portal::get_linked_client_id();
		if ( $client_id < 1 ) {
			return null;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, phone, address FROM ' . crm_table( 'clients' ) . ' WHERE id = %d AND status = %s',
				$client_id,
				'active'
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Admin manual status change.
	 *
	 * @return void
	 */
	public static function update_status() {
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $id || ! $status ) {
			wp_send_json_error( array( 'message' => __( 'Order and status are required.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_row( $id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! CRM_Capability_Registry::user_can_change_order_status( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot change status on this order.', 'ds-prod-import-crm' ) ), 403 );
		}

		$result = CRM_Order_Status::set_order_status( $id, $status );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$old_status = sanitize_key( (string) ( $order['status'] ?? '' ) );
		if ( $old_status === $status ) {
			wp_send_json_success( array( 'message' => __( 'Order status unchanged.', 'ds-prod-import-crm' ) ) );
		}

		$map       = CRM_Order_Status::get_status_map();
		$old_label = $map[ $old_status ]['label'] ?? $old_status;
		$new_label = $map[ $status ]['label'] ?? $status;
		self::log_activity(
			'update',
			'orders',
			$id,
			sprintf( 'Status changed to %s', $new_label ),
			array(
				'changes' => CRM_Audit::describe_changes(
					array( 'status' => $old_label ),
					array( 'status' => $new_label ),
					array( 'status' => __( 'Status', 'ds-prod-import-crm' ) )
				),
				'source'  => 'manual',
			)
		);

		wp_send_json_success( array( 'message' => __( 'Order status updated.', 'ds-prod-import-crm' ) ) );
	}

	/**
	 * Accept order — set accepted qty + prices, then unlock workflow.
	 *
	 * Expects POST items JSON: [ { id, accepted_quantity, unit_price }, ... ]
	 * Covers every order line. Accepted qty is the final order commitment.
	 *
	 * @return void
	 */
	public static function accept_order() {
		self::verify_request( 'crm_orders_accept' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_row( $id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! CRM_Order_Status::blocks_workflow( $order['status'] ) ) {
			wp_send_json_error( array( 'message' => __( 'This order is not waiting for acceptance.', 'ds-prod-import-crm' ) ) );
		}

		$items_table = crm_table( 'order_items' );
		$db_lines    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, product_name, quantity, unit_price, accepted_quantity FROM {$items_table} WHERE order_id = %d",
				$id
			),
			ARRAY_A
		);

		if ( empty( $db_lines ) ) {
			wp_send_json_error( array( 'message' => __( 'This order has no line items.', 'ds-prod-import-crm' ) ) );
		}

		$raw            = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$submitted      = json_decode( $raw, true );
		$submitted_map  = array();

		if ( is_array( $submitted ) ) {
			foreach ( $submitted as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$item_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
				if ( $item_id < 1 ) {
					continue;
				}
				$submitted_map[ $item_id ] = $item;
			}
		}

		// Require an explicit acceptance payload when China office sends qty+price.
		if ( empty( $submitted_map ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Set accepted quantity and unit price for each product before approving.', 'ds-prod-import-crm' ),
				)
			);
		}

		$changes     = array();
		$order_bill  = 0.0;
		$total_accepted = 0;

		self::begin_transaction();

		foreach ( $db_lines as $line ) {
			$item_id     = (int) $line['id'];
			$ordered_qty = (int) $line['quantity'];
			$payload     = $submitted_map[ $item_id ] ?? null;

			if ( ! $payload ) {
				self::rollback_transaction();
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: product name */
							__( 'Missing approval data for “%s”.', 'ds-prod-import-crm' ),
							$line['product_name']
						),
					)
				);
			}

			$accepted_qty = isset( $payload['accepted_quantity'] )
				? absint( $payload['accepted_quantity'] )
				: ( isset( $payload['quantity'] ) ? absint( $payload['quantity'] ) : 0 );
			$unit_price   = isset( $payload['unit_price'] ) ? crm_parse_amount( $payload['unit_price'] ) : 0.0;

			if ( $accepted_qty > $ordered_qty ) {
				self::rollback_transaction();
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: product name, 2: ordered qty */
							__( 'Accepted quantity for “%1$s” cannot exceed ordered quantity (%2$d).', 'ds-prod-import-crm' ),
							$line['product_name'],
							$ordered_qty
						),
					)
				);
			}

			if ( $accepted_qty > 0 && $unit_price <= 0 ) {
				self::rollback_transaction();
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: product name */
							__( 'Set a unit price greater than zero for “%s”.', 'ds-prod-import-crm' ),
							$line['product_name']
						),
					)
				);
			}

			if ( 0 === $accepted_qty ) {
				$unit_price = 0.0;
			}

			$wpdb->update(
				$items_table,
				array(
					'accepted_quantity' => $accepted_qty,
					'unit_price'        => $unit_price,
				),
				array( 'id' => $item_id ),
				array( '%d', '%f' ),
				array( '%d' )
			);

			$changes[] = sprintf(
				'%s: ordered %d → accepted %d @ %s',
				$line['product_name'],
				$ordered_qty,
				$accepted_qty,
				number_format( (float) $unit_price, 2, '.', '' )
			);

			$order_bill     += $accepted_qty * (float) $unit_price;
			$total_accepted += $accepted_qty;
		}

		if ( $total_accepted < 1 ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => __( 'Accept at least one piece across the order.', 'ds-prod-import-crm' ) ) );
		}

		$wpdb->update(
			crm_table( 'client_bills' ),
			array( 'amount' => round( $order_bill, 2 ) ),
			array(
				'order_id'  => $id,
				'bill_type' => 'order_bill',
			),
			array( '%f' ),
			array( '%d', '%s' )
		);

		$now     = current_time( 'mysql' );
		$user_id = CRM_Audit::current_user_id();
		$approval_note = isset( $_POST['approval_note'] )
			? sanitize_textarea_field( wp_unslash( $_POST['approval_note'] ) )
			: '';

		$wpdb->update(
			crm_table( 'orders' ),
			array(
				'accepted_at'    => $now,
				'accepted_by'    => $user_id,
				'approval_note'  => $approval_note,
				'updated_by'     => $user_id,
				'updated_at'     => $now,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		$result = CRM_Order_Status::accept_order( $id );
		if ( is_wp_error( $result ) ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		self::commit_transaction();

		self::log_activity(
			'update',
			'orders',
			$id,
			sprintf( 'Approved order %s (accepted qty set)', $order['order_number'] ),
			array(
				'changes'        => $changes,
				'approval_note'  => $approval_note,
			)
		);

		wp_send_json_success(
			array(
				'message'       => __( 'Order approved with accepted quantities and prices. Next: confirm supply and ship.', 'ds-prod-import-crm' ),
				'order_bill'    => round( $order_bill, 2 ),
				'accepted_at'   => $now,
				'needs_pricing' => false,
			)
		);
	}

	/**
	 * Update unit prices on order lines (China office / staff pricing workflow).
	 *
	 * @return void
	 */
	public static function save_prices() {
		self::verify_request( 'crm_orders_edit' );

		global $wpdb;

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'ds-prod-import-crm' ) ) );
		}

		$existing = self::fetch_order_row( $order_id );
		if ( ! $existing ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! CRM_Capability_Registry::user_can_edit_order( $existing ) ) {
			wp_send_json_error(
				array(
					'message' => CRM_Capability_Registry::order_edit_denied_message( $existing ),
				),
				403
			);
		}

		if ( 'cancelled' === $existing['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be edited.', 'ds-prod-import-crm' ) ) );
		}

		if ( crm_count_order_deliveries( $order_id ) > 0 ) {
			wp_send_json_error( array( 'message' => __( 'Prices cannot change after a delivery exists.', 'ds-prod-import-crm' ) ) );
		}

		$raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'No price updates submitted.', 'ds-prod-import-crm' ) ) );
		}

		$items_table = crm_table( 'order_items' );
		$bills_table = crm_table( 'client_bills' );
		$before      = self::fetch_order_items_snapshot( $order_id );
		$changes     = array();
		$order_bill  = 0.0;

		self::begin_transaction();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id    = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
			$unit_price = isset( $item['unit_price'] ) ? crm_parse_amount( $item['unit_price'] ) : 0;

			if ( $item_id < 1 ) {
				continue;
			}

			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, order_id, product_name, quantity, unit_price FROM {$items_table} WHERE id = %d AND order_id = %d",
					$item_id,
					$order_id
				),
				ARRAY_A
			);

			if ( ! $row ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => __( 'Invalid order line.', 'ds-prod-import-crm' ) ) );
			}

			$old_price = number_format( (float) $row['unit_price'], 2, '.', '' );
			$new_price = number_format( (float) $unit_price, 2, '.', '' );

			if ( $old_price !== $new_price ) {
				$wpdb->update(
					$items_table,
					array( 'unit_price' => $unit_price ),
					array( 'id' => $item_id ),
					array( '%f' ),
					array( '%d' )
				);
				$changes[] = sprintf(
					'%s %s: %s → %s',
					$row['product_name'],
					__( 'unit price', 'ds-prod-import-crm' ),
					$old_price,
					$new_price
				);
			}

			$order_bill += crm_order_item_accepted_qty( $row ) * (float) $unit_price;
		}

		// Recalculate bill from all lines so partial payload cannot undercount.
		$order_bill = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(COALESCE(accepted_quantity, quantity) * unit_price), 0) FROM {$items_table} WHERE order_id = %d",
				$order_id
			)
		);

		$wpdb->update(
			$bills_table,
			array( 'amount' => round( $order_bill, 2 ) ),
			array(
				'order_id'  => $order_id,
				'bill_type' => 'order_bill',
			),
			array( '%f' ),
			array( '%d', '%s' )
		);

		$wpdb->update(
			crm_table( 'orders' ),
			array(
				'updated_by' => CRM_Audit::current_user_id(),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $order_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		self::commit_transaction();

		if ( ! empty( $changes ) ) {
			self::log_activity(
				'update',
				'orders',
				$order_id,
				sprintf( 'Updated prices on %s', $existing['order_number'] ),
				array( 'changes' => $changes )
			);
		}

		wp_send_json_success(
			array(
				'message'        => __( 'Prices saved.', 'ds-prod-import-crm' ),
				'needs_pricing'  => ! self::order_lines_have_prices( $order_id ),
				'order_bill'     => round( $order_bill, 2 ),
			)
		);
	}

	/**
	 * Search clients for order form.
	 *
	 * @return void
	 */
	public static function clients_search() {
		self::verify_request_any( array( 'crm_orders_create', 'crm_orders_edit' ) );

		global $wpdb;

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		if ( mb_strlen( $search ) < 2 ) {
			wp_send_json_success(
				array(
					'items' => array(),
					'hint'  => __( 'Type at least 2 characters to search clients.', 'ds-prod-import-crm' ),
				)
			);
		}

		$table = crm_table( 'clients' );
		$like  = '%' . $wpdb->esc_like( $search ) . '%';

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, phone FROM {$table} WHERE status = 'active' AND (name LIKE %s OR phone LIKE %s) ORDER BY name ASC LIMIT 15",
				$like,
				$like
			),
			ARRAY_A
		);

		wp_send_json_success( array( 'items' => $items ? $items : array() ) );
	}

	/**
	 * Search products for order lines.
	 *
	 * @return void
	 */
	public static function products_search() {
		self::verify_request_any( array( 'crm_orders_create', 'crm_orders_edit' ) );

		global $wpdb;

		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$min_length = 2;

		if ( mb_strlen( $search ) < $min_length ) {
			wp_send_json_success(
				array(
					'items' => array(),
					'hint'  => sprintf(
						/* translators: %d: minimum characters */
						__( 'Type at least %d characters to search products.', 'ds-prod-import-crm' ),
						$min_length
					),
				)
			);
		}

		$table = crm_table( 'products' );
		$like  = '%' . $wpdb->esc_like( $search ) . '%';

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, sku, unit_price, purchase_rate, color, size,
				COALESCE(NULLIF(thumbnail_url, ''), image_url) AS image_url
				FROM {$table}
				WHERE name LIKE %s OR sku LIKE %s
				ORDER BY name ASC LIMIT 20",
				$like,
				$like
			),
			ARRAY_A
		);

		wp_send_json_success( array( 'items' => $items ? $items : array() ) );
	}

	/**
	 * List orders.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_request( 'crm_orders_view' );

		global $wpdb;

		$orders_table = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$items_table  = crm_table( 'order_items' );
		$pagination   = self::pagination_from_request();
		$dates        = self::date_range_from_request();
		$search       = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$tracking     = isset( $_POST['tracking'] ) ? sanitize_key( wp_unslash( $_POST['tracking'] ) ) : '';
		$client_id    = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$sort_by      = isset( $_POST['sort_by'] ) ? sanitize_key( wp_unslash( $_POST['sort_by'] ) ) : 'order_date';
		$sort_dir     = crm_sort_direction( isset( $_POST['sort_dir'] ) ? wp_unslash( $_POST['sort_dir'] ) : 'DESC' );
		$allowed      = array( 'order_number', 'order_date', 'client_name', 'status', 'total_amount' );
		$sort_by      = in_array( $sort_by, $allowed, true ) ? $sort_by : 'order_date';
		$sort_column  = 'client_name' === $sort_by ? 'cl.name' : ( 'total_amount' === $sort_by ? 'total_amount' : 'o.' . $sort_by );

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(o.order_number LIKE %s OR cl.name LIKE %s OR cl.phone LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $status && CRM_Order_Status::is_valid_slug( $status ) ) {
			$where[]  = 'o.status = %s';
			$params[] = $status;
		}

		if ( $tracking ) {
			CRM_Order_Tracking::apply_list_filter( $tracking, $where );
		}

		if ( $client_id ) {
			$where[]  = 'o.client_id = %d';
			$params[] = $client_id;
		}

		if ( $dates['date_from'] ) {
			$where[]  = 'o.order_date >= %s';
			$params[] = $dates['date_from'];
		}

		if ( $dates['date_to'] ) {
			$where[]  = 'o.order_date <= %s';
			$params[] = $dates['date_to'];
		}

		CRM_Client_Portal::apply_order_list_scope( $where, $params );

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$orders_table} o
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT o.id, o.order_number, o.client_id, cl.name AS client_name, cl.phone AS client_phone, o.order_date, o.status, o.notes, o.created_by, o.created_at,
			COALESCE((
				SELECT SUM(COALESCE(oi.accepted_quantity, oi.quantity) * oi.unit_price) FROM {$items_table} oi WHERE oi.order_id = o.id
			), 0) AS total_amount,
			COALESCE((
				SELECT SUM(oi.weight_kg) FROM {$items_table} oi WHERE oi.order_id = o.id
			), 0) AS total_weight_kg,
			(SELECT COUNT(*) FROM {$items_table} oi2 WHERE oi2.order_id = o.id) AS item_count,
			(SELECT COUNT(*) FROM {$items_table} oi3 WHERE oi3.order_id = o.id AND oi3.delivery_priority = 'urgent') AS urgent_count,
			(SELECT COUNT(*) FROM " . crm_table( 'deliveries' ) . " d WHERE d.order_id = o.id) AS delivery_count
			FROM {$orders_table} o
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE {$where_sql}
			ORDER BY {$sort_column} {$sort_dir}
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		if ( $items ) {
			crm_attach_order_product_previews( $items );
			CRM_Order_Tracking::attach_to_list_items( $items );
			foreach ( $items as &$item ) {
				$item['can_edit']          = 'cancelled' !== $item['status'] && CRM_Capability_Registry::user_can_edit_order( $item );
				$item['can_edit_own_only'] = $item['can_edit'] && ! current_user_can( 'crm_orders_edit' );
				$item['workflow_blocked']  = CRM_Order_Status::blocks_workflow( $item['status'] );
				$item['can_accept']        = $item['workflow_blocked'] && CRM_Capability_Registry::user_can_accept_orders();
				$item                      = array_merge( $item, CRM_Ownership::creator_meta( $item['created_by'] ?? 0 ) );
			}
			unset( $item );
		}

		wp_send_json_success(
			array(
				'items'       => $items ? $items : array(),
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
				'summary'     => CRM_Module_Summary::orders( $where_sql, $params ),
			)
		);
	}

	/**
	 * Get order with items and summary.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_request( 'crm_orders_view' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'ds-prod-import-crm' ) ) );
		}

		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$items_table   = crm_table( 'order_items' );
		$delivery_items_table = crm_table( 'delivery_items' );

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT o.*, cl.name AS client_name, cl.phone AS client_phone
				FROM {$orders_table} o
				LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
				WHERE o.id = %d",
				$id
			),
			ARRAY_A
		);

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! CRM_Client_Portal::user_can_view_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this order.', 'ds-prod-import-crm' ) ), 403 );
		}

		$products_table = crm_table( 'products' );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.*, " . crm_sql_product_image_url( 'p' ) . " AS product_image_url,
				NULLIF(p.image_url, '') AS product_full_image_url,
				COALESCE((
					SELECT SUM(di.quantity) FROM {$delivery_items_table} di WHERE di.order_item_id = oi.id
				), 0) AS qty_delivered
				FROM {$items_table} oi
				LEFT JOIN {$products_table} p ON p.id = oi.product_id
				WHERE oi.order_id = %d
				ORDER BY " . CRM_Order_Item_Priority::sql_order_by( 'oi' ),
				$id
			),
			ARRAY_A
		);

		$order_bill = 0;
		foreach ( $items as &$item ) {
			$accepted_qty          = crm_order_item_accepted_qty( $item );
			$item['qty_ordered']   = (int) $item['quantity'];
			$item['accepted_quantity'] = array_key_exists( 'accepted_quantity', $item ) && null !== $item['accepted_quantity'] && '' !== $item['accepted_quantity']
				? (int) $item['accepted_quantity']
				: null;
			$item['qty_accepted']  = $accepted_qty;
			$item['qty_delivered'] = (int) $item['qty_delivered'];
			$item['qty_due']       = max( 0, $accepted_qty - $item['qty_delivered'] );
			$item['weight_kg']     = crm_order_line_weight_kg(
				$item['weight_kg'] ?? 0,
				$accepted_qty > 0 ? $accepted_qty : (int) $item['quantity'],
				(int) ( $item['product_id'] ?? 0 ),
				$item['product_name'],
				$item['color'] ?? '',
				$item['size'] ?? ''
			);
			$item['product_image_url'] = ! empty( $item['product_image_url'] ) ? esc_url_raw( $item['product_image_url'] ) : '';
			$item['product_full_image_url'] = ! empty( $item['product_full_image_url'] )
				? esc_url_raw( $item['product_full_image_url'] )
				: $item['product_image_url'];
			CRM_Order_Item_Priority::enrich( $item );
			$item['qty_exported']       = crm_order_item_qty_exported( (int) $item['id'] );
			$item['qty_to_export']      = max( 0, $accepted_qty - $item['qty_exported'] );
			$item['weight_exported_kg'] = crm_order_item_weight_exported( (int) $item['id'] );
			// Prefer actual shipped weight once China has submitted the line.
			if ( (float) $item['weight_exported_kg'] > 0 ) {
				$item['weight_kg'] = (float) $item['weight_exported_kg'];
			}
			$order_bill += $accepted_qty * (float) $item['unit_price'];
		}
		unset( $item );

		$deliveries       = Delivery_Controller::get_deliveries_for_order( $id );
		$delivery_count   = crm_count_order_deliveries( $id );
		$payment_count    = crm_count_order_payments( $id );
		$is_cancelled      = 'cancelled' === $order['status'];
		$workflow_blocked  = CRM_Order_Status::blocks_workflow( $order['status'] );
		$can_edit          = ! $is_cancelled && CRM_Capability_Registry::user_can_edit_order( $order );
		$edit_blocked_reason = '';

		if ( ! $can_edit && ! $is_cancelled && CRM_Ownership::is_own_record( $order['created_by'] ?? 0 ) && ! current_user_can( 'crm_orders_edit' ) ) {
			$edit_blocked_reason = CRM_Capability_Registry::order_edit_denied_message( $order );
		}

		$can_deliver         = ! $workflow_blocked && ! $is_cancelled;
		$can_record_export   = $can_deliver && current_user_can( 'crm_shipments_create' );
		$can_record_payment  = current_user_can( 'crm_payments_create' );
		$view_ui             = self::order_view_ui(
			$order,
			array(
				'workflow_blocked'   => $workflow_blocked,
				'can_deliver'        => $can_deliver,
				'can_record_export'  => $can_record_export,
				'can_record_payment' => $can_record_payment,
			)
		);

		wp_send_json_success(
			array(
				'order'            => $order,
				'items'            => $items ? $items : array(),
				'statuses'         => CRM_Order_Status::get_all_active(),
				'summary'          => CRM_Ledger::get_order_summary( $id ),
				'payments'         => self::get_order_payments( $id ),
				'deliveries'       => $deliveries,
				'export_shipments' => Shipments_Controller::get_shipments_for_order( $id ),
				'export_remaining' => Shipments_Controller::get_export_remaining_summary( $id ),
				'delivery_count'   => $delivery_count,
				'payment_count'    => $payment_count,
				'workflow_blocked' => $workflow_blocked,
				'can_accept'       => $workflow_blocked && CRM_Capability_Registry::user_can_accept_orders(),
				'can_edit'          => $can_edit,
				'can_edit_lines'    => $delivery_count < 1 && $can_edit,
				'can_cancel'        => $delivery_count < 1 && ! $is_cancelled && CRM_Capability_Registry::user_can_cancel_order( $order ),
				'can_change_status' => CRM_Capability_Registry::user_can_change_order_status( $order ),
				'can_deliver'       => $can_deliver,
				'can_record_export' => $can_record_export,
				'can_record_payment' => $can_record_payment,
				'shipment_form_url' => crm_shipment_form_url( $id ),
				'can_edit_own_only' => $can_edit && ! current_user_can( 'crm_orders_edit' ),
				'edit_blocked_reason' => $edit_blocked_reason,
				'view_ui'           => $view_ui,
				'creator'           => CRM_Ownership::creator_meta( $order['created_by'] ?? 0 ),
				'history'           => CRM_Audit::get_for_order( $id, 80 ),
				'tracking'            => CRM_Order_Tracking::for_order(
					$id,
					array_merge(
						$order,
						array( 'delivery_count' => $delivery_count )
					)
				),
				'needs_pricing'       => ! self::order_lines_have_prices( $id ),
				'view_url'            => crm_order_view_url( $id ),
			)
		);
	}

	/**
	 * Role-aware layout flags for the order details popup.
	 *
	 * @param array<string, mixed> $order   Order row.
	 * @param array<string, mixed> $context Permission flags.
	 * @return array<string, mixed>
	 */
	public static function order_view_ui( array $order, array $context = array() ) {
		$is_client = CRM_Client_Portal::is_client_user();

		if ( $is_client ) {
			return array(
				'mode'    => 'client',
				'columns' => array( 'product', 'priority', 'variant', 'quantity', 'accepted', 'exported', 'weight', 'delivered', 'due', 'unit_price', 'line_total' ),
				'meta'    => array(
					'date'         => true,
					'status'       => true,
					'total_weight' => true,
					'notes'        => true,
				),
				'sections' => array(
					'workflow'          => true,
					'line_items'        => true,
					'china_export'      => true,
					'deliveries'        => true,
					'deliveries_action' => false,
					'billing'           => true,
					'billing_mode'      => 'client',
					'payments'          => true,
					'payments_action'   => false,
					'activity'          => false,
				),
			);
		}

		$workflow_blocked = ! empty( $context['workflow_blocked'] );

		return array(
			'mode'    => 'staff',
			'columns' => array( 'product', 'priority', 'variant', 'quantity', 'accepted', 'weight', 'delivered', 'due', 'exported', 'to_export', 'unit_price', 'line_total' ),
			'meta'    => array(
				'client'       => true,
				'date'         => true,
				'status'       => true,
				'created_by'   => true,
				'total_weight' => true,
				'notes'        => true,
			),
			'sections' => array(
				'workflow'          => $workflow_blocked,
				'line_items'        => true,
				'china_export'      => true,
				'deliveries'        => true,
				'deliveries_action' => ! empty( $context['can_deliver'] ) && current_user_can( 'crm_delivery_create' ),
				'billing'           => true,
				'billing_mode'      => 'full',
				'payments'          => current_user_can( 'crm_payments_view' ),
				'payments_action'   => ! empty( $context['can_record_payment'] ),
				'activity'          => true,
			),
		);
	}

	/**
	 * Cancel order (no deliveries).
	 *
	 * @return void
	 */
	public static function cancel_order() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_row( $id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! CRM_Capability_Registry::user_can_cancel_order( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot cancel this order.', 'ds-prod-import-crm' ) ), 403 );
		}

		if ( crm_count_order_deliveries( $id ) > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot cancel: this order has deliveries. Void deliveries first.', 'ds-prod-import-crm' ),
				)
			);
		}

		$result = CRM_Order_Status::set_order_status( $id, 'cancelled' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$by_client = CRM_Client_Portal::is_client_user();
		self::log_activity(
			'void',
			'orders',
			$id,
			$by_client ? 'Order cancelled by client' : 'Order cancelled'
		);

		wp_send_json_success( array( 'message' => __( 'Order cancelled.', 'ds-prod-import-crm' ) ) );
	}

	/**
	 * Payments linked to an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_order_payments( $order_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, payment_number, payment_date, amount, payment_method, reference FROM ' . crm_table( 'payments' ) . ' WHERE order_id = %d ORDER BY payment_date DESC',
				$order_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Parse order line items.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_items_from_request() {
		$raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items = json_decode( $raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'items_required', __( 'Add at least one order line item.', 'ds-prod-import-crm' ) );
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

			$parsed[] = array(
				'product_id'      => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'product_name'    => $product_name,
				'is_new_product'  => ! empty( $item['is_new_product'] ),
				'category_id'     => isset( $item['category_id'] ) ? absint( $item['category_id'] ) : 0,
				'sku'             => isset( $item['sku'] ) ? crm_sanitize_sku( $item['sku'] ) : '',
				'color'           => isset( $item['color'] ) ? sanitize_text_field( $item['color'] ) : '',
				'size'            => isset( $item['size'] ) ? sanitize_text_field( $item['size'] ) : '',
				'quantity'        => $quantity,
				'weight_kg'       => isset( $item['weight_kg'] ) ? crm_parse_weight( $item['weight_kg'] ) : 0,
				'unit_price'      => isset( $item['unit_price'] ) ? crm_parse_amount( $item['unit_price'] ) : 0,
				'delivery_priority' => CRM_Order_Item_Priority::sanitize( $item['delivery_priority'] ?? CRM_Order_Item_Priority::NORMAL ),
				'notes'           => isset( $item['notes'] ) ? sanitize_textarea_field( $item['notes'] ) : '',
			);
		}

		if ( empty( $parsed ) ) {
			return new \WP_Error( 'items_invalid', __( 'Each line needs a product and quantity.', 'ds-prod-import-crm' ) );
		}

		foreach ( $parsed as $index => $item ) {
			if ( $item['product_id'] > 0 && empty( $item['is_new_product'] ) ) {
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
				if ( current_user_can( 'crm_orders_edit' ) && (float) $parsed[ $index ]['unit_price'] <= 0 && (float) $catalog['unit_price'] > 0 ) {
					$parsed[ $index ]['unit_price'] = (float) $catalog['unit_price'];
				}
				continue;
			}

			if ( empty( $item['is_new_product'] ) ) {
				return new \WP_Error(
					'product_required',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: pick a catalog product or add as a new product with an image.', 'ds-prod-import-crm' ),
						$index + 1
					)
				);
			}

			$product_id = crm_create_product_from_order_line( $item, $index );
			if ( is_wp_error( $product_id ) ) {
				return $product_id;
			}

			$catalog = crm_get_catalog_product( $product_id );
			$parsed[ $index ]['product_id']   = $product_id;
			$parsed[ $index ]['product_name'] = $catalog ? $catalog['name'] : $item['product_name'];
			if ( current_user_can( 'crm_orders_edit' ) && (float) $parsed[ $index ]['unit_price'] <= 0 && $catalog && (float) $catalog['unit_price'] > 0 ) {
				$parsed[ $index ]['unit_price'] = (float) $catalog['unit_price'];
			}
		}

		return $parsed;
	}

	/**
	 * Create or update order (lines only if no deliveries yet).
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		$order_id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$client_id  = isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0;
		$client_id  = CRM_Client_Portal::resolve_client_id_for_save( $client_id );
		if ( is_wp_error( $client_id ) ) {
			wp_send_json_error( array( 'message' => $client_id->get_error_message() ) );
		}
		$order_date = isset( $_POST['order_date'] ) ? crm_normalize_date( wp_unslash( $_POST['order_date'] ) ) : '';
		$notes      = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a client.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! $order_date ) {
			wp_send_json_error( array( 'message' => __( 'Order date is required.', 'ds-prod-import-crm' ) ) );
		}

		$client_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'clients' ) . ' WHERE id = %d AND status = %s',
				$client_id,
				'active'
			)
		);

		if ( ! $client_exists ) {
			wp_send_json_error( array( 'message' => __( 'Selected client is not valid.', 'ds-prod-import-crm' ) ) );
		}

		$orders_table = crm_table( 'orders' );
		$items_table  = crm_table( 'order_items' );
		$bills_table  = crm_table( 'client_bills' );

		if ( $order_id ) {
			$existing = self::fetch_order_row( $order_id );

			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
			}

			if ( ! CRM_Capability_Registry::user_can_edit_order( $existing ) ) {
				wp_send_json_error(
					array(
						'message' => CRM_Capability_Registry::order_edit_denied_message( $existing ),
					),
					403
				);
			}

			if ( ! CRM_Client_Portal::user_can_view_order( $existing ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this order.', 'ds-prod-import-crm' ) ), 403 );
			}

			if ( 'cancelled' === $existing['status'] ) {
				wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be edited.', 'ds-prod-import-crm' ) ) );
			}

			$has_deliveries = crm_count_order_deliveries( $order_id ) > 0;

			if ( $has_deliveries ) {
				$wpdb->update(
					$orders_table,
					array(
						'notes'      => $notes,
						'updated_by' => CRM_Audit::current_user_id(),
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $order_id ),
					array( '%s', '%d', '%s' ),
					array( '%d' )
				);

				self::log_activity( 'update', 'orders', $order_id, sprintf( 'Updated notes on %s', $existing['order_number'] ) );

				wp_send_json_success(
					array(
						'message' => __( 'Order notes updated. Line items cannot change after a delivery exists.', 'ds-prod-import-crm' ),
						'id'      => $order_id,
					)
				);
			}

			$items = self::parse_items_from_request();
			if ( is_wp_error( $items ) ) {
				wp_send_json_error( array( 'message' => $items->get_error_message() ) );
			}

			$before_items = self::fetch_order_items_snapshot( $order_id );
			$before_order = array(
				'client_id'  => (int) ( $existing['client_id'] ?? 0 ),
				'order_date' => (string) ( $existing['order_date'] ?? '' ),
				'notes'      => (string) ( $existing['notes'] ?? '' ),
			);

			$order_bill = 0;
			foreach ( $items as $item ) {
				$order_bill += $item['quantity'] * $item['unit_price'];
			}

			self::begin_transaction();

			$wpdb->update(
				$orders_table,
				array(
					'client_id'   => $client_id,
					'order_date'  => $order_date,
					'notes'       => $notes,
					'updated_by'  => CRM_Audit::current_user_id(),
					'updated_at'  => current_time( 'mysql' ),
				),
				array( 'id' => $order_id ),
				array( '%d', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			$wpdb->delete( $items_table, array( 'order_id' => $order_id ), array( '%d' ) );

			foreach ( $items as $item ) {
				$item_inserted = $wpdb->insert(
					$items_table,
					array(
						'order_id'     => $order_id,
						'product_id'   => $item['product_id'] > 0 ? $item['product_id'] : 0,
						'product_name' => $item['product_name'],
						'color'        => $item['color'],
						'size'         => $item['size'],
						'quantity'     => $item['quantity'],
						'weight_kg'    => $item['weight_kg'],
						'unit_price'   => $item['unit_price'],
						'delivery_priority' => $item['delivery_priority'],
						'notes'        => $item['notes'],
						'created_at'   => current_time( 'mysql' ),
					),
					array( '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s' )
				);

				if ( ! $item_inserted ) {
					self::rollback_transaction();
					wp_send_json_error( array( 'message' => __( 'Failed to save order items.', 'ds-prod-import-crm' ) ) );
				}
			}

			$wpdb->update(
				$bills_table,
				array(
					'client_id' => $client_id,
					'bill_date' => $order_date,
					'amount'    => round( $order_bill, 2 ),
				),
				array(
					'order_id'  => $order_id,
					'bill_type' => 'order_bill',
				),
				array( '%d', '%s', '%f' ),
				array( '%d', '%s' )
			);

			self::commit_transaction();

			CRM_Order_Status::sync_delivery_status( $order_id );

			$audit_changes = array_merge(
				CRM_Audit::describe_changes(
					$before_order,
					array(
						'client_id'  => $client_id,
						'order_date' => $order_date,
						'notes'      => $notes,
					),
					array(
						'client_id'  => __( 'Client', 'ds-prod-import-crm' ),
						'order_date' => __( 'Order date', 'ds-prod-import-crm' ),
						'notes'      => __( 'Notes', 'ds-prod-import-crm' ),
					)
				),
				self::describe_line_item_changes( $before_items, $items )
			);

			self::log_activity(
				'update',
				'orders',
				$order_id,
				sprintf( 'Updated order %s', $existing['order_number'] ),
				array( 'changes' => $audit_changes )
			);

			wp_send_json_success(
				array(
					'message' => __( 'Order updated successfully.', 'ds-prod-import-crm' ),
					'id'      => $order_id,
				)
			);
		}

		self::verify_request( 'crm_orders_create' );

		$items = self::parse_items_from_request();
		if ( is_wp_error( $items ) ) {
			wp_send_json_error( array( 'message' => $items->get_error_message() ) );
		}

		$order_number = crm_generate_sequence_number( 'ORD', 'orders', 'order_number' );
		$order_bill   = 0;
		foreach ( $items as $item ) {
			$order_bill += $item['quantity'] * $item['unit_price'];
		}

		self::begin_transaction();

		$inserted = $wpdb->insert(
			$orders_table,
			array(
				'order_number' => $order_number,
				'client_id'    => $client_id,
				'order_date'   => $order_date,
				'notes'        => $notes,
				'status'       => CRM_Order_Status::initial_status_for_new_order(),
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => __( 'Failed to create order.', 'ds-prod-import-crm' ) ) );
		}

		$new_order_id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			$item_inserted = $wpdb->insert(
				$items_table,
				array(
					'order_id'     => $new_order_id,
					'product_id'   => $item['product_id'] > 0 ? $item['product_id'] : 0,
					'product_name' => $item['product_name'],
					'color'        => $item['color'],
					'size'         => $item['size'],
					'quantity'     => $item['quantity'],
					'weight_kg'    => $item['weight_kg'],
					'unit_price'   => $item['unit_price'],
					'delivery_priority' => $item['delivery_priority'],
					'notes'        => $item['notes'],
					'created_at'   => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s' )
			);

			if ( ! $item_inserted ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => __( 'Failed to save order items.', 'ds-prod-import-crm' ) ) );
			}
		}

		$bill_inserted = $wpdb->insert(
			$bills_table,
			array(
				'client_id'  => $client_id,
				'order_id'   => $new_order_id,
				'bill_date'  => $order_date,
				'bill_type'  => 'order_bill',
				'amount'     => round( $order_bill, 2 ),
				'notes'      => sprintf( 'Order bill for %s', $order_number ),
				'created_by'   => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%s' )
		);

		if ( ! $bill_inserted ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => __( 'Failed to create order bill.', 'ds-prod-import-crm' ) ) );
		}

		self::commit_transaction();

		$priced_lines = 0;
		foreach ( $items as $item ) {
			if ( (float) ( $item['unit_price'] ?? 0 ) > 0 ) {
				++$priced_lines;
			}
		}

		self::log_activity(
			'create',
			'orders',
			$new_order_id,
			sprintf( 'Created order %s', $order_number ),
			array(
				'changes' => array(
					sprintf(
						/* translators: %d: line count */
						__( 'Line items: %d', 'ds-prod-import-crm' ),
						count( $items )
					),
					$priced_lines < count( $items )
						? __( 'Prices pending — China office will confirm unit prices', 'ds-prod-import-crm' )
						: __( 'All line prices provided', 'ds-prod-import-crm' ),
				),
			)
		);

		wp_send_json_success(
			array(
				'message'      => __( 'Order created successfully.', 'ds-prod-import-crm' ),
				'id'           => $new_order_id,
				'order_number' => $order_number,
			)
		);
	}

	/**
	 * Minimal order row for permission checks.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_order_row( $order_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, order_number, status, created_by, client_id, order_date, notes FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
				$order_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Whether every order line has a positive unit price.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private static function order_lines_have_prices( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return false;
		}

		$unpriced = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'order_items' ) . '
				WHERE order_id = %d
				AND COALESCE(accepted_quantity, quantity) > 0
				AND unit_price <= 0',
				$order_id
			)
		);

		return 0 === $unpriced;
	}

	/**
	 * Snapshot order lines for audit diffs.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_order_items_snapshot( $order_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT product_name, color, size, quantity, unit_price FROM ' . crm_table( 'order_items' ) . ' WHERE order_id = %d ORDER BY id ASC',
				absint( $order_id )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Human-readable line item diffs for activity log.
	 *
	 * @param array<int, array<string, mixed>> $before Previous lines.
	 * @param array<int, array<string, mixed>> $after  New lines.
	 * @return array<int, string>
	 */
	private static function describe_line_item_changes( array $before, array $after ) {
		$changes = array();
		$max     = max( count( $before ), count( $after ) );

		for ( $i = 0; $i < $max; $i++ ) {
			$line_no = $i + 1;
			$old     = $before[ $i ] ?? null;
			$new     = $after[ $i ] ?? null;

			if ( ! $old && $new ) {
				$changes[] = sprintf(
					/* translators: 1: line number, 2: product name, 3: quantity */
					__( 'Line %1$d added: %2$s × %3$d', 'ds-prod-import-crm' ),
					$line_no,
					$new['product_name'] ?? '',
					(int) ( $new['quantity'] ?? 0 )
				);
				continue;
			}

			if ( $old && ! $new ) {
				$changes[] = sprintf(
					/* translators: 1: line number, 2: product name */
					__( 'Line %1$d removed: %2$s', 'ds-prod-import-crm' ),
					$line_no,
					$old['product_name'] ?? ''
				);
				continue;
			}

			if ( ! $old || ! $new ) {
				continue;
			}

			$line_label = sprintf(
				/* translators: %d: line number */
				__( 'Line %d', 'ds-prod-import-crm' ),
				$line_no
			);

			if ( (string) ( $old['product_name'] ?? '' ) !== (string) ( $new['product_name'] ?? '' ) ) {
				$changes[] = sprintf(
					'%s %s: %s → %s',
					$line_label,
					__( 'product', 'ds-prod-import-crm' ),
					$old['product_name'] ?? '—',
					$new['product_name'] ?? '—'
				);
			}

			if ( (int) ( $old['quantity'] ?? 0 ) !== (int) ( $new['quantity'] ?? 0 ) ) {
				$changes[] = sprintf(
					'%s %s: %s → %s',
					$line_label,
					__( 'qty', 'ds-prod-import-crm' ),
					(string) ( $old['quantity'] ?? 0 ),
					(string) ( $new['quantity'] ?? 0 )
				);
			}

			$old_price = number_format( (float) ( $old['unit_price'] ?? 0 ), 2, '.', '' );
			$new_price = number_format( (float) ( $new['unit_price'] ?? 0 ), 2, '.', '' );
			if ( $old_price !== $new_price ) {
				$changes[] = sprintf(
					'%s %s: %s → %s',
					$line_label,
					__( 'unit price', 'ds-prod-import-crm' ),
					$old_price,
					$new_price
				);
			}
		}

		return $changes;
	}
}
