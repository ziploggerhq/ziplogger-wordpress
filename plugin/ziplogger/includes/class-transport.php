<?php
/**
 * HTTP delivery to ZipLogger and classification of the response.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * POST {endpoint}{signal path}
 *   Content-Type: application/json
 *   X-Api-Key: <server ingestion key>
 *   Idempotency-Key: zlwp-<batch id>          (logs and events; see Signal::uses_idempotency_key)
 *
 * Contract facts this class relies on (from the backend's LogEndpoints, EventEndpoints and
 * OtlpEndpoints, and IngestRequestFlow):
 *
 *  logs    202 {"accepted":N,"rejected":0}   everything admitted.
 *          429 {"accepted":N,"rejected":M} + Retry-After   a PREFIX was admitted; a retry with the same key
 *          and the same body ingests only the remainder. 422 key bound to a different body; 409 in flight.
 *  events  202 {"accepted","rejected","identified","quota"}   the request was PROCESSED. "rejected" counts
 *          events failing validation (for example no user id): retrying cannot help, so it is reported
 *          but not retried. 429 = quota; the accepted prefix was stored and a retry is safe because every
 *          event carries an insertId.
 *  traces  200 {} or {"partialSuccess":{"rejectedSpans":N}}   spans upsert on (traceId, spanId), so re-sending
 *          a batch is safe.
 *  all     401 missing / invalid / wrong-scope key. 400 unparsable body. 413 over the size ceiling.
 *          503 + Retry-After when the node cannot store durably. 403/429 with a "quota" object when a plan
 *          pool is exhausted (Retry-After header or quota.retryAfterSeconds).
 *
 * A 2xx is never trusted on status alone: the body is checked against what was sent.
 *
 * Security: HTTPS certificates are always verified (a late-priority filter re-asserts this against
 * other plugins), redirects are never followed (so the API key cannot be forwarded to another
 * host), the response size is capped, and every diagnostic is redacted before it is kept.
 */
final class Transport {

	/**
	 * True while a ZipLogger request is in flight; other classes use it to recognise (and ignore)
	 * the plugin's own HTTP traffic.
	 *
	 * @var bool
	 */
	private static $in_flight = false;

	/**
	 * Whether a transport request is currently being made.
	 *
	 * @return bool
	 */
	public static function in_flight() {
		return self::$in_flight;
	}

	/**
	 * Register the request-hardening filters. Called once at boot.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'http_request_args', array( __CLASS__, 'harden_args' ), PHP_INT_MAX, 2 );
		add_filter( 'https_ssl_verify', array( __CLASS__, 'force_ssl_verify' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Re-assert safe arguments on our own requests, whatever other plugins did to them.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array
	 */
	public static function harden_args( $args, $url = '' ) {
		unset( $url );
		if ( is_array( $args ) && ! empty( $args['ziplogger_transport'] ) ) {
			$args['sslverify']   = true;
			$args['redirection'] = 0;
		}
		return $args;
	}

	/**
	 * Certificate verification stays on for our requests.
	 *
	 * @param bool   $verify Current value.
	 * @param string $url    URL.
	 * @return bool
	 */
	public static function force_ssl_verify( $verify, $url = '' ) {
		unset( $url );
		return self::$in_flight ? true : $verify;
	}

