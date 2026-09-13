<?php
/** Normalizes supported ShootCal references without executing pasted markup. */
declare( strict_types=1 );
namespace ShootCalWebCalendar;
defined( 'ABSPATH' ) || exit;

final class Embed_Reference {

	public static function is_token( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{8,128}$/D', $value );
	}

	/** @return array{token:string,query:array<string,string>}|null */
	public static function parse( string $input ): ?array {
		$input = trim( html_entity_decode( $input, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( self::is_token( $input ) ) {
			return array( 'token' => $input, 'query' => array() );
		}
		if ( strlen( $input ) > 16384 ) {
			return null;
		}
		if ( str_contains( $input, '<' ) ) {
			$tags = new \WP_HTML_Tag_Processor( $input );
			while ( $tags->next_tag() ) {
				if ( 'IFRAME' === $tags->get_tag() ) {
					$src = $tags->get_attribute( 'src' );
					return is_string( $src ) ? self::parse_url( $src ) : null;
				}
				if ( 'SCRIPT' === $tags->get_tag() ) {
					$src = $tags->get_attribute( 'src' );
					if ( ! is_string( $src ) || 'https://api.shootcal.com/embed.js' !== $src ) {
						return null;
					}
					$url = $tags->get_attribute( 'data-src' );
					if ( is_string( $url ) && '' !== $url ) {
						return self::parse_url( $url );
					}
					$token = $tags->get_attribute( 'data-shootcal' );
					return is_string( $token ) && self::is_token( $token )
						? array( 'token' => $token, 'query' => array() ) : null;
				}
			}
			return null;
		}
		return self::parse_url( $input );
	}

	private static function parse_url( string $url ): ?array {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		if ( 'feed.shootcal.com' === $host && preg_match( '#^/([A-Za-z0-9_-]{8,128})\.ics$#D', $path, $match ) ) {
			return array( 'token' => $match[1], 'query' => array() );
		}
		if ( 'api.shootcal.com' !== $host || ! preg_match( '#^/embed/([A-Za-z0-9_-]{8,128})$#D', $path, $match ) ) {
			return null;
		}
		$query = array();
		parse_str( $parts['query'] ?? '', $query );
		return array( 'token' => $match[1], 'query' => self::parameters( $query ) );
	}

	/** Only presentation values understood by the hosted calendar are retained. */
	public static function parameters( array $input ): array {
		$out = array();
		foreach ( $input as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = (string) $value;
			if ( 'months' === $key && ctype_digit( $value ) && (int) $value > 0 ) {
				$out[$key] = (string) min( 36, (int) $value );
			} elseif ( 'mode' === $key && in_array( $value, array( 'availability', 'full' ), true ) ) {
				$out[$key] = $value;
			} elseif ( 'first_day' === $key && in_array( $value, array( '0', '1' ), true ) ) {
				$out[$key] = $value;
			} elseif ( 'view' === $key && 'calendar' === $value ) {
				$out[$key] = $value;
			} elseif ( 'theme' === $key && in_array( $value, array( 'light', 'dark' ), true ) ) {
				$out[$key] = $value;
			} elseif ( in_array( $key, array( 'sc_card', 'sc_ink', 'sc_soft', 'sc_line', 'sc_accent', 'sc_font' ), true )
				&& preg_match( '/^[0-9a-zA-Z]{1,10}$/D', $value ) ) {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	public static function url( string $token, array $parameters = array() ): string {
		$query = self::parameters( $parameters );
		return 'https://api.shootcal.com/embed/' . rawurlencode( $token )
			. ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
	}
}
