<?php
/**
 * Destination safety (queued data never follows a changed key/endpoint to another workspace),
 * the three-credential model, module gating, pseudonyms and consent.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Destination;
use ZipLogger\WordPress\Health;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Queue_Writer;
use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Schema;
use ZipLogger\WordPress\Secrets;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Signal;
use ZipLogger\WordPress\Worker;

class Test_Destination_Credentials extends ZL_TestCase {

	const KEY_B = 'zk_second_workspace_key_0123456789';

	public function set_up() {
		parent::set_up();
		Secrets::reset();
		Destination::reset();
		Consent::reset();
	}

	public function tear_down() {
		unset( $_COOKIE[ Consent::COOKIE ] );
		Consent::reset();
		remove_all_filters( 'ziplogger_has_consent' );
		remove_all_filters( 'ziplogger_consent_state' );
		parent::tear_down();
	}

	private function log_event( $message ) {
		$r = new Recorder();
		$r->record( 'developer', 'error', $message );
		$r->flush();
	}

	private function rows_by_destination() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT destination, COUNT(*) AS c FROM ' . Schema::queue_table() . ' GROUP BY destination', OBJECT_K ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', wp_list_pluck( $rows, 'c' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Destination.
	// ---------------------------------------------------------------------------------------------

	public function test_the_destination_depends_on_the_key_and_the_endpoint_and_nothing_else() {
		$this->assertSame( '', Destination::current(), 'No key, no destination.' );
		$this->enable();
		$a = Destination::current();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a );
		$this->assertSame( $a, Destination::current() );

		Settings::save_api_key( self::KEY_B );
		$b = Destination::current();
		$this->assertNotSame( $a, $b, 'A different key is a different destination.' );

		Settings::save_api_key( self::KEY );
		$this->assertSame( $a, Destination::current(), 'Restoring the key restores the destination.' );

		Settings::save( array( 'enabled' => true, 'endpoint' => 'https://logs.example.org' ) );
		$this->assertNotSame( $a, Destination::current(), 'A different endpoint is a different destination.' );
	}

	public function test_the_fingerprint_reveals_nothing_about_the_key() {
		$this->enable();
		$this->assertStringNotContainsString( substr( self::KEY, 3, 8 ), Destination::current() );
	}

	public function test_new_rows_are_bound_to_the_destination_they_were_collected_for() {
		$this->enable();
		$this->log_event( 'for workspace A' );
		$this->assertSame( array( Destination::current() => 1 ), $this->rows_by_destination() );
	}

	public function test_rows_collected_before_any_key_exists_attach_to_the_first_destination() {
		Settings::save( array( 'enabled' => true ) ); // No key yet.
		$this->log_event( 'collected before the key' );
		$this->assertSame( array( '' => 1 ), $this->rows_by_destination() );

		Settings::save_api_key( self::KEY );
		Destination::reset();
		$report = ( new Worker() )->run();
		$this->assertSame( 1, $report['events_sent'], 'Unbound rows are sent to the first configured destination.' );
		$this->assertCount( 1, $this->requests );
	}

	public function test_changing_the_key_holds_queued_data_instead_of_sending_it_to_the_new_workspace() {
		$this->enable();
		$this->log_event( 'collected for workspace A' );
		$this->assertCount( 0, $this->requests );

		Settings::save_api_key( self::KEY_B ); // A different key: possibly a different workspace.
		Destination::reset();
		$this->log_event( 'collected for workspace B' );
		$this->assertCount( 2, $this->rows_by_destination() );

		$report = ( new Worker() )->run();
		$this->assertSame( 1, $report['events_sent'] );
		$this->assertSame( 1, $report['held'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( self::KEY_B, $this->requests[0]['args']['headers']['X-Api-Key'] );
		$this->assertStringContainsString( 'workspace B', $this->requests[0]['args']['body'] );
		$this->assertStringNotContainsString( 'workspace A', $this->requests[0]['args']['body'], 'Data collected for another destination must never be sent with the new key.' );

		// Nothing is ever sent for the held row, however often the worker runs.
		Clock::advance( 5000 );
		( new Worker() )->run( array( 'force' => true ) );
		foreach ( $this->requests as $req ) {
			$this->assertStringNotContainsString( 'workspace A', $req['args']['body'] );
		}
		$this->assertSame( 1, $this->queue_count() );
	}

	public function test_changing_the_endpoint_holds_queued_data_too() {
		$this->enable();
		$this->log_event( 'for the default endpoint' );
		Settings::save( array( 'enabled' => true, 'endpoint' => 'https://logs.example.org' ) );
		Destination::reset();
		$report = ( new Worker() )->run();
		$this->assertSame( 0, $report['events_sent'] );
		$this->assertSame( 1, $report['held'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_held_data_is_visible_in_health_and_stops_a_healthy_appearance() {
		$this->enable();
		$this->log_event( 'a' );
		Settings::save_api_key( self::KEY_B );
		Destination::reset();
		$h = Health::snapshot();
		$this->assertSame( 1, $h['held'] );
		$this->assertSame( 'held', $h['state'] );
	}

	public function test_the_administrator_can_send_held_data_to_the_new_destination() {
		$this->enable();
		$this->log_event( 'rotated key, same workspace' );
		Settings::save_api_key( self::KEY_B );
		Destination::reset();

		$queue = new \ZipLogger\WordPress\Queue_Store();
		$this->assertSame( 1, $queue->retarget_held( Destination::current() ) );
		$report = ( new Worker() )->run();
		$this->assertSame( 1, $report['events_sent'] );
		$this->assertSame( self::KEY_B, $this->requests[0]['args']['headers']['X-Api-Key'] );
	}

	public function test_the_administrator_can_discard_held_data() {
		$this->enable();
		$this->log_event( 'wrong workspace' );
		Settings::save_api_key( self::KEY_B );
		Destination::reset();
		$this->assertSame( 1, ( new \ZipLogger\WordPress\Queue_Store() )->discard_held( Destination::current() ) );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_held_data_still_expires() {
		$this->enable();
		$this->log_event( 'will expire' );
		Settings::save_api_key( self::KEY_B );
		Destination::reset();
		Clock::advance( (int) Limits::get( 'retention_seconds' ) + 10 );
		( new Worker() )->run();
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( 1, $this->meta->drop_counts()['expired'] );
	}

	public function test_every_signal_is_held_not_just_logs() {
		$this->enable();
		$writer = new Queue_Writer();
		$doc    = wp_json_encode( array( 'name' => 'x', 'userId' => 'u', 'insertId' => 'i' ) );
		$writer->write( Signal::EVENTS, array( array( 'payload' => $doc, 'size' => strlen( $doc ), 'severity' => 2, 'fingerprint' => '' ) ) );
		Settings::save_api_key( self::KEY_B );
		Destination::reset();
		( new Worker() )->run();
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 1, $this->queue_count() );
	}

	public function test_version_1_rows_are_adopted_by_the_current_destination_on_upgrade() {
		$this->enable();
		$this->seed( 2 ); // Unbound rows, as a version 1 queue would hold.
		update_option( Schema::VERSION_OPTION, 1 );
		Schema::maybe_upgrade();
		$this->assertSame( array( Destination::current() => 2 ), $this->rows_by_destination() );
		$this->assertSame( ZIPLOGGER_DB_VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Credentials.
	// ---------------------------------------------------------------------------------------------

	public function test_three_credentials_are_stored_apart_and_not_autoloaded() {
		$this->assertSame( '', Settings::save_key( 'server', self::KEY ) );
		$this->assertSame( '', Settings::save_key( 'browser', 'zk_browser_public_key_012345678901' ) );
		$this->assertSame( '', Settings::save_key( 'read', 'zk_read_only_key_0123456789012345' ) );
		$this->assertSame( self::KEY, Settings::api_key() );
		$this->assertSame( 'zk_browser_public_key_012345678901', Settings::browser_key() );
		$this->assertSame( 'zk_read_only_key_0123456789012345', Settings::read_key() );

		$auto = wp_load_alloptions();
		foreach ( array( 'ziplogger_api_key', 'ziplogger_browser_key', 'ziplogger_read_key' ) as $option ) {
			$this->assertArrayNotHasKey( $option, $auto );
		}
	}

	public function test_a_key_cannot_be_reused_for_another_purpose() {
		Settings::save_key( 'server', self::KEY );
		$error = Settings::save_key( 'browser', self::KEY );
		$this->assertStringContainsString( 'already used as the server key', $error );
		$this->assertSame( '', Settings::browser_key() );

		$this->assertStringContainsString( 'already used', Settings::save_key( 'read', self::KEY ) );
		$this->assertStringContainsString( 'Unknown credential', Settings::save_key( 'admin', 'zk_whatever_key_0123456789' ) );
	}

	public function test_a_duplicate_configured_outside_the_form_never_reaches_the_browser() {
		Settings::save_key( 'server', self::KEY );
		Settings::$constants['ZIPLOGGER_BROWSER_KEY'] = self::KEY; // wp-config.php reused the server key.
		$this->assertSame( '', Settings::browser_key(), 'The public key must never equal the private one.' );
		$this->assertNotSame( '', Settings::key_problem( 'browser' ) );
		$this->assertSame( self::KEY, Settings::api_key(), 'The server key itself is unaffected.' );

		Settings::$constants['ZIPLOGGER_BROWSER_KEY'] = 'zk_distinct_browser_key_012345678';
		Settings::$constants['ZIPLOGGER_READ_KEY']    = 'zk_distinct_browser_key_012345678';
		$this->assertSame( '', Settings::read_key(), 'The read key must differ from the browser key.' );
	}

	public function test_credential_precedence_and_sources_apply_to_every_kind() {
		Settings::save_key( 'browser', 'zk_saved_browser_key_012345678' );
		$this->assertSame( 'saved', Settings::key_source( 'browser' ) );
		putenv( 'ZIPLOGGER_BROWSER_KEY=zk_env_browser_key_0123456789' );
		$this->assertSame( 'environment', Settings::key_source( 'browser' ) );
		Settings::$constants['ZIPLOGGER_BROWSER_KEY'] = 'zk_const_browser_key_012345678';
		$this->assertSame( 'constant', Settings::key_source( 'browser' ) );
		$this->assertSame( 'zk_const_browser_key_012345678', Settings::browser_key() );
		putenv( 'ZIPLOGGER_BROWSER_KEY' );
	}

	public function test_hints_are_masked_for_every_kind() {
		Settings::save_key( 'read', 'zk_read_only_key_0123456789012345' );
		$hint = Settings::key_hint( 'read' );
		$this->assertStringStartsWith( 'zk_', $hint );
		$this->assertStringNotContainsString( 'read_only_key', $hint );
		$this->assertStringEndsWith( '2345', $hint );
	}

	public function test_removing_one_key_leaves_the_others() {
		Settings::save_key( 'server', self::KEY );
		Settings::save_key( 'browser', 'zk_browser_public_key_012345678901' );
		Settings::remove_key( 'browser' );
		$this->assertSame( '', Settings::browser_key() );
		$this->assertSame( self::KEY, Settings::api_key() );
	}

	// ---------------------------------------------------------------------------------------------
	// Modules.
	// ---------------------------------------------------------------------------------------------

	public function test_every_module_is_off_by_default_and_effective_only_with_its_prerequisites() {
		foreach ( array( 'browser', 'analytics', 'replay', 'tracing', 'woocommerce' ) as $module ) {
			$this->assertFalse( Modules::enabled( $module ), "$module must be off by default" );
			$this->assertFalse( Modules::effective( $module ) );
		}
		$this->assertFalse( Modules::frontend_needed() );

		Settings::save( array( 'browser' => array( 'enabled' => true ) ) );
		$this->assertTrue( Modules::enabled( 'browser' ) );
		$this->assertFalse( Modules::effective( 'browser' ), 'Switched on but no browser key: not running.' );
		$this->assertNotEmpty( Modules::blockers( 'browser' ) );

		Settings::save_key( 'browser', 'zk_browser_public_key_012345678901' );
		Settings::reset_cache();
		$this->assertTrue( Modules::effective( 'browser' ) );
		$this->assertTrue( Modules::frontend_needed() );
		$this->assertFalse( Modules::effective( 'replay' ), 'One module never switches another on.' );
	}

	public function test_woocommerce_is_never_effective_without_woocommerce() {
		if ( Modules::woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is loaded in this environment.' );
		}
		Settings::save_key( 'server', self::KEY );
		Settings::save( array( 'woocommerce' => array( 'enabled' => true ) ) );
		$this->assertFalse( Modules::effective( 'woocommerce' ) );
		$this->assertStringContainsString( 'WooCommerce is not active', implode( ' ', Modules::blockers( 'woocommerce' ) ) );
	}

	public function test_the_summary_distinguishes_off_blocked_and_on() {
		Settings::save( array( 'browser' => array( 'enabled' => true ), 'tracing' => array( 'enabled' => true ) ) );
		Settings::save_key( 'server', self::KEY );
		Settings::reset_cache();
		$summary = Modules::summary();
		$this->assertSame( 'blocked', $summary['browser']['state'] );
		$this->assertSame( 'on', $summary['tracing']['state'] );
		$this->assertSame( 'off', $summary['replay']['state'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Pseudonyms.
	// ---------------------------------------------------------------------------------------------

	public function test_pseudonyms_are_stable_opaque_and_per_kind() {
		$a = Secrets::pseudonym( 'wpu', 42 );
		$this->assertMatchesRegularExpression( '/^wpu_[0-9a-f]{24}$/', $a );
		$this->assertSame( $a, Secrets::pseudonym( 'wpu', 42 ), 'The same user always gets the same id.' );
		$this->assertNotSame( $a, Secrets::pseudonym( 'wpu', 43 ) );
		$this->assertNotSame( substr( $a, 4 ), substr( Secrets::pseudonym( 'ord', 42 ), 4 ), 'Different kinds of id never collide.' );
		$this->assertStringNotContainsString( '42', substr( $a, 0, 4 ) . 'x' );
	}

	public function test_the_pseudonym_secret_is_per_site_persistent_and_replaceable() {
		$first = Secrets::pseudonym( 'wpu', 7 );
		$this->assertGreaterThanOrEqual( 32, strlen( get_option( Secrets::OPTION ) ) );
		$this->assertArrayNotHasKey( Secrets::OPTION, wp_load_alloptions(), 'The secret is not autoloaded.' );

		Secrets::reset();
		$this->assertSame( $first, Secrets::pseudonym( 'wpu', 7 ), 'It survives across requests.' );

		update_option( Secrets::OPTION, str_repeat( 'f', 64 ) );
		Secrets::reset();
		$this->assertNotSame( $first, Secrets::pseudonym( 'wpu', 7 ), 'Replacing the secret is a deliberate reset of every derived id.' );

		Settings::$constants['ZIPLOGGER_SECRET'] = 'a-constant-secret-from-wp-config';
		Secrets::reset();
		$this->assertNotSame( $first, Secrets::pseudonym( 'wpu', 7 ) );
	}

	public function test_the_pseudonym_does_not_depend_on_the_wordpress_salts() {
		$before = Secrets::pseudonym( 'wpu', 9 );
		add_filter( 'salt', static function () { return 'rotated'; } );
		Secrets::reset();
		$this->assertSame( $before, Secrets::pseudonym( 'wpu', 9 ), 'Rotating WordPress salts must not silently re-identify everyone.' );
		remove_all_filters( 'salt' );
	}

	// ---------------------------------------------------------------------------------------------
	// Consent.
	// ---------------------------------------------------------------------------------------------

	public function test_defaults_require_consent_for_identifying_features_only() {
		$this->assertSame( 'none', Consent::policy( 'browser' ) );
		$this->assertSame( 'required', Consent::policy( 'analytics' ) );
		$this->assertSame( 'required', Consent::policy( 'replay' ) );
		$this->assertSame( 'none', Consent::policy( 'commerce' ) );
		$this->assertTrue( Consent::allows( 'browser' ) );
		$this->assertFalse( Consent::allows( 'analytics' ), 'No evidence is not consent.' );
		$this->assertSame( 'unknown', Consent::state( 'analytics' ) );
	}

	public function test_a_filter_can_grant_or_deny() {
		add_filter( 'ziplogger_has_consent', static function ( $c, $category ) { return 'analytics' === $category ? true : ( 'replay' === $category ? false : $c ); }, 10, 2 );
		Consent::reset();
		$this->assertTrue( Consent::allows( 'analytics' ) );
		$this->assertFalse( Consent::allows( 'replay' ) );
		$this->assertSame( 'denied', Consent::state( 'replay' ) );
	}

	public function test_the_plugins_own_cookie_is_evidence() {
		$_COOKIE[ Consent::COOKIE ] = 'browser,analytics';
		Consent::reset();
		$this->assertTrue( Consent::allows( 'analytics' ) );
		$this->assertFalse( Consent::allows( 'replay' ), 'A category not in the cookie is denied.' );
		$this->assertSame( 'denied', Consent::state( 'replay' ) );

		$_COOKIE[ Consent::COOKIE ] = '';
		Consent::reset();
		$this->assertFalse( Consent::allows( 'analytics' ), 'An empty cookie means the visitor granted nothing.' );

		$_COOKIE[ Consent::COOKIE ] = 'analytics;DROP TABLE';
		Consent::reset();
		$this->assertSame( 'unknown', Consent::state( 'analytics' ), 'A malformed cookie is ignored.' );
	}

	public function test_a_policy_of_none_needs_no_evidence_and_ignores_a_denial() {
		Settings::save( array( 'consent' => array( 'analytics' => 'none' ) ) );
		$_COOKIE[ Consent::COOKIE ] = '';
		Consent::reset();
		$this->assertTrue( Consent::allows( 'analytics' ) );
	}

	public function test_filter_evidence_wins_over_the_cookie() {
		$_COOKIE[ Consent::COOKIE ] = 'analytics';
		add_filter( 'ziplogger_has_consent', '__return_false' );
		Consent::reset();
		$this->assertFalse( Consent::allows( 'analytics' ) );
	}

	public function test_the_browser_receives_policy_but_no_per_visitor_data() {
		$_COOKIE[ Consent::COOKIE ] = 'analytics';
		Consent::reset();
		$json = wp_json_encode( Consent::for_browser() );
		$this->assertStringContainsString( '"analytics":"required"', $json );
		$this->assertStringNotContainsString( 'granted', $json, 'What one visitor consented to must not be embedded in a page others may receive.' );
		$this->assertSame( Consent::COOKIE, Consent::for_browser()['cookie'] );
	}
}
