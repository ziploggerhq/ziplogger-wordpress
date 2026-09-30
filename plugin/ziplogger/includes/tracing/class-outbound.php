<?php
/**
 * Child spans for outbound HTTP requests, and trace propagation to hosts the administrator allowed.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Tracing;

use ZipLogger\WordPress\Event_Factory;
use ZipLogger\WordPress\Otlp;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Two separate decisions per outbound request made through the WordPress HTTP API:
 *
 *  1. Is a span recorded? Yes, for blocking requests of a sampled trace, except ZipLogger's own traffic.
 *     A span holds the HOST, the method, the status and the duration (and a normalized path only if the
 *     administrator switched "record paths" on). Query strings and bodies are never read: webhook URLs and
 *     API calls routinely carry tokens there.
 *  2. Is a traceparent header sent? Only to this site itself and to hosts on the administrator's list.
 *     Never to anyone else, never with baggage or any other request context, and never to ZipLogger.
 *     Sending it to a third party would tell that party which of its requests belong to which of our
 *     traces, and lets it correlate our traffic: that is the administrator's call, host by host.
 */
final class Outbound {

	const ARG = 'ziplogger_span';

	/**
	 * Whether the hooks are installed.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Install the hooks (once).
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		// Late, so other plugins' argument changes are already in place.
		add_filter( 'http_request_args', array( __CLASS__, 'begin' ), 99999, 2 );
		add_action( 'http_api_debug', array( __CLASS__, 'end' ), 10, 5 );
	}

	/**
	 * Remove the hooks (tests).
	 *
	 * @return void
	 */
	public static function unregister() {
		remove_filter( 'http_request_args', array( __CLASS__, 'begin' ), 99999 );
		remove_action( 'http_api_debug', array( __CLASS__, 'end' ), 10 );
		self::$registered = false;
	}

	/**
	 * A request is about to be made: allocate a span and, for allowed hosts, add the header.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array
	 */
	public static function begin( $args, $url = '' ) {
		try {
			$tracer = Tracer::current();
			if ( null === $tracer || $tracer->suspended() || ! is_array( $args ) || ! is_string( $url ) ) {
				return $args;
			}
			if ( ! empty( $args['ziplogger_transport'] ) || Transport::in_flight() ) {
				return $args;
			}
			$parts = wp_parse_url( $url );
			$host  = is_array( $parts ) && isset( $parts['host'] ) && is_string( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			if ( '' === $host || ! in_array( isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '', array( 'http', 'https' ), true ) ) {
				return $args;
			}
			if ( Settings::endpoint_host() === $host ) {
				return $args; // ZipLogger's own traffic.
			}

			$span_id           = Context::random_id( 8 );
			$args[ self::ARG ] = array(
				'id'    => $span_id,
				'start' => microtime( true ),
			);

			if ( self::may_propagate( $host, isset( $parts['port'] ) ? (int) $parts['port'] : 0 ) && ( ! isset( $args['headers'] ) || is_array( $args['headers'] ) ) ) {
				$header = Context::format( $tracer->trace_id(), $span_id, $tracer->sampled() );
				if ( ! isset( $args['headers'] ) ) {
					$args['headers'] = array();
				}
				$has = false;
				foreach ( array_keys( $args['headers'] ) as $name ) {
					if ( 'traceparent' === strtolower( (string) $name ) ) {
						$has = true; // The caller set its own: never overwrite.
					}
				}
				if ( ! $has ) {
					$args['headers']['traceparent'] = $header;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $args;
	}

	/**
	 * A request finished: record its span.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param string          $context  "response".
	 * @param string          $class_name Transport class (unused).
	 * @param array           $args     Request arguments.
	 * @param string          $url      URL.
	 * @return void
	 */
	public static function end( $response = null, $context = '', $class_name = '', $args = array(), $url = '' ) {
		unset( $class_name );
		try {
			$tracer = Tracer::current();
			if ( null === $tracer || 'response' !== $context || ! is_array( $args ) || ! isset( $args[ self::ARG ] ) || ! is_string( $url ) ) {
				return;
			}
			$s = Settings::get();
			if ( ! $tracer->sampled() || empty( $s['tracing']['outbound_spans'] ) || empty( $args['blocking'] ) ) {
				return;
			}
			$mark  = $args[ self::ARG ];
			$parts = wp_parse_url( $url );
			$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
			if ( '' === $host || ! isset( $mark['id'], $mark['start'] ) ) {
				return;
			}

			$method = isset( $args['method'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $args['method'] ) ) : 'GET';
			$method = in_array( $method, array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ? $method : '_OTHER';
			$error  = is_wp_error( $response );
			$status = $error ? 0 : (int) wp_remote_retrieve_response_code( $response );

			$attrs = array(
				'http.request.method' => $method,
				'server.address'      => $host,
				'url.scheme'          => isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '',
			);
			if ( ! empty( $parts['port'] ) ) {
				$attrs['server.port'] = (int) $parts['port'];
			}
			if ( ! empty( $s['tracing']['record_path'] ) && ! empty( $parts['path'] ) ) {
				$attrs['url.path'] = Route::normalize( (string) $parts['path'] );
			}
			$message = '';
			if ( $error ) {
				$code                = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $response->get_error_code() ) );
				$attrs['error.type'] = '' === $code ? 'error' : $code;
				$message             = $attrs['error.type'];
			} else {
				$attrs['http.response.status_code'] = $status;
				if ( $status >= 400 ) {
					$attrs['error.type'] = (string) $status;
					$message             = 'HTTP ' . $status;
				}
			}

			$tracer->add_child(
				Otlp::span(
					array(
						'trace_id'       => $tracer->trace_id(),
						'span_id'        => $mark['id'],
						'parent_id'      => $tracer->child_parent(),
						'name'           => $method . ' ' . $host,
						'kind'           => Otlp::KIND_CLIENT,
						'start'          => (float) $mark['start'],
						'end'            => microtime( true ),
						'attributes'     => $attrs,
						'status'         => ( $error || $status >= 400 ) ? Otlp::STATUS_ERROR : Otlp::STATUS_UNSET,
						'status_message' => $message,
					)
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * May a traceparent header go to this host? This site, or a host on the administrator's list.
	 *
	 * @param string $host Lower-case host.
	 * @param int    $port Port, or 0.
	 * @return bool
	 */
	public static function may_propagate( $host, $port ) {
		$site = array();
		foreach ( array( home_url(), site_url() ) as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( is_string( $h ) ) {
				$site[] = strtolower( $h );
			}
		}
		if ( in_array( $host, $site, true ) ) {
			return true;
		}
		$s = Settings::get();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['tracing']['propagate_hosts'] ) as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			$entry_port = 0;
			if ( false !== strpos( $entry, ':' ) ) {
				list( $entry, $p ) = explode( ':', $entry, 2 );
				$entry_port        = (int) $p;
			}
			if ( $entry_port && $entry_port !== $port ) {
				continue;
			}
			if ( 0 === strpos( $entry, '*.' ) ) {
				$suffix = substr( $entry, 1 ); // ".example.com".
				if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			} elseif ( $entry === $host ) {
				return true;
			}
		}
		return false;
	}
}
