<?php
/**
 * Bookings follow the order: status changes, cancellations and refunds free capacity.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Orders extends LSET_Test_Case {

	private function bookings( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LSET_Install::table( 'bookings' ) . ' WHERE order_id = %d', $order_id ), ARRAY_A );
	}

	public function test_runs_on_hpos() {
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_paid_order_creates_booking_and_attendees() {
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'capacity' => 10 ) );
		$order = $this->order( array( $t => 3 ), 'processing' );
		$rows  = $this->bookings( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'processing', $rows[0]['status'] );
		$this->assertSame( '3', $rows[0]['qty'] );
		$this->assertSame( 'parent@example.com', $rows[0]['buyer_email'] );
		$this->assertCount( 3, LSET_Attendees::rows( $e ) );
		$this->assertSame( 7, $this->left( $e, $t ) );
	}

	public function test_sync_is_idempotent() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 2 ) );
		LSET_Orders::sync( $order );
		LSET_Orders::sync( $order );
		$this->assertCount( 1, $this->bookings( $order->get_id() ) );
		$this->assertCount( 2, LSET_Attendees::rows( $e ) );
	}

	public function test_pending_counts_while_held_then_frees() {
		global $wpdb;
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'capacity' => 5 ) );
		$order = $this->order( array( $t => 2 ), 'pending' );
		$this->assertSame( 3, $this->left( $e, $t ) );
		$wpdb->update( LSET_Install::table( 'bookings' ), array( 'hold_until' => LSET_Time::now_utc( -1 ) ), array( 'order_id' => $order->get_id() ) );
		$this->assertSame( 5, $this->left( $e, $t ), 'abandoned pending order stops counting' );
		$order->update_status( 'processing' );
		$this->assertSame( 3, $this->left( $e, $t ), 'paid later: counts again' );
	}

	public function test_cancel_failed_and_refund_free_capacity() {
		$e = $this->event();
		$t = $this->ticket( $e, array( 'capacity' => 10 ) );
		foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
			$order = $this->order( array( $t => 4 ), 'processing' );
			$this->assertSame( 6, $this->left( $e, $t ) );
			$order->update_status( $status );
			$this->assertSame( 10, $this->left( $e, $t ), $status . ' frees the tickets' );
			$this->assertSame( $status, $this->bookings( $order->get_id() )[0]['status'] );
		}
	}

	public function test_partial_refund_by_line_frees_that_many() {
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'capacity' => 10, 'price' => 5 ) );
		$order = $this->order( array( $t => 4 ), 'completed' );
		$items = $order->get_items();
		$item  = reset( $items );
		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 5,
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => 5,
					),
				),
			)
		);
		$row = $this->bookings( $order->get_id() )[0];
		$this->assertSame( '1', $row['refunded_qty'] );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 7, $this->left( $e, $t ) );

		$attendees = LSET_Attendees::rows( $e );
		$this->assertTrue( end( $attendees )['refunded'], 'the last attendee on the line is marked refunded' );
		$this->assertFalse( $attendees[0]['refunded'] );
	}

	public function test_money_only_refund_frees_nothing() {
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'capacity' => 10, 'price' => 5 ) );
		$order = $this->order( array( $t => 2 ), 'completed' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 2,
			)
		);
		$this->assertSame( 8, $this->left( $e, $t ) );
	}

	public function test_trashed_order_frees_capacity() {
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'capacity' => 10 ) );
		$order = $this->order( array( $t => 2 ), 'processing' );
		$order->delete( false );
		$this->assertSame( 10, $this->left( $e, $t ) );
	}

	public function test_ticket_only_orders_auto_complete() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 1 ), 'pending' );
		$order->payment_complete();
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );

		update_option( 'lset_settings', array( 'auto_complete' => 0 ) );
		$order = $this->order( array( $t => 1 ), 'pending' );
		$order->payment_complete();
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_order_line_is_converted_from_hold() {
		global $wpdb;
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'capacity' => 3 ) );
		$uid   = 'hold-1';
		LSET_Capacity::reserve( $e, array( $uid => array( $t, 3 ) ), 's' );
		$this->assertSame( 0, $this->left( $e, $t ) );

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( LSET_Install::product_id() );
		$item->set_quantity( 3 );
		$item->add_meta_data( LSET_Orders::META_EVENT, $e, true );
		$item->add_meta_data( LSET_Orders::META_TICKET, $t, true );
		$item->add_meta_data( LSET_Orders::META_UID, $uid, true );
		$order->add_item( $item );
		$order->save();
		LSET_Orders::sync( $order );

		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'holds' ) ) );
		$this->assertSame( 0, $this->left( $e, $t ), 'still taken, now by the pending booking' );
	}
}
