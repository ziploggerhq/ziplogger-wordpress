<?php
/**
 * Server-side tracing: trace context parsing (untrusted input), parent-aware sampling, the request span,
 * outbound child spans, propagation allowlists, log correlation and the failure modes.
 */

use ZipLogger\WordPress\Event_Factory;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Schema;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Tracing\Context;
use ZipLogger\WordPress\Tracing\Outbound;
use ZipLogger\WordPress\Tracing\Route;
use ZipLogger\WordPress\Tracing\Tracer;

class Test_Tracing extends ZL_TestCase {

	const TRACE  = '4bf92f3577b34da6a3ce929d0e0e4736';
	const PARENT = '00f067aa0ba902b7';

	/**
	 * The request URI before a test changed it.
	 *
	 * @var string
	 */
	private $original_uri = '/';

	public function set_up() {
		parent::set_up();
		Tracer::reset();
		Tracer::$status_source = static function () {
			return 200;
		};
		$this->original_uri        = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/orders/48151623/?token=SECRETQUERY';
		unset( $_REQUEST['action'] );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
	}

	public function tear_down() {
		Tracer::reset();
		Tracer::$status_source  = null;
		Tracer::$queries_source = null;
		remove_all_filters( 'http_request_args' );
		remove_all_filters( 'ziplogger_limits' );
		Limits::reset();
		$_SERVER['REQUEST_URI'] = $this->original_uri;
		unset( $_REQUEST['action'] );
		parent::tear_down();
	}

	/**
	 * Tracing on (server key set), with tracing settings overridden.
	 */
	private function turn_on( array $tracing = array() ) {
		Settings::save(
			array(
				'enabled'    => true,
				'tracing'    => array_merge( array( 'enabled' => true, 'sample_rate' => 100 ), $tracing ),
			)
		);
		Settings::save_api_key( self::KEY );
		Settings::reset_cache();
		Limits::reset();
	}

	private function start( array $server = array() ) {
		Tracer::$server = array_merge( array( 'REQUEST_TIME_FLOAT' => microtime( true ) - 0.25 ), $server );
		return Tracer::maybe_start();
	}

