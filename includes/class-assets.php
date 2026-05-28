<?php
/**
 * Conditional asset loading for the shortcode.
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class Assets {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend' ) );
		add_filter( 'the_content', array( $this, 'maybe_enqueue_for_content' ), 1 );
	}

	public function register_frontend(): void {
		wp_register_style(
			'shootcal-availability',
			PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			VERSION
		);
		wp_register_script(
			'shootcal-availability',
			PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
	}

	/**
	 * Enqueue assets only when the shortcode is present in the rendered content.
	 *
	 * @param string $content
	 * @return string Unmodified content.
	 */
	public function maybe_enqueue_for_content( $content ) {
		if ( is_string( $content ) && has_shortcode( $content, Shortcode::TAG ) ) {
			wp_enqueue_style( 'shootcal-availability' );
			wp_enqueue_script( 'shootcal-availability' );
		}
		return $content;
	}
}
