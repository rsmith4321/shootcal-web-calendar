<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package ShootCalWebCalendar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shootcal_web_calendar_options' );
delete_option( 'shootcal_web_calendar_cache_ver' );
delete_transient( 'shootcal_web_calendar_ical' );

// Clean up the cached release check left behind by the self-updater that
// earlier builds (distributed outside the WordPress.org directory) shipped.
// Updates now come only from the directory; harmless no-op on sites that
// never ran that updater.
delete_site_transient( 'shootcal_web_calendar_github_release' );
