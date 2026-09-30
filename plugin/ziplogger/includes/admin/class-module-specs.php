<?php
/**
 * Descriptions and field lists of the module screens.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every module screen states three things plainly: what data it collects, what it does to traffic,
 * and what it does to storage (queue size and ZipLogger volume).
 */
final class Module_Specs {

	/**
	 * Tabs that are module forms.
	 */
	const MODULE_TABS = array( 'browser', 'analytics', 'replay', 'tracing', 'woocommerce' );

	/**
	 * The description and fields of one module.
	 *
	 * @param string $module Module.
	 * @return array{title:string,intro:string,data:string,traffic:string,storage:string,fields:array[]}
	 */
	public static function get( $module ) {
		switch ( $module ) {
			case 'browser':
				return self::browser();
			case 'analytics':
				return self::analytics();
			case 'replay':
				return self::replay();
			case 'tracing':
				return self::tracing();
			case 'woocommerce':
				return self::woocommerce();
		}
		return array(
			'title'   => '',
			'intro'   => '',
			'data'    => '',
			'traffic' => '',
			'storage' => '',
			'fields'  => array(),
		);
	}

	/**
	 * Browser monitoring.
	 *
	 * @return array
	 */
	private static function browser() {
		return array(
			'title'   => __( 'Browser monitoring', 'ziplogger' ),
			'intro'   => __( 'Reports JavaScript errors, unhandled promise rejections, failing network requests and (optionally) page performance from your visitors\' browsers straight to ZipLogger, using the browser key. Nothing passes through your server.', 'ziplogger' ),
			'data'    => __( 'Error message and a sanitized stack trace, the page path (never the query string or fragment), the plugin version and a random per-page-view id. For requests: host, method, status and duration only. For performance: timings and Core Web Vitals numbers. No cookies are set and nothing is stored in the browser; identifiers live in memory for one page view. URLs, emails, tokens and IP-like values in messages are masked in the browser before sending.', 'ziplogger' ),
			'traffic' => __( 'One script (about 23 KB compressed, measured at build time; see assets/js/manifest.json), loaded once and cached. Data is sent in batches only when something is reported. Sampling below limits how many page views report performance.', 'ziplogger' ),
			'storage' => __( 'Each report is one log entry in ZipLogger and counts toward your ingest volume. Errors are capped per page view.', 'ziplogger' ),
			'fields'  => array(
				array(
					'key'            => 'enabled',
					'type'           => 'checkbox',
					'label'          => __( 'Browser monitoring', 'ziplogger' ),
					'checkbox_label' => __( 'Load the monitoring script and report to ZipLogger', 'ziplogger' ),
				),
				array(
					'key'            => 'errors',
					'type'           => 'checkbox',
					'label'          => __( 'JavaScript errors', 'ziplogger' ),
					'checkbox_label' => __( 'Report uncaught errors and unhandled promise rejections', 'ziplogger' ),
				),
				array(
					'key'   => 'error_sample_rate',
					'type'  => 'number',
					'label' => __( 'Error sampling', 'ziplogger' ),
					'unit'  => '%',
					'min'   => 0,
					'max'   => 100,
					'help'  => __( 'Share of page views that report errors. 100 reports every error.', 'ziplogger' ),
				),
				array(
					'key'   => 'max_errors_page',
					'type'  => 'number',
					'label' => __( 'Errors per page view', 'ziplogger' ),
					'min'   => 1,
					'max'   => 200,
					'help'  => __( 'A page stuck in an error loop reports at most this many distinct errors.', 'ziplogger' ),
				),
				array(
					'key'            => 'failed_requests',
					'type'           => 'checkbox',
					'label'          => __( 'Failed requests', 'ziplogger' ),
					'checkbox_label' => __( 'Report fetch and XMLHttpRequest calls that fail or return a server error', 'ziplogger' ),
				),
				array(
					'key'            => 'slow_requests',
					'type'           => 'checkbox',
					'label'          => __( 'Slow requests', 'ziplogger' ),
					'checkbox_label' => __( 'Report a sample of slow fetch and XMLHttpRequest calls', 'ziplogger' ),
				),
				array(
					'key'   => 'slow_threshold_ms',
					'type'  => 'number',
					'label' => __( 'Slow request threshold', 'ziplogger' ),
					'unit'  => 'ms',
					'min'   => 200,
					'max'   => 60000,
					'step'  => 100,
				),
				array(
					'key'            => 'navigation_timing',
					'type'           => 'checkbox',
					'label'          => __( 'Navigation timing', 'ziplogger' ),
					'checkbox_label' => __( 'Report page-load timings (DNS, connection, server response, load)', 'ziplogger' ),
				),
				array(
					'key'            => 'web_vitals',
					'type'           => 'checkbox',
					'label'          => __( 'Core Web Vitals', 'ziplogger' ),
					'checkbox_label' => __( 'Report LCP, INP, CLS, FCP and TTFB (Google\'s web-vitals library)', 'ziplogger' ),
				),
				array(
					'key'   => 'perf_sample_rate',
					'type'  => 'number',
					'label' => __( 'Performance sampling', 'ziplogger' ),
					'unit'  => '%',
					'min'   => 0,
					'max'   => 100,
					'help'  => __( 'Share of page views that report timings, vitals and slow requests. The choice is made once per page view.', 'ziplogger' ),
				),
			),
		);
	}

