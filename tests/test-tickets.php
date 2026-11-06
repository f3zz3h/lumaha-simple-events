<?php
/**
 * Tickets with QR codes: codes, the ticket email, the buyer's page and check-in.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Tickets extends LSET_Test_Case {

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/** Ticket emails sent so far (WooCommerce's own order emails are left out). */
	private function ticket_emails() {
		$mailer = tests_retrieve_phpmailer_instance();
		return array_values(
			array_filter(
				$mailer->mock_sent,
				function ( $m ) {
					return 0 === strpos( $m['subject'], 'Your tickets for' );
				}
			)
		);
	}

	private function page( $method, ...$args ) {
		$m = new ReflectionMethod( 'LSET_Tickets', $method );
		$m->setAccessible( true );
		ob_start();
		$m->invokeArgs( null, $args );
		return ob_get_clean();
	}

	public function test_qr_code_is_well_formed() {
		$qr = LSET_QR::encode( 'https://example.org/?lset_t=ABCDEFGHJKMN' );
		$this->assertSame( 3, $qr->version(), '40 bytes at level M fit version 3' );
		$this->assertSame( 29, $qr->size() );
		// Finder pattern corners and the always-dark module.
		foreach ( array( array( 0, 0 ), array( 28, 0 ), array( 0, 28 ), array( 8, 21 ) ) as $xy ) {
			$this->assertTrue( $qr->dark( $xy[0], $xy[1] ) );
		}
		$this->assertFalse( $qr->dark( 7, 7 ), 'separator round the finder' );
		$this->assertStringStartsWith( '<svg', $qr->svg() );
		$png = $qr->png( 2 );
		$this->assertSame( "\x89PNG", substr( $png, 0, 4 ) );
		$this->assertSame( ( 29 + 8 ) * 2, unpack( 'N', substr( $png, 16, 4 ) )[1], 'PNG width includes the quiet zone' );

		// Same input, same code (this matrix was checked with a QR reader).
		$bits = '';
		for ( $y = 0; $y < $qr->size(); $y++ ) {
			for ( $x = 0; $x < $qr->size(); $x++ ) {
				$bits .= $qr->dark( $x, $y ) ? '1' : '0';
			}
		}
		$this->assertSame( 'd223d5d29264b085d780a1d48fff846c', md5( $bits ) );
	}

	public function test_each_attendee_gets_a_code() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 3 ), 'processing' );
		$rows  = LSET_Tickets::for_order( $order->get_id() );
		$this->assertCount( 3, $rows );
		$codes = wp_list_pluck( $rows, 'ticket_code' );
		$this->assertCount( 3, array_unique( $codes ) );
		foreach ( $codes as $c ) {
			$this->assertMatchesRegularExpression( '/^[A-HJKMNP-Z2-9]{12}$/', $c );
		}
		$this->assertSame( 'valid', $rows[0]['state'] );

		$found = LSET_Tickets::by_code( strtolower( LSET_Tickets::display_code( $codes[1] ) ) );
		$this->assertSame( (int) $rows[1]['id'], (int) $found['id'], 'codes can be typed with dashes, in lower case' );
		$this->assertNull( LSET_Tickets::by_code( 'NOPE' ) );
	}

	public function test_tickets_emailed_once_when_paid() {
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'name' => 'Adult' ) );
		$order = $this->order( array( $t => 2 ), 'pending' );
		$this->assertCount( 0, $this->ticket_emails(), 'nothing for an unpaid order' );

		$order->update_status( 'completed' );
		$sent = $this->ticket_emails();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'parent@example.com', $sent[0]['to'][0][0] );
		$this->assertSame( 'Your tickets for Test event', $sent[0]['subject'] );
		$codes = wp_list_pluck( LSET_Tickets::for_order( $order->get_id() ), 'ticket_code' );
		foreach ( $codes as $c ) {
			$this->assertStringContainsString( 'cid:lset-qr-' . strtolower( $c ), $sent[0]['body'] );
			$this->assertStringContainsString( LSET_Tickets::display_code( $c ), $sent[0]['body'] );
		}
		$this->assertStringContainsString( 'Adult', $sent[0]['body'] );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $sent[0]['body'], 'link to the tickets page' );

		$mailer   = tests_retrieve_phpmailer_instance();
		$embedded = array_filter(
			$mailer->getAttachments(),
			function ( $a ) {
				return 'inline' === $a[6];
			}
		);
		$this->assertCount( 2, $embedded, 'one QR image per ticket' );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
		$this->assertCount( 1, $this->ticket_emails(), 'not sent again on later status changes' );
	}

	public function test_on_hold_waits_for_payment() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 1 ), 'on-hold' );
		$this->assertCount( 0, $this->ticket_emails() );
		$order->update_status( 'processing' );
		$this->assertCount( 1, $this->ticket_emails() );
	}

	public function test_setting_off_but_admin_can_still_send() {
		update_option( 'lset_settings', array( 'ticket_emails' => 0 ) );
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 1 ), 'completed' );
		$this->assertCount( 0, $this->ticket_emails() );
		LSET_Tickets::order_action_send( $order );
		$this->assertCount( 1, $this->ticket_emails() );
	}

	public function test_no_auto_email_for_past_events() {
		$e     = $this->event( '2020-07-03 12:00:00', '2020-07-03 15:00:00' );
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 1 ), 'completed' );
		$this->assertCount( 0, $this->ticket_emails() );
	}

	public function test_refunded_and_cancelled_tickets_are_void() {
		$e     = $this->event();
		$t     = $this->ticket( $e, array( 'price' => 5 ) );
		$order = $this->order( array( $t => 2 ), 'completed' );
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
		$states = wp_list_pluck( LSET_Tickets::for_order( $order->get_id() ), 'state' );
		$this->assertSame( array( 'valid', 'void' ), $states );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'cancelled' );
		$this->assertSame( array( 'void', 'void' ), wp_list_pluck( LSET_Tickets::for_order( $order->get_id() ), 'state' ) );
	}

	public function test_check_in_once_and_undo() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 3 ), 'completed' );
		$rows  = LSET_Tickets::for_order( $order->get_id() );
		$this->assertSame( array( 0, 3 ), LSET_Tickets::event_counts( $e ) );

		$this->assertTrue( LSET_Tickets::check_in( $rows[0]['id'] ) );
		$this->assertWPError( LSET_Tickets::check_in( $rows[0]['id'] ), 'a second scan is caught' );
		$this->assertSame( array( 1, 3 ), LSET_Tickets::event_counts( $e ) );
		$this->assertNotEmpty( LSET_Tickets::by_code( $rows[0]['ticket_code'] )['checked_in_at'] );

		LSET_Tickets::check_in( $rows[0]['id'], true );
		$this->assertSame( array( 0, 3 ), LSET_Tickets::event_counts( $e ) );
		$csv = LSET_Attendees::csv_rows( $e, 'paid' );
		$this->assertSame( array( 'Ticket code', 'Checked in' ), array_slice( $csv[0], -2 ) );
	}

	public function test_buyer_page_needs_the_order_key() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 2 ), 'completed' );
		$code  = LSET_Tickets::for_order( $order->get_id() )[0]['ticket_code'];

		$html = $this->page( 'page_order', $order->get_id(), $order->get_order_key() );
		$this->assertStringContainsString( LSET_Tickets::display_code( $code ), $html );
		$this->assertStringContainsString( '<svg', $html );

		$html = $this->page( 'page_order', $order->get_id(), 'wc_order_wrong' );
		$this->assertStringContainsString( 'Tickets not found', $html );
		$this->assertStringNotContainsString( LSET_Tickets::display_code( $code ), $html );
	}

	public function test_scan_page_staff_and_public() {
		$e     = $this->event();
		$t     = $this->ticket( $e );
		$order = $this->order( array( $t => 1 ), 'completed' );
		$code  = LSET_Tickets::for_order( $order->get_id() )[0]['ticket_code'];

		wp_set_current_user( 0 );
		$html = $this->page( 'page_ticket', $code, '' );
		$this->assertStringContainsString( 'Child1 Test', $html );
		$this->assertStringNotContainsString( 'lset_check_in', $html, 'no check-in button for the public' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$html = $this->page( 'page_ticket', $code, '' );
		$this->assertStringContainsString( 'Valid ticket', $html );
		$this->assertStringContainsString( 'lset_check_in', $html );

		LSET_Tickets::check_in( LSET_Tickets::by_code( $code )['id'] );
		$this->assertStringContainsString( 'Already checked in', $this->page( 'page_ticket', $code, '' ) );
	}
}
