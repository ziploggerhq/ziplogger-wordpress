<?php
/**
 * Read-only access to ZipLogger, for the dashboard: server side, with its own least-privilege key.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Dashboard;

use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Redactor;
use ZipLogger\WordPress\Secrets;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * The only thing this plugin reads back from ZipLogger is what the workspace exposes through its
 * Grafana-compatible query surface (/grafana/health, /grafana/loki/..., /grafana/prometheus/...,
 * /grafana/tempo/...), which accepts an API key with the READ scope. That is the whole trust model:
 *
 *  - The read key is a separate credential, checked to be different from the server and browser keys, and
 *    it is used here, on the server, and nowhere else. It is never printed, never in a URL, never sent to
 *    a browser: the admin screen asks WordPress (admin-ajax, capability + nonce), WordPress asks ZipLogger.
 *  - Nobody is asked for a personal login token or a password. A visitor to the dashboard needs nothing.
 *  - Requests go only to the configured endpoint, over verified HTTPS, without redirects (a redirect
 *    would carry the key to another host), with a short timeout and a response size limit, and through the
 *    same SSRF checks as delivery.
 *  - Answers are cached briefly, so opening the dashboard does not become a query load on ZipLogger.
 *
 * Nothing here is verified against the live service by this plugin's own tests: the request and response
 * shapes come from the service's source code and are exercised against a local
 * stand-in. A different answer is handled as "unavailable", never guessed at.
 */
final class Remote {

	const TIMEOUT   = 6;
	const MAX_BYTES = 1048576;
	const TTL       = 60;
	const ERROR_TTL = 15;

	/**
	 * Why the dashboard cannot read from ZipLogger right now, or '' when it can.
	 *
	 * @return string
	 */
	public static function unavailable_reason() {
		if ( '' === Settings::read_key() ) {
			$problem = Settings::key_problem( 'read' );
			return '' !== $problem ? $problem : __( 'No read key is set. Add one on the Connection tab to see live data here.', 'ziplogger-error-monitoring-session-replay' );
		}
		$problem = Settings::endpoint_problem();
		if ( '' !== $problem ) {
			return $problem;
		}
		return '' === Settings::endpoint_base() ? __( 'The endpoint is not usable.', 'ziplogger-error-monitoring-session-replay' ) : '';
	}

	/**
	 * GET a path on the workspace's read surface.
	 *
	 * @param string $path    Path under the endpoint base, e.g. "/grafana/loki/api/v1/query_range".
	 * @param array  $query   Query parameters (never a credential).
	 * @param bool   $refresh Bypass the cache.
	 * @return array{ok:bool,data:?array,error:string,code:string,cached:bool}
	 */
	public static function get( $path, array $query = array(), $refresh = false ) {
		$reason = self::unavailable_reason();
		if ( '' !== $reason ) {
			return self::fail( 'unconfigured', $reason );
		}
		$key  = Settings::read_key();
		$base = Settings::endpoint_base();
		if ( 0 !== strpos( $path, '/grafana/' ) ) {
			return self::fail( 'path', __( 'Only ZipLogger\'s read interface can be queried from here.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		$problem = Endpoint::preflight( $base );
		if ( '' !== $problem ) {
			return self::fail( 'endpoint', $problem );
		}

		ksort( $query );
		$url       = rtrim( $base, '/' ) . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
		$cache_key = 'ziplogger_rc_' . md5( Secrets::fingerprint( $base . '|' . $key ) . '|' . $url );
		if ( ! $refresh ) {
			$hit = get_transient( $cache_key );
			if ( is_array( $hit ) && isset( $hit['ok'] ) ) {
				$hit['cached'] = true;
				return $hit;
			}
		}

		$args = array(
			'method'              => 'GET',
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'httpversion'         => '1.1',
			'sslverify'           => true,
			'decompress'          => true,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => Transport::user_agent(),
			'headers'             => array(
				'Accept'    => 'application/json',
				'X-Api-Key' => $key,
			),
			'ziplogger_transport' => true, // Keeps the collectors and the tracer away from this request.
		);
		try {
			$response = wp_remote_get( $url, $args );
		} catch ( \Throwable $e ) {
			$response = new \WP_Error( 'ziplogger_exception', $e->getMessage() );
		}
		$result = self::classify( $response, $key );
		// Cache, but never an unreasonably large answer (transients live in the options table).
		if ( strlen( (string) wp_json_encode( $result ) ) <= 262144 ) {
			set_transient( $cache_key, $result, $result['ok'] ? self::TTL : self::ERROR_TTL );
		}
		return $result;
	}

	/**
	 * The workspace behind the read key (an uncached call, for the "test the read connection" button).
	 *
	 * @return array{ok:bool,data:?array,error:string,code:string,cached:bool}
	 */
	public static function health() {
		return self::get( '/grafana/health', array(), true );
	}

	/**
	 * Turn an HTTP response into a result. Server text is never trusted or echoed unredacted.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param string          $key      The read key (to make sure it can never leak into a message).
	 * @return array
	 */
	private static function classify( $response, $key ) {
		$redactor = new Redactor( array( 'secrets' => array( $key ) ) );
		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );
			$code    = ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) ? 'timeout' : 'network';
			if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'certificate' ) ) {
				$code = 'tls';
			}
			$text = array(
				'timeout' => __( 'ZipLogger did not answer in time.', 'ziplogger-error-monitoring-session-replay' ),
				'tls'     => __( 'The secure connection to ZipLogger could not be verified.', 'ziplogger-error-monitoring-session-replay' ),
				'network' => __( 'ZipLogger could not be reached.', 'ziplogger-error-monitoring-session-replay' ),
			);
			return self::fail( $code, $text[ $code ] );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );
		$server = is_array( $json ) && isset( $json['error'] ) && is_string( $json['error'] ) ? $redactor->diagnostic( $json['error'] ) : '';

		if ( $status >= 200 && $status < 300 ) {
			if ( ! is_array( $json ) ) {
				return self::fail( 'format', __( 'ZipLogger answered, but not in a format this screen understands.', 'ziplogger-error-monitoring-session-replay' ) );
			}
			return array(
				'ok'     => true,
				'data'   => $json,
				'error'  => '',
				'code'   => '',
				'cached' => false,
			);
		}
		if ( 401 === $status || 403 === $status ) {
			return self::fail( 'auth', __( 'ZipLogger did not accept the read key. It may be revoked, mistyped, or not have the read scope.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		if ( 404 === $status ) {
			return self::fail( 'unsupported', __( 'This ZipLogger server does not offer the read interface (it may be switched off or older).', 'ziplogger-error-monitoring-session-replay' ) );
		}
		if ( 429 === $status ) {
			return self::fail( 'rate', __( 'ZipLogger is rate-limiting reads right now. Try again in a minute.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		if ( 400 === $status || 422 === $status ) {
			return self::fail( 'query', '' !== $server ? $server : __( 'ZipLogger did not accept the query.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		if ( $status >= 500 ) {
			return self::fail( 'server', __( 'ZipLogger reported a problem. Try again later.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		return self::fail( 'http', sprintf( /* translators: %d: HTTP status */ __( 'ZipLogger answered with HTTP %d.', 'ziplogger-error-monitoring-session-replay' ), $status ) );
	}

	/**
	 * A failed result.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message for the administrator.
	 * @return array
	 */
	private static function fail( $code, $message ) {
		return array(
			'ok'     => false,
			'data'   => null,
			'error'  => $message,
			'code'   => $code,
			'cached' => false,
		);
	}
}
