<?php
/**
 * [shootcal_web_calendar] shortcode.
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

class Shortcode {

	public const TAG = 'shootcal_web_calendar';

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_ajax_shootcal_web_calendar_render', array( $this, 'handle_ajax_render' ) );
		add_action( 'wp_ajax_nopriv_shootcal_web_calendar_render', array( $this, 'handle_ajax_render' ) );
	}

	/**
	 * Shortcode/block entry point. In AJAX mode this returns a lightweight
	 * placeholder that JavaScript hydrates after the (cacheable) page loads;
	 * otherwise it renders the calendar server-side as usual.
	 *
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();
		// "Page caching" mode: emit a lightweight placeholder that JS hydrates from
		// admin-ajax, so the page stays fully cacheable while the calendar renders
		// fresh behind a full-page cache (e.g. Varnish). The placeholder carries the
		// per-embed URL plus an HMAC signature (see render_lazy_placeholder) so the
		// public AJAX endpoint only renders URLs the plugin itself emitted - never an
		// arbitrary attacker-supplied one.
		if ( ! empty( Settings::get_options()['ajax_render'] ) ) {
			return $this->render_lazy_placeholder( $atts );
		}
		return $this->render_calendar( $atts );
	}

	/**
	 * HMAC over the embed parameters, keyed on the site's auth salt. The lazy
	 * placeholder emits this alongside the params; the public AJAX endpoint
	 * recomputes and compares it, so it will only ever render a URL the plugin
	 * itself placed on a page - not an arbitrary attacker-supplied one.
	 */
	private static function signature( string $url, string $mode, string $months, string $first_day, string $timezone, string $msd, string $limited_color, string $booked_color ): string {
		return hash_hmac( 'sha256', implode( "\n", array( $url, $mode, $months, $first_day, $timezone, $msd, $limited_color, $booked_color ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Placeholder emitted in "Page caching" mode. Carries the embed's feed URL +
	 * display options as data attributes, plus an HMAC signature so the AJAX
	 * endpoint can trust the URL (see signature()). JS swaps in the real calendar
	 * after the (cacheable) page loads.
	 *
	 * @param array<string,string> $atts
	 */
	private function render_lazy_placeholder( array $atts ): string {
		$opts      = Settings::get_options();
		$url       = esc_url_raw( trim( html_entity_decode( (string) ( $atts['url'] ?? '' ), ENT_QUOTES ) ) );
		$mode      = ( isset( $atts['mode'] ) && 'full' === strtolower( (string) $atts['mode'] ) ) ? 'full' : 'availability';
		$months    = ( isset( $atts['months'] ) && (int) $atts['months'] > 0 ) ? (string) (int) $atts['months'] : '';
		$first_day = ( isset( $atts['first_day'] ) && '1' === (string) $atts['first_day'] ) ? '1' : (string) (int) $opts['first_day_of_week'];
		$timezone  = ! empty( $atts['timezone'] ) ? (string) $atts['timezone'] : '';
		$msd       = ( isset( $atts['multi_session_day'] ) && '0' === (string) $atts['multi_session_day'] ) ? '0' : '1';
		// Per-embed color overrides only apply (and only get signed) in availability mode.
		$limited   = ( 'availability' === $mode && ! empty( $atts['limited_color'] ) ) ? (string) sanitize_hex_color( (string) $atts['limited_color'] ) : '';
		$booked    = ( 'availability' === $mode && ! empty( $atts['booked_color'] ) )  ? (string) sanitize_hex_color( (string) $atts['booked_color'] )  : '';
		$sig       = self::signature( $url, $mode, $months, $first_day, $timezone, $msd, $limited, $booked );

		$data  = ' data-shootcal-url="' . esc_attr( $url ) . '"';
		$data .= ' data-shootcal-mode="' . esc_attr( $mode ) . '"';
		if ( '' !== $months ) {
			$data .= ' data-shootcal-months="' . esc_attr( $months ) . '"';
		}
		$data .= ' data-shootcal-first-day="' . esc_attr( $first_day ) . '"';
		if ( '' !== $timezone ) {
			$data .= ' data-shootcal-timezone="' . esc_attr( $timezone ) . '"';
		}
		$data .= ' data-shootcal-msd="' . esc_attr( $msd ) . '"';
		if ( '' !== $limited ) {
			$data .= ' data-shootcal-limited-color="' . esc_attr( $limited ) . '"';
		}
		if ( '' !== $booked ) {
			$data .= ' data-shootcal-booked-color="' . esc_attr( $booked ) . '"';
		}
		$data .= ' data-shootcal-sig="' . esc_attr( $sig ) . '"';

		return '<div class="shootcal-web-calendar__wrap shootcal-web-calendar__lazy" data-shootcal-lazy' . $data . '>'
			. '<p class="shootcal-web-calendar__lazy-msg">' . esc_html__( 'Loading calendar…', 'shootcal-web-calendar' ) . '</p>'
			. '</div>';
	}

	/**
	 * AJAX endpoint: render the calendar fresh, bypassing full-page caches.
	 * Public (logged-in + logged-out), read-only. The URL + options must carry a
	 * valid HMAC signature (see signature()), so this endpoint can only render an
	 * embed the plugin itself emitted - never an arbitrary attacker URL. The fetch
	 * still goes through wp_safe_remote_get, which blocks internal/SSRF targets.
	 */
	public function handle_ajax_render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authenticated by the HMAC signature below, not a nonce.
		// Mirror the emit-side normalization (render()) exactly so the HMAC
		// over $url matches; esc_url_raw also satisfies input sanitization.
		$url       = isset( $_POST['url'] ) ? esc_url_raw( trim( html_entity_decode( (string) wp_unslash( $_POST['url'] ), ENT_QUOTES ) ) ) : '';
		$mode      = ( isset( $_POST['mode'] ) && 'full' === $_POST['mode'] ) ? 'full' : 'availability';
		$months    = ( isset( $_POST['months'] ) && (int) $_POST['months'] > 0 ) ? (string) min( 36, (int) $_POST['months'] ) : '';
		$first_day = ( isset( $_POST['first_day'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['first_day'] ) ) ) ? '1' : '0';
		$timezone  = isset( $_POST['timezone'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['timezone'] ) ) : '';
		$msd       = ( isset( $_POST['msd'] ) && '0' === sanitize_text_field( wp_unslash( $_POST['msd'] ) ) ) ? '0' : '1';
		$limited   = isset( $_POST['limited_color'] ) ? (string) sanitize_hex_color( (string) wp_unslash( $_POST['limited_color'] ) ) : '';
		$booked    = isset( $_POST['booked_color'] )  ? (string) sanitize_hex_color( (string) wp_unslash( $_POST['booked_color'] ) )  : '';
		$sig       = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$expected = self::signature( $url, $mode, $months, $first_day, $timezone, $msd, $limited, $booked );
		if ( '' === $url || ! hash_equals( $expected, $sig ) ) {
			status_header( 400 );
			wp_die( '', '', array( 'response' => 400 ) );
		}

		$atts = array(
			'url'               => $url,
			'mode'              => $mode,
			'first_day'         => $first_day,
			'multi_session_day' => $msd,
		);
		if ( '' !== $months ) {
			$atts['months'] = $months;
		}
		if ( '' !== $timezone ) {
			$atts['timezone'] = $timezone;
		}
		if ( '' !== $limited ) {
			$atts['limited_color'] = $limited;
		}
		if ( '' !== $booked ) {
			$atts['booked_color'] = $booked;
		}

		nocache_headers();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_calendar() escapes all dynamic values as it builds the markup.
		echo $this->render_calendar( $atts );
		wp_die();
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	private function render_calendar( $atts = array() ): string {
		$opts = Settings::get_options();

		$atts = shortcode_atts(
			array(
				// Months is resolved after we know the source (below); blank means
				// "use the setting / auto-detect from the feed".
				'months'      => '',
				'first_day'   => (string) $opts['first_day_of_week'],
				// Timezone default is intentionally empty so resolve_timezone()
				// can prefer the feed's X-WR-TIMEZONE header for a ShootCal source
				// over the saved settings value. Only an explicit timezone="..."
				// attribute counts as a manual override.
				'timezone'    => '',
				// Display mode: "availability" (free/busy shading, default) or
				// "full" (show each event's title + time).
				'mode'        => 'availability',
				// Per-embed feed URL (required to render anything).
				'url'         => '',
				// Availability-mode only: "1" = a day with only timed events shows
				// as "Limited" (room for another client at a different time); "0" =
				// any event marks the whole day "Booked". Only meaningful for an
				// availability feed, so it lives per-embed, not site-wide.
				'multi_session_day' => '1',
				// Availability-mode only, per-embed: optional cell-color overrides for
				// the Limited and Booked day shading (hex like #fce3a8). Empty = the
				// built-in defaults. Ignored in full mode. These live on each embed
				// (shortcode/block), not site-wide.
				'limited_color'     => '',
				'booked_color'      => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		// Feed URL comes from the per-embed `url` attribute (shortcode or block).
		// The source (ShootCal vs generic iCal) is detected from the URL's host.
		$mode       = ( 'full' === strtolower( (string) $atts['mode'] ) ) ? 'full' : 'availability';
		// Decode entities the editor may inject into a shortcode attribute (e.g.
		// & -> &amp; inside a query string) before sanitizing, so multi-parameter
		// feed URLs survive. Block attributes are stored clean and unaffected.
		$active_url = esc_url_raw( trim( html_entity_decode( (string) $atts['url'], ENT_QUOTES ) ) );
		$source     = $this->source_for_url( $active_url );

		$attr_months  = (int) $atts['months']; // 0 if empty / not provided
		$first_dow    = ( 1 === (int) $atts['first_day'] ) ? 1 : 0;

		$fetcher = new Fetcher();
		$ical    = $fetcher->get_ical_text( $active_url );
		if ( is_wp_error( $ical ) ) {
			return $this->render_admin_notice( $ical->get_error_message() );
		}

		// Resolve display timezone. Priority:
		//   1. `timezone` shortcode/block attribute (explicit per-embed override)
		//   2. ShootCal source: X-WR-TIMEZONE from the feed itself
		//   3. The WordPress site timezone (Settings > General)
		// The plugin has no timezone setting of its own - it follows WordPress.
		$tz = $this->resolve_timezone(
			(string) $atts['timezone'],
			$source,
			$ical
		);

		// --- Rendered-output cache (the "rate limit" for the AJAX / uncached path) ---
		// The expensive work below (parse + recurrence expansion + per-day bucketing
		// + building up to 36 month panels) is cached for CACHE_TTL (10 min), keyed
		// on every input that changes the output: the feed body, the display options
		// and per-embed overrides, the resolved timezone, and today's date. So a
		// public AJAX render - or any uncached page view - re-runs that work at most
		// once per 10 minutes per distinct view instead of on every request. It
		// clears itself when the feed refreshes, when settings change, when the
		// "Clear cache" button bumps the cache version, or when the day rolls over,
		// and event data is never held beyond the 10-minute window.
		$today_render_date = ( new \DateTimeImmutable( 'today', $tz ) )->format( 'Y-m-d' );
		$render_key        = CACHE_KEY . '_html_' . md5(
			implode(
				'|',
				array(
					(string) get_option( 'shootcal_web_calendar_cache_ver', 0 ),
					$tz->getName(),
					$today_render_date,
					$source,
					(string) wp_json_encode( $opts ),
					(string) wp_json_encode( $atts ),
					$ical,
				)
			)
		);
		$cached_html = get_transient( $render_key );
		if ( is_string( $cached_html ) && '' !== $cached_html ) {
			return $cached_html;
		}

		$parser = new ICal_Parser();
		$events = $parser->parse( $ical );

		$today        = new \DateTimeImmutable( 'today', $tz );
		$window_start = $today->modify( 'first day of this month' )->setTime( 0, 0, 0 );

		// Determine months_ahead:
		//   - Google source: settings value, capped by `months` attr if provided.
		//   - ShootCal source: auto-detect from latest event in the feed (start of
		//     current month -> month containing the last event), capped by `months`
		//     attr if provided. Falls back to 3 months for an empty feed.
		if ( 'shootcal' === $source ) {
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
		// Per-embed: only "0" disables the Limited tier; anything else (default "1") keeps it.
		$multi_session_day     = ( '0' !== (string) $atts['multi_session_day'] );

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
				'html'        => $grid->render( $y, $m, $events_by_day, $tz, $first_dow, $multi_session_day, $mode ),
			);

			if ( $y === $today_y && $m === $today_m ) {
				$active_idx = $i;
				$today_idx  = $i;
			}

			$cursor = $cursor->modify( '+1 month' );
		}

		// The availability legend (Available / Limited / Booked) only applies in
		// availability mode; full mode just lists events, so skip it there.
		$legend       = ( 'full' === $mode ) ? '' : $this->legend_html( $multi_session_day );
		$credit       = $legend . $this->credit_html( $source );

		// Per-embed cell-color overrides (availability mode only), emitted as CSS
		// custom properties on the wrap's own inline style attribute. Default/empty
		// colors yield an empty string, so the stylesheet's built-in defaults apply
		// and the page stays light. An inline style attribute (unlike a <style>
		// block) needs no enqueue and travels with the cached and AJAX-rendered
		// HTML, and a custom property set inline outranks the .__wrap defaults.
		$limited_hex = ( 'full' === $mode ) ? '' : (string) sanitize_hex_color( (string) $atts['limited_color'] );
		$booked_hex  = ( 'full' === $mode ) ? '' : (string) sanitize_hex_color( (string) $atts['booked_color'] );
		$color_attr  = $this->color_style_attr( $limited_hex, $booked_hex );

		// Single month: skip the toolbar - nothing to navigate.
		if ( count( $panels ) === 1 ) {
			$html = '<div class="shootcal-web-calendar__wrap"' . $color_attr . '>' . $panels[0]['html'] . $credit . '</div>';
		} else {
			$html = $this->render_paginated( $panels, $active_idx, $today_idx, $credit, $color_attr );
		}

		set_transient( $render_key, $html, CACHE_TTL );
		return $html;
	}

	/**
	 * Build the per-embed cell-color override as an inline `style` attribute that
	 * sets the Limited/Booked CSS color variables on this calendar's wrap element.
	 * Colors arrive as sanitized hex (or '' for default); the variables are RGB
	 * triplets so the stylesheet can wrap them in rgba() to render 80% opacity at
	 * rest and 100% on hover (the chosen color is the peak).
	 *
	 * When both colors resolve to the built-in defaults we emit nothing - the
	 * stylesheet's own defaults apply and the page stays light. A custom property
	 * set on the element's own style attribute outranks the stylesheet's `.__wrap`
	 * defaults without !important, and - unlike a `<style>` block - it carries with
	 * the cached and AJAX-rendered HTML and needs no enqueue.
	 *
	 * @return string Leading-space ` style="..."` attribute, or '' for defaults.
	 */
	private function color_style_attr( string $limited_hex, string $booked_hex ): string {
		$limited_rgb = $this->hex_to_rgb_triplet( '' !== $limited_hex ? $limited_hex : '#fce3a8' );
		$booked_rgb  = $this->hex_to_rgb_triplet( '' !== $booked_hex  ? $booked_hex  : '#f6b9a3' );

		// Both at the built-in defaults: nothing to emit.
		$defaults = array(
			'252, 227, 168' => true, // #fce3a8
			'246, 185, 163' => true, // #f6b9a3
		);
		if ( isset( $defaults[ $limited_rgb ] ) && isset( $defaults[ $booked_rgb ] ) ) {
			return '';
		}

		return sprintf(
			' style="%s"',
			esc_attr(
				sprintf(
					'--shootcal-limited-bg-rgb:%1$s;--shootcal-booked-bg-rgb:%2$s;',
					$limited_rgb,
					$booked_rgb
				)
			)
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
			return '252, 227, 168'; // soft default (matches Limited bg)
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
	private function render_paginated( array $panels, int $active_idx, int $today_idx, string $credit, string $style_attr = '' ): string {
		$total       = count( $panels );
		$first_label = $panels[0]['label'];
		$last_label  = $panels[ $total - 1 ]['label'];

		$wrap_classes = 'shootcal-web-calendar__wrap shootcal-web-calendar__wrap--paginated';
		$html  = '<div class="' . esc_attr( $wrap_classes ) . '"' . $style_attr . ' data-shootcal-total="' . (int) $total . '" data-shootcal-today="' . (int) $today_idx . '" data-shootcal-active="' . (int) $active_idx . '">';

		// Toolbar - month label on the left, navigation group (< Today >) on the right.
		$html .= '<div class="shootcal-web-calendar__toolbar" role="group" aria-label="' . esc_attr__( 'Calendar navigation', 'shootcal-web-calendar' ) . '">';

		$html .= sprintf(
			'<h2 class="shootcal-web-calendar__nav-label" aria-live="polite" data-shootcal-month-label>%s <span class="shootcal-web-calendar__nav-year">%s</span></h2>',
			esc_html( $panels[ $active_idx ]['label_month'] ),
			esc_html( $panels[ $active_idx ]['label_year'] )
		);

		$html .= '<div class="shootcal-web-calendar__nav">';
		$html .= sprintf(
			'<button type="button" class="shootcal-web-calendar__btn shootcal-web-calendar__btn--prev" data-shootcal-action="prev" aria-label="%s"%s>%s</button>',
			esc_attr__( 'Previous month', 'shootcal-web-calendar' ),
			$active_idx === 0 ? ' disabled' : '',
			'&lsaquo;'
		);
		$today_disabled = ( $today_idx === -1 || $today_idx === $active_idx );
		$html .= sprintf(
			'<button type="button" class="shootcal-web-calendar__btn shootcal-web-calendar__btn--today" data-shootcal-action="today"%s>%s</button>',
			$today_disabled ? ' disabled' : '',
			esc_html__( 'Today', 'shootcal-web-calendar' )
		);
		$html .= sprintf(
			'<button type="button" class="shootcal-web-calendar__btn shootcal-web-calendar__btn--next" data-shootcal-action="next" aria-label="%s"%s>%s</button>',
			esc_attr__( 'Next month', 'shootcal-web-calendar' ),
			$active_idx === $total - 1 ? ' disabled' : '',
			'&rsaquo;'
		);
		$html .= '</div>';

		$html .= '</div>'; // toolbar

		// Viewport - all month panels rendered, only the active one visible.
		$html .= '<div class="shootcal-web-calendar__viewport">';
		foreach ( $panels as $p ) {
			$is_active = ( $p['idx'] === $active_idx );
			$html     .= sprintf(
				'<div class="shootcal-web-calendar__month-panel%s" data-shootcal-month-idx="%d" data-shootcal-month-label="%s" data-shootcal-month-name="%s" data-shootcal-month-year="%s"%s>%s</div>',
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
		$html .= '<p class="shootcal-web-calendar__sr-only">' . sprintf(
			/* translators: 1: earliest visible month, 2: latest visible month. */
			esc_html__( 'Available months: %1$s through %2$s.', 'shootcal-web-calendar' ),
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
	/**
	 * Compact color key beneath the grid. Per-cell aria-labels already convey
	 * status to screen readers, so the swatches are decorative (aria-hidden);
	 * the text labels stay readable. "Limited" is omitted when the photographer
	 * has turned off multi-session days (then there is no Limited state).
	 */
	private function legend_html( bool $multi_session_day ): string {
		$items = array( array( 'available', __( 'Available', 'shootcal-web-calendar' ) ) );
		if ( $multi_session_day ) {
			$items[] = array( 'limited', __( 'Limited', 'shootcal-web-calendar' ) );
		}
		$items[] = array( 'booked', __( 'Booked', 'shootcal-web-calendar' ) );

		$out = '<ul class="shootcal-web-calendar__legend">';
		foreach ( $items as $item ) {
			$out .= '<li class="shootcal-web-calendar__legend-item">'
				. '<span class="shootcal-web-calendar__legend-swatch shootcal-web-calendar__legend-swatch--' . esc_attr( $item[0] ) . '" aria-hidden="true"></span>'
				. esc_html( $item[1] )
				. '</li>';
		}
		return $out . '</ul>';
	}

	private function credit_html( string $source ): string {
		// Only ShootCal feeds carry the credit, and only when the site owner
		// hasn't switched it off (Settings > ShootCal Web Calendar).
		if ( 'shootcal' !== $source || empty( Settings::get_options()['show_credit'] ) ) {
			return '';
		}
		$link = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( 'https://www.ryansmithphotography.com/photography-apps/shootcal/' ),
			esc_html__( 'ShootCal', 'shootcal-web-calendar' )
		);
		return '<p class="shootcal-web-calendar__credit">' . sprintf(
			/* translators: %s: linked plugin name. */
			esc_html__( 'Calendar provided by %s by Ryan Smith Photography', 'shootcal-web-calendar' ),
			$link // already escaped above
		) . '</p>';
	}

	/**
	 * Detect the source type from a calendar URL: a *.shootcal.com URL is a
	 * ShootCal feed (unlocks timezone + months auto-detection and the credit
	 * line); anything else is a generic iCal feed, handled the same way a Google
	 * Calendar feed is. An empty URL falls back to the generic path.
	 */
	private function source_for_url( string $url ): string {
		if ( '' === $url ) {
			return 'google';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return ( is_string( $host ) && preg_match( '/(^|\.)shootcal\.com$/i', $host ) ) ? 'shootcal' : 'google';
	}

	/**
	 * Resolve the display timezone. Returns a DateTimeZone; never throws.
	 * See render() for the priority order.
	 */
	private function resolve_timezone( string $attr_tz, string $source, string $ical ): \DateTimeZone {
		// 1. `timezone` shortcode/block attribute - explicit per-embed override.
		if ( $attr_tz !== '' ) {
			$tz = $this->try_tz( $attr_tz );
			if ( $tz !== null ) {
				return $tz;
			}
		}

		// 2. For ShootCal source, pull X-WR-TIMEZONE from the feed itself.
		// (Not done for Google source - Google calendars embed VTIMEZONE blocks
		// which are more involved and the WordPress site timezone is the right anchor.)
		if ( 'shootcal' === $source ) {
			$feed_tz = $this->extract_feed_timezone( $ical );
			if ( $feed_tz !== '' ) {
				$tz = $this->try_tz( $feed_tz );
				if ( $tz !== null ) {
					return $tz;
				}
			}
		}

		// 3. The WordPress site timezone (Settings > General). The plugin has no
		// timezone setting of its own - it always follows WordPress.
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
			'<div class="shootcal-web-calendar__error" style="border:1px solid #c00; padding:.75em 1em; background:#fff5f5;"><strong>%s</strong> %s</div>',
			esc_html__( 'ShootCal Web Calendar:', 'shootcal-web-calendar' ),
			esc_html( $message )
		);
	}
}
