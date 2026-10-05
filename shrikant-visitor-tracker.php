<?php
/**
 * Plugin Name:       Shrikant Visitor Tracker
 * Plugin URI:        https://profiles.wordpress.org/shrikantgaur/
 * Description:       A modern, lightweight, privacy-first, self-hosted visitor analytics plugin for WordPress. Tracks unique visitors, page views, devices, referrers, UTM parameters, countries, and real-time online users — with zero paid dependencies.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Author:            Shri Kant Gaur
 * Author URI:        https://profiles.wordpress.org/shrikantgaur/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shrikant-visitor-tracker
 * Domain Path:       /languages
 *
 * @package Shrikant_Visitor_Tracker
 *
 * ============================================================================
 * ARCHITECTURE OVERVIEW
 * ============================================================================
 *
 * This plugin is built around a modular, object-oriented architecture:
 *
 * 1.  Shrikant_Visitor_Tracker        — Main bootstrap/container class (singleton)
 * 2.  Shrikant_VT_DB                  — Database schema creation and migration
 * 3.  Shrikant_VT_Settings            — All plugin settings (single WP option)
 * 4.  Shrikant_VT_Privacy             — DNT, IP anonymisation, GDPR hooks
 * 5.  Shrikant_VT_Bot_Filter          — Comprehensive bot/crawler filtering
 * 6.  Shrikant_VT_Device_Detector     — Lightweight UA-based device/browser/OS detection
 * 7.  Shrikant_VT_Geo                 — IP → Country with aggressive caching (ipwho.is)
 * 8.  Shrikant_VT_Online              — Real-time "currently online" via transients
 * 9.  Shrikant_VT_Stats               — Stats query helpers (used by admin & REST)
 * 10. Shrikant_VT_Tracker             — Core tracking logic (hot path, ultra-fast)
 * 11. Shrikant_VT_Cron                — WP-Cron jobs: SQL-side aggregation + cleanup
 * 12. Shrikant_VT_REST                — REST API under /wp-json/sk-vt/v1/
 * 13. Shrikant_VT_CLI                 — WP-CLI command group (wp sk-vt ...)
 * 14. Shrikant_VT_Admin               — Admin dashboard, Pages, UTM, Settings pages
 *
 * DATA FLOW (per request):
 *   template_redirect → Shrikant_VT_Bot_Filter::is_bot() → Shrikant_VT_Privacy::should_track()
 *   → Shrikant_VT_Tracker::maybe_track() → async AJAX write → sk_visitor_analytics table
 *   → WP-Cron aggregates into sk_visitor_summary (hourly)
 *
 * ============================================================================
 */

declare( strict_types=1 );

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'Shrikant_VT_VERSION',     '1.0.0' );
define( 'Shrikant_VT_FILE',        __FILE__ );
define( 'Shrikant_VT_DIR',         plugin_dir_path( __FILE__ ) );
define( 'Shrikant_VT_URL',         plugin_dir_url( __FILE__ ) );
define( 'Shrikant_VT_MIN_PHP',     '8.2' );
define( 'Shrikant_VT_MIN_WP',      '6.3' );
define( 'Shrikant_VT_TABLE_RAW',   'sk_visitor_analytics' );
define( 'Shrikant_VT_TABLE_SUM',   'sk_visitor_summary' );
define( 'Shrikant_VT_COOKIE_NAME', 'sk_unique_id' );
define( 'Shrikant_VT_COOKIE_TTL',  60 * 60 * 24 * 730 ); // 2 years in seconds.
define( 'Shrikant_VT_ONLINE_TTL',  300 );                  // 5 min default online window.

// ── Minimum-requirements gate ─────────────────────────────────────────────────
if ( version_compare( PHP_VERSION, Shrikant_VT_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', static function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: 1: required PHP version, 2: current PHP version */
                    __( 'Shrikant Visitor Tracker requires PHP %1$s or higher. Your server runs PHP %2$s.', 'shrikant-visitor-tracker' ),
                    Shrikant_VT_MIN_PHP,
                    PHP_VERSION
                )
            )
        );
    } );
    return;
}

if ( version_compare( $GLOBALS['wp_version'] ?? '0', Shrikant_VT_MIN_WP, '<' ) ) {
    add_action( 'admin_notices', static function () {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: 1: required WP version, 2: current WP version */
                    __( 'Shrikant Visitor Tracker requires WordPress %1$s or higher.', 'shrikant-visitor-tracker' ),
                    Shrikant_VT_MIN_WP
                )
            )
        );
    } );
    return;
}

