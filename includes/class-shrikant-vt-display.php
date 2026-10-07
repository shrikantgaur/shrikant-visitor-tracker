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
		private readonly Shrikant_VT_Stats $stats,
		private readonly ?Shrikant_VT_Settings $settings = null
	) {}

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_shortcode( 'shrikant_views', [ $this, 'shortcode' ] );
		add_filter( 'the_content', [ $this, 'maybe_append' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	/**
	 * The few rules the count needs, printed inline.
	 *
	 * Registered against no file, so this adds a handful of bytes to the page
	 * and no request. It exists because of one rule the icon cannot win any
	 * other way: Tailwind's Preflight, which a great many themes ship, resets
	 * svg to display:block. That drops the icon onto its own line above the
	 * text. The obvious fix -- display:inline-block in the element's style
	 * attribute -- does not survive wp_kses, which strips display from inline
	 * styles, so it has to be a rule in a stylesheet.
	 *
	 * A class beats Preflight's bare `svg` on specificity, so none of this
	 * needs !important.
	 */
	public function enqueue_styles(): void {
		wp_register_style( 'shrikant-vt-views', false, [], Shrikant_VT_VERSION );
		wp_enqueue_style( 'shrikant-vt-views' );
		wp_add_inline_style(
			'shrikant-vt-views',
			'.sk-vt-views-icon{display:inline-block;width:1em;height:1em;vertical-align:-.125em;margin-right:.35em;flex:none}'
			. '.sk-vt-views{display:flex;align-items:center;gap:0;flex-wrap:wrap}'
			. '.sk-vt-views-label{margin-right:.3em}'
		);
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

		// Settings → "Show the count to readers". The filter still has the
		// final say, so a theme can override whatever is chosen there.
		if ( $this->settings && ! $this->settings->auto_display() ) {
			return false;
		}

		$chosen = $this->settings ? $this->settings->display_post_types() : [ 'post' ];

		/**
		 * Filter: shrikant_vt_display_post_types
		 *
		 * @param array<int,string> $chosen Post types chosen in Settings.
		 */
		$enabled = (array) apply_filters( 'shrikant_vt_display_post_types', $chosen );

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
	 * [shrikant_views] — or [shrikant_views id="12" label="Reads:"]
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
			'shrikant_views'
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
			'<p class="sk-vt-views">%1$s<span class="sk-vt-views-label">%2$s</span> <span class="sk-vt-views-count">%3$s</span></p>',
			$this->icon( $post_id ),
			esc_html( $label ),
			esc_html( number_format_i18n( $views ) )
		);
	}

	/**
	 * The small eye before the count.
	 *
	 * Inline rather than an icon font or an image, because this plugin loads
	 * no stylesheet on the front end at all and should not start loading one
	 * for a single glyph. It is drawn in currentColor and sized in em, so it
	 * takes the colour and size of whatever text the theme puts it in.
	 *
	 * Hidden from screen readers: the label beside it already says "Views",
	 * and an icon that repeats the label is noise to anyone listening.
	 *
	 * @param int $post_id Post being rendered.
	 * @return string Markup, or an empty string when switched off.
	 */
	private function icon( int $post_id ): string {
		$default = '<svg class="sk-vt-views-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"'
			. ' width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2"'
			. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"'
			. ' style="vertical-align:-0.125em;margin-right:0.35em">'
			. '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"></path>'
			. '<circle cx="12" cy="12" r="3"></circle>'
			. '</svg>';

		/**
		 * Filter: shrikant_vt_views_icon
		 *
		 * Return your own markup, or an empty string for no icon at all.
		 *
		 * @param string $svg     Default inline SVG.
		 * @param int    $post_id Post being rendered.
		 */
		$icon = (string) apply_filters( 'shrikant_vt_views_icon', $default, $post_id );

		if ( '' === trim( $icon ) ) {
			return '';
		}

		// The filter can return anything, so what gets printed is limited to
		// the handful of SVG tags and attributes an icon actually needs.
		return wp_kses(
			$icon,
			[
				'svg'    => [
					'class' => true, 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true,
					'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true,
					'stroke-linejoin' => true, 'aria-hidden' => true, 'focusable' => true, 'role' => true,
					'style' => true,
				],
				'path'   => [ 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true ],
				'circle' => [ 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true ],
				'g'      => [ 'fill' => true, 'stroke' => true ],
			]
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
