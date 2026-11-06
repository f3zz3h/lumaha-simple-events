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
		LSET_Store::save_sale_window( $e, 'date', '2026-11-01 09:00:00', null, '2026-12-11 12:00:00' );
		$this->ticket( $e, array( 'name' => 'Year 6' ) );
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
		$this->assertSame( '2027-10-31 09:00:00', $n['sale_start'] );
		$this->assertSame( '2027-12-10 12:00:00', $n['sale_end'] );
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

	public function test_upgrade_moves_ticket_windows_to_the_event() {
		$e = $this->event( '2027-04-02 18:00:00', '2027-04-02 20:00:00' );
		$this->ticket( $e, array( 'name' => 'A', 'sale_start_mode' => 'date', 'sale_start' => '2027-03-01 09:00:00', 'sale_end' => '2027-04-01 12:00:00' ) );
		$this->ticket( $e, array( 'name' => 'B', 'sale_start_mode' => 'date', 'sale_start' => '2027-02-15 09:00:00', 'sale_end' => '2027-04-02 12:00:00' ) );
		LSET_Store::sale_window_from_tickets( $e );
		$n = LSET_Store::event( $e );
		$this->assertSame( 'date', $n['sale_mode'] );
		$this->assertSame( '2027-02-15 09:00:00', $n['sale_start'] ); // The first ticket to open.
		$this->assertSame( '2027-04-02 12:00:00', $n['sale_end'] ); // The last to close.

		$this->ticket( $e, array( 'name' => 'C' ) ); // On sale now, until the event.
		LSET_Store::sale_window_from_tickets( $e );
		$n = LSET_Store::event( $e );
		$this->assertSame( '', $n['sale_mode'] );
		$this->assertSame( '', $n['sale_end'] );
	}

	public function test_past_list_shows_only_past_events_newest_first() {
		$old   = $this->event( '2020-01-10 18:00:00', '2020-01-10 20:00:00' );
		$older = $this->event( '2019-01-10 18:00:00', '2019-01-10 20:00:00' );
		$soon  = $this->event( '2030-01-10 18:00:00', '2030-01-10 20:00:00' );
		wp_update_post( array( 'ID' => $old, 'post_title' => 'Old one' ) );
		wp_update_post( array( 'ID' => $older, 'post_title' => 'Older one' ) );
		wp_update_post( array( 'ID' => $soon, 'post_title' => 'Coming soon' ) );

		$past = do_shortcode( '[lset_events past="1"]' );
		$this->assertStringNotContainsString( 'Coming soon', $past );
		$this->assertLessThan( strpos( $past, 'Older one' ), strpos( $past, 'Old one' ) );

		$next = do_shortcode( '[lset_events]' );
		$this->assertStringContainsString( 'Coming soon', $next );
		$this->assertStringNotContainsString( 'Old one', $next );
	}

	public function test_list_layouts_and_filters() {
		$a = $this->event( '2030-01-10 18:00:00', '2030-01-10 20:00:00' );
		$b = $this->event( '2031-02-10 18:00:00', '2031-02-10 20:00:00' );
		wp_update_post( array( 'ID' => $a, 'post_title' => 'Disco 2030', 'post_excerpt' => 'Dance!' ) );
		wp_update_post( array( 'ID' => $b, 'post_title' => 'Fair 2031' ) );
		$this->ticket( $a, array( 'price' => 4 ) );
		$hall = wp_insert_term( 'School Hall', LSET_VENUE );
		wp_set_object_terms( $b, array( (int) $hall['term_id'] ), LSET_VENUE );

		$compact = do_shortcode( '[lset_events layout="compact"]' );
		$this->assertStringContainsString( 'lset-events--compact', $compact );
		$this->assertStringContainsString( 'lset-row', $compact );
		$this->assertStringNotContainsString( 'lset-button', $compact );

		$bare = do_shortcode( '[lset_events price="0" excerpt="0" button="0" columns="2"]' );
		$this->assertStringContainsString( 'lset-events--cols-2', $bare );
		$this->assertStringNotContainsString( 'Dance!', $bare );
		$this->assertStringNotContainsString( 'lset-card__price', $bare );
		$this->assertStringNotContainsString( 'lset-button', $bare );

		$this->assertStringContainsString( 'Fair 2031', do_shortcode( '[lset_events where="School Hall"]' ) );
		$this->assertStringNotContainsString( 'Disco 2030', do_shortcode( '[lset_events where="school-hall"]' ) );
		$this->assertStringNotContainsString( 'Fair 2031', do_shortcode( '[lset_events year="2030"]' ) );
		$this->assertStringContainsString( 'Disco 2030', do_shortcode( '[lset_events year="2030"]' ) );
	}

	public function test_admin_save_creates_groups_and_assigns_tickets() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		get_role( 'administrator' )->add_cap( 'manage_woocommerce' );
		$e     = $this->event();
		$old   = LSET_Store::save_group( array( 'event_id' => $e, 'name' => 'Old', 'capacity' => 5 ) );
		$t     = $this->ticket( $e, array( 'name' => 'Kept', 'group_id' => $old ) );
		$_POST = array(
			'lset_nonce'   => wp_create_nonce( 'lset_save_event' ),
			'lset'         => array( 'start_date' => '2030-07-03', 'start_time' => '12:00', 'bookings' => 1 ),
			'lset_groups'  => array(
				(string) $old => array( 'name' => 'Old', 'capacity' => '5', 'delete' => '1' ),
				'n1'          => array( 'name' => 'Volunteering', 'capacity' => '6', 'delete' => '0' ),
			),
			'lset_tickets' => array(
				't' . $t => array( 'id' => $t, 'name' => 'Kept', 'price' => '1', 'group' => 'n1' ),
				'n1'     => array( 'id' => 0, 'name' => 'Door', 'price' => '0', 'group' => '' ),
			),
		);
		LSET_Admin::save_event( $e, get_post( $e ) );
		$_POST = array();

		$groups = array_values( LSET_Store::groups( $e ) );
		$this->assertCount( 1, $groups );
		$this->assertSame( 'Volunteering', $groups[0]['name'] );
		$this->assertSame( 6, $groups[0]['capacity'] );
		$tickets = array_column( LSET_Store::tickets( $e ), null, 'name' );
		$this->assertSame( $groups[0]['id'], $tickets['Kept']['group_id'] );
		$this->assertSame( 0, $tickets['Door']['group_id'] );
	}

	public function test_previous_and_next_follow_the_event_date() {
		// Created out of order: post dates say c, a, b; event dates say a, b, c.
		$c = $this->event( '2027-03-01 18:00:00', '2027-03-01 20:00:00' );
		$a = $this->event( '2027-01-01 18:00:00', '2027-01-01 20:00:00' );
		$b = $this->event( '2027-02-01 18:00:00', '2027-02-01 20:00:00' );
		wp_update_post( array( 'ID' => $c, 'post_date' => '2020-01-01 00:00:00' ) );
		wp_update_post( array( 'ID' => $a, 'post_date' => '2020-02-01 00:00:00' ) );
		wp_update_post( array( 'ID' => $b, 'post_date' => '2020-03-01 00:00:00' ) );

		$GLOBALS['post'] = get_post( $b );
		$this->assertSame( $a, get_previous_post()->ID );
		$this->assertSame( $c, get_next_post()->ID );
		$GLOBALS['post'] = get_post( $a );
		$this->assertSame( '', get_previous_post() );
		$this->assertSame( $b, get_next_post()->ID );
	}

	public function test_post_navigation_says_event() {
		$this->assertSame( 'Next event', LSET_Frontend::event_nav_text( 'Next Post', 'Next Post', 'xpro' ) );
		$this->assertSame( 'Previous event', LSET_Frontend::event_nav_text( 'Previous post', 'Previous post', 'twentytwentyone' ) );
		$this->assertSame( 'Other', LSET_Frontend::event_nav_text( 'Other', 'Other', 'xpro' ) );
	}
}
