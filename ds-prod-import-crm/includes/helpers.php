<?php
/**
 * Shared helpers.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Build prefixed table name.
 *
 * @param string $table_slug Table suffix.
 * @return string
 */
function crm_table( $table_slug ) {
	global $wpdb;

	return $wpdb->prefix . 'crm_' . $table_slug;
}

/**
 * Format date as YYYY-MM-DD.
 *
 * @param string $date_value Date string.
 * @return string
 */
function crm_normalize_date( $date_value ) {
	$timestamp = strtotime( sanitize_text_field( $date_value ) );
	return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
}

/**
 * Sanitize sort direction.
 *
 * @param string $direction Sort direction.
 * @return string
 */
function crm_sort_direction( $direction ) {
	return 'ASC' === strtoupper( sanitize_text_field( $direction ) ) ? 'ASC' : 'DESC';
}

/**
 * Check if current user can manage companies.
 *
 * @return bool
 */
function crm_user_can_manage_companies() {
	return current_user_can( 'crm_companies_view' ) || current_user_can( 'crm_manage_companies' );
}

/**
 * Parse monetary amount to 2 decimal places.
 *
 * @param mixed $value Raw amount.
 * @return float
 */
function crm_parse_amount( $value ) {
	return round( max( 0, (float) $value ), 2 );
}

/**
 * Parse weight in kg to 2 decimal places.
 *
 * @param mixed $value Raw weight.
 * @return float
 */
function crm_parse_weight( $value ) {
	return round( max( 0, (float) $value ), 2 );
}

/**
 * Format weight (kg) for display.
 *
 * @param float|string $weight Weight in kg.
 * @param bool         $with_unit Append " kg".
 * @return string
 */
function crm_format_weight( $weight, $with_unit = false ) {
	$formatted = number_format( crm_parse_weight( $weight ), 2 );
	return $with_unit ? $formatted . ' kg' : $formatted;
}

/**
 * Average kg per unit from warehouse receive history for a product variant.
 *
 * @param int    $product_id   Catalog product ID.
 * @param string $product_name Product name fallback.
 * @param string $color        Color.
 * @param string $size         Size.
 * @return float
 */
function crm_receive_weight_per_unit( $product_id, $product_name, $color = '', $size = '' ) {
	global $wpdb;

	$table   = crm_table( 'receive_items' );
	$variant = CRM_Stock::normalize_variant( $color, $size );

	if ( $product_id > 0 ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(weight_kg), 0) AS total_kg, COALESCE(SUM(quantity), 0) AS total_qty
				FROM {$table}
				WHERE product_id = %d AND COALESCE(color, '') = %s AND COALESCE(size, '') = %s",
				$product_id,
				$variant['color'],
				$variant['size']
			),
			ARRAY_A
		);
	} else {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(weight_kg), 0) AS total_kg, COALESCE(SUM(quantity), 0) AS total_qty
				FROM {$table}
				WHERE product_name = %s AND COALESCE(color, '') = %s AND COALESCE(size, '') = %s",
				$product_name,
				$variant['color'],
				$variant['size']
			),
			ARRAY_A
		);
	}

	$qty = (int) ( $row['total_qty'] ?? 0 );
	if ( $qty < 1 ) {
		return 0.0;
	}

	return crm_parse_weight( (float) $row['total_kg'] / $qty );
}

/**
 * Weighted average shipping rate (BDT / kg) from warehouse receive history.
 *
 * @param int    $product_id   Catalog product ID.
 * @param string $product_name Product name fallback.
 * @param string $color        Color.
 * @param string $size         Size.
 * @return float
 */
function crm_receive_shipping_rate_per_kg( $product_id, $product_name, $color = '', $size = '' ) {
	global $wpdb;

	$table   = crm_table( 'receive_items' );
	$variant = CRM_Stock::normalize_variant( $color, $size );

	if ( $product_id > 0 ) {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(weight_kg), 0) AS total_kg,
				COALESCE(SUM(shipping_share), 0) AS total_shipping,
				COALESCE(SUM(weight_kg * shipping_rate_per_kg), 0) AS weighted_rate_sum
				FROM {$table}
				WHERE product_id = %d AND COALESCE(color, '') = %s AND COALESCE(size, '') = %s",
				$product_id,
				$variant['color'],
				$variant['size']
			),
			ARRAY_A
		);
	} else {
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(weight_kg), 0) AS total_kg,
				COALESCE(SUM(shipping_share), 0) AS total_shipping,
				COALESCE(SUM(weight_kg * shipping_rate_per_kg), 0) AS weighted_rate_sum
				FROM {$table}
				WHERE product_name = %s AND COALESCE(color, '') = %s AND COALESCE(size, '') = %s",
				$product_name,
				$variant['color'],
				$variant['size']
			),
			ARRAY_A
		);
	}

	$kg = (float) ( $row['total_kg'] ?? 0 );
	if ( $kg <= 0 ) {
		return 0.0;
	}

	$shipping = (float) ( $row['total_shipping'] ?? 0 );
	if ( $shipping > 0 ) {
		return crm_parse_amount( $shipping / $kg );
	}

	return crm_parse_amount( (float) ( $row['weighted_rate_sum'] ?? 0 ) / $kg );
}

/**
 * Total line weight (kg) for an order item.
 *
 * @param float|string $stored_weight_kg Saved line weight.
 * @param int          $quantity         Ordered quantity.
 * @param int          $product_id       Product ID.
 * @param string       $product_name     Product name.
 * @param string       $color            Color.
 * @param string       $size             Size.
 * @return float
 */
function crm_order_line_weight_kg( $stored_weight_kg, $quantity, $product_id, $product_name, $color = '', $size = '' ) {
	$qty    = max( 1, (int) $quantity );
	$weight = crm_parse_weight( $stored_weight_kg );

	if ( $weight > 0 ) {
		return $weight;
	}

	$per_unit = crm_receive_weight_per_unit( $product_id, $product_name, $color, $size );
	if ( $per_unit <= 0 ) {
		return 0.0;
	}

	return crm_parse_weight( $per_unit * $qty );
}

/**
 * Delivered weight (kg) for a partial or full delivery line.
 *
 * @param float|string $stored_weight_kg Saved order line weight.
 * @param int          $quantity_ordered Ordered quantity.
 * @param int          $deliver_qty      Quantity being delivered now.
 * @param int          $product_id       Product ID.
 * @param string       $product_name     Product name.
 * @param string       $color            Color.
 * @param string       $size             Size.
 * @return float
 */
function crm_delivery_line_weight_kg( $stored_weight_kg, $quantity_ordered, $deliver_qty, $product_id, $product_name, $color = '', $size = '' ) {
	$ordered = max( 1, (int) $quantity_ordered );
	$deliver = max( 0, (int) $deliver_qty );

	if ( $deliver < 1 ) {
		return 0.0;
	}

	$line_weight = crm_order_line_weight_kg( $stored_weight_kg, $ordered, $product_id, $product_name, $color, $size );

	return crm_parse_weight( ( $line_weight / $ordered ) * $deliver );
}

/**
 * Split a monetary amount across weights (2 dp). Last line absorbs rounding remainder.
 *
 * @param float              $total_amount Amount to allocate.
 * @param array<int, float>  $weights      Line weights.
 * @return array<int, float>
 */
function crm_allocate_by_weight( $total_amount, array $weights ) {
	$total_amount = crm_parse_amount( $total_amount );
	$count        = count( $weights );

	if ( $count < 1 || $total_amount <= 0 ) {
		return array_fill( 0, $count, 0.0 );
	}

	$normalized    = array_map( 'crm_parse_weight', $weights );
	$total_weight  = array_sum( $normalized );
	$shares        = array();
	$allocated     = 0.0;

	if ( $total_weight <= 0 ) {
		return array_fill( 0, $count, 0.0 );
	}

	foreach ( $normalized as $index => $weight ) {
		if ( $index === $count - 1 ) {
			$shares[] = crm_parse_amount( $total_amount - $allocated );
			continue;
		}

		$share       = crm_parse_amount( ( $weight / $total_weight ) * $total_amount );
		$shares[]    = $share;
		$allocated  += $share;
	}

	return $shares;
}

/**
 * Format amount for display (BDT).
 *
 * @param float|string $amount Amount.
 * @return string
 */
function crm_format_amount( $amount ) {
	return '৳' . number_format( (float) $amount, 2 );
}

/**
 * Generate sequential document number (ORD/RCV/DLV/PAY).
 *
 * @param string $prefix Number prefix.
 * @param string $table_slug Table slug without prefix.
 * @param string $column Number column name.
 * @return string
 */
function crm_generate_sequence_number( $prefix, $table_slug, $column ) {
	global $wpdb;

	$table = crm_table( $table_slug );
	$date  = gmdate( 'Ymd' );
	$like  = $wpdb->esc_like( $prefix . '-' . $date . '-' ) . '%';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$last = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT {$column} FROM {$table} WHERE {$column} LIKE %s ORDER BY id DESC LIMIT 1",
			$like
		)
	);

	$sequence = 1;
	if ( $last && preg_match( '/-(\d{4})$/', $last, $matches ) ) {
		$sequence = (int) $matches[1] + 1;
	}

	return sprintf( '%s-%s-%04d', $prefix, $date, $sequence );
}

