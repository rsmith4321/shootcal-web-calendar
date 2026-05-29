<?php
/**
 * Fetches the iCal feed with WordPress transient caching.
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class Fetcher {

	/**
	 * Return the raw iCal text, using cache when available.
	 *
	 * @return string|\WP_Error iCal body on success, WP_Error on failure.
	 */
	public function get_ical_text() {
		$url = Settings::get_active_url();
		if ( '' === $url ) {
			return new \WP_Error( 'shootcal_availability_no_url', __( 'No calendar URL configured. Add an iCal URL on the settings page, or paste one into the block.', 'shootcal-availability' ) );
		}

		$cache_key = self::cache_key( $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		// wp_safe_remote_get blocks requests to private/loopback addresses and
		// non-standard ports, so accepting an arbitrary iCal URL cannot be turned
		// into a server-side request forgery against internal services.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'ShootCal Availability/' . VERSION . '; ' . home_url(),
				'headers'     => array(
					'Accept' => 'text/calendar, text/plain;q=0.9, */*;q=0.5',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'shootcal_availability_http_error',
				sprintf(
					/* translators: %d: HTTP status code returned by Google. */
					__( 'Calendar request failed with HTTP %d.', 'shootcal-availability' ),
					$code
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || stripos( $body, 'BEGIN:VCALENDAR' ) === false ) {
			return new \WP_Error( 'shootcal_availability_bad_body', __( 'Calendar response did not look like an iCal feed.', 'shootcal-availability' ) );
		}

		set_transient( $cache_key, $body, CACHE_TTL );
		return $body;
	}

	/**
	 * Per-URL, versioned transient key. Bumping the version (flush_cache) makes
	 * every cached feed unreachable at once - that's how the "Clear cache" button
	 * and a settings change invalidate everything despite the per-URL keys.
	 */
	private static function cache_key( string $url ): string {
		$ver = (int) get_option( 'shootcal_availability_cache_ver', 0 );
		return CACHE_KEY . '_' . $ver . '_' . md5( $url );
	}

	/**
	 * Invalidate all cached feeds by bumping the cache version. The old
	 * transients become unreachable and expire on their own TTL.
	 */
	public static function flush_cache(): void {
		$ver = (int) get_option( 'shootcal_availability_cache_ver', 0 );
		update_option( 'shootcal_availability_cache_ver', $ver + 1 );
	}
}
