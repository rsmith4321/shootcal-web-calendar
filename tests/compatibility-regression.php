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
 * Additional source versions and feature limits: optimizer-contracts.md.
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
	'wphb_critical_css_exclusions',
	'flying_press_exclude_from_minify:css',
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
	'wphb_delay_js_exclusions',
);
$inline_hooks = array( 'rocket_defer_inline_exclusions', 'rocket_excluded_inline_js_content', 'sgo_javascript_combine_excluded_inline_content' );
$file_js_hooks = array( 'flying_press_exclude_from_minify:js', 'sgo_javascript_combine_excluded_external_paths', 'sgo_javascript_combine_excluded_internal_paths' );
$wp_optimize_hooks = array( 'wp-optimize-minify-default-exclusions', 'wp-optimize-minify-blacklist' );
$style_handle_hooks = array( 'sgo_css_minify_exclude', 'sgo_css_combine_exclude' );
$script_handle_hooks = array( 'sgo_js_minify_exclude', 'sgo_javascript_combine_exclude', 'sgo_js_async_exclude' );

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
foreach ( $file_js_hooks as $hook ) {
	$contracts[ $hook ] = array_slice( $scripts, 0, 3 );
}
foreach ( $wp_optimize_hooks as $hook ) {
	$contracts[ $hook ] = array_merge( array( $css ), array_slice( $scripts, 0, 3 ) );
}
foreach ( $style_handle_hooks as $hook ) {
	$contracts[ $hook ] = array( 'shootcal-web-calendar' );
}
foreach ( $script_handle_hooks as $hook ) {
	$contracts[ $hook ] = array( 'shootcal-web-calendar', 'shootcal-web-calendar-embed' );
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
	} elseif ( 'wphb_delay_js_exclusions' !== $hook ) {
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
	scwc_compat_assert( $literal_match( $script_tags[3], $rules ), "$hook protects the localized configuration using its separate inline-content API." );
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

// SiteGround matches exact WordPress handles, not URL fragments. The separate
// combination APIs and FlyingPress minification APIs match literal URL parts.
foreach ( array_merge( $style_handle_hooks, $script_handle_hooks ) as $hook ) {
	$rules = apply_filters( $hook, array( 'existing-handle' ) );
	scwc_compat_assert( in_array( 'shootcal-web-calendar', $rules, true ), "$hook protects the registered frontend handle." );
	scwc_compat_assert( ! in_array( 'shootcal-web-calendar-editor', $rules, true ), "$hook does not broadly exclude editor or unrelated handles." );
	scwc_compat_assert( ! in_array( $scripts[0], $rules, true ), "$hook receives handles instead of ineffective URL rules." );
}
foreach ( $file_js_hooks as $hook ) {
	$rules = apply_filters( $hook, array() );
	foreach ( array_slice( $script_tags, 0, 3 ) as $tag ) {
		scwc_compat_assert( $literal_match( $tag, $rules ), "$hook protects own files and the original external loader URL." );
	}
	foreach ( $unrelated_scripts as $tag ) {
		scwc_compat_assert( ! $literal_match( $tag, $rules ), "$hook does not exclude an unrelated or lookalike URL." );
	}
	scwc_compat_assert( ! in_array( 'ShootCalWebCalendarFront', $rules, true ), "$hook receives file fragments without an ineffective inline marker." );
}

// WP-Optimize strips the scheme/query, URL-decodes twice and matches literal
// fragments case-insensitively. Its blacklist is checked before async_using_js;
// default exclusions alone are checked too late for that loading mode.
$wpo_match = static function ( string $url, array $rules ): bool {
	$url = preg_replace( '#^https?://#i', '', $url );
	$url = strtok( urldecode( urldecode( $url ) ), '?' );
	foreach ( $rules as $rule ) {
		if ( false !== stripos( $url, trim( $rule, '/*' ) ) ) {
			return true;
		}
	}
	return false;
};
foreach ( $wp_optimize_hooks as $hook ) {
	$rules = apply_filters( $hook, array() );
	foreach ( array_merge( array( $css ), array_slice( $scripts, 0, 3 ) ) as $path ) {
		scwc_compat_assert( $wpo_match( 'https://cdn.example.test/wp-content/plugins' . $path . '?ver=2.5.2', $rules ), "$hook matches original calendar assets." );
	}
	scwc_compat_assert( $wpo_match( 'https://cdn.example.test/SHOOTCAL-WEB-CALENDAR/assets/js/%2566rontend.js?ver=2.5.2', $rules ), "$hook remains effective after vendor URL normalization." );
	scwc_compat_assert( ! $wpo_match( 'https://cdn.example.test/other-calendar/assets/js/frontend.js', $rules ), "$hook leaves other plugins eligible." );
}
$wpo_async = static function ( $tag, $src ) use ( $wpo_match ) {
	if ( $wpo_match( $src, apply_filters( 'wp-optimize-minify-blacklist', array() ) ) ) {
		return $tag;
	}
	return 'async-wrapper';
};
scwc_compat_assert( $script_tags[0] === $wpo_async( $script_tags[0], 'https://site.example.test/wp-content/plugins' . $scripts[0] ), 'WP-Optimize blacklist returns the original tag before async-via-JS transformation.' );
scwc_compat_assert( 'async-wrapper' === $wpo_async( $unrelated_scripts[0], 'https://site.example.test/other.js' ), 'WP-Optimize may still apply the selected async loading mode to unrelated scripts.' );

$hummingbird_hooks = array( 'wphb_minify_resource', 'wphb_combine_resource', 'wphb_defer_resource', 'wphb_async_resource', 'wphb_inline_resource' );
foreach ( $hummingbird_hooks as $hook ) {
	scwc_compat_assert( 20 === has_filter( $hook, array( 'ShootCalWebCalendar\\Compatibility', 'hummingbird_resource' ) ), "$hook runs after Hummingbird's saved choices at priority 10." );
	foreach ( array( 'shootcal-web-calendar', 'shootcal-web-calendar-embed' ) as $handle ) {
		scwc_compat_assert( false === apply_filters( $hook, true, $handle, 'scripts', 'https://cdn.example.test/renamed.js' ), "$hook recognizes exact script handles even with a CDN URL." );
	}
	scwc_compat_assert( false === apply_filters( $hook, true, 'renamed-handle', 'scripts', 'https://api.shootcal.com/embed.js' ), "$hook protects an enqueued standalone loader by original URL." );
	scwc_compat_assert( false === apply_filters( $hook, true, 'renamed-handle', 'styles', 'https://cdn.example.test/wp-content/plugins' . $css ), "$hook recognizes the complete calendar stylesheet URL." );
	scwc_compat_assert( false === apply_filters( $hook, true, 'shootcal-web-calendar', 'styles', 'https://cdn.example.test/renamed.css' ), "$hook recognizes the stylesheet's exact handle." );
	foreach ( array( true, false, null ) as $decision ) {
		scwc_compat_assert( $decision === apply_filters( $hook, $decision, 'other-plugin', 'scripts', 'https://example.test/other.js' ), "$hook preserves other files' existing decisions." );
		scwc_compat_assert( $decision === apply_filters( $hook, $decision, 'shootcal-web-calendar', 'unknown-type', null ), "$hook ignores an unsupported resource type." );
	}
	$vendor_saved_choice = static fn( $decision ) => true;
	add_filter( $hook, $vendor_saved_choice, 10 );
	scwc_compat_assert( false === apply_filters( $hook, false, 'shootcal-web-calendar', 'scripts', $scripts[0] ), "$hook protects our script after the vendor applies its saved choice." );
	scwc_compat_assert( true === apply_filters( $hook, false, 'other-plugin', 'scripts', '/other.js' ), "$hook preserves the vendor's saved choice for other scripts." );
	remove_filter( $hook, $vendor_saved_choice, 10 );
}

// W3TC's boolean filters retain original tag/queue boundaries. Removing a tag
// from its scan list would instead allow unrelated scripts to combine across it.
// The no-src inline branch returns before this hook and remains vendor-owned.
foreach ( array_slice( $script_tags, 0, 3 ) as $tag ) {
	scwc_compat_assert( false === apply_filters( 'w3tc_minify_js_do_tag_minification', true, $tag, '/vendor/file.js' ), 'W3TC receives the original script tag and skips the calendar asset.' );
}
foreach ( $unrelated_scripts as $tag ) {
	scwc_compat_assert( true === apply_filters( 'w3tc_minify_js_do_tag_minification', true, $tag, '/vendor/file.js' ), 'W3TC may still minify an unrelated script.' );
	scwc_compat_assert( false === apply_filters( 'w3tc_minify_js_do_tag_minification', false, $tag, '/vendor/file.js' ), 'W3TC preserves another exclusion decision.' );
}
scwc_compat_assert( false === apply_filters( 'w3tc_minify_css_do_tag_minification', true, $css_tag, '/vendor/file.css' ), 'W3TC preserves the complete calendar stylesheet.' );
scwc_compat_assert( true === apply_filters( 'w3tc_minify_css_do_tag_minification', true, $other_css_tag, '/vendor/file.css' ), 'W3TC still processes unrelated stylesheets.' );

// Use WordPress's actual HTML parser and inline tag serializer for NitroPack.
$nitro_tag = apply_filters( 'script_loader_tag', $script_tags[0], 'shootcal-web-calendar', 'https://cdn.example.test/wp-content/plugins' . $scripts[0] );
$processor = new WP_HTML_Tag_Processor( $nitro_tag );
$processor->next_tag( 'SCRIPT' );
scwc_compat_assert( true === $processor->get_attribute( 'nitro-exclude' ), 'NitroPack external script attribute is present as a boolean attribute.' );
scwc_compat_assert( $nitro_tag === apply_filters( 'script_loader_tag', $nitro_tag, 'shootcal-web-calendar', $scripts[0] ), 'NitroPack attributes are idempotent.' );
foreach ( array( $script_tags[1], $script_tags[2] ) as $tag ) {
	$nitro_tag = apply_filters( 'script_loader_tag', $tag, 'custom-handle', false !== strpos( $tag, 'api.shootcal.com' ) ? 'https://api.shootcal.com/embed.js' : $scripts[1] );
	scwc_compat_assert( str_contains( $nitro_tag, ' nitro-exclude' ), 'NitroPack recognizes frontend script URLs with another enqueue handle.' );
}
$compound = '<script>window.keepBefore = true;</script><script src="https://cdn.example.test/renamed.js" defer data-wp-strategy="defer" nonce="keep-me"></script>';
$compound_result = apply_filters( 'script_loader_tag', $compound, 'shootcal-web-calendar-embed', 'https://cdn.example.test/renamed.js' );
scwc_compat_assert( str_starts_with( $compound_result, '<script>window.keepBefore = true;</script>' ), 'NitroPack tag processing leaves preceding inline code unchanged.' );
$processor = new WP_HTML_Tag_Processor( $compound_result );
$processor->next_tag( 'SCRIPT' ); $processor->next_tag( 'SCRIPT' );
scwc_compat_assert( true === $processor->get_attribute( 'nitro-exclude' ) && true === $processor->get_attribute( 'defer' ) && 'keep-me' === $processor->get_attribute( 'nonce' ) && 'defer' === $processor->get_attribute( 'data-wp-strategy' ), 'NitroPack attribute insertion preserves the native loading strategy and CSP nonce.' );
scwc_compat_assert( $unrelated_scripts[0] === apply_filters( 'script_loader_tag', $unrelated_scripts[0], 'other-plugin', '/other.js' ), 'NitroPack leaves unrelated scripts byte-for-byte unchanged.' );
scwc_compat_assert( null === apply_filters( 'script_loader_tag', null, null, null ), 'NitroPack leaves unsupported script input unchanged.' );
$attributes = array( 'id' => 'shootcal-web-calendar-js-extra', 'nonce' => 'keep-inline-nonce', 'data-other' => 'kept' );
$expected = $attributes + array( 'nitro-exclude' => true );
scwc_compat_assert( $expected === apply_filters( 'wp_inline_script_attributes', $attributes ), 'NitroPack config attributes preserve the nonce and all existing data.' );
$inline_tag = wp_get_inline_script_tag( 'var ShootCalWebCalendarFront = {};', $attributes );
scwc_compat_assert( str_contains( $inline_tag, ' nitro-exclude' ) && str_contains( $inline_tag, 'var ShootCalWebCalendarFront = {};' ), 'Actual WordPress inline serializer emits the NitroPack marker without modifying JavaScript.' );
foreach ( array( array( 'id' => 'other-plugin-js-extra' ), array( 'id' => 'shootcal-web-calendar-editor-js-extra' ), array(), null ) as $unrelated ) {
	scwc_compat_assert( $unrelated === apply_filters( 'wp_inline_script_attributes', $unrelated ), 'NitroPack limits inline attributes to the exact calendar configuration ID.' );
}

remove_filter( 'query', $read_only_query );
$assertions = $GLOBALS['scwc_compat_assertions'];
echo "PASS: $assertions compatibility assertions using WordPress filters; no database options changed.\n";
