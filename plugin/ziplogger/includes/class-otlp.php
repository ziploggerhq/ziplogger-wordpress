<?php
/**
 * OTLP/HTTP JSON building blocks for trace export.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The service's OTLP/JSON trace parser accepts lowerCamelCase OTLP/JSON with hex
 * ids, int64 values as strings or numbers, and these attribute value kinds: stringValue, boolValue,
 * intValue, doubleValue, bytesValue and arrayValue. It does NOT read kvlistValue (such attributes are
 * silently dropped), so this class never produces one. Span ids upsert on (traceId, spanId), which is
 * what makes re-sending a batch safe.
 */
final class Otlp {

	const SCOPE_NAME = 'ziplogger-wordpress';

	const KIND_INTERNAL = 1;
	const KIND_SERVER   = 2;
	const KIND_CLIENT   = 3;

	const STATUS_UNSET = 0;
	const STATUS_OK    = 1;
	const STATUS_ERROR = 2;

	/**
	 * One OTLP attribute, or null when the value cannot be represented.
	 *
	 * @param string $key   Attribute key.
	 * @param mixed  $value Scalar or list of scalars.
	 * @return array|null
	 */
	public static function attr( $key, $value ) {
		$v = self::any_value( $value );
		if ( null === $v || '' === (string) $key ) {
			return null;
		}
		return array(
			'key'   => (string) $key,
			'value' => $v,
		);
	}

	/**
	 * A list of attributes from a key => value map (nulls and unrepresentable values are skipped).
	 *
	 * @param array $map Map.
	 * @return array[]
	 */
	public static function attrs( array $map ) {
		$out = array();
		foreach ( $map as $key => $value ) {
			$attr = self::attr( (string) $key, $value );
			if ( null !== $attr ) {
				$out[] = $attr;
			}
		}
		return $out;
	}

	/**
	 * An OTLP AnyValue for a scalar or a flat list of scalars.
	 *
	 * @param mixed $value Value.
	 * @return array|null
	 */
	private static function any_value( $value ) {
		if ( is_bool( $value ) ) {
			return array( 'boolValue' => $value );
		}
		if ( is_int( $value ) ) {
			return array( 'intValue' => (string) $value );
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? array( 'doubleValue' => $value ) : null;
		}
		if ( is_string( $value ) ) {
			return array( 'stringValue' => $value );
		}
		if ( is_array( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			$values = array();
			foreach ( $value as $item ) {
				$inner = is_array( $item ) ? null : self::any_value( $item );
				if ( null !== $inner ) {
					$values[] = $inner;
				}
			}
			return array( 'arrayValue' => array( 'values' => $values ) );
		}
		return null;
	}

	/**
	 * Unix time (seconds, float) as an OTLP nanosecond string, without floating-point loss.
	 *
	 * @param float $seconds Unix time.
	 * @return string
	 */
	public static function nanos( $seconds ) {
		$whole = (int) floor( $seconds );
		$frac  = (int) floor( ( $seconds - $whole ) * 1000000000 );
		return sprintf( '%d%09d', $whole, max( 0, min( 999999999, $frac ) ) );
	}

	/**
	 * A random id as lowercase hex.
	 *
	 * @param int $bytes 16 for a trace id, 8 for a span id.
	 * @return string
	 */
	public static function random_id( $bytes ) {
		for ( $i = 0; $i < 5; $i++ ) {
			try {
				$id = bin2hex( random_bytes( $bytes ) );
			} catch ( \Exception $e ) {
				$id = substr( md5( uniqid( (string) wp_rand(), true ) ) . md5( uniqid( (string) wp_rand(), true ) ), 0, $bytes * 2 );
			}
			if ( ! preg_match( '/^0+$/', $id ) ) { // An all-zero id is invalid in W3C Trace Context and OTLP.
				return $id;
			}
		}
		return str_repeat( '1', $bytes * 2 );
	}

	/**
	 * Build a span document (the array that becomes one queue row).
	 *
	 * @param array $s trace_id, span_id, parent_id (optional), name, kind, start, end (float seconds),
	 *                 attributes (map), status (0/1/2), status_message, events (list of [name, time, attributes map]).
	 * @return array
	 */
	public static function span( array $s ) {
		$span = array(
			'traceId'           => $s['trace_id'],
			'spanId'            => $s['span_id'],
			'name'              => (string) $s['name'],
			'kind'              => isset( $s['kind'] ) ? (int) $s['kind'] : self::KIND_INTERNAL,
			'startTimeUnixNano' => self::nanos( $s['start'] ),
			'endTimeUnixNano'   => self::nanos( max( $s['start'], $s['end'] ) ),
			'attributes'        => self::attrs( isset( $s['attributes'] ) ? $s['attributes'] : array() ),
		);
		if ( ! empty( $s['parent_id'] ) ) {
			$span['parentSpanId'] = $s['parent_id'];
		}
		if ( ! empty( $s['events'] ) ) {
			$span['events'] = array();
			foreach ( $s['events'] as $event ) {
				$span['events'][] = array(
					'name'         => (string) $event[0],
					'timeUnixNano' => self::nanos( $event[1] ),
					'attributes'   => self::attrs( isset( $event[2] ) ? $event[2] : array() ),
				);
			}
		}
		$status = array( 'code' => isset( $s['status'] ) ? (int) $s['status'] : self::STATUS_UNSET );
		if ( ! empty( $s['status_message'] ) ) {
			$status['message'] = (string) $s['status_message'];
		}
		$span['status'] = $status;
		return $span;
	}

	/**
	 * The resource (who is emitting) as an array.
	 *
	 * @return array
	 */
	public static function resource() {
		$settings = Settings::get();
		$tracing  = isset( $settings['tracing'] ) ? $settings['tracing'] : array();
		$service  = isset( $tracing['service_name'] ) && '' !== $tracing['service_name'] ? $tracing['service_name'] : $settings['source'];

		$attrs   = array(
			'service.name'                => $service,
			'deployment.environment.name' => Settings::environment(),
			'host.name'                   => Event_Factory::site_host(),
			'telemetry.sdk.name'          => 'ziplogger-wordpress',
			'telemetry.sdk.language'      => 'php',
			'telemetry.sdk.version'       => ZIPLOGGER_VERSION,
			'wordpress.version'           => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			'php.version'                 => PHP_VERSION,
		);
		$release = Event_Factory::release_value();
		if ( '' !== $release ) {
			$attrs['service.version'] = $release;
		}
		return array( 'attributes' => self::attrs( $attrs ) );
	}

	/**
	 * Wrap stored span documents into one ExportTraceServiceRequest. The same documents always give
	 * the same bytes for a given configuration.
	 *
	 * @param string[] $span_docs JSON documents, one per span.
	 * @return string
	 */
	public static function wrap_spans( array $span_docs ) {
		$resource = wp_json_encode( self::resource(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$scope    = wp_json_encode(
			array(
				'name'    => self::SCOPE_NAME,
				'version' => ZIPLOGGER_VERSION,
			)
		);
		return '{"resourceSpans":[{"resource":' . $resource . ',"scopeSpans":[{"scope":' . $scope . ',"spans":[' . implode( ',', $span_docs ) . ']}]}]}';
	}
}
