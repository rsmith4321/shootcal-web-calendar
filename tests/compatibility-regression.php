<?php
/**
 * Run with wp --skip-plugins --skip-themes eval-file on a local WordPress site.
 *
 * Uses WordPress's real filter engine without changing optimizer/database options.
 * Vendor matcher fixtures model these documented contracts, not a full optimizer:
 * https://perfmatters.io/docs/filters/
 * https://github.com/wp-media/wp-rocket/tree/develop/inc/Engine/Optimization
 * https://github.com/litespeedtech/lscache_wp/blob/master/src/utility.cls.php
 * https://github.com/futtta/autoptimize/blob/beta/classes/autoptimizeScripts.php
 * https://github.com/futtta/autoptimize/blob/beta/classes/autoptimizeStyles.php
 */

$GLOBALS['scwc_compat_assertions'] = 0;
function scwc_compat_assert( bool $condition, string $message ): void {
	++$GLOBALS['scwc_compat_assertions'];
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

// Fail before any SQL mutation, including an accidental option update in bootstrap.
$read_only_query = static function ( $query ) {
	if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query ) ) {
		throw new RuntimeException( 'Compatibility checks attempted a database write.' );
	}
	return $query;
};
add_filter( 'query', $read_only_query );

require_once dirname( __DIR__ ) . '/shootcal-web-calendar.php';
scwc_compat_assert( 10 === has_action( 'plugins_loaded', 'ShootCalWebCalendar\\bootstrap' ), 'Plugin entry point registers its bootstrap.' );
\ShootCalWebCalendar\bootstrap();

$css = '/shootcal-web-calendar/assets/css/frontend.css';
$scripts = array(
	'/shootcal-web-calendar/assets/js/frontend.js',
	'/shootcal-web-calendar/assets/js/embed.js',
	'api.shootcal.com/embed.js',
	'ShootCalWebCalendarFront',
);
$css_hooks = array(
	'perfmatters_rucss_excluded_stylesheets',
	'rocket_rucss_external_exclusions',
	'rocket_rucss_safelist',
	'litespeed_optimize_css_excludes',
);
$literal_js_hooks = array(
	'perfmatters_delay_js_exclusions',
	'perfmatters_defer_js_exclusions',
	'perfmatters_minify_js_exclusions',
	'litespeed_optimize_js_excludes',
	'litespeed_optm_js_defer_exc',
	'litespeed_optm_gm_js_exc',
);
$regex_js_hooks = array(
	'rocket_delay_js_exclusions',
	'rocket_exclude_defer_js',
	'rocket_exclude_js',
	'rocket_minify_excluded_external_js',
);
$inline_hooks = array( 'rocket_defer_inline_exclusions', 'rocket_excluded_inline_js_content' );

// An existing rule may have a non-sequential or named key. Preserve its value,
// key and order, and preserve an already-present ShootCal rule without duplication.
$contracts = array();
foreach ( $css_hooks as $hook ) {
	$contracts[ $hook ] = array( $css );
}
foreach ( $literal_js_hooks as $hook ) {
	$contracts[ $hook ] = $scripts;
}
foreach ( $regex_js_hooks as $hook ) {
	$contracts[ $hook ] = array_map( static fn( $script ) => preg_quote( $script, '#' ), $scripts );
}
foreach ( $inline_hooks as $hook ) {
	$contracts[ $hook ] = array( 'ShootCalWebCalendarFront' );
}
foreach ( $contracts as $hook => $required ) {
	scwc_compat_assert( false !== has_filter( $hook ), "$hook is registered by bootstrap." );
	$existing = array( 4 => '/existing/exclusion', 'vendor' => 'keep-this-rule', 9 => $required[0] );
	$result = apply_filters( $hook, $existing );
	scwc_compat_assert( $existing === array_slice( $result, 0, count( $existing ), true ), "$hook preserves existing entries, keys and order." );
	scwc_compat_assert( count( $existing ) + count( $required ) - 1 === count( $result ), "$hook adds only missing ShootCal entries." );
	foreach ( $required as $rule ) {
		scwc_compat_assert( in_array( $rule, $result, true ), "$hook includes its required rule." );
	}
	scwc_compat_assert( $result === apply_filters( $hook, $result ), "$hook is idempotent." );
	foreach ( array( null, false, 42, 'unexpected CSV' ) as $unexpected ) {
		scwc_compat_assert( $unexpected === apply_filters( $hook, $unexpected ), "$hook leaves unexpected input types unchanged." );
	}
}

