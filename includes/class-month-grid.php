<?php
/**
 * Render one month as an availability grid.
 *
 * Uses CSS Grid for layout (display: grid on the container), with ARIA grid
 * semantics (role="grid" / "row" / "gridcell" / "columnheader") so screen
 * readers still navigate it as a 2D calendar regardless of how the visual
 * layout is rendered.
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

class Month_Grid {

	/**
	 * @param int                       $year          Four-digit year.
	 * @param int                       $month         1-12.
	 * @param array<string,Event[]>     $events_by_day Pre-bucketed `Y-m-d` => Event[] map (see Shortcode).
	 * @param \DateTimeZone             $tz            Display timezone.
	 * @param int                       $first_dow     0=Sun, 1=Mon.
	 * @param bool                      $multi_session_day Whether timed-only days are treated as "Limited" (true)
	 *                                                  or rolled into "Booked" (false). Defaults to true.
	 */
	public function render( int $year, int $month, array $events_by_day, \DateTimeZone $tz, int $first_dow, bool $multi_session_day = true, string $mode = 'availability' ): string {
		$first_of_month = (new \DateTimeImmutable( 'now', $tz ))->setDate( $year, $month, 1 )->setTime( 0, 0, 0 );

		// Today's date in the display timezone, used to mute past day cells.
		$today_local = ( new \DateTimeImmutable( 'today', $tz ) )->setTime( 0, 0, 0 );

		$first_dow_in_month = (int) $first_of_month->format( 'w' );
		$leading            = ( $first_dow_in_month - $first_dow + 7 ) % 7;
		$grid_start         = $first_of_month->modify( "-{$leading} days" );

		$month_label    = wp_date( 'F Y', $first_of_month->getTimestamp(), $tz );
		$weekday_labels = $this->weekday_labels( $first_dow );

		$mode_class = ( 'full' === $mode ) ? ' shootcal-web-calendar--full' : '';
		$html  = '<div class="shootcal-web-calendar shootcal-web-calendar--month' . $mode_class . '" role="grid" aria-label="' . esc_attr( $month_label ) . '">';
		$html .= '<div class="shootcal-web-calendar__caption">' . esc_html( $month_label ) . '</div>';

		// Weekday header row.
		$html .= '<div class="shootcal-web-calendar__week shootcal-web-calendar__week--header" role="row">';
		foreach ( $weekday_labels as $label ) {
			$html .= '<div class="shootcal-web-calendar__weekday" role="columnheader" abbr="' . esc_attr( $label['full'] ) . '"><abbr title="' . esc_attr( $label['full'] ) . '">' . esc_html( $label['short'] ) . '</abbr></div>';
		}
		$html .= '</div>';

		// Always render a full 6-week (42-cell) grid, matching the macOS ShootCal
		// app's month view. This keeps every month the same height visually and
		// gives off-month days a place to surface their events.
		for ( $week = 0; $week < 6; $week++ ) {
			$row_html = '';
			for ( $dow = 0; $dow < 7; $dow++ ) {
				$cell_day = $grid_start->modify( '+' . ( $week * 7 + $dow ) . ' days' );
				$in_month = ( (int) $cell_day->format( 'n' ) === $month );
				$row_html .= $this->render_cell( $cell_day, $events_by_day, $tz, $in_month, $today_local, $multi_session_day, $mode );
			}
			$html .= '<div class="shootcal-web-calendar__week" role="row">' . $row_html . '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * @return array<int, array{short:string, full:string}>
	 */
	private function weekday_labels( int $first_dow ): array {
		global $wp_locale;
		$names = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$dow     = ( $first_dow + $i ) % 7;
			$full    = $wp_locale->get_weekday( $dow );
			$initial = $wp_locale->get_weekday_initial( $full );
			$names[] = array(
				'short' => $initial,
				'full'  => $full,
			);
		}
		return $names;
	}

	/**
	 * @param array<string,Event[]> $events_by_day
	 */
	private function render_cell( \DateTimeImmutable $day, array $events_by_day, \DateTimeZone $tz, bool $in_month, \DateTimeImmutable $today_local, bool $multi_session_day = true, string $mode = 'availability' ): string {
		$day_local = $day->setTimezone( $tz );
		$day_num   = (int) $day_local->format( 'j' );
		$iso_date  = $day_local->format( 'Y-m-d' );
		$is_past   = $day_local->setTime( 0, 0, 0 ) < $today_local;
		$is_today  = $day_local->setTime( 0, 0, 0 ) == $today_local;

		$covering = $events_by_day[ $iso_date ] ?? array();

		// Full-calendar mode lists each event's title + time instead of the
		// availability status pill / busy shading.
		if ( 'full' === $mode ) {
			return $this->render_cell_full( $day_local, $iso_date, $day_num, $is_past, $is_today, $in_month, $covering, $tz );
		}

		// Detect the busy "shape" of the day:
		//   has_full_day = at least one all-day event covers this day -> red, "Booked"
		//   has_timed    = at least one timed event covers this day   -> amber, "Limited"
		//                                                                if !has_full_day
		// A day with both a wedding (all-day) AND a timed session collapses to "Booked"
		// (the all-day commitment dominates - photographer is not taking new clients that day).
		$has_full_day = false;
		$has_timed    = false;
		foreach ( $covering as $event ) {
			if ( $event->all_day ) {
				$has_full_day = true;
			} else {
				$has_timed = true;
			}
		}

		$classes = array( 'shootcal-web-calendar__day' );

		// Resolve final state. The "Limited" tier only applies when the photographer
		// has opted in via the multi_session_day setting; otherwise any event
		// (timed or all-day) rolls up to "Booked".
		if ( $is_past ) {
			$classes[]  = 'is-past';
			$status_key = 'past';
		} elseif ( $has_full_day || ( $has_timed && ! $multi_session_day ) ) {
			$classes[]  = 'is-booked';
			$status_key = 'booked';
		} elseif ( $has_timed ) {
			$classes[]  = 'is-limited';
			$status_key = 'limited';
		} else {
			$classes[]  = 'is-available';
			$status_key = 'available';
		}
		if ( $is_today ) {
			$classes[] = 'is-today';
		}
		if ( ! $in_month ) {
			$classes[] = 'is-out-of-month';
		}

		$status_label = $this->status_label( $status_key );

		$aria_label = wp_date( 'l, F j, Y', $day_local->getTimestamp(), $tz ) . ' - ' . $status_label;

		$out  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="gridcell" aria-label="' . esc_attr( $aria_label ) . '">';
		$out .= '<time class="shootcal-web-calendar__date" datetime="' . esc_attr( $iso_date ) . '">' . esc_html( (string) $day_num ) . '</time>';

		// Status pill + booking times: shown on all non-past cells, regardless of
		// whether the cell belongs to the visible month or is an out-of-month
		// leading/trailing day. Matches the macOS app where off-month dates
		// surface their events in the same visual treatment, only with a grayed
		// date number to signal which month they actually belong to.
		// Past cells stay quiet (no chip) - visitors care about future bookable days.
		if ( ! $is_past ) {
			$out .= '<span class="shootcal-web-calendar__status">' . esc_html( $status_label ) . '</span>';

			// On a "Limited" day (timed events only) we always render the booked
			// time windows under the pill - "Booked 5-7 PM" - so visitors can
			// instantly see what's free without having to ask. On full-day
			// "Booked" days the time list is redundant (it would just repeat
			// "All day"), so we omit it.
			if ( $has_timed && ! $has_full_day ) {
				$timed = array();
				foreach ( $covering as $event ) {
					if ( ! $event->all_day ) {
						$timed[] = $event;
					}
				}
				$limit = 2; // show at most 2 inline; the rest sit behind "+N more"
				$out  .= '<ul class="shootcal-web-calendar__bookings">';
				foreach ( $timed as $i => $event ) {
					$overflow = ( $i >= $limit ) ? ' shootcal-web-calendar__booking--overflow' : '';
					$out     .= '<li class="shootcal-web-calendar__booking' . $overflow . '">' . esc_html( $this->format_booking_window( $event, $day_local, $tz ) ) . '</li>';
				}
				$extra = count( $timed ) - $limit;
				if ( $extra > 0 ) {
					$out .= '<li class="shootcal-web-calendar__booking--more">' . esc_html(
						/* translators: %d: number of additional bookings hidden behind the "more" indicator. */
						sprintf( _n( '+%d more', '+%d more', $extra, 'shootcal-web-calendar' ), $extra )
					) . '</li>';
				}
				$out .= '</ul>';
			}
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * Full-calendar cell: lists each event's title + time for the day. No
	 * availability status/colors are applied (those are availability-mode
	 * semantics). Past days still list their events - a sunrise/sunset style
	 * calendar is informational, not a booking grid.
	 *
	 * @param Event[] $covering
	 */
	private function render_cell_full( \DateTimeImmutable $day_local, string $iso_date, int $day_num, bool $is_past, bool $is_today, bool $in_month, array $covering, \DateTimeZone $tz ): string {
		$classes = array( 'shootcal-web-calendar__day' );
		if ( $is_past ) {
			$classes[] = 'is-past';
		}
		if ( $is_today ) {
			$classes[] = 'is-today';
		}
		if ( ! $in_month ) {
			$classes[] = 'is-out-of-month';
		}
		if ( ! empty( $covering ) ) {
			$classes[] = 'has-events';
		}

		// Order a day's events by start time so e.g. Sunrise lists before Sunset.
		usort(
			$covering,
			static fn( Event $a, Event $b ) => $a->start <=> $b->start
		);

		$count     = count( $covering );
		$date_long = wp_date( 'l, F j, Y', $day_local->getTimestamp(), $tz );
		$aria_label = ( $count > 0 )
			/* translators: 1: long date, 2: number of events on that day. */
			? sprintf( _n( '%1$s - %2$d event', '%1$s - %2$d events', $count, 'shootcal-web-calendar' ), $date_long, $count )
			: $date_long;

		$out  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" role="gridcell" aria-label="' . esc_attr( $aria_label ) . '">';
		$out .= '<time class="shootcal-web-calendar__date" datetime="' . esc_attr( $iso_date ) . '">' . esc_html( (string) $day_num ) . '</time>';

		if ( $count > 0 ) {
			$out .= '<ul class="shootcal-web-calendar__events">';
			foreach ( $covering as $event ) {
				// format_event_label() escapes each dynamic part as it builds the span markup.
				$out .= '<li class="shootcal-web-calendar__event">' . $this->format_event_label( $event, $tz ) . '</li>';
			}
			$out .= '</ul>';
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 * Escaped label for one event in full mode: a time span (timed events) plus a
	 * title span. All-day events show the title only, or "All day" when untitled.
	 */
	private function format_event_label( Event $event, \DateTimeZone $tz ): string {
		$summary = ( null !== $event->summary ) ? trim( $event->summary ) : '';

		if ( $event->all_day ) {
			$title = ( '' !== $summary ) ? $summary : __( 'All day', 'shootcal-web-calendar' );
			return '<span class="shootcal-web-calendar__event-title">' . esc_html( $title ) . '</span>';
		}

		$time_format = (string) get_option( 'time_format', 'g:i a' );
		$start_local = $event->start->setTimezone( $tz );
		$label       = '<span class="shootcal-web-calendar__event-time">' . esc_html( wp_date( $time_format, $start_local->getTimestamp(), $tz ) ) . '</span>';
		if ( '' !== $summary ) {
			$label .= '<span class="shootcal-web-calendar__event-title">' . esc_html( $summary ) . '</span>';
		}
		return $label;
	}

	private function status_label( string $key ): string {
		switch ( $key ) {
			case 'past':
				return __( 'Past', 'shootcal-web-calendar' );
			case 'booked':
				return __( 'Booked', 'shootcal-web-calendar' );
			case 'limited':
				return __( 'Limited', 'shootcal-web-calendar' );
			case 'available':
			default:
				return __( 'Available', 'shootcal-web-calendar' );
		}
	}

	private function format_booking_window( Event $event, \DateTimeImmutable $day_local, \DateTimeZone $tz ): string {
		if ( $event->all_day ) {
			return __( 'Booked all day', 'shootcal-web-calendar' );
		}
		$day_start = $day_local->setTime( 0, 0, 0 );
		$day_end   = $day_start->modify( '+1 day' );

		$start = max( $event->start->setTimezone( $tz ), $day_start );
		$end   = min( $event->end->setTimezone( $tz ), $day_end );

		$time_format = (string) get_option( 'time_format', 'g:i a' );

		/* translators: 1: booked window start time, 2: booked window end time. */
		return sprintf(
			__( 'Booked %1$s - %2$s', 'shootcal-web-calendar' ),
			wp_date( $time_format, $start->getTimestamp(), $tz ),
			wp_date( $time_format, $end->getTimestamp(), $tz )
		);
	}
}