	/**
	 * Send a payload.
	 *
	 * @param string $payload       Exact request body.
	 * @param string $key           Idempotency key.
	 * @param int    $event_count   Number of items in the payload.
	 * @param string $api_key       Server ingestion key.
	 * @param string $endpoint_base Validated endpoint base URL.
	 * @param string $signal        Signal.
	 * @return Delivery_Result
	 */
	public function send( $payload, $key, $event_count, $api_key, $endpoint_base, $signal = Signal::LOGS ) {
		$redactor = new Redactor( array( 'secrets' => array( $api_key ) ) );

		if ( '' === $api_key ) {
			return new Delivery_Result( Delivery_Result::AUTH, 0, 'no_key', __( 'No API key is configured.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		$problem = Endpoint::preflight( $endpoint_base );
		if ( '' !== $problem ) {
			return new Delivery_Result( Delivery_Result::CONFIG, 0, 'endpoint', $redactor->diagnostic( $problem ) );
		}

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'X-Api-Key'    => $api_key,
		);
		if ( Signal::uses_idempotency_key( $signal ) ) {
			$headers['Idempotency-Key'] = $key;
		}

		$args = array(
			'method'              => 'POST',
			'timeout'             => (int) Limits::get( 'http_timeout' ),
			'redirection'         => 0,
			'httpversion'         => '1.1',
			'blocking'            => true,
			'sslverify'           => true,
			'decompress'          => true,
			'limit_response_size' => 65536,
			'user-agent'          => self::user_agent(),
			'headers'             => $headers,
			'body'                => $payload,
			'data_format'         => 'body',
			'ziplogger_transport' => true,
		);

		self::$in_flight = true;
		try {
			$response = wp_remote_post( Signal::url( $signal, $endpoint_base ), $args );
		} catch ( \Throwable $e ) {
			$response = new \WP_Error( 'ziplogger_exception', $e->getMessage() );
		} finally {
			self::$in_flight = false;
		}

		return $this->classify( $response, (int) $event_count, $redactor, $signal );
	}

	/**
	 * Turn a WordPress HTTP response into a Delivery_Result.
	 *
	 * @param array|\WP_Error $response    Response.
	 * @param int             $event_count Items sent.
	 * @param Redactor        $redactor    Redactor for diagnostics.
	 * @param string          $signal      Signal.
	 * @return Delivery_Result
	 */
	public function classify( $response, $event_count, Redactor $redactor, $signal = Signal::LOGS ) {
		if ( is_wp_error( $response ) ) {
			$code    = (string) $response->get_error_code();
			$message = $response->get_error_message();
			$kind    = ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) ? 'timeout' : 'network';
			if ( false !== stripos( $message, 'ssl' ) || false !== stripos( $message, 'certificate' ) ) {
				$kind = 'tls';
			}
			return new Delivery_Result( Delivery_Result::TRANSIENT, 0, $kind, $redactor->diagnostic( $code . ': ' . $message ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$retry  = Backoff::parse_retry_after( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		$json   = json_decode( $body, true );
		$json   = is_array( $json ) ? $json : array();
		$server = isset( $json['error'] ) && is_string( $json['error'] ) ? $redactor->diagnostic( $json['error'] ) : '';

		if ( null === $retry && isset( $json['quota']['retryAfterSeconds'] ) && is_numeric( $json['quota']['retryAfterSeconds'] ) ) {
			$retry = min( max( 0, (int) $json['quota']['retryAfterSeconds'] ), (int) Limits::get( 'retry_after_cap' ) );
		}

		if ( $status >= 200 && $status < 300 ) {
			if ( Signal::TRACES === $signal ) {
				return $this->classify_traces_success( $status, $body, $json, $event_count, $retry );
			}
			if ( Signal::EVENTS === $signal ) {
				return $this->classify_events_success( $status, $json, $retry );
			}
			return $this->classify_success( $status, $json, $event_count, $retry );
		}

		$accepted = isset( $json['accepted'] ) && is_numeric( $json['accepted'] ) ? (int) $json['accepted'] : null;
		$rejected = isset( $json['rejected'] ) && is_numeric( $json['rejected'] ) ? (int) $json['rejected'] : null;

		// A plan pool is exhausted (or the feature is not in the plan): waiting is the only client-side answer.
		if ( 403 === $status && isset( $json['quota']['refusal'] ) ) {
			return new Delivery_Result( Delivery_Result::RATE_LIMITED, $status, 'quota', '' !== $server ? $server : 'HTTP 403 (' . preg_replace( '/[^a-z_]/', '', (string) $json['quota']['refusal'] ) . ')', $accepted, $rejected, $retry );
		}

		switch ( true ) {
			case 429 === $status:
				$partial = null !== $accepted && $accepted > 0;
				return new Delivery_Result(
					$partial ? Delivery_Result::PARTIAL : Delivery_Result::RATE_LIMITED,
					$status,
					'rate_limited',
					'' !== $server ? $server : 'HTTP 429',
					$accepted,
					$rejected,
					$retry
				);
			case 401 === $status:
			case 403 === $status:
				return new Delivery_Result( Delivery_Result::AUTH, $status, 'auth', '' !== $server ? $server : 'HTTP ' . $status, null, null, $retry );
			case 413 === $status:
				return new Delivery_Result( Delivery_Result::TOO_LARGE, $status, 'too_large', '' !== $server ? $server : 'HTTP 413' );
			case 422 === $status:
				return new Delivery_Result( Delivery_Result::KEY_CONFLICT, $status, 'key_conflict', '' !== $server ? $server : 'HTTP 422' );
			case 409 === $status:
				return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'in_flight', '' !== $server ? $server : 'HTTP 409', null, null, $retry );
			case 408 === $status:
				return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'timeout', 'HTTP 408', null, null, $retry );
			case $status >= 300 && $status < 400:
				return new Delivery_Result( Delivery_Result::CONFIG, $status, 'redirect', 'HTTP ' . $status . ': the endpoint redirected. Redirects are not followed so the API key cannot be forwarded; check the endpoint URL.' );
			case 404 === $status:
			case 405 === $status:
			case 410 === $status:
				return new Delivery_Result( Delivery_Result::CONFIG, $status, 'not_found', 'HTTP ' . $status . ': the ingestion endpoint was not found. Check the endpoint URL.' );
			case 415 === $status:
				return new Delivery_Result( Delivery_Result::CONFIG, $status, 'unsupported_media_type', 'HTTP 415: the endpoint did not accept the content type.' );
			case $status >= 500:
				return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'server_error', '' !== $server ? $server : 'HTTP ' . $status, null, null, $retry );
			case $status >= 400:
				return new Delivery_Result( Delivery_Result::BAD_REQUEST, $status, 'bad_request', '' !== $server ? $server : 'HTTP ' . $status );
			default:
				return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'unexpected_status', 'HTTP ' . $status, null, null, $retry );
		}
	}

	/**
	 * A logs 2xx. It only counts as delivered when the body says every event was accepted.
	 *
	 * @param int      $status      HTTP status.
	 * @param array    $json        Decoded body.
	 * @param int      $event_count Events sent.
	 * @param int|null $retry       Retry-After.
	 * @return Delivery_Result
	 */
	private function classify_success( $status, array $json, $event_count, $retry ) {
		if ( ! isset( $json['accepted'] ) || ! is_numeric( $json['accepted'] ) ) {
			// A proxy's 200 page, a captive portal, an empty body: not proof of ingestion. Retrying with
			// the same key is safe - if the backend did admit the batch it replays the original answer.
			return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'malformed_response', 'HTTP ' . $status . ' without an "accepted" count in the response body.', null, null, $retry );
		}
		$accepted = (int) $json['accepted'];
		$rejected = isset( $json['rejected'] ) && is_numeric( $json['rejected'] ) ? (int) $json['rejected'] : 0;

		if ( $rejected > 0 || $accepted < $event_count ) {
			return new Delivery_Result(
				$accepted > 0 ? Delivery_Result::PARTIAL : Delivery_Result::TRANSIENT,
				$status,
				'partial',
				sprintf( 'HTTP %d: %d of %d events accepted, %d rejected.', $status, $accepted, $event_count, $rejected ),
				$accepted,
				$rejected,
				$retry
			);
		}
		return new Delivery_Result( Delivery_Result::SUCCESS, $status, 'accepted', '', $accepted, $rejected );
	}

