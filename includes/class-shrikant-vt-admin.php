<?php
/**
 * Admin screens — menu registration, page rendering, and shared UI parts.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Admin
 *
 * Renders six screens:
 *
 *  Dashboard      — stat tiles with period-on-period change, trend and hourly
 *                   charts, device/browser/OS and source breakdowns, countries,
 *                   top pages. One period control drives everything below the
 *                   tiles; the tiles themselves are fixed windows.
 *  Pages Report   — searchable, sortable table with a totals row.
 *  UTM Campaigns  — campaign table with a totals row.
 *  Import         — one card per source counter, with what it holds.
 *  Settings       — grouped into Tracking, Privacy and Data, with the one
 *                   destructive option kept apart from the rest.
 *  How it works   — the questions people actually ask, with a contents list.
 *
 * Presentation notes:
 * ───────────────────
 * • Icons are Dashicons and flags are derived from the ISO country code, so
 *   these screens load one stylesheet and request nothing else.
 * • Chart heights live in CSS, not in a canvas height attribute, so a chart
 *   keeps its proportions when the column narrows.
 * • Every figure is run through number_format_i18n() and every string through
 *   the text domain.
 *
 * Security: output escaped; forms carry nonces; every screen gates on
 * manage_options.
 */
final class Shrikant_VT_Admin {

    /** Periods offered by the segmented control, in days. */
    private const PERIODS = [ 7, 14, 30, 90, 365 ];

    /**
     * How many rows a report pulls before paging through them.
     *
     * Sorting and searching have to see the whole result to be correct -- sort
     * by title across a window that was already cut to one page and the second
     * page would carry on from the wrong place -- so the cut happens after.
     */
    private const REPORT_LIMIT = 500;

    public function __construct(
        private readonly Shrikant_VT_Stats    $stats,
        private readonly Shrikant_VT_Settings $settings,
        private readonly Shrikant_VT_Online   $online
    ) {}

    public function register_hooks(): void {
        add_action( 'admin_menu',               [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',    [ $this, 'enqueue_assets' ] );
        add_action( 'wp_dashboard_setup',       [ $this, 'register_dashboard_widget' ] );
        add_action( 'admin_post_sk_vt_import',  [ $this, 'handle_import' ] );
    }

    // ── WordPress Dashboard widget ────────────────────────────────────────────

    public function register_dashboard_widget(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'sk_vt_dashboard_widget',
            __( 'Shrikant Analytics — Quick Stats', 'shrikant-visitor-tracker' ),
            [ $this, 'render_dashboard_widget' ]
        );
    }

    /**
     * The widget sits on wp-admin/index.php, where this plugin's stylesheet is
     * not enqueued, so its handful of rules has to travel with it.
     */
    public function render_dashboard_widget(): void {
        $today  = $this->stats->today();
        $week   = $this->stats->last_n_days( 7 );
        $month  = $this->stats->this_month();
        $online = $this->online->get_count();
        $url    = admin_url( 'admin.php?page=shrikant-visitor-tracker' );
        ?>
        <style>
        #sk_vt_dashboard_widget .sk-vt-dw-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:12px}
        #sk_vt_dashboard_widget .sk-vt-dw-card{position:relative;background:#f6f7f7;border-radius:5px;padding:11px 13px;overflow:hidden}
        #sk_vt_dashboard_widget .sk-vt-dw-card::before{content:"";position:absolute;inset:0 0 auto 0;height:2px;background:#2271b1;opacity:.85}
        #sk_vt_dashboard_widget .sk-vt-dw-card h4{margin:0 0 3px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#787c82;font-weight:600}
        #sk_vt_dashboard_widget .sk-vt-dw-num{font-size:1.5rem;font-weight:600;color:#1d2327;line-height:1.15;font-variant-numeric:tabular-nums}
        #sk_vt_dashboard_widget .sk-vt-dw-sub{font-size:11px;color:#787c82}
        #sk_vt_dashboard_widget .sk-vt-dw-online::before{background:#00a32a}
        #sk_vt_dashboard_widget .sk-vt-dw-online .sk-vt-dw-num{color:#007017}
        #sk_vt_dashboard_widget .sk-vt-dw-foot{display:flex;justify-content:flex-end;margin:0}
        </style>
        <div class="sk-vt-dw-grid">
            <?php
            $cards = [
                [ __( 'Today', 'shrikant-visitor-tracker' ),       $today ],
                [ __( 'Last 7 Days', 'shrikant-visitor-tracker' ), $week  ],
                [ __( 'This Month', 'shrikant-visitor-tracker' ),  $month ],
            ];
            foreach ( $cards as [ $label, $data ] ) : ?>
            <div class="sk-vt-dw-card">
                <h4><?php echo esc_html( $label ); ?></h4>
                <div class="sk-vt-dw-num"><?php echo esc_html( number_format_i18n( $data['pageviews'] ) ); ?></div>
                <div class="sk-vt-dw-sub"><?php
                    /* translators: %s: number of distinct visitors. */
                    printf( esc_html__( 'from %s visitors', 'shrikant-visitor-tracker' ), esc_html( number_format_i18n( $data['unique_visitors'] ) ) );
                ?></div>
            </div>
            <?php endforeach; ?>
            <div class="sk-vt-dw-card sk-vt-dw-online">
                <h4><?php esc_html_e( 'Online Now', 'shrikant-visitor-tracker' ); ?></h4>
                <div class="sk-vt-dw-num sk-vt-online-num"><?php echo esc_html( number_format_i18n( $online ) ); ?></div>
                <div class="sk-vt-dw-sub"><?php esc_html_e( 'active visitors', 'shrikant-visitor-tracker' ); ?></div>
            </div>
        </div>
        <p class="sk-vt-dw-foot">
            <a href="<?php echo esc_url( $url ); ?>" class="button button-small">
                <?php esc_html_e( 'Open full dashboard', 'shrikant-visitor-tracker' ); ?>
            </a>
        </p>
        <?php
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_menu_page(
            __( 'Shrikant Visitor Tracker', 'shrikant-visitor-tracker' ),
            __( 'Shrikant Analytics', 'shrikant-visitor-tracker' ),
            'manage_options',
            'shrikant-visitor-tracker',
            [ $this, 'render_dashboard' ],
            'dashicons-chart-area',
            25
        );

        $pages = [
            [ 'shrikant-visitor-tracker',          __( 'Dashboard', 'shrikant-visitor-tracker' ),     __( 'Dashboard', 'shrikant-visitor-tracker' ),     'render_dashboard' ],
            [ 'shrikant-visitor-tracker-pages',    __( 'Pages Report', 'shrikant-visitor-tracker' ),  __( 'Pages', 'shrikant-visitor-tracker' ),         'render_pages' ],
            [ 'shrikant-visitor-tracker-utm',      __( 'UTM Campaigns', 'shrikant-visitor-tracker' ), __( 'UTM Campaigns', 'shrikant-visitor-tracker' ), 'render_utm' ],
            [ 'shrikant-visitor-tracker-import',   __( 'Import', 'shrikant-visitor-tracker' ),        __( 'Import', 'shrikant-visitor-tracker' ),        'render_import' ],
            [ 'shrikant-visitor-tracker-settings', __( 'Settings', 'shrikant-visitor-tracker' ),      __( 'Settings', 'shrikant-visitor-tracker' ),      'render_settings' ],
            [ 'shrikant-visitor-tracker-help',     __( 'How it works', 'shrikant-visitor-tracker' ),  __( 'How it works', 'shrikant-visitor-tracker' ),  'render_help' ],
        ];

        foreach ( $pages as [ $slug, $title, $label, $method ] ) {
            add_submenu_page( 'shrikant-visitor-tracker', $title, $label, 'manage_options', $slug, [ $this, $method ] );
        }
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'shrikant-visitor-tracker' ) ) {
            return;
        }

        wp_enqueue_style( 'dashicons' );

        // Bundled, not fetched: the directory does not allow a plugin to load
        // code from somewhere else at run time.
        wp_enqueue_script( 'shrikant-vt-chartjs', Shrikant_VT_URL . 'assets/js/chart.umd.min.js', [], '4.4.1', true );
        wp_enqueue_script( 'shrikant-vt-admin',   Shrikant_VT_URL . 'assets/js/admin.js', [ 'shrikant-vt-chartjs' ], Shrikant_VT_VERSION, true );
        wp_enqueue_style(  'shrikant-vt-admin',   Shrikant_VT_URL . 'assets/css/admin.css', [ 'dashicons' ], Shrikant_VT_VERSION );

        // The period control drives the charts too, so the data localised here
        // has to come from the same window the page is about to render.
        $window = $this->selected_window();
        $days   = $window['days'];
        $args   = $this->window_args( $window );

        $devices = [];
        foreach ( $this->stats->device_breakdown( $days, $args ) as $type => $count ) {
            $devices[] = [ 'device' => ucfirst( (string) $type ), 'count' => $count ];
        }

        $sources = [];
        foreach ( $this->stats->traffic_sources( $days, $args ) as $type => $count ) {
            $sources[] = [ 'type' => ucfirst( (string) $type ), 'count' => $count ];
        }

        $daily = $this->stats->daily_series( $days, $args );

