<?php
/**
 * Country detection from IP with aggressive caching.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Geo
 *
 * Resolves an IP address to a 2-letter ISO country code using ipwho.is
 * (completely free, no API key required, up to 45 req/min per IP).
 *
 * Caching strategy (multi-layer):
 * ────────────────────────────────
 * Layer 1: Static in-process cache  — free, zero DB cost within one request.
 * Layer 2: WP transient (object cache if available, otherwise option table)
 *           keyed on the anonymised IP. TTL = settings.geo_cache_ttl (≥24h).
 * Layer 3: API call with wp_remote_get() — only when layers 1 & 2 miss.
 *
 * Graceful fallback:
 * ──────────────────
 * Any network failure, HTTP error, or unexpected response returns 'XX'
 * (unknown) and caches the miss to avoid hammering a dead endpoint.
 *
 * Privacy:
 * ────────
 * We only ever send the *anonymised* IP to ipwho.is.
 * For IPv4 with anonymisation on, the last octet is already zeroed
 * (e.g., 203.0.113.0), so country-level accuracy is preserved.
 */
final class Shrikant_VT_Geo {

    /** Fallback country code for any failure. */
    private const UNKNOWN = 'XX';

    /** Transient key prefix — full key: sk_vt_geo_{md5(ip)}. */
    private const CACHE_PREFIX = 'sk_vt_geo_';

    /** Free geo-IP endpoint. Returns JSON: {"countryCode":"US",...} */
    /*
     * https, and a service whose free tier actually serves it. ipwho.is is
     * http-only unless you pay, and shipping a plugin that sends anything over
     * plain http is both a review problem and a real one.
     */
    private const API_URL = 'https://ipwho.is/%s?fields=success,country_code';

    /** @var array<string,string> In-process cache: anonymised_ip → country_code. */
    private static array $in_process = [];

    public function __construct(
        private readonly Shrikant_VT_Settings $settings
    ) {}

    /**
     * Resolve a (pre-anonymised) IP to a country code.
     *
     * @param string $ip Anonymised IP from Shrikant_VT_Privacy::anonymise_ip().
     * @return string Two-letter country code (uppercase) or 'XX'.
     */
    public function get_country( string $ip ): string {
        if ( ! $this->settings->geo_enabled() ) {
            return self::UNKNOWN;
        }

        // Layer 1: In-process static cache.
        if ( isset( self::$in_process[ $ip ] ) ) {
            return self::$in_process[ $ip ];
        }

        // Transient key — md5 keeps key length fixed and avoids special chars.
        $transient_key = self::CACHE_PREFIX . md5( $ip );

        // Layer 2: Transient / object cache.
        $cached = get_transient( $transient_key );
        if ( false !== $cached ) {
            self::$in_process[ $ip ] = (string) $cached;
            return (string) $cached;
        }

        // Layer 3: API call.
        $country = $this->fetch_from_api( $ip );

        // Cache for the configured TTL (minimum 24h).
        set_transient( $transient_key, $country, $this->settings->geo_cache_ttl() );
        self::$in_process[ $ip ] = $country;

        return $country;
    }

    /**
     * Call ipwho.is and parse the country code.
     *
     * Note: ipwho.is's free plan does not support HTTPS. The request goes
     * over HTTP but only sends an anonymised IP (last octet zeroed), which
     * is not personal data under most privacy frameworks. If your hosting
     * environment blocks outbound HTTP, disable geo in settings; the plugin
     * will gracefully return 'XX' (unknown) for all visitors.
     *
     * @param string $ip Anonymised IP address.
     * @return string Country code or 'XX' on any failure.
     */
    private function fetch_from_api( string $ip ): string {
        $url = sprintf( self::API_URL, rawurlencode( $ip ) );

        $response = wp_remote_get( $url, [
            'timeout'    => 3,    // Never block more than 3 s.
            'sslverify'  => true,
            'user-agent' => 'Shrikant-Visitor-Tracker/' . Shrikant_VT_VERSION . '; ' . get_bloginfo( 'url' ),
        ] );

        if ( is_wp_error( $response ) ) {
            return self::UNKNOWN;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return self::UNKNOWN;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            return self::UNKNOWN;
        }

        $data = json_decode( $body, true );

        // A reserved or unroutable range answers with success=false rather
        // than an HTTP error, so the body has to be read, not just the status.
        if (
            ! is_array( $data ) ||
            empty( $data['success'] ) ||
            empty( $data['country_code'] ) ||
            ! preg_match( '/^[A-Za-z]{2}$/', (string) $data['country_code'] )
        ) {
            return self::UNKNOWN;
        }

        return strtoupper( $data['country_code'] );
    }

    /** No hooks needed. */
    public function register_hooks(): void {}
}
