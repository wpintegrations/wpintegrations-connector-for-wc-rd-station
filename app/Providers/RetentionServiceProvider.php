<?php
/**
 * Log retention: the daily purge and its schedule.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Providers;

use WPIntegrations\WcRdStation\Core\ServiceProvider;
use WPIntegrations\WcRdStation\Models\LogEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Log retention and its schedule.
 */
final class RetentionServiceProvider extends ServiceProvider {

	/** Fallback when the setting has never been saved. */
	const DEFAULT_DAYS = 30;

	/**
	 * Register the purge listener and keep its schedule alive.
	 */
	public function boot(): void {
		add_action(
			'wpi_wcrds_daily_purge',
			function () {
				$this->app->make( LogEntry::class )->purge_older_than( self::days() );
			}
		);

		// Re-add the schedule if an update or a cleared cron array dropped it.
		add_action(
			'admin_init',
			static function () {
				if ( ! wp_next_scheduled( 'wpi_wcrds_daily_purge' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpi_wcrds_daily_purge' );
				}
			}
		);
	}

	/**
	 * How many days of history to keep. Shared by everything on the purge hook,
	 * so one setting governs every kind of stored submission.
	 */
	public static function days(): int {
		$settings = (array) get_option( 'wpi_wcrds_settings', array() );
		return (int) ( $settings['log_retention_days'] ?? self::DEFAULT_DAYS );
	}
}
