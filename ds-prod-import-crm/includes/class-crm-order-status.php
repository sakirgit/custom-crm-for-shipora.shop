<?php
/**
 * Order status registry and automation.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Order status helpers.
 */
class CRM_Order_Status {
	/**
	 * Cached active status rows keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $status_map = null;

	/**
	 * All active statuses ordered for UI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all_active() {
		global $wpdb;

		$table = crm_table( 'order_statuses' );

		$rows = $wpdb->get_results(
			"SELECT id, slug, label, color, is_system, is_closed, blocks_workflow, auto_on_paid, sort_order
			FROM {$table}
			WHERE status = 'active'
			ORDER BY sort_order ASC, label ASC",
			ARRAY_A
		);

		return $rows ? $rows : array();
	}

	/**
	 * Active statuses keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_status_map() {
		if ( null === self::$status_map ) {
			self::$status_map = array();
			foreach ( self::get_all_active() as $row ) {
				self::$status_map[ $row['slug'] ] = $row;
			}
		}

		return self::$status_map;
	}

	/**
	 * Reset cached status map (after CRUD).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$status_map = null;
	}

	/**
	 * Whether a status blocks delivery, billing, and automation until cleared.
	 *
	 * @param string $slug Status slug.
	 * @return bool
	 */
	public static function blocks_workflow( $slug ) {
		$slug = sanitize_key( $slug );
		$map  = self::get_status_map();

		return ! empty( $map[ $slug ]['blocks_workflow'] );
	}

	/**
	 * Slugs for statuses that block workflow.
	 *
	 * @return array<int, string>
	 */
	public static function get_workflow_blocked_slugs() {
		$slugs = array();
		foreach ( self::get_status_map() as $slug => $row ) {
			if ( ! empty( $row['blocks_workflow'] ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * SQL fragment: order alias is workflow-active (not in a blocking status).
	 *
	 * @param string $alias Orders table alias.
	 * @return string
	 */
	public static function workflow_active_sql( $alias = 'o' ) {
		$slugs = self::get_workflow_blocked_slugs();
		if ( empty( $slugs ) ) {
			return '1=1';
		}

		global $wpdb;

		$quoted = array();
		foreach ( $slugs as $slug ) {
			$quoted[] = $wpdb->prepare( '%s', $slug );
		}

		return "{$alias}.status NOT IN (" . implode( ', ', $quoted ) . ')';
	}

	/**
	 * Initial status when a new order is created.
	 *
	 * All orders enter the approval queue; use Accept order to move to Pending.
	 *
	 * @return string
	 */
	public static function initial_status_for_new_order() {
		if ( self::is_valid_slug( 'awaiting_acceptance' ) ) {
			return 'awaiting_acceptance';
		}

		return 'pending';
	}

	/**
	 * Status applied when an admin accepts an order.
	 *
	 * @return string
	 */
	public static function accepted_status_slug() {
		return 'pending';
	}

	/**
	 * Whether the user who created an order may still edit it (before admin acceptance).
	 *
	 * @param string $slug Order status slug.
	 * @return bool
	 */
	public static function own_creator_can_edit( $slug ) {
		return self::blocks_workflow( $slug );
	}

	/**
	 * Accept an order waiting for admin approval.
	 *
	 * @param int $order_id Order ID.
	 * @return true|\WP_Error
	 */
	public static function accept_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return new \WP_Error( 'invalid_order', __( 'Invalid order ID.', 'ds-prod-import-crm' ) );
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, order_number FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
				$order_id
			),
			ARRAY_A
		);

		if ( ! $order ) {
			return new \WP_Error( 'not_found', __( 'Order not found.', 'ds-prod-import-crm' ) );
		}

		if ( ! self::blocks_workflow( $order['status'] ) ) {
			return new \WP_Error( 'not_awaiting', __( 'This order is not waiting for acceptance.', 'ds-prod-import-crm' ) );
		}

		$target = self::accepted_status_slug();
		if ( ! self::is_valid_slug( $target ) ) {
			return new \WP_Error( 'invalid_target', __( 'Accepted status is not configured.', 'ds-prod-import-crm' ) );
		}

		$result = self::set_order_status( $order_id, $target );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Validate status slug exists and is active.
	 *
	 * @param string $slug Status slug.
	 * @return bool
	 */
	public static function is_valid_slug( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return false;
		}

		return isset( self::get_status_map()[ $slug ] );
	}

	/**
	 * Update order status slug (manual admin change or automation).
	 *
	 * @param int                  $order_id Order ID.
	 * @param string               $slug     Status slug.
	 * @param array<string, mixed> $options  Optional: audit (bool), source (string).
	 * @return true|\WP_Error
	 */
	public static function set_order_status( $order_id, $slug, $options = array() ) {
		global $wpdb;

		$order_id = absint( $order_id );
		$slug     = sanitize_key( $slug );

		if ( ! self::is_valid_slug( $slug ) ) {
			return new \WP_Error( 'invalid_status', __( 'Invalid order status.', 'ds-prod-import-crm' ) );
		}

		$old_status = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
				$order_id
			)
		);

		if ( $old_status === $slug ) {
			return true;
		}

		$updated = $wpdb->update(
			crm_table( 'orders' ),
			array(
				'status'     => $slug,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $order_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'update_failed', __( 'Could not update order status.', 'ds-prod-import-crm' ) );
		}

