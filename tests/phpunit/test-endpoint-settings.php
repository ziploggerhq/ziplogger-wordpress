<?php
/**
 * Endpoint validation (SSRF protections) and settings handling.
 */

use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Settings;

class Test_Endpoint_Settings extends ZL_TestCase {

	/**
	 * @dataProvider valid_endpoint_provider
	 */
	public function test_valid_endpoints_are_normalized( $input, $expected_base ) {
		$r = Endpoint::validate( $input );
		$this->assertTrue( $r['ok'], $input . ' -> ' . $r['error'] );
		$this->assertSame( $expected_base, $r['base'] );
	}

	public function valid_endpoint_provider() {
		return array(
			'default'            => array( 'https://app.ziplogger.ai', 'https://app.ziplogger.ai' ),
			'trailing slash'     => array( 'https://app.ziplogger.ai/', 'https://app.ziplogger.ai' ),
			'upper case host'    => array( 'HTTPS://App.ZipLogger.AI', 'https://app.ziplogger.ai' ),
			'pasted ingest url'  => array( 'https://app.ziplogger.ai/ingest/v1/logs', 'https://app.ziplogger.ai' ),
			'self hosted'        => array( 'https://logs.example.org', 'https://logs.example.org' ),
			'self hosted subpath' => array( 'https://example.org/zl/', 'https://example.org/zl' ),
			'explicit 443'       => array( 'https://logs.example.org:443', 'https://logs.example.org' ),
		);
	}

	/**
	 * @dataProvider invalid_endpoint_provider
	 */
	public function test_unsafe_endpoints_are_rejected( $input, $code ) {
		$r = Endpoint::validate( $input );
		$this->assertFalse( $r['ok'], $input );
		$this->assertSame( $code, $r['code'], $input );
		$this->assertNotSame( '', $r['error'] );
	}

	public function invalid_endpoint_provider() {
		return array(
			'plain http'        => array( 'http://app.ziplogger.ai', 'scheme' ),
			'ftp'               => array( 'ftp://app.ziplogger.ai', 'scheme' ),
			'no scheme'         => array( 'app.ziplogger.ai', 'malformed' ),
			'credentials'       => array( 'https://user:pass@app.ziplogger.ai', 'credentials' ),
			'user only'         => array( 'https://user@app.ziplogger.ai', 'credentials' ),
			'query'             => array( 'https://app.ziplogger.ai/?x=1', 'query' ),
			'fragment'          => array( 'https://app.ziplogger.ai/#x', 'query' ),
			'ip literal'        => array( 'https://93.184.216.34', 'host' ),
			'loopback ip'       => array( 'https://127.0.0.1', 'host' ),
			'metadata ip'       => array( 'https://169.254.169.254/latest', 'host' ),
			'ipv6 literal'      => array( 'https://[::1]', 'host' ),
			'localhost'         => array( 'https://localhost', 'host' ),
			'dot local'         => array( 'https://printer.local', 'host' ),
			'dot internal'      => array( 'https://svc.internal', 'host' ),
			'single label'      => array( 'https://intranet', 'host' ),
			'odd port'          => array( 'https://app.ziplogger.ai:8443', 'port' ),
			'inner space'       => array( 'https://app.ziplogger.ai /x', 'malformed' ),
			'inner newline'     => array( "https://app.ziplogger.ai/\nX-Injected: 1", 'malformed' ),
			'backslash'         => array( 'https://app.ziplogger.ai\\@evil.com', 'malformed' ),
			'weird path'        => array( 'https://logs.example.org/a%2f..%2fb', 'path' ),
			'empty'             => array( '', 'malformed' ),
		);
	}

	/**
	 * @dataProvider private_address_provider
	 */
	public function test_hosts_resolving_to_non_public_addresses_are_rejected( $ip ) {
		Endpoint::$resolver = static function () use ( $ip ) {
			return array( $ip );
		};
		$r = Endpoint::validate( 'https://rebind.example.org' );
		$this->assertFalse( $r['ok'], $ip );
		$this->assertSame( 'dns', $r['code'] );
	}

