<?php
/**
 * China office portal users — scoped CRM access for export shipments.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for the CRM China Office and China Office Supervisor roles.
 */
class CRM_China_Office {
	/**
	 * China office officer role slug.
	 */
	const ROLE = 'crm_china_office';

	/**
	 * China office supervisor role slug.
	 */
	const SUPERVISOR_ROLE = 'crm_china_supervisor';

	/**
	 * Roles that use the China portal experience (modules, landing, timezone).
	 *
	 * @return array<int, string>
	 */
	public static function portal_roles() {
		return array( self::ROLE, self::SUPERVISOR_ROLE );
	}

	/**
	 * Default modules for China portal users (role baseline).
	 *
	 * Custom Team permissions can grant additional modules via capabilities.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_modules() {
		return array( 'shipments', 'orders', 'companies' );
	}

	/**
	 * Whether the user has any China portal role.
	 *
	 * @param \WP_User|null $user User or current user.
	 * @return bool
	 */
	public static function is_china_office_user( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return (bool) array_intersect( self::portal_roles(), (array) $user->roles );
	}

	/**
	 * Whether the user is a China office officer (can record exports / request amendments).
	 *
	 * @param \WP_User|null $user User or current user.
	 * @return bool
	 */
	public static function is_china_officer( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Whether the user is a China office supervisor (review only).
	 *
	 * @param \WP_User|null $user User or current user.
	 * @return bool
	 */
	public static function is_china_supervisor( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return in_array( self::SUPERVISOR_ROLE, (array) $user->roles, true );
	}

	/**
	 * Whether a module is available to China portal users.
	 *
	 * @param string $module Module slug.
	 * @return bool
	 */
	public static function china_can_access_module( $module ) {
		return CRM_Access::user_can_access_scoped_module( $module, self::allowed_modules() );
	}
}
