<?php
/**
 * Duplicating an event for next year, and the availability route.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Events extends LSET_Test_Case {

	public function test_duplicate_copies_tickets_groups_fields_and_shifts_dates() {
		$e = $this->event( '2026-12-11 17:30:00', '2026-12-11 19:30:00' );
		wp_update_post( array( 'ID' => $e, 'post_title' => 'Winter Disco 2026' ) );
		$g = LSET_Store::save_group( array( 'event_id' => $e, 'name' => 'Volunteering', 'capacity' => 40 ) );
		$this->ticket( $e, array( 'name' => 'Year 6', 'sale_start_mode' => 'date', 'sale_start' => '2026-11-01 09:00:00' ) );
		$this->ticket( $e, array( 'name' => 'Door', 'group_id' => $g, 'price' => 0 ) );
		$f = LSET_Store::save_field( array( 'label' => 'Class', 'type' => 'number' ) );
		LSET_Store::set_event_fields( $e, 'attendee', array( array( 'field_id' => $f, 'required' => 1 ) ) );
		$this->order( array( key( LSET_Store::tickets( $e ) ) => 2 ) );

		$new = LSET_Events::duplicate( $e, '+52 weeks' );
		$n   = LSET_Store::event( $new );
		$this->assertSame( 'draft', $n['status'] );
		$this->assertSame( 'Winter Disco 2027', $n['title'] );
		$this->assertSame( '2027-12-10 17:30:00', $n['start'] );
		$this->assertSame( '2027-12-10 19:30:00', $n['end'] );

		$tickets = array_values( LSET_Store::tickets( $new ) );
		$this->assertCount( 2, $tickets );
		$this->assertSame( '2027-10-31 09:00:00', $tickets[0]['sale_start'] );
		$groups = LSET_Store::groups( $new );
		$this->assertCount( 1, $groups );
		$this->assertSame( key( $groups ), $tickets[1]['group_id'] );
		$this->assertSame( 40, current( $groups )['capacity'] );
		$this->assertCount( 1, LSET_Store::event_fields( $new, 'attendee' ) );
		$this->assertSame( array(), LSET_Capacity::taken( $new ), 'no bookings copied' );
	}

	public function test_availability_route_is_live_and_uncached() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => 3, 'show_left_below' => 5 ) );
		$this->order( array( $t => 1 ) );
		do_action( 'rest_api_init' );
		$res = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/lset/v1/availability/' . $e ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertStringContainsString( 'no-store', $res->get_headers()['Cache-Control'] );
		$data = $res->get_data();
		$this->assertTrue( $data['open'] );
		$this->assertSame( 2, $data['tickets']->{$t}['left'] );
	}

	public function test_sold_out_ticket_reports_sold_out() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => 1 ) );
		$this->order( array( $t => 1 ) );
		$av = LSET_Frontend::availability( LSET_Store::event( $e ) );
		$this->assertSame( 'sold_out', $av['tickets']->{$t}['state'] );
		$this->assertFalse( $av['open'] );
	}

	public function test_csv_cells_cannot_run_formulas() {
		$this->assertSame( "'=HYPERLINK(1)", LSET_Attendees::csv_safe( '=HYPERLINK(1)' ) );
		$this->assertSame( 'Amy', LSET_Attendees::csv_safe( 'Amy' ) );
	}
}
