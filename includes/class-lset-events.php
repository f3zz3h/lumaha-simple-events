<?php
/**
 * The event post type, the venue taxonomy, duplicating an event, and old-URL redirects.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Events {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_old_urls' ) );
	}

	public static function register() {
		$slug = sanitize_title( Lumaha_Simple_Event_Tickets::setting( 'event_slug' ) );
		$slug = $slug ? $slug : 'events';

		register_post_type(
			LSET_CPT,
			array(
				'labels'          => array(
					'name'               => __( 'Events', 'lumaha-simple-event-tickets' ),
					'singular_name'      => __( 'Event', 'lumaha-simple-event-tickets' ),
					'add_new'            => __( 'Add event', 'lumaha-simple-event-tickets' ),
					'add_new_item'       => __( 'Add new event', 'lumaha-simple-event-tickets' ),
					'edit_item'          => __( 'Edit event', 'lumaha-simple-event-tickets' ),
					'new_item'           => __( 'New event', 'lumaha-simple-event-tickets' ),
					'view_item'          => __( 'View event', 'lumaha-simple-event-tickets' ),
					'search_items'       => __( 'Search events', 'lumaha-simple-event-tickets' ),
					'not_found'          => __( 'No events found.', 'lumaha-simple-event-tickets' ),
					'not_found_in_trash' => __( 'No events in the bin.', 'lumaha-simple-event-tickets' ),
					'all_items'          => __( 'All events', 'lumaha-simple-event-tickets' ),
					'menu_name'          => __( 'Events', 'lumaha-simple-event-tickets' ),
				),
				'public'          => true,
				'show_in_rest'    => false, // Classic editor: our boxes sit under the content.
				'has_archive'     => false, // The Events page (with [lset_events]) is the list.
				'rewrite'         => array(
					'slug'       => $slug,
					'with_front' => false,
				),
				'menu_icon'       => 'dashicons-tickets-alt',
				'menu_position'   => 56,
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type' => array( 'lset_event', 'lset_events' ),
				'map_meta_cap'    => true,
				// Anyone who can run the shop can run events.
				'capabilities'    => array(
					'edit_posts'             => 'manage_woocommerce',
					'edit_others_posts'      => 'manage_woocommerce',
					'edit_private_posts'     => 'manage_woocommerce',
					'edit_published_posts'   => 'manage_woocommerce',
					'publish_posts'          => 'manage_woocommerce',
					'read_private_posts'     => 'manage_woocommerce',
					'delete_posts'           => 'manage_woocommerce',
					'delete_others_posts'    => 'manage_woocommerce',
					'delete_private_posts'   => 'manage_woocommerce',
					'delete_published_posts' => 'manage_woocommerce',
					'create_posts'           => 'manage_woocommerce',
				),
			)
		);

		register_taxonomy(
			LSET_VENUE,
			LSET_CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Venues', 'lumaha-simple-event-tickets' ),
					'singular_name' => __( 'Venue', 'lumaha-simple-event-tickets' ),
					'add_new_item'  => __( 'Add new venue', 'lumaha-simple-event-tickets' ),
					'edit_item'     => __( 'Edit venue', 'lumaha-simple-event-tickets' ),
					'search_items'  => __( 'Search venues', 'lumaha-simple-event-tickets' ),
					'not_found'     => __( 'No venues found.', 'lumaha-simple-event-tickets' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'meta_box_cb'       => false, // Picked in the Event details box instead.
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_woocommerce',
					'edit_terms'   => 'manage_woocommerce',
					'delete_terms' => 'manage_woocommerce',
					'assign_terms' => 'manage_woocommerce',
				),
			)
		);
	}

	public static function maybe_flush() {
		if ( get_option( 'lset_flush_rewrites' ) ) {
			delete_option( 'lset_flush_rewrites' );
			flush_rewrite_rules();
		}
	}

	/**
	 * EventPrime linked to /events/?event=123. Send those to the migrated event (saved in _lset_ep_id).
	 */
	public static function redirect_old_urls() {
		if ( empty( $_GET['event'] ) || is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
			return;
		}
		$old = absint( wp_unslash( $_GET['event'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $old ) {
			return;
		}
		$found = get_posts(
			array(
				'post_type'      => LSET_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lset_ep_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'     => $old, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			)
		);
		if ( $found ) {
			wp_safe_redirect( get_permalink( $found[0] ), 301 );
			exit;
		}
	}

	/**
	 * Copy an event, its tickets, groups and fields, with every date moved by $modifier.
	 * The copy is a draft with no bookings. Returns the new event's id or a WP_Error.
	 *
	 * @param int    $event_id Event to copy.
	 * @param string $modifier e.g. "+1 year", "+52 weeks", "+364 days".
	 */
	public static function duplicate( $event_id, $modifier ) {
		$post = get_post( $event_id );
		if ( ! $post || LSET_CPT !== $post->post_type ) {
			return new WP_Error( 'lset_no_event', __( 'Event not found.', 'lumaha-simple-event-tickets' ) );
		}
		$event = LSET_Store::event( $post );

		$title = $post->post_title;
		// "Winter Disco 2026" → "Winter Disco 2027" when the year moves.
		$old_year = $event['start'] ? (int) substr( $event['start'], 0, 4 ) : 0;
		$new_year = $event['start'] ? (int) substr( LSET_Time::shift( $event['start'], $modifier, $event['tz'] ), 0, 4 ) : 0;
		if ( $old_year && $new_year && $old_year !== $new_year && false !== strpos( $title, (string) $old_year ) ) {
			$title = str_replace( (string) $old_year, (string) $new_year, $title );
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => LSET_CPT,
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_author'  => get_current_user_id() ? get_current_user_id() : $post->post_author,
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$thumb = get_post_thumbnail_id( $post );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, $thumb );
		}
		$venues = wp_get_object_terms( $event_id, LSET_VENUE, array( 'fields' => 'ids' ) );
		if ( $venues && ! is_wp_error( $venues ) ) {
			wp_set_object_terms( $new_id, array_map( 'intval', $venues ), LSET_VENUE );
		}

		foreach ( array( '_lset_bookings', '_lset_closed_msg', '_lset_ticket_note', '_lset_name_fields', '_lset_attendee_fields', '_lset_booking_fields' ) as $key ) {
			$v = get_post_meta( $event_id, $key, true );
			if ( '' !== $v ) {
				update_post_meta( $new_id, $key, wp_slash( $v ) );
			}
		}
		LSET_Store::save_dates(
			$new_id,
			$event['start'] ? LSET_Time::shift( $event['start'], $modifier, $event['tz'] ) : '',
			$event['end'] ? LSET_Time::shift( $event['end'], $modifier, $event['tz'] ) : '',
			$event['all_day']
		);

		$group_map = array();
		foreach ( LSET_Store::groups( $event_id ) as $g ) {
			$old_gid             = $g['id'];
			$g['id']             = 0;
			$g['event_id']       = $new_id;
			$g['ep_id']          = null;
			$group_map[ $old_gid ] = LSET_Store::save_group( $g );
		}
		foreach ( LSET_Store::tickets( $event_id ) as $t ) {
			$t['id']       = 0;
			$t['event_id'] = $new_id;
			$t['ep_id']    = null;
			$t['group_id'] = isset( $group_map[ $t['group_id'] ] ) ? $group_map[ $t['group_id'] ] : 0;
			LSET_Store::save_ticket( $t );
		}
		LSET_Store::save_sale_window(
			$new_id,
			$event['sale_mode'],
			$event['sale_start'] ? LSET_Time::shift( $event['sale_start'], $modifier, $event['tz'] ) : '',
			$event['sale_days'],
			$event['sale_end'] ? LSET_Time::shift( $event['sale_end'], $modifier, $event['tz'] ) : ''
		);
		return (int) $new_id;
	}
}