/**
 * Prepare a safe order autocomplete search (delivery / export pickers).
 *
 * Only blocks the shared order-number stem (ORD / ORD- / ORD-2 / ORD-20),
 * which would match nearly every order. Any more specific fragment
 * (e.g. 01, 719, 19-, ORD-2026) is allowed and matched with contains/prefix.
 *
 * @param string $search Raw search term.
 * @return array{
 *   ok:bool,
 *   hint?:string,
 *   mode?:string,
 *   contains_like?:string,
 *   order_number_like?:string,
 *   limit?:int
 * }
 */
function crm_prepare_order_autocomplete_search( $search ) {
	global $wpdb;

	$search = trim( (string) $search );
	$limit  = 20;

	if ( mb_strlen( $search ) < 2 ) {
		return array(
			'ok'   => false,
			'hint' => __( 'Type at least 2 characters to search orders.', 'ds-prod-import-crm' ),
		);
	}

	$compact = preg_replace( '/\s+/', '', $search );
	$upper   = strtoupper( (string) $compact );

	/*
	 * Block only the shared stem of ORD-20xx… numbers.
	 * ORD-2026, 01, 719, 19-, 9-0, etc. remain searchable.
	 */
	if ( in_array( $upper, array( 'ORD', 'ORD-', 'ORD-2', 'ORD-20' ), true ) ) {
		return array(
			'ok'   => false,
			'hint' => __( '“ORD-20” is too common. Type more of the number (e.g. 0719, 0001, or ORD-20260719).', 'ds-prod-import-crm' ),
		);
	}

	// Longer ORD-… queries: prefix-match order numbers (index-friendly).
	if ( preg_match( '/^ORD-?/i', (string) $compact ) && mb_strlen( (string) $compact ) >= 5 ) {
		$prefix = $upper;
		if ( preg_match( '/^ORD([0-9].*)$/', $upper, $m ) ) {
			$prefix = 'ORD-' . $m[1];
		} elseif ( preg_match( '/^ORD-(.+)$/', $upper, $m ) ) {
			$prefix = 'ORD-' . $m[1];
		}

		return array(
			'ok'                => true,
			'mode'              => 'order_prefix',
			'order_number_like' => $wpdb->esc_like( $prefix ) . '%',
			'contains_like'     => '%' . $wpdb->esc_like( $search ) . '%',
			'limit'             => $limit,
		);
	}

	// Fragments anywhere in the order number / client / product (01, 719, 19-, 9-0…).
	$like = '%' . $wpdb->esc_like( $search ) . '%';

	return array(
		'ok'                => true,
		'mode'              => 'contains',
		'order_number_like' => $like,
		'contains_like'     => $like,
		'limit'             => $limit,
	);
}

/**
 * Handle a validated product image upload.
 *
 * @param string $field_name $_FILES key.
 * @return array{url:string,thumbnail_url:string}|\WP_Error|string Empty string when no file.
 */
function crm_handle_product_image_upload( $field_name = 'image' ) {
	$field_name = sanitize_key( $field_name );

	if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['name'] ) ) {
		return '';
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$file = $_FILES[ $field_name ];

	if ( ! empty( $file['error'] ) ) {
		return new \WP_Error( 'upload_error', __( 'Image upload failed.', 'ds-prod-import-crm' ) );
	}

	if ( $file['size'] > 3 * 1024 * 1024 ) {
		return new \WP_Error( 'upload_size', __( 'Image must be 3MB or smaller.', 'ds-prod-import-crm' ) );
	}

	$mimes = crm_product_image_mimes();
	$check = wp_check_filetype( $file['name'], $mimes );
	if ( empty( $check['type'] ) ) {
		return new \WP_Error( 'upload_type', __( 'Only JPG, PNG, and WebP images are allowed.', 'ds-prod-import-crm' ) );
	}

	$upload = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => $mimes,
		)
	);

	if ( isset( $upload['error'] ) ) {
		return new \WP_Error( 'upload_error', $upload['error'] );
	}

	if ( empty( $upload['url'] ) || empty( $upload['file'] ) ) {
		return '';
	}

	$thumb = crm_create_square_thumbnail( $upload['file'] );
	$thumb_url = is_wp_error( $thumb ) ? '' : (string) $thumb;

	return array(
		'url'           => esc_url_raw( $upload['url'] ),
		'thumbnail_url' => esc_url_raw( $thumb_url ),
	);
}

/**
 * Upload a branding image (logo or favicon).
 *
 * @param string $file_key   $_FILES key.
 * @param array  $mime_types Allowed mime map for wp_check_filetype.
 * @return string|\WP_Error Image URL or error; empty string if no file.
 */
function crm_handle_brand_image_upload( $file_key, array $mime_types ) {
	if ( empty( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) ) {
		return '';
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$file = $_FILES[ $file_key ];

	if ( ! empty( $file['error'] ) ) {
		return new \WP_Error( 'upload_error', __( 'Image upload failed.', 'ds-prod-import-crm' ) );
	}

	if ( $file['size'] > 2 * 1024 * 1024 ) {
		return new \WP_Error( 'upload_size', __( 'Image must be 2MB or smaller.', 'ds-prod-import-crm' ) );
	}

	$check = wp_check_filetype( $file['name'], $mime_types );
	if ( empty( $check['type'] ) ) {
		return new \WP_Error( 'upload_type', __( 'Invalid image type for this field.', 'ds-prod-import-crm' ) );
	}

	$upload = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => $mime_types,
		)
	);

	if ( isset( $upload['error'] ) ) {
		return new \WP_Error( 'upload_error', $upload['error'] );
	}

	return isset( $upload['url'] ) ? esc_url_raw( $upload['url'] ) : '';
}

/**
 * Company branding for the public CRM app.
 *
 * @return array<string, string>
 */
function crm_get_branding() {
	$settings = crm_get_settings();
	$name     = trim( (string) ( $settings['company_name'] ?? '' ) );

	if ( '' === $name ) {
		$name = __( 'Product CRM', 'ds-prod-import-crm' );
	}

	$colors = crm_get_theme_colors();

	return array(
		'company_name'    => $name,
		'company_tagline' => trim( (string) ( $settings['company_tagline'] ?? '' ) ),
		'logo_url'        => esc_url( (string) ( $settings['company_logo_url'] ?? '' ) ),
		'favicon_url'     => esc_url( (string) ( $settings['favicon_url'] ?? '' ) ),
		'colors'          => $colors,
	);
}

/**
 * Sanitize a hex color for theme CSS.
 *
 * @param string $color   Raw input.
 * @param string $default Fallback when invalid.
 * @return string
 */
function crm_sanitize_hex_color( $color, $default = '#2563eb' ) {
	$color = trim( (string) $color );

	if ( '' === $color ) {
		return $default;
	}

	if ( '#' !== $color[0] ) {
		$color = '#' . $color;
	}

	if ( preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ) {
		return strtolower( $color );
	}

	return $default;
}

/**
 * Brand / theme colors from settings.
 *
 * @return array<string, string>
 */
function crm_get_theme_colors() {
	$settings = crm_get_settings();

	return array(
		'sidebar'        => crm_sanitize_hex_color( $settings['color_sidebar'] ?? '', '#1a1f2e' ),
		'accent'         => crm_sanitize_hex_color( $settings['color_accent'] ?? '', '#2563eb' ),
		'accent_secondary' => crm_sanitize_hex_color( $settings['color_accent_secondary'] ?? '', '#7c3aed' ),
	);
}

/**
 * Parse hex color to RGB components.
 *
 * @param string $hex Hex color.
 * @return array{r: int, g: int, b: int}
 */
function crm_hex_to_rgb( $hex ) {
	$hex = ltrim( crm_sanitize_hex_color( $hex, '#000000' ), '#' );

	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}

	return array(
		'r' => (int) hexdec( substr( $hex, 0, 2 ) ),
		'g' => (int) hexdec( substr( $hex, 2, 2 ) ),
		'b' => (int) hexdec( substr( $hex, 4, 2 ) ),
	);
}

/**
 * Darken a hex color (0–1 amount).
 *
 * @param string $hex    Hex color.
 * @param float  $amount Darken amount (e.g. 0.12 = 12% darker).
 * @return string
 */
function crm_color_darken( $hex, $amount = 0.12 ) {
	$rgb    = crm_hex_to_rgb( $hex );
	$factor = 1 - max( 0, min( 1, (float) $amount ) );

	return sprintf(
		'#%02x%02x%02x',
		(int) round( $rgb['r'] * $factor ),
		(int) round( $rgb['g'] * $factor ),
		(int) round( $rgb['b'] * $factor )
	);
}

