<?php
/**
 * REST routes. Capability-gated (manage_options) on top of core's nonce check.
 *
 * @package WPIntegrations\WcRdStation
 */

use WPIntegrations\WcRdStation\Http\Controllers\ConnectionController;
use WPIntegrations\WcRdStation\Http\Controllers\FlowController;
use WPIntegrations\WcRdStation\Http\Controllers\LogController;
use WPIntegrations\WcRdStation\Http\Controllers\MetaController;
use WPIntegrations\WcRdStation\Http\Controllers\OAuthController;
use WPIntegrations\WcRdStation\Http\Controllers\SettingsController;

defined( 'ABSPATH' ) || exit;

return static function ( WPIntegrations\WcRdStation\Http\Router $router ): void {
	$router->get( '/meta', array( MetaController::class, 'show' ) );
	$router->get( '/sources', array( MetaController::class, 'sources' ) );

	$router->post( '/connection/test', array( ConnectionController::class, 'test' ) );
	$router->post( '/connection/fields', array( ConnectionController::class, 'fields' ) );
	$router->post( '/connection/options', array( ConnectionController::class, 'options' ) );

	$router->get( '/flows', array( FlowController::class, 'index' ) );
	$router->post( '/flows', array( FlowController::class, 'store' ) );
	$router->put( '/flows/(?P<id>\d+)', array( FlowController::class, 'update' ) );
	$router->post( '/flows/(?P<id>\d+)/toggle', array( FlowController::class, 'toggle' ) );
	$router->delete( '/flows/(?P<id>\d+)', array( FlowController::class, 'destroy' ) );

	$router->get( '/oauth/status', array( OAuthController::class, 'status' ) );
	$router->post( '/oauth/credentials', array( OAuthController::class, 'credentials' ) );
	$router->post( '/oauth/authorize', array( OAuthController::class, 'authorize' ) );
	$router->post( '/oauth/disconnect', array( OAuthController::class, 'disconnect' ) );
	// Public: RD Station redirects the browser here, so no nonce is possible.
	// Authenticated by the single-use state token from /oauth/authorize.
	$router->public_get( '/oauth/callback', array( OAuthController::class, 'callback' ) );

	$router->get( '/logs', array( LogController::class, 'index' ) );
	$router->post( '/logs/delete', array( LogController::class, 'destroy' ) );
	$router->post( '/logs/clear', array( LogController::class, 'clear' ) );

	$router->get( '/settings', array( SettingsController::class, 'show' ) );
	$router->post( '/settings', array( SettingsController::class, 'update' ) );
};
