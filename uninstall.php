<?php
/**
 * Uninstall routine for Shrikant Visitor Tracker.
 *
 * Called by WordPress when the plugin is deleted from wp-admin → Plugins.
 * Runs in a separate PHP process — must bootstrap everything independently.
 * Handles both single-site and multisite installations.
 *
 * @package Shrikant_Visitor_Tracker
 */

// WordPress security check — only allow calls via WP's uninstall mechanism.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Keep the data unless the site owner has asked for it to go.
 *
 * This file runs when the plugin is deleted from wp-admin, and deletion is
 * not always a goodbye: replacing a hand-installed copy with the published
 * one, or moving to a renamed build, both go through Delete. Dropping the
 * tables there would throw away every visit ever recorded at the moment the
 * person believed they were keeping it. The setting lives under Settings →
 * "Delete all data when the plugin is deleted" and is off by default.
 */
$shrikant_vt_settings = get_option( 'sk_vt_settings', [] );

if ( empty( $shrikant_vt_settings['delete_data_on_uninstall'] ) ) {
	// Tidy up only what is cheap to rebuild: scheduled events and caches.
	wp_clear_scheduled_hook( 'sk_vt_hourly_aggregate' );
	wp_clear_scheduled_hook( 'sk_vt_daily_cleanup' );

	return;
}

if ( is_multisite() ) {
    // Multisite: drop tables and clean options for every site in the network.
    $shrikant_vt_sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
    foreach ( $shrikant_vt_sites as $shrikant_vt_site_id ) {
        switch_to_blog( (int) $shrikant_vt_site_id );
        shrikant_vt_uninstall_site();
        restore_current_blog();
    }
} else {
    shrikant_vt_uninstall_site();
}

// Remove network-level options if network-activated.
if ( is_multisite() ) {
    delete_site_option( 'sk_vt_db_version' );
}

// Clear cron events (network-wide hooks).
wp_clear_scheduled_hook( 'sk_vt_hourly_aggregate' );
wp_clear_scheduled_hook( 'sk_vt_daily_cleanup' );

/**
 * Drop tables, delete options, and purge transients for the current blog.
 * Runs once per site on multisite, once total on single site.
 */
function shrikant_vt_uninstall_site(): void {
    global $wpdb;

    // Drop plugin tables.
    $raw = $wpdb->prefix . 'sk_visitor_analytics';
    $sum = $wpdb->prefix . 'sk_visitor_summary';

    // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names are built from $wpdb->prefix; DROP and LIKE patterns cannot take placeholders.
    $wpdb->query( "DROP TABLE IF EXISTS `{$raw}`" );
    // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
    // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names are built from $wpdb->prefix; DROP and LIKE patterns cannot take placeholders.
    $wpdb->query( "DROP TABLE IF EXISTS `{$sum}`" );
    // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
    // phpcs:enable

    // Delete plugin options.
    delete_option( 'sk_vt_settings' );
    delete_option( 'sk_vt_db_version' );
    delete_option( 'sk_vt_last_agg_id' );

    // Purge all plugin transients — both the value and the timeout key.
    $base_prefix    = $wpdb->esc_like( '_transient_sk_vt_' );
    $timeout_prefix = $wpdb->esc_like( '_transient_timeout_sk_vt_' );

    // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names are built from $wpdb->prefix; DROP and LIKE patterns cannot take placeholders.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '{$base_prefix}%'
            OR option_name LIKE '{$timeout_prefix}%'"
    );
    // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
    // phpcs:enable

    // Remove per-site cron events.
    wp_clear_scheduled_hook( 'sk_vt_hourly_aggregate' );
    wp_clear_scheduled_hook( 'sk_vt_daily_cleanup' );
}
