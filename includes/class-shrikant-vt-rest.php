<?php
/**
 * REST API endpoints for Shrikant Visitor Tracker stats.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_REST
 *
 * Registers read-only REST endpoints under /wp-json/sk-vt/v1/.
 * All endpoints require the manage_options capability (admin only).
 *
 * Endpoints:
 * ──────────
 *   GET /sk-vt/v1/summary          → today/week/month/year totals + online count
 *   GET /sk-vt/v1/series           → daily pageview series (last N days, default 30)
 *   GET /sk-vt/v1/top-pages        → top pages by pageviews (last N days)
 *   GET /sk-vt/v1/traffic-sources  → referrer type breakdown
 *   GET /sk-vt/v1/devices          → device type breakdown
 *   GET /sk-vt/v1/countries        → top countries
 *   GET /sk-vt/v1/online           → currently online visitor count
 *   GET /sk-vt/v1/hourly           → hourly distribution for today
 *
 * These endpoints power:
 *  • The admin dashboard widgets (avoids full page reload for "online" card).
 *  • Potential Gutenberg/block dashboard panels.
 *  • External monitoring or custom reporting tools.
 *
 * Security:
 * ──────────
 * • permission_callback enforces manage_options on every route.
 * • All query params are sanitised via register_rest_route() args schema.
 * • Output is standard WP_REST_Response with proper HTTP codes.
 */
final class Shrikant_VT_REST {

    /** REST namespace. */
    private const NAMESPACE = 'sk-vt/v1';

    public function __construct(
        private readonly Shrikant_VT_Stats  $stats,
        private readonly Shrikant_VT_Online $online
    ) {}

