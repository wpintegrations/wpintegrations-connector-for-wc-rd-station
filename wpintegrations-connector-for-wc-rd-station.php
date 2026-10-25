<?php
/**
 * Plugin Name:       WPIntegrations Connector for WooCommerce and RD Station
 * Plugin URI:        https://wpintegrations.net/plugins/connect-woocommerce-to-rd-station/
 * Description:       Send WooCommerce orders to RD Station Marketing as conversion events, with field mapping, LGPD consent and lead attribution. Not affiliated with WooCommerce or RD Station.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires Plugins:  woocommerce
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:  11.2
 * Author:            WPIntegrations
 * Author URI:        https://wpintegrations.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpintegrations-connector-for-wc-rd-station
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro shares this plugin's namespace, constants and tables, so only one may run
 * and Pro wins. Decided before any constant is defined and without relying on
 * load order; the option is the only check available this early, since
 * is_plugin_active() lives in an admin-only file.
 */
$wpi_wcrds_pro       = 'wpintegrations-connector-for-wc-rd-station-pro/wpintegrations-connector-for-wc-rd-station-pro.php';
$wpi_wcrds_pro_alive = in_array( $wpi_wcrds_pro, (array) get_option( 'active_plugins', array() ), true )
	|| ( is_multisite() && array_key_exists( $wpi_wcrds_pro, (array) get_site_option( 'active_sitewide_plugins', array() ) ) );

if ( $wpi_wcrds_pro_alive ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-warning"><p>' . esc_html__(
				'The Pro version is active, so this free copy is doing nothing. You can safely delete it — your integrations and connection stay with Pro.',
				'wpintegrations-connector-for-wc-rd-station'
			) . '</p></div>';
		}
	);
	return;
}

define( 'WPI_WCRDS_VERSION', '1.0.0' );
define( 'WPI_WCRDS_FILE', __FILE__ );
define( 'WPI_WCRDS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPI_WCRDS_URL', plugin_dir_url( __FILE__ ) );
define( 'WPI_WCRDS_SLUG', 'wpintegrations-connector-for-wc-rd-station' );
define( 'WPI_WCRDS_REST_NS', 'wpi_wcrds/v1' );

// PSR-4 autoloader for app/. No Composer needed at runtime.
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPIntegrations\\WcRdStation\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WPI_WCRDS_PATH . 'app/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// HPOS and the block checkout: orders are read through the WC_Order API only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPI_WCRDS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPI_WCRDS_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( WPIntegrations\WcRdStation\Core\Application::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WPIntegrations\WcRdStation\Core\Application::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		WPIntegrations\WcRdStation\Core\Application::instance()->boot();
	}
);
