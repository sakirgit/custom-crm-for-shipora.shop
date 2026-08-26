<?php
/**
 * Company brand block (logo + name).
 *
 * @var string $brand_context sidebar|header|login.
 *
 * @package ds-prod-import-crm
 */

namespace DsProdImportCRM;

defined( 'ABSPATH' ) || exit;

$brand           = crm_get_branding();
$brand_context   = isset( $brand_context ) ? $brand_context : 'sidebar';
$has_logo        = ! empty( $brand['logo_url'] );
$show_tagline    = 'header' === $brand_context && ! empty( $brand['company_tagline'] );
?>
<div class="ds-crm-brand-block ds-crm-brand-<?php echo esc_attr( $brand_context ); ?><?php echo ( $has_logo && 'sidebar' === $brand_context ) ? ' has-sidebar-logo' : ''; ?>">
	
	<?php if ( $has_logo && 'sidebar' === $brand_context ) : ?>
		<div class="ds-crm-brand-logo-wrap">
			<img class="ds-crm-brand-logo" src="<?php echo esc_url( $brand['logo_url'] ); ?>" alt="<?php echo esc_attr( $brand['company_name'] ); ?>" />
		</div>
	<?php elseif ( $has_logo ) : ?>
		<img class="ds-crm-brand-logo" src="<?php echo esc_url( $brand['logo_url'] ); ?>" alt="<?php echo esc_attr( $brand['company_name'] ); ?>" />
	<?php endif; ?>
	
	<div class="ds-crm-brand-text">
		<strong class="ds-crm-brand-name"><?php echo esc_html( $brand['company_name'] ); ?></strong>
		<?php if ( $show_tagline ) : ?>
			<span class="ds-crm-brand-tagline"><?php echo esc_html( $brand['company_tagline'] ); ?></span>
		<?php endif; ?>
	</div>
</div>
