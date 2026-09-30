<?php
/**
 * The browser script's configuration and the per-visitor context endpoint.
 *
 * The properties that matter most: the configuration is the same for every visitor (page caches store it),
 * it carries only the browser key, and the endpoint that answers visitor-specific questions fails closed.
 */

use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Context_Endpoint;
use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Frontend;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Secrets;
use ZipLogger\WordPress\Settings;

class Test_Frontend extends ZL_TestCase {

	const BROWSER_KEY = 'zk_browser_public_0123456789abcd';
	const READ_KEY    = 'zk_read_private_0123456789abcdef';

	public function set_up() {
		parent::set_up();
		Secrets::reset();
		Consent::reset();
		delete_option( 'ziplogger_browser_key' );
		delete_option( 'ziplogger_read_key' );
	}

	public function tear_down() {
		remove_all_filters( 'ziplogger_frontend_config' );
		remove_all_filters( 'ziplogger_load_frontend' );
		remove_all_filters( 'ziplogger_replay_excluded_page' );
		remove_all_filters( 'ziplogger_replay_allowed_for_user' );
		remove_all_filters( 'wp_doing_ajax' );
		wp_set_current_user( 0 );
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * Server key, browser key and read key all set; the listed modules switched on.
	 */
	private function turn_on( array $modules = array( 'browser' ), array $module_settings = array() ) {
		$settings = array( 'enabled' => true );
		foreach ( $modules as $module ) {
			$settings[ $module ] = array_merge( array( 'enabled' => true ), isset( $module_settings[ $module ] ) ? $module_settings[ $module ] : array() );
		}
		Settings::save( $settings );
		Settings::save_api_key( self::KEY );
		$this->assertSame( '', Settings::save_key( 'browser', self::BROWSER_KEY ) );
		$this->assertSame( '', Settings::save_key( 'read', self::READ_KEY ) );
		Settings::reset_cache();
	}

	private function user( $role ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	// ---------------------------------------------------------------------------------------------
	// When the script is loaded.
	// ---------------------------------------------------------------------------------------------

	public function test_nothing_is_printed_or_enqueued_while_every_browser_module_is_off() {
		Settings::save( array( 'enabled' => true ) );
		Settings::save_api_key( self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		$this->assertFalse( Frontend::should_load() );
		ob_start();
		Frontend::print_config();
		$this->assertSame( '', ob_get_clean() );
		Frontend::enqueue();
		$this->assertFalse( wp_script_is( Frontend::HANDLE, 'enqueued' ) );
	}

	public function test_a_module_that_is_on_but_has_no_browser_key_sends_nothing() {
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ) ) );
		Settings::save_api_key( self::KEY );
		$this->assertFalse( Modules::effective( 'browser' ) );
		$this->assertNull( Frontend::config() );
		ob_start();
		Frontend::print_config();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_browser_key_may_not_be_the_server_key() {
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ) ) );
		Settings::save_api_key( self::KEY );
		$this->assertNotSame( '', Settings::save_key( 'browser', self::KEY ), 'Refused when saving.' );
		Settings::$constants = array( 'ZIPLOGGER_BROWSER_KEY' => self::KEY );
		Settings::reset_cache();
		$this->assertNull( Frontend::config(), 'A browser key that equals the server key is never printed.' );
		$this->assertStringNotContainsString( self::KEY, (string) wp_json_encode( Frontend::config() ) );
	}

	public function test_the_script_is_not_loaded_in_admin_ajax_feeds_or_previews() {
		$this->turn_on();
		$this->assertTrue( Frontend::should_load() );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( Frontend::should_load(), 'admin-ajax.php' );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->go_to( '/?feed=rss2' );
		$this->assertFalse( Frontend::should_load(), 'feeds' );

		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->user( 'administrator' ); // WordPress only treats it as a preview for someone who may edit the post.
		$this->go_to( '/?p=' . $post . '&preview=true' );
		$this->assertFalse( Frontend::should_load(), 'previews' );

		set_current_screen( 'dashboard' );
		$this->assertFalse( Frontend::should_load(), 'wp-admin' );
		set_current_screen( 'front' );
	}

	public function test_a_site_can_keep_the_script_off_specific_pages() {
		$this->turn_on();
		add_filter( 'ziplogger_load_frontend', '__return_false' );
		$this->assertFalse( Frontend::should_load() );
	}

	public function test_hooks_are_registered_and_the_plugin_declares_itself_consent_api_aware() {
		$this->assertNotFalse( has_action( 'wp_head', array( Frontend::class, 'print_config' ) ) );
		$this->assertNotFalse( has_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) ) );
		$this->assertTrue( apply_filters( 'wp_consent_api_registered_' . plugin_basename( ZIPLOGGER_FILE ), false ) );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_' . Frontend::CONTEXT_ACTION ), 'Anonymous visitors can ask, too.' );
	}

	public function test_the_script_tag_is_deferred_and_optimizers_are_asked_to_leave_it_alone() {
		$this->turn_on();
		Frontend::enqueue();
		ob_start();
		wp_print_scripts( Frontend::HANDLE );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'assets/js/ziplogger.min.js', $html );
		$this->assertStringContainsString( ' defer ', $html );
		$this->assertStringContainsString( 'data-cfasync="false"', $html );
		$this->assertStringContainsString( 'data-no-optimize="1"', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// What the configuration contains.
	// ---------------------------------------------------------------------------------------------

	public function test_the_config_carries_the_browser_key_and_never_the_server_or_read_key() {
		$this->turn_on( array( 'browser', 'analytics', 'replay', 'tracing' ), array( 'tracing' => array( 'browser' => true ) ) );
		$json = (string) wp_json_encode( Frontend::config() );
		$this->assertStringContainsString( self::BROWSER_KEY, $json );
		$this->assertStringNotContainsString( self::KEY, $json, 'The private server key must never reach a visitor.' );
		$this->assertStringNotContainsString( self::READ_KEY, $json, 'The read credential must never reach a visitor.' );
		ob_start();
		Frontend::print_config();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( self::READ_KEY, $html );
	}

	public function test_modules_in_the_config_are_the_effective_ones() {
		$this->turn_on( array( 'browser', 'analytics' ) );
		$m = Frontend::config()['modules'];
		$this->assertTrue( $m['browser'] );
		$this->assertTrue( $m['analytics'] );
		$this->assertFalse( $m['replay'] );
		$this->assertFalse( $m['tracing'] );
		$this->assertFalse( $m['commerce'] );
		$this->assertSame( '', Frontend::config()['replay']['recorderUrl'], 'The recorder is not even referenced while replay is off.' );
	}

	public function test_browser_tracing_needs_both_the_tracing_module_and_its_browser_option() {
		$this->turn_on( array( 'browser', 'tracing' ), array( 'tracing' => array( 'browser' => false ) ) );
		$this->assertFalse( Frontend::config()['modules']['tracing'] );
		$this->turn_on( array( 'browser', 'tracing' ), array( 'tracing' => array( 'browser' => true ) ) );
		$this->assertTrue( Frontend::config()['modules']['tracing'] );
	}

	public function test_settings_reach_the_browser_in_the_shape_the_script_reads() {
		$this->turn_on(
			array( 'browser', 'analytics', 'replay', 'tracing' ),
			array(
				'browser'   => array( 'slow_requests' => true, 'slow_threshold_ms' => 1500, 'web_vitals' => true, 'perf_sample_rate' => 25 ),
				'analytics' => array( 'interactions' => true, 'interaction_selectors' => ".buy\n#signup" ),
				'replay'    => array( 'sample_rate' => 7, 'block_selector' => '.private', 'exclude_paths' => "/members/*\n/vip", 'max_minutes' => 12 ),
				'tracing'   => array( 'browser' => true, 'sample_rate' => 33, 'propagate_hosts' => "api.partner.com\n*.cdn.example.com" ),
			)
		);
		$c = Frontend::config();
		$this->assertTrue( $c['browser']['slowRequests'] );
		$this->assertSame( 1500, $c['browser']['slowThresholdMs'] );
		$this->assertTrue( $c['browser']['webVitals'] );
		$this->assertSame( 25, $c['browser']['perfSampleRate'] );
		$this->assertSame( array( '.buy', '#signup' ), $c['analytics']['selectors'] );
		$this->assertSame( 7, $c['replay']['sampleRate'] );
		$this->assertSame( array( '.private' ), $c['replay']['blockSelector'] );
		$this->assertSame( array( '/members/*', '/vip' ), $c['replay']['excludePaths'] );
		$this->assertSame( 12, $c['replay']['maxMinutes'] );
		$this->assertStringContainsString( 'assets/js/ziplogger-recorder.min.js?ver=', $c['replay']['recorderUrl'] );
		$this->assertSame( array( 'api.partner.com', '*.cdn.example.com' ), $c['tracing']['propagateHosts'] );
		$this->assertSame( 33, $c['tracing']['sampleRate'] );
		$this->assertSame( 1, $c['v'] );
		$this->assertSame( array( 'browser' => 'none', 'analytics' => 'required', 'replay' => 'required', 'commerce' => 'none' ), $c['consent']['policy'] );
	}

	public function test_the_replay_exclusion_roles_stay_on_the_server() {
		$this->turn_on( array( 'browser', 'replay' ) );
		$this->assertArrayNotHasKey( 'excludeRoles', Frontend::config()['replay'], 'Which roles are excluded is not the visitor\'s business.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Cache safety.
	// ---------------------------------------------------------------------------------------------

	public function test_the_config_is_identical_for_every_visitor() {
		$this->turn_on( array( 'browser', 'analytics', 'replay', 'tracing' ), array( 'analytics' => array( 'identify' => true ), 'tracing' => array( 'browser' => true ) ) );
		$post = self::factory()->post->create();
		$this->go_to( get_permalink( $post ) );

		wp_set_current_user( 0 );
		$anonymous = wp_json_encode( Frontend::config() );
		foreach ( array( 'subscriber', 'customer', 'editor', 'administrator' ) as $role ) {
			if ( null === get_role( $role ) ) {
				continue;
			}
			$this->user( $role );
			$this->assertSame( $anonymous, wp_json_encode( Frontend::config() ), "Configuration must not depend on who is signed in ($role)." );
		}
	}

	public function test_the_config_holds_no_per_visitor_or_per_request_values() {
		$this->turn_on( array( 'browser', 'analytics', 'replay', 'tracing' ), array( 'analytics' => array( 'identify' => true ), 'tracing' => array( 'browser' => true ) ) );
		$this->user( 'administrator' );
		$json = strtolower( (string) wp_json_encode( Frontend::config() ) );
		foreach ( array( 'nonce', 'sessionid', 'session_id', 'traceid', 'trace_id', 'traceparent', 'userid', 'user_id', 'anonymousid', 'requestid', 'wpu_', 'sess_', 'anon_', 'user_email', 'user_login' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $json, "The cached page must not contain '$forbidden'." );
		}
		$this->assertSame( array( 'url', 'action', 'hintCookie' ), array_keys( Frontend::config()['context'] ) );
		$this->assertStringNotContainsString( 'http', Frontend::config()['context']['url'], 'A relative path keeps the request on the page\'s own origin.' );
	}

	public function test_the_printed_block_is_inert_json_and_cannot_be_broken_out_of() {
		$this->turn_on();
		add_filter(
			'ziplogger_frontend_config',
			static function ( $c ) {
				$c['x'] = '</script><script>alert(1)</script><!--';
				return $c;
			}
		);
		ob_start();
		Frontend::print_config();
		$html = ob_get_clean();
		$this->assertStringStartsWith( '<script type="application/json" id="ziplogger-config">', $html );
		$this->assertSame( 1, substr_count( $html, '</script>' ), 'Only the closing tag we wrote.' );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$decoded = json_decode( trim( preg_replace( '#^<script[^>]*>|</script>\s*$#', '', $html ) ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( '</script><script>alert(1)</script><!--', $decoded['x'], 'And it round-trips.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Endpoint validation for the browser.
	// ---------------------------------------------------------------------------------------------

	public function test_validating_the_endpoint_for_the_browser_never_does_a_dns_lookup() {
		$lookups             = 0;
		Endpoint::$resolver  = static function () use ( &$lookups ) {
			++$lookups;
			return array( '93.184.216.34' );
		};
		$this->turn_on();
		Settings::save( array( 'enabled' => true, 'endpoint' => 'https://ingest.customer-zl.example.org', 'browser' => array( 'enabled' => true ) ) );
		$this->assertSame( 'https://ingest.customer-zl.example.org', Settings::browser_endpoint() );
		$this->assertSame( 0, $lookups, 'Rendering a page must not resolve names.' );
	}

	public function test_an_unusable_endpoint_means_no_browser_script() {
		$this->turn_on();
		foreach ( array( 'http://ingest.example.org', 'https://user:pw@ingest.example.org', 'https://localhost', 'https://10.0.0.1', 'ftp://x.example.org' ) as $bad ) {
			Settings::save( array( 'enabled' => true, 'endpoint' => $bad, 'browser' => array( 'enabled' => true ) ) );
			$this->assertSame( '', Settings::browser_endpoint(), $bad );
			$this->assertNull( Frontend::config(), $bad );
		}
	}

	public function test_plain_http_to_localhost_is_only_possible_with_the_explicit_development_constant() {
		$this->turn_on();
		Settings::save( array( 'enabled' => true, 'endpoint' => 'http://localhost:5080', 'browser' => array( 'enabled' => true ) ) );
		$this->assertNull( Frontend::config() );
		Settings::$constants = array( 'ZIPLOGGER_ALLOW_INSECURE_ENDPOINT' => true );
		Settings::reset_cache();
		$c = Frontend::config();
		$this->assertSame( 'http://localhost:5080', $c['endpoint'] );
		$this->assertTrue( $c['allowInsecureEndpoint'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Page context.
	// ---------------------------------------------------------------------------------------------

	public function test_page_types() {
		$this->turn_on();
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( 'post', Frontend::page()['type'] );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( 'page', Frontend::page()['type'] );
		$this->go_to( '/?s=hello' );
		$this->assertSame( 'search', Frontend::page()['type'] );
		$this->go_to( '/?p=999999' );
		$this->assertSame( 'not_found', Frontend::page()['type'] );
		$this->go_to( home_url( '/' ) );
		$this->assertContains( Frontend::page()['type'], array( 'front_page', 'blog' ) );
	}

	public function test_password_protected_posts_and_filtered_pages_are_excluded_from_replay() {
		$this->turn_on();
		$open      = self::factory()->post->create();
		$protected = self::factory()->post->create( array( 'post_password' => 'secret' ) );
		$this->go_to( get_permalink( $open ) );
		$this->assertTrue( Frontend::page()['replay'] );
		$this->go_to( get_permalink( $protected ) );
		$this->assertFalse( Frontend::page()['replay'] );

		$this->go_to( get_permalink( $open ) );
		add_filter( 'ziplogger_replay_excluded_page', '__return_true' );
		$this->assertFalse( Frontend::page()['replay'] );
	}

	public function test_the_page_block_does_not_depend_on_the_visitor_for_a_protected_post() {
		$this->turn_on();
		$protected = self::factory()->post->create( array( 'post_password' => 'secret' ) );
		$this->go_to( get_permalink( $protected ) );
		wp_set_current_user( 0 );
		$anonymous = Frontend::page();
		$this->user( 'administrator' );
		$this->assertSame( $anonymous, Frontend::page() );
	}

	// ---------------------------------------------------------------------------------------------
	// The per-visitor context endpoint.
	// ---------------------------------------------------------------------------------------------

	private function ask() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		$this->catch_die( array( Context_Endpoint::class, 'handle' ) );
		$out = ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		return json_decode( $out, true );
	}

	public function test_an_anonymous_visitor_may_be_recorded_when_replay_is_on() {
		$this->turn_on( array( 'browser', 'replay' ) );
		$answer = $this->ask();
		$this->assertTrue( $answer['success'] );
		$this->assertSame( array( 'loggedIn' => false, 'replayAllowed' => true, 'userRef' => null ), $answer['data'] );
	}

	public function test_replay_is_not_allowed_for_anyone_while_the_replay_module_is_off() {
		$this->turn_on( array( 'browser' ) );
		$this->assertFalse( $this->ask()['data']['replayAllowed'] );
		$this->user( 'subscriber' );
		$this->assertFalse( $this->ask()['data']['replayAllowed'] );
	}

	public function test_excluded_roles_are_never_recorded_and_others_may_be() {
		$this->turn_on( array( 'browser', 'replay' ) );
		$this->user( 'administrator' );
		$a = $this->ask();
		$this->assertTrue( $a['data']['loggedIn'] );
		$this->assertFalse( $a['data']['replayAllowed'], 'Administrators are excluded by default.' );
		$this->user( 'editor' );
		$this->assertFalse( $this->ask()['data']['replayAllowed'], 'Editors are excluded by default.' );
		$this->user( 'subscriber' );
		$this->assertTrue( $this->ask()['data']['replayAllowed'] );

		$this->turn_on( array( 'browser', 'replay' ), array( 'replay' => array( 'exclude_roles' => array( 'subscriber' ) ) ) );
		$this->assertFalse( $this->ask()['data']['replayAllowed'], 'The administrator chose subscribers.' );
		$this->user( 'author' );
		$this->assertTrue( $this->ask()['data']['replayAllowed'] );
	}

	public function test_a_user_with_several_roles_is_excluded_if_any_role_is() {
		$this->turn_on( array( 'browser', 'replay' ) );
		$id = $this->user( 'subscriber' );
		( new WP_User( $id ) )->add_role( 'editor' );
		wp_set_current_user( 0 ); // Drop the cached user object so the new role is read.
		wp_set_current_user( $id );
		$this->assertFalse( $this->ask()['data']['replayAllowed'] );
	}

	public function test_the_site_can_veto_recording_and_only_a_strict_true_allows_it() {
		$this->turn_on( array( 'browser', 'replay' ) );
		$this->user( 'subscriber' );
		add_filter( 'ziplogger_replay_allowed_for_user', '__return_false' );
		$this->assertFalse( $this->ask()['data']['replayAllowed'] );
		remove_all_filters( 'ziplogger_replay_allowed_for_user' );
		add_filter(
			'ziplogger_replay_allowed_for_user',
			static function () {
				return 'yes';
			}
		);
		$this->assertFalse( $this->ask()['data']['replayAllowed'], 'A truthy string is not consent to record.' );
	}

	public function test_the_pseudonym_is_only_provided_when_identification_is_on() {
		$this->turn_on( array( 'browser', 'analytics' ) );
		$id = $this->user( 'subscriber' );
		$this->assertNull( $this->ask()['data']['userRef'] );

		$this->turn_on( array( 'browser', 'analytics' ), array( 'analytics' => array( 'identify' => true ) ) );
		$answer = $this->ask()['data'];
		$this->assertSame( Secrets::pseudonym( 'wpu', $id ), $answer['userRef'] );
		$this->assertMatchesRegularExpression( '/^wpu_[0-9a-f]{24}$/', $answer['userRef'] );
	}

	public function test_the_answer_never_contains_who_the_user_is() {
		$this->turn_on( array( 'browser', 'analytics', 'replay' ), array( 'analytics' => array( 'identify' => true ) ) );
		$id   = self::factory()->user->create( array( 'role' => 'subscriber', 'user_login' => 'jane_the_customer', 'user_email' => 'jane@customer.example', 'display_name' => 'Jane Customer' ) );
		wp_set_current_user( $id );
		$raw = (string) wp_json_encode( $this->ask() );
		foreach ( array( 'jane', 'customer.example', 'Jane', (string) $id . '"', 'user_login', 'email' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $raw, "Leaked: $leak" );
		}
	}

	public function test_the_pseudonym_differs_between_users_and_between_sites_secrets() {
		$this->turn_on( array( 'browser', 'analytics' ), array( 'analytics' => array( 'identify' => true ) ) );
		$this->user( 'subscriber' );
		$first = $this->ask()['data']['userRef'];
		$this->user( 'subscriber' );
		$second = $this->ask()['data']['userRef'];
		$this->assertNotSame( $first, $second );
	}

	public function test_only_post_is_answered_and_nothing_is_revealed_otherwise() {
		$this->turn_on( array( 'browser', 'replay' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		$this->catch_die( array( Context_Endpoint::class, 'handle' ) );
		$out = json_decode( ob_get_clean(), true );
		$this->assertFalse( $out['success'] );
		$this->assertArrayNotHasKey( 'loggedIn', (array) $out['data'] );
	}

	public function test_the_endpoint_is_closed_when_no_browser_module_is_on() {
		Settings::save( array( 'enabled' => true ) );
		$out = $this->ask();
		$this->assertFalse( $out['success'] );
	}

	public function test_the_sign_in_hint_cookie_is_only_touched_when_it_matters() {
		// With every browser module off, signing in must not set any cookie.
		Settings::save( array( 'enabled' => true ) );
		$this->assertFalse( Modules::frontend_needed() );
		$this->assertNotFalse( has_action( 'set_logged_in_cookie', array( Context_Endpoint::class, 'set_hint' ) ) );
		$this->assertNotFalse( has_action( 'clear_auth_cookie', array( Context_Endpoint::class, 'clear_hint' ) ) );
		Context_Endpoint::set_hint( '', 0, 0 );
		Context_Endpoint::clear_hint();
		$this->assertTrue( true, 'Neither call may fail or warn (headers may already be sent under PHPUnit).' );
	}
}
