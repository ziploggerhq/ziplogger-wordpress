<?php
/**
 * Redaction and sanitization.
 */

use ZipLogger\WordPress\Redactor;

class Test_Redactor extends ZL_TestCase {

	private function redactor( array $opts = array() ) {
		return new Redactor( $opts );
	}

	public function test_sensitive_field_names_are_masked_at_any_depth() {
		$out = $this->redactor()->value(
			array(
				'ok'      => 'fine',
				'password' => 'hunter2',
				'nested'  => array(
					'API-Key' => 'abc',
					'deep'    => array(
						'Authorization' => 'Bearer xyz',
						'cookie'        => 'a=b',
						'safe'          => 'value',
					),
				),
				'user_email' => 'a@b.co',
				'className'  => 'Foo',
				'sessionId'  => 'S123',
				'clientIP'   => '203.0.113.9',
			)
		);
		$this->assertSame( 'fine', $out['ok'] );
		$this->assertSame( '[redacted]', $out['password'] );
		$this->assertSame( '[redacted]', $out['nested']['API-Key'] );
		$this->assertSame( '[redacted]', $out['nested']['deep']['Authorization'] );
		$this->assertSame( '[redacted]', $out['nested']['deep']['cookie'] );
		$this->assertSame( 'value', $out['nested']['deep']['safe'] );
		$this->assertSame( '[redacted]', $out['user_email'] );
		$this->assertSame( 'Foo', $out['className'], 'A substring match must not hit "classname".' );
		$this->assertSame( '[redacted]', $out['sessionId'] );
		$this->assertSame( '[redacted]', $out['clientIP'] );
	}

	/**
	 * @dataProvider sensitive_key_provider
	 */
	public function test_key_classification( $key, $expected ) {
		$this->assertSame( $expected, $this->redactor()->is_sensitive_key( $key ), $key );
	}

	public function sensitive_key_provider() {
		return array(
			array( 'password', true ),
			array( 'user_pass', true ),
			array( 'pwd', true ),
			array( 'X-Api-Key', true ),
			array( 'apiKey', true ),
			array( 'access_token', true ),
			array( 'Set-Cookie', true ),
			array( 'creditCardNumber', true ),
			array( 'user_login', true ),
			array( 'remote_addr', true ),
			array( 'ip', true ),
			array( 'csrf_token', true ),
			array( 'processed_count', false ),
			array( 'className', false ),
			array( 'description', false ),
			array( 'shipping', false ),
			array( 'zip', false ),
			array( 'monkey', false ),
			array( 'phpVersion', false ),
			array( 'siteHost', false ),
			array( 12, false ),
		);
	}

	/**
	 * @dataProvider secret_provider
	 */
	public function test_secrets_are_removed_from_free_text( $input, $must_not_contain ) {
		$out = $this->redactor()->text( $input );
		foreach ( (array) $must_not_contain as $needle ) {
			$this->assertStringNotContainsString( $needle, $out, 'Input: ' . $input );
		}
	}

	public function secret_provider() {
		return array(
			'password pair'        => array( 'Login failed password=hunter2 on host', 'hunter2' ),
			'json password'        => array( '{"password":"hunter2","x":1}', 'hunter2' ),
			'php array arrow'      => array( "'api_key' => 'abcd1234efgh5678'", 'abcd1234efgh5678' ),
			'bearer header'        => array( 'Authorization: Bearer abcdefgh12345678', 'abcdefgh12345678' ),
			'bearer inline'        => array( 'sent Bearer abcdefgh12345678 to api', 'abcdefgh12345678' ),
			'basic auth'           => array( 'Basic dXNlcjpwYXNzd29yZDEyMw==', 'dXNlcjpwYXNzd29yZDEyMw' ),
			'cookie header'        => array( 'Cookie: wordpress_logged_in_abc=admin%7C1%7Cxyz; foo=bar', 'admin%7C1%7Cxyz' ),
			'set-cookie header'    => array( 'Set-Cookie: session=abcdef123456; Path=/', 'abcdef123456' ),
			'wp cookie inline'     => array( 'saw wordpress_sec_1a2b3c=secretvalue99 in log', 'secretvalue99' ),
			'jwt'                  => array( 'token ' . 'eyJ' . 'hbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PK', 'eyJzdWIiOiIxMjM0NTY3ODkwIn0' ),
			'ziplogger key'        => array( 'using zk_AbCdEf0123456789abcdefGH here', 'zk_AbCdEf0123456789abcdefGH' ),
			'stripe live key'      => array( 'key ' . 'sk_' . 'live_abcdefghijklmnop1234', 'sk_' . 'live_abcdefghijklmnop1234' ),
			'aws access key'       => array( 'AKIA' . 'IOSFODNN7EXAMPLE was used', 'AKIA' . 'IOSFODNN7EXAMPLE' ),
			'github token'         => array( 'gh' . 'p_abcdefghijklmnopqrstuvwxyz0123456789', 'gh' . 'p_abcdefghijklmnopqrstuvwxyz0123456789' ),
			'private key'          => array( "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIVATE KEY-----", 'MIIEpAIBAAKCAQEA' ),
			'url credentials'      => array( 'GET https://user:pa55w0rd@example.com/path?token=abc#frag failed', array( 'pa55w0rd', 'token=abc', '#frag' ) ),
			'dsn'                  => array( 'connect mysql://root:s3cretpw@db.internal:3306/wp', 's3cretpw' ),
			'relative query'       => array( 'POST /wp-admin/admin-ajax.php?action=save&nonce=abc123def', array( 'nonce=abc123def', 'action=save' ) ),
			'email'                => array( 'Contact jane.doe+tag@example.co.uk now', 'jane.doe' ),
			'ipv4'                 => array( 'from 192.168.1.10 and 203.0.113.7:8080', array( '192.168.1.10', '203.0.113.7' ) ),
			'ipv6 full'            => array( 'client 2001:0db8:85a3:0000:0000:8a2e:0370:7334 seen', '8a2e:0370' ),
			'ipv6 short'           => array( 'client 2001:db8::ff00:42:8329 seen', 'ff00:42' ),
			'card'                 => array( 'card 4111 1111 1111 1111 declined', '4111 1111 1111 1111' ),
			'sql literal'          => array( "INSERT INTO wp_x (a) VALUES ('secret-value-42')", 'secret-value-42' ),
			'sql where'            => array( "SELECT * FROM t WHERE name = 'Alice Smith'", 'Alice Smith' ),
		);
	}

