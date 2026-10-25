<?php
/**
 * Runs schema migrations, tracking the executed ones in an option.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Database;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	const OPTION = 'wpi_wcrds_migrations';

	/**
	 * Run every migration not yet recorded as executed.
	 */
	public function run(): void {
		$executed = (array) get_option( self::OPTION, array() );
		$changed  = false;

		foreach ( $this->migrations() as $class ) {
			if ( in_array( $class, $executed, true ) ) {
				continue;
			}
			( new $class() )->up();
			$executed[] = $class;
			$changed    = true;
		}

		if ( $changed ) {
			update_option( self::OPTION, $executed, false );
		}
	}

	/**
	 * Ordered migration list. Add-ons append via the filter.
	 *
	 * @return string[] Fully-qualified migration class names.
	 */
	private function migrations(): array {
		$core = array(
			Migrations\CreateFlowsTable::class,
			Migrations\CreateLogsTable::class,
		);

		/**
		 * Filters the migration class list so add-ons can register their own.
		 *
		 * @param string[] $core Migration class names.
		 */
		return (array) apply_filters( 'wpi_wcrds_migrations', $core );
	}
}
