<?php
/**
 * Dot-notation config repository loading config/*.php files.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Core;

defined( 'ABSPATH' ) || exit;

final class Config {

	/** @var array<string, array> */
	private $items = array();

	/** @var string */
	private $path;

	public function __construct( string $path ) {
		$this->path = untrailingslashit( $path );
	}

	/**
	 * Read a value: get('app.providers') is config/app.php key 'providers'.
	 *
	 * @param string $key     Dot key; the first segment is the file name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$segments = explode( '.', $key );
		$file     = array_shift( $segments );

		if ( ! isset( $this->items[ $file ] ) ) {
			$file_path = $this->path . '/' . $file . '.php';
			$this->items[ $file ] = is_readable( $file_path ) ? (array) include $file_path : array();
		}

		$value = $this->items[ $file ];
		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}
}
