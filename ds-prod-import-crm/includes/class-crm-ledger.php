<?php
/**
 * Client & supplier financial ledger calculations.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Central billing math for clients and shipping companies.
 */
class CRM_Ledger {
	/**
	 * Order financial summary for a single order.
	 *
	 * Client payments are purpose-tagged (order bill / delivery bill) and allocated
	 * oldest-order-first within each purpose pool. Legacy "auto" payments use a waterfall.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, float|string>
	 */
	public static function get_order_summary( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return self::empty_client_summary();
		}

		$order_row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT client_id, status FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
				$order_id
			),
			ARRAY_A
		);

		if ( ! $order_row || empty( $order_row['client_id'] ) ) {
			return self::empty_client_summary();
		}

		if ( CRM_Order_Status::blocks_workflow( $order_row['status'] ) || 'cancelled' === $order_row['status'] ) {
			$bills = self::get_order_bills( $order_id );

			return self::build_client_summary( $bills['order_bill'], $bills['delivery_bill'], 0 );
		}

		$client_id = (int) $order_row['client_id'];

		$allocations = self::allocate_client_payments( $client_id );

		if ( isset( $allocations[ $order_id ] ) ) {
			return $allocations[ $order_id ];
		}

		$bills = self::get_order_bills( $order_id );

		return self::build_client_summary( $bills['order_bill'], $bills['delivery_bill'], 0 );
	}

	/**
	 * Product and delivery bills for one order (before payment allocation).
	 *
	 * @param int $order_id Order ID.
	 * @return array{order_bill: float, delivery_bill: float}
	 */
	public static function get_order_bills( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return array(
				'order_bill'    => 0.0,
				'delivery_bill' => 0.0,
			);
		}

		$bills_table = crm_table( 'client_bills' );
		$items_table = crm_table( 'order_items' );

		$order_bill = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$bills_table} WHERE order_id = %d AND bill_type = 'order_bill'",
				$order_id
			)
		);

		if ( $order_bill <= 0 ) {
			$order_bill = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(COALESCE(accepted_quantity, quantity) * unit_price), 0) FROM {$items_table} WHERE order_id = %d",
					$order_id
				)
			);
		}

		return array(
			'order_bill'    => round( max( 0, $order_bill ), 2 ),
			'delivery_bill' => self::get_order_delivery_bill( $order_id ),
		);
	}

	/**
	 * Allocate all client payments across open orders (oldest first).
	 *
	 * Purpose-aware pools:
	 * - Legacy auto payments run first (waterfall: order then delivery, oldest orders first)
	 * - order_bill payments then cover remaining product/order dues only
	 * - delivery_bill payments then cover remaining delivery/shipping dues only
	 *
	 * Applying auto first ensures a new purpose-tagged payment reduces the due the user
	 * actually sees (instead of displacing auto money onto older bills of the same type).
	 * @param int $client_id Client ID.
	 * @return array<int, array<string, float|string>>
	 */
	public static function allocate_client_payments( $client_id ) {
		global $wpdb;

		$client_id = absint( $client_id );
		if ( $client_id < 1 ) {
			return array();
		}

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id FROM " . crm_table( 'orders' ) . " o
				WHERE o.client_id = %d AND o.status != 'cancelled'
				AND " . CRM_Order_Status::workflow_active_sql( 'o' ) . '
				ORDER BY o.order_date ASC, o.id ASC',
				$client_id
			),
			ARRAY_A
		);

		if ( ! $orders ) {
			return array();
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT amount, payment_purpose FROM ' . crm_table( 'payments' ) . ' WHERE client_id = %d',
				$client_id
			),
			ARRAY_A
		);

		$order_pool    = 0.0;
		$delivery_pool = 0.0;
		$auto_pool     = 0.0;

		foreach ( (array) $payments as $payment ) {
			$amount   = round( max( 0, (float) $payment['amount'] ), 2 );
			$purpose  = self::normalize_payment_purpose( $payment['payment_purpose'] ?? 'auto' );
			if ( 'order_bill' === $purpose ) {
				$order_pool += $amount;
			} elseif ( 'delivery_bill' === $purpose ) {
				$delivery_pool += $amount;
			} else {
				$auto_pool += $amount;
			}
		}

		$order_pool    = round( $order_pool, 2 );
		$delivery_pool = round( $delivery_pool, 2 );
		$auto_pool     = round( $auto_pool, 2 );

		$state = array();
		foreach ( $orders as $order ) {
			$order_id = (int) $order['id'];
			$bills    = self::get_order_bills( $order_id );
			$state[ $order_id ] = array(
				'order_bill'    => $bills['order_bill'],
				'delivery_bill' => $bills['delivery_bill'],
				'order_paid'    => 0.0,
				'delivery_paid' => 0.0,
			);
		}

		// 1) Legacy auto first (waterfall), so purpose-tagged payments reduce what is still due.
		foreach ( $state as $order_id => &$row ) {
			$order_remaining = round( max( 0, $row['order_bill'] - $row['order_paid'] ), 2 );
			$take_order      = round( min( $auto_pool, $order_remaining ), 2 );
			$row['order_paid'] += $take_order;
			$auto_pool          = round( max( 0, $auto_pool - $take_order ), 2 );

			$delivery_remaining = round( max( 0, $row['delivery_bill'] - $row['delivery_paid'] ), 2 );
			$take_delivery      = round( min( $auto_pool, $delivery_remaining ), 2 );
			$row['delivery_paid'] += $take_delivery;
			$auto_pool             = round( max( 0, $auto_pool - $take_delivery ), 2 );
		}
		unset( $row );

		// 2) Purpose-tagged: Order bill payments → remaining product dues (oldest first).
		foreach ( $state as $order_id => &$row ) {
			$remaining          = round( max( 0, $row['order_bill'] - $row['order_paid'] ), 2 );
			$take               = round( min( $order_pool, $remaining ), 2 );
			$row['order_paid'] += $take;
			$order_pool         = round( max( 0, $order_pool - $take ), 2 );
		}
		unset( $row );

		// 3) Purpose-tagged: Delivery bill payments → remaining shipping dues (oldest first).
		foreach ( $state as $order_id => &$row ) {
			$remaining             = round( max( 0, $row['delivery_bill'] - $row['delivery_paid'] ), 2 );
			$take                  = round( min( $delivery_pool, $remaining ), 2 );
			$row['delivery_paid'] += $take;
			$delivery_pool         = round( max( 0, $delivery_pool - $take ), 2 );
		}
		unset( $row );

		$allocations = array();
		foreach ( $state as $order_id => $row ) {
			$allocations[ $order_id ] = self::build_allocation_summary(
				$row['order_bill'],
				$row['delivery_bill'],
				$row['order_paid'],
				$row['delivery_paid']
			);
		}

		return $allocations;
	}

	/**
	 * Normalize payment purpose slug.
	 *
	 * @param string $purpose Raw purpose.
	 * @return string order_bill|delivery_bill|auto
	 */
	public static function normalize_payment_purpose( $purpose ) {
		$purpose = sanitize_key( (string) $purpose );
		if ( in_array( $purpose, array( 'order_bill', 'delivery_bill', 'auto' ), true ) ) {
			return $purpose;
		}
		return 'auto';
	}

	/**
	 * Human label for payment purpose.
	 *
	 * @param string $purpose Purpose slug.
	 * @return string
	 */
	public static function payment_purpose_label( $purpose ) {
		switch ( self::normalize_payment_purpose( $purpose ) ) {
			case 'order_bill':
				return __( 'Order bill', 'ds-prod-import-crm' );
			case 'delivery_bill':
				return __( 'Delivery bill', 'ds-prod-import-crm' );
			default:
				return __( 'Auto (legacy)', 'ds-prod-import-crm' );
		}
	}

	/**
	 * Re-evaluate auto-paid status for every open order on a client.
	 *
	 * @param int $client_id Client ID.
	 * @return void
	 */
	public static function sync_client_paid_statuses( $client_id ) {
		$client_id = absint( $client_id );
		if ( $client_id < 1 ) {
			return;
		}

		foreach ( array_keys( self::allocate_client_payments( $client_id ) ) as $order_id ) {
			CRM_Order_Status::maybe_set_paid_status( (int) $order_id );
		}
	}

	/**
	 * Client-wide ledger totals (purpose-aware via order allocations).
	 *
	 * @param int $client_id Client ID.
	 * @return array<string, float>
	 */
	public static function get_client_summary( $client_id ) {
		global $wpdb;

		$client_id = absint( $client_id );
		if ( $client_id < 1 ) {
			return self::empty_client_summary();
		}

		$allocations = self::allocate_client_payments( $client_id );
		$order_bill    = 0.0;
		$delivery_bill = 0.0;
		$order_paid    = 0.0;
		$delivery_paid = 0.0;

		foreach ( $allocations as $row ) {
			$order_bill    += (float) ( $row['order_bill'] ?? 0 );
			$delivery_bill += (float) ( $row['delivery_bill'] ?? 0 );
			$order_paid    += (float) ( $row['order_paid'] ?? 0 );
			$delivery_paid += (float) ( $row['delivery_paid'] ?? 0 );
		}

		$total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . crm_table( 'payments' ) . ' WHERE client_id = %d',
				$client_id
			)
		);

		$summary = self::build_allocation_summary( $order_bill, $delivery_bill, $order_paid, $delivery_paid );
		// Cash received may exceed allocated paid when a purpose is overpaid.
		$summary['payments_received'] = round( max( 0, $total_paid ), 2 );

		return $summary;
	}

	/**
	 * Global client billing totals (all clients).
	 *
	 * @return array<string, float>
	 */
	public static function get_total_client_summary() {
		global $wpdb;

		$bills_table      = crm_table( 'client_bills' );
		$payments_table   = crm_table( 'payments' );
		$deliveries_table = crm_table( 'deliveries' );

		$order_bill = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$bills_table} WHERE bill_type = 'order_bill'"
		);

		$delivery_from_bills = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$bills_table} WHERE bill_type = 'shipping_bill'"
		);

		$delivery_from_deliveries = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$deliveries_table}"
		);

		$delivery_bill = round( max( $delivery_from_bills, $delivery_from_deliveries ), 2 );

		$total_paid = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$payments_table}"
		);

		return self::build_client_summary( $order_bill, $delivery_bill, $total_paid );
	}

	/**
	 * Supplier / shipping company ledger.
	 *
	 * @param int $company_id Company ID.
	 * @return array<string, float|int>
	 */
	public static function get_company_summary( $company_id ) {
		global $wpdb;

		$company_id = absint( $company_id );
		if ( $company_id < 1 ) {
			return self::empty_company_summary();
		}

		$receives_table         = crm_table( 'warehouse_receives' );
		$company_bills_table    = crm_table( 'company_bills' );
		$company_payments_table = crm_table( 'company_payments' );

		$shipping_bill = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$receives_table} WHERE company_id = %d",
				$company_id
			)
		);

		$manual_bills = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$company_bills_table} WHERE company_id = %d",
				$company_id
			)
		);

		$total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$company_payments_table} WHERE company_id = %d",
				$company_id
			)
		);

		$receive_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$receives_table} WHERE company_id = %d",
				$company_id
			)
		);

		$total_billed = round( $shipping_bill + $manual_bills, 2 );

		return array(
			'shipping_bill' => round( $shipping_bill, 2 ),
			'manual_bills'  => round( $manual_bills, 2 ),
			'total_billed'  => $total_billed,
			'total_bill'    => $total_billed,
			'total_paid'    => round( $total_paid, 2 ),
			'total_due'     => round( max( 0, $total_billed - $total_paid ), 2 ),
			'receive_count' => $receive_count,
		);
	}

	/**
	 * Client-specific shipping totals and payments for one supplier company.
	 *
	 * Uses warehouse receives linked to the client (shipment → order → client).
	 *
	 * @param int                $company_id Company ID.
	 * @param int                $client_id  Client ID.
	 * @param array<string, string> $dates   Optional date_from / date_to (Y-m-d).
	 * @return array<string, float|int>
	 */
	public static function get_company_client_summary( $company_id, $client_id, $dates = array() ) {
		global $wpdb;

		$company_id = absint( $company_id );
		$client_id  = absint( $client_id );
		if ( $company_id < 1 || $client_id < 1 ) {
			return array(
				'shipping_bill' => 0.0,
				'total_paid'    => 0.0,
				'total_due'     => 0.0,
				'receive_count' => 0,
			);
		}

		$receives_table         = crm_table( 'warehouse_receives' );
		$shipments_table        = crm_table( 'export_shipments' );
		$orders_table           = crm_table( 'orders' );
		$company_payments_table = crm_table( 'company_payments' );
		$order_expr             = 'COALESCE(NULLIF(s.order_id, 0), NULLIF(r.order_id, 0))';

		$receive_where  = array( 'r.company_id = %d', 'o.client_id = %d' );
		$receive_params = array( $company_id, $client_id );
		$payment_where  = array( 'company_id = %d', 'client_id = %d' );
		$payment_params = array( $company_id, $client_id );

		$date_from = isset( $dates['date_from'] ) ? (string) $dates['date_from'] : '';
		$date_to   = isset( $dates['date_to'] ) ? (string) $dates['date_to'] : '';
		if ( $date_from ) {
			$receive_where[]  = 'r.receive_date >= %s';
			$receive_params[] = $date_from;
			$payment_where[]  = 'payment_date >= %s';
			$payment_params[] = $date_from;
		}
		if ( $date_to ) {
			$receive_where[]  = 'r.receive_date <= %s';
			$receive_params[] = $date_to;
			$payment_where[]  = 'payment_date <= %s';
			$payment_params[] = $date_to;
		}

		$receive_where_sql = implode( ' AND ', $receive_where );
		$payment_where_sql = implode( ' AND ', $payment_where );

		$shipping_bill = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(r.shipping_bill), 0)
				FROM {$receives_table} r
				LEFT JOIN {$shipments_table} s ON s.id = r.shipment_id
				LEFT JOIN {$orders_table} o ON o.id = {$order_expr}
				WHERE {$receive_where_sql}",
				$receive_params
			)
		);

		$receive_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$receives_table} r
				LEFT JOIN {$shipments_table} s ON s.id = r.shipment_id
				LEFT JOIN {$orders_table} o ON o.id = {$order_expr}
				WHERE {$receive_where_sql}",
				$receive_params
			)
		);

		$total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$company_payments_table}
				WHERE {$payment_where_sql}",
				$payment_params
			)
		);

		$shipping_bill = round( max( 0, $shipping_bill ), 2 );
		$total_paid    = round( max( 0, $total_paid ), 2 );

		return array(
			'shipping_bill' => $shipping_bill,
			'total_bill'    => $shipping_bill,
			'total_paid'    => $total_paid,
			'total_due'     => round( max( 0, $shipping_bill - $total_paid ), 2 ),
			'receive_count' => $receive_count,
		);
	}

	/**
	 * Global supplier billing totals (all shipping companies).
	 *
	 * @return array<string, float>
	 */
	public static function get_total_supplier_summary() {
		global $wpdb;

		$receives_table         = crm_table( 'warehouse_receives' );
		$company_bills_table    = crm_table( 'company_bills' );
		$company_payments_table = crm_table( 'company_payments' );

		$shipping_bill = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$receives_table}"
		);

		$manual_bills = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$company_bills_table}"
		);

		$total_paid = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0) FROM {$company_payments_table}"
		);

		$total_billed = round( $shipping_bill + $manual_bills, 2 );

		return array(
			'shipping_bill' => round( $shipping_bill, 2 ),
			'manual_bills'  => round( $manual_bills, 2 ),
			'total_billed'  => $total_billed,
			'total_bill'    => $total_billed,
			'total_paid'    => round( $total_paid, 2 ),
			'total_due'     => round( max( 0, $total_billed - $total_paid ), 2 ),
		);
	}

	/**
	 * Global customer due (all clients).
	 *
	 * @return float
	 */
	public static function get_total_client_due() {
		$summary = self::get_total_client_summary();

		return (float) $summary['total_due'];
	}

	/**
	 * Global supplier due (all companies).
	 *
	 * @return float
	 */
	public static function get_total_supplier_due() {
		$summary = self::get_total_supplier_summary();

		return (float) $summary['total_due'];
	}

	/**
	 * Delivery / shipping bill total for one order.
	 *
	 * @param int $order_id Order ID.
	 * @return float
	 */
	public static function get_order_delivery_bill( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return 0.0;
		}

		$bills_table      = crm_table( 'client_bills' );
		$deliveries_table = crm_table( 'deliveries' );

		$from_bills = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$bills_table} WHERE order_id = %d AND bill_type = 'shipping_bill'",
				$order_id
			)
		);

		$from_deliveries = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$deliveries_table} WHERE order_id = %d",
				$order_id
			)
		);

		return round( max( $from_bills, $from_deliveries ), 2 );
	}

	/**
	 * Delivery / shipping bill total for one client.
	 *
	 * @param int $client_id Client ID.
	 * @return float
	 */
	public static function get_client_delivery_bill( $client_id ) {
		global $wpdb;

		$client_id = absint( $client_id );
		if ( $client_id < 1 ) {
			return 0.0;
		}

		$bills_table      = crm_table( 'client_bills' );
		$deliveries_table = crm_table( 'deliveries' );

		$from_bills = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$bills_table} WHERE client_id = %d AND bill_type = 'shipping_bill'",
				$client_id
			)
		);

		$from_deliveries = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$deliveries_table} WHERE client_id = %d",
				$client_id
			)
		);

		return round( max( $from_bills, $from_deliveries ), 2 );
	}

	/**
	 * Build allocation summary from explicit order/delivery paid amounts.
	 *
	 * @param float $order_bill     Product/order bill.
	 * @param float $delivery_bill  Delivery/shipping bill.
	 * @param float $order_paid     Amount applied to order bill.
	 * @param float $delivery_paid  Amount applied to delivery bill.
	 * @return array<string, float|string>
	 */
	private static function build_allocation_summary( $order_bill, $delivery_bill, $order_paid, $delivery_paid ) {
		$order_bill    = round( max( 0, (float) $order_bill ), 2 );
		$delivery_bill = round( max( 0, (float) $delivery_bill ), 2 );
		$order_paid    = round( max( 0, min( (float) $order_paid, $order_bill ) ), 2 );
		$delivery_paid = round( max( 0, min( (float) $delivery_paid, $delivery_bill ) ), 2 );
		$total_bill    = round( $order_bill + $delivery_bill, 2 );
		$total_paid    = round( $order_paid + $delivery_paid, 2 );
		$order_due     = round( max( 0, $order_bill - $order_paid ), 2 );
		$delivery_due  = round( max( 0, $delivery_bill - $delivery_paid ), 2 );
		$total_due     = round( $order_due + $delivery_due, 2 );

		$payment_status = 'unpaid';
		if ( $total_bill <= 0 ) {
			$payment_status = 'none';
		} elseif ( $total_due <= 0.009 ) {
			$payment_status = 'paid';
		} elseif ( $total_paid > 0.009 ) {
			$payment_status = 'partial';
		}

		return array(
			'order_bill'      => $order_bill,
			'delivery_bill'   => $delivery_bill,
			'shipping_bill'   => $delivery_bill,
			'total_bill'      => $total_bill,
			'total_paid'      => $total_paid,
			'order_paid'      => $order_paid,
			'delivery_paid'   => $delivery_paid,
			'order_due'       => $order_due,
			'delivery_due'    => $delivery_due,
			'total_due'       => $total_due,
			'payment_status'  => $payment_status,
		);
	}

	/**
	 * Build client summary with legacy waterfall (order first, then delivery).
	 *
	 * @param float $order_bill    Product/order bill.
	 * @param float $delivery_bill Delivery/shipping bill.
	 * @param float $total_paid    Total payments received.
	 * @return array<string, float|string>
	 */
	private static function build_client_summary( $order_bill, $delivery_bill, $total_paid ) {
		$order_bill    = round( max( 0, (float) $order_bill ), 2 );
		$delivery_bill = round( max( 0, (float) $delivery_bill ), 2 );
		$total_paid    = round( max( 0, (float) $total_paid ), 2 );

		$order_paid    = round( min( $total_paid, $order_bill ), 2 );
		$remainder     = round( max( 0, $total_paid - $order_paid ), 2 );
		$delivery_paid = round( min( $remainder, $delivery_bill ), 2 );

		$summary               = self::build_allocation_summary( $order_bill, $delivery_bill, $order_paid, $delivery_paid );
		$summary['total_paid'] = $total_paid;
		$summary['total_due']  = round( max( 0, $summary['total_bill'] - $total_paid ), 2 );

		return $summary;
	}

	/**
	 * @return array<string, float|string>
	 */
	private static function empty_client_summary() {
		return self::build_allocation_summary( 0, 0, 0, 0 );
	}

	/**
	 * @return array<string, float|int>
	 */
	private static function empty_company_summary() {
		return array(
			'shipping_bill' => 0,
			'manual_bills'  => 0,
			'total_billed'  => 0,
			'total_bill'    => 0,
			'total_paid'    => 0,
			'total_due'     => 0,
			'receive_count' => 0,
		);
	}
}