	public function test_url_keeps_scheme_host_and_path_only() {
		$out = $this->redactor()->text( 'GET https://user:pa55@Example.com:8443/a/b?x=1#f end' );
		$this->assertStringContainsString( 'https://example.com:8443/a/b', $out );
		$this->assertStringNotContainsString( '?x=1', $out );
	}

	public function test_harmless_text_survives() {
		$text = 'Undefined variable $foo at 12:30:45, WordPress 6.4.1, Class::method, Ace::add, count=5 [ok]';
		$this->assertSame( $text, $this->redactor()->text( $text ) );
	}

	public function test_luhn_failing_numbers_are_not_treated_as_cards() {
		$this->assertStringContainsString( '1234 5678 9012 3456', $this->redactor()->text( 'order 1234 5678 9012 3456 placed' ) );
	}

	public function test_absolute_paths_are_rewritten() {
		$r = $this->redactor();

		$core = $r->text( 'in ' . ABSPATH . 'wp-includes/load.php on line 5' );
		$this->assertStringContainsString( 'in wp-includes/load.php on line 5', $core );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $core );

		$plugin = $r->text( WP_PLUGIN_DIR . '/foo/bar.php:12' );
		$this->assertSame( 'wp-content/plugins/foo/bar.php:12', $plugin );

		$unix = $r->text( 'in /var/www/vhosts/example.com/httpdocs/wp-content/plugins/x/y.php:7' );
		$this->assertStringNotContainsString( '/var/www', $unix );
		$this->assertStringNotContainsString( 'vhosts', $unix );
		$this->assertStringContainsString( 'y.php:7', $unix );

		$home = $r->text( 'in /home/alice/x.php' );
		$this->assertStringNotContainsString( 'alice', $home );
		$this->assertStringContainsString( 'x.php', $home );

