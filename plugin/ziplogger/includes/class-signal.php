<?php
/**
 * The signals this plugin delivers from the server, and how each is sent.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Three signals travel through the local queue. (Browser signals go straight from the visitor's
 * browser to ZipLogger with the browser key; they never touch the queue.)
 *
 * The signals:
 *  logs    POST /ingest/v1/logs     JSON array; full Idempotency-Key state machine on the backend.
 *  events  POST /ingest/v1/events   JSON array; idempotent per event through "insertId"; no gzip.
 *  traces  POST /v1/traces          OTLP/HTTP JSON; spans upsert on (traceId, spanId), so a retry is safe.
 *
 * Every signal has its own delivery state (pause, failure streak, last error): an unavailable traces
 * endpoint must not stop logs, and vice versa.
 */
final class Signal {

	const LOGS   = 'logs';
	const EVENTS = 'events';
	const TRACES = 'traces';

	const ALL = array( self::LOGS, self::EVENTS, self::TRACES );

	/**
	 * Whether a value names a signal.
	 *
	 * @param mixed $signal Candidate.
	 * @return bool
	 */
	public static function is_valid( $signal ) {
		return is_string( $signal ) && in_array( $signal, self::ALL, true );
	}

	/**
	 * Request path under the endpoint base.
	 *
	 * @param string $signal Signal.
	 * @return string
	 */
	public static function path( $signal ) {
		switch ( $signal ) {
			case self::EVENTS:
				return '/ingest/v1/events';
			case self::TRACES:
				return '/v1/traces';
			default:
				return '/ingest/v1/logs';
		}
	}

	/**
	 * Full URL for a signal.
	 *
	 * @param string $signal Signal.
	 * @param string $base   Validated endpoint base.
	 * @return string
	 */
	public static function url( $signal, $base ) {
		return rtrim( $base, '/' ) . self::path( $signal );
	}

	/**
	 * Suffix for per-signal state keys. Logs keep the original, unsuffixed keys.
	 *
	 * @param string $signal Signal.
	 * @return string
	 */
	public static function suffix( $signal ) {
		return self::LOGS === $signal ? '' : ':' . $signal;
	}

	/**
	 * Human-readable name.
	 *
	 * @param string $signal Signal.
	 * @return string
	 */
	public static function label( $signal ) {
		switch ( $signal ) {
			case self::EVENTS:
				return __( 'Events', 'ziplogger-error-monitoring-session-replay' );
			case self::TRACES:
				return __( 'Traces', 'ziplogger-error-monitoring-session-replay' );
			default:
				return __( 'Logs', 'ziplogger-error-monitoring-session-replay' );
		}
	}

	/**
	 * Idempotency-Key prefix. Logs keep "zlwp-" (the original format).
	 *
	 * @param string $signal Signal.
	 * @return string
	 */
	public static function key_prefix( $signal ) {
		switch ( $signal ) {
			case self::EVENTS:
				return 'zlwp-ev-';
			case self::TRACES:
				return 'zlwp-tr-';
			default:
				return Batch::KEY_PREFIX;
		}
	}

	/**
	 * Whether this signal's endpoint understands the Idempotency-Key header.
	 *
	 * Logs: a full replay/mismatch state machine. Events: billing de-duplication only (events dedupe
	 * on insertId). Traces: not supported, and not needed because span ids upsert.
	 *
	 * @param string $signal Signal.
	 * @return bool
	 */
	public static function uses_idempotency_key( $signal ) {
		return self::TRACES !== $signal;
	}

	/**
	 * Share of the queue capacity a signal may occupy. Logs may use all of it; the high-volume, lower
	 * value signals are capped so that they can never crowd errors out during an outage.
	 *
	 * @param string $signal Signal.
	 * @return float
	 */
	public static function queue_share( $signal ) {
		switch ( $signal ) {
			case self::EVENTS:
				return (float) Limits::get( 'queue_share_events' );
			case self::TRACES:
				return (float) Limits::get( 'queue_share_traces' );
			default:
				return 1.0;
		}
	}

	/**
	 * Build the request body from stored per-item JSON documents. The same documents always give the
	 * same bytes, so a retried batch is byte-identical.
	 *
	 * @param string   $signal Signal.
	 * @param string[] $docs   JSON documents, in queue order.
	 * @return string
	 */
	public static function payload( $signal, array $docs ) {
		if ( self::TRACES === $signal ) {
			return Otlp::wrap_spans( $docs );
		}
		return '[' . implode( ',', $docs ) . ']';
	}
}
