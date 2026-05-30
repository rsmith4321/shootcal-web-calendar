<?php
/**
 * Plugin Name:       ShootCal Web Calendar
 * Plugin URI:        https://shootcal.com
 * Description:       Embed an iCal calendar on your site as a month grid - free/busy availability (no event details) or a full calendar with event titles and times. Per-embed feed URL via shortcode or block.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Ryan Smith
 * Author URI:        https://www.ryansmithphotography.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shootcal-web-calendar
 * Domain Path:       /languages
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

const VERSION     = '2.0.0';
const SLUG        = 'shootcal-web-calendar';
const OPTION_KEY  = 'shootcal_web_calendar_options';
const CACHE_KEY   = 'shootcal_web_calendar_ical';
const CACHE_TTL   = 10 * MINUTE_IN_SECONDS;

define( __NAMESPACE__ . '\\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader for our namespace.
 *
 * Maps ShootCalWebCalendar\Foo_Bar -> includes/class-foo-bar.php
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( strpos( $class_name, __NAMESPACE__ . '\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );
		$file     = strtolower( str_replace( '_', '-', $relative ) );
		$path     = PLUGIN_DIR . 'includes/class-' . $file . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Bootstrap the plugin.
 */
function bootstrap(): void {
	load_plugin_textdomain( 'shootcal-web-calendar', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

	( new Settings() )->register();
	( new Shortcode() )->register();
	( new Block() )->register();
	( new Assets() )->register();

	// The GitHub Releases self-updater ships only in the build distributed from
	// shootcal.com / GitHub. The WordPress.org build omits
	// includes/class-updater.php (the directory is the update source there), so
	// only wire it up when the file is actually present.
	if ( file_exists( PLUGIN_DIR . 'includes/class-updater.php' ) ) {
		( new Updater() )->register();
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

/**
 * Activation hook: ensure default options exist, carrying over settings from the
 * pre-2.0 "ShootCal Availability" plugin if present (the slug rename makes WP see
 * this as a new plugin, so options don't transfer automatically).
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( false !== get_option( OPTION_KEY ) ) {
			return;
		}

		// Migrate display settings from the old plugin slug. Drop the removed
		// single-URL keys - feed URLs now live on each shortcode/block.
		$legacy = get_option( 'shootcal_availability_options' );
		if ( is_array( $legacy ) ) {
			unset( $legacy['calendar_url'], $legacy['source'], $legacy['ical_url'], $legacy['shootcal_feed_url'] );
			add_option( OPTION_KEY, $legacy );
			return;
		}

		add_option(
			OPTION_KEY,
			array(
				'months_ahead'       => 3,
				'first_day_of_week'  => 0,                   // 0=Sunday, 1=Monday
				'limited_color'      => '#fce3a8',           // base color for Limited cells (rendered at 0.8 opacity)
				'booked_color'       => '#f6b9a3',           // base color for Booked cells (rendered at 0.8 opacity)
				'timezone'           => '',                  // '' = follow the WordPress site timezone
				'ajax_render'        => false,               // Page caching mode off by default
			)
		);
	}
);

/**
 * Deactivation hook: flush the iCal cache so it does not outlive an uninstall.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		// Bump the cache version so every per-URL cached feed becomes unreachable
		// (the bare CACHE_KEY transient never existed under the versioned scheme).
		Fetcher::flush_cache();
	}
);
