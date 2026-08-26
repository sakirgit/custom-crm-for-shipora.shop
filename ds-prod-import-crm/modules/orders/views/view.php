<?php
/**
 * Full-page order details.
 *
 * @var int $order_id
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$order_id   = isset( $order_id ) ? absint( $order_id ) : 0;
$list_url   = crm_module_url( 'orders', 'frontend' );
$can_status = current_user_can( 'crm_orders_status' );

if ( $order_id < 1 ) {
	echo '<p>' . esc_html__( 'Invalid order.', 'ds-prod-import-crm' ) . '</p>';
	return;
}
?>
<div class="ds-crm-module-page ds-crm-order-view-page" data-crm-module="orders-view" data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-can-status="<?php echo $can_status ? '1' : '0'; ?>">
	<div class="ds-crm-page-header ds-crm-order-view-header">
		<div class="ds-crm-order-view-heading">
			<a class="ds-crm-back-link" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to orders', 'ds-prod-import-crm' ); ?></a>
			<h1 id="ds-crm-order-view-title"><?php esc_html_e( 'Order details', 'ds-prod-import-crm' ); ?></h1>
		</div>
		<div class="ds-crm-order-view-toolbar" hidden></div>
	</div>

	<div class="ds-crm-order-view-loading" aria-live="polite">
		<p><?php esc_html_e( 'Loading order…', 'ds-prod-import-crm' ); ?></p>
	</div>

	<div class="ds-crm-order-view-content" hidden>
		<section class="ds-crm-order-view-section ds-crm-order-tracking-section" hidden>
			<h2 class="ds-crm-order-view-section-title"><?php esc_html_e( 'Order tracking', 'ds-prod-import-crm' ); ?></h2>
			<div class="ds-crm-order-tracking-summary"></div>
			<ol class="ds-crm-order-tracking-timeline"></ol>
		</section>

		<div class="ds-crm-order-view-meta"></div>

		<?php if ( $can_status ) : ?>
		<div class="ds-crm-order-status-panel ds-crm-order-status-row" hidden>
			<label class="ds-crm-order-status-label" for="ds-crm-order-status-select"><?php esc_html_e( 'Change status', 'ds-prod-import-crm' ); ?></label>
			<div class="ds-crm-order-status-controls">
				<select id="ds-crm-order-status-select" class="ds-crm-order-status-select"></select>
				<button type="button" class="button button-primary ds-crm-save-order-status"><?php esc_html_e( 'Update', 'ds-prod-import-crm' ); ?></button>
			</div>
		</div>
		<?php endif; ?>

		<section class="ds-crm-order-view-section">
			<h2 class="ds-crm-order-view-section-title"><?php esc_html_e( 'Line items', 'ds-prod-import-crm' ); ?></h2>
			<div class="ds-crm-table-wrap ds-crm-order-view-table-wrap">
				<table class="ds-crm-table ds-crm-order-view-items">
					<thead>
						<tr></tr>
					</thead>
					<tbody></tbody>
					<tfoot hidden>
						<tr class="ds-crm-order-lines-total"></tr>
					</tfoot>
				</table>
			</div>
		</section>

		<div class="ds-crm-order-view-summary"></div>
	</div>
</div>