/**
 * Mix hex color toward white (tint).
 *
 * @param string $hex         Hex color.
 * @param float  $white_ratio 0 = original, 1 = white.
 * @return string
 */
function crm_color_tint( $hex, $white_ratio = 0.9 ) {
	$rgb = crm_hex_to_rgb( $hex );
	$w   = max( 0, min( 1, (float) $white_ratio ) );

	return sprintf(
		'#%02x%02x%02x',
		(int) round( $rgb['r'] * ( 1 - $w ) + 255 * $w ),
		(int) round( $rgb['g'] * ( 1 - $w ) + 255 * $w ),
		(int) round( $rgb['b'] * ( 1 - $w ) + 255 * $w )
	);
}

/**
 * Hex color as rgba().
 *
 * @param string $hex   Hex color.
 * @param float  $alpha Alpha 0–1.
 * @return string
 */
function crm_color_alpha( $hex, $alpha = 0.15 ) {
	$rgb = crm_hex_to_rgb( $hex );

	return sprintf(
		'rgba(%d,%d,%d,%s)',
		$rgb['r'],
		$rgb['g'],
		$rgb['b'],
		max( 0, min( 1, (float) $alpha ) )
	);
}

/**
 * Inline CSS that maps saved colors to CRM CSS variables.
 *
 * @return string
 */
function crm_get_theme_inline_css() {
	$colors = crm_get_theme_colors();
	$accent = $colors['accent'];

	$rules = sprintf(
		'--ds-crm-sidebar:%1$s;--ds-crm-accent:%2$s;--ds-crm-accent-secondary:%3$s;--ds-crm-accent-hover:%4$s;--ds-crm-accent-soft:%5$s;--ds-crm-accent-muted-bg:%6$s;--ds-crm-accent-muted-border:%7$s;--ds-crm-accent-muted-text:%8$s;--ds-crm-accent-focus:%9$s;--ds-crm-accent-shadow:%10$s;',
		$colors['sidebar'],
		$accent,
		$colors['accent_secondary'],
		crm_color_darken( $accent, 0.14 ),
		crm_color_tint( $accent, 0.55 ),
		crm_color_tint( $accent, 0.92 ),
		crm_color_tint( $accent, 0.78 ),
		crm_color_darken( $accent, 0.42 ),
		crm_color_alpha( $accent, 0.18 ),
		crm_color_alpha( $accent, 0.12 )
	);

	return sprintf( ':root,.ds-crm-frontend-root{%s}', $rules );
}

/**
 * Inline CSS for branding the WordPress wp-login.php screen.
 *
 * @return string
 */
function crm_get_wp_login_css() {
	$branding = crm_get_branding();
	$colors   = $branding['colors'];
	$accent   = $colors['accent'];
	$accent_hover = crm_color_darken( $accent, 0.14 );
	$bg_top   = crm_color_tint( $colors['sidebar'], 0.88 );
	$bg_bottom = crm_color_tint( $accent, 0.94 );
	$focus_ring = crm_color_alpha( $accent, 0.2 );

	$rules = array(
		'body.login {',
		'background: linear-gradient(160deg, ' . $bg_top . ' 0%, ' . $bg_bottom . ' 55%, #f0f0f1 100%);',
		'}',
		'.login form {',
		'border: 1px solid ' . crm_color_tint( $accent, 0.72 ) . ';',
		'border-radius: 12px;',
		'box-shadow: 0 12px 40px ' . crm_color_alpha( $colors['sidebar'], 0.12 ) . ';',
		'}',
		'.login label {',
		'color: ' . crm_color_darken( $colors['sidebar'], 0.05 ) . ';',
		'}',
		'.login input[type="text"],',
		'.login input[type="password"] {',
		'border-radius: 8px;',
		'border-color: ' . crm_color_tint( $accent, 0.65 ) . ';',
		'}',
		'.login input[type="text"]:focus,',
		'.login input[type="password"]:focus {',
		'border-color: ' . $accent . ';',
		'box-shadow: 0 0 0 1px ' . $accent . ', 0 0 0 3px ' . $focus_ring . ';',
		'}',
		'.wp-core-ui .button-primary {',
		'background: ' . $accent . ';',
		'border-color: ' . $accent_hover . ';',
		'border-radius: 8px;',
		'box-shadow: none;',
		'text-shadow: none;',
		'}',
		'.wp-core-ui .button-primary:hover,',
		'.wp-core-ui .button-primary:focus {',
		'background: ' . $accent_hover . ';',
		'border-color: ' . crm_color_darken( $accent, 0.22 ) . ';',
		'}',
		'.login #nav a,',
		'.login #backtoblog a,',
		'.login .privacy-policy-page-link a {',
		'color: ' . $accent . ' !important;',
		'}',
		'.login #nav a:hover,',
		'.login #backtoblog a:hover,',
		'.login .privacy-policy-page-link a:hover {',
		'color: ' . $accent_hover . ' !important;',
		'}',
	);

	if ( ! empty( $branding['logo_url'] ) ) {
		$logo_url = esc_url( $branding['logo_url'] );
		$rules[]  = '.login h1 a {';
		$rules[]  = 'background-image: url("' . $logo_url . '");';
		$rules[]  = 'background-size: contain;';
		$rules[]  = 'background-position: center center;';
		$rules[]  = 'background-repeat: no-repeat;';
		$rules[]  = 'width: min(320px, 90vw);';
		$rules[]  = 'height: 88px;';
		$rules[]  = 'margin: 0 auto 20px;';
		$rules[]  = 'text-indent: -9999px;';
		$rules[]  = 'overflow: hidden;';
		$rules[]  = 'display: block;';
		$rules[]  = '}';
	}

	return implode( "\n", $rules );
}

/**
 * Plugin settings with defaults.
 *
 * @return array<string, mixed>
 */
function crm_get_settings() {
	$defaults = array(
		'frontend_page_id'    => 0,
		'low_stock_threshold' => 5,
		'currency_symbol'     => '৳',
		'company_name'        => '',
		'company_tagline'     => '',
		'company_logo_url'        => '',
		'favicon_url'             => '',
		'color_sidebar'           => '#1a1f2e',
		'color_accent'            => '#2563eb',
		'color_accent_secondary'  => '#7c3aed',
		'pricing_mode'            => 'single',
		'client_orders_scope'     => 'own',
		'shipments_module_label'  => __( 'China Export', 'ds-prod-import-crm' ),
		'china_timezone'          => 'Asia/Shanghai',
		'bangladesh_timezone'     => 'Asia/Dhaka',
		'tracking_show_dual_tz'   => 1,
	);

	$stored = get_option( 'ds_crm_settings', array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return array_merge( $defaults, $stored );
}

/**
 * Product pricing mode: single (one price) or dual (sell + purchase).
 *
 * @return string 'single'|'dual'
 */
function crm_pricing_mode() {
	$mode = crm_get_settings()['pricing_mode'] ?? 'dual';

	return 'single' === $mode ? 'single' : 'dual';
}

/**
 * Whether the CRM uses one catalog price everywhere.
 *
 * @return bool
 */
function crm_is_single_price_mode() {
	return 'single' === crm_pricing_mode();
}

/**
 * Sidebar / page label for the China export shipments module.
 *
 * @return string
 */
function crm_shipments_module_label() {
	$label = trim( (string) ( crm_get_settings()['shipments_module_label'] ?? '' ) );

	if ( '' === $label ) {
		return __( 'China Export', 'ds-prod-import-crm' );
	}

	return $label;
}

/**
 * Final accepted qty for a line (China approval). Falls back to ordered qty if not yet accepted.
 *
 * @param array<string, mixed> $row Order item row.
 * @return int
 */
function crm_order_item_accepted_qty( array $row ) {
	if ( array_key_exists( 'accepted_quantity', $row ) && null !== $row['accepted_quantity'] && '' !== $row['accepted_quantity'] ) {
		return max( 0, (int) $row['accepted_quantity'] );
	}

	return max( 0, (int) ( $row['quantity'] ?? 0 ) );
}

/**
 * Whether China has recorded an accepted quantity on this line.
 *
 * @param array<string, mixed> $row Order item row.
 * @return bool
 */
function crm_order_item_has_accepted_qty( array $row ) {
	return array_key_exists( 'accepted_quantity', $row )
		&& null !== $row['accepted_quantity']
		&& '' !== $row['accepted_quantity'];
}

/**
 * Allowed IANA timezones for CRM tracking display.
 *
 * @return array<string, string> timezone => label
 */
function crm_tracking_timezone_choices() {
	return array(
		'Asia/Dhaka'     => __( 'Bangladesh (Asia/Dhaka)', 'ds-prod-import-crm' ),
		'Asia/Shanghai'  => __( 'China (Asia/Shanghai)', 'ds-prod-import-crm' ),
		'Asia/Hong_Kong' => __( 'Hong Kong (Asia/Hong_Kong)', 'ds-prod-import-crm' ),
		'UTC'            => __( 'UTC', 'ds-prod-import-crm' ),
	);
}

/**
 * Sanitize a timezone setting against allowed choices (or WordPress known list).
 *
 * @param string $timezone Candidate timezone.
 * @param string $default  Fallback.
 * @return string
 */
function crm_sanitize_tracking_timezone( $timezone, $default = 'Asia/Dhaka' ) {
	$timezone = is_string( $timezone ) ? trim( $timezone ) : '';
	$allowed  = array_keys( crm_tracking_timezone_choices() );

	if ( in_array( $timezone, $allowed, true ) ) {
		return $timezone;
	}

	$wp_zones = function_exists( 'timezone_identifiers_list' ) ? timezone_identifiers_list() : array();
	if ( in_array( $timezone, $wp_zones, true ) ) {
		return $timezone;
	}

	return $default;
}

/**
 * Format a MySQL datetime in a specific timezone.
 *
 * @param string $mysql_datetime Local WP datetime or Y-m-d / Y-m-d H:i:s.
 * @param string $timezone       IANA timezone.
 * @param string $format         PHP date format.
 * @return string Empty if invalid.
 */
function crm_format_in_timezone( $mysql_datetime, $timezone, $format = 'd M Y, g:i A' ) {
	$mysql_datetime = trim( (string) $mysql_datetime );
	if ( '' === $mysql_datetime || '0000-00-00' === $mysql_datetime || '0000-00-00 00:00:00' === $mysql_datetime ) {
		return '';
	}

	if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $mysql_datetime ) ) {
		$mysql_datetime .= ' 12:00:00';
	}

	$tz_site = wp_timezone();
	try {
		$dt = new \DateTimeImmutable( $mysql_datetime, $tz_site );
		$dt = $dt->setTimezone( new \DateTimeZone( crm_sanitize_tracking_timezone( $timezone, 'UTC' ) ) );
	} catch ( \Exception $e ) {
		return '';
	}

	return $dt->format( $format );
}

