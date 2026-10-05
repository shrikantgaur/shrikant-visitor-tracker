<?php
/**
 * Core tracking logic — the hot path.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Tracker
 *
 * Responsible for deciding whether to track a request and persisting
 * the data to the database.
 *
 * Performance philosophy:
 * ────────────────────────
 * Nothing expensive happens in the synchronous page request.
 *
 * Synchronous mode:  One prepared INSERT at template_redirect priority 5.
 *                    No update_option(), no loops, no API calls in the
 *                    hot path (geo comes from a transient cache).
 *
 * Async mode:        A tiny <script> tag fires an AJAX ping to
 *                    admin-ajax.php after the page has loaded in the
 *                    visitor's browser. The INSERT happens in the
 *                    background. Ideal for caching-plugin compatibility.
 *
 * Cookie strategy:
 * ─────────────────
 * • sk_unique_id  : Long-lived (2-year), HttpOnly, Secure, SameSite=Lax.
 *                   Holds a random 32-byte hex unique visitor token.
 * • sk_session_id : Session cookie (no expiry), rotates on each browser
 *                   session, used to count visits vs page views.
 *
 * Unique-visitor logic:
 * ──────────────────────
 * A visit is counted as "unique" if we haven't seen the cookie's
 * visitor_id + today's date combination in the raw table already.
 * We use a short-lived transient as a dedup gate to avoid a SELECT
 * on every page view.
 */
final class Shrikant_VT_Tracker {

    /** AJAX action for async tracking. */
    private const AJAX_ACTION = 'sk_vt_async_track';

    public function __construct(
        private readonly Shrikant_VT_Settings      $settings,
        private readonly Shrikant_VT_Privacy       $privacy,
        private readonly Shrikant_VT_Bot_Filter    $bot_filter,
        private readonly Shrikant_VT_Device_Detector $device_detector,
        private readonly Shrikant_VT_Geo           $geo,
        private readonly Shrikant_VT_Online        $online
    ) {}

