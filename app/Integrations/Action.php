<?php
/**
 * Base action: delivers a mapped payload to a destination service.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations;

use WPIntegrations\WcRdStation\Delivery\Result;
use WPIntegrations\WcRdStation\Services\Crypto;

defined( 'ABSPATH' ) || exit;

abstract class Action {

	/** Unique action key, e.g. 'rd_station'. */
	abstract public function key(): string;

	/** Human label for the admin UI. */
	abstract public function label(): string;

	/**
	 * Deliver the payload for a flow. Runs inside the queue worker.
	 *
	 * @param object $flow    Flow row (field_map/settings decoded).
	 * @param array  $payload Normalized submission: ['fields' => [...], 'meta' => [...]].
	 */
	abstract public function execute( object $flow, array $payload ): Result;

	/**
	 * Destination fields offered by the mapping UI.
	 *
	 * This build declares only the fields it delivers.
	 *
	 * @return array<int, array{key:string, label:string, required?:bool, group?:string}>
	 */
	public function target_fields(): array {
		return array();
	}

	/**
	 * Global (site-wide) credential fields, e.g. an API key.
	 *
	 * @return array<int, array{key:string, label:string, secret?:bool}>
	 */
	public function global_fields(): array {
		return array();
	}

	/**
	 * Per-flow settings shown in the flow editor, e.g. a conversion identifier.
	 *
	 * Extra keys the editor understands:
	 *  - 'type'           'select' or 'boolean'; anything else is a text input.
	 *                     A boolean stores '1' or '', so read it with empty().
	 *  - 'options_source' Key passed to discover_options(), so a select can
	 *                     offer the account's own values.
	 *  - 'multiple'       The select stores a list instead of a single value.
	 *  - 'depends_on'     field-key => allowed values; the field renders only
	 *                     while it matches. A non-array value means "truthy",
	 *                     which is how a boolean gates the fields under it.
	 *  - 'default'        Applied to new flows only; existing ones are never
	 *                     back-filled.
	 * @return array<int, array{key:string, label:string, help?:string, required?:bool, type?:string, default?:mixed, options?:array, options_source?:string, depends_on?:array<string, array>}>
	 */
	public function flow_fields(): array {
		return array();
	}

	/**
	 * Per-flow connection credentials from the editor's Connect step. Stored in
	 * the flow's settings; secrets are encrypted and masked before they leave.
	 *
	 * @return array<int, array{key:string, label:string, secret?:bool}>
	 */
	public function connection_fields(): array {
		return array();
	}

	/**
	 * Verify credentials against the service without creating data there.
	 *
	 * @param array<string, string> $credentials Plain-text credentials keyed by connection field.
	 */
	public function test_connection( array $credentials ): Result {
		return Result::failure( __( 'This destination does not support connection tests.', 'wpintegrations-connector-for-wc-rd-station' ) );
	}

	/**
	 * The connected account's own fields, customs included, so the mapping UI
	 * can offer more than target_fields().
	 *
	 * @param array<string, string> $credentials Plain-text credentials keyed by connection field.
	 * @param bool                  $refresh     Bypass any cached field list.
	 * @return array<int, array{key:string, label:string, custom:bool}>|null Null when discovery
	 *         is unsupported or unauthorized; callers fall back to target_fields().
	 */
	public function discover_target_fields( array $credentials, bool $refresh = false ): ?array {
		return null;
	}

	/**
	 * Options for a flow_field whose 'options_source' matches $key, fetched from
	 * the service instead of hardcoded.
	 *
	 * @param string               $key      The flow_field's options_source value.
	 * @param array<string, mixed> $settings Current flow settings, so dependent
	 *                                       lists can narrow (e.g. stages within
	 *                                       a chosen pipeline).
	 * @return array<int, array{value:string, label:string}> Empty when the key is
	 *         unknown or the list could not be fetched.
	 */
	public function discover_options( string $key, array $settings ): array {
		return array();
	}

	/** Field-map source meaning "send the pair's literal value". */
	const SOURCE_STATIC = '_static';

	/**
	 * Apply a flow's field map to a submission. A pair's source is a form field,
	 * the static sentinel (its 'value' is sent verbatim), or a _smart_* token.
	 *
	 * @param object $flow    Flow row.
	 * @param array  $payload Normalized submission.
	 * @return array<string, string> target_key => value.
	 */
	protected function map_fields( object $flow, array $payload ): array {
		$fields = (array) ( $payload['fields'] ?? array() );
		$meta   = (array) ( $payload['meta'] ?? array() );
		$mapped = array();

		foreach ( (array) $flow->field_map as $pair ) {
			$source = isset( $pair['source'] ) ? (string) $pair['source'] : '';
			$target = isset( $pair['target'] ) ? (string) $pair['target'] : '';
			if ( '' === $source || '' === $target ) {
				continue;
			}

			if ( self::SOURCE_STATIC === $source ) {
				$value = isset( $pair['value'] ) ? (string) $pair['value'] : '';
				if ( '' === $value ) {
					continue;
				}
			} elseif ( 0 === strpos( $source, '_smart_' ) ) {
				$value = $this->smart_value( $source, $meta );
				if ( null === $value || '' === $value ) {
					continue;
				}
			} elseif ( array_key_exists( $source, $fields ) ) {
				$value = $fields[ $source ];
				if ( is_array( $value ) ) {
					$value = implode( ', ', array_map( 'strval', $value ) );
				}
			} else {
				continue;
			}

			$mapped[ $target ] = (string) $value;
		}

		return $mapped;
	}

	/**
	 * Resolve a _smart_* mapping token. Submission values come from the meta the
	 * trigger captured, since the request is gone by delivery time; site values
	 * are read live. Keep in sync with SMART_SOURCES in Flows.jsx.
	 *
	 * @param array<string, string> $meta Submission meta.
	 */
	protected function smart_value( string $token, array $meta ): ?string {
		switch ( $token ) {
			case '_smart_page_url':
				return isset( $meta['page_url'] ) ? (string) $meta['page_url'] : null;
			case '_smart_submitted_at':
				return isset( $meta['submitted_at'] ) ? (string) $meta['submitted_at'] : null;
			case '_smart_user_email':
				return isset( $meta['user_email'] ) ? (string) $meta['user_email'] : null;
			case '_smart_user_name':
				return isset( $meta['user_name'] ) ? (string) $meta['user_name'] : null;
			case '_smart_site_title':
				return (string) get_bloginfo( 'name' );
			case '_smart_site_url':
				return (string) home_url();
		}
		return null;
	}

	/**
	 * Read a stored global credential, decrypting secrets.
	 */
	protected function credential( string $key ): string {
		$settings = (array) get_option( 'wpi_wcrds_settings', array() );
		$stored   = $settings['actions'][ $this->key() ][ $key ] ?? '';
		if ( '' === $stored ) {
			return '';
		}

		foreach ( $this->global_fields() as $field ) {
			if ( $field['key'] === $key && ! empty( $field['secret'] ) ) {
				return ( new Crypto() )->decrypt( $stored );
			}
		}
		return (string) $stored;
	}

	/**
	 * Read a connection credential stored on a flow, decrypting secrets.
	 *
	 * @param object $flow Flow row (settings decoded).
	 */
	protected function flow_credential( object $flow, string $key ): string {
		$settings = (array) $flow->settings;
		$stored   = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		if ( '' === $stored ) {
			return '';
		}

		foreach ( $this->connection_fields() as $field ) {
			if ( $field['key'] === $key && ! empty( $field['secret'] ) ) {
				return ( new Crypto() )->decrypt( $stored );
			}
		}
		return $stored;
	}
}
