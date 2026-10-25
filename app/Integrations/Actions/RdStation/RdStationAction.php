<?php

/**
 * Sends WooCommerce orders to RD Station as CDP conversion events.
 *
 * Uses /platform/events over the older /platform/conversions: same payload, but
 * it accepts OAuth, so one connection covers delivery and field discovery.
 * Tracking cookies captured at checkout preserve lead attribution.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations\Actions\RdStation;

use WPIntegrations\WcRdStation\Integrations\Action;
use WPIntegrations\WcRdStation\Delivery\Result;
use WPIntegrations\WcRdStation\Services\Http;

defined('ABSPATH') || exit;

final class RdStationAction extends Action
{

	const ENDPOINT = 'https://api.rd.services/platform/events';

	const FIELDS_ENDPOINT = 'https://api.rd.services/platform/contacts/fields';

	/** How long the discovered field list stays cached. */
	const FIELDS_CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** @var Http */
	private $http;

	/** @var RdStationOAuth */
	private $oauth;

	public function __construct(Http $http, RdStationOAuth $oauth)
	{
		$this->http  = $http;
		$this->oauth = $oauth;
	}

	public function key(): string
	{
		return 'rd_station';
	}

	public function label(): string
	{
		return __('RD Station Marketing', 'wpintegrations-connector-for-wc-rd-station');
	}

	/**
	 * Per-integration settings this build delivers. Nothing is declared that
	 * execute() does not send.
	 */
	public function flow_fields(): array
	{
		return array(
			array(
				'key'   => 'conversion_identifier',
				'label' => __('Conversion identifier', 'wpintegrations-connector-for-wc-rd-station'),
				'help'  => __('The conversion event name shown in RD Station (e.g. "woocommerce-order"). Defaults to the integration name.', 'wpintegrations-connector-for-wc-rd-station'),
			),
		);
	}

	public function target_fields(): array
	{
		$fields = array(
			array('key' => 'email', 'label' => __('Email', 'wpintegrations-connector-for-wc-rd-station'), 'required' => true),
			array('key' => 'name', 'label' => __('Name', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'mobile_phone', 'label' => __('Mobile phone', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'personal_phone', 'label' => __('Personal phone', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'job_title', 'label' => __('Job title', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'company_name', 'label' => __('Company name', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'company_site', 'label' => __('Company website', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'city', 'label' => __('City', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'state', 'label' => __('State', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'country', 'label' => __('Country', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'website', 'label' => __('Website', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'linkedin', 'label' => __('LinkedIn', 'wpintegrations-connector-for-wc-rd-station')),
			array('key' => 'legal_consent', 'label' => __('LGPD consent checkbox', 'wpintegrations-connector-for-wc-rd-station')),
		);
		return $fields;
	}

	/**
	 * Verify the OAuth connection can deliver. Sends a deliberately incomplete
	 * event: RD Station authenticates before validating, so a 400 proves the
	 * token works without creating a contact.
	 */
	public function test_connection(array $credentials): Result
	{
		if (! $this->oauth->connected()) {
			return Result::failure(__('Connect your RD Station account in Settings first.', 'wpintegrations-connector-for-wc-rd-station'));
		}

		$response = $this->oauth->post(
			self::ENDPOINT,
			array(
				'event_type'   => 'CONVERSION',
				'event_family' => 'CDP',
				'payload'      => array('conversion_identifier' => 'wpi-connection-test'),
			)
		);

		if (! $response) {
			return Result::failure(__('Could not obtain an RD Station access token — reconnect in Settings.', 'wpintegrations-connector-for-wc-rd-station'));
		}
		if (0 === $response->code) {
			/* translators: %s: transport error message. */
			return Result::failure(sprintf(__('Could not reach RD Station: %s', 'wpintegrations-connector-for-wc-rd-station'), $response->error));
		}
		if (401 === $response->code || 403 === $response->code) {
			return Result::failure(__('RD Station rejected the connection. Reconnect your account in Settings.', 'wpintegrations-connector-for-wc-rd-station'));
		}
		if ($response->ok || 400 === $response->code || 422 === $response->code) {
			return Result::success(__('Connection successful — RD Station accepted the request.', 'wpintegrations-connector-for-wc-rd-station'));
		}

		$detail = is_array($response->body) ? wp_json_encode($response->body) : (string) $response->body;
		/* translators: 1: HTTP status code, 2: error detail. */
		return Result::failure(sprintf(__('Unexpected RD Station response (HTTP %1$d): %2$s', 'wpintegrations-connector-for-wc-rd-station'), $response->code, mb_substr($detail, 0, 300)));
	}

	/**
	 * The account's contact fields (standard and custom cf_*) for the mapping UI.
	 * Requires OAuth — this endpoint 401s on an API key. Without a connection the
	 * UI falls back to the static targets plus free-text cf_* entry.
	 */
	public function discover_target_fields(array $credentials, bool $refresh = false): ?array
	{
		if (! $this->oauth->connected()) {
			return null;
		}

		$cache_key = 'wpi_wcrds_rd_fields_oauth';
		if ($refresh) {
			delete_transient($cache_key);
		}
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached ? $cached : null;
		}

		$response = $this->oauth->get(self::FIELDS_ENDPOINT);

		if (! $response || ! $response->ok || ! is_array($response->body)) {
			// Cache the miss briefly so every drawer open doesn't re-probe.
			set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
			return null;
		}

		$fields = array();
		foreach ((array) ($response->body['fields'] ?? array()) as $field) {
			$identifier = (string) ($field['api_identifier'] ?? '');
			if ('' === $identifier) {
				continue;
			}
			$name  = $field['name'] ?? array();
			$label = is_array($name)
				? (string) ($name['default'] ?? ($name['pt-BR'] ?? reset($name)))
				: (string) $name;

			$fields[] = array(
				'key'    => $identifier,
				'label'  => '' !== $label ? $label : $identifier,
				'custom' => 0 === strpos($identifier, 'cf_'),
			);
		}

		if (! $fields) {
			set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
			return null;
		}

		usort(
			$fields,
			static function (array $a, array $b): int {
				return strcasecmp($a['label'], $b['label']);
			}
		);

		set_transient($cache_key, $fields, self::FIELDS_CACHE_TTL);
		return $fields;
	}

	public function execute(object $flow, array $payload): Result
	{
		if (! $this->oauth->connected()) {
			return Result::failure(__('RD Station is not connected — connect your account under Settings.', 'wpintegrations-connector-for-wc-rd-station'));
		}

		$mapped = $this->map_fields($flow, $payload);
		if (empty($mapped['email']) || ! is_email($mapped['email'])) {
			return Result::failure(__('No valid email mapped — RD Station requires an email for conversions.', 'wpintegrations-connector-for-wc-rd-station'));
		}

		$meta = (array) ($payload['meta'] ?? array());

		// The integration's own identifier when set, otherwise its name.
		$settings   = (array) ($flow->settings ?? array());
		$identifier = sanitize_title((string) ($settings['conversion_identifier'] ?? ''));
		if ('' === $identifier) {
			$identifier = sanitize_title((string) $flow->title);
		}
		$body = array('conversion_identifier' => $identifier);

		// LGPD: a mapped, non-empty checkbox becomes a granted legal basis.
		$consent = isset($mapped['legal_consent']) ? trim((string) $mapped['legal_consent']) : '';
		unset($mapped['legal_consent']);
		if ('' !== $consent && 'off' !== strtolower($consent) && 'false' !== strtolower($consent)) {
			$body['legal_bases'] = array(
				array(
					'category' => 'communications',
					'type'     => 'consent',
					'status'   => 'granted',
				),
			);
			$body['available_for_mailing'] = true;
		}

		$body = array_merge($body, $mapped);

		// Attribution captured at submission time, so funnel reports stay accurate.
		if (! empty($meta['client_tracking_id'])) {
			$body['client_tracking_id'] = (string) $meta['client_tracking_id'];
		}
		if (! empty($meta['traffic_source'])) {
			$body['traffic_source'] = (string) $meta['traffic_source'];
		}

		$response = $this->oauth->post(
			self::ENDPOINT,
			array(
				'event_type'   => 'CONVERSION',
				'event_family' => 'CDP',
				'payload'      => $body,
			)
		);

		$request_excerpt = array('endpoint' => self::ENDPOINT, 'payload' => $body);

		if (! $response) {
			return Result::failure(
				__('Could not obtain an RD Station access token — reconnect your account under Settings.', 'wpintegrations-connector-for-wc-rd-station'),
				$request_excerpt
			);
		}

		if ($response->ok) {
			// The event UUID stays in the logged response body, so the log's
			// summary line stays readable.
			return Result::success(
				__('Conversion sent to RD Station.', 'wpintegrations-connector-for-wc-rd-station'),
				$request_excerpt,
				array('code' => $response->code, 'body' => $response->body)
			);
		}

		// A 401 here already survived a refresh-and-retry, so the connection
		// needs attention.
		if (401 === $response->code) {
			$message = __('RD Station rejected the connection (401). Reconnect your account under Settings.', 'wpintegrations-connector-for-wc-rd-station');
		} elseif ($response->error) {
			$message = $response->error;
		} else {
			$detail  = is_array($response->body) ? wp_json_encode($response->body) : (string) $response->body;
			/* translators: 1: HTTP status code, 2: error detail. */
			$message = sprintf(__('RD Station error (HTTP %1$d): %2$s', 'wpintegrations-connector-for-wc-rd-station'), $response->code, mb_substr($detail, 0, 300));
		}

		return Result::failure($message, $request_excerpt, array('code' => $response->code, 'body' => $response->body));
	}

}
