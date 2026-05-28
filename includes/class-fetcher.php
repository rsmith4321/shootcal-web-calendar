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
		$cached = get_transient( CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$url = Settings::get_active_url();
		if ( '' === $url ) {
			return new \WP_Error( 'shootcal_availability_no_url', __( 'No calendar URL configured. Choose a source on the settings page and paste your iCal URL.', 'shootcal-availability' ) );
		}

		$response = wp_remote_get(
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

		set_transient( CACHE_KEY, $body, CACHE_TTL );
		return $body;
	}
}
