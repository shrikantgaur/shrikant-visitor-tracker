<?php
/**
 * Database schema installation, migration, and helpers.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_DB
 *
 * Owns all DDL (CREATE TABLE, ALTER TABLE, index creation).
 * Uses dbDelta() for safe, idempotent schema migrations.
 *
 * Schema design decisions:
 * ─────────────────────────
 * • sk_visitor_analytics  : One row per page-view (raw events).
 *   - Kept narrow to minimise I/O on writes; heavy columns are nullable.
 *   - Composite index on (visit_date, page_id) powers most dashboard queries.
 *   - visitor_id is a hashed/opaque string — never a real IP.
 *
 * • sk_visitor_summary    : Pre-aggregated hourly/daily buckets.
 *   - Written by WP-Cron every hour from the raw table.
 *   - Dashboard widgets read ONLY from here → fast, no full-table scans.
 *   - UNIQUE KEY on (period_type, period_start, page_id, dimension_key)
 *     allows ON DUPLICATE KEY UPDATE increments.
 */
final class Shrikant_VT_DB {

    // DB schema version — bump to trigger dbDelta re-run on next upgrade.
    private const SCHEMA_VERSION = '1.0.0';
    private const OPTION_KEY     = 'sk_vt_db_version';

    /**
     * Static install entry-point, called by register_activation_hook().
     */
    public static function install(): void {
        ( new self() )->maybe_upgrade();
    }

    /**
     * Instance entry-point — also called by register_hooks() so upgrades
     * run automatically when the plugin is updated via wp-admin.
     */
    public function maybe_upgrade(): void {
        if ( get_option( self::OPTION_KEY ) === self::SCHEMA_VERSION ) {
            return; // Already up-to-date.
        }
        $this->create_tables();
        update_option( self::OPTION_KEY, self::SCHEMA_VERSION, false );
    }

    /** Expose hooks so the main class can call register_hooks(). */
    public function register_hooks(): void {
        // On plugin update (version bump), run schema upgrade on the next
        // admin request. admin_init fires after plugins_loaded so it's safe.
        add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );
    }

    /**
     * Create or upgrade both database tables using dbDelta().
     *
     * IMPORTANT: dbDelta is strict about SQL formatting:
     *   - Two spaces before field definitions inside CREATE TABLE.
     *   - PRIMARY KEY must be on its own line.
     *   - No trailing comma on last field before the closing parenthesis.
     */
    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Table 1: Raw page-view events ────────────────────────────────────
        $raw_table = $wpdb->prefix . Shrikant_VT_TABLE_RAW;

        $sql_raw = "CREATE TABLE {$raw_table} (
  id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_id      VARCHAR(64)         NOT NULL DEFAULT '',
  session_id      VARCHAR(64)         NOT NULL DEFAULT '',
  page_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  visit_date      DATE                NOT NULL,
  visit_hour      TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  visit_time      DATETIME            NOT NULL,
  country_code    CHAR(2)             NOT NULL DEFAULT 'XX',
  device_type     VARCHAR(10)         NOT NULL DEFAULT 'desktop',
  browser         VARCHAR(30)         NOT NULL DEFAULT '',
  os              VARCHAR(30)         NOT NULL DEFAULT '',
  referrer_type   VARCHAR(10)         NOT NULL DEFAULT 'direct',
  referrer_url    VARCHAR(512)        NOT NULL DEFAULT '',
  utm_source      VARCHAR(100)        NOT NULL DEFAULT '',
  utm_medium      VARCHAR(100)        NOT NULL DEFAULT '',
  utm_campaign    VARCHAR(100)        NOT NULL DEFAULT '',
  utm_content     VARCHAR(100)        NOT NULL DEFAULT '',
  utm_term        VARCHAR(100)        NOT NULL DEFAULT '',
  is_unique       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  page_url        VARCHAR(512)        NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY idx_visit_date      (visit_date),
  KEY idx_page_id         (page_id),
  KEY idx_visitor_id      (visitor_id(20)),
  KEY idx_date_page       (visit_date, page_id),
  KEY idx_country         (country_code),
  KEY idx_referrer_type   (referrer_type),
  KEY idx_is_unique       (is_unique),
  KEY idx_visit_hour      (visit_date, visit_hour)
) {$charset_collate};";

        // ── Table 2: Pre-aggregated summary buckets ───────────────────────────
        $sum_table = $wpdb->prefix . Shrikant_VT_TABLE_SUM;

        $sql_sum = "CREATE TABLE {$sum_table} (
  id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  period_type     VARCHAR(10)         NOT NULL DEFAULT 'day',
  period_start    DATETIME            NOT NULL,
  page_id         BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  dimension_key   VARCHAR(50)         NOT NULL DEFAULT 'total',
  dimension_val   VARCHAR(100)        NOT NULL DEFAULT '',
  pageviews       INT(11) UNSIGNED    NOT NULL DEFAULT 0,
  unique_visitors INT(11) UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_summary_bucket (period_type, period_start, page_id, dimension_key, dimension_val),
  KEY idx_period_type   (period_type, period_start),
  KEY idx_page_id       (page_id)
) {$charset_collate};";

        dbDelta( $sql_raw );
        dbDelta( $sql_sum );
    }

    /**
     * Network activation: install tables for every existing site in the network.
     * Called from register_activation_hook when plugin is network-activated.
     */
    public static function network_install(): void {
        if ( ! is_multisite() ) {
            self::install();
            return;
        }

        $sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
        foreach ( $sites as $site_id ) {
            switch_to_blog( (int) $site_id );
            ( new self() )->maybe_upgrade();
            restore_current_blog();
        }
    }

    /**
     * New site created in the network: install tables for it automatically.
     *
     * @param WP_Site $new_site The newly created site object.
     */
    public static function on_new_site( WP_Site $new_site ): void {
        if ( ! is_plugin_active_for_network( plugin_basename( Shrikant_VT_FILE ) ) ) {
            return;
        }
        switch_to_blog( (int) $new_site->blog_id );
        ( new self() )->maybe_upgrade();
        restore_current_blog();
    }
    public static function uninstall(): void {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB; schema statements cannot take placeholders.
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . Shrikant_VT_TABLE_RAW );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB; schema statements cannot take placeholders.
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . Shrikant_VT_TABLE_SUM );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        // phpcs:enable
        delete_option( self::OPTION_KEY );
        delete_option( 'sk_vt_settings' );
    }

    /**
     * Helper: fully-qualified table name with prefix.
     */
    public static function raw_table(): string {
        global $wpdb;
        return $wpdb->prefix . Shrikant_VT_TABLE_RAW;
    }

    public static function sum_table(): string {
        global $wpdb;
        return $wpdb->prefix . Shrikant_VT_TABLE_SUM;
    }
}
