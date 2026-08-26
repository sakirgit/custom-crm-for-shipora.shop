<?php
/**
 * Plugin Name: Product stock and order managment CRM
 * Description: China import CRM for orders, stock, delivery, billing, and reports.
 * Version: 0.18.2
 * Author: Developer-S.com Team
 * Text Domain: ds-prod-import-crm
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DS_CRM_FILE' ) ) {
	define( 'DS_CRM_FILE', __FILE__ );
}

if ( ! defined( 'DS_CRM_PATH' ) ) {
	define( 'DS_CRM_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DS_CRM_URL' ) ) {
	define( 'DS_CRM_URL', plugin_dir_url( __FILE__ ) );
}

require_once DS_CRM_PATH . 'includes/helpers.php';
require_once DS_CRM_PATH . 'includes/class-crm-activator.php';
require_once DS_CRM_PATH . 'includes/class-crm-capability-registry.php';
require_once DS_CRM_PATH . 'includes/class-crm-ownership.php';
require_once DS_CRM_PATH . 'includes/class-crm-audit.php';
require_once DS_CRM_PATH . 'includes/class-crm-roles.php';
require_once DS_CRM_PATH . 'includes/class-crm-access.php';
require_once DS_CRM_PATH . 'includes/class-crm-client-portal.php';
require_once DS_CRM_PATH . 'includes/class-crm-china-office.php';
require_once DS_CRM_PATH . 'includes/class-crm-capabilities.php';
require_once DS_CRM_PATH . 'includes/class-crm-controller-base.php';
require_once DS_CRM_PATH . 'includes/class-crm-module-summary.php';
require_once DS_CRM_PATH . 'includes/class-crm-data-reset.php';
require_once DS_CRM_PATH . 'includes/class-crm-admin-data.php';
require_once DS_CRM_PATH . 'includes/class-crm-stock.php';
require_once DS_CRM_PATH . 'includes/class-crm-app.php';
require_once DS_CRM_PATH . 'includes/class-crm-admin.php';
require_once DS_CRM_PATH . 'includes/class-crm-frontend.php';
require_once DS_CRM_PATH . 'includes/class-crm-login.php';
require_once DS_CRM_PATH . 'includes/class-crm-assets.php';
require_once DS_CRM_PATH . 'includes/class-crm-ajax.php';
require_once DS_CRM_PATH . 'modules/dashboard/class-dashboard-controller.php';
require_once DS_CRM_PATH . 'modules/companies/class-companies-controller.php';
require_once DS_CRM_PATH . 'modules/clients/class-clients-controller.php';
require_once DS_CRM_PATH . 'modules/products/class-products-controller.php';
require_once DS_CRM_PATH . 'modules/product-categories/class-product-categories-controller.php';
require_once DS_CRM_PATH . 'modules/warehouse/class-warehouse-controller.php';
require_once DS_CRM_PATH . 'modules/delivery/class-delivery-controller.php';
require_once DS_CRM_PATH . 'modules/shipments/class-shipments-controller.php';
require_once DS_CRM_PATH . 'modules/orders/class-orders-controller.php';
require_once DS_CRM_PATH . 'includes/class-crm-ledger.php';
require_once DS_CRM_PATH . 'includes/class-crm-order-status.php';
require_once DS_CRM_PATH . 'includes/class-crm-order-item-priority.php';
require_once DS_CRM_PATH . 'includes/class-crm-order-tracking.php';
require_once DS_CRM_PATH . 'modules/payments/class-payments-controller.php';
require_once DS_CRM_PATH . 'modules/order-statuses/class-order-statuses-controller.php';
require_once DS_CRM_PATH . 'modules/settings/class-settings-controller.php';
require_once DS_CRM_PATH . 'modules/reports/class-reports-controller.php';
require_once DS_CRM_PATH . 'modules/team/class-team-controller.php';
require_once DS_CRM_PATH . 'modules/activity/class-activity-controller.php';

register_activation_hook( DS_CRM_FILE, array( 'DsProdImportCRM\\CRM_Activator', 'activate' ) );
register_deactivation_hook( DS_CRM_FILE, array( 'DsProdImportCRM\\CRM_Roles', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function() {
		load_plugin_textdomain( 'ds-prod-import-crm', false, dirname( plugin_basename( DS_CRM_FILE ) ) . '/languages' );

		\DsProdImportCRM\CRM_Access::init();
		\DsProdImportCRM\CRM_Client_Portal::init();
		\DsProdImportCRM\CRM_Capabilities::init();
		\DsProdImportCRM\CRM_Frontend::init();
		\DsProdImportCRM\CRM_Login::init();
		\DsProdImportCRM\CRM_Assets::init();
		\DsProdImportCRM\CRM_Ajax::init();
		\DsProdImportCRM\Dashboard_Controller::init();
		\DsProdImportCRM\Companies_Controller::init();
		\DsProdImportCRM\Clients_Controller::init();
		\DsProdImportCRM\Products_Controller::init();
		\DsProdImportCRM\Product_Categories_Controller::init();
		\DsProdImportCRM\Warehouse_Controller::init();
		\DsProdImportCRM\Delivery_Controller::init();
		\DsProdImportCRM\Shipments_Controller::init();
		\DsProdImportCRM\Orders_Controller::init();
		\DsProdImportCRM\Payments_Controller::init();
		\DsProdImportCRM\Order_Statuses_Controller::init();
		\DsProdImportCRM\Settings_Controller::init();
		\DsProdImportCRM\CRM_Admin_Data::init();
		\DsProdImportCRM\Reports_Controller::init();
		\DsProdImportCRM\Team_Controller::init();
		\DsProdImportCRM\Activity_Controller::init();

		if ( is_admin() ) {
			\DsProdImportCRM\CRM_Admin::init();
		}
	}
);

add_action(
	'init',
	static function() {
		\DsProdImportCRM\CRM_Roles::maybe_register_roles();
		\DsProdImportCRM\CRM_Activator::maybe_upgrade();
	}
);

