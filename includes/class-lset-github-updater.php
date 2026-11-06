<?php
/**
 * Updates from GitHub releases, for copies installed from the GitHub zip.
 *
 * Copied from Lumaha Simple Raffle Tickets.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Offers the latest GitHub release as a normal plugin update (Dashboard → Updates, the Plugins
 * screen, "View details" and automatic updates), using the plugin header's `Update URI`.
 */
class LSET_GitHub_Updater {

	const REPO      = 'f3zz3h/lumaha-simple-events';
	const SLUG      = 'lumaha-simple-event-tickets';
	const CACHE     = 'lset_github_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'keep_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
		// Before WordPress's own update check on that screen (priority 10).
		add_action( 'load-update-core.php', array( __CLASS__, 'forget_on_check_again' ), 1 );
	}

	/**
	 * Dashboard → Updates → "Check again" asks GitHub again too, instead of using the cached release.
	 */
	public static function forget_on_check_again() {
		if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress's own link, no change of state.
			self::forget();
		}
	}

	/**
	 * The latest published release: version, zip and notes. Cached for 12 hours (1 hour after a failure),
	 * so update checks don't use up GitHub's limit of 60 unauthenticated requests an hour.
	 *
	 * @return array{version: string, package: string, url: string, notes: string, date: string}|null
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );

		if ( array() === $cached ) {
			return null; // A recent failure: don't ask again yet.
		}

		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'], $cached['url'], $cached['notes'], $cached['date'] ) ) {
			return $cached;
		}

		$release  = null;
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LumahaSimpleEventTickets/' . Lumaha_Simple_Event_Tickets::VERSION . '; ' . home_url(),
				),
			)
		);

		if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data    = json_decode( wp_remote_retrieve_body( $response ), true );
			$version = is_array( $data ) && isset( $data['tag_name'] ) ? ltrim( (string) $data['tag_name'], 'vV' ) : '';
			$package = '';

			foreach ( is_array( $data ) && isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::SLUG . '-' . $version . '.zip' === $asset['name'] ) {
					$package = (string) $asset['browser_download_url'];
				}
			}

			if ( $version && $package && empty( $data['draft'] ) && empty( $data['prerelease'] ) ) {
				$release = array(
					'version' => $version,
					'package' => $package,
					'url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : 'https://github.com/' . self::REPO . '/releases',
					'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
					'date'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
				);
			}
		}

		set_site_transient( self::CACHE, $release ? $release : array(), $release ? self::CACHE_TTL : HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Forget the cached release (after an update, so the next check is fresh).
	 */
	public static function forget() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * Answer WordPress's update check for this plugin (`update_plugins_{hostname}` for the `Update URI` host).
	 * WordPress compares the version itself and only offers it if it's newer.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin header data.
	 * @param string      $plugin_file Plugin file, relative to the plugins folder.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( LSET_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest();

		if ( ! $release ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * "View details" for the update: version, release date and the release notes.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action plugins_api action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Lumaha Simple Event Tickets',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://www.lumaha.uk/">Lumaha</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'last_updated'  => $release['date'],
			'sections'      => array(
				'changelog' => wpautop( make_clickable( esc_html( $release['notes'] ) ) ) . '<p><a href="' . esc_url( $release['url'] ) . '">' . esc_html__( 'Release on GitHub', 'lumaha-simple-event-tickets' ) . '</a></p>',
			),
		);
	}

	/**
	 * Install the update into the plugin's existing folder: the zip's folder is always
	 * `lumaha-simple-event-tickets/`, but a site may have it in a folder with another name.
	 *
	 * @param string|WP_Error $source        Unpacked folder.
	 * @param string          $remote_source Folder it was unpacked into.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $hook_extra    Details of the upgrade.
	 * @return string|WP_Error
	 */
	public function keep_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		$basename = plugin_basename( LSET_PLUGIN_FILE );

		if ( is_wp_error( $source ) || ! isset( $hook_extra['plugin'] ) || $basename !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . dirname( $basename ) . '/';

		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}

		return $wp_filesystem->move( $source, $wanted ) ? $wanted : $source;
	}
}

add_action(
	'plugins_loaded',
	static function () {
		new LSET_GitHub_Updater();
	}
);
