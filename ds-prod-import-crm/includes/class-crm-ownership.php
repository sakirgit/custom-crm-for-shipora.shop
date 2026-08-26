<?php
/**
 * Record ownership helpers — who created data and who may change it.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Ownership rules: full action cap OR (create cap + own record).
 */
class CRM_Ownership {
	/**
	 * Whether the current user owns a record.
	 *
	 * @param int|null $created_by_user_id Creator WordPress user ID.
	 * @return bool
	 */
	public static function is_own_record( $created_by_user_id ) {
		$owner_id = (int) $created_by_user_id;

		if ( $owner_id < 1 ) {
			return false;
		}

		return $owner_id === get_current_user_id();
	}

	/**
	 * Edit rule: edit-any cap, or create cap on own records.
	 *
	 * @param string   $edit_any_cap       Capability to edit any record.
	 * @param string   $create_cap         Capability that grants create (and own edit).
	 * @param int|null $created_by_user_id Record creator.
	 * @return bool
	 */
	public static function user_can_edit( $edit_any_cap, $create_cap, $created_by_user_id ) {
		if ( current_user_can( $edit_any_cap ) ) {
			return true;
		}

		if ( ! current_user_can( $create_cap ) ) {
			return false;
		}

		return self::is_own_record( $created_by_user_id );
	}

	/**
	 * Void/cancel/delete rule: action cap on any, or create cap on own records.
	 *
	 * @param string   $action_cap         Capability for the destructive action on any record.
	 * @param string   $create_cap         Create cap for own-record fallback.
	 * @param int|null $created_by_user_id Record creator.
	 * @return bool
	 */
	public static function user_can_act_on_own( $action_cap, $create_cap, $created_by_user_id ) {
		if ( current_user_can( $action_cap ) ) {
			return true;
		}

		if ( ! current_user_can( $create_cap ) ) {
			return false;
		}

		return self::is_own_record( $created_by_user_id );
	}

	/**
	 * Display name for a record creator.
	 *
	 * @param int|null $user_id User ID.
	 * @return string
	 */
	public static function get_creator_label( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id < 1 ) {
			return __( 'Unknown', 'ds-prod-import-crm' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return __( 'Deleted user', 'ds-prod-import-crm' );
		}

		return $user->display_name;
	}

	/**
	 * Creator payload for API responses.
	 *
	 * @param int|null $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function creator_meta( $user_id ) {
		$user_id = (int) $user_id;

		return array(
			'created_by'       => $user_id > 0 ? $user_id : null,
			'created_by_name'  => self::get_creator_label( $user_id ),
			'is_mine'          => self::is_own_record( $user_id ),
		);
	}
}
