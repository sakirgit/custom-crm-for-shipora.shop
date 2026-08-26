<?php
/**
 * Plugin uninstall file.
 *
 * @package ds-prod-import-crm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally does not remove business tables automatically.
// Keeping data is safer for accounting systems unless explicit purge is implemented.
