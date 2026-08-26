<?php
/**
 * Role and capability management.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers custom CRM roles and caps.
 */
class CRM_Roles {
	/**
	 * All CRM capability slugs.
	 *
	 * @return array<int, string>
	 */
	public static function get_capability_slugs() {
		return CRM_Capability_Registry::get_all_slugs();
	}

	/**
	 * Default CRM role definitions (slug => caps, excluding `read`).
	 *
	 * @return array<string, array{label: string, caps: array<int, string>}>
	 */
	public static function get_role_definitions() {
		$all_caps = self::get_capability_slugs();

		$manager_caps = array_values(
			array_filter(
				$all_caps,
				static function ( $cap ) {
					return ! in_array( $cap, array( 'crm_manage_users', 'crm_manage_settings' ), true );
				}
			)
		);

		return array(
			'crm_admin'        => array(
				'label' => __( 'CRM Admin', 'ds-prod-import-crm' ),
				'caps'  => $all_caps,
			),
			'crm_manager'      => array(
				'label' => __( 'CRM Manager', 'ds-prod-import-crm' ),
				'caps'  => $manager_caps,
			),
			'crm_warehouse'    => array(
				'label' => __( 'CRM Warehouse', 'ds-prod-import-crm' ),
				'caps'  => array(
					'crm_view_dashboard',
					'crm_stock_view',
					'crm_stock_receive',
					'crm_receive_stock',
					'crm_view_stock',
				),
			),
			'crm_accountant'   => array(
				'label' => __( 'CRM Accountant', 'ds-prod-import-crm' ),
				'caps'  => array(
					'crm_view_dashboard',
					'crm_payments_view',
					'crm_payments_create',
					'crm_payments_edit',
					'crm_payments_delete',
					'crm_manage_payments',
					'crm_billing_view',
					'crm_billing_edit',
					'crm_manage_billing',
					'crm_view_reports',
				),
			),
			'crm_viewer'       => array(
				'label' => __( 'CRM Viewer', 'ds-prod-import-crm' ),
				'caps'  => array(
					'crm_view_dashboard',
					'crm_view_reports',
				),
			),
			'crm_client'       => array(
				'label' => __( 'CRM Client', 'ds-prod-import-crm' ),
				'caps'  => array(
					'crm_orders_view',
					'crm_orders_create',
					'crm_delivery_view',
					'crm_payments_view',
					'crm_view_reports',
				),
			),
			'crm_china_office' => array(
				'label' => __( 'CRM China Office', 'ds-prod-import-crm' ),
				'caps'  => array(
					'crm_orders_view',
					'crm_orders_edit',
					'crm_orders_accept',
					'crm_shipments_view',
					'crm_shipments_create',
					'crm_shipments_amend',
					'crm_companies_view',
				),
			),
			'crm_china_supervisor' => array(
				'label' => __( 'CRM China Office Supervisor', 'ds-prod-import-crm' ),
				'caps'  => array(
					'crm_orders_view',
					'crm_shipments_view',
					'crm_shipments_review',
					'crm_companies_view',
				),
			),
		);
	}

	/**
	 * Register roles and keep their default capabilities in sync.
	 *
	 * WordPress `add_role()` is a no-op when the role already exists, so we
	 * explicitly `add_cap()` missing defaults on every boot.
	 *
	 * @return void
	 */
	public static function maybe_register_roles() {
		foreach ( self::get_role_definitions() as $slug => $definition ) {
			self::sync_role( $slug, $definition['label'], $definition['caps'] );
		}

		// Keep default WordPress admins able to access CRM without changing their role.
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::get_capability_slugs() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
	}

	/**
	 * Create a role if missing and ensure all expected caps are present.
	 *
	 * @param string             $slug  Role slug.
	 * @param string             $label Display name.
	 * @param array<int, string> $caps  Capability slugs.
	 * @return void
	 */
	private static function sync_role( $slug, $label, array $caps ) {
		$caps     = array_values( array_unique( array_filter( array_map( 'strval', $caps ) ) ) );
		$cap_map  = array_fill_keys( $caps, true );
		$cap_map['read'] = true;

		$role = get_role( $slug );
		if ( ! $role ) {
			add_role( $slug, $label, $cap_map );
			$role = get_role( $slug );
		}

		if ( ! $role ) {
			return;
		}

		foreach ( array_keys( $cap_map ) as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * Display label for a CRM role slug.
	 *
	 * @param string $role_slug Role slug.
	 * @return string
	 */
	public static function get_role_label( $role_slug ) {
		$definitions = self::get_role_definitions();
		if ( isset( $definitions[ $role_slug ]['label'] ) ) {
			return $definitions[ $role_slug ]['label'];
		}

		$wp_roles = wp_roles();
		if ( isset( $wp_roles->role_names[ $role_slug ] ) ) {
			return translate_user_role( $wp_roles->role_names[ $role_slug ] );
		}

		return $role_slug;
	}

	/**
	 * Cleanup roles on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::get_capability_slugs() as $capability ) {
				$administrator->remove_cap( $capability );
			}
		}

		foreach ( array_keys( self::get_role_definitions() ) as $slug ) {
			remove_role( $slug );
		}
	}
}
