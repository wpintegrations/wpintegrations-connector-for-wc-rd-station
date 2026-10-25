<?php
/**
 * Routes a trigger event to matching flows and delivers them.
 *
 * Delivery runs on shutdown, after the response is flushed, so a slow RD
 * Station never holds up the visitor. Still the same request, so a failure is
 * logged and not retried.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations;

use WPIntegrations\WcRdStation\Delivery\Dispatcher;
use WPIntegrations\WcRdStation\Models\Flow;

defined( 'ABSPATH' ) || exit;

final class FlowRunner {

	/**
	 * Seconds per request once the visitor has been answered. Shorter than the
	 * default: nobody is waiting, but a hung API still holds a PHP worker.
	 */
	const DELIVERY_TIMEOUT = 8;

	/** @var Flow */
	private $flows;

	/** @var Dispatcher */
	private $dispatcher;

	/**
	 * Submissions matched this request, delivered together on shutdown.
	 *
	 * @var array<int, array{flow:object, payload:array}>
	 */
	private $pending = array();

	/** @var bool Whether the shutdown handler is already attached. */
	private $hooked = false;

	public function __construct( Flow $flows, Dispatcher $dispatcher ) {
		$this->flows      = $flows;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * @param string          $trigger_key Trigger identifier.
	 * @param string|string[] $source      Source id, or a list of aliases for it
	 *                                     (first = canonical id).
	 * @param array           $fields      field_id => value.
	 * @param array           $meta        Request metadata.
	 */
	public function handle( string $trigger_key, $source, array $fields, array $meta = array() ): void {
		$sources = array_values( array_filter( array_map( 'strval', (array) $source ), 'strlen' ) );

		foreach ( $this->flows->for_trigger( $trigger_key, $sources ) as $flow ) {
			$this->pending[] = array(
				'flow'    => $flow,
				'payload' => array(
					'fields' => $fields,
					'meta'   => $meta,
					'source' => $sources[0] ?? '',
				),
			);
		}

		if ( $this->pending && ! $this->hooked ) {
			$this->hooked = true;
			// Priority 0: ahead of other shutdown work and the object cache
			// closing. Checkout and the admin have already written their response,
			// and shutdown actions run after it, so nobody waits on RD Station.
			add_action( 'shutdown', array( $this, 'deliver_pending' ), 0 );
		}
	}

	/**
	 * Deliver everything matched this request.
	 */
	public function deliver_pending(): void {
		$pending       = $this->pending;
		$this->pending = array();

		if ( ! $pending ) {
			return;
		}

		// Detach from the browser where the server allows it, so the success
		// message is not waiting on RD Station. The timeout below bounds the
		// case where it cannot.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}

		$clamp = static function () {
			return self::DELIVERY_TIMEOUT;
		};
		add_filter( 'wpi_wcrds_http_timeout', $clamp, 999 );

		foreach ( $pending as $item ) {
			// Dispatcher catches whatever an action throws: a fatal after the
			// response was flushed would be invisible and hard to trace.
			$this->dispatcher->deliver( $item['flow'], $item['payload'] );
		}

		remove_filter( 'wpi_wcrds_http_timeout', $clamp, 999 );
	}
}
