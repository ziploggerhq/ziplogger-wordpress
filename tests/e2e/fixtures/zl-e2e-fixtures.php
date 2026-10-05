<?php
/**
 * Must-use plugin for end-to-end tests ONLY (mounted by docker-compose.e2e.yml, never shipped).
 *
 * Adds URLs that produce observable PHP problems on demand, so tests can check the plugin captures
 * them while WordPress keeps behaving normally:
 *
 *   /?zl_fixture=warning     a PHP warning, page still renders
 *   /?zl_fixture=notice      a PHP notice
 *   /?zl_fixture=exception   an uncaught exception  (WordPress shows its critical-error page)
 *   /?zl_fixture=fatal       a fatal error: call to an undefined function
 *   /?zl_fixture=oom         memory exhaustion (best effort)
 *   /?zl_fixture=loop        50 identical warnings
 *   /?zl_fixture=secret      a warning whose message contains secrets
 *   /?zl_fixture=http        an outbound HTTP request that fails
 *   /?zl_fixture=log         calls ziplogger_log()
 *
 * Browser fixtures (they add markup and scripts to whatever page WordPress renders, at wp_footer):
 *
 *   /?zl_fixture=js_errors   an uncaught error, an unhandled rejection, failing and slow fetch/XHR requests
 *   /?zl_fixture=replay_page personal-looking text, inputs, a password field, a payment panel, an ignored
 *                            block, links with secrets, and content added after load
 *   /?zl_fixture=spa         two links that change the address with history.pushState (no reload)
 *
 * and REST routes under /wp-json/zl-e2e/v1/ : ok (echoes the trace headers it received), fail (HTTP 500),
 * slow (1.2 s) and third (a request that must never carry trace headers).
 */

add_action(
	'rest_api_init',
	static function () {
		$echo = static function () {
			$h = array();
			foreach ( array( 'HTTP_TRACEPARENT' => 'traceparent', 'HTTP_BAGGAGE' => 'baggage' ) as $server => $name ) {
				if ( ! empty( $_SERVER[ $server ] ) ) { // phpcs:ignore
					$h[ $name ] = (string) $_SERVER[ $server ]; // phpcs:ignore
				}
			}
			return new WP_REST_Response( array( 'ok' => true, 'received' => (object) $h ), 200 );
		};
		register_rest_route( 'zl-e2e/v1', '/ok', array( 'methods' => 'GET,POST', 'callback' => $echo, 'permission_callback' => '__return_true' ) );
		register_rest_route( 'zl-e2e/v1', '/fail', array( 'methods' => 'GET,POST', 'callback' => static function () { return new WP_REST_Response( array( 'error' => 'boom with secret token=abc123' ), 500 ); }, 'permission_callback' => '__return_true' ) );
		register_rest_route( 'zl-e2e/v1', '/slow', array( 'methods' => 'GET', 'callback' => static function () { usleep( 1200000 ); return new WP_REST_Response( array( 'ok' => true ), 200 ); }, 'permission_callback' => '__return_true' ) );
	}
);

