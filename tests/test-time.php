<?php
/**
 * Dates are stored as local wall-clock text and shown as typed, summer or winter.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Time extends LSET_Test_Case {

	public function test_july_event_entered_at_noon_shows_noon() {
		$id = $this->event( '2027-07-03 12:00:00', '2027-07-03 15:00:00' );
		$e  = LSET_Store::event( $id );
		$this->assertSame( '12:00', LSET_Time::format( $e['start'], 'H:i', $e['tz'] ) );
		$this->assertSame( '2027-07-03T12:00:00+01:00', LSET_Time::iso( $e['start'], $e['tz'] ) );
		$this->assertStringContainsString( '12:00', LSET_Frontend::when( $e ) );
		$this->assertStringNotContainsString( '11:00', LSET_Frontend::when( $e ) );
	}

	public function test_winter_event_has_gmt_offset() {
		$id = $this->event( '2026-12-11 17:30:00', '2026-12-11 19:30:00' );
		$e  = LSET_Store::event( $id );
		$this->assertSame( '2026-12-11T17:30:00+00:00', LSET_Time::iso( $e['start'], $e['tz'] ) );
		$this->assertSame( '17:30', LSET_Time::format( $e['start'], 'H:i', $e['tz'] ) );
	}

	public function test_utc_copy_is_for_sorting_only() {
		$id = $this->event( '2027-07-03 12:00:00' );
		$this->assertSame( gmmktime( 11, 0, 0, 7, 3, 2027 ), (int) get_post_meta( $id, '_lset_start_utc', true ) );
		$this->assertSame( '2027-07-03 12:00:00', get_post_meta( $id, '_lset_start', true ) );
	}

	public function test_shift_keeps_wall_clock_across_clock_change() {
		// A March (GMT) event moved a week later lands in BST and is still 18:00.
		$this->assertSame( '2027-04-02 18:00:00', LSET_Time::shift( '2027-03-26 18:00:00', '+7 days' ) );
		$this->assertSame( '2027-12-10 17:30:00', LSET_Time::shift( '2026-12-11 17:30:00', '+52 weeks' ) );
	}

	public function test_sale_start_days_before_is_local_midnight() {
		$id = $this->event( '2027-04-02 18:00:00' );
		$e  = LSET_Store::event( $id );
		$t  = LSET_Store::ticket( $this->ticket( $id, array( 'sale_start_mode' => 'days', 'sale_start_days' => 14 ) ) );
		$this->assertSame( '2027-03-19 00:00:00', LSET_Store::sale_start( $t, $e ) );
	}

	public function test_form_parts_are_normalised() {
		$this->assertSame( '2027-07-03 09:05:00', LSET_Time::from_parts( '2027-07-03', '9:05' ) );
		$this->assertSame( '2027-07-03 00:00:00', LSET_Time::from_parts( '2027-07-03', '' ) );
		$this->assertSame( '', LSET_Time::from_parts( 'next friday', '12:00' ) );
	}
}
