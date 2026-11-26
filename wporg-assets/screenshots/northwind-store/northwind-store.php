<?php
/**
 * Plugin Name: Northwind Store Tools (screenshots only)
 * Description: Produces the kind of problems a real shop has, so the ZipLogger screenshots show realistic data. Never shipped.
 * Version: 1.0.0
 */

// Server side: real PHP problems on specific pages, the way a buggy theme or shop plugin would cause them.
add_action(
	'template_redirect',
	static function () {
		if ( is_page( 'wholesale' ) ) {
			// A price arrives as "12,50" from a CSV import; PHP 8 refuses it.
			$row = array( 'price' => '12,50' );
			echo esc_html( number_format( $row['price'], 2 ) ); // TypeError, uncaught.
		}
		if ( is_page( 'subscriptions' ) ) {
			$options = array( 'size' => '1kg' );
			$grind   = $options['grind']; // Warning: undefined array key.
			unset( $grind );
		}
		if ( is_page( 'gift-cards' ) ) {
			throw new RuntimeException( 'Gift card balance lookup failed: provider returned HTTP 502' );
		}
		if ( is_page( 'order-status' ) ) {
			$order = null; // The order lookup found nothing and nobody checked.
			echo esc_html( $order->get_status() ); // Error: call to a member function on null.
		}
		if ( is_page( 'newsletter' ) && function_exists( 'ziplogger_log' ) ) {
			ziplogger_log( 'error', 'Newsletter sync failed: mailing list API returned 401 Unauthorized', array( 'list' => 'weekly-roasts', 'subscribers_pending' => 37 ) );
		}
		if ( is_page( 'brew-guides' ) ) {
			// A call to a third-party API that fails (traced as an outbound span).
			wp_remote_get( 'http://mock:5081/supplier/v2/inventory', array( 'timeout' => 3 ) );
		}
	}
);

// WP-Cron cannot loop back to this demo host inside the container, so deliver the queue the way the cron job
// would, before a ZipLogger screen renders. (On a real site WP-Cron or a system cron does this.)
add_action(
	'admin_init',
	static function () {
		if ( isset( $_GET['page'] ) && 'ziplogger' === $_GET['page'] && class_exists( '\ZipLogger\WordPress\Worker' ) ) { // phpcs:ignore
			( new \ZipLogger\WordPress\Worker() )->run( array( 'force' => true ) );
		}
	}
);

// REST endpoints the shop's front end calls.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'shop/v1',
			'/delivery-slots',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					usleep( 650000 );
					return new WP_REST_Response( array( 'slots' => array( 'Tue 9-12', 'Wed 13-17' ) ), 200 );
				},
			)
		);
		register_rest_route(
			'shop/v1',
			'/cart',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					if ( function_exists( 'ziplogger_log' ) ) {
						ziplogger_log( 'error', 'Cart update failed: inventory reservation timed out', array( 'sku' => 'ETH-YIRG-250', 'quantity' => 2, 'waited_ms' => 5000 ) );
					}
					return new WP_REST_Response( array( 'error' => 'reservation_timeout' ), 500 );
				},
			)
		);
		register_rest_route(
			'shop/v1',
			'/stock',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					usleep( 120000 );
					return new WP_REST_Response( array( 'in_stock' => true ), 200 );
				},
			)
		);
	}
);

// Browser side: the shop's own script, with the mistakes such scripts really have.
add_action(
	'wp_footer',
	static function () {
		?>
<style>.demo-promo{background:#3b2a1f;color:#fff;padding:28px 20px;font:600 18px/1.4 system-ui}</style>
<script>
(function () {
	var path = location.pathname;
	fetch('/wp-json/shop/v1/stock');
	// A slow click handler (it recalculates the whole page), which shows up as INP.
	document.addEventListener('click', function () { var t = Date.now(); while (Date.now() - t < 90 + Math.random() * 260) {} });
	if (path.indexOf('/shop') === 0 || path === '/') {
		// A promotion banner inserted above the content after load: a layout shift (CLS).
		setTimeout(function () {
			var b = document.createElement('div'); b.className = 'demo-promo'; b.textContent = 'Free shipping on orders over $40 this week';
			var main = document.querySelector('main') || document.body; main.insertBefore(b, main.firstChild);
		}, 500 + Math.random() * 600);
	}
	if (path.indexOf('/shop') === 0) {
		setTimeout(function () {
			var product = window.shopData && window.shopData.products[0];
			document.title = product.price + ' | ' + document.title; // TypeError: shopData is undefined
		}, 200);
		setTimeout(function () { fetch('/wp-json/shop/v1/cart', { method: 'POST', body: '{"sku":"ETH-YIRG-250","qty":2}' }); }, 400);
	}
	if (path.indexOf('/checkout') === 0) {
		setTimeout(function () {
			fetch('/wp-json/shop/v1/delivery-slots').then(function () {
				return Promise.reject(new Error('Delivery slots could not be rendered: unexpected response shape'));
			});
		}, 150);
	}
	if (path.indexOf('/contact') === 0) {
		setTimeout(function () { fetch('/wp-json/shop/v1/missing-endpoint'); }, 150);
	}
})();
</script>
		<?php
	},
	100
);
