<?php
/**
 * Settings page (Settings > ShootCal Availability).
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const PAGE_SLUG          = 'shootcal-availability';
	private const SECTION_SOURCE     = 'shootcal_availability_source';
	private const SECTION_DISPLAY    = 'shootcal_availability_display';
	private const NONCE_CLEAR_CACHE  = 'shootcal_availability_clear_cache';
	private const AJAX_TEST_HOOK     = 'shootcal_availability_test_connection';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_shootcal_availability_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_TEST_HOOK, array( $this, 'handle_test_connection' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'ShootCal Availability', 'shootcal-availability' ),
			__( 'ShootCal Availability', 'shootcal-availability' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'shootcal_availability_group',
			OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		// Source section: pick where the calendar data comes from.
		add_settings_section(
			self::SECTION_SOURCE,
			__( 'Calendar source', 'shootcal-availability' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Choose where this plugin reads your availability from. Both options serve the same iCal format and render the same way; only the data source differs.', 'shootcal-availability' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field( 'source', __( 'Source', 'shootcal-availability' ),
			array( $this, 'field_source' ), self::PAGE_SLUG, self::SECTION_SOURCE );
		add_settings_field( 'ical_url', __( 'Google Calendar iCal URL', 'shootcal-availability' ),
			array( $this, 'field_ical_url' ), self::PAGE_SLUG, self::SECTION_SOURCE,
			array( 'class' => 'shootcal-availability__row shootcal-availability__row--google' ) );
		add_settings_field( 'shootcal_feed_url', __( 'ShootCal feed URL', 'shootcal-availability' ),
			array( $this, 'field_shootcal_feed_url' ), self::PAGE_SLUG, self::SECTION_SOURCE,
			array( 'class' => 'shootcal-availability__row shootcal-availability__row--shootcal' ) );

		// Display section: how the grid renders.
		add_settings_section( self::SECTION_DISPLAY, __( 'Display', 'shootcal-availability' ), '__return_false', self::PAGE_SLUG );
		add_settings_field( 'months_ahead', __( 'Months to show', 'shootcal-availability' ),
			array( $this, 'field_months_ahead' ), self::PAGE_SLUG, self::SECTION_DISPLAY,
			array( 'class' => 'shootcal-availability__row shootcal-availability__row--google-only' ) );
		add_settings_field( 'months_ahead_shootcal_note', __( 'Months to show', 'shootcal-availability' ),
			array( $this, 'field_months_shootcal_note' ), self::PAGE_SLUG, self::SECTION_DISPLAY,
			array( 'class' => 'shootcal-availability__row shootcal-availability__row--shootcal-only' ) );
		add_settings_field( 'first_day_of_week', __( 'First day of week', 'shootcal-availability' ),
			array( $this, 'field_first_day_of_week' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'multi_session_day', __( 'Sessions per day', 'shootcal-availability' ),
			array( $this, 'field_multi_session_day' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'limited_color', __( 'Limited day color', 'shootcal-availability' ),
			array( $this, 'field_limited_color' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'booked_color', __( 'Booked day color', 'shootcal-availability' ),
			array( $this, 'field_booked_color' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'timezone', __( 'Display timezone', 'shootcal-availability' ),
			array( $this, 'field_timezone' ), self::PAGE_SLUG, self::SECTION_DISPLAY,
			array( 'class' => 'shootcal-availability__row shootcal-availability__row--google-only' ) );
		add_settings_field( 'timezone_shootcal_note', __( 'Display timezone', 'shootcal-availability' ),
			array( $this, 'field_timezone_shootcal_note' ), self::PAGE_SLUG, self::SECTION_DISPLAY,
			array( 'class' => 'shootcal-availability__row shootcal-availability__row--shootcal-only' ) );
	}

	public static function get_options(): array {
		$defaults = array(
			'source'            => 'google',
			'ical_url'          => '',
			'shootcal_feed_url' => '',
			'months_ahead'      => 3,
			'first_day_of_week' => 0,
			'multi_session_day' => true,
			'limited_color'     => '#fdf2dd',
			'booked_color'      => '#fae0cf',
			'timezone'          => wp_timezone_string(),
		);
		$options  = get_option( OPTION_KEY, array() );
		return wp_parse_args( is_array( $options ) ? $options : array(), $defaults );
	}

	/**
	 * Returns the URL the fetcher should use, based on the chosen source.
	 */
	public static function get_active_url(): string {
		$opts = self::get_options();
		return 'shootcal' === $opts['source']
			? (string) $opts['shootcal_feed_url']
			: (string) $opts['ical_url'];
	}

	public function sanitize( $input ): array {
		$existing = get_option( OPTION_KEY );
		$existing = is_array( $existing ) ? $existing : array();
		$out      = self::get_options();

		$out['source'] = ( isset( $input['source'] ) && 'shootcal' === $input['source'] ) ? 'shootcal' : 'google';

		$out['ical_url']          = isset( $input['ical_url'] ) ? esc_url_raw( trim( (string) $input['ical_url'] ) ) : '';
		$out['shootcal_feed_url'] = isset( $input['shootcal_feed_url'] ) ? esc_url_raw( trim( (string) $input['shootcal_feed_url'] ) ) : '';

		// Soft validation: warn (via settings_errors) when a URL doesn't look right.
		if ( 'shootcal' === $out['source'] && '' !== $out['shootcal_feed_url'] ) {
			$host = wp_parse_url( $out['shootcal_feed_url'], PHP_URL_HOST );
			if ( ! is_string( $host ) || ! preg_match( '/(^|\.)shootcal\.com$/i', $host ) ) {
				add_settings_error( OPTION_KEY, 'shootcal_url_host', __( 'The ShootCal feed URL should be on the shootcal.com domain. Double-check the URL you copied from the app.', 'shootcal-availability' ), 'warning' );
			}
		}
		if ( 'google' === $out['source'] && '' !== $out['ical_url'] ) {
			$host = wp_parse_url( $out['ical_url'], PHP_URL_HOST );
			if ( is_string( $host ) && preg_match( '/(^|\.)shootcal\.com$/i', $host ) ) {
				add_settings_error( OPTION_KEY, 'google_url_host', __( 'You pasted a shootcal.com URL but selected Google as the source. Switch source to ShootCal, or paste a calendar.google.com iCal URL.', 'shootcal-availability' ), 'warning' );
			}
		}

		$out['months_ahead']      = isset( $input['months_ahead'] ) ? max( 1, min( 36, (int) $input['months_ahead'] ) ) : 3;
		$out['first_day_of_week'] = isset( $input['first_day_of_week'] ) ? ( 1 === (int) $input['first_day_of_week'] ? 1 : 0 ) : 0;
		$out['multi_session_day'] = ! empty( $input['multi_session_day'] );

		// Color pickers - sanitize_hex_color returns null on bad input, fall back to default.
		$out['limited_color'] = sanitize_hex_color( isset( $input['limited_color'] ) ? (string) $input['limited_color'] : '' ) ?: '#fdf2dd';
		$out['booked_color']  = sanitize_hex_color( isset( $input['booked_color'] )  ? (string) $input['booked_color']  : '' ) ?: '#fae0cf';
		$out['timezone']          = isset( $input['timezone'] ) ? sanitize_text_field( (string) $input['timezone'] ) : wp_timezone_string();

		// If the active URL changed (or the source changed), drop the cache so the user sees fresh data.
		$old_active = self::active_url_from( $existing );
		$new_active = self::active_url_from( $out );
		if ( $old_active !== $new_active ) {
			delete_transient( CACHE_KEY );
		}

		return $out;
	}

	private static function active_url_from( array $opts ): string {
		$source = isset( $opts['source'] ) && 'shootcal' === $opts['source'] ? 'shootcal' : 'google';
		return 'shootcal' === $source ? (string) ( $opts['shootcal_feed_url'] ?? '' ) : (string) ( $opts['ical_url'] ?? '' );
	}

	// --- Field renderers -------------------------------------------------

	public function field_source(): void {
		$opts = self::get_options();
		$src  = (string) $opts['source'];
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( OPTION_KEY ); ?>[source]" value="google" <?php checked( $src, 'google' ); ?> data-shootcal-source="google" />
				<strong><?php esc_html_e( 'Google Calendar', 'shootcal-availability' ); ?></strong>
				<span class="description"><?php esc_html_e( '— paste a secret iCal URL from Google Calendar.', 'shootcal-availability' ); ?></span>
			</label><br />
			<label>
				<input type="radio" name="<?php echo esc_attr( OPTION_KEY ); ?>[source]" value="shootcal" <?php checked( $src, 'shootcal' ); ?> data-shootcal-source="shootcal" />
				<strong><?php esc_html_e( 'ShootCal', 'shootcal-availability' ); ?></strong>
				<span class="description"><?php esc_html_e( '— paste a feed URL from the ShootCal Mac app (Settings > Connect to your website).', 'shootcal-availability' ); ?></span>
			</label>
		</fieldset>
		<?php
	}

	public function field_ical_url(): void {
		$opts = self::get_options();
		printf(
			'<input type="url" name="%1$s[ical_url]" id="ical_url" value="%2$s" class="regular-text code" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics" autocomplete="off" />',
			esc_attr( OPTION_KEY ),
			esc_attr( $opts['ical_url'] )
		);
		echo '<p class="description">' . esc_html__( 'Google Calendar > Settings of your calendar > Integrate calendar > Secret address in iCal format. Treat this URL like a password.', 'shootcal-availability' ) . '</p>';
		$this->render_test_button( 'google' );
	}

	public function field_shootcal_feed_url(): void {
		$opts = self::get_options();
		printf(
			'<input type="url" name="%1$s[shootcal_feed_url]" id="shootcal_feed_url" value="%2$s" class="regular-text code" placeholder="https://feed.shootcal.com/&lt;token&gt;.ics" autocomplete="off" />',
			esc_attr( OPTION_KEY ),
			esc_attr( $opts['shootcal_feed_url'] )
		);
		echo '<p class="description">' . esc_html__( 'Open the ShootCal Mac app, go to Settings > Website > Connect to your website, click Copy URL, then paste here.', 'shootcal-availability' ) . '</p>';
		$this->render_test_button( 'shootcal' );
	}

	private function render_test_button( string $source ): void {
		?>
		<p>
			<button type="button" class="button" data-shootcal-test="<?php echo esc_attr( $source ); ?>">
				<?php esc_html_e( 'Test connection', 'shootcal-availability' ); ?>
			</button>
			<span class="spinner" style="float:none; margin-left:6px;"></span>
			<span class="shootcal-availability__test-result" aria-live="polite"></span>
		</p>
		<?php
	}

	public function field_months_ahead(): void {
		$opts = self::get_options();
		printf(
			'<input type="number" name="%1$s[months_ahead]" id="months_ahead" value="%2$d" min="1" max="36" class="small-text" />',
			esc_attr( OPTION_KEY ),
			(int) $opts['months_ahead']
		);
		echo ' <span class="description">' . esc_html__( 'Including the current month. Up to 36 (3 years) for wedding photographers and others booking far out. Year tabs appear automatically when the range spans more than one calendar year.', 'shootcal-availability' ) . '</span>';
	}

	public function field_months_shootcal_note(): void {
		echo '<p class="description" style="margin:0;">' . esc_html__( 'Auto-detected from the ShootCal feed. To change how far ahead is shown, open the ShootCal Mac app and adjust the push horizon under Settings > Website. Year tabs appear automatically when the range spans more than one calendar year.', 'shootcal-availability' ) . '</p>';
	}

	public function field_timezone_shootcal_note(): void {
		echo '<p class="description" style="margin:0;">' . esc_html__( 'Auto-detected from the ShootCal feed (the feed includes the timezone you use in the Mac app). Falls back to your WordPress general timezone setting if the feed does not specify one.', 'shootcal-availability' ) . '</p>';
	}

	public function field_first_day_of_week(): void {
		$opts = self::get_options();
		$val  = (int) $opts['first_day_of_week'];
		printf(
			'<select name="%1$s[first_day_of_week]" id="first_day_of_week">
				<option value="0"%2$s>%3$s</option>
				<option value="1"%4$s>%5$s</option>
			</select>',
			esc_attr( OPTION_KEY ),
			selected( $val, 0, false ),
			esc_html__( 'Sunday', 'shootcal-availability' ),
			selected( $val, 1, false ),
			esc_html__( 'Monday', 'shootcal-availability' )
		);
	}

	public function field_multi_session_day(): void {
		$opts = self::get_options();
		printf(
			'<label><input type="checkbox" name="%1$s[multi_session_day]" id="multi_session_day" value="1"%2$s /> %3$s</label>',
			esc_attr( OPTION_KEY ),
			checked( ! empty( $opts['multi_session_day'] ), true, false ),
			esc_html__( 'I can fit more than one client per day.', 'shootcal-availability' )
		);
		echo '<p class="description">';
		esc_html_e( 'When checked, days with only timed events (e.g. a sunset session) show as "Limited" - amber - signaling that another client could still book a different time. When unchecked, any event marks the whole day as "Booked" - red - same as an all-day commitment.', 'shootcal-availability' );
		echo '</p>';
	}

	public function field_limited_color(): void {
		$opts = self::get_options();
		printf(
			'<input type="color" name="%1$s[limited_color]" id="limited_color" value="%2$s" />',
			esc_attr( OPTION_KEY ),
			esc_attr( $opts['limited_color'] )
		);
		echo '<p class="description">' . esc_html__( 'Cells render at 80% of this color by default and animate to 100% on hover. Pick the vivid version - the calendar automatically tones it down at rest.', 'shootcal-availability' ) . '</p>';
	}

	public function field_booked_color(): void {
		$opts = self::get_options();
		printf(
			'<input type="color" name="%1$s[booked_color]" id="booked_color" value="%2$s" />',
			esc_attr( OPTION_KEY ),
			esc_attr( $opts['booked_color'] )
		);
		echo '<p class="description">' . esc_html__( 'Cells render at 80% of this color by default and animate to 100% on hover. Same 80/100 pattern as Limited.', 'shootcal-availability' ) . '</p>';
	}

	public function field_timezone(): void {
		$opts = self::get_options();
		printf(
			'<input type="text" name="%1$s[timezone]" id="timezone" value="%2$s" class="regular-text code" />',
			esc_attr( OPTION_KEY ),
			esc_attr( $opts['timezone'] )
		);
		echo '<p class="description">';
		printf(
			/* translators: %s: example IANA timezone identifier. */
			esc_html__( 'IANA timezone identifier, e.g. %s. Defaults to your WordPress timezone.', 'shootcal-availability' ),
			'<code>America/New_York</code>'
		);
		echo '</p>';
	}

	// --- Page chrome -----------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap shootcal-availability__settings">
			<h1><?php echo esc_html__( 'ShootCal Availability', 'shootcal-availability' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'shootcal_availability_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Shortcode', 'shootcal-availability' ); ?></h2>
			<p><?php esc_html_e( 'Embed the availability grid on any page or post:', 'shootcal-availability' ); ?></p>
			<p><code>[shootcal_availability]</code></p>

			<h2><?php esc_html_e( 'Cache', 'shootcal-availability' ); ?></h2>
			<p><?php esc_html_e( 'The calendar feed is fetched and cached for 10 minutes. Clear the cache to force an immediate refresh.', 'shootcal-availability' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shootcal_availability_clear_cache" />
				<?php wp_nonce_field( self::NONCE_CLEAR_CACHE ); ?>
				<?php submit_button( __( 'Clear cache now', 'shootcal-availability' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'shootcal-availability' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_CLEAR_CACHE );
		delete_transient( CACHE_KEY );
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'cache_cleared' => '1' ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	// --- Admin assets + AJAX test handler --------------------------------

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'shootcal-availability-admin', PLUGIN_URL . 'assets/css/admin.css', array(), VERSION );
		wp_enqueue_script( 'shootcal-availability-admin', PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), VERSION, true );
		wp_localize_script(
			'shootcal-availability-admin',
			'ShootCalAvailability',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_TEST_HOOK,
				'nonce'   => wp_create_nonce( self::AJAX_TEST_HOOK ),
				'i18n'    => array(
					'testing'        => __( 'Testing…', 'shootcal-availability' ),
					'enterUrl'       => __( 'Please enter a URL above first.', 'shootcal-availability' ),
					'networkError'   => __( 'Network error while testing.', 'shootcal-availability' ),
				),
			)
		);
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shootcal-availability' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_TEST_HOOK, 'nonce' );

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'No URL provided.', 'shootcal-availability' ) ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'ShootCal Availability/' . VERSION . '; ' . home_url(),
				'headers'    => array( 'Accept' => 'text/calendar, text/plain;q=0.9, */*;q=0.5' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			wp_send_json_error( array(
				/* translators: %d: HTTP status code from the calendar server. */
				'message' => sprintf( __( 'HTTP %d from the calendar server.', 'shootcal-availability' ), $code ),
			) );
		}
		if ( stripos( $body, 'BEGIN:VCALENDAR' ) === false ) {
			wp_send_json_error( array( 'message' => __( 'Response did not look like an iCalendar feed.', 'shootcal-availability' ) ) );
		}

		// Count events for a friendly success message.
		$events = preg_match_all( '/^BEGIN:VEVENT/mi', $body );

		// If event count is suspiciously low for the body size, include diagnostic info
		// so we can see what the server actually fetched. Useful when CF caches differ
		// between data centers, or when intermediate proxies strip parts of the body.
		$debug = array();
		if ( 0 === (int) $events ) {
			$headers = wp_remote_retrieve_headers( $response );
			$debug   = array(
				'body_preview'    => mb_substr( $body, 0, 400 ),
				'body_bytes'      => strlen( $body ),
				'content_type'    => isset( $headers['content-type'] ) ? (string) $headers['content-type'] : null,
				'content_length'  => isset( $headers['content-length'] ) ? (string) $headers['content-length'] : null,
				'last_modified'   => isset( $headers['last-modified'] ) ? (string) $headers['last-modified'] : null,
				'cf_cache_status' => isset( $headers['cf-cache-status'] ) ? (string) $headers['cf-cache-status'] : null,
				'cf_ray'          => isset( $headers['cf-ray'] ) ? (string) $headers['cf-ray'] : null,
				'server'          => isset( $headers['server'] ) ? (string) $headers['server'] : null,
			);
		}

		wp_send_json_success( array_merge(
			array(
				'message' => sprintf(
					/* translators: 1: number of events, 2: response body size in bytes. */
					_n( 'Connection OK: %1$d event found (%2$s bytes).', 'Connection OK: %1$d events found (%2$s bytes).', (int) $events, 'shootcal-availability' ),
					(int) $events,
					number_format_i18n( strlen( $body ) )
				),
			),
			$debug
		) );
	}
}