		if ( ! empty( $options['audit'] ) ) {
			$map       = self::get_status_map();
			$old_label = $map[ $old_status ]['label'] ?? $old_status;
			$new_label = $map[ $slug ]['label'] ?? $slug;
			$source    = sanitize_key( (string) ( $options['source'] ?? 'system' ) );
			$suffix    = '';
			if ( 'delivery' === $source ) {
				$suffix = ' ' . __( '(from delivery)', 'ds-prod-import-crm' );
			} elseif ( 'payment' === $source ) {
				$suffix = ' ' . __( '(from payment)', 'ds-prod-import-crm' );
			}

			CRM_Audit::log(
				'update',
				'orders',
				$order_id,
				sprintf( 'Status changed to %s%s', $new_label, $suffix ),
				array(
					'changes' => CRM_Audit::describe_changes(
						array( 'status' => $old_label ),
						array( 'status' => $new_label ),
						array( 'status' => __( 'Status', 'ds-prod-import-crm' ) )
					),
					'source'  => $source,
				)
			);
		}

		return true;
	}

	/**
	 * Sync delivery-driven status (pending / partial_delivered / completed delivery).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function sync_delivery_status( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( $order_id < 1 ) {
			return;
		}

		$status = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
				$order_id
			)
		);

		if ( self::blocks_workflow( (string) $status ) || 'cancelled' === $status ) {
			return;
		}

		$summary = CRM_Ledger::get_order_summary( $order_id );
		if ( $summary['total_due'] <= 0 ) {
			self::maybe_set_paid_status( $order_id );
			return;
		}

		$items_table          = crm_table( 'order_items' );
		$delivery_items_table = crm_table( 'delivery_items' );

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(COALESCE(oi.accepted_quantity, oi.quantity)), 0) AS qty_ordered,
					COALESCE((
						SELECT SUM(di.quantity)
						FROM {$delivery_items_table} di
						INNER JOIN {$items_table} oi2 ON oi2.id = di.order_item_id
						WHERE oi2.order_id = %d
					), 0) AS qty_delivered
				FROM {$items_table} oi
				WHERE oi.order_id = %d",
				$order_id,
				$order_id
			),
			ARRAY_A
		);

		$ordered   = (int) ( $totals['qty_ordered'] ?? 0 );
		$delivered = (int) ( $totals['qty_delivered'] ?? 0 );

		if ( $delivered <= 0 ) {
			return;
		}

		if ( $delivered >= $ordered ) {
			self::set_order_status(
				$order_id,
				'completed',
				array(
					'audit'  => true,
					'source' => 'delivery',
				)
			);
		} else {
			self::set_order_status(
				$order_id,
				'partial_delivered',
				array(
					'audit'  => true,
					'source' => 'delivery',
				)
			);
		}
	}

	/**
	 * When fully paid, apply status with auto_on_paid flag (usually completed).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function maybe_set_paid_status( $order_id ) {
		global $wpdb;

		$status = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT status FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
				$order_id
			)
		);

		if ( self::blocks_workflow( (string) $status ) || 'cancelled' === $status ) {
			return;
		}

		$summary = CRM_Ledger::get_order_summary( $order_id );
		if ( $summary['total_bill'] <= 0 || $summary['total_due'] > 0.009 ) {
			return;
		}

		$status_row = $wpdb->get_row(
			"SELECT slug FROM " . crm_table( 'order_statuses' ) . " WHERE auto_on_paid = 1 AND status = 'active' ORDER BY sort_order ASC LIMIT 1",
			ARRAY_A
		);

		if ( $status_row && ! empty( $status_row['slug'] ) ) {
			self::set_order_status(
				$order_id,
				$status_row['slug'],
				array(
					'audit'  => true,
					'source' => 'payment',
				)
			);
		}
	}

	/**
	 * Seed default statuses on install.
	 *
	 * @return void
	 */
	public static function seed_defaults() {
		global $wpdb;

		$table = crm_table( 'order_statuses' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count > 0 ) {
			return;
		}

		$defaults = array(
			array( 'awaiting_acceptance', __( 'Awaiting acceptance', 'ds-prod-import-crm' ), '#dc2626', 1, 0, 1, 0, 5 ),
			array( 'pending', __( 'Pending', 'ds-prod-import-crm' ), '#f59e0b', 1, 0, 0, 0, 10 ),
			array( 'partial_delivered', __( 'Partial Delivered', 'ds-prod-import-crm' ), '#3b82f6', 1, 0, 0, 0, 20 ),
			array( 'completed', __( 'Completed', 'ds-prod-import-crm' ), '#16a34a', 1, 1, 0, 1, 30 ),
			array( 'cancelled', __( 'Cancelled', 'ds-prod-import-crm' ), '#6b7280', 1, 1, 0, 0, 40 ),
		);

		foreach ( $defaults as $row ) {
			$wpdb->insert(
				$table,
				array(
					'slug'            => $row[0],
					'label'           => $row[1],
					'color'           => $row[2],
					'is_system'       => $row[3],
					'is_closed'       => $row[4],
					'blocks_workflow' => $row[5],
					'auto_on_paid'    => $row[6],
					'sort_order'      => $row[7],
					'status'          => 'active',
					'created_at'      => current_time( 'mysql' ),
					'updated_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}
	}
}
