<?php
/**
 * W3C Trace Context values: parsing untrusted headers, and building ones we send.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Tracing;

defined( 'ABSPATH' ) || exit;

/**
 * Everything in a request header is attacker-controlled. Parsing here is strict and small: a value that is
 * not exactly what the specification allows is ignored (the request simply starts its own trace), and only
 * validated ids and one validated baggage member ever leave this class.
 *
 * Trace Context (https://www.w3.org/TR/trace-context/): "version-traceid-parentid-flags", all lower-case
 * hex, 2-32-16-2 characters, ids not all zero. Only version 00 is understood; a header that claims another
 * version is treated as absent rather than guessed at.
 *
 * Baggage: only the "session.id" member is read, and only when its value has the shape of an id we could
 * have produced. Nothing else in baggage is looked at, stored, or forwarded.
 */
final class Context {

	/**
	 * Parse a traceparent header.
	 *
	 * @param mixed $header Raw header value.
	 * @return array{trace_id:string,parent_id:string,sampled:bool}|null Null when invalid.
	 */
	public static function parse_traceparent( $header ) {
		if ( ! is_string( $header ) || strlen( $header ) !== 55 ) {
			return null;
		}
		if ( 1 !== preg_match( '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D', $header, $m ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^0+$/', $m[1] ) || 1 === preg_match( '/^0+$/', $m[2] ) ) {
			return null;
		}
		return array(
			'trace_id'  => $m[1],
			'parent_id' => $m[2],
			'sampled'   => 1 === ( hexdec( $m[3] ) & 1 ),
		);
	}

	/**
	 * Build a traceparent header value.
	 *
	 * @param string $trace_id 32 hex characters.
	 * @param string $span_id  16 hex characters.
	 * @param bool   $sampled  Sampling decision.
	 * @return string
	 */
	public static function format( $trace_id, $span_id, $sampled ) {
		return '00-' . $trace_id . '-' . $span_id . '-' . ( $sampled ? '01' : '00' );
	}

	/**
	 * The session id carried in a baggage header, or ''.
	 *
	 * @param mixed $header Raw header value.
	 * @return string
	 */
	public static function parse_baggage_session( $header ) {
		if ( ! is_string( $header ) || '' === $header || strlen( $header ) > 2048 ) {
			return '';
		}
		foreach ( explode( ',', $header ) as $member ) {
			$pair = explode( ';', $member, 2 )[0];
			if ( false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $pair, 2 );
			if ( 'session.id' !== trim( $key ) ) {
				continue;
			}
			$value = rawurldecode( trim( $value ) );
			return 1 === preg_match( '/^[A-Za-z0-9_-]{8,64}$/D', $value ) ? $value : '';
		}
		return '';
	}

	/**
	 * Whether a header value looks like it could be tampered with to carry more than an id (control
	 * characters, line breaks). Used before any header value is placed anywhere.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_clean( $value ) {
		return 1 !== preg_match( '/[\x00-\x1F\x7F]/', (string) $value );
	}

	/**
	 * A random id as lowercase hex that is never all zero.
	 *
	 * @param int $bytes 16 for a trace id, 8 for a span id.
	 * @return string
	 */
	public static function random_id( $bytes ) {
		return \ZipLogger\WordPress\Otlp::random_id( $bytes );
	}

	/**
	 * A deterministic value in [0, 1) derived from a trace id, so every service that sees the same id and
	 * applies the same rate makes the same sampling decision.
	 *
	 * @param string $trace_id Trace id (32 hex characters).
	 * @return float
	 */
	public static function fraction( $trace_id ) {
		return hexdec( substr( $trace_id, 16, 8 ) ) / 4294967296;
	}
}
