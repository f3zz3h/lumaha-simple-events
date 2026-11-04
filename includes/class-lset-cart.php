<?php
/**
 * Basket: the add-to-basket form handler, validation, holds, prices and what the basket shows.
 *
 * Each ticket type picked becomes one cart line of the hidden "Event ticket" product, with
 * cart item data under 'lset':
 *   event_id, ticket_id, uid (our id for the line and its hold),
 *   attendees: [ { first_name, last_name, fields: { field_id: value } } ],
 *   booking:   { field_id: value }  (once-per-booking answers, copied to each line of that add),
 *   labels:    { field_id: label }  (so a renamed field still reads right on old orders).
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Cart {

	/** True while we add our own lines, so direct ?add-to-cart= of the ticket product is refused. */
	private static $adding = false;

	/** Event id whose add was refused on this request (the box shows the buyer's answers again). */
	private static $rejected = 0;

	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'handle_add' ), 25 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_product' ), 5, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'lock_quantity_classic' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'lock_quantity_blocks' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'item_permalink' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'item_thumbnail' ), 10, 2 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'removed' ), 10, 2 );
		add_action( 'woocommerce_before_cart_emptied', array( __CLASS__, 'emptied' ) );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'hide_product' ), 10, 2 );
	}

	public static function is_ticket_product( $product_id ) {
		return (int) $product_id === (int) get_option( LSET_Install::OPTION_PRODUCT );
	}

	public static function guard_product( $passed, $product_id ) {
		if ( ! self::$adding && self::is_ticket_product( $product_id ) ) {
			wc_add_notice( __( 'Please book event tickets from the event page.', 'lumaha-simple-event-tickets' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function hide_product( $visible, $product_id ) {
		return self::is_ticket_product( $product_id ) ? false : $visible;
	}

	/* ---------------------------------------------------------------------
	 * Add to basket
	 * ------------------------------------------------------------------ */

	/** The buyer's answers from a refused add, to put back in the form. */
	public static function rejected_post( $event_id ) {
		if ( (int) self::$rejected !== (int) $event_id ) {
			return array();
		}
		return self::read_post();
	}

	/** Raw-but-sanitised form input (types are checked in validate()). */
	private static function read_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in handle_add (logged-in) / not needed for guests, as with Woo's own add to cart.
		$qty = array();
		if ( isset( $_POST['lset_qty'] ) && is_array( $_POST['lset_qty'] ) ) {
			foreach ( wp_unslash( $_POST['lset_qty'] ) as $tid => $n ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$qty[ absint( $tid ) ] = absint( $n );
			}
		}
		$att = array();
		if ( isset( $_POST['lset_att'] ) && is_array( $_POST['lset_att'] ) ) {
			foreach ( wp_unslash( $_POST['lset_att'] ) as $tid => $people ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_array( $people ) ) {
					continue;
				}
				foreach ( $people as $n => $p ) {
					if ( ! is_array( $p ) ) {
						continue;
					}
					$row = array(
						'first_name' => isset( $p['first_name'] ) ? sanitize_text_field( $p['first_name'] ) : '',
						'last_name'  => isset( $p['last_name'] ) ? sanitize_text_field( $p['last_name'] ) : '',
						'fields'     => array(),
					);
					if ( isset( $p['f'] ) && is_array( $p['f'] ) ) {
						foreach ( $p['f'] as $fid => $v ) {
							$row['fields'][ absint( $fid ) ] = sanitize_textarea_field( is_array( $v ) ? '' : $v );
						}
					}
					$att[ absint( $tid ) ][ absint( $n ) ] = $row;
				}
			}
		}
		$book = array();
		if ( isset( $_POST['lset_book'] ) && is_array( $_POST['lset_book'] ) ) {
			foreach ( wp_unslash( $_POST['lset_book'] ) as $fid => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$book[ absint( $fid ) ] = sanitize_textarea_field( is_array( $v ) ? '' : $v );
			}
		}
		// phpcs:enable
		return array(
			'qty'  => $qty,
			'att'  => $att,
			'book' => $book,
		);
	}

	public static function handle_add() {
		if ( empty( $_POST['lset_add'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$event_id = absint( $_POST['lset_add'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// Logged-in pages aren't cached, so their nonce is fresh. Guests have none, as with Woo's own add to cart.
		if ( is_user_logged_in() && ( ! isset( $_POST['_lset_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_lset_nonce'] ) ), 'lset_add_' . $event_id ) ) ) {
			wc_add_notice( __( 'Your session timed out. Please try again.', 'lumaha-simple-event-tickets' ), 'error' );
			self::$rejected = $event_id;
			return;
		}
		$result = self::add( $event_id, self::read_post() );
		if ( is_wp_error( $result ) ) {
			foreach ( $result->get_error_messages() as $msg ) {
				wc_add_notice( $msg, 'error' );
			}
			self::$rejected = $event_id;
			return;
		}
		wc_add_notice( __( 'Tickets added to your basket.', 'lumaha-simple-event-tickets' ), 'success' );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * Validate a booking and add it to the basket, with holds. Used by the form and by tests.
	 *
	 * @param int   $event_id Event.
	 * @param array $in       qty: {ticket_id: n}, att: {ticket_id: [ {first_name,last_name,fields} ]}, book: {field_id: value}.
	 * @return string[]|WP_Error Cart item keys added.
	 */
	public static function add( $event_id, array $in ) {
		$lines = self::validate( $event_id, $in );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}
		$session = WC()->session ? (string) WC()->session->get_customer_id() : '';
		$holds   = array();
		foreach ( $lines as $line ) {
			$holds[ $line['uid'] ] = array( $line['ticket_id'], count( $line['attendees'] ) );
		}
		// Count this buyer's existing basket lines for the event too, so their holds are renewed together.
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['lset'] ) && (int) $item['lset']['event_id'] === (int) $event_id ) {
				$holds[ $item['lset']['uid'] ] = array( (int) $item['lset']['ticket_id'], (int) $item['quantity'] );
			}
		}
		$ok = LSET_Capacity::reserve( $event_id, $holds, $session );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$keys         = array();
		self::$adding = true;
		foreach ( $lines as $line ) {
			$qty = count( $line['attendees'] );
			$key = WC()->cart->add_to_cart( LSET_Install::product_id(), $qty, 0, array(), array( 'lset' => $line ) );
			if ( $key ) {
				$keys[] = $key;
			} else {
				LSET_Capacity::release( array( $line['uid'] ) );
			}
		}
		self::$adding = false;
		if ( ! $keys ) {
			return new WP_Error( 'lset_cart', __( 'Sorry, we couldn\'t add those tickets to your basket. Please try again.', 'lumaha-simple-event-tickets' ) );
		}
		return $keys;
	}

	/**
	 * Check a submission against the event's rules. Returns the cart lines to add, or a WP_Error
	 * with every problem found.
	 */
	public static function validate( $event_id, array $in ) {
		$e = LSET_Store::event( $event_id );
		if ( ! $e || 'publish' !== $e['status'] || ! $e['bookings'] ) {
			return new WP_Error( 'lset_closed', __( 'Bookings for this event are closed.', 'lumaha-simple-event-tickets' ) );
		}
		$tickets  = LSET_Store::tickets( $event_id, false );
		$a_fields = LSET_Store::event_fields( $event_id, 'attendee' );
		$b_fields = LSET_Store::event_fields( $event_id, 'booking' );
		$errors   = new WP_Error();
		$qty_in   = isset( $in['qty'] ) ? (array) $in['qty'] : array();
		$att_in   = isset( $in['att'] ) ? (array) $in['att'] : array();
		$book_in  = isset( $in['book'] ) ? (array) $in['book'] : array();

		// How many of each ticket are already in the basket (for the per-order max).
		$in_cart = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['lset'] ) ) {
					$tid             = (int) $item['lset']['ticket_id'];
					$in_cart[ $tid ] = ( isset( $in_cart[ $tid ] ) ? $in_cart[ $tid ] : 0 ) + (int) $item['quantity'];
				}
			}
		}

		$labels = array();
		foreach ( array_merge( $a_fields, $b_fields ) as $f ) {
			$labels[ $f['id'] ] = $f['label'];
		}

		$booking = array();
		foreach ( $b_fields as $f ) {
			$v = self::clean_value( $f, isset( $book_in[ $f['id'] ] ) ? $book_in[ $f['id'] ] : '', $errors, $f['label'] );
			if ( '' !== $v ) {
				$booking[ $f['id'] ] = $v;
			}
		}

		$lines = array();
		$total = 0;
		foreach ( $qty_in as $tid => $qty ) {
			$tid = (int) $tid;
			$qty = (int) $qty;
			if ( $qty <= 0 ) {
				continue;
			}
			if ( ! isset( $tickets[ $tid ] ) ) {
				$errors->add( 'lset_ticket', __( 'One of those tickets is no longer available.', 'lumaha-simple-event-tickets' ) );
				continue;
			}
			$t     = $tickets[ $tid ];
			$state = LSET_Store::sale_state( $t, $e );
			if ( 'not_yet' === $state ) {
				/* translators: %s: ticket name */
				$errors->add( 'lset_not_yet', sprintf( __( '"%s" isn\'t on sale yet.', 'lumaha-simple-event-tickets' ), $t['name'] ) );
				continue;
			}
			if ( '' !== $state ) {
				/* translators: %s: ticket name */
				$errors->add( 'lset_ended', sprintf( __( 'Sales of "%s" have ended.', 'lumaha-simple-event-tickets' ), $t['name'] ) );
				continue;
			}
			$have = isset( $in_cart[ $tid ] ) ? $in_cart[ $tid ] : 0;
			$max  = LSET_Store::max_per_order( $t );
			if ( $qty + $have > $max ) {
				/* translators: 1: number, 2: ticket name */
				$errors->add( 'lset_max', sprintf( __( 'You can book up to %1$d "%2$s" tickets per order (including any already in your basket).', 'lumaha-simple-event-tickets' ), $max, $t['name'] ) );
				continue;
			}
			if ( $t['min_qty'] > 0 && $qty + $have < $t['min_qty'] ) {
				/* translators: 1: number, 2: ticket name */
				$errors->add( 'lset_min', sprintf( __( 'Please book at least %1$d "%2$s" tickets.', 'lumaha-simple-event-tickets' ), $t['min_qty'], $t['name'] ) );
				continue;
			}
			$people = array();
			for ( $n = 1; $n <= $qty; $n++ ) {
				$p     = isset( $att_in[ $tid ][ $n ] ) ? (array) $att_in[ $tid ][ $n ] : array();
				$who   = sprintf( /* translators: 1: ticket name, 2: number */ __( '%1$s ticket %2$d', 'lumaha-simple-event-tickets' ), $t['name'], $n );
				$first = isset( $p['first_name'] ) ? trim( (string) $p['first_name'] ) : '';
				$last  = isset( $p['last_name'] ) ? trim( (string) $p['last_name'] ) : '';
				if ( 'required' === $e['name_fields'] && ( '' === $first || '' === $last ) ) {
					/* translators: %s: which ticket */
					$errors->add( 'lset_name', sprintf( __( 'Please enter a first and last name for %s.', 'lumaha-simple-event-tickets' ), $who ) );
				}
				if ( 'off' === $e['name_fields'] ) {
					$first = '';
					$last  = '';
				}
				$values = array();
				foreach ( $a_fields as $f ) {
					$raw = isset( $p['fields'][ $f['id'] ] ) ? $p['fields'][ $f['id'] ] : '';
					$v   = self::clean_value( $f, $raw, $errors, $f['label'] . ' (' . $who . ')' );
					if ( '' !== $v ) {
						$values[ $f['id'] ] = $v;
					}
				}
				$people[] = array(
					'first_name' => mb_substr( $first, 0, 100 ),
					'last_name'  => mb_substr( $last, 0, 100 ),
					'fields'     => $values,
				);
			}
			$total  += $qty;
			$lines[] = array(
				'event_id'  => (int) $event_id,
				'ticket_id' => $tid,
				'uid'       => wp_generate_uuid4(),
				'attendees' => $people,
				'booking'   => $booking,
				'labels'    => $labels,
			);
		}
		if ( ! $total && ! $errors->has_errors() ) {
			$errors->add( 'lset_none', __( 'Please choose at least one ticket.', 'lumaha-simple-event-tickets' ) );
		}
		return $errors->has_errors() ? $errors : $lines;
	}

	/** Check and tidy one answer. Adds to $errors and returns '' when it's not acceptable. */
	public static function clean_value( array $f, $raw, WP_Error $errors, $label ) {
		$v = trim( (string) $raw );
		switch ( $f['type'] ) {
			case 'checkbox':
				$v = ( '' !== $v && '0' !== $v ) ? __( 'Yes', 'lumaha-simple-event-tickets' ) : '';
				if ( $f['required'] && '' === $v ) {
					/* translators: %s: field label */
					$errors->add( 'lset_field', sprintf( __( 'Please tick "%s".', 'lumaha-simple-event-tickets' ), $label ) );
				}
				return $v;
			case 'number':
				if ( '' !== $v && ! is_numeric( $v ) ) {
					/* translators: %s: field label */
					$errors->add( 'lset_field', sprintf( __( '"%s" must be a number.', 'lumaha-simple-event-tickets' ), $label ) );
					return '';
				}
				break;
			case 'select':
				if ( '' !== $v && ! in_array( $v, $f['options'], true ) ) {
					/* translators: %s: field label */
					$errors->add( 'lset_field', sprintf( __( 'Please choose an option for "%s".', 'lumaha-simple-event-tickets' ), $label ) );
					return '';
				}
				break;
			case 'textarea':
				$v = mb_substr( $v, 0, 2000 );
				break;
			default:
				$v = mb_substr( $v, 0, 500 );
		}
		if ( $f['required'] && '' === $v ) {
			/* translators: %s: field label */
			$errors->add( 'lset_field', sprintf( __( 'Please fill in "%s".', 'lumaha-simple-event-tickets' ), $label ) );
		}
		return $v;
	}

	/* ---------------------------------------------------------------------
	 * In the basket
	 * ------------------------------------------------------------------ */

	/** Price and name always come from the ticket as it is now, not from the browser. */
	public static function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['lset'] ) ) {
				continue;
			}
			$t = LSET_Store::ticket( $item['lset']['ticket_id'] );
			$e = $t ? LSET_Store::event( $t['event_id'] ) : null;
			if ( ! $t || ! $e ) {
				continue;
			}
			$item['data']->set_price( $t['price'] );
			/* translators: 1: event title, 2: ticket name */
			$item['data']->set_name( sprintf( __( '%1$s – %2$s', 'lumaha-simple-event-tickets' ), $e['title'], $t['name'] ) );
		}
	}

	/** Lines shown under the item in the basket, checkout and mini-cart. */
	public static function item_data( $data, $item ) {
		if ( empty( $item['lset'] ) ) {
			return $data;
		}
		$e = LSET_Store::event( $item['lset']['event_id'] );
		if ( $e ) {
			$data[] = array(
				'key'   => __( 'Date', 'lumaha-simple-event-tickets' ),
				'value' => LSET_Frontend::when( $e ),
			);
			if ( $e['venue'] ) {
				$data[] = array(
					'key'   => __( 'Venue', 'lumaha-simple-event-tickets' ),
					'value' => $e['venue']['name'],
				);
			}
		}
		foreach ( self::attendee_lines( $item['lset'] ) as $k => $v ) {
			$data[] = array(
				'key'   => $k,
				'value' => $v,
			);
		}
		return $data;
	}

	/**
	 * "Attendee 1" => "Jane Smith (Childs Class: 3, Dietary Requirements: none)", then booking answers.
	 *
	 * @return string[] label => text.
	 */
	public static function attendee_lines( array $lset ) {
		$labels = isset( $lset['labels'] ) ? (array) $lset['labels'] : array();
		$out    = array();
		foreach ( (array) $lset['attendees'] as $i => $p ) {
			$name  = trim( $p['first_name'] . ' ' . $p['last_name'] );
			$extra = array();
			foreach ( (array) $p['fields'] as $fid => $v ) {
				$extra[] = ( isset( $labels[ $fid ] ) ? $labels[ $fid ] : '#' . $fid ) . ': ' . $v;
			}
			$text = $name;
			if ( $extra ) {
				$text .= ( '' !== $name ? ' (' : '' ) . implode( ', ', $extra ) . ( '' !== $name ? ')' : '' );
			}
			if ( '' !== $text ) {
				/* translators: %d: attendee number */
				$out[ sprintf( __( 'Attendee %d', 'lumaha-simple-event-tickets' ), $i + 1 ) ] = $text;
			}
		}
		foreach ( isset( $lset['booking'] ) ? (array) $lset['booking'] : array() as $fid => $v ) {
			$out[ isset( $labels[ $fid ] ) ? $labels[ $fid ] : '#' . $fid ] = $v;
		}
		return $out;
	}

	/** Attendee details are tied to the quantity, so the basket can't change it. */
	public static function lock_quantity_classic( $html, $key, $item ) {
		if ( ! empty( $item['lset'] ) ) {
			return sprintf( '%d <input type="hidden" name="cart[%s][qty]" value="%d" />', (int) $item['quantity'], esc_attr( $key ), (int) $item['quantity'] );
		}
		return $html;
	}

	public static function lock_quantity_blocks( $editable, $product, $item = null ) {
		if ( is_array( $item ) && ! empty( $item['lset'] ) ) {
			return false;
		}
		return $editable;
	}

	public static function item_permalink( $link, $item ) {
		if ( ! empty( $item['lset'] ) ) {
			$url = get_permalink( $item['lset']['event_id'] );
			return $url ? $url : $link;
		}
		return $link;
	}

	public static function item_thumbnail( $html, $item ) {
		if ( ! empty( $item['lset'] ) && has_post_thumbnail( $item['lset']['event_id'] ) ) {
			return get_the_post_thumbnail( $item['lset']['event_id'], 'woocommerce_thumbnail' );
		}
		return $html;
	}

	public static function removed( $key, $cart ) {
		if ( ! empty( $cart->cart_contents[ $key ]['lset']['uid'] ) ) {
			LSET_Capacity::release( array( $cart->cart_contents[ $key ]['lset']['uid'] ) );
		}
	}

	public static function emptied( $clear_persistent = true ) {
		if ( ! WC()->cart ) {
			return;
		}
		// After an order is placed the holds have already become bookings; this catches "empty basket".
		$uids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['lset']['uid'] ) ) {
				$uids[] = $item['lset']['uid'];
			}
		}
		LSET_Capacity::release( $uids );
		unset( $clear_persistent );
	}

	/**
	 * Basket and checkout: tickets still on sale, still within limits, and the holds renewed
	 * (or taken again if they ran out). Runs on the basket page and when the order is placed,
	 * for classic and block checkout.
	 */
	public static function check_cart() {
		if ( ! WC()->cart ) {
			return;
		}
		$by_event = array();
		$per_tid  = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['lset'] ) ) {
				continue;
			}
			$l   = $item['lset'];
			$t   = LSET_Store::ticket( $l['ticket_id'] );
			$e   = $t ? LSET_Store::event( $t['event_id'] ) : null;
			$qty = (int) $item['quantity'];
			if ( ! $t || ! $e ) {
				wc_add_notice( __( 'A ticket in your basket is no longer available. Please remove it.', 'lumaha-simple-event-tickets' ), 'error' );
				continue;
			}
			$state = LSET_Store::sale_state( $t, $e );
			if ( '' !== $state ) {
				/* translators: %s: ticket name */
				wc_add_notice( sprintf( __( '"%s" is no longer on sale. Please remove it from your basket.', 'lumaha-simple-event-tickets' ), $e['title'] . ' – ' . $t['name'] ), 'error' );
				continue;
			}
			if ( count( (array) $l['attendees'] ) !== $qty ) {
				/* translators: %s: ticket name */
				wc_add_notice( sprintf( __( 'The attendee details for "%s" don\'t match the quantity. Please remove it and book again.', 'lumaha-simple-event-tickets' ), $t['name'] ), 'error' );
				continue;
			}
			$per_tid[ $t['id'] ] = ( isset( $per_tid[ $t['id'] ] ) ? $per_tid[ $t['id'] ] : 0 ) + $qty;
			if ( $per_tid[ $t['id'] ] > LSET_Store::max_per_order( $t ) ) {
				/* translators: 1: number, 2: ticket name */
				wc_add_notice( sprintf( __( 'You can book up to %1$d "%2$s" tickets per order.', 'lumaha-simple-event-tickets' ), LSET_Store::max_per_order( $t ), $t['name'] ), 'error' );
				continue;
			}
			$by_event[ $e['id'] ][ $l['uid'] ] = array( $t['id'], $qty );
		}
		$session = WC()->session ? (string) WC()->session->get_customer_id() : '';
		foreach ( $by_event as $event_id => $holds ) {
			$ok = LSET_Capacity::reserve( $event_id, $holds, $session );
			if ( is_wp_error( $ok ) ) {
				wc_add_notice( $ok->get_error_message() . ' ' . __( 'Please change your tickets and try again.', 'lumaha-simple-event-tickets' ), 'error' );
			}
		}
	}
}
