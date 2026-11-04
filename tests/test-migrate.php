<?php
/**
 * wp lset migrate-eventprime, run against a small fake EventPrime install.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Migrate extends LSET_Test_Case {

	private $ep_event;
	private $ep_booking;
	private $ep_cancelled;
	private $order_id;

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
		wp_set_object_terms( $this->ep_event, array( (int) $venue['term_id'] ), 'em_venue' );
		unregister_taxonomy( 'em_venue' );
		$meta = array(
			'em_start_date'                     => '2026-07-03',
			'em_start_time'                     => '12:00 PM',
			'em_end_date'                       => '2026-07-03',
			'em_end_time'                       => '3:00 PM',
			'em_start_date_time'                => gmmktime( 12, 0, 0, 7, 3, 2026 ), // An hour out, as EventPrime does in summer.
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
		$item = new WC_Order_Item_Product();
		$item->set_name( 'Summer Fair 2026' );
		$item->set_quantity( 1 );
		$item->set_total( 7 );
		$item->add_meta_data( 'ep_booking_id', 999, true );
		$item->add_meta_data( 'ep_tickets_data', wp_json_encode( array( array( 'id' => 10, 'name' => 'Child', 'qty' => 2 ), array( 'id' => 11, 'name' => 'Adult', 'qty' => 1 ) ) ), true );
		$order->add_item( $item );
		$order->set_total( 7 );
		$order->set_status( 'completed' );
		$order->save();
		$this->order_id = $order->get_id();

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
					array(
						'name' => array( 'first_name' => 'Amy', 'last_name' => 'Smith' ),
						1      => array( 'label' => 'Dietary needs', 'Dietary needs' => 'No nuts' ),
						2      => array( 'label' => 'Year group', 'Year group' => 'Year 1' ),
					),
					array( 'name' => array( 'first_name' => 'Ben', 'last_name' => 'Smith' ) ),
				),
				11 => array( array( 'name' => array( 'first_name' => 'Pat', 'last_name' => 'Smith' ) ) ),
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

	private function bookings() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . LSET_Install::table( 'bookings' ) . " WHERE source = 'migrated' ORDER BY id", ARRAY_A );
	}

	public function test_local_datetime() {
		$this->assertSame( '2026-07-03 12:00:00', LSET_Migrate::local_datetime( '2026-07-03', '12:00 PM' ) );
		$this->assertSame( '2026-07-03 18:30:00', LSET_Migrate::local_datetime( '03/07/2026', '6:30 pm' ) );
		$this->assertSame( '2026-12-10 09:05:00', LSET_Migrate::local_datetime( '2026-12-10', '09:05' ) );
		$this->assertSame( '2026-07-03 00:00:00', LSET_Migrate::local_datetime( (string) gmmktime( 23, 0, 0, 7, 2, 2026 ), '' ) ); // Midnight BST as a stamp.
		$this->assertSame( '', LSET_Migrate::local_datetime( '', '10:00' ) );
	}

	public function test_dry_run_saves_nothing() {
		global $wpdb;
		$report = $this->run_migration( true );
		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 1, $report['counts']['events']['created'] );
		$this->assertSame( 3, $report['counts']['bookings']['created'] ); // One row per ticket line.
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
		$this->assertNotEmpty( preg_grep( '/em_start_date_time/', $report['warnings'] ) );
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
		$this->assertSame( 'days', $tickets['Child']['sale_start_mode'] );
		$this->assertSame( 14, $tickets['Child']['sale_start_days'] );
		$this->assertNull( $tickets['Adult']['capacity'] );
		$this->assertSame( 4, $tickets['Adult']['max_qty'] );
		$this->assertSame( $groups[0]['id'], $tickets['Adult']['group_id'] );

		$rows = $this->bookings();
		$this->assertCount( 3, $rows ); // Child + Adult on the paid booking, Child on the cancelled one.
		$paid = array_values( array_filter( $rows, function ( $r ) { return (int) $r['order_id'] === $this->order_id; } ) );
		$this->assertCount( 2, $paid );
		$this->assertSame( 'completed', $paid[0]['status'] );
		$this->assertSame( (string) $this->ep_booking, $paid[0]['ep_booking_id'] );
		$this->assertSame( 'Pat Parent', $paid[0]['buyer_name'] );

		$people = LSET_Attendees::rows( $ids[0] );
		$names  = wp_list_pluck( $people, 'first_name' );
		sort( $names );
		$this->assertSame( array( 'Amy', 'Ben', 'Pat' ), $names );
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
		$report = $this->run_migration();

		$this->assertSame( 1, $report['counts']['events']['updated'] );
		$this->assertArrayNotHasKey( 'created', $report['counts']['events'] );
		$this->assertCount( 1, $this->lset_event() );
		$this->assertSame( '2026-07-03 13:00:00', LSET_Store::event( $this->lset_event()[0] )['start'] );
		$this->assertSame( '2', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'tickets' ) ) );
		$this->assertSame( '2', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'fields' ) ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'ticket_groups' ) ) );
		$this->assertCount( 3, $this->bookings() );
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
		$paid = array_values( array_filter( $this->bookings(), function ( $r ) { return (int) $r['order_id'] === $this->order_id; } ) );
		$this->assertSame( 'refunded', $paid[0]['status'] );
		$this->assertSame( 40, $this->left( $id, $tickets['Child']['id'] ) );
	}

	public function test_old_order_lines_show_readably() {
		$item = current( wc_get_order( $this->order_id )->get_items() );
		$meta = wp_list_pluck( $item->get_formatted_meta_data(), 'display_value', 'display_key' );
		$this->assertSame( '2 × Child, 1 × Adult', $meta['Tickets'] );
		$this->assertArrayNotHasKey( 'ep_booking_id', $meta ); // Hidden outside the admin.
		$this->assertArrayNotHasKey( 'EventPrime booking', $meta );
	}
}
