<?php
/**
 * Minimal event value object: a busy block in time.
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

final class Event {

	public function __construct(
		public readonly \DateTimeImmutable $start,
		public readonly \DateTimeImmutable $end,
		public readonly bool $all_day,
		// Event title. Retained ONLY for full-calendar display mode; availability
		// mode never reads it, preserving the title-free privacy default.
		public readonly ?string $summary = null
	) {}

	/**
	 * Does this event overlap [$range_start, $range_end)?
	 */
	public function overlaps( \DateTimeImmutable $range_start, \DateTimeImmutable $range_end ): bool {
		return $this->start < $range_end && $this->end > $range_start;
	}

	/**
	 * Does this event cover any part of the given calendar day in the display timezone?
	 *
	 * All-day events are treated as floating dates per RFC 5545 (DATE value type
	 * has no timezone): an all-day event for 2026-10-01 covers that calendar day
	 * in every viewer's timezone, not just UTC. Without this special case, an
	 * all-day event stored as 2026-10-01T00:00:00Z would appear on 2026-09-30
	 * when viewed from any negative-offset timezone (e.g., America/New_York).
	 */
	public function covers_day( \DateTimeImmutable $day_in_display_tz ): bool {
		if ( $this->all_day ) {
			$day_date   = $day_in_display_tz->format( 'Y-m-d' );
			$utc        = new \DateTimeZone( 'UTC' );
			$start_date = $this->start->setTimezone( $utc )->format( 'Y-m-d' );
			$end_date   = $this->end->setTimezone( $utc )->format( 'Y-m-d' );
			// DTEND for DATE values is exclusive.
			return $start_date <= $day_date && $day_date < $end_date;
		}

		$tz        = $day_in_display_tz->getTimezone();
		$day_start = $day_in_display_tz->setTime( 0, 0, 0 );
		$day_end   = $day_start->modify( '+1 day' );

		$event_start = $this->start->setTimezone( $tz );
		$event_end   = $this->end->setTimezone( $tz );

		return $event_start < $day_end && $event_end > $day_start;
	}

	/**
	 * Return all calendar days this event covers in the display timezone,
	 * as `Y-m-d` strings. Used to bucket events into per-day lists so the
	 * grid renderer does O(1) lookups instead of O(events) per cell.
	 *
	 * @return string[]
	 */
	public function days_covered( \DateTimeZone $display_tz ): array {
		$out = array();
		// Hard cap on emitted day-keys (~2.7 years). The feed horizon maxes
		// at 24 months; without this a malformed/hostile VEVENT (e.g.
		// DTSTART 1970 / DTEND 2099) would expand to tens of thousands of
		// iterations per event — a feed-driven CPU/memory DoS.
		$max = 1000;

		if ( $this->all_day ) {
			$utc        = new \DateTimeZone( 'UTC' );
			$start_date = $this->start->setTimezone( $utc )->format( 'Y-m-d' );
			$end_date   = $this->end->setTimezone( $utc )->format( 'Y-m-d' );
			$cursor     = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $utc );
			$last       = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $utc );
			if ( false === $cursor || false === $last ) {
				return array();
			}
			while ( $cursor < $last && count( $out ) < $max ) {
				$out[]  = $cursor->format( 'Y-m-d' );
				$cursor = $cursor->modify( '+1 day' );
			}
			return $out;
		}

		$start_local = $this->start->setTimezone( $display_tz );
		$end_local   = $this->end->setTimezone( $display_tz );

		$cursor = $start_local->setTime( 0, 0, 0 );
		while ( $cursor < $end_local && count( $out ) < $max ) {
			$out[]  = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+1 day' );
		}
		// A zero-duration event at exactly the start of a day would otherwise
		// produce no buckets; emit one entry for the day it falls on so it still renders.
		if ( $out === array() && $start_local == $end_local ) {
			$out[] = $start_local->format( 'Y-m-d' );
		}
		return $out;
	}
}
