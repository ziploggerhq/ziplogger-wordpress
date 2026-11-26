<?php
/**
 * Plugin Name: ZipLogger demo shop: live panels
 * Description: For the ziplogger.dev demo only. A public, read-only REST route that shows visitors what the demo site has sent to ZipLogger (Web Vitals, JavaScript and server errors, request statistics, recent traces), built by the ZipLogger plugin's own dashboard panels with the read key on the server.
 * Version: 1.0.0
 *
 * GET /wp-json/ziplogger-demo/v1/live
 *
 * Safety:
 *  - The read key never leaves the server; the panels are the plugin's own, scoped to this site's source label.
 *  - The answer is cached for five minutes, so visitors can never turn into a query load on ZipLogger.
 *  - The HTML is filtered again here: links into the ZipLogger app (which visitors cannot open) and any
 *    attribute that is not on the list below are removed.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'ziplogger-demo/v1',
			'/live',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => 'ziplogger_demo_live_panels',
			)
		);
	}
);

/**
 * The panels, as filtered HTML.
 *
 * @return array|WP_Error
 */
function ziplogger_demo_live_panels() {
	$panels_class = '\ZipLogger\WordPress\Dashboard\Panels';
	if ( ! class_exists( $panels_class ) ) {
		return new WP_Error( 'ziplogger_demo_unavailable', 'The ZipLogger plugin is not active.', array( 'status' => 503 ) );
	}
	$cached = get_transient( 'ziplogger_demo_live_v4' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$titles = array(
		'vitals'         => 'Core Web Vitals, 75th percentile',
		'browser_errors' => 'Recent JavaScript errors',
		'errors'         => 'Recent server errors',
		'tracing_stats'  => 'Server requests (sampled)',
		'traces'         => 'Recent traces',
	);
	$allowed = array(
		'table'      => array( 'class' => true ),
		'caption'    => array( 'class' => true ),
		'thead'      => array(),
		'tbody'      => array(),
		'tr'         => array(),
		'th'         => array( 'scope' => true ),
		'td'         => array(),
		'p'          => array( 'class' => true ),
		'span'       => array( 'class' => true ),
		'time'       => array( 'datetime' => true ),
		'br'         => array(),
		'h4'         => array(),
		'ul'         => array( 'class' => true ),
		'li'         => array(),
		'figure'     => array( 'class' => true ),
		'figcaption' => array(),
		'details'    => array(),
		'summary'    => array(),
		'svg'        => array( 'viewbox' => true, 'width' => true, 'height' => true, 'role' => true, 'aria-label' => true ),
		'polyline'   => array( 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'points' => true ),
	);

	$out = array(
		'generated' => gmdate( 'c' ),
		'panels'    => array(),
	);
	foreach ( $titles as $id => $title ) {
		try {
			$r = call_user_func( array( $panels_class, 'render' ), $id, false );
		} catch ( \Throwable $e ) {
			$r = array( 'ok' => false, 'html' => '', 'error' => '' );
		}
		$ok   = is_array( $r ) && ! empty( $r['ok'] );
		$html = $ok ? (string) $r['html'] : '<p class="description">This panel is not available right now.</p>';
		// Links into the ZipLogger app need a ZipLogger login: keep their text, drop the link.
		$html = preg_replace( '#<p>\s*<a [^>]*>[^<]*</a>\s*</p>#', '', $html );
		// The administrator's own requests (wp-admin, admin-ajax, cron, login, REST admin) are not part of the demo.
		$html = preg_replace( '#<li>(?:(?!</li>).)*(?:wp-admin|admin-ajax|wp-cron|wp-login|code-snippets|sg-ai-studio|sg-cachepress|ziplogger-demo/|/wp/v2/)(?:(?!</li>).)*</li>#s', '', $html );
		$out['panels'][] = array(
			'id'    => $id,
			'title' => $title,
			'ok'    => $ok,
			'html'  => wp_kses( $html, $allowed ),
		);
	}
	set_transient( 'ziplogger_demo_live_v4', $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}
