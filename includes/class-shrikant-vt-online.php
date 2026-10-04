<?php
/**
 * Real-time "currently online users" counter using transients.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Online
 *
 * Tracks which unique visitor IDs have been active within the configured
 * inactivity window (default 5 minutes).
 *
 * Implementation:
 * ───────────────
 * We store a single transient 'sk_vt_online_visitors' that holds a
 * JSON-encoded array of { visitor_id => last_seen_timestamp } pairs.
 *
 * On each tracked request:
 *  1. Load the transient (O(1) if object cache is available).
 *  2. Write/update the current visitor's entry.
 *  3. Prune entries older than online_ttl.
 *  4. Save back with TTL = online_ttl.
 *
 * Object-cache note:
 * ──────────────────
 * With Redis or Memcached, the transient lives entirely in memory — zero
 * DB writes. Without an object cache, every visit = one option-table upsert
 * (wp_options). For very high traffic, consider a Redis-based counter.
 *
 * Scalability note for 50k+ req/day:
 * ────────────────────────────────────
 * At extreme traffic, replace this with a Redis ZADD + ZCOUNT pattern:
 *   ZADD sk_vt_online {timestamp} {visitor_id}
 *   ZCOUNT sk_vt_online {now-ttl} {now}
 * That eliminates PHP-side pruning entirely.
 */
final class Shrikant_VT_Online {

    private const TRANSIENT_KEY = 'sk_vt_online_visitors';

    public function __construct(
        private readonly Shrikant_VT_Settings $settings
    ) {}

    /**
     * Record a visitor as "currently online".
     *
     * @param string $visitor_id Opaque visitor identifier.
     */
    public function record( string $visitor_id ): void {
        $ttl  = $this->settings->online_ttl();
        $now  = time();

        // Load existing online map.
        $online = $this->load();

        // Update this visitor's last-seen timestamp.
        $online[ $visitor_id ] = $now;

        // Prune stale entries in the same pass (avoid a second write cycle).
        $cutoff = $now - $ttl;
        $online = array_filter( $online, static fn( int $ts ) => $ts >= $cutoff );

        set_transient( self::TRANSIENT_KEY, $online, $ttl );
    }

    /**
     * Get the current count of online visitors.
     *
     * @return int
     */
    public function get_count(): int {
        $ttl    = $this->settings->online_ttl();
        $cutoff = time() - $ttl;
        $online = $this->load();

        return count( array_filter( $online, static fn( int $ts ) => $ts >= $cutoff ) );
    }

    /**
     * Load the online map from cache.
     *
     * @return array<string,int> visitor_id → timestamp
     */
    private function load(): array {
        $data = get_transient( self::TRANSIENT_KEY );
        return is_array( $data ) ? $data : [];
    }

    /** No hooks needed. */
    public function register_hooks(): void {}
}
