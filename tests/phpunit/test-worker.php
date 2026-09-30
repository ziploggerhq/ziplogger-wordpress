<?php
/**
 * Delivery policy: acceptance, partial acceptance, retries, idempotency, outages, poison events.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Scheduler;
use ZipLogger\WordPress\Schema;
use ZipLogger\WordPress\Worker;

class Test_Worker extends ZL_TestCase {

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

	private function worker() {
		return new Worker();
	}

	/**
	 * Every idempotency key must map to exactly one request body - a key is never reused for a different payload.
	 */
	private function assert_keys_are_never_reused_for_different_payloads() {
		$bodies = array();
		foreach ( $this->requests as $req ) {
			$key = $req['args']['headers']['Idempotency-Key'];
			if ( isset( $bodies[ $key ] ) ) {
				$this->assertSame( $bodies[ $key ], $req['args']['body'], "Key $key was reused for a different payload" );
			}
			$bodies[ $key ] = $req['args']['body'];
		}
	}

	private function drop_count( $reason ) {
		return $this->meta->drop_counts()[ $reason ];
	}

	// ---------------------------------------------------------------------------------------------
	// Success.
	// ---------------------------------------------------------------------------------------------

	public function test_successful_delivery_deletes_rows_and_records_state() {
		$this->seed( 5 );
		$report = $this->worker()->run();

		$this->assertSame( 'sent', $report['status'] );
		$this->assertSame( 5, $report['events_sent'] );
		$this->assertSame( 0, $this->queue_count() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 5, $this->count_events( $this->requests[0]['args']['body'] ) );
		$this->assertSame( Clock::time(), $this->meta->get_num( Meta_Store::LAST_SUCCESS ) );
		$this->assertSame( 5, $this->meta->get_num( Meta_Store::SENT_EVENTS ) );
		$this->assertSame( 0, $this->meta->get_num( Meta_Store::FAILURES ) );
	}

	public function test_an_empty_queue_makes_no_request() {
		$report = $this->worker()->run();
		$this->assertSame( 'idle', $report['status'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_events_are_sent_in_batches_of_the_configured_size() {
		$this->limit( array( 'batch_max_events' => 2 ) );
		$this->seed( 5 );
		$this->worker()->run();
		$this->assertCount( 3, $this->requests );
		$this->assertSame( array( 2, 2, 1 ), array_map( array( $this, 'count_events_of_request' ), $this->requests ) );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function count_events_of_request( $req ) {
		return $this->count_events( $req['args']['body'] );
	}

	public function test_a_run_is_bounded_by_batch_count_and_leaves_a_follow_up_scheduled() {
		$this->limit( array( 'batch_max_events' => 1, 'worker_max_batches' => 2 ) );
		$this->seed( 10 );
		$report = $this->worker()->run();
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 8, $report['pending'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'More work remains, so another run must be scheduled.' );
	}

	public function test_a_run_is_bounded_by_its_time_budget() {
		$this->limit( array( 'batch_max_events' => 1 ) );
		$this->seed( 10 );
		$this->responses = array(
			function ( $args ) {
				Clock::advance( 10 ); // Each request takes ten seconds.
				return self::response( 202, array( 'accepted' => 1, 'rejected' => 0 ) );
			},
		);
		$this->worker()->run( array( 'max_seconds' => 25, 'max_batches' => 100 ) );
		$this->assertCount( 2, $this->requests, 'A third request could overrun the 25 second budget.' );
	}

	public function test_the_first_batch_is_always_attempted_even_with_a_tiny_budget() {
		$this->seed( 1 );
		$this->worker()->run( array( 'max_seconds' => 5 ) );
		$this->assertCount( 1, $this->requests );
	}

	// ---------------------------------------------------------------------------------------------
	// Partial acceptance and idempotency.
	// ---------------------------------------------------------------------------------------------

	public function test_partial_acceptance_retries_the_same_batch_with_the_same_key_and_bytes() {
		$this->seed( 3 );
		$this->responses = array(
			self::response( 429, array( 'accepted' => 2, 'rejected' => 1 ), array( 'Retry-After' => '120' ) ),
			self::response( 429, array( 'accepted' => 2, 'rejected' => 1 ), array( 'Retry-After' => '120' ) ),
			self::response( 202, array( 'accepted' => 3, 'rejected' => 0 ) ),
		);

		$report = $this->worker()->run();
		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 3, $this->queue_count(), 'Nothing is deleted until the whole batch is accepted.' );

		$gated = $this->worker()->run();
		$this->assertSame( 'gated', $gated['status'], 'Retry-After is honoured.' );
		$this->assertCount( 1, $this->requests );

		Clock::advance( 135 ); // Retry-After (120s) plus up to 10s of jitter.
		$this->worker()->run();
		$this->assertCount( 2, $this->requests );
		$this->assertSame( 3, $this->queue_count() );

		Clock::advance( 3600 );
		$final = $this->worker()->run();
		$this->assertSame( 'sent', $final['status'] );
		$this->assertSame( 0, $this->queue_count() );
		$this->assertCount( 3, $this->requests );

		$keys   = array_map( static function ( $r ) { return $r['args']['headers']['Idempotency-Key']; }, $this->requests );
		$bodies = array_map( static function ( $r ) { return $r['args']['body']; }, $this->requests );
		$this->assertCount( 1, array_unique( $keys ), 'Same Idempotency-Key on every retry.' );
		$this->assertCount( 1, array_unique( $bodies ), 'Byte-identical payload on every retry.' );
		$this->assertSame( 3, $this->count_events( $bodies[0] ) );
		$this->assertSame( 3, $this->meta->get_num( Meta_Store::SENT_EVENTS ), 'Counted once, when finally accepted.' );
	}

	public function test_a_partially_accepted_batch_is_never_split_or_dropped() {
		$this->seed( 4 );
		$this->responses = array( self::response( 429, array( 'accepted' => 1, 'rejected' => 3 ), array( 'Retry-After' => '60' ) ) );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->worker()->run( array( 'force' => true ) );
			Clock::advance( 4000 );
		}
		$this->assertGreaterThanOrEqual( 20, count( $this->requests ) );
		$this->assertSame( 4, $this->queue_count() );
		$this->assertSame( 0, $this->meta->dropped_total() );
		$this->assertCount( 1, array_unique( array_map( static function ( $r ) { return $r['args']['headers']['Idempotency-Key']; }, $this->requests ) ) );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( 'SELECT MIN(partial) FROM ' . Schema::queue_table() ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_2xx_that_does_not_confirm_acceptance_keeps_the_batch() {
		$this->seed( 3 );
		foreach ( array( self::response( 200, '<html>Welcome to hotel wifi</html>' ), self::response( 202, array( 'accepted' => 1, 'rejected' => 0 ) ), self::response( 204, '' ) ) as $response ) {
			$this->responses = array( $response );
			$this->worker()->run( array( 'force' => true ) );
			$this->assertSame( 3, $this->queue_count(), 'Only a body that confirms every event may delete the batch.' );
		}
		$this->assert_keys_are_never_reused_for_different_payloads();
		$this->assertCount( 1, array_unique( array_map( static function ( $r ) { return $r['args']['headers']['Idempotency-Key']; }, $this->requests ) ) );
	}

	public function test_each_batch_gets_its_own_key_and_a_key_is_never_shared_between_payloads() {
		$this->limit( array( 'batch_max_events' => 2 ) );
		$this->seed( 6 );
		$this->worker()->run();
		$keys = array_map( static function ( $r ) { return $r['args']['headers']['Idempotency-Key']; }, $this->requests );
		$this->assertCount( 3, array_unique( $keys ) );
		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression( '/^zlwp-[0-9a-f]{32}$/', $key );
			$this->assertLessThan( 200, strlen( $key ), 'The backend truncates keys at 200 characters.' );
		}
		$this->assert_keys_are_never_reused_for_different_payloads();
	}

	public function test_key_conflict_gives_the_same_events_a_new_key() {
		$this->seed( 2 );
		$this->responses = array(
			self::response( 422, array( 'error' => 'This Idempotency-Key was already used with a different payload.' ) ),
			self::response( 202, array( 'accepted' => 2, 'rejected' => 0 ) ),
		);
		$report = $this->worker()->run();
		$this->assertSame( 'sent', $report['status'] );
		$this->assertCount( 2, $this->requests );
		$this->assertNotSame( $this->requests[0]['args']['headers']['Idempotency-Key'], $this->requests[1]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'] );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_repeated_key_conflicts_cannot_spin_forever() {
		$this->seed( 1 );
		$this->responses = array( self::response( 422, '' ) );
		$this->worker()->run();
		$this->assertLessThanOrEqual( (int) Limits::get( 'worker_max_batches' ), count( $this->requests ) );
		$this->assertSame( 1, $this->queue_count() );
	}

	// ---------------------------------------------------------------------------------------------
	// Retry scheduling.
	// ---------------------------------------------------------------------------------------------

	public function test_transient_failures_back_off_exponentially_with_the_batch_kept_intact() {
		$this->seed( 2 );
		$this->responses = array( self::response( 503, '' ) );
		global $wpdb;

		$expected = array( 30, 60, 120, 240, 480, 960, 1800, 1800 );
		foreach ( $expected as $i => $delay ) {
			$this->worker()->run();
			$available = Clock::from_mysql( $wpdb->get_var( 'SELECT MIN(available_at) FROM ' . Schema::queue_table() ) ); // phpcs:ignore WordPress.DB
			$this->assertSame( Clock::time() + $delay, $available, 'Attempt ' . ( $i + 1 ) );
			$this->assertSame( $i + 1, (int) $wpdb->get_var( 'SELECT MAX(attempts) FROM ' . Schema::queue_table() ) ); // phpcs:ignore WordPress.DB

			$before = count( $this->requests );
			$this->worker()->run();
			$this->assertCount( $before, $this->requests, 'No request before the retry time.' );
			Clock::advance( $delay );
		}
		$this->assert_keys_are_never_reused_for_different_payloads();
		$this->assertSame( 2, $this->queue_count() );
		$this->assertSame( 0, $this->meta->dropped_total(), 'A failing endpoint never causes events to be dropped.' );
	}

	public function test_the_retry_is_scheduled_in_wp_cron() {
		$this->seed( 1 );
		$this->responses = array( self::response( 503, '' ) );
		$report          = $this->worker()->run();
		$this->assertSame( Clock::time() + 30, $report['next_run'] );
		$this->assertSame( Clock::time() + 30, wp_next_scheduled( Scheduler::HOOK_DELIVER ) );
	}

	public function test_retry_after_on_a_503_is_a_floor_for_the_backoff() {
		$this->seed( 1 );
		$this->responses = array( self::response( 503, '', array( 'Retry-After' => '900' ) ) );
		$report          = $this->worker()->run();
		$this->assertGreaterThanOrEqual( Clock::time() + 900, $report['next_run'] );
	}

	public function test_two_failures_in_one_run_pause_delivery_until_the_gate_opens() {
		$this->limit( array( 'batch_max_events' => 1 ) );
		$this->seed( 2 );
		$this->responses = array( self::response( 500, '' ) );
		$report          = $this->worker()->run();
		$this->assertCount( 2, $this->requests, 'Two different batches failed in one run.' );
		$this->assertGreaterThan( Clock::time(), $this->meta->get_num( Meta_Store::GATE_UNTIL ) );

		$again = $this->worker()->run();
		$this->assertSame( 'gated', $again['status'] );
		$this->assertCount( 2, $this->requests );
		$this->assertNotSame( '', $report['error'] );
	}

	public function test_a_timeout_is_retried_not_dropped() {
		$this->seed( 3 );
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 8001 milliseconds' ) );
		$report          = $this->worker()->run();
		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 3, $this->queue_count() );
		$this->assertStringContainsString( 'timed out', $this->meta->get_json( Meta_Store::LAST_ERROR )['message'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Outage and recovery.
	// ---------------------------------------------------------------------------------------------

	public function test_events_queued_during_an_outage_are_delivered_after_it_ends() {
		$this->limit( array( 'batch_max_events' => 100 ) );
		$this->seed( 250 );
		$outage = true;
		$this->responses = array(
			function ( $args ) use ( &$outage ) {
				return $outage
					? new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect: Connection refused' )
					: self::response( 202, array( 'accepted' => $this->count_events( $args['body'] ), 'rejected' => 0 ) );
			},
		);

		for ( $i = 0; $i < 12; $i++ ) {
			$this->worker()->run();
			Clock::advance( 1800 );
		}
		$this->assertSame( 250, $this->queue_count(), 'Nothing is lost while ZipLogger is unreachable.' );
		$this->assertSame( 0, $this->meta->dropped_total() );
		$this->assertSame( 0, $this->meta->get_num( Meta_Store::SENT_EVENTS ) );

		$outage = false;
		Clock::advance( 4000 );
		$delivered = array();
		for ( $i = 0; $i < 6 && $this->queue_count() > 0; $i++ ) {
			$this->worker()->run();
			Clock::advance( 1800 );
		}
		foreach ( $this->requests as $req ) {
			// Only count requests made after the outage ended: those were accepted.
			if ( false === strpos( $req['args']['body'], '"n":' ) ) {
				continue;
			}
		}
		$this->assertSame( 0, $this->queue_count(), 'The whole backlog is delivered once the endpoint is back.' );
		$this->assertSame( 250, $this->meta->get_num( Meta_Store::SENT_EVENTS ) );
		$this->assertSame( 0, $this->meta->dropped_total() );
		$this->assert_keys_are_never_reused_for_different_payloads();
		unset( $delivered );
	}

	public function test_a_long_outage_only_loses_events_to_the_retention_window() {
		$this->seed( 5 );
		$this->responses = array( new WP_Error( 'http_request_failed', 'down' ) );
		$this->worker()->run();
		Clock::advance( (int) Limits::get( 'retention_seconds' ) + 10 );
		$report = $this->worker()->run();
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( 5, $this->drop_count( 'expired' ), 'Expiry is counted, never silent.' );
		unset( $report );
	}

	public function test_a_crashed_workers_batch_is_resent_with_the_same_key_and_bytes() {
		$this->seed( 4 );
		$crashed = ( new Queue_Store() )->claim_next(); // The worker dies mid-request.

		$this->worker()->run();
		$this->assertCount( 0, $this->requests, 'The dead worker\'s lease still protects its batch.' );

		Clock::advance( (int) Limits::get( 'lease_seconds' ) + 1 );
		$this->worker()->run();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( $crashed->payload, $this->requests[0]['args']['body'] );
		$this->assertSame( $crashed->key(), $this->requests[0]['args']['headers']['Idempotency-Key'] );
		$this->assertSame( 0, $this->queue_count() );
	}

	// ---------------------------------------------------------------------------------------------
	// Authentication and configuration failures.
	// ---------------------------------------------------------------------------------------------

	public function test_auth_failure_pauses_delivery_without_burning_attempts_and_saving_resumes_it() {
		$this->seed( 3 );
		$this->responses = array( self::response( 401, array( 'error' => 'Invalid API key' ) ) );
		$report          = $this->worker()->run();
		$this->assertSame( 'failed', $report['status'] );
		$this->assertSame( 'auth', $this->meta->get_str( Meta_Store::BLOCKED ) );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( 'SELECT MAX(attempts) FROM ' . Schema::queue_table() ), 'A bad key says nothing about the events.' ); // phpcs:ignore WordPress.DB
		$this->assertSame( 3, $this->queue_count() );

		$this->assertSame( 'gated', $this->worker()->run()['status'] );
		Clock::advance( 60 );
		$this->assertSame( 'gated', $this->worker()->run()['status'], 'Paused for at least 15 minutes.' );
		$this->assertCount( 1, $this->requests );

		// The administrator fixes the key: saving settings lifts the pause.
		$this->meta->clear_gate();
		$this->responses = array( self::response( 202, array( 'accepted' => 3, 'rejected' => 0 ) ) );
		Clock::advance( 1000 );
		$this->assertSame( 'sent', $this->worker()->run( array( 'force' => true ) )['status'] );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_403_and_404_also_pause_delivery_and_keep_the_data() {
		foreach ( array( 403 => 'auth', 404 => 'config' ) as $status => $blocked ) {
			$this->meta->clear_gate();
			$this->queue->clear();
			$this->seed( 2 );
			$this->responses = array( self::response( $status, '' ) );
			$this->worker()->run( array( 'force' => true ) );
			$this->assertSame( $blocked, $this->meta->get_str( Meta_Store::BLOCKED ), "HTTP $status" );
			$this->assertSame( 2, $this->queue_count() );
		}
	}

	public function test_redirects_are_reported_not_followed() {
		$this->seed( 1 );
		$this->responses = array( self::response( 301, '', array( 'Location' => 'https://evil.example.org/collect' ) ) );
		$report          = $this->worker()->run();
		$this->assertCount( 1, $this->requests, 'No second request to the redirect target.' );
		$this->assertStringContainsString( 'redirect', strtolower( $report['error'] ) );
		$this->assertSame( 1, $this->queue_count() );
	}

	public function test_a_disabled_or_unconfigured_site_sends_nothing() {
		$this->seed( 2 );
		Settings_Helper::disable();
		$this->assertSame( 'disabled', $this->worker()->run()['status'] );

		$this->enable();
		Settings_Helper::remove_key();
		$report = $this->worker()->run();
		$this->assertSame( 'not_configured', $report['status'] );
		$this->assertNotSame( '', $report['error'] );

		$this->assertCount( 0, $this->requests );
		$this->assertSame( 2, $this->queue_count(), 'Data stays queued.' );
	}

	public function test_an_invalid_endpoint_blocks_delivery_instead_of_falling_back_to_another_host() {
		$this->seed( 1 );
		\ZipLogger\WordPress\Settings::$constants['ZIPLOGGER_ENDPOINT'] = 'http://insecure.example.org';
		\ZipLogger\WordPress\Settings::reset_cache();
		$report = $this->worker()->run();
		$this->assertSame( 'not_configured', $report['status'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_manual_flush_ignores_the_pause_and_retry_timers() {
		$this->seed( 2 );
		$this->responses = array( self::response( 503, '' ), self::response( 202, array( 'accepted' => 2, 'rejected' => 0 ) ) );
		$this->worker()->run();
		$this->assertCount( 1, $this->requests );
		$this->worker()->run();
		$this->assertCount( 1, $this->requests, 'The batch\'s retry timer is running, so a normal run waits.' );

		$report = $this->worker()->run( array( 'force' => true ) );
		$this->assertSame( 'sent', $report['status'] );
		$this->assertSame( 0, $this->queue_count() );
	}

	// ---------------------------------------------------------------------------------------------
	// Poison events.
	// ---------------------------------------------------------------------------------------------

	/**
	 * A server that rejects any batch containing the marker and accepts the rest. Successful requests
	 * are logged in $this->accepted_messages.
	 */
	private $accepted_messages = array();

	private function poison_server( $status, $marker = 'POISON' ) {
		$this->accepted_messages = array();
		$this->responses         = array(
			function ( $args ) use ( $status, $marker ) {
				if ( false !== strpos( $args['body'], $marker ) ) {
					return self::response( $status, array( 'error' => 'rejected' ) );
				}
				foreach ( json_decode( $args['body'], true ) as $event ) {
					$this->accepted_messages[] = $event['message'];
				}
				return self::response( 202, array( 'accepted' => $this->count_events( $args['body'] ), 'rejected' => 0 ) );
			},
		);
	}

	private function run_until_empty( $max_runs = 40, $advance = 700 ) {
		for ( $i = 0; $i < $max_runs && $this->queue_count() > 0; $i++ ) {
			$this->worker()->run( array( 'max_batches' => 50 ) );
			Clock::advance( $advance );
		}
	}

	private function seed_with_poison( $total, $poison_at ) {
		$this->seed( $poison_at - 1, 'good-a' );
		$this->seed( 1, 'POISON' );
		$this->seed( $total - $poison_at, 'good-b' );
	}

	public function test_a_400_poison_event_is_isolated_by_bisection_and_everything_else_is_delivered() {
		$this->seed_with_poison( 16, 11 );
		$this->poison_server( 400 );
		$this->run_until_empty();

		$this->assertSame( 0, $this->queue_count(), 'The poison event must not block the queue.' );
		$this->assertSame( 1, $this->drop_count( 'rejected' ) );
		$this->assertCount( 15, $this->accepted_messages );
		$this->assertCount( 15, array_unique( $this->accepted_messages ), 'No event is delivered twice.' );
		$this->assertNotContains( 'POISON 1', $this->accepted_messages );
		$this->assert_keys_are_never_reused_for_different_payloads();
	}

	public function test_a_413_is_split_and_only_a_single_oversize_event_is_dropped() {
		$this->seed_with_poison( 9, 4 );
		$this->poison_server( 413 );
		$this->run_until_empty();
		$this->assertSame( 0, $this->queue_count() );
		$this->assertSame( 1, $this->drop_count( 'oversize' ) );
		$this->assertCount( 8, $this->accepted_messages );
	}

	public function test_a_poison_event_at_the_edges_and_a_batch_of_one() {
		foreach ( array( 1, 8 ) as $pos ) {
			$this->queue->clear();
			$this->meta->clear_gate();
			$this->seed_with_poison( 8, $pos );
			$this->poison_server( 400 );
			$this->run_until_empty();
			$this->assertSame( 0, $this->queue_count(), "poison at $pos" );
			$this->assertCount( 7, $this->accepted_messages );
		}
		$this->queue->clear();
		$this->seed( 1, 'POISON' );
		$this->poison_server( 400 );
		$this->run_until_empty();
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_a_500_poison_event_is_isolated_only_because_the_endpoint_demonstrably_works() {
		$this->limit( array( 'batch_max_events' => 2 ) );
		$this->seed( 2, 'lead' );          // Batch 1: succeeds, proving the endpoint works.
		$this->seed_with_poison( 4, 2 );   // Batch 2 holds a good event and the poison; batch 3 is clean.
		$this->poison_server( 500 );
		$this->run_until_empty( 60, 2000 );

		$this->assertSame( 0, $this->queue_count(), 'The queue drains despite a poison event.' );
		$this->assertSame( 1, $this->drop_count( 'poison' ) );
		$this->assertNotContains( 'POISON 1', $this->accepted_messages );
		$this->assertCount( 5, $this->accepted_messages, 'Every other event is delivered.' );
		$this->assertCount( 5, array_unique( $this->accepted_messages ) );
		$this->assert_keys_are_never_reused_for_different_payloads();
	}

	public function test_when_nothing_gets_through_nothing_is_dropped_as_poison() {
		$this->seed( 6 );
		$this->responses = array( self::response( 500, '' ) );
		for ( $i = 0; $i < 40; $i++ ) {
			$this->worker()->run( array( 'force' => true ) );
			Clock::advance( 2000 );
		}
		$this->assertSame( 6, $this->queue_count() );
		$this->assertSame( 0, $this->meta->dropped_total(), 'A real outage looks exactly like a poison event until something succeeds.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Retention, isolation from the ingestion loop, and the test event.
	// ---------------------------------------------------------------------------------------------

	public function test_retention_purges_old_events_before_sending() {
		$this->seed( 3 );
		Clock::advance( (int) Limits::get( 'retention_seconds' ) + 1 );
		$this->seed( 1, 'fresh' );
		$this->worker()->run();
		$this->assertSame( 3, $this->drop_count( 'expired' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 1, $this->count_events( $this->requests[0]['args']['body'] ) );
	}

	public function test_delivery_diagnostics_are_never_queued_as_events() {
		$this->seed( 2 );
		$this->responses = array( self::response( 500, array( 'error' => 'boom' ) ) );
		$plugin_recorder = \ZipLogger\WordPress\Plugin::instance()->recorder();
		$this->worker()->run();
		$plugin_recorder->flush();
		$this->assertSame( 2, $this->queue_count(), 'Failures are kept in the state table, not fed back into the queue.' );
		$this->assertSame( 0, $plugin_recorder->buffered() );
		$this->assertNotSame( '', $this->meta->get_json( Meta_Store::LAST_ERROR )['message'] );
	}

	public function test_the_worker_swallows_nothing_it_should_report_and_records_the_last_result() {
		$this->seed( 1 );
		$this->worker()->run();
		$this->assertSame( 'sent', $this->meta->get_str( Meta_Store::WORKER_LAST_RESULT ) );
		$this->assertSame( Clock::time(), $this->meta->get_num( Meta_Store::WORKER_LAST_RUN ) );
	}

	public function test_the_test_event_reports_what_zipLogger_answered() {
		$this->responses = array( self::response( 202, array( 'accepted' => 1, 'rejected' => 0 ) ) );
		$result          = $this->worker()->send_test_event();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 202, $result['status'] );
		$this->assertSame( 1, $result['accepted'] );
		$this->assertSame( 0, $this->queue_count(), 'The test event bypasses the queue.' );

		$sent = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'connection_test', $sent[0]['fields']['eventType'] );
		$this->assertSame( 'info', $sent[0]['severity'] );
		$this->assertMatchesRegularExpression( '/^zlwp-test-[0-9a-f]{32}$/', $this->requests[0]['args']['headers']['Idempotency-Key'] );
		$this->assertTrue( $this->meta->get_json( Meta_Store::LAST_TEST )['ok'] );
	}

	public function test_the_test_event_reports_failures_without_leaking_the_key() {
		foreach ( array( 401, 500, 429 ) as $status ) {
			$this->responses = array( self::response( $status, array( 'error' => 'nope ' . self::KEY ) ) );
			$result          = $this->worker()->send_test_event();
			$this->assertFalse( $result['ok'], "HTTP $status" );
			$this->assertStringNotContainsString( self::KEY, $result['message'] );
			$this->assertSame( $status, $result['status'] );
		}
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );
		$this->assertFalse( $this->worker()->send_test_event()['ok'] );
	}

	public function test_the_test_event_works_before_collection_is_switched_on() {
		Settings_Helper::disable();
		$this->assertTrue( $this->worker()->send_test_event()['ok'], 'Testing the connection is how an administrator decides to switch collection on.' );
	}

	public function test_a_successful_test_lifts_an_auth_pause() {
		$this->seed( 1 );
		$this->responses = array( self::response( 401, '' ) );
		$this->worker()->run();
		$this->assertSame( 'auth', $this->meta->get_str( Meta_Store::BLOCKED ) );
		$this->responses = array( self::response( 202, array( 'accepted' => 1, 'rejected' => 0 ) ) );
		$this->assertTrue( $this->worker()->send_test_event()['ok'] );
		$this->assertSame( '', $this->meta->get_str( Meta_Store::BLOCKED ) );
	}

	public function test_the_test_event_without_a_key_makes_no_request() {
		Settings_Helper::remove_key();
		$result = $this->worker()->send_test_event();
		$this->assertFalse( $result['ok'] );
		$this->assertCount( 0, $this->requests );
	}
}

/**
 * Tiny helper for toggling settings inside tests.
 */
final class Settings_Helper {
	public static function disable() {
		$s            = \ZipLogger\WordPress\Settings::get();
		$s['enabled'] = false;
		\ZipLogger\WordPress\Settings::save( $s );
	}

	public static function remove_key() {
		\ZipLogger\WordPress\Settings::remove_api_key();
	}
}