// ── Autoloader (PSR-4-style, no Composer required) ────────────────────────────
spl_autoload_register( static function ( string $class ): void {
    // Only handle classes in our namespace/prefix.
    if ( ! str_starts_with( $class, 'Shrikant_VT' ) ) {
        return;
    }
    // Map Shrikant_VT_Some_Class → includes/class-sk-vt-some-class.php
    $file = Shrikant_VT_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ── Activation / Deactivation / Uninstall hooks ──────────────────────────────
register_activation_hook( Shrikant_VT_FILE,   [ 'Shrikant_VT_DB', 'network_install' ] );
register_deactivation_hook( Shrikant_VT_FILE, [ 'Shrikant_VT_Cron', 'deactivate' ] );
// New site in a multisite network → install tables for it automatically.
add_action( 'wp_initialize_site', [ 'Shrikant_VT_DB', 'on_new_site' ] );
// Uninstall is handled via uninstall.php (separate file, runs as its own process).

// ── Bootstrap on plugins_loaded ───────────────────────────────────────────────
add_action( 'plugins_loaded', static function (): void {
    Shrikant_Visitor_Tracker::get_instance()->init();
}, 5 );

/**
 * Class Shrikant_Visitor_Tracker
 *
 * Main plugin bootstrap and service container.
 * Uses a lightweight singleton so every include can call
 * Shrikant_Visitor_Tracker::get_instance()->get('stats') to grab a service.
 */
final class Shrikant_Visitor_Tracker {

    /** @var Shrikant_Visitor_Tracker|null Singleton instance. */
    private static ?Shrikant_Visitor_Tracker $instance = null;

    /** @var array<string, object> Registered service objects. */
    private array $services = [];

    /** Private constructor — use get_instance(). */
    private function __construct() {}

    /**
     * Get or create the singleton instance.
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstrap all plugin components.
     * Called once on plugins_loaded priority 5.
     */
    public function init(): void {
        // Load translations.
        load_plugin_textdomain(
            'shrikant-visitor-tracker',
            false,
            dirname( plugin_basename( Shrikant_VT_FILE ) ) . '/languages'
        );

        // Instantiate and register core services.
        $this->services['settings'] = new Shrikant_VT_Settings();
        $this->services['db']       = new Shrikant_VT_DB();
        $this->services['privacy']  = new Shrikant_VT_Privacy( $this->services['settings'] );
        $this->services['bot']      = new Shrikant_VT_Bot_Filter();
        $this->services['device']   = new Shrikant_VT_Device_Detector();
        $this->services['geo']      = new Shrikant_VT_Geo( $this->services['settings'] );
        $this->services['online']   = new Shrikant_VT_Online( $this->services['settings'] );
        $this->services['stats']    = new Shrikant_VT_Stats();
        $this->services['tracker']  = new Shrikant_VT_Tracker(
            $this->services['settings'],
            $this->services['privacy'],
            $this->services['bot'],
            $this->services['device'],
            $this->services['geo'],
            $this->services['online']
        );
        $this->services['cron']     = new Shrikant_VT_Cron( $this->services['settings'] );

        // Front-end display of the view count.
        $this->services['display'] = new Shrikant_VT_Display( $this->services['stats'] );

        // REST API (registered on rest_api_init — safe to load always).
        $this->services['rest'] = new Shrikant_VT_REST(
            $this->services['stats'],
            $this->services['online']
        );

        // WP-CLI commands — only registered when WP-CLI is active.
        $this->services['cli'] = new Shrikant_VT_CLI(
            $this->services['stats'],
            $this->services['online'],
            $this->services['cron'],
            $this->services['settings']
        );
        $this->services['cli']->register();

        // Admin-only services.
        if ( is_admin() ) {
            $this->services['admin'] = new Shrikant_VT_Admin(
                $this->services['stats'],
                $this->services['settings'],
                $this->services['online']
            );
        }

        // Register hooks on all services that expose a register_hooks() method.
        foreach ( $this->services as $service ) {
            if ( method_exists( $service, 'register_hooks' ) ) {
                $service->register_hooks();
            }
        }

        /**
         * Action: shrikant_vt_loaded
         * Fires after all Shrikant Visitor Tracker services are initialised.
         *
         * @param Shrikant_Visitor_Tracker $plugin The main plugin instance.
         */
        do_action( 'shrikant_vt_loaded', $this );
    }

    /**
     * Retrieve a registered service by key.
     *
     * @param string $key Service identifier.
     * @return object|null
     */
    public function get( string $key ): ?object {
        return $this->services[ $key ] ?? null;
    }
}
