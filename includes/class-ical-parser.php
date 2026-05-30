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
 *   - RRULE expansion for the common subset (FREQ=DAILY|WEEKLY|MONTHLY|YEARLY,
 *     INTERVAL, COUNT, UNTIL, weekly BYDAY) with EXDATE exclusions, bounded to a
 *     forward horizon. Feeds that pre-expand recurrence (e.g. Google) are
 *     unaffected; this is what makes recurring events from feeds that DON'T
 *     pre-expand (Apple, Outlook) show on every occurrence instead of just once.
 *
 * Intentionally does NOT handle:
 *   - Advanced RRULE parts (BYMONTHDAY, BYSETPOS, ordinal BYDAY like 2MO, BYMONTH,
 *     etc.) — an event using those keeps its single base instance rather than
 *     risk rendering a wrong recurrence.
 *   - VTODO / VJOURNAL / VFREEBUSY / VALARM / VTIMEZONE component bodies
 *     (we trust the system tz database to resolve TZIDs).
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

class ICal_Parser {

	/** Hard ceiling on occurrences generated per recurring event (safety net). */
	private const MAX_OCCURRENCES = 750;

	/** Iteration guard so a pathological rule can't loop forever. */
	private const MAX_ITERATIONS = 20000;

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
				foreach ( $this->build_events( $current ) as $built ) {
					$events[] = $built;
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
			// EXDATE can legitimately repeat within one VEVENT, and a single line
			// can carry a comma-separated list; accumulate every one (recurrence
			// exclusion needs them all) instead of keeping only the first.
			if ( 'EXDATE' === $prop['name'] ) {
				$current['__EXDATE'][] = $prop;
				continue;
			}
			// Keep the first occurrence of each other property (DTSTART/DTEND/RRULE etc).
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
	 * Build the event(s) a VEVENT produces. Without an RRULE that's one event;
	 * with a supported RRULE it's the expanded occurrences within a bounded
	 * forward horizon. Unsupported rules fall back to the single base instance
	 * (never worse than before recurrence support existed).
	 *
	 * @param array<string, mixed> $props
	 * @return Event[]
	 */
	private function build_events( array $props ): array {
		$base = $this->build_base_event( $props );
		if ( null === $base ) {
			return array();
		}
		// A modified single instance (RECURRENCE-ID) is a concrete one-off, not a
		// recurrence master — never expand it.
		if ( ! isset( $props['RRULE'] ) || isset( $props['RECURRENCE-ID'] ) ) {
			return array( $base );
		}
		$expanded = $this->expand_recurrence( $base, (string) $props['RRULE']['value'], $props );
		if ( null === $expanded ) {
			return array( $base ); // unsupported rule: at least the first instance
		}
		return $expanded;
	}

	/**
	 * Convert a collected property bag into the single base Event, or null if it
	 * should be skipped.
	 *
	 * @param array<string, array{name:string,params:array<string,string>,value:string}> $props
	 */
	private function build_base_event( array $props ): ?Event {
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

		// SUMMARY (the event title) is kept for full-calendar display mode only.
		// Availability mode ignores it, so this never weakens the privacy default.
		$summary = isset( $props['SUMMARY'] ) ? $this->unescape_text( (string) $props['SUMMARY']['value'] ) : null;

		return new Event(
			$start_info['dt'],
			$end_info['dt'],
			$start_info['all_day'],
			$summary
		);
	}

	/**
	 * Unescape an RFC 5545 TEXT value (e.g. SUMMARY): backslash-escaped
	 * commas, semicolons, newlines, and backslashes.
	 */
	private function unescape_text( string $value ): string {
		return str_replace(
			array( '\\N', '\\n', '\\,', '\\;', '\\\\' ),
			array( "\n", "\n", ',', ';', '\\' ),
			$value
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
		// A leading sign is not part of the ISO-8601 duration grammar that
		// DateInterval accepts, so strip BOTH '+'/'-' before constructing and
		// re-apply the sign via ->invert. (Previously only '+' was stripped, so
		// any negative duration threw and the event was silently dropped.)
		$duration = trim( $duration );
		$negative = str_starts_with( $duration, '-' );
		try {
			$interval = new \DateInterval( ltrim( $duration, '+-' ) );
		} catch ( \Exception $e ) {
			return null;
		}
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

	// === Recurrence expansion ================================================

	/**
	 * Expand a supported RRULE into concrete Event occurrences, bounded by a
	 * forward horizon so an open-ended rule can't expand forever. Returns null
	 * when the rule uses features we don't implement (caller falls back to the
	 * single base instance).
	 *
	 * @param array<string, mixed> $props
	 * @return Event[]|null
	 */
	private function expand_recurrence( Event $base, string $rrule, array $props ): ?array {
		$rule = $this->parse_rrule( $rrule );
		if ( null === $rule ) {
			return null;
		}

		$tz       = $base->start->getTimezone();
		$duration = $base->start->diff( $base->end );
		$exdates  = $this->collect_exdate_keys( $props, $base->all_day, $tz );

		// Bound expansion: never past UNTIL, never past ~37 months from now (one
		// more than the 36-month display cap), and never more than MAX_OCCURRENCES.
		// `floor` lets a long-running open-ended series skip its (irrelevant) past
		// occurrences cheaply instead of burning the occurrence budget on history.
		$now     = new \DateTimeImmutable( 'now', $tz );
		$horizon = $now->modify( '+37 months' );
		$floor   = $now->modify( 'first day of this month' )->modify( '-1 month' )->setTime( 0, 0, 0 );

		$starts = $this->generate_starts( $base->start, $rule, $floor, $horizon );

		$out = array();
		foreach ( $starts as $start ) {
			$key = $base->all_day
				? $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd' )
				: $start->setTimezone( $tz )->format( 'Ymd' );
			// EXDATE-excluded occurrences are dropped here (they still counted
			// toward COUNT in generate_starts, per RFC 5545).
			if ( in_array( $key, $exdates, true ) ) {
				continue;
			}
			$out[] = new Event( $start, $start->add( $duration ), $base->all_day, $base->summary );
		}
		return $out;
	}

	/**
	 * Parse the supported subset of an RRULE value into a normalized array, or
	 * null if it uses an unsupported feature.
	 *
	 * @return array{freq:string,interval:int,count:?int,until:?\DateTimeImmutable,byday:string[]}|null
	 */
	private function parse_rrule( string $rrule ): ?array {
		$parts = array();
		foreach ( explode( ';', $rrule ) as $seg ) {
			$eq = strpos( $seg, '=' );
			if ( false === $eq ) {
				continue;
			}
			$parts[ strtoupper( substr( $seg, 0, $eq ) ) ] = substr( $seg, $eq + 1 );
		}

		$freq = strtoupper( $parts['FREQ'] ?? '' );
		if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			return null; // SECONDLY/MINUTELY/HOURLY or missing FREQ -> unsupported
		}

		// Features we don't implement: bail so the caller keeps the single instance
		// rather than rendering a wrong recurrence.
		foreach ( array( 'BYMONTHDAY', 'BYSETPOS', 'BYMONTH', 'BYWEEKNO', 'BYYEARDAY', 'BYHOUR', 'BYMINUTE' ) as $unsupported ) {
			if ( isset( $parts[ $unsupported ] ) ) {
				return null;
			}
		}

		$byday = array();
		if ( isset( $parts['BYDAY'] ) && '' !== $parts['BYDAY'] ) {
			foreach ( explode( ',', $parts['BYDAY'] ) as $d ) {
				$d = strtoupper( trim( $d ) );
				if ( ! preg_match( '/^(MO|TU|WE|TH|FR|SA|SU)$/', $d ) ) {
					return null; // ordinal BYDAY (2MO, -1FR) unsupported
				}
				$byday[] = $d;
			}
			// We only implement BYDAY for weekly recurrence.
			if ( 'WEEKLY' !== $freq ) {
				return null;
			}
		}

		return array(
			'freq'     => $freq,
			'interval' => isset( $parts['INTERVAL'] ) ? max( 1, (int) $parts['INTERVAL'] ) : 1,
			'count'    => isset( $parts['COUNT'] ) ? max( 0, (int) $parts['COUNT'] ) : null,
			'until'    => isset( $parts['UNTIL'] ) ? $this->parse_until( (string) $parts['UNTIL'] ) : null,
			'byday'    => $byday,
		);
	}

	/**
	 * Parse an RRULE UNTIL value (date, UTC datetime, or local datetime). Always
	 * resolved against UTC; comparisons elsewhere are by absolute instant.
	 */
	private function parse_until( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );
		if ( preg_match( '/^\d{8}$/', $value ) ) {
			// All-day UNTIL is inclusive of that whole day.
			$dt = \DateTimeImmutable::createFromFormat( '!Ymd', $value, new \DateTimeZone( 'UTC' ) );
			return false === $dt ? null : $dt->modify( '+1 day -1 second' );
		}
		if ( 'Z' === substr( $value, -1 ) ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $value, new \DateTimeZone( 'UTC' ) );
			return false === $dt ? null : $dt;
		}
		$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $value, new \DateTimeZone( 'UTC' ) );
		return false === $dt ? null : $dt;
	}

	/**
	 * Generate occurrence start datetimes in chronological order, bounded by the
	 * rule's COUNT/UNTIL and the forward horizon (plus a hard safety cap). EXDATE
	 * filtering happens in the caller; per RFC 5545 excluded occurrences still
	 * count toward COUNT, so they ARE generated here.
	 *
	 * @param array{freq:string,interval:int,count:?int,until:?\DateTimeImmutable,byday:string[]} $rule
	 * @return \DateTimeImmutable[]
	 */
	private function generate_starts( \DateTimeImmutable $dtstart, array $rule, \DateTimeImmutable $floor, \DateTimeImmutable $horizon ): array {
		$freq     = $rule['freq'];
		$interval = $rule['interval'];
		$count    = $rule['count'];
		$until    = $rule['until'];
		$byday    = $rule['byday'];

		$starts  = array();
		$emitted = 0;

		$within = static function ( \DateTimeImmutable $occ ) use ( $until, $horizon ): bool {
			if ( null !== $until && $occ > $until ) {
				return false;
			}
			return $occ <= $horizon;
		};
		// Past occurrences only matter when COUNT is set (they consume the count).
		// For open-ended series, skipping them keeps the occurrence budget for the
		// visible window.
		$skippable_past = ( null === $count );

		if ( 'WEEKLY' === $freq && array() !== $byday ) {
			$map     = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
			$targets = array();
			foreach ( $byday as $d ) {
				$targets[] = $map[ $d ];
			}
			sort( $targets );

			$h = (int) $dtstart->format( 'H' );
			$i = (int) $dtstart->format( 'i' );
			$s = (int) $dtstart->format( 's' );

			$week   = 0;
			$safety = 0;
			while ( $safety++ < self::MAX_ITERATIONS ) {
				// Monday of the dtstart week, advanced by whole INTERVAL weeks.
				$week_start = $dtstart->modify( 'monday this week' )
					->modify( '+' . ( $week * $interval ) . ' weeks' )
					->setTime( $h, $i, $s );
				++$week;

				$week_after_end = ( $week_start > $horizon ) || ( null !== $until && $week_start > $until );

				foreach ( $targets as $n ) {
					$occ = $week_start->modify( '+' . ( $n - 1 ) . ' days' );
					if ( $occ < $dtstart ) {
						continue; // weekdays before the series start (first week only)
					}
					if ( ! $within( $occ ) ) {
						continue;
					}
					if ( $skippable_past && $occ < $floor ) {
						continue;
					}
					$starts[] = $occ;
					++$emitted;
					if ( ( null !== $count && $emitted >= $count ) || $emitted >= self::MAX_OCCURRENCES ) {
						return $starts;
					}
				}

				if ( $week_after_end ) {
					break;
				}
			}
			return $starts;
		}

		// Non-BYDAY: step DTSTART by INTERVAL units. Compute each occurrence from
		// DTSTART + n*interval (not cumulatively) so MONTHLY/YEARLY don't drift
		// after a short-month skip.
		$unit       = array(
			'DAILY'   => 'days',
			'WEEKLY'  => 'weeks',
			'MONTHLY' => 'months',
			'YEARLY'  => 'years',
		)[ $freq ];
		$anchor_day = (int) $dtstart->format( 'd' );

		$n      = 0;
		$safety = 0;
		while ( $safety++ < self::MAX_ITERATIONS ) {
			$occ = $dtstart->modify( '+' . ( $n * $interval ) . ' ' . $unit );
			++$n;
			if ( ! $within( $occ ) ) {
				break;
			}
			// MONTHLY/YEARLY: PHP rolls "Jan 31 + 1 month" over to Mar 3. Per RFC
			// 5545 a period without the anchor day-of-month is simply skipped.
			if ( ( 'MONTHLY' === $freq || 'YEARLY' === $freq ) && (int) $occ->format( 'd' ) !== $anchor_day ) {
				continue;
			}
			if ( $skippable_past && $occ < $floor ) {
				continue;
			}
			$starts[] = $occ;
			++$emitted;
			if ( ( null !== $count && $emitted >= $count ) || $emitted >= self::MAX_OCCURRENCES ) {
				break;
			}
		}
		return $starts;
	}

	/**
	 * Collect EXDATE day-keys (Ymd, in the event's reference timezone) for fast
	 * exclusion lookup. All-day events key on the UTC date; timed events on the
	 * event/display timezone date.
	 *
	 * @param array<string, mixed> $props
	 * @return string[]
	 */
	private function collect_exdate_keys( array $props, bool $all_day, \DateTimeZone $tz ): array {
		if ( empty( $props['__EXDATE'] ) || ! is_array( $props['__EXDATE'] ) ) {
			return array();
		}
		$keys = array();
		foreach ( $props['__EXDATE'] as $prop ) {
			$ex_tzid = $prop['params']['TZID'] ?? '';
			$ex_tz   = '' !== $ex_tzid ? ( $this->try_make_tz( $ex_tzid ) ?? $tz ) : $tz;
			foreach ( explode( ',', $prop['value'] ) as $value ) {
				$value = trim( $value );
				if ( '' === $value ) {
					continue;
				}
				if ( preg_match( '/^\d{8}$/', $value ) ) {
					$dt = \DateTimeImmutable::createFromFormat( '!Ymd', $value, new \DateTimeZone( 'UTC' ) );
				} elseif ( 'Z' === substr( $value, -1 ) ) {
					$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $value, new \DateTimeZone( 'UTC' ) );
				} else {
					$dt = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $value, $ex_tz );
				}
				if ( false === $dt ) {
					continue;
				}
				$keys[] = $all_day
					? $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd' )
					: $dt->setTimezone( $tz )->format( 'Ymd' );
			}
		}
		return $keys;
	}

	private function try_make_tz( string $name ): ?\DateTimeZone {
		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
