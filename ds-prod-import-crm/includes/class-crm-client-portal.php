<?php
/**
 * Client portal users — linked CRM clients, order visibility, scoped access.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for the CRM Client role (portal users linked to client records).
 */
class CRM_Client_Portal {
	/**
	 * CRM Client role slug.
	 */
	const ROLE = 'crm_client';

	/**
	 * User meta key for client record ID.
	 */
	const USER_META_CLIENT_ID = 'ds_crm_client_id';

	/**
	 * Register WordPress hooks for portal user ↔ client sync.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_user_role', array( __CLASS__, 'on_user_role_added' ), 10, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_role_set' ), 10, 3 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    Role added.
	 * @return void
	 */
	public static function on_user_role_added( $user_id, $role ) {
		if ( self::ROLE === $role ) {
			self::ensure_client_record_for_user( (int) $user_id );
		}
	}

	/**
	 * @param int          $user_id   User ID.
	 * @param string       $role      New role.
	 * @param array<mixed> $old_roles Previous roles.
	 * @return void
	 */
	public static function on_user_role_set( $user_id, $role, $old_roles ) {
		if ( self::ROLE === $role ) {
			self::ensure_client_record_for_user( (int) $user_id );
		}
	}

	/**
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && in_array( self::ROLE, (array) $user->roles, true ) ) {
			self::ensure_client_record_for_user( (int) $user_id );
		}
	}

	/**
	 * Whether the user is a client portal user.
	 *
	 * @param \WP_User|null $user User or current user.
	 * @return bool
	 */
	public static function is_client_user( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Client orders visibility for portal users.
	 *
	 * @return string 'own'|'all'
	 */
	public static function orders_scope() {
		$scope = crm_get_settings()['client_orders_scope'] ?? 'own';

		return 'all' === $scope ? 'all' : 'own';
	}

	/**
	 * Linked CRM client ID for a WordPress user.
	 *
	 * @param int $user_id User ID (0 = current).
	 * @return int
	 */
	public static function get_linked_client_id( $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( $user_id < 1 ) {
			return 0;
		}

		$meta_id = absint( get_user_meta( $user_id, self::USER_META_CLIENT_ID, true ) );
		if ( $meta_id > 0 ) {
			return $meta_id;
		}

		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . crm_table( 'clients' ) . ' WHERE wp_user_id = %d LIMIT 1',
				$user_id
			)
		);
	}

	/**
	 * Apply client portal filter to order list WHERE clause.
	 *
	 * @param array<int, string> $where  WHERE fragments.
	 * @param array<int, mixed>  $params Bound params.
	 * @return void
	 */
	public static function apply_order_list_scope( array &$where, array &$params ) {
		if ( ! self::is_client_user() || 'all' === self::orders_scope() ) {
			return;
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			$where[] = '1=0';
			return;
		}

		$where[]  = 'o.client_id = %d';
		$params[] = $client_id;
	}

	/**
	 * Whether the current user may view an order row.
	 *
	 * @param array<string, mixed> $order Order row with client_id.
	 * @return bool
	 */
	public static function user_can_view_order( $order ) {
		if ( ! self::is_client_user() ) {
			return true;
		}

		if ( 'all' === self::orders_scope() ) {
			return true;
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			return false;
		}

		return $client_id === (int) ( $order['client_id'] ?? 0 );
	}

	/**
	 * Resolve client_id when a portal user saves an order.
	 *
	 * @param int $requested_client_id Client ID from form.
	 * @return int|\WP_Error
	 */
	public static function resolve_client_id_for_save( $requested_client_id ) {
		if ( ! self::is_client_user() ) {
			return absint( $requested_client_id );
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			return new \WP_Error(
				'client_not_linked',
				__( 'Your account is not linked to a client record. Contact the administrator.', 'ds-prod-import-crm' )
			);
		}

		return $client_id;
	}

	/**
	 * Modules visible to client portal users.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_modules() {
		return array( 'orders', 'delivery', 'payments' );
	}

	/**
	 * Apply client portal filter to payment list WHERE clause.
	 *
	 * Uses payments.client_id (alias `p`). Scope follows client_orders_scope
	 * so portal visibility stays consistent with orders.
	 *
	 * @param array<int, string> $where  WHERE fragments.
	 * @param array<int, mixed>  $params Bound params.
	 * @return void
	 */
	public static function apply_payment_list_scope( array &$where, array &$params ) {
		if ( ! self::is_client_user() || 'all' === self::orders_scope() ) {
			return;
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			$where[] = '1=0';
			return;
		}

		$where[]  = 'p.client_id = %d';
		$params[] = $client_id;
	}

	/**
	 * Whether the current user may view a payment row.
	 *
	 * @param array<string, mixed> $payment Payment row with client_id.
	 * @return bool
	 */
	public static function user_can_view_payment( $payment ) {
		if ( ! self::is_client_user() ) {
			return true;
		}

		if ( 'all' === self::orders_scope() ) {
			return true;
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			return false;
		}

		return $client_id === (int) ( $payment['client_id'] ?? 0 );
	}

	/**
	 * Apply client portal filter to delivery list WHERE clause.
	 *
	 * Uses deliveries.client_id (alias `d`). Scope follows client_orders_scope
	 * so portal visibility stays consistent with orders.
	 *
	 * @param array<int, string> $where  WHERE fragments.
	 * @param array<int, mixed>  $params Bound params.
	 * @return void
	 */
	public static function apply_delivery_list_scope( array &$where, array &$params ) {
		if ( ! self::is_client_user() || 'all' === self::orders_scope() ) {
			return;
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			$where[] = '1=0';
			return;
		}

		$where[]  = 'd.client_id = %d';
		$params[] = $client_id;
	}

	/**
	 * Whether the current user may view a delivery row.
	 *
	 * @param array<string, mixed> $delivery Delivery row with client_id.
	 * @return bool
	 */
	public static function user_can_view_delivery( $delivery ) {
		if ( ! self::is_client_user() ) {
			return true;
		}

		if ( 'all' === self::orders_scope() ) {
			return true;
		}

		$client_id = self::get_linked_client_id();
		if ( $client_id < 1 ) {
			return false;
		}

		return $client_id === (int) ( $delivery['client_id'] ?? 0 );
	}

	/**
	 * Whether a module is available to client portal users.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function client_can_access_module( $module ) {
		return CRM_Access::user_can_access_scoped_module( $module, self::allowed_modules() );
	}

	/**
	 * Assign portal user to a client (clears conflicting links).
	 *
	 * @param int $client_id Client ID.
	 * @param int $user_id   User ID (0 clears link).
	 * @return void
	 */
	public static function assign_portal_user( $client_id, $user_id ) {
		global $wpdb;

		$client_id = absint( $client_id );
		$user_id   = absint( $user_id );
		$table     = crm_table( 'clients' );

		if ( $client_id < 1 ) {
			return;
		}

		$prev_user = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT wp_user_id FROM {$table} WHERE id = %d",
				$client_id
			)
		);

		if ( $user_id > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET wp_user_id = NULL WHERE wp_user_id = %d AND id != %d",
					$user_id,
					$client_id
				)
			);
		}

		if ( $prev_user > 0 && $prev_user !== $user_id ) {
			delete_user_meta( $prev_user, self::USER_META_CLIENT_ID );
		}

		self::link_user_to_client( $client_id, $user_id );
	}

	/**
	 * WordPress users eligible for client portal login.
	 *
	 * @return array<int, array{id:int,label:string}>
	 */
	public static function portal_user_options() {
		$users = get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$label = $user->display_name ? $user->display_name : $user->user_login;
			if ( $user->user_email ) {
				$label .= ' (' . $user->user_email . ')';
			}
			$options[] = array(
				'id'    => (int) $user->ID,
				'label' => $label,
			);
		}

		return $options;
	}

	/**
	 * Ensure every CRM Client WP user has a linked row in the clients table.
	 *
	 * @return int Number of client records created.
	 */
	public static function sync_all_portal_users() {
		$users   = get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		$created = 0;

		foreach ( $users as $user_id ) {
			$before = self::get_linked_client_id( (int) $user_id );
			$after  = self::ensure_client_record_for_user( (int) $user_id );
			if ( $before < 1 && $after > 0 ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Create (or return) the CRM client record for a portal user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Client ID or 0.
	 */
	public static function ensure_client_record_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id < 1 ) {
			return 0;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return 0;
		}

		$linked = self::get_linked_client_id( $user_id );
		if ( $linked > 0 ) {
			return $linked;
		}

		global $wpdb;

		$table = crm_table( 'clients' );
		$name  = $user->display_name ? $user->display_name : $user->user_login;
		$email = sanitize_email( $user->user_email );
		$now   = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'       => sanitize_text_field( $name ),
				'email'      => $email,
				'phone'      => '',
				'address'    => '',
				'notes'      => __( 'Auto-created for CRM Client portal login.', 'ds-prod-import-crm' ),
				'status'     => 'active',
				'wp_user_id' => $user_id,
				'created_at' => $now,
				'updated_at' => $now,
				'created_by' => get_current_user_id() ? get_current_user_id() : 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$client_id = (int) $wpdb->insert_id;
		if ( $client_id > 0 ) {
			update_user_meta( $user_id, self::USER_META_CLIENT_ID, $client_id );
		}

		return $client_id;
	}

	/**
	 * Overview rows for settings / admin (CRM Client users and client links).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_portal_users_overview() {
		$users = get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);

		if ( ! $users ) {
			return array();
		}

		global $wpdb;

		$table = crm_table( 'clients' );
		$rows  = array();

		foreach ( $users as $user ) {
			$client_id   = self::get_linked_client_id( (int) $user->ID );
			$client_name = '';
			$client_url  = '';

			if ( $client_id > 0 ) {
				$client_name = (string) $wpdb->get_var(
					$wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $client_id )
				);
				if ( crm_get_public_app_url() ) {
					$client_url = add_query_arg(
						array(
							'crm_module' => 'clients',
						),
						crm_get_public_app_url()
					);
				}
			}

			$label = $user->display_name ? $user->display_name : $user->user_login;
			if ( $user->user_email ) {
				$label .= ' (' . $user->user_email . ')';
			}

			$rows[] = array(
				'user_id'     => (int) $user->ID,
				'user_label'  => $label,
				'client_id'   => $client_id,
				'client_name' => $client_name,
				'client_url'  => $client_url,
				'is_linked'   => $client_id > 0 && '' !== $client_name,
			);
		}

		return $rows;
	}

	/**
	 * Link a WordPress user to a client record (both directions).
	 *
	 * @param int $client_id Client ID.
	 * @param int $user_id   User ID (0 clears link).
	 * @return void
	 */
	public static function link_user_to_client( $client_id, $user_id ) {
		global $wpdb;

		$client_id = absint( $client_id );
		$user_id   = absint( $user_id );
		$table     = crm_table( 'clients' );

		if ( $client_id > 0 ) {
			$wpdb->update(
				$table,
				array(
					'wp_user_id' => $user_id > 0 ? $user_id : null,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $client_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		if ( $user_id > 0 ) {
			if ( $client_id > 0 ) {
				update_user_meta( $user_id, self::USER_META_CLIENT_ID, $client_id );
			} else {
				delete_user_meta( $user_id, self::USER_META_CLIENT_ID );
			}
		}
	}
}
