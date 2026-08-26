<?php
/**
 * Summary KPI cards for CRM module list pages.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Build filtered summary metrics returned with list AJAX responses.
 */
class CRM_Module_Summary {
	/**
	 * One summary card for the frontend grid.
	 *
	 * @param string $label            Display label.
	 * @param string $value            Display value.
	 * @param string $tone             Optional tone slug (blue, green, amber, etc.).
	 * @param string $filter_status    Optional status slug for clickable KPI filters.
	 * @param string $filter_tracking  Optional tracking slug for clickable KPI filters.
	 * @param string $filter_period    Optional period slug (all|today|week|month).
	 * @return array{label:string,value:string,tone:string,filter_status:string,filter_tracking:string,filter_period:string}
	 */
	public static function card( $label, $value, $tone = 'blue', $filter_status = '', $filter_tracking = '', $filter_period = '' ) {
		return array(
			'label'           => (string) $label,
			'value'           => (string) $value,
			'tone'            => sanitize_key( $tone ),
			'filter_status'   => sanitize_key( $filter_status ),
			'filter_tracking' => sanitize_key( $filter_tracking ),
			'filter_period'   => sanitize_key( $filter_period ),
		);
	}

	/**
	 * Run a prepared row query.
	 *
	 * @param string               $sql    SQL with placeholders.
	 * @param array<int, mixed>    $params Query params.
	 * @return array<string, mixed>
	 */
	private static function row( $sql, array $params = array() ) {
		global $wpdb;

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (array) $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_row( $sql, ARRAY_A );
	}

