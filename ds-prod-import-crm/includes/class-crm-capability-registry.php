<?php
/**
 * CRM capability registry — modules, actions, and legacy bundles.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for granular CRM permissions.
 */
class CRM_Capability_Registry {
	/**
	 * Module definitions: label, menu cap, legacy bundle cap, actions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_modules() {
		return array(
			'general' => array(
				'label'       => __( 'General', 'ds-prod-import-crm' ),
				'menu_cap'    => null,
				'legacy_cap'  => null,
				'actions'     => array(
					'crm_view_dashboard'  => __( 'View dashboard', 'ds-prod-import-crm' ),
					'crm_manage_settings' => __( 'CRM settings & team', 'ds-prod-import-crm' ),
					'crm_manage_users'    => __( 'Manage users (legacy)', 'ds-prod-import-crm' ),
				),
			),
			'orders' => array(
				'label'      => __( 'Orders', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_orders_view',
				'legacy_cap' => 'crm_manage_orders',
				'actions'    => array(
					'crm_orders_view'   => __( 'View orders & details', 'ds-prod-import-crm' ),
					'crm_orders_create' => __( 'Create new orders', 'ds-prod-import-crm' ),
					'crm_orders_edit'   => __( 'Edit any orders', 'ds-prod-import-crm' ),
					'crm_orders_status' => __( 'Change order status', 'ds-prod-import-crm' ),
					'crm_orders_accept' => __( 'Accept orders (approve for processing)', 'ds-prod-import-crm' ),
					'crm_orders_cancel' => __( 'Cancel any orders', 'ds-prod-import-crm' ),
				),
			),
			'clients' => array(
				'label'      => __( 'Clients', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_clients_view',
				'legacy_cap' => 'crm_manage_clients',
				'actions'    => array(
					'crm_clients_view'   => __( 'View clients', 'ds-prod-import-crm' ),
					'crm_clients_create' => __( 'Add clients', 'ds-prod-import-crm' ),
					'crm_clients_edit'   => __( 'Edit clients', 'ds-prod-import-crm' ),
					'crm_clients_delete' => __( 'Delete clients', 'ds-prod-import-crm' ),
				),
			),
			'delivery' => array(
				'label'      => __( 'Delivery', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_delivery_view',
				'legacy_cap' => 'crm_manage_delivery',
				'actions'    => array(
					'crm_delivery_view'  => __( 'View deliveries', 'ds-prod-import-crm' ),
					'crm_delivery_create' => __( 'Create deliveries', 'ds-prod-import-crm' ),
					'crm_delivery_edit'  => __( 'Edit deliveries', 'ds-prod-import-crm' ),
					'crm_delivery_void'  => __( 'Void deliveries', 'ds-prod-import-crm' ),
				),
			),
			'shipments' => array(
				'label'      => __( 'China Export', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_shipments_view',
				'legacy_cap' => 'crm_manage_shipments',
				'actions'    => array(
					'crm_shipments_view'   => __( 'View export shipments', 'ds-prod-import-crm' ),
					'crm_shipments_create' => __( 'Record export shipments', 'ds-prod-import-crm' ),
					'crm_shipments_amend'  => __( 'Request export line quantity changes', 'ds-prod-import-crm' ),
					'crm_shipments_review' => __( 'Approve or decline export quantity change requests', 'ds-prod-import-crm' ),
					'crm_shipments_void'   => __( 'Void export shipments', 'ds-prod-import-crm' ),
				),
			),
			'payments' => array(
				'label'      => __( 'Payments', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_payments_view',
				'legacy_cap' => 'crm_manage_payments',
				'actions'    => array(
					'crm_payments_view'   => __( 'View payments', 'ds-prod-import-crm' ),
					'crm_payments_create' => __( 'Record payments', 'ds-prod-import-crm' ),
					'crm_payments_edit'   => __( 'Edit payments', 'ds-prod-import-crm' ),
					'crm_payments_delete' => __( 'Delete payments', 'ds-prod-import-crm' ),
				),
			),
			'billing' => array(
				'label'      => __( 'Billing', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_billing_view',
				'legacy_cap' => 'crm_manage_billing',
				'actions'    => array(
					'crm_billing_view' => __( 'View billing & supplier payments', 'ds-prod-import-crm' ),
					'crm_billing_edit' => __( 'Manage billing entries', 'ds-prod-import-crm' ),
				),
			),
			'warehouse' => array(
				'label'      => __( 'Warehouse', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_stock_view',
				'legacy_cap' => null,
				'actions'    => array(
					'crm_stock_view'    => __( 'View stock & receives', 'ds-prod-import-crm' ),
					'crm_stock_receive' => __( 'Receive stock', 'ds-prod-import-crm' ),
					'crm_stock_void'    => __( 'Void stock receives', 'ds-prod-import-crm' ),
				),
			),
			'companies' => array(
				'label'      => __( 'Companies / suppliers', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_companies_view',
				'legacy_cap' => 'crm_manage_companies',
				'actions'    => array(
					'crm_companies_view'   => __( 'View companies', 'ds-prod-import-crm' ),
					'crm_companies_create' => __( 'Add companies', 'ds-prod-import-crm' ),
					'crm_companies_edit'   => __( 'Edit companies', 'ds-prod-import-crm' ),
					'crm_companies_delete' => __( 'Delete companies', 'ds-prod-import-crm' ),
				),
			),
			'products' => array(
				'label'      => __( 'Products & categories', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_products_view',
				'legacy_cap' => 'crm_manage_products',
				'actions'    => array(
					'crm_products_view'   => __( 'View products & categories', 'ds-prod-import-crm' ),
					'crm_products_create' => __( 'Add products & categories', 'ds-prod-import-crm' ),
					'crm_products_edit'   => __( 'Edit products & categories', 'ds-prod-import-crm' ),
					'crm_products_delete' => __( 'Delete products & categories', 'ds-prod-import-crm' ),
				),
			),
			'reports' => array(
				'label'      => __( 'Reports', 'ds-prod-import-crm' ),
				'menu_cap'   => 'crm_view_reports',
				'legacy_cap' => null,
				'actions'    => array(
					'crm_view_reports' => __( 'View reports', 'ds-prod-import-crm' ),
				),
			),
		);
	}

	/**
	 * Legacy caps that imply all actions in a module.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function get_legacy_bundles() {
		$bundles = array();

		foreach ( self::get_modules() as $module ) {
			if ( empty( $module['legacy_cap'] ) || empty( $module['actions'] ) ) {
				continue;
			}
			$bundles[ $module['legacy_cap'] ] = array_keys( $module['actions'] );
		}

		$bundles['crm_receive_stock'] = array( 'crm_stock_receive', 'crm_stock_view' );
		$bundles['crm_view_stock']    = array( 'crm_stock_view' );

		return $bundles;
	}

	/**
	 * View cap required before other actions in a module (first action is always view).
	 *
	 * @param string $cap Capability slug.
	 * @return string|null
	 */
	public static function get_view_cap_for( $cap ) {
		foreach ( self::get_modules() as $module ) {
			$actions = array_keys( $module['actions'] );
			if ( count( $actions ) < 2 ) {
				continue;
			}
			$view_cap = $actions[0];
			if ( in_array( $cap, $actions, true ) && $cap !== $view_cap ) {
				return $view_cap;
			}
		}

		return null;
	}

