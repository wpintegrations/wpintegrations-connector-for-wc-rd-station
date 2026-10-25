<?php
/**
 * CRUD for flows. Connection credentials live on each flow: secrets are
 * encrypted inside its settings and leave the server only as a mask.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

use WPIntegrations\WcRdStation\Integrations\Registry;
use WPIntegrations\WcRdStation\Models\Flow;
use WPIntegrations\WcRdStation\Services\Crypto;

defined( 'ABSPATH' ) || exit;

final class FlowController extends Controller {

	/** @var Flow */
	private $flows;

	/** @var Registry */
	private $registry;

	/** @var Crypto */
	private $crypto;

	public function __construct( Flow $flows, Registry $registry, Crypto $crypto ) {
		$this->flows    = $flows;
		$this->registry = $registry;
		$this->crypto   = $crypto;
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( array_map( array( $this, 'present' ), $this->flows->where( array(), 200 ) ) );
	}

	public function store( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $this->validate( $request );
		if ( is_string( $data ) ) {
			return $this->fail( $data );
		}
		$id = $this->flows->create( $data );
		return $id ? $this->ok( $this->present( $this->flows->find( $id ) ), 201 ) : $this->fail( __( 'Could not save the flow.', 'wpintegrations-connector-for-wc-rd-station' ), 500 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$id       = (int) $request['id'];
		$existing = $this->flows->find( $id );
		if ( ! $existing ) {
			return $this->fail( __( 'Flow not found.', 'wpintegrations-connector-for-wc-rd-station' ), 404 );
		}
		$data = $this->validate( $request, (array) $existing->settings );
		if ( is_string( $data ) ) {
			return $this->fail( $data );
		}
		// The editor does not send a status: an edit must not switch a paused
		// integration back on.
		$body = (array) $request->get_json_params();
		if ( ! array_key_exists( 'status', $body ) ) {
			unset( $data['status'] );
		}
		$this->flows->update( $id, $data );
		return $this->ok( $this->present( $this->flows->find( $id ) ) );
	}

	public function toggle( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request['id'];
		$flow = $this->flows->find( $id );
		if ( ! $flow ) {
			return $this->fail( __( 'Flow not found.', 'wpintegrations-connector-for-wc-rd-station' ), 404 );
		}
		$this->flows->update( $id, array( 'status' => 'active' === $flow->status ? 'paused' : 'active' ) );
		return $this->ok( $this->present( $this->flows->find( $id ) ) );
	}

	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		if ( ! $this->flows->find( $id ) ) {
			return $this->fail( __( 'Flow not found.', 'wpintegrations-connector-for-wc-rd-station' ), 404 );
		}
		$this->flows->delete( $id );
		return $this->ok();
	}

	/**
	 * Mask secret credentials before a flow leaves the server.
	 *
	 * @param object|null $flow Flow row, or null.
	 * @return object|null
	 */
	private function present( $flow ) {
		if ( ! $flow ) {
			return $flow;
		}
		$action = $this->registry->action( (string) $flow->action_key );
		if ( ! $action ) {
			return $flow;
		}
		$settings = (array) $flow->settings;
		foreach ( $action->connection_fields() as $field ) {
			if ( ! empty( $field['secret'] ) && ! empty( $settings[ $field['key'] ] ) ) {
				$settings[ $field['key'] ] = SettingsController::MASK;
			}
		}
		$flow->settings = $settings;
		return $flow;
	}

	/**
	 * Validate and sanitize the request body.
	 *
	 * @param \WP_REST_Request     $request           Incoming request.
	 * @param array<string, mixed> $existing_settings Stored settings of the flow being updated;
	 *                                                masked secrets keep their stored ciphertext.
	 * @return array|string Sanitized row data, or an error message.
	 */
	private function validate( \WP_REST_Request $request, array $existing_settings = array() ) {
		$body = (array) $request->get_json_params();

		$title       = sanitize_text_field( (string) ( $body['title'] ?? '' ) );
		$trigger_key = sanitize_key( (string) ( $body['trigger_key'] ?? '' ) );
		$action_key  = sanitize_key( (string) ( $body['action_key'] ?? '' ) );

		if ( '' === $title ) {
			return __( 'Title is required.', 'wpintegrations-connector-for-wc-rd-station' );
		}
		if ( ! $this->registry->trigger( $trigger_key ) ) {
			return __( 'Unknown trigger.', 'wpintegrations-connector-for-wc-rd-station' );
		}
		$action = $this->registry->action( $action_key );
		if ( ! $action ) {
			return __( 'Unknown action.', 'wpintegrations-connector-for-wc-rd-station' );
		}

		$trigger_source = sanitize_text_field( (string) ( $body['trigger_source'] ?? '' ) );
		if ( '' === $trigger_source ) {
			return __( 'Pick the order status this integration listens to.', 'wpintegrations-connector-for-wc-rd-station' );
		}

		$field_map = array();
		foreach ( (array) ( $body['field_map'] ?? array() ) as $pair ) {
			$source = sanitize_text_field( (string) ( $pair['source'] ?? '' ) );
			$target = sanitize_text_field( (string) ( $pair['target'] ?? '' ) );
			if ( '' === $source || '' === $target ) {
				continue;
			}
			$entry = array( 'source' => $source, 'target' => $target );
			// Static pairs carry the literal value to send.
			if ( isset( $pair['value'] ) && '' !== (string) $pair['value'] ) {
				$entry['value'] = sanitize_text_field( (string) $pair['value'] );
			}
			$field_map[] = $entry;
		}

		$settings = $this->sanitize_deep( (array) ( $body['settings'] ?? array() ) );

		// The mask means "keep the saved key"; anything else is new and
		// stored encrypted.
		foreach ( $action->connection_fields() as $field ) {
			if ( empty( $field['secret'] ) ) {
				continue;
			}
			$key      = $field['key'];
			$incoming = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			if ( SettingsController::MASK === $incoming ) {
				$settings[ $key ] = (string) ( $existing_settings[ $key ] ?? '' );
			} else {
				$settings[ $key ] = '' === $incoming ? '' : $this->crypto->encrypt( $incoming );
			}
		}

		// Settings this build does not declare never reach the database, so
		// another build's leftovers cannot ride along.
		$allowed = array();
		foreach ( array_merge( $action->flow_fields(), $action->connection_fields() ) as $field ) {
			$allowed[ $field['key'] ] = true;
		}
		$settings = array_intersect_key( $settings, $allowed );

		return array(
			'title'          => $title,
			'trigger_key'    => $trigger_key,
			'trigger_source' => $trigger_source,
			'action_key'     => $action_key,
			'field_map'      => $field_map,
			'settings'       => $settings,
			'status'         => 'paused' === ( $body['status'] ?? '' ) ? 'paused' : 'active',
		);
	}
}
