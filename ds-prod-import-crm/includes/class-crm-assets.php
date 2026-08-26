<?php
/**
 * Admin and frontend assets registration.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Handles script/style enqueue.
 */
class CRM_Assets {
	/**
	 * Asset version.
	 */
	const VERSION = '0.34.2';

	/**
	 * Timezone config for JavaScript (camelCase keys).
	 *
	 * @return array<string, mixed>
	 */
	private static function js_timezone_config() {
		$config = crm_datetime_timezone_config();

		return array(
			'siteTimezone'      => (string) ( $config['site_timezone'] ?? 'Asia/Dhaka' ),
			'displayTimezone'   => (string) ( $config['display_timezone'] ?? 'Asia/Dhaka' ),
			'secondaryTimezone' => (string) ( $config['secondary_timezone'] ?? 'Asia/Shanghai' ),
			'chinaTimezone'     => (string) ( $config['china_timezone'] ?? 'Asia/Shanghai' ),
			'bangladeshTimezone'=> (string) ( $config['bangladesh_timezone'] ?? 'Asia/Dhaka' ),
			'isChinaOffice'     => ! empty( $config['is_china_office'] ),
			'chinaLabel'        => (string) ( $config['china_label'] ?? __( 'China', 'ds-prod-import-crm' ) ),
			'bangladeshLabel'   => (string) ( $config['bangladesh_label'] ?? __( 'Bangladesh', 'ds-prod-import-crm' ) ),
		);
	}

	/**
	 * Hook enqueue actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	/**
	 * Enqueue on wp-admin CRM screen.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public static function enqueue_admin( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'ds-prod-import-crm' ) ) {
			return;
		}

		self::enqueue_wp_admin_settings();
	}

	/**
	 * Lightweight assets for wp-admin CRM Settings (not the full frontend app bundle).
	 *
	 * @return void
	 */
	public static function enqueue_wp_admin_settings() {
		wp_enqueue_media();

		wp_enqueue_style(
			'ds-crm-admin-settings',
			DS_CRM_URL . 'assets/css/crm-admin-settings.css',
			array(),
			self::VERSION
		);

		wp_enqueue_script(
			'ds-crm-main',
			DS_CRM_URL . 'assets/js/crm-main.js',
			array( 'jquery' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-settings',
			DS_CRM_URL . 'assets/js/crm-settings.js',
			array( 'ds-crm-main', 'jquery', 'media-editor', 'media-views' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-admin-data-reset',
			DS_CRM_URL . 'assets/js/crm-admin-data-reset.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'ds-crm-main',
			'DsCrmApp',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'crm_nonce' ),
				'useWpMedia'   => true,
				'isFrontend'   => false,
				'timezone'     => self::js_timezone_config(),
				'i18n'         => array(
					'selectLogo'    => __( 'Select logo', 'ds-prod-import-crm' ),
					'selectFavicon' => __( 'Select favicon', 'ds-prod-import-crm' ),
					'changeMedia'   => __( 'Change image', 'ds-prod-import-crm' ),
					'copied'        => __( 'Shortcode copied.', 'ds-prod-import-crm' ),
				),
			)
		);
	}

