<?php
/**
 * The settings screens and their request handlers: authorization, CSRF, per-section saves,
 * credential handling, destination policy, escaping and accessibility.
 */

use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Admin\Tabs\Diagnostics;
use ZipLogger\WordPress\Destination;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Secrets;
use ZipLogger\WordPress\Settings;

class ZL_Redirect extends Exception {
	public $location;

	public function __construct( $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

class Test_Admin extends ZL_TestCase {

	const BROWSER_KEY = 'zk_browser_public_key_012345678901';
	const READ_KEY    = 'zk_read_only_key_0123456789012345';
	const KEY_B       = 'zk_second_workspace_key_0123456789';

	/**
	 * @var Settings_Page
	 */
	private $page;

	public function set_up() {
		parent::set_up();
		Secrets::reset();
		Destination::reset();
		$this->page = new Settings_Page();
		add_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );
	}

	public function tear_down() {
		remove_filter( 'wp_redirect', array( $this, 'catch_redirect' ) );
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		parent::tear_down();
	}

	public function catch_redirect( $location ) {
		throw new ZL_Redirect( $location );
	}

	private function login( $role = 'administrator' ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * Run an admin-post handler with a request. Returns the redirect location, or a WPDieException.
	 */
	private function submit( $method, array $data, $nonce_action = null ) {
		$_POST    = $data;
		$_REQUEST = $data;
		if ( null !== $nonce_action ) {
			$_POST['_wpnonce']    = wp_create_nonce( $nonce_action );
			$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
		}
		try {
			$died = $this->catch_die(
				function () use ( $method ) {
					$this->page->$method();
				}
			);
			return $died;
		} catch ( ZL_Redirect $r ) {
			return $r;
		}
	}

	private function notices() {
		return get_transient( 'ziplogger_notices_' . get_current_user_id() );
	}

	private function notice_text() {
		return implode( ' | ', array_column( (array) $this->notices(), 1 ) );
	}

	private function render_tab( $tab ) {
		$_GET['tab'] = $tab;
		ob_start();
		$this->page->render();
		return ob_get_clean();
	}

	// ---------------------------------------------------------------------------------------------
	// Authorization and CSRF.
	// ---------------------------------------------------------------------------------------------

	public function handler_provider() {
		return array(
			'save'        => array( 'handle_save', 'ziplogger_save', array( 'section' => 'logs', 'tab' => 'logs', 'enabled' => '1' ) ),
			'test'        => array( 'handle_test', 'ziplogger_test', array() ),
			'flush'       => array( 'handle_flush', 'ziplogger_flush', array() ),
			'clear'       => array( 'handle_clear', 'ziplogger_clear', array() ),
			'held'        => array( 'handle_held', 'ziplogger_held', array( 'choice' => 'discard' ) ),
			'diagnostics' => array( 'handle_diagnostics', 'ziplogger_diagnostics', array() ),
			'test_read'   => array( 'handle_test_read', 'ziplogger_test_read', array() ),
		);
	}

	/**
	 * @dataProvider handler_provider
	 */
	public function test_visitors_and_subscribers_are_refused_before_anything_happens( $method, $nonce_action, array $data ) {
		$this->seed( 3 );
		foreach ( array( 0, 'subscriber', 'author', 'editor' ) as $who ) {
			if ( 0 === $who ) {
				wp_set_current_user( 0 );
			} else {
				$this->login( $who );
			}
			$result = $this->submit( $method, $data, $nonce_action ); // Even with a VALID nonce: a nonce is not authorization.
			$this->assertInstanceOf( WPDieException::class, $result, "$method as " . ( $who ? $who : 'visitor' ) );
		}
		$this->assertSame( 3, $this->queue_count() );
		$this->assertFalse( Settings::is_enabled() );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * @dataProvider handler_provider
	 */
	public function test_an_administrator_without_a_valid_nonce_is_refused( $method, $nonce_action, array $data ) {
		$this->seed( 3 );
		$this->login();
		$missing = $this->submit( $method, $data, null );
		$this->assertInstanceOf( WPDieException::class, $missing, "$method without a nonce" );

		$wrong = $this->submit( $method, $data, 'some_other_action' );
		$this->assertInstanceOf( WPDieException::class, $wrong, "$method with another action's nonce" );

		$this->assertSame( 3, $this->queue_count(), 'Nothing was changed.' );
		$this->assertFalse( Settings::is_enabled() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_a_nonce_for_one_action_does_not_work_for_another() {
		$this->login();
		$this->seed( 2 );
		$result = $this->submit( 'handle_clear', array(), 'ziplogger_test' );
		$this->assertInstanceOf( WPDieException::class, $result );
		$this->assertSame( 2, $this->queue_count() );
	}

	public function test_no_handler_is_reachable_without_logging_in() {
		foreach ( array( 'save', 'test', 'flush', 'clear', 'held', 'diagnostics' ) as $action ) {
			$this->assertFalse( has_action( 'admin_post_nopriv_ziplogger_' . $action ), "$action must not be registered for logged-out visitors" );
		}
	}

	public function test_the_only_public_ajax_action_is_the_read_only_visitor_context_and_there_is_no_rest_route() {
		global $wp_filter;
		$public = array();
		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( 0 === strpos( $hook, 'wp_ajax_nopriv_ziplogger' ) ) {
				$public[] = $hook;
			}
		}
		$this->assertSame( array( 'wp_ajax_nopriv_ziplogger_context' ), $public, 'It answers about the asking visitor only, takes no input and changes nothing.' );
		foreach ( rest_get_server()->get_namespaces() as $namespace ) {
			$this->assertStringNotContainsString( 'ziplogger', $namespace, 'No REST namespace.' );
		}
	}

	public function test_the_settings_screen_itself_needs_the_capability() {
		$this->login( 'editor' );
		$died = $this->catch_die(
			function () {
				$this->page->render();
			}
		);
		$this->assertInstanceOf( WPDieException::class, $died );
	}

	// ---------------------------------------------------------------------------------------------
	// Saving sections.
	// ---------------------------------------------------------------------------------------------

	public function test_saving_the_logs_section_and_returning_to_its_tab() {
		$this->login();
		$r = $this->submit( 'handle_save', array( 'section' => 'logs', 'tab' => 'logs', 'enabled' => '1', 'min_severity' => 'error', 'collectors' => array( 'failed_logins' => '1' ), 'slow_http_seconds' => '9' ), 'ziplogger_save' );
		$this->assertInstanceOf( ZL_Redirect::class, $r );
		$this->assertStringContainsString( 'page=ziplogger&tab=logs', $r->location );
		$s = Settings::get();
		$this->assertTrue( $s['enabled'] );
		$this->assertSame( 'error', $s['min_severity'] );
		$this->assertTrue( $s['collectors']['failed_logins'] );
		$this->assertFalse( $s['collectors']['php_errors'], 'An unchecked box in the submitted section means off.' );
		$this->assertSame( 9, $s['slow_http_seconds'] );
		$this->assertStringContainsString( 'Settings saved', $this->notice_text() );
		$this->assertStringContainsString( 'no server API key', $this->notice_text() );
	}

	public function test_saving_one_tab_leaves_the_others_alone() {
		$this->enable( array( 'source' => 'kept', 'delete_on_uninstall' => true, 'browser' => array( 'enabled' => false, 'errors' => false ) ) );
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'source' => 'renamed', 'environment' => '', 'on_destination_change' => 'hold' ), 'ziplogger_save' );
		$s = Settings::get();
		$this->assertSame( 'renamed', $s['source'] );
		$this->assertTrue( $s['enabled'], 'The server switch belongs to another tab.' );
		$this->assertTrue( $s['delete_on_uninstall'] );
		$this->assertFalse( $s['browser']['errors'] );
	}

	public function test_saving_a_module_with_its_fields() {
		Settings::save_key( 'browser', self::BROWSER_KEY );
		$this->login();
		$r = $this->submit(
			'handle_save',
			array(
				'section'   => 'replay',
				'tab'       => 'replay',
				'ziplogger' => array(
					'replay' => array(
						'enabled'        => '1',
						'sample_rate'    => '12',
						'mask_all_text'  => '1',
						'block_selector' => ".ad\n.video",
						'exclude_paths'  => "/members/*\nbad",
						'exclude_roles'  => array( 'administrator', 'shop_manager', 'nonsense' ),
					),
				),
			),
			'ziplogger_save'
		);
		$this->assertStringContainsString( 'tab=replay', $r->location );
		$replay = Settings::get()['replay'];
		$this->assertTrue( $replay['enabled'] );
		$this->assertSame( 12, $replay['sample_rate'] );
		$this->assertSame( ".ad\n.video", $replay['block_selector'] );
		$this->assertSame( '/members/*', $replay['exclude_paths'] );
		$this->assertNotContains( 'nonsense', $replay['exclude_roles'] );
		$this->assertStringContainsString( 'were not accepted', $this->notice_text() );
	}

	public function test_a_module_is_not_switched_on_while_its_prerequisite_is_missing() {
		$this->login();
		foreach ( array( 'browser', 'analytics', 'replay' ) as $module ) {
			$this->submit( 'handle_save', array( 'section' => $module, 'tab' => $module, 'ziplogger' => array( $module => array( 'enabled' => '1' ) ) ), 'ziplogger_save' );
			$this->assertFalse( Settings::get()[ $module ]['enabled'], "$module must not switch on without a browser key" );
			$this->assertStringContainsString( 'browser API key', $this->notice_text() );
		}
		$this->submit( 'handle_save', array( 'section' => 'tracing', 'tab' => 'tracing', 'ziplogger' => array( 'tracing' => array( 'enabled' => '1' ) ) ), 'ziplogger_save' );
		$this->assertFalse( Settings::get()['tracing']['enabled'], 'Tracing needs a server key.' );

		Settings::save_key( 'browser', self::BROWSER_KEY );
		$this->submit( 'handle_save', array( 'section' => 'browser', 'tab' => 'browser', 'ziplogger' => array( 'browser' => array( 'enabled' => '1', 'errors' => '1' ) ) ), 'ziplogger_save' );
		$this->assertTrue( Settings::get()['browser']['enabled'] );
		$this->assertFalse( Settings::get()['replay']['enabled'], 'Enabling one module never enables another.' );
	}

	public function test_woocommerce_cannot_be_switched_on_without_woocommerce() {
		if ( \ZipLogger\WordPress\Modules::woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is loaded.' );
		}
		Settings::save_key( 'server', self::KEY );
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'woocommerce', 'tab' => 'woocommerce', 'ziplogger' => array( 'woocommerce' => array( 'enabled' => '1' ) ) ), 'ziplogger_save' );
		$this->assertFalse( Settings::get()['woocommerce']['enabled'] );
		$this->assertStringContainsString( 'WooCommerce is not active', $this->notice_text() );
	}

