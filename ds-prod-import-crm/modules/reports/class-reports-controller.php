<?php
/**
 * CRM reports module.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Client ledger, supplier ledger, and stock reports.
 */
class Reports_Controller extends CRM_Controller_Base {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_reports_filters', array( __CLASS__, 'filters' ) );
		add_action( 'wp_ajax_crm_reports_client_ledger', array( __CLASS__, 'client_ledger' ) );
		add_action( 'wp_ajax_crm_reports_client_statement', array( __CLASS__, 'client_statement' ) );
		add_action( 'wp_ajax_crm_reports_client_full', array( __CLASS__, 'client_full' ) );
		add_action( 'wp_ajax_crm_reports_supplier_ledger', array( __CLASS__, 'supplier_ledger' ) );
		add_action( 'wp_ajax_crm_reports_stock', array( __CLASS__, 'stock' ) );
	}

	/**
	 * Verify reports access.
	 *
	 * @return void
	 */
	private static function verify_reports() {
		self::verify_request( 'crm_view_reports' );
	}

	/**
	 * Staff-only report endpoints refuse client portal users.
	 *
	 * @return void
	 */
	private static function refuse_portal_staff_reports() {
		if ( CRM_Client_Portal::is_client_user() ) {
			wp_send_json_error(
				array(
					'message' => __( 'This report is not available in the client portal.', 'ds-prod-import-crm' ),
				),
				403
			);
		}
	}

	/**
	 * Resolve client ID for report endpoints (portal users are locked to their linked client).
	 *
	 * @param int $requested_id Client ID from request.
	 * @return int
	 */
	private static function resolve_report_client_id( $requested_id ) {
		$requested_id = absint( $requested_id );

		if ( CRM_Client_Portal::is_client_user() ) {
			$linked = CRM_Client_Portal::get_linked_client_id();
			if ( $linked < 1 ) {
				wp_send_json_error(
					array(
						'message' => __( 'Your account is not linked to a client profile.', 'ds-prod-import-crm' ),
					)
				);
			}
			return $linked;
		}

		return $requested_id;
	}

	/**
	 * Dropdown options for report filters.
	 *
	 * @return void
	 */
	public static function filters() {
		self::verify_reports();

		global $wpdb;

		if ( CRM_Client_Portal::is_client_user() ) {
			$linked = CRM_Client_Portal::get_linked_client_id();
			$clients = array();
			if ( $linked > 0 ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT id, name FROM ' . crm_table( 'clients' ) . ' WHERE id = %d',
						$linked
					),
					ARRAY_A
				);
				if ( $row ) {
					$clients[] = $row;
				}
			}

			wp_send_json_success(
				array(
					'is_portal'        => true,
					'linked_client_id' => $linked,
					'clients'          => $clients,
					'companies'        => array(),
				)
			);
		}

		$clients = $wpdb->get_results(
			'SELECT id, name FROM ' . crm_table( 'clients' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		$companies = $wpdb->get_results(
			'SELECT id, name, company_type FROM ' . crm_table( 'companies' ) . " WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'is_portal'        => false,
				'linked_client_id' => 0,
				'clients'          => $clients ? $clients : array(),
				'companies'        => $companies ? $companies : array(),
			)
		);
	}

	/**
	 * Client receivables ledger for a date range.
	 *
	 * @return void
	 */
	public static function client_ledger() {
		self::verify_reports();

		global $wpdb;

		$client_id = self::resolve_report_client_id( isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0 );
		$dates     = self::date_range_from_request();

		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a client.', 'ds-prod-import-crm' ) ) );
		}

		$client = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, name, phone, email, address FROM ' . crm_table( 'clients' ) . ' WHERE id = %d', $client_id ),
			ARRAY_A
		);

		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$bills_table    = crm_table( 'client_bills' );
		$payments_table = crm_table( 'payments' );
		$orders_table   = crm_table( 'orders' );

		$opening = self::client_opening_balance( $client_id, $dates['date_from'] );

		$bill_where  = array( 'b.client_id = %d' );
		$bill_params = array( $client_id );

		if ( $dates['date_from'] ) {
			$bill_where[]  = 'b.bill_date >= %s';
			$bill_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$bill_where[]  = 'b.bill_date <= %s';
			$bill_params[] = $dates['date_to'];
		}

		$bills = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.bill_date, b.bill_type, b.amount, o.order_number
				FROM {$bills_table} b
				LEFT JOIN {$orders_table} o ON o.id = b.order_id
				WHERE " . implode( ' AND ', $bill_where ) . '
				ORDER BY b.bill_date ASC, b.id ASC',
				$bill_params
			),
			ARRAY_A
		);

		$pay_where  = array( 'client_id = %d' );
		$pay_params = array( $client_id );

		if ( $dates['date_from'] ) {
			$pay_where[]  = 'payment_date >= %s';
			$pay_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$pay_where[]  = 'payment_date <= %s';
			$pay_params[] = $dates['date_to'];
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT payment_date, payment_number, amount, payment_method, reference, payment_purpose FROM ' . $payments_table . '
				WHERE ' . implode( ' AND ', $pay_where ) . '
				ORDER BY payment_date ASC, id ASC',
				$pay_params
			),
			ARRAY_A
		);

		$entries = array();

		foreach ( $bills ? $bills : array() as $bill ) {
			$label = 'shipping_bill' === $bill['bill_type']
				? __( 'Shipping bill', 'ds-prod-import-crm' )
				: __( 'Order bill', 'ds-prod-import-crm' );

			$entries[] = array(
				'date'      => $bill['bill_date'],
				'type'      => 'bill',
				'label'     => $label,
				'reference' => $bill['order_number'] ?: '—',
				'debit'     => round( (float) $bill['amount'], 2 ),
				'credit'    => 0,
			);
		}

		foreach ( (array) $payments as $payment ) {
			$purpose = CRM_Ledger::normalize_payment_purpose( $payment['payment_purpose'] ?? 'auto' );
			$entries[] = array(
				'date'      => $payment['payment_date'],
				'type'      => 'payment',
				'label'     => sprintf(
					/* translators: %s: payment purpose label */
					__( 'Payment · %s', 'ds-prod-import-crm' ),
					CRM_Ledger::payment_purpose_label( $purpose )
				),
				'reference' => $payment['payment_number'] ?: '—',
				'debit'     => 0,
				'credit'    => round( (float) $payment['amount'], 2 ),
				'purpose'   => $purpose,
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				$cmp = strcmp( $a['date'], $b['date'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return 'bill' === $a['type'] ? -1 : 1;
			}
		);

		$running     = $opening;
		$period_debit  = 0;
		$period_credit = 0;

		foreach ( $entries as &$entry ) {
			$period_debit  += $entry['debit'];
			$period_credit += $entry['credit'];
			$running       += $entry['debit'] - $entry['credit'];
			$entry['balance'] = round( $running, 2 );
		}
		unset( $entry );

		wp_send_json_success(
			array(
				'report_type' => 'client_ledger',
				'entity'      => $client,
				'date_from'   => $dates['date_from'],
				'date_to'     => $dates['date_to'],
				'opening'     => round( $opening, 2 ),
				'period_debit'  => round( $period_debit, 2 ),
				'period_credit' => round( $period_credit, 2 ),
				'closing'     => round( $running, 2 ),
				'entries'     => $entries,
				'summary'     => CRM_Ledger::get_client_summary( $client_id ),
			)
		);
	}

	/**
	 * Detailed client billing statement (order-wise bills + purpose-tagged payments).
	 *
	 * @return void
	 */
	public static function client_statement() {
		self::verify_reports();

		global $wpdb;

		$client_id = self::resolve_report_client_id( isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0 );
		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'Select a client.', 'ds-prod-import-crm' ) ) );
		}

		$client = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, name, phone, email, address FROM ' . crm_table( 'clients' ) . ' WHERE id = %d', $client_id ),
			ARRAY_A
		);
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$dates = self::date_range_from_request();
		$allocations = CRM_Ledger::allocate_client_payments( $client_id );

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_number, order_date, status FROM " . crm_table( 'orders' ) . "
				WHERE client_id = %d AND status != 'cancelled'
				ORDER BY order_date ASC, id ASC",
				$client_id
			),
			ARRAY_A
		);

		$order_rows = array();
		foreach ( (array) $orders as $order ) {
			$order_id = (int) $order['id'];
			$summary  = isset( $allocations[ $order_id ] )
				? $allocations[ $order_id ]
				: CRM_Ledger::get_order_summary( $order_id );

			if ( $dates['date_from'] && $order['order_date'] && $order['order_date'] < $dates['date_from'] ) {
				continue;
			}
			if ( $dates['date_to'] && $order['order_date'] && $order['order_date'] > $dates['date_to'] ) {
				continue;
			}

			$status = $summary['payment_status'] ?? 'unpaid';
			$order_rows[] = array(
				'order_id'       => $order_id,
				'order_number'   => $order['order_number'],
				'order_date'     => $order['order_date'],
				'order_status'   => $order['status'],
				'order_bill'     => (float) ( $summary['order_bill'] ?? 0 ),
				'order_paid'     => (float) ( $summary['order_paid'] ?? 0 ),
				'order_due'      => (float) ( $summary['order_due'] ?? 0 ),
				'delivery_bill'  => (float) ( $summary['delivery_bill'] ?? 0 ),
				'delivery_paid'  => (float) ( $summary['delivery_paid'] ?? 0 ),
				'delivery_due'   => (float) ( $summary['delivery_due'] ?? 0 ),
				'total_bill'     => (float) ( $summary['total_bill'] ?? 0 ),
				'total_paid'     => (float) ( $summary['total_paid'] ?? 0 ),
				'total_due'      => (float) ( $summary['total_due'] ?? 0 ),
				'payment_status' => $status,
				'payment_status_label' => self::payment_status_label( $status ),
			);
		}

		$pay_where  = array( 'client_id = %d' );
		$pay_params = array( $client_id );
		if ( $dates['date_from'] ) {
			$pay_where[]  = 'payment_date >= %s';
			$pay_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$pay_where[]  = 'payment_date <= %s';
			$pay_params[] = $dates['date_to'];
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT payment_date, payment_number, amount, payment_method, reference, payment_purpose, order_id
				FROM ' . crm_table( 'payments' ) . '
				WHERE ' . implode( ' AND ', $pay_where ) . '
				ORDER BY payment_date ASC, id ASC',
				$pay_params
			),
			ARRAY_A
		);

		$payment_rows = array();
		foreach ( (array) $payments as $payment ) {
			$purpose = CRM_Ledger::normalize_payment_purpose( $payment['payment_purpose'] ?? 'auto' );
			$payment_rows[] = array(
				'payment_date'           => $payment['payment_date'],
				'payment_number'         => $payment['payment_number'],
				'amount'                 => round( (float) $payment['amount'], 2 ),
				'payment_method'         => $payment['payment_method'],
				'reference'              => $payment['reference'],
				'payment_purpose'        => $purpose,
				'payment_purpose_label'  => CRM_Ledger::payment_purpose_label( $purpose ),
				'order_id'               => (int) ( $payment['order_id'] ?? 0 ),
			);
		}

		wp_send_json_success(
			array(
				'report_type' => 'client_statement',
				'entity'      => $client,
				'date_from'   => $dates['date_from'],
				'date_to'     => $dates['date_to'],
				'summary'     => CRM_Ledger::get_client_summary( $client_id ),
				'orders'      => $order_rows,
				'payments'    => $payment_rows,
			)
		);
	}

	/**
	 * Full client report: account summary + order billing + payments + chronological ledger.
	 * Used by admin and client portal; PDF via browser Print / Save as PDF.
	 *
	 * @return void
	 */
	public static function client_full() {
		self::verify_reports();

		global $wpdb;

		$client_id = self::resolve_report_client_id( isset( $_POST['client_id'] ) ? absint( $_POST['client_id'] ) : 0 );
		if ( ! $client_id ) {
			wp_send_json_error( array( 'message' => __( 'Select a client.', 'ds-prod-import-crm' ) ) );
		}

		$client = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, name, phone, email, address FROM ' . crm_table( 'clients' ) . ' WHERE id = %d', $client_id ),
			ARRAY_A
		);
		if ( ! $client ) {
			wp_send_json_error( array( 'message' => __( 'Client not found.', 'ds-prod-import-crm' ) ) );
		}

		$dates       = self::date_range_from_request();
		$summary     = CRM_Ledger::get_client_summary( $client_id );
		$allocations = CRM_Ledger::allocate_client_payments( $client_id );

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_number, order_date, status FROM " . crm_table( 'orders' ) . "
				WHERE client_id = %d AND status != 'cancelled'
				ORDER BY order_date ASC, id ASC",
				$client_id
			),
			ARRAY_A
		);

		$order_rows = array();
		foreach ( (array) $orders as $order ) {
			$order_id = (int) $order['id'];
			$row_sum  = isset( $allocations[ $order_id ] )
				? $allocations[ $order_id ]
				: CRM_Ledger::get_order_summary( $order_id );

			if ( $dates['date_from'] && $order['order_date'] && $order['order_date'] < $dates['date_from'] ) {
				continue;
			}
			if ( $dates['date_to'] && $order['order_date'] && $order['order_date'] > $dates['date_to'] ) {
				continue;
			}

			$status = $row_sum['payment_status'] ?? 'unpaid';
			$order_rows[] = array(
				'order_id'             => $order_id,
				'order_number'         => $order['order_number'],
				'order_date'           => $order['order_date'],
				'order_status'         => $order['status'],
				'order_bill'           => (float) ( $row_sum['order_bill'] ?? 0 ),
				'order_paid'           => (float) ( $row_sum['order_paid'] ?? 0 ),
				'order_due'            => (float) ( $row_sum['order_due'] ?? 0 ),
				'delivery_bill'        => (float) ( $row_sum['delivery_bill'] ?? 0 ),
				'delivery_paid'        => (float) ( $row_sum['delivery_paid'] ?? 0 ),
				'delivery_due'         => (float) ( $row_sum['delivery_due'] ?? 0 ),
				'total_bill'           => (float) ( $row_sum['total_bill'] ?? 0 ),
				'total_paid'           => (float) ( $row_sum['total_paid'] ?? 0 ),
				'total_due'            => (float) ( $row_sum['total_due'] ?? 0 ),
				'payment_status'       => $status,
				'payment_status_label' => self::payment_status_label( $status ),
			);
		}

		$pay_where  = array( 'client_id = %d' );
		$pay_params = array( $client_id );
		if ( $dates['date_from'] ) {
			$pay_where[]  = 'payment_date >= %s';
			$pay_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$pay_where[]  = 'payment_date <= %s';
			$pay_params[] = $dates['date_to'];
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT payment_date, payment_number, amount, payment_method, reference, payment_purpose, order_id
				FROM ' . crm_table( 'payments' ) . '
				WHERE ' . implode( ' AND ', $pay_where ) . '
				ORDER BY payment_date ASC, id ASC',
				$pay_params
			),
			ARRAY_A
		);

		$payment_rows = array();
		foreach ( (array) $payments as $payment ) {
			$purpose = CRM_Ledger::normalize_payment_purpose( $payment['payment_purpose'] ?? 'auto' );
			$payment_rows[] = array(
				'payment_date'          => $payment['payment_date'],
				'payment_number'        => $payment['payment_number'],
				'amount'                => round( (float) $payment['amount'], 2 ),
				'payment_method'        => $payment['payment_method'],
				'reference'             => $payment['reference'],
				'payment_purpose'       => $purpose,
				'payment_purpose_label' => CRM_Ledger::payment_purpose_label( $purpose ),
				'order_id'              => (int) ( $payment['order_id'] ?? 0 ),
			);
		}

		$ledger_payload = self::build_client_ledger_entries( $client_id, $dates );
		$open_orders    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . crm_table( 'orders' ) . " WHERE client_id = %d AND status != 'cancelled'",
				$client_id
			)
		);

		wp_send_json_success(
			array(
				'report_type'  => 'client_full',
				'entity'       => $client,
				'date_from'    => $dates['date_from'],
				'date_to'      => $dates['date_to'],
				'summary'      => $summary,
				'open_orders'  => $open_orders,
				'orders'       => $order_rows,
				'payments'     => $payment_rows,
				'opening'      => $ledger_payload['opening'],
				'period_debit' => $ledger_payload['period_debit'],
				'period_credit'=> $ledger_payload['period_credit'],
				'closing'      => $ledger_payload['closing'],
				'entries'      => $ledger_payload['entries'],
			)
		);
	}

	/**
	 * Build chronological client ledger entries for a date range.
	 *
	 * @param int                  $client_id Client ID.
	 * @param array<string,string> $dates     date_from / date_to.
	 * @return array{opening:float,period_debit:float,period_credit:float,closing:float,entries:array<int,array<string,mixed>>}
	 */
	private static function build_client_ledger_entries( $client_id, $dates ) {
		global $wpdb;

		$bills_table    = crm_table( 'client_bills' );
		$payments_table = crm_table( 'payments' );
		$orders_table   = crm_table( 'orders' );

		$opening = self::client_opening_balance( $client_id, $dates['date_from'] );

		$bill_where  = array( 'b.client_id = %d' );
		$bill_params = array( $client_id );
		if ( $dates['date_from'] ) {
			$bill_where[]  = 'b.bill_date >= %s';
			$bill_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$bill_where[]  = 'b.bill_date <= %s';
			$bill_params[] = $dates['date_to'];
		}

		$bills = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.bill_date, b.bill_type, b.amount, o.order_number
				FROM {$bills_table} b
				LEFT JOIN {$orders_table} o ON o.id = b.order_id
				WHERE " . implode( ' AND ', $bill_where ) . '
				ORDER BY b.bill_date ASC, b.id ASC',
				$bill_params
			),
			ARRAY_A
		);

		$pay_where  = array( 'client_id = %d' );
		$pay_params = array( $client_id );
		if ( $dates['date_from'] ) {
			$pay_where[]  = 'payment_date >= %s';
			$pay_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$pay_where[]  = 'payment_date <= %s';
			$pay_params[] = $dates['date_to'];
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT payment_date, payment_number, amount, payment_method, reference, payment_purpose FROM ' . $payments_table . '
				WHERE ' . implode( ' AND ', $pay_where ) . '
				ORDER BY payment_date ASC, id ASC',
				$pay_params
			),
			ARRAY_A
		);

		$entries = array();
		foreach ( (array) $bills as $bill ) {
			$label = 'shipping_bill' === $bill['bill_type']
				? __( 'Shipping bill', 'ds-prod-import-crm' )
				: __( 'Order bill', 'ds-prod-import-crm' );
			$entries[] = array(
				'date'      => $bill['bill_date'],
				'type'      => 'bill',
				'label'     => $label,
				'reference' => $bill['order_number'] ?: '—',
				'debit'     => round( (float) $bill['amount'], 2 ),
				'credit'    => 0,
			);
		}
		foreach ( (array) $payments as $payment ) {
			$purpose = CRM_Ledger::normalize_payment_purpose( $payment['payment_purpose'] ?? 'auto' );
			$entries[] = array(
				'date'      => $payment['payment_date'],
				'type'      => 'payment',
				'label'     => sprintf(
					/* translators: %s: payment purpose label */
					__( 'Payment · %s', 'ds-prod-import-crm' ),
					CRM_Ledger::payment_purpose_label( $purpose )
				),
				'reference' => $payment['payment_number'] ?: '—',
				'debit'     => 0,
				'credit'    => round( (float) $payment['amount'], 2 ),
				'purpose'   => $purpose,
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) $a['date'], (string) $b['date'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				if ( $a['type'] === $b['type'] ) {
					return 0;
				}
				return 'bill' === $a['type'] ? -1 : 1;
			}
		);

		$running        = $opening;
		$period_debit   = 0.0;
		$period_credit  = 0.0;
		foreach ( $entries as $index => $entry ) {
			$period_debit  += (float) $entry['debit'];
			$period_credit += (float) $entry['credit'];
			$running        = round( $running + (float) $entry['debit'] - (float) $entry['credit'], 2 );
			$entries[ $index ]['balance'] = $running;
		}

		return array(
			'opening'       => round( $opening, 2 ),
			'period_debit'  => round( $period_debit, 2 ),
			'period_credit' => round( $period_credit, 2 ),
			'closing'       => round( $running, 2 ),
			'entries'       => $entries,
		);
	}

	/**
	 * Human label for order payment status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function payment_status_label( $status ) {
		switch ( sanitize_key( (string) $status ) ) {
			case 'paid':
				return __( 'Paid', 'ds-prod-import-crm' );
			case 'partial':
				return __( 'Partial', 'ds-prod-import-crm' );
			case 'none':
				return __( 'No bill', 'ds-prod-import-crm' );
			default:
				return __( 'Unpaid', 'ds-prod-import-crm' );
		}
	}

	/**
	 * Opening balance for client before date_from.
	 *
	 * @param int    $client_id Client ID.
	 * @param string $date_from Start date (Y-m-d) or empty.
	 * @return float
	 */
	private static function client_opening_balance( $client_id, $date_from ) {
		if ( ! $date_from ) {
			return 0;
		}

		global $wpdb;

		$billed = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . crm_table( 'client_bills' ) . ' WHERE client_id = %d AND bill_date < %s',
				$client_id,
				$date_from
			)
		);

		$paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . crm_table( 'payments' ) . ' WHERE client_id = %d AND payment_date < %s',
				$client_id,
				$date_from
			)
		);

		return round( max( 0, $billed - $paid ), 2 );
	}

	/**
	 * Supplier payables ledger for a date range.
	 *
	 * @return void
	 */
	public static function supplier_ledger() {
		self::verify_reports();
		self::refuse_portal_staff_reports();

		global $wpdb;

		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$dates      = self::date_range_from_request();

		if ( ! $company_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a company.', 'ds-prod-import-crm' ) ) );
		}

		$company = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, name, company_type, phone FROM ' . crm_table( 'companies' ) . ' WHERE id = %d', $company_id ),
			ARRAY_A
		);

		if ( ! $company ) {
			wp_send_json_error( array( 'message' => __( 'Company not found.', 'ds-prod-import-crm' ) ) );
		}

		$opening = self::supplier_opening_balance( $company_id, $dates['date_from'] );
		$entries = array();

		$receives_table = crm_table( 'warehouse_receives' );

		$recv_where  = array( 'r.company_id = %d' );
		$recv_params = array( $company_id );

		if ( $dates['date_from'] ) {
			$recv_where[]  = 'r.receive_date >= %s';
			$recv_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$recv_where[]  = 'r.receive_date <= %s';
			$recv_params[] = $dates['date_to'];
		}

		$receives = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.receive_date, r.receive_number, r.shipping_bill, r.total_kg
				FROM {$receives_table} r
				WHERE " . implode( ' AND ', $recv_where ) . '
				ORDER BY r.receive_date ASC, r.id ASC',
				$recv_params
			),
			ARRAY_A
		);

		foreach ( $receives ? $receives : array() as $receive ) {
			$shipping = round( (float) $receive['shipping_bill'], 2 );

			if ( $shipping > 0 ) {
				$entries[] = array(
					'date'      => $receive['receive_date'],
					'type'      => 'receive_shipping',
					'label'     => __( 'Receive shipping', 'ds-prod-import-crm' ),
					'reference' => $receive['receive_number'],
					'debit'     => $shipping,
					'credit'    => 0,
				);
			}
		}

		$bills_table = crm_table( 'company_bills' );
		$bill_where  = array( 'company_id = %d' );
		$bill_params = array( $company_id );

		if ( $dates['date_from'] ) {
			$bill_where[]  = 'bill_date >= %s';
			$bill_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$bill_where[]  = 'bill_date <= %s';
			$bill_params[] = $dates['date_to'];
		}

		$manual_bills = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT bill_date, amount, reference FROM ' . $bills_table . '
				WHERE ' . implode( ' AND ', $bill_where ) . '
				ORDER BY bill_date ASC, id ASC',
				$bill_params
			),
			ARRAY_A
		);

		foreach ( $manual_bills ? $manual_bills : array() as $bill ) {
			$entries[] = array(
				'date'      => $bill['bill_date'],
				'type'      => 'manual_bill',
				'label'     => __( 'Manual bill', 'ds-prod-import-crm' ),
				'reference' => $bill['reference'] ?: '—',
				'debit'     => round( (float) $bill['amount'], 2 ),
				'credit'    => 0,
			);
		}

		$payments_table = crm_table( 'company_payments' );
		$pay_where      = array( 'company_id = %d' );
		$pay_params     = array( $company_id );

		if ( $dates['date_from'] ) {
			$pay_where[]  = 'payment_date >= %s';
			$pay_params[] = $dates['date_from'];
		}
		if ( $dates['date_to'] ) {
			$pay_where[]  = 'payment_date <= %s';
			$pay_params[] = $dates['date_to'];
		}

		$payments = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT payment_date, payment_number, amount, payment_method FROM ' . $payments_table . '
				WHERE ' . implode( ' AND ', $pay_where ) . '
				ORDER BY payment_date ASC, id ASC',
				$pay_params
			),
			ARRAY_A
		);

		foreach ( $payments ? $payments : array() as $payment ) {
			$entries[] = array(
				'date'      => $payment['payment_date'],
				'type'      => 'payment',
				'label'     => __( 'Payment to supplier', 'ds-prod-import-crm' ),
				'reference' => $payment['payment_number'] ?: '—',
				'debit'     => 0,
				'credit'    => round( (float) $payment['amount'], 2 ),
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				$cmp = strcmp( $a['date'], $b['date'] );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				return strcmp( $a['type'], $b['type'] );
			}
		);

		$running       = $opening;
		$period_debit  = 0;
		$period_credit = 0;

		foreach ( $entries as &$entry ) {
			$period_debit  += $entry['debit'];
			$period_credit += $entry['credit'];
			$running       += $entry['debit'] - $entry['credit'];
			$entry['balance'] = round( $running, 2 );
		}
		unset( $entry );

		wp_send_json_success(
			array(
				'report_type'   => 'supplier_ledger',
				'entity'        => $company,
				'date_from'     => $dates['date_from'],
				'date_to'       => $dates['date_to'],
				'opening'       => round( $opening, 2 ),
				'period_debit'  => round( $period_debit, 2 ),
				'period_credit' => round( $period_credit, 2 ),
				'closing'       => round( $running, 2 ),
				'entries'       => $entries,
				'summary'       => CRM_Ledger::get_company_summary( $company_id ),
			)
		);
	}

	/**
	 * Opening balance for supplier before date_from.
	 *
	 * @param int    $company_id Company ID.
	 * @param string $date_from  Start date or empty.
	 * @return float
	 */
	private static function supplier_opening_balance( $company_id, $date_from ) {
		if ( ! $date_from ) {
			return 0;
		}

		global $wpdb;

		$summary_before = array(
			'shipping' => (float) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(shipping_bill), 0) FROM ' . crm_table( 'warehouse_receives' ) . ' WHERE company_id = %d AND receive_date < %s',
					$company_id,
					$date_from
				)
			),
			'manual'   => (float) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(amount), 0) FROM ' . crm_table( 'company_bills' ) . ' WHERE company_id = %d AND bill_date < %s',
					$company_id,
					$date_from
				)
			),
			'paid'     => (float) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(SUM(amount), 0) FROM ' . crm_table( 'company_payments' ) . ' WHERE company_id = %d AND payment_date < %s',
					$company_id,
					$date_from
				)
			),
		);

		$billed = $summary_before['shipping'] + $summary_before['manual'];

		return round( max( 0, $billed - $summary_before['paid'] ), 2 );
	}

	/**
	 * Stock report (variant rows + product totals).
	 *
	 * @return void
	 */
	public static function stock() {
		self::verify_reports();
		self::refuse_portal_staff_reports();

		global $wpdb;

		$search          = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$low_stock_only  = ! empty( $_POST['low_stock_only'] );
		$hide_zero       = ! isset( $_POST['hide_zero'] ) || '0' !== (string) wp_unslash( $_POST['hide_zero'] );

		$settings  = crm_get_settings();
		$threshold = max( 1, (int) ( $settings['low_stock_threshold'] ?? 5 ) );

		$stock_table      = crm_table( 'stock' );
		$products_table   = crm_table( 'products' );
		$categories_table = crm_table( 'product_categories' );

		$where  = array( '1=1' );
		$params = array();

		if ( $hide_zero ) {
			$where[] = 's.quantity > 0';
		}

		if ( $low_stock_only ) {
			$where[]  = 's.quantity > 0 AND s.quantity <= %d';
			$params[] = $threshold;
		}

		if ( '' !== $search ) {
			$where[]  = 's.product_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql = "SELECT s.id, s.product_id, s.product_name, s.color, s.size, s.quantity,
			p.unit_price, c.name AS category_name, " . crm_sql_product_image_url( 'p' ) . " AS product_image_url
			FROM {$stock_table} s
			LEFT JOIN {$products_table} p ON p.id = s.product_id
			LEFT JOIN {$categories_table} c ON c.id = p.category_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY s.product_name ASC, s.color ASC, s.size ASC';

		if ( $params ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		$by_product = array();
		$total_pieces = 0;

		foreach ( $rows ? $rows : array() as $row ) {
			$qty = (int) $row['quantity'];
			$total_pieces += $qty;
			$key = (int) $row['product_id'] > 0 ? 'id:' . $row['product_id'] : 'name:' . $row['product_name'];

			if ( ! isset( $by_product[ $key ] ) ) {
				$by_product[ $key ] = array(
					'product_id'         => (int) $row['product_id'],
					'product_name'       => $row['product_name'],
					'product_image_url'  => ! empty( $row['product_image_url'] ) ? esc_url_raw( $row['product_image_url'] ) : '',
					'category'           => $row['category_name'] ?: '—',
					'unit_price'         => (float) ( $row['unit_price'] ?? 0 ),
					'quantity'           => 0,
					'variants'           => 0,
				);
			}

			$by_product[ $key ]['quantity'] += $qty;
			$by_product[ $key ]['variants'] += 1;
		}

		$product_totals = array_values( $by_product );
		usort(
			$product_totals,
			static function ( $a, $b ) {
				return strcasecmp( $a['product_name'], $b['product_name'] );
			}
		);

		$low_stock_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$stock_table} WHERE quantity > 0 AND quantity <= %d",
				$threshold
			)
		);

		wp_send_json_success(
			array(
				'report_type'      => 'stock',
				'threshold'        => $threshold,
				'total_pieces'     => $total_pieces,
				'product_count'    => count( $product_totals ),
				'variant_count'    => count( $rows ? $rows : array() ),
				'low_stock_count'  => $low_stock_count,
				'rows'             => $rows ? $rows : array(),
				'product_totals'   => $product_totals,
			)
		);
	}
}