	/**
	 * Assets for the public CRM login screen.
	 *
	 * @return void
	 */
	public static function enqueue_login() {
		wp_enqueue_style(
			'ds-crm-main',
			DS_CRM_URL . 'assets/css/crm-main.css',
			array(),
			self::VERSION
		);

		wp_enqueue_style(
			'ds-crm-frontend',
			DS_CRM_URL . 'assets/css/crm-frontend.css',
			array( 'ds-crm-main' ),
			self::VERSION
		);

		wp_add_inline_style( 'ds-crm-frontend', crm_get_theme_inline_css() );

		wp_enqueue_script(
			'ds-crm-login',
			DS_CRM_URL . 'assets/js/crm-login.js',
			array(),
			self::VERSION,
			true
		);

		wp_localize_script(
			'ds-crm-login',
			'DsCrmLogin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'crm_login' ),
				'loggingIn' => __( 'Signing in…', 'ds-prod-import-crm' ),
				'failed'    => __( 'Login failed. Please try again.', 'ds-prod-import-crm' ),
			)
		);
	}

	/**
	 * Enqueue CRM styles and scripts.
	 *
	 * @param string $context admin|frontend.
	 * @return void
	 */
	public static function enqueue_app( $context = 'admin' ) {
		static $enqueued = false;

		if ( $enqueued ) {
			return;
		}

		$enqueued = true;

		wp_enqueue_style(
			'ds-crm-main',
			DS_CRM_URL . 'assets/css/crm-main.css',
			array(),
			self::VERSION
		);

		$theme_css = crm_get_theme_inline_css();
		wp_add_inline_style( 'ds-crm-main', $theme_css );

		if ( 'frontend' === $context ) {
			wp_enqueue_style(
				'ds-crm-frontend',
				DS_CRM_URL . 'assets/css/crm-frontend.css',
				array( 'ds-crm-main' ),
				self::VERSION
			);
			wp_add_inline_style( 'ds-crm-frontend', $theme_css );
		}

		wp_enqueue_style(
			'ds-crm-print',
			DS_CRM_URL . 'assets/css/crm-print.css',
			array( 'ds-crm-main' ),
			self::VERSION,
			'print'
		);

		wp_enqueue_script(
			'chart-js',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);

		wp_enqueue_script(
			'ds-crm-main',
			DS_CRM_URL . 'assets/js/crm-main.js',
			array(),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-dashboard',
			DS_CRM_URL . 'assets/js/crm-dashboard.js',
			array( 'ds-crm-main', 'chart-js' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-companies',
			DS_CRM_URL . 'assets/js/crm-companies.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-company-ledger',
			DS_CRM_URL . 'assets/js/crm-company-ledger.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-clients',
			DS_CRM_URL . 'assets/js/crm-clients.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-image-crop',
			DS_CRM_URL . 'assets/js/crm-image-crop.js',
			array(),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-products',
			DS_CRM_URL . 'assets/js/crm-products.js',
			array( 'ds-crm-main', 'ds-crm-image-crop' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-product-categories',
			DS_CRM_URL . 'assets/js/crm-product-categories.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-warehouse',
			DS_CRM_URL . 'assets/js/crm-warehouse.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-receive-form',
			DS_CRM_URL . 'assets/js/crm-receive-form.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-orders',
			DS_CRM_URL . 'assets/js/crm-orders.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-order-form',
			DS_CRM_URL . 'assets/js/crm-order-form.js',
			array( 'ds-crm-main', 'ds-crm-image-crop' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-order-view',
			DS_CRM_URL . 'assets/js/crm-order-view.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-delivery',
			DS_CRM_URL . 'assets/js/crm-delivery.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-delivery-form',
			DS_CRM_URL . 'assets/js/crm-delivery-form.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-shipments',
			DS_CRM_URL . 'assets/js/crm-shipments.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-shipment-form',
			DS_CRM_URL . 'assets/js/crm-shipment-form.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-payments',
			DS_CRM_URL . 'assets/js/crm-payments.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-order-statuses',
			DS_CRM_URL . 'assets/js/crm-order-statuses.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-settings',
			DS_CRM_URL . 'assets/js/crm-settings.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-reports',
			DS_CRM_URL . 'assets/js/crm-reports.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-team',
			DS_CRM_URL . 'assets/js/crm-team.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_enqueue_script(
			'ds-crm-activity',
			DS_CRM_URL . 'assets/js/crm-activity.js',
			array( 'ds-crm-main' ),
			self::VERSION,
			true
		);

		wp_localize_script(
			'ds-crm-main',
			'DsCrmApp',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'crm_nonce' ),
				'moduleBaseUrl' => 'frontend' === $context
					? crm_get_app_base_url( 'frontend' )
					: crm_get_public_app_url(),
				'isFrontend'    => 'frontend' === $context,
				'branding'       => crm_get_branding(),
				'themeColors'    => crm_get_theme_colors(),
				'currencySymbol' => crm_get_settings()['currency_symbol'] ?? '৳',
				'pricingMode'    => crm_pricing_mode(),
				'isSinglePriceMode' => crm_is_single_price_mode(),
				'orderPricesOptional' => ! current_user_can( 'crm_orders_edit' ),
				'moduleLabels'   => array(
					'shipments' => crm_shipments_module_label(),
				),
				'timezone'       => self::js_timezone_config(),
				'i18n'          => array(
					'noRecords' => __( 'No records found.', 'ds-prod-import-crm' ),
				),
			)
		);
	}
}
