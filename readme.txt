=== Shrikant Visitor Tracker ===
Contributors: shrikantgaur
Tags: analytics, visitors, statistics, privacy, gdpr
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted visitor analytics that still counts correctly behind a page cache. Import from your old counter without losing its history.

== Description ==

Most view counters add up in PHP while the page is being built. On a site with a page cache that means the readers served from cache are never counted at all, while the crawlers that bypass the cache are counted every time — so the number drifts away from reality in both directions at once.

This one counts from the browser, after the page has loaded, and filters known bots before recording anything. The figure it shows is usually much lower than the plugin it replaced, and much closer to what Search Console reports.

= Switching from another counter =

Deleting a view counter normally throws its history away. The **Import** screen reads the totals out of Post Views Counter or WP-PostViews first, so you can remove them and keep the numbers. Imported counts are stored separately and never mixed into this plugin's own statistics — the two measure different things, and blending them would make both untrustworthy.

For the same reason, **deleting this plugin does not delete its data** unless you switch that on in Settings. Swapping one copy of a plugin for another goes through the same Delete button as saying goodbye to it.

= Everything else =

Shrikant Visitor Tracker gives you complete control over your visitor analytics data — everything is stored in your own database, nothing leaves your server (except a geo-IP lookup to the free ip-api.com API, which is cached for ≥24 hours).

= Key Features =

* **Total / daily / weekly / monthly / yearly visitors** with page-level tracking
* **Device, browser, and OS detection** via lightweight User-Agent parsing (no library)
* **Referrer categorisation** (direct, search, social, other) + full **UTM parameter tracking**
* **Country detection** via ip-api.com (free, no API key, IP anonymised before sending, cached ≥24h)
* **Real-time "currently online" counter** using WordPress transients
* **Counts correctly behind a page cache** — a non-blocking browser beacon, not a PHP counter, so WP Rocket, LiteSpeed, W3 Total Cache and Cloudflare do not hide your readers
* **Pre-aggregated summary table** — hourly WP-Cron job keeps dashboard queries fast regardless of traffic volume
* **Advanced bot filtering** — UA string matching + WordPress native `wp_is_bot()` + proxy headers
* **Privacy-first** — IP anonymisation, DNT header respect, cookie consent hook, GDPR data export/erasure
* **REST API** — full stats API under `/wp-json/sk-vt/v1/` for headless or custom integrations
* **WP-CLI commands** — `wp sk-vt stats today`, `wp sk-vt export`, `wp sk-vt cleanup --dry-run`, and more
* **Import from Post Views Counter or WP-PostViews** — switch without losing the history
* **Your data survives uninstall** by default
* **Zero paid dependencies** — 100% free and open source, nothing loaded from a CDN

= Privacy & GDPR =

* Raw IP addresses are **never stored**
* With IP anonymisation enabled (default), the last IPv4 octet is zeroed before any geo lookup
* Visitor IDs are HMAC-SHA256 hashes that rotate daily — they cannot be reversed to identify real people
* DNT header is respected by default
* Integrates with any cookie consent plugin via the `sk_vt_has_consent` filter
* Compatible with WordPress's built-in personal data export/erasure tools

= Developer Hooks =

* `sk_vt_before_track_visit` — modify or abort tracking
* `sk_vt_after_insert` — react to new visit rows
* `sk_vt_has_consent` — integrate cookie consent plugins
* `sk_vt_is_bot` — override bot detection
* `sk_vt_get_stats` — filter any stats result
* `sk_vt_default_settings` — add custom settings

== Installation ==

1. Upload the `sk-visitor-tracker` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **SK Analytics** in the admin sidebar to view your dashboard
4. Visit **SK Analytics → Settings** to configure tracking options

== Frequently Asked Questions ==

= Does this plugin share data with third parties? =

Only for country detection: if geo lookup is enabled, the anonymised IP address (last octet zeroed) is sent to ip-api.com's free API. The result is cached for at least 24 hours. All other data stays on your server.

= Is this compatible with caching plugins? =

Yes — enable **Async Tracking** in settings (it's on by default). The tracking pixel fires client-side via XMLHttpRequest after the cached page loads in the visitor's browser.

= How does uniqueness work? =

Each visitor gets a long-lived cookie (2 years, HttpOnly, Secure, SameSite=Lax). A visit is counted as "unique" once per visitor per day using a transient-based dedup gate — no SELECT query on every page view.

= Is it compatible with WordPress Multisite? =

Yes. Each sub-site gets its own prefixed database tables and settings.

= Can I use this with WP-CLI? =

Yes. Run `wp sk-vt stats today`, `wp sk-vt top-pages --limit=20`, `wp sk-vt export --from=2025-01-01 --file=visits.csv`, and more.


== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release — activate and visit SK Analytics in your admin sidebar.
