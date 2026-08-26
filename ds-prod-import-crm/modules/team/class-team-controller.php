<?php
/**
 * Team permissions (per-user CRM capabilities).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX for user permission overrides.
 */
class Team_Controller extends CRM_Controller_Base {
	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_crm_team_user_permissions', array( __CLASS__, 'get_user_permissions' ) );
		add_action( 'wp_ajax_crm_team_save_permissions', array( __CLASS__, 'save_user_permissions' ) );
		add_action( 'wp_ajax_crm_team_reset_permissions', array( __CLASS__, 'reset_user_permissions' ) );
	}

	/**
	 * Load permission matrix for one user.
	 *
	 * @return void
	 */
	public static function get_user_permissions() {
		self::verify_request( 'crm_manage_settings' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'ds-prod-import-crm' ) ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'User not found.', 'ds-prod-import-crm' ) ) );
		}

		if ( user_can( $user, 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'WordPress Administrators always have full access. Edit a different role instead.', 'ds-prod-import-crm' ),
				)
			);
		}

		$crm_roles = array_values( array_intersect( CRM_Access::get_crm_role_slugs(), (array) $user->roles ) );
		$crm_role  = $crm_roles[0] ?? '';

		if ( '' === $crm_role ) {
			wp_send_json_error(
				array(
					'message' => __( 'Assign a CRM role to this user in WordPress first (Users → Edit user). You can also add extra CRM roles under Additional CRM roles.', 'ds-prod-import-crm' ),
				)
			);
		}

		$role_defaults = CRM_Capabilities::get_caps_from_user_roles( $user );
		$effective     = CRM_Capabilities::get_effective( $user_id );
		$groups        = array();

		foreach ( CRM_Capabilities::get_groups() as $group_label => $caps ) {
			$items = array();
			foreach ( $caps as $cap => $label ) {
				$items[] = array(
					'cap'           => $cap,
					'label'         => $label,
					'enabled'       => ! empty( $effective[ $cap ] ),
					'role_default'  => ! empty( $role_defaults[ $cap ] ),
					'is_customized' => array_key_exists( $cap, CRM_Capabilities::get_overrides( $user_id ) ),
					'depends_on'    => CRM_Capability_Registry::get_view_cap_for( $cap ),
				);
			}
			$groups[] = array(
				'label' => $group_label,
				'items' => $items,
			);
		}

		$role_labels = array_map( array( CRM_Roles::class, 'get_role_label' ), $crm_roles );

		wp_send_json_success(
			array(
				'user'              => array(
					'id'                 => $user->ID,
					'display_name'       => $user->display_name,
					'email'              => $user->user_email,
					'role'               => $crm_role,
					'roles'              => $crm_roles,
					'role_label'         => implode( ' + ', $role_labels ),
					'has_multiple_roles' => count( $crm_roles ) > 1,
				),
				'groups'            => $groups,
				'has_custom'        => CRM_Capabilities::has_custom_overrides( $user_id ),
				'capability_labels' => self::flatten_group_labels(),
			)
		);
	}

	/**
	 * Save toggled permissions for a user.
	 *
	 * @return void
	 */
	public static function save_user_permissions() {
		self::verify_request( 'crm_manage_settings' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$raw     = isset( $_POST['permissions'] ) ? wp_unslash( $_POST['permissions'] ) : '';

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'ds-prod-import-crm' ) ) );
		}

		$user = get_userdata( $user_id );

		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cannot change permissions for this user.', 'ds-prod-import-crm' ) ) );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid permission data.', 'ds-prod-import-crm' ) ) );
		}

		$desired = array();

		foreach ( CRM_Capability_Registry::get_toggleable_slugs() as $cap ) {
			$desired[ $cap ] = ! empty( $decoded[ $cap ] );
		}

		CRM_Capabilities::save_user_permissions( $user_id, $desired );

		self::log_activity(
			'update',
			'team',
			$user_id,
			sprintf( 'Updated CRM permissions for %s', $user->user_login )
		);

		wp_send_json_success(
			array(
				'message'    => __( 'Permissions saved.', 'ds-prod-import-crm' ),
				'has_custom' => CRM_Capabilities::has_custom_overrides( $user_id ),
			)
		);
	}

	/**
	 * Reset user to role defaults.
	 *
	 * @return void
	 */
	public static function reset_user_permissions() {
		self::verify_request( 'crm_manage_settings' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'ds-prod-import-crm' ) ) );
		}

		CRM_Capabilities::reset_user_permissions( $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Permissions reset to role defaults.', 'ds-prod-import-crm' ),
			)
		);
	}

	/**
	 * Flat cap => label map for JS.
	 *
	 * @return array<string, string>
	 */
	private static function flatten_group_labels() {
		$flat = array();

		foreach ( CRM_Capabilities::get_groups() as $caps ) {
			foreach ( $caps as $cap => $label ) {
				$flat[ $cap ] = $label;
			}
		}

		return $flat;
	}
}