	public function private_address_provider() {
		return array(
			array( '10.0.0.5' ),
			array( '172.16.4.1' ),
			array( '192.168.1.1' ),
			array( '127.0.0.1' ),
			array( '169.254.169.254' ),
			array( '0.0.0.0' ),
			array( '::1' ),
			array( 'fc00::1' ),
			array( 'fe80::1' ),
			array( '::ffff:10.0.0.1' ),
			array( '::ffff:127.0.0.1' ),
			array( '::ffff:a00:1' ), // 10.0.0.1, written in hexadecimal.
			array( '::ffff:169.254.169.254' ),
			array( '64:ff9b::a00:1' ), // NAT64 form of 10.0.0.1.
			array( '2002:a00:1::1' ), // 6to4 form of 10.0.0.1.
			array( '::10.0.0.1' ), // The deprecated IPv4-compatible form.
			array( '2001:0:4136:e378:8000:63bf:3fff:fdd2' ), // Teredo.
		);
	}

	/**
	 * @dataProvider public_address_provider
	 */
	public function test_public_addresses_are_accepted( $ip ) {
		Endpoint::$resolver = static function () use ( $ip ) {
			return array( $ip );
		};
		$this->assertTrue( Endpoint::validate( 'https://public.example.org' )['ok'], $ip );
	}

	public function public_address_provider() {
		return array(
			array( '93.184.216.34' ),
			array( '2606:4700:4700::1111' ),
		);
	}

	public function test_one_private_address_among_public_ones_is_enough_to_reject() {
		Endpoint::$resolver = static function () {
			return array( '93.184.216.34', '10.1.1.1' );
		};
		$this->assertFalse( Endpoint::validate( 'https://mixed.example.org' )['ok'] );
	}

