<?php
/**
 * Uninstall: remove settings, and the archive tables only when the archive is empty.
 *
 * Archived orders exist only in the archive tables. Dropping them would delete those orders for good, so when the
 * archive still holds orders the tables are kept. Restore the orders first (Order Archive > Archived orders, or
 * `wp orderloft restore --all`), then uninstall to remove everything.
 *
 * @package Orderloft
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$orderloft_index = $wpdb->prefix . 'orderloft_index';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall check.
$orderloft_has_index = $orderloft_index === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orderloft_index ) ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall check.
$orderloft_archived = $orderloft_has_index ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $orderloft_index ) ) : 0;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'orderloft_daily', array(), 'orderloft' );
	as_unschedule_all_actions( 'orderloft_batch', array(), 'orderloft' );
}

if ( 0 === $orderloft_archived ) {
	$orderloft_tables = array( 'orders', 'order_addresses', 'order_operational_data', 'orders_meta', 'posts', 'postmeta', 'order_items', 'order_itemmeta', 'comments', 'commentmeta', 'index' );
	foreach ( $orderloft_tables as $orderloft_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'orderloft_' . $orderloft_table ) );
	}
	delete_option( 'orderloft_settings' );
	delete_option( 'orderloft_db_version' );
	delete_option( 'orderloft_run' );
}
