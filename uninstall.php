<?php
/**
 * Removes tables, options and cron events when the plugin is deleted.
 *
 * @package WPIntegrations\WcRdStation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Both editions share this data. Bail on an installed Pro copy, not just an
 * active one: a bulk delete can remove this plugin while Pro is momentarily
 * deactivated, taking Pro's tokens and integrations with it.
 */
$wpi_wcrds_pro = 'wpintegrations-connector-for-wc-rd-station-pro/wpintegrations-connector-for-wc-rd-station-pro.php';

$wpi_wcrds_active = (array) get_option( 'active_plugins', array() );
$wpi_wcrds_shared = in_array( $wpi_wcrds_pro, $wpi_wcrds_active, true )
	|| ( is_multisite() && array_key_exists( $wpi_wcrds_pro, (array) get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| file_exists( WP_PLUGIN_DIR . '/' . $wpi_wcrds_pro );

if ( $wpi_wcrds_shared ) {
	return;
}

global $wpdb;

// Plugin tables. Names are constants, not user input.
foreach ( array( 'wpi_wcrds_flows', 'wpi_wcrds_logs', 'wpi_wcrds_jobs' ) as $wpi_wcrds_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of plugin-owned tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $wpi_wcrds_table ) );
}

// Options.
delete_option( 'wpi_wcrds_settings' );
delete_option( 'wpi_wcrds_version' );
delete_option( 'wpi_wcrds_migrations' );
delete_option( 'wpi_wcrds_recent_shapes' );

// Transients: the order-field cache, plus RD Station field lists swept by prefix.
delete_transient( 'wpi_wcrds_wc_fields' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_wpi\_wcrds\_rd\_fields\_%'
	    OR option_name LIKE '\_transient\_timeout\_wpi\_wcrds\_rd\_fields\_%'"
);

// Cron events.
wp_clear_scheduled_hook( 'wpi_wcrds_queue_tick' );
wp_clear_scheduled_hook( 'wpi_wcrds_daily_purge' );
