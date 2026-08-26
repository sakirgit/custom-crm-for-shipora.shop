<?php
/**
 * Team access guide — uses WordPress Users (no duplicate user system).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'crm_manage_settings' ) ) {
	echo '<p>' . esc_html__( 'You do not have permission to manage team access.', 'ds-prod-import-crm' ) . '</p>';
	return;
}

$users_url = admin_url( 'users.php' );
$add_url   = admin_url( 'user-new.php' );
$roles     = array(
	array(
		'slug'  => 'crm_admin',
		'label' => __( 'CRM Admin', 'ds-prod-import-crm' ),
		'desc'  => __( 'Full CRM access plus CRM Settings in WordPress admin.', 'ds-prod-import-crm' ),
	),
	array(
		'slug'  => 'crm_manager',
		'label' => __( 'CRM Manager', 'ds-prod-import-crm' ),
		'desc'  => __( 'Orders, clients, warehouse, delivery, payments, products — daily operations.', 'ds-prod-import-crm' ),
	),
	array(
		'slug'  => 'crm_warehouse',
		'label' => __( 'CRM Warehouse', 'ds-prod-import-crm' ),
		'desc'  => __( 'Dashboard, receive stock, view stock only.', 'ds-prod-import-crm' ),
	),
	array(
		'slug'  => 'crm_accountant',
		'label' => __( 'CRM Accountant', 'ds-prod-import-crm' ),
		'desc'  => __( 'Dashboard, customer payments, billing, reports.', 'ds-prod-import-crm' ),
	),
	array(
		'slug'  => 'crm_viewer',
		'label' => __( 'CRM Viewer', 'ds-prod-import-crm' ),
		'desc'  => __( 'Dashboard and reports (read-only).', 'ds-prod-import-crm' ),
	),
	array(
		'slug'  => 'crm_client',
		'label' => __( 'CRM Client', 'ds-prod-import-crm' ),
		'desc'  => __( 'Customer portal — place and view orders only.', 'ds-prod-import-crm' ),
	),
	array(
		'slug'  => 'crm_china_office',
		'label' => __( 'CRM China Office', 'ds-prod-import-crm' ),
		'desc'  => sprintf(
			/* translators: %s: configurable module label */
			__( 'China office staff — review all orders, set final prices, approve orders, record export shipments, and request per-product quantity changes on submitted shipments (%s module).', 'ds-prod-import-crm' ),
			crm_shipments_module_label()
		),
	),
	array(
		'slug'  => 'crm_china_supervisor',
		'label' => __( 'CRM China Office Supervisor', 'ds-prod-import-crm' ),
		'desc'  => sprintf(
			/* translators: %s: configurable module label */
			__( 'China office supervisor — view %s and approve or decline export quantity change requests. Cannot change product quantities or record shipments.', 'ds-prod-import-crm' ),
			crm_shipments_module_label()
		),
	),
);

