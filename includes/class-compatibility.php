<?php
/**
 * Keep dynamically rendered calendar styles and startup scripts available.
 *
 * @package ShootCalWebCalendar
 */

declare( strict_types=1 );

namespace ShootCalWebCalendar;

defined( 'ABSPATH' ) || exit;

class Compatibility {

	private const STYLESHEETS = array( '/shootcal-web-calendar/assets/css/frontend.css' );
	private const STYLE_HANDLES = array( 'shootcal-web-calendar' );
	private const SCRIPT_HANDLES = array( 'shootcal-web-calendar', 'shootcal-web-calendar-embed' );

	private const SCRIPTS = array(
		'/shootcal-web-calendar/assets/js/frontend.js',
		'/shootcal-web-calendar/assets/js/embed.js',
		'api.shootcal.com/embed.js',
		'ShootCalWebCalendarFront',
	);

	public function register(): void {
		// These filters run even when an optimizer crawls outside the main query.
		add_filter( 'perfmatters_rucss_excluded_stylesheets', array( self::class, 'exclude_stylesheets' ) );
		add_filter( 'perfmatters_delay_js_exclusions', array( self::class, 'exclude_scripts' ) );
		add_filter( 'perfmatters_defer_js_exclusions', array( self::class, 'exclude_scripts' ) );
		add_filter( 'perfmatters_minify_js_exclusions', array( self::class, 'exclude_scripts' ) );

		// Preserve the complete CSS file, including states created after the crawl.
		add_filter( 'rocket_rucss_external_exclusions', array( self::class, 'exclude_stylesheets' ) );
		add_filter( 'rocket_rucss_safelist', array( self::class, 'exclude_stylesheets' ) );
		foreach ( array( 'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'rocket_exclude_js', 'rocket_minify_excluded_external_js' ) as $hook ) {
			add_filter( $hook, array( self::class, 'exclude_script_patterns' ) );
		}
		add_filter( 'rocket_defer_inline_exclusions', array( self::class, 'exclude_inline_config' ) );
		add_filter( 'rocket_excluded_inline_js_content', array( self::class, 'exclude_inline_config' ) );

		add_filter( 'litespeed_optimize_css_excludes', array( self::class, 'exclude_stylesheets' ) );
		add_filter( 'litespeed_optimize_js_excludes', array( self::class, 'exclude_scripts' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( self::class, 'exclude_scripts' ) );
		add_filter( 'litespeed_optm_gm_js_exc', array( self::class, 'exclude_scripts' ) );

		// Autoptimize uses CSV strings, or keyed JS arrays with per-entry flags.
		add_filter( 'autoptimize_filter_css_exclude', array( self::class, 'autoptimize_stylesheets' ) );
		add_filter( 'autoptimize_filter_js_exclude', array( self::class, 'autoptimize_scripts' ) );
		add_filter( 'autoptimize_filter_css_defer_excluded', array( self::class, 'autoptimize_defer_stylesheet' ), 10, 2 );

		// WP-Optimize checks its blacklist before the optional async JS wrapper.
		add_filter( 'wp-optimize-minify-default-exclusions', array( self::class, 'exclude_assets' ) );
		add_filter( 'wp-optimize-minify-blacklist', array( self::class, 'exclude_assets' ) );

		// SiteGround uses handles for enqueued assets and paths for HTML combining.
		foreach ( array( 'sgo_css_minify_exclude', 'sgo_css_combine_exclude' ) as $hook ) {
			add_filter( $hook, array( self::class, 'exclude_style_handles' ) );
		}
		foreach ( array( 'sgo_js_minify_exclude', 'sgo_javascript_combine_exclude', 'sgo_js_async_exclude' ) as $hook ) {
			add_filter( $hook, array( self::class, 'exclude_script_handles' ) );
		}
		add_filter( 'sgo_javascript_combine_excluded_inline_content', array( self::class, 'exclude_inline_config' ) );
		add_filter( 'sgo_javascript_combine_excluded_external_paths', array( self::class, 'exclude_script_files' ) );
		add_filter( 'sgo_javascript_combine_excluded_internal_paths', array( self::class, 'exclude_script_files' ) );

		add_filter( 'wphb_delay_js_exclusions', array( self::class, 'exclude_script_patterns' ) );
		add_filter( 'wphb_critical_css_exclusions', array( self::class, 'exclude_stylesheets' ) );
		foreach ( array( 'wphb_minify_resource', 'wphb_combine_resource', 'wphb_defer_resource', 'wphb_async_resource', 'wphb_inline_resource' ) as $hook ) {
			// Hummingbird applies the saved per-handle choices at priority 10.
			add_filter( $hook, array( self::class, 'hummingbird_resource' ), 20, 4 );
		}

		// Preserve tag boundaries so W3TC does not combine across these scripts.
		add_filter( 'w3tc_minify_js_do_tag_minification', array( self::class, 'w3tc_script' ), 20, 2 );
		add_filter( 'w3tc_minify_css_do_tag_minification', array( self::class, 'autoptimize_defer_stylesheet' ), 20, 2 );

		// FlyingPress publishes minification filters; delay exclusions are separate.
		add_filter( 'flying_press_exclude_from_minify:js', array( self::class, 'exclude_script_files' ) );
		add_filter( 'flying_press_exclude_from_minify:css', array( self::class, 'exclude_stylesheets' ) );

		// NitroPack itself uses this attribute for scripts that must run normally.
		add_filter( 'script_loader_tag', array( self::class, 'nitropack_script' ), 20, 3 );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'nitropack_inline_config' ) );
	}

