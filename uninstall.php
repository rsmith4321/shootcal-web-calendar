<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package ShootCalAvailability
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shootcal_availability_options' );
delete_option( 'shootcal_availability_cache_ver' );
delete_transient( 'shootcal_availability_ical' );

// Clean up the cached release check left behind by pre-1.0 builds distributed
// outside the WordPress.org directory. Harmless no-op on sites that never had it.
delete_site_transient( 'shootcal_availability_github_release' );
