<?php
/**
 * Reading and writing events, tickets, ticket groups and fields.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Store {

	const FIELD_TYPES = array( 'text', 'number', 'textarea', 'select', 'checkbox' );

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	/**
	 * Everything about an event that the rest of the plugin needs, in one array.
	 *
	 * @param int|WP_Post $post Event.
	 * @return array|null
	 */
	public static function event( $post ) {
		$post = get_post( $post );
		if ( ! $post || LSET_CPT !== $post->post_type ) {
			return null;
		}
		$id    = (int) $post->ID;
		$tz    = (string) get_post_meta( $id, '_lset_tz', true );
		$venue = null;
		$terms = get_the_terms( $id, LSET_VENUE );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$venue = self::venue( $terms[0] );
		}
		return array(
			'id'          => $id,
			'title'       => get_the_title( $post ),
			'status'      => $post->post_status,
			'start'       => (string) get_post_meta( $id, '_lset_start', true ),
			'end'         => (string) get_post_meta( $id, '_lset_end', true ),
			'tz'          => $tz ? $tz : wp_timezone_string(),
			'all_day'     => (bool) get_post_meta( $id, '_lset_all_day', true ),
			'bookings'    => (bool) get_post_meta( $id, '_lset_bookings', true ),
			'closed_msg'  => (string) get_post_meta( $id, '_lset_closed_msg', true ),
			'name_fields' => self::name_fields_mode( $id ),
			// When tickets are on sale, for the whole event.
			'sale_mode'   => (string) get_post_meta( $id, '_lset_sale_mode', true ),
			'sale_start'  => (string) get_post_meta( $id, '_lset_sale_start', true ),
			'sale_days'   => '' === get_post_meta( $id, '_lset_sale_days', true ) ? null : (int) get_post_meta( $id, '_lset_sale_days', true ),
			'sale_end'    => (string) get_post_meta( $id, '_lset_sale_end', true ),
			'venue'       => $venue,
			'url'         => get_permalink( $post ),
		);
	}

	/** First and last name per attendee: 'required', 'optional' or 'off'. */
	public static function name_fields_mode( $event_id ) {
		$v = (string) get_post_meta( $event_id, '_lset_name_fields', true );
		return in_array( $v, array( 'required', 'optional', 'off' ), true ) ? $v : 'required';
	}

	/** Save the event's start/end and keep the UTC copies (used only for sorting and "upcoming"). */
	public static function save_dates( $event_id, $start, $end, $all_day = false ) {
		$tz = wp_timezone_string();
		if ( '' === $end || ( $start && LSET_Time::ts( $end ) < LSET_Time::ts( $start ) ) ) {
			$end = $start;
		}
		update_post_meta( $event_id, '_lset_start', $start );
		update_post_meta( $event_id, '_lset_end', $end );
		update_post_meta( $event_id, '_lset_tz', $tz );
		update_post_meta( $event_id, '_lset_all_day', $all_day ? 1 : 0 );
		update_post_meta( $event_id, '_lset_start_utc', LSET_Time::ts( $start, $tz ) );
		// An all-day event runs to the end of its last day.
		$end_ts = LSET_Time::ts( $end, $tz );
		if ( $all_day && $end_ts ) {
			$end_ts = LSET_Time::ts( substr( $end, 0, 10 ) . ' 23:59:59', $tz );
		}
		update_post_meta( $event_id, '_lset_end_utc', $end_ts );
	}

	public static function venue( $term ) {
		$term = is_object( $term ) ? $term : get_term( (int) $term, LSET_VENUE );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return array(
			'id'       => (int) $term->term_id,
			'name'     => $term->name,
			'address'  => (string) get_term_meta( $term->term_id, 'lset_address', true ),
			'postcode' => (string) get_term_meta( $term->term_id, 'lset_postcode', true ),
			'map_url'  => (string) get_term_meta( $term->term_id, 'lset_map_url', true ),
		);
	}

	/**
	 * Attendee or booking fields attached to an event, joined to the field library.
	 *
	 * @param int    $event_id Event.
	 * @param string $level    'attendee' or 'booking'.
	 * @return array[] Each: id, label, type, options[], placeholder, required.
	 */
	public static function event_fields( $event_id, $level ) {
		$attached = json_decode( (string) get_post_meta( $event_id, '_lset_' . $level . '_fields', true ), true );
		if ( ! is_array( $attached ) || ! $attached ) {
			return array();
		}
		$library = self::fields();
		$out     = array();
		foreach ( $attached as $a ) {
			$fid = isset( $a['field_id'] ) ? (int) $a['field_id'] : 0;
			if ( ! isset( $library[ $fid ] ) ) {
				continue;
			}
			$f             = $library[ $fid ];
			$f['required'] = ! empty( $a['required'] );
			$out[]         = $f;
		}
		return $out;
	}

	/** @param array $list Each: field_id, required. */
	public static function set_event_fields( $event_id, $level, array $list ) {
		$clean = array();
		foreach ( $list as $a ) {
			if ( ! empty( $a['field_id'] ) ) {
				$clean[] = array(
					'field_id' => (int) $a['field_id'],
					'required' => empty( $a['required'] ) ? 0 : 1,
				);
			}
		}
		update_post_meta( $event_id, '_lset_' . $level . '_fields', wp_slash( wp_json_encode( $clean ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Field library
	 * ------------------------------------------------------------------ */

	/** @return array[] Keyed by id. */
	public static function fields() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . LSET_Install::table( 'fields' ) . ' ORDER BY sort_order, label', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = self::field_row( $r );
		}
		return $out;
	}

	public static function field( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LSET_Install::table( 'fields' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? self::field_row( $r ) : null;
	}

	private static function field_row( array $r ) {
		$options = json_decode( (string) $r['options'], true );
		return array(
			'id'          => (int) $r['id'],
			'label'       => $r['label'],
			'type'        => in_array( $r['type'], self::FIELD_TYPES, true ) ? $r['type'] : 'text',
			'options'     => is_array( $options ) ? array_values( array_map( 'strval', $options ) ) : array(),
			'placeholder' => (string) $r['placeholder'],
			'sort_order'  => (int) $r['sort_order'],
			'ep_id'       => $r['ep_id'] ? (int) $r['ep_id'] : null,
		);
	}

	/** Insert or update a library field. Returns its id. */
	public static function save_field( array $f ) {
		global $wpdb;
		$type = isset( $f['type'] ) && in_array( $f['type'], self::FIELD_TYPES, true ) ? $f['type'] : 'text';
		$opts = isset( $f['options'] ) ? (array) $f['options'] : array();
		$opts = array_values( array_filter( array_map( 'trim', array_map( 'strval', $opts ) ), 'strlen' ) );
		$data = array(
			'label'       => isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : '',
			'type'        => $type,
			'options'     => wp_json_encode( $opts ),
			'placeholder' => isset( $f['placeholder'] ) ? sanitize_text_field( $f['placeholder'] ) : '',
			'sort_order'  => isset( $f['sort_order'] ) ? (int) $f['sort_order'] : 0,
		);
		if ( array_key_exists( 'ep_id', $f ) ) {
			$data['ep_id'] = $f['ep_id'] ? (int) $f['ep_id'] : null;
		}
		$table = LSET_Install::table( 'fields' );
		if ( ! empty( $f['id'] ) ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $f['id'] ) );
			return (int) $f['id'];
		}
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	public static function delete_field( $id ) {
		global $wpdb;
		$wpdb->delete( LSET_Install::table( 'fields' ), array( 'id' => (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Tickets and groups
	 * ------------------------------------------------------------------ */

	/**
	 * @param int  $event_id     Event.
	 * @param bool $with_hidden  Include hidden tickets.
	 * @return array[] Keyed by id, in display order.
	 */
	public static function tickets( $event_id, $with_hidden = true ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LSET_Install::table( 'tickets' ) . ' WHERE event_id = %d ORDER BY sort_order, id', $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$t = self::ticket_row( $r );
			if ( $with_hidden || ! $t['hidden'] ) {
				$out[ $t['id'] ] = $t;
			}
		}
		return $out;
	}

	public static function ticket( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LSET_Install::table( 'tickets' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? self::ticket_row( $r ) : null;
	}

	private static function ticket_row( array $r ) {
		return array(
			'id'              => (int) $r['id'],
			'event_id'        => (int) $r['event_id'],
			'group_id'        => (int) $r['group_id'],
			'name'            => $r['name'],
			'description'     => (string) $r['description'],
			'price'           => (float) $r['price'],
			'capacity'        => null === $r['capacity'] ? null : (int) $r['capacity'],
			'sale_start_mode' => (string) $r['sale_start_mode'],
			'sale_start'      => (string) $r['sale_start'],
			'sale_start_days' => null === $r['sale_start_days'] ? null : (int) $r['sale_start_days'],
			'sale_end'        => (string) $r['sale_end'],
			'min_qty'         => (int) $r['min_qty'],
			'max_qty'         => (int) $r['max_qty'],
			'sort_order'      => (int) $r['sort_order'],
			'hidden'          => (bool) $r['hidden'],
			'show_left_below' => null === $r['show_left_below'] ? null : (int) $r['show_left_below'],
			'ep_id'           => $r['ep_id'] ? (int) $r['ep_id'] : null,
		);
	}

	/** Insert or update a ticket. Returns its id. */
	public static function save_ticket( array $t ) {
		global $wpdb;
		$nullable_int = function ( $v ) {
			return ( null === $v || '' === $v ) ? null : max( 0, (int) $v );
		};
		$mode = isset( $t['sale_start_mode'] ) && in_array( $t['sale_start_mode'], array( 'date', 'days' ), true ) ? $t['sale_start_mode'] : '';
		$data = array(
			'event_id'        => (int) $t['event_id'],
			'group_id'        => isset( $t['group_id'] ) ? (int) $t['group_id'] : 0,
			'name'            => isset( $t['name'] ) ? sanitize_text_field( $t['name'] ) : '',
			'description'     => isset( $t['description'] ) ? sanitize_textarea_field( $t['description'] ) : '',
			'price'           => isset( $t['price'] ) ? round( max( 0, (float) $t['price'] ), 2 ) : 0,
			'capacity'        => $nullable_int( isset( $t['capacity'] ) ? $t['capacity'] : null ),
			'sale_start_mode' => $mode,
			'sale_start'      => 'date' === $mode && ! empty( $t['sale_start'] ) ? $t['sale_start'] : null,
			'sale_start_days' => 'days' === $mode ? $nullable_int( isset( $t['sale_start_days'] ) ? $t['sale_start_days'] : null ) : null,
			'sale_end'        => ! empty( $t['sale_end'] ) ? $t['sale_end'] : null,
			'min_qty'         => isset( $t['min_qty'] ) ? max( 0, (int) $t['min_qty'] ) : 0,
			'max_qty'         => isset( $t['max_qty'] ) ? max( 0, (int) $t['max_qty'] ) : 0,
			'sort_order'      => isset( $t['sort_order'] ) ? (int) $t['sort_order'] : 0,
			'hidden'          => empty( $t['hidden'] ) ? 0 : 1,
			'show_left_below' => $nullable_int( isset( $t['show_left_below'] ) ? $t['show_left_below'] : null ),
		);
		if ( array_key_exists( 'ep_id', $t ) ) {
			$data['ep_id'] = $t['ep_id'] ? (int) $t['ep_id'] : null;
		}
		$table = LSET_Install::table( 'tickets' );
		if ( ! empty( $t['id'] ) ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $t['id'] ) );
			return (int) $t['id'];
		}
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/** A ticket that has bookings is hidden instead of deleted, so the register keeps its name. */
	public static function delete_ticket( $id ) {
		global $wpdb;
		$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LSET_Install::table( 'bookings' ) . ' WHERE ticket_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $used ) {
			$wpdb->update( LSET_Install::table( 'tickets' ), array( 'hidden' => 1 ), array( 'id' => (int) $id ) );
			return false;
		}
		$wpdb->delete( LSET_Install::table( 'tickets' ), array( 'id' => (int) $id ) );
		return true;
	}

	/** @return array[] Keyed by id. */
	public static function groups( $event_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LSET_Install::table( 'ticket_groups' ) . ' WHERE event_id = %d ORDER BY sort_order, id', $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = array(
				'id'         => (int) $r['id'],
				'event_id'   => (int) $r['event_id'],
				'name'       => $r['name'],
				'capacity'   => null === $r['capacity'] ? null : (int) $r['capacity'],
				'sort_order' => (int) $r['sort_order'],
				'ep_id'      => $r['ep_id'] ? (int) $r['ep_id'] : null,
			);
		}
		return $out;
	}

	public static function save_group( array $g ) {
		global $wpdb;
		$data = array(
			'event_id'   => (int) $g['event_id'],
			'name'       => sanitize_text_field( isset( $g['name'] ) ? $g['name'] : '' ),
			'capacity'   => ( ! isset( $g['capacity'] ) || '' === $g['capacity'] || null === $g['capacity'] ) ? null : max( 0, (int) $g['capacity'] ),
			'sort_order' => isset( $g['sort_order'] ) ? (int) $g['sort_order'] : 0,
		);
		if ( array_key_exists( 'ep_id', $g ) ) {
			$data['ep_id'] = $g['ep_id'] ? (int) $g['ep_id'] : null;
		}
		$table = LSET_Install::table( 'ticket_groups' );
		if ( ! empty( $g['id'] ) ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $g['id'] ) );
			return (int) $g['id'];
		}
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	public static function delete_group( $id ) {
		global $wpdb;
		$wpdb->update( LSET_Install::table( 'tickets' ), array( 'group_id' => 0 ), array( 'group_id' => (int) $id ) );
		$wpdb->delete( LSET_Install::table( 'ticket_groups' ), array( 'id' => (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * When a ticket is on sale
	 * ------------------------------------------------------------------ */

	/**
	 * When the event's tickets go on sale, as a local datetime string, or '' (on sale now).
	 * The window is set once per event; $ticket is unused and kept for callers.
	 */
	public static function sale_start( $ticket, array $event ) {
		unset( $ticket );
		return self::window_start( $event['sale_mode'], $event['sale_start'], $event['sale_days'], $event );
	}

	/** When sales end, as a local datetime string: the event's own, else the event start. */
	public static function sale_end( $ticket, array $event ) {
		unset( $ticket );
		return $event['sale_end'] ? $event['sale_end'] : $event['start'];
	}

	/** 'date' + datetime, or 'days' + N (midnight N days before the event day, local time), or '' = now. */
	private static function window_start( $mode, $start, $days, array $event ) {
		if ( 'date' === $mode && $start ) {
			return $start;
		}
		if ( 'days' === $mode && null !== $days && '' !== $days && $event['start'] ) {
			return LSET_Time::shift( substr( $event['start'], 0, 10 ) . ' 00:00:00', '-' . (int) $days . ' days', $event['tz'] );
		}
		return '';
	}

	/**
	 * Save an event's sale window.
	 *
	 * @param int    $event_id Event.
	 * @param string $mode     '', 'date' or 'days'.
	 * @param string $start    Local datetime (for 'date').
	 * @param mixed  $days     Days before the event (for 'days').
	 * @param string $end      Local datetime, or '' for the event start.
	 */
	public static function save_sale_window( $event_id, $mode, $start, $days, $end ) {
		$mode = in_array( $mode, array( 'date', 'days' ), true ) ? $mode : '';
		update_post_meta( $event_id, '_lset_sale_mode', $mode );
		update_post_meta( $event_id, '_lset_sale_start', 'date' === $mode ? (string) $start : '' );
		update_post_meta( $event_id, '_lset_sale_days', 'days' === $mode && '' !== (string) $days && null !== $days ? max( 0, (int) $days ) : '' );
		update_post_meta( $event_id, '_lset_sale_end', (string) $end );
	}

	/**
	 * Work out one sale window for an event from its tickets' old per-ticket windows: it opens
	 * when the first ticket did and closes when the last one did. Used once on upgrade, and by
	 * the EventPrime migrator, which reads per-ticket windows.
	 */
	public static function sale_window_from_tickets( $event_id ) {
		$event   = self::event( $event_id );
		$tickets = self::tickets( $event_id, false );
		$tickets = $tickets ? $tickets : self::tickets( $event_id );
		if ( ! $event || ! $tickets ) {
			return;
		}
		$starts = array();
		$days   = array();
		$ends   = array();
		$now    = false;
		foreach ( $tickets as $t ) {
			$s = self::window_start( $t['sale_start_mode'], $t['sale_start'], $t['sale_start_days'], $event );
			if ( '' === $s ) {
				$now = true;
			} else {
				$starts[] = $s;
			}
			$days[] = 'days' === $t['sale_start_mode'] ? $t['sale_start_days'] : null;
			$ends[] = $t['sale_end'];
		}
		$mode  = '';
		$start = '';
		$n     = null;
		if ( ! $now ) {
			if ( ! in_array( null, $days, true ) ) {
				$mode = 'days';
				$n    = max( $days );
			} else {
				$mode  = 'date';
				$start = min( $starts );
			}
		}
		$end = in_array( '', $ends, true ) ? '' : max( $ends );
		self::save_sale_window( $event_id, $mode, $start, $n, $end );
	}

	/**
	 * Whether a ticket can be bought right now.
	 *
	 * @return string '' when on sale, else 'not_yet', 'ended', 'hidden' or 'closed'.
	 */
	public static function sale_state( array $ticket, array $event, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! $event['bookings'] || 'publish' !== $event['status'] ) {
			return 'closed';
		}
		if ( $ticket['hidden'] ) {
			return 'hidden';
		}
		$start = self::sale_start( $ticket, $event );
		if ( $start && LSET_Time::ts( $start, $event['tz'] ) > $now ) {
			return 'not_yet';
		}
		$end = self::sale_end( $ticket, $event );
		if ( $end && LSET_Time::ts( $end, $event['tz'] ) <= $now ) {
			return 'ended';
		}
		return '';
	}

	/**
	 * The most of one ticket the booking box offers in one go. There's no per-order limit to set;
	 * this only keeps the quantity list a sensible length (capacity can lower it further).
	 */
	const MAX_PER_ORDER = 50;

	public static function max_per_order( $ticket = null ) {
		unset( $ticket );
		return self::MAX_PER_ORDER;
	}
}
