<?php
/**
 * Encrypts stored credentials (API keys, tokens) at rest with AES-256-GCM.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Services;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	/**
	 * @param string $plaintext Secret to store.
	 * @return string Base64 envelope (iv + tag + ciphertext), or '' on failure.
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return '';
		}
		return base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary envelope, not obfuscation.
	}

	/**
	 * @param string $envelope Value produced by encrypt().
	 * @return string Decrypted secret, or '' if invalid or tampered with.
	 */
	public function decrypt( string $envelope ): string {
		if ( '' === $envelope ) {
			return '';
		}
		$raw = base64_decode( $envelope, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * 32-byte key derived from WP auth salts; unique per install.
	 */
	private function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wpi_wcrds', true );
	}
}
