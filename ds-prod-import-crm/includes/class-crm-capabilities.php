<?php
/**
 * Per-user CRM capability overrides (on top of WordPress roles).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Lets admins enable/disable CRM activities per user.
 */
class CRM_Capabilities {
	/**
	 * User meta key for explicit cap overrides.
	 */
	const META_OVERRIDES = 'ds_crm_cap_overrides';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 99, 4 );
	}

	/**
	 * All CRM capability slugs.
	 *
	 * @return array<int, string>
	 */
	public static function get_all_slugs() {
		return CRM_Capability_Registry::get_all_slugs();
	}

	/**
	 * Grouped capabilities for the permissions UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_groups() {
		return CRM_Capability_Registry::get_permission_groups();
	}

	/**
	 * Capabilities granted by a CRM role slug.
	 *
	 * @param string $role_slug Role slug.
	 * @return array<string, bool>
	 */
	public static function get_role_capabilities( $role_slug ) {
		$role = get_role( $role_slug );
		$out  = array();

		foreach ( self::get_all_slugs() as $cap ) {
			$out[ $cap ] = $role && ! empty( $role->capabilities[ $cap ] );
		}

		return CRM_Capability_Registry::expand_legacy_bundles( $out );
	}

	/**
	 * Merge capabilities from all of a user's roles (CRM caps only).
	 *
	 * @param \WP_User $user User object.
	 * @return array<string, bool>
	 */
	public static function get_caps_from_user_roles( $user ) {
		$merged = array();

		foreach ( self::get_all_slugs() as $cap ) {
			$merged[ $cap ] = false;
		}

		if ( ! $user || ! $user->exists() ) {
			return $merged;
		}

		foreach ( (array) $user->roles as $role_slug ) {
			$role_caps = self::get_role_capabilities( $role_slug );
			foreach ( $role_caps as $cap => $allowed ) {
				if ( $allowed ) {
					$merged[ $cap ] = true;
				}
			}
		}

		return CRM_Capability_Registry::expand_legacy_bundles( $merged );
	}

	/**
	 * Stored per-user overrides (only differences from role).
	 *
	 * Legacy bundle keys are ignored — they are computed from action caps at runtime.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, bool>
	 */
	public static function get_overrides( $user_id ) {
		$stored = get_user_meta( $user_id, self::META_OVERRIDES, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$legacy_keys = array_flip( array_keys( CRM_Capability_Registry::get_legacy_bundles() ) );
		$clean       = array();

		foreach ( CRM_Capability_Registry::get_toggleable_slugs() as $cap ) {
			if ( array_key_exists( $cap, $stored ) && ! isset( $legacy_keys[ $cap ] ) ) {
				$clean[ $cap ] = (bool) $stored[ $cap ];
			}
		}

		return $clean;
	}

	/**
	 * Whether the user is a WordPress site administrator.
	 *
	 * @param \WP_User                 $user    User object.
	 * @param array<string, bool>|null $allcaps Capabilities being filtered.
	 * @return bool
	 */
	private static function is_site_administrator( $user, $allcaps = null ) {
		if ( is_array( $allcaps ) && ! empty( $allcaps['manage_options'] ) ) {
			return true;
		}

		return $user && in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Effective CRM capabilities for a user (role + overrides).
	 *
	 * @param int                      $user_id User ID.
	 * @param array<string, bool>|null $allcaps Optional caps array when called from user_has_cap filter.
	 * @return array<string, bool>
	 */
	public static function get_effective( $user_id, $allcaps = null ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		if ( self::is_site_administrator( $user, $allcaps ) ) {
			return array_fill_keys( self::get_all_slugs(), true );
		}

		$base      = self::get_caps_from_user_roles( $user );
		$overrides = self::get_overrides( $user_id );
		$effective = array();

		foreach ( self::get_all_slugs() as $cap ) {
			if ( array_key_exists( $cap, $overrides ) ) {
				$effective[ $cap ] = $overrides[ $cap ];
			} else {
				$effective[ $cap ] = ! empty( $base[ $cap ] );
			}
		}

		return CRM_Capability_Registry::apply_computed_legacy_caps( $effective );
	}

	/**
	 * Whether user has custom overrides saved.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_custom_overrides( $user_id ) {
		return ! empty( self::get_overrides( $user_id ) );
	}

	/**
	 * Save per-user capability toggles from admin UI.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string, bool> $desired Desired state for every CRM cap.
	 * @return void
	 */
	public static function save_user_permissions( $user_id, $desired ) {
		$user = get_userdata( $user_id );

		if ( ! $user || user_can( $user, 'manage_options' ) ) {
			return;
		}

		$desired       = CRM_Capability_Registry::normalize_permissions( $desired );
		$role_defaults = self::get_caps_from_user_roles( $user );
		$overrides     = array();

		// Only store differences for UI-toggleable action caps (never legacy bundles).
		foreach ( CRM_Capability_Registry::get_toggleable_slugs() as $cap ) {
			$want     = ! empty( $desired[ $cap ] );
			$role_has = ! empty( $role_defaults[ $cap ] );

			if ( $want !== $role_has ) {
				$overrides[ $cap ] = $want;
			}
		}

		if ( empty( $overrides ) ) {
			delete_user_meta( $user_id, self::META_OVERRIDES );
		} else {
			update_user_meta( $user_id, self::META_OVERRIDES, $overrides );
		}
	}

	/**
	 * Whether a saved override explicitly enables a capability.
	 *
	 * Used for scoped roles (client, China office) to allow modules outside
	 * their default whitelist when an admin grants them in Team permissions.
	 *
	 * @param int    $user_id User ID.
	 * @param string $cap     Capability slug.
	 * @return bool
	 */
	public static function is_cap_custom_enabled( $user_id, $cap ) {
		$overrides = self::get_overrides( $user_id );

		return array_key_exists( $cap, $overrides ) && ! empty( $overrides[ $cap ] );
	}

	/**
	 * Clear overrides so the role defaults apply again.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function reset_user_permissions( $user_id ) {
		delete_user_meta( $user_id, self::META_OVERRIDES );
	}

	/**
	 * Apply effective CRM caps when WordPress checks capabilities.
	 *
	 * @param array<string, bool> $allcaps All capabilities.
	 * @param array<int, string>  $caps    Requested caps.
	 * @param array<int, mixed>   $args    Extra args.
	 * @param \WP_User            $user    User.
	 * @return array<string, bool>
	 */
	public static function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return $allcaps;
		}

		if ( self::is_site_administrator( $user, $allcaps ) ) {
			return $allcaps;
		}

		if ( ! CRM_Access::user_has_crm_role( $user ) && ! self::has_custom_overrides( $user->ID ) ) {
			return $allcaps;
		}

		$effective = self::get_effective( $user->ID, $allcaps );

		foreach ( $effective as $cap => $allowed ) {
			$allcaps[ $cap ] = $allowed;
			if ( $allowed ) {
				$allcaps['read'] = true;
			}
		}

		return $allcaps;
	}
}
