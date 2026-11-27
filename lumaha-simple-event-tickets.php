<?php
/**
 * Plugin Name:       Lumaha Simple Event Tickets
 * Plugin URI:        https://github.com/f3zz3h/lumaha-simple-events
 * Description:       School events with tickets sold through the normal WooCommerce checkout. Attendee details per ticket, capacity with short holds, and an attendee list, CSV and printable register for the night.
 * Version:           0.3.0
 * Author:            Lumaha
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       lumaha-simple-event-tickets
 * Domain Path:       /languages
 * Update URI:        https://github.com/f3zz3h/lumaha-simple-events
 *
 * How it fits together:
 * - Events are a post type (lset_event), venues a taxonomy (lset_venue).
 * - Tickets, ticket groups and the checkout field library are rows in our own tables.
 * - Every ticket goes in the basket as one hidden virtual product ("Event ticket"), with
 *   the ticket and attendee details in the cart item. Woo's checkout and gateways do the rest.
 * - Bookings and attendees are copied into our own tables from the order, and follow its status.
 * - Each attendee gets a ticket with a QR code, emailed when the order is paid and scanned at the door.
 */

defined( 'ABSPATH' ) || exit;

define( 'LSET_VERSION', '0.3.0' );
define( 'LSET_PLUGIN_FILE', __FILE__ );
define( 'LSET_DIR', __DIR__ . '/' );
define( 'LSET_URL', plugin_dir_url( __FILE__ ) );
define( 'LSET_DB_VERSION', 3 );
define( 'LSET_CPT', 'lset_event' );
define( 'LSET_VENUE', 'lset_venue' );
define( 'LSET_CAP_ATTENDEES', 'lset_view_attendees' );
define( 'LSET_CAP_CHECKIN', 'lset_check_in' );

// lset-github-updater:start (the WordPress.org build removes this block, the file and the Update URI header; it updates from WordPress.org).
// Updates from GitHub releases (starts itself), for copies installed from the GitHub release zip.
if ( file_exists( __DIR__ . '/includes/class-lset-github-updater.php' ) ) {
	require_once __DIR__ . '/includes/class-lset-github-updater.php';
}
// lset-github-updater:end

require_once LSET_DIR . 'includes/class-lset-time.php';
require_once LSET_DIR . 'includes/class-lset-install.php';
require_once LSET_DIR . 'includes/class-lset-store.php';
require_once LSET_DIR . 'includes/class-lset-capacity.php';
require_once LSET_DIR . 'includes/class-lset-events.php';
require_once LSET_DIR . 'includes/class-lset-admin.php';
require_once LSET_DIR . 'includes/class-lset-frontend.php';
require_once LSET_DIR . 'includes/class-lset-gallery.php';
require_once LSET_DIR . 'includes/class-lset-cart.php';
require_once LSET_DIR . 'includes/class-lset-orders.php';
require_once LSET_DIR . 'includes/class-lset-attendees.php';
require_once LSET_DIR . 'includes/class-lset-qr.php';
require_once LSET_DIR . 'includes/class-lset-tickets.php';

/**
 * Small entry point. VERSION is read by the release build (and the GitHub updater in GitHub copies).
 */
final class LSET_Plugin {

	const VERSION = '0.3.0';

	/** Plugin settings with their defaults. */
	public static function settings() {
		$defaults = array(
			'hold_minutes'     => 15,
			'auto_complete'    => 1,
			'event_slug'       => 'events',
			'event_sidebar'    => 0, // Event pages full width by default.
			'delete_on_remove' => 0,
			'ticket_emails'    => 1,
			'ticket_colour'    => '#1d3557',
			'ticket_logo'      => '',
			'ticket_note'      => '',
		);
		$saved = get_option( 'lset_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function init() {
		LSET_Install::maybe_upgrade();
		LSET_Events::init();
		LSET_Admin::init();
		LSET_Frontend::init();
		LSET_Gallery::init();
		LSET_Cart::init();
		LSET_Orders::init();
		LSET_Attendees::init();
		LSET_Tickets::init();
		// Translations load on their own (WordPress 4.6+), from translate.wordpress.org.
	}

	/**
	 * An array posted by one of the plugin's forms, unslashed and sanitised on receipt: every value
	 * through sanitize_textarea_field() (plain text, line breaks kept), every key through sanitize_key().
	 * Callers check the nonce and capability first, then give each value its own stricter sanitiser.
	 *
	 * @param string $key The $_POST key.
	 * @return array
	 */
	public static function posted_array( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify the nonce (see the docblock).
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by self::clean_array().
		return self::clean_array( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Sanitise a nested array: keys with sanitize_key(), values with sanitize_textarea_field().
	 *
	 * @param array $in Unslashed input.
	 * @return array
	 */
	public static function clean_array( array $in ) {
		$out = array();
		foreach ( $in as $k => $v ) {
			$k         = is_int( $k ) ? $k : sanitize_key( $k );
			$out[ $k ] = is_array( $v ) ? self::clean_array( $v ) : sanitize_textarea_field( (string) $v );
		}
		return $out;
	}
}

register_activation_hook( __FILE__, array( 'LSET_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LSET_Install', 'deactivate' ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'WooCommerce' ) ) {
		LSET_Plugin::init();
	}
} );
