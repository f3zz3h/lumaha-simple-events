<?php
/**
 * The optional photo gallery on past events.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

class LSET_Test_Gallery extends LSET_Test_Case {

	private function image( $parent = 0 ) {
		return self::factory()->attachment->create_object(
			array(
				'file'           => 'photo-' . wp_generate_password( 6, false ) . '.jpg',
				'post_parent'    => $parent,
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
	}

	public function test_save_ids_keeps_order_and_drops_duplicates_and_non_images() {
		$e   = $this->event();
		$a   = $this->image();
		$b   = $this->image();
		$pdf = self::factory()->attachment->create_object(
			array(
				'file'           => 'menu.pdf',
				'post_mime_type' => 'application/pdf',
				'post_type'      => 'attachment',
			)
		);
		$this->assertSame( array( $b, $a ), LSET_Gallery::save_ids( $e, "$b,$a,$b,$pdf,0,nonsense" ) );
		$this->assertSame( array( $b, $a ), LSET_Gallery::ids( $e ) );

		wp_delete_attachment( $b, true );
		$this->assertSame( array( $a ), LSET_Gallery::ids( $e ), 'deleted photos drop out' );

		LSET_Gallery::save_ids( $e, '' );
		$this->assertSame( '', get_post_meta( $e, LSET_Gallery::META, true ) );
	}

	public function test_gallery_shows_only_once_the_event_has_finished() {
		$future = $this->event( '2030-07-03 12:00:00', '2030-07-03 15:00:00' );
		$past   = $this->event( '2020-07-03 12:00:00', '2020-07-03 15:00:00' );
		foreach ( array( $future, $past ) as $id ) {
			LSET_Gallery::save_ids( $id, array( $this->image(), $this->image() ) );
		}
		$this->assertSame( '', LSET_Gallery::for_event_page( LSET_Store::event( $future ) ) );

		$html = LSET_Gallery::for_event_page( LSET_Store::event( $past ) );
		$this->assertStringContainsString( 'id="lset-photos"', $html );
		$this->assertSame( 2, substr_count( $html, 'lset-gallery__link' ) );

		$this->assertSame( '', do_shortcode( '[lset_gallery id="' . $future . '"]' ) );
		$this->assertStringContainsString( 'lset-gallery__grid', do_shortcode( '[lset_gallery id="' . $future . '" any="1"]' ) );
	}

	public function test_no_photos_means_no_gallery() {
		$past = $this->event( '2020-07-03 12:00:00', '2020-07-03 15:00:00' );
		$this->assertFalse( LSET_Gallery::is_visible( LSET_Store::event( $past ) ) );
		$this->assertSame( '', LSET_Gallery::for_event_page( LSET_Store::event( $past ) ) );
	}

	public function test_past_events_list_links_to_photos() {
		$past = $this->event( '2020-07-03 12:00:00', '2020-07-03 15:00:00' );
		LSET_Gallery::save_ids( $past, array( $this->image() ) );
		$html = do_shortcode( '[lset_events past="1"]' );
		$this->assertStringContainsString( '#lset-photos', $html );
		$this->assertStringContainsString( 'See photos', $html );
	}

	public function test_can_view_filter_hides_photos_behind_a_gate() {
		$past = $this->event( '2020-07-03 12:00:00', '2020-07-03 15:00:00' );
		LSET_Gallery::save_ids( $past, array( $this->image() ) );
		add_filter( 'lset_gallery_can_view', '__return_false' );
		$html = LSET_Gallery::for_event_page( LSET_Store::event( $past ) );
		remove_filter( 'lset_gallery_can_view', '__return_false' );
		$this->assertStringContainsString( 'lset-gallery__gate', $html );
		$this->assertStringNotContainsString( 'lset-gallery__link', $html );
	}

	public function test_duplicate_does_not_copy_photos() {
		$e = $this->event( '2026-12-11 17:30:00', '2026-12-11 19:30:00' );
		LSET_Gallery::save_ids( $e, array( $this->image() ) );
		$new = LSET_Events::duplicate( $e, '+1 year' );
		$this->assertSame( array(), LSET_Gallery::ids( $new ) );
	}
}