		$win = $r->text( 'in C:\\Users\\bob\\site\\wp-content\\themes\\t\\f.php on line 3' );
		$this->assertStringNotContainsString( 'bob', $win );
		$this->assertStringContainsString( 'f.php', $win );
	}

	public function test_urls_are_not_mistaken_for_paths() {
		$out = $this->redactor()->text( 'GET https://example.com/var/www/thing.php' );
		$this->assertSame( 'GET https://example.com/var/www/thing.php', $out );
	}

	public function test_trace_arguments_are_stripped() {
		$trace = "#0 /var/www/html/wp-content/plugins/x/x.php(12): Foo->bar(Object(WP_Post), 'secret-arg', Array)\n#1 /var/www/html/index.php(3): baz('another-secret')\n#2 {main}";
		$out   = $this->redactor()->trace( $trace );
		$this->assertStringNotContainsString( 'secret-arg', $out );
		$this->assertStringNotContainsString( 'another-secret', $out );
		$this->assertStringContainsString( 'Foo->bar()', $out );
		$this->assertStringContainsString( 'baz()', $out );
		$this->assertStringContainsString( '{main}', $out );
		$this->assertMatchesRegularExpression( '/x\.php:12 Foo->bar\(\)/', $out );
	}

	public function test_depth_items_and_string_limits() {
		$r    = $this->redactor( array( 'max_depth' => 3, 'max_items' => 4, 'max_string' => 50 ) );
		$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => 'x' ) ) ) );
		$out  = $r->value( $deep );
		$this->assertSame( '[truncated: too deep]', $out['a']['b']['c'] );

		$wide = $r->value( array_combine( range( 'a', 'j' ), range( 1, 10 ) ) );
		$this->assertCount( 5, $wide ); // Four items plus the truncation marker.
		$this->assertArrayHasKey( '_truncated', $wide );

		$long = $r->value( str_repeat( 'é', 500 ) );
		$this->assertLessThanOrEqual( 50, strlen( $long ) );
		$this->assertSame( 1, preg_match( '//u', $long ), 'Truncation must not split a UTF-8 sequence.' );
	}

	public function test_invalid_utf8_and_control_characters() {
		$r   = $this->redactor();
		$out = $r->text( "bad \xff\xfe bytes\x00 and\x07 bell" );
		$this->assertSame( 1, preg_match( '//u', $out ) );
		$this->assertStringNotContainsString( "\x00", $out );
		$this->assertStringNotContainsString( "\x07", $out );
		$this->assertNotFalse( json_encode( $out ) );
	}

	public function test_objects_are_never_inspected_or_stringified() {
		$evil = new class() {
			public $secret = 'do-not-leak';
			public function __toString() {
				throw new RuntimeException( 'must not be called' );
			}
		};
		$r   = $this->redactor();
		$out = $r->value( array( 'o' => $evil, 'std' => new stdClass(), 'res' => fopen( 'php://memory', 'r' ), 'nan' => NAN, 'inf' => INF ) );
		$this->assertStringStartsWith( '[object ', $out['o'] );
		$this->assertStringNotContainsString( 'do-not-leak', $out['o'] );
		$this->assertSame( '[object stdClass]', $out['std'] );
		$this->assertSame( '[resource]', $out['res'] );
		$this->assertNull( $out['nan'] );
		$this->assertNull( $out['inf'] );
	}

	public function test_wp_error_and_throwable_are_summarised_and_redacted() {
		$r   = $this->redactor();
		$err = $r->value( new WP_Error( 'boom', 'failed for token=abc123xyz' ) );
		$this->assertSame( 'boom', $err['code'] );
		$this->assertStringNotContainsString( 'abc123xyz', $err['message'] );

		$ex = $r->value( new RuntimeException( 'password=hunter2' ) );
		$this->assertSame( 'RuntimeException', $ex['class'] );
		$this->assertStringNotContainsString( 'hunter2', $ex['message'] );
	}

	public function test_field_names_are_index_safe() {
		$r = $this->redactor();
		$this->assertSame( 'a_b', $r->field_name( 'a.b' ) );
		$this->assertSame( 64, strlen( $r->field_name( str_repeat( 'x', 200 ) ) ) );
		$this->assertSame( '_', $r->field_name( '' ) );
		$out = $r->value( array( 'a.b' => 1, 'c d' => 2 ) );
		$this->assertArrayHasKey( 'a_b', $out );
		$this->assertArrayHasKey( 'c_d', $out );
	}

	public function test_lists_stay_lists() {
		$out = $this->redactor()->value( array( 'items' => array( 'a', 'b', 'c' ) ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $out['items'] );
	}

	public function test_extra_keys_and_patterns_from_filters() {
		add_filter(
			'ziplogger_redact_keys',
			static function ( $keys ) {
				$keys[] = 'member_number';
				return $keys;
			}
		);
		add_filter(
			'ziplogger_redact_patterns',
			static function ( $p ) {
				$p[] = '/\bINV-\d{8}\b/';
				$p[] = '/(unclosed/'; // Invalid: must be ignored, not fatal.
				return $p;
			}
		);
		$r = Redactor::from_filters();
		$this->assertSame( '[redacted]', $r->value( array( 'member_number' => '77' ) )['member_number'] );
		$this->assertStringNotContainsString( 'INV-12345678', $r->text( 'see INV-12345678 please' ) );
		$this->assertStringContainsString( 'please', $r->text( 'see INV-12345678 please' ) );
	}

	public function test_exact_secrets_are_masked_in_diagnostics() {
		$r = $this->redactor( array( 'secrets' => array( 'plain-secret-value-1' ) ) );
		$this->assertStringNotContainsString( 'plain-secret-value-1', $r->diagnostic( 'request with plain-secret-value-1 failed' ) );
		$this->assertStringNotContainsString( 'plain-secret-value-1', $r->text( 'x plain-secret-value-1 y' ) );
	}

	public function test_output_is_bounded() {
		$out = $this->redactor()->text( str_repeat( 'a b ', 100000 ), 1000 );
		$this->assertLessThanOrEqual( 1000, strlen( $out ) );
	}
}
