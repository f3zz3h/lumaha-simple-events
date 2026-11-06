<?php
/**
 * Plugin Name:       Lumaha Simple Event Tickets: EventPrime Migrator
 * Plugin URI:        https://github.com/f3zz3h/lumaha-simple-events
 * Description:       One-off tool. Adds the WP-CLI command <code>wp lset migrate-eventprime</code>, which copies EventPrime events, tickets and bookings into Lumaha Simple Event Tickets. Deactivate and delete it once the move is done.
 * Version:           0.1.0
 * Author:            Lumaha
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce, lumaha-simple-event-tickets
 * Text Domain:       lumaha-set-eventprime-migrator
 *
 * It only adds a command line tool: nothing runs on page loads, and it stores nothing of its
 * own. Removing it leaves the copied events and bookings as they are.
 *
 * @package Lumaha_SET_EventPrime_Migrator
 */

defined( 'ABSPATH' ) || exit;

define( 'LSET_MIGRATOR_VERSION', '0.1.0' );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'Lumaha_Simple_Event_Tickets' ) || ! class_exists( 'WooCommerce' ) ) {
			return; // WordPress shows "requires" on the Plugins page; nothing to do without them.
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/includes/class-lset-migrate.php';
			LSET_Migrate::register();
		}
	},
	20
);

// A reminder on the Plugins page that this is a use-once tool.
add_filter(
	'plugin_row_meta',
	function ( $meta, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$meta[] = esc_html__( 'One-off tool: after migrating, deactivate and delete it.', 'lumaha-set-eventprime-migrator' );
		}
		return $meta;
	},
	10,
	2
);
