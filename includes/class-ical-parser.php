<?php
/**
 * Minimal iCalendar (RFC 5545) parser, scoped to what we need to display busy days.
 *
 * Handles:
 *   - VEVENT blocks
 *   - DTSTART / DTEND with TZID, UTC ("Z"), or VALUE=DATE (all-day)
 *   - Line unfolding (RFC 5545 §3.1)
 *   - STATUS:CANCELLED  -> skip
 *   - TRANSP:TRANSPARENT -> skip (event marks user as free, not busy)
 *
 * Intentionally does NOT handle:
 *   - RRULE expansion (Google's iCal feed pre-expands recurring events for the
 *     forward window we care about, so an MVP can skip this).
 *   - VTODO / VJOURNAL / VFREEBUSY / VALARM / VTIMEZONE component bodies
 *     (we trust the system tz database to resolve TZIDs).
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class ICal_Parser {

	/**
	 * Parse iCal text into a flat array of Event objects.
	 *
	 * @param string $ical Raw iCal text.
	 * @return Event[]
	 */
	public function parse( string $ical ): array {
		$lines  = $this->unfold_lines( $ical );
		$events = array();

		$in_event = false;
		$current  = array();

		foreach ( $lines as $line ) {
			if ( 'BEGIN:VEVENT' === $line ) {
				$in_event = true;
				$current  = array();
				continue;
			}
			if ( 'END:VEVENT' === $line ) {
				$in_event = false;
				$event    = $this->build_event( $current );
				if ( null !== $event ) {
					$events[] = $event;
				}
				$current = array();
				continue;
			}
			if ( ! $in_event ) {
				continue;
			}

			$prop = $this->parse_property( $line );
			if ( null === $prop ) {
				continue;
			}
			// Keep the first occurrence of each property (DTSTART/DTEND etc).
			if ( ! isset( $current[ $prop['name'] ] ) ) {
				$current[ $prop['name'] ] = $prop;
			}
		}

		return $events;
	}

	/**
	 * RFC 5545 §3.1 line unfolding: continuation lines start with a single
	 * space or tab; strip that and append to the previous line.
	 *
	 * @return string[]
	 */
	private function unfold_lines( string $ical ): array {
		$raw = preg_split( '/\R/', $ical );
		if ( false === $raw ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( ( "\t" === $line[0] || ' ' === $line[0] ) && ! empty( $out ) ) {
				$out[ count( $out ) - 1 ] .= substr( $line, 1 );
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Parse one content line into name + params + value.
	 *
	 * @return array{name: string, params: array<string,string>, value: string}|null
	 */
	private function parse_property( string $line ): ?array {
		$colon = strpos( $line, ':' );
		if ( false === $colon ) {
			return null;
		}
		$head  = substr( $line, 0, $colon );
		$value = substr( $line, $colon + 1 );

		$parts  = explode( ';', $head );
		$name   = strtoupper( array_shift( $parts ) );
		$params = array();
		foreach ( $parts as $p ) {
			$eq = strpos( $p, '=' );
			if ( false === $eq ) {
				continue;
			}
			$params[ strtoupper( substr( $p, 0, $eq ) ) ] = trim( substr( $p, $eq + 1 ), '"' );
		}

		return array(
			'name'   => $name,
			'params' => $params,
			'value'  => $value,
		);
	}

	/**
	 * Convert a collected property bag into an Event, or null if it should be skipped.
	 *
	 * @param array<string, array{name:string,params:array<string,string>,value:string}> $props
	 */
	private function build_event( array $props ): ?Event {
		if ( ! isset( $props['DTSTART'] ) ) {
			return null;
		}

		// Skip cancelled events.
		if ( isset( $props['STATUS'] ) && 'CANCELLED' === strtoupper( $props['STATUS']['value'] ) ) {
			return null;
		}
		// Skip events the user has marked transparent (= "free" / does not block time).
		if ( isset( $props['TRANSP'] ) && 'TRANSPARENT' === strtoupper( $props['TRANSP']['value'] ) ) {
			return null;
		}

		$start_info = $this->parse_datetime( $props['DTSTART'] );
		if ( null === $start_info ) {
			return null;
		}

		if ( isset( $props['DTEND'] ) ) {
			$end_info = $this->parse_datetime( $props['DTEND'] );
		} elseif ( isset( $props['DURATION'] ) ) {
			$end_info = $this->apply_duration( $start_info, $props['DURATION']['value'] );
		} else {
			// Per RFC 5545: no DTEND/DURATION on a DATE-only event means 1 day; on a
			// DATE-TIME event it means a zero-length instant. Both are fine to compute below.
			if ( $start_info['all_day'] ) {
				$end_info = array(
					'dt'      => $start_info['dt']->modify( '+1 day' ),
					'all_day' => true,
				);
			} else {
				$end_info = $start_info;
			}
		}

		if ( null === $end_info ) {
			return null;
		}

		return new Event(
			$start_info['dt'],
			$end_info['dt'],
			$start_info['all_day']
		);
	}

	/**
	 * Parse a DTSTART/DTEND property into a DateTimeImmutable plus all-day flag.
	 *
	 * @param array{name:string,params:array<string,string>,value:string} $prop
	 * @return array{dt: \DateTimeImmutable, all_day: bool}|null
	 */
	private function parse_datetime( array $prop ): ?array {
		$value      = trim( $prop['value'] );
		$value_type = isset( $prop['params']['VALUE'] ) ? strtoupper( $prop['params']['VALUE'] ) : '';
		$tzid       = $prop['params']['TZID'] ?? '';

		// All-day (date only).
		if ( 'DATE' === $value_type || preg_match( '/^\d{8}$/', $value ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Ymd', $value, new \DateTimeZone( 'UTC' ) );
			if ( false === $dt ) {
				return null;
			}
			return array( 'dt' => $dt, 'all_day' => true );
		}

		// UTC date-time (trailing Z).
		if ( 'Z' === substr( $value, -1 ) ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $value, new \DateTimeZone( 'UTC' ) );
			if ( false === $dt ) {
				return null;
			}
			return array( 'dt' => $dt, 'all_day' => false );
		}

		// Local time with explicit TZID, or floating local time.
		$tz = $this->resolve_timezone( $tzid );
		$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $value, $tz );
		if ( false === $dt ) {
			return null;
		}
		return array( 'dt' => $dt, 'all_day' => false );
	}

	/**
	 * Apply an RFC 5545 DURATION string to a start datetime.
	 * Supports the subset Google emits: PT#H#M#S and P#D etc.
	 *
	 * @param array{dt: \DateTimeImmutable, all_day: bool} $start
	 * @return array{dt: \DateTimeImmutable, all_day: bool}|null
	 */
	private function apply_duration( array $start, string $duration ): ?array {
		try {
			$interval = new \DateInterval( ltrim( $duration, '+' ) );
		} catch ( \Exception $e ) {
			return null;
		}
		$negative = str_starts_with( $duration, '-' );
		if ( $negative ) {
			$interval->invert = 1;
		}
		return array(
			'dt'      => $start['dt']->add( $interval ),
			'all_day' => $start['all_day'],
		);
	}

	private function resolve_timezone( string $tzid ): \DateTimeZone {
		if ( '' === $tzid ) {
			// Floating times: anchor to WordPress's timezone.
			return wp_timezone();
		}
		try {
			return new \DateTimeZone( $tzid );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}
}
