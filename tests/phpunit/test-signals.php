<?php
/**
 * Multiple signals through one queue: events and traces, OTLP payloads, per-signal delivery state,
 * queue shares, round-robin service.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Delivery_Result as R;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Otlp;
use ZipLogger\WordPress\Queue_Writer;
use ZipLogger\WordPress\Signal;
use ZipLogger\WordPress\Transport;
use ZipLogger\WordPress\Worker;

class Test_Signals extends ZL_TestCase {

	public function set_up() {
		parent::set_up();
		$this->enable();
	}

	private function limit( array $values ) {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) use ( $values ) {
				return array_merge( $l, $values );
			}
		);
		Limits::reset();
	}

	/**
	 * Queue N items of a signal through the writer (the real path).
	 */
	private function queue_items( $signal, $n, $prefix = 'item' ) {
		$candidates = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			if ( Signal::TRACES === $signal ) {
				$doc = wp_json_encode(
					Otlp::span(
						array(
							'trace_id'   => str_pad( dechex( $i ), 32, '0', STR_PAD_LEFT ),
							'span_id'    => str_pad( dechex( $i ), 16, '0', STR_PAD_LEFT ),
							'name'       => $prefix . ' ' . $i,
							'kind'       => Otlp::KIND_SERVER,
							'start'      => Clock::now(),
							'end'        => Clock::now() + 0.25,
							'attributes' => array( 'http.request.method' => 'GET' ),
						)
					)
				);
			} else {
				$doc = wp_json_encode(
					array(
						'name'        => $prefix . '_' . $i,
						'userId'      => 'wpu_test',
						'timestamp'   => Clock::iso(),
						'insertId'    => 'ins-' . $prefix . '-' . $i,
						'properties'  => array( 'n' => $i ),
					)
				);
			}
			$candidates[] = array(
				'payload'     => $doc,
				'size'        => strlen( $doc ),
				'severity'    => 2,
				'fingerprint' => '',
			);
		}
		return ( new Queue_Writer() )->write( $signal, $candidates );
	}

	// ---------------------------------------------------------------------------------------------
	// Signal definitions and OTLP building blocks.
	// ---------------------------------------------------------------------------------------------

	public function test_signal_paths_and_state_keys() {
		$this->assertSame( 'https://app.ziplogger.ai/ingest/v1/logs', Signal::url( Signal::LOGS, 'https://app.ziplogger.ai' ) );
		$this->assertSame( 'https://app.ziplogger.ai/ingest/v1/events', Signal::url( Signal::EVENTS, 'https://app.ziplogger.ai/' ) );
		$this->assertSame( 'https://app.ziplogger.ai/v1/traces', Signal::url( Signal::TRACES, 'https://app.ziplogger.ai' ) );
		$this->assertSame( '', Signal::suffix( Signal::LOGS ), 'Logs keep the original, unsuffixed state keys.' );
		$this->assertSame( ':events', Signal::suffix( Signal::EVENTS ) );
		$this->assertTrue( Signal::uses_idempotency_key( Signal::LOGS ) );
		$this->assertTrue( Signal::uses_idempotency_key( Signal::EVENTS ) );
		$this->assertFalse( Signal::uses_idempotency_key( Signal::TRACES ) );
		$this->assertFalse( Signal::is_valid( 'metrics' ) );
	}

	public function test_otlp_attribute_types_match_what_the_backend_parser_reads() {
		$attrs = Otlp::attrs(
			array(
				's'    => 'text',
				'b'    => true,
				'i'    => 42,
				'f'    => 1.5,
				'list' => array( 'a', 'b' ),
				'nan'  => NAN,
				'null' => null,
				'map'  => array( 'k' => 'v' ), // kvlistValue is NOT parsed by the backend: dropped, never emitted.
				''     => 'nameless',
			)
		);
		$by = array();
		foreach ( $attrs as $a ) {
			$by[ $a['key'] ] = $a['value'];
		}
		$this->assertSame( array( 'stringValue' => 'text' ), $by['s'] );
		$this->assertSame( array( 'boolValue' => true ), $by['b'] );
		$this->assertSame( array( 'intValue' => '42' ), $by['i'], 'int64 travels as a string to keep precision.' );
		$this->assertSame( array( 'doubleValue' => 1.5 ), $by['f'] );
		$this->assertSame( 'a', $by['list']['arrayValue']['values'][0]['stringValue'] );
		$this->assertArrayNotHasKey( 'nan', $by );
		$this->assertArrayNotHasKey( 'null', $by );
		$this->assertArrayNotHasKey( 'map', $by );
		$this->assertArrayNotHasKey( '', $by );
	}

	public function test_nanos_are_exact_and_ids_are_valid() {
		$this->assertSame( '1790000000000000000', Otlp::nanos( 1790000000.0 ) );
		$this->assertSame( '1790000000500000000', Otlp::nanos( 1790000000.5 ) );
		$this->assertSame( 19, strlen( Otlp::nanos( microtime( true ) ) ) );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Otlp::random_id( 16 ) );
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', Otlp::random_id( 8 ) );
			$this->assertDoesNotMatchRegularExpression( '/^0+$/', Otlp::random_id( 8 ) );
		}
	}

	public function test_a_span_document_has_the_shape_the_backend_parses() {
		$span = Otlp::span(
			array(
				'trace_id'       => str_repeat( 'a', 32 ),
				'span_id'        => str_repeat( 'b', 16 ),
				'parent_id'      => str_repeat( 'c', 16 ),
				'name'           => 'GET single',
				'kind'           => Otlp::KIND_SERVER,
				'start'          => 1790000000.0,
				'end'            => 1790000000.25,
				'attributes'     => array( 'http.response.status_code' => 500 ),
				'status'         => Otlp::STATUS_ERROR,
				'status_message' => 'boom',
				'events'         => array( array( 'exception', 1790000000.2, array( 'exception.type' => 'RuntimeException', 'exception.message' => 'boom' ) ) ),
			)
		);
		$this->assertSame( 'GET single', $span['name'] );
		$this->assertSame( 2, $span['kind'] );
		$this->assertSame( str_repeat( 'c', 16 ), $span['parentSpanId'] );
		$this->assertSame( '1790000000000000000', $span['startTimeUnixNano'] );
		$this->assertSame( '1790000000250000000', $span['endTimeUnixNano'] );
		$this->assertSame( 2, $span['status']['code'] );
		$this->assertSame( 'exception', $span['events'][0]['name'] );
		$this->assertSame( 'exception.type', $span['events'][0]['attributes'][0]['key'] );

		$no_parent = Otlp::span( array( 'trace_id' => str_repeat( 'a', 32 ), 'span_id' => str_repeat( 'b', 16 ), 'name' => 'x', 'start' => 1.0, 'end' => 0.5 ) );
		$this->assertArrayNotHasKey( 'parentSpanId', $no_parent );
		$this->assertSame( $no_parent['startTimeUnixNano'], $no_parent['endTimeUnixNano'], 'A span never ends before it starts.' );
	}

	public function test_the_trace_payload_wraps_stored_spans_deterministically() {
		\ZipLogger\WordPress\Settings::save( array( 'enabled' => true, 'source' => 'shop', 'tracing' => array( 'service_name' => 'shop-web' ) ) );
		$this->queue_items( Signal::TRACES, 3, 'span' );
		$batch = $this->queue->claim_next( Signal::TRACES );
		$again = $batch->payload;

		$decoded = json_decode( $batch->payload, true );
		$this->assertNotNull( $decoded, 'The payload is valid JSON.' );
		$this->assertCount( 3, $decoded['resourceSpans'][0]['scopeSpans'][0]['spans'] );
		$res = array();
		foreach ( $decoded['resourceSpans'][0]['resource']['attributes'] as $a ) {
			$res[ $a['key'] ] = $a['value']['stringValue'];
		}
		$this->assertSame( 'shop-web', $res['service.name'] );
		$this->assertSame( 'ziplogger-wordpress', $res['telemetry.sdk.name'] );
		$this->assertSame( 'ziplogger-wordpress', $decoded['resourceSpans'][0]['scopeSpans'][0]['scope']['name'] );

		$this->queue->release( $batch, Clock::time() );
		$retry = $this->queue->claim_next( Signal::TRACES );
		$this->assertSame( $again, $retry->payload, 'A retry rebuilds byte-identical bytes.' );
		$this->assertStringStartsWith( 'zlwp-tr-', $retry->key() );
	}

	public function test_batches_never_mix_signals() {
		$this->queue_items( Signal::LOGS, 0 );
		$this->seed( 3 );
		$this->queue_items( Signal::EVENTS, 3, 'ev' );
		$this->queue_items( Signal::TRACES, 3 );
		$logs   = $this->queue->claim_next( Signal::LOGS );
		$events = $this->queue->claim_next( Signal::EVENTS );
		$traces = $this->queue->claim_next( Signal::TRACES );
		$this->assertSame( 3, $logs->count );
		$this->assertSame( 3, $events->count );
		$this->assertSame( 3, $traces->count );
		$this->assertStringStartsWith( 'zlwp-ev-', $events->key() );
		$this->assertStringStartsWith( 'zlwp-', $logs->key() );
		$this->assertStringNotContainsString( 'ev_', $logs->payload );
		$this->assertNull( $this->queue->claim_next( Signal::EVENTS ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Events delivery.
	// ---------------------------------------------------------------------------------------------

	public function test_events_go_to_the_events_endpoint_with_the_same_contract_rules() {
		$this->queue_items( Signal::EVENTS, 3, 'ev' );
		$this->responses = array( self::response( 202, array( 'accepted' => 3, 'rejected' => 0, 'identified' => 0 ) ) );
		$report          = ( new Worker() )->run();

		$this->assertSame( 'sent', $report['status'] );
		$req = $this->requests[0];
		$this->assertSame( 'https://app.ziplogger.ai/ingest/v1/events', $req['url'] );
		$this->assertSame( self::KEY, $req['args']['headers']['X-Api-Key'] );
		$this->assertSame( 'application/json', $req['args']['headers']['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^zlwp-ev-[0-9a-f]{32}$/', $req['args']['headers']['Idempotency-Key'] );
		$this->assertCount( 3, json_decode( $req['args']['body'], true ) );
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( 3, $this->meta->get_num( $this->meta->sk( Meta_Store::SENT_EVENTS, Signal::EVENTS ) ) );
		$this->assertSame( 0, $this->meta->get_num( Meta_Store::SENT_EVENTS ), 'Counters are per signal.' );
	}

	public function test_events_the_backend_refuses_on_validation_are_counted_not_retried() {
		$this->queue_items( Signal::EVENTS, 3, 'ev' );
		$this->responses = array( self::response( 202, array( 'accepted' => 1, 'rejected' => 2, 'identified' => 0 ) ) );
		( new Worker() )->run();
		$this->assertSame( 0, $this->queue_count(), 'A 202 means the request was processed; retrying rejected events cannot help.' );
		$this->assertSame( 2, $this->meta->get_num( $this->meta->sk( Meta_Store::SERVER_REJECTED, Signal::EVENTS ) ) );
		$this->assertCount( 1, $this->requests );
	}

	public function test_an_events_quota_429_retries_the_same_batch_because_events_are_idempotent() {
		$this->queue_items( Signal::EVENTS, 4, 'ev' );
		$this->responses = array(
			self::response( 429, array( 'accepted' => 2, 'rejected' => 2 ), array( 'Retry-After' => '60' ) ),
			self::response( 202, array( 'accepted' => 4, 'rejected' => 0 ) ),
		);
		( new Worker() )->run();
		$this->assertSame( 4, $this->queue_count() );
		Clock::advance( 200 );
		( new Worker() )->run();
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'], 'Byte-identical, so every event keeps its insertId.' );
		$this->assertSame( $this->requests[0]['args']['headers']['Idempotency-Key'], $this->requests[1]['args']['headers']['Idempotency-Key'] );
	}

	public function test_an_events_response_without_a_count_is_not_success() {
		$this->queue_items( Signal::EVENTS, 2, 'ev' );
		$this->responses = array( self::response( 200, '<html>portal</html>' ) );
		( new Worker() )->run();
		$this->assertSame( 2, $this->queue_count() );
	}

	// ---------------------------------------------------------------------------------------------
	// Traces delivery.
	// ---------------------------------------------------------------------------------------------

	public function test_traces_are_sent_as_otlp_json_without_an_idempotency_key() {
		$this->queue_items( Signal::TRACES, 4 );
		$this->responses = array( self::response( 200, '{}' ) );
		( new Worker() )->run();

		$req = $this->requests[0];
		$this->assertSame( 'https://app.ziplogger.ai/v1/traces', $req['url'] );
		$this->assertSame( 'application/json', $req['args']['headers']['Content-Type'] );
		$this->assertSame( self::KEY, $req['args']['headers']['X-Api-Key'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $req['args']['headers'], 'The traces endpoint has no idempotency claim; span ids upsert instead.' );
		$this->assertCount( 4, json_decode( $req['args']['body'], true )['resourceSpans'][0]['scopeSpans'][0]['spans'] );
		$this->assertSame( 0, $this->queue_count() );
	}

	/**
	 * @dataProvider trace_response_provider
	 */
	public function test_trace_response_classification( $status, $body, $expected ) {
		$this->responses = array( self::response( $status, $body ) );
		$r               = ( new Transport() )->send( '{"resourceSpans":[]}', 'k', 5, self::KEY, self::ENDPOINT, Signal::TRACES );
		$this->assertSame( $expected, $r->outcome, "HTTP $status $body" );
	}

	public function trace_response_provider() {
		return array(
			'empty json'            => array( 200, '{}', R::SUCCESS ),
			'empty body'            => array( 200, '', R::SUCCESS ),
			'zero rejected'         => array( 200, '{"partialSuccess":{"rejectedSpans":0}}', R::SUCCESS ),
			'some rejected'         => array( 200, '{"partialSuccess":{"rejectedSpans":2,"errorMessage":"some spans were rejected"}}', R::TRANSIENT ),
			'html captive portal'   => array( 200, '<html>login</html>', R::TRANSIENT ),
			'unauthorized'          => array( 401, '{"error":"Invalid API key"}', R::AUTH ),
			'unsupported media'     => array( 415, '', R::CONFIG ),
			'too large'             => array( 413, '{"error":"too big"}', R::TOO_LARGE ),
			'bad payload'           => array( 400, '{"error":"Invalid OTLP payload"}', R::BAD_REQUEST ),
			'quota'                 => array( 429, '{"error":"quota"}', R::RATE_LIMITED ),
			'server error'          => array( 500, '', R::TRANSIENT ),
		);
	}

	public function test_a_partial_trace_success_is_retried_and_reported() {
		$this->queue_items( Signal::TRACES, 3 );
		$this->responses = array(
			self::response( 200, '{"partialSuccess":{"rejectedSpans":1}}' ),
			self::response( 200, '{}' ),
		);
		( new Worker() )->run();
		$this->assertSame( 3, $this->queue_count(), 'Kept: some spans were not stored.' );
		$this->assertStringContainsString( 'spans were rejected', $this->meta->get_json( $this->meta->sk( Meta_Store::LAST_ERROR, Signal::TRACES ) )['message'] );
		Clock::advance( 4000 );
		( new Worker() )->run( array( 'force' => true ) );
		$this->assertSame( 0, $this->queue_count() );
	}

	// ---------------------------------------------------------------------------------------------
	// Quota bodies.
	// ---------------------------------------------------------------------------------------------

	public function test_a_plan_quota_refusal_is_a_wait_with_the_servers_retry_time() {
		$this->queue_items( Signal::EVENTS, 2, 'ev' );
		$body            = array(
			'error' => 'quota',
			'code'  => 'quota',
			'quota' => array( 'refusal' => 'daily_ceiling', 'retryAfterSeconds' => 5400, 'action' => 'retry_later' ),
		);
		$this->responses = array( self::response( 403, $body ) );
		( new Worker() )->run();
		$this->assertSame( 2, $this->queue_count() );
		$gate = $this->meta->get_num( $this->meta->sk( Meta_Store::GATE_UNTIL, Signal::EVENTS ) );
		$this->assertGreaterThanOrEqual( Clock::time() + 5400, $gate, 'The body\'s retryAfterSeconds is honoured when there is no header.' );
		$this->assertSame( '', $this->meta->get_str( $this->meta->sk( Meta_Store::BLOCKED, Signal::EVENTS ) ), 'It is not an authentication failure.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Independence of signals.
	// ---------------------------------------------------------------------------------------------

	public function test_one_signal_failing_does_not_stop_the_others() {
		$this->seed( 2 );
		$this->queue_items( Signal::TRACES, 2 );
		$this->responses = array(
			function ( $args, $url ) {
				return false !== strpos( $url, '/v1/traces' )
					? self::response( 404, '' )
					: self::response( 202, array( 'accepted' => $this->count_events( $args['body'] ), 'rejected' => 0 ) );
			},
		);
		$report = ( new Worker() )->run();
		$this->assertSame( 2, $report['events_sent'], 'Logs were delivered.' );
		$this->assertSame( 'config', $this->meta->get_str( $this->meta->sk( Meta_Store::BLOCKED, Signal::TRACES ) ) );
		$this->assertSame( '', $this->meta->get_str( Meta_Store::BLOCKED ), 'The logs signal is not blocked.' );
		$this->assertSame( 2, $this->queue->counts_by_signal()[ Signal::TRACES ] );
		$this->assertStringContainsString( 'Traces', $report['error'], 'The error names the signal that failed.' );

		// Next run: traces stay paused, logs keep flowing.
		$this->seed( 1, 'later' );
		$before = count( $this->requests );
		( new Worker() )->run();
		$urls = array_map( static function ( $r ) { return $r['url']; }, array_slice( $this->requests, $before ) );
		$this->assertSame( array( 'https://app.ziplogger.ai/ingest/v1/logs' ), $urls );
	}

	public function test_signals_are_served_round_robin_so_a_backlog_cannot_starve_another() {
		$this->limit( array( 'batch_max_events' => 2, 'worker_max_batches' => 4 ) );
		$this->seed( 6 );
		$this->queue_items( Signal::EVENTS, 6, 'ev' );
		( new Worker() )->run();
		$paths = array_map(
			static function ( $r ) {
				return substr( $r['url'], strlen( 'https://app.ziplogger.ai' ) );
			},
			$this->requests
		);
		$this->assertSame( array( '/ingest/v1/logs', '/ingest/v1/events', '/ingest/v1/logs', '/ingest/v1/events' ), $paths );
	}

	public function test_each_signal_has_its_own_health() {
		$this->queue_items( Signal::EVENTS, 2, 'ev' );
		$this->responses = array( self::response( 202, array( 'accepted' => 2, 'rejected' => 0 ) ) );
		( new Worker() )->run();
		$h = \ZipLogger\WordPress\Health::snapshot();
		$this->assertGreaterThan( 0, $h['signals']['events']['last_success'] );
		$this->assertSame( 0, $h['signals']['logs']['last_success'] );
		$this->assertSame( 2, $h['signals']['events']['sent_events'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Capacity shares.
	// ---------------------------------------------------------------------------------------------

	public function test_spans_and_events_cannot_crowd_out_logs() {
		$this->limit( array( 'queue_max_events' => 100 ) );
		$this->assertSame( 50, $this->queue_items( Signal::TRACES, 80 ), 'Traces may use at most half of the queue.' );
		$this->assertSame( 30, $this->meta->drop_counts_by_signal()[ Signal::TRACES ]['overflow'] );
		$this->assertSame( 0, $this->meta->drop_counts_by_signal()[ Signal::LOGS ]['overflow'] );

		$this->assertSame( 50, $this->queue_items( Signal::EVENTS, 80, 'ev' ) - 0, 'Events fill what is left, up to the total bound.' );
		$this->assertSame( 100, $this->queue_count() );

		$this->seed( 1 );
		$this->assertSame( 101, $this->queue_count(), 'seed() bypasses the writer; the point is the logs share below.' );
	}

	public function test_logs_still_fit_when_the_other_signals_are_at_their_shares() {
		$this->limit( array( 'queue_max_events' => 100 ) );
		$this->queue_items( Signal::TRACES, 50 );
		$this->queue_items( Signal::EVENTS, 30, 'ev' );
		$recorder = new \ZipLogger\WordPress\Recorder();
		for ( $i = 0; $i < 20; $i++ ) {
			$recorder->record( 'developer', 'error', 'log ' . $i );
		}
		$recorder->flush();
		$logs = $this->queue->counts_by_signal()[ Signal::LOGS ];
		$this->assertSame( 20, $logs, 'Errors are not pushed out by spans and events.' );
		$this->assertSame( 100, $this->queue_count() );
	}

	public function test_a_full_shared_queue_drops_new_items_of_any_signal_and_counts_them_by_signal() {
		$this->limit( array( 'queue_max_events' => 100 ) );
		$this->seed( 100 );
		$this->assertSame( 0, $this->queue_items( Signal::EVENTS, 5, 'ev' ) );
		$this->assertSame( 5, $this->meta->drop_counts_by_signal()[ Signal::EVENTS ]['overflow'] );
		$this->assertSame( 5, $this->meta->drop_counts()['overflow'], 'The total sums every signal.' );
	}
}
