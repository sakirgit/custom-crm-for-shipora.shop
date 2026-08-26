<?php
/**
 * Product categories module controller.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Product category CRUD.
 */
class Product_Categories_Controller extends CRM_Controller_Base {
	/**
	 * Allowed sort columns.
	 *
	 * @var array<int, string>
	 */
	private static $sort_columns = array( 'name', 'status', 'created_at' );

	/**
	 * Register AJAX hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_product_categories_list', array( __CLASS__, 'list_items' ) );
		add_action( 'wp_ajax_crm_product_categories_get', array( __CLASS__, 'get_item' ) );
		add_action( 'wp_ajax_crm_product_categories_save', array( __CLASS__, 'save_item' ) );
		add_action( 'wp_ajax_crm_product_categories_delete', array( __CLASS__, 'delete_item' ) );
		add_action( 'wp_ajax_crm_product_categories_options', array( __CLASS__, 'options' ) );
	}

	/**
	 * Active categories for product form dropdowns.
	 *
	 * @return void
	 */
	public static function options() {
		self::verify_module_action( 'crm_products_view', 'crm_manage_products' );

		global $wpdb;

		$table = crm_table( 'product_categories' );
		$items = $wpdb->get_results(
			"SELECT id, name FROM {$table} WHERE status = 'active' ORDER BY name ASC",
			ARRAY_A
		);

		wp_send_json_success(
			array(
				'items' => $items ? $items : array(),
			)
		);
	}

	/**
	 * List categories.
	 *
	 * @return void
	 */
	public static function list_items() {
		self::verify_module_action( 'crm_products_view', 'crm_manage_products' );

		global $wpdb;

		$table      = crm_table( 'product_categories' );
		$pagination = self::pagination_from_request();
		$dates      = self::date_range_from_request();
		$search     = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$status     = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$sort_by    = isset( $_POST['sort_by'] ) ? sanitize_key( wp_unslash( $_POST['sort_by'] ) ) : 'created_at';
		$sort_dir   = crm_sort_direction( isset( $_POST['sort_dir'] ) ? wp_unslash( $_POST['sort_dir'] ) : 'DESC' );

		if ( ! in_array( $sort_by, self::$sort_columns, true ) ) {
			$sort_by = 'created_at';
		}

		$where  = array( '1=1' );
		$params = array();

		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		if ( $dates['date_from'] ) {
			$where[]  = 'DATE(created_at) >= %s';
			$params[] = $dates['date_from'];
		}

		if ( $dates['date_to'] ) {
			$where[]  = 'DATE(created_at) <= %s';
			$params[] = $dates['date_to'];
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $params ) ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$list_sql = "SELECT id, name, description, status, created_at, updated_at
			FROM {$table}
			WHERE {$where_sql}
			ORDER BY {$sort_by} {$sort_dir}
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
				'summary'     => CRM_Module_Summary::product_categories( $where_sql, $params ),
			)
		);
	}

	/**
	 * Get single category.
	 *
	 * @return void
	 */
	public static function get_item() {
		self::verify_module_action( 'crm_products_view', 'crm_manage_products' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid category ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'product_categories' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Category not found.', 'ds-prod-import-crm' ) ) );
		}

		wp_send_json_success( array( 'item' => $row ) );
	}

	/**
	 * Create or update category.
	 *
	 * @return void
	 */
	public static function save_item() {
		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::verify_module_save( 'crm_products_create', 'crm_products_edit', 'crm_manage_products', $id );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Category name is required.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'product_categories' );

		$duplicate = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE name = %s AND id != %d",
				$name,
				$id
			)
		);

		if ( $duplicate > 0 ) {
			wp_send_json_error( array( 'message' => __( 'A category with this name already exists.', 'ds-prod-import-crm' ) ) );
		}

		$data = array(
			'name'        => $name,
			'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'status'      => isset( $_POST['status'] ) && 'inactive' === sanitize_key( wp_unslash( $_POST['status'] ) ) ? 'inactive' : 'active',
			'updated_at'  => current_time( 'mysql' ),
		);

		$labels = array(
			'name'   => __( 'Name', 'ds-prod-import-crm' ),
			'status' => __( 'Status', 'ds-prod-import-crm' ),
		);

		if ( $id ) {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			$data['updated_by'] = CRM_Audit::current_user_id();

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( array( 'message' => __( 'Failed to update category.', 'ds-prod-import-crm' ) ) );
			}

			$changes = $before ? CRM_Audit::describe_changes( $before, $data, $labels ) : array();
			self::log_activity(
				'update',
				'product_categories',
				$id,
				sprintf( 'Updated category: %s', $name ),
				array( 'changes' => $changes )
			);

			wp_send_json_success(
				array(
					'message' => __( 'Category updated successfully.', 'ds-prod-import-crm' ),
					'id'      => $id,
				)
			);
		}

		$data['created_at'] = current_time( 'mysql' );
		$data['created_by'] = CRM_Audit::current_user_id();

		$inserted = $wpdb->insert(
			$table,
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to create category.', 'ds-prod-import-crm' ) ) );
		}

		$new_id = (int) $wpdb->insert_id;
		self::log_activity( 'create', 'product_categories', $new_id, sprintf( 'Created category: %s', $name ) );

		wp_send_json_success(
			array(
				'message' => __( 'Category created successfully.', 'ds-prod-import-crm' ),
				'id'      => $new_id,
			)
		);
	}

	/**
	 * Delete category when not used by products.
	 *
	 * @return void
	 */
	public static function delete_item() {
		self::verify_module_action( 'crm_products_delete', 'crm_manage_products' );

		global $wpdb;

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid category ID.', 'ds-prod-import-crm' ) ) );
		}

		$table = crm_table( 'product_categories' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT name, is_system FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Category not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( ! empty( $row['is_system'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The Uncategorized category cannot be deleted.', 'ds-prod-import-crm' ) ) );
		}

		$uncategorized_id = crm_uncategorized_category_id();
		$products_table   = crm_table( 'products' );

		$wpdb->update(
			$products_table,
			array(
				'category_id' => $uncategorized_id,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'category_id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'Failed to delete category.', 'ds-prod-import-crm' ) ) );
		}

		self::log_activity( 'delete', 'product_categories', $id, sprintf( 'Deleted category: %s (products moved to Uncategorized)', $row['name'] ) );

		wp_send_json_success(
			array(
				'message' => __( 'Category deleted. Its products were moved to Uncategorized.', 'ds-prod-import-crm' ),
			)
		);
	}
}
