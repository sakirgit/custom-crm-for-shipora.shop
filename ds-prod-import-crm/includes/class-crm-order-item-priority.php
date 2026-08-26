<?php
/**
 * Delivery priority for order line items.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Order item delivery priority (urgent → 2nd priority → normal).
 */
class CRM_Order_Item_Priority {

	const URGENT   = 'urgent';
	const PRIORITY = 'priority';
	const NORMAL   = 'normal';

	/**
	 * All priority slugs with labels.
	 *
	 * @return array<string, string>
	 */
	public static function all() {
		return array(
			self::URGENT   => __( 'Emergency', 'ds-prod-import-crm' ),
			self::PRIORITY => __( '2nd priority', 'ds-prod-import-crm' ),
			self::NORMAL   => __( 'Normal', 'ds-prod-import-crm' ),
		);
	}

	/**
	 * Sanitize a priority slug.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize( $value ) {
		$value = sanitize_key( (string) $value );
		$all   = self::all();

		return array_key_exists( $value, $all ) ? $value : self::NORMAL;
	}

	/**
	 * Human label for a priority slug.
	 *
	 * @param mixed $value Priority slug.
	 * @return string
	 */
	public static function label( $value ) {
		$slug = self::sanitize( $value );

		return self::all()[ $slug ];
	}

	/**
	 * Sort rank (lower = higher priority).
	 *
	 * @param mixed $value Priority slug.
	 * @return int
	 */
	public static function sort_rank( $value ) {
		$ranks = array(
			self::URGENT   => 0,
			self::PRIORITY => 1,
			self::NORMAL   => 2,
		);
		$slug  = self::sanitize( $value );

		return $ranks[ $slug ];
	}

	/**
	 * Sort items: urgent first, then 2nd priority, then normal; preserve id within tier.
	 *
	 * @param array<int, array<string, mixed>> $items Order item rows.
	 * @return void
	 */
	public static function sort_items( array &$items ) {
		usort(
			$items,
			static function ( $a, $b ) {
				$rank_a = self::sort_rank( $a['delivery_priority'] ?? self::NORMAL );
				$rank_b = self::sort_rank( $b['delivery_priority'] ?? self::NORMAL );

				if ( $rank_a !== $rank_b ) {
					return $rank_a <=> $rank_b;
				}

				return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
			}
		);
	}

	/**
	 * SQL ORDER BY clause fragment for order_items alias.
	 *
	 * @param string $alias Table alias.
	 * @return string
	 */
	public static function sql_order_by( $alias = 'oi' ) {
		$urgent   = self::URGENT;
		$priority = self::PRIORITY;
		$normal   = self::NORMAL;

		return "FIELD({$alias}.delivery_priority, '{$urgent}', '{$priority}', '{$normal}'), {$alias}.id ASC";
	}

	/**
	 * Attach normalized slug and label to an item row.
	 *
	 * @param array<string, mixed> $item Item row.
	 * @return void
	 */
	public static function enrich( array &$item ) {
		$slug                        = self::sanitize( $item['delivery_priority'] ?? self::NORMAL );
		$item['delivery_priority']       = $slug;
		$item['delivery_priority_label'] = self::label( $slug );
	}
}
