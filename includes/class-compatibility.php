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
	}

	/**
	 * WP Rocket interprets these lists as regular expressions.
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
