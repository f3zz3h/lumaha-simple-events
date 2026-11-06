<?php
/**
 * Plugin Name:       Lumaha Simple Event Tickets
 * Plugin URI:        https://github.com/f3zz3h/lumaha-simple-events
 * Description:       School events with tickets sold through the normal WooCommerce checkout. Attendee details per ticket, capacity with short holds, and an attendee list, CSV and printable register for the night.
 * Version:           0.2.1
 * Author:            Lumaha
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       lumaha-simple-event-tickets
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

define( 'LSET_VERSION', '0.2.1' );
define( 'LSET_PLUGIN_FILE', __FILE__ );
define( 'LSET_DIR', __DIR__ . '/' );
define( 'LSET_URL', plugin_dir_url( __FILE__ ) );
define( 'LSET_DB_VERSION', 3 );
define( 'LSET_CPT', 'lset_event' );
define( 'LSET_VENUE', 'lset_venue' );
define( 'LSET_CAP_ATTENDEES', 'lset_view_attendees' );
define( 'LSET_CAP_CHECKIN', 'lset_check_in' );

// lset-github-updater:start
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
 * Small entry point. VERSION is read by the release build and the GitHub updater.
 */
final class Lumaha_Simple_Event_Tickets {

	const VERSION = '0.2.1';

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
		add_action( 'init', function () {
			load_plugin_textdomain( 'lumaha-simple-event-tickets', false, dirname( plugin_basename( LSET_PLUGIN_FILE ) ) . '/languages' );
		}, 1 );
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
		Lumaha_Simple_Event_Tickets::init();
	}
} );
