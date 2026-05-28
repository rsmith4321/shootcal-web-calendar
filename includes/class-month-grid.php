<?php
/**
 * Render one month as an availability grid.
 *
 * Uses CSS Grid for layout (display: grid on the container), with ARIA grid
 * semantics (role="grid" / "row" / "gridcell" / "columnheader") so screen
 * readers still navigate it as a 2D calendar regardless of how the visual
 * layout is rendered.
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

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
	public function render( int $year, int $month, array $events_by_day, \DateTimeZone $tz, int $first_dow, bool $multi_session_day = true ): string {
		$first_of_month = (new \DateTimeImmutable( 'now', $tz ))->setDate( $year, $month, 1 )->setTime( 0, 0, 0 );

		// Today's date in the display timezone, used to mute past day cells.
		$today_local = ( new \DateTimeImmutable( 'today', $tz ) )->setTime( 0, 0, 0 );

		$first_dow_in_month = (int) $first_of_month->format( 'w' );
		$leading            = ( $first_dow_in_month - $first_dow + 7 ) % 7;
		$grid_start         = $first_of_month->modify( "-{$leading} days" );

		$month_label    = wp_date( 'F Y', $first_of_month->getTimestamp(), $tz );
		$weekday_labels = $this->weekday_labels( $first_dow );

		$html  = '<div class="shootcal-availability shootcal-availability--month" role="grid" aria-label="' . esc_attr( $month_label ) . '">';
		$html .= '<div class="shootcal-availability__caption">' . esc_html( $month_label ) . '</div>';

		// Weekday header row.
		$html .= '<div class="shootcal-availability__week shootcal-availability__week--header" role="row">';
		foreach ( $weekday_labels as $label ) {
			$html .= '<div class="shootcal-availability__weekday" role="columnheader" abbr="' . esc_attr( $label['full'] ) . '"><abbr title="' . esc_attr( $label['full'] ) . '">' . esc_html( $label['short'] ) . '</abbr></div>';
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
				$row_html .= $this->render_cell( $cell_day, $events_by_day, $tz, $in_month, $today_local, $multi_session_day );
			}
			$html .= '<div class="shootcal-availability__week" role="row">' . $row_html . '</div>';
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
	private function render_cell( \DateTimeImmutable $day, array $events_by_day, \DateTimeZone $tz, bool $in_month, \DateTimeImmutable $today_local, bool $multi_session_day = true ): string {
		$day_local = $day->setTimezone( $tz );
		$day_num   = (int) $day_local->format( 'j' );
		$iso_date  = $day_local->format( 'Y-m-d' );
		$is_past   = $day_local->setTime( 0, 0, 0 ) < $today_local;
		$is_today  = $day_local->setTime( 0, 0, 0 ) == $today_local;

		$covering = $events_by_day[ $iso_date ] ?? array();

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

		$classes = array( 'shootcal-availability__day' );

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
		$out .= '<time class="shootcal-availability__date" datetime="' . esc_attr( $iso_date ) . '">' . esc_html( (string) $day_num ) . '</time>';

		// Status pill + booking times: shown on all non-past cells, regardless of
		// whether the cell belongs to the visible month or is an out-of-month
		// leading/trailing day. Matches the macOS app where off-month dates
		// surface their events in the same visual treatment, only with a grayed
		// date number to signal which month they actually belong to.
		// Past cells stay quiet (no chip) - visitors care about future bookable days.
		if ( ! $is_past ) {
			$out .= '<span class="shootcal-availability__status">' . esc_html( $status_label ) . '</span>';

			// On a "Limited" day (timed events only) we always render the booked
			// time windows under the pill - "Booked 5-7 PM" - so visitors can
			// instantly see what's free without having to ask. On full-day
			// "Booked" days the time list is redundant (it would just repeat
			// "All day"), so we omit it.
			if ( $has_timed && ! $has_full_day ) {
				$out .= '<ul class="shootcal-availability__bookings">';
				foreach ( $covering as $event ) {
					if ( ! $event->all_day ) {
						$out .= '<li>' . esc_html( $this->format_booking_window( $event, $day_local, $tz ) ) . '</li>';
					}
				}
				$out .= '</ul>';
			}
		}

		$out .= '</div>';
		return $out;
	}

	private function status_label( string $key ): string {
		switch ( $key ) {
			case 'past':
				return __( 'Past', 'shootcal-availability' );
			case 'booked':
				return __( 'Booked', 'shootcal-availability' );
			case 'limited':
				return __( 'Limited', 'shootcal-availability' );
			case 'available':
			default:
				return __( 'Available', 'shootcal-availability' );
		}
	}

	private function format_booking_window( Event $event, \DateTimeImmutable $day_local, \DateTimeZone $tz ): string {
		if ( $event->all_day ) {
			return __( 'Booked all day', 'shootcal-availability' );
		}
		$day_start = $day_local->setTime( 0, 0, 0 );
		$day_end   = $day_start->modify( '+1 day' );

		$start = max( $event->start->setTimezone( $tz ), $day_start );
		$end   = min( $event->end->setTimezone( $tz ), $day_end );

		$time_format = (string) get_option( 'time_format', 'g:i a' );

		/* translators: 1: booked window start time, 2: booked window end time. */
		return sprintf(
			__( 'Booked %1$s - %2$s', 'shootcal-availability' ),
			wp_date( $time_format, $start->getTimestamp(), $tz ),
			wp_date( $time_format, $end->getTimestamp(), $tz )
		);
	}
}
