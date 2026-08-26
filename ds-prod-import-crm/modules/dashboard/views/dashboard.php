<?php
/**
 * Dashboard view.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$is_full_access  = current_user_can( 'crm_manage_settings' ) || current_user_can( 'crm_orders_edit' );
$show_warehouse  = $is_full_access || current_user_can( 'crm_stock_receive' ) || current_user_can( 'crm_stock_view' );
$show_accountant = $is_full_access || current_user_can( 'crm_payments_view' ) || current_user_can( 'crm_billing_view' );
$show_insights   = $show_warehouse || $show_accountant || current_user_can( 'crm_orders_view' );
$can_orders      = current_user_can( 'crm_orders_view' );
$can_receive     = current_user_can( 'crm_stock_receive' );
$can_payments    = current_user_can( 'crm_payments_view' );
$base_url        = isset( $base_url ) ? $base_url : crm_get_app_base_url( 'frontend' );
?>
<div class="ds-crm-dashboard" data-crm-module="dashboard"
	data-show-warehouse="<?php echo $show_warehouse ? '1' : '0'; ?>"
	data-show-accountant="<?php echo $show_accountant ? '1' : '0'; ?>"
	data-show-insights="<?php echo $show_insights ? '1' : '0'; ?>">
	<div class="ds-crm-page-header ds-crm-dashboard-header">
		<?php
		$brand_context = 'header';
		include DS_CRM_PATH . 'templates/partials/crm-brand.php';
		?>
		<div class="ds-crm-dashboard-header-meta">
			<h1><?php esc_html_e( 'Dashboard', 'ds-prod-import-crm' ); ?></h1>
			<p class="ds-crm-dashboard-period-label" id="ds-crm-dashboard-period-label"></p>
		</div>
	</div>

	<div class="ds-crm-dashboard-toolbar">
		<div class="ds-crm-period-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Date range', 'ds-prod-import-crm' ); ?>">
			<button type="button" class="ds-crm-period-tab is-active" data-period="today" role="tab" aria-selected="true"><?php esc_html_e( 'Today', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-period-tab" data-period="yesterday" role="tab"><?php esc_html_e( 'Yesterday', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-period-tab" data-period="7" role="tab"><?php esc_html_e( 'Last 7 days', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-period-tab" data-period="15" role="tab"><?php esc_html_e( 'Last 15 days', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-period-tab" data-period="30" role="tab"><?php esc_html_e( 'Last 30 days', 'ds-prod-import-crm' ); ?></button>
			<button type="button" class="ds-crm-period-tab" data-period="custom" role="tab"><?php esc_html_e( 'Custom', 'ds-prod-import-crm' ); ?></button>
		</div>
		<div class="ds-crm-period-custom" id="ds-crm-period-custom" hidden>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'From', 'ds-prod-import-crm' ); ?></span>
				<input type="date" class="ds-crm-period-from" />
			</label>
			<span class="ds-crm-period-sep">–</span>
			<label>
				<span class="screen-reader-text"><?php esc_html_e( 'To', 'ds-prod-import-crm' ); ?></span>
				<input type="date" class="ds-crm-period-to" />
			</label>
			<button type="button" class="button button-primary ds-crm-period-apply"><?php esc_html_e( 'Apply', 'ds-prod-import-crm' ); ?></button>
		</div>
	</div>

	<div class="ds-crm-dashboard-loading" id="ds-crm-dashboard-loading" hidden>
		<div class="ds-crm-spinner" role="status"></div>
		<span><?php esc_html_e( 'Updating dashboard…', 'ds-prod-import-crm' ); ?></span>
	</div>

	<div class="ds-crm-dashboard-body" id="ds-crm-dashboard-body">
		<?php if ( $show_warehouse || $show_accountant ) : ?>
			<div class="ds-crm-kpi-sections">
				<?php if ( $show_warehouse ) : ?>
					<section class="ds-crm-kpi-section" data-section="warehouse">
						<h2><?php esc_html_e( 'Warehouse', 'ds-prod-import-crm' ); ?></h2>
						<div class="ds-crm-kpi-grid" id="ds-crm-kpi-warehouse"></div>
					</section>
				<?php endif; ?>

				<?php if ( $show_accountant ) : ?>
					<section class="ds-crm-kpi-section" data-section="accountant">
						<h2><?php esc_html_e( 'Finance', 'ds-prod-import-crm' ); ?></h2>
						<div class="ds-crm-kpi-grid" id="ds-crm-kpi-accountant"></div>
					</section>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="ds-crm-dashboard-charts">
			<?php if ( $show_warehouse || $is_full_access ) : ?>
				<div class="ds-crm-chart-card" data-chart="order_status">
					<h3 id="ds-crm-chart-order-status-title"><?php esc_html_e( 'Orders by Status', 'ds-prod-import-crm' ); ?></h3>
					<div class="ds-crm-chart-wrap">
						<canvas id="ds-crm-chart-order-status" height="220"></canvas>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $show_accountant ) : ?>
				<div class="ds-crm-chart-card" data-chart="payments">
					<h3 id="ds-crm-chart-payments-title"><?php esc_html_e( 'Payments', 'ds-prod-import-crm' ); ?></h3>
					<div class="ds-crm-chart-wrap">
						<canvas id="ds-crm-chart-payments" height="220"></canvas>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $show_insights ) : ?>
			<div class="ds-crm-dashboard-insights">
				<div class="ds-crm-insight-card ds-crm-insight-wide">
					<h3><?php esc_html_e( 'Top 10 selling items', 'ds-prod-import-crm' ); ?></h3>
					<div class="ds-crm-table-wrap">
						<table class="ds-crm-table ds-crm-insight-table">
							<thead>
								<tr>
									<th>#</th>
									<th><?php esc_html_e( 'Product', 'ds-prod-import-crm' ); ?></th>
									<th><?php esc_html_e( 'Qty sold', 'ds-prod-import-crm' ); ?></th>
									<th><?php esc_html_e( 'Revenue', 'ds-prod-import-crm' ); ?></th>
								</tr>
							</thead>
							<tbody id="ds-crm-top-selling-body">
								<tr><td colspan="4" class="ds-crm-empty"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="ds-crm-insight-card">
					<h3><?php esc_html_e( 'Top clients', 'ds-prod-import-crm' ); ?></h3>
					<div class="ds-crm-table-wrap">
						<table class="ds-crm-table ds-crm-insight-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Client', 'ds-prod-import-crm' ); ?></th>
									<th><?php esc_html_e( 'Orders', 'ds-prod-import-crm' ); ?></th>
									<th><?php esc_html_e( 'Sales', 'ds-prod-import-crm' ); ?></th>
								</tr>
							</thead>
							<tbody id="ds-crm-top-clients-body">
								<tr><td colspan="3" class="ds-crm-empty"><?php esc_html_e( 'Loading…', 'ds-prod-import-crm' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<?php if ( $show_warehouse ) : ?>
					<div class="ds-crm-insight-card ds-crm-insight-stock">
						<h3><?php esc_html_e( 'Stock overview', 'ds-prod-import-crm' ); ?></h3>
						<ul class="ds-crm-stock-stats" id="ds-crm-stock-stats">
							<li><span><?php esc_html_e( 'Products in stock', 'ds-prod-import-crm' ); ?></span> <strong>—</strong></li>
							<li><span><?php esc_html_e( 'Total pieces', 'ds-prod-import-crm' ); ?></span> <strong>—</strong></li>
							<li><span><?php esc_html_e( 'New orders', 'ds-prod-import-crm' ); ?></span> <strong id="ds-crm-insight-new-orders">—</strong></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="ds-crm-quick-actions">
			<h3><?php esc_html_e( 'Quick Actions', 'ds-prod-import-crm' ); ?></h3>
			<div class="ds-crm-quick-actions-grid">
				<?php if ( $can_orders ) : ?>
					<a class="ds-crm-quick-action ds-crm-quick-action-orders" href="<?php echo esc_url( crm_module_url( 'orders', 'frontend' ) ); ?>">
						<span class="ds-crm-quick-icon">📋</span>
						<span><?php esc_html_e( 'Orders', 'ds-prod-import-crm' ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( $can_receive ) : ?>
					<a class="ds-crm-quick-action ds-crm-quick-action-receive" href="<?php echo esc_url( crm_module_url( 'warehouse', 'frontend' ) ); ?>">
						<span class="ds-crm-quick-icon">📦</span>
						<span><?php esc_html_e( 'Receive Stock', 'ds-prod-import-crm' ); ?></span>
					</a>
				<?php endif; ?>
				<?php if ( $can_payments ) : ?>
					<a class="ds-crm-quick-action ds-crm-quick-action-payments" href="<?php echo esc_url( crm_module_url( 'payments', 'frontend' ) ); ?>">
						<span class="ds-crm-quick-icon">💰</span>
						<span><?php esc_html_e( 'Payments', 'ds-prod-import-crm' ); ?></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
