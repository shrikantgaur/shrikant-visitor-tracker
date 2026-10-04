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
 * 1. sk_vt_hourly_aggregate (hourly)
 *    Reads new raw rows from sk_visitor_analytics, groups them by
 *    period bucket + dimension in SQL (one query per dimension),
 *    then upserts into sk_visitor_summary using multi-row INSERT …
 *    ON DUPLICATE KEY UPDATE. Previously did one query per row × per
 *    dimension (up to 25,000 queries/run). Now: 5 queries total.
 *
 * 2. sk_vt_daily_cleanup (daily)
 *    Deletes raw rows older than the configured retention period in
 *    batches of 1,000 to avoid table-locking. Also purges old summary
 *    buckets beyond 2× retention.
 */
final class Shrikant_VT_Cron {

    private const HOURLY_HOOK  = 'sk_vt_hourly_aggregate';
    private const DAILY_HOOK   = 'sk_vt_daily_cleanup';

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
    public function run_aggregation(): void {
        global $wpdb;

        $raw_table = Shrikant_VT_DB::raw_table();
        $sum_table = Shrikant_VT_DB::sum_table();
        $last_id   = (int) get_option( 'sk_vt_last_agg_id', 0 );

        // Find the upper bound ID for this batch — process at most AGG_BATCH rows.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        $batch_max_row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$raw_table} WHERE id > %d ORDER BY id ASC LIMIT %d, 1",
                $last_id,
                self::AGG_BATCH - 1
            )
        );

        // If fewer than AGG_BATCH rows exist, grab the true max id.
        if ( null === $batch_max_row ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
            $batch_max_row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MAX(id) FROM {$raw_table} WHERE id > %d",
                    $last_id
                )
            );
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
        ];

        foreach ( $dimensions as [ $dim_key, $group_col, $val_col ] ) {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
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

        update_option( 'sk_vt_last_agg_id', $max_id, false );

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

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
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
        $cutoff         = gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );
        $raw_table      = Shrikant_VT_DB::raw_table();
        $sum_table      = Shrikant_VT_DB::sum_table();

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
            $deleted = (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$raw_table} WHERE visit_date < %s LIMIT 1000",
                    $cutoff
                )
            );
            // Brief yield between batches on very large deletes.
            if ( $deleted === 1000 ) {
                usleep( 50000 ); // 50 ms.
            }
        } while ( $deleted === 1000 );

        // Purge summary buckets beyond 2× retention.
        $sum_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $retention_days * 2 ) . ' days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$sum_table} WHERE period_start < %s LIMIT 5000",
                $sum_cutoff
            )
        );

        /**
         * Action: sk_vt_after_cleanup
         * @param string $cutoff Date used for deletion.
         */
        do_action( 'sk_vt_after_cleanup', $cutoff );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Delete all plugin stat transients from wp_options.
     * Deletes both _transient_sk_vt_* and _transient_timeout_sk_vt_* rows
     * to prevent orphaned timeout entries accumulating.
     * Runs hourly in the background — acceptable use of a LIKE query.
     */
    private function flush_stat_transients(): void {
        global $wpdb;

        $base_prefix    = $wpdb->esc_like( '_transient_sk_vt_' );
        $timeout_prefix = $wpdb->esc_like( '_transient_timeout_sk_vt_' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '{$base_prefix}%'
                OR option_name LIKE '{$timeout_prefix}%'"
        );
    }
}