	public function test_saving_privacy_settings() {
		$this->login();
		$this->submit(
			'handle_save',
			array(
				'section'             => 'privacy',
				'tab'                 => 'privacy',
				'delete_on_uninstall' => '1',
				'ziplogger'           => array( 'consent' => array( 'browser' => 'required', 'analytics' => 'required', 'replay' => 'required', 'commerce' => 'none', 'wp_consent_api' => '1' ) ),
			),
			'ziplogger_save'
		);
		$s = Settings::get();
		$this->assertSame( 'required', $s['consent']['browser'] );
		$this->assertTrue( $s['delete_on_uninstall'] );
		$this->assertTrue( $s['consent']['wp_consent_api'] );
	}

	public function test_an_unknown_section_changes_nothing() {
		$this->login();
		$this->enable();
		$before = get_option( Settings::OPTION );
		$this->submit( 'handle_save', array( 'section' => 'evil', 'tab' => '../../wp-config', 'enabled' => '', 'ziplogger' => array( 'browser' => array( 'enabled' => '1' ) ) ), 'ziplogger_save' );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
		$this->assertStringContainsString( 'Unknown settings section', $this->notice_text() );
	}

	public function test_the_tab_is_validated_so_a_redirect_cannot_be_steered() {
		$this->login();
		$r = $this->submit( 'handle_save', array( 'section' => 'logs', 'tab' => 'http://evil.example/?x', 'enabled' => '' ), 'ziplogger_save' );
		$this->assertStringStartsWith( admin_url( 'options-general.php?page=ziplogger' ), $r->location );
		$this->assertStringNotContainsString( 'evil', $r->location );
	}

