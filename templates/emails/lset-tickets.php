<?php
/**
 * Event tickets email (HTML). Copy to yourtheme/woocommerce/emails/lset-tickets.php to change it.
 *
 * @var WC_Order $order
 * @var array[]  $tickets     Ticket views: event, when, venue, ticket, name, code_display, cid, notes, seq, qty.
 * @var string   $tickets_url Page with all the tickets, to print.
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

$lset_accent = LSET_Tickets::accent();

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php /* translators: %s: customer first name */ ?>
<p><?php echo esc_html( sprintf( __( 'Hi %s,', 'lumaha-simple-event-tickets' ), $order->get_billing_first_name() ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: %s: order number */ _n( 'Here is your ticket for order #%s.', 'Here are your tickets for order #%s. Each person has their own QR code.', count( $tickets ), 'lumaha-simple-event-tickets' ), $order->get_order_number() ) ); ?></p>
<p style="margin:0 0 24px;"><a href="<?php echo esc_url( $tickets_url ); ?>" style="display:inline-block;padding:10px 18px;background:<?php echo esc_attr( $lset_accent ); ?>;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;"><?php esc_html_e( 'View and print your tickets', 'lumaha-simple-event-tickets' ); ?></a></p>

<?php foreach ( $tickets as $lset_t ) : ?>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border:1px solid #dddddd;border-top:6px solid <?php echo esc_attr( $lset_accent ); ?>;margin:0 0 20px;border-collapse:separate;">
	<tr>
		<td valign="top" style="padding:14px 16px;">
			<p style="margin:0 0 4px;font-size:18px;font-weight:bold;"><?php echo esc_html( $lset_t['event'] ); ?></p>
			<p style="margin:0 0 4px;"><?php echo esc_html( $lset_t['when'] ); ?></p>
			<?php if ( $lset_t['venue'] ) : ?>
				<p style="margin:0 0 8px;color:#666666;"><?php echo esc_html( $lset_t['venue'] ); ?></p>
			<?php endif; ?>
			<p style="margin:8px 0 2px;"><?php esc_html_e( 'Ticket', 'lumaha-simple-event-tickets' ); ?>: <strong><?php echo esc_html( $lset_t['ticket'] ); ?></strong><?php echo $lset_t['qty'] > 1 ? esc_html( ' (' . sprintf( /* translators: 1: number, 2: total */ __( '%1$d of %2$d', 'lumaha-simple-event-tickets' ), $lset_t['seq'], $lset_t['qty'] ) . ')' ) : ''; ?></p>
			<?php if ( $lset_t['name'] ) : ?>
				<p style="margin:0 0 2px;"><?php esc_html_e( 'Name', 'lumaha-simple-event-tickets' ); ?>: <strong><?php echo esc_html( $lset_t['name'] ); ?></strong></p>
			<?php endif; ?>
			<?php foreach ( $lset_t['notes'] as $lset_note ) : ?>
				<p style="margin:8px 0 0;padding-top:8px;border-top:1px dashed #cccccc;font-size:13px;"><?php echo nl2br( esc_html( $lset_note ) ); ?></p>
			<?php endforeach; ?>
		</td>
		<td valign="top" align="center" width="170" style="padding:10px;width:170px;">
			<img src="cid:<?php echo esc_attr( $lset_t['cid'] ); ?>" width="160" height="160" alt="<?php echo esc_attr( sprintf( /* translators: %s: ticket code */ __( 'QR code %s', 'lumaha-simple-event-tickets' ), $lset_t['code_display'] ) ); ?>" style="display:block;width:160px;height:160px;border:0;">
			<p style="margin:4px 0 0;font-family:monospace;font-size:13px;letter-spacing:1px;"><?php echo esc_html( $lset_t['code_display'] ); ?></p>
		</td>
	</tr>
</table>
<?php endforeach; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
