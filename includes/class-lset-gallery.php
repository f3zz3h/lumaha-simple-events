<?php
/**
 * Optional photo gallery for an event, shown on the event page once the event has finished.
 *
 * Photos are picked from the media library and saved, in order, as attachment ids in
 * _lset_gallery. Who may see a gallery goes through can_view() (filter lset_gallery_can_view),
 * so a per-event password can be added there later without touching the rest.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Gallery {

	const META = '_lset_gallery';

	public static function init() {
		add_shortcode( 'lset_gallery', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'add_meta_boxes_' . LSET_CPT, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . LSET_CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/** The event's photos: attachment ids, in the order chosen, skipping any since deleted. */
	public static function ids( $event_id ) {
		$raw = (string) get_post_meta( $event_id, self::META, true );
		$ids = array();
		foreach ( explode( ',', $raw ) as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $ids, true ) && wp_attachment_is_image( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/** Save the photos from a list of ids (array or "1,2,3"). */
	public static function save_ids( $event_id, $ids ) {
		if ( ! is_array( $ids ) ) {
			$ids = explode( ',', (string) $ids );
		}
		$clean = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $clean, true ) && wp_attachment_is_image( $id ) ) {
				$clean[] = $id;
			}
		}
		if ( $clean ) {
			update_post_meta( $event_id, self::META, implode( ',', $clean ) );
		} else {
			delete_post_meta( $event_id, self::META );
		}
		return $clean;
	}

	public static function has_photos( $event_id ) {
		return (bool) self::ids( $event_id );
	}

	/** The gallery shows once the event has finished. */
	public static function is_visible( array $e ) {
		return LSET_Frontend::is_past( $e ) && self::has_photos( $e['id'] );
	}

	/**
	 * May the current visitor see this event's photos? Everyone, for now.
	 *
	 * Hook for a future per-event password: return false here and render() shows
	 * gate_html() (filter lset_gallery_gate) instead of the photos.
	 *
	 * @param array $e Event.
	 */
	public static function can_view( array $e ) {
		return (bool) apply_filters( 'lset_gallery_can_view', true, $e );
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	public static function register_assets() {
		wp_register_script( 'lset-gallery', LSET_URL . 'assets/lset-gallery.js', array(), LSET_VERSION, true );
		wp_localize_script(
			'lset-gallery',
			'lsetGalleryText',
			array(
				'close' => __( 'Close', 'lumaha-simple-event-tickets' ),
				'prev'  => __( 'Previous photo', 'lumaha-simple-event-tickets' ),
				'next'  => __( 'Next photo', 'lumaha-simple-event-tickets' ),
				/* translators: 1: photo number, 2: number of photos */
				'count' => __( 'Photo %1$d of %2$d', 'lumaha-simple-event-tickets' ),
			)
		);
	}

	/** Gallery HTML for the event page, or '' when there's nothing to show yet. */
	public static function for_event_page( array $e ) {
		return self::is_visible( $e ) ? self::render( $e ) : '';
	}

	/**
	 * [lset_gallery id="123"] for an Elementor page. Shows the photos once the event has
	 * finished; any="1" shows them straight away.
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'  => '0',
				'any' => '0',
			),
			$atts,
			'lset_gallery'
		);
		$e = LSET_Store::event( (int) $atts['id'] ? (int) $atts['id'] : get_the_ID() );
		if ( ! $e || 'publish' !== $e['status'] || ! self::has_photos( $e['id'] ) ) {
			return '';
		}
		if ( '1' !== (string) $atts['any'] && ! LSET_Frontend::is_past( $e ) ) {
			return '';
		}
		return self::render( $e );
	}

	public static function render( array $e ) {
		$ids = self::ids( $e['id'] );
		if ( ! $ids ) {
			return '';
		}
		wp_enqueue_style( 'lset' );
		$html  = '<section class="lset-gallery" id="lset-photos" aria-labelledby="lset-photos-' . (int) $e['id'] . '">';
		$html .= '<h2 class="lset-gallery__title" id="lset-photos-' . (int) $e['id'] . '">' . esc_html__( 'Photos', 'lumaha-simple-event-tickets' ) . '</h2>';
		if ( ! self::can_view( $e ) ) {
			return $html . self::gate_html( $e ) . '</section>';
		}
		wp_enqueue_script( 'lset-gallery' );
		$html .= '<ul class="lset-gallery__grid">';
		foreach ( $ids as $id ) {
			$full = wp_get_attachment_image_src( $id, 'large' );
			if ( ! $full ) {
				continue;
			}
			$alt     = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$caption = wp_get_attachment_caption( $id );
			$thumb   = wp_get_attachment_image(
				$id,
				'medium_large',
				false,
				array(
					'class'   => 'lset-gallery__img',
					'loading' => 'lazy',
					'alt'     => $alt ? $alt : ( $caption ? $caption : $e['title'] ),
				)
			);
			$html   .= '<li class="lset-gallery__item"><a class="lset-gallery__link" href="' . esc_url( $full[0] ) . '"';
			$html   .= $caption ? ' data-caption="' . esc_attr( $caption ) . '"' : '';
			$html   .= '>' . $thumb . '</a></li>';
		}
		$html .= '</ul></section>';
		return $html;
	}

	/** Shown instead of the photos when can_view() says no. */
	public static function gate_html( array $e ) {
		$default = '<p class="lset-gallery__gate">' . esc_html__( 'These photos are private.', 'lumaha-simple-event-tickets' ) . '</p>';
		return (string) apply_filters( 'lset_gallery_gate', $default, $e );
	}

	/* ---------------------------------------------------------------------
	 * Admin: the "Photo gallery" box
	 * ------------------------------------------------------------------ */

	public static function admin_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || LSET_CPT !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'lset-admin-gallery', LSET_URL . 'assets/lset-admin-gallery.js', array( 'jquery', 'jquery-ui-sortable' ), LSET_VERSION, true );
		wp_localize_script(
			'lset-admin-gallery',
			'lsetGalleryAdmin',
			array(
				'title'  => __( 'Event photos', 'lumaha-simple-event-tickets' ),
				'button' => __( 'Add to gallery', 'lumaha-simple-event-tickets' ),
				'remove' => __( 'Remove photo', 'lumaha-simple-event-tickets' ),
			)
		);
	}

	public static function meta_box() {
		add_meta_box( 'lset-gallery', __( 'Photo gallery', 'lumaha-simple-event-tickets' ), array( __CLASS__, 'box' ), LSET_CPT, 'normal', 'low' );
	}

	public static function box( $post ) {
		$ids = self::ids( $post->ID );
		wp_nonce_field( 'lset_gallery', 'lset_gallery_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Optional. Photos appear at the bottom of the event page once the event has finished. Drag to reorder.', 'lumaha-simple-event-tickets' ); ?></p>
		<input type="hidden" name="lset_gallery" class="lset-gallery-ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
		<ul class="lset-gallery-admin">
			<?php foreach ( $ids as $id ) : ?>
				<li data-id="<?php echo (int) $id; ?>">
					<?php echo wp_get_attachment_image( $id, 'thumbnail' ); ?>
					<button type="button" class="lset-gallery-remove" aria-label="<?php esc_attr_e( 'Remove photo', 'lumaha-simple-event-tickets' ); ?>">✕</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>
			<button type="button" class="button lset-gallery-add"><?php esc_html_e( 'Add photos', 'lumaha-simple-event-tickets' ); ?></button>
			<button type="button" class="button-link lset-gallery-clear"<?php echo $ids ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove all', 'lumaha-simple-event-tickets' ); ?></button>
		</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['lset_gallery_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lset_gallery_nonce'] ) ), 'lset_gallery' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::save_ids( $post_id, isset( $_POST['lset_gallery'] ) ? sanitize_text_field( wp_unslash( $_POST['lset_gallery'] ) ) : '' );
	}
}
