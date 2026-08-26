<?php
/**
 * Plugin activation routines.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Handles db installation and role bootstrap.
 */
class CRM_Activator {
	/**
	 * Database schema version.
	 */
	const DB_VERSION = '0.11.0';

	/**
	 * Create/upgrade plugin tables.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'crm_';

		$queries = array(
			"CREATE TABLE {$prefix}companies (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				company_type ENUM('cargo','local_supplier') DEFAULT 'cargo',
				contact_person VARCHAR(255),
				phone VARCHAR(50),
				address TEXT,
				notes TEXT,
				status ENUM('active','inactive') DEFAULT 'active',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_company_type (company_type)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}clients (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				phone VARCHAR(50),
				email VARCHAR(255),
				address TEXT,
				notes TEXT,
				status ENUM('active','inactive') DEFAULT 'active',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};",
			"CREATE TABLE {$prefix}product_categories (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				description TEXT,
				status ENUM('active','inactive') DEFAULT 'active',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};",
			"CREATE TABLE {$prefix}products (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				category_id INT,
				description TEXT,
				unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
				image_url VARCHAR(500),
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_category_id (category_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}orders (
				id INT AUTO_INCREMENT PRIMARY KEY,
				order_number VARCHAR(50) NOT NULL UNIQUE,
				client_id INT NOT NULL,
				order_date DATE NOT NULL,
				notes TEXT,
				status VARCHAR(50) NOT NULL DEFAULT 'pending',
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_client_id (client_id),
				INDEX idx_status (status)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}order_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				order_id INT NOT NULL,
				product_id INT,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				quantity INT NOT NULL DEFAULT 0,
				accepted_quantity INT DEFAULT NULL,
				weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				unit_price DECIMAL(12,2) DEFAULT 0,
				delivery_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
				notes TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_order_id (order_id),
				INDEX idx_delivery_priority (delivery_priority)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}warehouse_receives (
				id INT AUTO_INCREMENT PRIMARY KEY,
				receive_number VARCHAR(50) NOT NULL UNIQUE,
				company_id INT NOT NULL,
				shipment_id INT NULL,
				order_id INT NULL,
				client_id INT NULL,
				receive_date DATE NOT NULL,
				total_kg DECIMAL(10,3) DEFAULT 0,
				shipping_bill DECIMAL(12,2) DEFAULT 0,
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id),
				INDEX idx_shipment_id (shipment_id),
				INDEX idx_order_id (order_id),
				INDEX idx_client_id (client_id),
				INDEX idx_receive_date (receive_date)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}receive_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				receive_id INT NOT NULL,
				export_shipment_item_id INT NULL,
				product_id INT,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				quantity INT NOT NULL DEFAULT 0,
				missing_quantity INT NOT NULL DEFAULT 0,
				weight_kg DECIMAL(10,3) DEFAULT 0,
				rate DECIMAL(12,2) DEFAULT 0,
				bill_amount DECIMAL(12,2) DEFAULT 0,
				image_url VARCHAR(500),
				notes TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_receive_id (receive_id),
				INDEX idx_export_shipment_item_id (export_shipment_item_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}stock (
				id INT AUTO_INCREMENT PRIMARY KEY,
				product_id INT,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100) DEFAULT '',
				size VARCHAR(100) DEFAULT '',
				quantity INT NOT NULL DEFAULT 0,
				last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				UNIQUE KEY stock_key (product_name(100), color(50), size(50))
			) {$charset_collate};",
			"CREATE TABLE {$prefix}deliveries (
				id INT AUTO_INCREMENT PRIMARY KEY,
				delivery_number VARCHAR(50) NOT NULL UNIQUE,
				order_id INT NOT NULL,
				client_id INT NOT NULL,
				delivery_date DATE NOT NULL,
				delivered_by INT,
				receiver_name VARCHAR(255),
				receiver_phone VARCHAR(50),
				receiver_address TEXT,
				total_kg DECIMAL(10,3) DEFAULT 0,
				shipping_bill DECIMAL(12,2) DEFAULT 0,
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_order_id (order_id),
				INDEX idx_client_id (client_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}delivery_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				delivery_id INT NOT NULL,
				order_item_id INT NOT NULL,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				quantity INT NOT NULL DEFAULT 0,
				notes TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_delivery_id (delivery_id),
				INDEX idx_order_item_id (order_item_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}export_shipments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				shipment_number VARCHAR(50) NOT NULL UNIQUE,
				company_id INT NOT NULL,
				order_id INT NOT NULL,
				ship_date DATE NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'in_transit',
				total_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id),
				INDEX idx_order_id (order_id),
				INDEX idx_ship_date (ship_date),
				INDEX idx_status (status)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}export_shipment_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				shipment_id INT NOT NULL,
				order_item_id INT NOT NULL,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				quantity INT NOT NULL DEFAULT 0,
				weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				notes TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_shipment_id (shipment_id),
				INDEX idx_order_item_id (order_item_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}export_shipment_amendments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				shipment_id INT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				reason TEXT,
				review_notes TEXT,
				requested_by INT,
				requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				reviewed_by INT,
				reviewed_at DATETIME DEFAULT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_shipment_id (shipment_id),
				INDEX idx_status (status)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}export_shipment_amendment_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				amendment_id INT NOT NULL,
				shipment_item_id INT NOT NULL,
				order_item_id INT NOT NULL,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				old_quantity INT NOT NULL DEFAULT 0,
				new_quantity INT NOT NULL DEFAULT 0,
				old_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				new_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_amendment_id (amendment_id),
				INDEX idx_shipment_item_id (shipment_item_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}payments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				payment_number VARCHAR(50) NOT NULL UNIQUE,
				client_id INT NOT NULL,
				order_id INT,
				payment_purpose VARCHAR(20) NOT NULL DEFAULT 'auto',
				payment_date DATE NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				payment_method VARCHAR(100),
				reference VARCHAR(255),
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_client_id (client_id),
				INDEX idx_order_id (order_id),
				INDEX idx_payment_purpose (payment_purpose)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}client_bills (
				id INT AUTO_INCREMENT PRIMARY KEY,
				client_id INT NOT NULL,
				order_id INT,
				bill_date DATE NOT NULL,
				bill_type ENUM('order_bill','shipping_bill') NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_client_id (client_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}activity_log (
				id INT AUTO_INCREMENT PRIMARY KEY,
				user_id INT NOT NULL,
				action VARCHAR(100) NOT NULL,
				module VARCHAR(100),
				record_id INT,
				description TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_user_id (user_id),
				INDEX idx_module (module)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}order_statuses (
				id INT AUTO_INCREMENT PRIMARY KEY,
				slug VARCHAR(50) NOT NULL UNIQUE,
				label VARCHAR(100) NOT NULL,
				color VARCHAR(20) DEFAULT '#6b7280',
				is_system TINYINT(1) DEFAULT 0,
				is_closed TINYINT(1) DEFAULT 0,
				auto_on_paid TINYINT(1) DEFAULT 0,
				sort_order INT DEFAULT 0,
				status ENUM('active','inactive') DEFAULT 'active',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};",
			"CREATE TABLE {$prefix}company_bills (
				id INT AUTO_INCREMENT PRIMARY KEY,
				company_id INT NOT NULL,
				bill_date DATE NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				reference VARCHAR(255),
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}company_payments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				payment_number VARCHAR(50) NOT NULL UNIQUE,
				company_id INT NOT NULL,
				receive_id INT,
				payment_date DATE NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				payment_method VARCHAR(100),
				reference VARCHAR(255),
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id)
			) {$charset_collate};",
			"CREATE TABLE {$prefix}settings (
				option_key VARCHAR(100) NOT NULL PRIMARY KEY,
				option_value LONGTEXT,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};",
		);

		foreach ( $queries as $query ) {
			dbDelta( $query );
		}

		CRM_Roles::maybe_register_roles();
		self::maybe_upgrade();
		CRM_Order_Status::seed_defaults();
	}

	/**
	 * Run incremental schema upgrades on existing installs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'ds_crm_db_version', '0' );

		if ( version_compare( $installed, '0.2.0', '<' ) ) {
			self::upgrade_020();
		}

		if ( version_compare( $installed, '0.3.0', '<' ) ) {
			self::upgrade_030();
		}

		if ( version_compare( $installed, '0.5.0', '<' ) ) {
			self::upgrade_050();
		}

		if ( version_compare( $installed, '0.6.0', '<' ) ) {
			self::upgrade_060();
		}

		if ( version_compare( $installed, '0.7.0', '<' ) ) {
			self::upgrade_070();
		}

		if ( version_compare( $installed, '0.7.1', '<' ) ) {
			self::upgrade_071();
		}

		if ( version_compare( $installed, '0.8.0', '<' ) ) {
			self::upgrade_080();
		}

		if ( version_compare( $installed, '0.8.1', '<' ) ) {
			self::upgrade_081();
		}

		if ( version_compare( $installed, '0.9.0', '<' ) ) {
			self::upgrade_090();
		}

		if ( version_compare( $installed, '0.9.1', '<' ) ) {
			self::upgrade_091();
		}

		if ( version_compare( $installed, '0.9.2', '<' ) ) {
			self::upgrade_092();
		}

		if ( version_compare( $installed, '0.9.3', '<' ) ) {
			self::upgrade_093();
		}

		if ( version_compare( $installed, '0.9.4', '<' ) ) {
			self::upgrade_094();
		}

		if ( version_compare( $installed, '0.9.5', '<' ) ) {
			self::upgrade_095();
		}

		if ( version_compare( $installed, '0.9.6', '<' ) ) {
			self::upgrade_096();
		}

		if ( version_compare( $installed, '0.9.7', '<' ) ) {
			self::upgrade_097();
		}

		if ( version_compare( $installed, '0.9.8', '<' ) ) {
			self::upgrade_098();
		}

		if ( version_compare( $installed, '0.9.9', '<' ) ) {
			self::upgrade_099();
		}

		if ( version_compare( $installed, '0.10.0', '<' ) ) {
			self::upgrade_0100();
		}

		if ( version_compare( $installed, '0.11.0', '<' ) ) {
			self::upgrade_0110();
		}

		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			update_option( 'ds_crm_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Add product unit price column.
	 *
	 * @return void
	 */
	private static function upgrade_020() {
		global $wpdb;

		$products_table = $wpdb->prefix . 'crm_products';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_unit_price = $wpdb->get_results( "SHOW COLUMNS FROM `{$products_table}` LIKE 'unit_price'" );

		if ( empty( $has_unit_price ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN unit_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER description" );
		}
	}

	/**
	 * Add product categories table and link products by category_id.
	 *
	 * @return void
	 */
	private static function upgrade_030() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'crm_';

		dbDelta(
			"CREATE TABLE {$prefix}product_categories (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL,
				description TEXT,
				status ENUM('active','inactive') DEFAULT 'active',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};"
		);

