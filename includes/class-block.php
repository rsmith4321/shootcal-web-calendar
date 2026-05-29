<?php
/**
 * Gutenberg block registration for ShootCal Availability.
 *
 * The block is server-rendered: its `render_callback` delegates to the existing
 * Shortcode::render() so the block and `[shootcal_availability]` produce
 * identical HTML and share the same feed-fetch + transient cache + parser path.
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class Block {

	private const BLOCK_NAME    = 'shootcal-availability/calendar';
	private const EDITOR_SCRIPT = 'shootcal-availability-block-editor';

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		// Pre-register the editor script with explicit dependencies so we do not
		// need a build pipeline (no .asset.php file). The script is vanilla JS,
		// no JSX, no bundler.
		wp_register_script(
			self::EDITOR_SCRIPT,
			PLUGIN_URL . 'assets/js/block-editor.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
			),
			VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_SCRIPT, 'shootcal-availability' );

		register_block_type(
			PLUGIN_DIR . 'blocks/calendar',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Server-side render callback. Maps block attributes (camelCase) to the
	 * snake_case attribute names the shortcode expects, then delegates.
	 *
	 * @param array<string,mixed> $attributes
	 */
	public function render( array $attributes, string $content = '', $block = null ): string {
		$atts = array();

		if ( isset( $attributes['months'] ) && (int) $attributes['months'] > 0 ) {
			$atts['months'] = (string) (int) $attributes['months'];
		}
		if ( isset( $attributes['firstDay'] ) ) {
			$atts['first_day'] = (string) (int) $attributes['firstDay'];
		}
		if ( ! empty( $attributes['timezone'] ) && is_string( $attributes['timezone'] ) ) {
			$atts['timezone'] = $attributes['timezone'];
		}

		$shortcode = new Shortcode();
		$html      = $shortcode->render( $atts );

		// Ensure the frontend CSS + JS load on this page too. The Assets class
		// handles the shortcode path via the_content filter; in block contexts
		// (FSE templates, query loops, etc.) the_content might not be the carrier,
		// so register directly here too.
		wp_enqueue_style( 'shootcal-availability' );
		wp_enqueue_script( 'shootcal-availability' );

		return $html;
	}
}
