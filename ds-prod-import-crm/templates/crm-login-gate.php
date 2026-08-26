<?php
/**
 * Branded CRM login screen (WordPress authentication).
 *
 * @var string $redirect_to Login redirect URL.
 * @var string $login_error Optional error message.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$branding     = crm_get_branding();
$brand_context = 'login';
$redirect_to  = isset( $redirect_to ) ? $redirect_to : '';
$login_error  = isset( $login_error ) ? $login_error : '';
?>
<div class="ds-crm-frontend-root ds-crm-login-page">
	<div class="ds-crm-login-shell">
		<div class="ds-crm-login-card">
			<?php include DS_CRM_PATH . 'templates/partials/crm-brand.php'; ?>

			<div class="ds-crm-login-intro">
				<h1 class="ds-crm-login-title"><?php esc_html_e( 'Sign in to CRM', 'ds-prod-import-crm' ); ?></h1>
				<?php if ( ! empty( $branding['company_tagline'] ) ) : ?>
					<p class="ds-crm-login-tagline"><?php echo esc_html( $branding['company_tagline'] ); ?></p>
				<?php else : ?>
					<p class="ds-crm-login-tagline"><?php esc_html_e( 'Use your WordPress account assigned by your administrator.', 'ds-prod-import-crm' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $login_error ) : ?>
				<?php
				$is_logout_msg = isset( $_GET['loggedout'] ) && 'true' === sanitize_key( wp_unslash( $_GET['loggedout'] ) );
				$notice_class  = $is_logout_msg ? 'ds-crm-login-notice' : 'ds-crm-login-error';
				?>
				<div class="<?php echo esc_attr( $notice_class ); ?>" role="alert"><?php echo esc_html( $login_error ); ?></div>
			<?php endif; ?>

			<form class="ds-crm-login-form" method="post" action="<?php echo esc_url( $redirect_to ? $redirect_to : home_url( '/' ) ); ?>" novalidate>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />

				<p class="ds-crm-login-field">
					<label for="ds-crm-log"><?php esc_html_e( 'Username or email', 'ds-prod-import-crm' ); ?></label>
					<input type="text" name="log" id="ds-crm-log" class="input" value="<?php echo isset( $_POST['log'] ) ? esc_attr( wp_unslash( $_POST['log'] ) ) : ''; ?>" autocomplete="username" required />
				</p>

				<p class="ds-crm-login-field">
					<label for="ds-crm-pwd"><?php esc_html_e( 'Password', 'ds-prod-import-crm' ); ?></label>
					<input type="password" name="pwd" id="ds-crm-pwd" class="input" autocomplete="current-password" required />
				</p>

				<p class="ds-crm-login-remember">
					<label>
						<input type="checkbox" name="rememberme" value="forever" />
						<?php esc_html_e( 'Remember me', 'ds-prod-import-crm' ); ?>
					</label>
				</p>

				<p class="ds-crm-login-submit">
					<button type="submit" name="wp-submit" class="ds-crm-login-button">
						<?php esc_html_e( 'Log in', 'ds-prod-import-crm' ); ?>
					</button>
				</p>
			</form>

			<p class="ds-crm-login-footer">
				<a href="<?php echo esc_url( wp_lostpassword_url( $redirect_to ) ); ?>"><?php esc_html_e( 'Lost your password?', 'ds-prod-import-crm' ); ?></a>
			</p>
		</div>
	</div>
</div>
