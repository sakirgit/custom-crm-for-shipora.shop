<?php
/**
 * Dashboard module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Handles dashboard widget data.
 */
class Dashboard_Controller {
	/**
	 * Register dashboard AJAX.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_dashboard_stats', array( __CLASS__, 'stats' ) );
	}

	/**
	 * Whether user can see warehouse KPIs.
	 *
	 * @return bool
	 */
	private static function user_can_see_warehouse() {
		return current_user_can( 'crm_manage_settings' )
			|| current_user_can( 'crm_orders_view' )
			|| current_user_can( 'crm_stock_receive' )
			|| current_user_can( 'crm_stock_view' );
	}

	/**
	 * Whether user can see finance KPIs.
	 *
	 * @return bool
	 */
	private static function user_can_see_accountant() {
		return current_user_can( 'crm_manage_settings' )
			|| current_user_can( 'crm_orders_view' )
			|| current_user_can( 'crm_payments_view' )
			|| current_user_can( 'crm_billing_view' );
	}

	/**
	 * Parse requested dashboard period into date range (Y-m-d).
	 *
	 * @param string $period   Period slug.
	 * @param string $date_from Custom start (Y-m-d).
	 * @param string $date_to   Custom end (Y-m-d).
	 * @return array{0:string,1:string,2:string}|null from, to, label — null if invalid custom.
	 */
	private static function parse_date_range( $period, $date_from = '', $date_to = '' ) {
		$period = sanitize_key( $period );
		$today  = gmdate( 'Y-m-d' );

		switch ( $period ) {
			case 'yesterday':
				$day = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
				return array( $day, $day, __( 'Yesterday', 'ds-prod-import-crm' ) );
			case '7':
				return array(
					gmdate( 'Y-m-d', strtotime( '-6 days' ) ),
					$today,
					__( 'Last 7 days', 'ds-prod-import-crm' ),
				);
			case '15':
				return array(
					gmdate( 'Y-m-d', strtotime( '-14 days' ) ),
					$today,
					__( 'Last 15 days', 'ds-prod-import-crm' ),
				);
			case '30':
				return array(
					gmdate( 'Y-m-d', strtotime( '-29 days' ) ),
					$today,
					__( 'Last 30 days', 'ds-prod-import-crm' ),
				);
			case 'custom':
				$from = crm_normalize_date( $date_from );
				$to   = crm_normalize_date( $date_to );
				if ( ! $from || ! $to ) {
					return null;
				}
				if ( $from > $to ) {
					$swap = $from;
					$from = $to;
					$to   = $swap;
				}
				$label = sprintf(
					/* translators: 1: start date, 2: end date */
					__( 'Custom: %1$s – %2$s', 'ds-prod-import-crm' ),
					$from,
					$to
				);
				return array( $from, $to, $label );
			case 'today':
			default:
				return array( $today, $today, __( 'Today', 'ds-prod-import-crm' ) );
		}
	}

