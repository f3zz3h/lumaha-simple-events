<?php
/**
 * Admin: event boxes (details, tickets, fields), the field library, settings, venues and
 * "Duplicate for next year".
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Admin {

	const NONCE = 'lset_save_event';

	public static function init() {
		add_action( 'add_meta_boxes_' . LSET_CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . LSET_CPT, array( __CLASS__, 'save_event' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
		add_action( 'admin_post_lset_save_field', array( __CLASS__, 'handle_save_field' ) );
		add_action( 'admin_post_lset_delete_field', array( __CLASS__, 'handle_delete_field' ) );
		add_action( 'admin_post_lset_duplicate', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_lset_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_lset_create_page', array( __CLASS__, 'handle_create_page' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'manage_' . LSET_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . LSET_CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . LSET_CPT . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_order' ) );
		add_action( LSET_VENUE . '_add_form_fields', array( __CLASS__, 'venue_add_fields' ) );
		add_action( LSET_VENUE . '_edit_form_fields', array( __CLASS__, 'venue_edit_fields' ) );
		add_action( 'created_' . LSET_VENUE, array( __CLASS__, 'venue_save' ) );
		add_action( 'edited_' . LSET_VENUE, array( __CLASS__, 'venue_save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 10, 2 );
	}

	public static function classic_editor( $use, $post_type ) {
		return LSET_CPT === $post_type ? false : $use;
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ( LSET_CPT !== $screen->post_type && false === strpos( (string) $hook, 'lset' ) ) ) {
			return;
		}
		wp_enqueue_style( 'lset-admin', LSET_URL . 'assets/lset-admin.css', array(), LSET_VERSION );
		wp_enqueue_script( 'lset-admin', LSET_URL . 'assets/lset-admin.js', array(), LSET_VERSION, true );
	}

	public static function menus() {
		$parent = 'edit.php?post_type=' . LSET_CPT;
		add_submenu_page( $parent, __( 'Checkout fields', 'lumaha-simple-event-tickets' ), __( 'Checkout fields', 'lumaha-simple-event-tickets' ), 'manage_woocommerce', 'lset-fields', array( __CLASS__, 'fields_page' ) );
		add_submenu_page( $parent, __( 'Event settings', 'lumaha-simple-event-tickets' ), __( 'Settings', 'lumaha-simple-event-tickets' ), 'manage_woocommerce', 'lset-settings', array( __CLASS__, 'settings_page' ) );
		add_submenu_page( '', __( 'Duplicate event', 'lumaha-simple-event-tickets' ), '', 'manage_woocommerce', 'lset-duplicate', array( __CLASS__, 'duplicate_page' ) );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( $screen && 'edit-' . LSET_CPT === $screen->id && ! self::listing_page( 'upcoming' ) && current_user_can( 'manage_woocommerce' ) ) {
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Events aren\'t listed on your site yet.', 'lumaha-simple-event-tickets' ),
				esc_url( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-settings#lset-pages' ) ),
				esc_html__( 'Set up the Events and Past events pages', 'lumaha-simple-event-tickets' )
			);
		}
		if ( empty( $_GET['lset_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message display only.
			return;
		}
		$messages = array(
			'field_saved'    => __( 'Field saved.', 'lumaha-simple-event-tickets' ),
			'field_deleted'  => __( 'Field deleted.', 'lumaha-simple-event-tickets' ),
			'settings_saved' => __( 'Settings saved.', 'lumaha-simple-event-tickets' ),
			'page_created'   => __( 'Page created and published. Add it to your menu under Appearance → Menus (or in Elementor) so people can find it.', 'lumaha-simple-event-tickets' ),
			'duplicated'     => __( 'Event copied as a draft. Check the dates and tickets, then publish it.', 'lumaha-simple-event-tickets' ),
		);
		$key = sanitize_key( wp_unslash( $_GET['lset_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $key ] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Event boxes
	 * ------------------------------------------------------------------ */

	public static function meta_boxes() {
		add_meta_box( 'lset-details', __( 'Event details', 'lumaha-simple-event-tickets' ), array( __CLASS__, 'box_details' ), LSET_CPT, 'normal', 'high' );
		add_meta_box( 'lset-tickets', __( 'Tickets', 'lumaha-simple-event-tickets' ), array( __CLASS__, 'box_tickets' ), LSET_CPT, 'normal', 'high' );
		add_meta_box( 'lset-fields', __( 'Checkout fields', 'lumaha-simple-event-tickets' ), array( __CLASS__, 'box_fields' ), LSET_CPT, 'normal', 'default' );
	}

	public static function box_details( $post ) {
		wp_nonce_field( self::NONCE, 'lset_nonce' );
		$e      = LSET_Store::event( $post );
		$is_new = 'auto-draft' === $post->post_status;
		$venues = get_terms(
			array(
				'taxonomy'   => LSET_VENUE,
				'hide_empty' => false,
			)
		);
		$venue  = $e['venue'] ? $e['venue']['id'] : 0;
		?>
		<div class="lset-grid">
			<p>
				<label for="lset_start_date"><?php esc_html_e( 'Starts', 'lumaha-simple-event-tickets' ); ?></label><br>
				<input type="date" id="lset_start_date" name="lset[start_date]" value="<?php echo esc_attr( substr( $e['start'], 0, 10 ) ); ?>" required>
				<input type="time" name="lset[start_time]" value="<?php echo esc_attr( substr( $e['start'], 11, 5 ) ); ?>" class="lset-time">
			</p>
			<p>
				<label for="lset_end_date"><?php esc_html_e( 'Ends', 'lumaha-simple-event-tickets' ); ?></label><br>
				<input type="date" id="lset_end_date" name="lset[end_date]" value="<?php echo esc_attr( substr( $e['end'], 0, 10 ) ); ?>">
				<input type="time" name="lset[end_time]" value="<?php echo esc_attr( substr( $e['end'], 11, 5 ) ); ?>" class="lset-time">
			</p>
			<p>
				<label><input type="checkbox" name="lset[all_day]" value="1" <?php checked( $e['all_day'] ); ?>> <?php esc_html_e( 'All day (hide times)', 'lumaha-simple-event-tickets' ); ?></label>
			</p>
			<p>
				<label for="lset_venue"><?php esc_html_e( 'Venue', 'lumaha-simple-event-tickets' ); ?></label><br>
				<select id="lset_venue" name="lset[venue]">
					<option value="0"><?php esc_html_e( '— None —', 'lumaha-simple-event-tickets' ); ?></option>
					<?php foreach ( is_array( $venues ) ? $venues : array() as $v ) : ?>
						<option value="<?php echo (int) $v->term_id; ?>" <?php selected( $venue, (int) $v->term_id ); ?>><?php echo esc_html( $v->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . LSET_VENUE . '&post_type=' . LSET_CPT ) ); ?>"><?php esc_html_e( 'Manage venues', 'lumaha-simple-event-tickets' ); ?></a>
			</p>
		</div>
		<p class="description">
			<?php
			/* translators: %s: timezone name, e.g. Europe/London */
			printf( esc_html__( 'Times are in the site timezone (%s), exactly as you type them.', 'lumaha-simple-event-tickets' ), esc_html( wp_timezone_string() ) );
			?>
		</p>
		<hr>
		<p>
			<label><input type="checkbox" name="lset[bookings]" value="1" <?php checked( $is_new || $e['bookings'] ); ?>> <strong><?php esc_html_e( 'Take bookings for this event', 'lumaha-simple-event-tickets' ); ?></strong></label><br>
			<span class="description"><?php esc_html_e( 'Untick for information-only events (fairs, AGM).', 'lumaha-simple-event-tickets' ); ?></span>
		</p>
		<div class="lset-sale" data-mode="<?php echo esc_attr( $e['sale_mode'] ); ?>">
			<label for="lset_sale_mode"><strong><?php esc_html_e( 'Tickets on sale', 'lumaha-simple-event-tickets' ); ?></strong></label><br>
			<select id="lset_sale_mode" name="lset[sale_mode]" class="lset-sale-mode">
				<option value="" <?php selected( $e['sale_mode'], '' ); ?>><?php esc_html_e( 'Now', 'lumaha-simple-event-tickets' ); ?></option>
				<option value="date" <?php selected( $e['sale_mode'], 'date' ); ?>><?php esc_html_e( 'From a date', 'lumaha-simple-event-tickets' ); ?></option>
				<option value="days" <?php selected( $e['sale_mode'], 'days' ); ?>><?php esc_html_e( 'A number of days before the event', 'lumaha-simple-event-tickets' ); ?></option>
			</select>
			<span class="lset-sale-date"><input type="datetime-local" name="lset[sale_start]" aria-label="<?php esc_attr_e( 'On sale from', 'lumaha-simple-event-tickets' ); ?>" value="<?php echo esc_attr( $e['sale_start'] ? substr( str_replace( ' ', 'T', $e['sale_start'] ), 0, 16 ) : '' ); ?>"></span>
			<span class="lset-sale-days"><input type="number" min="0" step="1" class="lset-w-tiny" name="lset[sale_days]" aria-label="<?php esc_attr_e( 'Days before the event', 'lumaha-simple-event-tickets' ); ?>" value="<?php echo esc_attr( null === $e['sale_days'] ? '' : $e['sale_days'] ); ?>"> <?php esc_html_e( 'days before (from midnight)', 'lumaha-simple-event-tickets' ); ?></span>
			<p>
				<label for="lset_sale_end"><?php esc_html_e( 'Sales close', 'lumaha-simple-event-tickets' ); ?></label><br>
				<input type="datetime-local" id="lset_sale_end" name="lset[sale_end]" value="<?php echo esc_attr( $e['sale_end'] ? substr( str_replace( ' ', 'T', $e['sale_end'] ), 0, 16 ) : '' ); ?>">
				<span class="description"><?php esc_html_e( 'Leave blank to close when the event starts. Applies to every ticket.', 'lumaha-simple-event-tickets' ); ?></span>
			</p>
		</div>
		<p>
			<label for="lset_closed_msg"><?php esc_html_e( 'Message when bookings are closed or sold out (optional)', 'lumaha-simple-event-tickets' ); ?></label><br>
			<input type="text" class="widefat" id="lset_closed_msg" name="lset[closed_msg]" value="<?php echo esc_attr( $e['closed_msg'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Just turn up on the day, no tickets needed.', 'lumaha-simple-event-tickets' ); ?>">
		</p>
		<p>
			<label for="lset_ticket_note"><?php esc_html_e( 'Printed on each ticket (optional)', 'lumaha-simple-event-tickets' ); ?></label><br>
			<textarea class="widefat" rows="2" id="lset_ticket_note" name="lset[ticket_note]" placeholder="<?php esc_attr_e( 'e.g. Doors open 6:30pm. Bring cash for the bar.', 'lumaha-simple-event-tickets' ); ?>"><?php echo esc_textarea( $e['ticket_note'] ); ?></textarea>
		</p>
		<?php
	}

	public static function box_tickets( $post ) {
		$event   = LSET_Store::event( $post );
		$tickets = LSET_Store::tickets( $post->ID );
		$groups  = LSET_Store::groups( $post->ID );
		$sold    = $post->ID ? LSET_Capacity::taken( $post->ID ) : array();
		?>
		<h4><?php esc_html_e( 'Ticket groups', 'lumaha-simple-event-tickets' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Optional. A group lets several tickets share one limit, e.g. "Volunteering" capped at 6 across all volunteer slots. Add groups here, then pick one on each ticket below. Tickets with "No group" just use their own capacity. Buyers don\'t see groups.', 'lumaha-simple-event-tickets' ); ?></p>
		<table class="widefat lset-groups">
			<thead><tr><th><?php esc_html_e( 'Group', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Shared capacity', 'lumaha-simple-event-tickets' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php
			foreach ( $groups as $g ) {
				self::group_row( $g );
			}
			?>
			</tbody>
		</table>
		<template id="lset-group-template"><?php self::group_row( null ); ?></template>
		<p><button type="button" class="button lset-add-group"><?php esc_html_e( 'Add group', 'lumaha-simple-event-tickets' ); ?></button></p>

		<h4><?php esc_html_e( 'Tickets', 'lumaha-simple-event-tickets' ); ?></h4>
		<p class="description"><?php esc_html_e( 'Leave capacity blank for no limit. The box under it is optional: e.g. 5 shows buyers "Only 5 left" once 5 or fewer remain. Price 0 means free (it still goes through the checkout as a £0 order). When tickets go on sale is set once, in Event details above. Drag the ⇅ handle to reorder.', 'lumaha-simple-event-tickets' ); ?></p>
		<table class="widefat lset-tickets">
			<thead>
				<tr>
					<th class="lset-col-sort"></th>
					<th><?php esc_html_e( 'Ticket', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Price', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Capacity', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Group', 'lumaha-simple-event-tickets' ); ?></th>
					<th><?php esc_html_e( 'Taken', 'lumaha-simple-event-tickets' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $tickets as $t ) {
					self::ticket_row( $t, $groups, isset( $sold[ $t['id'] ] ) ? $sold[ $t['id'] ] : 0 );
				}
				?>
			</tbody>
		</table>
		<template id="lset-ticket-template"><?php self::ticket_row( null, $groups, 0 ); ?></template>
		<p><button type="button" class="button lset-add-ticket"><?php esc_html_e( 'Add ticket', 'lumaha-simple-event-tickets' ); ?></button></p>
		<?php
		unset( $event );
	}

	/** One group row. $g null = the blank template row (key __g__, swapped for a new key by the script). */
	private static function group_row( $g ) {
		$key  = $g ? (string) $g['id'] : '__g__';
		$name = 'lset_groups[' . $key . ']';
		?>
		<tr class="lset-group" data-key="<?php echo esc_attr( $key ); ?>">
			<td><input type="text" class="lset-w-group lset-group-name" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $g ? $g['name'] : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Volunteering', 'lumaha-simple-event-tickets' ); ?>">
				<input type="hidden" class="lset-delete-flag" name="<?php echo esc_attr( $name ); ?>[delete]" value="0"></td>
			<td><input type="number" min="0" step="1" class="lset-w-num" name="<?php echo esc_attr( $name ); ?>[capacity]" value="<?php echo esc_attr( $g && null !== $g['capacity'] ? $g['capacity'] : '' ); ?>" placeholder="<?php esc_attr_e( 'no limit', 'lumaha-simple-event-tickets' ); ?>"></td>
			<td><button type="button" class="button-link lset-remove" aria-label="<?php esc_attr_e( 'Remove group', 'lumaha-simple-event-tickets' ); ?>">✕</button></td>
		</tr>
		<?php
	}

	/**
	 * One ticket row. $t null = the blank template row (its inputs use __i__ as the index).
	 */
	private static function ticket_row( $t, array $groups, $sold ) {
		$i     = $t ? 't' . $t['id'] : '__i__';
		$name  = 'lset_tickets[' . $i . ']';
		$t     = $t ? $t : array(
			'id'              => 0,
			'name'            => '',
			'description'     => '',
			'price'           => '',
			'capacity'        => null,
			'group_id'        => 0,
			'hidden'          => false,
			'show_left_below' => null,
		);
		$fmt   = function ( $v ) {
			return '' === $v || null === $v ? '' : (string) $v;
		};
		?>
		<tr class="lset-ticket">
			<td class="lset-col-sort"><span class="lset-handle" title="<?php esc_attr_e( 'Drag to reorder', 'lumaha-simple-event-tickets' ); ?>">⇅</span>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo (int) $t['id']; ?>">
				<input type="hidden" class="lset-delete-flag" name="<?php echo esc_attr( $name ); ?>[delete]" value="0">
			</td>
			<td>
				<input type="text" class="lset-w-name" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $t['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Years 3-5', 'lumaha-simple-event-tickets' ); ?>">
				<textarea rows="1" class="lset-w-name" name="<?php echo esc_attr( $name ); ?>[description]" placeholder="<?php esc_attr_e( 'Description (optional)', 'lumaha-simple-event-tickets' ); ?>"><?php echo esc_textarea( $t['description'] ); ?></textarea>
				<label class="lset-small"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hidden]" value="1" <?php checked( $t['hidden'] ); ?>> <?php esc_html_e( 'Hidden', 'lumaha-simple-event-tickets' ); ?></label>
			</td>
			<td><input type="number" min="0" step="0.01" class="lset-w-num" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( '' === $t['price'] ? '' : wc_format_decimal( $t['price'], 2 ) ); ?>" placeholder="0.00"></td>
			<td>
				<input type="number" min="0" step="1" class="lset-w-num" name="<?php echo esc_attr( $name ); ?>[capacity]" value="<?php echo esc_attr( $fmt( $t['capacity'] ) ); ?>" placeholder="<?php esc_attr_e( 'no limit', 'lumaha-simple-event-tickets' ); ?>">
				<label class="lset-small lset-left-at" title="<?php esc_attr_e( 'Optional. Once this many or fewer are left, buyers see "Only 3 left" next to the ticket. Leave blank to never show it.', 'lumaha-simple-event-tickets' ); ?>"><?php esc_html_e( 'Say "only N left" at', 'lumaha-simple-event-tickets' ); ?> <input type="number" min="0" step="1" class="lset-w-tiny" name="<?php echo esc_attr( $name ); ?>[show_left_below]" value="<?php echo esc_attr( $fmt( $t['show_left_below'] ) ); ?>" placeholder="<?php esc_attr_e( 'off', 'lumaha-simple-event-tickets' ); ?>"></label>
			</td>
			<td>
				<select class="lset-group-pick" name="<?php echo esc_attr( $name ); ?>[group]">
					<option value=""><?php esc_html_e( 'No group', 'lumaha-simple-event-tickets' ); ?></option>
					<?php foreach ( $groups as $g ) : ?>
						<option value="<?php echo (int) $g['id']; ?>" <?php selected( $t['group_id'], $g['id'] ); ?>><?php echo esc_html( $g['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><?php echo (int) $sold; ?></td>
			<td><button type="button" class="button-link lset-remove" aria-label="<?php esc_attr_e( 'Remove ticket', 'lumaha-simple-event-tickets' ); ?>">✕</button></td>
		</tr>
		<?php
	}

	public static function box_fields( $post ) {
		$library = LSET_Store::fields();
		$current = array();
		foreach ( array( 'attendee', 'booking' ) as $level ) {
			$attached = json_decode( (string) get_post_meta( $post->ID, '_lset_' . $level . '_fields', true ), true );
			foreach ( is_array( $attached ) ? $attached : array() as $pos => $a ) {
				$current[ (int) $a['field_id'] ] = array(
					'level'    => $level,
					'required' => ! empty( $a['required'] ),
					'order'    => $pos + 1,
				);
			}
		}
		$names = LSET_Store::name_fields_mode( $post->ID );
		?>
		<p>
			<label for="lset_name_fields"><strong><?php esc_html_e( 'First and last name for each ticket', 'lumaha-simple-event-tickets' ); ?></strong></label>
			<select id="lset_name_fields" name="lset[name_fields]">
				<option value="required" <?php selected( $names, 'required' ); ?>><?php esc_html_e( 'Required', 'lumaha-simple-event-tickets' ); ?></option>
				<option value="optional" <?php selected( $names, 'optional' ); ?>><?php esc_html_e( 'Optional', 'lumaha-simple-event-tickets' ); ?></option>
				<option value="off" <?php selected( $names, 'off' ); ?>><?php esc_html_e( 'Don\'t ask', 'lumaha-simple-event-tickets' ); ?></option>
			</select>
		</p>
		<?php if ( ! $library ) : ?>
			<p><?php esc_html_e( 'No checkout fields yet.', 'lumaha-simple-event-tickets' ); ?> <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-fields' ) ); ?>"><?php esc_html_e( 'Add some', 'lumaha-simple-event-tickets' ); ?></a></p>
			<?php
			return;
		endif;
		?>
		<p class="description"><?php esc_html_e( '"Each ticket" asks once per ticket bought (e.g. child\'s class, dietary needs). "Once per booking" asks the buyer once (e.g. contact phone).', 'lumaha-simple-event-tickets' ); ?></p>
		<table class="widefat lset-fields-pick">
			<thead><tr><th><?php esc_html_e( 'Field', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Ask', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Required', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Order', 'lumaha-simple-event-tickets' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $library as $f ) : ?>
				<?php $c = isset( $current[ $f['id'] ] ) ? $current[ $f['id'] ] : array( 'level' => '', 'required' => false, 'order' => '' ); ?>
				<tr>
					<td><?php echo esc_html( $f['label'] ); ?> <span class="lset-small">(<?php echo esc_html( $f['type'] ); ?>)</span></td>
					<td>
						<select name="lset_fields[<?php echo (int) $f['id']; ?>][level]">
							<option value="" <?php selected( $c['level'], '' ); ?>><?php esc_html_e( 'Not used', 'lumaha-simple-event-tickets' ); ?></option>
							<option value="attendee" <?php selected( $c['level'], 'attendee' ); ?>><?php esc_html_e( 'Each ticket', 'lumaha-simple-event-tickets' ); ?></option>
							<option value="booking" <?php selected( $c['level'], 'booking' ); ?>><?php esc_html_e( 'Once per booking', 'lumaha-simple-event-tickets' ); ?></option>
						</select>
					</td>
					<td><input type="checkbox" name="lset_fields[<?php echo (int) $f['id']; ?>][required]" value="1" <?php checked( $c['required'] ); ?>></td>
					<td><input type="number" class="lset-w-tiny" name="lset_fields[<?php echo (int) $f['id']; ?>][order]" value="<?php echo esc_attr( $c['order'] ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function save_event( $post_id, $post ) {
		if ( ! isset( $_POST['lset_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lset_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['lset'] ) && is_array( $_POST['lset'] ) ? wp_unslash( $_POST['lset'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised below.

		$start = LSET_Time::from_parts( isset( $in['start_date'] ) ? $in['start_date'] : '', isset( $in['start_time'] ) ? $in['start_time'] : '' );
		$end   = LSET_Time::from_parts( isset( $in['end_date'] ) && $in['end_date'] ? $in['end_date'] : ( isset( $in['start_date'] ) ? $in['start_date'] : '' ), isset( $in['end_time'] ) ? $in['end_time'] : '' );
		LSET_Store::save_dates( $post_id, $start, $end, ! empty( $in['all_day'] ) );

		update_post_meta( $post_id, '_lset_bookings', empty( $in['bookings'] ) ? 0 : 1 );
		LSET_Store::save_sale_window(
			$post_id,
			isset( $in['sale_mode'] ) ? sanitize_key( $in['sale_mode'] ) : '',
			isset( $in['sale_start'] ) ? self::datetime_local( $in['sale_start'] ) : '',
			isset( $in['sale_days'] ) ? sanitize_text_field( $in['sale_days'] ) : '',
			isset( $in['sale_end'] ) ? self::datetime_local( $in['sale_end'] ) : ''
		);
		update_post_meta( $post_id, '_lset_closed_msg', isset( $in['closed_msg'] ) ? sanitize_text_field( $in['closed_msg'] ) : '' );
		update_post_meta( $post_id, '_lset_ticket_note', isset( $in['ticket_note'] ) ? sanitize_textarea_field( $in['ticket_note'] ) : '' );
		$names = isset( $in['name_fields'] ) ? sanitize_key( $in['name_fields'] ) : 'required';
		update_post_meta( $post_id, '_lset_name_fields', in_array( $names, array( 'required', 'optional', 'off' ), true ) ? $names : 'required' );

		$venue = isset( $in['venue'] ) ? absint( $in['venue'] ) : 0;
		wp_set_object_terms( $post_id, $venue ? array( $venue ) : array(), LSET_VENUE );

		// Fields.
		$picked = isset( $_POST['lset_fields'] ) && is_array( $_POST['lset_fields'] ) ? wp_unslash( $_POST['lset_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$levels = array(
			'attendee' => array(),
			'booking'  => array(),
		);
		foreach ( $picked as $fid => $p ) {
			$level = isset( $p['level'] ) ? sanitize_key( $p['level'] ) : '';
			if ( isset( $levels[ $level ] ) ) {
				$levels[ $level ][] = array(
					'field_id' => absint( $fid ),
					'required' => ! empty( $p['required'] ),
					'order'    => isset( $p['order'] ) && '' !== $p['order'] ? (int) $p['order'] : 999,
				);
			}
		}
		foreach ( $levels as $level => $list ) {
			usort(
				$list,
				function ( $a, $b ) {
					return $a['order'] - $b['order'];
				}
			);
			LSET_Store::set_event_fields( $post_id, $level, $list );
		}

		// Groups first, so tickets can point at new ones. Keys are group ids, or "n1", "n2"… for new rows.
		$existing_groups = LSET_Store::groups( $post_id );
		$group_in        = isset( $_POST['lset_groups'] ) && is_array( $_POST['lset_groups'] ) ? wp_unslash( $_POST['lset_groups'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$group_map       = array(); // Form key => group id (0 = deleted or blank).
		$g_order         = 0;
		foreach ( $group_in as $key => $g ) {
			$key = (string) $key;
			if ( '__g__' === $key ) {
				continue;
			}
			$gid  = ctype_digit( $key ) ? (int) $key : 0;
			$name = isset( $g['name'] ) ? trim( sanitize_text_field( $g['name'] ) ) : '';
			if ( $gid && ! isset( $existing_groups[ $gid ] ) ) {
				continue; // Not this event's group.
			}
			if ( ! empty( $g['delete'] ) || '' === $name ) {
				if ( $gid && ! empty( $g['delete'] ) ) {
					LSET_Store::delete_group( $gid );
				}
				$group_map[ $key ] = $gid && empty( $g['delete'] ) ? $gid : 0;
				continue;
			}
			$group_map[ $key ] = LSET_Store::save_group(
				array(
					'id'         => $gid,
					'event_id'   => $post_id,
					'name'       => $name,
					'capacity'   => isset( $g['capacity'] ) ? $g['capacity'] : '',
					'sort_order' => $g_order++,
					'ep_id'      => $gid ? $existing_groups[ $gid ]['ep_id'] : null,
				)
			);
		}

		// Tickets, in the order they appear on the form.
		$tickets_in = isset( $_POST['lset_tickets'] ) && is_array( $_POST['lset_tickets'] ) ? wp_unslash( $_POST['lset_tickets'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$existing   = LSET_Store::tickets( $post_id );
		$order      = 0;
		foreach ( $tickets_in as $key => $t ) {
			if ( '__i__' === $key ) {
				continue;
			}
			$id = isset( $t['id'] ) ? absint( $t['id'] ) : 0;
			if ( $id && ! isset( $existing[ $id ] ) ) {
				continue; // Not this event's ticket.
			}
			if ( ! empty( $t['delete'] ) ) {
				if ( $id ) {
					LSET_Store::delete_ticket( $id );
				}
				continue;
			}
			$name = isset( $t['name'] ) ? trim( sanitize_text_field( $t['name'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}
			$pick = isset( $t['group'] ) ? sanitize_key( $t['group'] ) : '';
			$gid  = isset( $group_map[ $pick ] ) ? $group_map[ $pick ] : 0;
			$current = $id ? $existing[ $id ] : array();
			LSET_Store::save_ticket(
				array(
					'id'              => $id,
					'event_id'        => $post_id,
					'group_id'        => $gid,
					'name'            => $name,
					'description'     => isset( $t['description'] ) ? $t['description'] : '',
					'price'           => isset( $t['price'] ) ? wc_format_decimal( $t['price'] ) : 0,
					'capacity'        => isset( $t['capacity'] ) ? $t['capacity'] : '',
					'ep_id'           => isset( $current['ep_id'] ) ? $current['ep_id'] : null,
					'sort_order'      => $order++,
					'hidden'          => ! empty( $t['hidden'] ),
					'show_left_below' => isset( $t['show_left_below'] ) ? $t['show_left_below'] : '',
				)
			);
		}

		unset( $post );
	}

	/** "2026-12-05T18:00" from a datetime-local input → "2026-12-05 18:00:00", or ''. */
	private static function datetime_local( $v ) {
		$v = sanitize_text_field( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$dt = LSET_Time::local( str_replace( 'T', ' ', $v ) );
		return $dt ? $dt->format( LSET_Time::FORMAT ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Events list
	 * ------------------------------------------------------------------ */

	public static function columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				continue;
			}
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['lset_start'] = __( 'Starts', 'lumaha-simple-event-tickets' );
				$new['lset_sold']  = __( 'Taken / capacity', 'lumaha-simple-event-tickets' );
			}
		}
		return $new;
	}

	public static function column( $col, $post_id ) {
		if ( 'lset_start' === $col ) {
			$e = LSET_Store::event( $post_id );
			echo esc_html( $e['start'] ? LSET_Time::format( $e['start'], $e['all_day'] ? get_option( 'date_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e['tz'] ) : '—' );
		} elseif ( 'lset_sold' === $col ) {
			if ( ! get_post_meta( $post_id, '_lset_bookings', true ) ) {
				esc_html_e( 'Info only', 'lumaha-simple-event-tickets' );
				return;
			}
			$tickets = LSET_Store::tickets( $post_id );
			$taken   = array_sum( LSET_Capacity::taken( $post_id ) );
			$cap     = 0;
			$open    = false;
			foreach ( $tickets as $t ) {
				if ( null === $t['capacity'] ) {
					$open = true;
				} else {
					$cap += $t['capacity'];
				}
			}
			echo esc_html( $taken . ' / ' . ( $open || ! $tickets ? '∞' : $cap ) );
			if ( current_user_can( LSET_CAP_ATTENDEES ) ) {
				printf( '<br><a href="%s">%s</a>', esc_url( LSET_Attendees::url( $post_id ) ), esc_html__( 'Attendees', 'lumaha-simple-event-tickets' ) );
			}
		}
	}

	public static function sortable( $cols ) {
		$cols['lset_start'] = 'lset_start';
		return $cols;
	}

	public static function admin_order( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || LSET_CPT !== $q->get( 'post_type' ) ) {
			return;
		}
		$orderby = $q->get( 'orderby' );
		if ( ! $orderby || 'lset_start' === $orderby ) {
			$q->set( 'meta_key', '_lset_start_utc' );
			$q->set( 'orderby', 'meta_value_num' );
			if ( ! $q->get( 'order' ) ) {
				$q->set( 'order', 'DESC' );
			}
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( LSET_CPT !== $post->post_type || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}
		$actions['lset_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=lset-duplicate&event=' . $post->ID ) ),
			esc_html__( 'Duplicate for next year', 'lumaha-simple-event-tickets' )
		);
		if ( current_user_can( LSET_CAP_ATTENDEES ) && get_post_meta( $post->ID, '_lset_bookings', true ) ) {
			$actions['lset_attendees'] = sprintf( '<a href="%s">%s</a>', esc_url( LSET_Attendees::url( $post->ID ) ), esc_html__( 'Attendees', 'lumaha-simple-event-tickets' ) );
		}
		return $actions;
	}

	/* ---------------------------------------------------------------------
	 * Duplicate for next year
	 * ------------------------------------------------------------------ */

	public static function duplicate_page() {
		$id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- form display only.
		$e  = $id ? LSET_Store::event( $id ) : null;
		if ( ! $e ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Event not found.', 'lumaha-simple-event-tickets' ) . '</p></div>';
			return;
		}
		$fmt  = get_option( 'date_format' ) . ( $e['all_day'] ? '' : ' ' . get_option( 'time_format' ) );
		$opts = array(
			'+1 year'  => __( 'Same date next year', 'lumaha-simple-event-tickets' ),
			'+52 weeks' => __( 'Same weekday next year (52 weeks later)', 'lumaha-simple-event-tickets' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( /* translators: %s: event title */ __( 'Duplicate "%s"', 'lumaha-simple-event-tickets' ), $e['title'] ) ); ?></h1>
			<p><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'This event starts %s. The copy gets the same tickets, groups and fields, with every date moved, and is saved as a draft with no bookings.', 'lumaha-simple-event-tickets' ), LSET_Time::format( $e['start'], $fmt, $e['tz'] ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lset_duplicate">
				<input type="hidden" name="event" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'lset_duplicate_' . $id ); ?>
				<?php foreach ( $opts as $mod => $label ) : ?>
					<p><label><input type="radio" name="shift" value="<?php echo esc_attr( $mod ); ?>" <?php checked( '+52 weeks', $mod ); ?>> <?php echo esc_html( $label ); ?> — <?php echo esc_html( LSET_Time::format( LSET_Time::shift( $e['start'], $mod, $e['tz'] ), $fmt, $e['tz'] ) ); ?></label></p>
				<?php endforeach; ?>
				<p><label><input type="radio" name="shift" value="days"> <?php esc_html_e( 'Move by', 'lumaha-simple-event-tickets' ); ?> <input type="number" name="days" class="small-text" value="364"> <?php esc_html_e( 'days', 'lumaha-simple-event-tickets' ); ?></label></p>
				<?php submit_button( __( 'Make the copy', 'lumaha-simple-event-tickets' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_duplicate() {
		$id = isset( $_POST['event'] ) ? absint( $_POST['event'] ) : 0;
		check_admin_referer( 'lset_duplicate_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lumaha-simple-event-tickets' ) );
		}
		$shift = isset( $_POST['shift'] ) ? sanitize_text_field( wp_unslash( $_POST['shift'] ) ) : '+52 weeks';
		if ( 'days' === $shift ) {
			$days  = isset( $_POST['days'] ) ? (int) $_POST['days'] : 364;
			$shift = ( $days >= 0 ? '+' : '' ) . $days . ' days';
		} elseif ( ! in_array( $shift, array( '+1 year', '+52 weeks' ), true ) ) {
			$shift = '+52 weeks';
		}
		$new = LSET_Events::duplicate( $id, $shift );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $new . '&action=edit&lset_msg=duplicated' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Field library
	 * ------------------------------------------------------------------ */

	public static function fields_page() {
		$fields = LSET_Store::fields();
		$edit   = isset( $_GET['edit'] ) ? LSET_Store::field( absint( $_GET['edit'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- form display only.
		$f      = $edit ? $edit : array(
			'id'          => 0,
			'label'       => '',
			'type'        => 'text',
			'options'     => array(),
			'placeholder' => '',
			'sort_order'  => 0,
		);
		$types  = array(
			'text'     => __( 'Text', 'lumaha-simple-event-tickets' ),
			'number'   => __( 'Number', 'lumaha-simple-event-tickets' ),
			'textarea' => __( 'Long text', 'lumaha-simple-event-tickets' ),
			'select'   => __( 'Drop-down', 'lumaha-simple-event-tickets' ),
			'checkbox' => __( 'Tick box', 'lumaha-simple-event-tickets' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Checkout fields', 'lumaha-simple-event-tickets' ); ?></h1>
			<p><?php esc_html_e( 'Fields you can ask for on any event. Choose which ones each event uses, and whether they\'re required, in the event\'s "Checkout fields" box.', 'lumaha-simple-event-tickets' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Label', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Type', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Options', 'lumaha-simple-event-tickets' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $fields ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No fields yet.', 'lumaha-simple-event-tickets' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $fields as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><?php echo esc_html( isset( $types[ $row['type'] ] ) ? $types[ $row['type'] ] : $row['type'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', $row['options'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-fields&edit=' . $row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'lumaha-simple-event-tickets' ); ?></a> |
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lset_delete_field&id=' . $row['id'] ), 'lset_delete_field_' . $row['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this field? Events using it will stop asking for it. Answers already given are kept.', 'lumaha-simple-event-tickets' ) ); ?>');"><?php esc_html_e( 'Delete', 'lumaha-simple-event-tickets' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo $edit ? esc_html__( 'Edit field', 'lumaha-simple-event-tickets' ) : esc_html__( 'Add a field', 'lumaha-simple-event-tickets' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lset_save_field">
				<input type="hidden" name="id" value="<?php echo (int) $f['id']; ?>">
				<?php wp_nonce_field( 'lset_save_field' ); ?>
				<table class="form-table">
					<tr><th><label for="lset_f_label"><?php esc_html_e( 'Label', 'lumaha-simple-event-tickets' ); ?></label></th><td><input type="text" id="lset_f_label" name="label" class="regular-text" value="<?php echo esc_attr( $f['label'] ); ?>" required></td></tr>
					<tr><th><label for="lset_f_type"><?php esc_html_e( 'Type', 'lumaha-simple-event-tickets' ); ?></label></th><td><select id="lset_f_type" name="type">
						<?php foreach ( $types as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['type'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
					<tr><th><label for="lset_f_options"><?php esc_html_e( 'Options', 'lumaha-simple-event-tickets' ); ?></label></th><td><textarea id="lset_f_options" name="options" rows="4" class="regular-text"><?php echo esc_textarea( implode( "\n", $f['options'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'For drop-downs: one option per line.', 'lumaha-simple-event-tickets' ); ?></p></td></tr>
					<tr><th><label for="lset_f_ph"><?php esc_html_e( 'Placeholder', 'lumaha-simple-event-tickets' ); ?></label></th><td><input type="text" id="lset_f_ph" name="placeholder" class="regular-text" value="<?php echo esc_attr( $f['placeholder'] ); ?>"></td></tr>
					<tr><th><label for="lset_f_order"><?php esc_html_e( 'List order', 'lumaha-simple-event-tickets' ); ?></label></th><td><input type="number" id="lset_f_order" name="sort_order" class="small-text" value="<?php echo (int) $f['sort_order']; ?>"></td></tr>
				</table>
				<?php submit_button( $edit ? __( 'Save field', 'lumaha-simple-event-tickets' ) : __( 'Add field', 'lumaha-simple-event-tickets' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save_field() {
		check_admin_referer( 'lset_save_field' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lumaha-simple-event-tickets' ) );
		}
		$options = isset( $_POST['options'] ) ? explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['options'] ) ) ) : array();
		LSET_Store::save_field(
			array(
				'id'          => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'label'       => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'type'        => isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : 'text',
				'options'     => $options,
				'placeholder' => isset( $_POST['placeholder'] ) ? sanitize_text_field( wp_unslash( $_POST['placeholder'] ) ) : '',
				'sort_order'  => isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0,
			)
		);
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-fields&lset_msg=field_saved' ) );
		exit;
	}

	public static function handle_delete_field() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'lset_delete_field_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lumaha-simple-event-tickets' ) );
		}
		LSET_Store::delete_field( $id );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-fields&lset_msg=field_deleted' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function settings_page() {
		$s = Lumaha_Simple_Event_Tickets::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Event settings', 'lumaha-simple-event-tickets' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lset_save_settings">
				<?php wp_nonce_field( 'lset_save_settings' ); ?>
				<table class="form-table">
					<tr><th><label for="lset_hold"><?php esc_html_e( 'Hold tickets in a basket for', 'lumaha-simple-event-tickets' ); ?></label></th><td><input type="number" min="1" max="120" id="lset_hold" name="hold_minutes" class="small-text" value="<?php echo (int) $s['hold_minutes']; ?>"> <?php esc_html_e( 'minutes', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Complete ticket orders', 'lumaha-simple-event-tickets' ); ?></th><td><label><input type="checkbox" name="auto_complete" value="1" <?php checked( $s['auto_complete'] ); ?>> <?php esc_html_e( 'Mark paid (and free) orders that only hold event tickets as Completed straight away', 'lumaha-simple-event-tickets' ); ?></label></td></tr>
					<tr><th><label for="lset_slug"><?php esc_html_e( 'Event address', 'lumaha-simple-event-tickets' ); ?></label></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" id="lset_slug" name="event_slug" class="regular-text" value="<?php echo esc_attr( $s['event_slug'] ); ?>"><code>/&lt;event-name&gt;/</code></td></tr>
					<tr><th><?php esc_html_e( 'Event page sidebar', 'lumaha-simple-event-tickets' ); ?></th><td><label><input type="checkbox" name="event_sidebar" value="1" <?php checked( ! empty( $s['event_sidebar'] ) ); ?>> <?php esc_html_e( 'Show the theme\'s sidebar on event pages (off: event pages are full width)', 'lumaha-simple-event-tickets' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'When the plugin is deleted', 'lumaha-simple-event-tickets' ); ?></th><td><label><input type="checkbox" name="delete_on_remove" value="1" <?php checked( $s['delete_on_remove'] ); ?>> <?php esc_html_e( 'Delete all events, tickets, bookings and attendee details. Orders are never touched.', 'lumaha-simple-event-tickets' ); ?></label></td></tr>
				</table>

				<h2 id="lset-tickets"><?php esc_html_e( 'Tickets', 'lumaha-simple-event-tickets' ); ?></h2>
				<p><?php esc_html_e( 'Each person booked gets their own ticket with a QR code. Scanning it with a phone camera opens the ticket; staff who are logged in can check it in there.', 'lumaha-simple-event-tickets' ); ?></p>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Ticket email', 'lumaha-simple-event-tickets' ); ?></th><td><label><input type="checkbox" name="ticket_emails" value="1" <?php checked( $s['ticket_emails'] ); ?>> <?php esc_html_e( 'Email tickets to the buyer when an order is paid (or free)', 'lumaha-simple-event-tickets' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the WooCommerce email settings */
								esc_html__( 'Change the subject, heading and closing text in %s. You can always resend from an order: Order actions → Email event tickets to the customer.', 'lumaha-simple-event-tickets' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=lset_email_tickets' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Emails → Event tickets', 'lumaha-simple-event-tickets' ) . '</a>'
							);
							?>
						</p></td></tr>
					<tr><th><label for="lset_ticket_colour"><?php esc_html_e( 'Ticket colour', 'lumaha-simple-event-tickets' ); ?></label></th><td><input type="color" id="lset_ticket_colour" name="ticket_colour" value="<?php echo esc_attr( LSET_Tickets::accent() ); ?>"></td></tr>
					<tr><th><label for="lset_ticket_logo"><?php esc_html_e( 'Logo on tickets', 'lumaha-simple-event-tickets' ); ?></label></th><td><input type="url" class="regular-text" id="lset_ticket_logo" name="ticket_logo" value="<?php echo esc_attr( $s['ticket_logo'] ); ?>" placeholder="https://"><p class="description"><?php esc_html_e( 'The address of an image from the Media Library. Leave blank to use the site logo.', 'lumaha-simple-event-tickets' ); ?></p></td></tr>
					<tr><th><label for="lset_ticket_note_all"><?php esc_html_e( 'Printed on every ticket', 'lumaha-simple-event-tickets' ); ?></label></th><td><textarea class="large-text" rows="3" id="lset_ticket_note_all" name="ticket_note" placeholder="<?php esc_attr_e( 'e.g. Children must be collected by an adult. Questions? events@example.org', 'lumaha-simple-event-tickets' ); ?>"><?php echo esc_textarea( $s['ticket_note'] ); ?></textarea><p class="description"><?php esc_html_e( 'Each event can add its own line too, under Event details.', 'lumaha-simple-event-tickets' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Door check-in', 'lumaha-simple-event-tickets' ); ?></th><td><a href="<?php echo esc_url( LSET_Tickets::door_url() ); ?>"><?php echo esc_html( LSET_Tickets::door_url() ); ?></a><p class="description"><?php esc_html_e( 'For typing in a code when a QR code won\'t scan. Admins and shop managers can check people in; give others the lset_check_in capability (e.g. with the Members plugin).', 'lumaha-simple-event-tickets' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2 id="lset-pages"><?php esc_html_e( 'Event pages', 'lumaha-simple-event-tickets' ); ?></h2>
			<p><?php esc_html_e( 'Lists of events for your site. Create a page here, or put the shortcode on any page (an Elementor Shortcode widget works too).', 'lumaha-simple-event-tickets' ); ?></p>
			<table class="widefat striped lset-pages">
				<thead><tr><th><?php esc_html_e( 'List', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Shortcode', 'lumaha-simple-event-tickets' ); ?></th><th><?php esc_html_e( 'Page', 'lumaha-simple-event-tickets' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::listing_pages() as $key => $def ) : ?>
					<?php $page = self::listing_page( $key ); ?>
					<tr>
						<td><?php echo esc_html( $def['label'] ); ?></td>
						<td><code><?php echo esc_html( $def['shortcode'] ); ?></code></td>
						<td>
							<?php if ( $page ) : ?>
								<a href="<?php echo esc_url( get_permalink( $page ) ); ?>"><?php echo esc_html( get_the_title( $page ) ); ?></a>
								(<a href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>"><?php esc_html_e( 'edit', 'lumaha-simple-event-tickets' ); ?></a>)
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="lset_create_page">
									<input type="hidden" name="list" value="<?php echo esc_attr( $key ); ?>">
									<?php wp_nonce_field( 'lset_create_page' ); ?>
									<?php /* translators: %s: page title */ submit_button( sprintf( __( 'Create "%s" page', 'lumaha-simple-event-tickets' ), $def['title'] ), 'secondary small', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<h3><?php esc_html_e( 'Display options', 'lumaha-simple-event-tickets' ); ?></h3>
			<p><?php esc_html_e( 'Add any of these inside the shortcode, e.g.', 'lumaha-simple-event-tickets' ); ?> <code>[lset_events layout="list" limit="4" price="0"]</code></p>
			<table class="widefat striped lset-pages">
				<tbody>
					<tr><td><code>layout="grid"</code> / <code>"list"</code> / <code>"compact"</code></td><td><?php esc_html_e( 'Cards in a grid (default); one wide row per event with the picture at the side; or a simple list of dates and titles.', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><td><code>columns="2"</code></td><td><?php esc_html_e( 'Grid only: up to 1–4 cards per row on a wide screen (default 3).', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><td><code>limit="6"</code></td><td><?php esc_html_e( 'Show only this many events.', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><td><code>order="asc"</code> / <code>"desc"</code></td><td><?php esc_html_e( 'Soonest or newest first. Upcoming lists default to soonest first, past lists to newest first.', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><td><code>image="0"</code> <code>excerpt="0"</code> <code>venue="0"</code> <code>price="0"</code> <code>button="0"</code></td><td><?php esc_html_e( 'Hide the picture, the short description, the venue, the "from" price or the Book / More info button.', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><td><code>where="School Hall"</code></td><td><?php esc_html_e( 'Only events at this venue.', 'lumaha-simple-event-tickets' ); ?></td></tr>
					<tr><td><code>year="2025"</code></td><td><?php esc_html_e( 'Only events in that year (handy for a past events archive).', 'lumaha-simple-event-tickets' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** The two listing pages this screen can make. */
	private static function listing_pages() {
		return array(
			'upcoming' => array(
				'label'     => __( 'Upcoming events', 'lumaha-simple-event-tickets' ),
				'title'     => __( 'Events', 'lumaha-simple-event-tickets' ),
				'shortcode' => '[lset_events]',
			),
			'past'     => array(
				'label'     => __( 'Past events', 'lumaha-simple-event-tickets' ),
				'title'     => __( 'Past events', 'lumaha-simple-event-tickets' ),
				'shortcode' => '[lset_events past="1"]',
			),
		);
	}

	/** The published page showing a list: the one we made, else any page using its shortcode. Returns its id or 0. */
	public static function listing_page( $key ) {
		$id = (int) get_option( 'lset_page_' . $key );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return $id;
		}
		global $wpdb;
		$pages = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID", '%[lset_events%' ) );
		foreach ( $pages as $p ) {
			if ( preg_match_all( '/\[lset_events([^\]]*)\]/', $p->post_content, $m ) ) {
				foreach ( $m[1] as $attrs ) {
					$is_past = (bool) preg_match( '/(past|show_past)\s*=\s*["\']?1/', $attrs );
					if ( ( 'past' === $key ) === $is_past ) {
						return (int) $p->ID;
					}
				}
			}
		}
		return 0;
	}

	public static function handle_create_page() {
		check_admin_referer( 'lset_create_page' );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lumaha-simple-event-tickets' ) );
		}
		$key   = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		$pages = self::listing_pages();
		if ( ! isset( $pages[ $key ] ) ) {
			wp_die( esc_html__( 'Unknown list.', 'lumaha-simple-event-tickets' ) );
		}
		$id = self::listing_page( $key );
		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $pages[ $key ]['title'],
					'post_content' => '<!-- wp:shortcode -->' . $pages[ $key ]['shortcode'] . '<!-- /wp:shortcode -->',
				)
			);
		}
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'lset_page_' . $key, (int) $id );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-settings&lset_msg=page_created#lset-pages' ) );
		exit;
	}

	public static function handle_save_settings() {
		check_admin_referer( 'lset_save_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'lumaha-simple-event-tickets' ) );
		}
		$old  = Lumaha_Simple_Event_Tickets::settings();
		$slug = isset( $_POST['event_slug'] ) ? sanitize_title( wp_unslash( $_POST['event_slug'] ) ) : 'events';
		$new  = array(
			'hold_minutes'     => isset( $_POST['hold_minutes'] ) ? min( 120, max( 1, (int) $_POST['hold_minutes'] ) ) : 15,
			'auto_complete'    => empty( $_POST['auto_complete'] ) ? 0 : 1,
			'event_slug'       => $slug ? $slug : 'events',
			'event_sidebar'    => empty( $_POST['event_sidebar'] ) ? 0 : 1,
			'delete_on_remove' => empty( $_POST['delete_on_remove'] ) ? 0 : 1,
			'ticket_emails'    => empty( $_POST['ticket_emails'] ) ? 0 : 1,
			'ticket_colour'    => isset( $_POST['ticket_colour'] ) && sanitize_hex_color( wp_unslash( $_POST['ticket_colour'] ) ) ? sanitize_hex_color( wp_unslash( $_POST['ticket_colour'] ) ) : '#1d3557',
			'ticket_logo'      => isset( $_POST['ticket_logo'] ) ? esc_url_raw( wp_unslash( $_POST['ticket_logo'] ) ) : '',
			'ticket_note'      => isset( $_POST['ticket_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_note'] ) ) : '',
		);
		update_option( 'lset_settings', array_merge( $old, $new ) );
		if ( $old['event_slug'] !== $new['event_slug'] ) {
			update_option( 'lset_flush_rewrites', 1 );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . LSET_CPT . '&page=lset-settings&lset_msg=settings_saved' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Venue address fields
	 * ------------------------------------------------------------------ */

	private static function venue_inputs() {
		return array(
			'lset_address'  => __( 'Address', 'lumaha-simple-event-tickets' ),
			'lset_postcode' => __( 'Postcode', 'lumaha-simple-event-tickets' ),
			'lset_map_url'  => __( 'Map link (optional)', 'lumaha-simple-event-tickets' ),
		);
	}

	public static function venue_add_fields() {
		wp_nonce_field( 'lset_venue', 'lset_venue_nonce' );
		foreach ( self::venue_inputs() as $key => $label ) {
			printf( '<div class="form-field"><label for="%1$s">%2$s</label><input type="text" id="%1$s" name="%1$s" value=""></div>', esc_attr( $key ), esc_html( $label ) );
		}
	}

	public static function venue_edit_fields( $term ) {
		wp_nonce_field( 'lset_venue', 'lset_venue_nonce' );
		foreach ( self::venue_inputs() as $key => $label ) {
			printf( '<tr class="form-field"><th><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%1$s" value="%3$s"></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( get_term_meta( $term->term_id, $key, true ) ) );
		}
	}

	public static function venue_save( $term_id ) {
		if ( ! isset( $_POST['lset_venue_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lset_venue_nonce'] ) ), 'lset_venue' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		foreach ( array_keys( self::venue_inputs() ) as $key ) {
			$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_term_meta( $term_id, $key, 'lset_map_url' === $key ? esc_url_raw( $v ) : $v );
		}
	}
}
