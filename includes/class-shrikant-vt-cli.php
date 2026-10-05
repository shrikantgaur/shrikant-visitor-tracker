<?php
/**
 * WP-CLI integration for Shrikant Visitor Tracker.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_CLI
 *
 * Provides WP-CLI commands under the `wp sk-vt` namespace.
 *
 * Usage examples:
 * ───────────────
 *   wp sk-vt stats today
 *   wp sk-vt stats week
 *   wp sk-vt stats month
 *   wp sk-vt stats year
 *   wp sk-vt stats range --from=2025-01-01 --to=2025-03-31
 *   wp sk-vt top-pages --limit=20 --days=30 --format=table
 *   wp sk-vt online
 *   wp sk-vt cleanup --dry-run
 *   wp sk-vt aggregate
 *   wp sk-vt reset --yes
 *   wp sk-vt export --from=2025-01-01 --to=2025-12-31 --format=csv
 *
 * Registration:
 * ─────────────
 * WP_CLI::add_command() is called only when WP-CLI is active to avoid
 * polluting the global namespace on web requests.
 */
final class Shrikant_VT_CLI {

    public function __construct(
        private readonly Shrikant_VT_Stats    $stats,
        private readonly Shrikant_VT_Online   $online,
        private readonly Shrikant_VT_Cron     $cron,
        private readonly Shrikant_VT_Settings $settings
    ) {}

    /**
     * Register the WP-CLI command group — only called when WP_CLI is defined.
     */
    public function register(): void {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        WP_CLI::add_command( 'sk-vt', $this );
    }

    /**
     * No WordPress hooks needed — CLI is registered via register().
     * Required by the bootstrap's register_hooks() loop.
     */
    public function register_hooks(): void {}

    // ── Commands ──────────────────────────────────────────────────────────────

    /**
     * Import historical view counts from another plugin before removing it.
     *
     * Counts are stored separately from this plugin's own tracking and are
     * never added to it. The two are different measurements: a PHP-based
     * counter misses every reader served from a page cache and counts the
     * crawlers that miss it, so blending the figures would make both useless.
     *
     * Safe to run twice — each post's imported figure is replaced, not added
     * to, so a second run cannot double anybody's history.
     *
     * ## OPTIONS
     *
     * [<source>]
     * : Which plugin to import from. Default: post-views-counter.
     *
     * [--dry-run]
     * : Report what would be imported without writing anything.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt import --dry-run
     *     wp sk-vt import post-views-counter
     *
     * @subcommand import
     */
    public function import( array $args, array $assoc_args ): void {
        $source = sanitize_key( $args[0] ?? 'post-views-counter' );

        if ( ! isset( Shrikant_VT_Import::sources()[ $source ] ) ) {
            WP_CLI::error( sprintf(
                'Unknown source "%s". Known: %s',
                $source,
                implode( ', ', array_keys( Shrikant_VT_Import::sources() ) )
            ) );
        }

        if ( ! Shrikant_VT_Import::available( $source ) ) {
            WP_CLI::error( sprintf( 'No data from %s found on this site.', $source ) );
        }

        $preview = Shrikant_VT_Import::preview( $source );

        if ( isset( $assoc_args['dry-run'] ) ) {
            WP_CLI::log( sprintf(
                'Would import %s views across %s posts from %s.',
                number_format_i18n( $preview['views'] ),
                number_format_i18n( $preview['posts'] ),
                $source
            ) );
            WP_CLI::log( 'Nothing was written. Drop --dry-run to import.' );
            return;
        }

        $result = Shrikant_VT_Import::run( $source );

        WP_CLI::success( sprintf(
            'Imported %s views across %s posts.',
            number_format_i18n( $result['views'] ),
            number_format_i18n( $result['posts'] )
        ) );

        if ( $result['skipped'] > 0 ) {
            WP_CLI::warning( sprintf(
                '%d counts belonged to posts that no longer exist and were dropped.',
                $result['skipped']
            ) );
        }

        WP_CLI::log( 'Stored as post meta. It is now safe to delete the source plugin.' );
    }

