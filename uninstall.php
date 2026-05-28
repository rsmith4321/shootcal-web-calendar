<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package ShootCalAvailability
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'shootcal_availability_options' );
delete_transient( 'shootcal_availability_ical' );
