<?php
/**
 * CRM audit trail — who did what, when, on which record.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Central activity and change logging for multi-user CRM.
 */
class CRM_Audit {
	/**
	 * Human labels for modules.
	 *
	 * @return array<string, string>
	 */
	public static function module_labels() {
		return array(
			'orders'             => __( 'Orders', 'ds-prod-import-crm' ),
			'clients'            => __( 'Clients', 'ds-prod-import-crm' ),
			'companies'          => __( 'Companies', 'ds-prod-import-crm' ),
			'products'           => __( 'Products', 'ds-prod-import-crm' ),
			'product_categories' => __( 'Product categories', 'ds-prod-import-crm' ),
			'warehouse'          => __( 'Warehouse', 'ds-prod-import-crm' ),
			'delivery'           => __( 'Delivery', 'ds-prod-import-crm' ),
			'shipments'          => __( 'Exports', 'ds-prod-import-crm' ),
			'payments'           => __( 'Payments', 'ds-prod-import-crm' ),
			'company_payments'   => __( 'Supplier payments', 'ds-prod-import-crm' ),
			'settings'           => __( 'Settings', 'ds-prod-import-crm' ),
			'team'               => __( 'Team', 'ds-prod-import-crm' ),
		);
	}

	/**
	 * Human labels for actions.
	 *
	 * @return array<string, string>
	 */
	public static function action_labels() {
		return array(
			'create' => __( 'Created', 'ds-prod-import-crm' ),
			'update' => __( 'Updated', 'ds-prod-import-crm' ),
			'delete' => __( 'Deleted', 'ds-prod-import-crm' ),
			'void'   => __( 'Voided', 'ds-prod-import-crm' ),
		);
	}