	/**
	 * An events 2xx: the request was processed. Validation rejects are final and only counted.
	 *
	 * @param int      $status HTTP status.
	 * @param array    $json   Decoded body.
	 * @param int|null $retry  Retry-After.
	 * @return Delivery_Result
	 */
	private function classify_events_success( $status, array $json, $retry ) {
		if ( ! isset( $json['accepted'] ) || ! is_numeric( $json['accepted'] ) ) {
			return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'malformed_response', 'HTTP ' . $status . ' without an "accepted" count in the response body.', null, null, $retry );
		}
		$rejected = isset( $json['rejected'] ) && is_numeric( $json['rejected'] ) ? (int) $json['rejected'] : 0;
		return new Delivery_Result( Delivery_Result::SUCCESS, $status, 'accepted', '', (int) $json['accepted'], $rejected );
	}

	/**
	 * A traces 2xx: an empty body or "{}" is success; partialSuccess.rejectedSpans means some spans were not stored.
	 *
	 * @param int      $status      HTTP status.
	 * @param string   $body        Raw body.
	 * @param array    $json        Decoded body.
	 * @param int      $event_count Spans sent.
	 * @param int|null $retry       Retry-After.
	 * @return Delivery_Result
	 */
	private function classify_traces_success( $status, $body, array $json, $event_count, $retry ) {
		if ( '' !== trim( $body ) && ! is_array( json_decode( $body, true ) ) ) {
			return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'malformed_response', 'HTTP ' . $status . ' with a response body that is not OTLP JSON.', null, null, $retry );
		}
		$rejected = isset( $json['partialSuccess']['rejectedSpans'] ) && is_numeric( $json['partialSuccess']['rejectedSpans'] ) ? (int) $json['partialSuccess']['rejectedSpans'] : 0;
		if ( $rejected > 0 ) {
			// Spans upsert on their ids, so sending the batch again is harmless and may succeed.
			return new Delivery_Result( Delivery_Result::TRANSIENT, $status, 'partial_success', sprintf( 'HTTP %d: %d of %d spans were rejected.', $status, $rejected, $event_count ), max( 0, $event_count - $rejected ), $rejected, $retry );
		}
		return new Delivery_Result( Delivery_Result::SUCCESS, $status, 'accepted', '', $event_count, 0 );
	}

	/**
	 * User-Agent: product, version and platform. No site URL and nothing identifying.
	 *
	 * @return string
	 */
	public static function user_agent() {
		global $wp_version;
		return 'ZipLogger-WordPress/' . ZIPLOGGER_VERSION . ' (WordPress/' . ( isset( $wp_version ) ? $wp_version : 'unknown' ) . '; PHP/' . PHP_VERSION . ')';
	}
}
