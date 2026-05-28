<?php
/**
 * GitHub Releases auto-updater.
 *
 * Hooks WordPress's plugin-update transient so a newer release published on
 * the plugin's GitHub repo shows up in wp-admin with the standard
 * "Update available" banner + one-click upgrade flow - same UX as a WP.org
 * plugin, without listing on WP.org.
 *
 * How it works:
 *   1. WP periodically refreshes the update_plugins transient (~12h).
 *   2. We hook pre_set_site_transient_update_plugins, query the GitHub
 *      Releases API for the latest tag on this repo, compare with our
 *      installed VERSION constant, and inject an update entry if newer.
 *   3. We hook plugins_api so WP's "View details" lightbox pulls release
 *      notes from the GitHub release body instead of trying WP.org.
 *   4. Result cached server-side for 12h so we don't hammer GitHub
 *      (which rate-limits unauthenticated calls to 60/hour anyway).
 *
 * The download_url we hand WP is the ZIP ASSET attached to the release
 * (not GitHub's auto-generated "Source code" ZIP, which unpacks into a
 * tag-named folder and breaks the plugin path). The release workflow
 * always attaches a `shootcal-availability-X.Y.Z.zip` built with the
 * correct top-level folder.
 *
 * @package ShootCalAvailability
 */

declare( strict_types=1 );

namespace ShootCalAvailability;

defined( 'ABSPATH' ) || exit;

class Updater {

	private const GITHUB_OWNER = 'rsmith4321';
	private const GITHUB_REPO  = 'shootcal-availability';
	private const CACHE_KEY    = 'shootcal_availability_github_release';
	private const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	private const ERROR_TTL    = HOUR_IN_SECONDS; // shorter cache when a fetch fails
	private const USER_AGENT   = 'ShootCal-Availability-WP-Plugin';

	private string $plugin_basename;
	private string $plugin_slug;
	private string $current_version;

	public function __construct() {
		// e.g. "shootcal-availability/shootcal-availability.php"
		$this->plugin_basename = plugin_basename( PLUGIN_FILE );
		// e.g. "shootcal-availability"
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->current_version = VERSION;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		// Clear our cache after WP successfully updates anything, so the next
		// "Check Again" reflects the new state instantly.
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Inject our update entry into the update_plugins transient if GitHub
	 * has a newer release than what's installed.
	 *
	 * @param object|false $transient
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		// WP populates ->checked once it's done scanning installed plugins.
		// If empty, this filter is firing too early - skip.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_latest_release();
		if ( null === $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->current_version, '<=' ) ) {
			// No update or we're already ahead (dev builds).
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'id'           => $this->plugin_basename,
			'slug'         => $this->plugin_slug,
			'plugin'       => $this->plugin_basename,
			'new_version'  => $release['version'],
			'url'          => $release['html_url'],
			'package'      => $release['download_url'],
			'tested'       => '6.8',
			'requires_php' => '8.0',
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
		);

		return $transient;
	}

	/**
	 * Feed the "View details" lightbox in wp-admin > Plugins.
	 *
	 * Without this, WP tries to look us up on WP.org (where we aren't
	 * listed) and shows a confusing error in the modal. We short-circuit
	 * with release info from GitHub instead.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'              => 'ShootCal Availability',
			'slug'              => $this->plugin_slug,
			'version'           => $release['version'],
			'author'            => '<a href="https://shootcal.app">Ryan Smith</a>',
			'homepage'          => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'short_description' => __( 'Show your photography availability on WordPress as a month grid.', 'shootcal-availability' ),
			'sections'          => array(
				'description' => __( 'Show your photography availability on WordPress as a month grid. Reads a private iCal URL or ShootCal feed URL server-side and exposes busy days without revealing event details.', 'shootcal-availability' ),
				'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ) . '</pre>',
			),
			'download_link'     => $release['download_url'],
			'last_updated'      => $release['published_at'],
			'requires'          => '6.4',
			'requires_php'      => '8.0',
			'tested'            => '6.8',
		);
	}

	/**
	 * Wipe the cached release so the next update check is fresh.
	 *
	 * @param mixed $upgrader
	 * @param mixed $hook_extra
	 */
	public function clear_cache( $upgrader = null, $hook_extra = null ): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Fetch + parse the latest release from GitHub. Cached for CACHE_TTL on
	 * success, ERROR_TTL on failure (so a flaky/rate-limited API doesn't get
	 * pounded on every admin page load).
	 *
	 * @return array{version:string, download_url:string, html_url:string, notes:string, published_at:string}|null
	 */
	private function fetch_latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			// Empty array sentinel = recent failure; keep returning null until
			// the short error cache expires.
			return empty( $cached ) ? null : $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_OWNER,
			self::GITHUB_REPO
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => self::USER_AGENT,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, array(), self::ERROR_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, array(), self::ERROR_TTL );
			return null;
		}

		// "v0.5.2" -> "0.5.2"
		$version = ltrim( (string) $body['tag_name'], 'vV' );

		// Pick the .zip asset attached to the release. We deliberately ignore
		// GitHub's auto-generated "Source code (zip)" because it unpacks into
		// a folder named after the tag, not the plugin slug.
		$download_url = '';
		if ( isset( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( str_ends_with( strtolower( (string) ( $asset['name'] ?? '' ) ), '.zip' ) ) {
					$download_url = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( '' === $download_url ) {
			// Release without a ZIP asset - skip it rather than pointing WP at
			// the unusable source ZIP.
			set_site_transient( self::CACHE_KEY, array(), self::ERROR_TTL );
			return null;
		}

		$result = array(
			'version'      => $version,
			'download_url' => $download_url,
			'html_url'     => (string) ( $body['html_url'] ?? '' ),
			'notes'        => (string) ( $body['body'] ?? '' ),
			'published_at' => (string) ( $body['published_at'] ?? '' ),
		);

		set_site_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}
}
