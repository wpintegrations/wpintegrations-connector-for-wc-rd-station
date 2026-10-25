<?php
/**
 * Tests destination credentials without saving anything. A masked secret falls
 * back to the key stored on the flow being edited, so a saved connection can be
 * re-tested without re-entering it.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

use WPIntegrations\WcRdStation\Integrations\Registry;
use WPIntegrations\WcRdStation\Models\Flow;
use WPIntegrations\WcRdStation\Services\Crypto;

defined( 'ABSPATH' ) || exit;

final class ConnectionController extends Controller {

	/** @var Registry */
	private $registry;

	/** @var Flow */
	private $flows;

	/** @var Crypto */
	private $crypto;

	public function __construct( Registry $registry, Flow $flows, Crypto $crypto ) {
		$this->registry = $registry;
		$this->flows    = $flows;
		$this->crypto   = $crypto;
	}

	public function test( \WP_REST_Request $request ): \WP_REST_Response {
		$action = $this->action_from( $request );
		if ( ! $action ) {
			return $this->fail( __( 'Unknown action.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		$result = $action->test_connection( $this->credentials_from( $request, $action ) );

		return $result->ok
			? $this->ok( array( 'message' => $result->message ) )
			: $this->fail( $result->message );
	}

	/**
	 * The account's own field list (custom fields included) for the mapping UI.
	 */
	public function fields( \WP_REST_Request $request ): \WP_REST_Response {
		$action = $this->action_from( $request );
		if ( ! $action ) {
			return $this->fail( __( 'Unknown action.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		$body    = (array) $request->get_json_params();
		$refresh = ! empty( $body['refresh'] );

		$fields = $action->discover_target_fields( $this->credentials_from( $request, $action ), $refresh );

		return $this->ok(
			array(
				'discovered' => null !== $fields,
				'fields'     => null !== $fields ? $fields : array(),
			)
		);
	}

	/**
	 * Options for a flow setting's options_source select. The current settings
	 * come along so dependent lists (stages within a pipeline) can narrow.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public function options( \WP_REST_Request $request ): \WP_REST_Response {
		$action = $this->action_from( $request );
		if ( ! $action ) {
			return $this->fail( __( 'Unknown action.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		$body = (array) $request->get_json_params();

		$key      = sanitize_key( (string) ( $body['option_key'] ?? '' ) );
		$settings = (array) ( $body['settings'] ?? array() );
		$settings = array_map( 'sanitize_text_field', (array) $settings );

		return $this->ok( array( 'options' => $action->discover_options( $key, $settings ) ) );
	}

	/**
	 * Resolve the action named in the request body.
	 *
	 * @return \WPIntegrations\WcRdStation\Integrations\Action|null
	 */
	private function action_from( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		return $this->registry->action( sanitize_key( (string) ( $body['action_key'] ?? '' ) ) );
	}

	/**
	 * Plain-text credentials from the request. A masked secret is replaced by the
	 * decrypted one stored on the flow being edited.
	 *
	 * @param \WPIntegrations\WcRdStation\Integrations\Action $action Destination action.
	 * @return array<string, string>
	 */
	private function credentials_from( \WP_REST_Request $request, $action ): array {
		$body     = (array) $request->get_json_params();
		$flow_id  = (int) ( $body['flow_id'] ?? 0 );
		$incoming = (array) ( $body['credentials'] ?? array() );

		$credentials = array();
		foreach ( $action->connection_fields() as $field ) {
			$key   = $field['key'];
			$value = sanitize_text_field( (string) ( $incoming[ $key ] ?? '' ) );

			if ( SettingsController::MASK === $value && ! empty( $field['secret'] ) ) {
				$flow   = $flow_id ? $this->flows->find( $flow_id ) : null;
				$stored = $flow ? (string) ( ( (array) $flow->settings )[ $key ] ?? '' ) : '';
				$value  = '' === $stored ? '' : $this->crypto->decrypt( $stored );
			}

			$credentials[ $key ] = $value;
		}

		return $credentials;
	}
}