    /**
     * Register REST routes.
     */
    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register all plugin REST routes.
     */
    public function register_routes(): void {
        $auth = [ $this, 'check_permission' ];

        // ── Summary totals ────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/summary', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_summary' ],
            'permission_callback' => $auth,
        ] );

        // ── Daily time-series ─────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/series', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_series' ],
            'permission_callback' => $auth,
            'args'                => [
                'days' => [
                    'default'           => 30,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn( $v ) => $v >= 1 && $v <= 365,
                ],
            ],
        ] );

        // ── Top pages ─────────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/top-pages', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_top_pages' ],
            'permission_callback' => $auth,
            'args'                => [
                'limit' => [
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn( $v ) => $v >= 1 && $v <= 100,
                ],
                'days' => [
                    'default'           => 30,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn( $v ) => $v >= 1 && $v <= 365,
                ],
            ],
        ] );

        // ── Traffic sources ───────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/traffic-sources', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_traffic_sources' ],
            'permission_callback' => $auth,
            'args'                => [
                'days' => [
                    'default'           => 30,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // ── Device breakdown ──────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/devices', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_devices' ],
            'permission_callback' => $auth,
            'args'                => [
                'days' => [
                    'default'           => 30,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // ── Countries ─────────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/countries', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_countries' ],
            'permission_callback' => $auth,
            'args'                => [
                'limit' => [
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                ],
                'days' => [
                    'default'           => 30,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // ── Browser breakdown ─────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/browsers', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_browsers' ],
            'permission_callback' => $auth,
            'args'                => [
                'days' => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // ── OS breakdown ──────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/os', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_os' ],
            'permission_callback' => $auth,
            'args'                => [
                'days' => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // ── UTM report ────────────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/utm', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_utm' ],
            'permission_callback' => $auth,
            'args'                => [
                'days'  => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
                'limit' => [ 'default' => 50, 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // ── CSV export (streams a download) ──────────────────────────────────
        register_rest_route( self::NAMESPACE, '/export', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'export_csv' ],
            'permission_callback' => $auth,
            'args'                => [
                'from' => [
                    'default'           => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ),
                ],
                'to' => [
                    'default'           => gmdate( 'Y-m-d' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ),
                ],
            ],
        ] );
        register_rest_route( self::NAMESPACE, '/online', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_online' ],
            'permission_callback' => $auth,
        ] );

        // ── Hourly distribution ───────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/hourly', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_hourly' ],
            'permission_callback' => $auth,
        ] );

        // ── Single-page stats ─────────────────────────────────────────────────
        register_rest_route( self::NAMESPACE, '/page/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_page_stats' ],
            'permission_callback' => $auth,
            'args'                => [
                'id' => [
                    'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
                'days' => [
                    'default'           => 30,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    // ── Callbacks ─────────────────────────────────────────────────────────────

    public function get_summary( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( [
            'today'          => $this->stats->today(),
            'last_7_days'    => $this->stats->last_n_days( 7 ),
            'this_month'     => $this->stats->this_month(),
            'this_year'      => $this->stats->this_year(),
            'online'         => $this->online->get_count(),
            'generated_at'   => gmdate( 'c' ),
        ] );
    }

    public function get_series( WP_REST_Request $request ): WP_REST_Response {
        $days = (int) $request->get_param( 'days' );
        return new WP_REST_Response( $this->stats->daily_series( $days ) );
    }

    public function get_top_pages( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            $this->stats->top_pages(
                (int) $request->get_param( 'limit' ),
                (int) $request->get_param( 'days' )
            )
        );
    }

    public function get_traffic_sources( WP_REST_Request $request ): WP_REST_Response {
        $days = (int) $request->get_param( 'days' );
        $raw  = $this->stats->traffic_sources( $days );

        // Return as array of objects for easier JSON consumption.
        $result = [];
        foreach ( $raw as $type => $count ) {
            $result[] = [ 'type' => $type, 'count' => $count ];
        }
        return new WP_REST_Response( $result );
    }

    public function get_devices( WP_REST_Request $request ): WP_REST_Response {
        $days = (int) $request->get_param( 'days' );
        $raw  = $this->stats->device_breakdown( $days );

        $result = [];
        foreach ( $raw as $type => $count ) {
            $result[] = [ 'device' => $type, 'count' => $count ];
        }
        return new WP_REST_Response( $result );
    }

    public function get_countries( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            $this->stats->top_countries(
                (int) $request->get_param( 'limit' ),
                (int) $request->get_param( 'days' )
            )
        );
    }

    public function get_online( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( [
            'count' => $this->online->get_count(),
            'at'    => gmdate( 'c' ),
        ] );
    }

    public function get_hourly( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( $this->stats->hourly_today() );
    }

    public function get_page_stats( WP_REST_Request $request ): WP_REST_Response {
        $page_id = (int) $request->get_param( 'id' );
        $days    = (int) $request->get_param( 'days' );
        $from    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
        $to      = gmdate( 'Y-m-d' );

        return new WP_REST_Response( [
            'page_id' => $page_id,
            'title'   => get_the_title( $page_id ),
            'url'     => get_permalink( $page_id ),
            'totals'  => $this->stats->get_totals( $from, $to, $page_id ),
            'series'  => $this->stats->daily_series_for_page( $days, $page_id ),
        ] );
    }

    public function get_browsers( WP_REST_Request $request ): WP_REST_Response {
        $days = (int) $request->get_param( 'days' );
        $raw  = $this->stats->browser_breakdown( $days );
        $result = [];
        foreach ( $raw as $browser => $count ) {
            $result[] = [ 'browser' => $browser, 'count' => $count ];
        }
        return new WP_REST_Response( $result );
    }

    public function get_os( WP_REST_Request $request ): WP_REST_Response {
        $days = (int) $request->get_param( 'days' );
        $raw  = $this->stats->os_breakdown( $days );
        $result = [];
        foreach ( $raw as $os => $count ) {
            $result[] = [ 'os' => $os, 'count' => $count ];
        }
        return new WP_REST_Response( $result );
    }

    public function get_utm( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            $this->stats->utm_report(
                (int) $request->get_param( 'limit' ),
                (int) $request->get_param( 'days' )
            )
        );
    }

    /**
     * Stream a CSV export of raw visit data directly to the browser.
     *
     * The WP REST router normally wraps callbacks in JSON, but we bypass
     * that by outputting CSV headers and fputcsv() content then calling exit.
     * On error we return a proper WP_REST_Response so the router handles it.
     *
     * @return WP_REST_Response|void  Returns error response or exits with CSV stream.
     */
    public function export_csv( WP_REST_Request $request ) {
        global $wpdb;

        $from  = sanitize_text_field( (string) $request->get_param( 'from' ) );
        $to    = sanitize_text_field( (string) $request->get_param( 'to' ) );

        // Secondary date format validation (route-level validate_callback already ran).
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid date format. Use Y-m-d.' ], 400 );
        }

        $table = Shrikant_VT_DB::raw_table();

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, visitor_id, page_id, visit_date, visit_hour, visit_time,
                        country_code, device_type, browser, os,
                        referrer_type, utm_source, utm_medium, utm_campaign,
                        utm_content, utm_term, is_unique, page_url
                 FROM {$table}
                 WHERE visit_date BETWEEN %s AND %s
                 ORDER BY id ASC
                 LIMIT 100000",
                $from, $to
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        if ( empty( $rows ) ) {
            return new WP_REST_Response( [ 'message' => 'No data for the given date range.' ], 200 );
        }

        // Sanitise filename to prevent header injection.
        $safe_from = preg_replace( '/[^0-9\-]/', '', $from );
        $safe_to   = preg_replace( '/[^0-9\-]/', '', $to );
        $filename  = 'sk-vt-export-' . $safe_from . '-to-' . $safe_to . '.csv';

        // Output CSV directly — bypasses REST JSON wrapping.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://output is a stream, not a file on disk; WP_Filesystem cannot write to it, and fputcsv is the only thing that quotes CSV fields correctly.
        $out = fopen( 'php://output', 'w' );
        if ( ! is_resource( $out ) ) {
            return new WP_REST_Response( [ 'error' => 'Could not open output stream.' ], 500 );
        }

        fputcsv( $out, array_keys( $rows[0] ) ); // Header row.
        foreach ( $rows as $row ) {
            fputcsv( $out, array_values( $row ) );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above, which is memory, not the filesystem.
        fclose( $out );
        exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    // ── Permission callback ───────────────────────────────────────────────────

    /**
     * All REST endpoints require admin-level access.
     */
    public function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }
}