		$products_table = $prefix . 'products';
		$categories_table = $prefix . 'product_categories';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_category_id = $wpdb->get_results( "SHOW COLUMNS FROM `{$products_table}` LIKE 'category_id'" );

		if ( empty( $has_category_id ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$products_table} ADD COLUMN category_id INT NULL AFTER name" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$products_table} ADD INDEX idx_category_id (category_id)" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_legacy_category = $wpdb->get_results( "SHOW COLUMNS FROM `{$products_table}` LIKE 'category'" );

		if ( ! empty( $has_legacy_category ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$legacy_rows = $wpdb->get_results( "SELECT id, category FROM {$products_table} WHERE category IS NOT NULL AND category != ''" );

			$name_to_id = array();

			foreach ( $legacy_rows as $row ) {
				$category_name = trim( $row->category );
				if ( '' === $category_name ) {
					continue;
				}

				if ( ! isset( $name_to_id[ $category_name ] ) ) {
					$wpdb->insert(
						$categories_table,
						array(
							'name'       => $category_name,
							'status'     => 'active',
							'created_at' => current_time( 'mysql' ),
							'updated_at' => current_time( 'mysql' ),
						),
						array( '%s', '%s', '%s', '%s' )
					);
					$name_to_id[ $category_name ] = (int) $wpdb->insert_id;
				}

				$wpdb->update(
					$products_table,
					array( 'category_id' => $name_to_id[ $category_name ] ),
					array( 'id' => (int) $row->id ),
					array( '%d' ),
					array( '%d' )
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$products_table} DROP COLUMN category" );
		}
	}

	/**
	 * Link stock rows to catalog products by name.
	 *
	 * @return void
	 */
	private static function upgrade_050() {
		global $wpdb;

		$stock_table    = $wpdb->prefix . 'crm_stock';
		$products_table = $wpdb->prefix . 'crm_products';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$stock_table} s
			INNER JOIN {$products_table} p ON p.name = s.product_name
			SET s.product_id = p.id
			WHERE s.product_id = 0 OR s.product_id IS NULL"
		);
	}

