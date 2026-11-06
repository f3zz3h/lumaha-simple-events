<?php
/**
 * Tables, capability, the hidden ticket product and scheduled tasks.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Install {

	const OPTION_DB      = 'lset_db_version';
	const OPTION_PRODUCT = 'lset_product_id';
	const CRON_HOLDS     = 'lset_clear_holds';

	/** Table names, with the site's prefix. */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lset_' . $name;
	}

	public static function activate() {
		$from = (int) get_option( self::OPTION_DB );
		self::create_tables();
		self::add_caps();
		if ( $from && $from < 2 ) {
			self::upgrade_2();
		}
		if ( $from && $from < 3 ) {
			self::upgrade_3();
		}
		update_option( self::OPTION_DB, LSET_DB_VERSION );
		if ( class_exists( 'WooCommerce' ) && did_action( 'woocommerce_init' ) ) {
			self::product_id(); // Otherwise made on first use.
		}
		self::schedule();
		// Rewrite rules for /events/<slug>/ are flushed once the post type is registered.
		update_option( 'lset_flush_rewrites', 1 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOLDS );
		flush_rewrite_rules();
	}

	/** Runs on every load: cheap version check, so updates from the zip upgrade the tables too. */
	public static function maybe_upgrade() {
		$from = (int) get_option( self::OPTION_DB );
		if ( $from !== LSET_DB_VERSION ) {
			self::create_tables();
			self::add_caps();
			if ( $from && $from < 2 ) {
				self::upgrade_2();
			}
			if ( $from && $from < 3 ) {
				self::upgrade_3();
			}
			update_option( self::OPTION_DB, LSET_DB_VERSION );
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON_HOLDS, array( 'LSET_Capacity', 'clear_expired' ) );
	}

	/** Version 2: the on-sale window moved from each ticket to the event. */
	private static function upgrade_2() {
		$ids = get_posts(
			array(
				'post_type'      => LSET_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			LSET_Store::sale_window_from_tickets( $id );
		}
	}

	/** Version 3: tickets with QR codes. Give every attendee already booked a ticket code. */
	private static function upgrade_3() {
		LSET_Tickets::ensure_codes( '1 = %d', array( 1 ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['lset_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'lumaha-simple-event-tickets' ),
		);
		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOLDS ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'lset_five_minutes', self::CRON_HOLDS );
		}
	}

	public static function add_caps() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			foreach ( array( LSET_CAP_ATTENDEES, LSET_CAP_CHECKIN ) as $cap ) {
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();

		// dbDelta wants two spaces after PRIMARY KEY and one field per line.
		$sql = array();

		$sql[] = 'CREATE TABLE ' . self::table( 'tickets' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
event_id bigint(20) unsigned NOT NULL,
group_id bigint(20) unsigned NOT NULL DEFAULT 0,
name varchar(200) NOT NULL DEFAULT '',
description text NULL,
price decimal(10,2) NOT NULL DEFAULT 0.00,
capacity int(11) NULL,
sale_start_mode varchar(10) NOT NULL DEFAULT '',
sale_start datetime NULL,
sale_start_days int(11) NULL,
sale_end datetime NULL,
min_qty int(11) NOT NULL DEFAULT 0,
max_qty int(11) NOT NULL DEFAULT 0,
sort_order int(11) NOT NULL DEFAULT 0,
hidden tinyint(1) NOT NULL DEFAULT 0,
show_left_below int(11) NULL,
ep_id bigint(20) unsigned NULL,
PRIMARY KEY  (id),
KEY event_id (event_id),
KEY ep_id (ep_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'ticket_groups' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
event_id bigint(20) unsigned NOT NULL,
name varchar(200) NOT NULL DEFAULT '',
capacity int(11) NULL,
sort_order int(11) NOT NULL DEFAULT 0,
ep_id bigint(20) unsigned NULL,
PRIMARY KEY  (id),
KEY event_id (event_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'fields' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
label varchar(200) NOT NULL DEFAULT '',
type varchar(20) NOT NULL DEFAULT 'text',
options text NULL,
placeholder varchar(200) NOT NULL DEFAULT '',
sort_order int(11) NOT NULL DEFAULT 0,
ep_id bigint(20) unsigned NULL,
PRIMARY KEY  (id)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'bookings' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
event_id bigint(20) unsigned NOT NULL,
ticket_id bigint(20) unsigned NOT NULL,
order_id bigint(20) unsigned NOT NULL DEFAULT 0,
order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
qty int(11) NOT NULL DEFAULT 0,
refunded_qty int(11) NOT NULL DEFAULT 0,
unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
line_total decimal(10,2) NOT NULL DEFAULT 0.00,
status varchar(20) NOT NULL DEFAULT 'pending',
source varchar(20) NOT NULL DEFAULT 'checkout',
hold_until datetime NULL,
buyer_name varchar(200) NOT NULL DEFAULT '',
buyer_email varchar(200) NOT NULL DEFAULT '',
buyer_phone varchar(50) NOT NULL DEFAULT '',
booking_fields longtext NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
ep_booking_id bigint(20) unsigned NULL,
PRIMARY KEY  (id),
KEY event_id (event_id),
KEY ticket_id (ticket_id),
KEY order_id (order_id),
KEY order_item_id (order_item_id),
KEY buyer_email (buyer_email),
KEY ep_booking_id (ep_booking_id)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'attendees' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
event_id bigint(20) unsigned NOT NULL,
ticket_id bigint(20) unsigned NOT NULL,
seq int(11) NOT NULL DEFAULT 1,
first_name varchar(100) NOT NULL DEFAULT '',
last_name varchar(100) NOT NULL DEFAULT '',
fields longtext NULL,
ticket_code varchar(20) NOT NULL DEFAULT '',
checked_in_at datetime NULL,
anonymised_at datetime NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id),
KEY event_id (event_id),
KEY ticket_code (ticket_code)
) $c;";

		$sql[] = 'CREATE TABLE ' . self::table( 'holds' ) . " (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
uid varchar(40) NOT NULL,
event_id bigint(20) unsigned NOT NULL,
ticket_id bigint(20) unsigned NOT NULL,
qty int(11) NOT NULL DEFAULT 0,
session_key varchar(100) NOT NULL DEFAULT '',
expires_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uid (uid),
KEY event_id (event_id),
KEY expires_at (expires_at)
) $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * The one hidden virtual product every ticket goes into the basket as. Made on first use,
	 * and made again if someone deletes it.
	 */
	public static function product_id() {
		$id      = (int) get_option( self::OPTION_PRODUCT );
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && 'trash' !== $product->get_status() ) {
			return $id;
		}
		$product = new WC_Product_Simple();
		$product->set_name( __( 'Event ticket', 'lumaha-simple-event-tickets' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_regular_price( '0' );
		$product->set_sold_individually( false );
		$product->set_manage_stock( false );
		$product->set_reviews_allowed( false );
		$product->set_description( __( 'Used by Lumaha Simple Event Tickets for every event ticket. Please don\'t delete or edit it.', 'lumaha-simple-event-tickets' ) );
		$product->update_meta_data( '_lset_ticket_product', 1 );
		$id = $product->save();
		update_option( self::OPTION_PRODUCT, $id );
		return (int) $id;
	}
}
