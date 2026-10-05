<?php
/**
 * Admin dashboard — menu registration, page rendering, and AJAX endpoints.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Admin
 *
 * Registers the admin menu and renders all dashboard views:
 *
 *  Dashboard  — Summary cards, 30-day trend, hourly chart, device/browser/OS
 *               doughnut charts, traffic sources doughnut, top countries, top pages.
 *  Pages      — Period-switchable full pages table with per-page JSON link.
 *  UTM        — UTM source/medium/campaign report table.
 *  Settings   — Plugin settings form + database overview.
 *
 * JavaScript:
 * ───────────
 * • Chart.js 4.4.1, bundled in assets/js/ (MIT, GPL-compatible).
 * • assets/js/admin.js handles doughnut/bar charts and live online counter
 *   polling via /sk-vt/v1/online REST endpoint every 30 seconds.
 * • All chart data inlined via wp_localize_script() — zero extra AJAX calls
 *   on dashboard load.
 *
 * Security: all output escaped; settings form uses nonce; capability gate.
 */
final class Shrikant_VT_Admin {

    public function __construct(
        private readonly Shrikant_VT_Stats    $stats,
        private readonly Shrikant_VT_Settings $settings,
        private readonly Shrikant_VT_Online   $online
    ) {}

    public function register_hooks(): void {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_dashboard_setup',    [ $this, 'register_dashboard_widget' ] );
        add_action( 'admin_post_sk_vt_import', [ $this, 'handle_import' ] );
    }

    /**
     * Register a compact stats widget on the WordPress main Dashboard (wp-admin/index.php).
     */
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
     * Render the compact Dashboard widget content.
     */
    public function render_dashboard_widget(): void {
        $today   = $this->stats->today();
        $week    = $this->stats->last_n_days( 7 );
        $month   = $this->stats->this_month();
        $online  = $this->online->get_count();
        $url     = admin_url( 'admin.php?page=shrikant-visitor-tracker' );
        ?>
        <style>
        #sk_vt_dashboard_widget .sk-vt-dw-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:10px}
        #sk_vt_dashboard_widget .sk-vt-dw-card{background:#f6f7f7;border-radius:3px;padding:10px 12px;text-align:center}
        #sk_vt_dashboard_widget .sk-vt-dw-card h4{margin:0 0 2px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#787c82}
        #sk_vt_dashboard_widget .sk-vt-dw-num{font-size:1.5rem;font-weight:700;color:#2271b1;line-height:1.1}
        #sk_vt_dashboard_widget .sk-vt-dw-sub{font-size:10px;color:#aaa}
        #sk_vt_dashboard_widget .sk-vt-dw-online .sk-vt-dw-num{color:#00a32a}
        </style>
        <div class="sk-vt-dw-grid">
            <?php
            $cards = [
                [ 'h' => __( 'Today', 'shrikant-visitor-tracker' ),      'pv' => $today['pageviews'],  'uv' => $today['unique_visitors'] ],
                [ 'h' => __( 'Last 7 Days', 'shrikant-visitor-tracker' ), 'pv' => $week['pageviews'],   'uv' => $week['unique_visitors'] ],
                [ 'h' => __( 'This Month', 'shrikant-visitor-tracker' ),  'pv' => $month['pageviews'],  'uv' => $month['unique_visitors'] ],
            ];
            foreach ( $cards as $c ) : ?>
            <div class="sk-vt-dw-card">
                <h4><?php echo esc_html( $c['h'] ); ?></h4>
                <div class="sk-vt-dw-num"><?php echo esc_html( number_format_i18n( $c['pv'] ) ); ?></div>
                <div class="sk-vt-dw-sub"><?php /* translators: %s: number of unique visitors. */
						printf( esc_html__( '%s unique', 'shrikant-visitor-tracker' ), esc_html( number_format_i18n( $c['uv'] ) ) ); ?></div>
            </div>
            <?php endforeach; ?>
            <div class="sk-vt-dw-card sk-vt-dw-online">
                <h4><?php esc_html_e( 'Online Now', 'shrikant-visitor-tracker' ); ?></h4>
                <div class="sk-vt-dw-num"><?php echo esc_html( (string) $online ); ?></div>
                <div class="sk-vt-dw-sub"><?php esc_html_e( 'active visitors', 'shrikant-visitor-tracker' ); ?></div>
            </div>
        </div>
        <p style="text-align:right;margin:0">
            <a href="<?php echo esc_url( $url ); ?>" class="button button-small">
                <?php esc_html_e( 'Full Dashboard →', 'shrikant-visitor-tracker' ); ?>
            </a>
        </p>
        <?php
    }

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
        add_submenu_page( 'shrikant-visitor-tracker', __( 'Dashboard', 'shrikant-visitor-tracker' ),    __( 'Dashboard', 'shrikant-visitor-tracker' ),    'manage_options', 'shrikant-visitor-tracker',          [ $this, 'render_dashboard' ] );
        add_submenu_page( 'shrikant-visitor-tracker', __( 'Pages Report', 'shrikant-visitor-tracker' ), __( 'Pages', 'shrikant-visitor-tracker' ),         'manage_options', 'shrikant-visitor-tracker-pages',    [ $this, 'render_pages' ] );
        add_submenu_page( 'shrikant-visitor-tracker', __( 'UTM Campaigns', 'shrikant-visitor-tracker' ),__( 'UTM Campaigns', 'shrikant-visitor-tracker' ), 'manage_options', 'shrikant-visitor-tracker-utm',      [ $this, 'render_utm' ] );
        add_submenu_page( 'shrikant-visitor-tracker', __( 'Import', 'shrikant-visitor-tracker' ),       __( 'Import', 'shrikant-visitor-tracker' ),        'manage_options', 'shrikant-visitor-tracker-import',   [ $this, 'render_import' ] );
        add_submenu_page( 'shrikant-visitor-tracker', __( 'Settings', 'shrikant-visitor-tracker' ),     __( 'Settings', 'shrikant-visitor-tracker' ),      'manage_options', 'shrikant-visitor-tracker-settings', [ $this, 'render_settings' ] );
        add_submenu_page( 'shrikant-visitor-tracker', __( 'How it works', 'shrikant-visitor-tracker' ),  __( 'How it works', 'shrikant-visitor-tracker' ),  'manage_options', 'shrikant-visitor-tracker-help',     [ $this, 'render_help' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'shrikant-visitor-tracker' ) ) {
            return;
        }

