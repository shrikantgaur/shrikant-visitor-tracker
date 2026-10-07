<?php
/**
 * WP-Cron jobs: data aggregation and cleanup.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Cron
 *
 * Manages two scheduled tasks:
 *
 * 1. shrikant_vt_hourly_aggregate (hourly)
 *    Reads new raw rows from shrikant_visitor_analytics, groups them by
 *    period bucket + dimension in SQL (one query per dimension),
 *    then upserts into shrikant_visitor_summary using multi-row INSERT …
 *    ON DUPLICATE KEY UPDATE. Previously did one query per row × per
 *    dimension (up to 25,000 queries/run). Now: 5 queries total.
 *
 * 2. shrikant_vt_daily_cleanup (daily)
 *    Deletes raw rows older than the configured retention period in
 *    batches of 1,000 to avoid table-locking. Also purges old summary
 *    buckets beyond 2× retention.
 */
final class Shrikant_VT_Cron {

    private const HOURLY_HOOK  = 'shrikant_vt_hourly_aggregate';
    private const DAILY_HOOK   = 'shrikant_vt_daily_cleanup';

    /** Maximum rows to process per aggregation run. */
    private const AGG_BATCH = 10000;

    /** Maximum upsert rows per multi-row INSERT statement. */
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly Shrikant_VT_Settings $settings
    ) {}

    public function register_hooks(): void {
        $this->maybe_schedule();
        add_action( self::HOURLY_HOOK, [ $this, 'run_aggregation' ] );
        add_action( self::DAILY_HOOK,  [ $this, 'run_cleanup' ] );
    }

    private function maybe_schedule(): void {
        if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::HOURLY_HOOK );
        }
        if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::DAILY_HOOK );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::HOURLY_HOOK );
        wp_clear_scheduled_hook( self::DAILY_HOOK );
    }

    // ── Job: aggregate ────────────────────────────────────────────────────────

    /**
     * Aggregate new raw rows into the summary table.
     *
     * Performance approach:
     * ─────────────────────
     * Instead of one INSERT per dimension per raw row (up to 25k queries),
     * we run one GROUP BY query per dimension (5 queries), build a multi-row
     * VALUES list in PHP, then upsert in chunks of 500 rows (a few queries).
     * Total DB round-trips: ~5–15 regardless of how many raw rows arrived.
     *
     * Dimensions aggregated per (period_start, page_id) bucket:
     *   total        → 'total'      : 'total'
     *   device_type  → 'device_type': e.g. 'mobile'
     *   country_code → 'country_code': e.g. 'US'
     *   referrer_type→ 'referrer_type': e.g. 'search'
     *   browser      → 'browser'    : e.g. 'Chrome'
     */
    /**
     * Throw the summaries away and build them again from the raw rows.
     *
     * The aggregation adds to the counts it finds, so running it twice over
     * the same rows counts them twice. That cannot happen on its own -- it
     * only ever reads ids above the last one it handled -- but it does happen
     * if that marker is moved back, a database is restored to an older copy,
     * or two runs overlap. This is the way back from that.
     *
     * Never automatic, and it refuses when the raw rows no longer reach as far
     * back as the summaries do: rebuilding from an incomplete source would
     * replace real history with a shorter version of it.
     *
     * @return array{rebuilt:bool,reason:string,rows:int}
     */
    public function rebuild_summaries(): array {
        global $wpdb;

        $raw_table = Shrikant_VT_DB::raw_table();
        $sum_table = Shrikant_VT_DB::sum_table();

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB; no value here comes from input.
        $raw_earliest = (string) $wpdb->get_var( "SELECT MIN(visit_time) FROM {$raw_table}" );
        $sum_earliest = (string) $wpdb->get_var( "SELECT MIN(period_start) FROM {$sum_table}" );

        if ( '' === $raw_earliest ) {
            return [ 'rebuilt' => false, 'reason' => 'no-raw-rows', 'rows' => 0 ];
        }

        if ( '' !== $sum_earliest && substr( $sum_earliest, 0, 10 ) < substr( $raw_earliest, 0, 10 ) ) {
            return [ 'rebuilt' => false, 'reason' => 'summaries-reach-further-back', 'rows' => 0 ];
        }

        $wpdb->query( "DELETE FROM {$sum_table}" );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        update_option( 'shrikant_vt_last_agg_id', 0, false );

        // One batch at a time, the way the hourly job does it.
        $guard = 0;
        while ( $this->catch_up() && ++$guard < 1000 ) {
            continue;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB.
        $rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sum_table}" );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        return [ 'rebuilt' => true, 'reason' => 'ok', 'rows' => $rows ];
    }

    /**
     * Fill in a dimension that was not being aggregated before.
     *
     * Adds rows for one dimension across every raw visit still on disk. It
     * only ever inserts a dimension_key that is absent, so it cannot double
     * anything that is already there -- the usual aggregation adds to the
     * counts it finds, which is exactly what must not happen here.
     *
     * Where the raw rows are gone, that history simply is not available. The
     * alternative -- rebuilding the whole summary table -- would delete the
     * history of anyone who had ever switched retention on.
     *
     * @param string $dimension Column in the raw table, also the dimension key.
     * @return int Rows written.
     */
    public function backfill_dimension( string $dimension ): int {
        global $wpdb;

        $allowed = [ 'os', 'browser', 'device_type', 'country_code', 'referrer_type' ];
        if ( ! in_array( $dimension, $allowed, true ) ) {
            return 0;
        }

        $raw_table = Shrikant_VT_DB::raw_table();
        $sum_table = Shrikant_VT_DB::sum_table();

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension is checked against the list above, never taken from input.
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$sum_table} WHERE dimension_key = %s", $dimension )
        );

        if ( $existing > 0 ) {
            return 0; // Already present; adding more would double it.
        }

        $written = (int) $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$sum_table}
                 (period_type, period_start, page_id, dimension_key, dimension_val, pageviews, unique_visitors)
                 SELECT 'hour',
                        DATE_FORMAT(visit_time, '%%Y-%%m-%%d %%H:00:00'),
                        page_id,
                        %s,
                        {$dimension},
                        COUNT(*),
                        SUM(is_unique)
                 FROM {$raw_table}
                 GROUP BY DATE_FORMAT(visit_time, '%%Y-%%m-%%d %%H:00:00'), page_id, {$dimension}",
                $dimension
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        return $written;
    }

    /**
     * Aggregate anything the hourly job has not reached yet.
     *
     * The reports read summaries, so a visit is invisible until it has been
     * rolled up. Waiting for the next hourly run would mean opening the
     * dashboard after a page view and not seeing it, which reads as the
     * plugin being broken. Called when an admin opens a report, where one
     * extra query on a handful of rows costs nothing.
     *
     * @return bool Whether anything was aggregated.
     */
    public function catch_up(): bool {
        global $wpdb;

        $raw_table = Shrikant_VT_DB::raw_table();
        $last_id   = (int) get_option( 'shrikant_vt_last_agg_id', 0 );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB; the value is a placeholder.
        $behind = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$raw_table} WHERE id > %d", $last_id )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        if ( $behind < 1 ) {
            return false;
        }

        $this->run_aggregation();

        return true;
    }

    public function run_aggregation(): void {
        global $wpdb;

        $raw_table = Shrikant_VT_DB::raw_table();
        $sum_table = Shrikant_VT_DB::sum_table();
        $last_id   = (int) get_option( 'shrikant_vt_last_agg_id', 0 );

        // Find the upper bound ID for this batch — process at most AGG_BATCH rows.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension columns from the literal map above, never from input; every value is a placeholder.
        $batch_max_row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$raw_table} WHERE id > %d ORDER BY id ASC LIMIT %d, 1",
                $last_id,
                self::AGG_BATCH - 1
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // If fewer than AGG_BATCH rows exist, grab the true max id.
        if ( null === $batch_max_row ) {
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension columns from the literal map above, never from input; every value is a placeholder.
            $batch_max_row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MAX(id) FROM {$raw_table} WHERE id > %d",
                    $last_id
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        }

        $max_id = (int) $batch_max_row;

        if ( ! $max_id ) {
            return; // No new rows.
        }

        // ── 5 aggregation queries (one per dimension) ─────────────────────────
        $dimensions = [
            // [ dim_key, GROUP-BY column expression, value column expression ]
            [ 'total',         "'total'",        "'total'" ],
            [ 'device_type',   'device_type',    'device_type' ],
            [ 'country_code',  'country_code',   'country_code' ],
            [ 'referrer_type', 'referrer_type',  'referrer_type' ],
            [ 'browser',       'browser',        'browser' ],
            [ 'os',            'os',             'os' ],
        ];

        foreach ( $dimensions as [ $dim_key, $group_col, $val_col ] ) {
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension columns from the literal map above, never from input; every value is a placeholder.
            $aggregated = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                         DATE_FORMAT(visit_time, '%%Y-%%m-%%d %%H:00:00') AS period_start,
                         page_id,
                         {$val_col}                                        AS dim_val,
                         COUNT(*)                                           AS pv,
                         SUM(is_unique)                                     AS uv
                     FROM {$raw_table}
                     WHERE id > %d AND id <= %d
                     GROUP BY period_start, page_id, {$group_col}",
                    $last_id,
                    $max_id
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
            // phpcs:enable

            if ( empty( $aggregated ) ) {
                continue;
            }

            // Build multi-row upsert in chunks.
            $chunks = array_chunk( $aggregated, self::INSERT_CHUNK );
            foreach ( $chunks as $chunk ) {
                $this->upsert_summary_chunk( $sum_table, $dim_key, $chunk );
            }
        }

        update_option( 'shrikant_vt_last_agg_id', $max_id, false );

        // Flush stat transients so dashboard reflects updated data.
        $this->flush_stat_transients();
    }

    /**
     * Build and execute a multi-row INSERT … ON DUPLICATE KEY UPDATE
     * for a chunk of aggregated dimension rows.
     *
     * @param string                             $sum_table Fully-qualified table name.
     * @param string                             $dim_key   Dimension key (e.g. 'device_type').
     * @param array<int,array<string,string>>    $chunk     Rows from the GROUP BY query.
     */
    private function upsert_summary_chunk( string $sum_table, string $dim_key, array $chunk ): void {
        global $wpdb;

        // Build placeholder list and flat value array.
        $placeholders = [];
        $values       = [];

        foreach ( $chunk as $row ) {
            $placeholders[] = "('hour', %s, %d, %s, %s, %d, %d)";
            $values[]       = $row['period_start'];
            $values[]       = (int) $row['page_id'];
            $values[]       = $dim_key;
            $values[]       = (string) $row['dim_val'];
            $values[]       = (int) $row['pv'];
            $values[]       = (int) $row['uv'];
        }

        $placeholder_sql = implode( ', ', $placeholders );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension columns from the literal map above, never from input; every value is a placeholder.
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$sum_table}
                 (period_type, period_start, page_id, dimension_key, dimension_val, pageviews, unique_visitors)
                 VALUES {$placeholder_sql}
                 ON DUPLICATE KEY UPDATE
                     pageviews       = pageviews       + VALUES(pageviews),
                     unique_visitors = unique_visitors + VALUES(unique_visitors)",
                $values
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        // phpcs:enable
    }

    // ── Job: cleanup ──────────────────────────────────────────────────────────

    /**
     * Delete raw rows older than the configured retention period in 1,000-row
     * batches to avoid long table locks.
     */
    public function run_cleanup(): void {
        global $wpdb;

        $retention_days = $this->settings->retention_days();

        /*
         * Zero means keep everything, which is the default. Deleting visits
         * is housekeeping for a site that wants it, not something that should
         * happen to anybody who never asked.
         */
        if ( $retention_days < 1 ) {
            do_action( 'shrikant_vt_after_cleanup', '' );
            return;
        }

        $cutoff    = gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );
        $raw_table = Shrikant_VT_DB::raw_table();

        do {
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension columns from the literal map above, never from input; every value is a placeholder.
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$raw_table} WHERE visit_date < %s LIMIT 1000",
                    $cutoff
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
            // Brief yield between batches on very large deletes.
            if ( $deleted === 1000 ) {
                usleep( 50000 ); // 50 ms.
            }
        } while ( $deleted === 1000 );

        /*
         * The summaries are deliberately left alone, for good. They are what
         * every report reads and they are small -- a handful of rows per page
         * per hour, against one row per page view. Clearing them at twice the
         * retention period, which is what used to happen here, did not tidy
         * anything up: it deleted the history the reports are made of.
         */

        /**
         * Action: shrikant_vt_after_cleanup
         * @param string $cutoff Date used for deletion.
         */
        do_action( 'shrikant_vt_after_cleanup', $cutoff );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Delete all plugin stat transients from wp_options.
     * Deletes both _transient_shrikant_vt_* and _transient_timeout_shrikant_vt_* rows
     * to prevent orphaned timeout entries accumulating.
     * Runs hourly in the background — acceptable use of a LIKE query.
     */
    private function flush_stat_transients(): void {
        global $wpdb;

        $base_prefix    = $wpdb->esc_like( '_transient_shrikant_vt_' );
        $timeout_prefix = $wpdb->esc_like( '_transient_timeout_shrikant_vt_' );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table names come from Shrikant_VT_DB and the dimension columns from the literal map above, never from input; every value is a placeholder.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '{$base_prefix}%'
                OR option_name LIKE '{$timeout_prefix}%'"
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
    }
}