$literal_match = static function ( string $content, array $rules ): bool {
	foreach ( $rules as $rule ) {
		if ( false !== strpos( $content, $rule ) ) {
			return true;
		}
	}
	return false;
};
$script_tags = array(
	'<script src="https://cdn.example.test/wp-content/plugins' . $scripts[0] . '?ver=2.5.1"></script>',
	'<script src="https://site.example.test/wp-content/plugins' . $scripts[1] . '"></script>',
	'<script src="https://api.shootcal.com/embed.js" data-src="https://api.shootcal.com/embed/FixtureToken123" async></script>',
	'<script id="shootcal-web-calendar-js-extra">var ShootCalWebCalendarFront = {"ajaxUrl":"https://site.example.test/wp-admin/admin-ajax.php"};</script>',
);
$unrelated_scripts = array(
	'<script src="https://site.example.test/wp-content/plugins/other-calendar/assets/js/frontend.js"></script>',
	'<script src="https://site.example.test/wp-content/plugins/shootcal-web-calendar/assets/js/admin.js"></script>',
	'<script src="https://apiXshootcalYcom/embedZjs"></script>',
	'<script>var OtherCalendarFront = {};</script>',
);
foreach ( $literal_js_hooks as $hook ) {
	$rules = apply_filters( $hook, array() );
	foreach ( $script_tags as $tag ) {
		scwc_compat_assert( $literal_match( $tag, $rules ), "$hook protects a ShootCal startup script or its inline config." );
	}
	foreach ( $unrelated_scripts as $tag ) {
		scwc_compat_assert( ! $literal_match( $tag, $rules ), "$hook leaves unrelated scripts eligible for optimization." );
	}
}
foreach ( $regex_js_hooks as $hook ) {
	$rules = apply_filters( $hook, array() );
	// Delay examines the whole tag; defer/minify examine external script URLs.
	$targets = $script_tags;
	$non_targets = $unrelated_scripts;
	if ( 'rocket_delay_js_exclusions' === $hook ) {
		$rules = array_map( static fn( $rule ) => str_replace( array( '+', '?ver', '#' ), array( '\\+', '\\?ver', '\\#' ), $rule ), $rules );
	} else {
		$get_url = static function ( $tag ) {
			preg_match( '/src="([^"]+)"/', $tag, $matches );
			return $matches[1];
		};
		$targets = array_map( $get_url, array_slice( $script_tags, 0, 3 ) );
		$non_targets = array_map( $get_url, array_slice( $unrelated_scripts, 0, 3 ) );
	}
	$pattern = '#' . implode( '|', $rules ) . '#i';
	foreach ( $targets as $tag ) {
		scwc_compat_assert( 1 === preg_match( $pattern, $tag ), "$hook patterns match the exact ShootCal asset or inline marker." );
	}
	foreach ( $non_targets as $tag ) {
		scwc_compat_assert( 0 === preg_match( $pattern, $tag ), "$hook quoted patterns do not match unrelated/lookalike filenames." );
	}
}
foreach ( $inline_hooks as $hook ) {
	$rules = apply_filters( $hook, array() );
	scwc_compat_assert( $literal_match( $script_tags[3], $rules ), "$hook protects the localized configuration using Rocket's separate inline-content API." );
	scwc_compat_assert( ! $literal_match( $unrelated_scripts[3], $rules ), "$hook leaves unrelated inline code eligible for optimization." );
}

$css_tag = '<link rel="stylesheet" href="https://cdn.example.test/wp-content/plugins' . $css . '?ver=2.5.1" media="all">';
$other_css_tag = '<link rel="stylesheet" href="https://cdn.example.test/wp-content/plugins/other-calendar/assets/css/frontend.css">';
foreach ( $css_hooks as $hook ) {
	$rules = apply_filters( $hook, array() );
	scwc_compat_assert( $literal_match( $css_tag, $rules ) && ! $literal_match( $other_css_tag, $rules ), "$hook targets only the complete ShootCal frontend stylesheet." );
}

