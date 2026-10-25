<?php
/**
 * Wraps wp_remote_* calls in a uniform response shape.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Services;

defined( 'ABSPATH' ) || exit;

final class Http {

	/** Seconds to wait on a remote call before giving up. */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Request timeout in seconds. Filterable so a caller delivering inside a
	 * page request can shorten it — a hung API must not hold a PHP worker.
	 */
	private function timeout(): int {
		/**
		 * Filters the timeout applied to every RD Station request.
		 *
		 * @param int $timeout Seconds.
		 */
		return max( 1, (int) apply_filters( 'wpi_wcrds_http_timeout', self::DEFAULT_TIMEOUT ) );
	}

	/**
	 * @param string $url     Request URL.
	 * @param array  $body    JSON-encodable body.
	 * @param array  $headers Extra headers.
	 * @return object{ok:bool, code:int, body:mixed, error:string}
	 */
	public function post_json( string $url, array $body, array $headers = array() ): object {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $this->timeout(),
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
		return $this->normalize( $response );
	}

	/**
	 * @param string $url     Request URL.
	 * @param array  $body    JSON-encodable body.
	 * @param array  $headers Extra headers.
	 * @return object{ok:bool, code:int, body:mixed, error:string}
	 */
	public function put_json( string $url, array $body, array $headers = array() ): object {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => $this->timeout(),
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
		return $this->normalize( $response );
	}

	/**
	 * @param string $url     Request URL.
	 * @param array  $headers Extra headers.
	 * @return object{ok:bool, code:int, body:mixed, error:string}
	 */
	public function get( string $url, array $headers = array() ): object {
		$response = wp_remote_get( $url, array( 'timeout' => $this->timeout(), 'headers' => $headers ) );
		return $this->normalize( $response );
	}

	/**
	 * @param array|\WP_Error $response Raw wp_remote_* result.
	 */
	private function normalize( $response ): object {
		if ( is_wp_error( $response ) ) {
			return (object) array(
				'ok'    => false,
				'code'  => 0,
				'body'  => null,
				'error' => $response->get_error_message(),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return (object) array(
			'ok'    => $code >= 200 && $code < 300,
			'code'  => $code,
			'body'  => null !== $decoded ? $decoded : $raw,
			'error' => '',
		);
	}
}