	/**
	 * WP Rocket and Hummingbird interpret these lists as regular expressions.
	 *
	 * @param mixed $exclusions Existing regular expressions.
	 * @return mixed
	 */
	public static function exclude_script_patterns( $exclusions ) {
		return self::append( $exclusions, array_map( static fn( $item ) => preg_quote( $item, '#' ), self::SCRIPTS ) );
	}

	/**
	 * @param mixed $exclusions Existing inline-code fragments.
	 * @return mixed
	 */
	public static function exclude_inline_config( $exclusions ) {
		return self::append( $exclusions, array( 'ShootCalWebCalendarFront' ) );
	}

	/**
	 * @param mixed $exclusions Comma-separated stylesheet exclusions.
	 * @return mixed
	 */
	public static function autoptimize_stylesheets( $exclusions ) {
		return self::append_csv( $exclusions, self::STYLESHEETS );
	}

	/**
	 * @param mixed $exclusions CSV or a pattern-keyed array of optimization flags.
	 * @return mixed
	 */
	public static function autoptimize_scripts( $exclusions ) {
		if ( is_array( $exclusions ) ) {
			foreach ( self::SCRIPTS as $script ) {
				if ( ! array_key_exists( $script, $exclusions ) ) {
					$exclusions[ $script ] = '';
				}
			}
			return $exclusions;
		}
		return self::append_csv( $exclusions, self::SCRIPTS );
	}

	/**
	 * @param mixed $defer Whether Autoptimize should defer an excluded stylesheet.
	 * @param mixed $tag The stylesheet link tag.
	 * @return mixed
	 */
	public static function autoptimize_defer_stylesheet( $defer, $tag ) {
		if ( is_string( $tag ) && false !== strpos( $tag, self::STYLESHEETS[0] ) ) {
			return false;
		}
		return $defer;
	}

	/**
	 * @param mixed $minify Whether W3TC should process the script tag.
	 * @param mixed $tag The complete original script tag.
	 * @return mixed
	 */
	public static function w3tc_script( $minify, $tag ) {
		return self::contains( $tag, self::SCRIPTS ) ? false : $minify;
	}

	/**
	 * Keep only our resources out of Hummingbird's asset transformations.
	 *
	 * @param mixed $optimize Existing decision for this operation.
	 * @param mixed $handle WordPress asset handle.
	 * @param mixed $type Either scripts or styles.
	 * @param mixed $url Original resource URL.
	 * @return mixed
	 */
	public static function hummingbird_resource( $optimize, $handle, $type, $url ) {
		if ( 'scripts' === $type && ( in_array( $handle, self::SCRIPT_HANDLES, true ) || self::contains( $url, self::script_files() ) ) ) {
			return false;
		}
		if ( 'styles' === $type && ( in_array( $handle, self::STYLE_HANDLES, true ) || self::contains( $url, self::STYLESHEETS ) ) ) {
			return false;
		}
		return $optimize;
	}

