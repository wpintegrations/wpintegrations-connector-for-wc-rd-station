<?php
/**
 * Global settings, including per-action credentials. Secrets are stored
 * encrypted; the browser only ever sees a mask ending in the secret's last few
 * characters, so the owner can tell which one is saved.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

use WPIntegrations\WcRdStation\Integrations\Registry;
use WPIntegrations\WcRdStation\Services\Crypto;

defined( 'ABSPATH' ) || exit;

final class SettingsController extends Controller {

	const MASK = '••••••••';

	/** @var Registry */
	private $registry;

	/** @var Crypto */
	private $crypto;

	public function __construct( Registry $registry, Crypto $crypto ) {
		$this->registry = $registry;
		$this->crypto   = $crypto;
	}

	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = (array) get_option( 'wpi_wcrds_settings', array() );

		// Mask stored secrets before they leave the server.
		foreach ( $this->registry->actions() as $action ) {
			foreach ( $action->global_fields() as $field ) {
				if ( empty( $field['secret'] ) ) {
					continue;
				}
				$stored = (string) ( $settings['actions'][ $action->key() ][ $field['key'] ] ?? '' );
				if ( '' !== $stored ) {
					$settings['actions'][ $action->key() ][ $field['key'] ] = self::mask( $this->crypto->decrypt( $stored ) );
				}
			}
		}

		$settings['log_retention_days'] = (int) ( $settings['log_retention_days'] ?? 30 );

		return $this->ok( $settings );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$body     = (array) $request->get_json_params();
		$existing = (array) get_option( 'wpi_wcrds_settings', array() );

		$settings = array(
			'log_retention_days' => min( 365, max( 1, (int) ( $body['log_retention_days'] ?? 30 ) ) ),
			// Start from what's stored, so credentials no longer declared as
			// global fields (a legacy API key, say) survive a save.
			'actions'            => (array) ( $existing['actions'] ?? array() ),
			// OAuthController owns the connections; saving settings must not
			// clear either product's slot.
			'oauth'              => (array) ( $existing['oauth'] ?? array() ),
		);

		foreach ( $this->registry->actions() as $action ) {
			foreach ( $action->global_fields() as $field ) {
				$key      = $field['key'];
				$incoming = isset( $body['actions'][ $action->key() ][ $key ] ) ? (string) $body['actions'][ $action->key() ][ $key ] : '';
				$incoming = sanitize_text_field( $incoming );

				if ( ! empty( $field['secret'] ) ) {
					$stored = (string) ( $existing['actions'][ $action->key() ][ $key ] ?? '' );
					if ( '' !== $stored && self::mask( $this->crypto->decrypt( $stored ) ) === $incoming ) {
						// Unchanged, so keep the stored ciphertext.
						$settings['actions'][ $action->key() ][ $key ] = $stored;
					} else {
						$settings['actions'][ $action->key() ][ $key ] = '' === $incoming ? '' : $this->crypto->encrypt( $incoming );
					}
				} else {
					$settings['actions'][ $action->key() ][ $key ] = $incoming;
				}
			}
		}

		update_option( 'wpi_wcrds_settings', $settings, false );

		return $this->show( $request );
	}

	/**
	 * What the browser sees of a saved secret: stars and its last six
	 * characters, or stars alone for one too short to spare them.
	 *
	 * @param string $secret Decrypted secret.
	 */
	public static function mask( string $secret ): string {
		return str_repeat( '*', 10 ) . ( strlen( $secret ) > 18 ? substr( $secret, -6 ) : '' );
	}
}