	/**
	 * Write an audit row.
	 *
	 * @param string              $action      create|update|delete|void.
	 * @param string              $module      Module slug.
	 * @param int                 $record_id   Record ID.
	 * @param string              $description Summary text.
	 * @param array<string,mixed> $meta        Optional structured context.
	 * @return void
	 */
	public static function log( $action, $module, $record_id, $description, $meta = array() ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$payload = array(
			'user_id'     => $user_id > 0 ? $user_id : 0,
			'action'      => sanitize_key( $action ),
			'module'      => sanitize_key( $module ),
			'record_id'   => absint( $record_id ),
			'description' => sanitize_text_field( $description ),
			'created_at'  => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( self::table_has_meta_column() && ! empty( $meta ) ) {
			$payload['meta'] = wp_json_encode( $meta );
			$formats[]       = '%s';
		}

		$wpdb->insert( crm_table( 'activity_log' ), $payload, $formats );
	}

	/**
	 * Build a change summary from before/after field maps.
	 *
	 * @param array<string, mixed> $before Field => value before.
	 * @param array<string, mixed> $after  Field => value after.
	 * @param array<string, string> $labels Field => human label.
	 * @return array<int, string>
	 */
	public static function describe_changes( array $before, array $after, array $labels ) {
		$changes = array();

		foreach ( $labels as $field => $label ) {
			$old = isset( $before[ $field ] ) ? (string) $before[ $field ] : '';
			$new = isset( $after[ $field ] ) ? (string) $after[ $field ] : '';

			if ( $old !== $new ) {
				$changes[] = sprintf(
					'%s: %s → %s',
					$label,
					'' === $old ? '—' : $old,
					'' === $new ? '—' : $new
				);
			}
		}

		return $changes;
	}

	/**
	 * Current user ID for created_by / updated_by columns.
	 *
	 * @return int|null
	 */
	public static function current_user_id() {
		$user_id = get_current_user_id();

		return $user_id > 0 ? $user_id : null;
	}

	/**
	 * Rows for one record timeline.
	 *
	 * @param string $module    Module slug.
	 * @param int    $record_id Record ID.
	 * @param int    $limit     Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_record( $module, $record_id, $limit = 25 ) {
		global $wpdb;

		$table = crm_table( 'activity_log' );
		$users = $wpdb->users;
		$limit = max( 1, min( 100, (int) $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.display_name AS user_name
				FROM {$table} a
				LEFT JOIN {$users} u ON u.ID = a.user_id
				WHERE a.module = %s AND a.record_id = %d
				ORDER BY a.created_at DESC, a.id DESC
				LIMIT %d",
				sanitize_key( $module ),
				absint( $record_id ),
				$limit
			),
			ARRAY_A
		);

		return self::format_rows( $rows ? $rows : array() );
	}

	/**
	 * Full timeline for an order: order audit + related payments, deliveries, exports.
	 *
	 * @param int $order_id Order ID.
	 * @param int $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_order( $order_id, $limit = 50 ) {
		global $wpdb;

		$order_id = absint( $order_id );
		$limit    = max( 1, min( 100, (int) $limit ) );

		if ( $order_id < 1 ) {
			return array();
		}

		$table = crm_table( 'activity_log' );
		$users = $wpdb->users;

		$payment_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . crm_table( 'payments' ) . ' WHERE order_id = %d',
				$order_id
			)
		);
		$delivery_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . crm_table( 'deliveries' ) . ' WHERE order_id = %d',
				$order_id
			)
		);
		$shipment_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . crm_table( 'export_shipments' ) . ' WHERE order_id = %d',
				$order_id
			)
		);

		$clauses = array( '(a.module = %s AND a.record_id = %d)' );
		$params  = array( 'orders', $order_id );

		$related = array(
			'payments'  => array_map( 'absint', $payment_ids ? $payment_ids : array() ),
			'delivery'  => array_map( 'absint', $delivery_ids ? $delivery_ids : array() ),
			'shipments' => array_map( 'absint', $shipment_ids ? $shipment_ids : array() ),
		);

		foreach ( $related as $module => $ids ) {
			$ids = array_values( array_filter( $ids ) );
			if ( empty( $ids ) ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$clauses[]    = "(a.module = %s AND a.record_id IN ({$placeholders}))";
			$params[]     = $module;
			foreach ( $ids as $rid ) {
				$params[] = $rid;
			}
		}

		// Catch related rows after the source record was deleted/voided (meta.order_id).
		if ( self::table_has_meta_column() ) {
			$clauses[] = 'a.meta LIKE %s';
			$params[]  = '%"order_id":' . $order_id . '%';
		}

		$where_sql = implode( ' OR ', $clauses );
		$params[]  = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dynamic OR/IN clauses built safely above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.display_name AS user_name
				FROM {$table} a
				LEFT JOIN {$users} u ON u.ID = a.user_id
				WHERE {$where_sql}
				ORDER BY a.created_at DESC, a.id DESC
				LIMIT %d",
				$params
			),
			ARRAY_A
		);

		return self::format_rows( $rows ? $rows : array(), true );
	}

	/**
	 * Paginated global activity feed (admin).
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array<string, mixed>
	 */
	public static function query_list( array $args = array() ) {
		global $wpdb;

		$table = crm_table( 'activity_log' );
		$users = $wpdb->users;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = in_array( (int) ( $args['per_page'] ?? 25 ), array( 10, 25, 50, 100 ), true ) ? (int) $args['per_page'] : 25;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['module'] ) ) {
			$where[]  = 'a.module = %s';
			$params[] = sanitize_key( $args['module'] );
		}

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'a.action = %s';
			$params[] = sanitize_key( $args['action'] );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'a.user_id = %d';
			$params[] = absint( $args['user_id'] );
		}

		if ( ! empty( $args['record_id'] ) ) {
			$where[]  = 'a.record_id = %d';
			$params[] = absint( $args['record_id'] );
		}

		$search = ! empty( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(a.description LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'a.created_at >= %s';
			$params[] = crm_normalize_date( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'a.created_at <= %s';
			$params[] = crm_normalize_date( $args['date_to'] ) . ' 23:59:59';
		}

		$where_sql      = implode( ' AND ', $where );
		$needs_user_join = '' !== $search;

		if ( $needs_user_join ) {
			$count_sql = "SELECT COUNT(*) FROM {$table} a LEFT JOIN {$users} u ON u.ID = a.user_id WHERE {$where_sql}";
		} else {
			$count_sql = "SELECT COUNT(*) FROM {$table} a WHERE {$where_sql}";
		}

		$total = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: (int) $wpdb->get_var( $count_sql );

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $page > $total_pages ) {
			$page   = $total_pages;
			$offset = ( $page - 1 ) * $per_page;
		}

		$select_cols = 'a.id, a.user_id, a.action, a.module, a.record_id, a.description, a.created_at';
		if ( self::table_has_meta_column() ) {
			$select_cols .= ', a.meta';
		}

		$list_sql = "SELECT {$select_cols}, u.display_name AS user_name
			FROM {$table} a
			LEFT JOIN {$users} u ON u.ID = a.user_id
			WHERE {$where_sql}
			ORDER BY a.id DESC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return array(
			'items'       => self::format_rows( $rows ? $rows : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
			'summary'     => CRM_Module_Summary::activity( $where_sql, $params, $needs_user_join ),
		);
	}

	/**
	 * CRM users for activity filter dropdown.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_crm_users_for_filter() {
		$users = get_users(
			array(
				'role__in' => CRM_Access::get_crm_role_slugs(),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		$out = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'id'    => $user->ID,
				'label' => $user->display_name,
				'email' => $user->user_email,
			);
		}

		$admin_users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email' ),
			)
		);

		foreach ( $admin_users as $user ) {
			$out[] = array(
				'id'    => $user->ID,
				'label' => $user->display_name . ' (Admin)',
				'email' => $user->user_email,
			);
		}

		return $out;
	}

	/**
	 * Whether activity_log has meta column (post-upgrade).
	 *
	 * @return bool
	 */
	public static function table_has_meta_column() {
		static $has = null;

		if ( null !== $has ) {
			return $has;
		}

		global $wpdb;
		$table = crm_table( 'activity_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'meta'" ) );

		return $has;
	}

	/**
	 * Normalize rows for API/JS.
	 *
	 * @param array<int, array<string, mixed>> $rows              Raw rows.
	 * @param bool                             $for_order_timeline Richer labels/badges for order page.
	 * @return array<int, array<string, mixed>>
	 */
	private static function format_rows( array $rows, $for_order_timeline = false ) {
		$module_labels = self::module_labels();
		$action_labels = self::action_labels();
		$seen          = array();

		$out = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
			}

			$row['module_label'] = $module_labels[ $row['module'] ] ?? ucwords( str_replace( '_', ' ', $row['module'] ) );
			$row['action_label'] = $action_labels[ $row['action'] ] ?? ucfirst( $row['action'] );
			$row['user_name']    = $row['user_name'] ?: __( 'System', 'ds-prod-import-crm' );
			$row['meta']         = self::decode_meta( $row['meta'] ?? null );
			$row['changes']      = isset( $row['meta']['changes'] ) && is_array( $row['meta']['changes'] ) ? $row['meta']['changes'] : array();
			$row['badge']        = sanitize_key( (string) ( $row['action'] ?? 'update' ) );

			if ( $for_order_timeline ) {
				$enriched            = self::enrich_order_timeline_row( $row );
				$row['action_label'] = $enriched['action_label'];
				$row['badge']        = $enriched['badge'];
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Human action label + badge key for the order activity timeline.
	 *
	 * @param array<string, mixed> $row Formatted row.
	 * @return array{action_label:string,badge:string}
	 */
	private static function enrich_order_timeline_row( array $row ) {
		$module = sanitize_key( (string) ( $row['module'] ?? '' ) );
		$action = sanitize_key( (string) ( $row['action'] ?? '' ) );
		$desc   = strtolower( (string) ( $row['description'] ?? '' ) );

		if ( 'payments' === $module ) {
			if ( 'delete' === $action ) {
				return array(
					'action_label' => __( 'Payment removed', 'ds-prod-import-crm' ),
					'badge'        => 'payment-delete',
				);
			}
			if ( 'update' === $action ) {
				return array(
					'action_label' => __( 'Payment updated', 'ds-prod-import-crm' ),
					'badge'        => 'payment',
				);
			}
			return array(
				'action_label' => __( 'Payment', 'ds-prod-import-crm' ),
				'badge'        => 'payment',
			);
		}

		if ( 'delivery' === $module ) {
			if ( 'void' === $action ) {
				return array(
					'action_label' => __( 'Delivery voided', 'ds-prod-import-crm' ),
					'badge'        => 'delivery-void',
				);
			}
			return array(
				'action_label' => __( 'Delivery', 'ds-prod-import-crm' ),
				'badge'        => 'delivery',
			);
		}

		if ( 'shipments' === $module ) {
			if ( 'void' === $action ) {
				return array(
					'action_label' => __( 'Export voided', 'ds-prod-import-crm' ),
					'badge'        => 'export-void',
				);
			}
			if ( 'update' === $action ) {
				return array(
					'action_label' => __( 'Export updated', 'ds-prod-import-crm' ),
					'badge'        => 'export',
				);
			}
			return array(
				'action_label' => __( 'Export', 'ds-prod-import-crm' ),
				'badge'        => 'export',
			);
		}

		if ( 'orders' === $module ) {
			if ( 'create' === $action ) {
				return array(
					'action_label' => __( 'Created', 'ds-prod-import-crm' ),
					'badge'        => 'create',
				);
			}
			if ( 'void' === $action ) {
				return array(
					'action_label' => __( 'Cancelled', 'ds-prod-import-crm' ),
					'badge'        => 'void',
				);
			}
			if ( false !== strpos( $desc, 'approved' ) ) {
				return array(
					'action_label' => __( 'Approved', 'ds-prod-import-crm' ),
					'badge'        => 'approve',
				);
			}
			if ( false !== strpos( $desc, 'status changed' ) ) {
				return array(
					'action_label' => __( 'Status', 'ds-prod-import-crm' ),
					'badge'        => 'status',
				);
			}
			if ( false !== strpos( $desc, 'price' ) ) {
				return array(
					'action_label' => __( 'Pricing', 'ds-prod-import-crm' ),
					'badge'        => 'pricing',
				);
			}
			if ( false !== strpos( $desc, 'notes' ) ) {
				return array(
					'action_label' => __( 'Notes', 'ds-prod-import-crm' ),
					'badge'        => 'notes',
				);
			}
			return array(
				'action_label' => __( 'Updated', 'ds-prod-import-crm' ),
				'badge'        => 'update',
			);
		}

		$action_labels = self::action_labels();

		return array(
			'action_label' => $action_labels[ $action ] ?? ucfirst( $action ),
			'badge'        => $action ?: 'update',
		);
	}

	/**
	 * @param mixed $raw Meta column value.
	 * @return array<string, mixed>
	 */
	private static function decode_meta( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
