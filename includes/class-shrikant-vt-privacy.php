<?php
/**
 * Privacy-first controls: DNT, IP anonymisation, GDPR/CCPA hooks.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Privacy
 *
 * Centralises all privacy decisions:
 *
 * 1. DNT (Do Not Track) header respect — opt-in via settings.
 * 2. IP anonymisation — removes last octet (IPv4) or last 80 bits (IPv6)
 *    before any processing; the raw IP is NEVER stored.
 * 3. Consent check — integrates with cookie-consent plugins via a
 *    filter hook (shrikant_vt_has_consent) so no hard dependency is needed.
 * 4. GDPR erasure / export hooks — WordPress's personal data tools.
 */
final class Shrikant_VT_Privacy {

    public function __construct(
        private readonly Shrikant_VT_Settings $settings
    ) {}

    /**
     * Should we track this request?
     * Returns false if any privacy gate is triggered.
     *
     * @return bool TRUE = proceed with tracking.
     */
    public function should_track(): bool {
        // Gate 1: Global tracking kill-switch.
        if ( ! $this->settings->tracking_enabled() ) {
            return false;
        }

        // Gate 2: Admin user exclusion.
        if ( ! $this->settings->track_admins() && current_user_can( 'manage_options' ) ) {
            return false;
        }

        // Gate 3: DNT header.
        if ( $this->settings->respect_dnt() && $this->visitor_sent_dnt() ) {
            return false;
        }

        // Gate 4: Consent check — third-party plugins implement this filter.
        /**
         * Filter: shrikant_vt_has_consent
         * Return FALSE to block tracking (e.g., user hasn't accepted cookies).
         *
         * @param bool $has_consent Defaults to TRUE (tracking allowed).
         */
        if ( ! (bool) apply_filters( 'shrikant_vt_has_consent', true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Anonymise an IP address.
     *
     * IPv4: zeros the last octet            → 203.0.113.0
     * IPv6: zeros the last 80 bits (5 groups) → 2001:db8:85a3::0:0:0:0:0
     *
     * If anonymisation is disabled in settings, returns the IP as-is
     * (it will still be hashed before storage — see get_visitor_id()).
     *
     * @param string $ip Raw IP address.
     * @return string Anonymised IP.
     */
    public function anonymise_ip( string $ip ): string {
        if ( ! $this->settings->ip_anonymization() ) {
            return $ip;
        }

        // Let WP handle the masking — uses inet_pton internally.
        return wp_privacy_anonymize_ip( $ip );
    }

    /**
     * Derive a stable, opaque visitor ID from the IP (+ optional UA salt).
     * Uses HMAC-SHA256 keyed with AUTH_KEY so the ID cannot be reversed.
     * The same visitor gets the same ID on the same day (daily rotation via
     * date salt adds k-anonymity and limits re-identification risk).
     *
     * @param string $ip     (already anonymised if setting enabled)
     * @param string $ua     User-Agent string.
     * @return string 64-char hex string.
     */
    public function get_visitor_id( string $ip, string $ua = '' ): string {
        // Daily salt: IDs rotate each day, preventing long-term tracking.
        $day_salt = gmdate( 'Y-m-d' );
        $secret   = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );

        return hash_hmac(
            'sha256',
            $ip . '|' . $ua . '|' . $day_salt,
            $secret
        );
    }

    /**
     * Get the current visitor's real IP address, respecting proxy headers.
     * Only trusts forwarded IPs when HTTPS is active (reduces spoofing risk).
     *
     * @return string IP address.
     */
    public function get_client_ip(): string {
        // When behind a trusted reverse proxy over HTTPS, check X-Forwarded-For.
        if ( is_ssl() && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip  = trim( $ips[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }

        return sanitize_text_field(
            wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' )
        );
    }

    /**
     * Check if the visitor sent a DNT: 1 header.
     */
    private function visitor_sent_dnt(): bool {
        return isset( $_SERVER['HTTP_DNT'] ) && '1' === $_SERVER['HTTP_DNT'];
    }

    /**
     * Register WordPress personal data erasure / export hooks (GDPR tools).
     */
    public function register_hooks(): void {
        // Personal data exporter (Tools → Export Personal Data).
        add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
        // Personal data eraser (Tools → Erase Personal Data).
        add_filter( 'wp_privacy_personal_data_erasers',  [ $this, 'register_eraser' ] );
    }

    /** Register the data exporter with WordPress. */
    public function register_exporter( array $exporters ): array {
        $exporters['shrikant-visitor-tracker'] = [
            'exporter_friendly_name' => __( 'Shrikant Visitor Tracker', 'shrikant-visitor-tracker' ),
            'callback'               => [ $this, 'export_visitor_data' ],
        ];
        return $exporters;
    }

    /** Register the data eraser with WordPress. */
    public function register_eraser( array $erasers ): array {
        $erasers['shrikant-visitor-tracker'] = [
            'eraser_friendly_name' => __( 'Shrikant Visitor Tracker', 'shrikant-visitor-tracker' ),
            'callback'             => [ $this, 'erase_visitor_data' ],
        ];
        return $erasers;
    }

    /**
     * Export visitor data for a given email address.
     * Because we store only hashed/anonymised IDs, we cannot reliably
     * associate rows to an email. We return an empty set and note this.
     */
    public function export_visitor_data( string $email, int $page = 1 ): array {
        return [
            'data' => [],
            'done' => true,
        ];
    }

    /**
     * Erase visitor data for a given email address.
     * Same limitation as export — IDs are opaque. We confirm done.
     */
    public function erase_visitor_data( string $email, int $page = 1 ): array {
        return [
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => [
                __( 'Shrikant Visitor Tracker stores only anonymised, hashed visitor identifiers that cannot be linked to an email address.', 'shrikant-visitor-tracker' ),
            ],
            'done'           => true,
        ];
    }
}
