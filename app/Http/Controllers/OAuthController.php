<?php
/**
 * RD Station OAuth2 connection endpoints.
 *
 * The callback is the plugin's one public route: RD Station redirects the
 * browser to it, so it carries no nonce and is authenticated by the single-use
 * state token instead. Both products share the callback; the state token says
 * whose code is being exchanged.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

use WPIntegrations\WcRdStation\Integrations\Actions\RdStation\RdStationOAuth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth2 connection endpoints.
 */
final class OAuthController extends Controller {

	/**
	 * The Marketing connection slot.
	 *
	 * @var RdStationOAuth
	 */
	private $marketing;

	/**
	 * Wire up dependencies.
	 *
	 * @param RdStationOAuth $marketing The RD Station Marketing connection.
	 */
	public function __construct( RdStationOAuth $marketing ) {
		$this->marketing = $marketing;
	}

	/**
	 * The connection every route in this controller acts on.
	 */
	private function oauth(): RdStationOAuth {
		return $this->marketing;
	}

	/**
	 * Connection state for one product.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( $this->oauth()->status() );
	}

	/**
	 * Save the app credentials created in the RD Station App Store.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function credentials( \WP_REST_Request $request ): \WP_REST_Response {
		$oauth     = $this->oauth();
		$body      = (array) $request->get_json_params();
		$client_id = sanitize_text_field( (string) ( $body['client_id'] ?? '' ) );
		$secret    = sanitize_text_field( (string) ( $body['client_secret'] ?? '' ) );

		if ( '' === $client_id ) {
			return $this->fail( __( 'Enter the Client ID from your RD Station app.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		// The mask the status call sent means "keep the stored secret".
		if ( SettingsController::MASK === $secret || ( $oauth->client_secret() && SettingsController::mask( $oauth->client_secret() ) === $secret ) ) {
			$secret = '';
		}

		$oauth->save_credentials( $client_id, $secret );

		if ( ! $oauth->has_credentials() ) {
			return $this->fail( __( 'Enter the Client Secret from your RD Station app.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		return $this->ok( $oauth->status() );
	}

	/**
	 * Build the consent URL to send the browser to.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function authorize( \WP_REST_Request $request ): \WP_REST_Response {
		$oauth = $this->oauth();

		if ( ! $oauth->has_credentials() ) {
			return $this->fail( __( 'Save your Client ID and Client Secret before connecting.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		return $this->ok( array( 'url' => $oauth->authorize_url() ) );
	}

	/**
	 * Drop the stored tokens for one product.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		$oauth = $this->oauth();
		$oauth->disconnect();
		return $this->ok( $oauth->status() );
	}

	/**
	 * OAuth redirect target: validate the state, exchange the code, then send
	 * the browser back to the plugin screen.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function callback( \WP_REST_Request $request ) {
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );

		$oauth = $this->oauth();

		if ( ! $oauth->state_matches( $state ) ) {
			return $this->redirect( __( 'The connection request expired or did not come from this site. Please try again.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		// Single use: consume the state before the code is touched, so no
		// outcome of this trip can be replayed.
		$oauth->verify_state( $state );

		if ( '' !== $error ) {
			/* translators: %s: error returned by RD Station. */
			return $this->redirect( sprintf( __( 'RD Station denied the connection: %s', 'wpintegrations-connector-for-wc-rd-station' ), $error ) );
		}

		if ( '' === $code ) {
			return $this->redirect( __( 'RD Station did not return an authorization code.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		$failure = $oauth->exchange_code( $code );

		return $this->redirect( $failure );
	}

	/**
	 * Redirect back to the Settings tab, which shows the outcome.
	 *
	 * @param string $error Error message, or '' on success.
	 */
	private function redirect( string $error ) {
		$url = add_query_arg(
			'' === $error
				? array( 'wpi_wcrds_oauth' => 'connected' )
				: array(
					'wpi_wcrds_oauth'       => 'error',
					'wpi_wcrds_oauth_error' => rawurlencode( $error ),
				),
			admin_url( 'admin.php?page=' . WPI_WCRDS_SLUG . '-settings' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}