	/**
	 * All registered capability slugs (actions + legacy-only caps).
	 *
	 * @return array<int, string>
	 */
	public static function get_all_slugs() {
		$slugs = array();

		foreach ( self::get_modules() as $module ) {
			foreach ( array_keys( $module['actions'] ) as $cap ) {
				$slugs[] = $cap;
			}
			if ( ! empty( $module['legacy_cap'] ) ) {
				$slugs[] = $module['legacy_cap'];
			}
		}

		$slugs[] = 'crm_receive_stock';
		$slugs[] = 'crm_view_stock';

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Permission UI groups (module label => actions).
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_permission_groups() {
		$groups = array();

		foreach ( self::get_modules() as $module ) {
			$groups[ $module['label'] ] = $module['actions'];
		}

		return $groups;
	}

	/**
	 * Capability slugs shown/toggled in the Team permissions UI (excludes legacy bundles).
	 *
	 * @return array<int, string>
	 */
	public static function get_toggleable_slugs() {
		$slugs = array();

		foreach ( self::get_modules() as $module ) {
			foreach ( array_keys( $module['actions'] ) as $cap ) {
				$slugs[] = $cap;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Menu access cap for a CRM module slug.
	 *
	 * @param string $module_slug Module slug from app shell.
	 * @return string|null
	 */
	public static function get_menu_cap_for_module( $module_slug ) {
		$map = array(
			'dashboard'          => 'crm_view_dashboard',
			'orders'             => 'crm_orders_view',
			'warehouse'          => 'crm_stock_view',
			'shipments'          => 'crm_shipments_view',
			'delivery'           => 'crm_delivery_view',
			'payments'           => 'crm_payments_view',
			'clients'            => 'crm_clients_view',
			'companies'          => 'crm_companies_view',
			'products'           => 'crm_products_view',
			'product-categories' => 'crm_products_view',
			'reports'            => 'crm_view_reports',
			'order-statuses'     => 'crm_manage_settings',
			'team'               => 'crm_manage_settings',
			'activity'           => 'crm_manage_settings',
		);

		if ( ! isset( $map[ $module_slug ] ) ) {
			return null;
		}

		return $map[ $module_slug ];
	}

	/**
	 * Expand legacy bundle caps into granular action caps.
	 *
	 * @param array<string, bool> $caps Cap map.
	 * @return array<string, bool>
	 */
	public static function expand_legacy_bundles( array $caps ) {
		foreach ( self::get_legacy_bundles() as $legacy => $children ) {
			if ( empty( $caps[ $legacy ] ) ) {
				continue;
			}
			foreach ( $children as $child ) {
				$caps[ $child ] = true;
			}
		}

		return $caps;
	}

	/**
	 * Set legacy bundle caps when all child actions are allowed.
	 *
	 * @param array<string, bool> $caps Cap map.
	 * @return array<string, bool>
	 */
	public static function apply_computed_legacy_caps( array $caps ) {
		foreach ( self::get_legacy_bundles() as $legacy => $children ) {
			$all = true;
			foreach ( $children as $child ) {
				if ( empty( $caps[ $child ] ) ) {
					$all = false;
					break;
				}
			}
			$caps[ $legacy ] = $all;
		}

		return $caps;
	}

	/**
	 * Normalize saved permissions (view required when other actions enabled).
	 *
	 * @param array<string, bool> $desired Desired caps.
	 * @return array<string, bool>
	 */
	public static function normalize_permissions( array $desired ) {
		foreach ( self::get_modules() as $module ) {
			$actions = array_keys( $module['actions'] );
			if ( count( $actions ) < 2 ) {
				continue;
			}

			$view_cap = $actions[0];
			$any_other = false;

			foreach ( array_slice( $actions, 1 ) as $cap ) {
				if ( ! empty( $desired[ $cap ] ) ) {
					$any_other = true;
					break;
				}
			}

			if ( $any_other ) {
				$desired[ $view_cap ] = true;
			}
		}

		return $desired;
	}

	/**
	 * Whether user can perform a simple orders action (no ownership).
	 *
	 * @param string $action Action key.
	 * @return bool
	 */
	public static function user_can_orders( $action ) {
		$map = array(
			'view'   => 'crm_orders_view',
			'create' => 'crm_orders_create',
			'edit'   => 'crm_orders_edit',
			'status' => 'crm_orders_status',
			'cancel' => 'crm_orders_cancel',
		);

		$cap = isset( $map[ $action ] ) ? $map[ $action ] : '';

		return $cap && current_user_can( $cap );
	}

	/**
	 * Whether the current user has any of the given capabilities.
	 *
	 * @param array<int, string> $caps Capability slugs.
	 * @return bool
	 */
	public static function user_can_any( array $caps ) {
		foreach ( $caps as $cap ) {
			if ( is_string( $cap ) && '' !== $cap && current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current user may perform a module action (granular or legacy bundle).
	 *
	 * @param string $action_cap Action capability.
	 * @param string $legacy_cap Optional legacy bundle capability.
	 * @return bool
	 */
	public static function user_can_module_action( $action_cap, $legacy_cap = '' ) {
		$caps = array( $action_cap );
		if ( $legacy_cap ) {
			$caps[] = $legacy_cap;
		}

		return self::user_can_any( $caps );
	}

	/**
	 * Whether the current user may record supplier bills / payments (billing module).
	 *
	 * @return bool
	 */
	public static function user_can_manage_billing() {
		return self::user_can_module_action( 'crm_billing_edit', 'crm_manage_billing' );
	}

	/**
	 * Whether staff may view supplier (company) payments inside Payments.
	 *
	 * @return bool
	 */
	public static function user_can_view_supplier_payments() {
		if ( CRM_Client_Portal::is_client_user() ) {
			return false;
		}

		return current_user_can( 'crm_payments_view' ) || current_user_can( 'crm_manage_payments' );
	}

	/**
	 * Whether the current user may record client payments.
	 *
	 * @return bool
	 */
	public static function user_can_record_payments() {
		return self::user_can_module_action( 'crm_payments_create', 'crm_manage_payments' );
	}

	/**
	 * Whether user may edit an order (any, or own when they can create).
	 *
	 * @param array<string, mixed>|object $order Order row with created_by.
	 * @return bool
	 */
	public static function user_can_edit_order( $order ) {
		$created_by = is_array( $order ) ? ( $order['created_by'] ?? 0 ) : ( $order->created_by ?? 0 );
		$status     = is_array( $order ) ? (string) ( $order['status'] ?? '' ) : (string) ( $order->status ?? '' );

		if ( current_user_can( 'crm_orders_edit' ) ) {
			return true;
		}

		if ( ! current_user_can( 'crm_orders_create' ) || ! CRM_Ownership::is_own_record( $created_by ) ) {
			return false;
		}

		return CRM_Order_Status::own_creator_can_edit( $status );
	}

	/**
	 * User-facing reason when edit is denied (for API / forms).
	 *
	 * @param array<string, mixed>|object $order Order row.
	 * @return string
	 */
	public static function order_edit_denied_message( $order ) {
		$created_by = is_array( $order ) ? ( $order['created_by'] ?? 0 ) : ( $order->created_by ?? 0 );
		$status     = is_array( $order ) ? (string) ( $order['status'] ?? '' ) : (string) ( $order->status ?? '' );

		if ( CRM_Ownership::is_own_record( $created_by ) && ! CRM_Order_Status::own_creator_can_edit( $status ) ) {
			return __( 'This order has been accepted and can no longer be edited. Contact an administrator if you need changes.', 'ds-prod-import-crm' );
		}

		return __( 'You can only edit orders you created while they are awaiting acceptance.', 'ds-prod-import-crm' );
	}

	/**
	 * Whether user may cancel an order (any, or own when they can create).
	 *
	 * @param array<string, mixed>|object $order Order row with created_by.
	 * @return bool
	 */
	public static function user_can_cancel_order( $order ) {
		if ( current_user_can( 'crm_orders_cancel' ) ) {
			return true;
		}

		$created_by = is_array( $order ) ? ( $order['created_by'] ?? 0 ) : ( $order->created_by ?? 0 );
		$status     = is_array( $order ) ? (string) ( $order['status'] ?? '' ) : (string) ( $order->status ?? '' );

		if ( ! current_user_can( 'crm_orders_create' ) || ! CRM_Ownership::is_own_record( $created_by ) ) {
			return false;
		}

		return CRM_Order_Status::own_creator_can_edit( $status );
	}

	/**
	 * Whether user may change status on an order.
	 *
	 * Status changes are staff-only (crm_orders_status). Creating an order does not
	 * grant clients permission to change workflow status after submission.
	 *
	 * @param array<string, mixed>|object $order Order row with created_by.
	 * @return bool
	 */
	public static function user_can_change_order_status( $order ) {
		return current_user_can( 'crm_orders_status' );
	}

	/**
	 * Whether user may accept an order awaiting admin approval.
	 *
	 * @return bool
	 */
	public static function user_can_accept_orders() {
		return current_user_can( 'crm_orders_accept' );
	}
}
