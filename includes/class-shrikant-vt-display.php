<?php
/**
 * Showing the view count on the front end.
 *
 * @package Shrikant_Visitor_Tracker
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Class Shrikant_VT_Display
 *
 * Replaces what a view-counter plugin was doing on the front end, so one can
 * be removed without the number disappearing from every post.
 *
 * Three ways in, because sites differ: an automatic line after the content, a
 * shortcode for placing it by hand, and shrikant_vt_views() for a theme to call.
 * There is also a drop-in for Post Views Counter's own function, so a theme
 * that calls pvc_get_post_views() keeps working after that plugin is gone.
 */
final class Shrikant_VT_Display {

	public function __construct(
		private readonly Shrikant_VT_Stats $stats
	) {}

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_shortcode( 'sk_views', [ $this, 'shortcode' ] );
		add_filter( 'the_content', [ $this, 'maybe_append' ], 20 );
	}

	/**
	 * The number for a post, counting imported history.
	 *
	 * @param int|null $post_id Post ID, or null for the current post.
	 * @return int
	 */
	public function views( ?int $post_id = null ): int {
		$post_id = $post_id ?: get_the_ID();

		if ( ! $post_id ) {
			return 0;
		}

		return $this->stats->views_for_page( (int) $post_id )['total'];
	}

	/**
	 * Whether the automatic line should appear here.
	 */
	private function should_append(): bool {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return false;
		}

		$enabled = (array) apply_filters(
			'shrikant_vt_display_post_types',
			[ 'post' ]
		);

		return in_array( get_post_type(), $enabled, true );
	}

	/**
	 * Append the count after the content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function maybe_append( string $content ): string {
		if ( ! $this->should_append() ) {
			return $content;
		}

		/**
		 * Turn the automatic line off without unhooking anything.
		 *
		 * @param bool $show Whether to show it.
		 */
		if ( ! apply_filters( 'shrikant_vt_show_views', true ) ) {
			return $content;
		}

		return $content . $this->markup( (int) get_the_ID() );
	}

	/**
	 * [sk_views] — or [sk_views id="12" label="Reads:"]
	 *
	 * @param array<string,string>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'id'    => '',
				'label' => '',
				'raw'   => 'no',
			],
			(array) $atts,
			'sk_views'
		);

		$post_id = $atts['id'] ? (int) $atts['id'] : (int) get_the_ID();

		if ( ! $post_id ) {
			return '';
		}

		// raw="yes" gives the bare number, for use inside other markup.
		if ( 'yes' === $atts['raw'] ) {
			return esc_html( (string) $this->views( $post_id ) );
		}

		return $this->markup( $post_id, $atts['label'] );
	}

	/**
	 * The rendered line.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $label   Override for the label.
	 * @return string
	 */
	private function markup( int $post_id, string $label = '' ): string {
		$views = $this->views( $post_id );

		if ( $views < 1 ) {
			return '';
		}

		$label = $label !== ''
			? $label
			: (string) apply_filters( 'shrikant_vt_views_label', __( 'Views:', 'shrikant-visitor-tracker' ) );

		return sprintf(
			'<p class="sk-vt-views"><span class="sk-vt-views-label">%1$s</span> <span class="sk-vt-views-count">%2$s</span></p>',
			esc_html( $label ),
			esc_html( number_format_i18n( $views ) )
		);
	}
}

/**
 * Views for a post, for themes to call.
 *
 * @param int|null $post_id Post ID, or null for the current post.
 * @return int
 */
function shrikant_vt_views( ?int $post_id = null ): int {
	$display = Shrikant_Visitor_Tracker::get_instance()->get( 'display' );

	return $display instanceof Shrikant_VT_Display ? $display->views( $post_id ) : 0;
}

/*
 * A theme that was built against Post Views Counter calls this. Defining it
 * only when that plugin is gone means removing it does not white-screen a
 * site, and does not collide while both are still installed.
 */
if ( ! function_exists( 'pvc_get_post_views' ) ) {
	/**
	 * Drop-in for Post Views Counter's function.
	 *
	 * @param int|null $post_id Post ID.
	 * @return int
	 */
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the name is the point: it stands in for Post Views Counter's own function so a theme that calls it keeps working. Prefixing it would defeat the shim, and it is only declared when that plugin is absent.
	function pvc_get_post_views( $post_id = null ): int {
		return shrikant_vt_views( $post_id ? (int) $post_id : null );
	}
}
