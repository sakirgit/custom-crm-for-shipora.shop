<?php
/**
 * Frontend shortcode and public page integration.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers [ds_prod_import_crm] shortcode for embedding CRM on any page.
 */
class CRM_Frontend {
	/**
	 * Shortcode tag (primary).
	 */
	const SHORTCODE = 'ds_prod_import_crm';

	/**
	 * Boot hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'output_favicon' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'output_login_theme_css' ), 6 );
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_document_title' ) );
	}

	/**
	 * Add body class when CRM shortcode page is viewed.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public static function body_class( $classes ) {
		if ( is_admin() || ! is_singular() ) {
			return $classes;
		}

		global $post;

		if ( $post instanceof \WP_Post && self::post_has_shortcode( $post ) ) {
			$classes[] = 'ds-crm-page-active';
			if ( ! is_user_logged_in() ) {
				$classes[] = 'ds-crm-login-screen';
			}
		}

		return $classes;
	}

	/**
	 * Enqueue CRM assets when the current page contains the shortcode.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_assets() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! self::post_has_shortcode( $post ) ) {
			return;
		}

		CRM_Assets::enqueue_app( 'frontend' );
	}

	/**
	 * Check if post content includes CRM shortcode.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	public static function post_has_shortcode( $post ) {
		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}

		return has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return self::render_login_gate();
		}

		if ( ! self::user_can_access_crm() ) {
			ob_start();
			echo '<div class="ds-crm-frontend-root ds-crm-login-page"><div class="ds-crm-login-shell"><div class="ds-crm-access-denied ds-crm-login-card">';
			echo '<h2>' . esc_html__( 'Access denied', 'ds-prod-import-crm' ) . '</h2>';
			echo '<p>' . esc_html__(
				'Your account does not have CRM access. Ask an administrator to assign a CRM role (e.g. CRM Client, CRM China Office, CRM Manager) under Users → Edit user.',
				'ds-prod-import-crm'
			) . '</p>';
			echo '</div></div></div>';
			return ob_get_clean();
		}

		CRM_Assets::enqueue_app( 'frontend' );

		ob_start();
		echo '<div class="ds-crm-frontend-root ds-crm-full-bleed">';
		CRM_App::render( 'frontend' );
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Whether the logged-in user may open the CRM frontend.
	 *
	 * @return bool
	 */
	private static function user_can_access_crm() {
		return CRM_Access::user_can_open_frontend_crm();
	}

	/**
	 * Login prompt for guests.
	 *
	 * @return string
	 */
	private static function render_login_gate() {
		CRM_Assets::enqueue_login();

		$redirect_to = '';
		if ( is_singular() ) {
			$redirect_to = get_permalink();
		}

		$login_error = '';
		if ( isset( $_GET['login'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['login'] ) ) ) {
			$login_error = __( 'Invalid username or password. Please try again.', 'ds-prod-import-crm' );
		} elseif ( isset( $_GET['loggedout'] ) && 'true' === sanitize_key( wp_unslash( $_GET['loggedout'] ) ) ) {
			$login_error = __( 'You have been logged out.', 'ds-prod-import-crm' );
		}

		ob_start();
		include DS_CRM_PATH . 'templates/crm-login-gate.php';
		return ob_get_clean();
	}

	/**
	 * Theme CSS variables on login page (before app bundle).
	 *
	 * @return void
	 */
	public static function output_login_theme_css() {
		if ( is_admin() || ! is_singular() || is_user_logged_in() ) {
			return;
		}

		global $post;

		if ( ! $post instanceof \WP_Post || ! self::post_has_shortcode( $post ) ) {
			return;
		}

		echo '<style id="ds-crm-theme-vars">' . crm_get_theme_inline_css() . '</style>' . "\n";
	}

	/**
	 * Output favicon on CRM public pages.
	 *
	 * @return void
	 */
	public static function filter_document_title( $title_parts ) {
		if ( is_admin() || ! is_singular() ) {
			return $title_parts;
		}

		global $post;

		if ( ! $post instanceof \WP_Post || ! self::post_has_shortcode( $post ) ) {
			return $title_parts;
		}

		$settings = crm_get_settings();
		$name     = trim( (string) ( $settings['company_name'] ?? '' ) );

		if ( '' !== $name ) {
			$title_parts['title'] = $name;
		}

		return $title_parts;
	}

	/**
	 * Output favicon on CRM public pages.
	 *
	 * @return void
	 */
	public static function output_favicon() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		global $post;

		if ( ! $post instanceof \WP_Post || ! self::post_has_shortcode( $post ) ) {
			return;
		}

		$branding = crm_get_branding();
		if ( empty( $branding['favicon_url'] ) ) {
			return;
		}

		$url = esc_url( $branding['favicon_url'] );
		echo '<link rel="icon" href="' . $url . '" sizes="32x32" />' . "\n";
		echo '<link rel="shortcut icon" href="' . $url . '" />' . "\n";
	}
}
