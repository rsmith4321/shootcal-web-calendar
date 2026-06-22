<?php
/**
 * Settings page (Settings > ShootCal Web Calendar).
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const PAGE_SLUG          = 'shootcal-web-calendar';
	private const SECTION_DISPLAY    = 'shootcal_web_calendar_display';
	private const NONCE_CLEAR_CACHE  = 'shootcal_web_calendar_clear_cache';
	private const AJAX_TEST_HOOK     = 'shootcal_web_calendar_test_connection';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_shootcal_web_calendar_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_TEST_HOOK, array( $this, 'handle_test_connection' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'ShootCal Web Calendar', 'shootcal-web-calendar' ),
			__( 'ShootCal Web Calendar', 'shootcal-web-calendar' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'shootcal_web_calendar_group',
			OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		// Display section: how the grid renders. (Feed URLs live on each
		// shortcode/block now - build one with the generator on this page.)
		add_settings_section( self::SECTION_DISPLAY, __( 'Display', 'shootcal-web-calendar' ), '__return_false', self::PAGE_SLUG );
		add_settings_field( 'months_ahead', __( 'Months to show', 'shootcal-web-calendar' ),
			array( $this, 'field_months_ahead' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'first_day_of_week', __( 'First day of week', 'shootcal-web-calendar' ),
			array( $this, 'field_first_day_of_week' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		// Cell colors are per-embed now (picked in the shortcode generator / block,
		// availability mode only) - no site-wide color setting here.
		add_settings_field( 'ajax_render', __( 'Page caching', 'shootcal-web-calendar' ),
			array( $this, 'field_ajax_render' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
		add_settings_field( 'show_credit', __( 'ShootCal credit', 'shootcal-web-calendar' ),
			array( $this, 'field_show_credit' ), self::PAGE_SLUG, self::SECTION_DISPLAY );
	}

	public static function get_options(): array {
		$defaults = array(
			'months_ahead'      => 12,
			'first_day_of_week' => 0,
			'ajax_render'       => false,
			'show_credit'       => true,
		);

		$options = get_option( OPTION_KEY, array() );
		$options = is_array( $options ) ? $options : array();

		return wp_parse_args( $options, $defaults );
	}

	public function sanitize( $input ): array {
		$out = self::get_options();

		// Drop legacy keys - feed URLs and cell colors now live on each
		// shortcode/block, not in Settings.
		unset( $out['source'], $out['ical_url'], $out['shootcal_feed_url'], $out['calendar_url'], $out['limited_color'], $out['booked_color'] );

		$out['months_ahead']      = isset( $input['months_ahead'] ) ? max( 1, min( 36, (int) $input['months_ahead'] ) ) : 12;
		$out['first_day_of_week'] = isset( $input['first_day_of_week'] ) ? ( 1 === (int) $input['first_day_of_week'] ? 1 : 0 ) : 0;
		$out['ajax_render']       = ! empty( $input['ajax_render'] );
		$out['show_credit']       = ! empty( $input['show_credit'] );
		// The calendar's display timezone follows WordPress (Settings > General);
		// there is no plugin timezone setting. A per-embed timezone="..." attribute
		// can still override it on a specific shortcode/block. Cell colors are also
		// per-embed (shortcode generator / block, availability mode only).

		return $out;
	}

	// --- Field renderers -------------------------------------------------

	public function field_months_ahead(): void {
		$opts = self::get_options();
		printf(
			'<input type="number" name="%1$s[months_ahead]" id="months_ahead" value="%2$d" min="1" max="36" class="small-text" />',
			esc_attr( OPTION_KEY ),
			(int) $opts['months_ahead']
		);
		echo ' <span class="description">' . esc_html__( 'Including the current month, up to 36 (3 years). Year tabs appear automatically when the range spans more than one calendar year. ShootCal feeds auto-detect this from the feed, so this setting is ignored for them.', 'shootcal-web-calendar' ) . '</span>';
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
			esc_html__( 'Sunday', 'shootcal-web-calendar' ),
			selected( $val, 1, false ),
			esc_html__( 'Monday', 'shootcal-web-calendar' )
		);
	}

	public function field_ajax_render(): void {
		$opts = self::get_options();
		printf(
			'<label><input type="checkbox" name="%1$s[ajax_render]" id="ajax_render" value="1"%2$s /> %3$s</label>',
			esc_attr( OPTION_KEY ),
			checked( ! empty( $opts['ajax_render'] ), true, false ),
			esc_html__( 'Load the calendar with JavaScript after the page loads.', 'shootcal-web-calendar' )
		);
		echo '<p class="description">';
		esc_html_e( 'Turn this on if your site uses full-page caching (e.g. Varnish or a page-cache plugin). The page itself stays cached, but the calendar is fetched fresh on each visit, so availability never gets stuck behind a long page cache. Leave off otherwise.', 'shootcal-web-calendar' );
		echo '</p>';

		// Used-CSS optimizers (Perfmatters, WP Rocket, etc.) scan the page
		// HTML to decide which CSS to keep. In this mode the calendar is
		// injected by JavaScript AFTER the page loads, so its styles aren't
		// in the scanned HTML and can get stripped, leaving the calendar
		// unstyled. Surface the fix right where the mode is enabled.
		if ( ! empty( $opts['ajax_render'] ) ) {
			echo '<div class="notice notice-warning inline" style="margin:10px 0 0;"><p style="margin:.5em 0;">';
			echo '<strong>' . esc_html__( 'Using a "Remove Unused CSS" optimizer?', 'shootcal-web-calendar' ) . '</strong><br />';
			printf(
				/* translators: %s: the plugin directory path to add to the CSS exclusion list. */
				esc_html__( 'If you use Perfmatters, WP Rocket, or another tool that removes unused CSS, add %s to its stylesheet exclusion list. In this mode the calendar loads via JavaScript, so the optimizer doesn\'t see its styles in the page HTML and may strip them, leaving the calendar unstyled.', 'shootcal-web-calendar' ),
				'<code>/shootcal-web-calendar/</code>'
			);
			echo '<br />';
			echo esc_html__( 'Perfmatters: Options > Assets > Used CSS > Excluded Stylesheets. WP Rocket: File Optimization > Reduce Unused CSS > CSS Safelist. After adding it, clear/regenerate the used CSS and your page cache.', 'shootcal-web-calendar' );
			echo '</p></div>';
		}
	}

	public function field_show_credit(): void {
		$opts = self::get_options();
		printf(
			'<label><input type="checkbox" name="%1$s[show_credit]" id="show_credit" value="1"%2$s /> %3$s</label>',
			esc_attr( OPTION_KEY ),
			checked( ! empty( $opts['show_credit'] ), true, false ),
			esc_html__( 'Show a small "Calendar provided by ShootCal" line below the calendar.', 'shootcal-web-calendar' )
		);
		echo '<p class="description">';
		esc_html_e( 'Only appears on calendars using a ShootCal feed. Turn it off to hide the credit. Keeping it on is appreciated but never required.', 'shootcal-web-calendar' );
		echo '</p>';
	}

	// --- Page chrome -----------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap shootcal-web-calendar__settings">
			<h1><?php echo esc_html__( 'ShootCal Web Calendar', 'shootcal-web-calendar' ); ?></h1>

			<div class="shootcal-web-calendar__intro" style="max-width:48em;">
				<p><strong><?php esc_html_e( 'Using the ShootCal app? Paste your embed - that is the main way.', 'shootcal-web-calendar' ); ?></strong> <?php esc_html_e( 'ShootCal gives you a ready-made embed (an <iframe> snippet). Paste it and the plugin just displays your live availability calendar - the same one shown on shootcal.com, including client self-booking. It stays current on its own and never gets stuck behind a page cache, so there is nothing else to configure.', 'shootcal-web-calendar' ); ?></p>
				<p><strong><?php esc_html_e( 'Two ways to drop it in:', 'shootcal-web-calendar' ); ?></strong></p>
				<ol>
					<li><?php
						/* translators: %s: block name. */
						printf( esc_html__( 'Block editor (easiest): add the %s block, then paste your ShootCal embed (the snippet or its URL) in the block sidebar. No shortcode needed.', 'shootcal-web-calendar' ), '<strong>ShootCal Web Calendar</strong>' );
					?></li>
					<li><?php esc_html_e( 'Shortcode: use the generator below to turn your embed into a shortcode, then paste it into a Shortcode block. Same result as the block.', 'shootcal-web-calendar' ); ?></li>
				</ol>
				<p><?php
					/* translators: %s: the path within the ShootCal app's settings. */
					printf( esc_html__( 'To get your embed: open the ShootCal app, go to %s, and copy the embed snippet (or the URL). Treat it like a password.', 'shootcal-web-calendar' ), '<em>Settings &rsaquo; Website &rsaquo; Connect to your website</em>' );
				?></p>
				<p><a href="<?php echo esc_url( 'https://www.ryansmithphotography.com/photography-apps/shootcal/' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more about the ShootCal app', 'shootcal-web-calendar' ); ?></a></p>

				<hr />
				<p><strong><?php esc_html_e( 'Other calendars (Google, Apple, Outlook).', 'shootcal-web-calendar' ); ?></strong> <?php esc_html_e( 'Any other iCal (.ics) feed still works: paste its URL instead of a ShootCal embed and the plugin renders the calendar itself, on your page. The Display settings, the per-embed colors, and the Page caching option below apply to these self-rendered calendars (a ShootCal embed ignores them - it carries its own).', 'shootcal-web-calendar' ); ?></p>
				<p><strong><?php esc_html_e( 'Timezone:', 'shootcal-web-calendar' ); ?></strong> <?php esc_html_e( 'self-rendered calendars display in your site timezone (Settings > General > Timezone - pick a city such as New York, not a manual UTC offset). A ShootCal embed carries its own timezone.', 'shootcal-web-calendar' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'shootcal_web_calendar_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Shortcode generator', 'shootcal-web-calendar' ); ?></h2>
			<p><?php esc_html_e( 'If you use the WordPress block (above), no shortcode is needed - skip this. To use a shortcode instead: paste your ShootCal embed (or another iCal feed URL), choose how it should display, and copy the generated shortcode into a Shortcode block. For a ShootCal embed the display options below come from ShootCal, so you can leave them as-is.', 'shootcal-web-calendar' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="shootcal-gen-url"><?php esc_html_e( 'ShootCal embed or feed URL', 'shootcal-web-calendar' ); ?></label></th>
					<td>
						<input type="text" id="shootcal-gen-url" class="regular-text code" placeholder="<?php esc_attr_e( 'Paste your ShootCal embed (snippet or URL), or an iCal feed URL', 'shootcal-web-calendar' ); ?>" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Treat this like a password. ShootCal app: Settings > Website > copy the embed (snippet or URL). Google Calendar: Settings > Integrate calendar > Secret address in iCal format.', 'shootcal-web-calendar' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="shootcal-gen-mode"><?php esc_html_e( 'Display mode', 'shootcal-web-calendar' ); ?></label></th>
					<td>
						<select id="shootcal-gen-mode">
							<option value="availability"><?php esc_html_e( 'Availability (free/busy shading)', 'shootcal-web-calendar' ); ?></option>
							<option value="full"><?php esc_html_e( 'Full calendar (show event titles + times)', 'shootcal-web-calendar' ); ?></option>
						</select>
					</td>
				</tr>
				<tr class="shootcal-gen-availability-row">
					<th scope="row"><?php esc_html_e( 'Bookings per day', 'shootcal-web-calendar' ); ?></th>
					<td>
						<label><input type="checkbox" id="shootcal-gen-msd" checked /> <?php esc_html_e( 'I can take more than one booking per day', 'shootcal-web-calendar' ); ?></label>
						<p class="description"><?php esc_html_e( 'Availability mode only. This decides how a day with one or more timed sessions (and no all-day event) looks to visitors. Checked: that day shows as "Limited" - partly booked, but you still have open time, so visitors know they can ask. Unchecked: the first booking marks the whole day "Booked", like an all-day commitment.', 'shootcal-web-calendar' ); ?></p>
					</td>
				</tr>
				<tr class="shootcal-gen-availability-row">
					<th scope="row"><label for="shootcal-gen-limited-color"><?php esc_html_e( 'Limited day color', 'shootcal-web-calendar' ); ?></label></th>
					<td>
						<input type="color" id="shootcal-gen-limited-color" value="#fce3a8" />
						<p class="description"><?php esc_html_e( 'Availability mode only. Shading for a "Limited" (partly booked) day. Cells render at 80% of this color and deepen to 100% on hover. Leave at the default to use the built-in soft gold.', 'shootcal-web-calendar' ); ?></p>
					</td>
				</tr>
				<tr class="shootcal-gen-availability-row">
					<th scope="row"><label for="shootcal-gen-booked-color"><?php esc_html_e( 'Booked day color', 'shootcal-web-calendar' ); ?></label></th>
					<td>
						<input type="color" id="shootcal-gen-booked-color" value="#f6b9a3" />
						<p class="description"><?php esc_html_e( 'Availability mode only. Shading for a fully "Booked" day, shown at reduced opacity at rest and full strength on hover. Leave at the default to use the built-in soft coral.', 'shootcal-web-calendar' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="shootcal-gen-months"><?php esc_html_e( 'Months to show', 'shootcal-web-calendar' ); ?></label></th>
					<td>
						<input type="number" id="shootcal-gen-months" class="small-text" min="1" max="36" placeholder="12" />
						<span class="description"><?php esc_html_e( 'Optional. Leave blank for the default (ShootCal feeds auto-detect).', 'shootcal-web-calendar' ); ?></span>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="shootcal-generate"><?php esc_html_e( 'Check feed & generate', 'shootcal-web-calendar' ); ?></button>
				<span class="spinner" style="float:none; margin-left:6px;"></span>
				<span class="shootcal-web-calendar__gen-result" aria-live="polite"></span>
			</p>
			<div id="shootcal-gen-output" style="display:none;">
				<p><label for="shootcal-gen-shortcode"><strong><?php esc_html_e( 'Your shortcode (copy and paste it into a page or post):', 'shootcal-web-calendar' ); ?></strong></label></p>
				<p>
					<input type="text" id="shootcal-gen-shortcode" class="large-text code" readonly onfocus="this.select();" />
					<button type="button" class="button" id="shootcal-gen-copy"><?php esc_html_e( 'Copy', 'shootcal-web-calendar' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Tip: the ShootCal Web Calendar block does the same thing without a shortcode - just paste the URL in its sidebar.', 'shootcal-web-calendar' ); ?></p>
			</div>

			<h2><?php esc_html_e( 'Cache', 'shootcal-web-calendar' ); ?></h2>
			<p><?php esc_html_e( 'The calendar feed is fetched and cached for 10 minutes. Clear the cache to force an immediate refresh.', 'shootcal-web-calendar' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="shootcal_web_calendar_clear_cache" />
				<?php wp_nonce_field( self::NONCE_CLEAR_CACHE ); ?>
				<?php submit_button( __( 'Clear cache now', 'shootcal-web-calendar' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'shootcal-web-calendar' ), '', array( 'response' => 403 ) );
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
		wp_enqueue_style( 'shootcal-web-calendar-admin', PLUGIN_URL . 'assets/css/admin.css', array(), VERSION );
		wp_enqueue_script( 'shootcal-web-calendar-admin', PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), VERSION, true );
		wp_localize_script(
			'shootcal-web-calendar-admin',
			'ShootCalWebCalendar',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_TEST_HOOK,
				'nonce'   => wp_create_nonce( self::AJAX_TEST_HOOK ),
				'i18n'    => array(
					'generating'   => __( 'Checking…', 'shootcal-web-calendar' ),
					'enterUrl'     => __( 'Please enter a feed URL first.', 'shootcal-web-calendar' ),
					'networkError' => __( 'Network error while checking the feed.', 'shootcal-web-calendar' ),
					'copied'       => __( 'Copied!', 'shootcal-web-calendar' ),
					'fullHint'     => __( 'This feed has event titles - "Full calendar" mode will show them.', 'shootcal-web-calendar' ),
					'shootcalEmbed' => __( 'ShootCal embed detected - it displays as your live calendar (no feed test needed).', 'shootcal-web-calendar' ),
				),
			)
		);
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'shootcal-web-calendar' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_TEST_HOOK, 'nonce' );

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'No URL provided.', 'shootcal-web-calendar' ) ) );
		}

		// Match the front-end fetch: wp_safe_remote_get so the test reflects what
		// will actually happen at render time (and can't be used to probe internal
		// hosts).
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'ShootCal Web Calendar/' . VERSION . '; ' . home_url(),
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
				'message' => sprintf( __( 'HTTP %d from the calendar server.', 'shootcal-web-calendar' ), $code ),
			) );
		}
		if ( stripos( $body, 'BEGIN:VCALENDAR' ) === false ) {
			wp_send_json_error( array( 'message' => __( 'Response did not look like an iCalendar feed.', 'shootcal-web-calendar' ) ) );
		}

		// Count events for a friendly success message, and detect whether events
		// carry titles (SUMMARY) so the generator can suggest Full vs Availability.
		$events     = preg_match_all( '/^BEGIN:VEVENT/mi', $body );
		$has_titles = (bool) preg_match( '/^SUMMARY[:;]/mi', $body );

		// If event count is suspiciously low for the body size, include diagnostic info
		// so we can see what the server actually fetched. Useful when CF caches differ
		// between data centers, or when intermediate proxies strip parts of the body.
		$debug = array();
		// Only reflect the fetched body preview + upstream headers back to the
		// admin in debug builds. In production this turned the backend into a
		// content-reflector for arbitrary admin-supplied URLs (defense in depth
		// behind the existing manage_options + nonce + SSRF guards).
		if ( 0 === (int) $events && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
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
				'message'    => sprintf(
					/* translators: 1: number of events, 2: response body size in bytes. */
					_n( 'Feed OK: %1$d event found (%2$s bytes).', 'Feed OK: %1$d events found (%2$s bytes).', (int) $events, 'shootcal-web-calendar' ),
					(int) $events,
					number_format_i18n( strlen( $body ) )
				),
				'has_titles' => $has_titles,
			),
			$debug
		) );
	}
}
