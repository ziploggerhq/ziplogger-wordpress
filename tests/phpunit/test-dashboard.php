<?php
/**
 * The dashboard: the read client (a separate least-privilege key, used on the server only), the panels built
 * from ZipLogger's read interface, the admin-ajax endpoint behind them, and what is (never) rendered.
 */

use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Dashboard\Ajax;
use ZipLogger\WordPress\Dashboard\Panels;
use ZipLogger\WordPress\Dashboard\Remote;
use ZipLogger\WordPress\Settings;

class Test_Dashboard extends ZL_TestCase {

	const READ_KEY    = 'zk_read_only_key_0123456789012345';
	const BROWSER_KEY = 'zk_browser_public_key_012345678901';

	public function set_up() {
		parent::set_up();
		delete_option( 'ziplogger_read_key' );
		delete_option( 'ziplogger_browser_key' );
		$this->global_cache_reset();
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	private function global_cache_reset() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ziplogger\\_rc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ziplogger\\_rc\\_%'" ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
	}

	private function configure( $read = true ) {
		$this->enable();
		Settings::save( array( 'enabled' => true, 'source' => 'e2e-site' ) );
		Settings::save_api_key( self::KEY );
		if ( $read ) {
			$this->assertSame( '', Settings::save_key( 'read', self::READ_KEY ) );
		} else {
			delete_option( 'ziplogger_read_key' );
		}
		Settings::reset_cache();
	}

	/**
	 * The requests made to ZipLogger's read interface.
	 */
	private function reads() {
		return array_values( array_filter( $this->requests, static function ( $r ) {
			return false !== strpos( $r['url'], '/grafana/' );
		} ) );
	}