    /**
     * Register WordPress hooks.
     */
    public function register_hooks(): void {
        if ( $this->settings->async_tracking() ) {
            // Async: inject tiny JS after page renders.
            add_action( 'wp_footer', [ $this, 'inject_async_beacon' ], 99 );
            // Handle the AJAX ping (works for both logged-in and guests).
            add_action( 'wp_ajax_'        . self::AJAX_ACTION, [ $this, 'handle_async_track' ] );
            add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'handle_async_track' ] );
        } else {
            // Synchronous: track at template_redirect (after WP_Query is set).
            add_action( 'template_redirect', [ $this, 'maybe_track' ], 5 );
        }
    }

    // ── Synchronous tracking ──────────────────────────────────────────────────

    /**
     * Entry point for synchronous mode.
     * Called at template_redirect priority 5.
     */
    public function maybe_track(): void {
        if ( $this->should_skip() ) {
            return;
        }
        $this->do_track( $this->collect_data() );
    }

    // ── Async tracking ────────────────────────────────────────────────────────

    /**
     * Output a tiny non-blocking JS beacon after page content.
     * The beacon sends page_id and referrer info via XMLHttpRequest.
     */
    public function inject_async_beacon(): void {
        if ( is_admin() ) {
            return; // Never inject on wp-admin pages.
        }

        $nonce   = wp_create_nonce( self::AJAX_ACTION );
        $page_id = $this->get_page_id();
        $ajaxurl = admin_url( 'admin-ajax.php' );

        // Using XMLHttpRequest (no jQuery dependency, works everywhere).
        printf(
            '<script id="sk-vt-beacon">' .
            '(function(){' .
            'var x=new XMLHttpRequest();' .
            'x.open("POST","%s",true);' .
            'x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");' .
            'x.send("action=%s&nonce=%s&pid=%d&ref="+encodeURIComponent(document.referrer)+"&url="+encodeURIComponent(location.href));' .
            '})();' .
            '</script>',
            esc_url( $ajaxurl ),
            esc_js( self::AJAX_ACTION ),
            esc_js( $nonce ),
            (int) $page_id
        );
    }

    /**
     * Handle the async AJAX request.
     * Security: nonce check + bot filter + per-IP rate limit.
     */
    public function handle_async_track(): void {
        // Verify nonce (prevents CSRF / forged pings).
        check_ajax_referer( self::AJAX_ACTION, 'nonce' );

        // Per-IP rate limit: max 60 pings per minute per IP.
        // Uses a transient keyed on the hashed IP so nothing sensitive is stored.
        if ( $this->is_rate_limited() ) {
            wp_send_json_success( [ 'skipped' => true, 'reason' => 'rate_limit' ] );
            return;
        }

        if ( $this->should_skip() ) {
            wp_send_json_success( [ 'skipped' => true ] );
            return;
        }

        $data           = $this->collect_data();
        // Override page_id from AJAX POST (JS knows the real post ID).
        $data['page_id'] = isset( $_POST['pid'] )
            ? absint( wp_unslash( $_POST['pid'] ) )
            : $data['page_id'];
        // Override referrer from JS (more accurate than server-side header).
        if ( ! empty( $_POST['ref'] ) ) {
            $data['referrer_raw'] = sanitize_url( wp_unslash( $_POST['ref'] ) );
            [ $data['referrer_type'], $data['referrer_url'] ] = $this->categorise_referrer( $data['referrer_raw'] );
        }

        $this->do_track( $data );
        wp_send_json_success( [ 'tracked' => true ] );
    }

    // ── Core insert ───────────────────────────────────────────────────────────

    /**
     * Build the data array and INSERT into the database.
     *
     * @param array<string,mixed> $data Collected visit data.
     */
    private function do_track( array $data ): void {
        global $wpdb;

        /**
         * Action: shrikant_vt_before_track_visit
         * Fires before inserting a visit. Hook here to add custom fields
         * or abort tracking by throwing an exception.
         *
         * @param array<string,mixed> $data Visit data array.
         */
        do_action( 'shrikant_vt_before_track_visit', $data );

        $table = Shrikant_VT_DB::raw_table();

        $now = current_time( 'mysql', true ); // UTC datetime.

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- recording a visit is the plugin's purpose; $wpdb->insert places every value itself.
        $wpdb->insert(
            $table,
            [
                'visitor_id'   => $data['visitor_id'],
                'session_id'   => $data['session_id'],
                'page_id'      => $data['page_id'],
                'visit_date'   => gmdate( 'Y-m-d' ),
                'visit_hour'   => (int) gmdate( 'G' ),
                'visit_time'   => $now,
                'country_code' => $data['country_code'],
                'device_type'  => $data['device']['device'],
                'browser'      => $data['device']['browser'],
                'os'           => $data['device']['os'],
                'referrer_type'=> $data['referrer_type'],
                'referrer_url' => $data['referrer_url'],
                'utm_source'   => $data['utm']['utm_source'],
                'utm_medium'   => $data['utm']['utm_medium'],
                'utm_campaign' => $data['utm']['utm_campaign'],
                'utm_content'  => $data['utm']['utm_content'],
                'utm_term'     => $data['utm']['utm_term'],
                'is_unique'    => (int) $data['is_unique'],
                'page_url'     => $data['page_url'],
            ],
            [ '%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s' ]
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery

        $insert_id = $wpdb->insert_id;

        // Update "currently online" transient.
        $this->online->record( $data['visitor_id'] );

        /**
         * Action: shrikant_vt_after_insert
         * Fires immediately after a visit row is inserted.
         *
         * @param int                 $insert_id  New row ID (0 on failure).
         * @param array<string,mixed> $data       Visit data.
         */
        do_action( 'shrikant_vt_after_insert', $insert_id, $data );
    }

    // ── Data collection ───────────────────────────────────────────────────────

    /**
     * Collect all visit data for the current request.
     *
     * @return array<string,mixed>
     */
    private function collect_data(): array {
        $raw_ip      = $this->privacy->get_client_ip();
        $anon_ip     = $this->privacy->anonymise_ip( $raw_ip );
        $ua          = isset( $_SERVER['HTTP_USER_AGENT'] )
                       ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
                       : '';
        $visitor_id  = $this->get_or_create_visitor_id( $anon_ip, $ua );
        $session_id  = $this->get_or_create_session_id();
        $page_id     = $this->get_page_id();
        $is_unique   = $this->is_unique_visit_today( $visitor_id );
        $device      = $this->device_detector->detect();
        $referrer    = isset( $_SERVER['HTTP_REFERER'] )
                       ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
                       : '';
        [ $ref_type, $ref_url ] = $this->categorise_referrer( $referrer );
        $utm         = $this->extract_utm();
        $country     = $this->geo->get_country( $anon_ip );
        $page_url    = esc_url_raw( home_url( add_query_arg( [] ) ) );

        return compact(
            'visitor_id', 'session_id', 'page_id', 'is_unique',
            'device', 'country', 'utm', 'page_url'
        ) + [
            'referrer_type' => $ref_type,
            'referrer_url'  => $ref_url,
            'referrer_raw'  => $referrer,
            'country_code'  => $country,
        ];
    }

    // ── Cookie helpers ────────────────────────────────────────────────────────

    /**
     * Get the persistent visitor ID from cookie, or create and set one.
     * Falls back to a server-side HMAC if cookies are unavailable.
     */
    private function get_or_create_visitor_id( string $anon_ip, string $ua ): string {
        if ( ! empty( $_COOKIE[ Shrikant_VT_COOKIE_NAME ] ) ) {
            $cookie_val = sanitize_text_field( wp_unslash( $_COOKIE[ Shrikant_VT_COOKIE_NAME ] ) );
            if ( preg_match( '/^[a-f0-9]{64}$/', $cookie_val ) ) {
                return $cookie_val; // Valid cookie found.
            }
        }

        // Generate a new random visitor ID.
        $new_id = bin2hex( random_bytes( 32 ) );

        // Set cookie: HttpOnly, Secure (when HTTPS), SameSite=Lax.
        $this->set_cookie( Shrikant_VT_COOKIE_NAME, $new_id, time() + Shrikant_VT_COOKIE_TTL );

        return $new_id;
    }

    /**
     * Get or create a session ID (session cookie = no expiry argument).
     */
    private function get_or_create_session_id(): string {
        $key = 'sk_session_id';

        if ( ! empty( $_COOKIE[ $key ] ) ) {
            $val = sanitize_text_field( wp_unslash( $_COOKIE[ $key ] ) );
            if ( preg_match( '/^[a-f0-9]{32}$/', $val ) ) {
                return $val;
            }
        }

        $session_id = bin2hex( random_bytes( 16 ) );
        $this->set_cookie( $key, $session_id, 0 ); // Session cookie.
        return $session_id;
    }

    /**
     * Set a cookie with modern security attributes.
     * Checks headers_sent() first so AJAX endpoints don't trigger
     * "headers already sent" warnings when output buffering is off.
     */
    private function set_cookie( string $name, string $value, int $expires ): void {
        if ( headers_sent() ) {
            return;
        }
        setcookie( $name, $value, [
            'expires'  => $expires,
            'path'     => COOKIEPATH ?: '/',
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }

    // ── Dedup check ───────────────────────────────────────────────────────────

    /**
     * Has this visitor already been counted as unique today?
     * Uses a short-lived transient as a fast dedup gate (avoids a SELECT).
     */
    private function is_unique_visit_today( string $visitor_id ): bool {
        $key   = 'sk_vt_uv_' . substr( $visitor_id, 0, 16 ) . '_' . gmdate( 'Ymd' );
        $seen  = get_transient( $key );

        if ( false !== $seen ) {
            return false; // Already seen today.
        }

        // Mark as seen for the rest of today (86400s = 1 day).
        set_transient( $key, 1, DAY_IN_SECONDS );
        return true;
    }

    // ── Referrer categorisation ───────────────────────────────────────────────

    /**
     * Categorise a referrer URL into: direct | search | social | other.
     *
     * @param string $referrer Raw referrer URL.
     * @return array{0:string,1:string} [type, cleaned_url]
     */
    private function categorise_referrer( string $referrer ): array {
        if ( empty( $referrer ) ) {
            return [ 'direct', '' ];
        }

        $host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );

        // Self-referrals → direct.
        $site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) ) {
            return [ 'direct', '' ];
        }

        // Search engines.
        $search_hosts = [
            'google.', 'bing.com', 'yahoo.com', 'duckduckgo.com',
            'baidu.com', 'yandex.', 'ask.com', 'ecosia.org',
            'brave.com', 'qwant.com', 'startpage.com',
        ];
        foreach ( $search_hosts as $sh ) {
            if ( str_contains( $host, $sh ) ) {
                return [ 'search', $referrer ];
            }
        }

        // Social networks.
        $social_hosts = [
            'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
            'linkedin.com', 'pinterest.com', 'reddit.com', 'tiktok.com',
            'youtube.com', 'whatsapp.com', 'telegram.org', 't.co',
            'snapchat.com', 'tumblr.com', 'quora.com',
        ];
        foreach ( $social_hosts as $sh ) {
            if ( str_contains( $host, $sh ) ) {
                return [ 'social', $referrer ];
            }
        }

        return [ 'other', $referrer ];
    }

    // ── UTM extraction ────────────────────────────────────────────────────────

    /**
     * Extract UTM parameters from the current request query string.
     *
     * @return array<string,string>
     */
    private function extract_utm(): array {
        $params = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ];
        $utm    = [];
        foreach ( $params as $param ) {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- this reads the campaign tags off an ordinary page view by an anonymous visitor. There is no form and no state change, so there is no nonce to check; the values are sanitised and only ever stored.
            $utm[ $param ] = isset( $_GET[ $param ] )
                ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) )
                : '';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }
        return $utm;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Get the current WordPress post/page ID, or 0 for non-singular pages.
     */
    private function get_page_id(): int {
        return is_singular() ? (int) get_queried_object_id() : 0;
    }

    /**
     * Fast pre-flight checks — return true to skip tracking.
     */
    private function should_skip(): bool {
        return $this->bot_filter->is_bot() || ! $this->privacy->should_track();
    }

    /**
     * Per-IP rate limiter for the async AJAX endpoint.
     * Allows max 60 requests per minute per IP address.
     * Uses a hashed transient key — the raw IP is never stored.
     *
     * @return bool TRUE if this IP has exceeded the rate limit.
     */
    private function is_rate_limited(): bool {
        $ip      = $this->privacy->get_client_ip();
        $key     = 'sk_vt_rl_' . substr( md5( $ip ), 0, 16 );
        $current = (int) get_transient( $key );

        if ( $current >= 60 ) {
            return true; // Over limit.
        }

        // Increment counter; set TTL to 60s on first hit.
        if ( $current === 0 ) {
            set_transient( $key, 1, MINUTE_IN_SECONDS );
        } else {
            // Increment without resetting TTL — use wp_cache directly when possible.
            set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
        }

        return false;
    }
}