/**
 * WordPress site timezone as an IANA name (for JS datetime parsing).
 *
 * @return string
 */
function crm_site_timezone_name() {
	$tz = wp_timezone();

	return $tz instanceof \DateTimeZone ? $tz->getName() : 'UTC';
}

/**
 * Timezone display config for the current CRM user.
 *
 * Bangladesh-Dhaka is the default for admin, staff, and clients.
 * China office users see China time as primary with BD as secondary.
 *
 * @return array<string, mixed>
 */
function crm_datetime_timezone_config() {
	$settings = crm_get_settings();
	$china_tz = crm_sanitize_tracking_timezone( (string) ( $settings['china_timezone'] ?? 'Asia/Shanghai' ), 'Asia/Shanghai' );
	$bd_tz    = crm_sanitize_tracking_timezone( (string) ( $settings['bangladesh_timezone'] ?? 'Asia/Dhaka' ), 'Asia/Dhaka' );
	$is_china = CRM_China_Office::is_china_office_user();

	return array(
		'site_timezone'       => crm_site_timezone_name(),
		'display_timezone'    => $is_china ? $china_tz : $bd_tz,
		'secondary_timezone'  => $is_china ? $bd_tz : $china_tz,
		'china_timezone'      => $china_tz,
		'bangladesh_timezone' => $bd_tz,
		'is_china_office'     => $is_china,
		'china_label'         => __( 'China', 'ds-prod-import-crm' ),
		'bangladesh_label'    => __( 'Bangladesh', 'ds-prod-import-crm' ),
	);
}

/**
 * Format a stored datetime in the current user's display timezone.
 *
 * @param string $mysql_datetime WP-local MySQL datetime.
 * @param string $format         PHP date format.
 * @return string
 */
function crm_format_datetime_for_user( $mysql_datetime, $format = 'd M Y, g:i A' ) {
	$config = crm_datetime_timezone_config();

	return crm_format_in_timezone( $mysql_datetime, (string) $config['display_timezone'], $format );
}

/**
 * Dual-timezone display payload for tracking timestamps.
 *
 * @param string $mysql_datetime WP-local MySQL datetime.
 * @return array<string, mixed>
 */
function crm_tracking_datetime_payload( $mysql_datetime ) {
	$settings     = crm_get_settings();
	$config       = crm_datetime_timezone_config();
	$china_tz     = (string) $config['china_timezone'];
	$bd_tz        = (string) $config['bangladesh_timezone'];
	$dual         = ! empty( $settings['tracking_show_dual_tz'] );
	$is_china     = ! empty( $config['is_china_office'] );
	$china_label  = (string) $config['china_label'];
	$bd_label     = (string) $config['bangladesh_label'];

	$china = crm_format_in_timezone( $mysql_datetime, $china_tz );
	$bd    = crm_format_in_timezone( $mysql_datetime, $bd_tz );

	if ( '' === $china && '' === $bd ) {
		return array(
			'raw'             => '',
			'primary'         => '',
			'secondary'       => '',
			'dual'            => false,
			'china'           => '',
			'bangladesh'      => '',
			'china_tz'        => $china_tz,
			'bd_tz'           => $bd_tz,
			'primary_label'   => '',
			'secondary_label' => '',
			'china_label'     => $china_label,
			'bd_label'        => $bd_label,
		);
	}

	if ( $is_china ) {
		$primary         = $china;
		$secondary       = $dual && $china !== $bd ? $bd : '';
		$primary_label   = $china_label;
		$secondary_label = $bd_label;
	} else {
		$primary         = $bd;
		$secondary       = $dual && $china !== $bd ? $china : '';
		$primary_label   = $bd_label;
		$secondary_label = $china_label;
	}

	return array(
		'raw'             => (string) $mysql_datetime,
		'primary'         => $primary,
		'secondary'       => $secondary,
		'dual'            => (bool) $dual,
		'china'           => $china,
		'bangladesh'      => $bd,
		'china_tz'        => $china_tz,
		'bd_tz'           => $bd_tz,
		'china_label'     => $china_label,
		'bd_label'        => $bd_label,
		'primary_label'   => $primary_label,
		'secondary_label' => $secondary_label,
	);
}

/**
 * Cost / import rate for warehouse receives (purchase in dual mode).
 *
 * @param array<string, mixed>|null $product Product row.
 * @return float
 */
function crm_product_cost_rate( $product ) {
	if ( ! is_array( $product ) ) {
		return 0.0;
	}

	if ( crm_is_single_price_mode() ) {
		return (float) ( $product['unit_price'] ?? 0 );
	}

	$purchase = (float) ( $product['purchase_rate'] ?? 0 );
	if ( $purchase > 0 ) {
		return $purchase;
	}

	return (float) ( $product['unit_price'] ?? 0 );
}

/**
 * When pricing mode changes, align stored product prices.
 *
 * @param string $old_mode Previous mode.
 * @param string $new_mode New mode.
 * @return void
 */
function crm_apply_pricing_mode_transition( $old_mode, $new_mode ) {
	$old_mode = 'single' === $old_mode ? 'single' : 'dual';
	$new_mode = 'single' === $new_mode ? 'single' : 'dual';

	if ( $old_mode === $new_mode ) {
		return;
	}

	global $wpdb;

	$table = crm_table( 'products' );

	if ( 'single' === $new_mode ) {
		// Dual → single: keep sell price; unify purchase to match.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET purchase_rate = unit_price" );
		return;
	}

	// Single → dual: the single price becomes sell price (unit_price).
	// Seed purchase cost from sell price so receives start consistent.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "UPDATE {$table} SET purchase_rate = unit_price" );
}

/**
 * Normalize product price fields on save.
 *
 * @param array<string, mixed> $data Product data with unit_price and purchase_rate.
 * @return array<string, mixed>
 */
function crm_normalize_product_prices_for_save( array $data ) {
	$unit_price = isset( $data['unit_price'] ) ? (float) $data['unit_price'] : 0;

	if ( crm_is_single_price_mode() ) {
		$data['unit_price']    = $unit_price;
		$data['purchase_rate'] = $unit_price;
	} else {
		$data['unit_price']    = $unit_price;
		$data['purchase_rate'] = isset( $data['purchase_rate'] ) ? (float) $data['purchase_rate'] : 0;
	}

	return $data;
}

/**
 * After a warehouse receive line, sync catalog cost/sell from rate.
 *
 * @param int   $product_id Product ID.
 * @param float $rate       Per-unit rate from receive line.
 * @return void
 */
function crm_sync_product_rate_from_receive( $product_id, $rate ) {
	$product_id = absint( $product_id );
	$rate       = (float) $rate;

	if ( $product_id < 1 || $rate <= 0 ) {
		return;
	}

	global $wpdb;

	$update = array(
		'updated_at' => current_time( 'mysql' ),
	);

	if ( crm_is_single_price_mode() ) {
		$update['unit_price']    = $rate;
		$update['purchase_rate'] = $rate;
		$formats                 = array( '%f', '%f', '%s' );
	} else {
		$update['purchase_rate'] = $rate;
		$formats                 = array( '%f', '%s' );
	}

	$wpdb->update(
		crm_table( 'products' ),
		$update,
		array( 'id' => $product_id ),
		$formats,
		array( '%d' )
	);
}