	/**
	 * Financial ledger tables, order statuses, weights, company types.
	 *
	 * @return void
	 */
	private static function upgrade_060() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'crm_';

		dbDelta(
			"CREATE TABLE {$prefix}order_statuses (
				id INT AUTO_INCREMENT PRIMARY KEY,
				slug VARCHAR(50) NOT NULL UNIQUE,
				label VARCHAR(100) NOT NULL,
				color VARCHAR(20) DEFAULT '#6b7280',
				is_system TINYINT(1) DEFAULT 0,
				is_closed TINYINT(1) DEFAULT 0,
				auto_on_paid TINYINT(1) DEFAULT 0,
				sort_order INT DEFAULT 0,
				status ENUM('active','inactive') DEFAULT 'active',
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}company_bills (
				id INT AUTO_INCREMENT PRIMARY KEY,
				company_id INT NOT NULL,
				bill_date DATE NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				reference VARCHAR(255),
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}company_payments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				payment_number VARCHAR(50) NOT NULL UNIQUE,
				company_id INT NOT NULL,
				receive_id INT,
				payment_date DATE NOT NULL,
				amount DECIMAL(12,2) NOT NULL,
				payment_method VARCHAR(100),
				reference VARCHAR(255),
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}settings (
				option_key VARCHAR(100) NOT NULL PRIMARY KEY,
				option_value LONGTEXT,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) {$charset_collate};"
		);

		$companies_table = $prefix . 'companies';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_company_type = $wpdb->get_results( "SHOW COLUMNS FROM `{$companies_table}` LIKE 'company_type'" );
		if ( empty( $has_company_type ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$companies_table} ADD COLUMN company_type ENUM('cargo','local_supplier') DEFAULT 'cargo' AFTER name" );
		}

		$order_items_table = $prefix . 'order_items';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_weight = $wpdb->get_results( "SHOW COLUMNS FROM `{$order_items_table}` LIKE 'weight_kg'" );
		if ( empty( $has_weight ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$order_items_table} ADD COLUMN weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER quantity" );
		}

		$orders_table = $prefix . 'orders';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_col = $wpdb->get_row( "SHOW COLUMNS FROM `{$orders_table}` LIKE 'status'", ARRAY_A );
		if ( $status_col && false !== strpos( $status_col['Type'], 'enum' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$orders_table} MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'" );
		}

		CRM_Order_Status::seed_defaults();

		update_option(
			'ds_crm_settings',
			array(
				'frontend_page_id'    => 0,
				'low_stock_threshold' => 5,
				'currency_symbol'     => '৳',
				'company_name'        => '',
				'company_tagline'     => '',
				'company_logo_url'    => '',
				'favicon_url'         => '',
			)
		);
	}

	/**
	 * Audit columns: created_by / updated_by on core tables, meta on activity_log.
	 *
	 * @return void
	 */
	private static function upgrade_070() {
		global $wpdb;

		$prefix = $wpdb->prefix . 'crm_';

		$tables = array(
			'clients',
			'companies',
			'products',
			'product_categories',
			'orders',
			'payments',
			'warehouse_receives',
			'deliveries',
		);

		foreach ( $tables as $table_slug ) {
			$table = $prefix . $table_slug;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_created = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'created_by'" );
			if ( empty( $has_created ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN created_by INT NULL" );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_updated = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'updated_by'" );
			if ( empty( $has_updated ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN updated_by INT NULL" );
			}
		}

		$activity = $prefix . 'activity_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_meta = $wpdb->get_results( "SHOW COLUMNS FROM `{$activity}` LIKE 'meta'" );
		if ( empty( $has_meta ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$activity} ADD COLUMN meta LONGTEXT NULL AFTER description" );
		}
	}

	/**
	 * Composite indexes for fast activity log pagination at scale.
	 *
	 * @return void
	 */
	private static function upgrade_071() {
		global $wpdb;

		$table = $wpdb->prefix . 'crm_activity_log';
		$indexes = array(
			'idx_activity_created'       => '(created_at)',
			'idx_activity_module_created'  => '(module, created_at)',
			'idx_activity_user_created'  => '(user_id, created_at)',
			'idx_activity_module_record'   => '(module, record_id)',
			'idx_activity_action'          => '(action)',
		);

		foreach ( $indexes as $name => $columns ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$name}'" );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD INDEX {$name} {$columns}" );
			}
		}
	}

	/**
	 * SKU, purchase rate, thumbnails, weight-based receive shipping fields.
	 *
	 * @return void
	 */
	private static function upgrade_080() {
		global $wpdb;

		$products_table  = $wpdb->prefix . 'crm_products';
		$receives_table  = $wpdb->prefix . 'crm_warehouse_receives';
		$items_table     = $wpdb->prefix . 'crm_receive_items';
		$categories_table = $wpdb->prefix . 'crm_product_categories';

		$product_cols = array(
			'sku'            => "ADD COLUMN sku VARCHAR(100) NULL AFTER name",
			'purchase_rate'  => "ADD COLUMN purchase_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_price",
			'thumbnail_url'  => "ADD COLUMN thumbnail_url VARCHAR(500) NULL AFTER image_url",
		);

		foreach ( $product_cols as $col => $sql_part ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$products_table}` LIKE '{$col}'" );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$products_table} {$sql_part}" );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_sku_index = $wpdb->get_results( "SHOW INDEX FROM `{$products_table}` WHERE Key_name = 'idx_sku'" );
		if ( empty( $has_sku_index ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$products_table} ADD UNIQUE INDEX idx_sku (sku)" );
		}

		$receive_cols = array(
			'shipping_rate_per_kg' => "ADD COLUMN shipping_rate_per_kg DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER total_kg",
			'product_bill_total'   => "ADD COLUMN product_bill_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER shipping_bill",
		);

		foreach ( $receive_cols as $col => $sql_part ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$receives_table}` LIKE '{$col}'" );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$receives_table} {$sql_part}" );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_shipping_share = $wpdb->get_results( "SHOW COLUMNS FROM `{$items_table}` LIKE 'shipping_share'" );
		if ( empty( $has_shipping_share ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN shipping_share DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER bill_amount" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_is_system = $wpdb->get_results( "SHOW COLUMNS FROM `{$categories_table}` LIKE 'is_system'" );
		if ( empty( $has_is_system ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$categories_table} ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER status" );
		}

		crm_ensure_uncategorized_category();
	}

	/**
	 * Default color and size on catalog products.
	 *
	 * @return void
	 */
	private static function upgrade_081() {
		global $wpdb;

		$products_table = $wpdb->prefix . 'crm_products';
		$cols           = array(
			'color' => "ADD COLUMN color VARCHAR(100) NULL AFTER purchase_rate",
			'size'  => "ADD COLUMN size VARCHAR(100) NULL AFTER color",
		);

		foreach ( $cols as $col => $sql_part ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$products_table}` LIKE '{$col}'" );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$products_table} {$sql_part}" );
			}
		}
	}

	/**
	 * Client portal: link CRM clients to WordPress users.
	 *
	 * @return void
	 */
	private static function upgrade_090() {
		global $wpdb;

		$table = $wpdb->prefix . 'crm_clients';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'wp_user_id'" );
		if ( empty( $exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN wp_user_id BIGINT UNSIGNED NULL AFTER status" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_wp_user_id (wp_user_id)" );
		}
	}

	/**
	 * Per-line shipping rate on warehouse receive items.
	 *
	 * @return void
	 */
	private static function upgrade_091() {
		global $wpdb;

		$items_table = $wpdb->prefix . 'crm_receive_items';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$items_table}` LIKE 'shipping_rate_per_kg'" );
		if ( empty( $exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN shipping_rate_per_kg DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER weight_kg" );
		}
	}

	/**
	 * Backfill client delivery bills from existing deliveries.
	 *
	 * @return void
	 */
	private static function upgrade_092() {
		crm_backfill_client_delivery_bills();
	}

	/**
	 * Ensure CRM Client WordPress users have client records.
	 *
	 * @return void
	 */
	private static function upgrade_093() {
		CRM_Client_Portal::sync_all_portal_users();
	}

	/**
	 * Per-line weight and shipping on delivery items.
	 *
	 * @return void
	 */
	private static function upgrade_094() {
		global $wpdb;

		$table = $wpdb->prefix . 'crm_delivery_items';
		$cols  = array(
			'weight_kg'            => 'ADD COLUMN weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0 AFTER quantity',
			'shipping_rate_per_kg' => 'ADD COLUMN shipping_rate_per_kg DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER weight_kg',
			'shipping_share'       => 'ADD COLUMN shipping_share DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER shipping_rate_per_kg',
		);

		foreach ( $cols as $col => $sql_part ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'" );
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} {$sql_part}" );
			}
		}
	}

	/**
	 * Order approval gate: statuses that block delivery and billing until accepted.
	 *
	 * @return void
	 */
	private static function upgrade_095() {
		global $wpdb;

		$table = $wpdb->prefix . 'crm_order_statuses';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'blocks_workflow'" );
		if ( empty( $exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN blocks_workflow TINYINT(1) NOT NULL DEFAULT 0 AFTER is_closed" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_awaiting = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE slug = 'awaiting_acceptance'" );
		if ( ! $has_awaiting ) {
			$wpdb->insert(
				$table,
				array(
					'slug'             => 'awaiting_acceptance',
					'label'            => __( 'Awaiting acceptance', 'ds-prod-import-crm' ),
					'color'            => '#dc2626',
					'is_system'        => 1,
					'is_closed'        => 0,
					'blocks_workflow'  => 1,
					'auto_on_paid'     => 0,
					'sort_order'       => 5,
					'status'           => 'active',
					'created_at'       => current_time( 'mysql' ),
					'updated_at'       => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		} else {
			$wpdb->update(
				$table,
				array( 'blocks_workflow' => 1, 'sort_order' => 5 ),
				array( 'slug' => 'awaiting_acceptance' ),
				array( '%d', '%d' ),
				array( '%s' )
			);
		}
	}

	/**
	 * Per-line delivery priority on order items.
	 *
	 * @return void
	 */
	private static function upgrade_096() {
		global $wpdb;

		$table = $wpdb->prefix . 'crm_order_items';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'delivery_priority'" );
		if ( empty( $exists ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN delivery_priority VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER unit_price" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_delivery_priority (delivery_priority)" );
		}
	}

	/**
	 * China export shipments (in-transit from office to BD warehouse).
	 *
	 * @return void
	 */
	private static function upgrade_097() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'crm_';

		dbDelta(
			"CREATE TABLE {$prefix}export_shipments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				shipment_number VARCHAR(50) NOT NULL UNIQUE,
				company_id INT NOT NULL,
				order_id INT NOT NULL,
				ship_date DATE NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'in_transit',
				total_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				notes TEXT,
				created_by INT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_company_id (company_id),
				INDEX idx_order_id (order_id),
				INDEX idx_ship_date (ship_date),
				INDEX idx_status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}export_shipment_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				shipment_id INT NOT NULL,
				order_item_id INT NOT NULL,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				quantity INT NOT NULL DEFAULT 0,
				weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				notes TEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_shipment_id (shipment_id),
				INDEX idx_order_item_id (order_item_id)
			) {$charset_collate};"
		);
	}

	/**
	 * China accept: accepted_quantity on lines + accepted_at on orders.
	 *
	 * @return void
	 */
	private static function upgrade_098() {
		global $wpdb;

		$items_table  = $wpdb->prefix . 'crm_order_items';
		$orders_table = $wpdb->prefix . 'crm_orders';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_accepted_qty = $wpdb->get_results( "SHOW COLUMNS FROM `{$items_table}` LIKE 'accepted_quantity'" );
		if ( empty( $has_accepted_qty ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN accepted_quantity INT DEFAULT NULL AFTER quantity" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_accepted_at = $wpdb->get_results( "SHOW COLUMNS FROM `{$orders_table}` LIKE 'accepted_at'" );
		if ( empty( $has_accepted_at ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$orders_table} ADD COLUMN accepted_at DATETIME DEFAULT NULL AFTER status" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$orders_table} ADD COLUMN accepted_by INT DEFAULT NULL AFTER accepted_at" );
		}

		// Backfill already-approved orders: accepted = ordered quantity.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$items_table} oi
			INNER JOIN {$orders_table} o ON o.id = oi.order_id
			INNER JOIN {$wpdb->prefix}crm_order_statuses os ON os.slug = o.status
			SET oi.accepted_quantity = oi.quantity
			WHERE oi.accepted_quantity IS NULL
			AND o.status != 'cancelled'
			AND COALESCE(os.blocks_workflow, 0) = 0"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$orders_table} o
			INNER JOIN {$wpdb->prefix}crm_order_statuses os ON os.slug = o.status
			SET o.accepted_at = COALESCE(o.updated_at, o.created_at),
			    o.accepted_by = COALESCE(o.updated_by, o.created_by)
			WHERE o.accepted_at IS NULL
			AND o.status != 'cancelled'
			AND COALESCE(os.blocks_workflow, 0) = 0"
		);
	}

	/**
	 * Export shipment line quantity change requests (China office amendments).
	 *
	 * @return void
	 */
	private static function upgrade_099() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'crm_';

		dbDelta(
			"CREATE TABLE {$prefix}export_shipment_amendments (
				id INT AUTO_INCREMENT PRIMARY KEY,
				shipment_id INT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				reason TEXT,
				review_notes TEXT,
				requested_by INT,
				requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				reviewed_by INT,
				reviewed_at DATETIME DEFAULT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				INDEX idx_shipment_id (shipment_id),
				INDEX idx_status (status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$prefix}export_shipment_amendment_items (
				id INT AUTO_INCREMENT PRIMARY KEY,
				amendment_id INT NOT NULL,
				shipment_item_id INT NOT NULL,
				order_item_id INT NOT NULL,
				product_name VARCHAR(255) NOT NULL,
				color VARCHAR(100),
				size VARCHAR(100),
				old_quantity INT NOT NULL DEFAULT 0,
				new_quantity INT NOT NULL DEFAULT 0,
				old_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				new_weight_kg DECIMAL(10,3) NOT NULL DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_amendment_id (amendment_id),
				INDEX idx_shipment_item_id (shipment_item_id)
			) {$charset_collate};"
		);
	}

	/**
	 * Link warehouse receives to China export shipments; track missing qty per line.
	 *
	 * @return void
	 */
	private static function upgrade_0100() {
		global $wpdb;

		$receives_table = $wpdb->prefix . 'crm_warehouse_receives';
		$items_table    = $wpdb->prefix . 'crm_receive_items';

		$receive_columns = array(
			'shipment_id' => "ALTER TABLE {$receives_table} ADD COLUMN shipment_id INT NULL AFTER company_id",
			'order_id'    => "ALTER TABLE {$receives_table} ADD COLUMN order_id INT NULL AFTER shipment_id",
			'client_id'   => "ALTER TABLE {$receives_table} ADD COLUMN client_id INT NULL AFTER order_id",
		);

		foreach ( $receive_columns as $column => $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has = $wpdb->get_results( "SHOW COLUMNS FROM `{$receives_table}` LIKE '{$column}'" );
			if ( empty( $has ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $sql );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_ship_idx = $wpdb->get_results( "SHOW INDEX FROM `{$receives_table}` WHERE Key_name = 'idx_shipment_id'" );
		if ( empty( $has_ship_idx ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$receives_table} ADD INDEX idx_shipment_id (shipment_id)" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_order_idx = $wpdb->get_results( "SHOW INDEX FROM `{$receives_table}` WHERE Key_name = 'idx_order_id'" );
		if ( empty( $has_order_idx ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$receives_table} ADD INDEX idx_order_id (order_id)" );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_client_idx = $wpdb->get_results( "SHOW INDEX FROM `{$receives_table}` WHERE Key_name = 'idx_client_id'" );
		if ( empty( $has_client_idx ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$receives_table} ADD INDEX idx_client_id (client_id)" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_esi = $wpdb->get_results( "SHOW COLUMNS FROM `{$items_table}` LIKE 'export_shipment_item_id'" );
		if ( empty( $has_esi ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN export_shipment_item_id INT NULL AFTER receive_id" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX idx_export_shipment_item_id (export_shipment_item_id)" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_missing = $wpdb->get_results( "SHOW COLUMNS FROM `{$items_table}` LIKE 'missing_quantity'" );
		if ( empty( $has_missing ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN missing_quantity INT NOT NULL DEFAULT 0 AFTER quantity" );
		}
	}

	/**
	 * Client payment purpose: order bill vs delivery bill (legacy rows = auto).
	 *
	 * @return void
	 */
	private static function upgrade_0110() {
		global $wpdb;

		$payments_table = $wpdb->prefix . 'crm_payments';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = $wpdb->get_results( "SHOW COLUMNS FROM `{$payments_table}` LIKE 'payment_purpose'" );
		if ( empty( $has ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"ALTER TABLE {$payments_table}
				ADD COLUMN payment_purpose VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER order_id"
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$payments_table} ADD INDEX idx_payment_purpose (payment_purpose)" );
		}
	}
}
