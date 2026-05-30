<?php
/**
 * Plugin Name:       ShootCal Availability
 * Plugin URI:        https://shootcal.com
 * Description:       Display your Google Calendar availability on your website as a month grid. Reads a private iCal URL and shows busy days without revealing event details.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Ryan Smith
 * Author URI:        https://www.ryansmithphotography.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shootcal-availability
 * Domain Path:       /languages
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.1.2';
const SLUG        = 'shootcal-availability';
const OPTION_KEY  = 'shootcal_availability_options';
const CACHE_KEY   = 'shootcal_availability_ical';
const CACHE_TTL   = 10 * MINUTE_IN_SECONDS;

define( __NAMESPACE__ . '\\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader for our namespace.
 *
 * Maps ShootCalAvailability\Foo_Bar -> includes/class-foo-bar.php
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
	load_plugin_textdomain( 'shootcal-availability', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

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
 * Activation hook: ensure default options exist.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( false === get_option( OPTION_KEY ) ) {
			add_option(
				OPTION_KEY,
				array(
					'source'             => 'google',           // 'google' | 'shootcal'
					'ical_url'           => '',                  // Google Calendar secret iCal URL
					'shootcal_feed_url'  => '',                  // ShootCal-minted feed URL
					'months_ahead'       => 3,
					'first_day_of_week'  => 0,                   // 0=Sunday, 1=Monday
					'multi_session_day'  => true,                // true: timed-only days are "Limited"; false: any event = "Booked"
					'limited_color'      => '#fce3a8',           // base color for Limited cells (rendered at 0.8 opacity)
					'booked_color'       => '#f6b9a3',           // base color for Booked cells (rendered at 0.8 opacity)
					'timezone'           => wp_timezone_string(),
				)
			);
		}
	}
);

/**
 * Deactivation hook: flush the iCal cache so it does not outlive an uninstall.
 */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		delete_transient( CACHE_KEY );
	}
);
