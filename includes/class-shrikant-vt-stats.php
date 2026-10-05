<?php
/**
 * Stats query helpers — all dashboard data comes through here.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Stats
 *
 * Public API for querying visitor analytics data.
 * All methods use prepared statements and read from the summary table
 * where possible (fast), falling back to the raw table only when
 * granular data is required.
 *
 * Result caching:
 * ───────────────
 * Each method caches its result in a transient for 5 minutes so that
 * multiple dashboard widgets don't each run the same query.
 *
 * Extensibility:
 * ──────────────
 * The filter shrikant_vt_get_stats fires before returning any stat, allowing
 * third-party code to modify or augment results.
 */
final class Shrikant_VT_Stats {

    // ── Aggregate totals ─────────────────────────────────────────────────────

    /**
     * Get total pageviews and unique visitors for a given date range.
     *
     * @param string      $from  Date string 'Y-m-d'.
     * @param string      $to    Date string 'Y-m-d'.
     * @param int|null    $page_id  Limit to a specific page (null = all pages).
     * @return array{pageviews:int,unique_visitors:int}
     */
    public function get_totals(
        string $from,
        string $to,
        ?int $page_id = null
    ): array {
        global $wpdb;

        $cache_key = 'sk_vt_totals_' . md5( $from . $to . (string) $page_id );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();

        $sql = $page_id !== null
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
            ? $wpdb->prepare(
                "SELECT COUNT(*) AS pageviews, SUM(is_unique) AS unique_visitors
                 FROM {$table}
                 WHERE visit_date BETWEEN %s AND %s AND page_id = %d",
                $from, $to, $page_id
            )
            : $wpdb->prepare(
                "SELECT COUNT(*) AS pageviews, SUM(is_unique) AS unique_visitors
                 FROM {$table}
                 WHERE visit_date BETWEEN %s AND %s",
                $from, $to
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $row = $wpdb->get_row( $sql, ARRAY_A );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $result = [
            'pageviews'       => (int) ( $row['pageviews']       ?? 0 ),
            'unique_visitors' => (int) ( $row['unique_visitors'] ?? 0 ),
        ];

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

        /**
         * Filter: shrikant_vt_get_stats
         * @param array  $result   Computed stats.
         * @param string $method   Calling method name.
         * @param array  $args     Method arguments.
         */
        return (array) apply_filters( 'shrikant_vt_get_stats', $result, 'get_totals', compact( 'from', 'to', 'page_id' ) );
    }

    // ── Period helpers (convenience wrappers) ─────────────────────────────────

    /** Stats for today. */
    /**
     * The dates a report should cover.
     *
     * Without a window this is the old behaviour -- N days back from today.
     * With one it is exactly the two dates given, which is what lets a report
     * cover a period that does not end today.
     *
     * @param int                     $days   Days back, when no window is given.
     * @param array<string,string>|null $window from/to as Y-m-d.
     * @return array{0:string,1:string}
     */
    private function resolve_window( int $days, ?array $window ): array {
        if ( is_array( $window ) && ! empty( $window['from'] ) && ! empty( $window['to'] ) ) {
            return [ (string) $window['from'], (string) $window['to'] ];
        }

        return [ gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ), gmdate( 'Y-m-d' ) ];
    }

    /**
     * Part of a cache key identifying the window.
     *
     * A cached report keyed only on the number of days would hand one custom
     * range's figures to another, since both are "custom".
     */
    private function window_key( int $days, ?array $window ): string {
        [ $from, $to ] = $this->resolve_window( $days, $window );

        return $from . '_' . $to;
    }

    public function today(): array {
        $today = gmdate( 'Y-m-d' );
        return $this->get_totals( $today, $today );
    }

    /** Stats for the last N days. */
    public function last_n_days( int $days ): array {
        return $this->get_totals(
            gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
            gmdate( 'Y-m-d' )
        );
    }

    /** Stats for the current month. */
    public function this_month(): array {
        return $this->get_totals(
            gmdate( 'Y-m-01' ),
            gmdate( 'Y-m-d' )
        );
    }

    /** Stats for the current year. */
    public function this_year(): array {
        return $this->get_totals(
            gmdate( 'Y-01-01' ),
            gmdate( 'Y-m-d' )
        );
    }

    // ── Time-series data ──────────────────────────────────────────────────────

