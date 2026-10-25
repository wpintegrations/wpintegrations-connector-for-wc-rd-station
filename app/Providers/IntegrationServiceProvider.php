<?php
/**
 * Registers built-in triggers/actions and boots available triggers.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Providers;

use WPIntegrations\WcRdStation\Core\ServiceProvider;
use WPIntegrations\WcRdStation\Integrations\Actions\RdStation\RdStationAction;
use WPIntegrations\WcRdStation\Integrations\Registry;
use WPIntegrations\WcRdStation\Integrations\Triggers\WooCommerce\WooCommerceTrigger;

defined( 'ABSPATH' ) || exit;

final class IntegrationServiceProvider extends ServiceProvider {

	public function boot(): void {
		$registry = $this->app->make( Registry::class );

		$registry->add_trigger( $this->app->make( WooCommerceTrigger::class ) );
		$registry->add_action( $this->app->make( RdStationAction::class ) );

		/**
		 * Add-ons register additional triggers and actions here.
		 *
		 * @param Registry $registry The integration registry.
		 */
		do_action( 'wpi_wcrds_register_integrations', $registry );

		add_action(
			'init',
			static function () use ( $registry ) {
				foreach ( $registry->triggers() as $trigger ) {
					if ( $trigger->available() ) {
						$trigger->register();
					}
				}
			}
		);
	}
}