add_action(
	'wp_footer',
	static function () {
		$fixture = isset( $_GET['zl_fixture'] ) ? preg_replace( '/[^a-z_]/', '', (string) $_GET['zl_fixture'] ) : ''; // phpcs:ignore
		if ( 'js_errors' === $fixture ) {
			?>
<script>
window.addEventListener('DOMContentLoaded', function () {
	setTimeout(function () { throw new Error('e2e uncaught error for jane@example.com token=abc123secret'); }, 50);
	setTimeout(function () { Promise.reject(new Error('e2e unhandled rejection')); }, 80);
	setTimeout(function () { fetch('/wp-json/zl-e2e/v1/fail?_nonce=NONCESECRET&email=jane@example.com', { method: 'POST', body: 'card=4242424242424242', headers: { Authorization: 'Bearer TOPSECRETBEARER' } }).then(function (r) { window.__failStatus = r.status; }); }, 100);
	setTimeout(function () { var x = new XMLHttpRequest(); x.open('GET', '/wp-json/zl-e2e/v1/fail?xhr=1&secret=XHRSECRET'); x.onloadend = function () { window.__xhrStatus = x.status; }; x.send(); }, 120);
	setTimeout(function () { fetch('/wp-json/zl-e2e/v1/ok').then(function (r) { return r.json(); }).then(function (j) { window.__okBody = j; }); }, 140);
	setTimeout(function () { fetch('/wp-json/zl-e2e/v1/slow').then(function (r) { window.__slowStatus = r.status; }); }, 160);
	setTimeout(function () { fetch('/nothing-here-404?x=1').then(function (r) { window.__notFound = r.status; }); }, 180);
	window.__fixtureReady = true;
});
</script>
			<?php
		}
		if ( 'replay_page' === $fixture ) {
			?>
<div id="zl-replay-fixture" style="padding:20px;font-family:sans-serif">
	<h1>Order for Jane Customer</h1>
	<p class="pii">Reach me at jane.customer@example.com or 4242 4242 4242 4242 (Visa)</p>
	<label>Name <input id="zl-name" type="text" value="Jane Customer"></label>
	<label>Password <input id="zl-pw" type="password" value="hunter2-SECRET"></label>
	<label>Card <input id="zl-cc" type="text" autocomplete="cc-number" value="4111111111111111"></label>
	<label>Notes <textarea id="zl-notes">TEXTAREA-PRIVATE-NOTE</textarea></label>
	<div class="woocommerce-checkout-payment">PAYMENT-PANEL-SECRET</div>
	<div data-ziplogger-ignore>IGNORED-BLOCK-SECRET</div>
	<div data-ziplogger-mask>MARKED-MASK-SECRET</div>
	<address>221B Baker Street ADDRESS-SECRET</address>
	<a id="zl-mail" href="mailto:jane.customer@example.com?subject=hello">Write to us</a>
	<a id="zl-tokenlink" href="/thing?token=LINKSECRETTOKEN&amp;x=1#frag">A link</a>
	<div id="zl-dynamic-host"></div>
	<button id="zl-add" type="button">Add dynamic content</button>
</div>
<script>
window.addEventListener('DOMContentLoaded', function () {
	function addDynamic() {
		var p = document.createElement('p');
		p.id = 'zl-dyn-' + Date.now();
		p.textContent = 'DYNAMIC-PRIVATE-TEXT for Jane';
		var input = document.createElement('input');
		input.type = 'text';
		input.value = 'DYNAMIC-INPUT-SECRET';
		var a = document.createElement('a');
		a.href = '/dynamic?session=DYNAMICLINKSECRET';
		a.textContent = 'dynamic link';
		var host = document.getElementById('zl-dynamic-host');
		host.appendChild(p); host.appendChild(input); host.appendChild(a);
	}
	document.getElementById('zl-add').addEventListener('click', addDynamic);
	setTimeout(addDynamic, 400);
	window.__fixtureReady = true;
});
</script>
			<?php
		}
		if ( 'spa' === $fixture ) {
			?>
<nav><a id="go-a" href="/spa-a/?coupon=SECRETCOUPON">A</a> <a id="go-b" href="/spa-b/">B</a> <a id="go-checkout" href="/checkout/">Checkout</a> <a id="go-home" href="/?zl_fixture=spa">Home</a></nav>
<script>
document.addEventListener('click', function (e) {
	var a = e.target.closest && e.target.closest('nav a');
	if (!a) { return; }
	e.preventDefault();
	history.pushState({}, '', a.getAttribute('href'));
	document.title = 'Route ' + a.textContent;
});
window.__fixtureReady = true;
</script>
			<?php
		}
	}
);

