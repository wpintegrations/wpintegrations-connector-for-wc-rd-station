<?php
/**
 * Registers REST routes. Every route requires a capability except public_get(),
 * used only where a remote service redirects the browser to us.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http;

use WPIntegrations\WcRdStation\Core\Application;

defined( 'ABSPATH' ) || exit;

final class Router {

	/** @var Application */
	private $app;

	public function __construct( Application $app ) {
		$this->app = $app;
	}

	public function get( string $path, array $handler, string $capability = 'manage_options' ): void {
		$this->route( 'GET', $path, $handler, $capability );
	}

	public function post( string $path, array $handler, string $capability = 'manage_options' ): void {
		$this->route( 'POST', $path, $handler, $capability );
	}

	public function put( string $path, array $handler, string $capability = 'manage_options' ): void {
		$this->route( 'PUT', $path, $handler, $capability );
	}

	public function delete( string $path, array $handler, string $capability = 'manage_options' ): void {
		$this->route( 'DELETE', $path, $handler, $capability );
	}

	/**
	 * Unauthenticated GET for callbacks that cannot carry a nonce. The handler
	 * MUST authenticate the request itself, e.g. with a single-use state token.
	 */
	public function public_get( string $path, array $handler ): void {
		$this->route( 'GET', $path, $handler, '' );
	}

	/**
	 * @param string $method     HTTP verb.
	 * @param string $path       Route path, may contain (?P<id>\d+) style params.
	 * @param array  $handler    [ControllerClass::class, 'method'].
	 * @param string $capability Required capability, or '' for a public route.
	 */
	private function route( string $method, string $path, array $handler, string $capability ): void {
		$app = $this->app;

		register_rest_route(
			WPI_WCRDS_REST_NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => static function ( \WP_REST_Request $request ) use ( $app, $handler ) {
					list( $class, $action ) = $handler;
					return $app->make( $class )->{$action}( $request );
				},
				// Core checks the nonce; this adds the capability check.
				'permission_callback' => static function () use ( $capability ) {
					return '' === $capability ? true : current_user_can( $capability );
				},
			)
		);
	}
}
