<?php
/**
 * wp lset migrate-eventprime [--dry-run]
 *
 * Copies EventPrime's venues, checkout fields, events, ticket categories, tickets and bookings
 * into LSET. It only reads EventPrime data: nothing of EventPrime's, and no WooCommerce order,
 * is changed. Every LSET row remembers the EventPrime id it came from, so running it again
 * updates what it made before instead of making duplicates.
 *
 * --dry-run does the whole run inside a database transaction and rolls it back, so the
 * report is exactly what a real run would do.
 *
 * @package Lumaha_SET_EventPrime_Migrator
 */

defined( 'ABSPATH' ) || exit;

class LSET_Migrate {

	/** @var array Counters and messages for the report. */
	private $report = array();

	/** @var bool */
	private $dry = false;

	/** EventPrime term id => LSET venue term id. */
	private $venues = array();

	/** EventPrime field id => LSET field id. */
	private $fields = array();

	/** EventPrime event id => LSET event id. */
	private $events = array();

	/** EventPrime category id => LSET group id. */
	private $groups = array();

	/** EventPrime price option id => LSET ticket id. */
	private $tickets = array();

	/** @var callable|null Where log lines go (WP_CLI::log by default). */
	public $logger = null;

	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			WP_CLI::add_command( 'lset migrate-eventprime', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Copy EventPrime events, tickets, fields and bookings into Lumaha Simple Event Tickets.
	 *
	 * Reads EventPrime data only. Safe to run again: it updates what it made last time.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Do everything inside a transaction and roll it back. Nothing is saved.
	 *
	 * [--from=<date>]
	 * : Only events starting on or after this date (Y-m-d). Default: all.
	 *
	 * [--report=<file>]
	 * : Also write the per-ticket comparison to this CSV file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lset migrate-eventprime --dry-run
	 *     wp lset migrate-eventprime --from=2026-01-01 --report=/tmp/lset-migration.csv
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Options.
	 */
	public static function cli( $args, $assoc_args ) {
		$m         = new self();
		$m->logger = function ( $msg ) {
			WP_CLI::log( $msg );
		};
		$result    = $m->run(
			array(
				'dry_run' => ! empty( $assoc_args['dry-run'] ),
				'from'    => isset( $assoc_args['from'] ) ? $assoc_args['from'] : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$m->print_report( $result );
		if ( ! empty( $assoc_args['report'] ) ) {
			$fh = fopen( $assoc_args['report'], 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fputcsv( $fh, array( 'EventPrime event', 'LSET event', 'Event', 'Starts', 'Ticket', 'EventPrime sold', 'LSET sold', 'Match' ), ',', '"', '\\' );
			foreach ( $result['compare'] as $row ) {
				fputcsv( $fh, array_values( $row ), ',', '"', '\\' );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			WP_CLI::log( 'Report written to ' . $assoc_args['report'] );
		}
		if ( $result['dry_run'] ) {
			WP_CLI::success( 'Dry run finished. Nothing was saved.' );
		} else {
			WP_CLI::success( 'Migration finished.' );
		}
	}

	private function log( $msg ) {
		if ( $this->logger ) {
			call_user_func( $this->logger, $msg );
		}
	}

	private function warn( $msg ) {
		$this->report['warnings'][] = $msg;
		$this->log( 'Warning: ' . $msg );
	}

	private function count( $what, $how ) {
		if ( ! isset( $this->report['counts'][ $what ][ $how ] ) ) {
			$this->report['counts'][ $what ][ $how ] = 0;
		}
		++$this->report['counts'][ $what ][ $how ];
	}

	/** EventPrime's table, with the site prefix (wpfr_em_price_options etc.). */
	private static function ep_table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		$ok    = false !== $wpdb->query( "SELECT 1 FROM `$table` LIMIT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own prefix + fixed name.
		$wpdb->suppress_errors( $quiet );
		return $ok;
	}

	/** The tables a run writes to. A dry run's rollback only works if they are all InnoDB. */
	private static function non_innodb_tables() {
		global $wpdb;
		$names = array( $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_taxonomy, $wpdb->term_relationships );
		foreach ( array( 'tickets', 'ticket_groups', 'fields', 'bookings', 'attendees' ) as $t ) {
			$names[] = LSET_Install::table( $t );
		}
		$bad = array();
		foreach ( $names as $n ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $n ) );
			if ( $engine && 'innodb' !== strtolower( $engine ) ) {
				$bad[] = "$n ($engine)";
			}
		}
		return $bad;
	}

	/**
	 * Run the migration.
	 *
	 * @param array $opts dry_run (bool), from (Y-m-d).
	 * @return array|WP_Error The report.
	 */
	public function run( array $opts = array() ) {
		global $wpdb;
		$this->dry    = ! empty( $opts['dry_run'] );
		$this->report = array(
			'dry_run'  => $this->dry,
			'counts'   => array(),
			'warnings' => array(),
			'compare'  => array(),
		);
		if ( ! post_type_exists( 'em_event' ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'em_event' ) ) ) {
			return new WP_Error( 'lset_no_ep', 'No EventPrime events (em_event posts) found.' );
		}

		// Keep the scheduled hold clean-up and our own order sync out of the way.
		remove_action( 'woocommerce_order_status_changed', array( 'LSET_Orders', 'status_changed' ), 10 );

		// The tests already run inside a transaction, so they ask for a savepoint instead.
		$savepoint = ! empty( $opts['savepoint'] );
		if ( $this->dry ) {
			$bad = self::non_innodb_tables();
			if ( $bad ) {
				return new WP_Error( 'lset_not_innodb', 'A dry run needs InnoDB tables so it can roll back, and these are not: ' . implode( ', ', $bad ) );
			}
			$wpdb->query( $savepoint ? 'SAVEPOINT lset_dry_run' : 'START TRANSACTION' );
		}
		try {
			$this->venues();
			$this->fields();
			$this->events( isset( $opts['from'] ) ? (string) $opts['from'] : '' );
			$this->bookings();
			$this->compare();
		} finally {
			if ( $this->dry ) {
				$wpdb->query( $savepoint ? 'ROLLBACK TO SAVEPOINT lset_dry_run' : 'ROLLBACK' );
				wp_cache_flush();
			}
		}
		return $this->report;
	}

	/* ---------------------------------------------------------------------
	 * Venues
	 * ------------------------------------------------------------------ */

	private function venues() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'em_venue',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			// EventPrime is off: read the terms directly.
			global $wpdb;
			$terms = $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s", 'em_venue' ) );
		}
		foreach ( (array) $terms as $term ) {
			$ep_id    = (int) $term->term_id;
			$existing = get_terms(
				array(
					'taxonomy'   => LSET_VENUE,
					'hide_empty' => false,
					'fields'     => 'ids',
					'meta_key'   => '_lset_ep_term_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'meta_value' => $ep_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				)
			);
			if ( $existing && ! is_wp_error( $existing ) ) {
				$id = (int) $existing[0];
				wp_update_term( $id, LSET_VENUE, array( 'name' => $term->name ) );
				$this->count( 'venues', 'updated' );
			} else {
				$made = wp_insert_term( $term->name, LSET_VENUE );
				if ( is_wp_error( $made ) ) {
					// Same name already exists (made by hand): use it.
					$id = isset( $made->error_data['term_exists'] ) ? (int) $made->error_data['term_exists'] : 0;
					if ( ! $id ) {
						$this->warn( 'Venue "' . $term->name . '" could not be created: ' . $made->get_error_message() );
						continue;
					}
				} else {
					$id = (int) $made['term_id'];
				}
				update_term_meta( $id, '_lset_ep_term_id', $ep_id );
				$this->count( 'venues', 'created' );
			}
			$address  = self::first_meta( 'term', $ep_id, array( 'em_address', 'address' ) );
			$postcode = self::first_meta( 'term', $ep_id, array( 'em_postcode', 'em_zip_code', 'em_zip', 'postcode' ) );
			$lat      = self::first_meta( 'term', $ep_id, array( 'em_lat', 'em_latitude', 'lat' ) );
			$lng      = self::first_meta( 'term', $ep_id, array( 'em_lng', 'em_longitude', 'lng' ) );
			if ( $postcode && false !== stripos( $address, $postcode ) ) {
				$address = trim( str_ireplace( $postcode, '', $address ), " ,\t\n" );
			}
			update_term_meta( $id, 'lset_address', sanitize_text_field( $address ) );
			update_term_meta( $id, 'lset_postcode', sanitize_text_field( $postcode ) );
			if ( is_numeric( $lat ) && is_numeric( $lng ) && ! get_term_meta( $id, 'lset_map_url', true ) ) {
				update_term_meta( $id, 'lset_map_url', esc_url_raw( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $lat . ',' . $lng ) ) );
			}
			$this->venues[ $ep_id ] = $id;
		}
	}

