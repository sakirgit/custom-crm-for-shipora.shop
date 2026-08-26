<?php
/**
 * CRM user access: wp-admin restrictions, login redirects, role helpers.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Users integration for CRM staff roles.
 */
class CRM_Access {
	/**
	 * CRM role slugs registered by the plugin.
	 *
	 * @return array<int, string>
	 */
	public static function get_crm_role_slugs() {
		return array(
			'crm_admin',
			'crm_manager',
			'crm_warehouse',
			'crm_accountant',
			'crm_viewer',
			'crm_client',
			'crm_china_office',
			'crm_china_supervisor',
		);
	}

	/**
	 * Whether the user has any CRM role.
	 *
	 * @param \WP_User|null $user User object or null for current user.
	 * @return bool
	 */
	public static function user_has_crm_role( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return (bool) array_intersect( self::get_crm_role_slugs(), (array) $user->roles );
	}

	/**
	 * Whether user is a WordPress site administrator (full wp-admin).
	 *
	 * @return bool
	 */
	public static function is_wp_administrator() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_crm_staff_from_admin' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'render_extra_crm_roles' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_extra_crm_roles' ) );
		add_action( 'user_new_form', array( __CLASS__, 'render_extra_crm_roles_new_user' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_extra_crm_roles' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_extra_crm_roles' ) );
		add_action( 'user_register', array( __CLASS__, 'save_extra_crm_roles_on_register' ), 30 );
	}

	/**
	 * Send CRM staff to the public CRM page instead of wp-admin (except settings screen).
	 *
	 * @return void
	 */
	public static function maybe_redirect_crm_staff_from_admin() {
		if ( ! is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( self::is_wp_administrator() ) {
			return;
		}

		if ( ! self::user_has_crm_role() ) {
			return;
		}

		if ( self::crm_staff_may_use_admin_screen() ) {
			return;
		}

		$target = crm_get_public_app_url();
		if ( ! $target ) {
			return;
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Allow CRM Settings (and own profile) in wp-admin for settings managers.
	 *
	 * @return bool
	 */
	private static function crm_staff_may_use_admin_screen() {
		if ( ! current_user_can( 'crm_manage_settings' ) ) {
			return false;
		}

		global $pagenow;

		if ( isset( $_GET['page'] ) && 'ds-prod-import-crm' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return true;
		}

		if ( in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) ) {
			$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : get_current_user_id();
			if ( $user_id === get_current_user_id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * After login, send CRM users to the CRM frontend page.
	 *
	 * @param string           $redirect_to           Redirect URL.
	 * @param string           $requested_redirect_to Requested redirect.
	 * @param \WP_User|\WP_Error $user                User or error.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_wp_error( $user ) || ! $user instanceof \WP_User ) {
			return $redirect_to;
		}

		return self::resolve_post_login_url( $user, $requested_redirect_to ? $requested_redirect_to : $redirect_to );
	}

	/**
	 * Where to send a user after CRM front login or wp-login redirect.
	 *
	 * @param \WP_User $user            Authenticated user.
	 * @param string   $requested_redirect Preferred redirect URL.
	 * @return string
	 */
	public static function resolve_post_login_url( $user, $requested_redirect = '' ) {
		$crm_url = crm_get_public_app_url();

		if ( $requested_redirect && wp_validate_redirect( $requested_redirect, false ) ) {
			$safe_redirect = $requested_redirect;
		} elseif ( $crm_url ) {
			$safe_redirect = $crm_url;
		} else {
			$safe_redirect = home_url( '/' );
		}

		if ( ! $crm_url ) {
			return $safe_redirect;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			return $safe_redirect;
		}

		if ( ! self::user_has_crm_role( $user ) ) {
			return $safe_redirect;
		}

		if ( CRM_Client_Portal::is_client_user( $user ) ) {
			return crm_module_url( 'orders', 'frontend' );
		}

		if ( CRM_China_Office::is_china_office_user( $user ) ) {
			return crm_module_url( 'shipments', 'frontend' );
		}

		if ( $requested_redirect && false !== strpos( $requested_redirect, 'wp-admin' ) ) {
			if ( user_can( $user, 'crm_manage_settings' ) ) {
				return crm_admin_settings_url();
			}
			return $crm_url;
		}

		return $crm_url;
	}

	/**
	 * Hide the WP admin bar for CRM staff on the front end.
	 *
	 * @param bool $show Whether to show admin bar.
	 * @return bool
	 */
	public static function show_admin_bar( $show ) {
		if ( is_admin() || self::is_wp_administrator() ) {
			return $show;
		}

		if ( self::user_has_crm_role() ) {
			return false;
		}

		return $show;
	}

	/**
	 * Capability required to access a CRM module in the menu.
	 *
	 * @param string $module Module slug.
	 * @return string|null Capability or null if always allowed with dashboard.
	 */
	public static function module_capability( $module ) {
		return CRM_Capability_Registry::get_menu_cap_for_module( $module );
	}

	/**
	 * CRM modules that require settings access (never for scoped portal roles).
	 *
	 * @return array<int, string>
	 */
	public static function get_admin_only_modules() {
		return array( 'team', 'activity', 'order-statuses' );
	}

	/**
	 * Module access for roles with a default whitelist plus optional Team grants.
	 *
	 * Baseline modules are available when the user holds the menu cap. Extra modules
	 * require an explicit custom override so role defaults alone cannot expand access.
	 *
	 * @param string              $module          Module slug.
	 * @param array<int, string>  $default_modules Role baseline modules.
	 * @return bool
	 */
	public static function user_can_access_scoped_module( $module, array $default_modules ) {
		$module = sanitize_key( $module );

		if ( in_array( $module, self::get_admin_only_modules(), true ) ) {
			return current_user_can( 'crm_manage_settings' );
		}

		if ( 'dashboard' === $module ) {
			return current_user_can( 'crm_view_dashboard' );
		}

		$cap = self::module_capability( $module );
		if ( ! $cap || ! current_user_can( $cap ) ) {
			return false;
		}

		if ( in_array( $module, $default_modules, true ) ) {
			return true;
		}

		return CRM_Capabilities::is_cap_custom_enabled( get_current_user_id(), $cap );
	}

	/**
	 * Whether the current user can open a CRM module.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function user_can_access_module( $module ) {
		$module = sanitize_key( $module );

		if ( CRM_Client_Portal::is_client_user() ) {
			return self::user_can_access_scoped_module( $module, CRM_Client_Portal::allowed_modules() );
		}

		if ( CRM_China_Office::is_china_office_user() ) {
			return self::user_can_access_scoped_module( $module, CRM_China_Office::allowed_modules() );
		}

		if ( 'dashboard' === $module ) {
			return current_user_can( 'crm_view_dashboard' );
		}

		$cap = self::module_capability( $module );

		if ( null === $cap ) {
			return current_user_can( 'crm_view_dashboard' );
		}

		return current_user_can( $cap );
	}

	/**
	 * Module slugs used to decide if the CRM frontend shell may load.
	 *
	 * @return array<int, string>
	 */
	public static function get_navigable_module_slugs() {
		return array(
			'dashboard',
			'orders',
			'shipments',
			'warehouse',
			'delivery',
			'payments',
			'clients',
			'companies',
			'products',
			'product-categories',
			'reports',
			'order-statuses',
			'team',
			'activity',
		);
	}

	/**
	 * Whether the logged-in user may open the public CRM (any permitted module).
	 *
	 * @return bool
	 */
	public static function user_can_open_frontend_crm() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! self::user_has_crm_role() ) {
			return false;
		}

		foreach ( self::get_navigable_module_slugs() as $slug ) {
			if ( self::user_can_access_module( $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current user may assign CRM roles in wp-admin.
	 *
	 * @return bool
	 */
	public static function current_user_can_assign_crm_roles() {
		return current_user_can( 'promote_users' ) || current_user_can( 'manage_options' );
	}

	/**
	 * CRM roles currently on a user.
	 *
	 * @param \WP_User|null $user User.
	 * @return array<int, string>
	 */
	public static function get_user_crm_roles( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return array();
		}

		return array_values( array_intersect( self::get_crm_role_slugs(), (array) $user->roles ) );
	}

	/**
	 * Render additional CRM role checkboxes on Add New User.
	 *
	 * @param string $operation Form context.
	 * @return void
	 */
	public static function render_extra_crm_roles_new_user( $operation ) {
		unset( $operation );
		self::render_extra_crm_roles( null );
	}

	/**
	 * Render “Additional CRM roles” on the WordPress user profile.
	 *
	 * WordPress stores one primary Role; these checkboxes let admins attach
	 * extra CRM roles (e.g. China Office + China Office Supervisor).
	 *
	 * @param \WP_User|null $user User being edited, or null on Add New User.
	 * @return void
	 */
	public static function render_extra_crm_roles( $user = null ) {
		if ( ! self::current_user_can_assign_crm_roles() ) {
			return;
		}

		if ( $user instanceof \WP_User && user_can( $user, 'manage_options' ) ) {
			return;
		}

		$definitions = CRM_Roles::get_role_definitions();
		$current     = ( $user instanceof \WP_User ) ? self::get_user_crm_roles( $user ) : array();
		$primary     = '';

		if ( $user instanceof \WP_User && ! empty( $user->roles ) ) {
			$primary = (string) reset( $user->roles );
		}

		?>
		<h2><?php esc_html_e( 'Additional CRM roles', 'ds-prod-import-crm' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'WordPress keeps one primary Role above. Check any extra CRM roles this account should also have. Capabilities from all CRM roles are combined. Example: set Role to CRM China Office, then also check CRM China Office Supervisor.', 'ds-prod-import-crm' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Extra roles', 'ds-prod-import-crm' ); ?></label></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Extra CRM roles', 'ds-prod-import-crm' ); ?></span></legend>
						<?php foreach ( $definitions as $slug => $definition ) : ?>
							<?php
							$is_primary = ( $primary === $slug );
							$checked    = in_array( $slug, $current, true );
							?>
							<label style="display:block;margin:0 0 8px;">
								<input
									type="checkbox"
									name="crm_extra_roles[]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( $checked ); ?>
									<?php disabled( $is_primary ); ?>
								/>
								<?php echo esc_html( $definition['label'] ); ?>
								<code style="margin-left:6px;"><?php echo esc_html( $slug ); ?></code>
								<?php if ( $is_primary ) : ?>
									<span class="description"> — <?php esc_html_e( 'primary Role above', 'ds-prod-import-crm' ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<?php if ( $primary && in_array( $primary, self::get_crm_role_slugs(), true ) ) : ?>
						<input type="hidden" name="crm_extra_roles[]" value="<?php echo esc_attr( $primary ); ?>" />
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist extra CRM roles after profile save.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function save_extra_crm_roles( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! self::current_user_can_assign_crm_roles() ) {
			return;
		}

		if ( ! isset( $_POST['_wpnonce'] ) && ! isset( $_POST['_wpnonce_create-user'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return;
		}

		$crm_slugs = self::get_crm_role_slugs();
		$primary   = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$posted    = isset( $_POST['crm_extra_roles'] ) ? (array) wp_unslash( $_POST['crm_extra_roles'] ) : array();
		$extra     = array();

		foreach ( $posted as $slug ) {
			$slug = sanitize_key( $slug );
			if ( in_array( $slug, $crm_slugs, true ) ) {
				$extra[] = $slug;
			}
		}

		$desired = $extra;

		if ( $primary && in_array( $primary, $crm_slugs, true ) ) {
			$desired[] = $primary;
		}

		$desired = array_values( array_unique( $desired ) );

		foreach ( $crm_slugs as $slug ) {
			if ( in_array( $slug, $desired, true ) ) {
				$user->add_role( $slug );
			} else {
				$user->remove_role( $slug );
			}
		}
	}

	/**
	 * Apply extra CRM roles right after a new user is created.
	 *
	 * @param int $user_id New user ID.
	 * @return void
	 */
	public static function save_extra_crm_roles_on_register( $user_id ) {
		self::save_extra_crm_roles( $user_id );
	}
}