	/**
	 * @param mixed $tag Enqueued script markup.
	 * @param mixed $handle WordPress script handle.
	 * @param mixed $src Script source URL.
	 * @return mixed
	 */
	public static function nitropack_script( $tag, $handle, $src ) {
		if ( ! is_string( $tag ) || ( ! in_array( $handle, self::SCRIPT_HANDLES, true ) && ! self::contains( $src, self::script_files() ) ) ) {
			return $tag;
		}
		$processor = new \WP_HTML_Tag_Processor( $tag );
		while ( $processor->next_tag( array( 'tag_name' => 'SCRIPT' ) ) ) {
			// A loader tag can also contain before/after inline code. Protect the
			// external asset here; localized configuration has its own WP filter.
			if ( null !== $processor->get_attribute( 'src' ) ) {
				$processor->set_attribute( 'nitro-exclude', true );
				break;
			}
		}
		return $processor->get_updated_html();
	}

	/**
	 * @param mixed $attributes Inline script HTML attributes.
	 * @return mixed
	 */
	public static function nitropack_inline_config( $attributes ) {
		if ( is_array( $attributes ) && 'shootcal-web-calendar-js-extra' === ( $attributes['id'] ?? null ) ) {
			$attributes['nitro-exclude'] = true;
		}
		return $attributes;
	}

	/**
	 * @param mixed $exclusions Existing literal asset paths.
	 * @return mixed
	 */
	public static function exclude_assets( $exclusions ) {
		return self::append( $exclusions, array_merge( self::STYLESHEETS, self::script_files() ) );
	}

	/**
	 * @param mixed $exclusions Existing stylesheet handles.
	 * @return mixed
	 */
	public static function exclude_style_handles( $exclusions ) {
		return self::append( $exclusions, self::STYLE_HANDLES );
	}

	/**
	 * @param mixed $exclusions Existing script handles.
	 * @return mixed
	 */
	public static function exclude_script_handles( $exclusions ) {
		return self::append( $exclusions, self::SCRIPT_HANDLES );
	}

	/**
	 * @param mixed $exclusions Existing script URL fragments.
	 * @return mixed
	 */
	public static function exclude_script_files( $exclusions ) {
		return self::append( $exclusions, self::script_files() );
	}

	/**
	 * @return array Script paths without the inline configuration marker.
	 */
	private static function script_files(): array {
		return array_slice( self::SCRIPTS, 0, 3 );
	}

	/**
	 * @param mixed $content Resource URL or markup.
	 * @param array $fragments Literal paths or inline content to find.
	 * @return bool
	 */
	private static function contains( $content, array $fragments ): bool {
		if ( ! is_string( $content ) ) {
			return false;
		}
		foreach ( $fragments as $fragment ) {
			if ( false !== strpos( $content, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $exclusions Existing comma-separated exclusions.
	 * @param array $required Our own narrow exclusions.
	 * @return mixed
	 */
	private static function append_csv( $exclusions, array $required ) {
		if ( ! is_string( $exclusions ) ) {
			return $exclusions;
		}
		$existing = array_map( 'trim', explode( ',', $exclusions ) );
		foreach ( $required as $item ) {
			if ( ! in_array( $item, $existing, true ) ) {
				$exclusions .= ( '' === trim( $exclusions ) ? '' : ',' ) . $item;
				$existing[] = $item;
			}
		}
		return $exclusions;
	}

	/**
	 * @param mixed $exclusions Existing stylesheet URL fragments.
	 * @return mixed
	 */
	public static function exclude_stylesheets( $exclusions ) {
		return self::append( $exclusions, self::STYLESHEETS );
	}

	/**
	 * Keep the inline AJAX configuration together with its consumer script.
	 *
	 * @param mixed $exclusions Existing script URL or inline-code fragments.
	 * @return mixed
	 */
	public static function exclude_scripts( $exclusions ) {
		return self::append( $exclusions, self::SCRIPTS );
	}

	/**
	 * @param mixed $exclusions The optimizer's existing exclusion list.
	 * @param array $required Our own narrow exclusions.
	 * @return mixed
	 */
	private static function append( $exclusions, array $required ) {
		if ( ! is_array( $exclusions ) ) {
			return $exclusions;
		}
		foreach ( $required as $item ) {
			if ( ! in_array( $item, $exclusions, true ) ) {
				$exclusions[] = $item;
			}
		}
		return $exclusions;
	}
}
