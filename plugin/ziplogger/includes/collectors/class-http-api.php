<?php
/**
 * Outbound WordPress HTTP API collector.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Collectors;

use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Observes requests made through wp_remote_*() (http_api_debug) and records failures and, optionally,
 * slow requests. Only the destination HOST, the method, the status and the duration are kept: paths
 * and query strings routinely carry tokens (webhook URLs, bot tokens), so they are not read.
 *
 * ZipLogger's own transport requests are excluded so that delivering events can never create events.
 * Non-blocking requests (WP-Cron's loopback) are ignored: they have no observable outcome.
 */
final class Http_Api {

	const STAMP = '_ziplogger_started';

	/**
	 * Recorder.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * Constructor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'http_request_args', array( $this, 'stamp' ), 10, 2 );
		add_action( 'http_api_debug', array( $this, 'observe' ), 10, 5 );
	}

	/**
	 * Note when a request starts.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array
	 */
	public function stamp( $args, $url = '' ) {
		unset( $url );
		if ( is_array( $args ) && empty( $args['ziplogger_transport'] ) ) {
			$args[ self::STAMP ] = microtime( true );
		}
		return $args;
	}

	/**
	 * Inspect a finished request.
	 *
	 * @param array|\WP_Error $response Response.
	 * @param string          $context  "response".
	 * @param string          $class_name Transport class (unused).
	 * @param array           $args     Request arguments.
	 * @param string          $url      URL.
	 * @return void
	 */
	public function observe( $response = null, $context = '', $class_name = '', $args = array(), $url = '' ) {
		unset( $class_name );
		if ( 'response' !== $context || ! is_array( $args ) || ! is_string( $url ) ) {
			return;
		}
		// Never observe ZipLogger's own traffic, however it is identified.
		if ( Transport::in_flight() || ! empty( $args['ziplogger_transport'] ) ) {
			return;
		}
		if ( empty( $args['blocking'] ) ) {
			return;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}
		$host = strtolower( $host );
		if ( Settings::endpoint_host() === $host ) {
			return; // Traffic to the configured ZipLogger host is ZipLogger's own.
		}

		$settings = Settings::get();
		$method   = isset( $args['method'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $args['method'] ) ) : 'GET';
		$duration = isset( $args[ self::STAMP ] ) ? max( 0.0, microtime( true ) - (float) $args[ self::STAMP ] ) : null;
		$ms       = null === $duration ? null : (int) round( $duration * 1000 );

		$error  = is_wp_error( $response );
		$status = $error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$failed = $error || $status >= 500;

		$fields = array(
			'host'   => $host,
			'method' => $method,
		);
		if ( null !== $ms ) {
			$fields['durationMs'] = $ms;
		}

		if ( $failed && ! empty( $settings['collectors']['http_failures'] ) ) {
			$code = $error ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $response->get_error_code() ) ) : '';
			if ( $error ) {
				$fields['errorCode'] = '' === $code ? 'unknown' : $code;
				$reason              = $response->get_error_message();
			} else {
				$fields['statusCode'] = $status;
				$reason               = 'HTTP ' . $status;
			}
			$this->recorder->record(
				'http_failure',
				'warn',
				sprintf( 'Outbound HTTP request to %s failed: %s', $host, $reason ),
				array(
					'template' => 'Outbound HTTP request failed',
					'fields'   => $fields,
					'dedupe'   => true,
					'fp_extra' => $host . '|' . $method . '|' . ( $error ? $code : $status ),
					'exempt'   => true,
				)
			);
			return;
		}

		if ( ! empty( $settings['collectors']['http_slow'] ) && null !== $duration && $duration >= (float) $settings['slow_http_seconds'] ) {
			if ( ! $error ) {
				$fields['statusCode'] = $status;
			}
			$this->recorder->record(
				'http_slow',
				'warn',
				sprintf( 'Outbound HTTP request to %s took %d ms', $host, (int) $ms ),
				array(
					'template' => 'Outbound HTTP request was slow',
					'fields'   => $fields,
					'dedupe'   => true,
					'fp_extra' => $host . '|' . $method,
					'exempt'   => true,
				)
			);
		}
	}
}