	/**
	 * Product analytics.
	 *
	 * @return array
	 */
	private static function analytics() {
		return array(
			'title'   => __( 'Product analytics', 'ziplogger' ),
			'intro'   => __( 'Sends page views and the interactions you choose to ZipLogger as product-analytics events, and exposes a small JavaScript API for your own events.', 'ziplogger' ),
			'data'    => __( 'Event name, page path (no query string), timestamp, a random anonymous visitor id and a random session id. Nothing else is read from the page: not text, not form values, not every click. With "identify" on, logged-in users also get an opaque keyed hash of their WordPress user id - never an email address or username.', 'ziplogger' ),
			'traffic' => __( 'Small batches sent from the browser while the visitor browses, and when the page is hidden.', 'ziplogger' ),
			'storage' => __( 'Each event counts toward your ZipLogger event allowance. The visitor id is kept in your visitors\' browser storage (localStorage) once the analytics consent policy allows it.', 'ziplogger' ),
			'fields'  => array(
				array(
					'key'            => 'enabled',
					'type'           => 'checkbox',
					'label'          => __( 'Product analytics', 'ziplogger' ),
					'checkbox_label' => __( 'Send analytics events to ZipLogger', 'ziplogger' ),
				),
				array(
					'key'            => 'page_views',
					'type'           => 'checkbox',
					'label'          => __( 'Page views', 'ziplogger' ),
					'checkbox_label' => __( 'Send an event when a page is viewed', 'ziplogger' ),
				),
				array(
					'key'            => 'spa_navigation',
					'type'           => 'checkbox',
					'label'          => __( 'Client-side navigation', 'ziplogger' ),
					'checkbox_label' => __( 'Also count page changes made without a reload (History API)', 'ziplogger' ),
				),
				array(
					'key'            => 'interactions',
					'type'           => 'checkbox',
					'label'          => __( 'Interaction events', 'ziplogger' ),
					'checkbox_label' => __( 'Track clicks on the elements listed below and on elements with a data-ziplogger-event attribute', 'ziplogger' ),
				),
				array(
					'key'         => 'interaction_selectors',
					'type'        => 'textarea',
					'label'       => __( 'Tracked elements', 'ziplogger' ),
					'placeholder' => ".newsletter-signup\n#buy-now",
					'help'        => __( 'One CSS selector per line (up to 20). Only clicks on a matching element are tracked; the event carries the selector, never the element\'s text or values. Give an element data-ziplogger-event="name" to name the event yourself.', 'ziplogger' ),
				),
				array(
					'key'            => 'identify',
					'type'           => 'checkbox',
					'label'          => __( 'Identify logged-in users', 'ziplogger' ),
					'checkbox_label' => __( 'Link a signed-in user\'s events with a pseudonymous id (a keyed hash, not their email or username)', 'ziplogger' ),
				),
				array(
					'key'   => 'sample_rate',
					'type'  => 'number',
					'label' => __( 'Sampling', 'ziplogger' ),
					'unit'  => '%',
					'min'   => 0,
					'max'   => 100,
					'help'  => __( 'Share of visitor sessions that send analytics. 100 tracks everyone.', 'ziplogger' ),
				),
			),
		);
	}

