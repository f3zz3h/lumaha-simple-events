<?php
/**
 * Event tickets email (plain text). Copy to yourtheme/woocommerce/emails/plain/lset-tickets.php to change it.
 *
 * @var WC_Order $order
 * @var array[]  $tickets
 * @var string   $tickets_url
 * @var string   $email_heading
 * @var string   $additional_content
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

/* translators: %s: customer first name */
echo esc_html( sprintf( __( 'Hi %s,', 'lumaha-simple-event-tickets' ), $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: order number */
echo esc_html( sprintf( __( 'Your tickets for order #%s. Open this page to show or print them with their QR codes:', 'lumaha-simple-event-tickets' ), $order->get_order_number() ) ) . "\n";
echo esc_url_raw( $tickets_url ) . "\n\n";

foreach ( $tickets as $lset_t ) {
	echo "----------------------------------------\n";
	echo esc_html( $lset_t['event'] ) . "\n" . esc_html( $lset_t['when'] ) . "\n";
	if ( $lset_t['venue'] ) {
		echo esc_html( $lset_t['venue'] ) . "\n";
	}
	echo esc_html( __( 'Ticket', 'lumaha-simple-event-tickets' ) . ': ' . $lset_t['ticket'] ) . "\n";
	if ( $lset_t['name'] ) {
		echo esc_html( __( 'Name', 'lumaha-simple-event-tickets' ) . ': ' . $lset_t['name'] ) . "\n";
	}
	echo esc_html( __( 'Code', 'lumaha-simple-event-tickets' ) . ': ' . $lset_t['code_display'] ) . "\n";
	foreach ( $lset_t['notes'] as $lset_note ) {
		echo "\n" . esc_html( $lset_note ) . "\n";
	}
}
echo "----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