	/**
	 * Orders list summary.
	 *
	 * @param string             $where_sql WHERE clause (uses o/cl aliases).
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function orders( $where_sql, array $params = array() ) {
		global $wpdb;

		$orders_table = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );

		$from = "FROM {$orders_table} o LEFT JOIN {$clients_table} cl ON cl.id = o.client_id WHERE {$where_sql}";

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN o.status = 'awaiting_acceptance' THEN 1 ELSE 0 END) AS awaiting,
				SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) AS completed,
				SUM(CASE WHEN o.status = 'partial_delivered' THEN 1 ELSE 0 END) AS partial,
				SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
			{$from}",
			$params
		);

		$cards = array(
			self::card( __( 'Total orders', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue', 'all' ),
			self::card( __( 'Awaiting acceptance', 'ds-prod-import-crm' ), (string) (int) ( $stats['awaiting'] ?? 0 ), 'rose', 'awaiting_acceptance' ),
			self::card( __( 'Pending', 'ds-prod-import-crm' ), (string) (int) ( $stats['pending'] ?? 0 ), 'amber', 'pending' ),
			self::card( __( 'Partial delivered', 'ds-prod-import-crm' ), (string) (int) ( $stats['partial'] ?? 0 ), 'purple', 'partial_delivered' ),
			self::card( __( 'Completed', 'ds-prod-import-crm' ), (string) (int) ( $stats['completed'] ?? 0 ), 'teal', 'completed' ),
			self::card( __( 'Cancelled', 'ds-prod-import-crm' ), (string) (int) ( $stats['cancelled'] ?? 0 ), 'slate', 'cancelled' ),
		);

		$billing = self::orders_billing_totals_for_filter( $where_sql, $params );
		$cards[] = self::card( __( 'Total order bill', 'ds-prod-import-crm' ), crm_format_amount( $billing['order_bill'] ), 'indigo' );
		$cards[] = self::card( __( 'Total delivery bill', 'ds-prod-import-crm' ), crm_format_amount( $billing['delivery_bill'] ), 'purple' );
		$cards[] = self::card( __( 'Total paid', 'ds-prod-import-crm' ), crm_format_amount( $billing['total_paid'] ), 'green' );
		$cards[] = self::card(
			__( 'Total due', 'ds-prod-import-crm' ),
			crm_format_amount( $billing['total_due'] ),
			( $billing['total_due'] ?? 0 ) > 0 ? 'rose' : 'teal'
		);

		return $cards;
	}

	/**
	 * China Operations (Orders tab) summary — workflow + export KPIs.
	 *
	 * @param string             $where_sql WHERE clause (uses o/cl aliases).
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function china_orders( $where_sql, array $params = array() ) {
		$orders_table   = crm_table( 'orders' );
		$clients_table  = crm_table( 'clients' );
		$items_table    = crm_table( 'order_items' );
		$ship_table     = crm_table( 'export_shipments' );
		$ship_items     = crm_table( 'export_shipment_items' );
		$from           = "FROM {$orders_table} o LEFT JOIN {$clients_table} cl ON cl.id = o.client_id WHERE {$where_sql}";

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN o.status = 'awaiting_acceptance' THEN 1 ELSE 0 END) AS awaiting,
				SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) AS pending,
				SUM(CASE WHEN o.status = 'partial_delivered' THEN 1 ELSE 0 END) AS partial,
				SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) AS completed
			{$from}",
			$params
		);

		$needs_pricing_sql = "SELECT COUNT(*) {$from}
			AND EXISTS (
				SELECT 1 FROM {$items_table} oi
				WHERE oi.order_id = o.id
				AND COALESCE(oi.accepted_quantity, oi.quantity) > 0
				AND oi.unit_price <= 0
			)";
		$needs_pricing = self::scalar( $needs_pricing_sql, $params );

		$qty_exported = "COALESCE((
			SELECT SUM(esi.quantity) FROM {$ship_items} esi
			INNER JOIN {$ship_table} es ON es.id = esi.shipment_id AND es.status != 'void'
			WHERE esi.order_item_id = oi.id
		), 0)";
		$ready_sql = "SELECT COUNT(*) {$from}
			AND " . CRM_Order_Status::workflow_active_sql( 'o' ) . "
			AND o.status != 'cancelled'
			AND EXISTS (
				SELECT 1 FROM {$items_table} oi
				WHERE oi.order_id = o.id
				AND COALESCE(oi.accepted_quantity, oi.quantity) > {$qty_exported}
			)";
		$ready_to_ship = self::scalar( $ready_sql, $params );

		$urgent_sql = "SELECT COUNT(*) {$from}
			AND EXISTS (
				SELECT 1 FROM {$items_table} oi
				WHERE oi.order_id = o.id
				AND oi.delivery_priority = 'urgent'
				AND COALESCE(oi.accepted_quantity, oi.quantity) > {$qty_exported}
			)";
		$urgent = self::scalar( $urgent_sql, $params );

		$bill = self::row(
			"SELECT COALESCE(SUM(COALESCE(oi.accepted_quantity, oi.quantity) * oi.unit_price), 0) AS total_bill
			FROM {$items_table} oi
			INNER JOIN {$orders_table} o ON o.id = oi.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE {$where_sql}",
			$params
		);

		$exports = self::row(
			"SELECT COUNT(*) AS shipment_count,
				COALESCE(SUM(s.total_kg), 0) AS total_kg
			FROM {$ship_table} s
			INNER JOIN {$orders_table} o ON o.id = s.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE s.status != 'void' AND {$where_sql}",
			$params
		);

		$in_transit  = 0;
		$received_bd = 0;
		$supplying   = 0;

		foreach ( array( 'to_bangladesh', 'received_bd', 'supplying' ) as $step ) {
			$step_sql = CRM_Order_Tracking::list_filter_sql( $step );
			if ( ! $step_sql ) {
				continue;
			}
			$count = self::scalar( "SELECT COUNT(*) {$from} AND {$step_sql}", $params );
			if ( 'to_bangladesh' === $step ) {
				$in_transit = $count;
			} elseif ( 'received_bd' === $step ) {
				$received_bd = $count;
			} else {
				$supplying = $count;
			}
		}

		return array(
			self::card( __( 'Total orders', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue', 'all' ),
			self::card( __( 'Pending approval', 'ds-prod-import-crm' ), (string) (int) ( $stats['awaiting'] ?? 0 ), 'rose', 'awaiting_acceptance' ),
			self::card( __( 'Pending', 'ds-prod-import-crm' ), (string) (int) ( $stats['pending'] ?? 0 ), 'amber', 'pending' ),
			self::card( __( 'Needs pricing', 'ds-prod-import-crm' ), (string) $needs_pricing, 'amber' ),
			self::card( __( 'Supply & ship prep', 'ds-prod-import-crm' ), (string) $supplying, 'indigo', '', 'supplying' ),
			self::card( __( 'Ready to ship', 'ds-prod-import-crm' ), (string) $ready_to_ship, 'teal' ),
			self::card( __( 'In transit', 'ds-prod-import-crm' ), (string) $in_transit, 'purple', '', 'to_bangladesh' ),
			self::card( __( 'Received at office', 'ds-prod-import-crm' ), (string) $received_bd, 'blue', '', 'received_bd' ),
			self::card( __( 'Urgent to ship', 'ds-prod-import-crm' ), (string) $urgent, 'rose' ),
			self::card( __( 'Total order bill', 'ds-prod-import-crm' ), crm_format_amount( $bill['total_bill'] ?? 0 ), 'green' ),
			self::card( __( 'Export shipments', 'ds-prod-import-crm' ), (string) (int) ( $exports['shipment_count'] ?? 0 ), 'indigo' ),
			self::card( __( 'Exported kg', 'ds-prod-import-crm' ), crm_format_weight( (float) ( $exports['total_kg'] ?? 0 ) ), 'teal' ),
		);
	}

	/**
	 * Sum allocated billing totals for orders matching the list filter.
	 *
	 * @param string             $where_sql WHERE clause (uses o/cl aliases).
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array{order_bill: float, delivery_bill: float, total_paid: float, total_due: float}
	 */
	private static function orders_billing_totals_for_filter( $where_sql, array $params = array() ) {
		global $wpdb;

		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$ids_sql       = "SELECT o.id, o.client_id FROM {$orders_table} o LEFT JOIN {$clients_table} cl ON cl.id = o.client_id WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = ! empty( $params )
			? $wpdb->get_results( $wpdb->prepare( $ids_sql, $params ), ARRAY_A )
			: $wpdb->get_results( $ids_sql, ARRAY_A );

		$totals = array(
			'order_bill'    => 0.0,
			'delivery_bill' => 0.0,
			'total_paid'    => 0.0,
			'total_due'     => 0.0,
		);

		if ( empty( $rows ) ) {
			return $totals;
		}

		$allocations_by_client = array();
		foreach ( $rows as $row ) {
			$order_id  = (int) ( $row['id'] ?? 0 );
			$client_id = (int) ( $row['client_id'] ?? 0 );
			if ( $order_id < 1 ) {
				continue;
			}

			if ( ! isset( $allocations_by_client[ $client_id ] ) ) {
				$allocations_by_client[ $client_id ] = $client_id > 0
					? CRM_Ledger::allocate_client_payments( $client_id )
					: array();
			}

			$summary = $allocations_by_client[ $client_id ][ $order_id ] ?? CRM_Ledger::get_order_summary( $order_id );
			$totals['order_bill']    += (float) ( $summary['order_bill'] ?? 0 );
			$totals['delivery_bill'] += (float) ( $summary['delivery_bill'] ?? 0 );
			$totals['total_paid']    += (float) ( $summary['total_paid'] ?? 0 );
			$totals['total_due']     += (float) ( $summary['total_due'] ?? 0 );
		}

		foreach ( $totals as $key => $value ) {
			$totals[ $key ] = round( $value, 2 );
		}

		return $totals;
	}