$crm_users = get_users(
	array(
		'role__in' => CRM_Access::get_crm_role_slugs(),
		'orderby'  => 'display_name',
		'order'    => 'ASC',
	)
);
?>
<div class="ds-crm-module-page" data-crm-module="team">
	<div class="ds-crm-page-header">
		<h1><?php esc_html_e( 'Team & access', 'ds-prod-import-crm' ); ?></h1>
	</div>

	<div class="ds-crm-notice ds-crm-notice-info">
		<?php esc_html_e( 'CRM uses WordPress users and roles. Create accounts under Users in wp-admin, then set the primary Role and any additional CRM roles on the user profile (for example China Office + China Office Supervisor). Use Permissions here to fine-tune activities without changing roles. Staff sign in on your public CRM page — they are not sent to the WordPress dashboard unless they are a site administrator.', 'ds-prod-import-crm' ); ?>
	</div>

	<div class="ds-crm-team-actions">
		<a class="button button-primary" href="<?php echo esc_url( $add_url ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'Add user (WordPress)', 'ds-prod-import-crm' ); ?>
		</a>
		<a class="button" href="<?php echo esc_url( $users_url ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'All users (WordPress)', 'ds-prod-import-crm' ); ?>
		</a>
		<?php if ( crm_get_public_app_url() ) : ?>
			<a class="button" href="<?php echo esc_url( crm_get_public_app_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Open CRM login page', 'ds-prod-import-crm' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<section class="ds-crm-ledger-section">
		<h2 class="ds-crm-order-view-section-title"><?php esc_html_e( 'CRM roles', 'ds-prod-import-crm' ); ?></h2>
		<div class="ds-crm-table-wrap">
			<table class="ds-crm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'ds-prod-import-crm' ); ?></th>
						<th><?php esc_html_e( 'Use for', 'ds-prod-import-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $roles as $role ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $role['label'] ); ?></strong><br><code><?php echo esc_html( $role['slug'] ); ?></code></td>
							<td><?php echo esc_html( $role['desc'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description"><?php esc_html_e( 'WordPress Administrators always have full CRM access without changing their role. A user may hold multiple CRM roles at once — assign extras under “Additional CRM roles” on the WordPress user profile.', 'ds-prod-import-crm' ); ?></p>
	</section>

	<section class="ds-crm-ledger-section">
		<h2 class="ds-crm-order-view-section-title"><?php esc_html_e( 'Users with a CRM role', 'ds-prod-import-crm' ); ?></h2>
		<?php if ( empty( $crm_users ) ) : ?>
			<p class="ds-crm-ledger-empty"><?php esc_html_e( 'No users have a CRM role yet. Add a user in WordPress and assign a role from the table above.', 'ds-prod-import-crm' ); ?></p>
		<?php else : ?>
			<div class="ds-crm-table-wrap">
				<table class="ds-crm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Email', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'CRM roles', 'ds-prod-import-crm' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ds-prod-import-crm' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $crm_users as $user ) : ?>
							<?php
							$user_crm_roles = array_values( array_intersect( CRM_Access::get_crm_role_slugs(), (array) $user->roles ) );
							$edit_url       = admin_url( 'user-edit.php?user_id=' . $user->ID );
							?>
							<tr data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>">
								<td><?php echo esc_html( $user->display_name ); ?></td>
								<td><?php echo esc_html( $user->user_email ); ?></td>
								<td class="ds-crm-team-role-cell">
									<?php foreach ( $user_crm_roles as $slug ) : ?>
										<span class="ds-crm-badge"><?php echo esc_html( CRM_Roles::get_role_label( $slug ) ); ?></span>
									<?php endforeach; ?>
									<?php if ( CRM_Capabilities::has_custom_overrides( $user->ID ) ) : ?>
										<span class="ds-crm-badge ds-crm-badge--custom"><?php esc_html_e( 'custom', 'ds-prod-import-crm' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="ds-crm-table-actions">
									<button type="button" class="button button-small button-primary ds-crm-team-permissions-btn" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>">
										<?php esc_html_e( 'Permissions', 'ds-prod-import-crm' ); ?>
									</button>
									<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'Edit in WordPress', 'ds-prod-import-crm' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<div class="ds-crm-modal" id="ds-crm-team-permissions-modal" hidden>
		<div class="ds-crm-modal-overlay"></div>
		<div class="ds-crm-modal-dialog ds-crm-modal-permissions" role="dialog" aria-modal="true" aria-labelledby="ds-crm-team-permissions-title">
			<div class="ds-crm-modal-header">
				<div class="ds-crm-modal-header-text">
					<h2 id="ds-crm-team-permissions-title"><?php esc_html_e( 'User permissions', 'ds-prod-import-crm' ); ?></h2>
					<p class="ds-crm-team-permissions-subtitle description"></p>
				</div>
				<button type="button" class="ds-crm-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ds-prod-import-crm' ); ?>">&times;</button>
			</div>
			<div class="ds-crm-modal-body ds-crm-team-permissions-body">
				<p class="ds-crm-permissions-intro description">
					<?php esc_html_e( 'Toggle each action separately. View access is required first. Users with create but without “edit any” can still fix orders they added themselves.', 'ds-prod-import-crm' ); ?>
				</p>
				<div class="ds-crm-team-permissions-groups" aria-live="polite"></div>
			</div>
			<div class="ds-crm-modal-footer ds-crm-modal-footer--split">
				<div class="ds-crm-modal-footer-start">
					<button type="button" class="button button-link ds-crm-team-permissions-reset" hidden>
						<?php esc_html_e( 'Reset to role defaults', 'ds-prod-import-crm' ); ?>
					</button>
				</div>
				<div class="ds-crm-modal-footer-end">
					<button type="button" class="button ds-crm-modal-cancel"><?php esc_html_e( 'Cancel', 'ds-prod-import-crm' ); ?></button>
					<button type="button" class="button button-primary ds-crm-team-permissions-save"><?php esc_html_e( 'Save permissions', 'ds-prod-import-crm' ); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>
