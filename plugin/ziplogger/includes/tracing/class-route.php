<?php
/**
 * What kind of request this is, and a low-cardinality name for it.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Tracing;

defined( 'ABSPATH' ) || exit;

/**
 * Span names must group: "GET /wp/v2/posts/{id}" is one row in a dashboard, "GET /wp/v2/posts/1734" and
 * a thousand siblings are noise (and, for paths that carry tokens, a leak). Identifier-looking segments
 * are replaced, and unregistered AJAX action names - which a visitor can invent at will - are collapsed.
 */
final class Route {

	const FRONTEND = 'frontend';
	const REST     = 'rest';
	const AJAX     = 'ajax';
	const CRON     = 'cron';
	const ADMIN    = 'admin';
	const XMLRPC   = 'xmlrpc';
	const LOGIN    = 'login';

	/**
	 * Classify the current request.
	 *
	 * @return string One of the constants above.
	 */
	public static function request_context() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::REST;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return self::XMLRPC;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return self::CRON;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return self::AJAX;
		}
		$script = self::script();
		if ( 'wp-login.php' === $script ) {
			return self::LOGIN;
		}
		if ( 'wp-cron.php' === $script ) {
			return self::CRON;
		}
		if ( 'xmlrpc.php' === $script ) {
			return self::XMLRPC;
		}
		if ( 'admin-ajax.php' === $script ) {
			return self::AJAX;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return self::ADMIN;
		}
		if ( self::is_rest_url() ) {
			return self::REST;
		}
		return self::FRONTEND;
	}

	/**
	 * Whether the URL asks for the REST API (REST_REQUEST is only defined once WordPress has parsed the
	 * request, which is after a request span must already exist).
	 *
	 * @return bool
	 */
	private static function is_rest_url() {
		$path = self::request_path();
		$pref = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		if ( 0 === strpos( ltrim( $path, '/' ), $pref . '/' ) || ltrim( $path, '/' ) === $pref ) {
			return true;
		}
		return isset( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to name the request; nothing is changed or output.
	}

	/**
	 * The script that handled the request ("index.php", "wp-login.php" ...).
	 *
	 * @return string
	 */
	public static function script() {
		$name = isset( $_SERVER['SCRIPT_NAME'] ) && is_string( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read only to classify the request; never output or stored as given.
		return strtolower( basename( str_replace( '\\', '/', $name ) ) );
	}

	/**
	 * The request path without query string or fragment.
	 *
	 * @return string
	 */
	public static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read only to classify the request; never output or stored as given.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * Replace identifier-looking segments so paths group.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$parts = explode( '/', (string) $path );
		foreach ( $parts as $i => $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			if ( 1 === preg_match( '/^\d+$/', $segment )
				|| 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment )
				|| 1 === preg_match( '/^[0-9a-f]{12,}$/i', $segment )
				|| ( strlen( $segment ) >= 16 && 1 === preg_match( '/\d/', $segment ) && 1 === preg_match( '/^[A-Za-z0-9_\-]+$/', $segment ) ) ) {
				$parts[ $i ] = '{id}';
			} elseif ( strlen( $segment ) > 60 ) {
				$parts[ $i ] = '{long}';
			}
		}
		$out = implode( '/', $parts );
		return strlen( $out ) > 200 ? substr( $out, 0, 200 ) : $out;
	}

	/**
	 * The route name for a request of the given context.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public static function name( $context ) {
		switch ( $context ) {
			case self::REST:
				$path = self::request_path();
				$pref = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
				if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to name the request; nothing is changed or output.
					$path = '/' . $pref . '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to name the request; nothing is changed or output.
				}
				return self::normalize( $path );
			case self::AJAX:
				return 'admin-ajax:' . self::ajax_action();
			case self::CRON:
				return 'wp-cron';
			case self::XMLRPC:
				return 'xmlrpc';
			case self::LOGIN:
				return 'wp-login';
			case self::ADMIN:
				$script = self::script();
				return 'wp-admin/' . ( '' === $script ? 'index.php' : preg_replace( '/[^a-z0-9._\-]/', '', $script ) );
			default:
				return self::frontend_route();
		}
	}

	/**
	 * A registered AJAX action name, or "unregistered": visitors can send any string as an action, and
	 * those must not become span names.
	 *
	 * @return string
	 */
	public static function ajax_action() {
		$action = '';
		if ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to name the request; nothing is changed or output.
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to name the request; nothing is changed or output.
		}
		if ( '' === $action || strlen( $action ) > 60 ) {
			return 'unregistered';
		}
		return ( has_action( 'wp_ajax_' . $action ) || has_action( 'wp_ajax_nopriv_' . $action ) ) ? $action : 'unregistered';
	}

	/**
	 * A page-type route for public pages (single:post, archive, search ...), never the URL.
	 *
	 * @return string
	 */
	private static function frontend_route() {
		if ( function_exists( 'did_action' ) && did_action( 'template_redirect' ) && class_exists( '\ZipLogger\WordPress\Frontend' ) ) {
			return 'page:' . \ZipLogger\WordPress\Frontend::page_type();
		}
		return 'page:unresolved';
	}
}