    /**
     * Get daily pageviews and unique visitors for the last N days.
     * Returns one row per day (useful for chart.js / other charts).
     *
     * @param int $days Number of days to look back.
     * @return array<int,array{date:string,pageviews:int,unique_visitors:int}>
     */
    public function daily_series( int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = 'sk_vt_daily_series_' . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT visit_date AS date,
                    COUNT(*)          AS pageviews,
                    SUM(is_unique)    AS unique_visitors
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY visit_date
             ORDER BY visit_date ASC",
            $from, $to
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $result = array_map( static fn( array $r ) => [
            'date'            => $r['date'],
            'pageviews'       => (int) $r['pageviews'],
            'unique_visitors' => (int) $r['unique_visitors'],
        ], $rows );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * Hourly distribution for the last 24 hours.
     *
     * @return array<int,array{hour:int,pageviews:int}>
     */
    public function hourly_today(): array {
        global $wpdb;

        $cache_key = 'sk_vt_hourly_today_' . gmdate( 'YmdH' );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        $today = gmdate( 'Y-m-d' );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT visit_hour AS hour, COUNT(*) AS pageviews
             FROM {$table}
             WHERE visit_date = %s
             GROUP BY visit_hour
             ORDER BY visit_hour ASC",
            $today
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows   = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $result = array_map( static fn( array $r ) => [
            'hour'      => (int) $r['hour'],
            'pageviews' => (int) $r['pageviews'],
        ], $rows );

        set_transient( $cache_key, $result, MINUTE_IN_SECONDS );
        return $result;
    }

    // ── Top pages ─────────────────────────────────────────────────────────────

    /**
     * Top N pages by pageviews for the last N days.
     *
     * @param int $limit  Max rows to return.
     * @param int $days   Look-back window.
     * @return array<int,array{page_id:int,title:string,url:string,pageviews:int,unique_visitors:int}>
     */
    public function top_pages( int $limit = 10, int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = "sk_vt_top_pages_{$limit}_" . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT page_id,
                    COUNT(*)       AS pageviews,
                    SUM(is_unique) AS unique_visitors
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY page_id
             ORDER BY pageviews DESC
             LIMIT %d",
            $from, $to, $limit
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $result = array_map( static function ( array $r ): array {
            $pid   = (int) $r['page_id'];
            $title = $pid > 0 ? get_the_title( $pid ) : __( '(Home / Archive)', 'shrikant-visitor-tracker' );
            $url   = $pid > 0 ? get_permalink( $pid ) : home_url( '/' );
            return [
                'page_id'         => $pid,
                'title'           => $title ?: "(ID: {$pid})",
                'url'             => (string) $url,
                'pageviews'       => (int) $r['pageviews'],
                'unique_visitors' => (int) $r['unique_visitors'],
            ];
        }, $rows );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── Traffic sources ───────────────────────────────────────────────────────

    /**
     * Breakdown of traffic by referrer_type for a given period.
     *
     * @param int $days Look-back window.
     * @return array<string,int> e.g. ['direct'=>100,'search'=>50,...]
     */
    public function traffic_sources( int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = 'sk_vt_sources_' . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT referrer_type, COUNT(*) AS cnt
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY referrer_type",
            $from, $to
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows   = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['referrer_type'] ] = (int) $row['cnt'];
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── Countries ─────────────────────────────────────────────────────────────

    /**
     * Top N countries by pageviews.
     *
     * @param int $limit Max rows.
     * @param int $days  Look-back window.
     * @return array<int,array{country_code:string,pageviews:int}>
     */
    public function top_countries( int $limit = 10, int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = "sk_vt_countries_{$limit}_" . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT country_code, COUNT(*) AS pageviews
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s AND country_code != 'XX'
             GROUP BY country_code
             ORDER BY pageviews DESC
             LIMIT %d",
            $from, $to, $limit
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows   = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $result = array_map( static fn( array $r ) => [
            'country_code' => $r['country_code'],
            'pageviews'    => (int) $r['pageviews'],
        ], $rows );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── Devices ───────────────────────────────────────────────────────────────

    /**
     * Device-type breakdown.
     *
     * @param int $days Look-back window.
     * @return array<string,int> e.g. ['desktop'=>200,'mobile'=>150,'tablet'=>30]
     */
    public function device_breakdown( int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = 'sk_vt_devices_' . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT device_type, COUNT(*) AS cnt
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY device_type",
            $from, $to
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows   = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['device_type'] ] = (int) $row['cnt'];
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── Per-page time-series ──────────────────────────────────────────────────

    /**
     * Daily series for a specific page — used by the REST /page/{id} endpoint.
     *
     * @param int $days    Look-back window.
     * @param int $page_id WordPress post/page ID.
     * @return array<int,array{date:string,pageviews:int,unique_visitors:int}>
     */
    public function daily_series_for_page( int $days, int $page_id, ?array $window = null ): array {
        global $wpdb;

        $cache_key = "sk_vt_series_{$page_id}_" . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT visit_date AS date,
                    COUNT(*)          AS pageviews,
                    SUM(is_unique)    AS unique_visitors
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s AND page_id = %d
             GROUP BY visit_date
             ORDER BY visit_date ASC",
            $from, $to, $page_id
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $result = array_map( static fn( array $r ) => [
            'date'            => $r['date'],
            'pageviews'       => (int) $r['pageviews'],
            'unique_visitors' => (int) $r['unique_visitors'],
        ], $rows );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── Browser breakdown ─────────────────────────────────────────────────────

    /**
     * Browser breakdown for the given period.
     *
     * @param int $days Look-back window.
     * @return array<string,int> e.g. ['Chrome'=>150,'Firefox'=>40,...]
     */
    public function browser_breakdown( int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = 'sk_vt_browsers_' . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT browser, COUNT(*) AS cnt
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY browser
             ORDER BY cnt DESC",
            $from, $to
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows   = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['browser'] ] = (int) $row['cnt'];
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── OS breakdown ──────────────────────────────────────────────────────────

    /**
     * Operating system breakdown.
     *
     * @param int $days Look-back window.
     * @return array<string,int>
     */
    public function os_breakdown( int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = 'sk_vt_os_' . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT os, COUNT(*) AS cnt
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
             GROUP BY os
             ORDER BY cnt DESC",
            $from, $to
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows   = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        $result = [];
        foreach ( $rows as $row ) {
            $result[ $row['os'] ] = (int) $row['cnt'];
        }

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── UTM campaign report ───────────────────────────────────────────────────

    /**
     * Top UTM campaigns by pageviews.
     *
     * @param int $limit Max rows.
     * @param int $days  Look-back window.
     * @return array<int,array{source:string,medium:string,campaign:string,pageviews:int}>
     */
    public function utm_report( int $limit = 20, int $days = 30, ?array $window = null ): array {
        global $wpdb;

        $cache_key = "sk_vt_utm_{$limit}_" . $this->window_key( $days, $window );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();
        [ $from, $to ] = $this->resolve_window( $days, $window );

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $sql = $wpdb->prepare(
            "SELECT utm_source, utm_medium, utm_campaign, COUNT(*) AS pageviews
             FROM {$table}
             WHERE visit_date BETWEEN %s AND %s
               AND utm_source != ''
             GROUP BY utm_source, utm_medium, utm_campaign
             ORDER BY pageviews DESC
             LIMIT %d",
            $from, $to, $limit
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $result = array_map( static fn( array $r ) => [
            'source'    => $r['utm_source'],
            'medium'    => $r['utm_medium'],
            'campaign'  => $r['utm_campaign'],
            'pageviews' => (int) $r['pageviews'],
        ], $rows );

        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    // ── All-time totals ───────────────────────────────────────────────────────

    /**
     * All-time totals across the entire raw table.
     * Useful for the settings page or a "total since install" widget.
     *
     * @return array{pageviews:int,unique_visitors:int,first_visit:string,last_visit:string}
     */
    /**
     * All-time views for one page, tracked and imported kept apart.
     *
     * The summary table records every view once per dimension -- country,
     * device, browser, referrer and a 'total' row -- so SUM(pageviews) over a
     * page returns five times its real figure. Only the 'total' rows are read.
     *
     * @param int $page_id Post or page ID.
     * @return array{tracked:int, imported:int, total:int}
     */
    public function views_for_page( int $page_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . Shrikant_VT_TABLE_SUM;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $tracked = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(pageviews) FROM `{$table}`
                  WHERE page_id = %d AND dimension_key = 'total'",
                $page_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $imported = class_exists( 'Shrikant_VT_Import' ) ? Shrikant_VT_Import::views_for( $page_id ) : 0;

        return [
            'tracked'  => $tracked,
            'imported' => $imported,
            'total'    => $tracked + $imported,
        ];
    }

    public function all_time_totals(): array {
        global $wpdb;

        $cache_key = 'sk_vt_alltime';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $table = Shrikant_VT_DB::raw_table();

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder, and reports read the plugin's own tables by design.
        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS pageviews,
                    SUM(is_unique) AS unique_visitors,
                    MIN(visit_date) AS first_visit,
                    MAX(visit_date) AS last_visit
             FROM {$table}",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        $result = [
            'pageviews'       => (int) ( $row['pageviews']       ?? 0 ),
            'unique_visitors' => (int) ( $row['unique_visitors'] ?? 0 ),
            'first_visit'     => (string) ( $row['first_visit']  ?? '' ),
            'last_visit'      => (string) ( $row['last_visit']   ?? '' ),
        ];

        // Cache for 15 minutes — all-time number doesn't need to be real-time.
        set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    /** No hooks needed. */
    public function register_hooks(): void {}
}
