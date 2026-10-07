<?php
/**
 * Check the plugin against a real WordPress install.
 *
 * Not a unit test suite — it boots WordPress, renders every admin screen and
 * compares the figures the reports produce against the rows in the database.
 * That is what has actually caught things here: a chart that never drew, a
 * cache serving one date range's numbers for another, a settings form that
 * dropped the window when you searched.
 *
 *   php tools/verify.php                     # finds WordPress by walking up
 *   php tools/verify.php /path/to/wordpress
 *
 * This file lives in the repository on purpose. Three earlier versions were
 * written into a temp directory and lost.
 */

$root = $argv[1] ?? null;

if ( ! $root ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			$root = $dir;
			break;
		}
		$dir = dirname( $dir );
	}
}

if ( ! $root || ! file_exists( rtrim( $root, '/' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php. Pass the WordPress root as the first argument.\n" );
	exit( 2 );
}

// Before wp-config, so WordPress really runs in debug mode and its own
// _doing_it_wrong and _deprecated_* notices actually fire.
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_USE_THEMES', false );

$caught = [];
set_error_handler(
	static function ( $no, $str, $file, $line ) use ( &$caught ) {
		$caught[] = "$str — " . basename( $file ) . ":$line";
		return false;
	}
);

require rtrim( $root, '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );

global $wpdb;

$fail = 0;
/**
 * @param string $label What is being checked.
 * @param mixed  $value What was found, for the eye.
 * @param bool   $pass  Whether it is right.
 */
function check( string $label, $value, bool $pass ): void {
	global $fail;
	if ( ! $pass ) {
		$fail++;
	}
	printf( "%-52s %-26s %s\n", $label, substr( (string) $value, 0, 24 ), $pass ? 'ok' : 'FAIL' );
}

$inst  = Shrikant_Visitor_Tracker::get_instance();
$stats = $inst->get( 'stats' );
$admin = new Shrikant_VT_Admin( $stats, $inst->get( 'settings' ), $inst->get( 'online' ) );
$raw   = $wpdb->prefix . Shrikant_VT_TABLE_RAW;
$sum   = $wpdb->prefix . Shrikant_VT_TABLE_SUM;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%shrikant_vt_%'" );

// ── The reports agree with the rows ─────────────────────────────────────────
$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw}" );
$roll = (int) $wpdb->get_var( "SELECT SUM(pageviews) FROM {$sum} WHERE dimension_key = 'total'" );
check( 'summaries match the raw rows', "{$roll} vs {$rows}", $roll === $rows );

$first = (string) $wpdb->get_var( "SELECT DATE(MIN(period_start)) FROM {$sum}" );
$win   = [ 'from' => $first ?: '2000-01-01', 'to' => gmdate( 'Y-m-d' ) ];

foreach ( [ 'device_breakdown', 'browser_breakdown', 'os_breakdown', 'traffic_sources' ] as $method ) {
	$total = array_sum( $stats->$method( 3650, $win ) );
	check( "{$method} accounts for every view", "{$total} vs {$rows}", $total === $rows );
}

$daily = $stats->daily_series( 3650, $win );
check( 'daily series sums to the total', array_sum( array_column( $daily, 'pageviews' ) ) . " vs {$rows}", array_sum( array_column( $daily, 'pageviews' ) ) === $rows );

// A cache keyed only on the day count would hand one range's rows to another.
$a = $stats->top_pages( 5, 30, [ 'from' => $win['from'], 'to' => $win['from'] ] );
$b = $stats->top_pages( 5, 30, $win );
check( 'two windows do not share a cache', ( $a === $b && $rows > 0 ) ? 'SHARED' : 'separate', ! ( $a === $b && $rows > 0 ) );

// ── Consent and defaults, which the directory cares about ───────────────────
$saved = get_option( 'shrikant_vt_settings' );
delete_option( 'shrikant_vt_settings' );
$fresh = new Shrikant_VT_Settings();
check( 'a fresh install does not phone home', $fresh->geo_enabled() ? 'geo ON' : 'geo off', ! $fresh->geo_enabled() );
check( 'a fresh install keeps all data', $fresh->retention_days() . ' days', 0 === $fresh->retention_days() );
check( 'uninstall is not destructive', $fresh->delete_data_on_uninstall() ? 'DESTRUCTIVE' : 'keeps data', ! $fresh->delete_data_on_uninstall() );
if ( false !== $saved ) {
	update_option( 'shrikant_vt_settings', $saved, false );
}

// ── Nothing weakly prefixed is registered ───────────────────────────────────
$src = '';
foreach ( glob( Shrikant_VT_DIR . '{,includes/}*.php', GLOB_BRACE ) as $file ) {
	$src .= file_get_contents( $file );
}
check( 'no sk-vt REST namespace', substr_count( $src, "'sk-vt/" ), 0 === substr_count( $src, "'sk-vt/" ) );
check( 'no sk_views shortcode', substr_count( $src, "'sk_views'" ), 0 === substr_count( $src, "'sk_views'" ) );
check( 'no <style> printed in admin', preg_match( '/\?>\s*<style>/', $src ) ? 'FOUND' : 'none', ! preg_match( '/\?>\s*<style>/', $src ) );

// ── Every screen renders ────────────────────────────────────────────────────
foreach ( [ 'render_dashboard', 'render_pages', 'render_utm', 'render_settings', 'render_import', 'render_help' ] as $method ) {
	$_GET = [ 'days' => '365' ];
	ob_start();
	$error = null;
	try {
		$admin->$method();
	} catch ( Throwable $e ) {
		$error = $e->getMessage();
	}
	$html = ob_get_clean();
	$_GET = [];
	check( "screen {$method}", $error ?: strlen( $html ) . ' bytes', null === $error && strlen( $html ) > 400 );
}

$_GET = [ 'from' => $win['from'], 'to' => $win['to'] ];
ob_start();
$admin->render_pages();
$html = ob_get_clean();
$_GET = [];
check( 'date filter renders', strlen( $html ) . ' bytes', strlen( $html ) > 400 );
check( 'and marks no preset as current', preg_match( '/<div class="sk-vt-seg".*?<\/div>/s', $html, $seg ) && 0 === preg_match_all( '/aria-current/', $seg[0] ) ? 'correct' : 'preset marked',
	preg_match( '/<div class="sk-vt-seg".*?<\/div>/s', $html, $seg ) && 0 === preg_match_all( '/aria-current/', $seg[0] ) );

// The charts are drawn from localised data, so a page can honour a window
// while its charts still show the old one.
$_GET = [ 'from' => $win['from'], 'to' => $win['to'] ];
wp_scripts()->registered = [];
$admin->enqueue_assets( 'toplevel_page_shrikant-visitor-tracker' );
$localised = (string) wp_scripts()->get_data( 'shrikant-vt-admin', 'data' );
$_GET = [];
check( 'chart series reaches the script', strpos( $localised, '"trend"' ) !== false ? 'yes' : 'no', strpos( $localised, '"trend"' ) !== false );

$routes = array_filter( array_keys( rest_get_server()->get_routes() ), static fn( $r ) => str_starts_with( $r, '/shrikant-vt' ) );
check( 'REST routes registered', count( $routes ) . ' routes', count( $routes ) > 0 );

$mine = array_values( array_unique( array_filter( $caught, static fn( $e ) => stripos( $e, 'shrikant' ) !== false ) ) );
check( 'no warnings with WP_DEBUG on', $mine ? $mine[0] : 'none', empty( $mine ) );

echo "\n", 0 === $fail ? "ALL CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n";
exit( 0 === $fail ? 0 : 1 );
