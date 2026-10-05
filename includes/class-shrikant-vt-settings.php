<?php
/**
 * Plugin settings — stored as a single serialised option.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Settings
 *
 * Provides typed getters for every plugin option.
 * All settings live in a single WP option (sk_vt_settings) to minimise
 * autoloaded option rows.
 *
 * Extend via the filter shrikant_vt_default_settings if you need to add options
 * without modifying this file.
 */
final class Shrikant_VT_Settings {

    private const OPTION_KEY = 'sk_vt_settings';

    /** @var array<string,mixed> Merged defaults + saved values. */
    private array $data;

    public function __construct() {
        $defaults   = $this->defaults();
        $saved      = get_option( self::OPTION_KEY, [] );
        $this->data = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
    }

    // ── Public typed getters ─────────────────────────────────────────────────

    /** Is tracking enabled at all? */
    public function tracking_enabled(): bool {
        return (bool) $this->data['tracking_enabled'];
    }

    /** Should deleting the plugin also delete its data? */
    public function delete_data_on_uninstall(): bool {
        return (bool) $this->data['delete_data_on_uninstall'];
    }

    /** Anonymise the last octet(s) of IPs before any geo look-up. */
    public function ip_anonymization(): bool {
        return (bool) $this->data['ip_anonymization'];
    }

    /** Respect the DNT (Do Not Track) browser header. */
    public function respect_dnt(): bool {
        return (bool) $this->data['respect_dnt'];
    }

    /** Number of days to keep raw rows before cron cleanup. */
    public function retention_days(): int {
        return max( 30, (int) $this->data['retention_days'] );
    }

    /** Inactivity window in seconds for "currently online" counter. */
    public function online_ttl(): int {
        $ttl = (int) $this->data['online_ttl'];
        return ( $ttl >= 60 && $ttl <= 3600 ) ? $ttl : Shrikant_VT_ONLINE_TTL;
    }

    /** Track logged-in admin users? */
    public function track_admins(): bool {
        return (bool) $this->data['track_admins'];
    }

    /** Use async (AJAX) write to avoid blocking page render. */
    public function async_tracking(): bool {
        return (bool) $this->data['async_tracking'];
    }

    /** Country geo-lookup enabled? */
    public function geo_enabled(): bool {
        return (bool) $this->data['geo_enabled'];
    }

    /** Whether the count is appended to content automatically. */
    public function auto_display(): bool {
        return ! empty( $this->data['auto_display'] );
    }

    /**
     * Post types the count is shown on.
     *
     * @return array<int,string>
     */
    public function display_post_types(): array {
        $types = $this->data['display_post_types'] ?? [ 'post' ];

        return self::clean_post_types( is_array( $types ) ? $types : [ $types ] );
    }

    /**
     * Keep only post types that exist and are public.
     *
     * A type can disappear between being chosen and being read -- the plugin
     * that registered it gets deactivated -- so the stored list is filtered on
     * the way out as well as on the way in, rather than trusted.
     *
     * @param mixed $input Raw list.
     * @return array<int,string>
     */
    private static function clean_post_types( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $public = get_post_types( [ 'public' => true ] );
        $clean  = [];

        foreach ( $input as $type ) {
            $type = sanitize_key( (string) $type );
            if ( isset( $public[ $type ] ) && ! in_array( $type, $clean, true ) ) {
                $clean[] = $type;
            }
        }

        return $clean;
    }

    /** How long (seconds) to cache a geo lookup result. Min 86400 (24h). */
    public function geo_cache_ttl(): int {
        $ttl = (int) $this->data['geo_cache_ttl'];
        return max( 86400, $ttl );
    }

    /** Raw access to a setting value (for admin form pre-population). */
    public function get( string $key ): mixed {
        return $this->data[ $key ] ?? null;
    }

    /** Persist new settings values — called from settings form handler. */
    public function save( array $raw_input ): void {
        $clean = $this->sanitize( $raw_input );
        update_option( self::OPTION_KEY, $clean, false );
        $this->data = wp_parse_args( $clean, $this->defaults() );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Default values for every setting.
     * Filterable so third-party code can add custom settings.
     *
     * @return array<string,mixed>
     */
    private function defaults(): array {
        /**
         * Filter: shrikant_vt_default_settings
         * Allows extending default settings without editing this file.
         *
         * @param array<string,mixed> $defaults
         */
        return (array) apply_filters( 'shrikant_vt_default_settings', [
            'tracking_enabled'  => true,
            'ip_anonymization'  => true,   // Recommended for GDPR.
            'respect_dnt'       => true,
            'retention_days'    => 365,
            'online_ttl'        => 300,    // 5 minutes.
            'track_admins'      => false,
            'async_tracking'    => true,
            'geo_enabled'       => true,
            'geo_cache_ttl'     => 86400,  // 24 hours.

            /*
             * Where the view count is shown to readers. Posts only by default,
             * because that is what most sites want and showing a count on a
             * contact page helps nobody. Any public post type can be added.
             */
            'auto_display'      => true,
            'display_post_types' => [ 'post' ],

            /*
             * Deleting the plugin from wp-admin runs uninstall.php, which used
             * to drop both tables unconditionally. Swapping this plugin for a
             * renamed build of itself -- exactly what happens when a hand-
             * installed copy is replaced by the published one -- would have
             * destroyed every visit ever recorded, at the moment the person
             * believed they were keeping it. So the default is to keep the
             * data, and throwing it away has to be asked for.
             */
            'delete_data_on_uninstall' => false,
        ] );
    }

    /**
     * Sanitize raw POST values before saving.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function sanitize( array $input ): array {
        return [
            'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
            'tracking_enabled'  => ! empty( $input['tracking_enabled'] ),
            'ip_anonymization'  => ! empty( $input['ip_anonymization'] ),
            'respect_dnt'       => ! empty( $input['respect_dnt'] ),
            'retention_days'    => absint( $input['retention_days'] ?? 365 ),
            'online_ttl'        => absint( $input['online_ttl'] ?? 300 ),
            'track_admins'      => ! empty( $input['track_admins'] ),
            'async_tracking'    => ! empty( $input['async_tracking'] ),
            'geo_enabled'       => ! empty( $input['geo_enabled'] ),
            'geo_cache_ttl'     => max( 86400, absint( $input['geo_cache_ttl'] ?? 86400 ) ),
            'auto_display'      => ! empty( $input['auto_display'] ),
            'display_post_types' => self::clean_post_types( $input['display_post_types'] ?? [] ),
        ];
    }

    /** Register Settings API hooks (admin form submission). */
    public function register_hooks(): void {
        add_action( 'admin_post_sk_vt_save_settings', [ $this, 'handle_form_submit' ] );
    }

    /** Handle the settings form POST (with nonce + capability check). */
    public function handle_form_submit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'shrikant-visitor-tracker' ) );
        }
        check_admin_referer( 'sk_vt_settings_save', 'sk_vt_nonce' );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $this->save( $_POST );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'shrikant-visitor-tracker-settings', 'updated' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
