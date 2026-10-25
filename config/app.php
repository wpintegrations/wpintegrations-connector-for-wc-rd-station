<?php
/**
 * Application configuration.
 *
 * @package WPIntegrations\WcRdStation
 */

defined( 'ABSPATH' ) || exit;

return array(
	'providers' => array(
		WPIntegrations\WcRdStation\Providers\AppServiceProvider::class,
		WPIntegrations\WcRdStation\Providers\DatabaseServiceProvider::class,
		WPIntegrations\WcRdStation\Providers\IntegrationServiceProvider::class,
		WPIntegrations\WcRdStation\Providers\RetentionServiceProvider::class,
		WPIntegrations\WcRdStation\Providers\RestServiceProvider::class,
		WPIntegrations\WcRdStation\Providers\AdminServiceProvider::class,
	),
);
