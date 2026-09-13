<?php
/** Keeps private iCal URLs out of cacheable public page markup. */
declare( strict_types=1 );
namespace ShootCalWebCalendar;
defined( 'ABSPATH' ) || exit;

final class Feed_Payload {
	private static function key(): string {
		return hash( 'sha256', 'shootcal-feed-payload-v1|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}

	/** Returns null when authenticated encryption is unavailable; caller renders on the server. */
	public static function encode( array $attributes ): ?string {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return null;
		}
		$json = wp_json_encode( $attributes );
		if ( ! is_string( $json ) || strlen( $json ) > 8192 ) {
			return null;
		}
		try {
			$iv = random_bytes( 12 );
			$tag = '';
			$encrypted = openssl_encrypt( $json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'shootcal-feed-v1', 16 );
			return false === $encrypted ? null : 'v1.' . rtrim( strtr( base64_encode( $iv . $tag . $encrypted ), '+/', '-_' ), '=' );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	public static function decode( string $payload ): ?array {
		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $payload ) > 16384
			|| ! preg_match( '/^v1\.([A-Za-z0-9_-]+)$/D', $payload, $match ) ) {
			return null;
		}
		$raw = base64_decode( strtr( $match[1], '-_', '+/' ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$json = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
			substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'shootcal-feed-v1' );
		if ( false === $json ) {
			return null;
		}
		$attributes = json_decode( $json, true );
		if ( ! is_array( $attributes ) || ! isset( $attributes['url'] ) || ! is_string( $attributes['url'] ) ) {
			return null;
		}
		foreach ( $attributes as $value ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
		}
		return $attributes;
	}
}