	/**
	 * Session replay.
	 *
	 * @return array
	 */
	private static function replay() {
		return array(
			'title'   => __( 'Session replay', 'ziplogger' ),
			'intro'   => __( 'Records a sample of visitor sessions with ZipLogger\'s official replay recorder so you can watch what happened before an error. It is separate from every other module and off until you switch it on here.', 'ziplogger' ),
			'data'    => __( 'A recording of the page as the visitor sees it: structure, clicks, scrolling and typing activity. All input values are masked and this cannot be turned off; password and payment fields are never recorded. By default all text is masked too. WordPress admin, login, account, cart, checkout and payment pages are never recorded, and you can add more exclusions. Cross-origin iframes (for example payment card fields hosted by your payment provider) cannot be recorded by any browser-side tool.', 'ziplogger' ),
			'traffic' => __( 'The recorder script (about 23 KB compressed, measured at build time) is downloaded only for sessions that are sampled in, consented and on an eligible page. Recordings upload in compressed chunks every few seconds.', 'ziplogger' ),
			'storage' => __( 'Replay is metered separately in ZipLogger (sessions and stored bytes). The limits below cap one session\'s length and size.', 'ziplogger' ),
			'fields'  => array(
				array(
					'key'            => 'enabled',
					'type'           => 'checkbox',
					'label'          => __( 'Session replay', 'ziplogger' ),
					'checkbox_label' => __( 'Record sampled sessions', 'ziplogger' ),
				),
				array(
					'key'   => 'sample_rate',
					'type'  => 'number',
					'label' => __( 'Sampling', 'ziplogger' ),
					'unit'  => '%',
					'min'   => 0,
					'max'   => 100,
					'help'  => __( 'Share of sessions recorded, decided once per session.', 'ziplogger' ),
				),
				array(
					'key'            => 'mask_all_text',
					'type'           => 'checkbox',
					'label'          => __( 'Mask all text', 'ziplogger' ),
					'checkbox_label' => __( 'Replace all text on the page with placeholder characters in the recording (recommended)', 'ziplogger' ),
					'help'           => __( 'Turn this off only if every page you record shows public content. Input values stay masked either way.', 'ziplogger' ),
				),
				array(
					'key'         => 'mask_selector',
					'type'        => 'textarea',
					'label'       => __( 'Additionally mask', 'ziplogger' ),
					'placeholder' => ".member-name\n[data-private]",
					'help'        => __( 'CSS selectors (one per line) whose text is masked. Elements with data-ziplogger-mask are always masked.', 'ziplogger' ),
				),
				array(
					'key'         => 'block_selector',
					'type'        => 'textarea',
					'label'       => __( 'Block completely', 'ziplogger' ),
					'placeholder' => ".video-embed\n#map",
					'help'        => __( 'CSS selectors (one per line) that are replaced by an empty box. Elements with data-ziplogger-ignore are always blocked.', 'ziplogger' ),
				),
				array(
					'key'         => 'exclude_paths',
					'type'        => 'textarea',
					'label'       => __( 'Never record these pages', 'ziplogger' ),
					'placeholder' => "/members/*\n/private",
					'help'        => __( 'URL paths starting with "/" (one per line, * matches anything). Added to the built-in exclusions, which cannot be removed: wp-admin, login, registration, password reset, my account, cart, checkout, order pay and order received.', 'ziplogger' ),
				),
				array(
					'key'   => 'exclude_roles',
					'type'  => 'roles',
					'label' => __( 'Never record users with these roles', 'ziplogger' ),
					'help'  => __( 'Logged-in users holding any of these roles are not recorded.', 'ziplogger' ),
				),
				array(
					'key'   => 'max_minutes',
					'type'  => 'number',
					'label' => __( 'Longest recording', 'ziplogger' ),
					'unit'  => __( 'minutes', 'ziplogger' ),
					'min'   => 1,
					'max'   => 60,
				),
				array(
					'key'   => 'max_megabytes',
					'type'  => 'number',
					'label' => __( 'Largest recording', 'ziplogger' ),
					'unit'  => 'MB',
					'min'   => 1,
					'max'   => 50,
					'help'  => __( 'Uncompressed size after which a session stops recording.', 'ziplogger' ),
				),
			),
		);
	}

