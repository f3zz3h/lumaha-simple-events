<?php
/**
 * The EventPrime migrator add-on (addons/lumaha-set-eventprime-migrator), run against a small
 * fake EventPrime install.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Migrate extends LSET_Test_Case {

	private $ep_event;
	private $ep_booking;
	private $ep_cancelled;
	private $order_id;
	private $ep_abandoned;
	private $lines = array();

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$p = $wpdb->prefix;
		// The test suite turns these into temporary tables, which roll back with each test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}eventprime_checkout_fields ( id bigint unsigned NOT NULL AUTO_INCREMENT, label varchar(255), type varchar(50), option_data text, PRIMARY KEY (id) ) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}eventprime_ticket_categories ( id bigint unsigned NOT NULL AUTO_INCREMENT, event_id bigint, name varchar(255), capacity int, priority int, PRIMARY KEY (id) ) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}em_price_options ( id bigint unsigned NOT NULL AUTO_INCREMENT, event_id bigint, category_id bigint, name varchar(255), description text, price decimal(10,2), capacity int, status varchar(20), min_ticket_no int, max_ticket_no int, priority int, booking_starts text, booking_ends text, show_remaining_tickets int, PRIMARY KEY (id) ) ENGINE=InnoDB" );

		// Venue (EventPrime is switched off, so its taxonomy isn't registered at run time).
		register_taxonomy( 'em_venue', 'em_event' );
		$venue = wp_insert_term( 'School Hall', 'em_venue' );
		update_term_meta( $venue['term_id'], 'em_address', 'Bristol Road, Bristol BS7 8XX' );
		update_term_meta( $venue['term_id'], 'em_postcode', 'BS7 8XX' );

		$wpdb->insert( "{$p}eventprime_checkout_fields", array( 'id' => 1, 'label' => 'Dietary needs', 'type' => 'text', 'option_data' => '' ) );
		$wpdb->insert( "{$p}eventprime_checkout_fields", array( 'id' => 2, 'label' => 'Year group', 'type' => 'radio', 'option_data' => '["Reception","Year 1"]' ) );

		$this->ep_event = self::factory()->post->create(
			array(
				'post_type'   => 'em_event',
				'post_status' => 'publish',
				'post_title'  => 'Summer Fair 2026',
			)
		);
		unregister_taxonomy( 'em_venue' );
		update_post_meta( $this->ep_event, 'em_venue', array( '0', (string) $venue['term_id'] ) ); // As EventPrime stores it.
		$meta = array(
			// As on the live site: the *_date stamps carry whatever time of day the event was saved
			// (here 15:05 BST, which a "+12 hours" guess would push into the next day); the
			// *_date_time stamps are the exact moments.
			'em_start_date'                     => gmmktime( 14, 5, 0, 7, 3, 2026 ),
			'em_start_time'                     => '12:00 PM',
			'em_end_date'                       => gmmktime( 14, 5, 0, 7, 3, 2026 ),
			'em_end_time'                       => '03:00 PM',
			'em_start_date_time'                => gmmktime( 11, 0, 0, 7, 3, 2026 ),
			'em_end_date_time'                  => gmmktime( 14, 0, 0, 7, 3, 2026 ),
			'em_enable_booking'                 => 'bookings_on',
			'em_event_checkout_attendee_fields' => array(
				'em_event_checkout_name'               => 1,
				'em_event_checkout_name_first_name'    => 1,
				'em_event_checkout_name_last_name'     => 1,
				'em_event_checkout_name_first_name_required' => 1,
				'em_event_checkout_fields_data'        => array( 1, 2 ),
				'em_event_checkout_fields_data_required' => array( 2 ),
			),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $this->ep_event, $k, $v );
		}

		$wpdb->insert( "{$p}eventprime_ticket_categories", array( 'id' => 5, 'event_id' => $this->ep_event, 'name' => 'Fair entry', 'capacity' => 50, 'priority' => 1 ) );
		$wpdb->insert( "{$p}em_price_options", array( 'id' => 10, 'event_id' => $this->ep_event, 'category_id' => 5, 'name' => 'Child', 'price' => 3.5, 'capacity' => 40, 'status' => '1', 'priority' => 1, 'booking_starts' => '{"booking_type":"relative_date","days":"14","days_option":"before","event_option":"event_start"}', 'booking_ends' => '{"booking_type":"event_date","event_option":"event_start"}' ) );
		$wpdb->insert( "{$p}em_price_options", array( 'id' => 11, 'event_id' => $this->ep_event, 'category_id' => 5, 'name' => 'Adult', 'price' => 0, 'capacity' => 0, 'status' => '1', 'priority' => 2, 'max_ticket_no' => 4 ) );

		// A paid booking with a Woo order, as EventPrime left it.
		$order = wc_create_order();
		$order->set_billing_email( 'parent@example.com' );
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Parent' );
		// One line per ticket type, Adult first, each with ep_tickets_data.tkt_id.
		foreach ( array( array( 11, 'Adult', 1, 0 ), array( 10, 'Child', 2, 7 ) ) as $l ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( 'Summer Fair 2026-' . $l[1] );
			$item->set_quantity( $l[2] );
			$item->set_total( $l[3] );
			$item->add_meta_data( 'ep_tickets_data', array( 'id' => 2407, 'tkt_id' => $l[0], 'ticket_name' => $l[1], 'event_id' => 1, 'qty' => $l[2] ), true );
			$order->add_item( $item );
		}
		$order->set_total( 7 );
		$order->set_status( 'completed' );
		$order->save();
		$this->order_id = $order->get_id();
		foreach ( $order->get_items() as $iid => $item ) {
			$this->lines[ $item->get_meta( 'ep_tickets_data' )['tkt_id'] ] = $iid;
		}

		// An abandoned attempt EventPrime left on the same order.
		$this->ep_abandoned = self::factory()->post->create(
			array(
				'post_type'   => 'em_booking',
				'post_status' => 'pending',
			)
		);
		$this->booking_meta( $this->ep_abandoned, 'pending', $this->order_id, array( array( 'id' => 10, 'qty' => 2, 'price' => 3.5 ) ), array() );

		$this->ep_booking = self::factory()->post->create(
			array(
				'post_type'   => 'em_booking',
				'post_status' => 'completed',
			)
		);
		$this->booking_meta(
			$this->ep_booking,
			'completed',
			$this->order_id,
			array(
				array( 'id' => 10, 'qty' => 2, 'price' => 3.5, 'subtotal' => 7 ),
				array( 'id' => 11, 'qty' => 1, 'price' => 0 ),
			),
			array(
				10 => array(
					1 => array(
						'name' => array( 'first_name' => 'Amy', 'last_name' => 'Smith' ),
						1      => array( 'label' => 'Dietary needs', 'Dietary needs' => 'No nuts' ),
						2      => array( 'label' => 'Year group', 'Year group' => 'Year 1' ),
					),
					2 => array( 'name' => array( 'first_name' => 'Ben', 'last_name' => 'Smith ' ) ),
				),
				11 => array( 1 => array( 'name' => array( 'first_name' => 'Pat', 'last_name' => 'Smith' ) ) ),
			)
		);

		// A cancelled booking that never had a Woo order.
		$this->ep_cancelled = self::factory()->post->create(
			array(
				'post_type'   => 'em_booking',
				'post_status' => 'cancelled',
			)
		);
		$this->booking_meta( $this->ep_cancelled, 'cancelled', 0, array( array( 'id' => 10, 'qty' => 1, 'price' => 3.5 ) ), array() );
	}

	private function booking_meta( $id, $status, $order_id, array $tickets, array $names ) {
		update_post_meta( $id, 'em_event', $this->ep_event );
		update_post_meta( $id, 'em_status', $status );
		update_post_meta( $id, 'em_order_info', wp_json_encode( array( 'tickets' => $tickets, 'user_email' => 'parent@example.com' ) ) );
		update_post_meta( $id, 'em_attendee_names', $names );
		if ( $order_id ) {
			update_post_meta( $id, 'wc_order_id', $order_id );
		}
	}

	private function run_migration( $dry = false ) {
		$m      = new LSET_Migrate();
		$report = $m->run(
			array(
				'dry_run'   => $dry,
				'savepoint' => true,
			)
		);
		$this->assertNotWPError( $report );
		return $report;
	}

	private function lset_event() {
		$ids = get_posts(
			array(
				'post_type'   => LSET_CPT,
				'post_status' => 'any',
				'fields'      => 'ids',
				'meta_key'    => '_lset_ep_id',
				'meta_value'  => $this->ep_event,
			)
		);
		return $ids;
	}

	/** The real booking's rows (the ones on an order line). */
	private function paid_rows() {
		return array_values(
			array_filter(
				$this->bookings(),
				function ( $r ) {
					return (int) $r['order_id'] === $this->order_id && (int) $r['order_item_id'] > 0;
				}
			)
		);
	}

	private function bookings() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . LSET_Install::table( 'bookings' ) . " WHERE source = 'migrated' ORDER BY id", ARRAY_A );
	}

	public function test_local_datetime() {
		$this->assertSame( '2026-07-03 12:00:00', LSET_Migrate::local_datetime( '2026-07-03', '12:00 PM' ) );
		$this->assertSame( '2026-07-03 18:30:00', LSET_Migrate::local_datetime( '03/07/2026', '6:30 pm' ) );
		$this->assertSame( '2026-12-10 09:05:00', LSET_Migrate::local_datetime( '2026-12-10', '09:05' ) );
		$this->assertSame( '2026-07-03 00:00:00', LSET_Migrate::local_datetime( (string) gmmktime( 23, 0, 0, 7, 2, 2026 ), '' ) ); // Midnight BST as a stamp.
		$this->assertSame( '2026-09-18 16:30:00', LSET_Migrate::local_datetime( '1789736733', '04:30 PM' ) ); // Fri 18 Sep 14:05 BST, as on the live site.
		$this->assertSame( '', LSET_Migrate::local_datetime( '', '10:00' ) );
	}

	public function test_dry_run_saves_nothing() {
		global $wpdb;
		$report = $this->run_migration( true );
		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 1, $report['counts']['events']['created'] );
		$this->assertSame( 4, $report['counts']['bookings']['created'] ); // One row per ticket line.
		$this->assertSame( array(), $this->lset_event() );
		$this->assertSame( array(), $this->bookings() );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'tickets' ) ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'fields' ) ) );
		$this->assertEmpty( get_terms( array( 'taxonomy' => LSET_VENUE, 'hide_empty' => false ) ) );
	}

	public function test_copies_events_tickets_fields_and_bookings() {
		$report = $this->run_migration();
		$ids    = $this->lset_event();
		$this->assertCount( 1, $ids );
		$e = LSET_Store::event( $ids[0] );

		// Dates come from the text, in UK time; EventPrime's hour-out stamp is only reported.
		$this->assertSame( '2026-07-03 12:00:00', $e['start'] );
		$this->assertSame( '2026-07-03 15:00:00', $e['end'] );
		$this->assertSame( '2026-07-03T11:00:00+00:00', gmdate( 'c', LSET_Time::ts( $e['start'] ) ) );
		$this->assertEmpty( preg_grep( '/date_time/', $report['warnings'] ) ); // The fields and the stamps agree.
		$this->assertSame( 'publish', $e['status'] );
		$this->assertSame( '1', (string) get_post_meta( $ids[0], '_lset_bookings', true ) );
		$this->assertSame( 'required', LSET_Store::name_fields_mode( $ids[0] ) );

		$venue = $e['venue'];
		$this->assertSame( 'School Hall', $venue['name'] );
		$this->assertSame( 'Bristol Road, Bristol', $venue['address'] );
		$this->assertSame( 'BS7 8XX', $venue['postcode'] );

		$fields = LSET_Store::event_fields( $ids[0], 'attendee' );
		$this->assertCount( 2, $fields );
		$by_label = array_column( $fields, null, 'label' );
		$this->assertFalse( $by_label['Dietary needs']['required'] );
		$this->assertTrue( $by_label['Year group']['required'] );
		$this->assertSame( 'select', $by_label['Year group']['type'] );
		$this->assertSame( array( 'Reception', 'Year 1' ), $by_label['Year group']['options'] );

		$groups = array_values( LSET_Store::groups( $ids[0] ) );
		$this->assertCount( 1, $groups );
		$this->assertSame( 50, $groups[0]['capacity'] );
		$tickets = array_column( LSET_Store::tickets( $ids[0] ), null, 'name' );
		$this->assertSame( 3.5, (float) $tickets['Child']['price'] );
		$this->assertSame( 40, $tickets['Child']['capacity'] );
		// EventPrime's per-ticket windows become one for the event: Adult is on sale now, so the event is.
		$this->assertSame( 'days', $tickets['Child']['sale_start_mode'] );
		$this->assertSame( '', $e['sale_mode'] );
		$this->assertNull( $tickets['Adult']['capacity'] );
		$this->assertSame( $groups[0]['id'], $tickets['Adult']['group_id'] );

		$rows = $this->bookings();
		$this->assertCount( 4, $rows ); // Child + Adult on the paid booking, Child on the abandoned and on the cancelled one.
		$paid = $this->paid_rows();
		$this->assertCount( 2, $paid );
		foreach ( $paid as $r ) {
			$this->assertSame( 'completed', $r['status'] );
			$this->assertSame( (string) $this->ep_booking, $r['ep_booking_id'] );
			$this->assertSame( 'Pat Parent', $r['buyer_name'] );
			// Each booking points at its own ticket's order line.
			$ep_tid = (int) $r['ticket_id'] === $tickets['Child']['id'] ? 10 : 11;
			$this->assertSame( (string) $this->lines[ $ep_tid ], $r['order_item_id'] );
		}

		// The abandoned attempt on the same order keeps EventPrime's "pending" and doesn't count.
		$abandoned = array_values( wp_list_filter( $rows, array( 'ep_booking_id' => (string) $this->ep_abandoned ) ) );
		$this->assertCount( 1, $abandoned );
		$this->assertSame( 'pending', $abandoned[0]['status'] );
		$this->assertSame( '0', $abandoned[0]['order_item_id'] );
		$this->assertNotEmpty( preg_grep( '/EventPrime bookings/', $report['warnings'] ) );

		$people = LSET_Attendees::rows( $ids[0] );
		$names  = wp_list_pluck( $people, 'first_name' );
		sort( $names );
		$this->assertSame( array( 'Amy', 'Ben', 'Pat' ), $names );
		$this->assertSame( array( 'Smith' ), array_values( array_unique( wp_list_pluck( $people, 'last_name' ) ) ) );
		$amy = array_values( wp_list_filter( $people, array( 'first_name' => 'Amy' ) ) )[0];
		$this->assertStringContainsString( 'No nuts', wp_json_encode( $amy ) );

		// 2 Child taken of 40; the group of 50 has 3 used.
		$this->assertSame( 38, $this->left( $ids[0], $tickets['Child']['id'] ) );
		$this->assertSame( 47, $this->left( $ids[0], $tickets['Adult']['id'] ) );

		foreach ( $report['compare'] as $c ) {
			$this->assertSame( 'yes', $c['match'], $c['ticket'] );
		}
	}

	public function test_running_again_makes_no_duplicates() {
		global $wpdb;
		$this->run_migration();
		update_post_meta( $this->ep_event, 'em_start_time', '1:00 PM' );
		update_post_meta( $this->ep_event, 'em_start_date_time', gmmktime( 12, 0, 0, 7, 3, 2026 ) );
		$report = $this->run_migration();

		$this->assertSame( 1, $report['counts']['events']['updated'] );
		$this->assertArrayNotHasKey( 'created', $report['counts']['events'] );
		$this->assertCount( 1, $this->lset_event() );
		$this->assertSame( '2026-07-03 13:00:00', LSET_Store::event( $this->lset_event()[0] )['start'] );
		$this->assertSame( '2', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'tickets' ) ) );
		$this->assertSame( '2', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'fields' ) ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'ticket_groups' ) ) );
		$this->assertCount( 4, $this->bookings() );
		$this->assertCount( 3, LSET_Attendees::rows( $this->lset_event()[0] ) );
		$this->assertCount( 1, get_terms( array( 'taxonomy' => LSET_VENUE, 'hide_empty' => false ) ) );
	}

	public function test_does_not_change_eventprime_or_the_order() {
		$before_meta  = get_post_meta( $this->ep_booking );
		$before_order = wc_get_order( $this->order_id )->get_status();
		$this->run_migration();
		$this->assertSame( $before_meta, get_post_meta( $this->ep_booking ) );
		$this->assertSame( $before_order, wc_get_order( $this->order_id )->get_status() );
		$this->assertSame( 'em_event', get_post_type( $this->ep_event ) );
	}

	public function test_migrated_booking_follows_a_refund() {
		$this->run_migration();
		$id      = $this->lset_event()[0];
		$tickets = array_column( LSET_Store::tickets( $id ), null, 'name' );
		$this->assertSame( 38, $this->left( $id, $tickets['Child']['id'] ) );

		wc_get_order( $this->order_id )->update_status( 'refunded' );
		foreach ( $this->paid_rows() as $r ) {
			$this->assertSame( 'refunded', $r['status'] );
			$this->assertSame( $r['qty'], $r['refunded_qty'] );
		}
		$this->assertSame( 40, $this->left( $id, $tickets['Child']['id'] ) );
		$abandoned = array_values( wp_list_filter( $this->bookings(), array( 'ep_booking_id' => (string) $this->ep_abandoned ) ) );
		$this->assertSame( 'pending', $abandoned[0]['status'] ); // Not dragged along by the order.
	}

	public function test_midnight_to_1159_is_all_day() {
		update_post_meta( $this->ep_event, 'em_start_date_time', gmmktime( 0, 0, 0, 11, 28, 2026 ) );
		update_post_meta( $this->ep_event, 'em_end_date_time', gmmktime( 23, 59, 0, 11, 28, 2026 ) );
		delete_post_meta( $this->ep_event, 'em_start_date' );
		delete_post_meta( $this->ep_event, 'em_end_date' );
		$this->run_migration();
		$e = LSET_Store::event( $this->lset_event()[0] );
		$this->assertTrue( $e['all_day'] );
		$this->assertSame( '2026-11-28', substr( $e['start'], 0, 10 ) );
	}
}