	/**
	 * Clients list summary.
	 *
	 * @param string             $where_sql WHERE clause.
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function clients( $where_sql, array $params = array() ) {
		$table = crm_table( 'clients' );
		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
				SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
			FROM {$table} WHERE {$where_sql}",
			$params
		);

		return array(
			self::card( __( 'Total clients', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue' ),
			self::card( __( 'Active', 'ds-prod-import-crm' ), (string) (int) ( $stats['active'] ?? 0 ), 'green' ),
			self::card( __( 'Inactive', 'ds-prod-import-crm' ), (string) (int) ( $stats['inactive'] ?? 0 ), 'amber' ),
			self::card( __( 'With phone', 'ds-prod-import-crm' ), (string) self::scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql} AND phone IS NOT NULL AND phone != ''", $params ), 'indigo' ),
		);
	}

	/**
	 * Companies list summary.
	 *
	 * @param string             $where_sql WHERE clause.
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function companies( $where_sql, array $params = array() ) {
		$table = crm_table( 'companies' );
		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
				SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive
			FROM {$table} WHERE {$where_sql}",
			$params
		);

		return array(
			self::card( __( 'Total suppliers', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue' ),
			self::card( __( 'Active', 'ds-prod-import-crm' ), (string) (int) ( $stats['active'] ?? 0 ), 'green' ),
			self::card( __( 'Inactive', 'ds-prod-import-crm' ), (string) (int) ( $stats['inactive'] ?? 0 ), 'amber' ),
			self::card( __( 'With phone', 'ds-prod-import-crm' ), (string) self::scalar( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql} AND phone IS NOT NULL AND phone != ''", $params ), 'indigo' ),
		);
	}

	/**
	 * Products list summary.
	 *
	 * @param string             $where_sql WHERE clause (p alias).
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function products( $where_sql, array $params = array() ) {
		$table = crm_table( 'products' );
		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN p.image_url IS NOT NULL AND p.image_url != '' THEN 1 ELSE 0 END) AS with_image,
				AVG(p.unit_price) AS avg_price
			FROM {$table} p WHERE {$where_sql}",
			$params
		);

		$low_stock = self::scalar(
			"SELECT COUNT(*) FROM {$table} p
			LEFT JOIN " . crm_table( 'stock' ) . " s ON s.product_id = p.id
			WHERE {$where_sql} AND COALESCE(s.quantity, 0) <= 5",
			$params
		);

		return array(
			self::card( __( 'Total products', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue' ),
			self::card( __( 'With image', 'ds-prod-import-crm' ), (string) (int) ( $stats['with_image'] ?? 0 ), 'green' ),
			self::card( __( 'Low stock (≤5)', 'ds-prod-import-crm' ), (string) $low_stock, 'amber' ),
			self::card( __( 'Avg unit price', 'ds-prod-import-crm' ), crm_format_amount( $stats['avg_price'] ?? 0 ), 'purple' ),
		);
	}

	/**
	 * Product categories list summary.
	 *
	 * @param string             $where_sql WHERE clause.
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function product_categories( $where_sql, array $params = array() ) {
		$table = crm_table( 'product_categories' );
		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active
			FROM {$table} WHERE {$where_sql}",
			$params
		);

		$products = (int) self::scalar(
			'SELECT COUNT(*) FROM ' . crm_table( 'products' ) . ' WHERE category_id > 0'
		);

		return array(
			self::card( __( 'Total categories', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue' ),
			self::card( __( 'Active', 'ds-prod-import-crm' ), (string) (int) ( $stats['active'] ?? 0 ), 'green' ),
			self::card( __( 'Categorized products', 'ds-prod-import-crm' ), (string) $products, 'indigo' ),
			self::card( __( 'Inactive categories', 'ds-prod-import-crm' ), (string) max( 0, (int) ( $stats['total'] ?? 0 ) - (int) ( $stats['active'] ?? 0 ) ), 'amber' ),
		);
	}

	/**
	 * Warehouse receives list summary.
	 *
	 * @param string             $where_sql WHERE clause (r/co aliases).
	 * @param array<int, mixed>  $params    Bound params.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function warehouse( $where_sql, array $params = array() ) {
		$receive_table = crm_table( 'warehouse_receives' );
		$company_table = crm_table( 'companies' );
		$clients_table = crm_table( 'clients' );
		$ship_table    = crm_table( 'export_shipments' );
		$orders_table  = crm_table( 'orders' );

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(r.total_kg), 0) AS total_kg,
				COALESCE(SUM(r.shipping_bill), 0) AS shipping_bill
			FROM {$receive_table} r
			LEFT JOIN {$company_table} co ON co.id = r.company_id
			LEFT JOIN {$ship_table} s ON s.id = r.shipment_id
			LEFT JOIN {$orders_table} o ON o.id = COALESCE(NULLIF(s.order_id, 0), NULLIF(r.order_id, 0))
			LEFT JOIN {$clients_table} cl ON cl.id = o.client_id
			WHERE {$where_sql}",
			$params
		);

		return array(
			self::card( __( 'Receives', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue' ),
			self::card( __( 'Total kg', 'ds-prod-import-crm' ), crm_format_weight( (float) ( $stats['total_kg'] ?? 0 ) ), 'teal' ),
			self::card( __( 'Shipping bills', 'ds-prod-import-crm' ), crm_format_amount( $stats['shipping_bill'] ?? 0 ), 'green' ),
			self::card( __( 'Avg kg / receive', 'ds-prod-import-crm' ), (int) ( $stats['total'] ?? 0 ) > 0 ? crm_format_weight( (float) $stats['total_kg'] / (int) $stats['total'] ) : '0.00', 'indigo' ),
		);
	}

	/**
	 * Deliveries list summary.
	 *
	 * @param string             $where_sql    WHERE clause for the active list filter (d/o/cl aliases).
	 * @param array<int, mixed>  $params       Bound params for $where_sql.
	 * @param string             $base_sql     WHERE without date/period (for period KPI cards).
	 * @param array<int, mixed>  $base_params  Bound params for $base_sql.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function deliveries( $where_sql, array $params = array(), $base_sql = '', array $base_params = array() ) {
		$table         = crm_table( 'deliveries' );
		$orders_table  = crm_table( 'orders' );
		$clients_table = crm_table( 'clients' );
		$items_table   = crm_table( 'delivery_items' );

		$from = "FROM {$table} d
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id";

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(d.total_kg), 0) AS total_kg,
				COALESCE(SUM(d.shipping_bill), 0) AS shipping_bill,
				COUNT(DISTINCT d.order_id) AS orders
			{$from}
			WHERE {$where_sql}",
			$params
		);

		$qty = (int) self::scalar(
			"SELECT COALESCE(SUM(di.quantity), 0)
			FROM {$items_table} di
			INNER JOIN {$table} d ON d.id = di.delivery_id
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$where_sql}",
			$params
		);

		$base_sql    = $base_sql ? $base_sql : $where_sql;
		$base_params = $base_sql === $where_sql ? $params : $base_params;

		$qty_all = (int) self::scalar(
			"SELECT COALESCE(SUM(di.quantity), 0)
			FROM {$items_table} di
			INNER JOIN {$table} d ON d.id = di.delivery_id
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$base_sql}",
			$base_params
		);

		$today = current_time( 'Y-m-d' );
		$week  = self::period_bounds( 'week' );
		$month = self::period_bounds( 'month' );

		$qty_today = (int) self::scalar(
			"SELECT COALESCE(SUM(di.quantity), 0)
			FROM {$items_table} di
			INNER JOIN {$table} d ON d.id = di.delivery_id
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$base_sql} AND d.delivery_date = %s",
			array_merge( $base_params, array( $today ) )
		);

		$qty_week = (int) self::scalar(
			"SELECT COALESCE(SUM(di.quantity), 0)
			FROM {$items_table} di
			INNER JOIN {$table} d ON d.id = di.delivery_id
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$base_sql} AND d.delivery_date >= %s AND d.delivery_date <= %s",
			array_merge( $base_params, array( $week['from'], $week['to'] ) )
		);

		$qty_month = (int) self::scalar(
			"SELECT COALESCE(SUM(di.quantity), 0)
			FROM {$items_table} di
			INNER JOIN {$table} d ON d.id = di.delivery_id
			LEFT JOIN {$orders_table} o ON o.id = d.order_id
			LEFT JOIN {$clients_table} cl ON cl.id = d.client_id
			WHERE {$base_sql} AND d.delivery_date >= %s AND d.delivery_date <= %s",
			array_merge( $base_params, array( $month['from'], $month['to'] ) )
		);

		$pcs = static function ( $n ) {
			$n = (int) $n;
			return sprintf(
				/* translators: %d: piece count */
				_n( '%d pc', '%d pcs', $n, 'ds-prod-import-crm' ),
				$n
			);
		};

		$total_kg = crm_format_weight( (float) ( $stats['total_kg'] ?? 0 ) );

		return array(
			self::card( __( 'Today', 'ds-prod-import-crm' ), $pcs( $qty_today ), 'amber', '', '', 'today' ),
			self::card( __( 'This week', 'ds-prod-import-crm' ), $pcs( $qty_week ), 'indigo', '', '', 'week' ),
			self::card( __( 'This month', 'ds-prod-import-crm' ), $pcs( $qty_month ), 'teal', '', '', 'month' ),
			self::card( __( 'Total qty', 'ds-prod-import-crm' ), $pcs( $qty_all ), 'blue', '', '', 'all' ),
			self::card(
				__( 'In view', 'ds-prod-import-crm' ),
				$pcs( $qty ) . ' · ' . (string) (int) ( $stats['total'] ?? 0 ),
				'green'
			),
			self::card( __( 'Total kg', 'ds-prod-import-crm' ), $total_kg, 'teal' ),
			self::card( __( 'Shipping bills', 'ds-prod-import-crm' ), crm_format_amount( $stats['shipping_bill'] ?? 0 ), 'green' ),
		);
	}

	/**
	 * Inclusive date bounds for a named period (site timezone).
	 *
	 * @param string $period today|week|month.
	 * @return array{from:string,to:string}
	 */
	public static function delivery_period_bounds( $period ) {
		return self::period_bounds( $period );
	}

	/**
	 * Inclusive date bounds for a named period (site timezone).
	 *
	 * @param string $period today|week|month.
	 * @return array{from:string,to:string}
	 */
	private static function period_bounds( $period ) {
		$today = current_time( 'Y-m-d' );
		$ts    = current_time( 'timestamp' );

		if ( 'today' === $period ) {
			return array(
				'from' => $today,
				'to'   => $today,
			);
		}

		if ( 'week' === $period ) {
			$dow   = (int) wp_date( 'N', $ts ); // 1 = Monday.
			$start = wp_date( 'Y-m-d', strtotime( '-' . ( $dow - 1 ) . ' days', $ts ) );
			return array(
				'from' => $start,
				'to'   => $today,
			);
		}

		if ( 'month' === $period ) {
			return array(
				'from' => wp_date( 'Y-m-01', $ts ),
				'to'   => $today,
			);
		}

		return array(
			'from' => '',
			'to'   => '',
		);
	}

	/**
	 * Client payments list summary (staff).
	 *
	 * @param string            $where_sql    WHERE for the current list (includes dates).
	 * @param array<int, mixed> $params       Bound params for $where_sql.
	 * @param string            $base_sql     WHERE without date/period (for period cards + ledger scope).
	 * @param array<int, mixed> $base_params  Bound params for $base_sql.
	 * @param int               $client_id    Selected client filter (0 = all).
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function payments( $where_sql, array $params = array(), $base_sql = '', array $base_params = array(), $client_id = 0 ) {
		$table         = crm_table( 'payments' );
		$clients_table = crm_table( 'clients' );
		$orders_table  = crm_table( 'orders' );
		$from          = "FROM {$table} p
			LEFT JOIN {$clients_table} cl ON cl.id = p.client_id
			LEFT JOIN {$orders_table} o ON o.id = p.order_id";

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(p.amount), 0) AS total_amount
			{$from}
			WHERE {$where_sql}",
			$params
		);

		$base_sql    = $base_sql ? $base_sql : $where_sql;
		$base_params = $base_sql === $where_sql ? $params : $base_params;

		$today = current_time( 'Y-m-d' );
		$month = self::period_bounds( 'month' );

		$today_amount = self::money(
			"SELECT COALESCE(SUM(p.amount), 0) {$from} WHERE {$base_sql} AND p.payment_date = %s",
			array_merge( $base_params, array( $today ) )
		);
		$month_amount = self::money(
			"SELECT COALESCE(SUM(p.amount), 0) {$from} WHERE {$base_sql} AND p.payment_date >= %s AND p.payment_date <= %s",
			array_merge( $base_params, array( $month['from'], $month['to'] ) )
		);

		$ledger   = $client_id > 0 ? CRM_Ledger::get_client_summary( $client_id ) : CRM_Ledger::get_total_client_summary();
		$due      = (float) ( $ledger['total_due'] ?? 0 );
		$due_tone = $due > 0 ? 'rose' : 'green';
		$list_n   = (int) ( $stats['total'] ?? 0 );
		$list_amt = crm_format_amount( $stats['total_amount'] ?? 0 );

		$cards = array(
			self::card( __( 'Outstanding due', 'ds-prod-import-crm' ), crm_format_amount( $due ), $due_tone ),
			self::card( __( 'Total billed', 'ds-prod-import-crm' ), crm_format_amount( $ledger['total_bill'] ?? 0 ), 'blue' ),
			self::card( __( 'Total collected', 'ds-prod-import-crm' ), crm_format_amount( $ledger['total_paid'] ?? 0 ), 'green' ),
			self::card( __( 'Today', 'ds-prod-import-crm' ), crm_format_amount( $today_amount ), 'amber', '', '', 'today' ),
			self::card( __( 'This month', 'ds-prod-import-crm' ), crm_format_amount( $month_amount ), 'teal', '', '', 'month' ),
			self::card( __( 'In this list', 'ds-prod-import-crm' ), $list_n . ' · ' . $list_amt, 'indigo' ),
		);

		if ( $client_id > 0 ) {
			$order_due    = (float) ( $ledger['order_due'] ?? 0 );
			$delivery_due = (float) ( $ledger['delivery_due'] ?? 0 );
			$cards[]      = self::card( __( 'Order due', 'ds-prod-import-crm' ), crm_format_amount( $order_due ), $order_due > 0 ? 'amber' : 'green' );
			$cards[]      = self::card( __( 'Delivery due', 'ds-prod-import-crm' ), crm_format_amount( $delivery_due ), $delivery_due > 0 ? 'amber' : 'green' );
		} else {
			$cards[] = self::card(
				__( 'Clients paid', 'ds-prod-import-crm' ),
				(string) self::scalar( "SELECT COUNT(DISTINCT p.client_id) {$from} WHERE {$where_sql}", $params ),
				'purple'
			);
		}

		return $cards;
	}

	/**
	 * Supplier payments list summary.
	 *
	 * @param string            $where_sql    WHERE for the current list (includes dates).
	 * @param array<int, mixed> $params       Bound params for $where_sql.
	 * @param string            $base_sql     WHERE without date/period.
	 * @param array<int, mixed> $base_params  Bound params for $base_sql.
	 * @param int               $company_id   Selected company filter (0 = all).
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function supplier_payments( $where_sql, array $params = array(), $base_sql = '', array $base_params = array(), $company_id = 0 ) {
		$table           = crm_table( 'company_payments' );
		$companies_table = crm_table( 'companies' );
		$from            = "FROM {$table} p
			LEFT JOIN {$companies_table} c ON c.id = p.company_id";

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(p.amount), 0) AS total_amount
			{$from}
			WHERE {$where_sql}",
			$params
		);

		$base_sql    = $base_sql ? $base_sql : $where_sql;
		$base_params = $base_sql === $where_sql ? $params : $base_params;

		$today = current_time( 'Y-m-d' );
		$month = self::period_bounds( 'month' );

		$today_amount = self::money(
			"SELECT COALESCE(SUM(p.amount), 0) {$from} WHERE {$base_sql} AND p.payment_date = %s",
			array_merge( $base_params, array( $today ) )
		);
		$month_amount = self::money(
			"SELECT COALESCE(SUM(p.amount), 0) {$from} WHERE {$base_sql} AND p.payment_date >= %s AND p.payment_date <= %s",
			array_merge( $base_params, array( $month['from'], $month['to'] ) )
		);

		$ledger   = $company_id > 0 ? CRM_Ledger::get_company_summary( $company_id ) : CRM_Ledger::get_total_supplier_summary();
		$due      = (float) ( $ledger['total_due'] ?? 0 );
		$due_tone = $due > 0 ? 'rose' : 'green';
		$list_n   = (int) ( $stats['total'] ?? 0 );
		$list_amt = crm_format_amount( $stats['total_amount'] ?? 0 );

		$cards = array(
			self::card( __( 'Outstanding payable', 'ds-prod-import-crm' ), crm_format_amount( $due ), $due_tone ),
			self::card( __( 'Total billed', 'ds-prod-import-crm' ), crm_format_amount( $ledger['total_bill'] ?? 0 ), 'blue' ),
			self::card( __( 'Total paid out', 'ds-prod-import-crm' ), crm_format_amount( $ledger['total_paid'] ?? 0 ), 'green' ),
			self::card( __( 'Today', 'ds-prod-import-crm' ), crm_format_amount( $today_amount ), 'amber', '', '', 'today' ),
			self::card( __( 'This month', 'ds-prod-import-crm' ), crm_format_amount( $month_amount ), 'teal', '', '', 'month' ),
			self::card( __( 'In this list', 'ds-prod-import-crm' ), $list_n . ' · ' . $list_amt, 'indigo' ),
		);

		if ( $company_id > 0 ) {
			$cards[] = self::card( __( 'Receive shipping', 'ds-prod-import-crm' ), crm_format_amount( $ledger['shipping_bill'] ?? 0 ), 'purple' );
			$cards[] = self::card( __( 'Manual bills', 'ds-prod-import-crm' ), crm_format_amount( $ledger['manual_bills'] ?? 0 ), 'amber' );
		} else {
			$cards[] = self::card(
				__( 'Companies paid', 'ds-prod-import-crm' ),
				(string) self::scalar( "SELECT COUNT(DISTINCT p.company_id) {$from} WHERE {$where_sql}", $params ),
				'purple'
			);
		}

		return $cards;
	}

	/**
	 * Activity log summary.
	 *
	 * @param string             $where_sql WHERE clause (a alias).
	 * @param array<int, mixed>  $params    Bound params.
	 * @param bool               $needs_user_join Whether user join is in count query.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function activity( $where_sql, array $params = array(), $needs_user_join = false ) {
		$table = crm_table( 'activity_log' );
		$users = $GLOBALS['wpdb']->users;

		$from = $needs_user_join
			? "FROM {$table} a LEFT JOIN {$users} u ON u.ID = a.user_id WHERE {$where_sql}"
			: "FROM {$table} a WHERE {$where_sql}";

		$stats = self::row(
			"SELECT COUNT(*) AS total,
				SUM(CASE WHEN a.action = 'create' THEN 1 ELSE 0 END) AS created,
				SUM(CASE WHEN a.action = 'update' THEN 1 ELSE 0 END) AS updated,
				SUM(CASE WHEN a.action = 'delete' THEN 1 ELSE 0 END) AS deleted
			{$from}",
			$params
		);

		return array(
			self::card( __( 'Total events', 'ds-prod-import-crm' ), (string) (int) ( $stats['total'] ?? 0 ), 'blue' ),
			self::card( __( 'Created', 'ds-prod-import-crm' ), (string) (int) ( $stats['created'] ?? 0 ), 'green' ),
			self::card( __( 'Updated', 'ds-prod-import-crm' ), (string) (int) ( $stats['updated'] ?? 0 ), 'amber' ),
			self::card( __( 'Deleted', 'ds-prod-import-crm' ), (string) (int) ( $stats['deleted'] ?? 0 ), 'rose' ),
		);
	}

	/**
	 * Order statuses summary.
	 *
	 * @param array<int, array<string, mixed>> $items Status rows.
	 * @return array<int, array{label:string,value:string,tone:string}>
	 */
	public static function order_statuses( array $items ) {
		$total   = count( $items );
		$system  = 0;
		$closed  = 0;
		$auto    = 0;

		foreach ( $items as $item ) {
			if ( ! empty( $item['is_system'] ) ) {
				++$system;
			}
			if ( ! empty( $item['is_closed'] ) ) {
				++$closed;
			}
			if ( ! empty( $item['auto_on_paid'] ) ) {
				++$auto;
			}
		}

		return array(
			self::card( __( 'Total statuses', 'ds-prod-import-crm' ), (string) $total, 'blue' ),
			self::card( __( 'System', 'ds-prod-import-crm' ), (string) $system, 'indigo' ),
			self::card( __( 'Custom', 'ds-prod-import-crm' ), (string) max( 0, $total - $system ), 'teal' ),
			self::card( __( 'Closed states', 'ds-prod-import-crm' ), (string) $closed, 'amber' ),
		);
	}

	/**
	 * Money sum helper.
	 *
	 * @param string            $sql    SQL returning a single numeric value.
	 * @param array<int, mixed> $params Params.
	 * @return float
	 */
	private static function money( $sql, array $params = array() ) {
		global $wpdb;

		if ( ! empty( $params ) ) {
			return (float) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (float) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Scalar query helper.
	 *
	 * @param string             $sql    SQL.
	 * @param array<int, mixed>  $params Params.
	 * @return int
	 */
	private static function scalar( $sql, array $params = array() ) {
		global $wpdb;

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}
}
