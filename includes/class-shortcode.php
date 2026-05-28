<?php
/**
 * [shootcal_availability] shortcode.
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	public const TAG = 'shootcal_availability';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts = array() ): string {
		$opts = Settings::get_options();

		// For Google source, default months comes from settings.
		// For ShootCal source, default is empty string meaning "auto-detect from feed".
		// The shortcode `months` attribute (if explicitly set) still works as an override/cap.
		$default_months = ( 'shootcal' === $opts['source'] ) ? '' : (string) $opts['months_ahead'];

		$atts = shortcode_atts(
			array(
				'months'      => $default_months,
				'first_day'   => (string) $opts['first_day_of_week'],
				// Timezone default is intentionally empty so resolve_timezone()
				// can prefer the feed's X-WR-TIMEZONE header for ShootCal source
				// over the saved settings value. Only an explicit timezone="..."
				// attribute on the shortcode counts as a manual override.
				'timezone'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$attr_months  = (int) $atts['months']; // 0 if empty / not provided
		$first_dow    = ( 1 === (int) $atts['first_day'] ) ? 1 : 0;

		$fetcher = new Fetcher();
		$ical    = $fetcher->get_ical_text();
		if ( is_wp_error( $ical ) ) {
			return $this->render_admin_notice( $ical->get_error_message() );
		}

		// Resolve display timezone.
		// Priority order:
		//   1. `timezone` shortcode attribute (manual override, highest priority)
		//   2. For ShootCal source: X-WR-TIMEZONE from the feed itself
		//   3. The plugin's saved `timezone` setting
		//   4. WordPress general timezone setting
		// This means a ShootCal user with an EDT app gets EDT in the plugin even
		// if their WordPress site is on UTC defaults - no manual setup needed.
		$tz = $this->resolve_timezone(
			(string) $atts['timezone'],
			(string) $opts['source'],
			$ical,
			(string) $opts['timezone']
		);

		$parser = new ICal_Parser();
		$events = $parser->parse( $ical );

		$today        = new \DateTimeImmutable( 'today', $tz );
		$window_start = $today->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		// Determine months_ahead:
		//   - Google source: settings value, capped by `months` attr if provided.
		//   - ShootCal source: auto-detect from latest event in the feed (start of
		//     current month -> month containing the last event), capped by `months`
		//     attr if provided. Falls back to 3 months for an empty feed.
		if ( 'shootcal' === $opts['source'] ) {
			$months_ahead = $this->auto_detect_months( $events, $window_start, $tz );
			if ( $attr_months > 0 ) {
				$months_ahead = min( $months_ahead, $attr_months );
			}
		} else {
			$months_ahead = $attr_months > 0 ? $attr_months : (int) $opts['months_ahead'];
		}
		$months_ahead = max( 1, min( 36, $months_ahead ) );

		$window_end = $window_start->modify( '+' . $months_ahead . ' months' );

		$events = array_values(
			array_filter(
				$events,
				static fn( Event $e ) => $e->overlaps( $window_start, $window_end )
			)
		);

		// Pre-bucket events by day so each grid cell does an O(1) array lookup
		// instead of iterating all events. For 36 months with 450 events the
		// difference is ~675K overlap checks vs ~1500 hash lookups.
		$events_by_day = array();
		foreach ( $events as $event ) {
			foreach ( $event->days_covered( $tz ) as $day_key ) {
				$events_by_day[ $day_key ][] = $event;
			}
		}

		$grid                  = new Month_Grid();
		$cursor                = $window_start;
		$multi_session_day     = ! empty( $opts['multi_session_day'] );

		// Render each month as its own panel. Build a flat list - we will hide
		// all but one and let the toolbar's prev/next/today nav switch between them.
		$today          = new \DateTimeImmutable( 'today', $tz );
		$today_y        = (int) $today->format( 'Y' );
		$today_m        = (int) $today->format( 'n' );
		$active_idx     = 0; // default to first panel if today isn't in the window
		$today_idx      = -1; // -1 if today's month is outside the visible window

		$panels = array();
		for ( $i = 0; $i < $months_ahead; $i++ ) {
			$y = (int) $cursor->format( 'Y' );
			$m = (int) $cursor->format( 'n' );

			$label       = wp_date( 'F Y', $cursor->getTimestamp(), $tz );
			$label_month = wp_date( 'F', $cursor->getTimestamp(), $tz );
			$label_year  = wp_date( 'Y', $cursor->getTimestamp(), $tz );
			$panels[] = array(
				'idx'         => $i,
				'year'        => $y,
				'month'       => $m,
				'label'       => $label,
				'label_month' => $label_month,
				'label_year'  => $label_year,
				'html'        => $grid->render( $y, $m, $events_by_day, $tz, $first_dow, $multi_session_day ),
			);

			if ( $y === $today_y && $m === $today_m ) {
				$active_idx = $i;
				$today_idx  = $i;
			}

			$cursor = $cursor->modify( '+1 month' );
		}

		$credit       = $this->credit_html( (string) $opts['source'] );
		$inline_style = $this->color_override_style( $opts );

		// Single month: skip the toolbar - nothing to navigate.
		if ( count( $panels ) === 1 ) {
			return $inline_style . '<div class="shootcal-availability__wrap">' . $panels[0]['html'] . $credit . '</div>';
		}

		return $inline_style . $this->render_paginated( $panels, $active_idx, $today_idx, $credit );
	}

	/**
	 * Emit a tiny `<style>` block that overrides the cell-color CSS variables
	 * with the user's color-picker choices. The variables are RGB triplets so
	 * the stylesheet can wrap them in rgba() to render 80% opacity at rest
	 * and 100% on hover (the user-chosen color is the hover/peak color).
	 */
	private function color_override_style( array $opts ): string {
		$limited_rgb = $this->hex_to_rgb_triplet( (string) ( $opts['limited_color'] ?? '#fdf2dd' ) );
		$booked_rgb  = $this->hex_to_rgb_triplet( (string) ( $opts['booked_color']  ?? '#fae0cf' ) );

		// Only emit overrides for non-default values, to keep page weight tiny.
		$defaults = array(
			'253, 242, 221' => true, // #fdf2dd
			'250, 224, 207' => true, // #fae0cf
		);
		if ( isset( $defaults[ $limited_rgb ] ) && isset( $defaults[ $booked_rgb ] ) ) {
			return '';
		}

		return sprintf(
			'<style>.shootcal-availability__wrap{--shootcal-limited-bg-rgb:%s;--shootcal-booked-bg-rgb:%s;}</style>',
			esc_attr( $limited_rgb ),
			esc_attr( $booked_rgb )
		);
	}

	/**
	 * Convert "#rrggbb" (or "#rgb") to "R, G, B" comma-separated triplet.
	 * Returns a sensible fallback on bad input.
	 */
	private function hex_to_rgb_triplet( string $hex ): string {
		$hex = ltrim( trim( $hex ), '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return '253, 242, 221'; // soft default (matches Limited bg)
		}
		return (string) hexdec( substr( $hex, 0, 2 ) ) . ', ' .
		       (string) hexdec( substr( $hex, 2, 2 ) ) . ', ' .
		       (string) hexdec( substr( $hex, 4, 2 ) );
	}

	/**
	 * Render the toolbar + viewport layout (one month visible at a time).
	 *
	 * @param array<int, array{idx:int,year:int,month:int,label:string,html:string}> $panels
	 */
	private function render_paginated( array $panels, int $active_idx, int $today_idx, string $credit ): string {
		$total       = count( $panels );
		$first_label = $panels[0]['label'];
		$last_label  = $panels[ $total - 1 ]['label'];

		$html  = '<div class="shootcal-availability__wrap shootcal-availability__wrap--paginated" data-shootcal-total="' . (int) $total . '" data-shootcal-today="' . (int) $today_idx . '" data-shootcal-active="' . (int) $active_idx . '">';

		// Toolbar - month label on the left, navigation group (< Today >) on the right.
		$html .= '<div class="shootcal-availability__toolbar" role="group" aria-label="' . esc_attr__( 'Calendar navigation', 'shootcal-availability' ) . '">';

		$html .= sprintf(
			'<h2 class="shootcal-availability__nav-label" aria-live="polite" data-shootcal-month-label>%s <span class="shootcal-availability__nav-year">%s</span></h2>',
			esc_html( $panels[ $active_idx ]['label_month'] ),
			esc_html( $panels[ $active_idx ]['label_year'] )
		);

		$html .= '<div class="shootcal-availability__nav">';
		$html .= sprintf(
			'<button type="button" class="shootcal-availability__btn shootcal-availability__btn--prev" data-shootcal-action="prev" aria-label="%s"%s>%s</button>',
			esc_attr__( 'Previous month', 'shootcal-availability' ),
			$active_idx === 0 ? ' disabled' : '',
			'&lsaquo;'
		);
		$today_disabled = ( $today_idx === -1 || $today_idx === $active_idx );
		$html .= sprintf(
			'<button type="button" class="shootcal-availability__btn shootcal-availability__btn--today" data-shootcal-action="today"%s>%s</button>',
			$today_disabled ? ' disabled' : '',
			esc_html__( 'Today', 'shootcal-availability' )
		);
		$html .= sprintf(
			'<button type="button" class="shootcal-availability__btn shootcal-availability__btn--next" data-shootcal-action="next" aria-label="%s"%s>%s</button>',
			esc_attr__( 'Next month', 'shootcal-availability' ),
			$active_idx === $total - 1 ? ' disabled' : '',
			'&rsaquo;'
		);
		$html .= '</div>';

		$html .= '</div>'; // toolbar

		// Viewport - all month panels rendered, only the active one visible.
		$html .= '<div class="shootcal-availability__viewport">';
		foreach ( $panels as $p ) {
			$is_active = ( $p['idx'] === $active_idx );
			$html     .= sprintf(
				'<div class="shootcal-availability__month-panel%s" data-shootcal-month-idx="%d" data-shootcal-month-label="%s" data-shootcal-month-name="%s" data-shootcal-month-year="%s"%s>%s</div>',
				$is_active ? ' is-active' : '',
				$p['idx'],
				esc_attr( $p['label'] ),
				esc_attr( $p['label_month'] ),
				esc_attr( $p['label_year'] ),
				$is_active ? '' : ' hidden',
				$p['html']
			);
		}
		$html .= '</div>';

		// Screen-reader only context about the available range.
		$html .= '<p class="shootcal-availability__sr-only">' . sprintf(
			/* translators: 1: earliest visible month, 2: latest visible month. */
			esc_html__( 'Available months: %1$s through %2$s.', 'shootcal-availability' ),
			esc_html( $first_label ),
			esc_html( $last_label )
		) . '</p>';

		$html .= $credit;
		$html .= '</div>';

		return $html;
	}

	/**
	 * Attribution line shown beneath the grid when the data source is ShootCal.
	 * Returns an empty string for the Google source - no credit is owed there.
	 */
	private function credit_html( string $source ): string {
		if ( 'shootcal' !== $source ) {
			return '';
		}
		$link = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://www.ryansmithphotography.com/photography-apps/shootcal/' ),
			esc_html__( 'ShootCal', 'shootcal-availability' )
		);
		return '<p class="shootcal-availability__credit">' . sprintf(
			/* translators: %s: linked plugin name. */
			esc_html__( 'Calendar provided by %s by Ryan Smith Photography', 'shootcal-availability' ),
			$link // already escaped above
		) . '</p>';
	}

	/**
	 * Resolve the display timezone. Returns a DateTimeZone; never throws.
	 * See render() for the priority order.
	 */
	private function resolve_timezone( string $attr_tz, string $source, string $ical, string $settings_tz ): \DateTimeZone {
		// 1. Shortcode attribute - highest priority manual override.
		if ( $attr_tz !== '' ) {
			$tz = $this->try_tz( $attr_tz );
			if ( $tz !== null ) {
				return $tz;
			}
		}

		// 2. For ShootCal source, pull X-WR-TIMEZONE from the feed itself.
		// (Not done for Google source - Google calendars embed VTIMEZONE blocks
		// which are more involved and the user's WP setting is the right anchor.)
		if ( 'shootcal' === $source ) {
			$feed_tz = $this->extract_feed_timezone( $ical );
			if ( $feed_tz !== '' ) {
				$tz = $this->try_tz( $feed_tz );
				if ( $tz !== null ) {
					return $tz;
				}
			}
		}

		// 3. Saved plugin setting (only meaningful for Google source after the UI
		// hides the field for ShootCal; for ShootCal it'll usually be the wp default).
		if ( $settings_tz !== '' ) {
			$tz = $this->try_tz( $settings_tz );
			if ( $tz !== null ) {
				return $tz;
			}
		}

		// 4. Final fallback.
		return wp_timezone();
	}

	private function try_tz( string $name ): ?\DateTimeZone {
		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Pull `X-WR-TIMEZONE` from the calendar header. Returns '' if absent.
	 */
	private function extract_feed_timezone( string $ical ): string {
		if ( preg_match( '/^X-WR-TIMEZONE\s*:\s*([^\r\n]+)/mi', $ical, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Find how many months to render based on the latest event end in the feed.
	 * Returns at least 1 (current month always renders), capped at 36 by the caller.
	 *
	 * @param Event[]            $events
	 * @param \DateTimeImmutable $window_start First day of the current month, local tz.
	 * @param \DateTimeZone      $tz
	 */
	private function auto_detect_months( array $events, \DateTimeImmutable $window_start, \DateTimeZone $tz ): int {
		if ( $events === array() ) {
			return 3; // sensible default for an empty feed
		}

		$latest_end = null;
		foreach ( $events as $event ) {
			$end_local = $event->end->setTimezone( $tz );
			if ( $latest_end === null || $end_local > $latest_end ) {
				$latest_end = $end_local;
			}
		}
		if ( $latest_end === null || $latest_end <= $window_start ) {
			return 1;
		}

		// DTEND in iCal is EXCLUSIVE - an event with DTEND at exactly midnight of
		// the next month doesn't actually cover any day in that next month. Back
		// off by one second so $last_occupied represents the last day the event
		// actually occupied. Without this, an event ending Apr 1 00:00 forces an
		// empty April panel to render.
		$last_occupied  = $latest_end->modify( '-1 second' );
		$end_month_start = $last_occupied->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		$start_y = (int) $window_start->format( 'Y' );
		$start_m = (int) $window_start->format( 'n' );
		$end_y   = (int) $end_month_start->format( 'Y' );
		$end_m   = (int) $end_month_start->format( 'n' );

		$months = ( $end_y - $start_y ) * 12 + ( $end_m - $start_m ) + 1; // +1 to include the end's month
		return max( 1, $months );
	}

	private function render_admin_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return sprintf(
			'<div class="shootcal-availability__error" style="border:1px solid #c00; padding:.75em 1em; background:#fff5f5;"><strong>%s</strong> %s</div>',
			esc_html__( 'ShootCal Availability:', 'shootcal-availability' ),
			esc_html( $message )
		);
	}
}