/**
 * Page ID where CRM shortcode is embedded.
 *
 * @return int
 */
function crm_get_frontend_page_id() {
	return absint( crm_get_settings()['frontend_page_id'] ?? 0 );
}

/**
 * Whether a published frontend CRM page is configured.
 *
 * @return bool
 */
function crm_frontend_is_ready() {
	$page_id = crm_get_frontend_page_id();

	return $page_id > 0 && 'publish' === get_post_status( $page_id );
}

/**
 * Public URL of the configured CRM page.
 *
 * @return string
 */
function crm_get_public_app_url() {
	if ( ! crm_frontend_is_ready() ) {
		return '';
	}

	$url = get_permalink( crm_get_frontend_page_id() );

	return $url ? $url : '';
}

/**
 * Base URL for CRM navigation.
 *
 * @param string $context admin|frontend|auto.
 * @return string
 */
function crm_get_app_base_url( $context = 'auto' ) {
	if ( 'auto' === $context ) {
		$context = is_admin() ? 'admin' : 'frontend';
	}

	if ( 'admin' === $context ) {
		return admin_url( 'admin.php?page=ds-prod-import-crm' );
	}

	if ( is_singular() ) {
		$permalink = get_permalink( get_queried_object_id() );
		if ( $permalink ) {
			return $permalink;
		}
	}

	$public_url = crm_get_public_app_url();
	if ( $public_url ) {
		return $public_url;
	}

	return home_url( '/' );
}

/**
 * @deprecated Use crm_get_public_app_url().
 *
 * @return string
 */
function crm_get_frontend_base_url() {
	$public_url = crm_get_public_app_url();

	if ( $public_url ) {
		return $public_url;
	}

	return crm_get_app_base_url( 'frontend' );
}

/**
 * Build a module URL on the CRM app.
 *
 * @param string $module  Module slug.
 * @param string $context admin|frontend|auto.
 * @return string
 */
function crm_module_url( $module = 'dashboard', $context = 'auto' ) {
	$module = sanitize_key( $module );

	if ( 'auto' === $context ) {
		$context = is_admin() ? 'admin' : 'frontend';
	}

	if ( 'admin' === $context ) {
		return add_query_arg(
			array(
				'page'       => 'ds-prod-import-crm',
				'crm_module' => $module,
			),
			admin_url( 'admin.php' )
		);
	}

	return add_query_arg( 'crm_module', $module, crm_get_app_base_url( 'frontend' ) );
}

/**
 * Full-page URL for creating or editing an order.
 *
 * @param int $order_id Order ID for edit; 0 for new.
 * @return string
 */
function crm_order_form_url( $order_id = 0 ) {
	$args = array(
		'crm_module'   => 'orders',
		'order_action' => $order_id > 0 ? 'edit' : 'new',
	);

	if ( $order_id > 0 ) {
		$args['order_id'] = absint( $order_id );
	}

	return add_query_arg( $args, crm_get_app_base_url( 'frontend' ) );
}

/**
 * Full-page URL for viewing a single order.
 *
 * @param int $order_id Order ID.
 * @return string
 */
function crm_order_view_url( $order_id ) {
	return add_query_arg(
		array(
			'crm_module'   => 'orders',
			'order_action' => 'view',
			'order_id'     => absint( $order_id ),
		),
		crm_get_app_base_url( 'frontend' )
	);
}

/**
 * Full-page URL for recording a new warehouse receive.
 *
 * @param int $shipment_id Optional China export shipment to receive against.
 * @return string
 */
function crm_receive_form_url( $shipment_id = 0 ) {
	$args = array(
		'crm_module'     => 'warehouse',
		'receive_action' => 'new',
	);

	if ( $shipment_id > 0 ) {
		$args['shipment_id'] = absint( $shipment_id );
	}

	return add_query_arg( $args, crm_get_app_base_url( 'frontend' ) );
}

/**
 * Quantity already received into stock for an export shipment line.
 *
 * @param int $export_shipment_item_id Export shipment item ID.
 * @return int
 */
function crm_shipment_item_qty_received( $export_shipment_item_id ) {
	global $wpdb;

	$export_shipment_item_id = absint( $export_shipment_item_id );
	if ( $export_shipment_item_id < 1 ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COALESCE(SUM(quantity), 0) FROM ' . crm_table( 'receive_items' ) . ' WHERE export_shipment_item_id = %d',
			$export_shipment_item_id
		)
	);
}

/**
 * Quantity marked missing for an export shipment line.
 *
 * @param int $export_shipment_item_id Export shipment item ID.
 * @return int
 */
function crm_shipment_item_qty_missing( $export_shipment_item_id ) {
	global $wpdb;

	$export_shipment_item_id = absint( $export_shipment_item_id );
	if ( $export_shipment_item_id < 1 ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COALESCE(SUM(missing_quantity), 0) FROM ' . crm_table( 'receive_items' ) . ' WHERE export_shipment_item_id = %d',
			$export_shipment_item_id
		)
	);
}

/**
 * Remaining qty still expected for an export shipment line.
 *
 * @param int $shipped   Qty on the shipment line.
 * @param int $received  Qty already received into stock.
 * @param int $missing   Qty already marked missing.
 * @return int
 */
function crm_shipment_item_qty_remaining( $shipped, $received = 0, $missing = 0 ) {
	return max( 0, (int) $shipped - (int) $received - (int) $missing );
}

/**
 * Aggregate receive progress for a China export shipment.
 *
 * @param int $shipment_id Export shipment ID.
 * @return array{qty_shipped:int,qty_received:int,qty_missing:int,qty_remaining:int,line_count:int,lines_pending:int}
 */
function crm_shipment_receive_progress( $shipment_id ) {
	global $wpdb;

	$shipment_id = absint( $shipment_id );
	$empty       = array(
		'qty_shipped'   => 0,
		'qty_received'  => 0,
		'qty_missing'   => 0,
		'qty_remaining' => 0,
		'line_count'    => 0,
		'lines_pending' => 0,
	);

	if ( $shipment_id < 1 ) {
		return $empty;
	}

	$items = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, quantity FROM ' . crm_table( 'export_shipment_items' ) . ' WHERE shipment_id = %d',
			$shipment_id
		),
		ARRAY_A
	);

	if ( ! $items ) {
		return $empty;
	}

	$qty_shipped   = 0;
	$qty_received  = 0;
	$qty_missing   = 0;
	$qty_remaining = 0;
	$lines_pending = 0;

	foreach ( $items as $item ) {
		$shipped   = (int) $item['quantity'];
		$received  = crm_shipment_item_qty_received( (int) $item['id'] );
		$missing   = crm_shipment_item_qty_missing( (int) $item['id'] );
		$remaining = crm_shipment_item_qty_remaining( $shipped, $received, $missing );

		$qty_shipped  += $shipped;
		$qty_received += $received;
		$qty_missing  += $missing;
		$qty_remaining += $remaining;

		if ( $remaining > 0 ) {
			++$lines_pending;
		}
	}

	return array(
		'qty_shipped'   => $qty_shipped,
		'qty_received'  => $qty_received,
		'qty_missing'   => $qty_missing,
		'qty_remaining' => $qty_remaining,
		'line_count'    => count( $items ),
		'lines_pending' => $lines_pending,
	);
}

/**
 * Sync export shipment status from warehouse receive progress.
 *
 * @param int $shipment_id Export shipment ID.
 * @return string|false Updated status, or false if unchanged/not found/void.
 */
function crm_sync_shipment_receive_status( $shipment_id ) {
	global $wpdb;

	$shipment_id = absint( $shipment_id );
	if ( $shipment_id < 1 ) {
		return false;
	}

	$table = crm_table( 'export_shipments' );
	$row   = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, status FROM {$table} WHERE id = %d", $shipment_id ),
		ARRAY_A
	);

	if ( ! $row || 'void' === $row['status'] ) {
		return false;
	}

	$progress = crm_shipment_receive_progress( $shipment_id );
	$new_status = 'in_transit';

	if ( $progress['qty_remaining'] < 1 && $progress['line_count'] > 0 ) {
		$new_status = 'received';
	} elseif ( $progress['qty_received'] > 0 || $progress['qty_missing'] > 0 ) {
		$new_status = 'partially_received';
	}

	if ( $new_status === $row['status'] ) {
		return $new_status;
	}

	$wpdb->update(
		$table,
		array(
			'status'     => $new_status,
			'updated_at' => current_time( 'mysql' ),
		),
		array( 'id' => $shipment_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	return $new_status;
}

/**
 * Whether a China export shipment is locked from Supply history qty changes
 * because BD warehouse has already received (or marked missing) any qty.
 *
 * @param int $shipment_id Export shipment ID.
 * @return bool
 */
function crm_shipment_is_warehouse_locked( $shipment_id ) {
	$shipment_id = absint( $shipment_id );
	if ( $shipment_id < 1 ) {
		return false;
	}

	global $wpdb;

	$receive_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . crm_table( 'warehouse_receives' ) . ' WHERE shipment_id = %d',
			$shipment_id
		)
	);

	if ( $receive_count > 0 ) {
		return true;
	}

	$progress = crm_shipment_receive_progress( $shipment_id );
	return $progress['qty_received'] > 0 || $progress['qty_missing'] > 0;
}

