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

// Clean up the cached release check left behind by pre-1.0 builds distributed
// outside the WordPress.org directory. Harmless no-op on sites that never had it.
delete_site_transient( 'shootcal_web_calendar_github_release' );
