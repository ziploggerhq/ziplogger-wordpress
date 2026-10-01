<?php
/**
 * Activation, deactivation, uninstall policy, scheduler and health, and multisite behaviour.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Health;
use ZipLogger\WordPress\Lifecycle;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Scheduler;
use ZipLogger\WordPress\Schema;
use ZipLogger\WordPress\Settings;

class Test_Lifecycle extends ZL_TestCase {

	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( Scheduler::HOOK_WATCHDOG );
	}

	// ---------------------------------------------------------------------------------------------
	// Activation / deactivation.
	// ---------------------------------------------------------------------------------------------

	public function test_activation_creates_the_schema_but_starts_nothing() {
		Lifecycle::activate( false );

		$this->assertTrue( Schema::tables_exist() );
		$this->assertSame( ZIPLOGGER_DB_VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( Settings::is_enabled(), 'Activation must not switch collection on.' );
		$this->assertSame( '', Settings::api_key() );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_WATCHDOG ), 'Hourly maintenance applies retention even while collection is off.' );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'No delivery is scheduled.' );
		$this->assertCount( 0, $this->requests, 'Activation makes no network request.' );
	}

	public function test_activation_is_repeatable() {
		Lifecycle::activate( false );
		Lifecycle::activate( false );
		$this->assertTrue( Schema::tables_exist() );
	}

	public function test_deactivation_stops_scheduling_but_keeps_settings_and_queued_events() {
		$this->enable( array( 'source' => 'kept-label' ) );
		$this->seed( 3 );
		Lifecycle::activate( false );
		Scheduler::ensure_scheduled( Clock::time() + 30 );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ) );

		Lifecycle::deactivate();

		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_WATCHDOG ) );
		$this->assertSame( 3, $this->queue_count(), 'Queued events are preserved.' );
		$this->assertSame( 'kept-label', Settings::get()['source'] );
		$this->assertNotSame( '', Settings::api_key(), 'The key is preserved too.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Network-wide activation (multisite).
	// ---------------------------------------------------------------------------------------------

	private function multisite_only() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (run with WP_MULTISITE=1).' );
		}
	}

	/**
	 * What a site has after set-up: its own tables and its own maintenance event.
	 *
	 * @param int $blog_id Site.
	 * @return array{tables:bool,watchdog:bool}
	 */
	private function site_state( $blog_id ) {
		switch_to_blog( $blog_id );
		$state = array(
			'tables'   => Schema::tables_exist(),
			'watchdog' => false !== wp_next_scheduled( Scheduler::HOOK_WATCHDOG ),
		);
		restore_current_blog();
		return $state;
	}

	private function set_network_active( $active ) {
		update_site_option( 'active_sitewide_plugins', $active ? array( plugin_basename( ZIPLOGGER_FILE ) => time() ) : array() );
	}

	public function test_network_activation_sets_up_every_site_and_prints_nothing() {
		$this->multisite_only();
		$sites = array( self::factory()->blog->create(), self::factory()->blog->create() );
		foreach ( $sites as $id ) {
			$this->assertFalse( $this->site_state( $id )['tables'], 'A new site has no ZipLogger tables yet.' );
		}

		ob_start();
		Lifecycle::activate( true );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Activation prints nothing.' );
		foreach ( $sites as $id ) {
			$this->assertSame( array( 'tables' => true, 'watchdog' => true ), $this->site_state( $id ), 'Site ' . $id );
		}
	}

	public function test_network_activation_keeps_each_sites_settings_apart() {
		$this->multisite_only();
		$a = self::factory()->blog->create();
		$b = self::factory()->blog->create();
		Lifecycle::activate( true );

		switch_to_blog( $a );
		Settings::save( array( 'source' => 'site-a' ) );
		restore_current_blog();
		switch_to_blog( $b );
		Settings::reset_cache();
		$this->assertNotSame( 'site-a', Settings::get()['source'], 'Another site does not see site a settings.' );
		restore_current_blog();
		Settings::reset_cache();
	}

	public function test_a_site_created_after_network_activation_is_set_up_when_it_is_created() {
		$this->multisite_only();
		$this->set_network_active( true );
		$id = self::factory()->blog->create();
		$this->assertSame( array( 'tables' => true, 'watchdog' => true ), $this->site_state( $id ) );
	}

	public function test_a_site_created_while_the_plugin_is_active_on_other_sites_only_is_left_alone() {
		$this->multisite_only();
		$this->set_network_active( false );
		$id = self::factory()->blog->create();
		$this->assertSame( array( 'tables' => false, 'watchdog' => false ), $this->site_state( $id ) );
	}

	public function test_network_deactivation_removes_the_schedule_of_every_site_and_keeps_the_data() {
		$this->multisite_only();
		$sites = array( self::factory()->blog->create(), self::factory()->blog->create() );
		Lifecycle::activate( true );
		Lifecycle::deactivate( true );
		foreach ( $sites as $id ) {
			$state = $this->site_state( $id );
			$this->assertFalse( $state['watchdog'], 'Site ' . $id . ' has no scheduled event.' );
			$this->assertTrue( $state['tables'], 'Deactivating keeps the data.' );
		}
	}

	public function test_the_first_request_of_a_site_the_activation_did_not_reach_sets_it_up() {
		$this->multisite_only();
		$id = self::factory()->blog->create();
		switch_to_blog( $id );
		$this->assertTrue( Schema::maybe_upgrade(), 'Tables are created on the first call.' );
		$this->assertFalse( Schema::maybe_upgrade(), 'And not again.' );
		$this->assertTrue( Schema::tables_exist() );
		restore_current_blog();
	}

	public function test_activating_a_single_site_does_not_touch_the_others() {
		$this->multisite_only();
		$other = self::factory()->blog->create();
		Lifecycle::activate( false );
		$this->assertSame( array( 'tables' => false, 'watchdog' => false ), $this->site_state( $other ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Uninstall policy.
	// ---------------------------------------------------------------------------------------------

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ziplogger/ziplogger.php' );
		}
		include ZIPLOGGER_DIR . 'uninstall.php';
	}

	public function test_uninstall_keeps_data_by_default_but_always_removes_schedules() {
		$this->enable();
		$this->seed( 2 );
		Scheduler::ensure_scheduled( Clock::time() + 30 );
		Scheduler::ensure_watchdog();

		$this->run_uninstall();

		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'Scheduled events would call code that no longer exists.' );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_WATCHDOG ) );
		$this->assertTrue( Schema::tables_exist(), 'The default policy keeps the data.' );
		$this->assertSame( 2, $this->queue_count() );
		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertNotSame( '', Settings::api_key() );
	}

	public function test_uninstall_with_the_delete_flag_removes_tables_options_and_schedules() {
		$this->enable( array( 'delete_on_uninstall' => true ) );
		$this->seed( 2 );
		Settings::save_key( 'browser', 'zk_browser_public_key_012345678901' );
		Settings::save_key( 'read', 'zk_read_only_key_0123456789012345' );
		\ZipLogger\WordPress\Secrets::site_secret();
		set_transient( 'ziplogger_notices_1', array( array( 'success', 'x' ) ), 60 );
		Scheduler::ensure_scheduled( Clock::time() + 30 );

		// The WordPress test case turns CREATE/DROP TABLE into TEMPORARY variants; a real DROP is needed here.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			$this->run_uninstall();

			$this->assertFalse( Schema::tables_exist() );
			$this->assertFalse( get_option( Settings::OPTION, false ) );
			$this->assertFalse( get_option( Settings::KEY_OPTION, false ) );
			foreach ( array( 'ziplogger_browser_key', 'ziplogger_read_key', 'ziplogger_secret' ) as $option ) {
				$this->assertFalse( get_option( $option, false ), "$option is removed too" );
			}
			$this->assertFalse( get_option( Schema::VERSION_OPTION, false ) );
			$this->assertFalse( get_transient( 'ziplogger_notices_1' ) );
			$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ) );
		} finally {
			Schema::install(); // Later tests need the tables.
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	public function test_uninstall_removes_only_this_plugins_options() {
		$this->enable( array( 'delete_on_uninstall' => true ) );
		update_option( 'some_other_plugin_option', 'keep me' );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			$this->run_uninstall();
			$this->assertSame( 'keep me', get_option( 'some_other_plugin_option' ) );
		} finally {
			Schema::install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Scheduler.
	// ---------------------------------------------------------------------------------------------

	public function test_an_earlier_run_replaces_a_later_one_and_a_later_request_does_not() {
		$now = Clock::time();
		Scheduler::ensure_scheduled( $now + 900 );
		$this->assertSame( $now + 900, wp_next_scheduled( Scheduler::HOOK_DELIVER ) );

		Scheduler::ensure_scheduled( $now + 30 );
		$this->assertSame( $now + 30, wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'WordPress would silently ignore a new event within ten minutes of an existing one.' );

		Scheduler::ensure_scheduled( $now + 600 );
		$this->assertSame( $now + 30, wp_next_scheduled( Scheduler::HOOK_DELIVER ) );
		$this->assertCount( 1, _get_cron_array()[ $now + 30 ] ?? array(), 'Exactly one delivery event.' );
	}

	public function test_a_past_time_is_clamped_to_now() {
		Scheduler::ensure_scheduled( 1 );
		$this->assertGreaterThanOrEqual( Clock::time(), wp_next_scheduled( Scheduler::HOOK_DELIVER ) );
	}

	public function test_wp_cron_disabled_is_reported() {
		Settings::$constants['DISABLE_WP_CRON'] = false;
		$this->assertFalse( Scheduler::cron_disabled() );
		Settings::$constants['DISABLE_WP_CRON'] = true;
		$this->assertTrue( Scheduler::cron_disabled() );
	}

	public function test_the_watchdog_rearms_lost_delivery_and_applies_retention() {
		$this->enable();
		$this->seed( 2 );
		Clock::advance( (int) Limits::get( 'retention_seconds' ) + 5 );
		$this->seed( 1, 'fresh' );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ) );

		\ZipLogger\WordPress\Plugin::instance()->run_watchdog();

		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'The single delivery event was lost; the watchdog re-arms it.' );
		$this->assertSame( 2, $this->meta->drop_counts()['expired'] );
		$this->assertSame( 1, $this->queue_count() );
	}

	public function test_the_watchdog_does_nothing_when_collection_is_off() {
		$this->seed( 1 );
		\ZipLogger\WordPress\Plugin::instance()->run_watchdog();
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ) );
	}

	public function test_the_cron_callback_delivers() {
		$this->enable();
		$this->seed( 2 );
		\ZipLogger\WordPress\Plugin::instance()->run_delivery();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_an_internal_error_in_the_cron_callback_is_contained_and_retried_later() {
		$this->enable();
		$this->seed( 1 );
		// A third-party option filter that throws: the worker fails, and so would anything that
		// re-read the key while reporting the failure.
		add_filter(
			'pre_option_' . Settings::KEY_OPTION,
			static function () {
				throw new Error( 'option filter exploded near ' . ZL_TestCase::KEY );
			}
		);
		\ZipLogger\WordPress\Plugin::instance()->run_delivery();
		remove_all_filters( 'pre_option_' . Settings::KEY_OPTION );

		$last = $this->meta->get_json( Meta_Store::LAST_ERROR );
		$this->assertSame( 'internal', $last['outcome'] );
		$this->assertStringContainsString( 'option filter exploded', $last['message'] );
		$this->assertStringNotContainsString( self::KEY, $last['message'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'A retry is scheduled.' );
		$this->assertSame( 1, $this->queue_count(), 'The queue is untouched.' );
	}

	public function test_a_throwing_limits_filter_falls_back_to_defaults() {
		add_filter(
			'ziplogger_limits',
			static function () {
				throw new Error( 'limits exploded' );
			}
		);
		Limits::reset();
		$this->assertSame( 5000, Limits::get( 'queue_max_events' ) );
		remove_all_filters( 'ziplogger_limits' );
	}

	// ---------------------------------------------------------------------------------------------
	// Health.
	// ---------------------------------------------------------------------------------------------

	public function test_health_states() {
		$this->assertSame( 'off', Health::snapshot()['state'] );

		Settings::save( array( 'enabled' => true ) );
		$this->assertSame( 'unconfigured', Health::snapshot()['state'] );

		Settings::save_api_key( self::KEY );
		$this->assertSame( 'ready', Health::snapshot()['state'] );

		$this->seed( 2 );
		$this->assertSame( 'waiting', Health::snapshot()['state'] );

		$this->meta->set_num( Meta_Store::LAST_SUCCESS, Clock::time() );
		$this->queue->clear();
		$this->assertSame( 'healthy', Health::snapshot()['state'] );

		$this->meta->set_str( Meta_Store::BLOCKED, 'auth' );
		$this->meta->set_num( Meta_Store::GATE_UNTIL, Clock::time() + 900 );
		$this->assertSame( 'blocked', Health::snapshot()['state'] );

		$this->meta->clear_gate();
		$this->meta->set_num( Meta_Store::FAILURES, 2 );
		$this->meta->set_num( Meta_Store::GATE_UNTIL, Clock::time() + 300 );
		$this->assertSame( 'failing', Health::snapshot()['state'] );
	}

	public function test_an_overdue_worker_is_reported() {
		$this->enable();
		$this->seed( 3 );
		$this->meta->set_num( Meta_Store::WORKER_LAST_RUN, Clock::time() );
		$this->assertFalse( Health::snapshot()['overdue'] );

		// Events became due one delivery delay after they were queued.
		Clock::advance( (int) Limits::get( 'first_delivery_delay' ) + (int) Limits::get( 'overdue_after' ) - 10 );
		$this->assertFalse( Health::snapshot()['overdue'], 'A quiet site is not overdue yet.' );

		Clock::advance( 20 );
		$h = Health::snapshot();
		$this->assertTrue( $h['overdue'] );
		$this->assertSame( 'overdue', $h['state'] );
		$this->assertGreaterThan( 0, $h['overdue_by'] );

		// A worker run brings it back to normal even though events remain.
		$this->meta->set_num( Meta_Store::WORKER_LAST_RUN, Clock::time() );
		$this->assertFalse( Health::snapshot()['overdue'] );
	}

	public function test_a_batch_waiting_for_a_retry_is_not_overdue_until_its_timer_ends() {
		$this->enable();
		$this->seed( 2 );
		$batch = $this->queue->claim_next();
		$this->queue->release( $batch, Clock::time() + 1800 );
		$this->meta->set_num( Meta_Store::WORKER_LAST_RUN, Clock::time() );

		Clock::advance( 1800 + 300 );
		$this->assertFalse( Health::snapshot()['overdue'] );
		Clock::advance( 400 );
		$this->assertTrue( Health::snapshot()['overdue'] );
	}

	public function test_health_exposes_counts_and_dropped_breakdown() {
		$this->enable();
		$this->seed( 4 );
		$this->meta->dropped( 'overflow', 3 );
		$this->meta->dropped( 'expired', 2 );
		$this->meta->incr( Meta_Store::SUPPRESSED, 7 );
		$h = Health::snapshot();
		$this->assertSame( 4, $h['pending'] );
		$this->assertSame( 5, $h['dropped_total'] );
		$this->assertSame( 3, $h['drops']['overflow'] );
		$this->assertSame( 7, $h['suppressed'] );
		$this->assertSame( 'saved', $h['key_source'] );
		$this->assertSame( 'https://app.ziplogger.ai', $h['endpoint'] );
	}

	public function test_meta_counters_are_atomic_upserts() {
		$this->meta->incr( 'sent_events', 5 );
		$this->meta->incr( 'sent_events', 7 );
		$this->meta->incr( 'sent_events', 0 );
		$this->meta->incr( 'sent_events', -3 );
		$this->assertSame( 12, $this->meta->get_num( 'sent_events' ) );
		$this->assertSame( 0, $this->meta->get_num( 'never_set' ) );
		$this->meta->set_json( 'j', array( 'a' => 1 ) );
		$this->assertSame( array( 'a' => 1 ), $this->meta->get_json( 'j' ) );
		$this->assertSame( array(), $this->meta->get_json( 'missing' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Multisite: settings, queues and lifecycle are isolated per site.
	// ---------------------------------------------------------------------------------------------

	public function test_queues_and_settings_are_isolated_per_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (run with WP_MULTISITE=1).' );
		}
		$this->enable( array( 'source' => 'site-one' ) );
		$this->seed( 2, 'one' );

		$blog_two = self::factory()->blog->create();
		switch_to_blog( $blog_two );
		try {
			Schema::install();
			$this->assertFalse( Settings::is_enabled(), 'The second site has its own (default) settings.' );
			$this->assertSame( '', Settings::api_key(), 'Keys are per site.' );
			$this->assertSame( 0, ( new Queue_Store() )->stats()['count'], 'The second site has its own empty queue.' );
			$this->assertNotSame( Schema::queue_table(), $GLOBALS['wpdb']->base_prefix . 'ziplogger_queue' );

			Settings::save( array( 'enabled' => true, 'source' => 'site-two' ) );
			( new Queue_Store() )->insert_many( array( array( 'payload' => '{"message":"two"}', 'size' => 17, 'severity' => 2, 'fingerprint' => '', 'created_at' => Clock::time() ) ) );
			$this->assertSame( 1, ( new Queue_Store() )->stats()['count'] );
			$this->assertSame( 'site-two', Settings::get()['source'] );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( 2, $this->queue_count(), 'Site one is untouched by site two.' );
		$this->assertSame( 'site-one', Settings::get()['source'], 'The settings cache follows the blog switch.' );
	}

	public function test_events_recorded_on_one_site_land_in_that_sites_queue_even_if_flushed_after_a_switch() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (run with WP_MULTISITE=1).' );
		}
		$this->enable();
		$recorder = new Recorder();
		$recorder->record( 'developer', 'error', 'recorded on the main site' );

		$blog_two = self::factory()->blog->create();
		switch_to_blog( $blog_two );
		try {
			Schema::install();
			$recorder->flush(); // A shutdown flush after code switched blogs.
			$this->assertSame( 0, ( new Queue_Store() )->stats()['count'], 'Must not land in the other site\'s queue.' );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( 1, $this->queue_count() );
	}
}
