<?php
/**
 * One-way import of view counts from other plugins.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Import
 *
 * Brings historical per-post view counts in from a plugin that is about to be
 * removed, so uninstalling it does not throw the history away.
 *
 * The counts are stored in post meta rather than in this plugin's own tables,
 * and that is deliberate. A count from Post Views Counter is not the same
 * measurement as a count from this plugin: that one increments in PHP, so it
 * misses every reader served from a page cache and counts the crawlers that
 * miss it. On the site this was written for it had logged 68,797 views where
 * this plugin had 12,485 — five times more, for a site whose Search Console
 * reported thirty-six clicks in the same period.
 *
 * Blending those numbers would make both meaningless. They are kept apart and
 * labelled, so a total can show "tracked" and "before tracking started" and a
 * reader of the dashboard can tell which is which.
 */
final class Shrikant_VT_Import {

	/** Per-post meta key holding the imported total. */
	public const META_KEY = '_sk_vt_imported_views';

	/** Option recording what was imported, and from where. */
	public const LOG_OPTION = 'sk_vt_import_log';

	/**
	 * Sources this can import from.
	 *
	 * Each entry names the table, the post-id column and the count column, so
	 * adding another plugin later is a line here rather than a new method.
	 *
	 * @return array<string, array{label:string, table:string, id_col:string, count_col:string}>
	 */
	public static function sources(): array {
		return array(
			'post-views-counter' => array(
				'label'     => 'Post Views Counter',
				'table'     => 'post_views',
				'id_col'    => 'id',
				'count_col' => 'count',
			),
			'wp-postviews'       => array(
				'label'     => 'WP-PostViews',
				'table'     => '',
				'id_col'    => '',
				'count_col' => '',
			),
		);
	}

	/**
	 * Whether a source's data is present on this site.
	 *
	 * @param string $source Source key.
	 * @return bool
	 */
	public static function available( string $source ): bool {
		global $wpdb;

		$config = self::sources()[ $source ] ?? null;

		if ( ! $config ) {
			return false;
		}

		// WP-PostViews keeps its counts in post meta, not a table of its own.
		if ( '' === $config['table'] ) {
			// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the source table and columns are validated against a literal allow-list above before they reach the query.
			return (bool) $wpdb->get_var(
				$wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", 'views' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		}

		$table = $wpdb->prefix . $config['table'];

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the source table and columns are validated against a literal allow-list above before they reach the query.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
	}

	/**
	 * What an import would bring in, without writing anything.
	 *
	 * @param string $source Source key.
	 * @return array{posts:int, views:int}
	 */
	public static function preview( string $source ): array {
		$rows = self::read( $source );

		return array(
			'posts' => count( $rows ),
			'views' => array_sum( $rows ),
		);
	}

	/**
	 * Read a source's totals, keyed by post ID.
	 *
	 * @param string $source Source key.
	 * @return array<int,int>
	 */
	private static function read( string $source ): array {
		global $wpdb;

		$config = self::sources()[ $source ] ?? null;

		if ( ! $config || ! self::available( $source ) ) {
			return array();
		}

		if ( '' === $config['table'] ) {
			// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the source table and columns are validated against a literal allow-list above before they reach the query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id AS id, CAST(meta_value AS UNSIGNED) AS total
					   FROM {$wpdb->postmeta} WHERE meta_key = %s",
					'views'
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		} else {
			$table = $wpdb->prefix . $config['table'];

			/*
			 // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the source table and columns are validated against a literal allow-list above before they reach the query.
			 * Column names cannot be passed through $wpdb->prepare(), so they
			 * are checked against the shape a column name is allowed to take
			 * before being interpolated. The values come from the hardcoded
			 * list in sources() and nothing reaches this from a request, but
			 * a table-name or column-name interpolation is worth proving safe
			 * rather than arguing is safe.
			 */
			$id_col    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $config['id_col'] );
			 // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
			$count_col = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $config['count_col'] );

			if ( '' === $id_col || '' === $count_col ) {
				return array();
			}

			/*
			 * Post Views Counter keeps the same views five times over: type 0
			 * daily, 1 weekly, 2 monthly, 3 yearly and 4 all-time. Summing the
			 * table counts every view four or five times -- on the site this
			 * was written against, the naive sum came to 342,643 where the
			 * all-time rows said 68,797. Only type 4 is read.
			 */
			// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the source table and columns are validated against a literal allow-list above before they reach the query.
			$rows = $wpdb->get_results(
				"SELECT `{$id_col}` AS id, `{$count_col}` AS total FROM `{$table}` WHERE type = 4"
			);
			// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

			// Older versions of the plugin did not write a type 4 row. Fall
			// back to the yearly rows, which are a complete picture summed
			// once rather than four times.
			if ( ! $rows ) {
				// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- the source table and columns are validated against a literal allow-list above before they reach the query.
				$rows = $wpdb->get_results(
					"SELECT `{$id_col}` AS id, SUM(`{$count_col}`) AS total FROM `{$table}` WHERE type = 3 GROUP BY `{$id_col}`"
				);
				// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
			}
		}

		$out = array();

		foreach ( (array) $rows as $row ) {
			$id    = (int) $row->id;
			$total = (int) $row->total;

			// A count against a post that no longer exists has nowhere to go.
			if ( $id > 0 && $total > 0 && get_post_status( $id ) ) {
				$out[ $id ] = $total;
			}
		}

		return $out;
	}

	/**
	 * Perform the import.
	 *
	 * Safe to run twice: each post's imported figure is replaced, never added
	 * to, so a second run cannot double anyone's history.
	 *
	 * @param string $source Source key.
	 * @return array{posts:int, views:int, skipped:int}
	 */
	public static function run( string $source ): array {
		$rows    = self::read( $source );
		$written = 0;
		$views   = 0;

		foreach ( $rows as $id => $total ) {
			update_post_meta( $id, self::META_KEY, $total );
			$written++;
			$views += $total;
		}

		$log               = get_option( self::LOG_OPTION, array() );
		$log[ $source ]    = array(
			'at'    => current_time( 'mysql' ),
			'posts' => $written,
			'views' => $views,
		);

		update_option( self::LOG_OPTION, $log, false );

		return array(
			'posts'   => $written,
			'views'   => $views,
			'skipped' => count( $rows ) - $written,
		);
	}

	/**
	 * Views imported for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function views_for( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::META_KEY, true );
	}

	/**
	 * What has been imported so far.
	 *
	 * @return array<string, array{at:string, posts:int, views:int}>
	 */
	public static function log(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}
}