/**
 * Full-page URL for recording a new customer delivery.
 *
 * @param int $order_id Optional order to pre-select.
 * @return string
 */
function crm_delivery_form_url( $order_id = 0 ) {
	$args = array(
		'crm_module'      => 'delivery',
		'delivery_action' => 'new',
	);

	if ( $order_id > 0 ) {
		$args['order_id'] = absint( $order_id );
	}

	return add_query_arg( $args, crm_get_app_base_url( 'frontend' ) );
}

/**
 * Quantity already recorded on non-void export shipments for an order line.
 *
 * @param int $order_item_id Order item ID.
 * @return int
 */
function crm_order_item_qty_exported( $order_item_id ) {
	global $wpdb;

	$order_item_id = absint( $order_item_id );
	if ( $order_item_id < 1 ) {
		return 0;
	}

	$items_table = crm_table( 'export_shipment_items' );
	$ship_table  = crm_table( 'export_shipments' );

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(esi.quantity), 0) FROM {$items_table} esi
			INNER JOIN {$ship_table} es ON es.id = esi.shipment_id AND es.status != 'void'
			WHERE esi.order_item_id = %d",
			$order_item_id
		)
	);
}

/**
 * Total kg already recorded on non-void export shipments for an order line.
 *
 * @param int $order_item_id Order item ID.
 * @return float
 */
function crm_order_item_weight_exported( $order_item_id ) {
	global $wpdb;

	$order_item_id = absint( $order_item_id );
	if ( $order_item_id < 1 ) {
		return 0.0;
	}

	$items_table = crm_table( 'export_shipment_items' );
	$ship_table  = crm_table( 'export_shipments' );

	return crm_parse_weight(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(esi.weight_kg), 0) FROM {$items_table} esi
				INNER JOIN {$ship_table} es ON es.id = esi.shipment_id AND es.status != 'void'
				WHERE esi.order_item_id = %d",
				$order_item_id
			)
		)
	);
}

/**
 * Full-page URL for recording a China export shipment.
 *
 * @param int $order_id Optional order to pre-select.
 * @return string
 */
function crm_shipment_form_url( $order_id = 0 ) {
	$args = array(
		'crm_module'      => 'shipments',
		'shipment_action' => 'new',
	);

	if ( $order_id > 0 ) {
		$args['order_id'] = absint( $order_id );
	}

	return add_query_arg( $args, crm_get_app_base_url( 'frontend' ) );
}

/**
 * Full-page URL for a company / supplier ledger.
 *
 * @param int $company_id Company ID.
 * @return string
 */
function crm_company_ledger_url( $company_id ) {
	return add_query_arg(
		array(
			'crm_module'      => 'companies',
			'company_action'  => 'ledger',
			'company_id'      => absint( $company_id ),
		),
		crm_get_app_base_url( 'frontend' )
	);
}

/**
 * Payments module URL, optionally opening the supplier tab.
 *
 * @param string $tab     clients|suppliers.
 * @param int    $company_id Optional company to preselect on supplier form.
 * @return string
 */
function crm_payments_url( $tab = 'clients', $company_id = 0 ) {
	$args = array(
		'crm_module' => 'payments',
	);

	if ( 'suppliers' === $tab ) {
		$args['payments_tab'] = 'suppliers';
	}

	if ( $company_id > 0 ) {
		$args['company_id'] = absint( $company_id );
	}

	return add_query_arg( $args, crm_get_app_base_url( 'frontend' ) );
}

/**
 * Allowed product image mime types.
 *
 * @return array<string, string>
 */
function crm_product_image_mimes() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);
}

/**
 * Ensure the system "Uncategorized" category exists.
 *
 * @return int Category ID.
 */
