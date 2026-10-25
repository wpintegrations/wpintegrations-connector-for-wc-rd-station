<?php
/**
 * Base migration with dbDelta helper.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Database;

defined( 'ABSPATH' ) || exit;

abstract class Migration {

	/**
	 * Apply the migration.
	 */
	abstract public function up(): void;

	/**
	 * Create or update a table via dbDelta.
	 *
	 * @param string $table   Unprefixed table name (e.g. 'wpi_wcrds_flows').
	 * @param string $columns Column definitions (without CREATE TABLE wrapper).
	 */
	protected function create_table( string $table, string $columns ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}{$table} (\n{$columns}\n) {$charset};" );
	}
}
