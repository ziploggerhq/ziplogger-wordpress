<?php
/**
 * Every resource bound in one place.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Bounds for event size, queue size, retention, batching, retries and worker time.
 *
 * Sites can adjust them with the "ziplogger_limits" filter; the result is clamped so a careless
 * filter cannot turn a bound into "unbounded".
 */
final class Limits {

	/**
	 * Default, minimum and maximum per limit.
	 *
	 * @var array<string, array{0:int|float,1:int|float,2:int|float}>
	 */
	const SPEC = array(
		// Event shape.
		'max_event_bytes'            => array( 16384, 2048, 65536 ),
		'max_message_bytes'          => array( 4096, 256, 16384 ),
		'max_stack_bytes'            => array( 8192, 0, 32768 ),
		'max_string_bytes'           => array( 1024, 64, 8192 ),
		'max_depth'                  => array( 4, 1, 8 ),
		'max_items'                  => array( 40, 5, 200 ),
		'max_fields'                 => array( 40, 10, 100 ),
		'max_frames'                 => array( 30, 5, 100 ),
		// Per request.
		'request_event_cap'          => array( 100, 5, 1000 ),
		'request_span_cap'           => array( 50, 1, 500 ),
		// Sampled trace contexts from other parties honoured per minute (0 = never honour, always decide locally).
		'inbound_sampled_per_minute' => array( 120, 0, 100000 ),
		'buffer_flush_at'            => array( 25, 1, 200 ),
		// Queue.
		'queue_max_events'           => array( 5000, 100, 100000 ),
		'queue_max_bytes'            => array( 33554432, 1048576, 268435456 ),
		'low_priority_ceiling'       => array( 0.9, 0.5, 1.0 ),
		'queue_share_events'         => array( 0.7, 0.1, 1.0 ),
		'queue_share_traces'         => array( 0.5, 0.1, 1.0 ),
		'retention_seconds'          => array( 172800, 3600, 2592000 ),
		// Delivery.
		'batch_max_events'           => array( 100, 1, 500 ),
		'batch_max_bytes'            => array( 524288, 16384, 2097152 ),
		'http_timeout'               => array( 8, 1, 15 ),
		'worker_max_seconds'         => array( 20, 5, 55 ),
		'worker_max_batches'         => array( 5, 1, 50 ),
		'lease_seconds'              => array( 90, 30, 600 ),
		'max_attempts'               => array( 10, 2, 50 ),
		'isolate_after'              => array( 3, 2, 20 ),
		'backoff_base'               => array( 30, 5, 600 ),
		'backoff_cap'                => array( 1800, 60, 21600 ),
		'retry_after_cap'            => array( 86400, 60, 172800 ),
		'first_delivery_delay'       => array( 30, 5, 600 ),
		'overdue_after'              => array( 600, 120, 86400 ),
		'meta_retention_seconds'     => array( 604800, 3600, 2592000 ),
	);

	/**
	 * Memoized clamped values.
	 *
	 * @var array<string, int|float>|null
	 */
	private static $resolved = null;

	/**
	 * Get one limit.
	 *
	 * @param string $name Limit name.
	 * @return int|float
	 */
	public static function get( $name ) {
		if ( null === self::$resolved ) {
			self::resolve();
		}
		return self::$resolved[ $name ];
	}

	/**
	 * Forget memoized values (tests, or after a filter is added late).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$resolved = null;
	}

	/**
	 * Apply the filter and clamp every value into its allowed range.
	 *
	 * @return void
	 */
	private static function resolve() {
		$defaults = array();
		foreach ( self::SPEC as $name => $range ) {
			$defaults[ $name ] = $range[0];
		}
		/**
		 * Filters the plugin's resource limits.
		 *
		 * @param array $limits Map of limit name to value. Values outside the supported range are clamped.
		 */
		try {
			$filtered = apply_filters( 'ziplogger_limits', $defaults );
		} catch ( \Throwable $e ) {
			$filtered = $defaults; // A broken filter must not break logging: fall back to the defaults.
		}
		$filtered = is_array( $filtered ) ? $filtered : array();

		$out = array();
		foreach ( self::SPEC as $name => $range ) {
			$value = isset( $filtered[ $name ] ) && is_numeric( $filtered[ $name ] ) ? $filtered[ $name ] + 0 : $range[0];
			$value = max( $range[1], min( $range[2], $value ) );
			// Integer limits stay integers; only the queue ceiling is fractional.
			$out[ $name ] = is_float( $range[0] ) ? (float) $value : (int) $value;
		}
		// A lease must outlive the request it protects, or two workers would send the same batch.
		$out['lease_seconds'] = max( $out['lease_seconds'], $out['http_timeout'] + 20 );
		// Isolation has to happen before the attempt ceiling, or it can never trigger.
		$out['isolate_after'] = min( $out['isolate_after'], $out['max_attempts'] );

		self::$resolved = $out;
	}
}
