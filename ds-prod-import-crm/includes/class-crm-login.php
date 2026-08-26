<?php
/**
 * Branded CRM login (public gate + wp-login.php).
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX login for the public CRM gate and WordPress login branding.
 */
class CRM_Login {
	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_nopriv_crm_front_login', array( __CLASS__, 'handle_ajax_login' ) );
		add_action( 'wp_ajax_crm_front_login', array( __CLASS__, 'handle_ajax_login' ) );

		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_wp_login_styles' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'filter_login_logo_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'filter_login_logo_text' ) );
		add_action( 'login_head', array( __CLASS__, 'print_login_favicon' ) );
	}

	/**
	 * Apply CRM company logo and brand colors on wp-login.php.
	 *
	 * @return void
	 */
	public static function enqueue_wp_login_styles() {
		wp_register_style( 'ds-crm-wp-login', false, array(), CRM_Assets::VERSION );
		wp_enqueue_style( 'ds-crm-wp-login' );
		wp_add_inline_style( 'ds-crm-wp-login', crm_get_wp_login_css() );
	}

	/**
	 * Logo link on wp-login.php.
	 *
	 * @param string $url Default URL.
	 * @return string
	 */
	public static function filter_login_logo_url( $url ) {
		$crm_url = crm_get_public_app_url();

		return $crm_url ? $crm_url : home_url( '/' );
	}

	/**
	 * Accessible logo label on wp-login.php.
	 *
	 * @param string $text Default text.
	 * @return string
	 */
	public static function filter_login_logo_text( $text ) {
		$branding = crm_get_branding();

		return ! empty( $branding['company_name'] ) ? $branding['company_name'] : $text;
	}

	/**
	 * Favicon on wp-login.php when configured in CRM settings.
	 *
	 * @return void
	 */
	public static function print_login_favicon() {
		$branding = crm_get_branding();

		if ( empty( $branding['favicon_url'] ) ) {
			return;
		}

		printf(
			'<link rel="icon" href="%s" />' . "\n",
			esc_url( $branding['favicon_url'] )
		);
	}

	/**
	 * Authenticate via wp_signon and return redirect URL.
	 *
	 * @return void
	 */
	public static function handle_ajax_login() {
		check_ajax_referer( 'crm_login', 'nonce' );

		if ( is_user_logged_in() ) {
			wp_send_json_success(
				array(
					'redirect' => CRM_Access::resolve_post_login_url( wp_get_current_user(), self::requested_redirect() ),
				)
			);
		}

		$username = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '';

		if ( '' === $username || '' === $password ) {
			wp_send_json_error(
				array(
					'message' => __( 'Username and password are required.', 'ds-prod-import-crm' ),
				)
			);
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => ! empty( $_POST['rememberme'] ),
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid username or password. Please try again.', 'ds-prod-import-crm' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'redirect' => CRM_Access::resolve_post_login_url( $user, self::requested_redirect() ),
			)
		);
	}

	/**
	 * Redirect target from the login form.
	 *
	 * @return string
	 */
	private static function requested_redirect() {
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( $redirect && wp_validate_redirect( $redirect, false ) ) {
			return $redirect;
		}

		$crm_url = crm_get_public_app_url();

		return $crm_url ? $crm_url : home_url( '/' );
	}
}