add_action(
	'init',
	static function () {
		if ( empty( $_GET['zl_fixture'] ) ) { // phpcs:ignore
			return;
		}
		$fixture = preg_replace( '/[^a-z_]/', '', (string) $_GET['zl_fixture'] ); // phpcs:ignore

		switch ( $fixture ) {
			case 'warning':
				trigger_error( 'e2e fixture warning', E_USER_WARNING ); // phpcs:ignore
				break;
			case 'notice':
				trigger_error( 'e2e fixture notice', E_USER_NOTICE ); // phpcs:ignore
				break;
			case 'exception':
				throw new RuntimeException( 'e2e fixture uncaught exception' );
			case 'fatal':
				zl_e2e_undefined_function(); // phpcs:ignore
				break;
			case 'oom':
				ini_set( 'memory_limit', '24M' ); // phpcs:ignore
				$zl = array();
				while ( true ) {
					$zl[] = str_repeat( 'x', 1048576 );
				}
			case 'loop':
				for ( $i = 0; $i < 50; $i++ ) {
					trigger_error( 'e2e fixture repeated warning', E_USER_WARNING ); // phpcs:ignore
				}
				break;
			case 'secret':
				trigger_error( 'e2e failed for jane.doe@example.com password=hunter2 token=abc123secret from 203.0.113.9 via https://user:pw@example.com/x?key=SECRETKEY', E_USER_WARNING ); // phpcs:ignore
				break;
			case 'http':
				wp_remote_get( 'https://unreachable.invalid/hook/SECRET-PATH?token=abc', array( 'timeout' => 2 ) );
				break;
			case 'log':
				if ( function_exists( 'ziplogger_log' ) ) {
					ziplogger_log( 'error', 'Background import failed', array( 'processed_count' => 42, 'password' => 'do-not-send' ) );
				}
				break;
			case 'outbound':
				// Three real HTTP requests: one that works, one that fails, one whose URL carries secrets.
				wp_remote_get( 'http://thirdparty.example.org:5081/third-party/ok?token=SECRETQUERY', array( 'timeout' => 5 ) );
				wp_remote_post( 'http://thirdparty.example.org:5081/third-party/hooks/T000/B000/SECRETWEBHOOK', array( 'timeout' => 5, 'body' => 'payload=SECRETBODY' ) );
				wp_remote_get( 'http://thirdparty.example.org:9/refused', array( 'timeout' => 3 ) );
				echo 'outbound done';
				exit;
		}
	},
	1
);

// ---------------------------------------------------------------------------------------------------------
// WooCommerce fixtures: a test payment gateway (classic checkout) and a "gateway webhook" that finishes,
// fails or refunds an order from outside the shopper's session (no cookies, no browser), like a real
// payment provider's callback.
// ---------------------------------------------------------------------------------------------------------

add_action(
	'woocommerce_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}
		/**
		 * A gateway whose outcome is decided by the option zl_e2e_gateway_outcome: success | failure | pending.
		 */
		class ZL_E2E_Gateway extends WC_Payment_Gateway {
			public function __construct() {
				$this->id           = 'zl_e2e';
				$this->method_title = 'ZipLogger E2E gateway';
				$this->title        = 'Test card';
				$this->has_fields   = false;
				$this->enabled      = 'yes';
				$this->supports     = array( 'products' );
			}

			public function is_available() {
				return true;
			}

			public function process_payment( $order_id ) {
				$order   = wc_get_order( $order_id );
				$outcome = get_option( 'zl_e2e_gateway_outcome', 'success' );
				if ( 'failure' === $outcome ) {
					$order->update_status( 'failed', 'Card declined (test).' );
					wc_add_notice( 'Your card was declined (test).', 'error' );
					return array( 'result' => 'failure' );
				}
				if ( 'pending' === $outcome ) {
					$order->update_status( 'on-hold', 'Waiting for the provider webhook (test).' );
				} else {
					$order->payment_complete( 'zl_txn_' . $order_id );
				}
				WC()->cart->empty_cart();
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
		}
	}
);

add_filter(
	'woocommerce_payment_gateways',
	static function ( $gateways ) {
		if ( class_exists( 'ZL_E2E_Gateway' ) ) {
			$gateways[] = 'ZL_E2E_Gateway';
		}
		return $gateways;
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'zl-e2e/v1',
			'/gateway-webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $request->get_param( 'order_id' ) ) : null;
					if ( ! $order ) {
						return new WP_REST_Response( array( 'error' => 'no such order' ), 404 );
					}
					$outcome = (string) $request->get_param( 'outcome' );
					if ( 'paid' === $outcome ) {
						$order->payment_complete( 'txn_webhook_' . $order->get_id() );
					} elseif ( 'failed' === $outcome ) {
						$order->update_status( 'failed', 'Provider reported a failure (test).' );
					} elseif ( 'refund' === $outcome ) {
						$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => (string) $request->get_param( 'amount' ), 'reason' => 'webhook refund' ) );
						if ( is_wp_error( $refund ) ) {
							return new WP_REST_Response( array( 'error' => $refund->get_error_message() ), 400 );
						}
					} elseif ( 'status' === $outcome ) {
						$order->update_status( (string) $request->get_param( 'to' ) );
					}
					return new WP_REST_Response( array( 'ok' => true, 'status' => $order->get_status() ), 200 );
				},
			)
		);
	}
);