	/**
	 * Tracing.
	 *
	 * @return array
	 */
	private static function tracing() {
		return array(
			'title'   => __( 'Distributed tracing', 'ziplogger' ),
			'intro'   => __( 'Records how long WordPress takes to answer requests, and the outbound HTTP calls it makes while doing so, as OpenTelemetry traces in ZipLogger. Requests that already carry a trace context from the browser are joined to it.', 'ziplogger' ),
			'data'    => __( 'For a sampled request: method, a route template (for example "single" or "/wc/v3/orders/(?P<id>)"), response status, duration, the WordPress request type (front end, admin, REST, AJAX, cron) and a count of database queries. For outbound calls: host, method, status and duration - never the path or query string. The URL path of your own pages is added only if you switch that on below.', 'ziplogger' ),
			'traffic' => __( 'Spans are queued locally and sent by the same background job as logs. The trace header is added to outbound requests only for the hosts you list below, never to other third parties.', 'ziplogger' ),
			'storage' => __( 'Spans share your ZipLogger ingest volume with logs. Sampling controls how many requests produce a trace; the local queue keeps at most half of its capacity for spans.', 'ziplogger' ),
			'fields'  => array(
				array(
					'key'            => 'enabled',
					'type'           => 'checkbox',
					'label'          => __( 'Tracing', 'ziplogger' ),
					'checkbox_label' => __( 'Send traces to ZipLogger', 'ziplogger' ),
				),
				array(
					'key'            => 'server_spans',
					'type'           => 'checkbox',
					'label'          => __( 'Request spans', 'ziplogger' ),
					'checkbox_label' => __( 'One span per sampled WordPress request', 'ziplogger' ),
				),
				array(
					'key'            => 'outbound_spans',
					'type'           => 'checkbox',
					'label'          => __( 'Outbound calls', 'ziplogger' ),
					'checkbox_label' => __( 'A child span for each outbound wp_remote_*() call in a sampled request', 'ziplogger' ),
				),
				array(
					'key'   => 'sample_rate',
					'type'  => 'number',
					'label' => __( 'Sampling', 'ziplogger' ),
					'unit'  => '%',
					'min'   => 0,
					'max'   => 100,
					'help'  => __( 'Share of requests that start a new trace. A request that arrives with a trace context follows the caller\'s sampling decision instead.', 'ziplogger' ),
				),
				array(
					'key'         => 'propagate_hosts',
					'type'        => 'textarea',
					'label'       => __( 'Send trace headers to', 'ziplogger' ),
					'placeholder' => "api.example.com\n*.internal-services.example.com",
					'help'        => __( 'Host names (one per line) that receive a W3C traceparent header on outbound requests. Empty means none. Only traceparent is sent, never baggage or cookies.', 'ziplogger' ),
				),
				array(
					'key'         => 'service_name',
					'type'        => 'text',
					'label'       => __( 'Service name', 'ziplogger' ),
					'placeholder' => 'wordpress',
					'help'        => __( 'How this site appears in ZipLogger traces. Defaults to the site label.', 'ziplogger' ),
				),
				array(
					'key'            => 'record_path',
					'type'           => 'checkbox',
					'label'          => __( 'URL path', 'ziplogger' ),
					'checkbox_label' => __( 'Also record the request path (no query string)', 'ziplogger' ),
					'help'           => __( 'Paths can contain personal data such as member slugs. Off by default; the route template is always recorded.', 'ziplogger' ),
				),
				array(
					'key'            => 'db_query_count',
					'type'           => 'checkbox',
					'label'          => __( 'Database query count', 'ziplogger' ),
					'checkbox_label' => __( 'Record how many queries WordPress ran (free: read from the database class, nothing is measured)', 'ziplogger' ),
				),
				array(
					'key'            => 'db_timing',
					'type'           => 'checkbox',
					'label'          => __( 'Database timing (experimental)', 'ziplogger' ),
					'checkbox_label' => __( 'On sampled requests only, also measure total query time', 'ziplogger' ),
					'help'           => __( 'Uses WordPress\'s query log on sampled requests, which has a real cost: it keeps every query in memory. Covers queries made through $wpdb after this plugin loads; earlier queries and direct database connections are not covered. Query text is never recorded.', 'ziplogger' ),
				),
				array(
					'key'            => 'browser',
					'type'           => 'checkbox',
					'label'          => __( 'Browser to server', 'ziplogger' ),
					'checkbox_label' => __( 'Trace this site\'s own fetch/XHR calls in the browser and link them to the server request', 'ziplogger' ),
					'help'           => __( 'Adds a traceparent header to same-site requests (WordPress AJAX, REST, WooCommerce Store API). Spans are sent from the browser with the browser key. Page-load requests are not linked, because pages may be cached and must not contain a trace id.', 'ziplogger' ),
				),
			),
		);
	}

