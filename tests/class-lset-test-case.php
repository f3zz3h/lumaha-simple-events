<?php
/**
 * Helpers shared by the tests.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

abstract class LSET_Test_Case extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/London' );
		update_option( 'lset_settings', array() );
	}

	/** A published event with bookings on, starting $start (local time). */
	protected function event( $start = '2030-07-03 12:00:00', $end = '2030-07-03 15:00:00', array $meta = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_type'   => LSET_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Test event',
			)
		);
		LSET_Store::save_dates( $id, $start, $end );
		update_post_meta( $id, '_lset_bookings', 1 );
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	protected function ticket( $event_id, array $args = array() ) {
		return LSET_Store::save_ticket(
			array_merge(
				array(
					'event_id' => $event_id,
					'name'     => 'Ticket',
					'price'    => 3.5,
					'capacity' => 10,
				),
				$args
			)
		);
	}

	/**
	 * An order with ticket lines, as the checkout would make it, then synced.
	 *
	 * @param array  $lines  ticket_id => qty.
	 * @param string $status Order status.
	 * @return WC_Order
	 */
	protected function order( array $lines, $status = 'processing' ) {
		$order = wc_create_order();
		$order->set_billing_email( 'parent@example.com' );
		$order->set_billing_first_name( 'Pat' );
		$order->set_billing_last_name( 'Parent' );
		$order->set_billing_phone( '07700900123' );
		foreach ( $lines as $tid => $qty ) {
			$t    = LSET_Store::ticket( $tid );
			$item = new WC_Order_Item_Product();
			$item->set_product_id( LSET_Install::product_id() );
			$item->set_name( $t['name'] );
			$item->set_quantity( $qty );
			$item->set_subtotal( $t['price'] * $qty );
			$item->set_total( $t['price'] * $qty );
			$people = array();
			for ( $i = 1; $i <= $qty; $i++ ) {
				$people[] = array(
					'first_name' => 'Child' . $i,
					'last_name'  => 'Test',
					'fields'     => array(),
				);
			}
			$item->add_meta_data( LSET_Orders::META_EVENT, $t['event_id'], true );
			$item->add_meta_data( LSET_Orders::META_TICKET, $tid, true );
			$item->add_meta_data( LSET_Orders::META_UID, wp_generate_uuid4(), true );
			$item->add_meta_data( LSET_Orders::META_ATTENDEES, $people, true );
			$item->add_meta_data( LSET_Orders::META_BOOKING, array(), true );
			$item->add_meta_data( LSET_Orders::META_LABELS, array(), true );
			$order->add_item( $item );
		}
		$order->calculate_totals( false );
		$order->set_status( 'pending' );
		$order->save();
		LSET_Orders::sync( $order );
		if ( 'pending' !== $status ) {
			$order->update_status( $status );
		}
		return wc_get_order( $order->get_id() );
	}

	protected function left( $event_id, $ticket_id ) {
		$left = LSET_Capacity::left( $event_id );
		return $left[ $ticket_id ];
	}
}
