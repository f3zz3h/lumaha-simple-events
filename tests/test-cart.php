<?php
/**
 * Add to basket: validation of tickets and fields.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Cart extends LSET_Test_Case {

	public function set_up() {
		parent::set_up();
		wc_load_cart();
		WC()->cart->empty_cart();
	}

	private function with_fields( $e ) {
		$class = LSET_Store::save_field( array( 'label' => 'Childs Class', 'type' => 'number' ) );
		$diet  = LSET_Store::save_field( array( 'label' => 'Dietary', 'type' => 'select', 'options' => array( 'None', 'Vegetarian' ) ) );
		$phone = LSET_Store::save_field( array( 'label' => 'Phone', 'type' => 'text' ) );
		LSET_Store::set_event_fields( $e, 'attendee', array( array( 'field_id' => $class, 'required' => 1 ), array( 'field_id' => $diet, 'required' => 0 ) ) );
		LSET_Store::set_event_fields( $e, 'booking', array( array( 'field_id' => $phone, 'required' => 1 ) ) );
		return array( $class, $diet, $phone );
	}

	public function test_valid_booking_is_added_with_attendees() {
		$e                        = $this->event();
		$t                        = $this->ticket( $e, array( 'capacity' => 5 ) );
		list( $class, $diet, $ph ) = $this->with_fields( $e );
		$keys                     = LSET_Cart::add(
			$e,
			array(
				'qty'  => array( $t => 2 ),
				'att'  => array(
					$t => array(
						1 => array( 'first_name' => 'Amy', 'last_name' => 'Pond', 'fields' => array( $class => '4' ) ),
						2 => array( 'first_name' => 'Rory', 'last_name' => 'Williams', 'fields' => array( $class => '5', $diet => 'Vegetarian' ) ),
					),
				),
				'book' => array( $ph => '0123' ),
			)
		);
		$this->assertIsArray( $keys );
		$item = WC()->cart->get_cart_item( $keys[0] );
		$this->assertSame( 2, $item['quantity'] );
		$this->assertSame( 'Rory', $item['lset']['attendees'][1]['first_name'] );
		$this->assertSame( 3, $this->left( $e, $t ), 'basket hold taken' );
		WC()->cart->calculate_totals();
		$this->assertEquals( 7.0, (float) WC()->cart->get_total( 'edit' ) );
	}

	public function test_required_fields_and_types_are_checked() {
		$e                        = $this->event();
		$t                        = $this->ticket( $e );
		list( $class, $diet, $ph ) = $this->with_fields( $e );
		$err                      = LSET_Cart::validate(
			$e,
			array(
				'qty'  => array( $t => 1 ),
				'att'  => array( $t => array( 1 => array( 'first_name' => 'Amy', 'last_name' => '', 'fields' => array( $class => 'four', $diet => 'Pizza' ) ) ) ),
				'book' => array(),
			)
		);
		$this->assertWPError( $err );
		$msgs = implode( ' | ', $err->get_error_messages() );
		$this->assertStringContainsString( 'first and last name', $msgs );
		$this->assertStringContainsString( 'must be a number', $msgs );
		$this->assertStringContainsString( 'choose an option', $msgs );
		$this->assertStringContainsString( 'Phone', $msgs );
	}

	public function test_max_per_order_counts_basket() {
		$e = $this->event( '2030-07-03 12:00:00', '2030-07-03 15:00:00', array( '_lset_name_fields' => 'off' ) );
		$t = $this->ticket( $e, array( 'max_qty' => 2 ) );
		$in = array( 'qty' => array( $t => 2 ) );
		$this->assertIsArray( LSET_Cart::add( $e, $in ) );
		$err = LSET_Cart::add( $e, array( 'qty' => array( $t => 1 ) ) );
		$this->assertWPError( $err );
		$this->assertSame( 'lset_max', $err->get_error_code() );
	}

	public function test_not_on_sale_yet_and_closed() {
		$e = $this->event( '2030-07-03 12:00:00', '2030-07-03 15:00:00', array( '_lset_name_fields' => 'off' ) );
		$t = $this->ticket( $e, array( 'sale_start_mode' => 'date', 'sale_start' => '2030-06-01 09:00:00' ) );
		$this->assertSame( 'lset_not_yet', LSET_Cart::validate( $e, array( 'qty' => array( $t => 1 ) ) )->get_error_code() );

		update_post_meta( $e, '_lset_bookings', 0 );
		$this->assertSame( 'lset_closed', LSET_Cart::validate( $e, array( 'qty' => array( $t => 1 ) ) )->get_error_code() );
	}

	public function test_sold_out_is_refused() {
		$e = $this->event( '2030-07-03 12:00:00', '2030-07-03 15:00:00', array( '_lset_name_fields' => 'off' ) );
		$t = $this->ticket( $e, array( 'capacity' => 1 ) );
		$this->order( array( $t => 1 ) );
		$err = LSET_Cart::add( $e, array( 'qty' => array( $t => 1 ) ) );
		$this->assertWPError( $err );
		$this->assertSame( 'lset_sold_out', $err->get_error_code() );
	}

	public function test_ticket_product_cannot_be_added_directly() {
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, LSET_Install::product_id(), 1 ) );
	}

	public function test_removing_a_line_releases_its_hold() {
		$e    = $this->event( '2030-07-03 12:00:00', '2030-07-03 15:00:00', array( '_lset_name_fields' => 'off' ) );
		$t    = $this->ticket( $e, array( 'capacity' => 3 ) );
		$keys = LSET_Cart::add( $e, array( 'qty' => array( $t => 2 ) ) );
		$this->assertSame( 1, $this->left( $e, $t ) );
		WC()->cart->remove_cart_item( $keys[0] );
		$this->assertSame( 3, $this->left( $e, $t ) );
	}
}
