<?php
/**
 * Lightweight device, browser, and OS detection via User-Agent parsing.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Device_Detector
 *
 * Parses the HTTP User-Agent string to extract:
 *   - Device type  : mobile | tablet | desktop
 *   - Browser      : Chrome | Firefox | Safari | Edge | Opera | IE | Other
 *   - OS           : Windows | macOS | Linux | Android | iOS | Other
 *
 * Design decisions:
 * ─────────────────
 * • Zero external dependencies — regex-based UA parsing only.
 * • Single public method detect() returns a typed array; result is
 *   memoised per-request via a static cache.
 * • Intentionally not exhaustive: covers >95% of real-world traffic
 *   without the overhead of a full UA parser library.
 * • Uses WordPress wp_is_mobile() as a quick first-pass signal.
 */
final class Shrikant_VT_Device_Detector {

    /** @var array{device:string,browser:string,os:string}|null */
    private static ?array $cache = null;

    /**
     * Detect device type, browser, and OS from the current request UA.
     *
     * @return array{device:string,browser:string,os:string}
     */
    public function detect(): array {
        if ( null !== self::$cache ) {
            return self::$cache;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        self::$cache = [
            'device'  => $this->detect_device( $ua ),
            'browser' => $this->detect_browser( $ua ),
            'os'      => $this->detect_os( $ua ),
        ];

        return self::$cache;
    }

    // ── Device type ───────────────────────────────────────────────────────────

    /**
     * Detect device type.
     * Order matters: tablet check must precede mobile (iPads report "Mobile").
     */
    private function detect_device( string $ua ): string {
        $ua_lower = strtolower( $ua );

        // Tablets: iPad or Android devices without "Mobile" but with "Android".
        if (
            str_contains( $ua_lower, 'ipad' ) ||
            ( str_contains( $ua_lower, 'android' ) && ! str_contains( $ua_lower, 'mobile' ) ) ||
            str_contains( $ua_lower, 'tablet' ) ||
            str_contains( $ua_lower, 'kindle' ) ||
            str_contains( $ua_lower, 'silk' )
        ) {
            return 'tablet';
        }

        // Mobile: leverage WordPress helper + extra patterns.
        if (
            wp_is_mobile() ||
            str_contains( $ua_lower, 'iphone' ) ||
            str_contains( $ua_lower, 'ipod' ) ||
            str_contains( $ua_lower, 'blackberry' ) ||
            str_contains( $ua_lower, 'windows phone' )
        ) {
            return 'mobile';
        }

        return 'desktop';
    }

    // ── Browser ───────────────────────────────────────────────────────────────

    /**
     * Detect browser from UA.
     * Order is critical because many browsers include each other's tokens.
     * Rule: most-specific patterns first.
     */
    private function detect_browser( string $ua ): string {
        // Edge (Chromium-based) — must come before Chrome.
        if ( preg_match( '/Edg\//i', $ua ) ) {
            return 'Edge';
        }
        // Samsung Internet — must come before Chrome.
        if ( str_contains( $ua, 'SamsungBrowser' ) ) {
            return 'Samsung Internet';
        }
        // Opera (new, OPR token) — must come before Chrome.
        if ( str_contains( $ua, 'OPR/' ) || str_contains( $ua, 'Opera' ) ) {
            return 'Opera';
        }
        // Chrome / Chromium — must come before Safari.
        if ( preg_match( '/Chrome\/[\d.]+/i', $ua ) ) {
            return 'Chrome';
        }
        // Firefox.
        if ( str_contains( $ua, 'Firefox/' ) ) {
            return 'Firefox';
        }
        // Safari — UA contains "Safari" but NOT "Chrome" (already caught above).
        if ( str_contains( $ua, 'Safari/' ) ) {
            return 'Safari';
        }
        // Internet Explorer.
        if ( str_contains( $ua, 'MSIE' ) || str_contains( $ua, 'Trident/' ) ) {
            return 'IE';
        }

        return 'Other';
    }

    // ── Operating system ──────────────────────────────────────────────────────

    /**
     * Detect OS from UA.
     */
    private function detect_os( string $ua ): string {
        // Mobile OSes first (Android UA also contains Linux).
        if ( str_contains( $ua, 'Android' ) ) {
            return 'Android';
        }
        if ( str_contains( $ua, 'iPhone' ) || str_contains( $ua, 'iPad' ) || str_contains( $ua, 'iPod' ) ) {
            return 'iOS';
        }
        // Desktop OSes.
        if ( str_contains( $ua, 'Windows NT' ) || str_contains( $ua, 'Windows Phone' ) ) {
            return 'Windows';
        }
        if ( str_contains( $ua, 'Macintosh' ) || str_contains( $ua, 'Mac OS X' ) ) {
            return 'macOS';
        }
        if ( str_contains( $ua, 'Linux' ) ) {
            return 'Linux';
        }
        if ( str_contains( $ua, 'CrOS' ) ) {
            return 'Chrome OS';
        }

        return 'Other';
    }

    /** No hooks needed. */
    public function register_hooks(): void {}
}
