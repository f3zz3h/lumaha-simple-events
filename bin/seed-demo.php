<?php
/**
 * Demo data for a local dev site: two venues, the Horfield field library, a disco with
 * year-group tickets and a capped volunteer group, and an info-only fair.
 *
 * Run: wp eval-file bin/seed-demo.php
 *
 * @package Lumaha_Simple_Event_Tickets
 */

if ( ! class_exists( 'LSET_Store' ) ) {
	WP_CLI::error( 'Activate Lumaha Simple Event Tickets first.' );
}

$school = term_exists( 'Horfield Primary School', LSET_VENUE );
$school = $school ? (int) $school['term_id'] : (int) wp_insert_term( 'Horfield Primary School', LSET_VENUE )['term_id'];
update_term_meta( $school, 'lset_address', 'Bishop Manor Road, Bristol' );
update_term_meta( $school, 'lset_postcode', 'BS10 5BD' );

$beehive = term_exists( 'The Beehive', LSET_VENUE );
$beehive = $beehive ? (int) $beehive['term_id'] : (int) wp_insert_term( 'The Beehive', LSET_VENUE )['term_id'];
update_term_meta( $beehive, 'lset_address', 'Wellington Hill West, Bristol' );
update_term_meta( $beehive, 'lset_postcode', 'BS9 4QY' );

$fields = array();
foreach ( array(
	array( 'Childs Class', 'number' ),
	array( 'Dietary Requirements', 'text' ),
	array( 'Medical Conditions', 'text' ),
	array( 'Song request', 'text' ),
	array( 'Contact Telephone Number', 'text' ),
	array( 'If volunteering are you DBS registered with the school?', 'select', array( 'Yes', 'No' ) ),
	array( 'If volunteering are you first aid trained?', 'checkbox' ),
) as $i => $f ) {
	$existing = null;
	foreach ( LSET_Store::fields() as $lf ) {
		if ( $lf['label'] === $f[0] ) {
			$existing = $lf['id'];
		}
	}
	$fields[ $f[0] ] = LSET_Store::save_field(
		array(
			'id'         => $existing,
			'label'      => $f[0],
			'type'       => $f[1],
			'options'    => isset( $f[2] ) ? $f[2] : array(),
			'sort_order' => $i,
		)
	);
}

$year  = (int) wp_date( 'Y' ) + ( (int) wp_date( 'n' ) >= 12 ? 1 : 0 );
$disco = wp_insert_post(
	array(
		'post_type'    => LSET_CPT,
		'post_status'  => 'publish',
		'post_title'   => 'Winter Disco ' . $year,
		'post_content' => '<p>Our winter disco in the school hall, with DJ, glow sticks and the tuck shop.</p>',
		'post_excerpt' => 'Disco for every year group, in two sessions.',
	)
);
LSET_Store::save_dates( $disco, $year . '-12-11 17:30:00', $year . '-12-11 19:30:00' );
update_post_meta( $disco, '_lset_bookings', 1 );
update_post_meta( $disco, '_lset_name_fields', 'required' );
wp_set_object_terms( $disco, array( $school ), LSET_VENUE );
LSET_Store::set_event_fields(
	$disco,
	'attendee',
	array(
		array( 'field_id' => $fields['Childs Class'], 'required' => 1 ),
		array( 'field_id' => $fields['Song request'], 'required' => 0 ),
		array( 'field_id' => $fields['Dietary Requirements'], 'required' => 0 ),
	)
);
LSET_Store::set_event_fields(
	$disco,
	'booking',
	array(
		array( 'field_id' => $fields['Contact Telephone Number'], 'required' => 1 ),
	)
);
$vol = LSET_Store::save_group( array( 'event_id' => $disco, 'name' => 'Volunteering', 'capacity' => 4 ) );
foreach ( array(
	array( 'Reception, Years 1 & 2', 3.5, 200, 0 ),
	array( 'Years 3-5', 3.5, 300, 0 ),
	array( 'Year 6', 3.5, 3, 0 ),
	array( 'Volunteer: tuck shop', 0, 2, $vol ),
	array( 'Volunteer: door', 0, 3, $vol ),
) as $i => $t ) {
	LSET_Store::save_ticket(
		array(
			'event_id'        => $disco,
			'name'            => $t[0],
			'price'           => $t[1],
			'capacity'        => $t[2],
			'group_id'        => $t[3],
			'sort_order'      => $i,
			'max_qty'         => $t[3] ? 1 : 20,
			'show_left_below' => 5,
		)
	);
}

$fair = wp_insert_post(
	array(
		'post_type'    => LSET_CPT,
		'post_status'  => 'publish',
		'post_title'   => 'Summer Fair ' . ( $year + 1 ),
		'post_content' => '<p>Stalls, games and the bar. No tickets needed.</p>',
	)
);
LSET_Store::save_dates( $fair, ( $year + 1 ) . '-07-03 12:00:00', ( $year + 1 ) . '-07-03 15:00:00' );
update_post_meta( $fair, '_lset_bookings', 0 );
update_post_meta( $fair, '_lset_closed_msg', 'Just turn up on the day, no tickets needed.' );
wp_set_object_terms( $fair, array( $school ), LSET_VENUE );

$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Events',
		'post_name'    => 'events',
		'post_content' => '[lset_events upcoming="1" order="asc"]',
	)
);

WP_CLI::success( "Disco $disco, fair $fair, events page $page." );
