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

		// Calendar source: a single iCal URL. The source (ShootCal vs generic
		// iCal) is detected automatically from the URL, so there's no toggle.
		add_settings_section(
			self::SECTION_SOURCE,
			__( 'Calendar source', 'shootcal-availability' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Paste the iCal URL of the calendar you want to show. It can be a Google Calendar secret iCal address, an Apple or Outlook iCal feed, or a ShootCal feed URL. The plugin detects the type automatically. A ShootCal feed adds extra control: it hides your personal events, builds availability from your session types, and auto-detects your timezone and how many months to show.', 'shootcal-availability' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field( 'calendar_url', __( 'Calendar URL', 'shootcal-availability' ),
			array( $this, 'field_calendar_url' ), self::PAGE_SLUG, self::SECTION_SOURCE );

		// Display section: how the grid renders.
		add_settings_section( self::SECTION_DISPLAY, __( 'Display', 'shootcal-availability' ), '__return_false', self::PAGE_SLUG );
		add_settings_field( 'months_ahead', __( 'Months to show', 'shootcal-availability' ),
			array( $this, 'field_months_ahead' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'first_day_of_week', __( 'First day of week', 'shootcal-availability' ),
			array( $this, 'field_first_day_of_week' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'multi_session_day', __( 'Sessions per day', 'shootcal-availability' ),
			array( $this, 'field_multi_session_day' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'limited_color', __( 'Limited day color', 'shootcal-availability' ),
			array( $this, 'field_limited_color' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'booked_color', __( 'Booked day color', 'shootcal-availability' ),
			array( $this, 'field_booked_color' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'timezone', __( 'Display timezone', 'shootcal-availability' ),
			array( $this, 'field_timezone' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'ajax_render', __( 'Page caching', 'shootcal-availability' ),
			array( $this, 'field_ajax_render' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
	}

	public static function get_options(): array {
		$defaults = array(
			'calendar_url'      => '',
			'months_ahead'      => 3,
			'first_day_of_week' => 0,
			'multi_session_day' => true,
			'limited_color'     => '#fce3a8',
			'booked_color'      => '#f6b9a3',
			'timezone'          => wp_timezone_string(),
			'ajax_render'       => false,
		);

		$options = get_option( OPTION_KEY, array() );
		$options = is_array( $options ) ? $options : array();

		// Migrate the pre-1.1 split fields (source + ical_url + shootcal_feed_url)
		// into the single calendar_url. Read-time fallback so existing installs
		// keep working immediately; the old keys are dropped on the next save.
		if ( ! isset( $options['calendar_url'] ) ) {
			$legacy_is_shootcal      = isset( $options['source'] ) && 'shootcal' === $options['source'];
			$options['calendar_url'] = $legacy_is_shootcal
				? (string) ( $options['shootcal_feed_url'] ?? '' )
				: (string) ( $options['ical_url'] ?? '' );
		}

		// Installs that never changed the cell colors have the pre-1.1.9 defaults
		// saved; bump them to the new, more-distinct defaults so they aren't stuck
		// on the old near-identical tints. Genuinely custom colors are left alone.
		if ( isset( $options['limited_color'] ) && '#fdf2dd' === strtolower( (string) $options['limited_color'] ) ) {
			$options['limited_color'] = '#fce3a8';
		}
		if ( isset( $options['booked_color'] ) && '#fae0cf' === strtolower( (string) $options['booked_color'] ) ) {
			$options['booked_color'] = '#f6b9a3';
		}

		return wp_parse_args( $options, $defaults );
	}

	/**
	 * The single configured calendar URL the fetcher should use.
	 */
	public static function get_active_url(): string {
		return (string) self::get_options()['calendar_url'];
	}

	public function sanitize( $input ): array {
		$existing = self::get_options();
		$out      = self::get_options();

		// Drop the legacy split-source keys; we now store a single URL.
		unset( $out['source'], $out['ical_url'], $out['shootcal_feed_url'] );

		$out['calendar_url'] = isset( $input['calendar_url'] ) ? esc_url_raw( trim( (string) $input['calendar_url'] ) ) : '';

		$out['months_ahead']      = isset( $input['months_ahead'] ) ? max( 1, min( 36, (int) $input['months_ahead'] ) ) : 3;
		$out['first_day_of_week'] = isset( $input['first_day_of_week'] ) ? ( 1 === (int) $input['first_day_of_week'] ? 1 : 0 ) : 0;
		$out['multi_session_day'] = ! empty( $input['multi_session_day'] );
		$out['ajax_render']       = ! empty( $input['ajax_render'] );

		// Color pickers - sanitize_hex_color returns null on bad input, fall back to default.
		$out['limited_color'] = sanitize_hex_color( isset( $input['limited_color'] ) ? (string) $input['limited_color'] : '' ) ?: '#fce3a8';
		$out['booked_color']  = sanitize_hex_color( isset( $input['booked_color'] )  ? (string) $input['booked_color']  : '' ) ?: '#f6b9a3';
		$out['timezone']      = isset( $input['timezone'] ) ? sanitize_text_field( (string) $input['timezone'] ) : wp_timezone_string();

		// If the URL changed, drop the cache so the user sees fresh data.
		if ( (string) $existing['calendar_url'] !== $out['calendar_url'] ) {
			Fetcher::flush_cache();
		}

		return $out;
	}

	// --- Field renderers -------------------------------------------------

	public function field_calendar_url(): void {
		$opts = self::get_options();
		printf(
			'<input type="url" name="%1$s[calendar_url]" id="calendar_url" value="%2$s" class="regular-text code" placeholder="https://" autocomplete="off" />',
			esc_attr( OPTION_KEY ),
			esc_attr( $opts['calendar_url'] )
		);
		echo '<p class="description">' . esc_html__( 'Google Calendar: open your calendar\'s settings, scroll to Integrate calendar, and copy the Secret address in iCal format. ShootCal: open the Mac app, go to Settings > Website > Connect to your website, and click Copy URL. Treat this URL like a password.', 'shootcal-availability' ) . '</p>';
		$this->render_test_button();
	}

	private function render_test_button(): void {
		?>
		<p>
			<button type="button" class="button" id="shootcal-test-connection">
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
		echo ' <span class="description">' . esc_html__( 'Including the current month, up to 36 (3 years). Year tabs appear automatically when the range spans more than one calendar year. ShootCal feeds auto-detect this from the feed, so this setting is ignored for them.', 'shootcal-availability' ) . '</span>';
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
			esc_html__( 'IANA timezone identifier, e.g. %s. Defaults to your WordPress timezone. ShootCal feeds auto-detect the timezone from the feed.', 'shootcal-availability' ),
			'<code>America/New_York</code>'
		);
		echo '</p>';
	}

	public function field_ajax_render(): void {
		$opts = self::get_options();
		printf(
			'<label><input type="checkbox" name="%1$s[ajax_render]" id="ajax_render" value="1"%2$s /> %3$s</label>',
			esc_attr( OPTION_KEY ),
			checked( ! empty( $opts['ajax_render'] ), true, false ),
			esc_html__( 'Load the calendar with JavaScript after the page loads.', 'shootcal-availability' )
		);
		echo '<p class="description">';
		esc_html_e( 'Turn this on if your site uses full-page caching (e.g. Varnish or a page-cache plugin). The page itself stays cached, but the calendar is fetched fresh on each visit, so availability never gets stuck behind a long page cache. Leave off otherwise.', 'shootcal-availability' );
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
		Fetcher::flush_cache();
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
					'testing'      => __( 'Testing…', 'shootcal-availability' ),
					'enterUrl'     => __( 'Please enter a URL above first.', 'shootcal-availability' ),
					'networkError' => __( 'Network error while testing.', 'shootcal-availability' ),
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

		// Match the front-end fetch: wp_safe_remote_get so the test reflects what
		// will actually happen at render time (and can't be used to probe internal
		// hosts).
		$response = wp_safe_remote_get(
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
