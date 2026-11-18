<?php
/**
 * Uninstall: remove the plugin's own options. Imported demo content is left in
 * place on purpose (it is site content now); remove it from the importer screen
 * before uninstalling if you do not want it.
 *
 * @package SaayehDemoImporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'sdi_state', 'sdi_backup', 'sdi_imported', 'sdi_registry' ) as $sdi_option ) {
	delete_option( $sdi_option );
}
delete_transient( 'sdi_lock' );
delete_transient( 'sdi_theme_switched' );
