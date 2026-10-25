<?php
/**
 * WooCommerce trigger: an order reaching a status.
 *
 * Each order status is a source, so an integration listens to "order becomes
 * Processing" (the default) or any other status. An order is sent at most once
 * per status, however often it moves back and forth.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations\Triggers\WooCommerce;

use WPIntegrations\WcRdStation\Integrations\FlowRunner;
use WPIntegrations\WcRdStation\Integrations\Trigger;

defined( 'ABSPATH' ) || exit;

final class WooCommerceTrigger extends Trigger {

	/** Order meta holding the visitor's RD Station tracking values from checkout. */
	const TRACKING_META = '_wpi_wcrds_tracking';

	/** Order meta listing the statuses already dispatched for the order. */
	const SENT_META = '_wpi_wcrds_sent_statuses';

	/** @var WooCommerceOrderFields */
	private $fields;

	public function __construct( FlowRunner $runner, WooCommerceOrderFields $fields ) {
		parent::__construct( $runner );
		$this->fields = $fields;
	}

	public function key(): string {
		return 'woocommerce';
	}

	public function label(): string {
		return __( 'WooCommerce', 'wpintegrations-connector-for-wc-rd-station' );
	}

	public function available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	public function sources( bool $refresh = false ): array {
		return $this->fields->sources( $refresh );
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );

		// The visitor is only present at checkout; later status changes happen
		// in the admin, a payment webhook or cron. Classic and block checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'remember_tracking' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'remember_tracking' ) );
	}

	/**
	 * Store the visitor's tracking values on the order at checkout.
	 *
	 * @param int|\WC_Order $order_or_id Order, or its id on the classic hook.
	 */
	public function remember_tracking( $order_or_id ): void {
		$order = is_object( $order_or_id ) ? $order_or_id : wc_get_order( (int) $order_or_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$tracking = $this->tracking_cookies();
		if ( wp_get_referer() ) {
			$tracking['page_url'] = esc_url_raw( (string) wp_get_referer() );
		}
		if ( ! $tracking ) {
			return;
		}

		$order->update_meta_data( self::TRACKING_META, $tracking );
		$order->save_meta_data();
	}

	/**
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status, without the wc- prefix.
	 * @param string    $to       New status, without the wc- prefix.
	 * @param \WC_Order $order    The order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( (int) $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$to   = sanitize_key( (string) $to );
		$sent = array_values( array_filter( array_map( 'strval', (array) $order->get_meta( self::SENT_META ) ), 'strlen' ) );
		if ( '' === $to || in_array( $to, $sent, true ) ) {
			return;
		}
		$sent[] = $to;
		$order->update_meta_data( self::SENT_META, $sent );
		$order->save_meta_data();

		$values = $this->fields->values( $order );
		$this->remember_shape( $to, array_keys( $values ), (string) wc_get_order_status_name( $to ) );
		$this->dispatch( $to, $values, $this->order_meta( $order ) );
	}

	/**
	 * Delivery metadata. Tracking comes from checkout; the user is the
	 * customer, not whoever changed the status.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function order_meta( $order ): array {
		$created = $order->get_date_created();

		$meta = array(
			'page_url'     => '',
			'submitted_at' => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' ),
			'order_id'     => (int) $order->get_id(),
		);

		$tracking = $order->get_meta( self::TRACKING_META );
		// A gateway can settle the order inside checkout, before the hook that
		// stores tracking; the visitor's cookies are still on this request.
		if ( ! is_array( $tracking ) && ! is_admin() && ! wp_doing_cron() ) {
			$tracking = $this->tracking_cookies();
		}
		if ( is_array( $tracking ) ) {
			foreach ( array( 'page_url', 'client_tracking_id', 'traffic_source' ) as $key ) {
				if ( ! empty( $tracking[ $key ] ) ) {
					$meta[ $key ] = sanitize_text_field( (string) $tracking[ $key ] );
				}
			}
		}

		$user = $order->get_user();
		if ( $user && $user->exists() ) {
			$meta['user_email'] = sanitize_email( (string) $user->user_email );
			$meta['user_name']  = sanitize_text_field( (string) $user->display_name );
		}

		return $meta;
	}

	/**
	 * RD Station's own tracking cookies, so lead attribution survives like a
	 * native RD Station form.
	 */
	private function tracking_cookies(): array {
		$tracking = array();

		// RD Station's tracker keeps a JSON blob in `rdtrk`; the conversion API
		// wants only the visitor UUID inside it.
		$rdtrk = '';
		if ( isset( $_COOKIE['rdtrk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tracking cookie, no state change.
			$rdtrk = sanitize_text_field( wp_unslash( $_COOKIE['rdtrk'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_COOKIE['_rdtrk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$rdtrk = sanitize_text_field( wp_unslash( $_COOKIE['_rdtrk'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/i', $rdtrk, $match ) ) {
			$tracking['client_tracking_id'] = $match[0];
		}
		// PHP stores the `__trf.src` cookie under `__trf_src`.
		if ( isset( $_COOKIE['__trf_src'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tracking['traffic_source'] = sanitize_text_field( wp_unslash( $_COOKIE['__trf_src'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return $tracking;
	}
}