function crm_ensure_uncategorized_category() {
	global $wpdb;

	$table = crm_table( 'product_categories' );
	$id    = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE is_system = 1 OR name = %s ORDER BY is_system DESC, id ASC LIMIT 1",
			'Uncategorized'
		)
	);

	if ( $id > 0 ) {
		$wpdb->update(
			$table,
			array(
				'name'       => 'Uncategorized',
				'status'     => 'active',
				'is_system'  => 1,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return $id;
	}

	$wpdb->insert(
		$table,
		array(
			'name'       => 'Uncategorized',
			'description'=> '',
			'status'     => 'active',
			'is_system'  => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

/**
 * Uncategorized category ID (creates if missing).
 *
 * @return int
 */
function crm_uncategorized_category_id() {
	return crm_ensure_uncategorized_category();
}

/**
 * Best image URL for list thumbnails.
 *
 * @param array<string, mixed>|object $product Product row.
 * @return string
 */
function crm_product_thumb_url( $product ) {
	$row = (array) $product;
	if ( ! empty( $row['thumbnail_url'] ) ) {
		return (string) $row['thumbnail_url'];
	}
	return ! empty( $row['image_url'] ) ? (string) $row['image_url'] : '';
}

/**
 * SQL expression for the best product image URL (thumbnail preferred).
 *
 * @param string $alias Products table alias.
 * @return string
 */
function crm_sql_product_image_url( $alias = 'p' ) {
	$alias = preg_replace( '/[^a-zA-Z0-9_]/', '', $alias );
	return "COALESCE(NULLIF({$alias}.thumbnail_url, ''), {$alias}.image_url)";
}

/**
 * Sanitize SKU string.
 *
 * @param string $sku Raw SKU.
 * @return string
 */
function crm_sanitize_sku( $sku ) {
	$sku = strtoupper( trim( sanitize_text_field( $sku ) ) );
	return preg_replace( '/\s+/', '-', $sku );
}

/**
 * Generate square thumbnail from uploaded image file.
 *
 * @param string $file_path Absolute path to source image.
 * @param int    $size      Thumbnail size in pixels.
 * @return string|\WP_Error Thumbnail URL or error.
 */
function crm_create_square_thumbnail( $file_path, $size = 120 ) {
	if ( ! file_exists( $file_path ) ) {
		return new \WP_Error( 'thumb_missing', __( 'Image file not found for thumbnail.', 'ds-prod-import-crm' ) );
	}

	$editor = wp_get_image_editor( $file_path );
	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$editor->resize( $size, $size, true );
	$saved = $editor->save();
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $saved['path'] ) && ! empty( $upload_dir['basedir'] ) && 0 === strpos( $saved['path'], $upload_dir['basedir'] ) ) {
		$relative = ltrim( str_replace( $upload_dir['basedir'], '', $saved['path'] ), '/' );
		return trailingslashit( $upload_dir['baseurl'] ) . $relative;
	}

	return ! empty( $saved['url'] ) ? $saved['url'] : '';
}

/**
 * wp-admin URL for CRM configuration screens.
 *
 * @param string $section Optional section slug.
 * @return string
 */
function crm_admin_settings_url( $section = '' ) {
	$args = array( 'page' => 'ds-prod-import-crm' );

	if ( $section ) {
		$args['crm_section'] = sanitize_key( $section );
	}

	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Whether CRM is rendering on the public frontend page.
 *
 * @return bool
 */
function crm_is_frontend_request() {
	if ( is_admin() ) {
		return false;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$page_id = crm_get_frontend_page_id();
	if ( ! $page_id ) {
		return false;
	}

	return (int) get_queried_object_id() === (int) $page_id;
}

/**
 * Primary shortcode string for copy/paste.
 *
 * @return string
 */
function crm_shortcode_tag() {
	return 'ds_prod_import_crm';
}

/**
 * Full shortcode including brackets.
 *
 * @return string
 */
function crm_shortcode_example() {
	return '[' . crm_shortcode_tag() . ']';
}

/**
 * Load a catalog product by ID.
 *
 * @param int $product_id Product ID.
 * @return array<string, mixed>|null
 */
function crm_get_catalog_product( $product_id ) {
	global $wpdb;

	$product_id = absint( $product_id );
	if ( $product_id < 1 ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, name, sku, unit_price, purchase_rate, color, size, image_url, thumbnail_url FROM ' . crm_table( 'products' ) . ' WHERE id = %d',
			$product_id
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Count deliveries recorded for an order.
 *
 * @param int $order_id Order ID.
 * @return int
 */
function crm_count_order_deliveries( $order_id ) {
	global $wpdb;

	$order_id = absint( $order_id );
	if ( $order_id < 1 ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . crm_table( 'deliveries' ) . ' WHERE order_id = %d',
			$order_id
		)
	);
}

/**
 * Count payments linked to an order.
 *
 * @param int $order_id Order ID.
 * @return int
 */
function crm_count_order_payments( $order_id ) {
	global $wpdb;

	$order_id = absint( $order_id );
	if ( $order_id < 1 ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . crm_table( 'payments' ) . ' WHERE order_id = %d',
			$order_id
		)
	);
}

/**
 * Whether a warehouse receive can be voided (enough stock on hand per line).
 *
 * @param int $receive_id Receive ID.
 * @return true|\WP_Error
 */
function crm_receive_can_void( $receive_id ) {
	global $wpdb;

	$receive_id = absint( $receive_id );
	if ( $receive_id < 1 ) {
		return new \WP_Error( 'invalid_receive', __( 'Invalid receive ID.', 'ds-prod-import-crm' ) );
	}

	$items = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT product_id, product_name, color, size, quantity FROM ' . crm_table( 'receive_items' ) . ' WHERE receive_id = %d AND quantity > 0',
			$receive_id
		),
		ARRAY_A
	);

	if ( ! $items ) {
		return new \WP_Error( 'receive_empty', __( 'Receive has no items.', 'ds-prod-import-crm' ) );
	}

	foreach ( $items as $item ) {
		$need = (int) $item['quantity'];
		$avail = CRM_Stock::get_availability(
			(int) ( $item['product_id'] ?? 0 ),
			$item['product_name'],
			$item['color'] ?? '',
			$item['size'] ?? ''
		);

		if ( $avail['total'] < $need ) {
			return new \WP_Error(
				'receive_void_stock',
				sprintf(
					/* translators: 1: product name, 2: available, 3: received qty */
					__( 'Cannot void: %1$s — only %2$d in stock but receive added %3$d (already used in deliveries).', 'ds-prod-import-crm' ),
					$item['product_name'],
					$avail['total'],
					$need
				)
			);
		}
	}

	return true;
}

/**
 * Attach product name/image previews to order list rows.
 *
 * @param array<int, array<string, mixed>> $orders Order rows.
 * @return void
 */
function crm_attach_order_product_previews( array &$orders ) {
	if ( empty( $orders ) ) {
		return;
	}

	global $wpdb;

	$order_ids = array_map( 'absint', wp_list_pluck( $orders, 'id' ) );
	$order_ids = array_filter( $order_ids );
	if ( empty( $order_ids ) ) {
		return;
	}

	$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
	$items_table  = crm_table( 'order_items' );
	$products_tbl = crm_table( 'products' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$sql = "SELECT oi.order_id, oi.id AS order_item_id, oi.product_name, oi.product_id, oi.color, oi.size, oi.quantity, oi.delivery_priority,
		COALESCE(NULLIF(p.thumbnail_url, ''), p.image_url) AS image_url,
		NULLIF(p.image_url, '') AS full_image_url,
		COALESCE((
			SELECT SUM(di.quantity) FROM " . crm_table( 'delivery_items' ) . " di WHERE di.order_item_id = oi.id
		), 0) AS qty_delivered
		FROM {$items_table} oi
		LEFT JOIN {$products_tbl} p ON p.id = oi.product_id
		WHERE oi.order_id IN ({$placeholders})
		ORDER BY oi.order_id ASC, " . \DsProdImportCRM\CRM_Order_Item_Priority::sql_order_by( 'oi' );

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_ids ), ARRAY_A );

	$grouped = array();
	foreach ( $rows ? $rows : array() as $row ) {
		$oid = (int) $row['order_id'];
		if ( ! isset( $grouped[ $oid ] ) ) {
			$grouped[ $oid ] = array();
		}
		if ( count( $grouped[ $oid ] ) >= 4 ) {
			continue;
		}
		$qty         = (int) ( $row['quantity'] ?? 0 );
		$delivered   = (int) ( $row['qty_delivered'] ?? 0 );
		$grouped[ $oid ][] = array(
			'name'              => $row['product_name'],
			'color'             => $row['color'] ?? '',
			'size'              => $row['size'] ?? '',
			'quantity'          => $qty,
			'qty_delivered'     => $delivered,
			'qty_due'           => max( 0, $qty - $delivered ),
			'delivery_priority' => \DsProdImportCRM\CRM_Order_Item_Priority::sanitize( $row['delivery_priority'] ?? 'normal' ),
			'image_url'         => $row['image_url'] ? esc_url_raw( $row['image_url'] ) : '',
			'full_image_url'    => ! empty( $row['full_image_url'] ) ? esc_url_raw( $row['full_image_url'] ) : ( $row['image_url'] ? esc_url_raw( $row['image_url'] ) : '' ),
		);
	}

	foreach ( $orders as &$order ) {
		$oid                      = (int) $order['id'];
		$order['product_preview'] = isset( $grouped[ $oid ] ) ? $grouped[ $oid ] : array();
	}
	unset( $order );
}

/**
 * Attach product previews to warehouse receive list rows.
 *
 * @param array<int, array<string, mixed>> $receives Receive rows.
 * @return void
 */
function crm_attach_receive_product_previews( array &$receives ) {
	if ( empty( $receives ) ) {
		return;
	}

	global $wpdb;

	$ids = array_filter( array_map( 'absint', wp_list_pluck( $receives, 'id' ) ) );
	if ( empty( $ids ) ) {
		return;
	}

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$items_table  = crm_table( 'receive_items' );
	$products_tbl = crm_table( 'products' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$sql = "SELECT ri.receive_id, ri.product_name, ri.product_id, ri.color, ri.size, ri.quantity,
		" . crm_sql_product_image_url( 'p' ) . " AS image_url
		FROM {$items_table} ri
		LEFT JOIN {$products_tbl} p ON p.id = ri.product_id
		WHERE ri.receive_id IN ({$placeholders})
		ORDER BY ri.receive_id ASC, ri.id ASC";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
	crm_group_line_product_previews( $receives, $rows, 'receive_id' );
}

/**
 * Attach product previews to delivery list rows.
 *
 * @param array<int, array<string, mixed>> $deliveries Delivery rows.
 * @return void
 */
function crm_attach_delivery_product_previews( array &$deliveries ) {
	if ( empty( $deliveries ) ) {
		return;
	}

	global $wpdb;

	$ids = array_filter( array_map( 'absint', wp_list_pluck( $deliveries, 'id' ) ) );
	if ( empty( $ids ) ) {
		return;
	}

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$items_table  = crm_table( 'delivery_items' );
	$order_items  = crm_table( 'order_items' );
	$products_tbl = crm_table( 'products' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$sql = "SELECT di.delivery_id, di.product_name, di.color, di.size, di.quantity, oi.product_id,
		" . crm_sql_product_image_url( 'p' ) . " AS image_url
		FROM {$items_table} di
		LEFT JOIN {$order_items} oi ON oi.id = di.order_item_id
		LEFT JOIN {$products_tbl} p ON p.id = oi.product_id
		WHERE di.delivery_id IN ({$placeholders})
		ORDER BY di.delivery_id ASC, di.id ASC";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
	crm_group_line_product_previews( $deliveries, $rows, 'delivery_id' );
}

/**
 * Attach product preview thumbnails to export shipment list rows.
 *
 * @param array<int, array<string, mixed>> $shipments Shipment rows.
 * @return void
 */
function crm_attach_export_shipment_product_previews( array &$shipments ) {
	if ( empty( $shipments ) ) {
		return;
	}

	global $wpdb;

	$ids = array_filter( array_map( 'absint', wp_list_pluck( $shipments, 'id' ) ) );
	if ( empty( $ids ) ) {
		return;
	}

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$items_table  = crm_table( 'export_shipment_items' );
	$order_items  = crm_table( 'order_items' );
	$products_tbl = crm_table( 'products' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$sql = "SELECT esi.shipment_id, esi.product_name, esi.color, esi.size, esi.quantity, oi.product_id, oi.delivery_priority,
		" . crm_sql_product_image_url( 'p' ) . " AS image_url,
		NULLIF(p.image_url, '') AS full_image_url
		FROM {$items_table} esi
		LEFT JOIN {$order_items} oi ON oi.id = esi.order_item_id
		LEFT JOIN {$products_tbl} p ON p.id = oi.product_id
		WHERE esi.shipment_id IN ({$placeholders})
		ORDER BY esi.shipment_id ASC, esi.id ASC";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

	$grouped = array();
	foreach ( $rows ? $rows : array() as $row ) {
		$pid = (int) ( $row['shipment_id'] ?? 0 );
		if ( $pid < 1 ) {
			continue;
		}
		if ( ! isset( $grouped[ $pid ] ) ) {
			$grouped[ $pid ] = array();
		}
		if ( count( $grouped[ $pid ] ) >= 4 ) {
			continue;
		}
		$grouped[ $pid ][] = array(
			'name'              => $row['product_name'],
			'color'             => $row['color'] ?? '',
			'size'              => $row['size'] ?? '',
			'quantity'          => (int) ( $row['quantity'] ?? 0 ),
			'delivery_priority' => \DsProdImportCRM\CRM_Order_Item_Priority::sanitize( $row['delivery_priority'] ?? 'normal' ),
			'image_url'         => ! empty( $row['image_url'] ) ? esc_url_raw( $row['image_url'] ) : '',
			'full_image_url'    => ! empty( $row['full_image_url'] ) ? esc_url_raw( $row['full_image_url'] ) : ( ! empty( $row['image_url'] ) ? esc_url_raw( $row['image_url'] ) : '' ),
		);
	}

	foreach ( $shipments as &$shipment ) {
		$sid                        = (int) $shipment['id'];
		$shipment['product_preview'] = isset( $grouped[ $sid ] ) ? $grouped[ $sid ] : array();
	}
	unset( $shipment );
}

/**
 * Group line rows into product_preview arrays on parent list rows.
 *
 * @param array<int, array<string, mixed>> $parents     Parent rows (by reference).
 * @param array<int, array<string, mixed>> $line_rows   Line rows from SQL.
 * @param string                           $parent_key  FK column on line rows.
 * @return void
 */
function crm_group_line_product_previews( array &$parents, array $line_rows, $parent_key ) {
	$grouped = array();

	foreach ( $line_rows as $row ) {
		$pid = (int) ( $row[ $parent_key ] ?? 0 );
		if ( $pid < 1 ) {
			continue;
		}
		if ( ! isset( $grouped[ $pid ] ) ) {
			$grouped[ $pid ] = array();
		}
		if ( count( $grouped[ $pid ] ) >= 4 ) {
			continue;
		}
		$grouped[ $pid ][] = array(
			'name'      => $row['product_name'],
			'color'     => $row['color'] ?? '',
			'size'      => $row['size'] ?? '',
			'quantity'  => (int) ( $row['quantity'] ?? 0 ),
			'image_url' => ! empty( $row['image_url'] ) ? esc_url_raw( $row['image_url'] ) : '',
		);
	}

	foreach ( $parents as &$parent ) {
		$pid                     = (int) ( $parent['id'] ?? 0 );
		$parent['product_preview'] = isset( $grouped[ $pid ] ) ? $grouped[ $pid ] : array();
	}
	unset( $parent );
}

/**
 * Create a catalog product from an order line (quick add).
 *
 * @param array<string, mixed> $item       Parsed line item.
 * @param int                  $line_index Zero-based line index for file upload field.
 * @return int|\WP_Error Product ID or error.
 */
function crm_create_product_from_order_line( array $item, $line_index ) {
	global $wpdb;

	if ( ! current_user_can( 'crm_orders_create' ) && ! current_user_can( 'crm_products_create' ) ) {
		return new \WP_Error( 'forbidden', __( 'You cannot add products.', 'ds-prod-import-crm' ) );
	}

	$name = isset( $item['product_name'] ) ? sanitize_text_field( $item['product_name'] ) : '';
	if ( '' === $name ) {
		return new \WP_Error( 'name_required', __( 'Product name is required.', 'ds-prod-import-crm' ) );
	}

	$table = crm_table( 'products' );

	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table} WHERE LOWER(name) = LOWER(%s) LIMIT 1",
			$name
		)
	);

	if ( $existing_id > 0 ) {
		return $existing_id;
	}

	$field_key = 'new_product_image_' . absint( $line_index );
	$upload    = crm_handle_product_image_upload( $field_key );

	if ( is_wp_error( $upload ) ) {
		return $upload;
	}

	if ( '' === $upload ) {
		return new \WP_Error(
			'image_required',
			sprintf(
				/* translators: %d: line number */
				__( 'Line %d: add a product image for new products.', 'ds-prod-import-crm' ),
				$line_index + 1
			)
		);
	}

	$image_url     = is_array( $upload ) ? ( $upload['url'] ?? '' ) : (string) $upload;
	$thumbnail_url = is_array( $upload ) ? ( $upload['thumbnail_url'] ?? '' ) : '';

	$category_id = isset( $item['category_id'] ) ? absint( $item['category_id'] ) : 0;
	if ( $category_id > 0 ) {
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . crm_table( 'product_categories' ) . ' WHERE id = %d AND status = %s',
				$category_id,
				'active'
			)
		);
		if ( ! $exists ) {
			$category_id = 0;
		}
	}

	$unit_price = isset( $item['unit_price'] ) ? crm_parse_amount( $item['unit_price'] ) : 0;
	$sku        = isset( $item['sku'] ) ? crm_sanitize_sku( $item['sku'] ) : '';
	if ( '' === $sku ) {
		$sku = 'ORD-' . strtoupper( substr( md5( $name . microtime( true ) ), 0, 8 ) );
	} else {
		$duplicate_sku = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE sku = %s",
				$sku
			)
		);
		if ( $duplicate_sku > 0 ) {
			return new \WP_Error(
				'sku_duplicate',
				sprintf(
					/* translators: %d: line number */
					__( 'Line %d: SKU "%s" is already used by another product.', 'ds-prod-import-crm' ),
					$line_index + 1,
					$sku
				)
			);
		}
	}

	$price_data = crm_normalize_product_prices_for_save(
		array(
			'unit_price'    => $unit_price,
			'purchase_rate' => $unit_price,
		)
	);

	$inserted = $wpdb->insert(
		$table,
		array(
			'name'          => $name,
			'sku'           => $sku,
			'category_id'   => $category_id,
			'description'   => '',
			'unit_price'    => $price_data['unit_price'],
			'purchase_rate' => $price_data['purchase_rate'],
			'color'         => isset( $item['color'] ) ? sanitize_text_field( $item['color'] ) : '',
			'size'          => isset( $item['size'] ) ? sanitize_text_field( $item['size'] ) : '',
			'image_url'     => $image_url,
			'thumbnail_url' => $thumbnail_url,
			'created_by'    => CRM_Audit::current_user_id(),
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%d', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	if ( ! $inserted ) {
		return new \WP_Error( 'product_create_failed', __( 'Failed to create product.', 'ds-prod-import-crm' ) );
	}

	$new_id = (int) $wpdb->insert_id;
	CRM_Audit::log( 'create', 'products', $new_id, sprintf( 'Quick-added from order: %s', $name ) );

	return $new_id;
}