	private static function first_meta( $type, $id, array $keys ) {
		foreach ( $keys as $k ) {
			$v = get_metadata( $type, $id, $k, true );
			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				return trim( (string) $v );
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Field library
	 * ------------------------------------------------------------------ */

	private function fields() {
		global $wpdb;
		$table = self::ep_table( 'eventprime_checkout_fields' );
		if ( ! self::table_exists( $table ) ) {
			$this->warn( "No $table table: no checkout fields to copy." );
			return;
		}
		$existing = array();
		foreach ( LSET_Store::fields() as $f ) {
			if ( $f['ep_id'] ) {
				$existing[ $f['ep_id'] ] = $f['id'];
			}
		}
		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $i => $r ) {
			$ep_type = strtolower( (string) $r['type'] );
			$map     = array(
				'text'     => 'text',
				'number'   => 'number',
				'textarea' => 'textarea',
				'select'   => 'select',
				'dropdown' => 'select',
				'radio'    => 'select',
				'checkbox' => 'checkbox',
				'email'    => 'text',
				'tel'      => 'text',
				'phone'    => 'text',
				'date'     => 'text',
			);
			$type    = isset( $map[ $ep_type ] ) ? $map[ $ep_type ] : 'text';
			if ( ! isset( $map[ $ep_type ] ) ) {
				$this->warn( 'Field "' . $r['label'] . '" has type "' . $ep_type . '"; copied as text.' );
			}
			$ep_id                  = (int) $r['id'];
			$this->fields[ $ep_id ] = LSET_Store::save_field(
				array(
					'id'         => isset( $existing[ $ep_id ] ) ? $existing[ $ep_id ] : 0,
					'label'      => $r['label'],
					'type'       => $type,
					'options'    => self::options( isset( $r['option_data'] ) ? $r['option_data'] : '' ),
					'sort_order' => $i,
					'ep_id'      => $ep_id,
				)
			);
			$this->count( 'fields', isset( $existing[ $ep_id ] ) ? 'updated' : 'created' );
		}
	}

	/** EventPrime option_data (JSON or serialized, a list of strings or of {label|value}) → strings. */
	public static function options( $raw ) {
		$data = self::decode( $raw );
		$out  = array();
		foreach ( is_array( $data ) ? $data : array() as $o ) {
			if ( is_array( $o ) ) {
				foreach ( array( 'label', 'value', 'name', 'option' ) as $k ) {
					if ( isset( $o[ $k ] ) && is_scalar( $o[ $k ] ) && '' !== trim( (string) $o[ $k ] ) ) {
						$out[] = trim( (string) $o[ $k ] );
						break;
					}
				}
			} elseif ( is_scalar( $o ) && '' !== trim( (string) $o ) ) {
				$out[] = trim( (string) $o );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** JSON, serialized PHP or an array → array (or the scalar as it was). */
	public static function decode( $raw ) {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return json_decode( wp_json_encode( $raw ), true );
		}
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return array();
		}
		$j = json_decode( $raw, true );
		if ( null !== $j || 'null' === $raw ) {
			return $j;
		}
		$u = maybe_unserialize( $raw );
		if ( is_array( $u ) || is_object( $u ) ) {
			return json_decode( wp_json_encode( $u ), true );
		}
		return $raw;
	}

	/* ---------------------------------------------------------------------
	 * Events, groups and tickets
	 * ------------------------------------------------------------------ */

	/**
	 * EventPrime's date + time text → local "Y-m-d H:i:s". The date may be Y-m-d, d/m/Y or a
	 * Unix timestamp somewhere in the day (EventPrime keeps whatever time of day it was saved
	 * at); the time "6:00 PM" or "18:00".
	 */
	public static function local_datetime( $date, $time ) {
		$date = trim( (string) $date );
		$time = trim( (string) $time );
		if ( '' === $date ) {
			return '';
		}
		if ( ctype_digit( $date ) ) {
			// Any moment in the day: its date in the site's timezone.
			$d = wp_date( 'Y-m-d', (int) $date );
		} else {
			$d = '';
			foreach ( array( 'Y-m-d', 'd/m/Y', 'Y/m/d', 'd-m-Y', 'm/d/Y' ) as $f ) {
				$dt = DateTime::createFromFormat( '!' . $f, $date );
				if ( $dt && $dt->format( $f ) === $date ) {
					$d = $dt->format( 'Y-m-d' );
					break;
				}
			}
			if ( '' === $d && false !== strtotime( $date ) ) {
				$d = gmdate( 'Y-m-d', strtotime( $date . ' 12:00 UTC' ) );
			}
		}
		if ( '' === $d ) {
			return '';
		}
		$t = '00:00:00';
		if ( '' !== $time ) {
			$parsed = DateTime::createFromFormat( '!Y-m-d g:i A', '2000-01-01 ' . strtoupper( $time ) );
			if ( ! $parsed ) {
				$parsed = DateTime::createFromFormat( '!Y-m-d H:i', '2000-01-01 ' . $time );
			}
			if ( ! $parsed ) {
				$parsed = DateTime::createFromFormat( '!Y-m-d g:iA', '2000-01-01 ' . strtoupper( str_replace( ' ', '', $time ) ) );
			}
			if ( $parsed ) {
				$t = $parsed->format( 'H:i:00' );
			}
		}
		return $d . ' ' . $t;
	}

	/**
	 * An event's start or end as local "Y-m-d H:i:s". em_start_date_time / em_end_date_time hold
	 * the exact moment and are used when set; otherwise the date and time fields. A disagreement
	 * between the two is reported.
	 */
	private function event_moment( $p, $which ) {
		$text  = self::local_datetime( get_post_meta( $p->ID, "em_{$which}_date", true ), get_post_meta( $p->ID, "em_{$which}_time", true ) );
		$stamp = get_post_meta( $p->ID, "em_{$which}_date_time", true );
		if ( ! is_numeric( $stamp ) || (int) $stamp <= 0 ) {
			return $text;
		}
		$exact = wp_date( 'Y-m-d H:i:00', (int) $stamp );
		if ( $text && $text !== $exact ) {
			$this->warn( sprintf( 'Event %d "%s": %s date/time fields say %s but em_%s_date_time says %s. Using %s.', $p->ID, $p->post_title, $which, $text, $which, $exact, $exact ) );
		}
		return $exact;
	}

	private function events( $from ) {
		global $wpdb;
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID", 'em_event' ) );
		$this->log( count( $posts ) . ' EventPrime events found.' );
		foreach ( $posts as $p ) {
			$ep_id    = (int) $p->ID;
			$start    = $this->event_moment( $p, 'start' );
			$end      = $this->event_moment( $p, 'end' );
			// EventPrime has no all-day switch here; "12:00 AM to 11:59 PM" on one day means all day.
			$all_day  = (bool) get_post_meta( $ep_id, 'em_all_day', true )
				|| ( $start && $end && substr( $start, 0, 10 ) === substr( $end, 0, 10 ) && '00:00' === substr( $start, 11, 5 ) && '23:59' === substr( $end, 11, 5 ) );
			if ( '' === $start ) {
				$this->warn( "Event $ep_id \"{$p->post_title}\": no start date. Skipped." );
				$this->count( 'events', 'skipped' );
				continue;
			}
			if ( $from && substr( $start, 0, 10 ) < $from ) {
				$this->count( 'events', 'before --from' );
				continue;
			}

			$existing = get_posts(
				array(
					'post_type'      => LSET_CPT,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_lset_ep_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'meta_value'     => $ep_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				)
			);
			$data = array(
				'post_type'     => LSET_CPT,
				'post_title'    => $p->post_title,
				'post_content'  => $p->post_content,
				'post_excerpt'  => $p->post_excerpt,
				'post_status'   => in_array( $p->post_status, array( 'publish', 'draft', 'private', 'future', 'pending' ), true ) ? $p->post_status : 'draft',
				'post_author'   => $p->post_author,
				'post_date'     => $p->post_date,
				'post_date_gmt' => $p->post_date_gmt,
			);
			if ( $existing ) {
				$data['ID'] = (int) $existing[0];
				$id         = wp_update_post( wp_slash( $data ), true );
				$this->count( 'events', 'updated' );
			} else {
				$data['post_name'] = $p->post_name;
				$id                = wp_insert_post( wp_slash( $data ), true );
				$this->count( 'events', 'created' );
			}
			if ( is_wp_error( $id ) ) {
				$this->warn( "Event $ep_id: " . $id->get_error_message() );
				continue;
			}
			$id                    = (int) $id;
			$this->events[ $ep_id ] = $id;
			update_post_meta( $id, '_lset_ep_id', $ep_id );
			LSET_Store::save_dates( $id, $start, $end ? $end : $start, $all_day );

			$thumb = (int) get_post_meta( $ep_id, '_thumbnail_id', true );
			if ( ! $thumb ) {
				$gallery = (string) get_post_meta( $ep_id, 'em_gallery_image_ids', true );
				$thumb   = (int) strtok( $gallery, ',' );
			}
			if ( $thumb ) {
				set_post_thumbnail( $id, $thumb );
			}

			$venue_ids = array();
			$terms     = $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = 'em_venue'", $ep_id ) );
			// em_venue may be one id or a list like [ "0", "82" ].
			foreach ( (array) self::decode( get_post_meta( $ep_id, 'em_venue', true ) ) as $meta_venue ) {
				if ( is_numeric( $meta_venue ) && (int) $meta_venue > 0 ) {
					$terms[] = (int) $meta_venue;
				}
			}
			foreach ( $terms as $tid ) {
				if ( isset( $this->venues[ (int) $tid ] ) ) {
					$venue_ids[] = $this->venues[ (int) $tid ];
				}
			}
			wp_set_object_terms( $id, $venue_ids ? array( $venue_ids[0] ) : array(), LSET_VENUE );

			$this->event_fields( $ep_id, $id );
			$has_tickets = $this->event_tickets( $ep_id, $id, $start );
			LSET_Store::sale_window_from_tickets( $id ); // EventPrime sets the window per ticket; LSET per event.
			$enable      = strtolower( (string) get_post_meta( $ep_id, 'em_enable_booking', true ) );
			$bookings_on = $has_tickets && ! in_array( $enable, array( 'bookings_off', 'off', '0', 'no', 'external_bookings' ), true );
			update_post_meta( $id, '_lset_bookings', $bookings_on ? 1 : 0 );
		}
	}

	/** Which fields an event asks for, per attendee and per booking, and which are required. */
	private function event_fields( $ep_id, $id ) {
		$att  = self::decode( get_post_meta( $ep_id, 'em_event_checkout_attendee_fields', true ) );
		$book = self::decode( get_post_meta( $ep_id, 'em_event_checkout_booking_fields', true ) );
		$att  = is_array( $att ) ? $att : array();
		$book = is_array( $book ) ? $book : array();

		$pick = function ( array $conf, $ids_key ) use ( $ep_id ) {
			$ids      = isset( $conf[ $ids_key ] ) ? (array) $conf[ $ids_key ] : array();
			$required = isset( $conf[ $ids_key . '_required' ] ) ? array_map( 'intval', (array) $conf[ $ids_key . '_required' ] ) : array();
			$out      = array();
			foreach ( $ids as $ep_fid ) {
				$ep_fid = (int) $ep_fid;
				if ( isset( $this->fields[ $ep_fid ] ) ) {
					$out[] = array(
						'field_id' => $this->fields[ $ep_fid ],
						'required' => in_array( $ep_fid, $required, true ),
					);
				} else {
					$this->warn( "Event $ep_id: uses EventPrime field $ep_fid, which wasn't found." );
				}
			}
			return $out;
		};
		LSET_Store::set_event_fields( $id, 'attendee', $pick( $att, 'em_event_checkout_fields_data' ) );
		LSET_Store::set_event_fields( $id, 'booking', $pick( $book, 'em_event_booking_fields_data' ) );

		// Names: look for EventPrime's first/last-name switches and their "required" flags.
		$enabled  = false;
		$required = false;
		foreach ( $att as $k => $v ) {
			if ( false === strpos( (string) $k, 'name' ) || ! $v ) {
				continue;
			}
			if ( false !== strpos( (string) $k, 'required' ) ) {
				$required = true;
			} else {
				$enabled = true;
			}
		}
		update_post_meta( $id, '_lset_name_fields', $enabled ? ( $required ? 'required' : 'optional' ) : ( $att ? 'off' : 'required' ) );
	}

	/** Groups and tickets. Returns whether the event has any tickets. */
	private function event_tickets( $ep_id, $id, $start ) {
		global $wpdb;
		$cats_table = self::ep_table( 'eventprime_ticket_categories' );
		$opts_table = self::ep_table( 'em_price_options' );

		$have_groups = array();
		foreach ( LSET_Store::groups( $id ) as $g ) {
			if ( $g['ep_id'] ) {
				$have_groups[ $g['ep_id'] ] = $g['id'];
			}
		}
		if ( self::table_exists( $cats_table ) ) {
			$cats = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $cats_table WHERE event_id = %d ORDER BY id", $ep_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $cats as $i => $c ) {
				$cid                  = (int) $c['id'];
				$cap                  = isset( $c['capacity'] ) ? (int) $c['capacity'] : 0;
				$this->groups[ $cid ] = LSET_Store::save_group(
					array(
						'id'         => isset( $have_groups[ $cid ] ) ? $have_groups[ $cid ] : 0,
						'event_id'   => $id,
						'name'       => $c['name'],
						'capacity'   => $cap > 0 ? $cap : null,
						'sort_order' => isset( $c['priority'] ) ? (int) $c['priority'] : $i,
						'ep_id'      => $cid,
					)
				);
				$this->count( 'ticket groups', isset( $have_groups[ $cid ] ) ? 'updated' : 'created' );
			}
		}

		if ( ! self::table_exists( $opts_table ) ) {
			$this->warn( "No $opts_table table: no tickets to copy." );
			return false;
		}
		$have = array();
		foreach ( LSET_Store::tickets( $id ) as $t ) {
			if ( $t['ep_id'] ) {
				$have[ $t['ep_id'] ] = $t['id'];
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $opts_table WHERE event_id = %d ORDER BY priority, id", $ep_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $i => $r ) {
			$oid  = (int) $r['id'];
			$sale = self::sale_window( isset( $r['booking_starts'] ) ? $r['booking_starts'] : '', isset( $r['booking_ends'] ) ? $r['booking_ends'] : '', $start );
			foreach ( $sale['warnings'] as $w ) {
				$this->warn( "Ticket $oid \"{$r['name']}\": $w" );
			}
			$cap     = isset( $r['capacity'] ) ? (int) $r['capacity'] : 0;
			$status  = isset( $r['status'] ) ? strtolower( (string) $r['status'] ) : '1';
			$cat     = isset( $r['category_id'] ) ? (int) $r['category_id'] : 0;
			$min     = isset( $r['min_ticket_no'] ) ? (int) $r['min_ticket_no'] : 0;
			$max     = isset( $r['max_ticket_no'] ) ? (int) $r['max_ticket_no'] : 0;
			$left_at = isset( $r['show_remaining_tickets'] ) && $r['show_remaining_tickets'] ? 10 : null;
			$this->tickets[ $oid ] = LSET_Store::save_ticket(
				array(
					'id'              => isset( $have[ $oid ] ) ? $have[ $oid ] : 0,
					'event_id'        => $id,
					'group_id'        => $cat && isset( $this->groups[ $cat ] ) ? $this->groups[ $cat ] : 0,
					'name'            => $r['name'],
					'description'     => isset( $r['description'] ) ? wp_strip_all_tags( (string) $r['description'] ) : '',
					'price'           => isset( $r['price'] ) ? (float) $r['price'] : 0,
					'capacity'        => $cap > 0 ? $cap : '',
					'sale_start_mode' => $sale['mode'],
					'sale_start'      => $sale['start'],
					'sale_start_days' => $sale['days'],
					'sale_end'        => $sale['end'],
					'min_qty'         => $min > 1 ? $min : 0,
					'max_qty'         => max( 0, $max ),
					'sort_order'      => isset( $r['priority'] ) ? (int) $r['priority'] : $i,
					'hidden'          => in_array( $status, array( '0', 'inactive', 'disabled', 'hidden', 'draft' ), true ),
					'show_left_below' => $left_at,
					'ep_id'           => $oid,
				)
			);
			$this->count( 'tickets', isset( $have[ $oid ] ) ? 'updated' : 'created' );
		}
		return (bool) $rows;
	}

	/**
	 * EventPrime's booking_starts / booking_ends JSON → our sale start/end.
	 *
	 * Seen shapes: {"booking_type":"custom_date","start_date":"2026-11-01","start_time":"9:00 AM"},
	 * {"booking_type":"relative_date","days":"14","days_option":"before","event_option":"event_start"},
	 * {"booking_type":"event_date","event_option":"event_start"}. Anything else is logged.
	 *
	 * @return array mode, start, days, end, warnings.
	 */
	public static function sale_window( $starts_raw, $ends_raw, $event_start ) {
		$out = array(
			'mode'     => '',
			'start'    => '',
			'days'     => null,
			'end'      => '',
			'warnings' => array(),
		);
		$s   = self::decode( $starts_raw );
		if ( is_array( $s ) && $s ) {
			$type = isset( $s['booking_type'] ) ? (string) $s['booking_type'] : '';
			if ( 'custom_date' === $type ) {
				$d = self::local_datetime( isset( $s['start_date'] ) ? $s['start_date'] : '', isset( $s['start_time'] ) ? $s['start_time'] : '' );
				if ( $d ) {
					$out['mode']  = 'date';
					$out['start'] = $d;
				}
			} elseif ( 'relative_date' === $type ) {
				$days   = isset( $s['days'] ) ? (int) $s['days'] : 0;
				$before = ! isset( $s['days_option'] ) || 'after' !== $s['days_option'];
				if ( $before && ( ! isset( $s['event_option'] ) || 'event_start' === $s['event_option'] ) ) {
					$out['mode'] = 'days';
					$out['days'] = $days;
				} elseif ( $event_start ) {
					// Rare: N days after, or relative to the end. Work out the date once.
					$out['mode']     = 'date';
					$out['start']    = LSET_Time::shift( substr( $event_start, 0, 10 ) . ' 00:00:00', ( $before ? '-' : '+' ) . $days . ' days' );
					$out['warnings'][] = 'sale start relative to "' . ( isset( $s['event_option'] ) ? $s['event_option'] : '' ) . '", saved as the date ' . $out['start'] . '.';
				}
			} elseif ( '' !== $type && 'event_date' !== $type ) {
				$out['warnings'][] = 'unknown booking_starts type "' . $type . '"; on sale now.';
			}
		}
		$e = self::decode( $ends_raw );
		if ( is_array( $e ) && $e ) {
			$type = isset( $e['booking_type'] ) ? (string) $e['booking_type'] : '';
			if ( 'custom_date' === $type ) {
				$out['end'] = self::local_datetime( isset( $e['end_date'] ) ? $e['end_date'] : ( isset( $e['start_date'] ) ? $e['start_date'] : '' ), isset( $e['end_time'] ) ? $e['end_time'] : ( isset( $e['start_time'] ) ? $e['start_time'] : '' ) );
			} elseif ( 'relative_date' === $type && $event_start ) {
				$days       = isset( $e['days'] ) ? (int) $e['days'] : 0;
				$before     = ! isset( $e['days_option'] ) || 'after' !== $e['days_option'];
				$out['end'] = LSET_Time::shift( $event_start, ( $before ? '-' : '+' ) . $days . ' days' );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Bookings
	 * ------------------------------------------------------------------ */

	private function bookings() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID", 'em_booking' ) );
		$this->log( count( $ids ) . ' EventPrime bookings found.' );
		$table = LSET_Install::table( 'bookings' );
		$att   = LSET_Install::table( 'attendees' );

		// Abandoned attempts: EventPrime sometimes left extra bookings (usually "pending") on the
		// same Woo order as the real one. Those keep their own EventPrime status and no order line,
		// so they don't count as sold and don't follow the order later.
		$by_order = array();
		foreach ( $ids as $bid ) {
			$oid = (int) get_post_meta( $bid, 'wc_order_id', true );
			if ( $oid ) {
				$by_order[ $oid ][] = (int) $bid;
			}
		}
		$extra = array();
		foreach ( $by_order as $oid => $bids ) {
			if ( count( $bids ) < 2 ) {
				continue;
			}
			$real = array_values(
				array_filter(
					$bids,
					function ( $b ) {
						return 'completed' === self::ep_status( get_post_meta( $b, 'em_status', true ) );
					}
				)
			);
			$real = $real ? $real : array( max( $bids ) );
			foreach ( array_diff( $bids, $real ) as $b ) {
				$extra[ $b ] = true;
			}
			if ( array_diff( $bids, $real ) ) {
				$this->warn( "Order #$oid has " . count( $bids ) . ' EventPrime bookings (' . implode( ', ', $bids ) . '); ' . implode( ', ', $real ) . ' taken as the real one, the rest keep their EventPrime status.' );
			}
		}

		foreach ( $ids as $bid ) {
			$bid      = (int) $bid;
			$ep_event = (int) get_post_meta( $bid, 'em_event', true );
			if ( ! isset( $this->events[ $ep_event ] ) ) {
				$this->count( 'bookings', 'skipped (event not migrated)' );
				continue;
			}
			$event_id = $this->events[ $ep_event ];
			$info     = self::decode( get_post_meta( $bid, 'em_order_info', true ) );
			$info     = is_array( $info ) ? $info : array();
			$names    = self::decode( get_post_meta( $bid, 'em_attendee_names', true ) );
			$names    = is_array( $names ) ? $names : array();
			$bfields  = self::answers( self::decode( get_post_meta( $bid, 'em_booking_fields_data', true ) ) );

			$order_id = (int) get_post_meta( $bid, 'wc_order_id', true );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			$is_extra = isset( $extra[ $bid ] );
			$status   = $order && ! $is_extra ? $order->get_status() : self::ep_status( get_post_meta( $bid, 'em_status', true ) );
			if ( $is_extra ) {
				$this->count( 'bookings', 'extra on an order (kept as ' . $status . ')' );
			}
			if ( $order_id && ! $order ) {
				$this->warn( "Booking $bid: Woo order #$order_id not found; status taken from EventPrime." );
			}
			if ( ! $order_id ) {
				$this->warn( "Booking $bid has no Woo order (" . get_post_meta( $bid, 'em_payment_method', true ) . '); kept as a migrated booking with no order link.' );
			}
			// EventPrime made one order line per ticket type, each with ep_tickets_data.tkt_id.
			$lines = array();
			if ( $order && ! $is_extra ) {
				$items = $order->get_items();
				foreach ( $items as $iid => $item ) {
					$data = self::decode( $item->get_meta( 'ep_tickets_data', true ) );
					if ( is_array( $data ) && isset( $data['tkt_id'] ) ) {
						$lines[ (int) $data['tkt_id'] ] = (int) $iid;
					}
				}
				if ( ! $lines && 1 === count( $items ) ) {
					$lines['*'] = (int) key( $items );
				}
			}
			$tickets = isset( $info['tickets'] ) && is_array( $info['tickets'] ) ? $info['tickets'] : array();
			if ( ! $tickets ) {
				$this->warn( "Booking $bid: no tickets in em_order_info. Skipped." );
				$this->count( 'bookings', 'skipped (no tickets)' );
				continue;
			}
			$buyer_name = trim( ( isset( $info['user_name'] ) ? $info['user_name'] : '' ) . ' ' . ( isset( $info['last_name'] ) ? $info['last_name'] : '' ) );
			if ( $order && '' === $buyer_name ) {
				$buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			}
			$created = get_post_field( 'post_date_gmt', $bid );
			$created = $created && '0000-00-00 00:00:00' !== $created ? $created : LSET_Time::now_utc();

			foreach ( $tickets as $t ) {
				$ep_tid = isset( $t['id'] ) ? (int) $t['id'] : 0;
				if ( ! isset( $this->tickets[ $ep_tid ] ) ) {
					$this->warn( "Booking $bid: ticket $ep_tid isn't one of the event's tickets. Line skipped." );
					continue;
				}
				$ticket_id = $this->tickets[ $ep_tid ];
				$item_id   = isset( $lines[ $ep_tid ] ) ? $lines[ $ep_tid ] : ( isset( $lines['*'] ) ? $lines['*'] : 0 );
				if ( $order && ! $is_extra && ! $item_id ) {
					$this->warn( "Booking $bid: no line on order #$order_id for ticket $ep_tid; it won't follow later changes to the order." );
				}
				$qty       = isset( $t['qty'] ) ? (int) $t['qty'] : 0;
				$price     = isset( $t['price'] ) ? (float) $t['price'] : 0;
				$subtotal  = isset( $t['subtotal'] ) ? (float) $t['subtotal'] : $price * $qty;
				$row       = array(
					'event_id'       => $event_id,
					'ticket_id'      => $ticket_id,
					'order_id'       => $order_id,
					'order_item_id'  => $item_id,
					'qty'            => $qty,
					'refunded_qty'   => 'refunded' === $status ? $qty : ( $order && $item_id ? min( $qty, absint( $order->get_qty_refunded_for_item( $item_id ) ) ) : 0 ),
					'unit_price'     => round( $price, 2 ),
					'line_total'     => round( $subtotal, 2 ),
					'status'         => $status,
					'source'         => 'migrated',
					'hold_until'     => null,
					'buyer_name'     => $buyer_name,
					'buyer_email'    => isset( $info['user_email'] ) ? (string) $info['user_email'] : ( $order ? $order->get_billing_email() : '' ),
					'buyer_phone'    => isset( $info['user_phone'] ) ? (string) $info['user_phone'] : ( $order ? $order->get_billing_phone() : '' ),
					'booking_fields' => wp_json_encode( $bfields ),
					'user_id'        => (int) get_post_meta( $bid, 'em_user', true ),
					'created_at'     => $created,
					'updated_at'     => LSET_Time::now_utc(),
					'ep_booking_id'  => $bid,
				);
				$existing  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE ep_booking_id = %d AND ticket_id = %d", $bid, $ticket_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $existing ) {
					$wpdb->update( $table, $row, array( 'id' => $existing ) );
					$booking_id = $existing;
					$wpdb->delete( $att, array( 'booking_id' => $booking_id ) );
					$this->count( 'bookings', 'updated' );
				} else {
					$wpdb->insert( $table, $row );
					$booking_id = (int) $wpdb->insert_id;
					$this->count( 'bookings', 'created' );
				}

				$people = isset( $names[ $ep_tid ] ) && is_array( $names[ $ep_tid ] ) ? array_values( $names[ $ep_tid ] ) : array();
				for ( $i = 0; $i < $qty; $i++ ) {
					$p     = isset( $people[ $i ] ) && is_array( $people[ $i ] ) ? $people[ $i ] : array();
					$first = isset( $p['name']['first_name'] ) ? (string) $p['name']['first_name'] : '';
					$last  = isset( $p['name']['last_name'] ) ? (string) $p['name']['last_name'] : '';
					unset( $p['name'] );
					$wpdb->insert(
						$att,
						array(
							'booking_id' => $booking_id,
							'event_id'   => $event_id,
							'ticket_id'  => $ticket_id,
							'seq'        => $i + 1,
							'first_name' => mb_substr( sanitize_text_field( $first ), 0, 100 ),
							'last_name'  => mb_substr( sanitize_text_field( $last ), 0, 100 ),
							'fields'     => wp_json_encode( self::answers( $p ) ),
						)
					);
					$this->count( 'attendees', 'written' );
				}
				if ( count( $people ) > $qty ) {
					$this->warn( "Booking $bid ticket $ep_tid: " . count( $people ) . " names for $qty tickets; extra names dropped." );
				}
			}
		}
	}

	/**
	 * EventPrime answers {ep_field_id: {label, <value>}} → ours {field_id: {label, value}}.
	 * The value sits under a key we can't predict (often the label itself, or "value").
	 */
	public function answers( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $ep_fid => $a ) {
			if ( ! is_numeric( $ep_fid ) ) {
				continue;
			}
			$label = '';
			$value = '';
			if ( is_array( $a ) ) {
				$label = isset( $a['label'] ) ? (string) $a['label'] : '';
				foreach ( $a as $k => $v ) {
					if ( 'label' === $k || 'type' === $k ) {
						continue;
					}
					if ( is_array( $v ) ) {
						$v = implode( ', ', array_filter( array_map( 'strval', $v ), 'strlen' ) );
					}
					if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
						$value = trim( (string) $v );
						break;
					}
				}
			} elseif ( is_scalar( $a ) ) {
				$value = (string) $a;
			}
			if ( '' === $value ) {
				continue;
			}
			$fid         = isset( $this->fields[ (int) $ep_fid ] ) ? $this->fields[ (int) $ep_fid ] : 'ep' . (int) $ep_fid;
			$out[ $fid ] = array(
				'label' => sanitize_text_field( $label ),
				'value' => sanitize_textarea_field( $value ),
			);
		}
		return $out;
	}

	private static function ep_status( $s ) {
		$s   = strtolower( (string) $s );
		$map = array(
			'completed' => 'completed',
			'complete'  => 'completed',
			'pending'   => 'pending',
			'cancelled' => 'cancelled',
			'canceled'  => 'cancelled',
			'refunded'  => 'refunded',
			'failed'    => 'failed',
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : 'pending';
	}

	/* ---------------------------------------------------------------------
	 * Report
	 * ------------------------------------------------------------------ */

	/** Sold per ticket: EventPrime's completed bookings vs LSET's counted bookings. */
	private function compare() {
		global $wpdb;
		foreach ( $this->events as $ep_event => $event_id ) {
			$ep_sold = array();
			$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'em_event' WHERE p.post_type = 'em_booking' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_value = %s", (string) $ep_event ) );
			foreach ( $ids as $bid ) {
				if ( 'completed' !== self::ep_status( get_post_meta( $bid, 'em_status', true ) ) ) {
					continue;
				}
				$info = self::decode( get_post_meta( $bid, 'em_order_info', true ) );
				foreach ( isset( $info['tickets'] ) && is_array( $info['tickets'] ) ? $info['tickets'] : array() as $t ) {
					$tid             = isset( $t['id'] ) ? (int) $t['id'] : 0;
					$ep_sold[ $tid ] = ( isset( $ep_sold[ $tid ] ) ? $ep_sold[ $tid ] : 0 ) + ( isset( $t['qty'] ) ? (int) $t['qty'] : 0 );
				}
			}
			$ours = array();
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ticket_id, SUM(GREATEST(qty - refunded_qty, 0)) n FROM ' . LSET_Install::table( 'bookings' ) . " WHERE event_id = %d AND status IN ('completed','processing','on-hold') GROUP BY ticket_id", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $r ) {
				$ours[ (int) $r['ticket_id'] ] = (int) $r['n'];
			}
			$e = LSET_Store::event( $event_id );
			foreach ( LSET_Store::tickets( $event_id ) as $t ) {
				$a                         = $t['ep_id'] && isset( $ep_sold[ $t['ep_id'] ] ) ? $ep_sold[ $t['ep_id'] ] : 0;
				$b                         = isset( $ours[ $t['id'] ] ) ? $ours[ $t['id'] ] : 0;
				$this->report['compare'][] = array(
					'ep_event' => $ep_event,
					'event'    => $event_id,
					'title'    => $e['title'],
					'start'    => $e['start'],
					'ticket'   => $t['name'],
					'ep_sold'  => $a,
					'sold'     => $b,
					'match'    => $a === $b ? 'yes' : 'NO',
				);
			}
		}
	}

	private function print_report( array $r ) {
		WP_CLI::log( '' );
		WP_CLI::log( $r['dry_run'] ? '== Dry run: what a real run would do' : '== Done' );
		foreach ( $r['counts'] as $what => $hows ) {
			$bits = array();
			foreach ( $hows as $how => $n ) {
				$bits[] = "$n $how";
			}
			WP_CLI::log( str_pad( ucfirst( $what ) . ':', 16 ) . implode( ', ', $bits ) );
		}
		WP_CLI::log( '' );
		WP_CLI::log( '== Sold per ticket (EventPrime completed vs LSET booked)' );
		if ( $r['compare'] ) {
			$rows = array_map(
				function ( $c ) {
					return array(
						'Event'  => $c['title'],
						'Starts' => substr( $c['start'], 0, 16 ),
						'Ticket' => $c['ticket'],
						'EP'     => $c['ep_sold'],
						'LSET'   => $c['sold'],
						'Match'  => $c['match'],
					);
				},
				$r['compare']
			);
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'Event', 'Starts', 'Ticket', 'EP', 'LSET', 'Match' ) );
		}
		$bad = array_filter(
			$r['compare'],
			function ( $c ) {
				return 'NO' === $c['match'];
			}
		);
		WP_CLI::log( $bad ? count( $bad ) . ' ticket(s) differ. A difference usually means the Woo order status and EventPrime\'s status disagree; see the warnings.' : 'Every ticket matches.' );
		if ( $r['warnings'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( '== Warnings (' . count( $r['warnings'] ) . ')' );
			foreach ( $r['warnings'] as $w ) {
				WP_CLI::log( '- ' . $w );
			}
		}
	}
}
