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
 * • shrikant_visitor_analytics  : One row per page-view (raw events).
 *   - Kept narrow to minimise I/O on writes; heavy columns are nullable.
 *   - Composite index on (visit_date, page_id) powers most dashboard queries.
 *   - visitor_id is a hashed/opaque string — never a real IP.
 *
 * • shrikant_visitor_summary    : Pre-aggregated hourly/daily buckets.
 *   - Written by WP-Cron every hour from the raw table.
 *   - Dashboard widgets read ONLY from here → fast, no full-table scans.
 *   - UNIQUE KEY on (period_type, period_start, page_id, dimension_key)
 *     allows ON DUPLICATE KEY UPDATE increments.
 */
final class Shrikant_VT_DB {

    // DB schema version — bump to trigger dbDelta re-run on next upgrade.
    private const SCHEMA_VERSION = '1.1.0';
    private const OPTION_KEY     = 'shrikant_vt_db_version';

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
    /**
     * Move anything stored under the old, weakly prefixed names.
     *
     * Everything this plugin writes used to be keyed on "sk_vt" / "sk_visitor",
     * which is too short to be safely distinct. Renaming the keys without
     * moving what is already under them would leave a working install looking
     * like a fresh one, with months of visits still in the database and
     * nothing reading them. So this runs once, before anything else.
     *
     * Each step checks that the old name exists and the new one does not, so
     * running it twice cannot overwrite migrated data with stale data.
     *
     * @return array<string,int> What was moved, for the log.
     */
    public static function migrate_legacy_names(): array {
        global $wpdb;

        $moved = [ 'tables' => 0, 'options' => 0, 'meta' => 0, 'transients' => 0, 'events' => 0 ];

        // ── Tables ──────────────────────────────────────────────────────────
        foreach ( [
            'sk_visitor_analytics' => Shrikant_VT_TABLE_RAW,
            'sk_visitor_summary'   => Shrikant_VT_TABLE_SUM,
        ] as $old_suffix => $new_suffix ) {
            $old = $wpdb->prefix . $old_suffix;
            $new = $wpdb->prefix . $new_suffix;

            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names are built from $wpdb->prefix and this plugin's own constants, never from input.
            $has_old = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
            $has_new = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );

            if ( $has_old && ! $has_new ) {
                $wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
                $moved['tables']++;
            }
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        }

        // ── Options ─────────────────────────────────────────────────────────
        foreach ( [ 'settings', 'db_version', 'last_agg_id', 'import_log' ] as $name ) {
            $old = 'sk_vt_' . $name;
            $new = 'shrikant_vt_' . $name;
            $val = get_option( $old, null );

            if ( null !== $val && false === get_option( $new, false ) ) {
                update_option( $new, $val, false );
                $moved['options']++;
            }

            delete_option( $old );
        }

        // ── Imported view counts, one meta key across every post ────────────
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- a single keyed UPDATE on postmeta; there is no API for renaming a meta key.
        $moved['meta'] = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
                '_shrikant_vt_imported_views',
                '_sk_vt_imported_views'
            )
        );

        // ── Old caches: not worth moving, only worth clearing ───────────────
        $like = $wpdb->esc_like( '_transient_sk_vt_' ) . '%';
        $like_timeout = $wpdb->esc_like( '_transient_timeout_sk_vt_' ) . '%';
        $moved['transients'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $like_timeout
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // ── Scheduled events ────────────────────────────────────────────────
        foreach ( [ 'sk_vt_hourly_aggregate', 'sk_vt_daily_cleanup' ] as $old_hook ) {
            if ( wp_next_scheduled( $old_hook ) ) {
                wp_clear_scheduled_hook( $old_hook );
                $moved['events']++;
            }
        }

        return $moved;
    }

    public function maybe_upgrade(): void {
        /*
         * Before the version check. An install still on the old key names has
         * no shrikant_vt_db_version to compare against, so it would be taken
         * for a brand-new one while its months of data sat under keys nothing
         * reads any more.
         */
        self::migrate_legacy_names();

        if ( get_option( self::OPTION_KEY ) === self::SCHEMA_VERSION ) {
            return; // Already up-to-date.
        }
        $this->create_tables();

        /*
         * Reports read the summaries now, and the operating system was never
         * among the dimensions being summarised. Fill it in from the raw rows
         * that are still here, once.
         */
        $cron = Shrikant_Visitor_Tracker::get_instance()->get( 'cron' );
        if ( $cron instanceof Shrikant_VT_Cron ) {
            $cron->backfill_dimension( 'os' );
        }

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
        delete_option( 'shrikant_vt_settings' );
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
