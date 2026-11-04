<?php
/**
 * Capacity: ticket caps, group caps, holds and the event lock.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Capacity extends LSET_Test_Case {

	public function test_hold_counts_until_it_expires() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => 5 ) );
		$this->assertTrue( LSET_Capacity::reserve( $e, array( 'a' => array( $t, 3 ) ), 's1' ) );
		$this->assertSame( 2, $this->left( $e, $t ) );

		global $wpdb;
		$wpdb->update( LSET_Install::table( 'holds' ), array( 'expires_at' => LSET_Time::now_utc( -1 ) ), array( 'uid' => 'a' ) );
		$this->assertSame( 5, $this->left( $e, $t ) );
	}

	public function test_reserve_refuses_more_than_left() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => 2, 'name' => 'Year 6' ) );
		$this->assertTrue( LSET_Capacity::reserve( $e, array( 'a' => array( $t, 2 ) ), 's1' ) );
		$err = LSET_Capacity::reserve( $e, array( 'b' => array( $t, 1 ) ), 's2' );
		$this->assertWPError( $err );
		$this->assertSame( 'lset_sold_out', $err->get_error_code() );
	}

	public function test_renewing_own_hold_does_not_count_it_twice() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => 2 ) );
		$this->assertTrue( LSET_Capacity::reserve( $e, array( 'a' => array( $t, 2 ) ), 's1' ) );
		$this->assertTrue( LSET_Capacity::reserve( $e, array( 'a' => array( $t, 2 ) ), 's1' ) );
	}

	public function test_group_cap_is_shared() {
		$e  = $this->event();
		$g  = LSET_Store::save_group( array( 'event_id' => $e, 'name' => 'Volunteering', 'capacity' => 3 ) );
		$t1 = $this->ticket( $e, array( 'capacity' => 2, 'group_id' => $g, 'price' => 0 ) );
		$t2 = $this->ticket( $e, array( 'capacity' => 12, 'group_id' => $g, 'price' => 0 ) );
		$this->order( array( $t1 => 2 ) );
		$this->assertSame( 0, $this->left( $e, $t1 ) );
		$this->assertSame( 1, $this->left( $e, $t2 ) );
		$this->assertWPError( LSET_Capacity::reserve( $e, array( 'x' => array( $t2, 2 ) ), 's' ) );
		$this->assertTrue( LSET_Capacity::reserve( $e, array( 'x' => array( $t2, 1 ) ), 's' ) );
	}

	public function test_no_capacity_means_no_limit() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => '' ) );
		$this->assertNull( $this->left( $e, $t ) );
		$this->assertTrue( LSET_Capacity::reserve( $e, array( 'a' => array( $t, 500 ) ), 's' ) );
	}

	public function test_lock_is_held_while_reserving() {
		$e      = $this->event();
		$t      = $this->ticket( $e );
		$seen   = null;
		$name   = $GLOBALS['wpdb']->prefix . 'lset_event_' . $e;
		LSET_Capacity::with_lock(
			$e,
			function () use ( $name, &$seen ) {
				// A second connection can't take the lock while we hold it.
				$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
				$res   = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" );
				$seen  = (int) $res->fetch_row()[0];
				$other->close();
			}
		);
		$this->assertSame( 0, $seen, 'another buyer must wait for the lock' );
		unset( $t );
	}

	public function test_expired_holds_are_cleared() {
		global $wpdb;
		$e = $this->event();
		$t = $this->ticket( $e );
		LSET_Capacity::reserve( $e, array( 'old' => array( $t, 1 ), 'new' => array( $t, 1 ) ), 's' );
		$wpdb->update( LSET_Install::table( 'holds' ), array( 'expires_at' => LSET_Time::now_utc( -60 ) ), array( 'uid' => 'old' ) );
		LSET_Capacity::clear_expired();
		$this->assertSame( array( 'new' ), $wpdb->get_col( 'SELECT uid FROM ' . LSET_Install::table( 'holds' ) ) );
	}
}
