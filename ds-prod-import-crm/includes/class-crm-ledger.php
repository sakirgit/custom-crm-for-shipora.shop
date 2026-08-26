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
	 * Payments are pooled at client level and allocated oldest-order-first
	 * (order bill, then delivery bill per order).
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, float>
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
	 * Within each order: product bill is covered before delivery/shipping bill.
	 *
	 * @param int $client_id Client ID.
	 * @return array<int, array<string, float>>
	 */
	public static function allocate_client_payments( $client_id ) {
		global $wpdb;

		$client_id = absint( $client_id );
		if ( $client_id < 1 ) {
			return array();
		}

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM " . crm_table( 'orders' ) . "
				WHERE client_id = %d AND status != 'cancelled'
				AND " . CRM_Order_Status::workflow_active_sql() . '
				ORDER BY order_date ASC, id ASC',
				$client_id
			),
			ARRAY_A
		);

		$total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . crm_table( 'payments' ) . ' WHERE client_id = %d',
				$client_id
			)
		);

		$pool         = round( max( 0, $total_paid ), 2 );
		$allocations  = array();

		if ( ! $orders ) {
			return $allocations;
		}

		foreach ( $orders as $order ) {
			$order_id = (int) $order['id'];
			$bills    = self::get_order_bills( $order_id );

			$order_paid = round( min( $pool, $bills['order_bill'] ), 2 );
			$pool       = round( max( 0, $pool - $order_paid ), 2 );

			$delivery_paid = round( min( $pool, $bills['delivery_bill'] ), 2 );
			$pool          = round( max( 0, $pool - $delivery_paid ), 2 );

			$paid_on_order = round( $order_paid + $delivery_paid, 2 );

			$allocations[ $order_id ] = self::build_client_summary(
				$bills['order_bill'],
				$bills['delivery_bill'],
				$paid_on_order
			);
		}

		return $allocations;
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
	 * Client-wide ledger totals.
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

		$bills_table    = crm_table( 'client_bills' );
		$payments_table = crm_table( 'payments' );
		$items_table    = crm_table( 'order_items' );

		$order_bill = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cb.amount), 0) FROM {$bills_table} cb
				INNER JOIN " . crm_table( 'orders' ) . " o ON o.id = cb.order_id
				WHERE cb.client_id = %d AND cb.bill_type = 'order_bill'
				AND o.status != 'cancelled'
				AND " . CRM_Order_Status::workflow_active_sql( 'o' ),
				$client_id
			)
		);

		if ( $order_bill <= 0 ) {
			$order_bill = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(COALESCE(oi.accepted_quantity, oi.quantity) * oi.unit_price), 0)
					FROM {$items_table} oi
					INNER JOIN " . crm_table( 'orders' ) . " o ON o.id = oi.order_id
					WHERE o.client_id = %d AND o.status != 'cancelled'
					AND " . CRM_Order_Status::workflow_active_sql( 'o' ),
					$client_id
				)
			);
		}

		$delivery_bill = self::get_client_delivery_bill( $client_id );

		$total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) FROM {$payments_table} WHERE client_id = %d",
				$client_id
			)
		);

		return self::build_client_summary( $order_bill, $delivery_bill, $total_paid );
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
	 * Build client summary with payment allocation (order first, then delivery).
	 *
	 * @param float $order_bill    Product/order bill.
	 * @param float $delivery_bill Delivery/shipping bill.
	 * @param float $total_paid    Total payments received.
	 * @return array<string, float>
	 */
	private static function build_client_summary( $order_bill, $delivery_bill, $total_paid ) {
		$order_bill    = round( max( 0, (float) $order_bill ), 2 );
		$delivery_bill = round( max( 0, (float) $delivery_bill ), 2 );
		$total_paid    = round( max( 0, (float) $total_paid ), 2 );
		$total_bill    = round( $order_bill + $delivery_bill, 2 );

		$order_paid    = round( min( $total_paid, $order_bill ), 2 );
		$remainder     = round( max( 0, $total_paid - $order_paid ), 2 );
		$delivery_paid = round( min( $remainder, $delivery_bill ), 2 );

		return array(
			'order_bill'     => $order_bill,
			'delivery_bill'  => $delivery_bill,
			'shipping_bill'  => $delivery_bill,
			'total_bill'     => $total_bill,
			'total_paid'     => $total_paid,
			'order_paid'     => $order_paid,
			'delivery_paid'  => $delivery_paid,
			'order_due'      => round( max( 0, $order_bill - $order_paid ), 2 ),
			'delivery_due'   => round( max( 0, $delivery_bill - $delivery_paid ), 2 ),
			'total_due'      => round( max( 0, $total_bill - $total_paid ), 2 ),
		);
	}

	/**
	 * @return array<string, float>
	 */
	private static function empty_client_summary() {
		return self::build_client_summary( 0, 0, 0 );
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
