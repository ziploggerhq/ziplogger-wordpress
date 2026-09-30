<?php
/**
 * The record built for ZipLogger matches the ingestion contract.
 */

use ZipLogger\WordPress\Event_Factory;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Redactor;
use ZipLogger\WordPress\Settings;

class Test_Event_Factory extends ZL_TestCase {

	private function ef() {
		return new Event_Factory( new Redactor() );
	}

	/**
	 * The properties the service's log endpoint binds.
	 */
	private const CONTRACT_KEYS = array( 'timestamp', 'source', 'severity', 'message', 'release', 'commitSha', 'stackTrace', 'fields', 'tags' );

	public function test_record_matches_the_ingestion_contract() {
		Settings::save( array( 'source' => 'shop.example.com' ) );
		$record = $this->ef()->build(
			array(
				'type'     => 'php_error',
				'severity' => 'warn',
				'message'  => 'Example WordPress application error',
				'stack'    => "#0 wp-content/plugins/x/x.php:12 foo()",
			)
		);

		foreach ( array_keys( $record ) as $key ) {
			$this->assertContains( $key, self::CONTRACT_KEYS, "Unexpected top-level property: $key" );
		}
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s', 1790000000 ) . '.000Z', $record['timestamp'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,7})?Z$/', $record['timestamp'] );
		$this->assertSame( 'shop.example.com', $record['source'] );
		$this->assertSame( 'warn', $record['severity'] );
		$this->assertSame( 'Example WordPress application error', $record['message'] );
		$this->assertIsString( $record['stackTrace'] );

