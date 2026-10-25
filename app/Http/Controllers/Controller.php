<?php
/**
 * Base controller helpers.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

defined( 'ABSPATH' ) || exit;

abstract class Controller {

	/**
	 * @param mixed $data Response payload.
	 */
	protected function ok( $data = null, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'success' => true, 'data' => $data ), $status );
	}

	protected function fail( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'success' => false, 'message' => $message ), $status );
	}

	/**
	 * Sanitize nested input as text fields.
	 *
	 * @param mixed $value Raw input.
	 * @return mixed
	 */
	protected function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( (string) $key ) ] = $this->sanitize_deep( $item );
			}
			return $clean;
		}
		return sanitize_text_field( (string) $value );
	}
}