	public function test_unresolvable_host_is_rejected_on_save() {
		Endpoint::$resolver = static function () {
			return array();
		};
		$r = Endpoint::validate( 'https://nope.example.org', array( 'resolve' => true ) );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'dns', $r['code'] );
	}

	public function test_default_host_needs_no_dns_lookup() {
		$calls = 0;
		Endpoint::$resolver = static function () use ( &$calls ) {
			++$calls;
			return array();
		};
		$this->assertTrue( Endpoint::validate( 'https://app.ziplogger.ai' )['ok'] );
		$this->assertSame( 0, $calls );
	}

	public function test_public_ipv6_is_accepted() {
		Endpoint::$resolver = static function () {
			return array( '2606:4700:4700::1111' );
		};
		$this->assertTrue( Endpoint::validate( 'https://v6.example.org' )['ok'] );
	}

	public function test_local_development_exception_needs_explicit_configuration() {
		$this->assertFalse( Endpoint::insecure_allowed() );
		$this->assertFalse( Endpoint::validate( 'http://localhost:8080' )['ok'] );

		Settings::$constants['ZIPLOGGER_ALLOW_INSECURE_ENDPOINT'] = 'yes'; // Truthy but not exactly true.
		$this->assertFalse( Endpoint::insecure_allowed() );

		Settings::$constants['ZIPLOGGER_ALLOW_INSECURE_ENDPOINT'] = true;
		$this->assertTrue( Endpoint::insecure_allowed() );
		$r = Endpoint::validate( 'http://localhost:8080' );
		$this->assertTrue( $r['ok'], $r['error'] );
		$this->assertSame( 'http://localhost:8080', $r['base'] );
		$this->assertTrue( Endpoint::validate( 'http://mock:5081' )['ok'] );

		// The exception relaxes host, scheme and port only. Credentials and queries stay forbidden.
		$this->assertFalse( Endpoint::validate( 'http://user:pw@localhost:8080' )['ok'] );
		$this->assertFalse( Endpoint::validate( 'http://localhost:8080/?a=1' )['ok'] );
	}

	public function test_surrounding_whitespace_from_pasting_is_trimmed() {
		$r = Endpoint::validate( "  https://app.ziplogger.ai/\n" );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'https://app.ziplogger.ai', $r['base'] );
	}

	public function test_ingest_and_app_urls() {
		$this->assertSame( 'https://app.ziplogger.ai/ingest/v1/logs', Endpoint::ingest_url( 'https://app.ziplogger.ai' ) );
		$this->assertSame( 'https://app.ziplogger.ai/ingest/v1/logs', Endpoint::ingest_url( 'https://app.ziplogger.ai/' ) );
		$this->assertSame( 'https://app.ziplogger.ai/', Endpoint::app_url( '' ) );
		$this->assertSame( 'https://logs.example.org/', Endpoint::app_url( 'https://logs.example.org' ) );
		$this->assertSame( 'https://example.org/zl/', Endpoint::app_url( 'https://example.org/zl' ) );
	}

	// -----------------------------------------------------------------------------------------------

	public function test_defaults_are_off_and_quiet() {
		$s = Settings::get();
		$this->assertFalse( $s['enabled'] );
		$this->assertSame( 'warn', $s['min_severity'] );
		$this->assertSame( 'wordpress', $s['source'] );
		$this->assertTrue( $s['collectors']['php_errors'] );
		$this->assertTrue( $s['collectors']['plugin_theme'] );
		$this->assertTrue( $s['collectors']['updates'] );
		$this->assertFalse( $s['collectors']['failed_logins'], 'Noisy collectors are off by default.' );
		$this->assertFalse( $s['collectors']['http_failures'] );
		$this->assertFalse( $s['collectors']['http_slow'] );
		$this->assertFalse( $s['delete_on_uninstall'] );
		$this->assertFalse( Settings::is_enabled() );
		$this->assertFalse( Settings::collector_enabled( 'php_errors' ), 'No collector runs while collection is off.' );
	}

	public function test_normalize_survives_garbage() {
		$s = Settings::normalize( array( 'enabled' => 'yes', 'min_severity' => 'loud', 'slow_http_seconds' => '9999', 'collectors' => 'x', 'source' => array(), 'environment' => 'Prod Env!', 'endpoint' => 5 ) );
		$this->assertTrue( $s['enabled'] );
		$this->assertSame( 'warn', $s['min_severity'] );
		$this->assertSame( 60, $s['slow_http_seconds'] );
		$this->assertSame( 'wordpress', $s['source'] );
		$this->assertSame( '', $s['environment'] );
		$this->assertSame( '', $s['endpoint'] );
		$this->assertTrue( $s['collectors']['php_errors'] );
	}

	public function test_submission_keeps_previous_values_for_invalid_fields() {
		$current = Settings::get();
		$r       = Settings::validate_submission(
			array(
				'enabled'           => '1',
				'source'            => 'bad label!<script>',
				'min_severity'      => 'loud',
				'slow_http_seconds' => '0',
				'endpoint'          => 'http://insecure.example.org',
				'environment'       => 'Not Valid!',
				'collectors'        => array( 'failed_logins' => '1' ),
			),
			$current
		);
		$this->assertTrue( $r['settings']['enabled'] );
		$this->assertSame( $current['source'], $r['settings']['source'] );
		$this->assertSame( $current['min_severity'], $r['settings']['min_severity'] );
		$this->assertSame( $current['slow_http_seconds'], $r['settings']['slow_http_seconds'] );
		$this->assertSame( '', $r['settings']['endpoint'] );
		$this->assertCount( 5, $r['errors'] );
		$this->assertTrue( $r['settings']['collectors']['failed_logins'] );
		$this->assertFalse( $r['settings']['collectors']['php_errors'], 'An unchecked box means off.' );
	}

	public function test_submission_stores_the_default_endpoint_as_empty() {
		$r = Settings::validate_submission( array( 'endpoint' => 'https://app.ziplogger.ai/' ), Settings::get() );
		$this->assertSame( '', $r['settings']['endpoint'] );
		$r = Settings::validate_submission( array( 'endpoint' => 'https://logs.example.org' ), Settings::get() );
		$this->assertSame( 'https://logs.example.org', $r['settings']['endpoint'] );
	}

	public function test_api_key_validation_blocks_header_injection() {
		foreach ( array( "abc\r\nX-Evil: 1", 'short', 'has space in it 12345', str_repeat( 'a', 300 ), '' ) as $bad ) {
			$this->assertNotSame( '', Settings::save_api_key( $bad ), json_encode( $bad ) );
		}
		$this->assertSame( '', Settings::api_key() );
		$this->assertSame( '', Settings::save_api_key( self::KEY ) );
		$this->assertSame( self::KEY, Settings::api_key() );
	}

	public function test_api_key_precedence_constant_then_environment_then_saved() {
		Settings::save_api_key( 'zk_saved_key_000000000000' );
		$this->assertSame( 'saved', Settings::api_key_source() );
		$this->assertSame( 'zk_saved_key_000000000000', Settings::api_key() );

		putenv( 'ZIPLOGGER_API_KEY=zk_env_key_1111111111111' );
		$this->assertSame( 'environment', Settings::api_key_source() );
		$this->assertSame( 'zk_env_key_1111111111111', Settings::api_key() );

		Settings::$constants['ZIPLOGGER_API_KEY'] = 'zk_const_key_22222222222';
		$this->assertSame( 'constant', Settings::api_key_source() );
		$this->assertSame( 'zk_const_key_22222222222', Settings::api_key() );

		Settings::$constants['ZIPLOGGER_API_KEY'] = "bad key\nwith newline";
		$this->assertSame( 'invalid', Settings::api_key_source() );
		$this->assertSame( '', Settings::api_key(), 'An invalid override must not fall through to a weaker source.' );
	}

	public function test_saved_key_is_not_autoloaded_and_not_exposed_through_rest() {
		Settings::save( array( 'enabled' => true ) );
		Settings::save_api_key( self::KEY );

		$this->assertArrayNotHasKey( Settings::KEY_OPTION, wp_load_alloptions(), 'The key must not sit in the autoloaded options cache.' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$server = rest_get_server();
		$routes = array_keys( $server->get_routes() );
		foreach ( $routes as $route ) {
			$this->assertStringNotContainsString( 'ziplogger', $route, 'This release registers no REST routes.' );
		}
		$request  = new WP_REST_Request( 'GET', '/wp/v2/settings' );
		$response = rest_do_request( $request );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $response->get_data() ) );
		$this->assertArrayNotHasKey( Settings::OPTION, (array) $response->get_data() );
		$this->assertArrayNotHasKey( Settings::KEY_OPTION, (array) $response->get_data() );
	}

	public function test_key_hint_is_masked() {
		Settings::save_api_key( self::KEY );
		$hint = Settings::api_key_hint();
		$this->assertStringStartsWith( 'zk_', $hint );
		$this->assertStringEndsWith( substr( self::KEY, -4 ), $hint );
		$this->assertStringNotContainsString( substr( self::KEY, 3, 12 ), $hint );
	}

	public function test_removing_the_key() {
		Settings::save_api_key( self::KEY );
		Settings::remove_api_key();
		$this->assertSame( 'none', Settings::api_key_source() );
		$this->assertSame( '', Settings::api_key_hint() );
	}

	public function test_endpoint_from_constant_wins_and_invalid_values_block_delivery() {
		Settings::save( array( 'endpoint' => 'https://saved.example.org' ) );
		$this->assertSame( 'https://saved.example.org', Settings::endpoint_base() );
		$this->assertSame( 'saved', Settings::endpoint_source() );

		Settings::$constants['ZIPLOGGER_ENDPOINT'] = 'https://const.example.org';
		Settings::reset_cache();
		$this->assertSame( 'https://const.example.org', Settings::endpoint_base() );
		$this->assertSame( 'constant', Settings::endpoint_source() );

		Settings::$constants['ZIPLOGGER_ENDPOINT'] = 'http://insecure.example.org';
		Settings::reset_cache();
		$this->assertSame( '', Settings::endpoint_base(), 'An invalid configured endpoint must not silently fall back to another host.' );
		$this->assertNotSame( '', Settings::endpoint_problem() );
	}

	public function test_is_configured_needs_key_and_endpoint() {
		$this->assertFalse( Settings::is_configured() );
		Settings::save_api_key( self::KEY );
		$this->assertTrue( Settings::is_configured() );
	}

	public function test_environment_falls_back_to_wordpress_environment_type() {
		$this->assertSame( wp_get_environment_type(), Settings::environment() );
		Settings::save( array( 'environment' => 'qa' ) );
		$this->assertSame( 'qa', Settings::environment() );
	}
}