/**
 * Sync aggregated delivery shipping bill to client_bills for an order.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function crm_sync_client_order_shipping_bill( $order_id ) {
	global $wpdb;

	$order_id = absint( $order_id );
	if ( $order_id < 1 ) {
		return;
	}

	$orders_table     = crm_table( 'orders' );
	$deliveries_table = crm_table( 'deliveries' );
	$bills_table      = crm_table( 'client_bills' );

	$order = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, client_id FROM {$orders_table} WHERE id = %d", $order_id ),
		ARRAY_A
	);

	if ( ! $order || empty( $order['client_id'] ) ) {
		return;
	}

	$amount = (float) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COALESCE(SUM(shipping_bill), 0) FROM {$deliveries_table} WHERE order_id = %d",
			$order_id
		)
	);
	$amount = crm_parse_amount( $amount );

	$bill_date = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(delivery_date) FROM {$deliveries_table} WHERE order_id = %d",
			$order_id
		)
	);
	if ( ! $bill_date ) {
		$bill_date = gmdate( 'Y-m-d' );
	}

	$existing_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$bills_table} WHERE order_id = %d AND bill_type = 'shipping_bill' LIMIT 1",
			$order_id
		)
	);

	if ( $amount <= 0 ) {
		if ( $existing_id > 0 ) {
			$wpdb->delete( $bills_table, array( 'id' => $existing_id ), array( '%d' ) );
		}
		return;
	}

	$data = array(
		'client_id'  => (int) $order['client_id'],
		'order_id'   => $order_id,
		'bill_date'  => $bill_date,
		'bill_type'  => 'shipping_bill',
		'amount'     => $amount,
		'notes'      => sprintf( 'Delivery shipping for order #%d', $order_id ),
		'created_by' => CRM_Audit::current_user_id(),
	);

	if ( $existing_id > 0 ) {
		$wpdb->update(
			$bills_table,
			array(
				'amount'    => $amount,
				'bill_date' => $bill_date,
			),
			array( 'id' => $existing_id ),
			array( '%f', '%s' ),
			array( '%d' )
		);
		return;
	}

	$data['created_at'] = current_time( 'mysql' );
	$wpdb->insert(
		$bills_table,
		$data,
		array( '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%s' )
	);
}

/**
 * Backfill client delivery bills from existing deliveries.
 *
 * @return void
 */
function crm_backfill_client_delivery_bills() {
	global $wpdb;

	$order_ids = $wpdb->get_col( 'SELECT DISTINCT order_id FROM ' . crm_table( 'deliveries' ) . ' WHERE order_id > 0' );
	if ( ! $order_ids ) {
		return;
	}

	foreach ( $order_ids as $order_id ) {
		crm_sync_client_order_shipping_bill( (int) $order_id );
	}
}