	/**
	 * Build payment chart series for a date range.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @return array{labels:array<int,string>,amounts:array<int,float>}
	 */
	private static function payments_chart_series( $date_from, $date_to ) {
		global $wpdb;

		$payments_table = crm_table( 'payments' );
		$labels         = array();
		$amounts        = array();
		$start          = strtotime( $date_from );
		$end            = strtotime( $date_to );
		$day_count      = (int) floor( ( $end - $start ) / DAY_IN_SECONDS ) + 1;

		for ( $i = 0; $i < $day_count; $i++ ) {
			$date      = gmdate( 'Y-m-d', $start + ( $i * DAY_IN_SECONDS ) );
			$labels[]  = gmdate( 'M j', strtotime( $date ) );
			$amounts[] = round(
				(float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(amount), 0) FROM {$payments_table} WHERE payment_date = %s",
						$date
					)
				),
				2
			);
		}

		return array(
			'labels'  => $labels,
			'amounts' => $amounts,
		);
	}

	/**
	 * Top selling products in date range.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param int    $limit     Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_top_selling_products( $date_from, $date_to, $limit = 10 ) {
		global $wpdb;

		$orders_table   = crm_table( 'orders' );
		$items_table    = crm_table( 'order_items' );
		$products_table = crm_table( 'products' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT agg.product_name, agg.qty_sold, agg.revenue, agg.product_id,
					" . crm_sql_product_image_url( 'p' ) . " AS product_image_url
				FROM (
					SELECT oi.product_name,
						MAX(oi.product_id) AS product_id,
						COALESCE(SUM(oi.quantity), 0) AS qty_sold,
						COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
					FROM {$items_table} oi
					INNER JOIN {$orders_table} o ON o.id = oi.order_id
					WHERE o.order_date BETWEEN %s AND %s
						AND o.status NOT IN ('cancelled')
					GROUP BY oi.product_name
				) agg
				LEFT JOIN {$products_table} p ON p.id = agg.product_id
				ORDER BY agg.qty_sold DESC, agg.revenue DESC
				LIMIT %d",
				$date_from,
				$date_to,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Top clients by order revenue in date range.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to   End date.
	 * @param int    $limit     Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_top_clients( $date_from, $date_to, $limit = 5 ) {
		global $wpdb;

		$orders_table  = crm_table( 'orders' );
		$items_table   = crm_table( 'order_items' );
		$clients_table = crm_table( 'clients' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.name AS client_name,
					COUNT(DISTINCT o.id) AS order_count,
					COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
				FROM {$orders_table} o
				INNER JOIN {$clients_table} c ON c.id = o.client_id
				INNER JOIN {$items_table} oi ON oi.order_id = o.id
				WHERE o.order_date BETWEEN %s AND %s
					AND o.status NOT IN ('cancelled')
				GROUP BY o.client_id, c.name
				ORDER BY revenue DESC
				LIMIT %d",
				$date_from,
				$date_to,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return live dashboard KPIs.
	 *
	 * @return void
	 */
	public static function stats() {
		check_ajax_referer( 'crm_nonce', 'nonce' );

		if ( ! current_user_can( 'crm_view_dashboard' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Unauthorized request.', 'ds-prod-import-crm' ),
				),
				403
			);
		}

		$period    = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : 'today';
		$date_from = isset( $_POST['date_from'] ) ? wp_unslash( $_POST['date_from'] ) : '';
		$date_to   = isset( $_POST['date_to'] ) ? wp_unslash( $_POST['date_to'] ) : '';

		$range = self::parse_date_range( $period, $date_from, $date_to );
		if ( null === $range ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please choose valid custom dates.', 'ds-prod-import-crm' ),
				)
			);
		}

		list( $range_from, $range_to, $period_label ) = $range;

		global $wpdb;

		$cards              = array();
		$charts             = array();
		$insights           = array();
		$show_warehouse     = self::user_can_see_warehouse();
		$show_accountant    = self::user_can_see_accountant();
		$show_orders        = $show_warehouse || $show_accountant || current_user_can( 'crm_orders_view' );
		$new_orders_period  = null;
		$warehouse_summary  = null;

		if ( $show_warehouse ) {
			$receive_table    = crm_table( 'warehouse_receives' );
			$stock_table      = crm_table( 'stock' );
			$deliveries_table = crm_table( 'deliveries' );
			$orders_table     = crm_table( 'orders' );

			$receive_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$receive_table} WHERE receive_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$receive_kg = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(total_kg), 0) FROM {$receive_table} WHERE receive_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$deliveries_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$deliveries_table} WHERE delivery_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$settings  = get_option( 'ds_crm_settings', array( 'low_stock_threshold' => 5 ) );
			$threshold = max( 1, (int) ( $settings['low_stock_threshold'] ?? 5 ) );

			$low_stock = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$stock_table} WHERE quantity > 0 AND quantity <= %d",
					$threshold
				)
			);

			$pending_orders = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$orders_table} WHERE status IN ('pending','partial_delivered')"
			);

			$warehouse_summary = $wpdb->get_row(
				"SELECT COUNT(DISTINCT product_name) AS unique_products, COALESCE(SUM(quantity), 0) AS total_pieces FROM {$stock_table} WHERE quantity > 0",
				ARRAY_A
			);

			$new_orders_period = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$orders_table} WHERE order_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$items_table = crm_table( 'order_items' );
			$items_sold  = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(oi.quantity), 0)
					FROM {$items_table} oi
					INNER JOIN {$orders_table} o ON o.id = oi.order_id
					WHERE o.order_date BETWEEN %s AND %s AND o.status NOT IN ('cancelled')",
					$range_from,
					$range_to
				)
			);

			$shipping_bills = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$receive_table} WHERE receive_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$completed_orders = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$orders_table} WHERE status = 'completed' AND order_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$cards['warehouse'] = array(
				'receives'          => array(
					'label' => __( 'Receives', 'ds-prod-import-crm' ),
					'value' => sprintf( '%d (%s)', $receive_count, crm_format_weight( $receive_kg, true ) ),
					'icon'  => '📦',
					'color' => 'blue',
				),
				'deliveries'        => array(
					'label' => __( 'Deliveries', 'ds-prod-import-crm' ),
					'value' => (string) $deliveries_count,
					'icon'  => '🚚',
					'color' => 'teal',
				),
				'low_stock'         => array(
					'label' => __( 'Low Stock Items', 'ds-prod-import-crm' ),
					'value' => (string) $low_stock,
					'icon'  => '⚠️',
					'color' => 'amber',
				),
				'pending_orders'    => array(
					'label' => __( 'Pending Orders', 'ds-prod-import-crm' ),
					'value' => (string) $pending_orders,
					'icon'  => '📋',
					'color' => 'purple',
				),
				'stock_summary'     => array(
					'label' => __( 'Stock Summary', 'ds-prod-import-crm' ),
					'value' => sprintf(
						/* translators: 1: product count, 2: piece count */
						__( '%1$d products / %2$d pcs', 'ds-prod-import-crm' ),
						(int) ( $warehouse_summary['unique_products'] ?? 0 ),
						(int) ( $warehouse_summary['total_pieces'] ?? 0 )
					),
					'icon'  => '🏭',
					'color' => 'indigo',
				),
				'new_orders'        => array(
					'label' => __( 'New Orders', 'ds-prod-import-crm' ),
					'value' => (string) $new_orders_period,
					'icon'  => '📝',
					'color' => 'blue',
				),
				'items_sold'        => array(
					'label' => __( 'Items Sold', 'ds-prod-import-crm' ),
					'value' => (string) $items_sold,
					'icon'  => '🛍',
					'color' => 'green',
				),
				'shipping_bills'    => array(
					'label' => __( 'Shipping Bills', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $shipping_bills ),
					'icon'  => '🚢',
					'color' => 'orange',
				),
				'completed_orders'  => array(
					'label' => __( 'Completed Orders', 'ds-prod-import-crm' ),
					'value' => (string) $completed_orders,
					'icon'  => '✅',
					'color' => 'green',
				),
			);

			$status_breakdown = array();
			$statuses         = CRM_Order_Status::get_all_active();

			foreach ( $statuses as $status ) {
				$count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$orders_table} WHERE status = %s AND order_date BETWEEN %s AND %s",
						$status['slug'],
						$range_from,
						$range_to
					)
				);

				if ( $count > 0 ) {
					$status_breakdown[] = array(
						'label' => $status['label'],
						'count' => $count,
						'color' => $status['color'],
					);
				}
			}

			$charts['order_status_breakdown'] = $status_breakdown;
		}

		if ( $show_accountant ) {
			$payments_table = crm_table( 'payments' );
			$orders_table   = crm_table( 'orders' );
			$items_table    = crm_table( 'order_items' );

			$client_totals    = CRM_Ledger::get_total_client_summary();
			$supplier_totals  = CRM_Ledger::get_total_supplier_summary();
			$total_due        = (float) $client_totals['total_due'];
			$supplier_due     = (float) $supplier_totals['total_due'];

			$payments_period = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount), 0) FROM {$payments_table} WHERE payment_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$sales_period = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(oi.quantity * oi.unit_price), 0)
					FROM {$items_table} oi
					INNER JOIN {$orders_table} o ON o.id = oi.order_id
					WHERE o.order_date BETWEEN %s AND %s AND o.status NOT IN ('cancelled')",
					$range_from,
					$range_to
				)
			);

			$payment_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$payments_table} WHERE payment_date BETWEEN %s AND %s",
					$range_from,
					$range_to
				)
			);

			$orders_in_period = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$orders_table} WHERE order_date BETWEEN %s AND %s AND status NOT IN ('cancelled')",
					$range_from,
					$range_to
				)
			);

			$active_clients = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT client_id) FROM {$orders_table} WHERE order_date BETWEEN %s AND %s AND status NOT IN ('cancelled')",
					$range_from,
					$range_to
				)
			);

			$avg_order_value = $orders_in_period > 0 ? $sales_period / $orders_in_period : 0;

			$outstanding_orders = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$orders_table} WHERE order_date BETWEEN %s AND %s AND status NOT IN ('cancelled','completed')",
					$range_from,
					$range_to
				)
			);

			$cards['accountant'] = array(
				'client_bill'        => array(
					'label' => __( 'All client bill', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $client_totals['total_bill'] ),
					'icon'  => '📒',
					'color' => 'indigo',
				),
				'client_paid'        => array(
					'label' => __( 'All client paid', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $client_totals['total_paid'] ),
					'icon'  => '✅',
					'color' => 'green',
				),
				'client_due'         => array(
					'label' => __( 'All client due', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $total_due ),
					'icon'  => '💳',
					'color' => 'rose',
				),
				'supplier_bill'      => array(
					'label' => __( 'All shipping co. bill', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $supplier_totals['total_bill'] ),
					'icon'  => '🚢',
					'color' => 'blue',
				),
				'supplier_paid'      => array(
					'label' => __( 'All shipping co. paid', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $supplier_totals['total_paid'] ),
					'icon'  => '💸',
					'color' => 'teal',
				),
				'supplier_due'       => array(
					'label' => __( 'All shipping co. due', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $supplier_due ),
					'icon'  => '🏢',
					'color' => 'orange',
				),
				'payments'           => array(
					'label' => __( 'Payments Received', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $payments_period ),
					'icon'  => '💰',
					'color' => 'green',
				),
				'sales'              => array(
					'label' => __( 'Order Sales', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $sales_period ),
					'icon'  => '🛍',
					'color' => 'indigo',
				),
				'payment_count'      => array(
					'label' => __( 'Payment Entries', 'ds-prod-import-crm' ),
					'value' => (string) $payment_count,
					'icon'  => '🧾',
					'color' => 'teal',
				),
				'orders_in_period'   => array(
					'label' => __( 'Orders (Period)', 'ds-prod-import-crm' ),
					'value' => (string) $orders_in_period,
					'icon'  => '📋',
					'color' => 'purple',
				),
				'active_clients'     => array(
					'label' => __( 'Active Clients', 'ds-prod-import-crm' ),
					'value' => (string) $active_clients,
					'icon'  => '👥',
					'color' => 'blue',
				),
				'avg_order_value'    => array(
					'label' => __( 'Avg Order Value', 'ds-prod-import-crm' ),
					'value' => crm_format_amount( $avg_order_value ),
					'icon'  => '📊',
					'color' => 'indigo',
				),
				'open_orders_period' => array(
					'label' => __( 'Open Orders (Period)', 'ds-prod-import-crm' ),
					'value' => (string) $outstanding_orders,
					'icon'  => '⏳',
					'color' => 'amber',
				),
			);

			$charts['payments_by_day'] = self::payments_chart_series( $range_from, $range_to );
		}

		if ( $show_orders ) {
			if ( null === $new_orders_period ) {
				$new_orders_period = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM ' . crm_table( 'orders' ) . ' WHERE order_date BETWEEN %s AND %s',
						$range_from,
						$range_to
					)
				);
			}

			$new_orders = $new_orders_period;

			$insights['summary'] = array(
				'new_orders' => $new_orders,
			);

			$insights['top_selling'] = array_map(
				static function ( $row ) {
					return array(
						'product_name'       => $row['product_name'],
						'product_image_url'  => ! empty( $row['product_image_url'] ) ? esc_url_raw( $row['product_image_url'] ) : '',
						'qty_sold'           => (int) $row['qty_sold'],
						'revenue'            => crm_format_amount( $row['revenue'] ),
						'revenue_raw'        => (float) $row['revenue'],
					);
				},
				self::get_top_selling_products( $range_from, $range_to, 10 )
			);

			$insights['top_clients'] = array_map(
				static function ( $row ) {
					return array(
						'client_name' => $row['client_name'],
						'order_count' => (int) $row['order_count'],
						'revenue'     => crm_format_amount( $row['revenue'] ),
					);
				},
				self::get_top_clients( $range_from, $range_to, 5 )
			);
		}

		if ( $show_warehouse && isset( $warehouse_summary ) ) {
			$insights['stock'] = array(
				'unique_products' => (int) ( $warehouse_summary['unique_products'] ?? 0 ),
				'total_pieces'    => (int) ( $warehouse_summary['total_pieces'] ?? 0 ),
			);
		}

		wp_send_json_success(
			array(
				'period'       => $period,
				'period_label' => $period_label,
				'date_from'    => $range_from,
				'date_to'      => $range_to,
				'cards'        => $cards,
				'charts'       => $charts,
				'insights'     => $insights,
			)
		);
	}
}
