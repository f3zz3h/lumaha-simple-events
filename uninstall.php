<?php
/**
 * Runs when the plugin is deleted (not when it's deactivated).
 *
 * Leaves everything in place unless "Delete all data" is ticked in Events → Settings.
 * WooCommerce orders are never touched, so old orders keep their ticket details.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$lset_settings = get_option( 'lset_settings', array() );
if ( empty( $lset_settings['delete_on_remove'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'tickets', 'ticket_groups', 'fields', 'bookings', 'attendees', 'holds' ) as $lset_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'lset_' . $lset_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

$lset_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'lset_event' ) );
foreach ( $lset_ids as $lset_id ) {
	wp_delete_post( (int) $lset_id, true );
}

$lset_terms = $wpdb->get_col( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->term_taxonomy} t WHERE t.taxonomy = %s", 'lset_venue' ) );
foreach ( $lset_terms as $lset_term ) {
	$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $lset_term ) );
	$wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => (int) $lset_term ) );
	$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $lset_term ) );
}

$lset_product = (int) get_option( 'lset_product_id' );
if ( $lset_product ) {
	wp_delete_post( $lset_product, true );
}

foreach ( array( 'lset_settings', 'lset_db_version', 'lset_product_id', 'lset_flush_rewrites' ) as $lset_option ) {
	delete_option( $lset_option );
}

foreach ( array( 'administrator', 'shop_manager' ) as $lset_role_name ) {
	$lset_role = get_role( $lset_role_name );
	if ( $lset_role ) {
		$lset_role->remove_cap( 'lset_view_attendees' );
		$lset_role->remove_cap( 'lset_check_in' );
	}
}

wp_clear_scheduled_hook( 'lset_clear_holds' );