        wp_localize_script( 'shrikant-vt-admin', 'skVtAdmin', [
            'restUrl'  => esc_url_raw( rest_url( 'sk-vt/v1/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'devices'  => $devices,
            'sources'  => $sources,
            'browsers' => $this->stats->browser_breakdown( $days, $args ),
            'osData'   => $this->stats->os_breakdown( $days, $args ),
            'hourly'   => $this->stats->hourly_today(),
            'trend'    => [
                'labels'    => array_column( $daily, 'date' ),
                'pageviews' => array_map( 'intval', array_column( $daily, 'pageviews' ) ),
                'unique'    => array_map( 'intval', array_column( $daily, 'unique_visitors' ) ),
            ],
            'labels'   => [
                'pageviews'  => __( 'Pageviews', 'shrikant-visitor-tracker' ),
                'unique'     => __( 'Unique Visitors', 'shrikant-visitor-tracker' ),
                'empty'      => __( 'Nothing recorded in this window.', 'shrikant-visitor-tracker' ),
                'emptyToday' => __( 'No visits yet today.', 'shrikant-visitor-tracker' ),
            ],
        ] );
    }

    /**
     * The reporting window.
     *
     * Either one of the preset periods, or an explicit pair of dates when both
     * are given and usable. A custom range is the only way to look at a period
     * that does not end today, which is what the presets cannot express.
     *
     * @return array{custom:bool,days:int,from:string,to:string,label:string}
     */
    private function selected_window(): array {
        $today = gmdate( 'Y-m-d' );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the dates for a read-only report, read out of the URL. They change nothing, so there is no form submission to tie a nonce to; each is accepted only if it is a real calendar date in Y-m-d.
        $from = isset( $_GET['from'] ) ? $this->as_date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
        $to   = isset( $_GET['to'] ) ? $this->as_date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';
        $days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 30;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( '' !== $from && '' !== $to ) {
            // Back to front is a slip, not a reason to show an empty report.
            if ( $from > $to ) {
                [ $from, $to ] = [ $to, $from ];
            }

            // Nothing has been recorded tomorrow, so do not offer to look.
            if ( $to > $today ) {
                $to = $today;
            }
            if ( $from > $today ) {
                $from = $today;
            }

            $span = (int) ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;

            return [
                'custom' => true,
                'days'   => max( 1, $span ),
                'from'   => $from,
                'to'     => $to,
                'label'  => sprintf(
                    /* translators: 1: start date, 2: end date. */
                    __( '%1$s to %2$s', 'shrikant-visitor-tracker' ),
                    $from,
                    $to
                ),
            ];
        }

        $days = in_array( $days, self::PERIODS, true ) ? $days : 30;

        return [
            'custom' => false,
            'days'   => $days,
            'from'   => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
            'to'     => $today,
            'label'  => sprintf(
                /* translators: %s: number of days in the reporting period. */
                _n( 'Last %s day', 'Last %s days', $days, 'shrikant-visitor-tracker' ),
                number_format_i18n( $days )
            ),
        ];
    }

    /**
     * A date, if the string really is one.
     *
     * Checked by round-tripping through DateTimeImmutable rather than by
     * pattern alone, so 2026-02-31 is rejected instead of silently becoming
     * the third of March.
     */
    private function as_date( string $value ): string {
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

        return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
    }

    /** What a report passes to the stats class. */
    private function window_args( array $window ): array {
        return [ 'from' => $window['from'], 'to' => $window['to'] ];
    }

    /** Query args that carry the chosen window from one link to the next. */
    private function window_query( array $window ): array {
        return $window['custom']
            ? [ 'from' => $window['from'], 'to' => $window['to'] ]
            : [ 'days' => $window['days'] ];
    }

    // ── Shared UI parts ───────────────────────────────────────────────────────

    /**
     * Page header: title, one line saying what the screen is for, and whatever
     * controls belong to it.
     */
    private function head( string $title, string $subtitle, ?callable $actions = null ): void {
        ?>
        <div class="sk-vt-head">
            <div class="sk-vt-head__text">
                <h1><?php echo esc_html( $title ); ?></h1>
                <?php if ( '' !== $subtitle ) : ?>
                    <p class="sk-vt-head__sub"><?php echo esc_html( $subtitle ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( $actions ) : ?>
                <div class="sk-vt-head__actions"><?php $actions(); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Segmented period control. Marked with aria-current rather than a
     * primary-button colour, so a screen reader is told which one is active
     * and not just shown it.
     */
    private function period_control( string $page_slug, array $window, array $keep = [] ): void {
        $labels = [
            7   => __( '7 days', 'shrikant-visitor-tracker' ),
            14  => __( '14 days', 'shrikant-visitor-tracker' ),
            30  => __( '30 days', 'shrikant-visitor-tracker' ),
            90  => __( '90 days', 'shrikant-visitor-tracker' ),
            365 => __( '1 year', 'shrikant-visitor-tracker' ),
        ];

        $today = gmdate( 'Y-m-d' );
        ?>
        <div class="sk-vt-period">
            <div class="sk-vt-seg" role="group" aria-label="<?php esc_attr_e( 'Reporting period', 'shrikant-visitor-tracker' ); ?>">
                <?php foreach ( self::PERIODS as $d ) :
                    $args = array_merge( $keep, [ 'page' => $page_slug, 'days' => $d ] );
                    unset( $args['from'], $args['to'], $args['paged'] );
                    $url = add_query_arg( $args, admin_url( 'admin.php' ) );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       <?php echo ( ! $window['custom'] && $window['days'] === $d ) ? 'aria-current="true"' : ''; ?>>
                        <?php echo esc_html( $labels[ $d ] ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php
            /*
             * A plain GET form, so the chosen range ends up in the URL like
             * every other filter here and the page can be bookmarked, shared
             * or reloaded without the dates being lost.
             */
            ?>
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
                  class="sk-vt-dates<?php echo $window['custom'] ? ' is-active' : ''; ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr( $page_slug ); ?>">
                <?php foreach ( $keep as $key => $value ) :
                    if ( in_array( $key, [ 'page', 'days', 'from', 'to', 'paged' ], true ) ) {
                        continue;
                    }
                    ?>
                    <input type="hidden" name="<?php echo esc_attr( (string) $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
                <?php endforeach; ?>

                <label for="sk-vt-from-<?php echo esc_attr( $page_slug ); ?>"><?php esc_html_e( 'From', 'shrikant-visitor-tracker' ); ?></label>
                <input type="date" id="sk-vt-from-<?php echo esc_attr( $page_slug ); ?>" name="from"
                       max="<?php echo esc_attr( $today ); ?>"
                       value="<?php echo esc_attr( $window['custom'] ? $window['from'] : '' ); ?>">

                <label for="sk-vt-to-<?php echo esc_attr( $page_slug ); ?>"><?php esc_html_e( 'to', 'shrikant-visitor-tracker' ); ?></label>
                <input type="date" id="sk-vt-to-<?php echo esc_attr( $page_slug ); ?>" name="to"
                       max="<?php echo esc_attr( $today ); ?>"
                       value="<?php echo esc_attr( $window['custom'] ? $window['to'] : '' ); ?>">

                <button type="submit" class="button"><?php esc_html_e( 'Apply', 'shrikant-visitor-tracker' ); ?></button>

                <?php if ( $window['custom'] ) :
                    $clear = array_merge( $keep, [ 'page' => $page_slug, 'days' => 30 ] );
                    unset( $clear['from'], $clear['to'], $clear['paged'] );
                    ?>
                    <a class="sk-vt-dates__clear" href="<?php echo esc_url( add_query_arg( $clear, admin_url( 'admin.php' ) ) ); ?>">
                        <?php esc_html_e( 'Clear', 'shrikant-visitor-tracker' ); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * Period-on-period change.
     *
     * Shown because the first thing anybody asks a dashboard is whether the
     * number went up, and a bare figure cannot answer that. The comparison is
     * always against the window of the same length immediately before, so it
     * is like for like; a period with nothing to compare against says so
     * rather than inventing a percentage.
     */
    private function delta( int $now, int $before ): void {
        if ( 0 === $before ) {
            if ( 0 === $now ) {
                printf(
                    '<span class="sk-vt-delta sk-vt-delta--flat">%s</span>',
                    esc_html__( 'no data', 'shrikant-visitor-tracker' )
                );
                return;
            }
            // Neutral, not green: there is nothing behind this window to have
            // risen from, and a green pill reads as growth that did not happen.
            printf(
                '<span class="sk-vt-delta sk-vt-delta--none">%s</span>',
                esc_html__( 'no earlier data', 'shrikant-visitor-tracker' )
            );
            return;
        }

        $pct   = ( $now - $before ) / $before * 100;
        $round = (int) round( abs( $pct ) );

        if ( 0 === $round ) {
            printf(
                '<span class="sk-vt-delta sk-vt-delta--flat"><span class="dashicons dashicons-minus" aria-hidden="true"></span>%s</span>',
                esc_html__( 'level', 'shrikant-visitor-tracker' )
            );
            return;
        }

        $up    = $pct > 0;
        $class = $up ? 'up' : 'down';
        $icon  = $up ? 'arrow-up-alt' : 'arrow-down-alt';

        printf(
            '<span class="sk-vt-delta sk-vt-delta--%1$s" title="%2$s"><span class="dashicons dashicons-%3$s" aria-hidden="true"></span>%4$s</span>',
            esc_attr( $class ),
            esc_attr(
                sprintf(
                    /* translators: %s: figure for the previous period of the same length. */
                    __( 'Previous period: %s', 'shrikant-visitor-tracker' ),
                    number_format_i18n( $before )
                )
            ),
            esc_attr( $icon ),
            esc_html( number_format_i18n( $round ) . '%' )
        );
    }

    /**
     * What a panel shows when it has nothing to show. A blank box reads as a
     * broken plugin; a sentence explaining why it is empty does not.
     */
    private function empty_state( string $icon, string $title, string $body ): void {
        ?>
        <div class="sk-vt-empty">
            <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
            <span class="sk-vt-empty__title"><?php echo esc_html( $title ); ?></span>
            <p><?php echo esc_html( $body ); ?></p>
        </div>
        <?php
    }

    /**
     * Turn an ISO 3166-1 alpha-2 code into its flag by offsetting each letter
     * into the regional indicator block. Computed, so there is no flag image
     * to ship and no sprite to request.
     */
    private function flag( string $code ): string {
        $code = strtoupper( trim( $code ) );

        if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
            return '';
        }

        $flag = '';
        foreach ( str_split( $code ) as $letter ) {
            $flag .= mb_chr( 0x1F1E6 + ( ord( $letter ) - 65 ), 'UTF-8' );
        }

        return $flag;
    }

    /**
     * The country's name when PHP's intl extension can give one, and the code
     * on its own when it cannot. Nothing is bundled for this: a 250-row table
     * of country names would be a translation burden for every locale, and
     * WordPress does not ship one to borrow.
     */
    private function country_name( string $code ): string {
        $code = strtoupper( trim( $code ) );

        if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
            return __( 'Unknown', 'shrikant-visitor-tracker' );
        }

        if ( class_exists( 'Locale' ) ) {
            $name = Locale::getDisplayRegion( '-' . $code, determine_locale() );
            if ( is_string( $name ) && '' !== $name && $name !== $code ) {
                return $name;
            }
        }

        return $code;
    }

    /**
     * Rows per page, read from the URL and checked against what the control
     * offers. 25 keeps a page short enough to scan.
     */
    private function per_page(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display preference for a read-only report, read out of the URL. It changes nothing, and is only accepted if it appears in the list below.
        $per = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 25;

        return in_array( $per, [ 25, 50, 100 ], true ) ? $per : 25;
    }

    /** The requested page number, before it is clamped to what exists. */
    private function current_page(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the page number for a read-only report, read out of the URL. It changes nothing and is clamped to the number of pages that exist.
        return max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
    }

    /**
     * Pagination strip.
     *
     * Built on core's paginate_links() rather than hand-rolled anchors, so the
     * markup, the keyboard behaviour and the screen-reader text are the ones
     * the rest of wp-admin already uses.
     */
    private function pagination( int $total, int $per_page, int $current, array $keep ): void {
        if ( $total <= $per_page ) {
            return;
        }

        $last  = (int) ceil( $total / $per_page );
        $first = ( ( $current - 1 ) * $per_page ) + 1;
        $to    = min( $total, $current * $per_page );

        $links = paginate_links( [
            'base'      => add_query_arg( array_merge( $keep, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
            'format'    => '',
            'total'     => $last,
            'current'   => $current,
            'mid_size'  => 2,
            'type'      => 'plain',
            'prev_text' => '&lsaquo; ' . __( 'Previous', 'shrikant-visitor-tracker' ),
            'next_text' => __( 'Next', 'shrikant-visitor-tracker' ) . ' &rsaquo;',
        ] );
        ?>
        <div class="sk-vt-tablenav">
            <span class="sk-vt-tablenav__count">
                <?php
                printf(
                    /* translators: 1: first row on this page, 2: last row on this page, 3: total rows. */
                    esc_html__( 'Showing %1$s–%2$s of %3$s', 'shrikant-visitor-tracker' ),
                    esc_html( number_format_i18n( $first ) ),
                    esc_html( number_format_i18n( $to ) ),
                    esc_html( number_format_i18n( $total ) )
                );
                ?>
            </span>
            <?php if ( $links ) : ?>
                <nav class="sk-vt-pages" aria-label="<?php esc_attr_e( 'Report pages', 'shrikant-visitor-tracker' ); ?>">
                    <?php echo wp_kses_post( $links ); ?>
                </nav>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The rows-per-page control that sits beside the pagination. */
    private function per_page_control( int $current, array $keep ): void {
        ?>
        <span class="sk-vt-perpage">
            <?php esc_html_e( 'Per page', 'shrikant-visitor-tracker' ); ?>
            <?php foreach ( [ 25, 50, 100 ] as $option ) :
                // Changing the page size invalidates the page number, so it goes.
                $args = array_merge( $keep, [ 'per_page' => $option ] );
                unset( $args['paged'] );
                ?>
                <a href="<?php echo esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ); ?>"
                   <?php echo $current === $option ? 'aria-current="true"' : ''; ?>>
                    <?php echo esc_html( number_format_i18n( $option ) ); ?>
                </a>
            <?php endforeach; ?>
        </span>
        <?php
    }

    /** Link to the CSV export for a window. */
    private function export_url( array $window ): string {
        return rest_url( 'sk-vt/v1/export?from=' . rawurlencode( $window['from'] ) . '&to=' . rawurlencode( $window['to'] ) );
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $window = $this->selected_window();
        $days   = $window['days'];
        $args   = $this->window_args( $window );

        $online    = $this->online->get_count();
        $alltime   = $this->stats->all_time_totals();
        $top_pages = $this->stats->top_pages( 10, $days, $args );
        $countries = $this->stats->top_countries( 10, $days, $args );

        $period_label = $window['label'];
        ?>
        <div class="wrap sk-vt">
            <?php
            $this->head(
                __( 'Visitor Analytics', 'shrikant-visitor-tracker' ),
                __( 'Counted in the reader\'s browser, so pages served from a cache are counted too. Known bots are filtered out before anything is recorded.', 'shrikant-visitor-tracker' ),
                function () use ( $window ) {
                    $this->period_control( 'shrikant-visitor-tracker', $window );
                    ?>
                    <a href="<?php echo esc_url( $this->export_url( $window ) ); ?>" class="button" download>
                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                        <?php esc_html_e( 'Export CSV', 'shrikant-visitor-tracker' ); ?>
                    </a>
                    <?php
                }
            );

            $this->render_stat_tiles( $alltime, $online );
            ?>

            <div class="sk-vt-grid sk-vt-grid--wide-left">
                <div class="sk-vt-card">
                    <div class="sk-vt-card__head">
                        <h2><?php esc_html_e( 'Traffic trend', 'shrikant-visitor-tracker' ); ?></h2>
                        <span class="sk-vt-card__hint"><?php echo esc_html( $period_label ); ?></span>
                    </div>
                    <div class="sk-vt-chart sk-vt-chart--line"><canvas id="sk-vt-trend-chart"></canvas></div>
                </div>
                <div class="sk-vt-card">
                    <div class="sk-vt-card__head">
                        <h2><?php esc_html_e( 'Hourly pattern', 'shrikant-visitor-tracker' ); ?></h2>
                        <span class="sk-vt-card__hint"><?php esc_html_e( 'Today, UTC', 'shrikant-visitor-tracker' ); ?></span>
                    </div>
                    <div class="sk-vt-chart sk-vt-chart--bar"><canvas id="sk-vt-hourly-chart"></canvas></div>
                </div>
            </div>

            <div class="sk-vt-grid sk-vt-grid--4">
                <?php
                /*
                 * The four breakdowns sit together because they answer the same
                 * shape of question. Traffic sources used to sit beside the
                 * countries table, which is twice as tall, so a third of that
                 * card was empty whatever the data.
                 */
                $breakdowns = [
                    [ 'sk-vt-devices-chart',  __( 'Devices', 'shrikant-visitor-tracker' ) ],
                    [ 'sk-vt-browsers-chart', __( 'Browsers', 'shrikant-visitor-tracker' ) ],
                    [ 'sk-vt-os-chart',       __( 'Operating systems', 'shrikant-visitor-tracker' ) ],
                    [ 'sk-vt-sources-chart',  __( 'Traffic sources', 'shrikant-visitor-tracker' ) ],
                ];
                foreach ( $breakdowns as [ $id, $label ] ) : ?>
                    <div class="sk-vt-card">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $label ); ?></h2></div>
                        <div class="sk-vt-chart sk-vt-chart--doughnut"><canvas id="<?php echo esc_attr( $id ); ?>"></canvas></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="sk-vt-grid sk-vt-grid--narrow-left">
                <div class="sk-vt-card sk-vt-card--flush">
                    <div class="sk-vt-card__head">
                        <h2><?php esc_html_e( 'Top countries', 'shrikant-visitor-tracker' ); ?></h2>
                        <span class="sk-vt-card__hint"><?php echo esc_html( $period_label ); ?></span>
                    </div>
                    <?php $this->render_countries_table( $countries ); ?>
                </div>

                <div class="sk-vt-card sk-vt-card--flush">
                    <div class="sk-vt-card__head">
                        <h2><?php esc_html_e( 'Top pages', 'shrikant-visitor-tracker' ); ?></h2>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( [ 'page' => 'shrikant-visitor-tracker-pages' ], $this->window_query( $window ) ), admin_url( 'admin.php' ) ) ); ?>">
                            <?php esc_html_e( 'Full pages report', 'shrikant-visitor-tracker' ); ?>
                        </a>
                    </div>
                    <?php $this->render_pages_table( $top_pages, false ); ?>
                </div>
            </div>
        </div>

        <?php
    }

    /**
     * The five fixed windows. These do not follow the period control: a tile
     * labelled "Today" has to mean today whatever the charts below are showing.
     */
    private function render_stat_tiles( array $alltime, int $online ): void {
        /*
         * Each tile names the window it reports and the window it is compared
         * with. The comparison windows are the same length as the ones being
         * reported, so the percentage is like for like -- last_n_days( 7 )
         * spans eight dates inclusive, so its comparison does too.
         */
        $tiles = [
            [
                'label' => __( 'Today', 'shrikant-visitor-tracker' ),
                'icon'  => 'calendar-alt',
                'now'   => $this->stats->today(),
                'prev'  => $this->stats->get_totals( gmdate( 'Y-m-d', strtotime( '-1 day' ) ), gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ),
                'note'  => __( 'vs yesterday', 'shrikant-visitor-tracker' ),
            ],
            [
                'label' => __( 'Last 7 days', 'shrikant-visitor-tracker' ),
                'icon'  => 'chart-bar',
                'now'   => $this->stats->last_n_days( 7 ),
                'prev'  => $this->stats->get_totals( gmdate( 'Y-m-d', strtotime( '-15 days' ) ), gmdate( 'Y-m-d', strtotime( '-8 days' ) ) ),
                'note'  => __( 'vs previous 7 days', 'shrikant-visitor-tracker' ),
            ],
            [
                'label' => __( 'This month', 'shrikant-visitor-tracker' ),
                'icon'  => 'calendar',
                'now'   => $this->stats->this_month(),
                'prev'  => $this->stats->get_totals(
                    gmdate( 'Y-m-01', strtotime( 'first day of last month' ) ),
                    gmdate( 'Y-m-d', strtotime( '-1 month' ) )
                ),
                'note'  => __( 'vs same days last month', 'shrikant-visitor-tracker' ),
            ],
            [
                'label' => __( 'All time', 'shrikant-visitor-tracker' ),
                'icon'  => 'database',
                'now'   => $alltime,
                'prev'  => null,
                'note'  => $alltime['first_visit']
                    ? sprintf(
                        /* translators: %s: date of the first recorded visit. */
                        __( 'since %s', 'shrikant-visitor-tracker' ),
                        $alltime['first_visit']
                    )
                    : __( 'no visits yet', 'shrikant-visitor-tracker' ),
            ],
        ];
        ?>
        <div class="sk-vt-stats">
            <?php foreach ( $tiles as $tile ) : ?>
            <div class="sk-vt-stat">
                <span class="sk-vt-stat__label">
                    <span class="dashicons dashicons-<?php echo esc_attr( $tile['icon'] ); ?>" aria-hidden="true"></span>
                    <?php echo esc_html( $tile['label'] ); ?>
                </span>
                <span class="sk-vt-stat__num"><?php echo esc_html( number_format_i18n( $tile['now']['pageviews'] ) ); ?></span>
                <span class="sk-vt-stat__unit"><?php esc_html_e( 'page views', 'shrikant-visitor-tracker' ); ?></span>
                <span class="sk-vt-stat__foot">
                    <?php if ( null !== $tile['prev'] ) : ?>
                        <?php $this->delta( (int) $tile['now']['pageviews'], (int) $tile['prev']['pageviews'] ); ?>
                    <?php endif; ?>
                    <span><?php echo esc_html( $tile['note'] ); ?></span>
                </span>
                <span class="sk-vt-stat__foot">
                    <?php
                    /* translators: %s: number of distinct visitors. */
                    printf( esc_html__( 'from %s visitors', 'shrikant-visitor-tracker' ), esc_html( number_format_i18n( $tile['now']['unique_visitors'] ) ) );
                    ?>
                </span>
            </div>
            <?php endforeach; ?>

            <div class="sk-vt-stat sk-vt-stat--live">
                <span class="sk-vt-stat__label">
                    <span class="sk-vt-dot" aria-hidden="true"></span>
                    <?php esc_html_e( 'Online now', 'shrikant-visitor-tracker' ); ?>
                </span>
                <span class="sk-vt-stat__num sk-vt-online-num"><?php echo esc_html( number_format_i18n( $online ) ); ?></span>
                <span class="sk-vt-stat__foot"><?php esc_html_e( 'active visitors', 'shrikant-visitor-tracker' ); ?></span>
                <span class="sk-vt-stat__foot"><?php esc_html_e( 'refreshes every 30 seconds', 'shrikant-visitor-tracker' ); ?></span>
            </div>
        </div>
        <?php
    }

    // ── Tables ────────────────────────────────────────────────────────────────

    /**
     * Top pages.
     *
     * @param array<int,array<string,mixed>> $pages   Rows to show on this page.
     * @param bool                           $totals  Add a totals row.
     * @param array<string,string>|null      $sort    Current orderby/order, when the headers are sortable.
     * @param array<string,mixed>            $keep    Query args the sort links must preserve.
     * @param int                            $offset  Rows before this page, so ranks keep counting.
     * @param array<string,int>|null         $overall Figures for the whole result, not just this page.
     */
    private function render_pages_table( array $pages, bool $totals, ?array $sort = null, array $keep = [], int $offset = 0, ?array $overall = null ): void {
        if ( empty( $pages ) ) {
            $this->empty_state(
                'media-document',
                __( 'No pages to report', 'shrikant-visitor-tracker' ),
                __( 'Nothing was viewed in this window. Try a longer period, or clear the search box.', 'shrikant-visitor-tracker' )
            );
            return;
        }

        /*
         * Share and bar length are measured against the whole result, not the
         * slice on screen. Measured per page, row 26 would restart at 100% and
         * the column would say something different on every page.
         */
        $sum_pv = $overall['pageviews'] ?? array_sum( array_column( $pages, 'pageviews' ) );
        $top_pv = $overall['max'] ?? max( array_column( $pages, 'pageviews' ) );
        ?>
        <p class="sk-vt-legend">
            <span><strong><?php esc_html_e( 'Views', 'shrikant-visitor-tracker' ); ?></strong> <?php esc_html_e( 'every time the page was opened', 'shrikant-visitor-tracker' ); ?></span>
            <span><strong><?php esc_html_e( 'Visitors', 'shrikant-visitor-tracker' ); ?></strong> <?php esc_html_e( 'how many people that was — one person reading twice is 2 views, 1 visitor', 'shrikant-visitor-tracker' ); ?></span>
            <span><strong><?php esc_html_e( 'Share', 'shrikant-visitor-tracker' ); ?></strong> <?php esc_html_e( 'this page\'s slice of all views in the period', 'shrikant-visitor-tracker' ); ?></span>
        </p>
        <table class="sk-vt-table">
            <thead>
                <tr>
                    <th class="sk-vt-rank" scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Rank', 'shrikant-visitor-tracker' ); ?></span>#</th>
                    <th scope="col"><?php $this->sort_header( 'title', __( 'Page', 'shrikant-visitor-tracker' ), $sort, $keep ); ?></th>
                    <th class="sk-vt-num" scope="col"><?php $this->sort_header( 'views', __( 'Views', 'shrikant-visitor-tracker' ), $sort, $keep ); ?></th>
                    <th class="sk-vt-num" scope="col"><?php $this->sort_header( 'unique', __( 'Visitors', 'shrikant-visitor-tracker' ), $sort, $keep ); ?></th>
                    <th scope="col" style="width:150px"><?php esc_html_e( 'Share', 'shrikant-visitor-tracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php $i = $offset; foreach ( $pages as $p ) : $i++;
                /*
                 * The bar is drawn against the busiest row rather than the
                 * total, because against a total every bar in a long tail is
                 * a hairline and the column says nothing. The percentage
                 * beside it is still the share of the total.
                 */
                $relative = $top_pv > 0 ? (float) $p['pageviews'] / $top_pv * 100 : 0.0;
                $share    = $sum_pv > 0 ? (float) $p['pageviews'] / $sum_pv * 100 : 0.0;
                ?>
                <tr>
                    <td class="sk-vt-rank"><?php echo esc_html( number_format_i18n( $i ) ); ?></td>
                    <td>
                        <a class="sk-vt-title" href="<?php echo esc_url( (string) $p['url'] ); ?>" target="_blank" rel="noopener noreferrer"
                           title="<?php echo esc_attr( (string) $p['title'] ); ?>">
                            <?php echo esc_html( (string) $p['title'] ); ?>
                        </a>
                        <span class="sk-vt-url"><?php echo esc_html( (string) $p['url'] ); ?></span>
                    </td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $p['pageviews'] ) ); ?></td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $p['unique_visitors'] ) ); ?></td>
                    <td>
                        <div class="sk-vt-share">
                            <span class="sk-vt-share__track">
                                <span class="sk-vt-share__fill" style="width:<?php echo esc_attr( (string) round( $relative, 2 ) ); ?>%"></span>
                            </span>
                            <span class="sk-vt-share__pct"><?php echo esc_html( number_format_i18n( round( $share, 1 ), 1 ) . '%' ); ?></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ( $totals ) : ?>
            <tfoot>
                <tr>
                    <td class="sk-vt-rank"></td>
                    <?php
                    $foot_count  = $overall['count'] ?? count( $pages );
                    $foot_unique = $overall['unique'] ?? array_sum( array_column( $pages, 'unique_visitors' ) );
                    ?>
                    <td><?php
                        printf(
                            /* translators: %s: number of pages in the whole report. */
                            esc_html( _n( '%s page in total', '%s pages in total', (int) $foot_count, 'shrikant-visitor-tracker' ) ),
                            esc_html( number_format_i18n( $foot_count ) )
                        );
                    ?></td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $sum_pv ) ); ?></td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $foot_unique ) ); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <?php
    }

    /**
     * A sortable column heading, or plain text when the table is not sortable.
     *
     * @param array<string,string>|null $sort Current orderby/order.
     * @param array<string,mixed>       $keep Query args to carry through.
     */
    private function sort_header( string $key, string $label, ?array $sort, array $keep ): void {
        if ( null === $sort ) {
            echo esc_html( $label );
            return;
        }

        $active = $sort['orderby'] === $key;
        $next   = ( $active && 'desc' === $sort['order'] ) ? 'asc' : 'desc';
        $url    = add_query_arg( array_merge( $keep, [ 'orderby' => $key, 'order' => $next ] ), admin_url( 'admin.php' ) );
        $icon   = $active
            ? ( 'desc' === $sort['order'] ? 'arrow-down' : 'arrow-up' )
            : 'sort';
        ?>
        <a href="<?php echo esc_url( $url ); ?>">
            <?php echo esc_html( $label ); ?>
            <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
        </a>
        <?php
    }

    /** Top countries, with the flag worked out from the code. */
    private function render_countries_table( array $countries ): void {
        if ( empty( $countries ) ) {
            $this->empty_state(
                'admin-site-alt3',
                __( 'No country data', 'shrikant-visitor-tracker' ),
                __( 'Country Detection may be switched off in Settings. When it is on, the anonymised IP is resolved to a country and nothing else leaves the server.', 'shrikant-visitor-tracker' )
            );
            return;
        }

        $total = array_sum( array_column( $countries, 'pageviews' ) );
        $top   = max( array_column( $countries, 'pageviews' ) );
        ?>
        <table class="sk-vt-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Country', 'shrikant-visitor-tracker' ); ?></th>
                    <th class="sk-vt-num" scope="col"><?php esc_html_e( 'Views', 'shrikant-visitor-tracker' ); ?></th>
                    <th scope="col" style="width:140px"><?php esc_html_e( 'Share of views', 'shrikant-visitor-tracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $countries as $c ) :
                $code     = (string) $c['country_code'];
                $flag     = $this->flag( $code );
                $relative = $top > 0 ? (float) $c['pageviews'] / $top * 100 : 0.0;
                $share    = $total > 0 ? (float) $c['pageviews'] / $total * 100 : 0.0;
                ?>
                <tr>
                    <td>
                        <span class="sk-vt-country">
                            <?php if ( '' !== $flag ) : ?>
                                <span class="sk-vt-flag" aria-hidden="true"><?php echo esc_html( $flag ); ?></span>
                            <?php endif; ?>
                            <span><?php echo esc_html( $this->country_name( $code ) ); ?></span>
                            <span class="sk-vt-code"><?php echo esc_html( $code ); ?></span>
                        </span>
                    </td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $c['pageviews'] ) ); ?></td>
                    <td>
                        <div class="sk-vt-share">
                            <span class="sk-vt-share__track">
                                <span class="sk-vt-share__fill" style="width:<?php echo esc_attr( (string) round( $relative, 2 ) ); ?>%"></span>
                            </span>
                            <span class="sk-vt-share__pct"><?php echo esc_html( number_format_i18n( round( $share, 1 ), 1 ) . '%' ); ?></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ── Pages Report ──────────────────────────────────────────────────────────

    public function render_pages(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $window = $this->selected_window();
        $days   = $window['days'];
        $pages  = $this->stats->top_pages( self::REPORT_LIMIT, $days, $this->window_args( $window ) );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the search term and sort order for a read-only report, read out of the URL. They change nothing, so there is no form submission to tie a nonce to; the term is sanitised and the sort key is checked against a fixed list.
        $search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'views';
        $order   = isset( $_GET['order'] ) && 'asc' === sanitize_key( wp_unslash( $_GET['order'] ) ) ? 'asc' : 'desc';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $orderby = in_array( $orderby, [ 'title', 'views', 'unique' ], true ) ? $orderby : 'views';
        $matched = $pages;

        if ( '' !== $search ) {
            $needle  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );
            $matched = array_values( array_filter(
                $pages,
                static function ( array $p ) use ( $needle ): bool {
                    $haystack = strtolower( (string) $p['title'] . ' ' . (string) $p['url'] );
                    return str_contains( $haystack, $needle );
                }
            ) );
        }

        $column = [ 'title' => 'title', 'views' => 'pageviews', 'unique' => 'unique_visitors' ][ $orderby ];

        usort(
            $matched,
            static function ( array $a, array $b ) use ( $column, $order ): int {
                $cmp = 'title' === $column
                    ? strnatcasecmp( (string) $a[ $column ], (string) $b[ $column ] )
                    : (int) $a[ $column ] <=> (int) $b[ $column ];

                return 'asc' === $order ? $cmp : -$cmp;
            }
        );

        $keep = array_merge( [ 'page' => 'shrikant-visitor-tracker-pages' ], $this->window_query( $window ) );
        if ( '' !== $search ) {
            $keep['s'] = $search;
        }
        $keep['orderby']  = $orderby;
        $keep['order']    = $order;
        $per_page         = $this->per_page();
        $keep['per_page'] = $per_page;

        $total    = count( $matched );

        /*
         * Figures for the whole result, worked out before the slice, so the
         * totals row and the share column describe the report rather than
         * whichever twenty-five rows happen to be on screen.
         */
        $overall = [
            'count'     => $total,
            'pageviews' => array_sum( array_column( $matched, 'pageviews' ) ),
            'unique'    => array_sum( array_column( $matched, 'unique_visitors' ) ),
            'max'       => $matched ? max( array_column( $matched, 'pageviews' ) ) : 0,
        ];

        // A page number past the end lands on the last page rather than on nothing.
        $last_page = max( 1, (int) ceil( $total / $per_page ) );
        $paged     = min( $this->current_page(), $last_page );
        $offset    = ( $paged - 1 ) * $per_page;
        $slice     = array_slice( $matched, $offset, $per_page );
        ?>
        <div class="wrap sk-vt">
            <?php
            $this->head(
                __( 'Pages Report', 'shrikant-visitor-tracker' ),
                __( 'The busiest pages in the window. Click a column heading to reorder, or search to narrow the list.', 'shrikant-visitor-tracker' ),
                function () use ( $window, $keep ) {
                    $this->period_control( 'shrikant-visitor-tracker-pages', $window, $keep );
                    ?>
                    <a href="<?php echo esc_url( $this->export_url( $window ) ); ?>" class="button" download>
                        <span class="dashicons dashicons-download" aria-hidden="true"></span>
                        <?php esc_html_e( 'Export CSV', 'shrikant-visitor-tracker' ); ?>
                    </a>
                    <?php
                }
            );
            ?>

            <div class="sk-vt-card sk-vt-card--flush">
                <div class="sk-vt-card__head">
                    <h2><?php
                        if ( '' === $search ) {
                            printf(
                                /* translators: %s: number of pages in the report. */
                                esc_html( _n( '%s page', '%s pages', $total, 'shrikant-visitor-tracker' ) ),
                                esc_html( number_format_i18n( $total ) )
                            );
                        } else {
                            printf(
                                /* translators: 1: number of matching pages, 2: the search term. */
                                esc_html( _n( '%1$s page matching &#8220;%2$s&#8221;', '%1$s pages matching &#8220;%2$s&#8221;', $total, 'shrikant-visitor-tracker' ) ),
                                esc_html( number_format_i18n( $total ) ),
                                esc_html( $search )
                            );
                        }
                    ?></h2>

                    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sk-vt-search">
                        <input type="hidden" name="page" value="shrikant-visitor-tracker-pages">
                        <?php
                        /*
                         * The window goes through as whatever it is -- a preset
                         * or a pair of dates. Emitting $days here instead sent
                         * the span of a custom range as a preset, so searching
                         * inside a range quietly moved you to a different one.
                         */
                        foreach ( $this->window_query( $window ) as $key => $value ) : ?>
                            <input type="hidden" name="<?php echo esc_attr( (string) $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
                        <input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
                        <input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>">
                        <label class="screen-reader-text" for="sk-vt-page-search"><?php esc_html_e( 'Search pages', 'shrikant-visitor-tracker' ); ?></label>
                        <input type="search" id="sk-vt-page-search" name="s" value="<?php echo esc_attr( $search ); ?>"
                               placeholder="<?php esc_attr_e( 'Search title or URL…', 'shrikant-visitor-tracker' ); ?>">
                        <button type="submit" class="button"><?php esc_html_e( 'Search', 'shrikant-visitor-tracker' ); ?></button>
                        <?php if ( '' !== $search ) : ?>
                            <a class="button-link" href="<?php echo esc_url( add_query_arg( array_merge( [ 'page' => 'shrikant-visitor-tracker-pages' ], $this->window_query( $window ), [ 'per_page' => $per_page ] ), admin_url( 'admin.php' ) ) ); ?>">
                                <?php esc_html_e( 'Clear', 'shrikant-visitor-tracker' ); ?>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php $this->render_pages_table( $slice, true, [ 'orderby' => $orderby, 'order' => $order ], $keep, $offset, $overall ); ?>

                <div class="sk-vt-tablefoot">
                    <?php
                    $this->pagination( $total, $per_page, $paged, $keep );
                    $this->per_page_control( $per_page, $keep );
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── UTM Campaigns ─────────────────────────────────────────────────────────

    public function render_utm(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $window = $this->selected_window();
        $days   = $window['days'];
        $report = $this->stats->utm_report( self::REPORT_LIMIT, $days, $this->window_args( $window ) );
        $total  = array_sum( array_column( $report, 'pageviews' ) );
        $top    = $report ? max( array_column( $report, 'pageviews' ) ) : 0;

        $keep = array_merge(
            [ 'page' => 'shrikant-visitor-tracker-utm' ],
            $this->window_query( $window ),
            [ 'per_page' => $this->per_page() ]
        );

        $per_page  = $this->per_page();
        $rows      = count( $report );
        $last_page = max( 1, (int) ceil( $rows / $per_page ) );
        $paged     = min( $this->current_page(), $last_page );
        $offset    = ( $paged - 1 ) * $per_page;
        $slice     = array_slice( $report, $offset, $per_page );
        ?>
        <div class="wrap sk-vt">
            <?php
            $this->head(
                __( 'UTM Campaigns', 'shrikant-visitor-tracker' ),
                __( 'Campaign tags are picked up automatically from the page URL, so a link you tagged elsewhere shows up here without any setup.', 'shrikant-visitor-tracker' ),
                function () use ( $window, $keep ) {
                    $this->period_control( 'shrikant-visitor-tracker-utm', $window, $keep );
                }
            );
            ?>

            <?php if ( empty( $report ) ) : ?>
                <div class="sk-vt-card">
                    <?php $this->empty_state(
                        'megaphone',
                        __( 'No tagged visits yet', 'shrikant-visitor-tracker' ),
                        __( 'Add utm_source, utm_medium and utm_campaign to a link you share, and the visits it brings will be listed here.', 'shrikant-visitor-tracker' )
                    ); ?>
                    <div class="sk-vt-note">
                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                        <p>
                            <?php esc_html_e( 'An example of a tagged link:', 'shrikant-visitor-tracker' ); ?>
                            <br><code><?php echo esc_html( home_url( '/?utm_source=newsletter&utm_medium=email&utm_campaign=launch' ) ); ?></code>
                        </p>
                    </div>
                </div>
            <?php else : ?>
                <div class="sk-vt-card sk-vt-card--flush">
                    <div class="sk-vt-card__head">
                        <h2><?php
                            printf(
                                /* translators: %s: number of campaign rows. */
                                esc_html( _n( '%s tagged combination', '%s tagged combinations', count( $report ), 'shrikant-visitor-tracker' ) ),
                                esc_html( number_format_i18n( count( $report ) ) )
                            );
                        ?></h2>
                        <span class="sk-vt-card__hint"><?php
                            printf(
                                /* translators: %s: total tagged pageviews. */
                                esc_html__( '%s tagged pageviews in this window', 'shrikant-visitor-tracker' ),
                                esc_html( number_format_i18n( $total ) )
                            );
                        ?></span>
                    </div>

                    <table class="sk-vt-table">
                        <thead>
                            <tr>
                                <th class="sk-vt-rank" scope="col">#</th>
                                <th scope="col"><?php esc_html_e( 'Source', 'shrikant-visitor-tracker' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Medium', 'shrikant-visitor-tracker' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Campaign', 'shrikant-visitor-tracker' ); ?></th>
                                <th class="sk-vt-num" scope="col"><?php esc_html_e( 'Pageviews', 'shrikant-visitor-tracker' ); ?></th>
                                <th scope="col" style="width:150px"><?php esc_html_e( 'Share', 'shrikant-visitor-tracker' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $i = $offset; foreach ( $slice as $row ) : $i++;
                            $relative = $top > 0 ? (float) $row['pageviews'] / $top * 100 : 0.0;
                            $share    = $total > 0 ? (float) $row['pageviews'] / $total * 100 : 0.0;
                            ?>
                            <tr>
                                <td class="sk-vt-rank"><?php echo esc_html( number_format_i18n( $i ) ); ?></td>
                                <td><?php echo esc_html( $row['source'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row['medium'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row['campaign'] ?: '—' ); ?></td>
                                <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $row['pageviews'] ) ); ?></td>
                                <td>
                                    <div class="sk-vt-share">
                                        <span class="sk-vt-share__track">
                                            <span class="sk-vt-share__fill" style="width:<?php echo esc_attr( (string) round( $relative, 2 ) ); ?>%"></span>
                                        </span>
                                        <span class="sk-vt-share__pct"><?php echo esc_html( number_format_i18n( round( $share, 1 ), 1 ) . '%' ); ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="sk-vt-rank"></td>
                                <td colspan="3"><?php esc_html_e( 'Total', 'shrikant-visitor-tracker' ); ?></td>
                                <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="sk-vt-tablefoot">
                        <?php
                        $this->pagination( $rows, $per_page, $paged, $keep );
                        $this->per_page_control( $per_page, $keep );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $alltime = $this->stats->all_time_totals();
        ?>
        <div class="wrap sk-vt">
            <?php
            $this->head(
                __( 'Settings', 'shrikant-visitor-tracker' ),
                __( 'What is recorded, what is kept, and what happens to it. Everything here is off-site-free unless Country Detection is on.', 'shrikant-visitor-tracker' )
            );

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- just the "saved" flag that handle_form_submit() put in the URL after it had already verified its own nonce.
            if ( ! empty( $_GET['updated'] ) ) :
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'shrikant-visitor-tracker' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="sk-vt-grid sk-vt-grid--wide-left">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sk-vt-settings-form">
                    <input type="hidden" name="action" value="sk_vt_save_settings">
                    <?php wp_nonce_field( 'sk_vt_settings_save', 'sk_vt_nonce' ); ?>

                    <fieldset class="sk-vt-card sk-vt-fieldset">
                        <legend><?php esc_html_e( 'Tracking', 'shrikant-visitor-tracker' ); ?></legend>
                        <p class="sk-vt-fieldset__sub"><?php esc_html_e( 'Whether visits are recorded at all, and how the request is made.', 'shrikant-visitor-tracker' ); ?></p>
                        <?php
                        $this->option(
                            'tracking_enabled',
                            __( 'Record visits', 'shrikant-visitor-tracker' ),
                            __( 'The master switch. Turn it off and nothing new is recorded; what has already been collected is left alone.', 'shrikant-visitor-tracker' ),
                            $this->settings->tracking_enabled()
                        );
                        $this->option(
                            'async_tracking',
                            __( 'Count in the browser', 'shrikant-visitor-tracker' ),
                            __( 'Sends one small request from the footer after the page has loaded, instead of counting while PHP builds the page. Leave this on if the site has any page cache — LiteSpeed, WP Rocket, W3 Total Cache or a CDN — because a cached page never runs PHP and would otherwise never be counted.', 'shrikant-visitor-tracker' ),
                            $this->settings->async_tracking()
                        );
                        $this->option(
                            'track_admins',
                            __( 'Include your own visits', 'shrikant-visitor-tracker' ),
                            __( 'Counts logged-in users who can manage the site. Off by default, so your own editing does not show up as traffic.', 'shrikant-visitor-tracker' ),
                            $this->settings->track_admins()
                        );
                        ?>
                    </fieldset>

                    <fieldset class="sk-vt-card sk-vt-fieldset">
                        <legend><?php esc_html_e( 'Show the count to readers', 'shrikant-visitor-tracker' ); ?></legend>
                        <p class="sk-vt-fieldset__sub"><?php esc_html_e( 'Adds a line like "Views: 1,234" at the end of the content. This is only about what visitors see — tracking carries on either way.', 'shrikant-visitor-tracker' ); ?></p>
                        <?php
                        $this->option(
                            'auto_display',
                            __( 'Add the count automatically', 'shrikant-visitor-tracker' ),
                            __( 'Turn this off to place the count yourself with the [sk_views] shortcode or a block, instead of having it appended.', 'shrikant-visitor-tracker' ),
                            $this->settings->auto_display()
                        );

                        $chosen = $this->settings->display_post_types();
                        $types  = get_post_types( [ 'public' => true ], 'objects' );
                        unset( $types['attachment'] );
                        ?>
                        <div class="sk-vt-field">
                            <span class="sk-vt-field__name"><?php esc_html_e( 'Show it on', 'shrikant-visitor-tracker' ); ?></span>
                            <div class="sk-vt-checks">
                                <?php foreach ( $types as $type ) :
                                    $id = 'sk-vt-pt-' . $type->name;
                                    ?>
                                    <label class="sk-vt-check" for="<?php echo esc_attr( $id ); ?>">
                                        <input type="checkbox" id="<?php echo esc_attr( $id ); ?>"
                                               name="display_post_types[]" value="<?php echo esc_attr( $type->name ); ?>"
                                               <?php checked( in_array( $type->name, $chosen, true ) ); ?>>
                                        <span><?php echo esc_html( $type->labels->name ); ?></span>
                                        <code><?php echo esc_html( $type->name ); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="sk-vt-field__desc"><?php esc_html_e( 'Every public post type on this site is listed, including ones added by your theme or other plugins. Tick none and the count is not added anywhere automatically.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </fieldset>

                    <fieldset class="sk-vt-card sk-vt-fieldset">
                        <legend><?php esc_html_e( 'Privacy', 'shrikant-visitor-tracker' ); ?></legend>
                        <p class="sk-vt-fieldset__sub"><?php esc_html_e( 'The raw IP address is never written to the database. These control what happens before that.', 'shrikant-visitor-tracker' ); ?></p>
                        <?php
                        $this->option(
                            'ip_anonymization',
                            __( 'Anonymise the IP address', 'shrikant-visitor-tracker' ),
                            __( 'Zeroes the last part of the address before it is used for anything. Worth leaving on: it is what makes the country lookup below defensible under GDPR.', 'shrikant-visitor-tracker' ),
                            $this->settings->ip_anonymization()
                        );
                        $this->option(
                            'respect_dnt',
                            __( 'Respect Do Not Track', 'shrikant-visitor-tracker' ),
                            __( 'Skips visitors whose browser sends the Do Not Track header. Those visits are not counted anywhere.', 'shrikant-visitor-tracker' ),
                            $this->settings->respect_dnt()
                        );
                        $this->option(
                            'geo_enabled',
                            __( 'Detect the country', 'shrikant-visitor-tracker' ),
                            __( 'Resolves the anonymised address to a country through ipwho.is, over https, with no API key and the result cached for at least a day. This is the only thing that ever leaves your server — turn it off and nothing does.', 'shrikant-visitor-tracker' ),
                            $this->settings->geo_enabled()
                        );
                        ?>
                    </fieldset>

                    <fieldset class="sk-vt-card sk-vt-fieldset">
                        <legend><?php esc_html_e( 'Data', 'shrikant-visitor-tracker' ); ?></legend>
                        <p class="sk-vt-fieldset__sub"><?php esc_html_e( 'Individual visits are rolled up into hourly summaries every hour. The summaries are what the reports read, and they are never deleted.', 'shrikant-visitor-tracker' ); ?></p>

                        <div class="sk-vt-field">
                            <label class="sk-vt-field__name" for="sk-vt-retention"><?php esc_html_e( 'Keep individual visits for', 'shrikant-visitor-tracker' ); ?></label>
                            <select name="retention_days" id="sk-vt-retention">
                                <?php
                                $retention = [
                                    30  => __( '30 days', 'shrikant-visitor-tracker' ),
                                    90  => __( '90 days', 'shrikant-visitor-tracker' ),
                                    180 => __( '6 months', 'shrikant-visitor-tracker' ),
                                    365 => __( '1 year', 'shrikant-visitor-tracker' ),
                                    730 => __( '2 years', 'shrikant-visitor-tracker' ),
                                ];
                                foreach ( $retention as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $this->settings->retention_days(), $value ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sk-vt-field__desc"><?php esc_html_e( 'Rows older than this are cleared each night. Your totals and charts are unaffected, because they read the summaries — only the ability to drill into a single old day is lost.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>

                        <div class="sk-vt-field">
                            <label class="sk-vt-field__name" for="sk-vt-online-ttl"><?php esc_html_e( 'Count someone as online for', 'shrikant-visitor-tracker' ); ?></label>
                            <select name="online_ttl" id="sk-vt-online-ttl">
                                <?php
                                $windows = [
                                    60  => __( '1 minute', 'shrikant-visitor-tracker' ),
                                    180 => __( '3 minutes', 'shrikant-visitor-tracker' ),
                                    300 => __( '5 minutes', 'shrikant-visitor-tracker' ),
                                    600 => __( '10 minutes', 'shrikant-visitor-tracker' ),
                                    900 => __( '15 minutes', 'shrikant-visitor-tracker' ),
                                ];
                                foreach ( $windows as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $this->settings->online_ttl(), $value ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="sk-vt-field__desc"><?php esc_html_e( 'How long after their last page view a visitor still counts towards "Online now".', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </fieldset>

                    <fieldset class="sk-vt-card sk-vt-fieldset">
                        <legend><?php esc_html_e( 'On deleting the plugin', 'shrikant-visitor-tracker' ); ?></legend>
                        <?php
                        $this->option(
                            'delete_data_on_uninstall',
                            __( 'Destroy all recorded data when the plugin is deleted', 'shrikant-visitor-tracker' ),
                            __( 'Off by default, and worth leaving off. Deleting a plugin from wp-admin is not always a goodbye — replacing a hand-installed copy with one from the directory goes through Delete as well, and with this switched on that would take every visit ever recorded with it. Leave it off and the data survives; you can always drop the tables by hand later.', 'shrikant-visitor-tracker' ),
                            $this->settings->delete_data_on_uninstall(),
                            true
                        );
                        ?>
                    </fieldset>

                    <div class="sk-vt-savebar">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'shrikant-visitor-tracker' ); ?></button>
                        <span class="sk-vt-savebar__note"><?php esc_html_e( 'Changes apply to new visits. Nothing already recorded is altered.', 'shrikant-visitor-tracker' ); ?></span>
                    </div>
                </form>

                <aside class="sk-vt-side">
                    <div class="sk-vt-card" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php esc_html_e( 'What is stored', 'shrikant-visitor-tracker' ); ?></h2></div>
                        <table class="sk-vt-defs">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Pageviews', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><strong><?php echo esc_html( number_format_i18n( $alltime['pageviews'] ) ); ?></strong></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Visitors', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><strong><?php echo esc_html( number_format_i18n( $alltime['unique_visitors'] ) ); ?></strong></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'First visit', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><strong><?php echo esc_html( $alltime['first_visit'] ?: '—' ); ?></strong></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Latest visit', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><strong><?php echo esc_html( $alltime['last_visit'] ?? '—' ); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="sk-vt-card">
                        <div class="sk-vt-card__head"><h2><?php esc_html_e( 'Reading the data elsewhere', 'shrikant-visitor-tracker' ); ?></h2></div>
                        <table class="sk-vt-defs">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'REST API', 'shrikant-visitor-tracker' ); ?></th>
                                    <td>
                                        <a href="<?php echo esc_url( rest_url( 'sk-vt/v1/summary' ) ); ?>" target="_blank" rel="noopener">
                                            <?php esc_html_e( 'summary endpoint', 'shrikant-visitor-tracker' ); ?>
                                        </a>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">WP-CLI</th>
                                    <td><code>wp sk-vt stats today</code></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="sk-vt-note">
                            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                            <p><?php esc_html_e( 'Both need an administrator login. Nothing about your visitors is readable without one.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * One checkbox setting.
     *
     * The label wraps the name only. When the explanation was inside the label
     * too, clicking anywhere in a paragraph toggled the setting — including the
     * paragraph belonging to the option that decides whether deleting the
     * plugin destroys every visit it ever recorded.
     */
    private function option( string $name, string $label, string $desc, bool $checked, bool $danger = false ): void {
        $id = 'sk-vt-opt-' . $name;
        ?>
        <div class="sk-vt-opt<?php echo $danger ? ' sk-vt-opt--danger' : ''; ?>">
            <?php
            /*
             * A switch is only paint. The control underneath is still an
             * ordinary checkbox with the same name, so it keeps its place in
             * the tab order, its label, and whatever a screen reader makes of
             * a checkbox -- none of which a div dressed as a toggle would.
             */
            ?>
            <span class="sk-vt-switch">
                <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"
                       <?php checked( $checked ); ?> aria-describedby="<?php echo esc_attr( $id . '-desc' ); ?>">
                <span class="sk-vt-switch__track" aria-hidden="true"></span>
            </span>
            <label class="sk-vt-opt__name" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <p class="sk-vt-opt__desc" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $desc ); ?></p>
        </div>
        <?php
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * Run an import, then send the admin back to the Import screen.
     */
    public function handle_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'shrikant-visitor-tracker' ) );
        }

        check_admin_referer( 'sk_vt_import' );

        $source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        $args   = [ 'page' => 'shrikant-visitor-tracker-import' ];

        if ( ! isset( Shrikant_VT_Import::sources()[ $source ] ) || ! Shrikant_VT_Import::available( $source ) ) {
            $args['shrikant_vt_error'] = 'missing';
        } else {
            $result                    = Shrikant_VT_Import::run( $source );
            $args['shrikant_vt_posts'] = $result['posts'];
            $args['shrikant_vt_views'] = $result['views'];
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Import screen.
     *
     * Exists because these counters are usually running on several sites and
     * WP-CLI is not always available on all of them — the same job the
     * `wp sk-vt import` command does, from a button.
     */
    public function render_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $log = Shrikant_VT_Import::log();
        ?>
        <div class="wrap sk-vt">
            <?php
            $this->head(
                __( 'Import', 'shrikant-visitor-tracker' ),
                __( 'Bring the totals across from a counter you already run, so that removing it does not take the history with it.', 'shrikant-visitor-tracker' )
            );

            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the counts handle_import() put in the URL after it had already verified its own nonce. Nothing here acts on them; they are cast to int and printed.
            if ( isset( $_GET['shrikant_vt_views'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                        printf(
                            /* translators: 1: number of views, 2: number of posts */
                            esc_html__( 'Imported %1$s views across %2$s posts. Those figures are stored here now and no longer depend on the plugin they came from.', 'shrikant-visitor-tracker' ),
                            '<strong>' . esc_html( number_format_i18n( (int) $_GET['shrikant_vt_views'] ) ) . '</strong>',
                            '<strong>' . esc_html( number_format_i18n( isset( $_GET['shrikant_vt_posts'] ) ? (int) $_GET['shrikant_vt_posts'] : 0 ) ) . '</strong>'
                        );
                    ?></p>
                </div>
            <?php elseif ( isset( $_GET['shrikant_vt_error'] ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'That plugin has no data on this site, so there was nothing to import.', 'shrikant-visitor-tracker' ); ?></p>
                </div>
            <?php endif; // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

            <div class="sk-vt-card" style="margin-bottom:16px">
                <div class="sk-vt-card__head"><h2><?php esc_html_e( 'How this works', 'shrikant-visitor-tracker' ); ?></h2></div>

                <?php
                /*
                 * Four steps rather than four paragraphs. The order is the
                 * part that matters -- importing after the old plugin is gone
                 * is too late, and that is the mistake this screen exists to
                 * prevent.
                 */
                $steps = [
                    [
                        'icon'  => 'search',
                        'title' => __( 'We read what is there', 'shrikant-visitor-tracker' ),
                        'body'  => __( 'Each counter below shows how much history it is holding. Nothing has been copied yet.', 'shrikant-visitor-tracker' ),
                    ],
                    [
                        'icon'  => 'download',
                        'title' => __( 'You press Import', 'shrikant-visitor-tracker' ),
                        'body'  => __( 'The totals are copied across, per post. The other plugin is only read from — nothing in it is changed or deleted.', 'shrikant-visitor-tracker' ),
                    ],
                    [
                        'icon'  => 'visibility',
                        'title' => __( 'Readers keep seeing their counts', 'shrikant-visitor-tracker' ),
                        'body'  => __( 'The number on a post becomes the imported total plus whatever this plugin records from now on, so no article appears to lose its history.', 'shrikant-visitor-tracker' ),
                    ],
                    [
                        'icon'  => 'trash',
                        'title' => __( 'Then the old plugin can go', 'shrikant-visitor-tracker' ),
                        'body'  => __( 'Check a post or two first. Once the numbers look right, the other plugin is no longer needed for them.', 'shrikant-visitor-tracker' ),
                    ],
                ];
                ?>
                <ol class="sk-vt-steps">
                    <?php foreach ( $steps as $step ) : ?>
                        <li>
                            <span class="sk-vt-steps__icon"><span class="dashicons dashicons-<?php echo esc_attr( $step['icon'] ); ?>" aria-hidden="true"></span></span>
                            <span class="sk-vt-steps__title"><?php echo esc_html( $step['title'] ); ?></span>
                            <span class="sk-vt-steps__body"><?php echo esc_html( $step['body'] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>

                <div class="sk-vt-note">
                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                    <p><?php esc_html_e( 'Safe to run more than once — each post\'s imported figure is replaced, never added to, so a second run cannot double anybody\'s history.', 'shrikant-visitor-tracker' ); ?></p>
                </div>

                <details class="sk-vt-details">
                    <summary><?php esc_html_e( 'Why the imported number is kept separate', 'shrikant-visitor-tracker' ); ?></summary>
                    <div class="sk-vt-prose">
                        <p><?php esc_html_e( 'Imported counts are stored apart from what this plugin records itself, and are never added into its own statistics. They are two different measurements: the imported total covers however long the other counter was running, under whatever it treated as a view, while this plugin counts only from the day it was switched on and leaves out the bots it recognises.', 'shrikant-visitor-tracker' ); ?></p>
                        <p><?php esc_html_e( 'So a reader sees the two added together and nothing appears lost, while the dashboard shows only what was actually tracked and stays comparable with itself.', 'shrikant-visitor-tracker' ); ?></p>
                    </div>
                </details>

                <p class="sk-vt-disclaimer">
                    <?php esc_html_e( 'The plugins named below are separate projects by their own authors. They are listed here only so their data can be read; this plugin is not affiliated with or endorsed by them.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <?php foreach ( Shrikant_VT_Import::sources() as $key => $source ) :
                $available = Shrikant_VT_Import::available( $key );
                $preview   = $available ? Shrikant_VT_Import::preview( $key ) : [ 'posts' => 0, 'views' => 0 ];
                $done      = $log[ $key ] ?? null;
                ?>
                <div class="sk-vt-card" style="margin-bottom:16px">
                    <div class="sk-vt-card__head">
                        <h2><?php echo esc_html( $source['label'] ); ?></h2>
                        <?php if ( ! $available ) : ?>
                            <span class="sk-vt-badge sk-vt-badge--muted">
                                <span class="dashicons dashicons-minus" aria-hidden="true"></span>
                                <?php esc_html_e( 'Nothing to import', 'shrikant-visitor-tracker' ); ?>
                            </span>
                        <?php elseif ( $done ) : ?>
                            <span class="sk-vt-badge sk-vt-badge--ok">
                                <span class="dashicons dashicons-yes" aria-hidden="true"></span>
                                <?php esc_html_e( 'Imported', 'shrikant-visitor-tracker' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="sk-vt-badge sk-vt-badge--info">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php esc_html_e( 'Ready to import', 'shrikant-visitor-tracker' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ( ! $available ) : ?>
                        <p class="sk-vt-opt__desc" style="margin:0"><?php esc_html_e( 'This counter has no data on this site, so there is nothing for it to hand over.', 'shrikant-visitor-tracker' ); ?></p>
                    <?php else : ?>
                        <dl class="sk-vt-source__figures">
                            <div class="sk-vt-source__figure">
                                <dt><?php esc_html_e( 'Views found', 'shrikant-visitor-tracker' ); ?></dt>
                                <dd><?php echo esc_html( number_format_i18n( $preview['views'] ) ); ?></dd>
                            </div>
                            <div class="sk-vt-source__figure">
                                <dt><?php esc_html_e( 'Across posts', 'shrikant-visitor-tracker' ); ?></dt>
                                <dd><?php echo esc_html( number_format_i18n( $preview['posts'] ) ); ?></dd>
                            </div>
                            <?php if ( $done ) : ?>
                            <div class="sk-vt-source__figure">
                                <dt><?php esc_html_e( 'Last imported', 'shrikant-visitor-tracker' ); ?></dt>
                                <dd style="font-size:13px;font-weight:500"><?php echo esc_html( (string) $done['at'] ); ?></dd>
                            </div>
                            <?php endif; ?>
                        </dl>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sk-vt-actions">
                            <input type="hidden" name="action" value="sk_vt_import">
                            <input type="hidden" name="source" value="<?php echo esc_attr( (string) $key ); ?>">
                            <?php wp_nonce_field( 'sk_vt_import' ); ?>
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-download" aria-hidden="true"></span>
                                <?php
                                echo $done
                                    ? esc_html__( 'Import again', 'shrikant-visitor-tracker' )
                                    : esc_html__( 'Import these counts', 'shrikant-visitor-tracker' );
                                ?>
                            </button>
                            <span class="sk-vt-actions__note">
                                <?php
                                echo $done
                                    ? esc_html__( 'Replaces the figures imported last time.', 'shrikant-visitor-tracker' )
                                    : esc_html__( 'Nothing is removed from the other plugin.', 'shrikant-visitor-tracker' );
                                ?>
                            </span>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ── How it works ──────────────────────────────────────────────────────────

    /**
     * Written for the person who installed this and now wants to know why its
     * numbers disagree with the counter they had before — which is the first
     * question everybody asks, and the one a feature list never answers.
     *
     * The contents list is here because this page is long by necessity: the
     * answers are explanations, not settings, and somebody arriving with one
     * specific question should not have to read the other six.
     */
    public function render_help(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $rest     = rest_url( 'sk-vt/v1/' );
        $sections = [
            'counting'    => __( 'How a visit is counted', 'shrikant-visitor-tracker' ),
            'words'       => __( 'Views, visitors and share', 'shrikant-visitor-tracker' ),
            'differences' => __( 'Why this figure differs from another counter\'s', 'shrikant-visitor-tracker' ),
            'display'     => __( 'Showing the count to readers', 'shrikant-visitor-tracker' ),
            'migrating'   => __( 'Coming from another counter', 'shrikant-visitor-tracker' ),
            'privacy'     => __( 'What is stored about a visitor', 'shrikant-visitor-tracker' ),
            'upkeep'      => __( 'Housekeeping', 'shrikant-visitor-tracker' ),
            'developers'  => __( 'For developers', 'shrikant-visitor-tracker' ),
        ];
        ?>
        <div class="wrap sk-vt">
            <?php
            $this->head(
                __( 'How this plugin works', 'shrikant-visitor-tracker' ),
                __( 'The questions people ask after installing it, answered in the order they usually come up.', 'shrikant-visitor-tracker' )
            );
            ?>

            <div class="sk-vt-grid" style="grid-template-columns:minmax(0,240px) minmax(0,1fr)">
                <nav class="sk-vt-card sk-vt-toc" aria-label="<?php esc_attr_e( 'On this page', 'shrikant-visitor-tracker' ); ?>">
                    <div class="sk-vt-card__head"><h2><?php esc_html_e( 'On this page', 'shrikant-visitor-tracker' ); ?></h2></div>
                    <ol>
                        <?php foreach ( $sections as $id => $label ) : ?>
                            <li><a href="#sk-vt-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a></li>
                        <?php endforeach; ?>
                    </ol>
                </nav>

                <div>
                    <div class="sk-vt-card" id="sk-vt-counting" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['counting'] ); ?></h2></div>
                        <div class="sk-vt-prose">
                            <p><?php esc_html_e( 'When a page finishes loading, a small script in the footer sends one request back to your site saying "this page was viewed". That is the whole mechanism.', 'shrikant-visitor-tracker' ); ?></p>
                            <p><?php esc_html_e( 'It works this way on purpose. Most view counters add up in PHP while the page is being built — which means that on a site with a page cache, a reader served from the cache is never counted at all, because PHP never ran. The counting here happens in the reader\'s browser, so a cached page is counted exactly like an uncached one.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-words" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['words'] ); ?></h2></div>
                        <table class="sk-vt-defs">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Views', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'Every time a page was opened. Open the same article twice and that is two views.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Visitors', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'How many different people those views came from, counted once each per day. Open the same article twice in a day and that is one visitor.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Share', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'That row\'s slice of all the views in the period you are looking at. The whole column adds up to 100%.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Online now', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'Visitors seen within the last few minutes. How few is set in Settings.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="sk-vt-note">
                            <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                            <p><?php esc_html_e( 'Views is always the larger of the two. If they are close, most people are reading one page and leaving.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-differences" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['differences'] ); ?></h2></div>
                        <div class="sk-vt-prose">
                            <p><?php esc_html_e( 'Two counters almost never agree, and usually neither is broken. The figure here starts on the day this plugin was switched on, counts once per page view from the reader\'s browser, and leaves out the bots it recognises. Another tool may have been running for years, may count differently, and may or may not filter crawlers — so the totals measure different things over different spans.', 'shrikant-visitor-tracker' ); ?></p>
                            <p><?php esc_html_e( 'Where a counter does its counting matters too. One that adds up in PHP while the page is built cannot see a reader served from a page cache, because PHP never runs for them; several counters, this one included, count from the browser instead to avoid that. If you are comparing two tools, it is worth checking which way each is configured before deciding one is wrong.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                        <div class="sk-vt-note">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <p><?php esc_html_e( 'If a figure here looks wrong, compare it with Search Console rather than with the old plugin. Clicks there and visits here should be in the same neighbourhood.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-display" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['display'] ); ?></h2></div>
                        <div class="sk-vt-prose">
                            <p><?php esc_html_e( 'Three ways — use whichever suits the theme.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                        <table class="sk-vt-defs">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Automatic', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'A line appears under every single post. Nothing to set up.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Shortcode', 'shrikant-visitor-tracker' ); ?></th>
                                    <td>
                                        <code>[sk_views]</code>
                                        <code>[sk_views id="12"]</code>
                                        <code>[sk_views label="Reads:"]</code>
                                        <code>[sk_views raw="yes"]</code>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'In a template', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><code>&lt;?php echo shrikant_vt_views(); ?&gt;</code></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="sk-vt-note">
                            <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                            <p>
                                <?php esc_html_e( 'To turn the automatic line off, add this to your theme:', 'shrikant-visitor-tracker' ); ?>
                                <br><code>add_filter( 'shrikant_vt_show_views', '__return_false' );</code>
                            </p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-migrating" style="margin-bottom:16px">
                        <div class="sk-vt-card__head">
                            <h2><?php echo esc_html( $sections['migrating'] ); ?></h2>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=shrikant-visitor-tracker-import' ) ); ?>">
                                <?php esc_html_e( 'Open Import', 'shrikant-visitor-tracker' ); ?>
                            </a>
                        </div>
                        <div class="sk-vt-prose">
                            <p><?php esc_html_e( 'The Import screen reads the totals out of Post Views Counter or WP-PostViews so that deleting them does not take the history with it. Import first, check the numbers appear, and only then remove the old plugin.', 'shrikant-visitor-tracker' ); ?></p>
                            <p><?php esc_html_e( 'Imported counts are kept apart from what this plugin records itself, and never added into its own statistics. The two measure different things, and mixing them would make both untrustworthy. A reader sees the sum; the dashboard shows what was actually tracked.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-privacy" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['privacy'] ); ?></h2></div>
                        <table class="sk-vt-defs">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Stored', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'Page, date and hour, a hashed visitor id, country, device, browser, OS, referrer and any UTM parameters.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Never stored', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'The raw IP address. It is anonymised before anything is done with it, and never written down.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Sent off-site', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><?php esc_html_e( 'Only the anonymised IP, only to resolve a country, only over https, and only when Country Detection is on. Turn it off and nothing leaves your server at all.', 'shrikant-visitor-tracker' ); ?></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="sk-vt-note">
                            <span class="dashicons dashicons-privacy" aria-hidden="true"></span>
                            <p><?php esc_html_e( 'Visitors sending Do Not Track are skipped. Tools → Export/Erase Personal Data works with this plugin.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-upkeep" style="margin-bottom:16px">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['upkeep'] ); ?></h2></div>
                        <div class="sk-vt-prose">
                            <p><?php esc_html_e( 'Individual visits are rolled up into hourly summaries once an hour, and the raw rows are cleared after the retention period set in Settings. The reports read the summaries, so they stay fast however much traffic the site gets.', 'shrikant-visitor-tracker' ); ?></p>
                        </div>
                        <div class="sk-vt-note sk-vt-note--warn">
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                            <p>
                                <strong><?php esc_html_e( 'Deleting this plugin does not delete its data', 'shrikant-visitor-tracker' ); ?></strong>
                                — <?php esc_html_e( 'unless you switch that on in Settings. Deleting is not always a goodbye; swapping one copy for another goes through the same button.', 'shrikant-visitor-tracker' ); ?>
                            </p>
                        </div>
                    </div>

                    <div class="sk-vt-card" id="sk-vt-developers">
                        <div class="sk-vt-card__head"><h2><?php echo esc_html( $sections['developers'] ); ?></h2></div>
                        <table class="sk-vt-defs">
                            <tbody>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'REST API', 'shrikant-visitor-tracker' ); ?></th>
                                    <td><code><?php echo esc_html( $rest ); ?></code></td>
                                </tr>
                                <tr>
                                    <th scope="row">WP-CLI</th>
                                    <td>
                                        <code>wp sk-vt stats today</code>
                                        <code>wp sk-vt top-pages</code>
                                        <code>wp sk-vt import --dry-run</code>
                                        <code>wp sk-vt export</code>
                                        <code>wp sk-vt cleanup --dry-run</code>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Filters', 'shrikant-visitor-tracker' ); ?></th>
                                    <td>
                                        <code>shrikant_vt_show_views</code>
                                        <code>shrikant_vt_views_label</code>
                                        <code>shrikant_vt_display_post_types</code>
                                        <code>shrikant_vt_default_settings</code>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Actions', 'shrikant-visitor-tracker' ); ?></th>
                                    <td>
                                        <code>shrikant_vt_before_track_visit</code>
                                        <code>shrikant_vt_after_insert</code>
                                        <code>shrikant_vt_after_cleanup</code>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