        // Bundled, not fetched: the directory does not allow a plugin to load
        // code from somewhere else at run time.
        wp_enqueue_script( 'shrikant-vt-chartjs', Shrikant_VT_URL . 'assets/js/chart.umd.min.js', [], '4.4.1', true );
        wp_enqueue_script( 'shrikant-vt-admin',   Shrikant_VT_URL . 'assets/js/admin.js', [ 'shrikant-vt-chartjs' ], Shrikant_VT_VERSION, true );
        wp_enqueue_style(  'shrikant-vt-admin',   Shrikant_VT_URL . 'assets/css/admin.css', [], Shrikant_VT_VERSION );

        // Inline all chart data so admin.js needs no extra AJAX on load.
        $devices_raw = $this->stats->device_breakdown( 30 );
        $devices     = [];
        foreach ( $devices_raw as $type => $count ) {
            $devices[] = [ 'device' => ucfirst( $type ), 'count' => $count ];
        }

        $sources_raw = $this->stats->traffic_sources( 30 );
        $sources     = [];
        foreach ( $sources_raw as $type => $count ) {
            $sources[] = [ 'type' => ucfirst( $type ), 'count' => $count ];
        }

        wp_localize_script( 'shrikant-vt-admin', 'skVtAdmin', [
            'restUrl' => esc_url_raw( rest_url( 'sk-vt/v1/' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'devices' => $devices,
            'sources' => $sources,
            'browsers'=> $this->stats->browser_breakdown( 30 ),
            'osData'  => $this->stats->os_breakdown( 30 ),
            'hourly'  => $this->stats->hourly_today(),
            'labels'  => [
                'pageviews' => __( 'Pageviews', 'shrikant-visitor-tracker' ),
                'unique'    => __( 'Unique Visitors', 'shrikant-visitor-tracker' ),
            ],
        ] );
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $today        = $this->stats->today();
        $week         = $this->stats->last_n_days( 7 );
        $month        = $this->stats->this_month();
        $alltime      = $this->stats->all_time_totals();
        $online_count = $this->online->get_count();
        $top_pages    = $this->stats->top_pages( 10, 30 );
        $countries    = $this->stats->top_countries( 10, 30 );
        $daily        = $this->stats->daily_series( 30 );

        $chart_labels = wp_json_encode( array_column( $daily, 'date' ) );
        $chart_pv     = wp_json_encode( array_column( $daily, 'pageviews' ) );
        $chart_uv     = wp_json_encode( array_column( $daily, 'unique_visitors' ) );
        ?>
        <div class="wrap shrikant-vt-dashboard">
            <h1><?php esc_html_e( 'Shrikant Visitor Tracker', 'shrikant-visitor-tracker' ); ?></h1>

            <?php $this->render_stat_cards( $today, $week, $month, $alltime, $online_count ); ?>

            <div class="sk-vt-row sk-vt-row-2">
                <div class="sk-vt-card">
                    <h2><?php esc_html_e( 'Last 30 Days', 'shrikant-visitor-tracker' ); ?></h2>
                    <canvas id="sk-vt-trend-chart" height="90"></canvas>
                </div>
                <div class="sk-vt-card">
                    <h2><?php esc_html_e( "Today's Hourly Distribution", 'shrikant-visitor-tracker' ); ?></h2>
                    <canvas id="sk-vt-hourly-chart" height="90"></canvas>
                </div>
            </div>

            <div class="sk-vt-row sk-vt-row-3">
                <div class="sk-vt-card"><h2><?php esc_html_e( 'Devices', 'shrikant-visitor-tracker' ); ?></h2><canvas id="sk-vt-devices-chart"></canvas></div>
                <div class="sk-vt-card"><h2><?php esc_html_e( 'Browsers', 'shrikant-visitor-tracker' ); ?></h2><canvas id="sk-vt-browsers-chart"></canvas></div>
                <div class="sk-vt-card"><h2><?php esc_html_e( 'Operating Systems', 'shrikant-visitor-tracker' ); ?></h2><canvas id="sk-vt-os-chart"></canvas></div>
            </div>

            <div class="sk-vt-row sk-vt-row-2">
                <div class="sk-vt-card">
                    <h2><?php esc_html_e( 'Traffic Sources (30d)', 'shrikant-visitor-tracker' ); ?></h2>
                    <canvas id="sk-vt-sources-chart"></canvas>
                </div>
                <div class="sk-vt-card">
                    <h2><?php esc_html_e( 'Top Countries (30d)', 'shrikant-visitor-tracker' ); ?></h2>
                    <?php $this->render_countries_table( $countries ); ?>
                </div>
            </div>

            <div class="sk-vt-row">
                <div class="sk-vt-card">
                    <h2>
                        <?php esc_html_e( 'Top Pages (30d)', 'shrikant-visitor-tracker' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=shrikant-visitor-tracker-pages' ) ); ?>" class="sk-vt-see-all">
                            <?php esc_html_e( 'All pages →', 'shrikant-visitor-tracker' ); ?>
                        </a>
                    </h2>
                    <?php $this->render_top_pages_table( $top_pages ); ?>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var ctx=document.getElementById('sk-vt-trend-chart');
            if(!ctx)return;
            new Chart(ctx,{
                type:'line',
                data:{
                    labels:<?php echo $chart_labels; // phpcs:ignore WordPress.Security.EscapeOutput ?>,
                    datasets:[
                        {label:'<?php echo esc_js(__('Pageviews','shrikant-visitor-tracker')); ?>',data:<?php echo $chart_pv; // phpcs:ignore WordPress.Security.EscapeOutput ?>,borderColor:'#2271b1',backgroundColor:'rgba(34,113,177,0.1)',fill:true,tension:0.35,pointRadius:2},
                        {label:'<?php echo esc_js(__('Unique Visitors','shrikant-visitor-tracker')); ?>',data:<?php echo $chart_uv; // phpcs:ignore WordPress.Security.EscapeOutput ?>,borderColor:'#d63638',backgroundColor:'rgba(214,54,56,0.08)',fill:true,tension:0.35,pointRadius:2}
                    ]
                },
                options:{responsive:true,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
            });
        })();
        </script>
        <?php
    }

    private function render_stat_cards( array $today, array $week, array $month, array $alltime, int $online ): void {
        $cards = [
            [ 'label' => __( 'Today',       'shrikant-visitor-tracker' ), 'data' => $today   ],
            [ 'label' => __( 'Last 7 Days', 'shrikant-visitor-tracker' ), 'data' => $week    ],
            [ 'label' => __( 'This Month',  'shrikant-visitor-tracker' ), 'data' => $month   ],
            [ 'label' => __( 'All Time',    'shrikant-visitor-tracker' ), 'data' => $alltime ],
        ];
        ?>
        <div class="sk-vt-cards">
            <?php foreach ( $cards as $card ) : ?>
            <div class="sk-vt-card sk-vt-stat-card">
                <h3><?php echo esc_html( $card['label'] ); ?></h3>
                <div class="sk-vt-stat-num"><?php echo esc_html( number_format_i18n( $card['data']['pageviews'] ) ); ?></div>
                <div class="sk-vt-stat-sub">
                    <?php /* translators: %s: number of unique visitors. */
						printf( esc_html__( '%s unique visitors', 'shrikant-visitor-tracker' ), esc_html( number_format_i18n( $card['data']['unique_visitors'] ) ) ); ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="sk-vt-card sk-vt-stat-card sk-vt-online-card">
                <h3><?php esc_html_e( 'Online Now', 'shrikant-visitor-tracker' ); ?></h3>
                <div class="sk-vt-stat-num sk-vt-online-num"><?php echo esc_html( (string) $online ); ?></div>
                <div class="sk-vt-stat-sub"><?php esc_html_e( 'active visitors', 'shrikant-visitor-tracker' ); ?></div>
            </div>
        </div>
        <?php
    }

    private function render_top_pages_table( array $pages ): void {
        if ( empty( $pages ) ) {
            echo '<p>' . esc_html__( 'No data yet.', 'shrikant-visitor-tracker' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat striped sk-vt-table">
            <thead><tr>
                <th><?php esc_html_e( 'Page', 'shrikant-visitor-tracker' ); ?></th>
                <th class="sk-vt-num"><?php esc_html_e( 'Views', 'shrikant-visitor-tracker' ); ?></th>
                <th class="sk-vt-num"><?php esc_html_e( 'Unique', 'shrikant-visitor-tracker' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $pages as $p ) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( (string) $p['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo esc_html( $p['title'] ); ?>
                        </a>
                        <div class="sk-vt-url-preview"><?php echo esc_html( (string) $p['url'] ); ?></div>
                    </td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $p['pageviews'] ) ); ?></td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $p['unique_visitors'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_countries_table( array $countries ): void {
        if ( empty( $countries ) ) {
            echo '<p>' . esc_html__( 'No geo data yet.', 'shrikant-visitor-tracker' ) . '</p>';
            return;
        }
        $total = array_sum( array_column( $countries, 'pageviews' ) );
        ?>
        <table class="wp-list-table widefat striped sk-vt-table">
            <thead><tr>
                <th><?php esc_html_e( 'Country', 'shrikant-visitor-tracker' ); ?></th>
                <th class="sk-vt-num"><?php esc_html_e( 'Views', 'shrikant-visitor-tracker' ); ?></th>
                <th style="width:120px"><?php esc_html_e( 'Share', 'shrikant-visitor-tracker' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $countries as $c ) :
                $pct = $total > 0 ? round( $c['pageviews'] / $total * 100, 1 ) : 0;
            ?>
                <tr>
                    <td><?php echo esc_html( $c['country_code'] ); ?></td>
                    <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $c['pageviews'] ) ); ?></td>
                    <td>
                        <div class="sk-vt-bar-wrap">
                            <div class="sk-vt-bar-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></div>
                            <span class="sk-vt-bar-label"><?php echo esc_html( $pct . '%' ); ?></span>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ── Pages sub-page ────────────────────────────────────────────────────────

    public function render_pages(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $days  = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 30;
        $days  = in_array( $days, [ 7, 14, 30, 90, 365 ], true ) ? $days : 30;
        $pages = $this->stats->top_pages( 50, $days );
        ?>
        <div class="wrap shrikant-vt-dashboard">
            <h1><?php esc_html_e( 'Pages Report', 'shrikant-visitor-tracker' ); ?></h1>
            <?php $this->period_switcher( 'shrikant-visitor-tracker-pages', $days ); ?>
            <div class="sk-vt-card" style="margin-top:1rem">
                <h2 style="display:flex;justify-content:space-between;align-items:center">
                    <span><?php /* translators: %d: number of days in the reporting period. */
						printf( esc_html__( 'Top 50 Pages — Last %d Days', 'shrikant-visitor-tracker' ), esc_html( (string) $days ) ); ?></span>
                    <a href="<?php echo esc_url( rest_url( 'sk-vt/v1/export?from=' . gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ) . '&to=' . gmdate( 'Y-m-d' ) ) ); ?>"
                       class="button" download>
                        ⬇ <?php esc_html_e( 'Export CSV', 'shrikant-visitor-tracker' ); ?>
                    </a>
                </h2>
                <?php $this->render_top_pages_table( $pages ); ?>
            </div>
        </div>
        <?php
    }

    // ── UTM sub-page ──────────────────────────────────────────────────────────

    public function render_utm(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $days   = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 30;
        $days   = in_array( $days, [ 7, 14, 30, 90, 365 ], true ) ? $days : 30;
        $report = $this->stats->utm_report( 50, $days );
        ?>
        <div class="wrap shrikant-vt-dashboard">
            <h1><?php esc_html_e( 'UTM Campaigns', 'shrikant-visitor-tracker' ); ?></h1>
            <?php $this->period_switcher( 'shrikant-visitor-tracker-utm', $days ); ?>
            <div class="sk-vt-card" style="margin-top:1rem">
                <h2><?php esc_html_e( 'UTM Campaign Report', 'shrikant-visitor-tracker' ); ?></h2>
                <?php if ( empty( $report ) ) : ?>
                    <p><?php esc_html_e( 'No UTM data yet. UTM parameters (utm_source, utm_medium, utm_campaign, utm_content, utm_term) are tracked automatically when present in page URLs.', 'shrikant-visitor-tracker' ); ?></p>
                <?php else : ?>
                <table class="wp-list-table widefat striped sk-vt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Source', 'shrikant-visitor-tracker' ); ?></th>
                        <th><?php esc_html_e( 'Medium', 'shrikant-visitor-tracker' ); ?></th>
                        <th><?php esc_html_e( 'Campaign', 'shrikant-visitor-tracker' ); ?></th>
                        <th class="sk-vt-num"><?php esc_html_e( 'Pageviews', 'shrikant-visitor-tracker' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $report as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['source'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['medium'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['campaign'] ?: '—' ); ?></td>
                            <td class="sk-vt-num"><?php echo esc_html( number_format_i18n( $row['pageviews'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ── Settings page ─────────────────────────────────────────────────────────

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $alltime = $this->stats->all_time_totals();
        ?>
        <div class="wrap sk-vt-settings">
            <h1><?php esc_html_e( 'Shrikant Visitor Tracker — Settings', 'shrikant-visitor-tracker' ); ?></h1>

            <?php if ( ! empty( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Settings saved.', 'shrikant-visitor-tracker' ); ?></p>
            </div>
            <?php endif; ?>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'Database Overview', 'shrikant-visitor-tracker' ); ?></h2>
                <table class="sk-vt-info-table">
                    <tr><td><?php esc_html_e( 'Total pageviews', 'shrikant-visitor-tracker' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( $alltime['pageviews'] ) ); ?></strong></td></tr>
                    <tr><td><?php esc_html_e( 'Total unique visits', 'shrikant-visitor-tracker' ); ?></td><td><strong><?php echo esc_html( number_format_i18n( $alltime['unique_visitors'] ) ); ?></strong></td></tr>
                    <tr><td><?php esc_html_e( 'First recorded visit', 'shrikant-visitor-tracker' ); ?></td><td><strong><?php echo esc_html( $alltime['first_visit'] ?: '—' ); ?></strong></td></tr>
                    <tr>
                        <td><?php esc_html_e( 'REST API base URL', 'shrikant-visitor-tracker' ); ?></td>
                        <td><a href="<?php echo esc_url( rest_url( 'sk-vt/v1/summary' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( rest_url( 'sk-vt/v1/summary' ) ); ?></a></td>
                    </tr>
                </table>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:680px">
                <input type="hidden" name="action" value="sk_vt_save_settings">
                <?php wp_nonce_field( 'sk_vt_settings_save', 'sk_vt_nonce' ); ?>
                <table class="form-table">
                    <?php
                    $this->checkbox_row( 'tracking_enabled', __( 'Enable Tracking', 'shrikant-visitor-tracker' ),  __( 'Master on/off switch for all visitor tracking.', 'shrikant-visitor-tracker' ),                                                                   $this->settings->tracking_enabled() );
                    $this->checkbox_row( 'ip_anonymization', __( 'IP Anonymisation', 'shrikant-visitor-tracker' ), __( 'Zero the last IPv4 octet before geo lookup — strongly recommended for GDPR compliance.', 'shrikant-visitor-tracker' ),                           $this->settings->ip_anonymization() );
                    $this->checkbox_row( 'respect_dnt',      __( 'Respect DNT', 'shrikant-visitor-tracker' ),      __( 'Skip visitors who send the Do Not Track (DNT: 1) header.', 'shrikant-visitor-tracker' ),                                                         $this->settings->respect_dnt() );
                    $this->checkbox_row( 'track_admins',     __( 'Track Admins', 'shrikant-visitor-tracker' ),     __( 'Include visits by logged-in users with manage_options capability.', 'shrikant-visitor-tracker' ),                                                  $this->settings->track_admins() );
                    $this->checkbox_row( 'async_tracking',   __( 'Async Tracking', 'shrikant-visitor-tracker' ),   __( 'Use a non-blocking JS beacon. Recommended for sites with WP Rocket, LiteSpeed Cache, W3 Total Cache, etc.', 'shrikant-visitor-tracker' ),         $this->settings->async_tracking() );
                    $this->checkbox_row( 'geo_enabled',      __( 'Country Detection', 'shrikant-visitor-tracker' ),__( 'Resolve visitor country via ipwho.is (free, no API key). Anonymised IP is sent. Results cached for ≥24 hours.', 'shrikant-visitor-tracker' ),    $this->settings->geo_enabled() );
                    $this->checkbox_row( 'delete_data_on_uninstall', __( 'Delete Data on Uninstall', 'shrikant-visitor-tracker' ), __( 'Off by default, and worth leaving off. Deleting the plugin from wp-admin is not always a goodbye — replacing a hand-installed copy with one from the directory goes through Delete too, and with this on it would take every visit ever recorded with it.', 'shrikant-visitor-tracker' ), $this->settings->delete_data_on_uninstall() );
                    ?>
                    <tr>
                        <th><?php esc_html_e( 'Data Retention', 'shrikant-visitor-tracker' ); ?></th>
                        <td>
                            <select name="retention_days">
                                <?php foreach ( [ 30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '1 year', 730 => '2 years' ] as $v => $l ) : ?>
                                    <option value="<?php echo esc_attr( (string) $v ); ?>" <?php selected( $this->settings->retention_days(), $v ); ?>><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Raw visit rows older than this are deleted by the nightly cron job.', 'shrikant-visitor-tracker' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( '"Online" Window', 'shrikant-visitor-tracker' ); ?></th>
                        <td>
                            <select name="online_ttl">
                                <?php foreach ( [ 60 => '1 minute', 180 => '3 minutes', 300 => '5 minutes', 600 => '10 minutes', 900 => '15 minutes' ] as $v => $l ) : ?>
                                    <option value="<?php echo esc_attr( (string) $v ); ?>" <?php selected( $this->settings->online_ttl(), $v ); ?>><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Visitors inactive longer than this are removed from "Online Now" count.', 'shrikant-visitor-tracker' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Save Settings', 'shrikant-visitor-tracker' ) ); ?>
            </form>
        </div>
        <?php
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function checkbox_row( string $name, string $label, string $desc, bool $checked ): void {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
                    <?php echo esc_html( $desc ); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    private function period_switcher( string $page_slug, int $current_days ): void {
        echo '<div class="sk-vt-period-switcher">';
        foreach ( [ 7 => '7d', 14 => '14d', 30 => '30d', 90 => '90d', 365 => '1yr' ] as $d => $lbl ) {
            $url   = add_query_arg( [ 'page' => $page_slug, 'days' => $d ], admin_url( 'admin.php' ) );
            $class = $current_days === $d ? 'button button-primary' : 'button';
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_html( $lbl )
            );
        }
        echo '</div>';
    }

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
            $args['sk_vt_error'] = 'missing';
        } else {
            $result              = Shrikant_VT_Import::run( $source );
            $args['sk_vt_posts'] = $result['posts'];
            $args['shrikant_vt_views'] = $result['views'];
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Import screen.
     *
     * Exists because these counters are usually running on several sites and
     * WP-CLI is not always available on all of them -- the same job the
     * `wp sk-vt import` command does, from a button.
     */
    public function render_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $log = Shrikant_VT_Import::log();
        ?>
        <div class="wrap sk-vt-settings">
            <h1><?php esc_html_e( 'Shrikant Visitor Tracker — Import', 'shrikant-visitor-tracker' ); ?></h1>

            <?php if ( isset( $_GET['shrikant_vt_views'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: number of views, 2: number of posts */
                            esc_html__( 'Imported %1$s views across %2$s posts. You can now delete the plugin they came from.', 'shrikant-visitor-tracker' ),
                            '<strong>' . esc_html( number_format_i18n( (int) $_GET['shrikant_vt_views'] ) ) . '</strong>',
                            '<strong>' . esc_html( number_format_i18n( (int) $_GET['sk_vt_posts'] ) ) . '</strong>'
                        );
                        ?>
                    </p>
                </div>
            <?php elseif ( isset( $_GET['sk_vt_error'] ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'That plugin has no data on this site, so there was nothing to import.', 'shrikant-visitor-tracker' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'Bringing history across', 'shrikant-visitor-tracker' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Imported counts are kept separate from this plugin\'s own tracking and are never added to it. The two measure different things: a counter that runs in PHP misses every reader served from a page cache and counts the crawlers that miss it, so the figures usually disagree by several times over. Blending them would make both untrustworthy.', 'shrikant-visitor-tracker' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'Running an import twice is safe — each post\'s figure is replaced, not added to.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <?php foreach ( Shrikant_VT_Import::sources() as $key => $source ) : ?>
                <?php
                $available = Shrikant_VT_Import::available( $key );
                $preview   = $available ? Shrikant_VT_Import::preview( $key ) : [ 'posts' => 0, 'views' => 0 ];
                $done      = $log[ $key ] ?? null;
                ?>
                <div class="sk-vt-card sk-vt-info-box">
                    <h2><?php echo esc_html( $source['label'] ); ?></h2>

                    <?php if ( ! $available ) : ?>
                        <p><em><?php esc_html_e( 'No data from this plugin on this site.', 'shrikant-visitor-tracker' ); ?></em></p>
                    <?php else : ?>
                        <table class="sk-vt-info-table">
                            <tr>
                                <td><?php esc_html_e( 'Found', 'shrikant-visitor-tracker' ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( number_format_i18n( $preview['views'] ) ); ?></strong>
                                    <?php esc_html_e( 'views across', 'shrikant-visitor-tracker' ); ?>
                                    <strong><?php echo esc_html( number_format_i18n( $preview['posts'] ) ); ?></strong>
                                    <?php esc_html_e( 'posts', 'shrikant-visitor-tracker' ); ?>
                                </td>
                            </tr>
                            <?php if ( $done ) : ?>
                                <tr>
                                    <td><?php esc_html_e( 'Last imported', 'shrikant-visitor-tracker' ); ?></td>
                                    <td><?php echo esc_html( $done['at'] ); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
                            <input type="hidden" name="action" value="sk_vt_import">
                            <input type="hidden" name="source" value="<?php echo esc_attr( $key ); ?>">
                            <?php wp_nonce_field( 'sk_vt_import' ); ?>
                            <button type="submit" class="button button-primary">
                                <?php
                                echo $done
                                    ? esc_html__( 'Import again', 'shrikant-visitor-tracker' )
                                    : esc_html__( 'Import these counts', 'shrikant-visitor-tracker' );
                                ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * How it works.
     *
     * Written for the person who installed this and now wants to know why its
     * numbers disagree with the counter they had before -- which is the first
     * question everybody asks, and the one a feature list never answers.
     */
    public function render_help(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $rest = esc_url( rest_url( 'sk-vt/v1/' ) );
        ?>
        <div class="wrap sk-vt-settings">
            <h1><?php esc_html_e( 'How this plugin works', 'shrikant-visitor-tracker' ); ?></h1>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'How a visit is counted', 'shrikant-visitor-tracker' ); ?></h2>
                <p>
                    <?php esc_html_e( 'When a page finishes loading, a small script in the footer sends one request back to your site saying "this page was viewed". That is the whole mechanism.', 'shrikant-visitor-tracker' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'It works this way on purpose. Most view counters add up in PHP while the page is being built — which means that on a site with a page cache, a reader served from the cache is never counted at all, because PHP never ran. The counting here happens in the reader\'s browser, so a cached page is counted exactly like an uncached one.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'Why these numbers are lower than your old plugin\'s', 'shrikant-visitor-tracker' ); ?></h2>
                <p>
                    <?php esc_html_e( 'They usually are, by a lot, and the lower number is the more honest one. A PHP-based counter misses the cached readers and counts the crawlers that bypass the cache — and crawlers visit far more often than people do. This plugin filters known bots before recording anything.', 'shrikant-visitor-tracker' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'If a figure here looks wrong, compare it with Search Console rather than with the old plugin. Clicks there and visits here should be in the same neighbourhood.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'Showing the count to readers', 'shrikant-visitor-tracker' ); ?></h2>
                <p><?php esc_html_e( 'Three ways, use whichever suits the theme:', 'shrikant-visitor-tracker' ); ?></p>
                <table class="sk-vt-info-table">
                    <tr>
                        <td><strong><?php esc_html_e( 'Automatic', 'shrikant-visitor-tracker' ); ?></strong></td>
                        <td><?php esc_html_e( 'A line appears under every single post. Nothing to set up.', 'shrikant-visitor-tracker' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Shortcode', 'shrikant-visitor-tracker' ); ?></strong></td>
                        <td>
                            <code>[sk_views]</code> &nbsp;
                            <code>[sk_views id="12"]</code> &nbsp;
                            <code>[sk_views label="Reads:"]</code> &nbsp;
                            <code>[sk_views raw="yes"]</code>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'In a template', 'shrikant-visitor-tracker' ); ?></strong></td>
                        <td><code>&lt;?php echo shrikant_vt_views(); ?&gt;</code></td>
                    </tr>
                </table>
                <p class="description">
                    <?php esc_html_e( 'To turn the automatic line off, add this to your theme:', 'shrikant-visitor-tracker' ); ?>
                    <br><code>add_filter( 'sk_vt_show_views', '__return_false' );</code>
                </p>
            </div>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'Coming from another counter', 'shrikant-visitor-tracker' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s: link to the Import screen */
                        esc_html__( 'The %s screen reads the totals out of Post Views Counter or WP-PostViews so that deleting them does not take the history with it. Import first, check the numbers appear, and only then remove the old plugin.', 'shrikant-visitor-tracker' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=shrikant-visitor-tracker-import' ) ) . '">' . esc_html__( 'Import', 'shrikant-visitor-tracker' ) . '</a>'
                    );
                    ?>
                </p>
                <p>
                    <?php esc_html_e( 'Imported counts are kept apart from what this plugin records itself, and never added into its own statistics. The two measure different things, and mixing them would make both untrustworthy. A reader sees the sum; the dashboard shows what was actually tracked.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'What is stored about a visitor', 'shrikant-visitor-tracker' ); ?></h2>
                <table class="sk-vt-info-table">
                    <tr><td><?php esc_html_e( 'Stored', 'shrikant-visitor-tracker' ); ?></td><td><?php esc_html_e( 'Page, date and hour, a hashed visitor id, country, device, browser, OS, referrer and any UTM parameters.', 'shrikant-visitor-tracker' ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Never stored', 'shrikant-visitor-tracker' ); ?></td><td><?php esc_html_e( 'The raw IP address. It is anonymised before anything is done with it, and never written down.', 'shrikant-visitor-tracker' ); ?></td></tr>
                    <tr><td><?php esc_html_e( 'Sent off-site', 'shrikant-visitor-tracker' ); ?></td><td><?php esc_html_e( 'Only the anonymised IP, only to resolve a country, only over https, and only when Country Detection is on. Turn it off and nothing leaves your server at all.', 'shrikant-visitor-tracker' ); ?></td></tr>
                </table>
                <p class="description">
                    <?php esc_html_e( 'Visitors sending Do Not Track are skipped. Tools → Export/Erase Personal Data works with this plugin.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'Housekeeping', 'shrikant-visitor-tracker' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Individual visits are rolled up into hourly summaries once an hour, and the raw rows are cleared after the retention period set in Settings. The dashboard reads the summaries, so it stays fast however much traffic the site gets.', 'shrikant-visitor-tracker' ); ?>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Deleting this plugin does not delete its data', 'shrikant-visitor-tracker' ); ?></strong>
                    — <?php esc_html_e( 'unless you switch that on in Settings. Deleting is not always a goodbye; swapping one copy for another goes through the same button.', 'shrikant-visitor-tracker' ); ?>
                </p>
            </div>

            <div class="sk-vt-card sk-vt-info-box">
                <h2><?php esc_html_e( 'For developers', 'shrikant-visitor-tracker' ); ?></h2>
                <table class="sk-vt-info-table">
                    <tr><td><?php esc_html_e( 'REST API', 'shrikant-visitor-tracker' ); ?></td><td><code><?php echo esc_html( $rest ); ?></code></td></tr>
                    <tr><td>WP-CLI</td><td><code>wp sk-vt stats today</code>, <code>wp sk-vt top-pages</code>, <code>wp sk-vt import --dry-run</code>, <code>wp sk-vt export</code>, <code>wp sk-vt cleanup --dry-run</code></td></tr>
                    <tr><td><?php esc_html_e( 'Filters', 'shrikant-visitor-tracker' ); ?></td><td><code>sk_vt_show_views</code>, <code>sk_vt_views_label</code>, <code>sk_vt_display_post_types</code>, <code>sk_vt_default_settings</code></td></tr>
                </table>
            </div>
        </div>
        <?php
    }
}
