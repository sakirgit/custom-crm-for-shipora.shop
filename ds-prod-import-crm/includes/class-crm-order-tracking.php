<?php
/**
 * Order workflow tracking — audience-aware labels and timelines.
 *
 * Client and staff (admin) see the same pipeline with different wording.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Computes per-order progress through review, China supply, BD receive, and delivery.
 */
class CRM_Order_Tracking {
	/**
	 * Attach tracking summaries to list rows.
	 *
	 * @param array<int, array<string, mixed>> $items Order list rows.
	 * @return void
	 */
	public static function attach_to_list_items( array &$items ) {
		if ( empty( $items ) ) {
			return;
		}

		$audience    = self::resolve_audience();
		$order_ids   = array_map(
			static function ( $item ) {
				return (int) ( $item['id'] ?? 0 );
			},
			$items
		);
		$aggregates  = self::load_aggregates( $order_ids );
		$export_meta = self::load_export_meta( $order_ids );

		foreach ( $items as &$item ) {
			$order_id = (int) ( $item['id'] ?? 0 );
			$agg      = $aggregates[ $order_id ] ?? self::empty_aggregate();
			$meta     = $export_meta[ $order_id ] ?? self::empty_export_meta();
			$agg      = array_merge( $agg, $meta );
			$item['tracking']      = self::build_summary( $item, $agg, $audience );
			$item['needs_pricing'] = ! empty( $item['tracking']['needs_pricing'] );
		}
		unset( $item );
	}

