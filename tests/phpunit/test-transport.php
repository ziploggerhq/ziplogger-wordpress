<?php
/**
 * The HTTP contract and response classification.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Delivery_Result as R;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Transport;

class Test_Transport extends ZL_TestCase {

	private function send( $payload = '[{"message":"x"}]', $count = 1, $key = 'zlwp-abc123' ) {
		return ( new Transport() )->send( $payload, $key, $count, self::KEY, self::ENDPOINT );
	}

	public function test_request_matches_the_ingestion_contract() {
		$payload = '[{"timestamp":"2026-09-30T12:00:00.000Z","source":"wordpress","severity":"error","message":"Example","fields":{"a":1},"tags":["wordpress"]}]';
		$this->responses = array( self::response( 202, array( 'accepted' => 1, 'rejected' => 0 ) ) );
		$result          = $this->send( $payload, 1, 'zlwp-key-1' );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $this->requests );
		$req = $this->requests[0];
		$this->assertSame( 'https://app.ziplogger.ai/ingest/v1/logs', $req['url'] );
		$this->assertSame( 'POST', $req['args']['method'] );
		$this->assertSame( 'application/json', $req['args']['headers']['Content-Type'] );
		$this->assertSame( self::KEY, $req['args']['headers']['X-Api-Key'] );
		$this->assertSame( 'zlwp-key-1', $req['args']['headers']['Idempotency-Key'] );
		$this->assertSame( $payload, $req['args']['body'], 'The exact serialized payload is sent, untouched.' );
		$this->assertTrue( $req['args']['blocking'], 'Delivery must be blocking: a non-blocking request proves nothing.' );
		$this->assertTrue( $req['args']['sslverify'] );
		$this->assertSame( 0, $req['args']['redirection'], 'Redirects must never be followed with the API key attached.' );
		$this->assertSame( (int) Limits::get( 'http_timeout' ), $req['args']['timeout'] );
		$this->assertLessThanOrEqual( 15, $req['args']['timeout'] );
		$this->assertLessThanOrEqual( 65536, $req['args']['limit_response_size'] );
		$this->assertStringStartsWith( 'ZipLogger-WordPress/' . ZIPLOGGER_VERSION, $req['args']['user-agent'] );
		$this->assertStringNotContainsString( wp_parse_url( home_url(), PHP_URL_HOST ), $req['args']['user-agent'], 'The User-Agent must not identify the site.' );
		$this->assertArrayNotHasKey( 'Authorization', $req['args']['headers'] );
	}

	public function test_the_api_key_is_never_placed_in_the_url_or_body() {
		$this->send();
		$this->assertStringNotContainsString( self::KEY, $this->requests[0]['url'] );
		$this->assertStringNotContainsString( self::KEY, $this->requests[0]['args']['body'] );
	}

	public function test_other_plugins_cannot_weaken_tls_or_enable_redirects() {
		add_filter(
			'http_request_args',
			static function ( $args ) {
				$args['sslverify']   = false;
				$args['redirection'] = 5;
				return $args;
			},
			20
		);
		$seen = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen = apply_filters( 'https_ssl_verify', false, $url ); // What the transport would be told.
				return $pre;
			},
			5,
			3
		);
		Transport::register_hooks();
		$this->send();

		$this->assertTrue( $this->requests[0]['args']['sslverify'] );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
		$this->assertTrue( $seen, 'https_ssl_verify must be forced back to true while a ZipLogger request is in flight.' );
		$this->assertFalse( apply_filters( 'https_ssl_verify', false, 'https://other.example.org' ), 'Other requests are left alone.' );
	}

	public function test_the_in_flight_flag_is_set_only_during_the_request() {
		$during = null;
		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$during ) {
				$during = Transport::in_flight();
				return $pre;
			},
			5
		);
		$this->assertFalse( Transport::in_flight() );
		$this->send();
		$this->assertTrue( $during );
		$this->assertFalse( Transport::in_flight() );
	}

	public function test_an_exception_from_the_http_layer_becomes_a_transient_failure() {
		$this->responses = array(
			static function () {
				throw new RuntimeException( 'transport blew up' );
			},
		);
		$r = $this->send();
		$this->assertSame( R::TRANSIENT, $r->outcome );
		$this->assertFalse( Transport::in_flight(), 'The flag must be cleared even when the request throws.' );
	}

	public function test_no_key_or_unusable_endpoint_means_no_request() {
		$r = ( new Transport() )->send( '[{}]', 'k', 1, '', self::ENDPOINT );
		$this->assertSame( R::AUTH, $r->outcome );
		$r = ( new Transport() )->send( '[{}]', 'k', 1, self::KEY, 'http://insecure.example.org' );
		$this->assertSame( R::CONFIG, $r->outcome );
		$r = ( new Transport() )->send( '[{}]', 'k', 1, self::KEY, 'https://10.0.0.1' );
		$this->assertSame( R::CONFIG, $r->outcome );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_custom_endpoint_that_now_resolves_privately_is_refused_at_send_time() {
		$call = 0;
		\ZipLogger\WordPress\Endpoint::$resolver = static function () use ( &$call ) {
			return ++$call > 0 ? array( '10.0.0.7' ) : array();
		};
		$r = ( new Transport() )->send( '[{}]', 'k', 1, self::KEY, 'https://logs.example.org' );
		$this->assertSame( R::CONFIG, $r->outcome, 'DNS is re-checked on every request, not only when the setting is saved.' );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @dataProvider classification_provider
	 */
	public function test_response_classification( $status, $body, array $headers, $count, $outcome, $kind = null ) {
		$this->responses = array( self::response( $status, $body, $headers ) );
		$r               = $this->send( '[' . implode( ',', array_fill( 0, $count, '{}' ) ) . ']', $count );
		$this->assertSame( $outcome, $r->outcome, "HTTP $status" );
		if ( null !== $kind ) {
			$this->assertSame( $kind, $r->kind );
		}
	}

	public function classification_provider() {
		return array(
			'202 all accepted'            => array( 202, array( 'accepted' => 3, 'rejected' => 0 ), array(), 3, R::SUCCESS ),
			'200 all accepted'            => array( 200, array( 'accepted' => 3, 'rejected' => 0 ), array(), 3, R::SUCCESS ),
			'202 replay duplicate'        => array( 202, array( 'accepted' => 3, 'rejected' => 0, 'duplicate' => true ), array(), 3, R::SUCCESS ),
			'202 more than sent'          => array( 202, array( 'accepted' => 9, 'rejected' => 0 ), array(), 3, R::SUCCESS ),
			'202 fewer accepted'          => array( 202, array( 'accepted' => 2, 'rejected' => 0 ), array(), 3, R::PARTIAL ),
			'202 some rejected'           => array( 202, array( 'accepted' => 2, 'rejected' => 1 ), array(), 3, R::PARTIAL ),
			'202 none accepted'           => array( 202, array( 'accepted' => 0, 'rejected' => 3 ), array(), 3, R::TRANSIENT ),
			'202 empty body'              => array( 202, '', array(), 3, R::TRANSIENT, 'malformed_response' ),
			'200 html page'               => array( 200, '<html>captive portal</html>', array(), 3, R::TRANSIENT, 'malformed_response' ),
			'202 accepted not a number'   => array( 202, array( 'accepted' => 'lots' ), array(), 3, R::TRANSIENT, 'malformed_response' ),
			'204 no content'              => array( 204, '', array(), 3, R::TRANSIENT, 'malformed_response' ),
			'429 nothing accepted'        => array( 429, array( 'accepted' => 0, 'rejected' => 3 ), array( 'Retry-After' => '120' ), 3, R::RATE_LIMITED ),
			'429 partial'                 => array( 429, array( 'accepted' => 2, 'rejected' => 1 ), array( 'Retry-After' => '120' ), 3, R::PARTIAL ),
			'429 no body'                 => array( 429, '', array(), 3, R::RATE_LIMITED ),
			'401'                         => array( 401, array( 'error' => 'Invalid API key' ), array(), 3, R::AUTH ),
			'403'                         => array( 403, '', array(), 3, R::AUTH ),
			'400'                         => array( 400, array( 'error' => 'Invalid JSON' ), array(), 3, R::BAD_REQUEST ),
			'415'                         => array( 415, '', array(), 3, R::CONFIG ),
			'413'                         => array( 413, array( 'error' => 'Payload exceeds 32 MB.' ), array(), 3, R::TOO_LARGE ),
			'422 key conflict'            => array( 422, array( 'error' => 'This Idempotency-Key was already used with a different payload.' ), array(), 3, R::KEY_CONFLICT ),
			'409 in flight'               => array( 409, array( 'error' => 'still being processed' ), array( 'Retry-After' => '2' ), 3, R::TRANSIENT, 'in_flight' ),
			'408'                         => array( 408, '', array(), 3, R::TRANSIENT, 'timeout' ),
			'404'                         => array( 404, '', array(), 3, R::CONFIG, 'not_found' ),
			'405'                         => array( 405, '', array(), 3, R::CONFIG ),
			'301 redirect'                => array( 301, '', array( 'Location' => 'https://evil.example.org/' ), 3, R::CONFIG, 'redirect' ),
			'302 redirect'                => array( 302, '', array(), 3, R::CONFIG, 'redirect' ),
			'307 redirect'                => array( 307, '', array(), 3, R::CONFIG, 'redirect' ),
			'500'                         => array( 500, '', array(), 3, R::TRANSIENT, 'server_error' ),
			'502'                         => array( 502, '<html>Bad gateway</html>', array(), 3, R::TRANSIENT ),
			'503 durable unavailable'     => array( 503, array( 'accepted' => 0, 'rejected' => 3, 'code' => 'ingest_journal_unavailable' ), array( 'Retry-After' => '5' ), 3, R::TRANSIENT ),
			'504'                         => array( 504, '', array(), 3, R::TRANSIENT ),
		);
	}

	public function test_retry_after_is_parsed_from_seconds_and_dates() {
		$this->responses = array( self::response( 429, array( 'accepted' => 0, 'rejected' => 1 ), array( 'Retry-After' => '300' ) ) );
		$this->assertSame( 300, $this->send()->retry_after );

		$date            = gmdate( 'D, d M Y H:i:s', Clock::time() + 90 ) . ' GMT';
		$this->responses = array( self::response( 503, '', array( 'Retry-After' => $date ) ) );
		$this->assertSame( 90, $this->send()->retry_after );

		$this->responses = array( self::response( 503, '' ) );
		$this->assertNull( $this->send()->retry_after );
	}

	public function test_partial_results_report_the_counts_the_server_gave() {
		$this->responses = array( self::response( 429, array( 'accepted' => 2, 'rejected' => 3 ), array( 'Retry-After' => '60' ) ) );
		$r               = $this->send( '[{},{},{},{},{}]', 5 );
		$this->assertSame( R::PARTIAL, $r->outcome );
		$this->assertSame( 2, $r->accepted );
		$this->assertSame( 3, $r->rejected );
		$this->assertFalse( $r->is_success() );
	}

	/**
	 * @dataProvider wp_error_provider
	 */
	public function test_network_failures_are_transient( $code, $message, $kind ) {
		$this->responses = array( new WP_Error( $code, $message ) );
		$r               = $this->send();
		$this->assertSame( R::TRANSIENT, $r->outcome );
		$this->assertSame( $kind, $r->kind );
		$this->assertSame( 0, $r->status );
	}

	public function wp_error_provider() {
		return array(
			'timeout'      => array( 'http_request_failed', 'cURL error 28: Operation timed out after 8001 milliseconds with 0 bytes received', 'timeout' ),
			'dns'          => array( 'http_request_failed', 'cURL error 6: Could not resolve host: app.ziplogger.ai', 'network' ),
			'refused'      => array( 'http_request_failed', 'cURL error 7: Failed to connect to app.ziplogger.ai port 443: Connection refused', 'network' ),
			'tls'          => array( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', 'tls' ),
			'blocked'      => array( 'http_request_not_executed', 'User has blocked requests through HTTP.', 'network' ),
		);
	}

	public function test_diagnostics_never_contain_the_api_key_or_query_strings() {
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 35: failed for https://app.ziplogger.ai/ingest/v1/logs?key=' . self::KEY . ' with ' . self::KEY ) );
		$r               = $this->send();
		$this->assertStringNotContainsString( self::KEY, $r->message );
		$this->assertStringNotContainsString( '?key=', $r->message );

		$this->responses = array( self::response( 401, array( 'error' => 'Invalid API key ' . self::KEY ) ) );
		$r               = $this->send();
		$this->assertStringNotContainsString( self::KEY, $r->message );
	}

	public function test_server_error_text_is_bounded_and_sanitized() {
		$this->responses = array( self::response( 500, array( 'error' => str_repeat( 'boom ', 500 ) . ' contact ops@example.com at /var/www/private/app.php' ) ) );
		$r               = $this->send();
		$this->assertLessThanOrEqual( 300, strlen( $r->message ) );
		$this->responses = array( self::response( 500, array( 'error' => 'ops@example.com /var/www/private/app.php' ) ) );
		$r               = $this->send();
		$this->assertStringNotContainsString( 'ops@example.com', $r->message );
		$this->assertStringNotContainsString( '/var/www/private', $r->message );
	}
}
