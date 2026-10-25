<?php
/**
 * Service container and application kernel (Laravel-style).
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Core;

use WPIntegrations\WcRdStation\Database\Migrator;

defined( 'ABSPATH' ) || exit;

final class Application {

	/** @var Application|null */
	private static $instance = null;

	/** @var array<string, callable> Factory bindings. */
	private $bindings = array();

	/** @var array<string, object> Resolved singletons. */
	private $instances = array();

	/** @var Config */
	private $config;

	/** @var bool */
	private $booted = false;

	public static function instance(): Application {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->config = new Config( WPI_WCRDS_PATH . 'config' );
		$this->instances[ Config::class ] = $this->config;
	}

	/**
	 * Bind a factory. The same instance is returned every time.
	 *
	 * @param string   $abstract Class or interface name.
	 * @param callable $factory  Factory receiving the Application.
	 */
	public function singleton( string $abstract, callable $factory ): void {
		$this->bindings[ $abstract ] = $factory;
	}

	/**
	 * Register an existing object.
	 */
	public function share( string $abstract, $object ): void {
		$this->instances[ $abstract ] = $object;
	}

	/**
	 * Resolve a class, falling back to auto-injected construction.
	 *
	 * @template T
	 * @param class-string<T> $abstract Class name.
	 * @return T
	 */
	public function make( string $abstract ) {
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}
		if ( isset( $this->bindings[ $abstract ] ) ) {
			$this->instances[ $abstract ] = call_user_func( $this->bindings[ $abstract ], $this );
			return $this->instances[ $abstract ];
		}
		$this->instances[ $abstract ] = $this->build( $abstract );
		return $this->instances[ $abstract ];
	}

	/**
	 * Instantiate a class, resolving its typed constructor params.
	 */
	private function build( string $concrete ) {
		$reflector   = new \ReflectionClass( $concrete );
		$constructor = $reflector->getConstructor();
		if ( null === $constructor || 0 === $constructor->getNumberOfParameters() ) {
			return new $concrete();
		}
		$args = array();
		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();
			if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ) {
				$args[] = $this->make( $type->getName() );
			} elseif ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
			} else {
				throw new \RuntimeException( esc_html( "Unresolvable constructor parameter \${$param->getName()} for {$concrete}." ) );
			}
		}
		return $reflector->newInstanceArgs( $args );
	}

	public function config(): Config {
		return $this->config;
	}

	/**
	 * Boot all service providers declared in config/app.php.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$providers = array();
		foreach ( $this->config->get( 'app.providers', array() ) as $provider_class ) {
			$provider = new $provider_class( $this );
			$provider->register();
			$providers[] = $provider;
		}
		foreach ( $providers as $provider ) {
			$provider->boot();
		}

		/**
		 * Fires once the core has booted; add-ons register integrations here.
		 *
		 * @param Application $this The application container.
		 */
		do_action( 'wpi_wcrds_booted', $this );
	}

	/**
	 * Run migrations and schedule the log purge.
	 */
	public static function activate(): void {
		$app = self::instance();
		$app->make( Migrator::class )->run();

		if ( ! wp_next_scheduled( 'wpi_wcrds_daily_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpi_wcrds_daily_purge' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wpi_wcrds_daily_purge' );
		// Scheduled by the Pro plugin's delivery queue; clear it in case Pro
		// left it behind.
		wp_clear_scheduled_hook( 'wpi_wcrds_queue_tick' );
	}
}