    /**
     * Display visitor statistics for a given period.
     *
     * ## OPTIONS
     *
     * <period>
     * : The reporting period. One of: today, yesterday, week, month, year.
     *
     * [--from=<date>]
     * : Start date (Y-m-d). Overrides <period> when combined with --to.
     *
     * [--to=<date>]
     * : End date (Y-m-d). Defaults to today.
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml). Default: table.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt stats today
     *     wp sk-vt stats month --format=json
     *     wp sk-vt stats range --from=2025-01-01 --to=2025-06-30
     *
     * @subcommand stats
     */
    public function stats( array $args, array $assoc_args ): void {
        $period = $args[0] ?? 'today';
        $format = $assoc_args['format'] ?? 'table';

        if ( isset( $assoc_args['from'] ) || 'range' === $period ) {
            $from = sanitize_text_field( $assoc_args['from'] ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
            $to   = sanitize_text_field( $assoc_args['to']   ?? gmdate( 'Y-m-d' ) );

            // Validate date format.
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
                WP_CLI::error( "Invalid --from date format. Use Y-m-d (e.g. 2025-01-01)." );
                return;
            }
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
                WP_CLI::error( "Invalid --to date format. Use Y-m-d (e.g. 2025-12-31)." );
                return;
            }

            $result = $this->stats->get_totals( $from, $to );
            $label  = "{$from} → {$to}";
        } else {
            [ $result, $label ] = match ( $period ) {
                'today'     => [ $this->stats->today(),          'Today (' . gmdate( 'Y-m-d' ) . ')' ],
                'yesterday' => [
                    $this->stats->get_totals(
                        gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
                        gmdate( 'Y-m-d', strtotime( '-1 day' ) )
                    ),
                    'Yesterday (' . gmdate( 'Y-m-d', strtotime( '-1 day' ) ) . ')',
                ],
                'week'      => [ $this->stats->last_n_days( 7 ),  'Last 7 days' ],
                'month'     => [ $this->stats->this_month(),      'This month' ],
                'year'      => [ $this->stats->this_year(),       'This year' ],
                default     => [ $this->stats->today(),           'Today (' . gmdate( 'Y-m-d' ) . ')' ],
            };
        }

        $rows = [
            [
                'Period'          => $label,
                'Pageviews'       => number_format( $result['pageviews'] ),
                'Unique Visitors' => number_format( $result['unique_visitors'] ),
            ],
        ];

