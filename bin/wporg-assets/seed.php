<?php
// Fictional demo data for WordPress.org screenshots (Oakfield Primary PTA).
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
function shot_img( $file, $post ) {
	$tmp = wp_tempnam( $file ); copy( __DIR__ . '/' . $file, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), $post );
	set_post_thumbnail( $post, $id );
	return $id;
}
$hall = (int) wp_insert_term( 'School Hall', LSET_VENUE )['term_id'];
update_term_meta( $hall, 'lset_address', '1 Oakfield Lane' );
$club = (int) wp_insert_term( 'Oakfield Social Club', LSET_VENUE )['term_id'];
$f = array();
foreach ( array( array( "Child's class", 'select', array( 'Reception', 'Year 1', 'Year 2', 'Year 3', 'Year 4', 'Year 5', 'Year 6' ) ), array( 'Dietary needs', 'text' ), array( 'Contact phone', 'text' ), array( 'Team name', 'text' ) ) as $i => $x ) {
	$f[ $x[0] ] = LSET_Store::save_field( array( 'label' => $x[0], 'type' => $x[1], 'options' => isset( $x[2] ) ? $x[2] : array(), 'sort_order' => $i ) );
}
function shot_event( $title, $start, $end, $venue, $img, $excerpt, $content, $bookings = 1 ) {
	$id = wp_insert_post( array( 'post_type' => LSET_CPT, 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => $content ) );
	LSET_Store::save_dates( $id, $start, $end );
	update_post_meta( $id, '_lset_bookings', $bookings );
	update_post_meta( $id, '_lset_name_fields', 'required' );
	wp_set_object_terms( $id, array( $venue ), LSET_VENUE );
	shot_img( $img, $id );
	return $id;
}
$disco = shot_event( 'Winter Disco', '2026-12-11 17:30:00', '2026-12-11 19:30:00', $hall, 'disco.jpg', 'Two sessions with a DJ, glow sticks and the tuck shop.', '<p>Our winter disco is back! Two sessions in the school hall with a DJ, glow sticks, games and the tuck shop. Children must be collected from the hall by a grown-up.</p>' );
LSET_Store::set_event_fields( $disco, 'attendee', array( array( 'field_id' => $f["Child's class"], 'required' => 1 ), array( 'field_id' => $f['Dietary needs'], 'required' => 0 ) ) );
LSET_Store::set_event_fields( $disco, 'booking', array( array( 'field_id' => $f['Contact phone'], 'required' => 1 ) ) );
$vol = LSET_Store::save_group( array( 'event_id' => $disco, 'name' => 'Volunteers', 'capacity' => 8 ) );
$t = array();
foreach ( array( array( 'Infants (Reception to Year 2), 5:30pm', 3, 120, 0 ), array( 'Juniors (Years 3 to 6), 6:30pm', 3.5, 150, 0 ), array( 'Volunteer: tuck shop', 0, 4, $vol ), array( 'Volunteer: door', 0, 4, $vol ) ) as $i => $x ) {
	$t[] = LSET_Store::save_ticket( array( 'event_id' => $disco, 'name' => $x[0], 'price' => $x[1], 'capacity' => $x[2], 'group_id' => $x[3], 'sort_order' => $i, 'show_left_below' => 10 ) );
}
$quiz = shot_event( 'Quiz Night', '2027-02-06 19:00:00', '2027-02-06 22:30:00', $club, 'quiz.jpg', 'Teams of up to six. Bar open, fish and chips available.', '<p>Grown-ups only: our famous quiz night, with a picture round and a prize for the winning team.</p>' );
LSET_Store::set_event_fields( $quiz, 'booking', array( array( 'field_id' => $f['Team name'], 'required' => 1 ) ) );
LSET_Store::save_ticket( array( 'event_id' => $quiz, 'name' => 'Quiz ticket', 'price' => 6, 'capacity' => 90, 'sort_order' => 0 ) );
$fair = shot_event( 'Christmas Fair', '2026-12-05 12:00:00', '2026-12-05 15:00:00', $hall, 'xmas.jpg', 'Stalls, Santa\'s grotto and mulled wine.', '<p>Stalls, games and Santa\'s grotto. No tickets needed.</p>', 0 );
update_post_meta( $fair, '_lset_closed_msg', 'Free entry: just come along on the day.' );
$summer = shot_event( 'Summer Fair', '2026-07-04 12:00:00', '2026-07-04 16:00:00', $hall, 'summer.jpg', 'Thank you to everyone who came!', '<p>Thank you to everyone who came and helped. We raised £2,340 for new playground equipment.</p>', 0 );

$kids = array( array( 'Ava', 'Patel', 'Year 1' ), array( 'Leo', 'Patel', 'Year 4' ), array( 'Isla', 'Morgan', 'Reception' ), array( 'Noah', 'Hughes', 'Year 2' ), array( 'Freya', 'Hughes', 'Year 5' ), array( 'Oscar', 'Clarke', 'Year 3' ), array( 'Maya', 'Bennett', 'Year 6' ), array( 'Arlo', 'Evans', 'Year 2' ), array( 'Ruby', 'Shaw', 'Year 1' ), array( 'Theo', 'Walsh', 'Year 4' ), array( 'Elsie', 'Ward', 'Reception' ), array( 'Jude', 'Khan', 'Year 6' ) );
$cls = LSET_Store::field( $f["Child's class"] );
foreach ( $kids as $k ) {
	$tid = in_array( $k[2], array( 'Reception', 'Year 1', 'Year 2' ), true ) ? $t[0] : $t[1];
	$tk = LSET_Store::ticket( $tid );
	$order = wc_create_order();
	$order->set_billing_email( strtolower( $k[1] ) . '@example.com' );
	$order->set_billing_first_name( 'Sam' ); $order->set_billing_last_name( $k[1] );
	$item = new WC_Order_Item_Product();
	$item->set_product_id( LSET_Install::product_id() ); $item->set_name( $tk['name'] ); $item->set_quantity( 1 );
	$item->set_subtotal( $tk['price'] ); $item->set_total( $tk['price'] );
	$item->add_meta_data( LSET_Orders::META_EVENT, $disco, true );
	$item->add_meta_data( LSET_Orders::META_TICKET, $tid, true );
	$item->add_meta_data( LSET_Orders::META_UID, wp_generate_uuid4(), true );
	$item->add_meta_data( LSET_Orders::META_ATTENDEES, array( array( 'first_name' => $k[0], 'last_name' => $k[1], 'fields' => array( $f["Child's class"] => $k[2], $f['Dietary needs'] => 'Ruby' === $k[0] ? 'No nuts' : '' ) ) ), true );
	$item->add_meta_data( LSET_Orders::META_BOOKING, array( $f['Contact phone'] => '07700 900' . wp_rand( 100, 999 ) ), true );
	$item->add_meta_data( LSET_Orders::META_LABELS, array(), true );
	$order->add_item( $item ); $order->calculate_totals( false ); $order->set_status( 'pending' ); $order->save();
	LSET_Orders::sync( $order ); $order->update_status( 'processing' );
}
foreach ( array( 'upcoming' => array( 'Events', '[lset_events]' ), 'past' => array( 'Past events', '[lset_events past="1"]' ) ) as $k => $p ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $p[0], 'post_content' => '<!-- wp:shortcode -->' . $p[1] . '<!-- /wp:shortcode -->' ) );
}
echo "disco=$disco quiz=$quiz fair=$fair summer=$summer\n";
