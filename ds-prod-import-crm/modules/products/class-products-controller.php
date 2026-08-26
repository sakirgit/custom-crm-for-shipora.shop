<?php
/**
 * Products module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Product master CRUD.
 */
class Products_Controller extends CRM_Controller_Base {
	/**
	 * Allowed sort columns.
	 *
	 * @var array<int, string>
	 */
	private static $sort_columns = array( 'name', 'sku', 'category_name', 'unit_price', 'created_at' );

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_products_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_products_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_products_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_products_delete', array( __CLASS__, 'delete_item' ) );
		add_action( 'wp_ajax_crm_products_search', array( __CLASS__, 'search_picker' ) );
	}

	/**
	 * Search catalog products for order/receive pickers.
	 *
	 * @return void
	 */
	public static function search_picker() {
		self::verify_request_any(
			array(
				'crm_products_view',
				'crm_manage_products',
				'crm_manage_orders',
				'crm_orders_view',
				'crm_orders_create',
				'crm_orders_edit',
				'crm_stock_receive',
				'crm_receive_stock',
			)
		);

		global $wpdb;

		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$min_length = 2;
		$table      = crm_table( 'products' );

		if ( mb_strlen( $search ) < $min_length ) {
			wp_send_json_success(
				array(
					'items'      => array(),
					'hint'       => sprintf(
						/* translators: %d: minimum characters */
						__( 'Type at least %d characters to search products.', 'ds-prod-import-crm' ),
						$min_length
					),
					'min_length' => $min_length,
				)
			);
		}

		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$prefix = $wpdb->esc_like( $search ) . '%';

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, sku, unit_price, purchase_rate, color, size,
				COALESCE(NULLIF(thumbnail_url, ''), image_url) AS image_url
				FROM {$table}
				WHERE name LIKE %s OR sku LIKE %s
				ORDER BY CASE WHEN name LIKE %s OR sku LIKE %s THEN 0 ELSE 1 END, name ASC
				LIMIT 15",
				$like,
				$like,
				$prefix,
				$prefix
			),
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'items'      => $items ? $items : array(),
				'has_more'   => is_array( $items ) && count( $items ) >= 15,
				'min_length' => $min_length,
			)
		);
	}

	/**
	 * List products with search, sort, pagination, stock summary.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_module_action( 'crm_products_view', 'crm_manage_products' );

		global $wpdb;

		$table            = crm_table( 'products' );
		$stock_table      = crm_table( 'stock' );
		$categories_table = crm_table( 'product_categories' );
		$pagination  = self::pagination_from_request();
		$dates       = self::date_range_from_request();
		$search      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$sort_by     = isset( $_POST['sort_by'] ) ? sanitize_key( wp_unslash( $_POST['sort_by'] ) ) : 'created_at';
		$sort_dir    = crm_sort_direction( isset( $_POST['sort_dir'] ) ? wp_unslash( $_POST['sort_dir'] ) : 'DESC' );

		if ( ! in_array( $sort_by, self::$sort_columns, true ) ) {
			$sort_by = 'created_at';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(p.name LIKE %s OR c.name LIKE %s OR p.description LIKE %s OR p.sku LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $dates['date_from'] ) {
			$where[]  = 'DATE(p.created_at) >= %s';
			$params[] = $dates['date_from'];
		}

		if ( $dates['date_to'] ) {
			$where[]  = 'DATE(p.created_at) <= %s';
			$params[] = $dates['date_to'];
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} p LEFT JOIN {$categories_table} c ON c.id = p.category_id WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$sort_column = 'category_name' === $sort_by ? 'c.name' : 'p.' . $sort_by;

		$list_sql = "SELECT p.id, p.name, p.sku, p.color, p.size, p.category_id, MAX(c.name) AS category_name, p.description, p.unit_price, p.purchase_rate,
			p.image_url, COALESCE(NULLIF(p.thumbnail_url, ''), p.image_url) AS thumbnail_url, p.created_at, p.updated_at,
			COALESCE((
				SELECT SUM(s.quantity) FROM {$stock_table} s
				WHERE s.product_id = p.id OR (s.product_id = 0 AND s.product_name = p.name)
			), 0) AS stock_qty
			FROM {$table} p
			LEFT JOIN {$categories_table} c ON c.id = p.category_id
			WHERE {$where_sql}
			GROUP BY p.id
			ORDER BY {$sort_column} {$sort_dir}
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $pagination['per_page'], $pagination['offset'] ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
		$total_pages = $pagination['per_page'] > 0 ? (int) ceil( $total / $pagination['per_page'] ) : 1;

		wp_send_json_success(
			array(
				'items'       => $items ? $items : array(),
				'total'       => $total,
				'page'        => $pagination['page'],
				'per_page'    => $pagination['per_page'],
				'total_pages' => max( 1, $total_pages ),
				'summary'     => CRM_Module_Summary::products( $where_sql, $params ),
			)
		);
	}

	/**
	 * Get single product.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_module_action( 'crm_products_view', 'crm_manage_products' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'products' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success( array( 'item' => $row ) );
	}

	/**
	 * Create or update product.
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::verify_module_save( 'crm_products_create', 'crm_products_edit', 'crm_manage_products', $id );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Product name is required.', 'ds-prod-import-crm' ) ) );
		}

		$image_url     = isset( $_POST['image_url_current'] ) ? esc_url_raw( wp_unslash( $_POST['image_url_current'] ) ) : '';
		$thumbnail_url = isset( $_POST['thumbnail_url_current'] ) ? esc_url_raw( wp_unslash( $_POST['thumbnail_url_current'] ) ) : '';

		if ( ! empty( $_POST['remove_image'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['remove_image'] ) ) ) {
			$image_url     = '';
			$thumbnail_url = '';
		}

		$upload_result = crm_handle_product_image_upload();
		if ( is_wp_error( $upload_result ) ) {
			wp_send_json_error( array( 'message' => $upload_result->get_error_message() ) );
		}
		if ( is_array( $upload_result ) && ! empty( $upload_result['url'] ) ) {
			$image_url     = $upload_result['url'];
			$thumbnail_url = $upload_result['thumbnail_url'] ?? '';
		}

		$sku = isset( $_POST['sku'] ) ? crm_sanitize_sku( wp_unslash( $_POST['sku'] ) ) : '';
		if ( '' !== $sku ) {
			$sku_table = crm_table( 'products' );
			$duplicate = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$sku_table} WHERE sku = %s AND id != %d",
					$sku,
					$id
				)
			);
			if ( $duplicate > 0 ) {
				wp_send_json_error( array( 'message' => __( 'Another product already uses this SKU.', 'ds-prod-import-crm' ) ) );
			}
		}

		$category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;

		if ( $category_id > 0 ) {
			$category_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . crm_table( 'product_categories' ) . ' WHERE id = %d',
					$category_id
				)
			);
			if ( ! $category_exists ) {
				wp_send_json_error( array( 'message' => __( 'Selected category does not exist.', 'ds-prod-import-crm' ) ) );
			}
		}

		$data = array(
			'name'          => $name,
			'sku'           => $sku,
			'category_id'   => $category_id > 0 ? $category_id : 0,
			'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'unit_price'    => isset( $_POST['unit_price'] ) ? crm_parse_amount( wp_unslash( $_POST['unit_price'] ) ) : 0,
			'purchase_rate' => isset( $_POST['purchase_rate'] ) ? crm_parse_amount( wp_unslash( $_POST['purchase_rate'] ) ) : 0,
			'color'         => isset( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : '',
			'size'          => isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '',
			'image_url'     => $image_url,
			'thumbnail_url' => $thumbnail_url,
			'updated_at'    => current_time( 'mysql' ),
		);
		$data = crm_normalize_product_prices_for_save( $data );

		$table  = crm_table( 'products' );
		$labels = array(
			'name'          => __( 'Name', 'ds-prod-import-crm' ),
			'sku'           => __( 'SKU', 'ds-prod-import-crm' ),
			'category_id'   => __( 'Category', 'ds-prod-import-crm' ),
			'unit_price'    => __( 'Unit price', 'ds-prod-import-crm' ),
			'purchase_rate' => __( 'Purchase rate', 'ds-prod-import-crm' ),
			'color'         => __( 'Color', 'ds-prod-import-crm' ),
			'size'          => __( 'Size', 'ds-prod-import-crm' ),
		);

		if ( $id ) {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			$data['updated_by'] = CRM_Audit::current_user_id();

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update product.', 'ds-prod-import-crm' ) ) );
			}

			$changes = $before ? CRM_Audit::describe_changes( $before, $data, $labels ) : array();
			self::log_activity(
				'update',
				'products',
				$id,
				sprintf( 'Updated product: %s', $name ),
				array( 'changes' => $changes )
			);

			wp_send_json_success(
				array(
					'message' => __( 'Product updated successfully.', 'ds-prod-import-crm' ),
					'id'      => $id,
				)
			);
		}

		$data['created_at'] = current_time( 'mysql' );
		$data['created_by'] = CRM_Audit::current_user_id();

		$inserted = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create product.', 'ds-prod-import-crm' ) ) );
		}

		$new_id = (int) $wpdb->insert_id;
		self::log_activity( 'create', 'products', $new_id, sprintf( 'Created product: %s', $name ) );

		wp_send_json_success(
			array(
				'message' => __( 'Product created successfully.', 'ds-prod-import-crm' ),
				'id'      => $new_id,
			)
		);
	}

	/**
	 * Delete product when not referenced.
	 *
	 * @return void
	 */
	public static function delete_item() {
		self::verify_module_action( 'crm_products_delete', 'crm_manage_products' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'products' );
		$name  = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) );

		if ( ! $name ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'ds-prod-import-crm' ) ) );
		}

		$order_item_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'order_items' ) . ' WHERE product_id = %d',
				$id
			)
		);

		$receive_item_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'receive_items' ) . ' WHERE product_id = %d',
				$id
			)
		);

		$stock_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'stock' ) . ' WHERE product_id = %d AND quantity > 0',
				$id
			)
		);

		if ( $order_item_count > 0 || $receive_item_count > 0 || $stock_count > 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot delete: this product is used in orders, receives, or has stock. Remove stock usage first.', 'ds-prod-import-crm' ),
				)
			);
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete product.', 'ds-prod-import-crm' ) ) );
		}

		self::log_activity( 'delete', 'products', $id, sprintf( 'Deleted product: %s', $name ) );

		wp_send_json_success(
			array(
				'message' => __( 'Product deleted successfully.', 'ds-prod-import-crm' ),
			)
		);
	}
}
