<?php
/**
 * Admin menu page and asset loading for the React app.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Providers;

use WPIntegrations\WcRdStation\Core\ServiceProvider;

defined( 'ABSPATH' ) || exit;

final class AdminServiceProvider extends ServiceProvider {

	const MENU_SLUG = 'wpintegrations-connector-for-wc-rd-station';

	/** The plugin's page on wpintegrations.net, which also describes the separate Pro plugin. */
	const PRO_URL = 'https://wpintegrations.net/plugins/';

	/**
	 * The WPIntegrations mark, 20px cut, from brand/svg/wpi-admin-menu-icon.svg.
	 *
	 * Inlined because wp-admin only recolours data-URI icons (svg-painter.js
	 * rewrites every fill), which is also why the artwork is a single path with
	 * one fill and no strokes, masks or groups.
	 *
	 * Regenerate with: base64 -w0 brand/svg/wpi-admin-menu-icon.svg
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9IldQSW50ZWdyYXRpb25zIj4KICA8cGF0aCBmaWxsPSIjQTdBQUFEIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik00LjcgMS4zSDEwLjVBMy40IDMuNCAwIDAgMSAxMy45IDQuN1YxMC41QTMuNCAzLjQgMCAwIDEgMTAuNSAxMy45SDQuN0EzLjQgMy40IDAgMCAxIDEuMyAxMC41VjQuN0EzLjQgMy40IDAgMCAxIDQuNyAxLjNaTTQuNyA0LjNIMTAuNUEwLjQgMC40IDAgMCAxIDEwLjkgNC43VjEwLjVBMC40IDAuNCAwIDAgMSAxMC41IDEwLjlINC43QTAuNCAwLjQgMCAwIDEgNC4zIDEwLjVWNC43QTAuNCAwLjQgMCAwIDEgNC43IDQuM1pNOS41IDYuMUgxNS4zQTMuNCAzLjQgMCAwIDEgMTguNyA5LjVWMTUuM0EzLjQgMy40IDAgMCAxIDE1LjMgMTguN0g5LjVBMy40IDMuNCAwIDAgMSA2LjEgMTUuM1Y5LjVBMy40IDMuNCAwIDAgMSA5LjUgNi4xWk05LjUgOS4xSDE1LjNBMC40IDAuNCAwIDAgMSAxNS43IDkuNVYxNS4zQTAuNCAwLjQgMCAwIDEgMTUuMyAxNS43SDkuNUEwLjQgMC40IDAgMCAxIDkuMSAxNS4zVjkuNUEwLjQgMC40IDAgMCAxIDkuNSA5LjFaTTEwLjkgNi4xSDEzLjlWOS4xSDEwLjlaTTYuMSAxMC45SDkuMVYxMy45SDYuMVpNMTAgNi4xSDEwLjlWOS4xSDEwWk0xMy45IDYuMUgxNC44VjkuMUgxMy45Wk01LjIgMTAuOUg2LjFWMTMuOUg1LjJaTTkuMSAxMC45SDEwVjEzLjlIOS4xWiIvPgo8L3N2Zz4K';

	/** Admin page slug => the React tab it opens. */
	const PAGES = array(
		self::MENU_SLUG               => 'flows',
		self::MENU_SLUG . '-logs'     => 'logs',
		self::MENU_SLUG . '-settings' => 'settings',
	);

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPI_WCRDS_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Links on the Plugins screen: the settings the user is looking for, and
	 * the plugin's page on wpintegrations.net.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$mine = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) ),
				esc_html__( 'Settings', 'wpintegrations-connector-for-wc-rd-station' )
			),
		);

		$mine[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::PRO_URL ),
			esc_html__( 'Pro version', 'wpintegrations-connector-for-wc-rd-station' )
		);

		return array_merge( $mine, $links );
	}

	public function menu(): void {
		add_menu_page(
			__( 'WooCommerce to RD Station', 'wpintegrations-connector-for-wc-rd-station' ),
			__( 'WooCommerce to RD Station', 'wpintegrations-connector-for-wc-rd-station' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			self::MENU_ICON,
			58
		);

		// Renames the auto-generated first submenu item, which repeats the title.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Integrations', 'wpintegrations-connector-for-wc-rd-station' ),
			__( 'Integrations', 'wpintegrations-connector-for-wc-rd-station' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'wpintegrations-connector-for-wc-rd-station' ),
			__( 'Logs', 'wpintegrations-connector-for-wc-rd-station' ),
			'manage_options',
			self::MENU_SLUG . '-logs',
			array( $this, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wpintegrations-connector-for-wc-rd-station' ),
			__( 'Settings', 'wpintegrations-connector-for-wc-rd-station' ),
			'manage_options',
			self::MENU_SLUG . '-settings',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		echo '<div id="wpi_wcrds-admin-root"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup, no variables.
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		$tab = $this->current_tab();
		if ( null === $tab ) {
			return;
		}

		$this->build_assets( $tab );
	}

	/**
	 * The tab this admin page opens on, or null when it is not one of ours.
	 *
	 * @return string|null
	 */
	private function current_tab(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the page slug, processes no input.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::PAGES[ $page ] ?? null;
	}

	/**
	 * Load compiled assets listed in the Vite manifest.
	 *
	 * @param string $tab Tab the app opens on.
	 */
	private function build_assets( string $tab ): void {
		$manifest_file = WPI_WCRDS_PATH . 'build/.vite/manifest.json';
		if ( ! is_readable( $manifest_file ) ) {
			return;
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true );
		$entry    = $manifest['resources/js/index.jsx'] ?? null;
		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			return;
		}

		wp_enqueue_script( 'wpi_wcrds-admin', WPI_WCRDS_URL . 'build/' . $entry['file'], array(), WPI_WCRDS_VERSION, true );
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 2 );

		foreach ( $entry['css'] ?? array() as $index => $css ) {
			wp_enqueue_style( 'wpi_wcrds-admin-' . $index, WPI_WCRDS_URL . 'build/' . $css, array(), WPI_WCRDS_VERSION );
		}

		wp_add_inline_script( 'wpi_wcrds-admin', $this->boot_script( $tab ), 'before' );
	}

	/**
	 * Add type="module" — Vite builds ES modules.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 */
	public function module_tag( string $tag, string $handle ): string {
		if ( 'wpi_wcrds-admin' !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script type="module" ', $tag );
	}

	/**
	 * Inline script handing the REST URL, nonce and starting tab to the app.
	 *
	 * @param string $tab Tab the app opens on.
	 */
	private function boot_script( string $tab ): string {
		$tab_urls = array();
		foreach ( self::PAGES as $slug => $name ) {
			$tab_urls[ $name ] = esc_url_raw( admin_url( 'admin.php?page=' . $slug ) );
		}

		return 'window.wpi_wcrdsBoot = ' . wp_json_encode(
			array(
				'restUrl' => esc_url_raw( rest_url( WPI_WCRDS_REST_NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'version' => WPI_WCRDS_VERSION,
				'tab'     => $tab,
				'tabUrls' => $tab_urls,
			)
		) . ';';
	}
}
