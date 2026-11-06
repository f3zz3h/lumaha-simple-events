<?php
/**
 * Front end: shortcodes, the event page, the booking box and the live availability route.
 *
 * Page HTML holds no counts and no nonces, so it is safe to cache (LiteSpeed, Cloudflare).
 * What's left comes from GET /wp-json/lset/v1/availability/<event>, sent with no-store.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Frontend {

	/** Set while we render our own content, so the_content filter doesn't run twice. */
	private static $rendering = false;

	public static function init() {
		add_shortcode( 'lset_events', array( __CLASS__, 'shortcode_events' ) );
		add_shortcode( 'lset_event', array( __CLASS__, 'shortcode_event' ) );
		add_filter( 'the_content', array( __CLASS__, 'single_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	public static function register_assets() {
		wp_register_style( 'lset', LSET_URL . 'assets/lset.css', array(), LSET_VERSION );
		wp_register_script( 'lset', LSET_URL . 'assets/lset.js', array(), LSET_VERSION, true );
		wp_localize_script(
			'lset',
			'lsetText',
			array(
				'attendee'     => __( 'Ticket %1$d: %2$s', 'lumaha-simple-event-tickets' ),
				'firstName'    => __( 'First name', 'lumaha-simple-event-tickets' ),
				'lastName'     => __( 'Last name', 'lumaha-simple-event-tickets' ),
				'required'     => __( '(required)', 'lumaha-simple-event-tickets' ),
				'choose'       => __( 'Choose…', 'lumaha-simple-event-tickets' ),
				'yes'          => __( 'Yes', 'lumaha-simple-event-tickets' ),
				'soldOut'      => __( 'Sold out', 'lumaha-simple-event-tickets' ),
				'left'         => __( '%d left', 'lumaha-simple-event-tickets' ),
				'ended'        => __( 'Sales have ended', 'lumaha-simple-event-tickets' ),
				'offline'      => __( 'We couldn\'t check live availability. Please refresh the page.', 'lumaha-simple-event-tickets' ),
				'pickOne'      => __( 'Please choose at least one ticket.', 'lumaha-simple-event-tickets' ),
			)
		);
		if ( is_singular( LSET_CPT ) ) {
			wp_enqueue_style( 'lset' );
		}
	}

	private static function enqueue() {
		wp_enqueue_style( 'lset' );
		wp_enqueue_script( 'lset' );
	}

	/* ---------------------------------------------------------------------
	 * Formatting
	 * ------------------------------------------------------------------ */

	/** "Friday 12 December 2026, 6:00pm – 8:00pm" (or across days). */
	public static function when( array $e ) {
		if ( ! $e['start'] ) {
			return '';
		}
		$date_fmt = apply_filters( 'lset_date_format', 'l j F Y' );
		$time_fmt = get_option( 'time_format' );
		$same_day = substr( $e['start'], 0, 10 ) === substr( $e['end'], 0, 10 );
		$out      = LSET_Time::format( $e['start'], $date_fmt, $e['tz'] );
		if ( $e['all_day'] ) {
			return $same_day ? $out : $out . ' – ' . LSET_Time::format( $e['end'], $date_fmt, $e['tz'] );
		}
		$out .= ', ' . LSET_Time::format( $e['start'], $time_fmt, $e['tz'] );
		if ( $e['end'] && $e['end'] !== $e['start'] ) {
			$out .= ' – ' . ( $same_day ? '' : LSET_Time::format( $e['end'], $date_fmt, $e['tz'] ) . ', ' ) . LSET_Time::format( $e['end'], $time_fmt, $e['tz'] );
		}
		return $out;
	}

	public static function venue_line( $venue ) {
		if ( ! $venue ) {
			return '';
		}
		$bits = array_filter( array( $venue['name'], $venue['address'], $venue['postcode'] ), 'strlen' );
		return implode( ', ', $bits );
	}

	/** Lowest price of the visible tickets: "From £3.50", "Free", or '' with no tickets. */
	public static function from_price( $event_id ) {
		$prices = wp_list_pluck( LSET_Store::tickets( $event_id, false ), 'price' );
		if ( ! $prices ) {
			return '';
		}
		$min = min( $prices );
		$max = max( $prices );
		if ( $max <= 0 ) {
			return __( 'Free', 'lumaha-simple-event-tickets' );
		}
		if ( $min === $max ) {
			return wp_strip_all_tags( wc_price( $min ) );
		}
		/* translators: %s: price */
		return sprintf( __( 'From %s', 'lumaha-simple-event-tickets' ), wp_strip_all_tags( wc_price( $min ) ) );
	}

	public static function is_past( array $e ) {
		$end = (int) get_post_meta( $e['id'], '_lset_end_utc', true );
		return $end && $end < time();
	}

	/* ---------------------------------------------------------------------
	 * [lset_events]
	 * ------------------------------------------------------------------ */

	public static function shortcode_events( $atts ) {
		$atts = shortcode_atts(
			array(
				'upcoming'  => '', // Default: yes, unless past="1" is given.
				'past'      => '0',
				'order'     => '', // Default: soonest first; newest first for past events.
				'limit'     => '-1',
				'show_past' => '0', // Same as past="1".
			),
			$atts,
			'lset_events'
		);
		self::enqueue();
		$past     = '1' === (string) $atts['past'] || '1' === (string) $atts['show_past'];
		$upcoming = '' === (string) $atts['upcoming'] ? ! $past : '1' === (string) $atts['upcoming'];
		$order    = strtolower( (string) $atts['order'] );
		$order    = '' === $order ? ( $past && ! $upcoming ? 'DESC' : 'ASC' ) : ( 'desc' === $order ? 'DESC' : 'ASC' );
		$args     = array(
			'post_type'      => LSET_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['limit'] ? (int) $atts['limit'] : -1,
			'meta_key'       => '_lset_start_utc', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'orderby'        => 'meta_value_num',
			'order'          => $order,
		);
		if ( $past && ! $upcoming ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				array(
					'key'     => '_lset_end_utc',
					'value'   => time(),
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			);
		} elseif ( ! $past ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				array(
					'key'     => '_lset_end_utc',
					'value'   => time(),
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			);
		}
		$q = new WP_Query( $args );
		if ( ! $q->have_posts() ) {
			$none = $past && ! $upcoming ? __( 'No past events yet.', 'lumaha-simple-event-tickets' ) : __( 'No events coming up just now. Please check back soon.', 'lumaha-simple-event-tickets' );
			return '<div class="lset-events lset-events--empty"><p>' . esc_html( $none ) . '</p></div>';
		}
		$html = '<div class="lset-events">';
		foreach ( $q->posts as $post ) {
			$e     = LSET_Store::event( $post );
			$price = $e['bookings'] ? self::from_price( $e['id'] ) : '';
			$img   = get_the_post_thumbnail( $post, 'medium_large', array( 'class' => 'lset-card__img', 'loading' => 'lazy' ) );
			$html .= '<article class="lset-card">';
			if ( $img ) {
				$html .= '<a class="lset-card__media" href="' . esc_url( $e['url'] ) . '">' . $img . '</a>';
			}
			$html .= '<div class="lset-card__body">';
			$html .= '<h3 class="lset-card__title"><a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['title'] ) . '</a></h3>';
			$html .= '<p class="lset-card__when">' . esc_html( self::when( $e ) ) . '</p>';
			if ( $e['venue'] ) {
				$html .= '<p class="lset-card__venue">' . esc_html( $e['venue']['name'] ) . '</p>';
			}
			if ( has_excerpt( $post ) ) {
				$html .= '<p class="lset-card__excerpt">' . esc_html( get_the_excerpt( $post ) ) . '</p>';
			}
			$html .= '<p class="lset-card__foot">';
			if ( $price ) {
				$html .= '<span class="lset-card__price">' . esc_html( $price ) . '</span> ';
			}
			$label = $e['bookings'] && ! self::is_past( $e ) ? __( 'Book', 'lumaha-simple-event-tickets' ) : __( 'More info', 'lumaha-simple-event-tickets' );
			$html .= '<a class="lset-button" href="' . esc_url( $e['url'] ) . '">' . esc_html( $label ) . '</a></p>';
			$html .= '</div></article>';
		}
		$html .= '</div>';
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Single event page and [lset_event id=""]
	 * ------------------------------------------------------------------ */

	public static function single_content( $content ) {
		if ( self::$rendering || ! is_singular( LSET_CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$e = LSET_Store::event( get_the_ID() );
		if ( ! $e ) {
			return $content;
		}
		return self::details( $e ) . $content . self::booking_box( $e );
	}

	public static function shortcode_event( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'      => '0',
				'details' => '1',
			),
			$atts,
			'lset_event'
		);
		$e = LSET_Store::event( (int) $atts['id'] );
		if ( ! $e || 'publish' !== $e['status'] ) {
			return '';
		}
		$html = '1' === (string) $atts['details'] ? self::details( $e, true ) : '';
		return $html . self::booking_box( $e );
	}

	/** Date, time and venue block. */
	public static function details( array $e, $with_title = false ) {
		self::enqueue();
		$html = '<div class="lset-details">';
		if ( $with_title ) {
			$html .= '<h2 class="lset-details__title"><a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['title'] ) . '</a></h2>';
		}
		$html .= '<p class="lset-details__when"><span class="lset-label">' . esc_html__( 'When', 'lumaha-simple-event-tickets' ) . '</span> ';
		$html .= '<time datetime="' . esc_attr( LSET_Time::iso( $e['start'], $e['tz'] ) ) . '">' . esc_html( self::when( $e ) ) . '</time></p>';
		if ( $e['venue'] ) {
			$html .= '<p class="lset-details__where"><span class="lset-label">' . esc_html__( 'Where', 'lumaha-simple-event-tickets' ) . '</span> ' . esc_html( self::venue_line( $e['venue'] ) );
			if ( $e['venue']['map_url'] ) {
				$html .= ' <a href="' . esc_url( $e['venue']['map_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'lumaha-simple-event-tickets' ) . '</a>';
			}
			$html .= '</p>';
		}
		$html .= '</div>';
		return $html;
	}

	/** The ticket picker. */
	public static function booking_box( array $e ) {
		self::enqueue();
		if ( ! $e['bookings'] ) {
			return $e['closed_msg'] ? '<div class="lset-box lset-box--closed"><p>' . esc_html( $e['closed_msg'] ) . '</p></div>' : '';
		}
		if ( self::is_past( $e ) ) {
			return '<div class="lset-box lset-box--closed"><p>' . esc_html__( 'This event has finished.', 'lumaha-simple-event-tickets' ) . '</p></div>';
		}
		$tickets = LSET_Store::tickets( $e['id'], false );
		if ( ! $tickets ) {
			return '';
		}

		$attendee_fields = LSET_Store::event_fields( $e['id'], 'attendee' );
		$booking_fields  = LSET_Store::event_fields( $e['id'], 'booking' );
		$old             = LSET_Cart::rejected_post( $e['id'] );

		$notices = '';
		if ( function_exists( 'wc_print_notices' ) && WC()->session && wc_notice_count() > 0 && $old ) {
			$notices = wc_print_notices( true );
		}

		$data = array(
			'event'  => $e['id'],
			'rest'   => esc_url_raw( rest_url( 'lset/v1/availability/' . $e['id'] ) ),
			'fields' => array_map(
				function ( $f ) {
					return array(
						'id'          => $f['id'],
						'label'       => $f['label'],
						'type'        => $f['type'],
						'options'     => $f['options'],
						'placeholder' => $f['placeholder'],
						'required'    => $f['required'],
					);
				},
				$attendee_fields
			),
			'names'  => $e['name_fields'],
			'old'    => $old ? $old : new stdClass(),
		);

		ob_start();
		?>
		<div class="lset-box" id="lset-book" data-lset="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
			<?php echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce notice HTML. ?>
			<h2 class="lset-box__title"><?php esc_html_e( 'Tickets', 'lumaha-simple-event-tickets' ); ?></h2>
			<div class="lset-status" role="status" aria-live="polite"></div>
			<?php if ( $e['closed_msg'] ) : ?>
				<p class="lset-closed-msg" hidden><?php echo esc_html( $e['closed_msg'] ); ?></p>
			<?php endif; ?>
			<form class="lset-form" method="post" action="<?php echo esc_url( $e['url'] ); ?>#lset-book" novalidate>
				<input type="hidden" name="lset_add" value="<?php echo (int) $e['id']; ?>">
				<div class="lset-tickets">
				<?php foreach ( $tickets as $t ) : ?>
					<?php
					$max      = LSET_Store::max_per_order( $t );
					$selected = isset( $old['qty'][ $t['id'] ] ) ? (int) $old['qty'][ $t['id'] ] : 0;
					?>
					<div class="lset-ticket" data-ticket="<?php echo (int) $t['id']; ?>" data-name="<?php echo esc_attr( $t['name'] ); ?>">
						<div class="lset-ticket__info">
							<label class="lset-ticket__name" for="lset-qty-<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['name'] ); ?></label>
							<span class="lset-ticket__price"><?php echo $t['price'] > 0 ? wp_kses_post( wc_price( $t['price'] ) ) : esc_html__( 'Free', 'lumaha-simple-event-tickets' ); ?></span>
							<?php if ( $t['description'] ) : ?>
								<span class="lset-ticket__desc"><?php echo esc_html( $t['description'] ); ?></span>
							<?php endif; ?>
							<span class="lset-ticket__left"></span>
						</div>
						<select class="lset-qty" id="lset-qty-<?php echo (int) $t['id']; ?>" name="lset_qty[<?php echo (int) $t['id']; ?>]" data-min="0" data-max="<?php echo (int) $max; ?>" disabled>
							<option value="0">0</option>
							<?php for ( $n = 1; $n <= $max; $n++ ) : ?>
								<option value="<?php echo (int) $n; ?>" <?php selected( $selected, $n ); ?>><?php echo (int) $n; ?></option>
							<?php endfor; ?>
						</select>
						<div class="lset-attendees"></div>
					</div>
				<?php endforeach; ?>
				</div>
				<?php if ( $booking_fields ) : ?>
					<fieldset class="lset-booking-fields">
						<legend><?php esc_html_e( 'About your booking', 'lumaha-simple-event-tickets' ); ?></legend>
						<?php
						foreach ( $booking_fields as $f ) {
							$value = isset( $old['book'][ $f['id'] ] ) ? $old['book'][ $f['id'] ] : '';
							echo self::field_html( $f, 'lset_book[' . $f['id'] . ']', 'lset-b-' . $f['id'], $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
						}
						?>
					</fieldset>
				<?php endif; ?>
				<?php if ( is_user_logged_in() ) : ?>
					<?php wp_nonce_field( 'lset_add_' . $e['id'], '_lset_nonce', false ); ?>
				<?php endif; ?>
				<noscript><p><?php esc_html_e( 'Please turn on JavaScript to book tickets.', 'lumaha-simple-event-tickets' ); ?></p></noscript>
				<p class="lset-submit"><button type="submit" class="lset-button lset-add button alt" disabled><?php esc_html_e( 'Add to basket', 'lumaha-simple-event-tickets' ); ?></button></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/** One booking-level field, server-rendered (attendee fields are drawn by lset.js from the same data). */
	public static function field_html( array $f, $name, $id, $value ) {
		$req   = $f['required'] ? ' required' : '';
		$label = esc_html( $f['label'] ) . ( $f['required'] ? ' <abbr class="required" title="' . esc_attr__( 'required', 'lumaha-simple-event-tickets' ) . '">*</abbr>' : '' );
		$html  = '<p class="lset-field lset-field--' . esc_attr( $f['type'] ) . '">';
		switch ( $f['type'] ) {
			case 'checkbox':
				$html .= '<label><input type="checkbox" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="1"' . checked( ! empty( $value ), true, false ) . $req . '> ' . $label . '</label>';
				break;
			case 'textarea':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label><textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" rows="3" placeholder="' . esc_attr( $f['placeholder'] ) . '"' . $req . '>' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'select':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label><select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"' . $req . '><option value="">' . esc_html__( 'Choose…', 'lumaha-simple-event-tickets' ) . '</option>';
				foreach ( $f['options'] as $opt ) {
					$html .= '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
				}
				$html .= '</select>';
				break;
			default:
				$type  = 'number' === $f['type'] ? 'number' : 'text';
				$extra = 'number' === $f['type'] ? ' inputmode="numeric" step="any"' : '';
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label . '</label><input type="' . $type . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $f['placeholder'] ) . '"' . $extra . $req . '>';
		}
		return $html . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Live availability
	 * ------------------------------------------------------------------ */

	public static function rest_routes() {
		register_rest_route(
			'lset/v1',
			'/availability/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_availability' ),
				'permission_callback' => '__return_true', // Public, read-only: the same facts the event page shows.
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $v ) {
							return is_numeric( $v );
						},
					),
				),
			)
		);
	}

	public static function rest_availability( WP_REST_Request $request ) {
		$e = LSET_Store::event( (int) $request['id'] );
		if ( ! $e || 'publish' !== $e['status'] ) {
			return new WP_Error( 'lset_not_found', __( 'Event not found.', 'lumaha-simple-event-tickets' ), array( 'status' => 404 ) );
		}
		$response = rest_ensure_response( self::availability( $e ) );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		return $response;
	}

	/**
	 * What the booking box needs to know right now.
	 *
	 * @param array $e         Event.
	 * @param array $exclude   Basket holds to leave out.
	 */
	public static function availability( array $e, array $exclude = array() ) {
		$tickets = LSET_Store::tickets( $e['id'], false );
		$left    = LSET_Capacity::left( $e['id'], $exclude );
		$out     = array();
		$any     = false;
		foreach ( $tickets as $t ) {
			$state = LSET_Store::sale_state( $t, $e );
			$l     = isset( $left[ $t['id'] ] ) ? $left[ $t['id'] ] : null;
			if ( '' === $state && null !== $l && $l < 1 ) {
				$state = 'sold_out';
			}
			$max  = LSET_Store::max_per_order( $t );
			$row  = array(
				'state'     => $state,
				'max'       => null === $l ? $max : min( $max, $l ),
				'min'       => 0,
				'left'      => null,
				'message'   => '',
			);
			if ( null !== $l && null !== $t['show_left_below'] && $l <= $t['show_left_below'] ) {
				$row['left'] = $l;
			}
			if ( 'not_yet' === $state ) {
				$starts = LSET_Store::sale_start( $t, $e );
				/* translators: %s: date and time */
				$row['message'] = sprintf( __( 'On sale from %s', 'lumaha-simple-event-tickets' ), LSET_Time::format( $starts, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e['tz'] ) );
			}
			if ( '' === $state ) {
				$any = true;
			}
			$out[ $t['id'] ] = $row;
		}
		return array(
			'event'   => $e['id'],
			'open'    => $any,
			'tickets' => (object) $out,
			'message' => $any ? '' : ( $e['closed_msg'] ? $e['closed_msg'] : __( 'Bookings for this event are closed or sold out.', 'lumaha-simple-event-tickets' ) ),
		);
	}
}
