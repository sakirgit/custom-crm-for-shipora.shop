<?php
/**
 * CRM app shell template.
 *
 * @var string $active_module
 * @var string $crm_context
 * @var string $base_url
 * @var string $logout_url
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$modules = array(
	'dashboard'          => array( 'label' => __( 'Dashboard', 'ds-prod-import-crm' ), 'icon' => '📊' ),
	'orders'             => array( 'label' => __( 'Orders', 'ds-prod-import-crm' ), 'icon' => '📋' ),
	'shipments'          => array( 'label' => crm_shipments_module_label(), 'icon' => '✈️' ),
	'warehouse'          => array( 'label' => __( 'Warehouse / Receive', 'ds-prod-import-crm' ), 'icon' => '📦' ),
	'delivery'           => array( 'label' => __( 'Delivery', 'ds-prod-import-crm' ), 'icon' => '🚚' ),
	'payments'           => array(
		'label' => CRM_Client_Portal::is_client_user()
			? __( 'My payments', 'ds-prod-import-crm' )
			: __( 'Payments', 'ds-prod-import-crm' ),
		'icon'  => '💰',
	),
	'clients'            => array( 'label' => __( 'Clients', 'ds-prod-import-crm' ), 'icon' => '👥' ),
	'companies'          => array( 'label' => __( 'Companies', 'ds-prod-import-crm' ), 'icon' => '🏢' ),
	'products'           => array( 'label' => __( 'Products', 'ds-prod-import-crm' ), 'icon' => '🛍' ),
	'product-categories' => array( 'label' => __( 'Categories', 'ds-prod-import-crm' ), 'icon' => '🏷' ),
	'reports'            => array( 'label' => __( 'Reports', 'ds-prod-import-crm' ), 'icon' => '📈' ),
	'order-statuses'     => array( 'label' => __( 'Order Statuses', 'ds-prod-import-crm' ), 'icon' => '🏷' ),
	'team'               => array( 'label' => __( 'Team & access', 'ds-prod-import-crm' ), 'icon' => '👤' ),
	'activity'           => array( 'label' => __( 'Activity log', 'ds-prod-import-crm' ), 'icon' => '📝' ),
);

$can_manage_settings = current_user_can( 'crm_manage_settings' );
?>
<div class="ds-crm-app">
	<button type="button" class="ds-crm-nav-toggle" aria-controls="ds-crm-sidebar" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open menu', 'ds-prod-import-crm' ); ?>">
		<span class="ds-crm-nav-toggle-bars" aria-hidden="true"></span>
		<span class="ds-crm-nav-toggle-label"><?php esc_html_e( 'Menu', 'ds-prod-import-crm' ); ?></span>
	</button>
	<div class="ds-crm-nav-backdrop" aria-hidden="true"></div>
	<aside class="ds-crm-sidebar" id="ds-crm-sidebar">
		<nav aria-label="<?php esc_attr_e( 'CRM modules', 'ds-prod-import-crm' ); ?>">
			<ul>
				<?php foreach ( $modules as $slug => $module ) : ?>
					<?php
					if ( in_array( $slug, array( 'team', 'activity' ), true ) && ! $can_manage_settings ) {
						continue;
					}
					if ( ! CRM_Access::user_can_access_module( $slug ) ) {
						continue;
					}
					?>
					<li class="<?php echo $active_module === $slug ? 'is-active' : ''; ?>">
						<a href="<?php echo esc_url( crm_module_url( $slug, 'frontend' ) ); ?>">
							<span><?php echo esc_html( $module['icon'] ); ?></span>
							<span><?php echo esc_html( $module['label'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</aside>
	<main class="ds-crm-content">
		<header class="ds-crm-topbar">
			<span class="ds-crm-user"><?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
			<?php if ( $can_manage_settings ) : ?>
				<a class="ds-crm-admin-settings-link" href="<?php echo esc_url( crm_admin_settings_url() ); ?>">
					<?php esc_html_e( 'WP Settings', 'ds-prod-import-crm' ); ?>
				</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Logout', 'ds-prod-import-crm' ); ?></a>
		</header>
		<section class="ds-crm-module">
			<?php
			$module_views = array(
				'dashboard'          => 'modules/dashboard/views/dashboard.php',
				'companies'          => 'modules/companies/views/list.php',
				'clients'            => 'modules/clients/views/list.php',
				'products'           => 'modules/products/views/list.php',
				'product-categories' => 'modules/product-categories/views/list.php',
				'warehouse'          => 'modules/warehouse/views/list.php',
				'shipments'          => 'modules/shipments/views/list.php',
				'orders'             => 'modules/orders/views/list.php',
				'delivery'           => 'modules/delivery/views/list.php',
				'payments'           => 'modules/payments/views/list.php',
				'order-statuses'     => 'modules/order-statuses/views/list.php',
				'reports'            => 'modules/reports/views/reports.php',
				'team'               => 'modules/team/views/team-access.php',
				'activity'           => 'modules/activity/views/activity-log.php',
			);

			if ( 'orders' === $active_module ) {
				$order_action = isset( $_GET['order_action'] ) ? sanitize_key( wp_unslash( $_GET['order_action'] ) ) : '';
				$order_id     = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
				$form_path    = DS_CRM_PATH . 'modules/orders/views/form.php';

				if ( in_array( $order_action, array( 'new', 'edit' ), true ) && file_exists( $form_path ) ) {
					if ( 'new' === $order_action && current_user_can( 'crm_orders_create' ) ) {
						$is_edit  = false;
						$order_id = 0;
						include $form_path;
					} elseif ( 'edit' === $order_action && $order_id > 0 ) {
						global $wpdb;
						$order_row = $wpdb->get_row(
							$wpdb->prepare(
								'SELECT * FROM ' . crm_table( 'orders' ) . ' WHERE id = %d',
								$order_id
							),
							ARRAY_A
						);

						if ( ! $order_row || ! CRM_Client_Portal::user_can_view_order( $order_row ) ) {
							echo '<p>' . esc_html__( 'You do not have permission to view this order.', 'ds-prod-import-crm' ) . '</p>';
						} elseif ( ! CRM_Capability_Registry::user_can_edit_order( $order_row ) ) {
							wp_safe_redirect( crm_order_view_url( $order_id ) );
							exit;
						} else {
							$is_edit = true;
							include $form_path;
						}
					} else {
						echo '<p>' . esc_html__( 'You do not have permission to open this order form.', 'ds-prod-import-crm' ) . '</p>';
					}
				} elseif ( 'view' === $order_action && $order_id > 0 ) {
					$view_path = DS_CRM_PATH . 'modules/orders/views/view.php';
					if ( file_exists( $view_path ) && current_user_can( 'crm_orders_view' ) ) {
						include $view_path;
					} else {
						echo '<p>' . esc_html__( 'You do not have permission to view this order.', 'ds-prod-import-crm' ) . '</p>';
					}
				} else {
					$list_path = DS_CRM_PATH . 'modules/orders/views/list.php';
					if ( file_exists( $list_path ) ) {
						include $list_path;
					}
				}
			} elseif ( 'warehouse' === $active_module ) {
				$receive_action = isset( $_GET['receive_action'] ) ? sanitize_key( wp_unslash( $_GET['receive_action'] ) ) : '';
				$form_path      = DS_CRM_PATH . 'modules/warehouse/views/form.php';

				if ( 'new' === $receive_action && file_exists( $form_path ) ) {
					if ( current_user_can( 'crm_receive_stock' ) ) {
						include $form_path;
					} else {
						echo '<p>' . esc_html__( 'You do not have permission to record receives.', 'ds-prod-import-crm' ) . '</p>';
					}
				} else {
					$list_path = DS_CRM_PATH . 'modules/warehouse/views/list.php';
					if ( file_exists( $list_path ) ) {
						include $list_path;
					}
				}
			} elseif ( 'shipments' === $active_module ) {
				$shipment_action = isset( $_GET['shipment_action'] ) ? sanitize_key( wp_unslash( $_GET['shipment_action'] ) ) : '';
				$form_path       = DS_CRM_PATH . 'modules/shipments/views/form.php';

				if ( 'new' === $shipment_action && file_exists( $form_path ) ) {
					$preset_shipment_order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
					$can_open_shipment_form   = current_user_can( 'crm_shipments_create' )
						|| (
							$preset_shipment_order_id > 0
							&& current_user_can( 'crm_shipments_view' )
							&& (
								current_user_can( 'crm_orders_accept' )
								|| current_user_can( 'crm_orders_edit' )
								|| current_user_can( 'crm_shipments_create' )
							)
						);

					if ( $can_open_shipment_form ) {
						include $form_path;
					} else {
						echo '<p>' . esc_html__( 'You do not have permission to open this order.', 'ds-prod-import-crm' ) . '</p>';
					}
				} else {
					$list_path = DS_CRM_PATH . 'modules/shipments/views/list.php';
					if ( file_exists( $list_path ) ) {
						include $list_path;
					}
				}
			} elseif ( 'companies' === $active_module ) {
				$company_action = isset( $_GET['company_action'] ) ? sanitize_key( wp_unslash( $_GET['company_action'] ) ) : '';
				$company_id     = isset( $_GET['company_id'] ) ? absint( $_GET['company_id'] ) : 0;
				$ledger_path    = DS_CRM_PATH . 'modules/companies/views/ledger.php';

				if ( 'ledger' === $company_action && $company_id > 0 && file_exists( $ledger_path ) ) {
					if ( current_user_can( 'crm_companies_view' ) || current_user_can( 'crm_manage_companies' ) || current_user_can( 'crm_billing_view' ) || current_user_can( 'crm_manage_billing' ) ) {
						include $ledger_path;
					} else {
						echo '<p>' . esc_html__( 'You do not have permission to view this company ledger.', 'ds-prod-import-crm' ) . '</p>';
					}
				} else {
					$list_path = DS_CRM_PATH . 'modules/companies/views/list.php';
					if ( file_exists( $list_path ) ) {
						include $list_path;
					}
				}
			} elseif ( 'delivery' === $active_module ) {
				$delivery_action = isset( $_GET['delivery_action'] ) ? sanitize_key( wp_unslash( $_GET['delivery_action'] ) ) : '';
				$form_path       = DS_CRM_PATH . 'modules/delivery/views/form.php';

				if ( 'new' === $delivery_action && file_exists( $form_path ) ) {
					if ( current_user_can( 'crm_delivery_create' ) || current_user_can( 'crm_manage_delivery' ) ) {
						include $form_path;
					} else {
						echo '<p>' . esc_html__( 'You do not have permission to record deliveries.', 'ds-prod-import-crm' ) . '</p>';
					}
				} else {
					$list_path = DS_CRM_PATH . 'modules/delivery/views/list.php';
					if ( file_exists( $list_path ) ) {
						include $list_path;
					}
				}
			} elseif ( isset( $module_views[ $active_module ] ) ) {
				$view_path = DS_CRM_PATH . $module_views[ $active_module ];
				if ( file_exists( $view_path ) ) {
					include $view_path;
				}
			} else {
				?>
				<div class="ds-crm-placeholder">
					<h2><?php echo esc_html( ucfirst( str_replace( '-', ' ', $active_module ) ) ); ?></h2>
					<p><?php esc_html_e( 'This module will be implemented in the next phases.', 'ds-prod-import-crm' ); ?></p>
				</div>
				<?php
			}
			?>
		</section>
	</main>
</div>
