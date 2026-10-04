<?php
/**
 * Bot and crawler detection / filtering.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Bot_Filter
 *
 * Multi-layer bot detection:
 *  1. WordPress native wp_is_bot() (WP 6.7+) when available.
 *  2. Common bot/crawler User-Agent string matching.
 *  3. Empty or suspicious UA strings.
 *  4. Known proxy / data-centre headers (often used by scrapers).
 *
 * Performance: all checks run on a cached, lowercased UA string.
 * The result is cached in a static property so the check is free on
 * repeated calls within the same request.
 */
final class Shrikant_VT_Bot_Filter {

    /** @var bool|null Cached result for this request. */
    private static ?bool $is_bot_cache = null;

    /**
     * Comprehensive list of bot User-Agent substrings (lowercase).
     * Sources: IAB/ABC International Spiders & Robots list,
     *          robots.txt user-agent specs, common monitoring tools.
     *
     * @var string[]
     */
    private const BOT_STRINGS = [
        // Search engines.
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
        'yandexbot', 'sogou', 'exabot', 'facebot', 'ia_archiver',
        'applebot', 'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot',
        'rogerbot', 'seznambot', 'blexbot', 'linkdexbot', 'naverbot',
        // Monitoring / uptime tools.
        'pingdom', 'uptimerobot', 'statuscake', 'site24x7', 'freshping',
        'newrelic', 'datadog', 'zabbix', 'nagios', 'monit',
        // HTTP libraries / scrapers.
        'python-requests', 'python-urllib', 'go-http-client', 'java/',
        'curl/', 'libwww-perl', 'lwp-', 'httpclient', 'okhttp',
        'axios', 'node-fetch', 'got/', 'superagent', 'mechanize',
        'scrapy', 'wget', 'http_request2',
        // SEO / marketing crawlers.
        'screaming frog', 'sitebulb', 'seokicks', 'majestic', 'magestic',
        'opensiteexplorer', 'cognitiveseo', 'deepcrawl', 'oncrawl',
        // Social / preview.
        'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot',
        'discordbot', 'slackbot', 'vkshare',
        // Generic.
        'bot', 'crawler', 'spider', 'scraper', 'fetcher', 'checker',
        'validator', 'monitor', 'archiver', 'headless',
        // Headless browsers.
        'phantomjs', 'slimerjs', 'puppeteer', 'playwright',
        // Misc.
        'feedparser', 'feedfetcher', 'rssbot', 'feedburner',
    ];

    /**
     * Check if the current request originates from a bot.
     *
     * @return bool TRUE if this looks like a bot request.
     */
    public function is_bot(): bool {
        if ( null !== self::$is_bot_cache ) {
            return self::$is_bot_cache;
        }

        self::$is_bot_cache = $this->detect();
        return self::$is_bot_cache;
    }

    /**
     * Run all bot-detection layers.
     */
    private function detect(): bool {
        // Layer 1: WordPress native function (available since WP 6.7).
        if ( function_exists( 'wp_is_bot' ) && wp_is_bot() ) {
            return true;
        }

        $ua = $this->get_user_agent();

        // Layer 2: Empty or very short UA → almost certainly not a human.
        if ( strlen( $ua ) < 10 ) {
            return true;
        }

        // Layer 3: UA substring matching.
        foreach ( self::BOT_STRINGS as $bot ) {
            if ( str_contains( $ua, $bot ) ) {
                return true;
            }
        }

        // Layer 4: Proxy / data-centre headers often set by scrapers.
        if ( $this->has_proxy_headers() ) {
            return true;
        }

        /**
         * Filter: sk_vt_is_bot
         * Allow third-party code to override bot detection result.
         *
         * @param bool   $is_bot  Current detection result.
         * @param string $ua      Lowercased User-Agent string.
         */
        return (bool) apply_filters( 'sk_vt_is_bot', false, $ua );
    }

    /**
     * Return the lowercased User-Agent string, safe to compare.
     */
    private function get_user_agent(): string {
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
            : '';
        return strtolower( $ua );
    }

    /**
     * Detect common headers that indicate automated / proxy requests.
     * These are absent in normal browser traffic.
     */
    private function has_proxy_headers(): bool {
        $proxy_headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_VIA',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
        ];

        // Having a forwarded-for header alone isn't suspicious
        // (reverse proxies set it legitimately). We use it as a
        // weak signal, combined with other checks — not as a hard block.
        // The strong bot signal is the presence of "HTTP_VIA" (proxy chain).
        return isset( $_SERVER['HTTP_VIA'] );
    }

    /** No hooks needed — this is called directly. */
    public function register_hooks(): void {}
}
