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
	 * Optional bounds are display-calendar dates, with an exclusive end. Clamp
	 * before walking days so a long event that began years ago still covers the
	 * visible window. Callers without bounds retain the historical 1000-day cap.
	 *
	 * @return string[]
	 */
	public function days_covered( \DateTimeZone $display_tz, ?\DateTimeImmutable $window_start = null, ?\DateTimeImmutable $window_end = null ): array {
		$out = array();
		if ( $this->end < $this->start ) {
			return $out;
		}
		$first_day = $window_start?->setTimezone( $display_tz )->setTime( 0, 0, 0 );
		$after_last_day = $window_end?->setTimezone( $display_tz )->setTime( 0, 0, 0 );
		if ( null !== $first_day && null !== $after_last_day && $first_day >= $after_last_day ) {
			return $out;
		}
		// The full, caller-bounded display window can exceed 1000 days (36 months).
		// Unbounded callers retain a safety net against hostile century-long data.
		$max = null !== $first_day && null !== $after_last_day
			? (int) $first_day->diff( $after_last_day )->format( '%a' )
			: 1000;

		if ( $this->all_day ) {
			$utc        = new \DateTimeZone( 'UTC' );
			$start_date = $this->start->setTimezone( $utc )->format( 'Y-m-d' );
			$end_date   = $this->end->setTimezone( $utc )->format( 'Y-m-d' );
			$cursor     = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $utc );
			$last       = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $utc );
			if ( false === $cursor || false === $last ) {
				return array();
			}
			if ( null !== $first_day ) {
				$cursor = max( $cursor, new \DateTimeImmutable( $first_day->format( 'Y-m-d' ), $utc ) );
			}
			if ( null !== $after_last_day ) {
				$last = min( $last, new \DateTimeImmutable( $after_last_day->format( 'Y-m-d' ), $utc ) );
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
		if ( null !== $first_day ) {
			$cursor = max( $cursor, $first_day );
		}
		$last = null !== $after_last_day ? min( $end_local, $after_last_day ) : $end_local;
		while ( $cursor < $last && count( $out ) < $max ) {
			$out[]  = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+1 day' );
		}
		// A zero-duration event at exactly the start of a day would otherwise
		// produce no buckets; emit one entry for the day it falls on so it still renders.
		if ( $out === array() && $start_local == $end_local
			&& ( null === $first_day || $start_local >= $first_day )
			&& ( null === $after_last_day || $start_local < $after_last_day ) ) {
			$out[] = $start_local->format( 'Y-m-d' );
		}
		return $out;
	}
}