	private function queued_spans() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT payload, sig, severity FROM ' . Schema::queue_table() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( $r ) {
				return array(
					'span'     => json_decode( $r['payload'], true ),
					'signal'   => $r['sig'],
					'severity' => (int) $r['severity'],
				);
			},
			$rows
		);
	}

	private static function attrs( array $span ) {
		$out = array();
		foreach ( $span['attributes'] as $a ) {
			$v = $a['value'];
			$out[ $a['key'] ] = reset( $v );
		}
		return $out;
	}

	// ---------------------------------------------------------------------------------------------
	// Untrusted trace context.
	// ---------------------------------------------------------------------------------------------

	public function test_a_valid_traceparent_is_parsed_and_the_sampled_flag_is_bit_zero() {
		$p = Context::parse_traceparent( '00-' . self::TRACE . '-' . self::PARENT . '-01' );
		$this->assertSame( array( 'trace_id' => self::TRACE, 'parent_id' => self::PARENT, 'sampled' => true ), $p );
		$this->assertFalse( Context::parse_traceparent( '00-' . self::TRACE . '-' . self::PARENT . '-00' )['sampled'] );
		$this->assertTrue( Context::parse_traceparent( '00-' . self::TRACE . '-' . self::PARENT . '-03' )['sampled'], 'Other flag bits do not hide the sampled bit.' );
		$this->assertFalse( Context::parse_traceparent( '00-' . self::TRACE . '-' . self::PARENT . '-02' )['sampled'] );
	}

	public function test_anything_that_is_not_exactly_a_traceparent_is_ignored() {
		$ok = '00-' . self::TRACE . '-' . self::PARENT . '-01';
		$bad = array(
			strtoupper( $ok ),
			'01-' . self::TRACE . '-' . self::PARENT . '-01',
			'ff-' . self::TRACE . '-' . self::PARENT . '-01',
			'00-' . substr( self::TRACE, 1 ) . '-' . self::PARENT . '-01',
			'00-' . self::TRACE . '0-' . self::PARENT . '-01',
			'00-' . str_repeat( '0', 32 ) . '-' . self::PARENT . '-01',
			'00-' . self::TRACE . '-' . str_repeat( '0', 16 ) . '-01',
			$ok . ' ',
			$ok . "\n",
			' ' . $ok,
			$ok . '-extra',
			'00_' . self::TRACE . '_' . self::PARENT . '_01',
			'00-' . self::TRACE . '-' . self::PARENT . '-zz',
			"00-" . self::TRACE . "-" . self::PARENT . "-0\x00",
			'',
			'garbage',
		);
		foreach ( $bad as $value ) {
			$this->assertNull( Context::parse_traceparent( $value ), 'Must be ignored: ' . json_encode( $value ) );
		}
		foreach ( array( null, 5, array( $ok ), true, new stdClass() ) as $value ) {
			$this->assertNull( Context::parse_traceparent( $value ) );
		}
	}

	public function test_format_round_trips() {
		$header = Context::format( self::TRACE, self::PARENT, true );
		$this->assertSame( '00-' . self::TRACE . '-' . self::PARENT . '-01', $header );
		$this->assertSame( '00-' . self::TRACE . '-' . self::PARENT . '-00', Context::format( self::TRACE, self::PARENT, false ) );
		$this->assertNotNull( Context::parse_traceparent( $header ) );
	}

	public function test_only_a_well_formed_session_id_is_taken_from_baggage() {
		$this->assertSame( 'sess_0123456789abcdef0123', Context::parse_baggage_session( 'session.id=sess_0123456789abcdef0123' ) );
		$this->assertSame( 'sess_0123456789abcdef0123', Context::parse_baggage_session( 'userId=alice, session.id=sess_0123456789abcdef0123 ,x=y' ) );
		$this->assertSame( 'sess_0123456789abcdef0123', Context::parse_baggage_session( 'session.id=sess_0123456789abcdef0123;property=1' ), 'Baggage properties are dropped.' );
		$this->assertSame( 'sess_0123456789abcdef0123', Context::parse_baggage_session( 'session.id=sess%5F0123456789abcdef0123' ) );
		foreach ( array( '', 'session.id=', 'Session.Id=sess_0123456789abcdef0123', 'session.id=short', 'session.id=' . str_repeat( 'a', 65 ), 'session.id=<script>alert(1)</script>', "session.id=sess_0123456789abcdef0123\r\nX-Injected: 1", 'session.id=a b c d e f g h', 'other=sess_0123456789abcdef0123', str_repeat( 'a=b,', 1000 ) ) as $value ) {
			$this->assertSame( '', Context::parse_baggage_session( $value ), 'Must be ignored: ' . substr( json_encode( $value ), 0, 60 ) );
		}
		foreach ( array( null, 5, array( 'session.id=sess_0123456789abcdef0123' ) ) as $value ) {
			$this->assertSame( '', Context::parse_baggage_session( $value ) );
		}
	}

	public function test_the_sampling_fraction_is_deterministic_and_spread_evenly() {
		$this->assertSame( Context::fraction( self::TRACE ), Context::fraction( self::TRACE ) );
		$hits = 0;
		for ( $i = 0; $i < 4000; $i++ ) {
			$f = Context::fraction( Context::random_id( 16 ) );
			$this->assertGreaterThanOrEqual( 0, $f );
			$this->assertLessThan( 1, $f );
			if ( $f < 0.25 ) {
				++$hits;
			}
		}
		$this->assertGreaterThan( 800, $hits );
		$this->assertLessThan( 1200, $hits );
	}

	public function test_random_ids_have_the_right_shape_and_are_never_all_zero() {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Context::random_id( 16 ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', Context::random_id( 8 ) );
		$this->assertNotSame( Context::random_id( 16 ), Context::random_id( 16 ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Route names.
	// ---------------------------------------------------------------------------------------------

	public function test_paths_are_normalized_so_they_group_and_carry_no_identifiers() {
		$cases = array(
			'/wp-json/wp/v2/posts/12'                          => '/wp-json/wp/v2/posts/{id}',
			'/wp-json/wp/v2/users/me'                          => '/wp-json/wp/v2/users/me',
			'/wp-json/wc/store/v1/cart/items/9f8a7b6c5d4e3f2a1b0c' => '/wp-json/wc/store/v1/cart/items/{id}',
			'/u/3f2504e0-4f89-41d3-9a0c-0305e82c3301/profile'  => '/u/{id}/profile',
			'/reset/aB3dE5fG7hJ9kL2mN4p'                       => '/reset/{id}',
			'/category/news/page/2'                            => '/category/news/page/{id}',
			'/'                                                => '/',
			'/plain/words/only'                                => '/plain/words/only',
		);
		foreach ( $cases as $in => $expected ) {
			$this->assertSame( $expected, Route::normalize( $in ), $in );
		}
		$this->assertSame( '/a/{long}', Route::normalize( '/a/' . str_repeat( 'x', 80 ) ) );
		$this->assertLessThanOrEqual( 200, strlen( Route::normalize( '/' . implode( '/', array_fill( 0, 100, 'segment' ) ) ) ) );
	}

	public function test_an_invented_ajax_action_never_becomes_a_span_name() {
		$_REQUEST['action'] = 'totally_random_' . wp_generate_password( 8, false );
		$this->assertSame( 'unregistered', Route::ajax_action() );
		add_action( 'wp_ajax_my_real_action', '__return_true' );
		$_REQUEST['action'] = 'my_real_action';
		$this->assertSame( 'my_real_action', Route::ajax_action() );
		$_REQUEST['action'] = array( 'x' );
		$this->assertSame( 'unregistered', Route::ajax_action() );
		remove_action( 'wp_ajax_my_real_action', '__return_true' );
	}

	// ---------------------------------------------------------------------------------------------
	// When tracing runs.
	// ---------------------------------------------------------------------------------------------

	public function test_nothing_is_created_while_the_module_is_off_or_unusable() {
		Settings::save( array( 'enabled' => true ) );
		Settings::save_api_key( self::KEY );
		$this->assertNull( $this->start() );
		$this->assertNull( Tracer::ids() );

		Tracer::reset();
		Settings::save( array( 'enabled' => true, 'tracing' => array( 'enabled' => true ) ) );
		delete_option( Settings::KEY_OPTION );
		Settings::reset_cache();
		$this->assertNull( $this->start(), 'No server key: not effective.' );

		Tracer::reset();
		$this->turn_on( array( 'server_spans' => false, 'outbound_spans' => false ) );
		$this->assertNull( $this->start(), 'Both span kinds off.' );
		$this->assertFalse( has_action( 'shutdown', array( Tracer::class, 'on_shutdown' ) ) );
	}

	public function test_the_plugins_own_visitor_context_endpoint_is_not_traced() {
		$this->turn_on();
		$_REQUEST['action'] = 'ziplogger_context';
		$this->assertNull( $this->start() );
	}

	// ---------------------------------------------------------------------------------------------
	// Sampling.
	// ---------------------------------------------------------------------------------------------

	public function test_a_request_without_context_starts_a_new_root_trace_at_the_local_rate() {
		$this->turn_on( array( 'sample_rate' => 100 ) );
		$t = $this->start();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $t->trace_id() );
		$this->assertTrue( $t->sampled() );
		$this->assertFalse( $t->remote() );
		Tracer::reset();
		$this->turn_on( array( 'sample_rate' => 0 ) );
		$this->assertFalse( $this->start()->sampled() );
	}

	public function test_the_local_decision_is_reproducible_from_the_trace_id() {
		$this->turn_on( array( 'sample_rate' => 30 ) );
		$counts = array( 'yes' => 0, 'no' => 0 );
		for ( $i = 0; $i < 600; $i++ ) {
			$t = new Tracer( array() );
			if ( $t->sampled() ) {
				++$counts['yes'];
				$this->assertLessThan( 0.3, Context::fraction( $t->trace_id() ) );
			} else {
				++$counts['no'];
				$this->assertGreaterThanOrEqual( 0.3, Context::fraction( $t->trace_id() ) );
			}
		}
		$this->assertGreaterThan( 120, $counts['yes'] );
		$this->assertLessThan( 240, $counts['yes'] );
	}

	public function test_a_valid_inbound_context_is_continued_and_its_decision_wins() {
		$this->turn_on( array( 'sample_rate' => 0 ) );
		$t = new Tracer( array( 'HTTP_TRACEPARENT' => '00-' . self::TRACE . '-' . self::PARENT . '-01', 'HTTP_BAGGAGE' => 'session.id=sess_0123456789abcdef0123' ) );
		$this->assertSame( self::TRACE, $t->trace_id() );
		$this->assertTrue( $t->remote() );
		$this->assertTrue( $t->sampled(), 'The caller sampled it, so we do, even at a local rate of 0.' );
		$this->assertSame( 'sess_0123456789abcdef0123', $t->session() );
		$this->assertNotSame( self::PARENT, $t->span_id(), 'We always get our own span id.' );

		$this->turn_on( array( 'sample_rate' => 100 ) );
		$off = new Tracer( array( 'HTTP_TRACEPARENT' => '00-' . self::TRACE . '-' . self::PARENT . '-00' ) );
		$this->assertFalse( $off->sampled(), 'The caller did not sample it: neither do we, even at a local rate of 100.' );
		$this->assertSame( self::TRACE, $off->trace_id() );
	}

	public function test_an_invalid_inbound_context_is_ignored_and_a_new_trace_starts() {
		$this->turn_on( array( 'sample_rate' => 100 ) );
		$t = new Tracer( array( 'HTTP_TRACEPARENT' => '00-' . str_repeat( '0', 32 ) . '-' . self::PARENT . '-01', 'HTTP_BAGGAGE' => 'session.id=sess_0123456789abcdef0123' ) );
		$this->assertFalse( $t->remote() );
		$this->assertNotSame( str_repeat( '0', 32 ), $t->trace_id() );
		$this->assertSame( '', $t->session(), 'Baggage from a request with no valid trace context is not used either.' );
	}

	public function test_a_stranger_cannot_make_every_request_a_recorded_one() {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) {
				$l['inbound_sampled_per_minute'] = 2;
				return $l;
			}
		);
		$this->turn_on( array( 'sample_rate' => 0 ) );
		$header = '00-' . self::TRACE . '-' . self::PARENT . '-01';
		$results = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = ( new Tracer( array( 'HTTP_TRACEPARENT' => $header ) ) )->sampled();
		}
		$this->assertSame( array( true, true, false, false, false ), $results, 'Beyond the budget the local rate (0) decides.' );
	}

	public function test_honouring_inbound_sampling_can_be_switched_off() {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) {
				$l['inbound_sampled_per_minute'] = 0;
				return $l;
			}
		);
		$this->turn_on( array( 'sample_rate' => 0 ) );
		$t = new Tracer( array( 'HTTP_TRACEPARENT' => '00-' . self::TRACE . '-' . self::PARENT . '-01' ) );
		$this->assertFalse( $t->sampled() );
		$this->assertSame( self::TRACE, $t->trace_id(), 'The trace id is still continued, for log correlation.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The request span.
	// ---------------------------------------------------------------------------------------------

	public function test_the_finished_request_span_is_queued_as_a_trace_with_only_sanitized_attributes() {
		$this->turn_on();
		$t = $this->start( array( 'HTTP_TRACEPARENT' => '00-' . self::TRACE . '-' . self::PARENT . '-01', 'HTTP_BAGGAGE' => 'session.id=sess_0123456789abcdef0123' ) );
		$t->finish();

		$rows = $this->queued_spans();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'traces', $rows[0]['signal'] );
		$span = $rows[0]['span'];
		$this->assertSame( self::TRACE, $span['traceId'] );
		$this->assertSame( $t->span_id(), $span['spanId'] );
		$this->assertSame( self::PARENT, $span['parentSpanId'] );
		$this->assertSame( 2, $span['kind'] );
		$this->assertSame( 'GET page:unresolved', $span['name'] );
		$this->assertGreaterThan( (int) $span['startTimeUnixNano'], (int) $span['endTimeUnixNano'] );

		$a = self::attrs( $span );
		$this->assertSame( 'GET', $a['http.request.method'] );
		$this->assertSame( '200', $a['http.response.status_code'] );
		$this->assertSame( 'frontend', $a['ziplogger.request.context'] );
		$this->assertSame( 'sess_0123456789abcdef0123', $a['session.id'] );
		$this->assertTrue( $a['ziplogger.trace.remote_parent'] );
		$this->assertArrayHasKey( 'wordpress.db.query_count', $a );
		$this->assertArrayHasKey( 'php.memory.peak_bytes', $a );
		$this->assertArrayNotHasKey( 'url.path', $a, 'Paths are not recorded unless asked for.' );
		$raw = wp_json_encode( $span );
		$this->assertStringNotContainsString( 'SECRETQUERY', $raw, 'Query strings are never read.' );
		$this->assertStringNotContainsString( '48151623', $raw );
		$this->assertSame( 0, $span['status']['code'] );
	}

	public function test_the_path_is_recorded_only_when_enabled_and_then_normalized_without_the_query() {
		$this->turn_on( array( 'record_path' => true ) );
		$this->start()->finish();
		$a = self::attrs( $this->queued_spans()[0]['span'] );
		$this->assertSame( '/orders/{id}/', $a['url.path'] );
		$this->assertStringNotContainsString( 'SECRETQUERY', wp_json_encode( $this->queued_spans()[0]['span'] ) );
	}

	public function test_an_unsampled_request_writes_nothing() {
		$this->turn_on( array( 'sample_rate' => 0 ) );
		$this->start()->finish();
		$this->assertSame( array(), $this->queued_spans() );
	}

	public function test_a_request_that_only_delivers_telemetry_is_not_traced() {
		$this->turn_on();
		$t = $this->start();
		\ZipLogger\WordPress\Plugin::instance()->run_delivery();
		$t->finish();
		$this->assertSame( array(), $this->queued_spans(), 'Tracing the request that sends spans would create a span to send, forever.' );

		Tracer::reset();
		$this->turn_on();
		$t = $this->start();
		\ZipLogger\WordPress\Plugin::instance()->run_watchdog();
		$t->finish();
		$this->assertSame( array(), $this->queued_spans() );
	}

	public function test_finishing_twice_writes_once() {
		$this->turn_on();
		$t = $this->start();
		$t->finish();
		$t->finish();
		Tracer::on_shutdown();
		$this->assertCount( 1, $this->queued_spans() );
	}

	public function test_a_server_error_status_marks_the_span_as_failed() {
		$this->turn_on();
		Tracer::$status_source = static function () {
			return 503;
		};
		$this->start()->finish();
		$span = $this->queued_spans()[0]['span'];
		$this->assertSame( 2, $span['status']['code'] );
		$this->assertSame( 'HTTP 503', $span['status']['message'] );
		$this->assertSame( 'error', array_search( $this->queued_spans()[0]['severity'], array( 'debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3, 'fatal' => 4 ), true ), 'A failed span has error priority in the queue.' );
	}

	public function test_a_client_error_is_not_a_server_failure() {
		$this->turn_on();
		Tracer::$status_source = static function () {
			return 404;
		};
		$this->start()->finish();
		$this->assertSame( 0, $this->queued_spans()[0]['span']['status']['code'] );
		$this->assertSame( 1, $this->queued_spans()[0]['severity'], 'Ordinary spans have the lowest queue priority.' );
	}

	public function test_an_uncaught_exception_becomes_a_span_event_with_the_message_redacted() {
		$this->turn_on();
		$t = $this->start();
		Tracer::note_error( 'App\\Mailer\\SendFailed', 'Could not send to jane@example.com with token=abc123secret', "#0 /var/www/html/wp-content/plugins/x/a.php(10): send()\n#1 {main}" );
		Tracer::note_error( 'Later\\Error', 'a second error does not replace the first' );
		$t->finish();
		$span = $this->queued_spans()[0]['span'];
		$this->assertSame( 2, $span['status']['code'] );
		$this->assertSame( 'App\\Mailer\\SendFailed', $span['status']['message'] );
		$this->assertCount( 1, $span['events'] );
		$event = $span['events'][0];
		$this->assertSame( 'exception', $event['name'] );
		$e = self::attrs( $event );
		$this->assertSame( 'App\\Mailer\\SendFailed', $e['exception.type'] );
		$this->assertStringNotContainsString( 'jane@example.com', $e['exception.message'] );
		$this->assertStringNotContainsString( 'abc123secret', $e['exception.message'] );
		$this->assertArrayHasKey( 'exception.stacktrace', $e );
		$this->assertStringNotContainsString( 'Later', wp_json_encode( $span ) );
	}

	public function test_database_timing_exists_only_with_savequeries_and_never_contains_sql() {
		$this->turn_on( array( 'db_timing' => true ) );
		Tracer::$queries_source = static function () {
			return array( array( "SELECT * FROM wp_users WHERE user_email = 'jane@example.com'", 0.5, 'trace' ), array( 'SELECT 1', 0.25, 'trace' ) );
		};
		$this->start()->finish();
		$span = $this->queued_spans()[0]['span'];
		$a    = self::attrs( $span );
		$this->assertSame( '750', (string) $a['wordpress.db.total_ms'] );
		$this->assertSame( '500', (string) $a['wordpress.db.slowest_ms'] );
		$this->assertSame( 'SAVEQUERIES', $a['wordpress.db.timing'] );
		$this->assertStringNotContainsString( 'SELECT', wp_json_encode( $span ) );
		$this->assertStringNotContainsString( 'jane@example.com', wp_json_encode( $span ) );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
		Tracer::reset();
		$this->turn_on( array( 'db_timing' => false ) );
		Tracer::$queries_source = static function () {
			return array( array( 'SELECT 1', 9.0, '' ) );
		};
		$this->start()->finish();
		$this->assertArrayNotHasKey( 'wordpress.db.total_ms', self::attrs( $this->queued_spans()[0]['span'] ) );
	}

	public function test_without_savequeries_there_is_no_timing_and_this_plugin_never_enables_it() {
		$this->turn_on( array( 'db_timing' => true ) );
		$this->start()->finish();
		$a = self::attrs( $this->queued_spans()[0]['span'] );
		$this->assertArrayNotHasKey( 'wordpress.db.total_ms', $a );
		$this->assertArrayHasKey( 'wordpress.db.query_count', $a, 'The count is always available.' );
		$sources = '';
		foreach ( glob( ZIPLOGGER_DIR . 'includes/{,*/,*/*/}*.php', GLOB_BRACE ) as $file ) {
			$sources .= (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$this->assertDoesNotMatchRegularExpression( "/define\\(\\s*['\"]SAVEQUERIES/", $sources );
	}

	public function test_the_query_count_can_be_switched_off() {
		$this->turn_on( array( 'db_query_count' => false ) );
		$this->start()->finish();
		$this->assertArrayNotHasKey( 'wordpress.db.query_count', self::attrs( $this->queued_spans()[0]['span'] ) );
	}

	public function test_with_server_spans_off_only_outbound_children_are_recorded_and_hang_off_the_callers_span() {
		$this->turn_on( array( 'server_spans' => false, 'outbound_spans' => true ) );
		$t = $this->start( array( 'HTTP_TRACEPARENT' => '00-' . self::TRACE . '-' . self::PARENT . '-01' ) );
		$args = apply_filters( 'http_request_args', array( 'method' => 'GET', 'blocking' => true ), 'https://api.partner.example/v1/x' );
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $args, 'https://api.partner.example/v1/x' );
		$t->finish();
		$rows = $this->queued_spans();
		$this->assertCount( 1, $rows, 'No request span.' );
		$this->assertSame( self::PARENT, $rows[0]['span']['parentSpanId'] );
		$this->assertSame( 3, $rows[0]['span']['kind'] );
	}

	public function test_the_number_of_child_spans_per_request_is_capped_and_the_loss_is_visible() {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) {
				$l['request_span_cap'] = 2;
				return $l;
			}
		);
		$this->turn_on();
		$t = $this->start();
		for ( $i = 0; $i < 5; $i++ ) {
			$url  = 'https://api.partner.example/v1/' . $i;
			$args = apply_filters( 'http_request_args', array( 'method' => 'GET', 'blocking' => true ), $url );
			do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $args, $url );
		}
		$t->finish();
		$rows = $this->queued_spans();
		$this->assertCount( 3, $rows, 'The request span plus two children.' );
		$this->assertSame( '3', (string) self::attrs( $rows[0]['span'] )['ziplogger.child_spans_dropped'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Outbound requests.
	// ---------------------------------------------------------------------------------------------

	private function outbound( $url, array $args = array() ) {
		return apply_filters( 'http_request_args', array_merge( array( 'method' => 'GET', 'blocking' => true, 'headers' => array() ), $args ), $url );
	}

	public function test_trace_headers_go_to_this_site_and_to_listed_hosts_only() {
		$this->turn_on( array( 'propagate_hosts' => "api.partner.example\n*.internal.example.org\nports.example:8443" ) );
		$t = $this->start();
		$header = static function ( $args ) {
			foreach ( $args['headers'] as $k => $v ) {
				if ( 'traceparent' === strtolower( $k ) ) {
					return $v;
				}
			}
			return null;
		};
		$site = wp_parse_url( home_url(), PHP_URL_HOST );

		$own = $header( $this->outbound( 'http://' . $site . '/wp-json/x' ) );
		$this->assertMatchesRegularExpression( '/^00-' . $t->trace_id() . '-[0-9a-f]{16}-01$/', (string) $own, 'This site.' );
		$this->assertNotNull( $header( $this->outbound( 'https://api.partner.example/v1' ) ), 'Listed host.' );
		$this->assertNotNull( $header( $this->outbound( 'https://a.b.internal.example.org/x' ) ), 'Wildcard.' );
		$this->assertNotNull( $header( $this->outbound( 'https://ports.example:8443/x' ) ), 'Host and port.' );

		foreach ( array( 'https://ports.example/x', 'https://ports.example:9000/x', 'https://internal.example.org/', 'https://evilinternal.example.org/', 'https://api.partner.example.evil.test/', 'https://api.stripe.com/v1/charges', 'https://hooks.slack.com/services/T000/B000/SECRET' ) as $url ) {
			$this->assertNull( $header( $this->outbound( $url ) ), 'No trace header to ' . $url );
		}
	}

	public function test_baggage_and_other_context_are_never_sent_to_anyone() {
		$this->turn_on( array( 'propagate_hosts' => 'api.partner.example' ) );
		$this->start( array( 'HTTP_TRACEPARENT' => '00-' . self::TRACE . '-' . self::PARENT . '-01', 'HTTP_BAGGAGE' => 'session.id=sess_0123456789abcdef0123' ) );
		$args = $this->outbound( 'https://api.partner.example/v1' );
		$names = array_map( 'strtolower', array_keys( $args['headers'] ) );
		$this->assertSame( array( 'traceparent' ), $names );
		$this->assertStringNotContainsString( 'sess_', wp_json_encode( $args['headers'] ) );
	}

	public function test_the_flags_tell_the_receiver_whether_the_trace_is_sampled() {
		$this->turn_on( array( 'sample_rate' => 0, 'propagate_hosts' => 'api.partner.example' ) );
		$this->start();
		$this->assertMatchesRegularExpression( '/-00$/', $this->outbound( 'https://api.partner.example/v1' )['headers']['traceparent'] );
	}

	public function test_a_header_the_caller_set_is_never_overwritten_and_raw_header_strings_are_left_alone() {
		$this->turn_on( array( 'propagate_hosts' => 'api.partner.example' ) );
		$this->start();
		$mine = $this->outbound( 'https://api.partner.example/v1', array( 'headers' => array( 'TraceParent' => 'theirs' ) ) );
		$this->assertSame( 'theirs', $mine['headers']['TraceParent'] );
		$this->assertCount( 1, $mine['headers'] );
		$raw = $this->outbound( 'https://api.partner.example/v1', array( 'headers' => "X-Raw: 1\r\n" ) );
		$this->assertSame( "X-Raw: 1\r\n", $raw['headers'] );
	}

	public function test_zipLoggers_own_traffic_and_non_http_urls_are_never_touched() {
		$this->turn_on( array( 'propagate_hosts' => 'app.ziplogger.ai' ) );
		$this->start();
		$own = $this->outbound( 'https://app.ziplogger.ai/ingest/v1/logs' );
		$this->assertArrayNotHasKey( 'traceparent', $own['headers'] );
		$this->assertArrayNotHasKey( Outbound::ARG, $own );
		$tr = $this->outbound( 'https://api.partner.example/x', array( 'ziplogger_transport' => true ) );
		$this->assertArrayNotHasKey( Outbound::ARG, $tr );
		$this->assertArrayNotHasKey( Outbound::ARG, $this->outbound( 'ftp://files.example/x' ) );
		$this->assertArrayNotHasKey( Outbound::ARG, $this->outbound( '/relative/only' ) );
	}

	public function test_a_suspended_tracer_traces_nothing() {
		$this->turn_on( array( 'propagate_hosts' => 'api.partner.example' ) );
		$t = $this->start();
		$t->suspend();
		$args = $this->outbound( 'https://api.partner.example/v1' );
		$this->assertArrayNotHasKey( 'traceparent', $args['headers'] );
		$t->resume();
		$this->assertArrayHasKey( 'traceparent', $this->outbound( 'https://api.partner.example/v1' )['headers'] );
	}

	private static function reply( $code ) {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_an_outbound_request_becomes_a_child_span_with_host_method_status_and_duration_only() {
		$this->turn_on();
		$t    = $this->start();
		$url  = 'https://hooks.example.com/services/T000/B000/SECRETWEBHOOKTOKEN?key=SECRETKEY';
		$args = $this->outbound( $url, array( 'method' => 'POST', 'body' => 'payload=SECRETBODY' ) );
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $args, $url );
		$t->finish();

		$rows = $this->queued_spans();
		$this->assertCount( 2, $rows );
		$child = $rows[1]['span'];
		$this->assertSame( 3, $child['kind'] );
		$this->assertSame( $t->trace_id(), $child['traceId'] );
		$this->assertSame( $t->span_id(), $child['parentSpanId'] );
		$this->assertSame( 'POST hooks.example.com', $child['name'] );
		$a = self::attrs( $child );
		$this->assertSame( 'hooks.example.com', $a['server.address'] );
		$this->assertSame( '200', $a['http.response.status_code'] );
		$this->assertArrayNotHasKey( 'url.path', $a );
		$raw = wp_json_encode( $child );
		foreach ( array( 'SECRETWEBHOOKTOKEN', 'SECRETKEY', 'SECRETBODY', 'services' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $raw, $leak );
		}
		$this->assertSame( 0, $child['status']['code'] );
	}

	public function test_the_outbound_path_is_recorded_only_when_enabled_and_never_with_its_query() {
		$this->turn_on( array( 'record_path' => true ) );
		$t   = $this->start();
		$url = 'https://api.partner.example/v2/customers/48151623/orders?token=SECRET';
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $this->outbound( $url ), $url );
		$t->finish();
		$a = self::attrs( $this->queued_spans()[1]['span'] );
		$this->assertSame( '/v2/customers/{id}/orders', $a['url.path'] );
	}

	public function test_failures_and_error_statuses_mark_the_child_span() {
		$this->turn_on();
		$t   = $this->start();
		$url = 'https://api.partner.example/v1';
		do_action( 'http_api_debug', new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds with 0 bytes received (203.0.113.9)' ), 'response', 'Requests', $this->outbound( $url ), $url );
		do_action( 'http_api_debug', self::reply( 502 ), 'response', 'Requests', $this->outbound( $url ), $url );
		do_action( 'http_api_debug', self::reply( 404 ), 'response', 'Requests', $this->outbound( $url ), $url );
		$t->finish();
		$rows = $this->queued_spans();
		$this->assertSame( 2, $rows[1]['span']['status']['code'] );
		$this->assertSame( 'http_request_failed', self::attrs( $rows[1]['span'] )['error.type'] );
		$this->assertStringNotContainsString( '203.0.113.9', wp_json_encode( $rows[1]['span'] ), 'The error text is never copied.' );
		$this->assertSame( 2, $rows[2]['span']['status']['code'] );
		$this->assertSame( 'HTTP 502', $rows[2]['span']['status']['message'] );
		$this->assertSame( 2, $rows[3]['span']['status']['code'], 'A 4xx is an error for a client span.' );
	}

	public function test_non_blocking_and_unstamped_requests_and_unsampled_traces_produce_no_child_spans() {
		$this->turn_on();
		$t   = $this->start();
		$url = 'https://api.partner.example/v1';
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $this->outbound( $url, array( 'blocking' => false ) ), $url );
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', array( 'method' => 'GET', 'blocking' => true ), $url );
		do_action( 'http_api_debug', self::reply( 200 ), 'other-context', 'Requests', $this->outbound( $url ), $url );
		$t->finish();
		$this->assertCount( 1, $this->queued_spans(), 'Only the request span.' );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
		Tracer::reset();
		$this->turn_on( array( 'sample_rate' => 0 ) );
		$t = $this->start();
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $this->outbound( $url ), $url );
		$t->finish();
		$this->assertCount( 0, $this->queued_spans() );
	}

	public function test_outbound_spans_can_be_switched_off_separately() {
		$this->turn_on( array( 'outbound_spans' => false ) );
		$t   = $this->start();
		$url = 'https://api.partner.example/v1';
		do_action( 'http_api_debug', self::reply( 200 ), 'response', 'Requests', $this->outbound( $url ), $url );
		$t->finish();
		$this->assertCount( 1, $this->queued_spans() );
	}

	// ---------------------------------------------------------------------------------------------
	// Log correlation, delivery format and cache safety.
	// ---------------------------------------------------------------------------------------------

	public function test_logs_written_during_a_traced_request_carry_its_trace_and_span_id() {
		$this->turn_on();
		$t = $this->start();
		$record = ( new Event_Factory() )->build( array( 'type' => 'developer', 'severity' => 'error', 'message' => 'checkout failed' ) );
		$this->assertSame( $t->trace_id(), $record['fields']['traceId'] );
		$this->assertSame( $t->span_id(), $record['fields']['spanId'] );
		$this->assertArrayHasKey( 'requestId', $record['fields'], 'The per-request id stays.' );

		Tracer::reset();
		$plain = ( new Event_Factory() )->build( array( 'type' => 'developer', 'severity' => 'error', 'message' => 'checkout failed' ) );
		$this->assertArrayNotHasKey( 'traceId', $plain['fields'] );
	}

	public function test_a_developer_context_field_cannot_impersonate_the_trace_id() {
		$this->turn_on();
		$t = $this->start();
		$record = ( new Event_Factory() )->build( array( 'type' => 'developer', 'severity' => 'error', 'message' => 'x', 'context' => array( 'traceId' => 'forged', 'spanId' => 'forged' ) ) );
		$this->assertSame( $t->trace_id(), $record['fields']['traceId'] );
		$this->assertSame( 'forged', $record['fields']['context_traceId'], 'Renamed, not dropped and not allowed to overwrite.' );
	}

	public function test_queued_spans_wrap_into_a_valid_otlp_request_with_the_site_as_the_service() {
		$this->turn_on( array( 'service_name' => 'my-shop' ) );
		$this->start()->finish();
		global $wpdb;
		$payloads = $wpdb->get_col( 'SELECT payload FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
		$body     = json_decode( \ZipLogger\WordPress\Otlp::wrap_spans( $payloads ), true );
		$this->assertIsArray( $body );
		$res = $body['resourceSpans'][0]['resource']['attributes'];
		$svc = array_values( array_filter( $res, static function ( $a ) {
			return 'service.name' === $a['key'];
		} ) );
		$this->assertSame( 'my-shop', $svc[0]['value']['stringValue'] );
		$this->assertCount( 1, $body['resourceSpans'][0]['scopeSpans'][0]['spans'] );
	}

	public function test_no_trace_identifier_is_ever_written_to_the_response_or_the_page() {
		$this->turn_on( array( 'browser' => true ) );
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ), 'tracing' => array( 'enabled' => true, 'sample_rate' => 100, 'browser' => true ) ) );
		Settings::save_api_key( self::KEY );
		Settings::save_key( 'browser', 'zk_browser_public_0123456789abcd' );
		Settings::reset_cache();
		$t = $this->start();
		ob_start();
		\ZipLogger\WordPress\Frontend::print_config();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( $t->trace_id(), $html );
		$this->assertStringNotContainsString( $t->span_id(), $html );
		$this->assertStringNotContainsString( 'traceparent', $html );
	}

	public function test_a_broken_setting_or_hook_can_never_break_the_request() {
		$this->turn_on();
		$t = $this->start();
		add_filter(
			'ziplogger_redact_patterns',
			static function () {
				throw new RuntimeException( 'broken filter' );
			}
		);
		$t->record_error( 'X', 'y' );
		$t->finish();
		$this->assertTrue( true, 'Neither call threw.' );
		Tracer::note_error( 'Z', 'ignored when there is no tracer' );
		Tracer::reset();
		Tracer::note_error( 'Z', 'ignored when there is no tracer' );
	}
}
