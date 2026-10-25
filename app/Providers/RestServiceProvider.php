<?php
/**
 * Registers REST routes from routes/api.php.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Providers;

use WPIntegrations\WcRdStation\Core\ServiceProvider;
use WPIntegrations\WcRdStation\Http\Router;

defined( 'ABSPATH' ) || exit;

final class RestServiceProvider extends ServiceProvider {

	public function boot(): void {
		add_action(
			'rest_api_init',
			function () {
				$routes = require WPI_WCRDS_PATH . 'routes/api.php';
				$routes( new Router( $this->app ) );
			}
		);
	}
}
