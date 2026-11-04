<?php
/**
 * Capacity: what's taken, what's left, and short holds for tickets in a basket.
 *
 * Taken = paid or on-hold bookings, minus refunded quantity
 *       + pending bookings whose hold hasn't run out (order placed, payment not finished)
 *       + live basket holds.
 *
 * Every change that can use up capacity (taking or extending a hold, turning a hold into a
 * booking) happens inside a MySQL named lock for the event, so two buyers can't both get the
 * last ticket. Reading availability needs no lock.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Capacity {

	/** Booking statuses that always count. */
	const COUNTED = array( 'on-hold', 'processing', 'completed' );

	/** Booking statuses that count only while their hold is live. */
	const HELD = array( 'pending', 'checkout-draft' );

	public static function hold_seconds() {
		return max( 1, (int) Lumaha_Simple_Event_Tickets::setting( 'hold_minutes' ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * How many of each ticket in an event are taken.
	 *
	 * @param int      $event_id     Event.
	 * @param string[] $exclude_uids Basket holds to leave out (the buyer's own, when re-checking).
	 * @return int[] ticket_id => taken.
	 */
	public static function taken( $event_id, array $exclude_uids = array() ) {
		global $wpdb;
		$now      = LSET_Time::now_utc();
		$bookings = LSET_Install::table( 'bookings' );
		$holds    = LSET_Install::table( 'holds' );

		$counted = "'" . implode( "','", self::COUNTED ) . "'";
		$held    = "'" . implode( "','", self::HELD ) . "'";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and fixed status lists.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ticket_id, SUM(GREATEST(qty - refunded_qty, 0)) AS n FROM $bookings
				WHERE event_id = %d AND ( status IN ($counted) OR ( status IN ($held) AND hold_until > %s ) )
				GROUP BY ticket_id",
				$event_id,
				$now
			),
			ARRAY_A
		);
		$taken = array();
		foreach ( (array) $rows as $r ) {
			$taken[ (int) $r['ticket_id'] ] = (int) $r['n'];
		}

		$sql  = "SELECT ticket_id, SUM(qty) AS n FROM $holds WHERE event_id = %d AND expires_at > %s";
		$args = array( $event_id, $now );
		if ( $exclude_uids ) {
			$sql .= ' AND uid NOT IN (' . implode( ',', array_fill( 0, count( $exclude_uids ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $exclude_uids ) );
		}
		$sql .= ' GROUP BY ticket_id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			$tid           = (int) $r['ticket_id'];
			$taken[ $tid ] = ( isset( $taken[ $tid ] ) ? $taken[ $tid ] : 0 ) + (int) $r['n'];
		}
		return $taken;
	}

	/**
	 * How many of each ticket can still be bought: the lower of the ticket's own room and its
	 * group's room. null = no limit.
	 *
	 * @return array ticket_id => int|null.
	 */
	public static function left( $event_id, array $exclude_uids = array(), $tickets = null, $groups = null ) {
		$tickets = null === $tickets ? LSET_Store::tickets( $event_id ) : $tickets;
		$groups  = null === $groups ? LSET_Store::groups( $event_id ) : $groups;
		$taken   = self::taken( $event_id, $exclude_uids );

		$group_taken = array();
		foreach ( $tickets as $t ) {
			if ( $t['group_id'] ) {
				$group_taken[ $t['group_id'] ] = ( isset( $group_taken[ $t['group_id'] ] ) ? $group_taken[ $t['group_id'] ] : 0 ) + ( isset( $taken[ $t['id'] ] ) ? $taken[ $t['id'] ] : 0 );
			}
		}

		$left = array();
		foreach ( $tickets as $t ) {
			$room = null;
			if ( null !== $t['capacity'] ) {
				$room = max( 0, $t['capacity'] - ( isset( $taken[ $t['id'] ] ) ? $taken[ $t['id'] ] : 0 ) );
			}
			if ( $t['group_id'] && isset( $groups[ $t['group_id'] ] ) && null !== $groups[ $t['group_id'] ]['capacity'] ) {
				$g_room = max( 0, $groups[ $t['group_id'] ]['capacity'] - ( isset( $group_taken[ $t['group_id'] ] ) ? $group_taken[ $t['group_id'] ] : 0 ) );
				$room   = null === $room ? $g_room : min( $room, $g_room );
			}
			$left[ $t['id'] ] = $room;
		}
		return $left;
	}

	/** Run $fn while holding the event's lock. Returns $fn's result, or a WP_Error if the lock wasn't free in time. */
	public static function with_lock( $event_id, callable $fn ) {
		global $wpdb;
		$name = $wpdb->prefix . 'lset_event_' . (int) $event_id;
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) );
		if ( 1 !== $got ) {
			return new WP_Error( 'lset_busy', __( 'Lots of people are booking right now. Please try again in a moment.', 'lumaha-simple-event-tickets' ) );
		}
		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Take (or extend) basket holds for one event, all or nothing.
	 *
	 * @param int    $event_id    Event.
	 * @param array  $lines       uid => [ ticket_id, qty ]. The uid is the cart line's own id.
	 * @param string $session_key Woo customer/session id.
	 * @return true|WP_Error
	 */
	public static function reserve( $event_id, array $lines, $session_key ) {
		return self::with_lock(
			$event_id,
			function () use ( $event_id, $lines, $session_key ) {
				global $wpdb;
				$tickets = LSET_Store::tickets( $event_id );
				$groups  = LSET_Store::groups( $event_id );
				$error   = self::check_room( $event_id, $lines, array_keys( $lines ), $tickets, $groups );
				if ( is_wp_error( $error ) ) {
					return $error;
				}
				$expires = LSET_Time::now_utc( self::hold_seconds() );
				$table   = LSET_Install::table( 'holds' );
				foreach ( $lines as $uid => $line ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "INSERT INTO $table (uid, event_id, ticket_id, qty, session_key, expires_at) VALUES (%s, %d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE ticket_id = VALUES(ticket_id), qty = VALUES(qty), session_key = VALUES(session_key), expires_at = VALUES(expires_at)", $uid, $event_id, $line[0], $line[1], (string) $session_key, $expires ) );
				}
				return true;
			}
		);
	}

	/**
	 * Is there room for these lines, leaving out the given holds? Ticket caps and group caps.
	 *
	 * @return true|WP_Error
	 */
	public static function check_room( $event_id, array $lines, array $exclude_uids, $tickets = null, $groups = null ) {
		$tickets = null === $tickets ? LSET_Store::tickets( $event_id ) : $tickets;
		$groups  = null === $groups ? LSET_Store::groups( $event_id ) : $groups;
		$left    = self::left( $event_id, $exclude_uids, $tickets, $groups );

		$want_ticket = array();
		$want_group  = array();
		foreach ( $lines as $line ) {
			list( $tid, $qty ) = $line;
			if ( ! isset( $tickets[ $tid ] ) ) {
				return new WP_Error( 'lset_ticket', __( 'That ticket is no longer available.', 'lumaha-simple-event-tickets' ) );
			}
			$want_ticket[ $tid ] = ( isset( $want_ticket[ $tid ] ) ? $want_ticket[ $tid ] : 0 ) + $qty;
			$gid                 = $tickets[ $tid ]['group_id'];
			if ( $gid ) {
				$want_group[ $gid ] = ( isset( $want_group[ $gid ] ) ? $want_group[ $gid ] : 0 ) + $qty;
			}
		}

		// Group room: the group's cap minus what's taken across all its tickets.
		$taken = self::taken( $event_id, $exclude_uids );
		foreach ( $want_group as $gid => $qty ) {
			if ( ! isset( $groups[ $gid ] ) || null === $groups[ $gid ]['capacity'] ) {
				continue;
			}
			$used = 0;
			foreach ( $tickets as $t ) {
				if ( $t['group_id'] === $gid ) {
					$used += isset( $taken[ $t['id'] ] ) ? $taken[ $t['id'] ] : 0;
				}
			}
			$room = max( 0, $groups[ $gid ]['capacity'] - $used );
			if ( $qty > $room ) {
				return self::not_enough( $groups[ $gid ]['name'], $room );
			}
		}
		foreach ( $want_ticket as $tid => $qty ) {
			$t = $tickets[ $tid ];
			if ( null === $t['capacity'] ) {
				continue;
			}
			$room = max( 0, $t['capacity'] - ( isset( $taken[ $tid ] ) ? $taken[ $tid ] : 0 ) );
			if ( $qty > $room ) {
				return self::not_enough( $t['name'], $room );
			}
		}
		return true;
	}

	private static function not_enough( $name, $room ) {
		if ( $room <= 0 ) {
			/* translators: %s: ticket or group name */
			return new WP_Error( 'lset_sold_out', sprintf( __( 'Sorry, "%s" has sold out.', 'lumaha-simple-event-tickets' ), $name ) );
		}
		/* translators: 1: number left, 2: ticket or group name */
		return new WP_Error( 'lset_not_enough', sprintf( _n( 'Sorry, there is only %1$d "%2$s" ticket left.', 'Sorry, there are only %1$d "%2$s" tickets left.', $room, 'lumaha-simple-event-tickets' ), $room, $name ) );
	}

	/** Drop basket holds (a line was removed). */
	public static function release( array $uids ) {
		global $wpdb;
		$uids = array_values( array_filter( array_map( 'strval', $uids ) ) );
		if ( ! $uids ) {
			return;
		}
		$table = LSET_Install::table( 'holds' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE uid IN (" . implode( ',', array_fill( 0, count( $uids ), '%s' ) ) . ')', $uids ) );
	}

	/** Cron: delete basket holds that have run out. Queries ignore them anyway; this keeps the table small. */
	public static function clear_expired() {
		global $wpdb;
		$table = LSET_Install::table( 'holds' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires_at <= %s", LSET_Time::now_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
