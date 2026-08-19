<?php
/**
 * Plugin updates from GitHub releases.
 *
 * Mirrors the OC Reviews updater: it reads the latest GitHub release, looks for a
 * built `oc-story.zip` asset, and hands WordPress a package to install. Works
 * with a public repository out of the box; for a private repository it reads a
 * token from wp-config.php (constant OCS_UPDATE_TOKEN, or the shared
 * OC_UPDATE_TOKEN) and downloads the private asset correctly via the GitHub
 * asset API. The token is never stored in this repository or in the database.
 *
 * @package OC_Story
 */

namespace OCS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub releases and injects updates into wp-admin.
 */
final class Updater {

	private const TRANSIENT = 'ocs_release';
	private const TTL       = 6 * HOUR_IN_SECONDS;
	private const API       = 'https://api.github.com/repos/%s/releases/latest';

	/**
	 * Plugin basename, e.g. "oc-story/oc-story.php".
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin directory slug, e.g. "oc-story".
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * GitHub "owner/repo".
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Store the update source.
	 *
	 * @param string $basename Plugin basename.
	 * @param string $version  Installed version.
	 * @param string $repo     GitHub owner/repo.
	 */
	public function __construct( string $basename, string $version, string $repo ) {
		$this->basename = $basename;
		$this->slug     = dirname( $basename );
		$this->version  = $version;
		$this->repo     = (string) apply_filters( 'ocs_update_repo', $repo );
	}

	/**
	 * Register hooks. Admin only — never costs a front-end request.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 2 );

		// Private-repo asset downloads need the token and the octet-stream Accept
		// header, and GitHub's redirect to S3 must not carry the auth header. This
		// short-circuits the download to do both correctly. It no-ops on public
		// repos (no token) and for any package that is not our private asset.
		add_filter( 'upgrader_pre_download', array( $this, 'auth_download' ), 10, 3 );
	}

	/**
	 * Add our plugin to WordPress's update list when a newer release exists.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $transient;
		}

		if ( version_compare( $release['version'], $this->version, '<=' ) ) {
			return $transient;
		}

		$item = (object) array(
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $this->basename ] = $item;

		return $transient;
	}

	/**
	 * Populate the "View details" modal.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'OC Story',
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://originalconcepts.co.il">Original Concepts</a>',
			'homepage'      => 'https://originalconcepts.co.il/oc-story',
			'requires'      => $release['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $release['requires_php'],
			'last_updated'  => $release['date'],
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Shoppable video and stories for WooCommerce: Instagram-style circles, sliders and product-page video with tagged products.', 'oc-story' ),
				'changelog'   => '' !== $release['changelog']
					? wp_kses_post( wpautop( $release['changelog'] ) )
					: esc_html__( 'See the release notes on GitHub.', 'oc-story' ),
			),
		);
	}

	/**
	 * Drop the cached release after any update runs.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $data     Update context.
	 */
	public function flush( $upgrader, $data ): void {
		if ( isset( $data['type'] ) && 'plugin' === $data['type'] ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/**
	 * Download a private release asset with authentication.
	 *
	 * WordPress downloads `package` with a plain request that carries no token,
	 * so a private asset would 404. We take over: ask the asset API for the
	 * redirect to the signed URL (with the token and octet-stream Accept), then
	 * download that signed URL with no auth header (S3 rejects a second one).
	 *
	 * @param bool|\WP_Error $reply    Short-circuit value (false to continue).
	 * @param string         $package  Package URL.
	 * @param \WP_Upgrader   $upgrader Upgrader instance.
	 * @return bool|string|\WP_Error Temp file path, WP_Error, or $reply untouched.
	 */
	public function auth_download( $reply, $package, $upgrader ) {
		$token  = $this->token();
		$prefix = 'https://api.github.com/repos/' . $this->repo . '/releases/assets/';

		if ( false !== $reply || '' === $token || ! is_string( $package ) || 0 !== strpos( $package, $prefix ) ) {
			return $reply;
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Accept'        => 'application/octet-stream',
					'Authorization' => 'Bearer ' . $token,
					'User-Agent'    => 'oc-story/' . $this->version,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( in_array( (int) $code, array( 301, 302, 307, 308 ), true ) && $location ) {
			// download_url() streams to a temp file with no auth header.
			return download_url( $location );
		}

		if ( 200 === (int) $code ) {
			$body = wp_remote_retrieve_body( $response );
			if ( '' === $body ) {
				return new \WP_Error( 'ocs_update_empty', __( 'The update package was empty.', 'oc-story' ) );
			}
			$tmp = wp_tempnam( $this->slug . '.zip' );
			if ( ! $tmp ) {
				return new \WP_Error( 'ocs_update_tmp', __( 'Could not create a temporary file for the update.', 'oc-story' ) );
			}
			file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return $tmp;
		}

		return new \WP_Error(
			'ocs_update_download',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'Could not download the update from GitHub (HTTP %d).', 'oc-story' ),
				(int) $code
			)
		);
	}

	/**
	 * Latest release, cached. Returns null when unavailable — a GitHub outage
	 * must never block wp-admin.
	 *
	 * @return array<string,string>|null
	 */
	private function latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( self::API, $this->repo ),
			array(
				'timeout' => 8,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a broken token does not hammer the API.
			set_site_transient( self::TRANSIENT, 'none', 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		$token       = $this->token();
		$package     = '';
		$browser_url = '';
		$asset_api   = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'] ) && $this->slug . '.zip' === $asset['name'] ) {
				$browser_url = (string) ( $asset['browser_download_url'] ?? '' );
				$asset_api   = (string) ( $asset['url'] ?? '' );
				break;
			}
		}

		// Private repos must download through the asset API (with the token);
		// public repos use the direct browser URL.
		$package = ( '' !== $token && '' !== $asset_api ) ? $asset_api : $browser_url;

		if ( '' === $package ) {
			return null;
		}

		$release = array(
			'version'      => ltrim( (string) $body['tag_name'], 'v' ),
			'package'      => $package,
			'url'          => (string) ( $body['html_url'] ?? '' ),
			'changelog'    => (string) ( $body['body'] ?? '' ),
			'date'         => (string) ( $body['published_at'] ?? '' ),
			'tested'       => '',
			'requires'     => '',
			'requires_php' => '',
		);

		set_site_transient( self::TRANSIENT, $release, self::TTL );

		return $release;
	}

	/**
	 * Request headers. A token is read from wp-config.php when the repository is
	 * private — it is never stored in this repository or in the database.
	 *
	 * @return array<string,string>
	 */
	private function headers(): array {
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'oc-story/' . $this->version,
		);

		$token = $this->token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * The update token, if defined. Prefers a plugin-specific constant, then the
	 * shared OC constant used by the theme, then a filter.
	 *
	 * @return string
	 */
	private function token(): string {
		$token = '';
		if ( defined( 'OCS_UPDATE_TOKEN' ) && is_string( OCS_UPDATE_TOKEN ) ) {
			$token = OCS_UPDATE_TOKEN;
		} elseif ( defined( 'OC_UPDATE_TOKEN' ) && is_string( OC_UPDATE_TOKEN ) ) {
			$token = OC_UPDATE_TOKEN;
		}

		return (string) apply_filters( 'ocs_update_token', $token );
	}
}
