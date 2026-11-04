<?php
/**
 * Admin attendee list per event, CSV export and a printable register for the door.
 *
 * Everything here needs the lset_view_attendees capability (admins and shop managers get it;
 * the Members plugin can give it to others), because it holds children's names and
 * medical/dietary details.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Attendees {

	const PAGE = 'lset-attendees';

	/** Status filters: key => booking statuses. */
	public static function filters() {
		return array(
			'paid'      => array(
				'label'    => __( 'Booked (paid, free or on hold)', 'lumaha-simple-event-tickets' ),
				'statuses' => array( 'completed', 'processing', 'on-hold' ),
			),
			'pending'   => array(
				'label'    => __( 'Awaiting payment', 'lumaha-simple-event-tickets' ),
				'statuses' => array( 'pending' ),
			),
			'cancelled' => array(
				'label'    => __( 'Cancelled, failed or refunded', 'lumaha-simple-event-tickets' ),
				'statuses' => array( 'cancelled', 'failed', 'refunded', 'removed', 'trash' ),
			),
			'all'       => array(
				'label'    => __( 'All', 'lumaha-simple-event-tickets' ),
				'statuses' => array(),
			),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lset_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_lset_register', array( __CLASS__, 'print_register' ) );
	}

	public static function url( $event_id, $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => LSET_CPT,
					'page'      => self::PAGE,
					'event'     => (int) $event_id,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . LSET_CPT, __( 'Attendees', 'lumaha-simple-event-tickets' ), __( 'Attendees', 'lumaha-simple-event-tickets' ), LSET_CAP_ATTENDEES, self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * Attendee rows for an event with their booking, oldest booking first.
	 *
	 * @param int    $event_id Event.
	 * @param string $filter   A key of filters().
	 * @return array[]
	 */
	public static function rows( $event_id, $filter = 'paid' ) {
		global $wpdb;
		$filters  = self::filters();
		$statuses = isset( $filters[ $filter ] ) ? $filters[ $filter ]['statuses'] : $filters['paid']['statuses'];
		$a        = LSET_Install::table( 'attendees' );
		$b        = LSET_Install::table( 'bookings' );
		$sql      = "SELECT a.*, b.order_id, b.status, b.qty, b.refunded_qty, b.buyer_name, b.buyer_email, b.buyer_phone, b.booking_fields, b.source, b.created_at
			FROM $a a JOIN $b b ON b.id = a.booking_id WHERE a.event_id = %d";
		$args     = array( $event_id );
		if ( $statuses ) {
			$sql .= ' AND b.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$args = array_merge( $args, $statuses );
		}
		$sql .= ' ORDER BY b.created_at, a.booking_id, a.seq';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$r ) {
			$r['fields']         = self::decode( $r['fields'] );
			$r['booking_fields'] = self::decode( $r['booking_fields'] );
			// The last N attendees of a partly refunded line are the refunded ones.
			$r['refunded'] = (int) $r['seq'] > (int) $r['qty'] - (int) $r['refunded_qty'];
		}
		return $rows;
	}

	private static function decode( $json ) {
		$v = json_decode( (string) $json, true );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * The columns for attendee and booking answers: the event's fields, then any older labels found in the data.
	 *
	 * @return array{0: string[], 1: string[]} Attendee labels, booking labels, keyed by field id.
	 */
	public static function columns( $event_id, array $rows ) {
		$att  = array();
		$book = array();
		foreach ( LSET_Store::event_fields( $event_id, 'attendee' ) as $f ) {
			$att[ $f['id'] ] = $f['label'];
		}
		foreach ( LSET_Store::event_fields( $event_id, 'booking' ) as $f ) {
			$book[ $f['id'] ] = $f['label'];
		}
		foreach ( $rows as $r ) {
			foreach ( $r['fields'] as $fid => $v ) {
				if ( ! isset( $att[ $fid ] ) ) {
					$att[ $fid ] = isset( $v['label'] ) && '' !== $v['label'] ? $v['label'] : '#' . $fid;
				}
			}
			foreach ( $r['booking_fields'] as $fid => $v ) {
				if ( ! isset( $book[ $fid ] ) ) {
					$book[ $fid ] = isset( $v['label'] ) && '' !== $v['label'] ? $v['label'] : '#' . $fid;
				}
			}
		}
		return array( $att, $book );
	}

	private static function value( array $answers, $fid ) {
		return isset( $answers[ $fid ]['value'] ) ? (string) $answers[ $fid ]['value'] : '';
	}

	/** Sold vs capacity per ticket and group. */
	public static function summary( $event_id ) {
		global $wpdb;
		$tickets = LSET_Store::tickets( $event_id );
		$groups  = LSET_Store::groups( $event_id );
		$b       = LSET_Install::table( 'bookings' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT ticket_id, status, SUM(GREATEST(qty - refunded_qty, 0)) AS n, SUM(line_total) AS total FROM $b WHERE event_id = %d GROUP BY ticket_id, status", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$booked  = array();
		$pending = array();
		foreach ( $rows as $r ) {
			$tid = (int) $r['ticket_id'];
			if ( in_array( $r['status'], LSET_Capacity::COUNTED, true ) ) {
				$booked[ $tid ] = ( isset( $booked[ $tid ] ) ? $booked[ $tid ] : 0 ) + (int) $r['n'];
			} elseif ( 'pending' === $r['status'] ) {
				$pending[ $tid ] = ( isset( $pending[ $tid ] ) ? $pending[ $tid ] : 0 ) + (int) $r['n'];
			}
		}
		$taken = LSET_Capacity::taken( $event_id );
		$out   = array();
		foreach ( $tickets as $t ) {
			$out['tickets'][ $t['id'] ] = array(
				'name'     => $t['name'],
				'group'    => $t['group_id'] && isset( $groups[ $t['group_id'] ] ) ? $groups[ $t['group_id'] ]['name'] : '',
				'booked'   => isset( $booked[ $t['id'] ] ) ? $booked[ $t['id'] ] : 0,
				'held'     => ( isset( $taken[ $t['id'] ] ) ? $taken[ $t['id'] ] : 0 ) - ( isset( $booked[ $t['id'] ] ) ? $booked[ $t['id'] ] : 0 ),
				'pending'  => isset( $pending[ $t['id'] ] ) ? $pending[ $t['id'] ] : 0,
				'capacity' => $t['capacity'],
				'hidden'   => $t['hidden'],
			);
		}
		foreach ( $groups as $g ) {
			$n = 0;
			foreach ( $tickets as $t ) {
				if ( $t['group_id'] === $g['id'] ) {
					$n += isset( $booked[ $t['id'] ] ) ? $booked[ $t['id'] ] : 0;
				}
			}
			$out['groups'][ $g['id'] ] = array(
				'name'     => $g['name'],
				'booked'   => $n,
				'capacity' => $g['capacity'],
			);
		}
		return $out + array(
			'tickets' => array(),
			'groups'  => array(),
		);
	}

	private static function order_link( $order_id ) {
		if ( ! $order_id ) {
			return '';
		}
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$url = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $order_id );
		} else {
			$url = admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
		}
		return '<a href="' . esc_url( $url ) . '">#' . (int) $order_id . '</a>';
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( LSET_CAP_ATTENDEES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see attendee details.', 'lumaha-simple-event-tickets' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		$filter   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'paid';
		// phpcs:enable
		$filters = self::filters();
		$filter  = isset( $filters[ $filter ] ) ? $filter : 'paid';
		$events  = get_posts(
			array(
				'post_type'      => LSET_CPT,
				'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
				'posts_per_page' => 200,
				'meta_key'       => '_lset_start_utc', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);
		if ( ! $event_id && $events ) {
			// Default: the next event coming up, else the most recent.
			$event_id = $events[0]->ID;
			foreach ( $events as $p ) {
				if ( (int) get_post_meta( $p->ID, '_lset_end_utc', true ) >= time() ) {
					$event_id = $p->ID;
				}
			}
		}
		$e = $event_id ? LSET_Store::event( $event_id ) : null;
		?>
		<div class="wrap lset-attendees-page">
			<h1><?php esc_html_e( 'Attendees', 'lumaha-simple-event-tickets' ); ?></h1>
			<form method="get" class="lset-filter">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( LSET_CPT ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<select name="event">
					<?php foreach ( $events as $p ) : ?>
						<?php $pe = LSET_Store::event( $p ); ?>
						<option value="<?php echo (int) $p->ID; ?>" <?php selected( $event_id, $p->ID ); ?>><?php echo esc_html( $pe['title'] . ' — ' . ( $pe['start'] ? LSET_Time::format( $pe['start'], 'j M Y', $pe['tz'] ) : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<?php foreach ( $filters as $k => $f ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>><?php echo esc_html( $f['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Show', 'lumaha-simple-event-tickets' ), 'secondary', '', false ); ?>
			</form>
			<?php
			if ( ! $e ) {
				echo '<p>' . esc_html__( 'No events yet.', 'lumaha-simple-event-tickets' ) . '</p></div>';
				return;
			}
			$rows                   = self::rows( $event_id, $filter );
			list( $a_cols, $b_cols ) = self::columns( $event_id, $rows );
			$summary                = self::summary( $event_id );
			$action_url             = function ( $action ) use ( $event_id, $filter ) {
				return wp_nonce_url(
					add_query_arg(
						array(
							'action' => $action,
							'event'  => $event_id,
							'status' => $filter,
						),
						admin_url( 'admin-post.php' )
					),
					$action . '_' . $event_id
				);
			};
			?>
			<h2><?php echo esc_html( $e['title'] ); ?> <span class="lset-small"><?php echo esc_html( LSET_Frontend::when( $e ) ); ?></span></h2>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $action_url( 'lset_export' ) ); ?>"><?php esc_html_e( 'Download CSV', 'lumaha-simple-event-tickets' ); ?></a>
				<a class="button" target="_blank" href="<?php echo esc_url( $action_url( 'lset_register' ) ); ?>"><?php esc_html_e( 'Printable register', 'lumaha-simple-event-tickets' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( get_edit_post_link( $event_id ) ); ?>"><?php esc_html_e( 'Edit event', 'lumaha-simple-event-tickets' ); ?></a>
			</p>

			<table class="widefat striped lset-summary">
				<thead><tr>
					<th><?php esc_html_e( 'Ticket', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Group', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Booked', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'In baskets / awaiting payment', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Capacity', 'lumaha-simple-event-tickets' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $summary['tickets'] as $s ) : ?>
					<tr>
						<td><?php echo esc_html( $s['name'] ); ?><?php echo $s['hidden'] ? ' <span class="lset-small">(' . esc_html__( 'hidden', 'lumaha-simple-event-tickets' ) . ')</span>' : ''; ?></td>
						<td><?php echo esc_html( $s['group'] ); ?></td>
						<td><?php echo (int) $s['booked']; ?></td>
						<td><?php echo (int) max( 0, $s['held'] ); ?></td>
						<td><?php echo null === $s['capacity'] ? '∞' : (int) $s['capacity']; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php foreach ( $summary['groups'] as $g ) : ?>
					<tr class="lset-summary__group">
						<td colspan="2"><strong><?php echo esc_html( sprintf( /* translators: %s: group name */ __( 'Group: %s', 'lumaha-simple-event-tickets' ), $g['name'] ) ); ?></strong></td>
						<td><?php echo (int) $g['booked']; ?></td>
						<td></td>
						<td><?php echo null === $g['capacity'] ? '∞' : (int) $g['capacity']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html( sprintf( /* translators: %d: number of attendees */ _n( '%d attendee', '%d attendees', count( $rows ), 'lumaha-simple-event-tickets' ), count( $rows ) ) ); ?></h3>
			<div class="lset-scroll">
			<table class="widefat striped lset-list">
				<thead><tr>
					<th><?php esc_html_e( 'Ticket', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'First name', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Last name', 'lumaha-simple-event-tickets' ); ?></th>
					<?php foreach ( $a_cols as $label ) : ?>
						<th><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Order', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Booked by', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Email', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'lumaha-simple-event-tickets' ); ?></th>
					<?php foreach ( $b_cols as $label ) : ?>
						<th><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Status', 'lumaha-simple-event-tickets' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="<?php echo 8 + count( $a_cols ) + count( $b_cols ); ?>"><?php esc_html_e( 'No attendees for this filter.', 'lumaha-simple-event-tickets' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr class="<?php echo $r['refunded'] ? 'lset-refunded' : ''; ?>">
						<td><?php echo esc_html( isset( $summary['tickets'][ $r['ticket_id'] ] ) ? $summary['tickets'][ $r['ticket_id'] ]['name'] : '#' . $r['ticket_id'] ); ?></td>
						<td><?php echo esc_html( $r['first_name'] ); ?></td>
						<td><?php echo esc_html( $r['last_name'] ); ?></td>
						<?php foreach ( array_keys( $a_cols ) as $fid ) : ?>
							<td><?php echo esc_html( self::value( $r['fields'], $fid ) ); ?></td>
						<?php endforeach; ?>
						<td><?php echo wp_kses_post( self::order_link( $r['order_id'] ) ); ?></td>
						<td><?php echo esc_html( $r['buyer_name'] ); ?></td>
						<td><?php echo $r['buyer_email'] ? '<a href="mailto:' . esc_attr( $r['buyer_email'] ) . '">' . esc_html( $r['buyer_email'] ) . '</a>' : ''; ?></td>
						<td><?php echo esc_html( $r['buyer_phone'] ); ?></td>
						<?php foreach ( array_keys( $b_cols ) as $fid ) : ?>
							<td><?php echo esc_html( self::value( $r['booking_fields'], $fid ) ); ?></td>
						<?php endforeach; ?>
						<td><?php echo esc_html( $r['refunded'] ? __( 'refunded', 'lumaha-simple-event-tickets' ) : wc_get_order_status_name( $r['status'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}

	private static function check_request( $action ) {
		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		check_admin_referer( $action . '_' . $event_id );
		if ( ! current_user_can( LSET_CAP_ATTENDEES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see attendee details.', 'lumaha-simple-event-tickets' ) );
		}
		$e = LSET_Store::event( $event_id );
		if ( ! $e ) {
			wp_die( esc_html__( 'Event not found.', 'lumaha-simple-event-tickets' ) );
		}
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'paid';
		return array( $e, $filter );
	}

	/** Spreadsheet apps run cells that start with = + - @ as formulas. */
	public static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}

	/** The CSV as rows of cells (header first). */
	public static function csv_rows( $event_id, $filter ) {
		$rows                   = self::rows( $event_id, $filter );
		list( $a_cols, $b_cols ) = self::columns( $event_id, $rows );
		$tickets                = LSET_Store::tickets( $event_id );
		$groups                 = LSET_Store::groups( $event_id );
		$out                    = array();
		$out[]                  = array_merge(
			array( 'Ticket', 'Group', 'First name', 'Last name' ),
			array_values( $a_cols ),
			array( 'Order', 'Booked by', 'Email', 'Phone' ),
			array_values( $b_cols ),
			array( 'Status', 'Booked at' )
		);
		foreach ( $rows as $r ) {
			$t     = isset( $tickets[ $r['ticket_id'] ] ) ? $tickets[ $r['ticket_id'] ] : null;
			$cells = array(
				$t ? $t['name'] : '#' . $r['ticket_id'],
				$t && $t['group_id'] && isset( $groups[ $t['group_id'] ] ) ? $groups[ $t['group_id'] ]['name'] : '',
				$r['first_name'],
				$r['last_name'],
			);
			foreach ( array_keys( $a_cols ) as $fid ) {
				$cells[] = self::value( $r['fields'], $fid );
			}
			$cells = array_merge( $cells, array( $r['order_id'] ? $r['order_id'] : '', $r['buyer_name'], $r['buyer_email'], $r['buyer_phone'] ) );
			foreach ( array_keys( $b_cols ) as $fid ) {
				$cells[] = self::value( $r['booking_fields'], $fid );
			}
			$cells[] = $r['refunded'] ? 'refunded' : $r['status'];
			$cells[] = $r['created_at'] ? wp_date( 'Y-m-d H:i', strtotime( $r['created_at'] . ' UTC' ) ) : '';
			$out[]   = $cells;
		}
		return $out;
	}

	public static function export_csv() {
		list( $e, $filter ) = self::check_request( 'lset_export' );
		$name               = sanitize_file_name( $e['title'] . '-attendees-' . wp_date( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streamed, never saved.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		foreach ( self::csv_rows( $e['id'], $filter ) as $cells ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), $cells ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** A plain page to print: grouped by ticket, sorted by last name, with a tick box column. */
	public static function print_register() {
		list( $e, $filter ) = self::check_request( 'lset_register' );
		$rows                   = array_filter(
			self::rows( $e['id'], $filter ),
			function ( $r ) {
				return ! $r['refunded'];
			}
		);
		list( $a_cols, $b_cols ) = self::columns( $e['id'], $rows );
		$tickets                = LSET_Store::tickets( $e['id'] );
		$by_ticket              = array();
		foreach ( $rows as $r ) {
			$by_ticket[ $r['ticket_id'] ][] = $r;
		}
		nocache_headers();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $e['title'] . ' — ' . __( 'Register', 'lumaha-simple-event-tickets' ) ); ?></title>
<style>
body { font: 12px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; margin: 16px; color: #000; background: #fff; }
h1 { font-size: 18px; margin: 0 0 4px; }
h2 { font-size: 14px; margin: 18px 0 6px; page-break-after: avoid; }
p.meta { margin: 0 0 12px; color: #333; }
table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
tr { page-break-inside: avoid; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
th { background: #eee; }
td.tick { width: 22px; }
.print { margin: 0 0 12px; }
@media print { .print { display: none; } body { margin: 0; } }
</style>
</head>
<body>
<p class="print"><button onclick="window.print()"><?php esc_html_e( 'Print', 'lumaha-simple-event-tickets' ); ?></button></p>
<h1><?php echo esc_html( $e['title'] ); ?></h1>
<p class="meta"><?php echo esc_html( LSET_Frontend::when( $e ) ); ?><?php echo $e['venue'] ? ' · ' . esc_html( $e['venue']['name'] ) : ''; ?> · <?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d attendee', '%d attendees', count( $rows ), 'lumaha-simple-event-tickets' ), count( $rows ) ) ); ?> · <?php echo esc_html( sprintf( /* translators: %s: date and time */ __( 'printed %s', 'lumaha-simple-event-tickets' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ); ?></p>
		<?php foreach ( $tickets as $t ) : ?>
			<?php
			if ( empty( $by_ticket[ $t['id'] ] ) ) {
				continue;
			}
			$list = $by_ticket[ $t['id'] ];
			usort(
				$list,
				function ( $x, $y ) {
					return strcasecmp( $x['last_name'] . ' ' . $x['first_name'], $y['last_name'] . ' ' . $y['first_name'] );
				}
			);
			?>
<h2><?php echo esc_html( $t['name'] . ' (' . count( $list ) . ')' ); ?></h2>
<table>
<thead><tr><th class="tick">✓</th><th><?php esc_html_e( 'Last name', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'First name', 'lumaha-simple-event-tickets' ); ?></th>
			<?php foreach ( $a_cols as $label ) : ?>
<th><?php echo esc_html( $label ); ?></th>
			<?php endforeach; ?>
<th><?php esc_html_e( 'Booked by', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Phone', 'lumaha-simple-event-tickets' ); ?></th>
			<?php foreach ( $b_cols as $label ) : ?>
<th><?php echo esc_html( $label ); ?></th>
			<?php endforeach; ?>
<th><?php esc_html_e( 'Order', 'lumaha-simple-event-tickets' ); ?></th></tr></thead>
<tbody>
			<?php foreach ( $list as $r ) : ?>
<tr><td class="tick"></td><td><?php echo esc_html( $r['last_name'] ); ?></td><td><?php echo esc_html( $r['first_name'] ); ?></td>
				<?php foreach ( array_keys( $a_cols ) as $fid ) : ?>
<td><?php echo esc_html( self::value( $r['fields'], $fid ) ); ?></td>
				<?php endforeach; ?>
<td><?php echo esc_html( $r['buyer_name'] ); ?></td><td><?php echo esc_html( $r['buyer_phone'] ); ?></td>
				<?php foreach ( array_keys( $b_cols ) as $fid ) : ?>
<td><?php echo esc_html( self::value( $r['booking_fields'], $fid ) ); ?></td>
				<?php endforeach; ?>
<td><?php echo $r['order_id'] ? '#' . (int) $r['order_id'] : ''; ?></td></tr>
			<?php endforeach; ?>
</tbody>
</table>
		<?php endforeach; ?>
<?php if ( ! $rows ) : ?>
<p><?php esc_html_e( 'No attendees yet.', 'lumaha-simple-event-tickets' ); ?></p>
<?php endif; ?>
</body>
</html>
		<?php
		exit;
	}
}