// Autoptimize's CSV APIs trim tokens for matching, but we preserve the original
// user's text verbatim and avoid adding duplicate tokens despite whitespace.
foreach ( array( 'autoptimize_filter_css_exclude' => array( $css ), 'autoptimize_filter_js_exclude' => $scripts ) as $hook => $required ) {
	scwc_compat_assert( false !== has_filter( $hook ), "$hook is registered by bootstrap." );
	$existing = ' existing.js , ' . $required[0] . ' ,custom-rule';
	$result = apply_filters( $hook, $existing );
	$rules = array_map( 'trim', explode( ',', $result ) );
	scwc_compat_assert( str_starts_with( $result, $existing ), "$hook preserves existing CSV text." );
	scwc_compat_assert( count( $rules ) === 3 + count( $required ) - 1, "$hook avoids duplicates after whitespace normalization." );
	foreach ( $required as $rule ) {
		scwc_compat_assert( in_array( $rule, $rules, true ), "$hook adds its required literal exclusion." );
	}
	scwc_compat_assert( $result === apply_filters( $hook, $result ), "$hook is idempotent." );
	scwc_compat_assert( implode( ',', $required ) === apply_filters( $hook, '' ), "$hook handles an empty CSV list." );
	foreach ( array( null, false, 42 ) as $unexpected ) {
		scwc_compat_assert( $unexpected === apply_filters( $hook, $unexpected ), "$hook leaves unsupported types unchanged." );
	}
}
$ao_js = apply_filters( 'autoptimize_filter_js_exclude', array(
	'legacy-remove.js' => 'remove',
	'legacy-async.js' => 'async',
	'legacy-defer.js' => 'defer',
	$scripts[0] => 'defer',
) );
scwc_compat_assert( 'remove' === $ao_js['legacy-remove.js'] && 'async' === $ao_js['legacy-async.js'] && 'defer' === $ao_js['legacy-defer.js'] && 'defer' === $ao_js[ $scripts[0] ], 'Autoptimize preserves existing flags, including an explicit flag on a ShootCal entry.' );
scwc_compat_assert( '' === $ao_js[ $scripts[1] ] && '' === $ao_js[ $scripts[2] ] && '' === $ao_js[ $scripts[3] ], 'Autoptimize adds pattern keys with empty flags rather than numeric array values.' );
scwc_compat_assert( $ao_js === apply_filters( 'autoptimize_filter_js_exclude', $ao_js ), 'Autoptimize keyed arrays are idempotent.' );
foreach ( $script_tags as $tag ) {
	scwc_compat_assert( $literal_match( $tag, array_keys( $ao_js ) ), 'Autoptimize keyed patterns protect each startup script and localized config.' );
}
foreach ( $unrelated_scripts as $tag ) {
	scwc_compat_assert( ! $literal_match( $tag, array_keys( $ao_js ) ), 'Autoptimize keyed patterns do not exclude unrelated scripts.' );
}
$ao_css = array_map( 'trim', explode( ',', apply_filters( 'autoptimize_filter_css_exclude', '' ) ) );
scwc_compat_assert( $literal_match( $css_tag, $ao_css ) && ! $literal_match( $other_css_tag, $ao_css ), 'Autoptimize excludes only ShootCal frontend CSS from aggregation.' );
scwc_compat_assert( false === apply_filters( 'autoptimize_filter_css_defer_excluded', true, $css_tag ), 'Autoptimize receives the second tag argument and keeps ShootCal CSS synchronous.' );
scwc_compat_assert( true === apply_filters( 'autoptimize_filter_css_defer_excluded', true, $other_css_tag ), 'Autoptimize can still defer unrelated excluded CSS.' );
scwc_compat_assert( false === apply_filters( 'autoptimize_filter_css_defer_excluded', false, $other_css_tag ), 'Autoptimize retains another filter\'s decision not to defer unrelated CSS.' );
scwc_compat_assert( true === apply_filters( 'autoptimize_filter_css_defer_excluded', true, null ), 'Autoptimize retains the existing value when a stylesheet tag is unavailable.' );

remove_filter( 'query', $read_only_query );
$assertions = $GLOBALS['scwc_compat_assertions'];
echo "PASS: $assertions compatibility assertions using WordPress filters; no database options changed.\n";