	private function answer( array $body, $status = 200 ) {
		$this->responses = array( self::response( $status, $body ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The read client.
	// ---------------------------------------------------------------------------------------------

	public function test_without_a_read_key_nothing_is_requested_and_the_reason_is_shown() {
		$this->configure( false );
		$this->assertStringContainsString( 'No read key', Remote::unavailable_reason() );
		$r = Remote::health();
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'unconfigured', $r['code'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_the_read_key_must_differ_from_the_other_credentials() {
		$this->configure( false );
		$this->assertNotSame( '', Settings::save_key( 'read', self::KEY ), 'Refused when it equals the server key.' );
		Settings::$constants = array( 'ZIPLOGGER_READ_KEY' => self::KEY );
		Settings::reset_cache();
		$this->assertNotSame( '', Remote::unavailable_reason(), 'And unusable if set some other way.' );
		$this->assertCount( 0, $this->requests );
	}

	public function test_the_request_goes_to_the_endpoint_with_only_the_read_key_and_safe_arguments() {
		$this->configure();
		Settings::save_key( 'browser', self::BROWSER_KEY );
		$this->answer( array( 'status' => 'ok', 'workspace' => 'acme' ) );
		$r = Remote::health();
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'acme', $r['data']['workspace'] );

		$req = $this->reads()[0];
		$this->assertSame( 'https://app.ziplogger.ai/grafana/health', $req['url'] );
		$this->assertSame( self::READ_KEY, $req['args']['headers']['X-Api-Key'] );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $req['args'] ), 'Never the server key.' );
		$this->assertStringNotContainsString( self::BROWSER_KEY, wp_json_encode( $req['args'] ), 'Never the browser key.' );
		$this->assertStringNotContainsString( self::READ_KEY, $req['url'], 'A key never travels in a URL.' );
		$this->assertSame( 0, $req['args']['redirection'], 'A redirect would carry the key to another host.' );
		$this->assertTrue( $req['args']['sslverify'] );
		$this->assertLessThanOrEqual( 6, $req['args']['timeout'] );
		$this->assertGreaterThan( 0, $req['args']['limit_response_size'] );
		$this->assertTrue( $req['args']['ziplogger_transport'], 'Marked as the plugin\'s own traffic.' );
		$this->assertSame( 'GET', $req['args']['method'] );
	}

	public function test_only_the_read_interface_can_be_queried_from_here() {
		$this->configure();
		foreach ( array( '/ingest/v1/logs', '/v1/traces', '/api/keys', '/grafana' , '/x/grafana/health' ) as $path ) {
			$r = Remote::get( $path );
			$this->assertFalse( $r['ok'], $path );
		}
		$this->assertCount( 0, $this->requests );
	}

	public function test_answers_are_cached_briefly_and_the_cache_belongs_to_the_key_and_the_query() {
		$this->configure();
		$this->answer( array( 'status' => 'success', 'data' => array( 'result' => array() ) ) );
		Remote::get( '/grafana/loki/api/v1/query_range', array( 'query' => '{a="b"}', 'limit' => 5 ) );
		$second = Remote::get( '/grafana/loki/api/v1/query_range', array( 'limit' => 5, 'query' => '{a="b"}' ) ); // Same query, other order.
		$this->assertCount( 1, $this->reads() );
		$this->assertTrue( $second['cached'] );

		Remote::get( '/grafana/loki/api/v1/query_range', array( 'query' => '{a="b"}', 'limit' => 5 ), true );
		$this->assertCount( 2, $this->reads(), 'Refresh bypasses the cache.' );

		Remote::get( '/grafana/loki/api/v1/query_range', array( 'query' => '{a="c"}', 'limit' => 5 ) );
		$this->assertCount( 3, $this->reads(), 'Another query is another entry.' );

		Settings::save_key( 'read', 'zk_another_read_key_0123456789012345' );
		Settings::reset_cache();
		Remote::get( '/grafana/loki/api/v1/query_range', array( 'query' => '{a="b"}', 'limit' => 5 ) );
		$this->assertCount( 4, $this->reads(), 'A different key never reads another key\'s cache.' );
	}

	public function test_failures_are_classified_and_never_echo_the_key() {
		$this->configure();
		$cases = array(
			401 => array( 'auth', array( 'error' => 'Invalid API key' ) ),
			403 => array( 'auth', array() ),
			404 => array( 'unsupported', array() ),
			429 => array( 'rate', array() ),
			500 => array( 'server', array() ),
			503 => array( 'server', array() ),
			302 => array( 'http', array() ),
			400 => array( 'query', array( 'error' => 'The query is empty.' ) ),
		);
		foreach ( $cases as $status => $case ) {
			$this->global_cache_reset();
			$this->responses = array( self::response( $status, $case[1] ) );
			$r               = Remote::get( '/grafana/health', array( 'n' => $status ) );
			$this->assertFalse( $r['ok'], (string) $status );
			$this->assertSame( $case[0], $r['code'], (string) $status );
			$this->assertNotSame( '', $r['error'] );
		}
		$this->global_cache_reset();
		$this->responses = array( self::response( 400, array( 'error' => 'bad key ' . self::READ_KEY . ' in query' ) ) );
		$this->assertStringNotContainsString( self::READ_KEY, Remote::get( '/grafana/health', array( 'x' => 1 ) )['error'], 'Even a server that repeats the key back cannot make it appear.' );
	}

	public function test_network_and_format_problems_are_reported_plainly() {
		$this->configure();
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 6001 milliseconds' ) );
		$this->assertSame( 'timeout', Remote::get( '/grafana/health', array( 'a' => 1 ) )['code'] );
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ) );
		$this->assertSame( 'tls', Remote::get( '/grafana/health', array( 'a' => 2 ) )['code'] );
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );
		$this->assertSame( 'network', Remote::get( '/grafana/health', array( 'a' => 3 ) )['code'] );
		$this->responses = array( self::response( 200, '<html>proxy error</html>' ) );
		$this->assertSame( 'format', Remote::get( '/grafana/health', array( 'a' => 4 ) )['code'] );
	}

	public function test_a_failure_is_remembered_for_a_moment_so_a_broken_service_is_not_hammered() {
		$this->configure();
		$this->responses = array( self::response( 500, array() ) );
		Remote::get( '/grafana/health', array( 'z' => 1 ) );
		Remote::get( '/grafana/health', array( 'z' => 1 ) );
		Remote::get( '/grafana/health', array( 'z' => 1 ) );
		$this->assertCount( 1, $this->reads() );
	}

	public function test_an_unsafe_endpoint_is_refused_before_any_request() {
		$this->configure();
		Settings::save( array( 'enabled' => true, 'endpoint' => 'http://insecure.example.org' ) );
		Settings::reset_cache();
		$this->assertNotSame( '', Remote::unavailable_reason() );
		Remote::health();
		$this->assertCount( 0, $this->requests );
	}

	// ---------------------------------------------------------------------------------------------
	// Parsing.
	// ---------------------------------------------------------------------------------------------

	public function test_loki_streams_are_flattened_newest_first_and_bad_entries_are_ignored() {
		$data = array(
			'data' => array(
				'result' => array(
					array( 'stream' => array(), 'values' => array( array( '1790000000000000000', 'older' ), array( 'x', 'bad timestamp' ), array( '1790000100000000000', 5 ) ) ),
					array( 'stream' => array(), 'values' => array( array( '1790000200000000000', 'newer' ), 'not a pair' ) ),
					'garbage',
				),
			),
		);
		$lines = Panels::parse_streams( $data );
		$this->assertSame( array( 'newer', 'older' ), array_column( $lines, 'line' ) );
		$this->assertSame( 1790000200, $lines[0]['time'] );
		$this->assertSame( array(), Panels::parse_streams( array() ) );
		$this->assertSame( array(), Panels::parse_streams( array( 'data' => array( 'result' => 'nope' ) ) ) );
	}

	public function test_matrix_series_are_summed_per_time_and_sorted() {
		$data = array(
			'data' => array(
				'result' => array(
					array( 'values' => array( array( 200, '5' ), array( 100, '2' ), array( 300, 'NaNish' ) ) ),
					array( 'values' => array( array( 100, '3' ), array( 200, '1' ) ) ),
				),
			),
		);
		$this->assertSame( array( array( 100, 5.0 ), array( 200, 6.0 ) ), Panels::parse_matrix( $data ) );
		$this->assertSame( array(), Panels::parse_matrix( array( 'data' => array( 'result' => array( array( 'nothing' => 1 ) ) ) ) ) );
	}

	public function test_logfmt_tails_are_parsed() {
		$f = Panels::logfmt( 'Web vital LCP: 2400 ms (good) eventType=web_vital metric=LCP value=2431 note="two words" quoted="say \"hi\""' );
		$this->assertSame( 'web_vital', $f['eventType'] );
		$this->assertSame( 'LCP', $f['metric'] );
		$this->assertSame( '2431', $f['value'] );
		$this->assertSame( 'two words', $f['note'] );
		$this->assertSame( 'say "hi"', $f['quoted'] );
	}

	public function test_percentile_uses_nearest_rank() {
		$this->assertSame( 3.0, Panels::percentile( array( 1, 2, 3, 4 ), 75 ), 'ceil(0.75 x 4) = the 3rd value.' );
		$this->assertSame( 4.0, Panels::percentile( array( 1, 2, 3, 4 ), 100 ) );
		$this->assertSame( 10.0, Panels::percentile( array( 10 ), 75 ) );
		$this->assertSame( 0.0, Panels::percentile( array(), 75 ) );
		$this->assertSame( 3.0, Panels::percentile( array( 5, 1, 3 ), 50 ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Panels.
	// ---------------------------------------------------------------------------------------------

	public function test_a_message_that_contains_key_value_text_is_not_cut_at_it() {
		$this->configure();
		$this->answer(
			array(
				'status' => 'success',
				'data'   => array(
					'resultType' => 'streams',
					'result'     => array(
						array(
							'stream' => array(),
							'values' => array(
								array( '1790000200000000000', 'Uncaught Error: failed for [email] token=[redacted] environment=production siteHost=shop.test eventType=js_error' ),
							),
						),
					),
				),
			)
		);
		$r = Panels::render( 'errors' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'failed for [email] token=[redacted]</td>', $r['html'], 'The redacted text belongs to the message.' );
		$this->assertStringNotContainsString( 'environment=production', $r['html'] );
	}

	public function test_the_recent_errors_panel_queries_this_sites_source_and_escapes_everything() {
		$this->configure();
		$this->answer(
			array(
				'status' => 'success',
				'data'   => array(
					'resultType' => 'streams',
					'result'     => array(
						array(
							'stream' => array(),
							'values' => array(
								array( '1790000200000000000', 'PHP Fatal error: <script>alert(1)</script> "quoted" ' . str_repeat( 'long ', 100 ) . ' file=/var/www/x.php line=3' ),
								array( '1790000100000000000', 'Second failure eventType=php_fatal severity=error' ),
							),
						),
					),
				),
			)
		);
		$r = Panels::render( 'errors' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringNotContainsString( '<script>', $r['html'] );
		$this->assertStringContainsString( '&lt;script&gt;', $r['html'] );
		$this->assertStringNotContainsString( 'file=/var/www', $r['html'], 'Only the message, not the structured tail.' );
		$this->assertStringContainsString( 'Second failure', $r['html'] );
		$this->assertStringNotContainsString( 'severity=error', $r['html'] );
		$this->assertMatchesRegularExpression( '/…/u', $r['html'], 'Long messages are cut.' );
		$this->assertStringContainsString( '<caption class="screen-reader-text">', $r['html'] );

		$url = $this->reads()[0]['url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( '{service="e2e-site", severity=~"error|fatal"}', $q['query'] );
		$this->assertSame( '8', $q['limit'] );
		$this->assertSame( DAY_IN_SECONDS, (int) $q['end'] - (int) $q['start'] );
		$this->assertGreaterThanOrEqual( time(), (int) $q['end'], 'The window reaches the present: the newest events are not hidden.' );
		$this->assertLessThanOrEqual( time() + 60, (int) $q['end'] );
		$this->assertStringContainsString( '/grafana/loki/api/v1/query_range', $url );
	}

	public function test_an_empty_answer_says_so_and_a_failed_one_says_why() {
		$this->configure();
		$this->answer( array( 'status' => 'success', 'data' => array( 'result' => array() ) ) );
		$r = Panels::render( 'browser_errors' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Nothing in the last 24 hours', $r['html'] );

		$this->global_cache_reset();
		$this->responses = array( self::response( 401, array() ) );
		$r               = Panels::render( 'errors' );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'read key', $r['error'] );
	}

	public function test_trend_panels_draw_an_accessible_chart_with_a_text_summary_and_the_numbers() {
		$this->configure();
		$values = array();
		foreach ( range( 0, 23 ) as $h ) {
			$values[] = array( 1790000000 + $h * 3600, (string) ( $h % 5 ) );
		}
		$this->answer( array( 'status' => 'success', 'data' => array( 'resultType' => 'matrix', 'result' => array( array( 'metric' => array(), 'values' => $values ) ) ) ) );
		$r = Panels::render( 'error_trend' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( '<svg', $r['html'] );
		$this->assertStringContainsString( 'role="img"', $r['html'] );
		$this->assertMatchesRegularExpression( '/aria-label="[^"]*in total, at most/', $r['html'] );
		$this->assertStringContainsString( '<details><summary>Show the numbers', $r['html'] );
		$this->assertSame( 24, substr_count( $r['html'], '<tr><td>' ), 'One row per hour.' );

		$url = $this->reads()[0]['url'];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'count_over_time({service="e2e-site", severity=~"error|fatal"}[1h])', $q['query'] );
	}

	public function test_web_vitals_show_the_75th_percentile_with_a_rating_in_words() {
		$this->configure();
		$lines = array();
		foreach ( array( 1000, 1200, 2000, 2400, 5000 ) as $i => $lcp ) {
			$lines[] = array( (string) ( 1790000000 + $i ) . '000000000', "Web vital LCP: $lcp ms eventType=web_vital metric=LCP value=$lcp rating=good" );
		}
		$lines[] = array( '1790000010000000000', 'Web vital CLS: 0.3 eventType=web_vital metric=CLS value=0.3' );
		$lines[] = array( '1790000011000000000', 'Web vital BOGUS eventType=web_vital metric=BOGUS value=1' );
		$lines[] = array( '1790000012000000000', 'Web vital LCP eventType=web_vital metric=LCP value=not-a-number' );
		$this->answer( array( 'status' => 'success', 'data' => array( 'result' => array( array( 'stream' => array(), 'values' => $lines ) ) ) ) );
		$r = Panels::render( 'vitals' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( '<th scope="row">LCP</th><td>2,400 ms</td><td>Good</td><td>5</td>', $r['html'], 'Nearest-rank p75 of the five valid measurements.' );
		$this->assertStringContainsString( '<th scope="row">CLS</th><td>0.300</td><td>Poor</td>', $r['html'] );
		$this->assertStringNotContainsString( 'BOGUS', $r['html'] );
		parse_str( (string) wp_parse_url( $this->reads()[0]['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( '{service="e2e-site", field_eventType="web_vital"}', $q['query'] );
	}

	public function test_tracing_stats_use_the_documented_metrics_for_the_tracing_service() {
		$this->configure();
		Settings::save( array( 'enabled' => true, 'source' => 'e2e-site', 'tracing' => array( 'enabled' => true, 'service_name' => 'my-shop' ) ) );
		Settings::save_api_key( self::KEY );
		Settings::reset_cache();
		$this->responses = array(
			function ( $args, $url ) {
				if ( false !== strpos( $url, 'ziplogger_request_count' ) ) {
					return self::response( 200, array( 'status' => 'success', 'data' => array( 'resultType' => 'matrix', 'result' => array( array( 'metric' => array(), 'values' => array( array( 1790000000, '40' ), array( 1790003600, '60' ) ) ) ) ) ) );
				}
				if ( false !== strpos( $url, 'ziplogger_request_error_count' ) ) {
					return self::response( 200, array( 'status' => 'success', 'data' => array( 'result' => array( array( 'values' => array( array( 1790000000, '3' ), array( 1790003600, '2' ) ) ) ) ) ) );
				}
				return self::response( 200, array( 'status' => 'success', 'data' => array( 'result' => array( array( 'values' => array( array( 1790000000, '210.5' ), array( 1790003600, '380.4' ) ) ) ) ) ) );
			},
		);
		$r = Panels::render( 'tracing_stats' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( '<th scope="row">Sampled requests</th><td>100</td>', $r['html'] );
		$this->assertStringContainsString( '<th scope="row">With an error status</th><td>5</td>', $r['html'] );
		$this->assertStringContainsString( '380 ms', $r['html'] );
		$this->assertStringContainsString( 'not every request', $r['html'] );
		$this->assertCount( 3, $this->reads() );
		foreach ( $this->reads() as $req ) {
			$this->assertStringContainsString( rawurlencode( '{service="my-shop"}' ), $req['url'] );
			$this->assertStringContainsString( '/grafana/prometheus/api/v1/query_range', $req['url'] );
		}
	}

	public function test_traces_link_into_the_application_only_for_well_formed_ids() {
		$this->configure();
		$good = str_repeat( 'a1', 16 );
		$this->answer(
			array(
				'traces' => array(
					array( 'traceID' => $good, 'rootTraceName' => 'GET /wp-json/x <b>bold</b>', 'durationMs' => 1234.5 ),
					array( 'traceID' => 'not-a-trace-id"><script>', 'rootTraceName' => 'evil' ),
					array( 'rootTraceName' => 'no id' ),
				),
			)
		);
		$r = Panels::render( 'traces' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'href="https://app.ziplogger.ai/traces/' . $good . '"', $r['html'] );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $r['html'] );
		$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt;', $r['html'] );
		$this->assertStringNotContainsString( 'evil', $r['html'] );
		$this->assertStringNotContainsString( '<script>', $r['html'] );
		$this->assertStringContainsString( '1,235 ms', $r['html'] );
	}

	public function test_an_unknown_panel_and_an_unconfigured_dashboard_are_handled() {
		$this->configure();
		$this->assertFalse( Panels::render( 'nope' )['ok'] );
		$this->assertFalse( Panels::render( '../../etc' )['ok'] );
		$this->configure( false );
		$r = Panels::render( 'errors' );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'No read key', $r['error'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_slot_carries_a_nonce_and_never_a_credential() {
		$this->configure();
		ob_start();
		Panels::slot( 'errors', 'Recent server errors' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-ziplogger-panel="errors"', $html );
		$this->assertMatchesRegularExpression( '/data-nonce="[0-9a-f]{10}"/', $html );
		$this->assertStringContainsString( 'admin-ajax.php', $html );
		$this->assertStringNotContainsString( self::READ_KEY, $html );
		$this->assertStringNotContainsString( 'zk_', $html );

		$this->configure( false );
		ob_start();
		Panels::slot( 'errors', 'Recent server errors' );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'No read key', $html );
		$this->assertStringNotContainsString( 'data-nonce', $html, 'Nothing to fetch, nothing to enhance.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The admin-ajax endpoint.
	// ---------------------------------------------------------------------------------------------

	private function ajax( array $post, $role = 'administrator', $method = 'POST', $nonce = true ) {
		if ( $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		} else {
			wp_set_current_user( 0 );
		}
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		if ( $nonce ) {
			$_POST['nonce'] = wp_create_nonce( 'ziplogger_panel' );
		}
		$_REQUEST = $_POST;
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		$died = $this->catch_die( array( Ajax::class, 'handle' ) );
		$out  = ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		return array( json_decode( $out, true ), $died, $out );
	}

	public function test_only_administrators_with_a_nonce_can_load_a_panel() {
		$this->configure();
		$this->answer( array( 'status' => 'success', 'data' => array( 'result' => array() ) ) );
		foreach ( array( 'subscriber', 'editor', 'author', null ) as $role ) {
			list( $json ) = $this->ajax( array( 'panel' => 'errors' ), $role );
			$this->assertFalse( $json['success'], (string) $role );
		}
		list( , $died ) = $this->ajax( array( 'panel' => 'errors' ), 'administrator', 'POST', false );
		$this->assertInstanceOf( WPDieException::class, $died, 'No nonce, no answer.' );
		$this->assertCount( 0, $this->reads(), 'Nothing was requested from ZipLogger for any of them.' );

		list( $json ) = $this->ajax( array( 'panel' => 'errors' ) );
		$this->assertTrue( $json['success'] );
		$this->assertStringContainsString( 'Nothing in the last 24 hours', $json['data']['html'] );
	}

	public function test_only_post_is_accepted_and_only_a_known_panel_is_built() {
		$this->configure();
		list( $json ) = $this->ajax( array( 'panel' => 'errors' ), 'administrator', 'GET' );
		$this->assertFalse( $json['success'] );
		list( $json ) = $this->ajax( array( 'panel' => 'drop_tables' ) );
		$this->assertFalse( $json['success'] );
		list( $json ) = $this->ajax( array() );
		$this->assertFalse( $json['success'] );
		$this->assertCount( 0, $this->reads() );
	}

	public function test_the_response_never_contains_a_credential_even_when_the_service_echoes_it() {
		$this->configure();
		$this->answer( array( 'status' => 'success', 'data' => array( 'result' => array( array( 'values' => array( array( '1790000000000000000', 'x-api-key: ' . self::READ_KEY ) ) ) ) ) ) );
		list( , , $out ) = $this->ajax( array( 'panel' => 'errors' ) );
		$this->assertStringContainsString( 'x-api-key', strtolower( $out ), 'The line itself is data and is shown (escaped)...' );
		// ...but the plugin\'s redaction of stored/queued data is unrelated; what matters is that the key the
		// PLUGIN holds is never added to any response of its own making:
		$this->answer( array( 'status' => 'success', 'data' => array( 'result' => array() ) ) );
		$this->global_cache_reset();
		list( , , $out2 ) = $this->ajax( array( 'panel' => 'error_trend' ) );
		$this->assertStringNotContainsString( self::READ_KEY, $out2 );
	}

	public function test_there_is_no_public_variant_of_the_panel_endpoint() {
		( new Settings_Page() )->register(); // The screen registers its hooks only inside wp-admin.
		$this->assertNotFalse( has_action( 'wp_ajax_' . Ajax::ACTION ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_' . Ajax::ACTION ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The screens.
	// ---------------------------------------------------------------------------------------------

	private function render_tab( $tab ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab'] = $tab;
		ob_start();
		( new Settings_Page() )->render();
		return ob_get_clean();
	}

	public function test_no_screen_ever_prints_the_read_key_and_the_live_sections_are_on_the_right_tabs() {
		$this->configure();
		Settings::save_key( 'browser', self::BROWSER_KEY );
		$expect = array(
			'overview'   => array( 'errors', 'error_trend' ),
			'logs'       => array( 'errors', 'error_trend' ),
			'browser'    => array( 'browser_errors', 'browser_failed', 'vitals' ),
			'tracing'    => array( 'tracing_stats', 'traces' ),
			'connection' => array(),
			'analytics'  => array(),
			'replay'     => array(),
			'woocommerce' => array(),
			'privacy'    => array(),
			'diagnostics' => array(),
		);
		foreach ( $expect as $tab => $panels ) {
			$html = $this->render_tab( $tab );
			$this->assertStringNotContainsString( self::READ_KEY, $html, "$tab must not print the read key" );
			$this->assertStringNotContainsString( self::KEY, $html, "$tab must not print the server key" );
			$this->assertStringNotContainsString( self::BROWSER_KEY, $html, "$tab must not print the browser key" );
			preg_match_all( '/data-ziplogger-panel="([a-z_]+)"/', $html, $m );
			$this->assertSame( $panels, $m[1], "panels on the $tab tab" );
		}
	}

	public function test_analytics_replay_and_woocommerce_show_links_and_local_facts_not_invented_numbers() {
		$this->configure();
		$analytics = $this->render_tab( 'analytics' );
		$this->assertStringContainsString( 'https://app.ziplogger.ai/events', $analytics );
		$this->assertStringContainsString( 'no product-analytics query', $analytics );
		$replay = $this->render_tab( 'replay' );
		$this->assertStringContainsString( 'https://app.ziplogger.ai/replays', $replay );
		$woo = $this->render_tab( 'woocommerce' );
		$this->assertStringContainsString( 'https://app.ziplogger.ai/events/name/order_created', $woo );
		$this->assertStringContainsString( 'No order event has been queued yet.', $woo );
		$this->assertStringContainsString( 'Orders created', $woo );
	}

	public function test_order_event_counters_are_local_and_count_what_was_queued() {
		$this->configure();
		\ZipLogger\WordPress\Settings::save( array( 'enabled' => true, 'woocommerce' => array( 'enabled' => true ) ) );
		\ZipLogger\WordPress\Settings::save_api_key( self::KEY );
		\ZipLogger\WordPress\Settings::reset_cache();
		\ZipLogger\WordPress\Server_Events::emit( 'order_created', array( 'a' => 1 ), array( 'anonymousId' => 'anon_1' ), 'k1', true );
		\ZipLogger\WordPress\Server_Events::emit( 'order_created', array( 'a' => 1 ), array( 'anonymousId' => 'anon_2' ), 'k2', true );
		\ZipLogger\WordPress\Server_Events::emit( 'payment_completed', array(), array( 'anonymousId' => 'anon_2' ), 'k3', true );
		$meta = new \ZipLogger\WordPress\Meta_Store();
		$this->assertSame( 2, $meta->get_num( 'ev:order_created' ) );
		$this->assertSame( 1, $meta->get_num( 'ev:payment_completed' ) );
		$this->assertGreaterThan( 0, $meta->get_num( 'ev_last' ) );
	}

	public function test_testing_the_read_key_reports_the_workspace_and_remembers_the_result() {
		$this->configure();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		$this->answer( array( 'status' => 'ok', 'workspace' => 'acme-corp' ) );
		$_POST    = array( '_wpnonce' => wp_create_nonce( 'ziplogger_test_read' ), 'tab' => 'connection' );
		$_REQUEST = $_POST;
		try {
			( new Settings_Page() )->handle_test_read();
		} catch ( ZL_Redirect_Stop $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		$notices = get_transient( 'ziplogger_notices_' . get_current_user_id() );
		$this->assertSame( 'success', $notices[0][0] );
		$this->assertStringContainsString( 'acme-corp', $notices[0][1] );
		$this->assertStringNotContainsString( self::READ_KEY, wp_json_encode( $notices ) );
		$last = ( new \ZipLogger\WordPress\Meta_Store() )->get_json( 'read_test' );
		$this->assertTrue( $last['ok'] );
		$this->assertSame( 'acme-corp', $last['workspace'] );
	}

	public function stop_redirect( $location ) {
		throw new ZL_Redirect_Stop( $location );
	}
}

class ZL_Redirect_Stop extends Exception {}