// Debug aid (only while an option is set): a log of the order hooks, to understand what WooCommerce fires.
add_action( 'woocommerce_new_order', static function ( $id, $order = null ) {
	if ( get_option( 'zl_hook_log_on' ) ) { $l = (array) get_option( 'zl_hook_log', array() ); $l[] = 'new_order ' . $id . ' status=' . ( $order ? $order->get_status( 'edit' ) : '?' ); update_option( 'zl_hook_log', $l, false ); }
}, 1, 2 );
add_action( 'woocommerce_order_status_changed', static function ( $id, $from, $to ) {
	if ( get_option( 'zl_hook_log_on' ) ) { $l = (array) get_option( 'zl_hook_log', array() ); $l[] = 'status_changed ' . $id . ' ' . $from . '->' . $to; update_option( 'zl_hook_log', $l, false ); }
}, 1, 3 );

// Performance runs compare configurations request by request, interleaved, so that drift in the machine cannot
// pass for a cost of the plugin. ?zl_cfg=<id> applies one stored configuration to THAT REQUEST ONLY:
//   inactive   the plugin is removed from the active list (the WordPress + WooCommerce baseline);
//   any other  the plugin's settings are replaced by entry <id> of the option zl_perf_cfgs (written by
//              tests/perf/*.mjs).
// The id comes from the query string (one request) or from the cookie zl_cfg (a whole browser session: the page's
// own follow-up requests, such as the visitor-context call, must see the same configuration as the page).
// Nothing is written to the database by a measured request, and no cron job is spawned during one.
$zl_cfg = '';
if ( ! empty( $_GET['zl_perf'] ) || ! empty( $_COOKIE['zl_perf'] ) ) { // phpcs:ignore
	$zl_raw = isset( $_GET['zl_cfg'] ) ? $_GET['zl_cfg'] : ( isset( $_COOKIE['zl_cfg'] ) ? $_COOKIE['zl_cfg'] : '' ); // phpcs:ignore
	$zl_cfg = is_string( $zl_raw ) ? preg_replace( '/[^a-z0-9_-]/i', '', $zl_raw ) : '';
}
if ( '' !== $zl_cfg ) {
	add_filter( 'pre_get_ready_cron_jobs', '__return_empty_array' );
	if ( 'inactive' === $zl_cfg ) {
		add_filter(
			'option_active_plugins',
			static function ( $plugins ) {
				return array_values( array_diff( (array) $plugins, array( 'ziplogger-error-monitoring-session-replay/ziplogger.php' ) ) );
			}
		);
	} else {
		add_filter(
			'pre_option_ziplogger_settings',
			static function ( $pre ) use ( $zl_cfg ) {
				$map = get_option( 'zl_perf_cfgs' );
				return is_array( $map ) && isset( $map[ $zl_cfg ] ) ? $map[ $zl_cfg ] : $pre;
			}
		);
	}
}

// Performance probe: with ?zl_perf=1 one JSON line per request is appended to wp-content/zl-perf.log at the very
// end of the request - after every shutdown function the plugin registered - holding the milliseconds since PHP
// received the request, the number of database queries and the peak memory. These are measured by PHP itself,
// so they include the plugin's shutdown work (queue insert, span write) and exclude the network and the client.
add_action(
	'shutdown',
	static function () {
		if ( empty( $_GET['zl_perf'] ) ) { // phpcs:ignore
			return;
		}
		// Registered while the shutdown action runs: PHP calls it after every function registered before it.
		register_shutdown_function(
			static function () {
				file_put_contents( // phpcs:ignore
					WP_CONTENT_DIR . '/zl-perf.log',
					wp_json_encode( array(
						'cfg'     => isset( $_GET['zl_cfg'] ) ? preg_replace( '/[^a-z0-9_-]/i', '', (string) $_GET['zl_cfg'] ) : '', // phpcs:ignore
						'ms'      => round( ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 2 ),
						'queries' => function_exists( 'get_num_queries' ) ? get_num_queries() : 0,
						'peak'    => memory_get_peak_usage( true ),
						'used'    => memory_get_peak_usage( false ),
					) ) . "
",
					FILE_APPEND
				);
			}
		);
	},
	PHP_INT_MAX
);
