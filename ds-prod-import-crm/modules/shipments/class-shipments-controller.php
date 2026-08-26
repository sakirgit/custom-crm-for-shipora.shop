<?php
/**
 * China office export shipments (in-transit to BD warehouse).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Record partial exports from China via cargo companies before warehouse receive.
 */
class Shipments_Controller extends CRM_Controller_Base {

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_shipments_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_shipments_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_shipments_form_data', array( __CLASS__, 'form_data' ) );
		add_action( 'wp_ajax_crm_shipments_orders', array( __CLASS__, 'orders_for_shipment' ) );
		add_action( 'wp_ajax_crm_shipments_orders_search', array( __CLASS__, 'orders_search' ) );
		add_action( 'wp_ajax_crm_shipments_orders_ready', array( __CLASS__, 'orders_ready_list' ) );
		add_action( 'wp_ajax_crm_shipments_order_lines', array( __CLASS__, 'order_lines' ) );
		add_action( 'wp_ajax_crm_shipments_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_shipments_update_company', array( __CLASS__, 'update_company' ) );
		add_action( 'wp_ajax_crm_shipments_void', array( __CLASS__, 'void_item' ) );
		add_action( 'wp_ajax_crm_shipments_amend_request', array( __CLASS__, 'request_amendment' ) );
		add_action( 'wp_ajax_crm_shipments_amend_review', array( __CLASS__, 'review_amendment' ) );
		add_action( 'wp_ajax_crm_shipments_amendments_list', array( __CLASS__, 'list_amendments' ) );
	}

	/**
	 * SQL fragment: exported qty for an order item (non-void shipments).
	 *
	 * @param string $order_item_alias order_items alias.
	 * @return string
	 */
	private static function sql_qty_exported( $order_item_alias = 'oi' ) {
		$items_table = crm_table( 'export_shipment_items' );
		$ship_table  = crm_table( 'export_shipments' );

		return "COALESCE((
			SELECT SUM(esi.quantity) FROM {$items_table} esi
			INNER JOIN {$ship_table} es ON es.id = esi.shipment_id AND es.status != 'void'
			WHERE esi.order_item_id = {$order_item_alias}.id
		), 0)";
	}

	/**
	 * SQL expression: accepted (or ordered) qty still remaining to export.
	 *
	 * @param string $order_item_alias Table alias for order_items.
	 * @return string
	 */
	private static function sql_has_qty_to_export( $order_item_alias = 'oi' ) {
		return 'COALESCE(' . $order_item_alias . '.accepted_quantity, ' . $order_item_alias . '.quantity) > ' . self::sql_qty_exported( $order_item_alias );
	}

	/**
	 * Supply/export legs recorded for an order (newest first).
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_shipments_for_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return array();
		}

		$ship_table      = crm_table( 'export_shipments' );
		$items_table     = crm_table( 'export_shipment_items' );
		$order_items     = crm_table( 'order_items' );
		$products_table  = crm_table( 'products' );
		$companies_table = crm_table( 'companies' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.shipment_number, s.ship_date, s.status, s.total_kg, s.notes, s.created_at,
					s.company_id, co.name AS company_name,
					COALESCE((SELECT SUM(esi.quantity) FROM {$items_table} esi WHERE esi.shipment_id = s.id), 0) AS qty_total,
					(SELECT COUNT(*) FROM {$items_table} esi2 WHERE esi2.shipment_id = s.id) AS item_count
				FROM {$ship_table} s
				LEFT JOIN {$companies_table} co ON co.id = s.company_id
				WHERE s.order_id = %d AND s.status != 'void'
				ORDER BY s.ship_date ASC, s.id ASC",
				$order_id
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$shipment_ids = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			$rows
		);
		$placeholders = implode( ',', array_fill( 0, count( $shipment_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$line_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT esi.id AS shipment_item_id, esi.shipment_id, esi.order_item_id, esi.product_name, esi.color, esi.size,
					esi.quantity, esi.weight_kg, esi.notes AS line_notes,
					oi.quantity AS qty_ordered, oi.accepted_quantity, oi.delivery_priority, oi.unit_price,
					" . crm_sql_product_image_url( 'p' ) . " AS product_image_url,
					NULLIF(p.image_url, '') AS product_full_image_url
				FROM {$items_table} esi
				LEFT JOIN {$order_items} oi ON oi.id = esi.order_item_id
				LEFT JOIN {$products_table} p ON p.id = oi.product_id
				WHERE esi.shipment_id IN ({$placeholders})
				ORDER BY esi.id ASC",
				$shipment_ids
			),
			ARRAY_A
		);

		$lines_by_shipment = array();
		foreach ( $line_rows ? $line_rows : array() as $line ) {
			$sid = (int) $line['shipment_id'];
			if ( ! isset( $lines_by_shipment[ $sid ] ) ) {
				$lines_by_shipment[ $sid ] = array();
			}
			$item = array(
				'id'                => (int) ( $line['shipment_item_id'] ?? 0 ),
				'shipment_item_id'  => (int) ( $line['shipment_item_id'] ?? 0 ),
				'order_item_id'     => (int) ( $line['order_item_id'] ?? 0 ),
				'product_name'      => (string) $line['product_name'],
				'product_image_url' => ! empty( $line['product_image_url'] ) ? esc_url_raw( $line['product_image_url'] ) : '',
				'product_full_image_url' => ! empty( $line['product_full_image_url'] )
					? esc_url_raw( $line['product_full_image_url'] )
					: ( ! empty( $line['product_image_url'] ) ? esc_url_raw( $line['product_image_url'] ) : '' ),
				'color'             => (string) ( $line['color'] ?? '' ),
				'size'              => (string) ( $line['size'] ?? '' ),
				'quantity'          => (int) $line['quantity'],
				'qty_ordered'       => (int) ( $line['qty_ordered'] ?? 0 ),
				'accepted_quantity' => array_key_exists( 'accepted_quantity', $line ) && null !== $line['accepted_quantity'] && '' !== $line['accepted_quantity']
					? (int) $line['accepted_quantity']
					: null,
				'qty_accepted'      => crm_order_item_accepted_qty(
					array(
						'accepted_quantity' => $line['accepted_quantity'] ?? null,
						'quantity'          => (int) ( $line['qty_ordered'] ?? 0 ),
					)
				),
				'weight_kg'         => (float) $line['weight_kg'],
				'unit_price'        => (float) ( $line['unit_price'] ?? 0 ),
				'notes'             => (string) ( $line['line_notes'] ?? '' ),
				'delivery_priority' => (string) ( $line['delivery_priority'] ?? 'normal' ),
			);
			CRM_Order_Item_Priority::enrich( $item );
			$lines_by_shipment[ $sid ][] = $item;
		}

		$can_amend_cap  = current_user_can( 'crm_shipments_amend' );
		$can_review_cap = current_user_can( 'crm_shipments_review' );

		foreach ( $rows as &$row ) {
			$sid               = (int) $row['id'];
			$row['qty_total']  = (int) $row['qty_total'];
			$row['item_count'] = (int) $row['item_count'];
			$row['notes']      = (string) ( $row['notes'] ?? '' );
			$row['items']      = $lines_by_shipment[ $sid ] ?? array();
			$row['time']       = crm_tracking_datetime_payload(
				! empty( $row['created_at'] ) ? (string) $row['created_at'] : ( (string) $row['ship_date'] . ' 12:00:00' )
			);

			$pending_list = self::get_pending_amendments_for_shipment( $sid );
			$pending_by_item = array();
			foreach ( $pending_list as $amendment ) {
				foreach ( $amendment['items'] as $aline ) {
					$item_key = (int) ( $aline['shipment_item_id'] ?? 0 );
					if ( $item_key < 1 ) {
						continue;
					}
					$pending_by_item[ $item_key ] = array_merge(
						$aline,
						array(
							'amendment_id'        => (int) $amendment['id'],
							'reason'              => (string) ( $amendment['reason'] ?? '' ),
							'requested_by_name'   => (string) ( $amendment['requested_by_name'] ?? '' ),
							'requested_at'        => (string) ( $amendment['requested_at'] ?? '' ),
						)
					);
				}
			}

			$warehouse_locked = crm_shipment_is_warehouse_locked( $sid );

			foreach ( $row['items'] as &$item ) {
				$item_id = (int) ( $item['shipment_item_id'] ?? $item['id'] ?? 0 );
				$pending = $pending_by_item[ $item_id ] ?? null;
				$item['pending_amendment'] = $pending;
				$item['can_amend']         = ! $warehouse_locked
					&& 'void' !== ( $row['status'] ?? '' )
					&& $can_amend_cap
					&& empty( $pending );
				$item['can_review']        = ! empty( $pending ) && $can_review_cap;
			}
			unset( $item );

			$row['pending_amendments']      = $pending_list;
			$row['pending_amendment']       = ! empty( $pending_list ) ? $pending_list[0] : null;
			$row['has_pending_amendment']   = ! empty( $pending_list );
			$row['warehouse_locked']        = $warehouse_locked;
			$row['can_amend']               = ! $warehouse_locked && 'void' !== ( $row['status'] ?? '' ) && $can_amend_cap;
			$row['can_review']              = $can_review_cap && ! empty( $pending_list );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Remaining export summary for an order after a supply.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, int|bool>
	 */
	public static function get_export_remaining_summary( $order_id ) {
		$lines         = self::get_order_lines_with_export_due( $order_id );
		$qty_ordered   = 0;
		$qty_accepted  = 0;
		$qty_exported  = 0;
		$qty_remaining = 0;
		$lines_due     = 0;
		$lines_total   = 0;

		foreach ( $lines as $line ) {
			$ordered        = (int) ( $line['quantity'] ?? 0 );
			$accepted       = (int) ( $line['qty_accepted'] ?? 0 );
			$exported       = (int) ( $line['qty_exported'] ?? 0 );
			$due            = (int) ( $line['qty_to_export'] ?? 0 );
			$qty_ordered   += $ordered;
			$qty_accepted  += $accepted;
			$qty_exported  += $exported;
			$qty_remaining += $due;
			$lines_total++;
			if ( $due > 0 ) {
				$lines_due++;
			}
		}

		$shipments       = self::get_shipments_for_order( $order_id );
		$shipment_count  = count( $shipments );
		$total_kg        = 0.0;
		foreach ( $shipments as $shipment ) {
			$total_kg += (float) ( $shipment['total_kg'] ?? 0 );
		}

		$pct = $qty_accepted > 0
			? (int) min( 100, round( ( $qty_exported / $qty_accepted ) * 100 ) )
			: 0;

		return array(
			'qty_ordered'     => $qty_ordered,
			'qty_accepted'    => $qty_accepted,
			'qty_exported'    => $qty_exported,
			'qty_remaining'   => $qty_remaining,
			'lines_remaining' => $lines_due,
			'lines_total'     => $lines_total,
			'shipment_count'  => $shipment_count,
			'total_kg'        => round( $total_kg, 3 ),
			'pct_supplied'    => $pct,
			'fully_supplied'  => $qty_accepted > 0 && $qty_remaining < 1,
		);
	}

	/**
	 * List export shipments.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_request( 'crm_shipments_view' );

		global $wpdb;

		$table           = crm_table( 'export_shipments' );
		$orders_table    = crm_table( 'orders' );
		$clients_table   = crm_table( 'clients' );
		$companies_table = crm_table( 'companies' );
		$pagination      = self::pagination_from_request();
		$dates           = self::date_range_from_request();
		$search          = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$order_id        = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$company_id      = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;

		$where  = array( "s.status != 'void'" );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.shipment_number LIKE %s OR o.order_number LIKE %s OR cl.name LIKE %s OR co.name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $order_id ) {
			$where[]  = 's.order_id = %d';
			$params[] = $order_id;
		}

		if ( $company_id ) {
			$where[]  = 's.company_id = %d';
			$params[] = $company_id;
		}

		if ( $dates['date_from'] ) {
			$where[]  = 's.ship_date >= %s';
			$params[] = $dates['date_from'];
		}

		if ( $dates['date_to'] ) {
			$where[]  = 's.ship_date <= %s';
			$params[] = $dates['date_to'];
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} s
			LEFT JOIN {$orders_table} o ON o.id = s.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			LEFT JOIN {$companies_table} co ON co.id = s.company_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT s.*, o.order_number, cl.name AS client_name, co.name AS company_name,
			(SELECT COUNT(*) FROM " . crm_table( 'export_shipment_items' ) . " esi WHERE esi.shipment_id = s.id) AS item_count,
			(SELECT COUNT(*) FROM " . crm_table( 'export_shipment_amendments' ) . " esa WHERE esa.shipment_id = s.id AND esa.status = 'pending') AS pending_amendment_count
			FROM {$table} s
			LEFT JOIN {$orders_table} o ON o.id = s.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			LEFT JOIN {$companies_table} co ON co.id = s.company_id
			WHERE {$where_sql}
			ORDER BY s.ship_date DESC, s.id DESC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		if ( $items ) {
			foreach ( $items as &$row ) {
				$row['pending_amendment_count'] = (int) ( $row['pending_amendment_count'] ?? 0 );
				$row['has_pending_amendment']   = $row['pending_amendment_count'] > 0;
			}
			unset( $row );
			crm_attach_export_shipment_product_previews( $items );
		}

		wp_send_json_success(
			array(
				'items'       => $items ? $items : array(),
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
			)
		);
	}

	/**
	 * Single shipment with lines.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_request( 'crm_shipments_view' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shipment ID.', 'ds-prod-import-crm' ) ) );
		}

		$shipment = self::fetch_shipment_row( $id );
		if ( ! $shipment ) {
			wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'ds-prod-import-crm' ) ) );
		}

		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT esi.*, ' . crm_sql_product_image_url( 'p' ) . ' AS product_image_url,
				NULLIF(p.image_url, \'\') AS product_full_image_url,
				oi.delivery_priority
				FROM ' . crm_table( 'export_shipment_items' ) . ' esi
				LEFT JOIN ' . crm_table( 'order_items' ) . ' oi ON oi.id = esi.order_item_id
				LEFT JOIN ' . crm_table( 'products' ) . ' p ON p.id = oi.product_id
				WHERE esi.shipment_id = %d ORDER BY esi.id ASC',
				$id
			),
			ARRAY_A
		);

		if ( $items ) {
			foreach ( $items as &$item ) {
				$item['product_image_url'] = ! empty( $item['product_image_url'] ) ? esc_url_raw( $item['product_image_url'] ) : '';
				$item['product_full_image_url'] = ! empty( $item['product_full_image_url'] )
					? esc_url_raw( $item['product_full_image_url'] )
					: $item['product_image_url'];
				CRM_Order_Item_Priority::enrich( $item );
			}
			unset( $item );
		}

		$created_by_name = '';
		if ( ! empty( $shipment['created_by'] ) ) {
			$user = get_userdata( (int) $shipment['created_by'] );
			$created_by_name = $user ? $user->display_name : '';
		}

		$warehouse_locked   = crm_shipment_is_warehouse_locked( $id );
		$pending_amendment  = self::get_pending_amendment_for_shipment( $id );
		$pending_amendments = self::get_pending_amendments_for_shipment( $id );
		$can_amend          = ! $warehouse_locked && 'void' !== $shipment['status'] && current_user_can( 'crm_shipments_amend' );
		$can_review         = ! empty( $pending_amendments ) && current_user_can( 'crm_shipments_review' );
		$can_change_company = ! $warehouse_locked && 'void' !== $shipment['status'] && current_user_can( 'crm_shipments_create' );
		$companies          = array();

		if ( $can_change_company ) {
			$companies = $wpdb->get_results(
				'SELECT id, name FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' ORDER BY name ASC",
				ARRAY_A
			);
		}

		if ( $items ) {
			$pending_by_item = array();
			foreach ( $pending_amendments as $amendment ) {
				foreach ( $amendment['items'] as $aline ) {
					$item_key = (int) ( $aline['shipment_item_id'] ?? 0 );
					if ( $item_key > 0 ) {
						$pending_by_item[ $item_key ] = array_merge(
							$aline,
							array(
								'amendment_id'      => (int) $amendment['id'],
								'reason'            => (string) ( $amendment['reason'] ?? '' ),
								'requested_by_name' => (string) ( $amendment['requested_by_name'] ?? '' ),
							)
						);
					}
				}
			}
			foreach ( $items as &$item ) {
				$item_id = (int) ( $item['id'] ?? 0 );
				$pending = $pending_by_item[ $item_id ] ?? null;
				$item['pending_amendment'] = $pending;
				$item['can_amend']         = $can_amend && empty( $pending );
				$item['can_review']        = ! empty( $pending ) && current_user_can( 'crm_shipments_review' );
			}
			unset( $item );
		}

		wp_send_json_success(
			array(
				'shipment'            => $shipment,
				'items'               => $items ? $items : array(),
				'created_by_name'     => $created_by_name,
				'warehouse_locked'    => $warehouse_locked,
				'can_void'            => ! $warehouse_locked
					&& 'void' !== $shipment['status']
					&& current_user_can( 'crm_shipments_void' )
					&& empty( $pending_amendments ),
				'can_change_company'  => $can_change_company,
				'can_amend'           => $can_amend,
				'can_review'          => $can_review,
				'pending_amendment'   => $pending_amendment,
				'pending_amendments'  => $pending_amendments,
				'companies'           => $companies ? $companies : array(),
			)
		);
	}

	/**
	 * Companies for export form.
	 *
	 * @return void
	 */
	public static function form_data() {
		self::verify_request( 'crm_shipments_create' );

		global $wpdb;

		$companies = $wpdb->get_results(
			'SELECT id, name, company_type FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' AND company_type = 'cargo' ORDER BY name ASC",
			ARRAY_A
		);

		if ( ! $companies ) {
			$companies = $wpdb->get_results(
				'SELECT id, name, company_type FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' ORDER BY name ASC",
				ARRAY_A
			);
		}

		wp_send_json_success(
			array(
				'companies' => $companies ? $companies : array(),
			)
		);
	}

	/**
	 * Orders with lines still to export from China.
	 *
	 * @return void
	 */
	public static function orders_for_shipment() {
		self::verify_request( 'crm_shipments_create' );

		global $wpdb;

		$orders_table = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$items_table  = crm_table( 'order_items' );
		$qty_exported = self::sql_qty_exported( 'oi' );

		$sql = "SELECT o.id, o.order_number, o.order_date, o.status, o.client_id, cl.name AS client_name
			FROM {$orders_table} o
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE o.status != 'cancelled'
			AND EXISTS (
				SELECT 1 FROM {$items_table} oi
				WHERE oi.order_id = o.id
				AND " . self::sql_has_qty_to_export( 'oi' ) . "
			)
			ORDER BY o.order_date DESC, o.id DESC
			LIMIT 200";

		$orders = $wpdb->get_results( $sql, ARRAY_A );

		if ( $orders ) {
			$orders = self::sort_export_picker_orders( array_map( array( __CLASS__, 'enrich_order_export_picker' ), $orders ) );
		}

		wp_send_json_success(
			array(
				'orders' => $orders ? $orders : array(),
			)
		);
	}

	/**
	 * Search orders with lines still to export (for shipment form picker).
	 *
	 * @return void
	 */
	public static function orders_search() {
		self::verify_request( 'crm_shipments_create' );

		global $wpdb;

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$prep   = crm_prepare_order_autocomplete_search( $search );
		if ( empty( $prep['ok'] ) ) {
			wp_send_json_success(
				array(
					'items' => array(),
					'hint'  => $prep['hint'] ?? __( 'Type at least 2 characters to search orders.', 'ds-prod-import-crm' ),
				)
			);
		}

		$orders_table   = crm_table( 'orders' );
		$clients_table  = crm_table( 'clients' );
		$items_table    = crm_table( 'order_items' );
		$products_table = crm_table( 'products' );
		$limit          = max( 1, min( 25, (int) ( $prep['limit'] ?? 20 ) ) );
		$fetch_limit    = $limit + 1;
		$contains       = $prep['contains_like'] ?? ( '%' . $wpdb->esc_like( $search ) . '%' );
		$order_like     = $prep['order_number_like'] ?? $contains;

		$sql = "SELECT o.id, o.order_number, o.order_date, o.status, o.client_id, cl.name AS client_name, cl.phone AS client_phone,
			(SELECT COUNT(*) FROM {$items_table} oi_due WHERE oi_due.order_id = o.id AND " . self::sql_has_qty_to_export( 'oi_due' ) . ") AS lines_to_export,
			(SELECT COUNT(*) FROM {$items_table} oi_cnt WHERE oi_cnt.order_id = o.id) AS item_count
			FROM {$orders_table} o
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE o.status != 'cancelled'
			AND EXISTS (
				SELECT 1 FROM {$items_table} oi
				WHERE oi.order_id = o.id
				AND " . self::sql_has_qty_to_export( 'oi' ) . "
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

		$items = $wpdb->get_results(
			$wpdb->prepare( $sql, $order_like, $contains, $contains, $contains, $contains, $contains, $fetch_limit ),
			ARRAY_A
		);

		$truncated = false;
		if ( $items && count( $items ) > $limit ) {
			$truncated = true;
			$items     = array_slice( $items, 0, $limit );
		}

		if ( $items ) {
			$items = self::sort_export_picker_orders( array_map( array( __CLASS__, 'enrich_order_export_picker' ), $items ) );
		}

		wp_send_json_success(
			array(
				'items'     => $items ? $items : array(),
				'truncated' => $truncated,
				'hint'      => $truncated
					? __( 'Showing the first matches. Keep typing to narrow the list.', 'ds-prod-import-crm' )
					: '',
			)
		);
	}

	public static function orders_ready_list() {
		self::verify_request( 'crm_shipments_view' );

		global $wpdb;

		$search         = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status         = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$tracking       = isset( $_POST['tracking'] ) ? sanitize_key( wp_unslash( $_POST['tracking'] ) ) : '';
		$orders_table   = crm_table( 'orders' );
		$clients_table  = crm_table( 'clients' );
		$items_table    = crm_table( 'order_items' );
		$products_table = crm_table( 'products' );
		$qty_exported   = self::sql_qty_exported( 'oi' );
		$qty_exported_u = self::sql_qty_exported( 'oi_u' );
		$qty_exported_p = self::sql_qty_exported( 'oi_p' );

		$where  = array();
		$params = array();

		if ( $status && CRM_Order_Status::is_valid_slug( $status ) ) {
			$where[]  = 'o.status = %s';
			$params[] = $status;
		} elseif ( 'cancelled' !== $tracking ) {
			$where[] = "o.status != 'cancelled'";
		}

		if ( $tracking ) {
			CRM_Order_Tracking::apply_list_filter( $tracking, $where );
		}

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(
				o.order_number LIKE %s
				OR cl.name LIKE %s
				OR cl.phone LIKE %s
				OR EXISTS (
					SELECT 1 FROM {$items_table} oi_s
					LEFT JOIN {$products_table} p ON p.id = oi_s.product_id
					WHERE oi_s.order_id = o.id
					AND (oi_s.product_name LIKE %s OR p.name LIKE %s OR p.sku LIKE %s)
				)
			)';
			$params   = array_merge( $params, array( $like, $like, $like, $like, $like, $like ) );
		}

		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT o.id, o.order_number, o.order_date, o.status, o.client_id, o.created_by, cl.name AS client_name, cl.phone AS client_phone,
			COALESCE((
				SELECT SUM(COALESCE(oi_b.accepted_quantity, oi_b.quantity) * oi_b.unit_price) FROM {$items_table} oi_b WHERE oi_b.order_id = o.id
			), 0) AS total_amount,
			(SELECT COUNT(*) FROM {$items_table} oi_unp WHERE oi_unp.order_id = o.id AND COALESCE(oi_unp.accepted_quantity, oi_unp.quantity) > 0 AND oi_unp.unit_price <= 0) AS lines_unpriced,
			(SELECT COUNT(*) FROM {$items_table} oi_due WHERE oi_due.order_id = o.id AND " . self::sql_has_qty_to_export( 'oi_due' ) . ") AS lines_to_export,
			(SELECT COUNT(*) FROM {$items_table} oi_cnt WHERE oi_cnt.order_id = o.id) AS item_count,
			(SELECT COUNT(*) FROM {$items_table} oi_u WHERE oi_u.order_id = o.id AND oi_u.delivery_priority = 'urgent' AND " . self::sql_has_qty_to_export( 'oi_u' ) . ") AS urgent_count,
			(
				SELECT oi_p.delivery_priority FROM {$items_table} oi_p
				WHERE oi_p.order_id = o.id AND " . self::sql_has_qty_to_export( 'oi_p' ) . "
				ORDER BY " . CRM_Order_Item_Priority::sql_order_by( 'oi_p' ) . "
				LIMIT 1
			) AS top_priority
			FROM {$orders_table} o
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE {$where_sql}
			ORDER BY o.order_date DESC, o.id DESC
			LIMIT 500";

		if ( ! empty( $params ) ) {
			$orders = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			$orders = $wpdb->get_results( $sql, ARRAY_A );
		}

		if ( $orders ) {
			crm_attach_order_product_previews( $orders );
			CRM_Order_Tracking::attach_to_list_items( $orders );
			$orders = array_map(
				static function ( $order ) {
					$order = self::enrich_order_export_picker( $order );
					$order['top_priority']      = CRM_Order_Item_Priority::sanitize( $order['top_priority'] ?? CRM_Order_Item_Priority::NORMAL );
					$order['shipment_form_url'] = crm_shipment_form_url( (int) $order['id'] );
					$order['order_view_url']    = crm_order_view_url( (int) $order['id'] );
					$order['order_edit_url']    = crm_order_form_url( (int) $order['id'] );
					$order['needs_pricing']     = (int) ( $order['lines_unpriced'] ?? 0 ) > 0;
					$order['can_edit']          = CRM_Capability_Registry::user_can_edit_order( $order );
					$order['can_accept']        = ! empty( $order['workflow_blocked'] ) && CRM_Capability_Registry::user_can_accept_orders();
					$order['can_record_export'] = current_user_can( 'crm_shipments_create' ) && ! empty( $order['can_export'] ) && (int) ( $order['lines_to_export'] ?? 0 ) > 0;

					return $order;
				},
				$orders
			);
			$orders = self::sort_orders_work_queue( $orders );
		}

		wp_send_json_success(
			array(
				'orders'         => $orders ? $orders : array(),
				'total'          => $orders ? count( $orders ) : 0,
				'statuses'       => CRM_Order_Status::get_all_active(),
				'tracking_steps' => CRM_Order_Tracking::get_list_filter_options( 'staff' ),
				'summary'        => CRM_Module_Summary::china_orders( $where_sql, $params ),
			)
		);
	}

	/**
	 * Order lines with export due quantities.
	 *
	 * @return void
	 */
	public static function order_lines() {
		self::verify_request( 'crm_shipments_create' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Order is required.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_header( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::enrich_order_export_picker( $order );

		if ( 'cancelled' === $order['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be exported.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! empty( $order['workflow_blocked'] ) ) {
			wp_send_json_error( array( 'message' => self::export_blocked_message( $order ) ) );
		}

		wp_send_json_success(
			array(
				'order'      => $order,
				'lines'      => self::get_order_lines_with_export_due( $order_id ),
				'shipments'  => self::get_shipments_for_order( $order_id ),
				'remaining'  => self::get_export_remaining_summary( $order_id ),
				'can_amend'  => current_user_can( 'crm_shipments_amend' ),
				'can_review' => current_user_can( 'crm_shipments_review' ),
			)
		);
	}

	/**
	 * Create export shipment record.
	 *
	 * @return void
	 */
	public static function save_item() {
		self::verify_request( 'crm_shipments_create' );

		global $wpdb;

		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$ship_date  = isset( $_POST['ship_date'] ) ? crm_normalize_date( wp_unslash( $_POST['ship_date'] ) ) : '';
		$notes      = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		if ( ! $company_id || ! $order_id || ! $ship_date ) {
			wp_send_json_error( array( 'message' => __( 'Order, ship date, and export company are required.', 'ds-prod-import-crm' ) ) );
		}

		$company_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'companies' ) . " WHERE id = %d AND status = 'active'",
				$company_id
			)
		);

		if ( ! $company_exists ) {
			wp_send_json_error( array( 'message' => __( 'Selected export company is not valid.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::fetch_order_header( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'ds-prod-import-crm' ) ) );
		}

		$order = self::enrich_order_export_picker( $order );

		if ( 'cancelled' === $order['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cancelled orders cannot be exported.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! empty( $order['workflow_blocked'] ) ) {
			wp_send_json_error( array( 'message' => self::export_blocked_message( $order ) ) );
		}

		$lines_to_ship = self::parse_items_from_request( $order_id );
		if ( is_wp_error( $lines_to_ship ) ) {
			wp_send_json_error( array( 'message' => $lines_to_ship->get_error_message() ) );
		}

		$total_kg = 0;
		foreach ( $lines_to_ship as $line ) {
			$total_kg += (float) $line['weight_kg'];
		}
		$total_kg = crm_parse_weight( $total_kg );

		$shipment_number = crm_generate_sequence_number( 'EXP', 'export_shipments', 'shipment_number' );
		$shipments_table = crm_table( 'export_shipments' );
		$items_table     = crm_table( 'export_shipment_items' );

		self::begin_transaction();

		$inserted = $wpdb->insert(
			$shipments_table,
			array(
				'shipment_number' => $shipment_number,
				'company_id'      => $company_id,
				'order_id'        => $order_id,
				'ship_date'       => $ship_date,
				'status'          => 'in_transit',
				'total_kg'        => $total_kg,
				'notes'           => $notes,
				'created_by'      => CRM_Audit::current_user_id(),
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			self::rollback_transaction();
			wp_send_json_error( array( 'message' => __( 'Failed to save export shipment.', 'ds-prod-import-crm' ) ) );
		}

		$shipment_id = (int) $wpdb->insert_id;

		foreach ( $lines_to_ship as $line ) {
			$item_inserted = $wpdb->insert(
				$items_table,
				array(
					'shipment_id'   => $shipment_id,
					'order_item_id' => $line['order_item_id'],
					'product_name'  => $line['product_name'],
					'color'         => $line['color'],
					'size'          => $line['size'],
					'quantity'      => $line['ship_qty'],
					'weight_kg'     => $line['weight_kg'],
					'notes'         => $line['notes'],
					'created_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%s', '%s' )
			);

			if ( ! $item_inserted ) {
				self::rollback_transaction();
				wp_send_json_error( array( 'message' => __( 'Failed to save shipment lines.', 'ds-prod-import-crm' ) ) );
			}
		}

		self::commit_transaction();

		self::log_activity(
			'create',
			'shipments',
			$shipment_id,
			sprintf(
				'Recorded export %s for order %s via %s',
				$shipment_number,
				$order['order_number'],
				self::company_name( $company_id )
			),
			array(
				'order_id' => $order_id,
			)
		);

		$remaining = self::get_export_remaining_summary( $order_id );
		$ship_qty  = 0;
		foreach ( $lines_to_ship as $line ) {
			$ship_qty += (int) $line['ship_qty'];
		}

		$message = $remaining['fully_supplied']
			? __( 'Supply confirmed. All accepted quantity is now on the way.', 'ds-prod-import-crm' )
			: sprintf(
				/* translators: 1: pcs just shipped, 2: remaining pcs */
				__( 'Supply confirmed (%1$d pcs). %2$d pcs still remaining — you can supply more later.', 'ds-prod-import-crm' ),
				$ship_qty,
				(int) $remaining['qty_remaining']
			);

		wp_send_json_success(
			array(
				'message'         => $message,
				'id'              => $shipment_id,
				'shipment_number' => $shipment_number,
				'remaining'       => $remaining,
				'shipments'       => self::get_shipments_for_order( $order_id ),
			)
		);
	}

	/**
	 * Change shipping company on an in-transit export (China office may reassign).
	 *
	 * @return void
	 */
	public static function update_company() {
		self::verify_request( 'crm_shipments_create' );

		global $wpdb;

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;

		if ( ! $id || ! $company_id ) {
			wp_send_json_error( array( 'message' => __( 'Shipment and shipping company are required.', 'ds-prod-import-crm' ) ) );
		}

		$shipment = self::fetch_shipment_row( $id );
		if ( ! $shipment ) {
			wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'void' === $shipment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cannot change company on a voided shipment.', 'ds-prod-import-crm' ) ) );
		}

		if ( crm_shipment_is_warehouse_locked( $id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This shipment was already received in Warehouse — company cannot be changed.', 'ds-prod-import-crm' ),
				)
			);
		}

		if ( (int) $shipment['company_id'] === $company_id ) {
			wp_send_json_success(
				array(
					'message'      => __( 'Shipping company unchanged.', 'ds-prod-import-crm' ),
					'company_id'   => $company_id,
					'company_name' => self::company_name( $company_id ),
				)
			);
		}

		$company_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'companies' ) . " WHERE id = %d AND status = 'active'",
				$company_id
			)
		);

		if ( ! $company_exists ) {
			wp_send_json_error( array( 'message' => __( 'Selected shipping company is not valid.', 'ds-prod-import-crm' ) ) );
		}

		$old_name = self::company_name( (int) $shipment['company_id'] );
		$new_name = self::company_name( $company_id );

		$updated = $wpdb->update(
			crm_table( 'export_shipments' ),
			array(
				'company_id' => $company_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'Failed to update shipping company.', 'ds-prod-import-crm' ) ) );
		}

		self::log_activity(
			'update',
			'shipments',
			$id,
			sprintf(
				'Changed shipper on %s from %s to %s',
				$shipment['shipment_number'],
				$old_name,
				$new_name
			),
			array(
				'order_id' => (int) ( $shipment['order_id'] ?? 0 ),
				'changes'  => array(
					sprintf(
						'%s: %s → %s',
						__( 'Shipper', 'ds-prod-import-crm' ),
						$old_name,
						$new_name
					),
				),
			)
		);

		wp_send_json_success(
			array(
				'message'      => __( 'Shipping company updated. Shipment remains on the way.', 'ds-prod-import-crm' ),
				'company_id'   => $company_id,
				'company_name' => $new_name,
			)
		);
	}

	/**
	 * Void an export shipment (keeps audit trail).
	 *
	 * @return void
	 */
	public static function void_item() {
		self::verify_request( 'crm_shipments_void' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shipment ID.', 'ds-prod-import-crm' ) ) );
		}

		$shipment = self::fetch_shipment_row( $id );
		if ( ! $shipment ) {
			wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'void' === $shipment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'This shipment is already void.', 'ds-prod-import-crm' ) ) );
		}

		if ( self::get_pending_amendment_for_shipment( $id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Resolve the pending quantity change request before voiding this shipment.', 'ds-prod-import-crm' ),
				)
			);
		}

		$receive_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'warehouse_receives' ) . ' WHERE shipment_id = %d',
				$id
			)
		);
		if ( $receive_count > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'This shipment already has warehouse receives. Void those receives first (if allowed), then void the shipment.', 'ds-prod-import-crm' ),
				)
			);
		}

		if ( ! self::mark_shipment_void( $shipment ) ) {
			wp_send_json_error( array( 'message' => __( 'Failed to void shipment.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Export shipment voided.', 'ds-prod-import-crm' ) ) );
	}

	/**
	 * Mark an export shipment void and write the audit row.
	 *
	 * @param array<string, mixed> $shipment Shipment row.
	 * @param string               $log_note Optional extra log context.
	 * @return bool
	 */
	private static function mark_shipment_void( array $shipment, $log_note = '' ) {
		global $wpdb;

		$shipment_id = (int) $shipment['id'];
		$receive_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'warehouse_receives' ) . ' WHERE shipment_id = %d',
				$shipment_id
			)
		);

		if ( $receive_count > 0 ) {
			return false;
		}

		$updated = $wpdb->update(
			crm_table( 'export_shipments' ),
			array(
				'status'     => 'void',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $shipment_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$note = $log_note
			? sprintf( 'Voided export %s (%s)', $shipment['shipment_number'], $log_note )
			: sprintf( 'Voided export %s', $shipment['shipment_number'] );

		self::log_activity(
			'void',
			'shipments',
			(int) $shipment['id'],
			$note,
			array(
				'order_id' => (int) ( $shipment['order_id'] ?? 0 ),
			)
		);

		return true;
	}

	/**
	 * Request a per-line quantity reduction on a submitted export shipment.
	 *
	 * Freed quantity becomes available again for a new export (e.g. another shipper).
	 *
	 * @return void
	 */
	public static function request_amendment() {
		self::verify_request( 'crm_shipments_amend' );

		global $wpdb;

		$shipment_id = isset( $_POST['shipment_id'] ) ? absint( $_POST['shipment_id'] ) : 0;
		$reason      = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( $shipment_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid shipment ID.', 'ds-prod-import-crm' ) ) );
		}

		if ( '' === $reason ) {
			wp_send_json_error( array( 'message' => __( 'Please explain why these quantities need to change.', 'ds-prod-import-crm' ) ) );
		}

		$shipment = self::fetch_shipment_row( $shipment_id );
		if ( ! $shipment ) {
			wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'void' === $shipment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cannot change quantities on a voided shipment.', 'ds-prod-import-crm' ) ) );
		}

		if ( crm_shipment_is_warehouse_locked( $shipment_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This shipment was already received in Warehouse — Supply history quantities cannot be changed.', 'ds-prod-import-crm' ),
				)
			);
		}

		$parsed = self::parse_amendment_items_from_request( $shipment_id );
		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error( array( 'message' => $parsed->get_error_message() ) );
		}

		$lines   = $parsed['items'];
		$empties = ! empty( $parsed['empties_shipment'] );

		$overlap_ids = array();
		foreach ( $lines as $line ) {
			$overlap_ids[] = (int) $line['shipment_item_id'];
		}
		$blocked = self::get_pending_amendments_covering_items( $shipment_id, $overlap_ids );
		if ( ! empty( $blocked ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'One or more of these products already have a pending change request. Wait for review, or change another product.', 'ds-prod-import-crm' ),
				)
			);
		}

		$now     = current_time( 'mysql' );
		$user_id = get_current_user_id();

		$inserted = $wpdb->insert(
			crm_table( 'export_shipment_amendments' ),
			array(
				'shipment_id'  => $shipment_id,
				'status'       => 'pending',
				'reason'       => $reason,
				'requested_by' => $user_id,
				'requested_at' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to submit quantity change request.', 'ds-prod-import-crm' ) ) );
		}

		$amendment_id = (int) $wpdb->insert_id;
		$change_lines = array();
		$removes      = 0;

		foreach ( $lines as $line ) {
			$wpdb->insert(
				crm_table( 'export_shipment_amendment_items' ),
				array(
					'amendment_id'     => $amendment_id,
					'shipment_item_id' => $line['shipment_item_id'],
					'order_item_id'    => $line['order_item_id'],
					'product_name'     => $line['product_name'],
					'color'            => $line['color'],
					'size'             => $line['size'],
					'old_quantity'     => $line['old_quantity'],
					'new_quantity'     => $line['new_quantity'],
					'old_weight_kg'    => $line['old_weight_kg'],
					'new_weight_kg'    => $line['new_weight_kg'],
					'created_at'       => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%s' )
			);

			$change_lines[] = sprintf(
				'%s: %d → %d',
				$line['product_name'],
				$line['old_quantity'],
				$line['new_quantity']
			);

			if ( (int) $line['new_quantity'] < 1 ) {
				++$removes;
			}
		}

		self::log_activity(
			'update',
			'shipments',
			$shipment_id,
			sprintf(
				'Requested quantity change on %s (%d line%s)',
				$shipment['shipment_number'],
				count( $lines ),
				1 === count( $lines ) ? '' : 's'
			),
			array(
				'order_id'     => (int) ( $shipment['order_id'] ?? 0 ),
				'amendment_id' => $amendment_id,
				'changes'      => $change_lines,
			)
		);

		if ( $empties ) {
			$message = __( 'Request submitted. After approval this shipment will have no remaining products and will be voided so the qty can be exported again.', 'ds-prod-import-crm' );
		} elseif ( $removes > 0 ) {
			$message = __( 'Request submitted. After approval this product will be removed from the shipment and the qty can be exported again.', 'ds-prod-import-crm' );
		} else {
			$message = __( 'Quantity change request submitted for supervisor review.', 'ds-prod-import-crm' );
		}

		wp_send_json_success(
			array(
				'message'           => $message,
				'amendment_id'      => $amendment_id,
				'empties_shipment'  => $empties,
			)
		);
	}

	/**
	 * Approve or decline a pending export quantity change request.
	 *
	 * Supervisors/admins may only accept or decline — they cannot edit quantities.
	 *
	 * @return void
	 */
	public static function review_amendment() {
		self::verify_request( 'crm_shipments_review' );

		global $wpdb;

		$amendment_id = isset( $_POST['amendment_id'] ) ? absint( $_POST['amendment_id'] ) : 0;
		$decision     = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$review_notes = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_notes'] ) ) : '';

		if ( $amendment_id < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid change request.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! in_array( $decision, array( 'approved', 'declined' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose accept or decline.', 'ds-prod-import-crm' ) ) );
		}

		$amendment = self::fetch_amendment_row( $amendment_id );
		if ( ! $amendment ) {
			wp_send_json_error( array( 'message' => __( 'Change request not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'pending' !== $amendment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'This change request was already reviewed.', 'ds-prod-import-crm' ) ) );
		}

		$shipment = self::fetch_shipment_row( (int) $amendment['shipment_id'] );
		if ( ! $shipment ) {
			wp_send_json_error( array( 'message' => __( 'Shipment not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'void' === $shipment['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Cannot review changes on a voided shipment.', 'ds-prod-import-crm' ) ) );
		}

		if ( 'approved' === $decision && crm_shipment_is_warehouse_locked( (int) $amendment['shipment_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'This shipment was already received in Warehouse — decline the change request instead; quantities can no longer be edited.', 'ds-prod-import-crm' ),
				)
			);
		}

		$now     = current_time( 'mysql' );
		$user_id = get_current_user_id();

		if ( 'declined' === $decision ) {
			$updated = $wpdb->update(
				crm_table( 'export_shipment_amendments' ),
				array(
					'status'       => 'declined',
					'review_notes' => $review_notes,
					'reviewed_by'  => $user_id,
					'reviewed_at'  => $now,
					'updated_at'   => $now,
				),
				array( 'id' => $amendment_id ),
				array( '%s', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to decline the change request.', 'ds-prod-import-crm' ) ) );
			}

			self::log_activity(
				'update',
				'shipments',
				(int) $shipment['id'],
				sprintf( 'Declined quantity change request on %s', $shipment['shipment_number'] ),
				array(
					'order_id'     => (int) ( $shipment['order_id'] ?? 0 ),
					'amendment_id' => $amendment_id,
				)
			);

			wp_send_json_success(
				array(
					'message' => __( 'Quantity change request declined. Shipment quantities are unchanged.', 'ds-prod-import-crm' ),
				)
			);
		}

		$items = self::get_amendment_items( $amendment_id );
		if ( empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'This change request has no line items.', 'ds-prod-import-crm' ) ) );
		}

		$current_lines = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, quantity, weight_kg FROM ' . crm_table( 'export_shipment_items' ) . ' WHERE shipment_id = %d',
				(int) $shipment['id']
			),
			ARRAY_A
		);
		$current_by_id = array();
		$remaining_qty = 0;
		foreach ( $current_lines ? $current_lines : array() as $line ) {
			$current_by_id[ (int) $line['id'] ] = $line;
			$remaining_qty                     += (int) $line['quantity'];
		}

		// Re-validate against current shipment lines before applying.
		foreach ( $items as $item ) {
			$sid = (int) $item['shipment_item_id'];
			if ( ! isset( $current_by_id[ $sid ] ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: product name */
							__( '%s is no longer on this shipment. Decline and request again if needed.', 'ds-prod-import-crm' ),
							$item['product_name']
						),
					)
				);
			}

			$current = $current_by_id[ $sid ];
			if ( (int) $current['quantity'] !== (int) $item['old_quantity'] ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: product name */
							__( '%s quantity changed since this request was submitted. Decline and request again.', 'ds-prod-import-crm' ),
							$item['product_name']
						),
					)
				);
			}

			$new_qty = (int) $item['new_quantity'];
			if ( $new_qty < 0 || $new_qty > (int) $current['quantity'] ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid approved quantities. Decline and request again.', 'ds-prod-import-crm' ),
					)
				);
			}

			$remaining_qty -= ( (int) $current['quantity'] - $new_qty );
		}

		$void_after   = $remaining_qty < 1;
		$removed      = 0;
		$change_lines = array();

		foreach ( $items as $item ) {
			$shipment_item_id = (int) $item['shipment_item_id'];
			$new_qty          = (int) $item['new_quantity'];
			$new_weight       = (float) $item['new_weight_kg'];

			if ( $new_qty < 1 ) {
				++$removed;
				$wpdb->delete(
					crm_table( 'export_shipment_items' ),
					array( 'id' => $shipment_item_id ),
					array( '%d' )
				);
			} else {
				$wpdb->update(
					crm_table( 'export_shipment_items' ),
					array(
						'quantity'  => $new_qty,
						'weight_kg' => $new_weight,
					),
					array( 'id' => $shipment_item_id ),
					array( '%d', '%f' ),
					array( '%d' )
				);
			}

			$change_lines[] = sprintf(
				'%s: %d → %d',
				$item['product_name'],
				(int) $item['old_quantity'],
				$new_qty
			);
		}

		$total_kg = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(weight_kg), 0) FROM ' . crm_table( 'export_shipment_items' ) . ' WHERE shipment_id = %d',
				(int) $shipment['id']
			)
		);

		$wpdb->update(
			crm_table( 'export_shipments' ),
			array(
				'total_kg'   => $total_kg,
				'updated_at' => $now,
			),
			array( 'id' => (int) $shipment['id'] ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		$wpdb->update(
			crm_table( 'export_shipment_amendments' ),
			array(
				'status'       => 'approved',
				'review_notes' => $review_notes,
				'reviewed_by'  => $user_id,
				'reviewed_at'  => $now,
				'updated_at'   => $now,
			),
			array( 'id' => $amendment_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$did_void = false;
		if ( $void_after ) {
			$did_void = self::mark_shipment_void(
				$shipment,
				__( 'no products remaining after approved qty change', 'ds-prod-import-crm' )
			);
		}

		self::log_activity(
			'update',
			'shipments',
			(int) $shipment['id'],
			sprintf( 'Approved quantity change on %s', $shipment['shipment_number'] ),
			array(
				'order_id'     => (int) ( $shipment['order_id'] ?? 0 ),
				'amendment_id' => $amendment_id,
				'changes'      => $change_lines,
			)
		);

		if ( $did_void ) {
			$message = __( 'Change approved. The shipment had no remaining products and was voided. Qty is available to export again.', 'ds-prod-import-crm' );
		} elseif ( $removed > 0 ) {
			$message = __( 'Change approved. Product removed from this shipment. Qty is available to export again.', 'ds-prod-import-crm' );
		} else {
			$message = __( 'Quantity change approved. Reduced quantities are available to export again with another shipper if needed.', 'ds-prod-import-crm' );
		}

		wp_send_json_success(
			array(
				'message' => $message,
			)
		);
	}

	/**
	 * List export quantity change requests as product-level rows (supervisor review board).
	 *
	 * @return void
	 */
	public static function list_amendments() {
		self::verify_request( 'crm_shipments_view' );

		global $wpdb;

		$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';
		$pagination = self::pagination_from_request();
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$allowed_status = array( 'pending', 'approved', 'declined', 'all' );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'pending';
		}

		$amend_table = crm_table( 'export_shipment_amendments' );
		$ship_table  = crm_table( 'export_shipments' );
		$orders      = crm_table( 'orders' );
		$clients     = crm_table( 'clients' );
		$companies   = crm_table( 'companies' );
		$items_table = crm_table( 'export_shipment_amendment_items' );

		$where  = array( '1=1' );
		$params = array();

		if ( 'all' !== $status ) {
			$where[]  = 'a.status = %s';
			$params[] = $status;
		}

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(s.shipment_number LIKE %s OR o.order_number LIKE %s OR cl.name LIKE %s OR co.name LIKE %s OR ai.product_name LIKE %s OR a.reason LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$items_table} ai
			INNER JOIN {$amend_table} a ON a.id = ai.amendment_id
			INNER JOIN {$ship_table} s ON s.id = a.shipment_id
			LEFT JOIN {$orders} o ON o.id = s.order_id
			LEFT JOIN {$clients} cl ON cl.id = o.client_id
			LEFT JOIN {$companies} co ON co.id = s.company_id
			WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT ai.id AS line_id, ai.amendment_id, ai.shipment_item_id, ai.order_item_id,
			ai.product_name, ai.color, ai.size,
			ai.old_quantity, ai.new_quantity, ai.old_weight_kg, ai.new_weight_kg,
			a.status, a.reason, a.requested_by, a.requested_at, a.reviewed_by, a.reviewed_at, a.review_notes,
			s.id AS shipment_id, s.shipment_number, s.ship_date, s.order_id, s.company_id, s.status AS shipment_status,
			o.order_number, cl.name AS client_name, co.name AS company_name
			FROM {$items_table} ai
			INNER JOIN {$amend_table} a ON a.id = ai.amendment_id
			INNER JOIN {$ship_table} s ON s.id = a.shipment_id
			LEFT JOIN {$orders} o ON o.id = s.order_id
			LEFT JOIN {$clients} cl ON cl.id = o.client_id
			LEFT JOIN {$companies} co ON co.id = s.company_id
			WHERE {$where_sql}
			ORDER BY FIELD(a.status, 'pending', 'approved', 'declined'), s.shipment_number ASC, a.requested_at DESC, ai.id ASC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		$pending_total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$items_table} ai
			INNER JOIN {$amend_table} a ON a.id = ai.amendment_id
			WHERE a.status = 'pending'"
		);

		if ( $items ) {
			foreach ( $items as &$row ) {
				$row['amendment_id']     = (int) $row['amendment_id'];
				$row['shipment_id']      = (int) $row['shipment_id'];
				$row['old_quantity']     = (int) $row['old_quantity'];
				$row['new_quantity']     = (int) $row['new_quantity'];
				$row['qty_released']     = max( 0, (int) $row['old_quantity'] - (int) $row['new_quantity'] );
				$row['old_weight_kg']    = (float) $row['old_weight_kg'];
				$row['new_weight_kg']    = (float) $row['new_weight_kg'];
				$row['requested_by_name'] = '';
				$row['reviewed_by_name']  = '';
				if ( ! empty( $row['requested_by'] ) ) {
					$user = get_userdata( (int) $row['requested_by'] );
					$row['requested_by_name'] = $user ? $user->display_name : '';
				}
				if ( ! empty( $row['reviewed_by'] ) ) {
					$user = get_userdata( (int) $row['reviewed_by'] );
					$row['reviewed_by_name'] = $user ? $user->display_name : '';
				}
				$row['time'] = crm_tracking_datetime_payload( (string) ( $row['requested_at'] ?? '' ) );
			}
			unset( $row );
		}

		wp_send_json_success(
			array(
				'items'         => $items ? $items : array(),
				'total'         => $total,
				'pending_total' => $pending_total,
				'page'          => $pagination['page'],
				'per_page'      => $pagination['per_page'],
				'total_pages'   => max( 1, $total_pages ),
				'can_review'    => current_user_can( 'crm_shipments_review' ),
				'can_amend'     => current_user_can( 'crm_shipments_amend' ),
			)
		);
	}

	/**
	 * Pending amendments for a shipment (newest first).
	 *
	 * @param int $shipment_id Shipment ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_pending_amendments_for_shipment( $shipment_id ) {
		global $wpdb;

		$shipment_id = absint( $shipment_id );
		if ( $shipment_id < 1 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . crm_table( 'export_shipment_amendments' ) . " WHERE shipment_id = %d AND status = 'pending' ORDER BY id DESC",
				$shipment_id
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = self::enrich_amendment_payload( $row );
		}

		return $out;
	}

	/**
	 * Pending amendment payload for a shipment (or null) — newest pending.
	 *
	 * @param int $shipment_id Shipment ID.
	 * @return array<string, mixed>|null
	 */
	private static function get_pending_amendment_for_shipment( $shipment_id ) {
		$list = self::get_pending_amendments_for_shipment( $shipment_id );
		return ! empty( $list ) ? $list[0] : null;
	}

	/**
	 * Whether any pending amendment already covers these shipment item IDs.
	 *
	 * @param int               $shipment_id Shipment ID.
	 * @param array<int, int>   $item_ids    Shipment item IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_pending_amendments_covering_items( $shipment_id, array $item_ids ) {
		$item_ids = array_values( array_unique( array_filter( array_map( 'absint', $item_ids ) ) ) );
		if ( empty( $item_ids ) ) {
			return array();
		}

		$blocked = array();
		foreach ( self::get_pending_amendments_for_shipment( $shipment_id ) as $amendment ) {
			foreach ( $amendment['items'] as $line ) {
				if ( in_array( (int) $line['shipment_item_id'], $item_ids, true ) ) {
					$blocked[] = $amendment;
					break;
				}
			}
		}

		return $blocked;
	}

	/**
	 * Amendment header row.
	 *
	 * @param int $amendment_id Amendment ID.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_amendment_row( $amendment_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . crm_table( 'export_shipment_amendments' ) . ' WHERE id = %d',
				absint( $amendment_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Line items for an amendment.
	 *
	 * @param int $amendment_id Amendment ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_amendment_items( $amendment_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . crm_table( 'export_shipment_amendment_items' ) . ' WHERE amendment_id = %d ORDER BY id ASC',
				absint( $amendment_id )
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Enrich amendment with requester names and line items.
	 *
	 * @param array<string, mixed> $row Amendment row.
	 * @return array<string, mixed>
	 */
	private static function enrich_amendment_payload( array $row ) {
		$row['id']          = (int) $row['id'];
		$row['shipment_id'] = (int) $row['shipment_id'];
		$row['requested_by_name'] = '';
		$row['reviewed_by_name']  = '';

		if ( ! empty( $row['requested_by'] ) ) {
			$user = get_userdata( (int) $row['requested_by'] );
			$row['requested_by_name'] = $user ? $user->display_name : '';
		}
		if ( ! empty( $row['reviewed_by'] ) ) {
			$user = get_userdata( (int) $row['reviewed_by'] );
			$row['reviewed_by_name'] = $user ? $user->display_name : '';
		}

		$items = self::get_amendment_items( (int) $row['id'] );
		foreach ( $items as &$item ) {
			$item['old_quantity']  = (int) $item['old_quantity'];
			$item['new_quantity']  = (int) $item['new_quantity'];
			$item['qty_released']  = max( 0, $item['old_quantity'] - $item['new_quantity'] );
			$item['old_weight_kg'] = (float) $item['old_weight_kg'];
			$item['new_weight_kg'] = (float) $item['new_weight_kg'];
		}
		unset( $item );

		$row['items'] = $items;
		$row['time']  = crm_tracking_datetime_payload( (string) ( $row['requested_at'] ?? $row['created_at'] ?? '' ) );

		return $row;
	}

	/**
	 * Parse amendment line reductions from JSON request.
	 *
	 * @param int $shipment_id Shipment ID.
	 * @return array{items:array<int, array<string, mixed>>, empties_shipment:bool}|\WP_Error
	 */
	private static function parse_amendment_items_from_request( $shipment_id ) {
		global $wpdb;

		$raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items = json_decode( $raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'items_required', __( 'Change at least one product quantity or weight.', 'ds-prod-import-crm' ) );
		}

		$current_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . crm_table( 'export_shipment_items' ) . ' WHERE shipment_id = %d',
				$shipment_id
			),
			ARRAY_A
		);

		$by_id = array();
		foreach ( $current_rows ? $current_rows : array() as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		$parsed          = array();
		$would_leave_qty = 0;

		foreach ( $by_id as $row ) {
			$would_leave_qty += (int) $row['quantity'];
		}

		foreach ( $items as $index => $item ) {
			$shipment_item_id = isset( $item['shipment_item_id'] ) ? absint( $item['shipment_item_id'] ) : 0;
			$new_qty          = array_key_exists( 'new_quantity', $item ) ? absint( $item['new_quantity'] ) : -1;

			if ( $shipment_item_id < 1 || ! isset( $by_id[ $shipment_item_id ] ) ) {
				return new \WP_Error(
					'invalid_line',
					sprintf(
						/* translators: %d: line number */
						__( 'Line %d: invalid shipment product.', 'ds-prod-import-crm' ),
						$index + 1
					)
				);
			}

			$current    = $by_id[ $shipment_item_id ];
			$old_qty    = (int) $current['quantity'];
			$old_weight = (float) $current['weight_kg'];

			if ( $new_qty < 0 || $new_qty > $old_qty ) {
				return new \WP_Error(
					'qty_invalid',
					sprintf(
						/* translators: 1: product name, 2: current qty */
						__( '%1$s: new quantity cannot be higher than the shipped quantity (%2$d).', 'ds-prod-import-crm' ),
						$current['product_name'],
						$old_qty
					)
				);
			}

			if ( array_key_exists( 'new_weight_kg', $item ) && '' !== $item['new_weight_kg'] && null !== $item['new_weight_kg'] ) {
				$new_weight = crm_parse_weight( $item['new_weight_kg'] );
			} elseif ( $new_qty !== $old_qty && $old_qty > 0 ) {
				$new_weight = round( $old_weight * ( $new_qty / $old_qty ), 3 );
			} else {
				$new_weight = $old_weight;
			}

			if ( $new_weight < 0 ) {
				return new \WP_Error(
					'weight_invalid',
					sprintf(
						/* translators: %s: product name */
						__( '%s: weight cannot be negative.', 'ds-prod-import-crm' ),
						$current['product_name']
					)
				);
			}

			$qty_changed    = $new_qty !== $old_qty;
			$weight_changed = abs( $new_weight - $old_weight ) > 0.0005;

			if ( ! $qty_changed && ! $weight_changed ) {
				continue;
			}

			if ( $new_qty < 1 && $new_weight > 0 ) {
				$new_weight = 0.0;
			}

			$would_leave_qty -= ( $old_qty - $new_qty );

			$parsed[] = array(
				'shipment_item_id' => $shipment_item_id,
				'order_item_id'    => (int) $current['order_item_id'],
				'product_name'     => (string) $current['product_name'],
				'color'            => (string) ( $current['color'] ?? '' ),
				'size'             => (string) ( $current['size'] ?? '' ),
				'old_quantity'     => $old_qty,
				'new_quantity'     => $new_qty,
				'old_weight_kg'    => $old_weight,
				'new_weight_kg'    => $new_weight,
			);
		}

		if ( empty( $parsed ) ) {
			return new \WP_Error( 'items_required', __( 'Change at least one product quantity or weight.', 'ds-prod-import-crm' ) );
		}

		return array(
			'items'             => $parsed,
			'empties_shipment'  => $would_leave_qty < 1,
		);
	}

	/**
	 * Parse shipment lines from JSON request.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function parse_items_from_request( $order_id ) {
		$raw   = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
		$items = json_decode( $raw, true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'items_required', __( 'Add at least one line with a ship quantity.', 'ds-prod-import-crm' ) );
		}

		$lines_by_id = array();
		foreach ( self::get_order_lines_with_export_due( $order_id ) as $line ) {
			$lines_by_id[ (int) $line['id'] ] = $line;
		}

		$parsed = array();

		foreach ( $items as $index => $item ) {
			$order_item_id = isset( $item['order_item_id'] ) ? absint( $item['order_item_id'] ) : 0;
			$ship_qty      = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;

			if ( $ship_qty < 1 ) {
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

			if ( (float) ( $line['unit_price'] ?? 0 ) <= 0 ) {
				return new \WP_Error(
					'price_required',
					sprintf(
						/* translators: %s: product name */
						__( '%s: set a unit price before confirming supply for this product.', 'ds-prod-import-crm' ),
						$line['product_name']
					)
				);
			}

			if ( $ship_qty > (int) $line['qty_to_export'] ) {
				return new \WP_Error(
					'qty_exceeds_due',
					sprintf(
						/* translators: 1: product name, 2: due qty */
						__( '%1$s: cannot ship more than %2$d (quantity still to export).', 'ds-prod-import-crm' ),
						$line['product_name'],
						(int) $line['qty_to_export']
					)
				);
			}

			$weight_kg = isset( $item['weight_kg'] ) ? crm_parse_weight( $item['weight_kg'] ) : 0;

			$parsed[] = array(
				'order_item_id' => $order_item_id,
				'product_name'  => $line['product_name'],
				'color'         => $line['color'],
				'size'          => $line['size'],
				'ship_qty'      => $ship_qty,
				'weight_kg'     => $weight_kg,
				'notes'         => isset( $item['notes'] ) ? sanitize_textarea_field( $item['notes'] ) : '',
			);
		}

		if ( empty( $parsed ) ) {
			return new \WP_Error( 'items_required', __( 'Add at least one line with a ship quantity.', 'ds-prod-import-crm' ) );
		}

		return $parsed;
	}

	/**
	 * Order lines with export due qty.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_order_lines_with_export_due( $order_id ) {
		global $wpdb;

		$items_table    = crm_table( 'order_items' );
		$products_table = crm_table( 'products' );
		$qty_exported   = self::sql_qty_exported( 'oi' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT oi.*, " . crm_sql_product_image_url( 'p' ) . " AS product_image_url,
				NULLIF(p.image_url, '') AS product_full_image_url,
				{$qty_exported} AS qty_exported
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
			$row['qty_exported']       = (int) $row['qty_exported'];
			$row['qty_ordered']        = (int) $row['quantity'];
			$row['accepted_quantity']  = array_key_exists( 'accepted_quantity', $row ) && null !== $row['accepted_quantity'] && '' !== $row['accepted_quantity']
				? (int) $row['accepted_quantity']
				: null;
			$row['qty_accepted']       = crm_order_item_accepted_qty( $row );
			$row['qty_to_export']      = max( 0, $row['qty_accepted'] - $row['qty_exported'] );
			$row['product_image_url']  = ! empty( $row['product_image_url'] ) ? esc_url_raw( $row['product_image_url'] ) : '';
			$row['product_full_image_url'] = ! empty( $row['product_full_image_url'] )
				? esc_url_raw( $row['product_full_image_url'] )
				: $row['product_image_url'];
			CRM_Order_Item_Priority::enrich( $row );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Shipment header row.
	 *
	 * @param int $id Shipment ID.
	 * @return array<string, mixed>|null
	 */
	private static function fetch_shipment_row( $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.*, o.order_number, cl.name AS client_name, cl.phone AS client_phone, co.name AS company_name
				FROM ' . crm_table( 'export_shipments' ) . ' s
				LEFT JOIN ' . crm_table( 'orders' ) . ' o ON o.id = s.order_id
				LEFT JOIN ' . crm_table( 'clients' ) . ' cl ON cl.id = o.client_id
				LEFT JOIN ' . crm_table( 'companies' ) . ' co ON co.id = s.company_id
				WHERE s.id = %d',
				$id
			),
			ARRAY_A
		);
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
				'SELECT o.*, cl.name AS client_name, cl.phone AS client_phone
				FROM ' . crm_table( 'orders' ) . ' o
				LEFT JOIN ' . crm_table( 'clients' ) . ' cl ON cl.id = o.client_id
				WHERE o.id = %d',
				$order_id
			),
			ARRAY_A
		);
	}

	/**
	 * Company display name.
	 *
	 * @param int $company_id Company ID.
	 * @return string
	 */
	private static function company_name( $company_id ) {
		global $wpdb;

		$name = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT name FROM ' . crm_table( 'companies' ) . ' WHERE id = %d',
				$company_id
			)
		);

		return $name ? (string) $name : '—';
	}

	/**
	 * Add export-picker flags and status label to an order row.
	 *
	 * @param array<string, mixed> $order Order row.
	 * @return array<string, mixed>
	 */
	public static function enrich_order_export_picker( array $order ) {
		$status = (string) ( $order['status'] ?? '' );
		$map    = CRM_Order_Status::get_status_map();

		$order['status_label']     = isset( $map[ $status ]['label'] ) ? (string) $map[ $status ]['label'] : $status;
		$order['workflow_blocked'] = CRM_Order_Status::blocks_workflow( $status );
		$order['can_export']       = ! $order['workflow_blocked'] && 'cancelled' !== $status;

		return $order;
	}

	/**
	 * Exportable orders first in shipment picker search.
	 *
	 * @param array<int, array<string, mixed>> $orders Order rows.
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Urgent / priority orders first in the shipments list queue.
	 *
	 * @param array<int, array<string, mixed>> $orders Order rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sort_orders_work_queue( array $orders ) {
		$priority_rank = array(
			CRM_Order_Item_Priority::URGENT   => 0,
			CRM_Order_Item_Priority::PRIORITY => 1,
			CRM_Order_Item_Priority::NORMAL   => 2,
		);

		usort(
			$orders,
			static function ( $a, $b ) use ( $priority_rank ) {
				$a_blocked = ! empty( $a['workflow_blocked'] ) ? 1 : 0;
				$b_blocked = ! empty( $b['workflow_blocked'] ) ? 1 : 0;
				if ( $a_blocked !== $b_blocked ) {
					return $b_blocked <=> $a_blocked;
				}

				$a_pricing = ! empty( $a['needs_pricing'] ) ? 1 : 0;
				$b_pricing = ! empty( $b['needs_pricing'] ) ? 1 : 0;
				if ( $a_pricing !== $b_pricing ) {
					return $b_pricing <=> $a_pricing;
				}

				$a_urgent = (int) ( $a['urgent_count'] ?? 0 );
				$b_urgent = (int) ( $b['urgent_count'] ?? 0 );

				if ( $a_urgent !== $b_urgent ) {
					return $b_urgent <=> $a_urgent;
				}

				$a_pri = $priority_rank[ $a['top_priority'] ?? CRM_Order_Item_Priority::NORMAL ] ?? 2;
				$b_pri = $priority_rank[ $b['top_priority'] ?? CRM_Order_Item_Priority::NORMAL ] ?? 2;

				if ( $a_pri !== $b_pri ) {
					return $a_pri <=> $b_pri;
				}

				$a_date = strtotime( (string) ( $a['order_date'] ?? '' ) );
				$b_date = strtotime( (string) ( $b['order_date'] ?? '' ) );

				if ( $a_date !== $b_date ) {
					return $b_date <=> $a_date;
				}

				return (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 );
			}
		);

		return $orders;
	}

	private static function sort_orders_ready_for_export( array $orders ) {
		return self::sort_orders_work_queue( $orders );
	}

	private static function sort_export_picker_orders( array $orders ) {
		usort(
			$orders,
			static function ( $a, $b ) {
				$a_export = ! empty( $a['can_export'] ) ? 1 : 0;
				$b_export = ! empty( $b['can_export'] ) ? 1 : 0;

				if ( $a_export !== $b_export ) {
					return $b_export <=> $a_export;
				}

				return 0;
			}
		);

		return $orders;
	}

	/**
	 * User-facing message when export is blocked by order status.
	 *
	 * @param array<string, mixed> $order Order row with optional status_label.
	 * @return string
	 */
	private static function export_blocked_message( array $order ) {
		$label = trim( (string) ( $order['status_label'] ?? '' ) );

		if ( '' !== $label ) {
			return sprintf(
				/* translators: %s: order status label */
				__( 'This order is "%s" and must be accepted before it can be exported.', 'ds-prod-import-crm' ),
				$label
			);
		}

		return __( 'This order must be accepted before it can be exported.', 'ds-prod-import-crm' );
	}
}
