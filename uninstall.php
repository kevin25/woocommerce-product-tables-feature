<?php
/**
 * WooCommerce Product Tables Uninstall
 *
 * Removes all plugin data when the plugin is deleted via the WordPress admin.
 * Does NOT drop custom tables by default to prevent accidental data loss.
 * Set WPT_REMOVE_ALL_DATA constant to true before uninstalling to drop tables.
 *
 * @package WooCommerce\ProductTables
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
delete_option( 'wpt_custom_product_tables_enabled' );
delete_option( 'wpt_dual_write_enabled' );
delete_option( 'wpt_backwards_compat_enabled' );
delete_option( 'wpt_migration_batch_size' );
delete_option( 'wpt_db_version' );
delete_option( 'wpt_installed_at' );

// Clear scheduled events.
$timestamp = wp_next_scheduled( 'wpt_daily_cache_cleanup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wpt_daily_cache_cleanup' );
}

// Unschedule Action Scheduler actions if available.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'wpt_background_sync' );
	as_unschedule_all_actions( 'wpt_migration_batch' );
}

// Drop custom tables only if WPT_REMOVE_ALL_DATA is explicitly set.
if ( defined( 'WPT_REMOVE_ALL_DATA' ) && WPT_REMOVE_ALL_DATA ) {
	global $wpdb;

	$tables = array(
		"{$wpdb->prefix}wpt_product_variation_attribute_values",
		"{$wpdb->prefix}wpt_product_relationships",
		"{$wpdb->prefix}wpt_product_downloads",
		"{$wpdb->prefix}wpt_product_attribute_values",
		"{$wpdb->prefix}wpt_product_attributes",
		"{$wpdb->prefix}wpt_products",
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
	}
}
