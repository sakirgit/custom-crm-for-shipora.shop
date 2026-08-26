<?php
/**
 * Development / staging helper — wipe operational CRM data, keep settings.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Clears transactional CRM tables while preserving configuration.
 */
class CRM_Data_Reset {
	/**
	 * Tables cleared on reset, in safe dependency order (children first).
	 *
	 * @return array<string, string> slug => label
	 */
	public static function clearable_tables() {
		return array(
			'export_shipment_items' => __( 'Export shipment lines', 'ds-prod-import-crm' ),
			'export_shipments'      => __( 'China export shipments', 'ds-prod-import-crm' ),
			'delivery_items'     => __( 'Delivery line items', 'ds-prod-import-crm' ),
			'deliveries'         => __( 'Deliveries', 'ds-prod-import-crm' ),
			'receive_items'      => __( 'Warehouse receive lines', 'ds-prod-import-crm' ),
			'warehouse_receives' => __( 'Warehouse receives', 'ds-prod-import-crm' ),
			'order_items'        => __( 'Order line items', 'ds-prod-import-crm' ),
			'payments'           => __( 'Client payments', 'ds-prod-import-crm' ),
			'client_bills'       => __( 'Client bills', 'ds-prod-import-crm' ),
			'company_payments'   => __( 'Supplier payments', 'ds-prod-import-crm' ),
			'company_bills'      => __( 'Supplier bills', 'ds-prod-import-crm' ),
			'orders'             => __( 'Orders', 'ds-prod-import-crm' ),
			'stock'              => __( 'Stock levels', 'ds-prod-import-crm' ),
			'products'           => __( 'Products', 'ds-prod-import-crm' ),
			'product_categories' => __( 'Product categories', 'ds-prod-import-crm' ),
			'clients'            => __( 'Clients', 'ds-prod-import-crm' ),
			'companies'          => __( 'Suppliers / companies', 'ds-prod-import-crm' ),
			'activity_log'       => __( 'Activity log', 'ds-prod-import-crm' ),
		);
	}

	/**
	 * Configuration that is never removed by this tool.
	 *
	 * @return array<int, string>
	 */
	public static function preserved_labels() {
		return array(
			__( 'CRM settings (branding, colors, frontend page, thresholds)', 'ds-prod-import-crm' ),
			__( 'Order statuses', 'ds-prod-import-crm' ),
			__( 'WordPress users, roles, and CRM permissions', 'ds-prod-import-crm' ),
			__( 'Plugin database version', 'ds-prod-import-crm' ),
		);
	}

	/**
	 * Row counts for the reset panel preview.
	 *
	 * @return array{tables:array<int,array{slug:string,label:string,count:int}>,total_rows:int}
	 */
	public static function get_counts() {
		global $wpdb;

		$tables     = self::clearable_tables();
		$rows       = array();
		$total_rows = 0;

		foreach ( $tables as $slug => $label ) {
			$table = crm_table( $slug );
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total_rows += $count;
			$rows[] = array(
				'slug'  => $slug,
				'label' => $label,
				'count' => $count,
			);
		}

		return array(
			'tables'     => $rows,
			'total_rows' => $total_rows,
		);
	}

	/**
	 * Delete all operational CRM data.
	 *
	 * @return array{cleared_tables:int,total_rows_before:int}|WP_Error
	 */
	public static function reset_operational_data() {
		global $wpdb;

		$before = self::get_counts();
		$tables = self::clearable_tables();

		foreach ( array_keys( $tables ) as $slug ) {
			$table = crm_table( $slug );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( "TRUNCATE TABLE {$table}" );

			if ( false === $result ) {
				return new \WP_Error(
					'crm_reset_failed',
					sprintf(
						/* translators: %s: database table slug */
						__( 'Could not clear table: %s', 'ds-prod-import-crm' ),
						$slug
					)
				);
			}
		}

		return array(
			'cleared_tables'    => count( $tables ),
			'total_rows_before' => (int) $before['total_rows'],
		);
	}
}
