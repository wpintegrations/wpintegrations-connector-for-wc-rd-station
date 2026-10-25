<?php
/**
 * The order fields an integration can map, and their values for one order.
 *
 * Every order status is a "source" the admin UI can pick, and each one offers
 * the same order fields. Custom checkout fields are discovered from the meta of
 * recent orders, so fields added by checkout plugins (CPF/CNPJ, consent boxes)
 * can be mapped without typing their keys.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations\Triggers\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class WooCommerceOrderFields {

	const CACHE_KEY = 'wpi_wcrds_wc_fields';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Prefix of a field id that reads an order meta key. */
	const META_PREFIX = 'meta:';

	/** How many recent orders are read to discover custom checkout fields. */
	const DISCOVERY_ORDERS = 20;

	/**
	 * Address keys WooCommerce stores for both billing and shipping.
	 */
	const ADDRESS_KEYS = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );

	/**
	 * Order statuses as sources, Processing first: it is the status a paid order
	 * reaches, so it is the sensible default for a new integration.
	 *
	 * @param bool $refresh Bypass the cache and rediscover custom fields.
	 * @return array[] Each: id, name, fields[] of {id, label, type}.
	 */
	public function sources( bool $refresh = false ): array {
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return array();
		}

		$fields   = $this->fields( $refresh );
		$statuses = (array) wc_get_order_statuses();

		$sources = array();
		foreach ( $statuses as $key => $label ) {
			$id = 0 === strpos( (string) $key, 'wc-' ) ? substr( (string) $key, 3 ) : (string) $key;
			// Drafts are checkouts still in progress, never an order to send.
			if ( 'checkout-draft' === $id ) {
				continue;
			}
			$sources[ $id ] = array(
				'id'     => $id,
				'name'   => sanitize_text_field( (string) $label ),
				'fields' => $fields,
			);
		}

		if ( isset( $sources['processing'] ) ) {
			$sources = array( 'processing' => $sources['processing'] ) + $sources;
		}

		return array_values( $sources );
	}

	/**
	 * Drop the cached field list, e.g. after checkout fields change.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * All mappable order fields: the fixed set plus discovered custom meta.
	 *
	 * @param bool $refresh Bypass the cache.
	 * @return array[] Each: id, label, type.
	 */
	public function fields( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$fields = array_merge( $this->fixed_fields(), $this->meta_fields() );
		set_transient( self::CACHE_KEY, $fields, self::CACHE_TTL );

		return $fields;
	}

	/**
	 * Values for one order, keyed by field id.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<string, string>
	 */
	public function values( $order ): array {
		$values = array(
			'order_id'             => (string) $order->get_id(),
			'order_number'         => (string) $order->get_order_number(),
			'order_status'         => (string) wc_get_order_status_name( $order->get_status() ),
			'order_total'          => wc_format_decimal( $order->get_total(), wc_get_price_decimals() ),
			'order_subtotal'       => wc_format_decimal( $order->get_subtotal(), wc_get_price_decimals() ),
			'order_discount_total' => wc_format_decimal( $order->get_discount_total(), wc_get_price_decimals() ),
			'order_shipping_total' => wc_format_decimal( $order->get_shipping_total(), wc_get_price_decimals() ),
			'order_tax_total'      => wc_format_decimal( $order->get_total_tax(), wc_get_price_decimals() ),
			'order_currency'       => (string) $order->get_currency(),
			'payment_method'       => (string) $order->get_payment_method_title(),
			'shipping_method'      => (string) $order->get_shipping_method(),
			'coupon_codes'         => implode( ', ', array_map( 'strval', (array) $order->get_coupon_codes() ) ),
			'customer_note'        => (string) $order->get_customer_note(),
			'order_date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
			'customer_id'          => (string) $order->get_customer_id(),
			'billing_full_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email'        => (string) $order->get_billing_email(),
		);

		$names = array();
		$skus  = array();
		$count = 0;
		foreach ( $order->get_items() as $item ) {
			$qty     = (int) $item->get_quantity();
			$count  += $qty;
			$names[] = sprintf( '%s × %d', $item->get_name(), $qty );
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product && '' !== (string) $product->get_sku() ) {
				$skus[] = (string) $product->get_sku();
			}
		}
		$values['order_items']      = implode( ', ', $names );
		$values['order_item_skus']  = implode( ', ', $skus );
		$values['order_item_count'] = (string) $count;

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( self::ADDRESS_KEYS as $key ) {
				$getter = "get_{$type}_{$key}";
				if ( is_callable( array( $order, $getter ) ) ) {
					$values[ "{$type}_{$key}" ] = (string) $order->$getter();
				}
			}
		}

		// WooCommerce's own order attribution (8.5+), when the store keeps it.
		foreach ( array( 'source_type', 'utm_source', 'utm_medium', 'utm_campaign' ) as $key ) {
			$values[ "attribution_{$key}" ] = (string) $order->get_meta( "_wc_order_attribution_{$key}" );
		}

		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) ( $data['key'] ?? '' );
			if ( ! $this->is_custom_meta( $key ) ) {
				continue;
			}
			$value = $data['value'] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			if ( is_scalar( $value ) ) {
				$values[ self::META_PREFIX . $key ] = (string) $value;
			}
		}

		return array_map( 'sanitize_text_field', $values );
	}

	/**
	 * The fields every order has.
	 *
	 * @return array[]
	 */
	private function fixed_fields(): array {
		$fields = array(
			array( 'billing_email', __( 'Billing email', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_first_name', __( 'Billing first name', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_last_name', __( 'Billing last name', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_full_name', __( 'Billing full name', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_phone', __( 'Billing phone', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_company', __( 'Billing company', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_address_1', __( 'Billing address line 1', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_address_2', __( 'Billing address line 2', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_city', __( 'Billing city', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_state', __( 'Billing state', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_postcode', __( 'Billing postcode', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'billing_country', __( 'Billing country', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_first_name', __( 'Shipping first name', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_last_name', __( 'Shipping last name', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_company', __( 'Shipping company', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_phone', __( 'Shipping phone', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_address_1', __( 'Shipping address line 1', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_address_2', __( 'Shipping address line 2', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_city', __( 'Shipping city', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_state', __( 'Shipping state', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_postcode', __( 'Shipping postcode', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_country', __( 'Shipping country', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_id', __( 'Order ID', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_number', __( 'Order number', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_status', __( 'Order status', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_date', __( 'Order date', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_total', __( 'Order total', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_subtotal', __( 'Order subtotal', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_discount_total', __( 'Discount total', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_shipping_total', __( 'Shipping total', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_tax_total', __( 'Tax total', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_currency', __( 'Currency', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_items', __( 'Products (name × quantity)', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_item_skus', __( 'Product SKUs', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'order_item_count', __( 'Number of items', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'coupon_codes', __( 'Coupon codes', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'payment_method', __( 'Payment method', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'shipping_method', __( 'Shipping method', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'customer_note', __( 'Customer note', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'customer_id', __( 'Customer user ID', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'attribution_source_type', __( 'Attribution: source type', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'attribution_utm_source', __( 'Attribution: UTM source', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'attribution_utm_medium', __( 'Attribution: UTM medium', 'wpintegrations-connector-for-wc-rd-station' ) ),
			array( 'attribution_utm_campaign', __( 'Attribution: UTM campaign', 'wpintegrations-connector-for-wc-rd-station' ) ),
		);

		return array_map(
			static function ( array $field ): array {
				return array(
					'id'    => $field[0],
					'label' => $field[1],
					'type'  => 'order',
				);
			},
			$fields
		);
	}

	/**
	 * Custom checkout fields seen on recent orders.
	 *
	 * @return array[]
	 */
	private function meta_fields(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = (array) wc_get_orders(
			array(
				'limit'   => self::DISCOVERY_ORDERS,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);

		$keys = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_meta_data() as $meta ) {
				$key = (string) ( $meta->get_data()['key'] ?? '' );
				if ( $this->is_custom_meta( $key ) ) {
					$keys[ $key ] = true;
				}
			}
		}

		$keys = array_keys( $keys );
		sort( $keys );

		return array_map(
			static function ( string $key ): array {
				return array(
					'id'    => self::META_PREFIX . $key,
					/* translators: %s: order meta key, e.g. "_billing_cpf". */
					'label' => sprintf( __( 'Custom field: %s', 'wpintegrations-connector-for-wc-rd-station' ), $key ),
					'type'  => 'meta',
				);
			},
			$keys
		);
	}

	/**
	 * Whether a meta key is a custom checkout field worth offering. Public keys
	 * qualify; of the protected ones only billing/shipping extras do, which is
	 * where Brazilian checkout plugins keep CPF, CNPJ, number and neighbourhood.
	 */
	private function is_custom_meta( string $key ): bool {
		if ( '' === $key || 0 === strpos( $key, '_wpi_' ) || 0 === strpos( $key, '_wc_' ) ) {
			return false;
		}
		if ( '_' !== $key[0] ) {
			return true;
		}
		if ( ! preg_match( '/^_(billing|shipping)_(.+)$/', $key, $match ) ) {
			return false;
		}
		// Core address keys are already fixed fields.
		return ! in_array( $match[2], array_merge( self::ADDRESS_KEYS, array( 'email', 'address_index' ) ), true );
	}
}