        WP_CLI\Utils\format_items( $format, $rows, [ 'Period', 'Pageviews', 'Unique Visitors' ] );
    }

    /**
     * Show the top N pages by pageviews.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Number of pages to show. Default: 10.
     *
     * [--days=<n>]
     * : Look-back window in days. Default: 30.
     *
     * [--format=<format>]
     * : Output format (table, json, csv). Default: table.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt top-pages --limit=20 --days=7
     *
     * @subcommand top-pages
     */
    public function top_pages( array $args, array $assoc_args ): void {
        $limit  = (int) ( $assoc_args['limit'] ?? 10 );
        $days   = (int) ( $assoc_args['days']  ?? 30 );
        $format = $assoc_args['format'] ?? 'table';

        $pages = $this->stats->top_pages( $limit, $days );

        if ( empty( $pages ) ) {
            WP_CLI::warning( 'No data found for the given period.' );
            return;
        }

        $rows = array_map( static fn( array $p ) => [
            'ID'       => $p['page_id'],
            'Title'    => mb_strimwidth( $p['title'], 0, 50, '…' ),
            'Views'    => number_format( $p['pageviews'] ),
            'Unique'   => number_format( $p['unique_visitors'] ),
        ], $pages );

        WP_CLI\Utils\format_items( $format, $rows, [ 'ID', 'Title', 'Views', 'Unique' ] );
    }

    /**
     * Show the current number of online visitors.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt online
     *
     * @subcommand online
     */
    public function online( array $args, array $assoc_args ): void {
        $count = $this->online->get_count();
        WP_CLI::success( sprintf(
            /* translators: %d: visitor count */
            _n( '%d visitor currently online.', '%d visitors currently online.', $count, 'shrikant-visitor-tracker' ),
            $count
        ) );
    }

    /**
     * Run the data cleanup job immediately (delete rows older than retention period).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview how many rows would be deleted without actually deleting.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt cleanup
     *     wp sk-vt cleanup --dry-run
     *
     * @subcommand cleanup
     */
    public function cleanup( array $args, array $assoc_args ): void {
        global $wpdb;

        $dry_run        = isset( $assoc_args['dry-run'] );
        $retention_days = $this->settings->retention_days();
        $cutoff         = gmdate( 'Y-m-d', strtotime( "-{$retention_days} days" ) );
        $table          = Shrikant_VT_DB::raw_table();

        if ( $dry_run ) {
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder.
            $count = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE visit_date < %s", $cutoff )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
            WP_CLI::log( sprintf(
                'Dry run: %s rows would be deleted (cutoff: %s, retention: %d days).',
                number_format( $count ),
                $cutoff,
                $retention_days
            ) );
            return;
        }

        WP_CLI::log( "Running cleanup (cutoff: {$cutoff})…" );
        $this->cron->run_cleanup();
        WP_CLI::success( 'Cleanup completed.' );
    }

    /**
     * Run the hourly aggregation job immediately.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt aggregate
     *
     * @subcommand aggregate
     */
    public function aggregate( array $args, array $assoc_args ): void {
        WP_CLI::log( 'Running aggregation…' );
        $this->cron->run_aggregation();
        WP_CLI::success( 'Aggregation completed.' );
    }

    /**
     * Export raw visit data to CSV or JSON.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : Start date (Y-m-d). Default: 30 days ago.
     *
     * [--to=<date>]
     * : End date (Y-m-d). Default: today.
     *
     * [--format=<format>]
     * : Output format: csv or json. Default: csv.
     *
     * [--file=<path>]
     * : Write output to this file path. Default: stdout.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt export --from=2025-01-01 --to=2025-12-31 --file=visits.csv
     *     wp sk-vt export --format=json > visits.json
     *
     * @subcommand export
     */
    public function export( array $args, array $assoc_args ): void {
        global $wpdb;

        $from   = $assoc_args['from']   ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $to     = $assoc_args['to']     ?? gmdate( 'Y-m-d' );
        $format = $assoc_args['format'] ?? 'csv';
        $file   = $assoc_args['file']   ?? null;

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
                 ORDER BY id ASC",
                $from, $to
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

        if ( empty( $rows ) ) {
            WP_CLI::warning( 'No rows found for the given date range.' );
            return;
        }

        $output = match ( $format ) {
            'json'  => wp_json_encode( $rows, JSON_PRETTY_PRINT ),
            default => $this->to_csv( $rows ),
        };

        if ( $file ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
            file_put_contents( $file, $output );
            WP_CLI::success( sprintf( 'Exported %d rows to %s', count( $rows ), $file ) );
        } else {
            echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * Reset ALL analytics data (drops and recreates tables).
     * Requires --yes confirmation flag.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp sk-vt reset --yes
     *
     * @subcommand reset
     */
    public function reset( array $args, array $assoc_args ): void {
        WP_CLI::confirm(
            'This will permanently delete ALL analytics data. Are you sure?',
            $assoc_args
        );

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder.
        $wpdb->query( 'TRUNCATE TABLE ' . Shrikant_VT_DB::raw_table() );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- table name comes from Shrikant_VT_DB, never from input; every value is a placeholder.
        $wpdb->query( 'TRUNCATE TABLE ' . Shrikant_VT_DB::sum_table() );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
        // phpcs:enable
        delete_option( 'sk_vt_last_agg_id' );

        WP_CLI::success( 'All analytics data has been deleted.' );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Convert an array of associative arrays to a CSV string.
     *
     * @param array<int,array<string,string>> $rows
     * @return string CSV content.
     */
    private function to_csv( array $rows ): string {
        if ( empty( $rows ) ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is a stream, not a file on disk; WP_Filesystem cannot write to it, and fputcsv is the only thing that quotes CSV fields correctly.
        $buffer = fopen( 'php://temp', 'r+' );
        if ( ! is_resource( $buffer ) ) {
            return '';
        }

        fputcsv( $buffer, array_keys( $rows[0] ) ); // Header row.
        foreach ( $rows as $row ) {
            fputcsv( $buffer, array_values( $row ) );
        }

        rewind( $buffer );
        $csv = stream_get_contents( $buffer );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above, which is memory, not the filesystem.
        fclose( $buffer );

        return is_string( $csv ) ? $csv : '';
    }
}