	// ---------------------------------------------------------------------------------------------
	// Connection: credentials and destination policy.
	// ---------------------------------------------------------------------------------------------

	public function test_saving_credentials_stores_them_without_echoing_them() {
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'key' => array( 'server' => self::KEY, 'browser' => self::BROWSER_KEY, 'read' => self::READ_KEY ) ), 'ziplogger_save' );
		$this->assertSame( self::KEY, Settings::api_key() );
		$this->assertSame( self::BROWSER_KEY, Settings::browser_key() );
		$this->assertSame( self::READ_KEY, Settings::read_key() );
		$text = wp_json_encode( $this->notices() );
		foreach ( array( self::KEY, self::BROWSER_KEY, self::READ_KEY ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
		$this->assertStringContainsString( 'server key was saved', $text );
	}

	public function test_a_browser_key_equal_to_the_server_key_is_refused() {
		Settings::save_key( 'server', self::KEY );
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'key' => array( 'browser' => self::KEY ) ), 'ziplogger_save' );
		$this->assertSame( '', Settings::browser_key() );
		$this->assertStringContainsString( 'already used as the server key', $this->notice_text() );
	}

	public function test_removing_a_key() {
		Settings::save_key( 'read', self::READ_KEY );
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'remove_key' => array( 'read' => '1' ) ), 'ziplogger_save' );
		$this->assertSame( '', Settings::read_key() );
	}

	public function test_saving_connection_lifts_a_delivery_pause() {
		$this->enable();
		$this->meta->set_num( Meta_Store::GATE_UNTIL, time() + 900 );
		$this->meta->set_str( Meta_Store::BLOCKED, 'auth' );
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'key' => array( 'server' => self::KEY_B ) ), 'ziplogger_save' );
		$this->assertSame( 0, $this->meta->get_num( Meta_Store::GATE_UNTIL ) );
		$this->assertSame( '', $this->meta->get_str( Meta_Store::BLOCKED ) );
	}

	private function queue_event_for_current_destination() {
		$r = new Recorder();
		$r->record( 'developer', 'error', 'waiting' );
		$r->flush();
	}

	public function test_a_key_change_holds_queued_data_by_default_and_says_so() {
		$this->enable();
		$this->queue_event_for_current_destination();
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'on_destination_change' => 'hold', 'key' => array( 'server' => self::KEY_B ) ), 'ziplogger_save' );
		$this->assertSame( 1, ( new Queue_Store() )->held_count( Destination::current() ) );
		$this->assertStringContainsString( 'HELD', $this->notice_text() );
	}

	public function test_the_retarget_policy_sends_queued_data_to_the_new_destination() {
		$this->enable();
		$this->queue_event_for_current_destination();
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'on_destination_change' => 'retarget', 'key' => array( 'server' => self::KEY_B ) ), 'ziplogger_save' );
		$this->assertSame( 0, ( new Queue_Store() )->held_count( Destination::current() ) );
		$this->assertStringContainsString( 'will now be sent to the new one', $this->notice_text() );
	}

	public function test_the_discard_policy_deletes_data_collected_for_the_old_destination() {
		$this->enable();
		$this->queue_event_for_current_destination();
		$this->login();
		$this->submit( 'handle_save', array( 'section' => 'connection', 'tab' => 'connection', 'on_destination_change' => 'discard', 'key' => array( 'server' => self::KEY_B ) ), 'ziplogger_save' );
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( 1, $this->meta->drop_counts()['cleared'] );
	}

	public function test_the_held_handler_retargets_or_discards() {
		$this->enable();
		$this->queue_event_for_current_destination();
		Settings::save_key( 'server', self::KEY_B );
		Destination::reset();
		$this->login();

		$this->submit( 'handle_held', array( 'choice' => 'retarget' ), 'ziplogger_held' );
		$this->assertSame( 0, ( new Queue_Store() )->held_count( Destination::current() ) );
		$this->assertStringContainsString( 'will now be sent', $this->notice_text() );

		$this->queue->clear();
		$this->enable();
		$this->queue_event_for_current_destination();
		Settings::save_key( 'server', self::KEY_B ); // A different key after queuing: the row is now held.
		Destination::reset();
		$this->assertSame( 1, ( new Queue_Store() )->held_count( Destination::current() ) );
		$this->submit( 'handle_held', array( 'choice' => 'discard' ), 'ziplogger_held' );
		$this->assertSame( 0, $this->queue_count() );
	}

	// ---------------------------------------------------------------------------------------------
	// Actions.
	// ---------------------------------------------------------------------------------------------

	public function test_the_test_action_reports_the_servers_answer_and_stays_on_its_tab() {
		$this->enable();
		$this->login();
		$this->responses = array( self::response( 202, array( 'accepted' => 1, 'rejected' => 0 ) ) );
		$r               = $this->submit( 'handle_test', array( 'tab' => 'connection' ), 'ziplogger_test' );
		$this->assertStringContainsString( 'tab=connection', $r->location );
		$this->assertStringContainsString( 'accepted the test event', $this->notice_text() );

		$this->responses = array( self::response( 401, array( 'error' => 'Invalid API key ' . self::KEY ) ) );
		$this->submit( 'handle_test', array( 'tab' => 'overview' ), 'ziplogger_test' );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $this->notices() ) );
		$this->assertSame( 'error', $this->notices()[0][0] );
	}

	public function test_the_flush_and_clear_actions() {
		$this->enable();
		$this->login();
		$this->seed( 3 );
		$this->submit( 'handle_flush', array( 'tab' => 'logs' ), 'ziplogger_flush' );
		$this->assertSame( 0, $this->queue_count() );
		$this->assertStringContainsString( 'Delivered 3', $this->notice_text() );

		$this->seed( 4 );
		$this->submit( 'handle_clear', array( 'tab' => 'logs' ), 'ziplogger_clear' );
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( 4, $this->meta->drop_counts()['cleared'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Rendering.
	// ---------------------------------------------------------------------------------------------

	public function test_every_tab_renders_and_none_reveals_a_credential() {
		Settings::save( array( 'enabled' => true ) );
		Settings::save_key( 'server', self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		Settings::save_key( 'read', self::READ_KEY );
		$this->login();
		$this->seed( 2 );

		foreach ( array_keys( Settings_Page::tabs() ) as $tab ) {
			$html = $this->render_tab( $tab );
			$this->assertStringContainsString( 'ziplogger-wrap', $html, $tab );
			foreach ( array( self::KEY, self::BROWSER_KEY, self::READ_KEY ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $html, "The $tab tab must not print a credential." );
				$this->assertStringNotContainsString( substr( $secret, 3, 20 ), $html, "The $tab tab must not print part of a credential." );
			}
		}
	}

	public function test_credentials_from_constants_are_described_never_shown() {
		Settings::$constants['ZIPLOGGER_API_KEY']       = self::KEY;
		Settings::$constants['ZIPLOGGER_BROWSER_KEY']   = self::BROWSER_KEY;
		$this->login();
		$html = $this->render_tab( 'connection' );
		$this->assertStringContainsString( 'ZIPLOGGER_API_KEY constant', $html );
		$this->assertStringContainsString( 'ZIPLOGGER_BROWSER_KEY constant', $html );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( self::BROWSER_KEY, $html );
	}

	public function test_stored_values_are_escaped_wherever_they_are_rendered() {
		$evil = '"><script>alert(1)</script>';
		update_option(
			Settings::OPTION,
			array(
				'source'       => $evil,
				'endpoint'     => $evil,
				'environment'  => $evil,
				'replay'       => array( 'block_selector' => $evil, 'exclude_paths' => $evil, 'mask_selector' => $evil ),
				'analytics'    => array( 'interaction_selectors' => $evil ),
				'tracing'      => array( 'propagate_hosts' => $evil, 'service_name' => $evil ),
			)
		);
		Settings::reset_cache();
		$this->login();
		foreach ( array_keys( Settings_Page::tabs() ) as $tab ) {
			$html = $this->render_tab( $tab );
			$this->assertStringNotContainsString( '<script>alert(1)', $html, "$tab" );
		}
	}

	public function test_notices_are_shown_once_and_escaped() {
		$this->login();
		set_transient( 'ziplogger_notices_' . get_current_user_id(), array( array( 'error', '<b>bold</b> & "quoted"' ), array( 'bogus-type', 'x' ), 'garbage' ), 60 );
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt;', $html );
		$this->assertStringContainsString( 'notice-info', $html, 'An unknown type falls back to info.' );
		$this->assertStringNotContainsString( '<b>bold</b>', $html );
		$this->assertFalse( $this->notices(), 'One-shot.' );
	}

	public function test_an_unknown_tab_falls_back_to_the_overview() {
		$this->login();
		$html = $this->render_tab( '../../evil' );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( 'Modules', $html );
	}

	public function test_every_form_control_has_an_accessible_label() {
		$this->login();
		foreach ( array( 'connection', 'logs', 'browser', 'analytics', 'replay', 'tracing', 'woocommerce', 'privacy' ) as $tab ) {
			$html = $this->render_tab( $tab );
			$dom  = new DOMDocument();
			@$dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$xp = new DOMXPath( $dom );
			foreach ( $xp->query( '//input[not(@type="hidden") and not(@type="submit")] | //select | //textarea' ) as $control ) {
				$id = $control->getAttribute( 'id' );
				$this->assertNotSame( '', $id, "A control on the $tab tab has no id" );
				$labelled = $xp->query( '//label[@for="' . $id . '"]' )->length > 0 || $control->hasAttribute( 'aria-label' );
				$this->assertTrue( $labelled, "Control #$id on the $tab tab has no label" );
			}
			foreach ( $xp->query( '//th' ) as $th ) {
				$this->assertTrue( $th->hasAttribute( 'scope' ), 'Table headers declare their scope on the ' . $tab . ' tab.' );
			}
		}
	}

	public function test_every_form_carries_a_nonce_and_a_section() {
		$this->login();
		foreach ( array( 'connection', 'logs', 'browser', 'analytics', 'replay', 'tracing', 'woocommerce', 'privacy' ) as $tab ) {
			$html = $this->render_tab( $tab );
			$this->assertStringContainsString( 'name="_wpnonce"', $html, $tab );
			$this->assertStringContainsString( 'name="section"', $html, $tab );
			$this->assertStringContainsString( 'admin-post.php', $html, $tab );
		}
	}

	public function test_each_module_tab_states_data_traffic_and_storage_effects() {
		$this->login();
		foreach ( array( 'browser', 'analytics', 'replay', 'tracing', 'woocommerce' ) as $tab ) {
			$html = $this->render_tab( $tab );
			$this->assertStringContainsString( 'Data collected', $html, $tab );
			$this->assertStringContainsString( 'Effect on traffic', $html, $tab );
			$this->assertStringContainsString( 'Effect on storage', $html, $tab );
		}
	}

	public function test_the_replay_screen_offers_no_way_to_unmask_inputs() {
		$this->login();
		$html = $this->render_tab( 'replay' );
		$this->assertStringNotContainsString( 'mask_inputs', $html );
		$this->assertStringContainsString( 'cannot be turned off', $html );
		$this->assertStringContainsString( 'Cross-origin iframes', $html );
	}

	public function test_the_privacy_screen_explains_identifiers_and_consent() {
		$this->login();
		$html = $this->render_tab( 'privacy' );
		$this->assertStringContainsString( 'ZipLoggerWP.consent.grant', $html );
		$this->assertStringContainsString( 'ziplogger_has_consent', $html );
		$this->assertStringContainsString( 'not deleted by withdrawing consent', $html );
	}

	public function test_the_overview_lists_every_module_with_its_state() {
		Settings::save( array( 'browser' => array( 'enabled' => true ) ) );
		$this->login();
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'On, but not running', $html );
		$this->assertStringContainsString( 'No browser API key is set', $html );
		foreach ( array( 'Server logs', 'Browser monitoring', 'Analytics', 'Session replay', 'Tracing', 'WooCommerce' ) as $label ) {
			$this->assertStringContainsString( $label, $html );
		}
	}

	public function test_open_zipLogger_links_to_the_verified_application_url() {
		$this->login();
		$html = $this->render_tab( 'overview' );
		$this->assertStringContainsString( 'href="https://app.ziplogger.ai/"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	public function test_assets_load_only_on_the_settings_screen() {
		$this->page->enqueue( 'index.php' );
		$this->assertFalse( wp_style_is( 'ziplogger-admin', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'ziplogger-admin', 'enqueued' ) );
		$this->page->enqueue( 'settings_page_ziplogger' );
		$this->assertTrue( wp_style_is( 'ziplogger-admin', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'ziplogger-admin', 'enqueued' ) );
		wp_dequeue_style( 'ziplogger-admin' );
		wp_dequeue_script( 'ziplogger-admin' );
	}

	public function test_the_menu_entry_needs_manage_options() {
		global $submenu;
		$this->login();
		$this->page->add_menu();
		$found = false;
		foreach ( (array) $submenu['options-general.php'] as $item ) {
			if ( 'ziplogger' === $item[2] ) {
				$found = true;
				$this->assertSame( 'manage_options', $item[1] );
			}
		}
		$this->assertTrue( $found );
	}

	public function test_the_stylesheet_uses_logical_properties_so_rtl_mirrors_correctly() {
		$css = file_get_contents( ZIPLOGGER_DIR . 'assets/admin.css' );
		$this->assertDoesNotMatchRegularExpression( '/(margin|padding)-(left|right)\s*:|(^|[^-])float\s*:\s*(left|right)|text-align\s*:\s*(left|right)|border-(left|right)\s*:|(^|[\s;{])(left|right)\s*:/m', $css );
		$this->assertStringContainsString( 'inline-start', $css );
	}

	// ---------------------------------------------------------------------------------------------
	// Diagnostics.
	// ---------------------------------------------------------------------------------------------

	public function test_the_diagnostics_report_contains_no_credentials_or_personal_data() {
		Settings::save( array( 'enabled' => true, 'analytics' => array( 'interaction_selectors' => '.secret-selector' ), 'replay' => array( 'block_selector' => '.private-block' ) ) );
		Settings::save_key( 'server', self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		Settings::save_key( 'read', self::READ_KEY );
		update_option( Secrets::OPTION, str_repeat( 'ab', 32 ) );
		$json = wp_json_encode( Diagnostics::report() );

		foreach ( array( self::KEY, self::BROWSER_KEY, self::READ_KEY, str_repeat( 'ab', 32 ), 'read_only_key', 'browser_public' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		$report = json_decode( $json, true );
		$this->assertSame( 'saved', $report['key_sources']['server'] );
		$this->assertSame( 'saved', $report['key_sources']['browser'] );
		$this->assertArrayHasKey( 'modules', $report );
		$this->assertArrayHasKey( 'by_signal', $report['queue'] );
		$this->assertTrue( $report['credentials'][0]['ok'] );
		$this->assertStringNotContainsString( 'secret-selector', $json, 'Site-specific selectors are left out of the report.' );
		$this->assertSame( ZIPLOGGER_VERSION, $report['plugin']['version'] );
	}

	public function test_diagnostics_flags_a_reused_key() {
		Settings::save_key( 'server', self::KEY );
		Settings::$constants['ZIPLOGGER_BROWSER_KEY'] = self::KEY;
		$report = Diagnostics::report();
		$this->assertFalse( $report['credentials'][0]['ok'] );
	}
}
