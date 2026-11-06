<?php
/**
 * Tickets with QR codes: one per attendee, emailed when the order is paid, a printable page for
 * the buyer, and a check-in page for the door that opens when staff scan the code.
 *
 * - Each attendee row gets a random ticket code. The QR code is a link to /?lset_t=<code>.
 * - Anyone who scans it sees the ticket. Staff (lset_check_in) see whether it's valid and can check it in.
 * - The buyer's page of all their tickets is /?lset_tickets=<order id>&key=<order key>.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Tickets {

	const Q_TICKET = 'lset_t';
	const Q_ORDER  = 'lset_tickets';
	const META_SENT = '_lset_tickets_sent';

	/** Order statuses that get tickets emailed. */
	const SEND_ON = array( 'processing', 'completed' );

	/** No 0/O, 1/I/L, so a code read out or typed in is hard to get wrong. */
	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'email_class' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 20, 4 ); // After LSET_Orders::sync.
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
		add_action( 'admin_post_lset_check_in', array( __CLASS__, 'handle_check_in' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_lset_send_tickets', array( __CLASS__, 'order_action_send' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details_link' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_link' ), 10, 4 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order_link' ) );
	}

	public static function email_class( $emails ) {
		require_once LSET_DIR . 'includes/class-lset-email-tickets.php';
		$emails['LSET_Email_Tickets'] = new LSET_Email_Tickets();
		return $emails;
	}

	/* ---------------------------------------------------------------------
	 * Codes and ticket data
	 * ------------------------------------------------------------------ */

	/** A new random ticket code, 12 characters (about 60 bits). */
	public static function new_code() {
		$code = '';
		$max  = strlen( self::ALPHABET ) - 1;
		for ( $i = 0; $i < 12; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $code;
	}

	/** What people type or scan → the stored form: capitals, no spaces or dashes. */
	public static function clean_code( $code ) {
		return substr( preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $code ) ), 0, 20 );
	}

	/** "ABCD-EFGH-JKMN", easier to read out. */
	public static function display_code( $code ) {
		return implode( '-', str_split( (string) $code, 4 ) );
	}

	public static function ticket_url( $code ) {
		return add_query_arg( self::Q_TICKET, rawurlencode( $code ), home_url( '/' ) );
	}

	public static function order_url( WC_Order $order ) {
		return add_query_arg(
			array(
				self::Q_ORDER => $order->get_id(),
				'key'         => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	public static function door_url() {
		return add_query_arg( self::Q_TICKET, '', home_url( '/' ) );
	}

	/** Give a code to any attendee of these bookings that doesn't have one yet (e.g. migrated ones). */
	public static function ensure_codes( $where_sql, array $args ) {
		global $wpdb;
		$a   = LSET_Install::table( 'attendees' );
		$b   = LSET_Install::table( 'bookings' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT a.id FROM $a a JOIN $b b ON b.id = a.booking_id WHERE a.ticket_code = '' AND $where_sql", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ids as $id ) {
			$wpdb->update( $a, array( 'ticket_code' => self::new_code() ), array( 'id' => (int) $id ) );
		}
	}

	private static function select_sql() {
		$a = LSET_Install::table( 'attendees' );
		$b = LSET_Install::table( 'bookings' );
		return "SELECT a.id, a.booking_id, a.event_id, a.ticket_id, a.seq, a.first_name, a.last_name, a.fields, a.ticket_code, a.checked_in_at,
			b.order_id, b.status, b.qty, b.refunded_qty, b.source, b.buyer_name, b.buyer_email
			FROM $a a JOIN $b b ON b.id = a.booking_id";
	}

	/**
	 * Every ticket on an order, in order.
	 *
	 * @return array[] Rows with 'state' added (see state()).
	 */
	public static function for_order( $order_id ) {
		global $wpdb;
		self::ensure_codes( 'b.order_id = %d', array( (int) $order_id ) );
		$rows = $wpdb->get_results( $wpdb->prepare( self::select_sql() . ' WHERE b.order_id = %d ORDER BY b.id, a.seq', (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'with_state' ), $rows );
	}

	/** One ticket by its code, or null. */
	public static function by_code( $code ) {
		global $wpdb;
		$code = self::clean_code( $code );
		if ( strlen( $code ) < 8 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( self::select_sql() . ' WHERE a.ticket_code = %s LIMIT 1', $code ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::with_state( $row ) : null;
	}

	private static function with_state( array $r ) {
		$r['refunded'] = (int) $r['seq'] > (int) $r['qty'] - (int) $r['refunded_qty'];
		$r['state']    = self::state( $r );
		return $r;
	}

	/**
	 * Is the ticket good for entry?
	 *
	 * @return string 'valid' (paid or free), 'on-hold' (payment not confirmed yet), 'unpaid', or 'void' (cancelled, refunded…).
	 */
	public static function state( array $r ) {
		if ( ! empty( $r['refunded'] ) ) {
			return 'void';
		}
		switch ( $r['status'] ) {
			case 'completed':
			case 'processing':
				return 'valid';
			case 'on-hold':
				return 'on-hold';
			case 'pending':
			case 'checkout-draft':
				return 'unpaid';
			default:
				return 'void';
		}
	}

	/**
	 * Everything a ticket shows, ready for a template.
	 *
	 * @param array $r A row from for_order() or by_code().
	 */
	public static function view( array $r ) {
		static $events  = array();
		static $tickets = array();
		$eid = (int) $r['event_id'];
		if ( ! array_key_exists( $eid, $events ) ) {
			$events[ $eid ] = LSET_Store::event( $eid );
		}
		$tid = (int) $r['ticket_id'];
		if ( ! array_key_exists( $tid, $tickets ) ) {
			$tickets[ $tid ] = LSET_Store::ticket( $tid );
		}
		$e    = $events[ $eid ];
		$t    = $tickets[ $tid ];
		$name = trim( $r['first_name'] . ' ' . $r['last_name'] );
		$note = trim( (string) Lumaha_Simple_Event_Tickets::setting( 'ticket_note' ) );
		return array(
			'id'           => (int) $r['id'],
			'code'         => $r['ticket_code'],
			'code_display' => self::display_code( $r['ticket_code'] ),
			'url'          => self::ticket_url( $r['ticket_code'] ),
			'cid'          => 'lset-qr-' . strtolower( $r['ticket_code'] ),
			'name'         => $name,
			'seq'          => (int) $r['seq'],
			'qty'          => (int) $r['qty'],
			'ticket'       => $t ? $t['name'] : __( 'Ticket', 'lumaha-simple-event-tickets' ),
			'event_id'     => $eid,
			'event'        => $e ? $e['title'] : '',
			'event_url'    => $e ? $e['url'] : '',
			'when'         => $e ? LSET_Frontend::when( $e ) : '',
			'venue'        => $e && $e['venue'] ? LSET_Frontend::venue_line( $e['venue'] ) : '',
			'notes'        => array_values( array_filter( array( $e ? $e['ticket_note'] : '', $note ), 'strlen' ) ),
			'order_id'     => (int) $r['order_id'],
			'state'        => $r['state'],
			'checked_in'   => $r['checked_in_at'],
		);
	}

	public static function qr_svg( $code, $px = 0 ) {
		/* translators: %s: ticket code */
		return LSET_QR::encode( self::ticket_url( $code ) )->svg( $px, sprintf( __( 'QR code for ticket %s', 'lumaha-simple-event-tickets' ), self::display_code( $code ) ) );
	}

	public static function qr_png( $code, $scale = 6 ) {
		return LSET_QR::encode( self::ticket_url( $code ) )->png( $scale );
	}

	/* ---------------------------------------------------------------------
	 * Check-in
	 * ------------------------------------------------------------------ */

	/**
	 * Check a ticket in (or undo it).
	 *
	 * @return true|WP_Error
	 */
	public static function check_in( $attendee_id, $undo = false ) {
		global $wpdb;
		$a = LSET_Install::table( 'attendees' );
		if ( $undo ) {
			$wpdb->update( $a, array( 'checked_in_at' => null ), array( 'id' => (int) $attendee_id ) );
			return true;
		}
		// Only the first scan wins, if two people scan the same ticket at once.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $a SET checked_in_at = %s WHERE id = %d AND checked_in_at IS NULL", LSET_Time::now_utc(), (int) $attendee_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $n ? true : new WP_Error( 'lset_already', __( 'This ticket was already checked in.', 'lumaha-simple-event-tickets' ) );
	}

	/** [checked in, valid tickets] for an event. */
	public static function event_counts( $event_id ) {
		global $wpdb;
		$a   = LSET_Install::table( 'attendees' );
		$b   = LSET_Install::table( 'bookings' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(a.checked_in_at IS NOT NULL) AS done FROM $a a JOIN $b b ON b.id = a.booking_id WHERE a.event_id = %d AND b.status IN ('completed','processing','on-hold') AND a.seq <= b.qty - b.refunded_qty", (int) $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( (int) $row['done'], (int) $row['total'] );
	}

	public static function handle_check_in() {
		$code = isset( $_POST['code'] ) ? self::clean_code( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
		check_admin_referer( 'lset_check_in_' . $code );
		if ( ! current_user_can( LSET_CAP_CHECKIN ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to check people in.', 'lumaha-simple-event-tickets' ) );
		}
		$r    = self::by_code( $code );
		$undo = ! empty( $_POST['undo'] );
		$done = 'none';
		if ( $r && ( $undo || 'void' !== $r['state'] ) ) {
			$res  = self::check_in( $r['id'], $undo );
			$done = is_wp_error( $res ) ? 'already' : ( $undo ? 'undone' : 'in' );
		}
		wp_safe_redirect( add_query_arg( 'lset_done', $done, self::ticket_url( $code ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	public static function status_changed( $order_id, $from, $to, $order = null ) {
		unset( $from );
		if ( ! in_array( $to, self::SEND_ON, true ) || ! Lumaha_Simple_Event_Tickets::setting( 'ticket_emails' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_SENT ) ) {
			return;
		}
		self::send( $order );
	}

	/**
	 * Email the order's tickets to the buyer.
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $manual An admin asked: also send tickets for migrated bookings and past events.
	 * @return bool Sent.
	 */
	public static function send( WC_Order $order, $manual = false ) {
		$tickets = array();
		foreach ( self::for_order( $order->get_id() ) as $r ) {
			if ( ! in_array( $r['state'], array( 'valid', 'on-hold' ), true ) || '' === $r['ticket_code'] ) {
				continue;
			}
			if ( ! $manual && 'migrated' === $r['source'] ) {
				continue;
			}
			$v = self::view( $r );
			if ( ! $manual && $v['event_id'] ) {
				$end = (int) get_post_meta( $v['event_id'], '_lset_end_utc', true );
				if ( $end && $end < time() ) {
					continue;
				}
			}
			$tickets[] = $v;
		}
		if ( ! $tickets || ! $order->get_billing_email() ) {
			return false;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = isset( $emails['LSET_Email_Tickets'] ) ? $emails['LSET_Email_Tickets'] : null;
		if ( ! $email || ( ! $manual && ! $email->is_enabled() ) ) {
			return false;
		}
		// Mark first, so a second status change while this one is still sending doesn't send again.
		$order->update_meta_data( self::META_SENT, time() );
		$order->save_meta_data();
		$sent = $email->trigger( $order, $tickets, $manual );
		if ( $sent ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: number of tickets, 2: email address */
					_n( 'Event tickets: %1$d ticket with a QR code emailed to %2$s.', 'Event tickets: %1$d tickets with QR codes emailed to %2$s.', count( $tickets ), 'lumaha-simple-event-tickets' ),
					count( $tickets ),
					$order->get_billing_email()
				)
			);
		} else {
			$order->delete_meta_data( self::META_SENT );
			$order->save_meta_data();
			$order->add_order_note( __( 'Event tickets: the ticket email could not be sent. Use "Email event tickets to the customer" in Order actions to try again.', 'lumaha-simple-event-tickets' ) );
		}
		return (bool) $sent;
	}

	public static function has_tickets( WC_Order $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( LSET_Orders::META_TICKET ) ) {
				return true;
			}
		}
		return false;
	}

	public static function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			global $theorder;
			$order = $theorder;
		}
		if ( $order instanceof WC_Order && self::has_tickets( $order ) ) {
			$actions['lset_send_tickets'] = __( 'Email event tickets to the customer', 'lumaha-simple-event-tickets' );
		}
		return $actions;
	}

	public static function order_action_send( $order ) {
		if ( ! self::send( $order, true ) ) {
			$order->add_order_note( __( 'Event tickets: nothing to send. Tickets go out for paid, free or on-hold bookings only.', 'lumaha-simple-event-tickets' ) );
		}
	}

	/** "View and print your tickets" on the thank-you page and in My Account. */
	public static function order_details_link( $order ) {
		if ( ! $order instanceof WC_Order || ! in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true ) || ! self::has_tickets( $order ) ) {
			return;
		}
		printf(
			'<p class="lset-order-tickets"><a class="button" href="%s">%s</a></p>',
			esc_url( self::order_url( $order ) ),
			esc_html__( 'View and print your tickets', 'lumaha-simple-event-tickets' )
		);
	}

	/** The same link in WooCommerce's own customer emails (order complete, invoice…). */
	public static function email_link( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ( $email && 'lset_tickets' === $email->id ) || ! $order instanceof WC_Order || ! in_array( $order->get_status(), self::SEND_ON, true ) || ! self::has_tickets( $order ) ) {
			return;
		}
		$label = __( 'View and print your tickets', 'lumaha-simple-event-tickets' );
		if ( $plain_text ) {
			echo "\n" . esc_html( $label ) . ': ' . esc_url_raw( self::order_url( $order ) ) . "\n";
		} else {
			printf( '<p><a href="%s">%s</a></p>', esc_url( self::order_url( $order ) ), esc_html( $label ) );
		}
	}

	public static function admin_order_link( $order ) {
		if ( ! $order instanceof WC_Order || ! self::has_tickets( $order ) ) {
			return;
		}
		$sent = (int) $order->get_meta( self::META_SENT );
		echo '<p><strong>' . esc_html__( 'Event tickets', 'lumaha-simple-event-tickets' ) . ':</strong> <a href="' . esc_url( self::order_url( $order ) ) . '" target="_blank">' . esc_html__( 'View tickets', 'lumaha-simple-event-tickets' ) . '</a>';
		if ( $sent ) {
			/* translators: %s: date and time */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Emailed %s', 'lumaha-simple-event-tickets' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sent ) ) ) . '</span>';
		}
		echo '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Pages: the buyer's tickets, and the scan / check-in page
	 * ------------------------------------------------------------------ */

	public static function route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the order key or ticket code is the secret; nothing changes here.
		if ( isset( $_GET[ self::Q_ORDER ] ) ) {
			self::page_order( absint( $_GET[ self::Q_ORDER ] ), isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '' );
			exit;
		}
		if ( isset( $_GET[ self::Q_TICKET ] ) ) {
			self::page_ticket( self::clean_code( sanitize_text_field( wp_unslash( $_GET[ self::Q_TICKET ] ) ) ), isset( $_GET['lset_done'] ) ? sanitize_key( $_GET['lset_done'] ) : '' );
			exit;
		}
		// phpcs:enable
	}

	public static function accent() {
		$c = sanitize_hex_color( (string) Lumaha_Simple_Event_Tickets::setting( 'ticket_colour' ) );
		return $c ? $c : '#1d3557';
	}

	/** The logo for tickets: the one in settings, else the site logo. */
	public static function logo_url() {
		$url = (string) Lumaha_Simple_Event_Tickets::setting( 'ticket_logo' );
		if ( ! $url && get_theme_mod( 'custom_logo' ) ) {
			$url = (string) wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' );
		}
		return $url;
	}

	private static function page_start( $title, $status = 200 ) {
		if ( ! headers_sent() ) {
			status_header( $status );
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		$accent = self::accent();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' · ' . get_bloginfo( 'name' ) ); ?></title>
<style>
:root { --accent: <?php echo esc_html( $accent ); ?>; }
* { box-sizing: border-box; }
body { margin: 0; padding: 16px; font: 16px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #1a1a1a; background: #f2f2f2; }
main { max-width: 720px; margin: 0 auto; }
h1 { font-size: 22px; margin: 4px 0 12px; }
.toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
.btn { display: inline-block; padding: 10px 16px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
.btn--light { background: #fff; color: #1a1a1a; border: 1px solid #ccc; }
.btn--big { width: 100%; padding: 18px; font-size: 20px; }
.muted { color: #555; font-size: 14px; }
.ticket { background: #fff; border-radius: 10px; overflow: hidden; margin: 0 0 16px; box-shadow: 0 1px 3px rgba(0,0,0,.15); page-break-inside: avoid; break-inside: avoid; }
.ticket__head { background: var(--accent); color: #fff; padding: 10px 16px; display: flex; align-items: center; gap: 12px; font-weight: 600; }
.ticket__head img { max-height: 40px; max-width: 160px; background: #fff; border-radius: 4px; padding: 2px; }
.ticket__body { display: flex; gap: 16px; padding: 16px; flex-wrap: wrap; }
.ticket__info { flex: 1 1 260px; min-width: 0; }
.ticket__info h2 { margin: 0 0 4px; font-size: 20px; }
.ticket__info p { margin: 0 0 4px; }
.ticket dl { display: grid; grid-template-columns: auto 1fr; gap: 2px 12px; margin: 10px 0; }
.ticket dt { color: #555; }
.ticket dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
.ticket__note { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #ccc; font-size: 14px; white-space: pre-line; }
.ticket__qr { flex: 0 0 180px; text-align: center; margin: 0 auto; }
.ticket__qr svg { width: 180px; height: 180px; display: block; }
.ticket__qr code { display: block; margin-top: 4px; font-size: 15px; letter-spacing: 1px; }
.banner { border-radius: 10px; padding: 18px 16px; color: #fff; margin-bottom: 16px; }
.banner strong { display: block; font-size: 24px; }
.banner--ok { background: #1e7a34; }
.banner--warn { background: #b36b00; }
.banner--bad { background: #b3261e; }
.card { background: #fff; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
.card .name { font-size: 26px; font-weight: 700; margin: 0 0 4px; overflow-wrap: anywhere; }
.card dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin: 12px 0 0; }
.card dt { color: #555; }
.card dd { margin: 0; overflow-wrap: anywhere; }
.code-form { display: flex; gap: 8px; }
.code-form input { flex: 1; min-width: 0; padding: 10px; font: inherit; font-size: 18px; text-transform: uppercase; border: 1px solid #aaa; border-radius: 6px; }
@media print {
	body { background: #fff; padding: 0; }
	.toolbar, .noprint { display: none; }
	.ticket { box-shadow: none; border: 1px solid #999; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>
<main>
		<?php
	}

	private static function page_end() {
		echo "</main>\n</body>\n</html>\n";
	}

	private static function message_page( $title, $text, $status = 404 ) {
		self::page_start( $title, $status );
		echo '<div class="card"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p></div>';
		self::page_end();
	}

	/** One ticket, as the buyer sees it (web page and print). */
	public static function ticket_html( array $v ) {
		$logo = self::logo_url();
		ob_start();
		?>
		<section class="ticket">
			<div class="ticket__head">
				<?php if ( $logo ) : ?>
					<img src="<?php echo esc_url( $logo ); ?>" alt="">
				<?php endif; ?>
				<span><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
			</div>
			<div class="ticket__body">
				<div class="ticket__info">
					<h2><?php echo esc_html( $v['event'] ); ?></h2>
					<p><?php echo esc_html( $v['when'] ); ?></p>
					<?php if ( $v['venue'] ) : ?>
						<p class="muted"><?php echo esc_html( $v['venue'] ); ?></p>
					<?php endif; ?>
					<dl>
						<dt><?php esc_html_e( 'Ticket', 'lumaha-simple-event-tickets' ); ?></dt><dd><?php echo esc_html( $v['ticket'] ); ?></dd>
						<?php if ( $v['name'] ) : ?>
							<dt><?php esc_html_e( 'Name', 'lumaha-simple-event-tickets' ); ?></dt><dd><?php echo esc_html( $v['name'] ); ?></dd>
						<?php endif; ?>
						<?php if ( $v['order_id'] ) : ?>
							<dt><?php esc_html_e( 'Order', 'lumaha-simple-event-tickets' ); ?></dt><dd>#<?php echo (int) $v['order_id']; ?><?php echo $v['qty'] > 1 ? esc_html( ' · ' . sprintf( /* translators: 1: number, 2: total */ __( '%1$d of %2$d', 'lumaha-simple-event-tickets' ), $v['seq'], $v['qty'] ) ) : ''; ?></dd>
						<?php endif; ?>
					</dl>
					<?php foreach ( $v['notes'] as $note ) : ?>
						<div class="ticket__note"><?php echo esc_html( $note ); ?></div>
					<?php endforeach; ?>
				</div>
				<div class="ticket__qr">
					<?php echo self::qr_svg( $v['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from a fixed set of elements. ?>
					<code><?php echo esc_html( $v['code_display'] ); ?></code>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function page_order( $order_id, $key ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || $order instanceof WC_Order_Refund || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			self::message_page( __( 'Tickets not found', 'lumaha-simple-event-tickets' ), __( 'This link isn\'t right. Please use the link in your ticket email.', 'lumaha-simple-event-tickets' ) );
			return;
		}
		$rows  = self::for_order( $order->get_id() );
		$good  = array();
		$other = 0;
		foreach ( $rows as $r ) {
			if ( in_array( $r['state'], array( 'valid', 'on-hold' ), true ) ) {
				$good[] = self::view( $r );
			} else {
				++$other;
			}
		}
		self::page_start( __( 'Your tickets', 'lumaha-simple-event-tickets' ) );
		?>
		<h1><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Your tickets for order #%s', 'lumaha-simple-event-tickets' ), $order->get_order_number() ) ); ?></h1>
		<?php if ( $good ) : ?>
			<div class="toolbar">
				<button type="button" class="btn" onclick="window.print()"><?php esc_html_e( 'Print tickets', 'lumaha-simple-event-tickets' ); ?></button>
				<span class="muted"><?php esc_html_e( 'Show the QR code at the door, on your phone or printed. Each person has their own ticket.', 'lumaha-simple-event-tickets' ); ?></span>
			</div>
			<?php if ( 'on-hold' === $order->get_status() ) : ?>
				<p class="card"><?php esc_html_e( 'We\'re still waiting to confirm your payment. Your tickets will be valid once it\'s through.', 'lumaha-simple-event-tickets' ); ?></p>
			<?php endif; ?>
			<?php
			foreach ( $good as $v ) {
				echo self::ticket_html( $v ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
			?>
		<?php elseif ( in_array( $order->get_status(), array( 'pending', 'checkout-draft', 'failed' ), true ) ) : ?>
			<p class="card"><?php esc_html_e( 'This order hasn\'t been paid yet, so there are no tickets to show.', 'lumaha-simple-event-tickets' ); ?></p>
		<?php else : ?>
			<p class="card"><?php esc_html_e( 'There are no valid tickets on this order.', 'lumaha-simple-event-tickets' ); ?></p>
		<?php endif; ?>
		<?php if ( $other && $good ) : ?>
			<p class="muted"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d ticket on this order was cancelled or refunded, so it isn\'t shown.', '%d tickets on this order were cancelled or refunded, so they aren\'t shown.', $other, 'lumaha-simple-event-tickets' ), $other ) ); ?></p>
		<?php endif; ?>
		<?php
		self::page_end();
	}

	private static function page_ticket( $code, $done ) {
		$staff = current_user_can( LSET_CAP_CHECKIN );
		$r     = '' !== $code ? self::by_code( $code ) : null;

		if ( ! $staff ) {
			if ( ! $r ) {
				self::message_page( __( 'Ticket not found', 'lumaha-simple-event-tickets' ), __( 'We couldn\'t find this ticket. Please check the link in your ticket email.', 'lumaha-simple-event-tickets' ) );
				return;
			}
			self::page_start( __( 'Your ticket', 'lumaha-simple-event-tickets' ) );
			if ( 'void' === $r['state'] ) {
				echo '<div class="banner banner--bad"><strong>' . esc_html__( 'Not valid', 'lumaha-simple-event-tickets' ) . '</strong>' . esc_html__( 'This ticket was cancelled or refunded.', 'lumaha-simple-event-tickets' ) . '</div>';
			} elseif ( 'unpaid' === $r['state'] ) {
				echo '<div class="banner banner--warn"><strong>' . esc_html__( 'Not paid yet', 'lumaha-simple-event-tickets' ) . '</strong></div>';
			} else {
				echo self::ticket_html( self::view( $r ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
			echo '<p class="muted noprint"><a href="' . esc_url( wp_login_url( self::ticket_url( $code ) ) ) . '">' . esc_html__( 'Staff: log in to check tickets in', 'lumaha-simple-event-tickets' ) . '</a></p>';
			self::page_end();
			return;
		}

		self::page_start( __( 'Check-in', 'lumaha-simple-event-tickets' ), $r || '' === $code ? 200 : 404 );
		if ( '' !== $code && ! $r ) {
			echo '<div class="banner banner--bad"><strong>' . esc_html__( 'Ticket not found', 'lumaha-simple-event-tickets' ) . '</strong>' . esc_html( self::display_code( $code ) ) . '</div>';
		}
		if ( $r ) {
			self::door_card( $r, $done );
		}
		?>
		<div class="card noprint">
			<form class="code-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="screen-reader-text" for="lset-code" hidden><?php esc_html_e( 'Ticket code', 'lumaha-simple-event-tickets' ); ?></label>
				<input id="lset-code" name="<?php echo esc_attr( self::Q_TICKET ); ?>" placeholder="<?php esc_attr_e( 'Type a ticket code', 'lumaha-simple-event-tickets' ); ?>" autocomplete="off" autocapitalize="characters" <?php echo $r ? '' : 'autofocus'; ?>>
				<button class="btn" type="submit"><?php esc_html_e( 'Find', 'lumaha-simple-event-tickets' ); ?></button>
			</form>
			<p class="muted"><?php esc_html_e( 'Scan each QR code with your phone\'s camera; it opens here. Stay logged in on this phone for the evening.', 'lumaha-simple-event-tickets' ); ?></p>
		</div>
		<?php
		self::page_end();
	}

	/** The staff view of one scanned ticket. */
	private static function door_card( array $r, $done ) {
		$v       = self::view( $r );
		$checked = $r['checked_in_at'];
		$at      = $checked ? wp_date( get_option( 'time_format' ), strtotime( $checked . ' UTC' ) ) : '';
		$at_day  = $checked ? wp_date( 'j M', strtotime( $checked . ' UTC' ) ) : '';
		if ( $at_day && wp_date( 'j M' ) !== $at_day ) {
			$at = $at_day . ' ' . $at;
		}

		if ( 'void' === $r['state'] ) {
			$banner = array( 'bad', __( 'Not valid', 'lumaha-simple-event-tickets' ), $r['refunded'] || 'refunded' === $r['status'] ? __( 'This ticket was refunded.', 'lumaha-simple-event-tickets' ) : __( 'This booking was cancelled.', 'lumaha-simple-event-tickets' ) );
		} elseif ( 'unpaid' === $r['state'] ) {
			$banner = array( 'bad', __( 'Not paid', 'lumaha-simple-event-tickets' ), __( 'This order is still waiting for payment.', 'lumaha-simple-event-tickets' ) );
		} elseif ( 'in' === $done && $checked ) {
			$banner = array( 'ok', __( 'Checked in ✓', 'lumaha-simple-event-tickets' ), '' );
		} elseif ( $checked ) {
			/* translators: %s: time */
			$banner = array( 'warn', __( 'Already checked in', 'lumaha-simple-event-tickets' ), sprintf( __( 'This ticket was checked in at %s.', 'lumaha-simple-event-tickets' ), $at ) );
		} elseif ( 'on-hold' === $r['state'] ) {
			$banner = array( 'warn', __( 'Payment not confirmed', 'lumaha-simple-event-tickets' ), __( 'The order is on hold (e.g. waiting for a bank transfer). Check before letting them in.', 'lumaha-simple-event-tickets' ) );
		} else {
			$banner = array( 'ok', __( 'Valid ticket', 'lumaha-simple-event-tickets' ), 'undone' === $done ? __( 'Check-in undone.', 'lumaha-simple-event-tickets' ) : '' );
		}

		$e        = LSET_Store::event( $r['event_id'] );
		$not_today = '';
		if ( $e && $e['start'] ) {
			$today = wp_date( 'Y-m-d', null, new DateTimeZone( $e['tz'] ) );
			if ( $today < substr( $e['start'], 0, 10 ) || $today > substr( $e['end'] ? $e['end'] : $e['start'], 0, 10 ) ) {
				/* translators: %s: event date */
				$not_today = sprintf( __( 'Heads up: this ticket is for %s, not today.', 'lumaha-simple-event-tickets' ), LSET_Time::format( $e['start'], 'l j F Y', $e['tz'] ) );
			}
		}
		list( $n_in, $n_all ) = self::event_counts( $r['event_id'] );
		?>
		<div class="banner banner--<?php echo esc_attr( $banner[0] ); ?>"><strong><?php echo esc_html( $banner[1] ); ?></strong><?php echo esc_html( $banner[2] ); ?></div>
		<?php if ( $not_today ) : ?>
			<div class="banner banner--warn"><?php echo esc_html( $not_today ); ?></div>
		<?php endif; ?>
		<div class="card">
			<p class="name"><?php echo esc_html( $v['name'] ? $v['name'] : __( '(no name given)', 'lumaha-simple-event-tickets' ) ); ?></p>
			<p><strong><?php echo esc_html( $v['ticket'] ); ?></strong> · <?php echo esc_html( $v['event'] ); ?></p>
			<p class="muted"><?php echo esc_html( $v['when'] ); ?></p>
			<dl>
				<dt><?php esc_html_e( 'Booked by', 'lumaha-simple-event-tickets' ); ?></dt><dd><?php echo esc_html( $r['buyer_name'] ); ?></dd>
				<dt><?php esc_html_e( 'Order', 'lumaha-simple-event-tickets' ); ?></dt><dd>#<?php echo (int) $r['order_id']; ?><?php echo $v['qty'] > 1 ? esc_html( ' · ' . sprintf( /* translators: 1: number, 2: total */ __( 'ticket %1$d of %2$d', 'lumaha-simple-event-tickets' ), $v['seq'], $v['qty'] ) ) : ''; ?></dd>
				<dt><?php esc_html_e( 'Code', 'lumaha-simple-event-tickets' ); ?></dt><dd><?php echo esc_html( $v['code_display'] ); ?></dd>
				<?php
				if ( current_user_can( LSET_CAP_ATTENDEES ) ) {
					$fields = json_decode( (string) $r['fields'], true );
					foreach ( is_array( $fields ) ? $fields : array() as $f ) {
						if ( isset( $f['value'] ) && '' !== (string) $f['value'] ) {
							echo '<dt>' . esc_html( isset( $f['label'] ) ? $f['label'] : '' ) . '</dt><dd>' . esc_html( (string) $f['value'] ) . '</dd>';
						}
					}
				}
				?>
			</dl>
		</div>
		<?php if ( 'void' !== $r['state'] && 'unpaid' !== $r['state'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="noprint" style="margin-bottom:16px">
				<input type="hidden" name="action" value="lset_check_in">
				<input type="hidden" name="code" value="<?php echo esc_attr( $r['ticket_code'] ); ?>">
				<?php wp_nonce_field( 'lset_check_in_' . $r['ticket_code'] ); ?>
				<?php if ( $checked ) : ?>
					<button type="submit" name="undo" value="1" class="btn btn--light"><?php esc_html_e( 'Undo check-in', 'lumaha-simple-event-tickets' ); ?></button>
				<?php else : ?>
					<button type="submit" class="btn btn--big"><?php esc_html_e( 'Check in', 'lumaha-simple-event-tickets' ); ?></button>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<p class="muted">
			<?php
			/* translators: 1: checked in, 2: total tickets, 3: event */
			echo esc_html( sprintf( __( '%1$d of %2$d checked in for %3$s.', 'lumaha-simple-event-tickets' ), $n_in, $n_all, $v['event'] ) );
			if ( current_user_can( LSET_CAP_ATTENDEES ) ) {
				echo ' <a href="' . esc_url( LSET_Attendees::url( $r['event_id'] ) ) . '">' . esc_html__( 'Attendee list', 'lumaha-simple-event-tickets' ) . '</a>';
			}
			?>
		</p>
		<?php
	}
}
