<?php
/**
 * Site-wide RD Station OAuth2 connection.
 *
 * Owns the app credentials, the authorization redirect, the code exchange and
 * transparent refreshing. RD Station issues one set of credentials per product;
 * this plugin connects to RD Station Marketing.
 *
 * Each site registers its own app, so no credentials ship with the plugin and
 * no broker ever sees a token.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations\Actions\RdStation;

use WPIntegrations\WcRdStation\Http\Controllers\SettingsController;
use WPIntegrations\WcRdStation\Services\Crypto;
use WPIntegrations\WcRdStation\Services\Http;

defined( 'ABSPATH' ) || exit;

/**
 * The RD Station Marketing OAuth connection.
 */
final class RdStationOAuth {

	const DIALOG_ENDPOINT = 'https://api.rd.services/auth/dialog';
	const TOKEN_ENDPOINT  = 'https://api.rd.services/auth/token';

	/** Refresh this many seconds before the token actually expires. */
	const EXPIRY_MARGIN = 120;

	/** Lifetime of the CSRF state token issued on Connect. */
	const STATE_TTL = 900;

	const STATE_TRANSIENT = 'wpi_wcrds_oauth_state';

	/**
	 * The HTTP transport.
	 *
	 * @var Http
	 */
	private $http;

	/**
	 * Token encryption helper.
	 *
	 * @var Crypto
	 */
	private $crypto;

	/**
	 * Wire up dependencies.
	 *
	 * @param Http   $http   The HTTP transport.
	 * @param Crypto $crypto Token encryption helper.
	 */
	public function __construct( Http $http, Crypto $crypto ) {
		$this->http   = $http;
		$this->crypto = $crypto;
	}

	/**
	 * Callback URL to register in RD Station. Path-based, as the App Store form
	 * requires.
	 */
	public function redirect_uri(): string {
		return rest_url( WPI_WCRDS_REST_NS . '/oauth/callback' );
	}

	/**
	 * The RD Station product this connection targets.
	 *
	 * @return string
	 */
	public function product(): string {
		return 'marketing';
	}

	/**
	 * Authorization (consent) endpoint.
	 *
	 * @return string
	 */
	protected function dialog_endpoint(): string {
		return self::DIALOG_ENDPOINT;
	}

	/**
	 * Token endpoint.
	 *
	 * @return string
	 */
	protected function token_endpoint(): string {
		return self::TOKEN_ENDPOINT;
	}

	/**
	 * Storage key for this connection's config block.
	 *
	 * @return string
	 */
	protected function config_key(): string {
		return 'oauth';
	}

	/**
	 * Transient key for this connection's CSRF state token.
	 *
	 * @return string
	 */
	protected function state_transient(): string {
		return self::STATE_TRANSIENT;
	}

	/**
	 * The app's client id.
	 *
	 * @return string
	 */
	public function client_id(): string {
		return (string) ( $this->config()['client_id'] ?? '' );
	}

	/**
	 * The app's decrypted client secret.
	 *
	 * @return string
	 */
	public function client_secret(): string {
		$stored = (string) ( $this->config()['client_secret'] ?? '' );
		return '' === $stored ? '' : $this->crypto->decrypt( $stored );
	}

	/**
	 * Whether app credentials have been saved.
	 *
	 * @return bool
	 */
	public function has_credentials(): bool {
		return '' !== $this->client_id() && '' !== $this->client_secret();
	}

	/**
	 * Whether a connected account's tokens are stored.
	 *
	 * @return bool
	 */
	public function connected(): bool {
		return '' !== (string) ( $this->config()['refresh_token'] ?? '' );
	}

	/**
	 * Store the app credentials. An empty secret keeps the stored one, so the
	 * browser can send back a mask instead of the real value.
	 *
	 * @param string $client_id     The app's client id.
	 * @param string $client_secret The app's client secret, or '' to keep the stored one.
	 */
	public function save_credentials( string $client_id, string $client_secret ): void {
		$config              = $this->config();
		$config['client_id'] = $client_id;
		if ( '' !== $client_secret ) {
			$config['client_secret'] = $this->crypto->encrypt( $client_secret );
		}
		$this->save( $config );
	}