		$fields = $record['fields'];
		$this->assertSame( 'php_error', $fields['eventType'] );
		$this->assertSame( PHP_VERSION, $fields['phpVersion'] );
		$this->assertSame( ZIPLOGGER_VERSION, $fields['pluginVersion'] );
		$this->assertSame( $GLOBALS['wp_version'], $fields['wordpressVersion'] );
		$this->assertSame( strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ), $fields['siteHost'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $fields['requestId'] );
		$this->assertNotSame( '', $fields['environment'] );
		$this->assertSame( 'Example WordPress application error', $fields['messageTemplate'] );
		$this->assertSame( array( 'wordpress', $fields['environment'] ), $record['tags'] );
	}

	public function test_wire_json_types() {
		$encoded = $this->ef()->encode( $this->ef()->build( array( 'severity' => 'error', 'message' => 'm' ) ) );
		$json    = json_decode( $encoded['json'], false );
		$this->assertIsObject( $json->fields, 'fields must be a JSON object' );
		$this->assertIsArray( $json->tags );
		foreach ( $json->tags as $tag ) {
			$this->assertIsString( $tag );
		}
		$this->assertIsString( $json->message );
		$this->assertIsString( $json->timestamp );
	}

	public function test_request_id_is_shared_within_a_request() {
		$a = $this->ef()->build( array( 'message' => 'a' ) );
		$b = $this->ef()->build( array( 'message' => 'b' ) );
		$this->assertSame( $a['fields']['requestId'], $b['fields']['requestId'] );
	}

	public function test_environment_setting_overrides_detection() {
		Settings::save( array( 'environment' => 'staging' ) );
		$record = $this->ef()->build( array( 'message' => 'm' ) );
		$this->assertSame( 'staging', $record['fields']['environment'] );
		$this->assertSame( array( 'wordpress', 'staging' ), $record['tags'] );
	}

	/**
	 * @dataProvider severity_provider
	 */
	public function test_severity_is_normalized( $input, $expected ) {
		$this->assertSame( $expected, $this->ef()->build( array( 'severity' => $input, 'message' => 'm' ) )['severity'] );
	}

	public function severity_provider() {
		return array(
			array( 'WARNING', 'warn' ),
			array( 'warn', 'warn' ),
			array( 'critical', 'fatal' ),
			array( 'Emergency', 'fatal' ),
			array( 'notice', 'info' ),
			array( 'trace', 'debug' ),
			array( 'nonsense', 'info' ),
			array( 42, 'info' ),
		);
	}

	public function test_developer_context_cannot_overwrite_reserved_fields() {
		$record = $this->ef()->build(
			array(
				'type'    => 'developer',
				'message' => 'm',
				'context' => array(
					'environment'     => 'attacker',
					'eventType'       => 'evil',
					'requestId'       => 'forged',
					'processed_count' => 42,
				),
			)
		);
		$this->assertNotSame( 'attacker', $record['fields']['environment'] );
		$this->assertSame( 'developer', $record['fields']['eventType'] );
		$this->assertNotSame( 'forged', $record['fields']['requestId'] );
		$this->assertSame( 'attacker', $record['fields']['context_environment'] );
		$this->assertSame( 42, $record['fields']['processed_count'] );
	}

	public function test_context_is_redacted() {
		$record = $this->ef()->build( array( 'message' => 'm', 'context' => array( 'password' => 'x', 'note' => 'call jane@example.com' ) ) );
		$this->assertSame( '[redacted]', $record['fields']['password'] );
		$this->assertStringNotContainsString( 'jane@example.com', $record['fields']['note'] );
	}

	public function test_list_context_is_kept_under_one_field() {
		$record = $this->ef()->build( array( 'message' => 'm', 'context' => array( 'a', 'b' ) ) );
		$this->assertSame( array( 'a', 'b' ), $record['fields']['context'] );
	}

	public function test_message_is_redacted_and_never_empty() {
		$f = $this->ef();
		$this->assertSame( '(empty message)', $f->build( array( 'message' => '   ' ) )['message'] );
		$this->assertStringNotContainsString( 'hunter2', $f->build( array( 'message' => 'password=hunter2' ) )['message'] );
	}

	public function test_template_groups_variable_parts() {
		$f = $this->ef();
		$this->assertSame( 'Undefined variable {value} in x.php on line {n}', $f->template( "Undefined variable 'foo' in x.php on line 12" ) );
		$this->assertSame( 'Order {n} failed for {uuid}', $f->template( 'Order 83112 failed for 123e4567-e89b-12d3-a456-426614174000' ) );
		$this->assertSame( 'hash {hex}', $f->template( 'hash 0123456789abcdef0123' ) );
		$this->assertSame( $f->template( 'Timeout after 30 seconds' ), $f->template( 'Timeout after 45 seconds' ) );
	}

	public function test_explicit_template_is_used() {
		$record = $this->ef()->build( array( 'message' => 'Plugin activated: akismet', 'template' => 'Plugin activated: {plugin}' ) );
		$this->assertSame( 'Plugin activated: {plugin}', $record['fields']['messageTemplate'] );
	}

	public function test_release_and_commit_sha_are_optional_and_validated() {
		$f = $this->ef();
		$this->assertArrayNotHasKey( 'release', $f->build( array( 'message' => 'm' ) ) );
		$this->assertArrayNotHasKey( 'commitSha', $f->build( array( 'message' => 'm' ) ) );

		add_filter( 'ziplogger_release', static function () { return '2026.09.30+build.7'; } );
		add_filter( 'ziplogger_commit_sha', static function () { return 'ABCDEF1234567'; } );
		$r = $f->build( array( 'message' => 'm' ) );
		$this->assertSame( '2026.09.30+build.7', $r['release'] );
		$this->assertSame( 'abcdef1234567', $r['commitSha'] );

		remove_all_filters( 'ziplogger_release' );
		remove_all_filters( 'ziplogger_commit_sha' );
		add_filter( 'ziplogger_release', static function () { return "bad release\nX-Injected: 1"; } );
		add_filter( 'ziplogger_commit_sha', static function () { return 'not-hex!'; } );
		$r = $f->build( array( 'message' => 'm' ) );
		$this->assertArrayNotHasKey( 'release', $r );
		$this->assertArrayNotHasKey( 'commitSha', $r );
	}

	public function test_tags_filter_is_validated() {
		add_filter( 'ziplogger_tags', static function ( $t ) { $t[] = 'team:web'; $t[] = 'bad tag!'; $t[] = 42; return $t; } );
		$r = $this->ef()->build( array( 'message' => 'm' ) );
		$this->assertContains( 'team:web', $r['tags'] );
		$this->assertNotContains( 'bad tag!', $r['tags'] );
	}

	public function test_encode_shrinks_oversize_events_in_order() {
		$f = $this->ef();
		// A huge stack trace goes first.
		$record = $f->build( array( 'message' => 'm', 'stack' => str_repeat( "#0 wp-content/plugins/x/x.php:1 f()\n", 3000 ) ) );
		$record['stackTrace'] = str_repeat( 'x', 30000 );
		$enc = $f->encode( $record );
		$this->assertNotNull( $enc['json'] );
		$this->assertLessThanOrEqual( Limits::get( 'max_event_bytes' ), strlen( $enc['json'] ) );
		$this->assertArrayNotHasKey( 'stackTrace', json_decode( $enc['json'], true ) );

		// A huge field set is stripped to the reserved fields.
		$record = $f->build( array( 'message' => 'm' ) );
		for ( $i = 0; $i < 40; $i++ ) {
			$record['fields'][ 'big' . $i ] = str_repeat( 'y', 2000 );
		}
		$enc = $f->encode( $record );
		$this->assertNotNull( $enc['json'] );
		$decoded = json_decode( $enc['json'], true );
		$this->assertArrayHasKey( 'eventType', $decoded['fields'] );
		$this->assertArrayNotHasKey( 'big0', $decoded['fields'] );
	}

	public function test_encode_gives_up_only_when_nothing_can_fit() {
		add_filter( 'ziplogger_limits', static function ( $l ) { $l['max_event_bytes'] = 2048; return $l; } );
		Limits::reset();
		$f      = $this->ef();
		$record = $f->build( array( 'message' => 'm' ) );
		$record['source'] = str_repeat( 's', 3000 ); // Not reducible by the shrink steps.
		$enc = $f->encode( $record );
		$this->assertNull( $enc['json'] );
		$this->assertSame( 'oversize', $enc['reason'] );
	}

	public function test_encode_reports_unencodable_values() {
		$f      = $this->ef();
		$record = $f->build( array( 'message' => 'm' ) );
		$record['fields']['r'] = fopen( 'php://memory', 'r' );
		$enc = $f->encode( $record );
		$this->assertNull( $enc['json'] );
		$this->assertSame( 'unencodable', $enc['reason'] );
	}

	public function test_invalid_utf8_never_breaks_encoding() {
		$f      = $this->ef();
		$record = $f->build( array( 'message' => "bad \xff bytes", 'context' => array( "k\xff" => "v\xfe" ) ) );
		$enc    = $f->encode( $record );
		$this->assertNotNull( $enc['json'] );
		$this->assertNotNull( json_decode( $enc['json'], true ) );
	}
}
