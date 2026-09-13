<?php
/**
 * Real WordPress menu regression, with no option/account writes or HTTP calls.
 *
 * wp --skip-plugins --skip-themes eval-file tests/admin-menu-regression.php /path/to/shootcal-instagram-feed
 *
 * Active plugins, installed metadata, and users are in-memory fixtures. All SQL
 * except reads is rejected, and the original process state is restored.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_using_ext_object_cache() ) {
	throw new RuntimeException( 'Use WP-CLI on a local site without an external object cache.' );
}
if ( function_exists( 'ShootCalWebCalendar\\bootstrap' ) || function_exists( 'ShootCalInstagramFeed\\bootstrap' ) ) {
	throw new RuntimeException( 'Run with --skip-plugins --skip-themes.' );
}
$social_root = isset( $args[0] ) ? rtrim( $args[0], '/' ) : '';
if ( ! is_file( $social_root . '/shootcal-instagram-feed.php' ) ) {
	throw new RuntimeException( 'Pass the Social Feed checkout directory as the first argument.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once dirname( __DIR__ ) . '/shootcal-web-calendar.php';
require_once $social_root . '/shootcal-instagram-feed.php';

$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
};
$sql_guard = static function ( string $query ): string {
	if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $query ) ) {
		throw new RuntimeException( 'Unexpected database write blocked by menu regression.' );
	}
	return $query;
};
$http_guard = static function (): void {
	throw new RuntimeException( 'Unexpected outgoing request blocked by menu regression.' );
};
add_filter( 'query', $sql_guard, PHP_INT_MAX );
add_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );

$copy_hooks = static function ( array $hooks ): array {
	return array_map( static fn( $hook ) => is_object( $hook ) ? clone $hook : $hook, $hooks );
};
$saved_globals = array();
foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file',
	'_wp_menu_nopriv', '_wp_submenu_nopriv', 'pagenow', 'plugin_page', 'parent_file', 'typenow',
	'current_user', 'wp_object_cache', 'wp_scripts', 'wp_styles' ) as $name ) {
	$saved_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
}
$baseline_hooks = $copy_hooks( $GLOBALS['wp_filter'] );
$option_names = array( 'active_plugins', 'cron', 'shootcal_web_calendar_options', 'shootcal_web_calendar_cache_ver',
	'shootcal_instagram_feed_options', 'shootcal_instagram_feed_feeds', 'shootcal_instagram_feed_cache',
	'shootcal_instagram_feed_status', 'shootcal_instagram_feed_refresh_lock', 'shootcal_instagram_feed_oauth' );
$stored_before = array_map( 'get_option', $option_names );
$slugs = array( 'calendar' => 'shootcal-web-calendar', 'social' => 'shootcal-instagram-feed' );
$files = array_map( static fn( string $slug ): string => $slug . '/' . $slug . '.php', $slugs );

try {
	$normalizer = static function ( string $source ): string {
		$source = preg_replace( '#/\*\*.*?\*/#s', '', $source );
		return str_replace( array( 'ShootCalInstagramFeed', ", 'shootcal-instagram-feed' )" ), array( 'ShootCalWebCalendar', ", 'shootcal-web-calendar' )" ), $source );
	};
	$assert( $normalizer( file_get_contents( dirname( __DIR__ ) . '/includes/class-admin-menu.php' ) )
		=== $normalizer( file_get_contents( $social_root . '/includes/class-admin-menu.php' ) ), 'Shared menu helpers differ beyond namespace, text domain, and documentation.' );
	foreach ( array( 'assets/css/admin-menu.css', 'assets/img/shootcal-logo.svg' ) as $asset ) {
		$assert( file_get_contents( dirname( __DIR__ ) . '/' . $asset ) === file_get_contents( $social_root . '/' . $asset ), 'Shared menu assets must match in both plugins.' );
	}

	foreach ( array( array( 'calendar' ), array( 'social' ), array( 'calendar', 'social' ), array( 'social', 'calendar' ) ) as $order ) {
		foreach ( array( true, false ) as $administrator ) {
			$GLOBALS['wp_filter'] = $copy_hooks( $baseline_hooks );
			foreach ( array( 'menu', 'submenu', '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_menu_nopriv', '_wp_submenu_nopriv' ) as $name ) {
				$GLOBALS[ $name ] = array();
			}
			$GLOBALS['admin_page_hooks'] = array( 'options-general.php' => 'settings' );
			$GLOBALS['pagenow'] = 'admin.php';
			$GLOBALS['parent_file'] = '';
			$GLOBALS['typenow'] = '';
			unset( $GLOBALS['plugin_page'] );
			$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
			$GLOBALS['wp_scripts'] = new WP_Scripts();
			$GLOBALS['wp_styles'] = new WP_Styles();
			$user = new WP_User();
			$user->ID = 987654321;
			$user->allcaps = array( 'read' => true, 'manage_options' => $administrator );
			$GLOBALS['current_user'] = $user;
			$active = array_map( static fn( string $key ): string => $files[ $key ], $order );
			add_filter( 'pre_option_active_plugins', static fn() => $active );
			add_filter( 'pre_site_option_active_sitewide_plugins', static fn() => array() );
			$installed = array_fill_keys( array_values( $files ), array( 'Version' => 'fixture' ) );
			wp_cache_set( 'plugins', array( '' => $installed ), 'plugins' );
			foreach ( $order as $key ) {
				if ( 'calendar' === $key ) {
					\ShootCalWebCalendar\bootstrap();
				} else {
					\ShootCalInstagramFeed\bootstrap();
				}
			}
			do_action( 'admin_menu' );
			$parents = array_filter( $GLOBALS['menu'], static fn( array $entry ): bool => 'shootcal' === $entry[2] );
			$assert( 1 === count( $parents ), 'Each load order must register exactly one ShootCal parent.' );
			$parent = reset( $parents );
			$assert( 'ShootCal Apps' === $parent[0] && 'ShootCal Apps' === $parent[3], 'Parent label and page title must be ShootCal Apps.' );
			$owner_url = 'calendar' === $order[0] ? \ShootCalWebCalendar\PLUGIN_URL : \ShootCalInstagramFeed\PLUGIN_URL;
			$assert( $owner_url . 'assets/img/shootcal-logo.svg' === $parent[6], 'Parent logo must use the first active plugin local SVG.' );
			$assert( 'shootcal-apps' === $GLOBALS['admin_page_hooks']['shootcal'], 'WordPress must derive its page hook prefix from ShootCal Apps.' );
			$children = array_column( $GLOBALS['submenu']['shootcal'] ?? array(), 2 );
			foreach ( $GLOBALS['submenu']['shootcal'] ?? array() as $child ) {
				$icons = array( 'shootcal' => 'dashicons-admin-home', $slugs['calendar'] => 'dashicons-calendar-alt', $slugs['social'] => 'dashicons-instagram' );
				$assert( str_contains( $child[0], $icons[ $child[2] ] ) && str_contains( $child[0], 'aria-hidden="true"' ), 'Child menu icons must be decorative and match their app.' );
			}
			$expected = $administrator ? array_merge( array( 'shootcal' ), array_map( static fn( string $key ): string => $slugs[ $key ], $order ) ) : array();
			sort( $children );
			sort( $expected );
			$assert( $expected === $children, 'Submenus must contain exactly the active plugins and one Overview for administrators.' );
			$assert( ! in_array( $slugs['calendar'], array_column( $GLOBALS['submenu']['options-general.php'] ?? array(), 2 ), true ), 'Legacy Calendar page must not duplicate the Settings sidebar entry.' );

			$routes = array( array( 'admin.php', 'shootcal' ) );
			foreach ( $order as $key ) {
				$routes[] = array( 'admin.php', $slugs[ $key ] );
				if ( 'calendar' === $key ) {
					$routes[] = array( 'options-general.php', $slugs[ $key ] );
				}
			}
			foreach ( $routes as [ $pagenow, $page ] ) {
				$GLOBALS['pagenow'] = $pagenow;
				$GLOBALS['plugin_page'] = $page;
				$GLOBALS['parent_file'] = '';
				$assert( $administrator === user_can_access_admin_page(), 'Route permissions failed for ' . $pagenow . '?page=' . $page );
				$hook = get_plugin_page_hook( $page, $pagenow );
				if ( $administrator ) {
					$assert( is_string( $hook ) && isset( $GLOBALS['_registered_pages'][ $hook ] ), 'Registered callback missing for ' . $pagenow . '?page=' . $page );
					$GLOBALS['wp_scripts'] = new WP_Scripts();
					$GLOBALS['wp_styles'] = new WP_Styles();
					do_action( 'admin_enqueue_scripts', $hook );
					$assert( wp_style_is( 'shootcal-apps-admin-menu', 'enqueued' ), 'Shared menu CSS must load with either plugin alone.' );
					$assert( $owner_url . 'assets/css/admin-menu.css' === wp_styles()->registered['shootcal-apps-admin-menu']->src, 'Only the parent owner supplies shared menu CSS.' );
					$assert( ( $slugs['calendar'] === $page ) === wp_script_is( 'shootcal-web-calendar-admin', 'enqueued' ), 'Calendar script must load on both Calendar routes and no other pages.' );
					$assert( ( $slugs['calendar'] === $page ) === wp_style_is( 'shootcal-web-calendar-admin', 'enqueued' ), 'Calendar CSS must load on both Calendar routes and no other pages.' );
				}
			}

			foreach ( $order as $key ) {
				$dashboard = 'calendar' === $key ? new \ShootCalWebCalendar\Admin_Menu() : new \ShootCalInstagramFeed\Admin_Menu();
				ob_start();
				$dashboard->render_page();
				$html = ob_get_clean();
				if ( ! $administrator ) {
					$assert( '' === $html, 'Dashboard must emit no content without manage_options.' );
					continue;
				}
				$assert( 2 === substr_count( $html, '<section class="card"' ), 'Overview must show both plugin cards once.' );
				$assert( str_contains( $html, 'ShootCal Apps</h1>' ) && str_contains( $html, 'assets/img/shootcal-logo.svg' ), 'Overview must display the ShootCal Apps heading and logo.' );
				foreach ( $slugs as $candidate => $slug ) {
					$assert( in_array( $candidate, $order, true ) === str_contains( $html, admin_url( 'admin.php?page=' . $slug ) ), 'Only active plugins receive settings links.' );
				}
				if ( 1 === count( $order ) ) {
					$assert( str_contains( $html, 'Installed, inactive' ) && str_contains( $html, admin_url( 'plugins.php' ) ), 'Inactive installed plugin must link to plugin management.' );
					wp_cache_set( 'plugins', array( '' => array( $files[ $key ] => array( 'Version' => 'fixture' ) ) ), 'plugins' );
					ob_start();
					$dashboard->render_page();
					$missing_html = ob_get_clean();
					$other = 'calendar' === $key ? 'social' : 'calendar';
					$assert( str_contains( $missing_html, 'Not installed' ) && str_contains( $missing_html, 'https://wordpress.org/plugins/' . $slugs[ $other ] . '/' ), 'Missing plugin must link to its directory page.' );
				}
			}
		}
	}
} finally {
	$GLOBALS['wp_filter'] = $baseline_hooks;
	foreach ( $saved_globals as $name => [ $present, $value ] ) {
		if ( $present ) {
			$GLOBALS[ $name ] = $value;
		} else {
			unset( $GLOBALS[ $name ] );
		}
	}
	$assert( $stored_before === array_map( 'get_option', $option_names ), 'Stored plugin settings, credentials, activation state, and cron must remain unchanged.' );
	remove_filter( 'query', $sql_guard, PHP_INT_MAX );
	remove_filter( 'pre_http_request', $http_guard, PHP_INT_MAX );
}

echo 'PASS: ' . $checks . " shared-menu WordPress regression checks; no database writes or outgoing requests.\n";
