<?php
/**
 * Orders: the ticket details saved on each order line, and booking + attendee rows that
 * follow the order's status (HPOS-safe: we only use the WC_Order API and our own tables).
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Orders {

	const META_EVENT     = '_lset_event_id';
	const META_TICKET    = '_lset_ticket_id';
	const META_UID       = '_lset_uid';
	const META_ATTENDEES = '_lset_attendees';
	const META_BOOKING   = '_lset_booking';
	const META_LABELS    = '_lset_labels';

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_line' ), 10, 4 );

		// Booking rows: when the order is placed (classic and block checkout), on every status
		// change, after refunds, and when an admin saves an order.
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync' ) );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'refund_deleted' ), 10, 2 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'sync' ), 60 );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'trashed' ) );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'trashed' ) );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'sync' ) );

		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'auto_complete' ), 10, 3 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'old_eventprime_meta' ), 10, 2 );
	}

	/**
	 * Old EventPrime orders keep their own line meta. Once EventPrime is off, nothing formats it,
	 * so show ep_tickets_data as "2 × Adult" and the booking id as plain text (admin only).
	 */
	public static function old_eventprime_meta( $meta, $item ) {
		foreach ( $meta as $id => $m ) {
			if ( 'ep_booking_id' === $m->key ) {
				if ( is_admin() ) {
					$m->display_key   = __( 'EventPrime booking', 'lumaha-simple-event-tickets' );
					$m->display_value = '#' . absint( $m->value );
				} else {
					unset( $meta[ $id ] );
				}
			} elseif ( 'ep_tickets_data' === $m->key ) {
				$lines = array();
				$data  = is_array( $m->value ) ? $m->value : json_decode( (string) $m->value, true );
				$data  = null === $data ? maybe_unserialize( $m->value ) : $data;
				foreach ( is_array( $data ) ? $data : array() as $t ) {
					if ( is_array( $t ) && isset( $t['name'] ) && is_scalar( $t['name'] ) ) {
						$qty     = isset( $t['qty'] ) ? (int) $t['qty'] : 1;
						$lines[] = $qty . ' × ' . $t['name'];
					}
				}
				if ( $lines ) {
					$m->display_key   = __( 'Tickets', 'lumaha-simple-event-tickets' );
					$m->display_value = esc_html( implode( ', ', $lines ) );
				} else {
					unset( $meta[ $id ] );
				}
			}
		}
		unset( $item );
		return $meta;
	}

	/** Copy the ticket and attendee details from the basket onto the order line. */
	public static function save_line( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['lset'] ) ) {
			return;
		}
		$l = $values['lset'];
		$e = LSET_Store::event( $l['event_id'] );

		// Readable copies, shown in emails, My Account and the admin order screen.
		if ( $e ) {
			$item->add_meta_data( __( 'Event', 'lumaha-simple-event-tickets' ), $e['title'], true );
			$item->add_meta_data( __( 'Date', 'lumaha-simple-event-tickets' ), LSET_Frontend::when( $e ), true );
			if ( $e['venue'] ) {
				$item->add_meta_data( __( 'Venue', 'lumaha-simple-event-tickets' ), LSET_Frontend::venue_line( $e['venue'] ), true );
			}
		}
		foreach ( LSET_Cart::attendee_lines( $l ) as $k => $v ) {
			$item->add_meta_data( $k, $v, true );
		}

		// Our own copies, hidden from customers (leading underscore).
		$item->add_meta_data( self::META_EVENT, (int) $l['event_id'], true );
		$item->add_meta_data( self::META_TICKET, (int) $l['ticket_id'], true );
		$item->add_meta_data( self::META_UID, (string) $l['uid'], true );
		$item->add_meta_data( self::META_ATTENDEES, (array) $l['attendees'], true );
		$item->add_meta_data( self::META_BOOKING, (array) $l['booking'], true );
		$item->add_meta_data( self::META_LABELS, (array) $l['labels'], true );
		unset( $cart_item_key, $order );
	}

	public static function status_changed( $order_id, $from, $to, $order ) {
		self::sync( $order ? $order : $order_id );
		unset( $from, $to );
	}

	public static function refund_deleted( $refund_id, $order_id ) {
		self::sync( $order_id );
		unset( $refund_id );
	}

	/** Orders that only hold event tickets skip "Processing". */
	public static function auto_complete( $status, $order_id, $order = null ) {
		if ( ! Lumaha_Simple_Event_Tickets::setting( 'auto_complete' ) ) {
			return $status;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! count( $order->get_items() ) ) {
			return $status;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item->get_meta( self::META_TICKET ) ) {
				return $status;
			}
		}
		return 'completed';
	}

	public static function trashed( $order_id ) {
		global $wpdb;
		$wpdb->update(
			LSET_Install::table( 'bookings' ),
			array(
				'status'     => 'trash',
				'updated_at' => LSET_Time::now_utc(),
			),
			array( 'order_id' => (int) $order_id )
		);
	}

	/**
	 * Make the booking rows match the order: one row per ticket line. Safe to call any number of times.
	 *
	 * @param int|WC_Order $order Order.
	 */
	public static function sync( $order ) {
		global $wpdb;
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || $order instanceof WC_Order_Refund ) {
			return;
		}
		$status = $order->get_status();
		if ( 'checkout-draft' === $status ) {
			return; // Block checkout's draft: the basket holds still cover it.
		}
		$table = LSET_Install::table( 'bookings' );
		$now   = LSET_Time::now_utc();
		$seen  = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$ticket_id = (int) $item->get_meta( self::META_TICKET );
			if ( ! $ticket_id ) {
				continue;
			}
			$event_id = (int) $item->get_meta( self::META_EVENT );
			$seen[]   = (int) $item_id;
			$qty      = (int) $item->get_quantity();
			$refunded = absint( $order->get_qty_refunded_for_item( $item_id ) );
			$row      = array(
				'status'       => $status,
				'qty'          => $qty,
				'refunded_qty' => min( $qty, $refunded ),
				'unit_price'   => $qty ? round( (float) $item->get_total() / $qty, 2 ) : 0,
				'line_total'   => round( (float) $item->get_total(), 2 ),
				'buyer_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'buyer_email'  => (string) $order->get_billing_email(),
				'buyer_phone'  => (string) $order->get_billing_phone(),
				'user_id'      => (int) $order->get_customer_id(),
				'updated_at'   => $now,
			);
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM $table WHERE order_item_id = %d", $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $existing ) {
				if ( in_array( $status, LSET_Capacity::HELD, true ) && ! in_array( $existing['status'], LSET_Capacity::HELD, true ) ) {
					$row['hold_until'] = LSET_Time::now_utc( LSET_Capacity::hold_seconds() );
				}
				$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
				$booking_id = (int) $existing['id'];
				$created    = false;
			} else {
				// A new booking takes over its basket hold: write it, then drop the hold, inside the event lock.
				$uid        = (string) $item->get_meta( self::META_UID );
				$booking_id = LSET_Capacity::with_lock(
					$event_id,
					function () use ( $wpdb, $table, $row, $event_id, $ticket_id, $order, $item, $item_id, $now, $uid, $status ) {
						$again = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE order_item_id = %d", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( $again ) {
							return $again;
						}
						$booking = (array) $item->get_meta( self::META_BOOKING );
						$wpdb->insert(
							$table,
							array_merge(
								$row,
								array(
									'event_id'       => $event_id,
									'ticket_id'      => $ticket_id,
									'order_id'       => $order->get_id(),
									'order_item_id'  => $item_id,
									'source'         => $order->get_created_via() === 'admin' ? 'manual' : 'checkout',
									'hold_until'     => in_array( $status, LSET_Capacity::HELD, true ) ? LSET_Time::now_utc( LSET_Capacity::hold_seconds() ) : null,
									'booking_fields' => wp_json_encode( self::labelled( $booking, (array) $item->get_meta( self::META_LABELS ) ) ),
									'created_at'     => $now,
								)
							)
						);
						$id = (int) $wpdb->insert_id;
						if ( $uid ) {
							LSET_Capacity::release( array( $uid ) );
						}
						return $id;
					}
				);
				if ( is_wp_error( $booking_id ) ) {
					$order->add_order_note( 'Event tickets: could not record the booking (' . $booking_id->get_error_message() . '). It will be recorded on the next status change.' );
					continue;
				}
				$created = true;
			}
			if ( $created ) {
				self::insert_attendees( $booking_id, $event_id, $ticket_id, $item );
				self::check_oversold( $event_id, $order );
			} elseif ( in_array( $status, LSET_Capacity::COUNTED, true ) && ! in_array( $existing['status'], LSET_Capacity::COUNTED, true ) ) {
				self::check_oversold( $event_id, $order );
			}
		}

		// Ticket lines removed from the order by an admin.
		$placeholders = $seen ? implode( ',', array_fill( 0, count( $seen ), '%d' ) ) : '0';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'removed', updated_at = %s WHERE order_id = %d AND source IN ('checkout','manual') AND order_item_id NOT IN ($placeholders)", array_merge( array( $now, $order->get_id() ), $seen ) ) );

		// Bookings copied from EventPrime follow their order's status. EventPrime put every ticket on one
		// order line, so a part refund can't be matched to a ticket: only a full refund frees the places.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = %s, refunded_qty = IF(%s = 'refunded', qty, 0), updated_at = %s WHERE order_id = %d AND source = 'migrated'", $status, $status, $now, $order->get_id() ) );
	}

	/** { field_id: value } → { field_id: { label, value } }, so the answers stay readable. */
	private static function labelled( array $values, array $labels ) {
		$out = array();
		foreach ( $values as $fid => $v ) {
			$out[ $fid ] = array(
				'label' => isset( $labels[ $fid ] ) ? $labels[ $fid ] : '',
				'value' => $v,
			);
		}
		return $out;
	}

	private static function insert_attendees( $booking_id, $event_id, $ticket_id, $item ) {
		global $wpdb;
		$people = (array) $item->get_meta( self::META_ATTENDEES );
		$labels = (array) $item->get_meta( self::META_LABELS );
		$qty    = (int) $item->get_quantity();
		for ( $i = 0; $i < $qty; $i++ ) {
			$p = isset( $people[ $i ] ) ? (array) $people[ $i ] : array();
			$wpdb->insert(
				LSET_Install::table( 'attendees' ),
				array(
					'booking_id' => $booking_id,
					'event_id'   => $event_id,
					'ticket_id'  => $ticket_id,
					'seq'        => $i + 1,
					'first_name' => isset( $p['first_name'] ) ? (string) $p['first_name'] : '',
					'last_name'  => isset( $p['last_name'] ) ? (string) $p['last_name'] : '',
					'fields'     => wp_json_encode( self::labelled( isset( $p['fields'] ) ? (array) $p['fields'] : array(), $labels ) ),
				)
			);
		}
	}

	/**
	 * A payment that finished long after its hold ran out could take the count over capacity.
	 * We can't refuse a payment that's already been taken, so flag it for the committee.
	 */
	private static function check_oversold( $event_id, WC_Order $order ) {
		$tickets = LSET_Store::tickets( $event_id );
		$groups  = LSET_Store::groups( $event_id );
		$taken   = LSET_Capacity::taken( $event_id );
		$over    = array();
		$g_taken = array();
		foreach ( $tickets as $t ) {
			$n = isset( $taken[ $t['id'] ] ) ? $taken[ $t['id'] ] : 0;
			if ( null !== $t['capacity'] && $n > $t['capacity'] ) {
				$over[] = sprintf( '%s (%d of %d)', $t['name'], $n, $t['capacity'] );
			}
			if ( $t['group_id'] ) {
				$g_taken[ $t['group_id'] ] = ( isset( $g_taken[ $t['group_id'] ] ) ? $g_taken[ $t['group_id'] ] : 0 ) + $n;
			}
		}
		foreach ( $g_taken as $gid => $n ) {
			if ( isset( $groups[ $gid ] ) && null !== $groups[ $gid ]['capacity'] && $n > $groups[ $gid ]['capacity'] ) {
				$over[] = sprintf( '%s (%d of %d)', $groups[ $gid ]['name'], $n, $groups[ $gid ]['capacity'] );
			}
		}
		if ( $over ) {
			$order->add_order_note( 'Event tickets: this order takes these tickets over capacity: ' . implode( ', ', $over ) . '. The payment finished after the basket hold ran out. Please check.' );
		}
	}
}
