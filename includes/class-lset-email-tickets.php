<?php
/**
 * The "Event tickets" email: one card per ticket with its QR code, sent when an order is paid.
 *
 * Subject, heading and extra text are set in WooCommerce → Settings → Emails → Event tickets.
 * The QR codes go in the email as embedded images, so they show without "load images".
 * A theme can override the template: yourtheme/woocommerce/emails/lset-tickets.php.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Email_Tickets extends WC_Email {

	/** @var array[] Ticket views (LSET_Tickets::view()). */
	public $tickets = array();

	/** @var string[] cid => PNG bytes, embedded while sending. */
	private $images = array();

	public function __construct() {
		$this->id             = 'lset_tickets';
		$this->customer_email = true;
		$this->title          = __( 'Event tickets', 'lumaha-simple-event-tickets' );
		$this->description    = __( 'Sent to the buyer when an order with event tickets is paid (or free). One ticket per person, each with a QR code to scan at the door.', 'lumaha-simple-event-tickets' );
		$this->template_html  = 'emails/lset-tickets.php';
		$this->template_plain = 'emails/plain/lset-tickets.php';
		$this->template_base  = LSET_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{event}'        => '',
		);
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Your tickets for {event}', 'lumaha-simple-event-tickets' );
	}

	public function get_default_heading() {
		return __( 'Your tickets', 'lumaha-simple-event-tickets' );
	}

	public function get_default_additional_content() {
		return __( 'Show the QR code at the door, on your phone or printed. See you there!', 'lumaha-simple-event-tickets' );
	}

	/**
	 * Send it.
	 *
	 * @param WC_Order $order   Order.
	 * @param array[]  $tickets Ticket views.
	 * @param bool     $force   Send even if the email is turned off (an admin asked for it).
	 * @return bool
	 */
	public function trigger( $order, array $tickets, $force = false ) {
		$this->setup_locale();
		$this->object    = $order;
		$this->recipient = $order->get_billing_email();
		$this->tickets   = $tickets;
		$events          = array_unique( wp_list_pluck( $tickets, 'event' ) );

		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{event}']        = implode( ', ', $events );

		$this->images = array();
		foreach ( $tickets as $t ) {
			$this->images[ $t['cid'] ] = LSET_Tickets::qr_png( $t['code'], 6 );
		}

		$sent = false;
		if ( ( $force || $this->is_enabled() ) && $this->get_recipient() ) {
			add_action( 'phpmailer_init', array( $this, 'embed_images' ), 999 );
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			remove_action( 'phpmailer_init', array( $this, 'embed_images' ), 999 );
		}
		$this->restore_locale();
		return (bool) $sent;
	}

	/** @param PHPMailer\PHPMailer\PHPMailer $mailer Mailer. */
	public function embed_images( $mailer ) {
		if ( 'text/html' !== $this->get_content_type() ) {
			return;
		}
		foreach ( $this->images as $cid => $png ) {
			$mailer->addStringEmbeddedImage( $png, $cid, $cid . '.png', 'base64', 'image/png' );
		}
	}

	private function template_args( $plain ) {
		return array(
			'order'              => $this->object,
			'tickets'            => $this->tickets,
			'tickets_url'        => $this->object ? LSET_Tickets::order_url( $this->object ) : '',
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
