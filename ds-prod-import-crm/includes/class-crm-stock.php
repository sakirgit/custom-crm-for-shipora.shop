<?php
/**
 * Warehouse stock operations.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains crm_stock rows.
 */
class CRM_Stock {
	/**
	 * Normalize color/size for consistent stock keys.
	 *
	 * @param string|null $color Color.
	 * @param string|null $size Size.
	 * @return array{color: string, size: string}
	 */
	public static function normalize_variant( $color, $size ) {
		return array(
			'color' => sanitize_text_field( (string) ( $color ?? '' ) ),
			'size'  => sanitize_text_field( (string) ( $size ?? '' ) ),
		);
	}

	/**
	 * Variant quantity (exact color + size on the order line).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_name Product name.
	 * @param string $color Color.
	 * @param string $size Size.
	 * @return int
	 */
	public static function get_variant_quantity( $product_id, $product_name, $color, $size ) {
		global $wpdb;

		$variant = self::normalize_variant( $color, $size );
		$table   = crm_table( 'stock' );

		$qty = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(quantity, 0) FROM {$table}
				WHERE product_name = %s
				AND COALESCE(color, '') = %s
				AND COALESCE(size, '') = %s
				LIMIT 1",
				$product_name,
				$variant['color'],
				$variant['size']
			)
		);

		return max( 0, $qty );
	}

	/**
	 * Total stock for a catalog product (all color/size variants — matches Products list).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_name Product name.
	 * @return int
	 */
	public static function get_product_total_quantity( $product_id, $product_name ) {
		global $wpdb;

		$table = crm_table( 'stock' );
		$total = 0;

		if ( $product_id > 0 ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE product_id = %d",
					$product_id
				)
			);
		}

		if ( $total <= 0 && '' !== trim( $product_name ) ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE product_name = %s",
					$product_name
				)
			);
		}

		return max( 0, $total );
	}

	/**
	 * Variant + total availability for UI and validation.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_name Product name.
	 * @param string $color Color.
	 * @param string $size Size.
	 * @return array{variant: int, total: int}
	 */
	public static function get_availability( $product_id, $product_name, $color, $size ) {
		$variant = self::get_variant_quantity( $product_id, $product_name, $color, $size );
		$total   = self::get_product_total_quantity( $product_id, $product_name );

		return array(
			'variant' => $variant,
			'total'   => max( $total, $variant ),
		);
	}

	/**
	 * Find a stock row by variant key.
	 *
	 * @param string $product_name Product name.
	 * @param string $color Color.
	 * @param string $size Size.
	 * @return array<string, mixed>|null
	 */
	private static function find_variant_row( $product_name, $color, $size ) {
		global $wpdb;

		$variant = self::normalize_variant( $color, $size );
		$table   = crm_table( 'stock' );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, quantity, product_id FROM {$table}
				WHERE product_name = %s
				AND COALESCE(color, '') = %s
				AND COALESCE(size, '') = %s
				LIMIT 1",
				$product_name,
				$variant['color'],
				$variant['size']
			),
			ARRAY_A
		);
	}

	/**
	 * Stock rows for a product (for fallback decrement).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_name Product name.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_product_stock_rows( $product_id, $product_name ) {
		global $wpdb;

		$table = crm_table( 'stock' );
		$rows  = array();

		if ( $product_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, quantity, color, size FROM {$table}
					WHERE product_id = %d AND quantity > 0
					ORDER BY quantity DESC, id ASC",
					$product_id
				),
				ARRAY_A
			);
		}

		if ( empty( $rows ) && '' !== trim( $product_name ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, quantity, color, size FROM {$table}
					WHERE product_name = %s AND quantity > 0
					ORDER BY quantity DESC, id ASC",
					$product_name
				),
				ARRAY_A
			);
		}

		return $rows ?: array();
	}

	/**
	 * Increase stock for a product variant.
	 *
	 * @param int    $product_id Product ID (optional).
	 * @param string $product_name Product name.
	 * @param string $color Color.
	 * @param string $size Size.
	 * @param int    $quantity Quantity to add.
	 * @return bool
	 */
	public static function increment( $product_id, $product_name, $color, $size, $quantity ) {
		global $wpdb;

		$quantity = max( 0, absint( $quantity ) );
		if ( $quantity < 1 || '' === trim( $product_name ) ) {
			return false;
		}

		$variant = self::normalize_variant( $color, $size );
		$table   = crm_table( 'stock' );

		if ( $product_id <= 0 ) {
			$products_table = crm_table( 'products' );
			$product_id     = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$products_table} WHERE name = %s LIMIT 1",
					$product_name
				)
			);
		}

		$existing = self::find_variant_row( $product_name, $variant['color'], $variant['size'] );

		if ( $existing ) {
			$product_id_value = $product_id > 0 ? $product_id : (int) $existing['product_id'];

			return false !== $wpdb->update(
				$table,
				array(
					'product_id'   => $product_id_value > 0 ? $product_id_value : null,
					'quantity'     => (int) $existing['quantity'] + $quantity,
					'last_updated' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $existing['id'] ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);
		}

		return (bool) $wpdb->insert(
			$table,
			array(
				'product_id'   => $product_id > 0 ? $product_id : 0,
				'product_name' => $product_name,
				'color'        => $variant['color'],
				'size'         => $variant['size'],
				'quantity'     => $quantity,
				'last_updated' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Decrease stock for an exact variant key.
	 *
	 * @param string $product_name Product name.
	 * @param string $color Color.
	 * @param string $size Size.
	 * @param int    $quantity Quantity to remove.
	 * @return true|\WP_Error
	 */
	public static function decrement( $product_name, $color, $size, $quantity ) {
		return self::decrement_row( self::find_variant_row( $product_name, $color, $size ), $quantity, $product_name );
	}

	/**
	 * Decrease stock for a delivery line (variant first, then other rows for same product).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $product_name Product name.
	 * @param string $color Color.
	 * @param string $size Size.
	 * @param int    $quantity Quantity to remove.
	 * @return true|\WP_Error
	 */
	public static function decrement_for_delivery( $product_id, $product_name, $color, $size, $quantity ) {
		$quantity = max( 0, absint( $quantity ) );
		if ( $quantity < 1 ) {
			return true;
		}

		$availability = self::get_availability( $product_id, $product_name, $color, $size );
		if ( $availability['total'] < $quantity ) {
			return new \WP_Error(
				'stock_insufficient',
				sprintf(
					/* translators: 1: available qty, 2: requested qty */
					__( 'Insufficient stock for %3$s. Available: %1$d, requested: %2$d', 'ds-prod-import-crm' ),
					$availability['total'],
					$quantity,
					$product_name
				)
			);
		}

		$remaining = $quantity;
		$variant   = self::find_variant_row( $product_name, $color, $size );

		if ( $variant && (int) $variant['quantity'] > 0 ) {
			$take   = min( $remaining, (int) $variant['quantity'] );
			$result = self::decrement_row_by_id( (int) $variant['id'], $take, $product_name );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$remaining -= $take;
		}

		if ( $remaining < 1 ) {
			return true;
		}

		$variant_key = self::normalize_variant( $color, $size );
		foreach ( self::get_product_stock_rows( $product_id, $product_name ) as $row ) {
			if ( $remaining < 1 ) {
				break;
			}

			$row_color = self::normalize_variant( $row['color'] ?? '', $row['size'] ?? '' );
			if ( $row_color['color'] === $variant_key['color'] && $row_color['size'] === $variant_key['size'] ) {
				continue;
			}

			$take   = min( $remaining, (int) $row['quantity'] );
			$result = self::decrement_row_by_id( (int) $row['id'], $take, $product_name );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$remaining -= $take;
		}

		if ( $remaining > 0 ) {
			return new \WP_Error(
				'stock_insufficient',
				sprintf(
					__( 'Could not allocate %1$d unit(s) of stock for %2$s.', 'ds-prod-import-crm' ),
					$remaining,
					$product_name
				)
			);
		}

		return true;
	}

	/**
	 * Decrease quantity on a stock row.
	 *
	 * @param array<string, mixed>|null $row Stock row.
	 * @param int                       $quantity Quantity.
	 * @param string                    $product_name Product label for errors.
	 * @return true|\WP_Error
	 */
	private static function decrement_row( $row, $quantity, $product_name ) {
		if ( ! $row ) {
			return new \WP_Error( 'stock_missing', __( 'No stock record found for this product variant.', 'ds-prod-import-crm' ) );
		}

		return self::decrement_row_by_id( (int) $row['id'], $quantity, $product_name );
	}

	/**
	 * Decrease quantity on a stock row by ID.
	 *
	 * @param int    $row_id Row ID.
	 * @param int    $quantity Quantity.
	 * @param string $product_name Product label for errors.
	 * @return true|\WP_Error
	 */
	private static function decrement_row_by_id( $row_id, $quantity, $product_name ) {
		global $wpdb;

		$quantity = max( 0, absint( $quantity ) );
		$table    = crm_table( 'stock' );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, quantity FROM {$table} WHERE id = %d", $row_id ),
			ARRAY_A
		);

		if ( ! $existing ) {
			return new \WP_Error( 'stock_missing', __( 'Stock row not found.', 'ds-prod-import-crm' ) );
		}

		if ( (int) $existing['quantity'] < $quantity ) {
			return new \WP_Error(
				'stock_insufficient',
				sprintf(
					__( 'Insufficient stock for %1$s. Available: %2$d, requested: %3$d', 'ds-prod-import-crm' ),
					$product_name,
					(int) $existing['quantity'],
					$quantity
				)
			);
		}

		$updated = $wpdb->update(
			$table,
			array(
				'quantity'     => (int) $existing['quantity'] - $quantity,
				'last_updated' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated ? true : new \WP_Error( 'stock_update_failed', __( 'Failed to update stock.', 'ds-prod-import-crm' ) );
	}
}