	/**
	 * Full tracking payload for order detail.
	 *
	 * @param int                  $order_id Order ID.
	 * @param array<string, mixed> $order    Order row (status, etc.).
	 * @param string|null          $audience Optional 'client' or 'staff'.
	 * @return array<string, mixed>
	 */
	public static function for_order( $order_id, array $order = array(), $audience = null ) {
		$order_id = absint( $order_id );
		$audience = self::normalize_audience( $audience );

		if ( $order_id < 1 ) {
			return self::build_summary( array(), self::empty_aggregate(), $audience );
		}

		if ( empty( $order ) ) {
			global $wpdb;
			$order = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT id, status, order_number, created_by, created_at, accepted_at FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
					$order_id
				),
				ARRAY_A
			);
			if ( ! $order ) {
				return self::build_summary( array(), self::empty_aggregate(), $audience );
			}
		}

		$aggregates = self::load_aggregates( array( $order_id ) );
		$exports    = self::load_export_meta( array( $order_id ) );
		$agg        = array_merge(
			$aggregates[ $order_id ] ?? self::empty_aggregate(),
			$exports[ $order_id ] ?? self::empty_export_meta()
		);

		$summary              = self::build_summary( $order, $agg, $audience );
		$summary['audience']  = $audience;
		$summary['steps']     = self::build_timeline( $order, $agg, $summary, $audience );
		$summary['events']    = self::build_events( $order, $agg, $audience );
		$qty_basis            = max( (int) $agg['qty_accepted'], 0 ) > 0 || ! empty( $order['accepted_at'] )
			? (int) $agg['qty_accepted']
			: (int) $agg['qty_ordered'];
		$summary['metrics']   = array(
			'qty_ordered'    => (int) $agg['qty_ordered'],
			'qty_accepted'   => (int) $agg['qty_accepted'],
			'qty_exported'   => (int) $agg['qty_exported'],
			'qty_delivered'  => (int) $agg['qty_delivered'],
			'qty_due'        => max( 0, $qty_basis - (int) $agg['qty_delivered'] ),
			'lines_unpriced' => (int) $agg['lines_unpriced'],
			'delivery_count' => (int) ( $order['delivery_count'] ?? crm_count_order_deliveries( $order_id ) ),
			'export_count'   => (int) $agg['export_shipment_count'],
			'companies'      => $agg['company_names'] ?? array(),
		);

		return $summary;
	}

	/**
	 * Valid tracking step slugs for list filtering (audience-neutral).
	 *
	 * @return array<int, string>
	 */
	public static function get_list_filter_slugs() {
		return array( 'review', 'supplying', 'to_bangladesh', 'received_bd', 'delivered', 'cancelled' );
	}

	/**
	 * @param string $slug Filter slug.
	 * @return bool
	 */
	public static function is_valid_list_filter( $slug ) {
		return in_array( sanitize_key( $slug ), self::get_list_filter_slugs(), true );
	}

	/**
	 * Options for the orders list tracking filter (audience-aware labels).
	 *
	 * @param string|null $audience Optional client|staff.
	 * @return array<int, array{slug: string, label: string}>
	 */
	public static function get_list_filter_options( $audience = null ) {
		$audience  = self::normalize_audience( $audience );
		$is_client = 'client' === $audience;

		$labels = $is_client
			? array(
				'review'        => __( 'On hold', 'ds-prod-import-crm' ),
				'supplying'     => __( 'Supply in progress', 'ds-prod-import-crm' ),
				'to_bangladesh' => __( 'In transit to Bangladesh', 'ds-prod-import-crm' ),
				'received_bd'   => __( 'Ready for delivery', 'ds-prod-import-crm' ),
				'delivered'     => __( 'Delivered', 'ds-prod-import-crm' ),
				'cancelled'     => __( 'Cancelled', 'ds-prod-import-crm' ),
			)
			: array(
				'review'        => __( 'Pending approval', 'ds-prod-import-crm' ),
				'supplying'     => __( 'Supply & ship prep', 'ds-prod-import-crm' ),
				'to_bangladesh' => __( 'In transit to Bangladesh', 'ds-prod-import-crm' ),
				'received_bd'   => __( 'Received at office', 'ds-prod-import-crm' ),
				'delivered'     => __( 'Delivered', 'ds-prod-import-crm' ),
				'cancelled'     => __( 'Cancelled', 'ds-prod-import-crm' ),
			);

		$out = array();
		foreach ( self::get_list_filter_slugs() as $slug ) {
			$out[] = array(
				'slug'  => $slug,
				'label' => $labels[ $slug ] ?? $slug,
			);
		}

		return $out;
	}

	/**
	 * Append a WHERE fragment for tracking-step filtering.
	 *
	 * @param string              $step  Filter slug.
	 * @param array<int, string>  $where WHERE fragments.
	 * @return void
	 */
	public static function apply_list_filter( $step, array &$where ) {
		if ( ! self::is_valid_list_filter( $step ) ) {
			return;
		}

		$sql = self::list_filter_where_sql( sanitize_key( $step ) );
		if ( $sql ) {
			$where[] = $sql;
		}
	}

	/**
	 * Public SQL fragment for a tracking list filter (order alias `o`).
	 *
	 * @param string $step Filter slug.
	 * @return string
	 */
	public static function list_filter_sql( $step ) {
		if ( ! self::is_valid_list_filter( $step ) ) {
			return '';
		}

		return self::list_filter_where_sql( sanitize_key( $step ) );
	}

	/**
	 * @return string
	 */
	private static function resolve_audience() {
		return CRM_Client_Portal::is_client_user() ? 'client' : 'staff';
	}

	/**
	 * @param string|null $audience Audience.
	 * @return string
	 */
	private static function normalize_audience( $audience ) {
		$audience = is_string( $audience ) ? sanitize_key( $audience ) : '';
		if ( in_array( $audience, array( 'client', 'staff' ), true ) ) {
			return $audience;
		}

		return self::resolve_audience();
	}

	/**
	 * @return array<string, int|float|array<int, string>>
	 */
	private static function empty_aggregate() {
		return array(
			'qty_ordered'           => 0,
			'qty_accepted'          => 0,
			'qty_exported'          => 0,
			'qty_delivered'         => 0,
			'lines_unpriced'        => 0,
			'order_bill'            => 0.0,
			'export_shipment_count' => 0,
			'company_names'         => array(),
			'company_label'         => '',
			'export_events'         => array(),
		);
	}

	/**
	 * @return array<string, int|array<int, string>|string>
	 */
	private static function empty_export_meta() {
		return array(
			'export_shipment_count' => 0,
			'company_names'         => array(),
			'company_label'         => '',
			'export_events'         => array(),
		);
	}

	/**
	 * @param array<int, int> $order_ids Order IDs.
	 * @return array<int, array<string, int|float>>
	 */
	private static function load_aggregates( array $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$items_table          = crm_table( 'order_items' );
		$delivery_items_table = crm_table( 'delivery_items' );
		$export_items_table   = crm_table( 'export_shipment_items' );
		$export_ship_table    = crm_table( 'export_shipments' );
		$placeholders         = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = "SELECT oi.order_id,
			COALESCE(SUM(oi.quantity), 0) AS qty_ordered,
			COALESCE(SUM(COALESCE(oi.accepted_quantity, oi.quantity)), 0) AS qty_accepted,
			COALESCE(SUM(CASE WHEN COALESCE(oi.accepted_quantity, oi.quantity) > 0 AND oi.unit_price <= 0 THEN 1 ELSE 0 END), 0) AS lines_unpriced,
			COALESCE(SUM(COALESCE(oi.accepted_quantity, oi.quantity) * oi.unit_price), 0) AS order_bill,
			COALESCE((
				SELECT SUM(di.quantity) FROM {$delivery_items_table} di WHERE di.order_item_id = oi.id
			), 0) AS qty_delivered_line,
			COALESCE((
				SELECT SUM(esi.quantity) FROM {$export_items_table} esi
				INNER JOIN {$export_ship_table} es ON es.id = esi.shipment_id AND es.status != 'void'
				WHERE esi.order_item_id = oi.id
			), 0) AS qty_exported_line
			FROM {$items_table} oi
			WHERE oi.order_id IN ({$placeholders})
			GROUP BY oi.order_id, oi.id";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_ids ), ARRAY_A );
		$out  = array();

		foreach ( $order_ids as $order_id ) {
			$out[ $order_id ] = self::empty_aggregate();
		}

		if ( ! $rows ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$order_id = (int) $row['order_id'];
			if ( ! isset( $out[ $order_id ] ) ) {
				continue;
			}
			$out[ $order_id ]['qty_ordered']    += (int) $row['qty_ordered'];
			$out[ $order_id ]['qty_accepted']   += (int) $row['qty_accepted'];
			$out[ $order_id ]['qty_delivered']  += (int) $row['qty_delivered_line'];
			$out[ $order_id ]['qty_exported']   += (int) $row['qty_exported_line'];
			$out[ $order_id ]['lines_unpriced'] += (int) $row['lines_unpriced'];
			$out[ $order_id ]['order_bill']     += (float) $row['order_bill'];
		}

		return $out;
	}

	/**
	 * Export shipment counts and cargo company names per order.
	 *
	 * @param array<int, int> $order_ids Order IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function load_export_meta( array $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$ship_table     = crm_table( 'export_shipments' );
		$companies_table = crm_table( 'companies' );
		$placeholders   = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = "SELECT es.order_id, es.ship_date, es.created_at, es.id, es.shipment_number, es.notes, co.name AS company_name,
			COALESCE((
				SELECT SUM(esi.quantity) FROM " . crm_table( 'export_shipment_items' ) . " esi WHERE esi.shipment_id = es.id
			), 0) AS qty_total
			FROM {$ship_table} es
			LEFT JOIN {$companies_table} co ON co.id = es.company_id
			WHERE es.status != 'void' AND es.order_id IN ({$placeholders})
			ORDER BY es.ship_date ASC, es.id ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_ids ), ARRAY_A );
		$out  = array();

		foreach ( $order_ids as $order_id ) {
			$out[ $order_id ] = self::empty_export_meta();
		}

		foreach ( $rows ? $rows : array() as $row ) {
			$order_id = (int) $row['order_id'];
			if ( ! isset( $out[ $order_id ] ) ) {
				continue;
			}
			$out[ $order_id ]['export_shipment_count']++;
			$name = trim( (string) ( $row['company_name'] ?? '' ) );
			if ( '' !== $name && ! in_array( $name, $out[ $order_id ]['company_names'], true ) ) {
				$out[ $order_id ]['company_names'][] = $name;
			}
			$occurred = ! empty( $row['created_at'] ) ? (string) $row['created_at'] : ( (string) ( $row['ship_date'] ?? '' ) . ' 12:00:00' );
			$out[ $order_id ]['export_events'][] = array(
				'id'               => (int) $row['id'],
				'shipment_number'  => (string) ( $row['shipment_number'] ?? '' ),
				'ship_date'        => (string) ( $row['ship_date'] ?? '' ),
				'occurred_at'      => $occurred,
				'company'          => $name,
				'notes'            => (string) ( $row['notes'] ?? '' ),
				'qty_total'        => (int) ( $row['qty_total'] ?? 0 ),
				'time'             => crm_tracking_datetime_payload( $occurred ),
			);
		}

		foreach ( $out as $order_id => $meta ) {
			$out[ $order_id ]['company_label'] = ! empty( $meta['company_names'] )
				? implode( ', ', $meta['company_names'] )
				: '';
		}

		return $out;
	}

	/**
	 * Whether status is an on-hold style block (awaiting / on_hold).
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	private static function is_on_hold_status( $status ) {
		$status = sanitize_key( $status );
		if ( in_array( $status, array( 'on_hold', 'awaiting_acceptance' ), true ) ) {
			return true;
		}

		return CRM_Order_Status::blocks_workflow( $status );
	}

	/**
	 * Detect cancelled-by-client from recent audit when possible.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private static function was_cancelled_by_client( $order_id ) {
		$history = CRM_Audit::get_for_record( 'orders', $order_id, 20 );
		foreach ( $history ? $history : array() as $row ) {
			$action = (string) ( $row['action'] ?? '' );
			$text   = (string) ( $row['description'] ?? $row['message'] ?? '' );
			if ( false !== stripos( $text, 'cancelled by client' ) ) {
				return true;
			}
			if ( 'void' === $action && false !== stripos( $text, 'cancel' ) ) {
				$user_id = (int) ( $row['user_id'] ?? 0 );
				if ( $user_id > 0 ) {
					$user = get_userdata( $user_id );
					if ( $user && CRM_Client_Portal::is_client_user( $user ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $order    Order row.
	 * @param array<string, mixed> $agg      Aggregates.
	 * @param string               $audience client|staff.
	 * @return array<string, mixed>
	 */
	private static function build_summary( array $order, array $agg, $audience ) {
		$status             = sanitize_key( (string) ( $order['status'] ?? '' ) );
		$qty_ordered        = (int) ( $agg['qty_ordered'] ?? 0 );
		$qty_accepted       = (int) ( $agg['qty_accepted'] ?? 0 );
		$qty_basis          = $qty_accepted > 0 || ! empty( $order['accepted_at'] ) ? $qty_accepted : $qty_ordered;
		$qty_delivered      = (int) ( $agg['qty_delivered'] ?? 0 );
		$qty_exported       = (int) ( $agg['qty_exported'] ?? 0 );
		$lines_unpriced     = (int) ( $agg['lines_unpriced'] ?? 0 );
		$needs_pricing      = $lines_unpriced > 0;
		$workflow_blocked   = self::is_on_hold_status( $status );
		$is_cancelled       = 'cancelled' === $status;
		$china_placed       = ! $workflow_blocked && ! $is_cancelled;
		$supply_confirmed   = $china_placed && ! $needs_pricing;
		$export_started     = $qty_exported > 0;
		$export_done        = $qty_basis > 0 && $qty_exported >= $qty_basis;
		$delivery_started   = $qty_delivered > 0;
		$delivery_done      = $qty_basis > 0 && $qty_delivered >= $qty_basis;
		$company_label      = (string) ( $agg['company_label'] ?? '' );
		$is_client          = 'client' === $audience;

		if ( $is_cancelled ) {
			$by_client = self::was_cancelled_by_client( (int) ( $order['id'] ?? 0 ) );
			return array(
				'short_label'   => $by_client
					? __( 'Cancelled by client', 'ds-prod-import-crm' )
					: __( 'Order cancelled', 'ds-prod-import-crm' ),
				'short_detail'  => '',
				'tone'          => 'muted',
				'needs_pricing' => false,
				'current_step'  => 'cancelled',
			);
		}

		if ( $workflow_blocked ) {
			if ( $is_client ) {
				return array(
					'short_label'   => __( 'On hold', 'ds-prod-import-crm' ),
					'short_detail'  => __( 'Waiting for approval', 'ds-prod-import-crm' ),
					'tone'          => 'warning',
					'needs_pricing' => $needs_pricing,
					'current_step'  => 'review',
				);
			}

			return array(
				'short_label'   => __( 'Pending approval', 'ds-prod-import-crm' ),
				'short_detail'  => __( 'China office: Approve order, then Confirm supply', 'ds-prod-import-crm' ),
				'tone'          => 'warning',
				'needs_pricing' => $needs_pricing,
				'current_step'  => 'review',
			);
		}

		if ( $delivery_done ) {
			return array(
				'short_label'   => __( 'Delivered', 'ds-prod-import-crm' ),
				'short_detail'  => sprintf(
					/* translators: 1: delivered qty, 2: accepted/order qty */
					__( '%1$d / %2$d pcs', 'ds-prod-import-crm' ),
					$qty_delivered,
					$qty_basis
				),
				'tone'          => 'success',
				'needs_pricing' => false,
				'current_step'  => 'delivered',
			);
		}

		if ( $delivery_started ) {
			return array(
				'short_label'   => __( 'Partially delivered', 'ds-prod-import-crm' ),
				'short_detail'  => sprintf(
					/* translators: 1: delivered qty, 2: accepted/order qty */
					__( '%1$d / %2$d pcs', 'ds-prod-import-crm' ),
					$qty_delivered,
					$qty_basis
				),
				'tone'          => 'info',
				'needs_pricing' => false,
				'current_step'  => 'delivered',
			);
		}

		if ( $export_done ) {
			return array(
				'short_label'   => $is_client
					? __( 'Goods available for client delivery', 'ds-prod-import-crm' )
					: __( 'Received at our office', 'ds-prod-import-crm' ),
				'short_detail'  => __( 'Ready for client delivery', 'ds-prod-import-crm' ),
				'tone'          => 'info',
				'needs_pricing' => false,
				'current_step'  => 'received_bd',
			);
		}

		if ( $export_started ) {
			$detail = sprintf(
				/* translators: 1: exported qty, 2: accepted qty */
				__( '%1$d / %2$d pcs to Bangladesh', 'ds-prod-import-crm' ),
				$qty_exported,
				$qty_basis
			);
			if ( ! $is_client && '' !== $company_label ) {
				$detail = sprintf(
					/* translators: 1: company names, 2: exported qty, 3: accepted qty */
					__( '%1$s · %2$d / %3$d pcs', 'ds-prod-import-crm' ),
					$company_label,
					$qty_exported,
					$qty_basis
				);
			}

			$partial = $qty_basis > 0 && $qty_exported < $qty_basis;
			$short   = $partial
				? __( 'Partially submitted to Bangladesh', 'ds-prod-import-crm' )
				: __( 'Fully submitted to Bangladesh', 'ds-prod-import-crm' );

			if ( ! $is_client && '' !== $company_label ) {
				$short = $partial
					? sprintf(
						/* translators: %s: cargo company name(s) */
						__( 'Partially submitted to Bangladesh: %s', 'ds-prod-import-crm' ),
						$company_label
					)
					: sprintf(
						/* translators: %s: cargo company name(s) */
						__( 'Fully submitted to Bangladesh: %s', 'ds-prod-import-crm' ),
						$company_label
					);
			}

			return array(
				'short_label'   => $short,
				'short_detail'  => $detail,
				'tone'          => 'info',
				'needs_pricing' => false,
				'current_step'  => 'to_bangladesh',
			);
		}

		if ( $supply_confirmed ) {
			return array(
				'short_label'   => $is_client
					? __( 'Supplying from China', 'ds-prod-import-crm' )
					: __( 'Waiting to ship', 'ds-prod-import-crm' ),
				'short_detail'  => $is_client
					? __( 'Products are being arranged in China', 'ds-prod-import-crm' )
					: __( 'Supply confirmed — waiting to assign shipper', 'ds-prod-import-crm' ),
				'tone'          => 'info',
				'needs_pricing' => false,
				'current_step'  => 'supplying',
			);
		}

		if ( $china_placed && $needs_pricing ) {
			return array(
				'short_label'   => $is_client
					? __( 'Order placed in China', 'ds-prod-import-crm' )
					: __( 'Confirm supply', 'ds-prod-import-crm' ),
				'short_detail'  => $is_client
					? __( 'China office is arranging supply', 'ds-prod-import-crm' )
					: __( 'Approved — confirm quantity and unit price next', 'ds-prod-import-crm' ),
				'tone'          => 'info',
				'needs_pricing' => true,
				'current_step'  => 'supplying',
			);
		}

		return array(
			'short_label'   => __( 'Order placed in China', 'ds-prod-import-crm' ),
			'short_detail'  => $is_client
				? sprintf(
					/* translators: 1: accepted qty, 2: ordered qty */
					__( 'Accepted %1$d of %2$d pcs', 'ds-prod-import-crm' ),
					$qty_accepted > 0 ? $qty_accepted : $qty_ordered,
					$qty_ordered
				)
				: sprintf(
					/* translators: 1: accepted qty, 2: ordered qty */
					__( 'Approved — accepted %1$d of %2$d · confirm supply next', 'ds-prod-import-crm' ),
					$qty_accepted > 0 ? $qty_accepted : $qty_ordered,
					$qty_ordered
				),
			'tone'          => 'info',
			'needs_pricing' => false,
			'current_step'  => 'china_placed',
		);
	}

	/**
	 * @param array<string, mixed> $order    Order row.
	 * @param array<string, mixed> $agg      Aggregates.
	 * @param array<string, mixed> $summary  Short summary.
	 * @param string               $audience client|staff.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_timeline( array $order, array $agg, array $summary, $audience ) {
		$status           = sanitize_key( (string) ( $order['status'] ?? '' ) );
		$is_cancelled     = 'cancelled' === $status;
		$workflow_blocked = self::is_on_hold_status( $status );
		$needs_pricing    = (int) ( $agg['lines_unpriced'] ?? 0 ) > 0;
		$qty_ordered      = (int) ( $agg['qty_ordered'] ?? 0 );
		$qty_accepted     = (int) ( $agg['qty_accepted'] ?? 0 );
		$qty_basis        = $qty_accepted > 0 || ! empty( $order['accepted_at'] ) ? $qty_accepted : $qty_ordered;
		$qty_exported     = (int) ( $agg['qty_exported'] ?? 0 );
		$qty_delivered    = (int) ( $agg['qty_delivered'] ?? 0 );
		$china_placed     = ! $workflow_blocked && ! $is_cancelled;
		$supply_done      = $china_placed && ! $needs_pricing;
		$export_started   = $qty_exported > 0;
		$export_done      = $qty_basis > 0 && $qty_exported >= $qty_basis;
		$export_partial   = $export_started && ! $export_done;
		$delivery_started = $qty_delivered > 0;
		$delivery_done    = $qty_basis > 0 && $qty_delivered >= $qty_basis;
		$delivery_partial = $delivery_started && ! $delivery_done;
		$company_label    = (string) ( $agg['company_label'] ?? '' );
		$is_client        = 'client' === $audience;
		$submitted_at     = (string) ( $order['created_at'] ?? '' );
		$accepted_at      = (string) ( $order['accepted_at'] ?? '' );

		$steps = array(
			array(
				'key'   => 'submitted',
				'label' => __( 'Order submitted', 'ds-prod-import-crm' ),
				'desc'  => __( 'New order received', 'ds-prod-import-crm' ),
				'time'  => crm_tracking_datetime_payload( $submitted_at ),
			),
			array(
				'key'   => 'review',
				'label' => $is_client
					? ( $china_placed ? __( 'Approved', 'ds-prod-import-crm' ) : __( 'On hold', 'ds-prod-import-crm' ) )
					: ( $china_placed ? __( 'Approved', 'ds-prod-import-crm' ) : __( 'Pending approval', 'ds-prod-import-crm' ) ),
				'desc'  => $is_client
					? ( $china_placed
						? sprintf(
							/* translators: 1: accepted, 2: ordered */
							__( 'Accepted %1$d of %2$d pcs', 'ds-prod-import-crm' ),
							$qty_basis,
							$qty_ordered
						)
						: __( 'Waiting for office approval', 'ds-prod-import-crm' ) )
					: ( $china_placed
						? sprintf(
							/* translators: 1: accepted, 2: ordered */
							__( 'Accepted %1$d of %2$d pcs · confirm supply next', 'ds-prod-import-crm' ),
							$qty_basis,
							$qty_ordered
						)
						: __( 'Awaiting China office approval', 'ds-prod-import-crm' ) ),
				'time'  => $china_placed ? crm_tracking_datetime_payload( $accepted_at ) : array(),
			),
			array(
				'key'   => 'china_placed',
				'label' => __( 'Order placed in China', 'ds-prod-import-crm' ),
				'desc'  => $is_client
					? __( 'Accepted by the China office', 'ds-prod-import-crm' )
					: __( 'Approve order completed — not yet shipping', 'ds-prod-import-crm' ),
				'time'  => $china_placed ? crm_tracking_datetime_payload( $accepted_at ) : array(),
			),
			array(
				'key'   => 'supplying',
				'label' => $export_done
					? __( 'Fully supplied from China', 'ds-prod-import-crm' )
					: ( $export_partial
						? __( 'Partially supplied from China', 'ds-prod-import-crm' )
						: ( $supply_done
							? __( 'Supply confirmed', 'ds-prod-import-crm' )
							: ( $is_client
								? __( 'Supply from China', 'ds-prod-import-crm' )
								: __( 'Confirm supply', 'ds-prod-import-crm' ) ) ) ),
				'desc'  => $export_started
					? sprintf(
						/* translators: 1: exported qty, 2: accepted qty */
						__( '%1$d / %2$d pcs arranged', 'ds-prod-import-crm' ),
						$qty_exported,
						$qty_basis
					)
					: ( $supply_done
						? __( 'Waiting to ship — assign shipper next', 'ds-prod-import-crm' )
						: ( $is_client
							? __( 'China office is confirming what can be supplied', 'ds-prod-import-crm' )
							: __( 'Confirm quantity and unit price — still waiting to ship', 'ds-prod-import-crm' ) ) ),
				'time'  => ! empty( $agg['export_events'][0]['time'] ) ? $agg['export_events'][0]['time'] : array(),
			),
			array(
				'key'   => 'to_bangladesh',
				'label' => $export_done
					? __( 'Fully submitted to Bangladesh', 'ds-prod-import-crm' )
					: ( $export_partial
						? __( 'Partially submitted to Bangladesh', 'ds-prod-import-crm' )
						: __( 'Submitted to Bangladesh', 'ds-prod-import-crm' ) ),
				'desc'  => self::bangladesh_step_desc( $is_client, $company_label, $export_started, $qty_exported, $qty_basis ),
				'time'  => ! empty( $agg['export_events'] ) ? ( end( $agg['export_events'] )['time'] ?? array() ) : array(),
			),
			array(
				'key'   => 'received_bd',
				'label' => $is_client
					? __( 'Goods available for client delivery', 'ds-prod-import-crm' )
					: __( 'Received at our office', 'ds-prod-import-crm' ),
				'desc'  => __( 'Goods available for client delivery', 'ds-prod-import-crm' ),
				'time'  => $export_done && ! empty( $agg['export_events'] ) ? ( end( $agg['export_events'] )['time'] ?? array() ) : array(),
			),
			array(
				'key'   => 'delivered',
				'label' => $delivery_done
					? __( 'Delivered', 'ds-prod-import-crm' )
					: ( $delivery_partial
						? __( 'Partially delivered', 'ds-prod-import-crm' )
						: __( 'Delivered', 'ds-prod-import-crm' ) ),
				'desc'  => $delivery_started
					? sprintf(
						/* translators: 1: delivered qty, 2: accepted qty */
						__( '%1$d / %2$d pcs handed over', 'ds-prod-import-crm' ),
						$qty_delivered,
						$qty_basis
					)
					: __( 'Handed over to the client', 'ds-prod-import-crm' ),
				'time'  => array(),
			),
		);

		$state_map = array(
			'submitted'     => 'done',
			'review'        => $is_cancelled ? 'pending' : ( $china_placed ? 'done' : 'current' ),
			'china_placed'  => $is_cancelled || ! $china_placed ? 'pending' : ( $supply_done || $export_started ? 'done' : 'current' ),
			'supplying'     => $is_cancelled || ! $china_placed ? 'pending' : ( $export_started || $export_done ? 'done' : ( $supply_done ? 'current' : ( $china_placed ? 'current' : 'pending' ) ) ),
			'to_bangladesh' => $is_cancelled || ! $china_placed ? 'pending' : ( $export_done ? 'done' : ( $export_started ? 'current' : 'pending' ) ),
			'received_bd'   => $is_cancelled || ! $china_placed ? 'pending' : ( $export_done ? ( $delivery_started ? 'done' : 'current' ) : 'pending' ),
			'delivered'     => $is_cancelled ? 'pending' : ( $delivery_done ? 'done' : ( $delivery_started ? 'current' : 'pending' ) ),
		);

		// When supply is confirmed but no export yet, mark china_placed done and supplying current.
		if ( $china_placed && $supply_done && ! $export_started && ! $is_cancelled ) {
			$state_map['china_placed'] = 'done';
			$state_map['supplying']    = 'current';
		}

		// When supply not yet priced after china place, keep supplying as current.
		if ( $china_placed && $needs_pricing && ! $export_started && ! $is_cancelled ) {
			$state_map['china_placed'] = 'done';
			$state_map['supplying']    = 'current';
		}

		foreach ( $steps as &$step ) {
			$step['state'] = $state_map[ $step['key'] ] ?? 'pending';
		}
		unset( $step );

		if ( $is_cancelled ) {
			$by_client = self::was_cancelled_by_client( (int) ( $order['id'] ?? 0 ) );
			foreach ( $steps as &$step ) {
				if ( 'submitted' === $step['key'] ) {
					$step['state'] = 'done';
				} elseif ( 'review' === $step['key'] && $china_placed ) {
					$step['state'] = 'done';
				} else {
					$step['state'] = 'cancelled';
				}
			}
			unset( $step );

			$steps[] = array(
				'key'   => 'cancelled',
				'label' => $by_client
					? __( 'Cancelled by client', 'ds-prod-import-crm' )
					: __( 'Order cancelled', 'ds-prod-import-crm' ),
				'desc'  => '',
				'state' => 'current',
			);
		} else {
			// Ensure only one current step (first current wins; later currents become pending if earlier current exists).
			$found_current = false;
			foreach ( $steps as &$step ) {
				if ( 'current' === $step['state'] ) {
					if ( $found_current ) {
						$step['state'] = 'pending';
					} else {
						$found_current = true;
					}
				}
			}
			unset( $step );

			if ( ! $found_current && ! empty( $summary['current_step'] ) ) {
				foreach ( $steps as &$step ) {
					if ( $step['key'] === $summary['current_step'] && 'pending' === $step['state'] ) {
						$step['state'] = 'current';
						break;
					}
				}
				unset( $step );
			}
		}

		$supply_legs = array();
		foreach ( (array) ( $agg['export_events'] ?? array() ) as $export ) {
			$qty   = (int) ( $export['qty_total'] ?? 0 );
			$notes = trim( (string) ( $export['notes'] ?? '' ) );
			$supply_legs[] = array(
				'shipment_number' => (string) ( $export['shipment_number'] ?? '' ),
				'company'         => (string) ( $export['company'] ?? '' ),
				'qty_total'       => $qty,
				'ship_date'       => (string) ( $export['ship_date'] ?? '' ),
				'notes'           => $notes,
				'time'            => $export['time'] ?? array(),
				'label'           => $qty > 0
					? sprintf(
						/* translators: 1: qty, 2: company or fallback */
						__( '%1$d pcs%2$s', 'ds-prod-import-crm' ),
						$qty,
						! empty( $export['company'] ) && ! $is_client
							? ' · ' . $export['company']
							: ''
					)
					: (string) ( $export['company'] ?? __( 'Supply shipment', 'ds-prod-import-crm' ) ),
			);
		}

		foreach ( $steps as &$step ) {
			if ( 'to_bangladesh' === $step['key'] && ! empty( $supply_legs ) ) {
				$step['legs'] = $supply_legs;
				if ( count( $supply_legs ) > 1 ) {
					$step['desc'] = sprintf(
						/* translators: 1: number of supply batches, 2: exported, 3: accepted */
						__( '%1$d supply batches · %2$d / %3$d pcs', 'ds-prod-import-crm' ),
						count( $supply_legs ),
						$qty_exported,
						$qty_basis
					);
				}
			}
		}
		unset( $step );

		return $steps;
	}

	/**
	 * Chronological tracking events with dual timezone times.
	 *
	 * @param array<string, mixed> $order    Order row.
	 * @param array<string, mixed> $agg      Aggregates.
	 * @param string               $audience client|staff.
	 * @return array<int, array<string, mixed>>
	 */
	private static function build_events( array $order, array $agg, $audience ) {
		$events     = array();
		$is_client  = 'client' === $audience;
		$qty_ordered  = (int) ( $agg['qty_ordered'] ?? 0 );
		$qty_accepted = (int) ( $agg['qty_accepted'] ?? 0 );

		if ( ! empty( $order['created_at'] ) ) {
			$events[] = array(
				'key'   => 'submitted',
				'label' => __( 'Order submitted', 'ds-prod-import-crm' ),
				'detail'=> sprintf(
					/* translators: %d: ordered qty */
					__( '%d pcs ordered', 'ds-prod-import-crm' ),
					$qty_ordered
				),
				'time'  => crm_tracking_datetime_payload( (string) $order['created_at'] ),
			);
		}

		if ( ! empty( $order['accepted_at'] ) ) {
			$events[] = array(
				'key'   => 'approved',
				'label' => $is_client ? __( 'Order approved', 'ds-prod-import-crm' ) : __( 'Approved by China office', 'ds-prod-import-crm' ),
				'detail'=> sprintf(
					/* translators: 1: accepted qty, 2: ordered qty */
					__( 'Accepted %1$d of %2$d pcs', 'ds-prod-import-crm' ),
					$qty_accepted > 0 ? $qty_accepted : $qty_ordered,
					$qty_ordered
				),
				'time'  => crm_tracking_datetime_payload( (string) $order['accepted_at'] ),
			);
		}

		foreach ( (array) ( $agg['export_events'] ?? array() ) as $export ) {
			$company   = (string) ( $export['company'] ?? '' );
			$qty       = (int) ( $export['qty_total'] ?? 0 );
			$ship_no   = (string) ( $export['shipment_number'] ?? '' );
			$qty_label = $qty > 0
				? sprintf(
					/* translators: %d: pcs in this supply leg */
					_n( '%d pc', '%d pcs', $qty, 'ds-prod-import-crm' ),
					$qty
				)
				: '';

			if ( $is_client ) {
				$detail = $qty_label
					? sprintf(
						/* translators: %s: quantity label */
						__( '%s submitted to Bangladesh from China', 'ds-prod-import-crm' ),
						$qty_label
					)
					: __( 'Products submitted to Bangladesh from China', 'ds-prod-import-crm' );
			} elseif ( '' !== $company && '' !== $qty_label ) {
				$detail = sprintf(
					/* translators: 1: qty label, 2: company */
					__( '%1$s via %2$s', 'ds-prod-import-crm' ),
					$qty_label,
					$company
				);
			} elseif ( '' !== $company ) {
				$detail = sprintf(
					/* translators: %s: cargo company */
					__( 'Submitted to Bangladesh via %s', 'ds-prod-import-crm' ),
					$company
				);
			} else {
				$detail = __( 'Products submitted to Bangladesh from China', 'ds-prod-import-crm' );
			}

			if ( '' !== $ship_no ) {
				$detail .= ' · ' . $ship_no;
			}

			$events[] = array(
				'key'   => 'export',
				'label' => __( 'Supply / shipment', 'ds-prod-import-crm' ),
				'detail'=> $detail,
				'time'  => $export['time'] ?? crm_tracking_datetime_payload( (string) ( $export['occurred_at'] ?? '' ) ),
			);
		}

		return $events;
	}

	/**
	 * Description for the Bangladesh submission step.
	 *
	 * @param bool   $is_client      Client audience.
	 * @param string $company_label  Cargo companies.
	 * @param bool   $export_started Whether any export exists.
	 * @param int    $qty_exported   Exported qty.
	 * @param int    $qty_ordered    Ordered qty.
	 * @return string
	 */
	private static function bangladesh_step_desc( $is_client, $company_label, $export_started, $qty_exported, $qty_ordered ) {
		if ( ! $export_started ) {
			return $is_client
				? __( 'Products will ship from China to Bangladesh', 'ds-prod-import-crm' )
				: __( 'Record export shipment with cargo company', 'ds-prod-import-crm' );
		}

		if ( ! $is_client && '' !== $company_label ) {
			return sprintf(
				/* translators: 1: company name(s), 2: exported qty, 3: ordered qty */
				__( '%1$s · %2$d / %3$d pcs from China', 'ds-prod-import-crm' ),
				$company_label,
				$qty_exported,
				$qty_ordered
			);
		}

		return sprintf(
			/* translators: 1: exported qty, 2: ordered qty */
			__( '%1$d / %2$d pcs submitted from China', 'ds-prod-import-crm' ),
			$qty_exported,
			$qty_ordered
		);
	}

	/**
	 * SQL for workflow-blocked statuses (mirrors is_on_hold_status).
	 *
	 * @param string $alias Orders table alias.
	 * @return string
	 */
	private static function workflow_blocked_status_sql( $alias = 'o' ) {
		$slugs = array_unique(
			array_merge(
				CRM_Order_Status::get_workflow_blocked_slugs(),
				array( 'on_hold', 'awaiting_acceptance' )
			)
		);

		if ( empty( $slugs ) ) {
			return '0=1';
		}

		global $wpdb;

		$quoted = array();
		foreach ( $slugs as $slug ) {
			$quoted[] = $wpdb->prepare( '%s', $slug );
		}

		return "{$alias}.status IN (" . implode( ', ', $quoted ) . ')';
	}

	/**
	 * Reusable per-order aggregate subqueries for list filtering.
	 *
	 * @return array<string, string>
	 */
	private static function list_filter_aggregate_sql() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$items = crm_table( 'order_items' );
		$di    = crm_table( 'delivery_items' );
		$esi   = crm_table( 'export_shipment_items' );
		$es    = crm_table( 'export_shipments' );

		$qty_ordered = "(SELECT COALESCE(SUM(oi.quantity), 0) FROM {$items} oi WHERE oi.order_id = o.id)";
		$qty_accepted = "(SELECT COALESCE(SUM(COALESCE(oi.accepted_quantity, oi.quantity)), 0) FROM {$items} oi WHERE oi.order_id = o.id)";
		$qty_delivered = "(SELECT COALESCE(SUM(di.quantity), 0) FROM {$di} di INNER JOIN {$items} oi ON oi.id = di.order_item_id WHERE oi.order_id = o.id)";
		$qty_exported = "(SELECT COALESCE(SUM(esi.quantity), 0) FROM {$esi} esi INNER JOIN {$es} es ON es.id = esi.shipment_id AND es.status != 'void' INNER JOIN {$items} oi ON oi.id = esi.order_item_id WHERE oi.order_id = o.id)";
		$qty_basis = "(CASE WHEN {$qty_accepted} > 0 OR (o.accepted_at IS NOT NULL AND o.accepted_at != '' AND o.accepted_at != '0000-00-00 00:00:00') THEN {$qty_accepted} ELSE {$qty_ordered} END)";

		$cache = array(
			'qty_ordered'   => $qty_ordered,
			'qty_accepted'  => $qty_accepted,
			'qty_delivered' => $qty_delivered,
			'qty_exported'  => $qty_exported,
			'qty_basis'     => $qty_basis,
		);

		return $cache;
	}

	/**
	 * WHERE fragment matching build_summary() current_step for one filter slug.
	 *
	 * @param string $step Filter slug.
	 * @return string
	 */
	private static function list_filter_where_sql( $step ) {
		$agg          = self::list_filter_aggregate_sql();
		$blocked      = self::workflow_blocked_status_sql( 'o' );
		$active       = '(' . CRM_Order_Status::workflow_active_sql( 'o' ) . " AND o.status != 'cancelled')";
		$qty_basis    = $agg['qty_basis'];
		$qty_del      = $agg['qty_delivered'];
		$qty_exp      = $agg['qty_exported'];
		$export_done  = "({$qty_basis} > 0 AND {$qty_exp} >= {$qty_basis})";
		$export_start = "({$qty_exp} > 0)";

		switch ( $step ) {
			case 'cancelled':
				return "o.status = 'cancelled'";
			case 'review':
				return "({$blocked} AND o.status != 'cancelled')";
			case 'delivered':
				return "({$active} AND {$qty_del} > 0)";
			case 'received_bd':
				return "({$active} AND {$qty_del} = 0 AND {$export_done})";
			case 'to_bangladesh':
				return "({$active} AND {$qty_del} = 0 AND NOT {$export_done} AND {$export_start})";
			case 'supplying':
				return "({$active} AND {$qty_del} = 0 AND NOT {$export_start})";
		}

		return '';
	}
}