	/**
	 * WooCommerce.
	 *
	 * @return array
	 */
	private static function woocommerce() {
		return array(
			'title'   => __( 'WooCommerce', 'ziplogger' ),
			'intro'   => __( 'Tracks the shopping journey and the business outcomes that matter. Interactions (product views, cart changes, checkout) come from the browser; order, payment and refund outcomes come from your server, so they keep working when analytics scripts are blocked.', 'ziplogger' ),
			'data'    => __( 'Currency, amounts (in the currency\'s smallest unit), quantities and product ids or SKUs (your choice), plus an opaque order reference - a keyed hash, never the order number, order key or order id. Never sent: names, addresses, email addresses, phone numbers, order notes, payment details or custom order fields.', 'ziplogger' ),
			'traffic' => __( 'Server events are queued locally and sent by the background job. Browser events are sent by the analytics script, subject to your consent settings.', 'ziplogger' ),
			'storage' => __( 'Each event counts toward your ZipLogger event allowance. Every event has a deterministic id, so repeated hooks, retries and page reloads never create duplicates.', 'ziplogger' ),
			'fields'  => array(
				array(
					'key'            => 'enabled',
					'type'           => 'checkbox',
					'label'          => __( 'WooCommerce events', 'ziplogger' ),
					'checkbox_label' => __( 'Send WooCommerce events to ZipLogger', 'ziplogger' ),
				),
				array(
					'key'            => 'product_views',
					'type'           => 'checkbox',
					'label'          => __( 'Product views', 'ziplogger' ),
					'checkbox_label' => __( 'A product page was viewed (browser)', 'ziplogger' ),
				),
				array(
					'key'            => 'cart_events',
					'type'           => 'checkbox',
					'label'          => __( 'Cart', 'ziplogger' ),
					'checkbox_label' => __( 'Product added to or removed from the cart (browser)', 'ziplogger' ),
				),
				array(
					'key'            => 'checkout',
					'type'           => 'checkbox',
					'label'          => __( 'Checkout', 'ziplogger' ),
					'checkbox_label' => __( 'Checkout started (browser)', 'ziplogger' ),
				),
				array(
					'key'            => 'orders',
					'type'           => 'checkbox',
					'label'          => __( 'Orders', 'ziplogger' ),
					'checkbox_label' => __( 'An order was created (server)', 'ziplogger' ),
				),
				array(
					'key'            => 'payments',
					'type'           => 'checkbox',
					'label'          => __( 'Payments', 'ziplogger' ),
					'checkbox_label' => __( 'A payment was confirmed, or failed (server; the thank-you page is never treated as proof of payment)', 'ziplogger' ),
				),
				array(
					'key'            => 'status_changes',
					'type'           => 'checkbox',
					'label'          => __( 'Order status', 'ziplogger' ),
					'checkbox_label' => __( 'An order changed status (server)', 'ziplogger' ),
				),
				array(
					'key'            => 'refunds',
					'type'           => 'checkbox',
					'label'          => __( 'Refunds', 'ziplogger' ),
					'checkbox_label' => __( 'An order was refunded, in full or in part (server)', 'ziplogger' ),
				),
				array(
					'key'     => 'product_identifier',
					'type'    => 'select',
					'label'   => __( 'Product identifier', 'ziplogger' ),
					'options' => array(
						'id'   => __( 'Product id', 'ziplogger' ),
						'sku'  => __( 'SKU', 'ziplogger' ),
						'none' => __( 'None (quantities and amounts only)', 'ziplogger' ),
					),
					'help'    => __( 'What identifies a product in events. SKUs are sent as-is, so use ids if your SKUs contain anything private.', 'ziplogger' ),
				),
				array(
					'key'            => 'include_items',
					'type'           => 'checkbox',
					'label'          => __( 'Line items', 'ziplogger' ),
					'checkbox_label' => __( 'Include a short list of items (identifier, quantity, price) in order and refund events', 'ziplogger' ),
				),
			),
		);
	}
}