	/**
	 * Mint a single-use state token and build the consent URL.
	 *
	 * @return string
	 */
	public function authorize_url(): string {
		$state = wp_generate_uuid4();
		set_transient( $this->state_transient(), $state, self::STATE_TTL );

		$query = array(
			'client_id'    => rawurlencode( $this->client_id() ),
			'redirect_uri' => rawurlencode( $this->redirect_uri() ),
			'state'        => rawurlencode( $state ),
		);

		return add_query_arg( $query, $this->dialog_endpoint() );
	}

	/**
	 * Whether the state matches this connection's issued one, without consuming it.
	 * The callback uses it to find which product a return trip belongs to.
	 *
	 * @param string $state State token returned by RD Station.
	 * @return bool
	 */
	public function state_matches( string $state ): bool {
		$expected = (string) get_transient( $this->state_transient() );
		return '' !== $expected && hash_equals( $expected, $state );
	}

	/**
	 * Check the returned state against the issued one; single use, so a valid
	 * state is consumed immediately.
	 *
	 * @param string $state State token returned by RD Station.
	 * @return bool
	 */
	public function verify_state( string $state ): bool {
		$valid = $this->state_matches( $state );
		delete_transient( $this->state_transient() );
		return $valid;
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code Authorization code returned by RD Station.
	 * @return string Empty on success, otherwise an error message.
	 */
	public function exchange_code( string $code ): string {
		$body = array(
			'client_id'     => $this->client_id(),
			'client_secret' => $this->client_secret(),
			'code'          => $code,
		);

		$response = $this->http->post_json( $this->token_endpoint(), $body );

		return $this->store_tokens( $response );
	}

	/**
	 * Trade the refresh token for a new access token.
	 *
	 * @return string Empty on success, otherwise an error message.
	 */
	public function refresh(): string {
		$refresh_token = $this->refresh_token();
		if ( '' === $refresh_token ) {
			return __( 'No refresh token stored — reconnect to RD Station.', 'wpintegrations-connector-for-wc-rd-station' );
		}

		$response = $this->http->post_json(
			$this->token_endpoint(),
			array(
				'client_id'     => $this->client_id(),
				'client_secret' => $this->client_secret(),
				'refresh_token' => $refresh_token,
				'grant_type'    => 'refresh_token',
			)
		);

		return $this->store_tokens( $response );
	}

	/**
	 * A usable access token, refreshing first if it is expired or near it.
	 *
	 * @return string
	 */
	public function access_token(): string {
		$config  = $this->config();
		$token   = (string) ( $config['access_token'] ?? '' );
		$expires = (int) ( $config['expires_at'] ?? 0 );

		if ( '' === $token || $expires <= time() + self::EXPIRY_MARGIN ) {
			if ( '' !== $this->refresh() ) {
				return '';
			}
			$token = (string) ( $this->config()['access_token'] ?? '' );
		}

		return '' === $token ? '' : $this->crypto->decrypt( $token );
	}

	/**
	 * Authorized GET. A 401 means a stale token: refresh once, then retry.
	 *
	 * @param string $url Absolute API URL.
	 * @return object|null Normalized Http response, or null when not connected.
	 */
	public function get( string $url ) {
		$token = $this->access_token();
		if ( '' === $token ) {
			return null;
		}

		$response = $this->http->get( $url, array( 'Authorization' => 'Bearer ' . $token ) );

		if ( 401 === $response->code && '' === $this->refresh() ) {
			$token = $this->access_token();
			if ( '' !== $token ) {
				$response = $this->http->get( $url, array( 'Authorization' => 'Bearer ' . $token ) );
			}
		}

		return $response;
	}

	/**
	 * Authorized POST, with the same refresh-once-on-401 behaviour as get().
	 *
	 * @param string $url  Absolute API URL.
	 * @param array  $body JSON-encodable body.
	 * @return object|null Normalized Http response, or null when not connected.
	 */
	public function post( string $url, array $body ) {
		$token = $this->access_token();
		if ( '' === $token ) {
			return null;
		}

		$response = $this->http->post_json( $url, $body, array( 'Authorization' => 'Bearer ' . $token ) );

		if ( 401 === $response->code && '' === $this->refresh() ) {
			$token = $this->access_token();
			if ( '' !== $token ) {
				$response = $this->http->post_json( $url, $body, array( 'Authorization' => 'Bearer ' . $token ) );
			}
		}

		return $response;
	}

	/**
	 * Authorized PUT, with the same refresh-once-on-401 behaviour as get().
	 *
	 * @param string $url  Absolute API URL.
	 * @param array  $body JSON-encodable body.
	 * @return object|null Normalized Http response, or null when not connected.
	 */
	public function put( string $url, array $body ) {
		$token = $this->access_token();
		if ( '' === $token ) {
			return null;
		}

		$response = $this->http->put_json( $url, $body, array( 'Authorization' => 'Bearer ' . $token ) );

		if ( 401 === $response->code && '' === $this->refresh() ) {
			$token = $this->access_token();
			if ( '' !== $token ) {
				$response = $this->http->put_json( $url, $body, array( 'Authorization' => 'Bearer ' . $token ) );
			}
		}

		return $response;
	}

	/**
	 * Drop the tokens but keep the app credentials, so reconnecting does not
	 * mean re-entering them.
	 */
	public function disconnect(): void {
		$config = $this->config();
		unset( $config['access_token'], $config['refresh_token'], $config['expires_at'], $config['connected_at'] );
		$this->save( $config );
	}

	/**
	 * Connection state for the admin UI. Never exposes a secret: only its
	 * last characters, so the owner can tell which one is saved.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$config = $this->config();

		return array(
			'product'         => $this->product(),
			'has_credentials' => $this->has_credentials(),
			'connected'       => $this->connected(),
			'client_id'       => $this->client_id(),
			'has_secret'      => '' !== (string) ( $config['client_secret'] ?? '' ),
			'secret_mask'     => '' !== (string) ( $config['client_secret'] ?? '' ) ? SettingsController::mask( $this->client_secret() ) : '',
			'redirect_uri'    => $this->redirect_uri(),
			'connected_at'    => (string) ( $config['connected_at'] ?? '' ),
			'expires_at'      => (int) ( $config['expires_at'] ?? 0 ),
		);
	}

	/**
	 * The decrypted refresh token.
	 *
	 * @return string
	 */
	private function refresh_token(): string {
		$stored = (string) ( $this->config()['refresh_token'] ?? '' );
		return '' === $stored ? '' : $this->crypto->decrypt( $stored );
	}

	/**
	 * Persist tokens from a token-endpoint response.
	 *
	 * @param object $response Normalized Http response.
	 * @return string Empty on success, otherwise an error message.
	 */
	private function store_tokens( object $response ): string {
		if ( ! $response->ok || ! is_array( $response->body ) ) {
			$detail = is_array( $response->body ) ? wp_json_encode( $response->body ) : (string) $response->body;
			if ( $response->error ) {
				$detail = $response->error;
			}
			/* translators: 1: HTTP status code, 2: error detail. */
			return sprintf( __( 'RD Station rejected the connection (HTTP %1$d): %2$s', 'wpintegrations-connector-for-wc-rd-station' ), $response->code, mb_substr( (string) $detail, 0, 300 ) );
		}

		$access = (string) ( $response->body['access_token'] ?? '' );
		if ( '' === $access ) {
			return __( 'RD Station did not return an access token.', 'wpintegrations-connector-for-wc-rd-station' );
		}

		$config                 = $this->config();
		$config['access_token'] = $this->crypto->encrypt( $access );
		$config['expires_at']   = time() + (int) ( $response->body['expires_in'] ?? DAY_IN_SECONDS );
		$config['connected_at'] = current_time( 'mysql', true );

		// A refresh does not always return a new refresh token; keep the stored one.
		$refresh = (string) ( $response->body['refresh_token'] ?? '' );
		if ( '' !== $refresh ) {
			$config['refresh_token'] = $this->crypto->encrypt( $refresh );
		}

		$this->save( $config );
		return '';
	}

	/**
	 * This slot's stored config block.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		$settings = (array) get_option( 'wpi_wcrds_settings', array() );
		return (array) ( $settings[ $this->config_key() ] ?? array() );
	}

	/**
	 * Persist this connection's config block.
	 *
	 * @param array<string, mixed> $config OAuth config block.
	 */
	private function save( array $config ): void {
		$settings                        = (array) get_option( 'wpi_wcrds_settings', array() );
		$settings[ $this->config_key() ] = $config;
		update_option( 'wpi_wcrds_settings', $settings, false );
	}
}
