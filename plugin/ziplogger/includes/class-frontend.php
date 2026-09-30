<?php
/**
 * The front-end script and its configuration.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the browser script (errors, performance, analytics, replay, browser tracing) on public pages.
 *
 * The configuration printed here is CACHE-SAFE by construction: it depends on the settings and on the URL
 * being rendered, and on nothing about the visitor. Page caches, CDNs and optimization plugins may store
 * and serve it to anyone. Everything that differs per visitor (is anyone signed in, may this person be
 * recorded, what is their pseudonymous id, what did they consent to) is decided in the browser or asked of
 * an uncached endpoint (see Context_Endpoint). There is no session id, trace id, nonce or user field in
 * this class, and tests keep it that way.
 *
 * Credentials: only the BROWSER key is ever printed. It is an ingestion-only key meant for public code.
 * The server key and the read key are never passed to this class.
 */
final class Frontend {

	const HANDLE         = 'ziplogger';
	const CONFIG_ID      = 'ziplogger-config';
	const SCRIPT         = 'assets/js/ziplogger.min.js';
	const RECORDER       = 'assets/js/ziplogger-recorder.min.js';
	const CONTEXT_ACTION = 'ziplogger_context';
	const HINT_COOKIE    = 'ziplogger_li';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'print_config' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
	}

	/**
	 * Whether the script belongs on this request.
	 *
	 * @return bool
	 */
	public static function should_load() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_embed() || is_customize_preview() || is_preview() ) {
			return false;
		}
		if ( ! Modules::frontend_needed() ) {
			return false;
		}
		/**
		 * Lets a site keep the browser script off specific pages or templates.
		 *
		 * @param bool $load Whether to load it.
		 */
		return (bool) apply_filters( 'ziplogger_load_frontend', true );
	}

	/**
	 * Print the configuration as an inert JSON block (never executed, so a Content-Security-Policy that
	 * forbids inline scripts does not block it, and optimizers do not rewrite it).
	 *
	 * @return void
	 */
	public static function print_config() {
		if ( ! self::should_load() ) {
			return;
		}
		$config = self::config();
		if ( null === $config ) {
			return;
		}
		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return;
		}
		echo '<script type="application/json" id="' . esc_attr( self::CONFIG_ID ) . '">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with the HEX flags above.
	}

	/**
	 * Enqueue the script.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::should_load() || null === self::config() ) {
			return;
		}
		wp_register_script( self::HANDLE, ZIPLOGGER_URL . self::SCRIPT, array(), self::asset_version( self::SCRIPT ), false );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Add defer, and ask optimization plugins to leave the script alone (they must not delay, concatenate
	 * or reorder it: it observes the page from the start).
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function script_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle || false !== strpos( $tag, ' defer' ) ) {
			return $tag;
		}
		return str_replace( ' src=', ' defer data-cfasync="false" data-no-optimize="1" data-no-defer="1" nowprocket src=', $tag );
	}

	/**
	 * The configuration for the current URL, or null when there is nothing usable to send.
	 *
	 * @return array|null
	 */
	public static function config() {
		$key      = Settings::browser_key();
		$endpoint = Settings::browser_endpoint();
		if ( '' === $key || '' === $endpoint ) {
			return null;
		}
		$s = Settings::get();

		$browser_on   = Modules::effective( 'browser' );
		$analytics_on = Modules::effective( 'analytics' );
		$replay_on    = Modules::effective( 'replay' );
		$tracing_on   = Modules::effective( 'tracing' ) && ! empty( $s['tracing']['browser'] );
		$commerce_on  = Modules::effective( 'woocommerce' );

		$config = array(
			'v'           => 1,
			'endpoint'    => $endpoint,
			'key'         => $key,
			'source'      => $s['source'],
			'environment' => Settings::environment(),
			'release'     => Event_Factory::release_value(),
			'commitSha'   => Event_Factory::commit_sha_value(),
			'modules'     => array(
				'browser'   => $browser_on,
				'analytics' => $analytics_on,
				'replay'    => $replay_on,
				'tracing'   => $tracing_on,
				'commerce'  => $commerce_on,
			),
			'browser'     => array(
				'errors'           => (bool) $s['browser']['errors'],
				'failedRequests'   => (bool) $s['browser']['failed_requests'],
				'slowRequests'     => (bool) $s['browser']['slow_requests'],
				'slowThresholdMs'  => (int) $s['browser']['slow_threshold_ms'],
				'navigationTiming' => (bool) $s['browser']['navigation_timing'],
				'webVitals'        => (bool) $s['browser']['web_vitals'],
				'perfSampleRate'   => (int) $s['browser']['perf_sample_rate'],
				'errorSampleRate'  => (int) $s['browser']['error_sample_rate'],
				'maxErrorsPage'    => (int) $s['browser']['max_errors_page'],
			),
			'analytics'   => array(
				'pageViews'     => (bool) $s['analytics']['page_views'],
				'spaNavigation' => (bool) $s['analytics']['spa_navigation'],
				'interactions'  => (bool) $s['analytics']['interactions'],
				'selectors'     => self::lines( $s['analytics']['interaction_selectors'] ),
				'identify'      => (bool) $s['analytics']['identify'],
				'sampleRate'    => (int) $s['analytics']['sample_rate'],
			),
			'replay'      => array(
				'sampleRate'    => (int) $s['replay']['sample_rate'],
				'maskAllText'   => (bool) $s['replay']['mask_all_text'],
				'blockSelector' => self::lines( $s['replay']['block_selector'] ),
				'maskSelector'  => self::lines( $s['replay']['mask_selector'] ),
				'excludePaths'  => self::lines( $s['replay']['exclude_paths'] ),
				'maxMinutes'    => (int) $s['replay']['max_minutes'],
				'maxMegabytes'  => (int) $s['replay']['max_megabytes'],
				'recorderUrl'   => $replay_on ? ZIPLOGGER_URL . self::RECORDER . '?ver=' . self::asset_version( self::RECORDER ) : '',
			),
			'tracing'     => array(
				'sampleRate'       => (int) $s['tracing']['sample_rate'],
				'propagateHosts'   => self::lines( $s['tracing']['propagate_hosts'] ),
				'browser'          => $tracing_on,
				'serviceName'      => '' !== $s['tracing']['service_name'] ? $s['tracing']['service_name'] : $s['source'],
				'propagateSession' => true,
			),
			'commerce'    => array(
				// The shop's base currency setting, not the "current" currency: a multi-currency plugin changes that per visitor.
				'currency'          => $commerce_on ? Commerce\Money::currency( get_option( 'woocommerce_currency', '' ) ) : '',
				'productViews'      => $commerce_on && ! empty( $s['woocommerce']['product_views'] ),
				'cartEvents'        => $commerce_on && ! empty( $s['woocommerce']['cart_events'] ),
				'checkout'          => $commerce_on && ! empty( $s['woocommerce']['checkout'] ),
				'cookie'            => Commerce\Visitor::COOKIE,
				'productIdentifier' => $commerce_on ? $s['woocommerce']['product_identifier'] : 'none',
			),
			'consent'     => array_merge(
				Consent::for_browser(),
				array(
					'honorDnt'   => (bool) apply_filters( 'ziplogger_honor_dnt', true ),
					'cookiePath' => defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
				)
			),
			'context'     => array(
				'url'        => self::context_path(),
				'action'     => self::CONTEXT_ACTION,
				'hintCookie' => self::HINT_COOKIE,
			),
			'page'        => self::page(),
		);
		if ( Endpoint::insecure_allowed() ) {
			$config['allowInsecureEndpoint'] = true;
		}

		/**
		 * Filters the browser configuration. Must stay free of anything visitor-specific: page caches will
		 * store it. Do not add session ids, user ids, nonces or per-request values.
		 *
		 * @param array $config Configuration.
		 */
		$filtered = apply_filters( 'ziplogger_frontend_config', $config );
		return is_array( $filtered ) ? $filtered : $config;
	}

	/**
	 * What kind of page this URL is, whether recording it is off limits, and which URL segments hold ids.
	 * All three follow from the URL alone, so they are the same for every visitor.
	 *
	 * @return array
	 */
	public static function page() {
		return array(
			'type'               => self::page_type(),
			'replay'             => ! self::replay_excluded_page(),
			'sensitiveEndpoints' => self::sensitive_endpoints(),
			'commerce'           => Commerce\Products::page_block(),
		);
	}

	/**
	 * A coarse page type for analytics.
	 *
	 * @return string
	 */
	public static function page_type() {
		if ( Modules::woocommerce_active() ) {
			if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
				return 'order_received';
			}
			if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
				return 'order_pay';
			}
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				return 'checkout';
			}
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return 'cart';
			}
			if ( function_exists( 'is_account_page' ) && is_account_page() ) {
				return 'account';
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				return 'product';
			}
			if ( function_exists( 'is_shop' ) && is_shop() ) {
				return 'shop';
			}
			if ( ( function_exists( 'is_product_category' ) && is_product_category() ) || ( function_exists( 'is_product_tag' ) && is_product_tag() ) ) {
				return 'product_archive';
			}
		}
		if ( is_404() ) {
			return 'not_found';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_front_page() ) {
			return 'front_page';
		}
		if ( is_home() ) {
			return 'blog';
		}
		if ( is_singular() ) {
			$type = get_post_type();
			return is_string( $type ) && '' !== $type ? sanitize_key( $type ) : 'single';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		return 'other';
	}

	/**
	 * Pages that session replay must never record, as far as the URL can tell: checkout, cart, account
	 * and payment pages, and password-protected content. (Visitor-dependent exclusions, such as roles, are
	 * applied by the browser after asking the server; login and admin screens never load this script.)
	 *
	 * @return bool
	 */
	public static function replay_excluded_page() {
		$excluded = false;
		if ( Modules::woocommerce_active() ) {
			$excluded = ( function_exists( 'is_checkout' ) && is_checkout() )
				|| ( function_exists( 'is_cart' ) && is_cart() )
				|| ( function_exists( 'is_account_page' ) && is_account_page() )
				|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );
		}
		if ( ! $excluded && is_singular() ) {
			$post     = get_post();
			$excluded = $post instanceof \WP_Post && '' !== (string) $post->post_password;
		}
		/**
		 * Lets a site mark more pages as never-record. Return true to exclude the current page.
		 *
		 * @param bool $excluded Whether the page is excluded.
		 */
		return (bool) apply_filters( 'ziplogger_replay_excluded_page', $excluded );
	}

	/**
	 * URL segments after which an identifier follows ("order-received/1234"), so analytics and traces can
	 * mask the identifier. WooCommerce lets shops rename these, so the real slugs are read.
	 *
	 * @return string[]
	 */
	public static function sensitive_endpoints() {
		$slugs = array();
		if ( Modules::woocommerce_active() && function_exists( 'WC' ) && WC() && isset( WC()->query ) && is_object( WC()->query ) && method_exists( WC()->query, 'get_query_vars' ) ) {
			$vars = WC()->query->get_query_vars();
			foreach ( array( 'view-order', 'order-pay', 'order-received', 'delete-payment-method', 'set-default-payment-method', 'view-subscription' ) as $name ) {
				if ( ! empty( $vars[ $name ] ) && is_string( $vars[ $name ] ) ) {
					$slugs[] = sanitize_title( $vars[ $name ] );
				}
			}
		}
		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	/**
	 * The admin-ajax path, relative so the browser uses the page's own origin (a different admin host
	 * would turn the request into a credentialed cross-origin one).
	 *
	 * @return string
	 */
	public static function context_path() {
		$path = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/wp-admin/admin-ajax.php';
	}

	/**
	 * Cache-busting version for a shipped asset: its modification time, which changes on every release.
	 *
	 * @param string $relative Path inside the plugin.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$file = ZIPLOGGER_DIR . $relative;
		$time = is_readable( $file ) ? (int) filemtime( $file ) : 0;
		return ZIPLOGGER_VERSION . ( $time ? '.' . $time : '' );
	}

	/**
	 * Split a stored multi-line setting into a list.
	 *
	 * @param mixed $text Stored value.
	 * @return string[]
	 */
	private static function lines( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ), 'strlen' ) );
	}
}
