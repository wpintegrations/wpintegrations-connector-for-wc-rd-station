<?php
/**
 * Base service provider (Laravel-style register/boot lifecycle).
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Core;

defined( 'ABSPATH' ) || exit;

abstract class ServiceProvider {

	/** @var Application */
	protected $app;

	public function __construct( Application $app ) {
		$this->app = $app;
	}

	/**
	 * Bind services into the container. No hooks here.
	 */
	public function register(): void {}

	/**
	 * Attach hooks. Runs after every provider has registered.
	 */
	public function boot(): void {}
}
