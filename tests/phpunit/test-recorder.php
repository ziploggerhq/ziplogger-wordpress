<?php
/**
 * The recording pipeline: threshold, limits, redaction before persistence, de-duplication,
 * overflow policy and the developer API.
 */

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Plugin;
use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Scheduler;

class Test_Recorder extends ZL_TestCase {

	private function limit( array $values ) {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) use ( $values ) {
				return array_merge( $l, $values );
			}
		);
		Limits::reset();
	}

	private function recorder() {
		return new Recorder();
	}

	public function test_nothing_is_recorded_while_collection_is_off() {
		$r = $this->recorder();
		$this->assertFalse( $r->record( 'developer', 'fatal', 'boom' ) );
		$this->assertSame( 0, $r->buffered() );
		$this->assertFalse( ziplogger_log( 'fatal', 'boom' ) );
		$r->flush();
		$this->assertSame( 0, $this->queue_count() );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_DELIVER ), 'Nothing may be scheduled either.' );
	}

	public function test_severity_threshold_applies_to_recorded_events() {
		$this->enable( array( 'min_severity' => 'warn' ) );
		$r = $this->recorder();
		$this->assertFalse( $r->record( 'developer', 'debug', 'd' ) );
		$this->assertFalse( $r->record( 'developer', 'info', 'i' ) );
		$this->assertTrue( $r->record( 'developer', 'warn', 'w' ) );
		$this->assertTrue( $r->record( 'developer', 'error', 'e' ) );
		$this->assertTrue( $r->record( 'developer', 'fatal', 'f' ) );
		$this->assertSame( 3, $r->buffered() );
	}

	public function test_exempt_events_ignore_the_threshold() {
		$this->enable( array( 'min_severity' => 'error' ) );
		$r = $this->recorder();
		$this->assertTrue( $r->record( 'plugin_activated', 'info', 'Plugin activated: x', array( 'exempt' => true ) ) );
	}

	public function test_would_record_is_a_cheap_precheck() {
		$this->enable( array( 'min_severity' => 'warn' ) );
		$r = $this->recorder();
		$this->assertFalse( $r->would_record( 'info' ) );
		$this->assertTrue( $r->would_record( 'info', true ) );
		$this->assertTrue( $r->would_record( 'warn' ) );
	}

	public function test_events_are_redacted_before_they_are_persisted() {
		$this->enable( array( 'min_severity' => 'debug' ) );
		$r = $this->recorder();
		$r->record(
			'developer',
			'error',
			'Login for jane@example.com failed with password=hunter2 from 203.0.113.9 using Bearer abcdefgh12345678',
			array(
				'context' => array( 'password' => 'hunter2', 'token' => 'tok-123456', 'note' => 'see https://u:p@ex.com/x?a=b', 'ok' => 'fine' ),
				'stack'   => "#0 /var/www/site/wp-content/plugins/x/x.php(3): f('hunter2')\n#1 {main}",
			)
		);
		$r->flush();

		$stored = implode( "\n", $this->queued_payloads() );
		foreach ( array( 'jane@example.com', 'hunter2', '203.0.113.9', 'abcdefgh12345678', 'tok-123456', 'u:p@', '?a=b', '/var/www/site' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $stored, "$secret reached the queue table" );
		}
		$this->assertStringContainsString( 'fine', $stored );
	}

	public function test_flush_persists_and_schedules_delivery() {
		$this->enable();
		$r = $this->recorder();
		$r->record( 'developer', 'error', 'first' );
		$r->record( 'developer', 'error', 'second' );
		$this->assertSame( 0, $this->queue_count(), 'Nothing is written until flush.' );
		$this->assertSame( 2, $r->flush() );
		$this->assertSame( 2, $this->queue_count() );
		$this->assertSame( 0, $r->buffered() );

		$next = wp_next_scheduled( Scheduler::HOOK_DELIVER );
		$this->assertNotFalse( $next );
		$this->assertSame( Clock::time() + (int) Limits::get( 'first_delivery_delay' ), $next );
		$this->assertSame( 0, $r->flush(), 'A second flush has nothing to do.' );
		$this->assertCount( 0, $this->requests, 'Recording never makes an outbound request.' );
	}

	public function test_per_request_cap_drops_and_counts() {
		$this->enable();
		$this->limit( array( 'request_event_cap' => 5 ) );
		$r = $this->recorder();
		for ( $i = 0; $i < 8; $i++ ) {
			$r->record( 'developer', 'error', 'event ' . $i );
		}
		$this->assertSame( 5, $r->buffered() );
		$r->flush();
		$this->assertSame( 5, $this->queue_count() );
		$this->assertSame( 3, $this->meta->drop_counts()['request_cap'] );
	}

	public function test_fatal_errors_bypass_the_per_request_cap() {
		$this->enable();
		$this->limit( array( 'request_event_cap' => 5 ) );
		$r = $this->recorder();
		for ( $i = 0; $i < 5; $i++ ) {
			$r->record( 'developer', 'error', 'event ' . $i );
		}
		$this->assertTrue( $r->record( 'php_fatal', 'fatal', 'PHP Fatal error: x', array( 'bypass_cap' => true ) ) );
	}

	public function test_duplicates_within_a_request_collapse_with_an_occurrence_count() {
		$this->enable();
		$r = $this->recorder();
		$this->assertTrue( $r->record( 'php_error', 'warn', 'Undefined index x in f.php on line 9', array( 'dedupe' => true, 'fp_extra' => 'f.php:9' ) ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertFalse( $r->record( 'php_error', 'warn', 'Undefined index x in f.php on line 9', array( 'dedupe' => true, 'fp_extra' => 'f.php:9' ) ) );
		}
		$this->assertSame( 1, $r->buffered() );
		$r->flush();
		$events = $this->queued_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 5, $events[0]['fields']['occurrencesInRequest'] );
	}

	public function test_variable_parts_do_not_defeat_de_duplication() {
		$this->enable();
		$r = $this->recorder();
		$r->record( 'php_error', 'warn', 'Query for user 101 failed', array( 'dedupe' => true ) );
		$r->record( 'php_error', 'warn', 'Query for user 202 failed', array( 'dedupe' => true ) );
		$this->assertSame( 1, $r->buffered() );
	}

	public function test_developer_events_are_not_de_duplicated() {
		$this->enable();
		$r = $this->recorder();
		$r->record( 'developer', 'error', 'Import row failed' );
		$r->record( 'developer', 'error', 'Import row failed' );
		$this->assertSame( 2, $r->buffered() );
	}

	public function test_pending_duplicates_across_requests_are_suppressed_then_reported() {
		$this->enable();
		$args = array( 'dedupe' => true, 'fp_extra' => 'f.php:9' );

		$r1 = $this->recorder();
		$r1->record( 'php_error', 'warn', 'Same warning', $args );
		$r1->flush();
		$this->assertSame( 1, $this->queue_count() );

		// Two more requests hit the same warning while the first is still waiting to be delivered.
		foreach ( array( 1, 2 ) as $unused ) {
			$r = $this->recorder();
			$r->record( 'php_error', 'warn', 'Same warning', $args );
			$r->flush();
		}
		$this->assertSame( 1, $this->queue_count(), 'Repeats must not pile up in the queue.' );
		$this->assertSame( 2, $this->meta->get_num( Meta_Store::SUPPRESSED ) );

		// Once the first is delivered (gone from the queue) the next one carries the tally.
		$this->queue->clear();
		$r = $this->recorder();
		$r->record( 'php_error', 'warn', 'Same warning', $args );
		$r->flush();
		$events = $this->queued_events();
		$this->assertCount( 1, $events );
		$this->assertSame( 2, $events[0]['fields']['suppressedRepeats'] );

		// The tally is consumed, not repeated.
		$this->queue->clear();
		$r = $this->recorder();
		$r->record( 'php_error', 'warn', 'Same warning', $args );
		$r->flush();
		$this->assertArrayNotHasKey( 'suppressedRepeats', $this->queued_events()[0]['fields'] );
	}

	public function test_a_full_queue_drops_the_newest_events_and_counts_them() {
		$this->enable();
		$this->limit( array( 'queue_max_events' => 100 ) );
		$docs = $this->seed( 100, 'old' );
		$r    = $this->recorder();
		$r->record( 'developer', 'error', 'newest' );
		$this->assertSame( 0, $r->flush() );

		$this->assertSame( 100, $this->queue_count(), 'The queue never grows past its bound.' );
		$this->assertSame( 1, $this->meta->drop_counts()['overflow'] );
		$this->assertSame( $docs[0], $this->queued_payloads()[0], 'Oldest events are kept: drop-newest policy.' );
		$this->assertStringNotContainsString( 'newest', implode( '', $this->queued_payloads() ) );
	}

	public function test_debug_and_info_cannot_use_the_last_slice_of_capacity() {
		$this->enable( array( 'min_severity' => 'debug' ) );
		$this->limit( array( 'queue_max_events' => 100 ) );
		$this->seed( 90 );
		$r = $this->recorder();
		$r->record( 'developer', 'info', 'chatty info' );
		$r->record( 'developer', 'debug', 'chatty debug' );
		$r->record( 'developer', 'error', 'important error' );
		$r->flush();

		$stored = implode( '', $this->queued_payloads() );
		$this->assertStringNotContainsString( 'chatty', $stored );
		$this->assertStringContainsString( 'important error', $stored );
		$this->assertSame( 2, $this->meta->drop_counts()['overflow'] );
	}

	public function test_the_byte_bound_also_applies() {
		$this->enable();
		$this->limit( array( 'queue_max_bytes' => 1048576 ) );
		$big = array( 'payload' => '{}', 'size' => 1048000, 'severity' => 2, 'fingerprint' => '', 'created_at' => Clock::time() );
		$this->queue->insert_many( array( $big ) );
		$r = $this->recorder();
		$r->record( 'developer', 'error', str_repeat( 'x', 1000 ) );
		$this->assertSame( 0, $r->flush() );
		$this->assertSame( 1, $this->meta->drop_counts()['overflow'] );
	}

	public function test_developer_function_and_action_share_the_same_pipeline() {
		$this->enable( array( 'min_severity' => 'info' ) );
		$plugin = Plugin::instance();

		$this->assertTrue( ziplogger_log( 'info', 'Background import completed', array( 'processed_count' => 42 ) ) );
		do_action( 'ziplogger_log', 'error', 'Import failed', array( 'batch' => 7, 'password' => 'p' ) );
		$this->assertSame( 2, $plugin->recorder()->buffered() );
		$plugin->recorder()->flush();

		$events = $this->queued_events();
		$this->assertCount( 2, $events );
		$this->assertSame( 'info', $events[0]['severity'] );
		$this->assertSame( 'Background import completed', $events[0]['message'] );
		$this->assertSame( 42, $events[0]['fields']['processed_count'] );
		$this->assertSame( 'developer', $events[0]['fields']['eventType'] );
		$this->assertSame( 'error', $events[1]['severity'] );
		$this->assertSame( '[redacted]', $events[1]['fields']['password'], 'Developer events get the same redaction.' );
		$this->assertSame( 7, $events[1]['fields']['batch'] );
	}

	public function test_developer_events_are_validated_like_any_other() {
		$this->enable( array( 'min_severity' => 'debug' ) );
		$plugin = Plugin::instance();
		ziplogger_log( 'WARNING', 'a' );
		ziplogger_log( 'bogus', 'b' );
		ziplogger_log( 'error', array( 'not', 'a', 'string' ) );
		ziplogger_log( 'error', 12345 );
		ziplogger_log( 'error', 'c', 'not-an-array' );
		$plugin->recorder()->flush();

		$events = $this->queued_events();
		$this->assertSame( array( 'warn', 'info', 'error', 'error', 'error' ), array_column( $events, 'severity' ) );
		$this->assertStringStartsWith( '[unsupported message type', $events[2]['message'] );
		$this->assertSame( '12345', $events[3]['message'] );
	}

	public function test_developer_events_respect_the_severity_threshold() {
		$this->enable( array( 'min_severity' => 'error' ) );
		$this->assertFalse( ziplogger_log( 'warn', 'below the line' ) );
		$this->assertTrue( ziplogger_log( 'error', 'on the line' ) );
	}

	public function test_an_exception_in_the_context_becomes_an_argument_free_stack_trace() {
		$this->enable();
		$plugin = Plugin::instance();
		try {
			( static function ( $secret ) {
				throw new RuntimeException( 'Import failed: token=abc123secret' );
			} )( 'super-secret-argument' );
		} catch ( RuntimeException $e ) {
			ziplogger_log( 'error', 'Caught', array( 'exception' => $e ) );
		}
		$plugin->recorder()->flush();

		$event  = $this->queued_events()[0];
		$stored = implode( '', $this->queued_payloads() );
		$this->assertSame( 'RuntimeException', $event['fields']['exceptionClass'] );
		$this->assertArrayNotHasKey( 'exception', $event['fields'] );
		$this->assertStringContainsString( 'RuntimeException', $event['stackTrace'] );
		$this->assertMatchesRegularExpression( '/\S+\.php:\d+/', $event['stackTrace'], 'The server parses file:line frames.' );
		$this->assertStringNotContainsString( 'super-secret-argument', $stored );
		$this->assertStringNotContainsString( 'abc123secret', $stored );
	}

	public function test_filters_can_exclude_or_change_events_and_are_redacted_afterwards() {
		$this->enable();
		add_filter(
			'ziplogger_should_log',
			static function ( $log, $spec ) {
				return false === strpos( $spec['message'], 'ignore-me' ) ? $log : false;
			},
			10,
			2
		);
		add_filter(
			'ziplogger_event',
			static function ( $spec ) {
				if ( false !== strpos( $spec['message'], 'drop-me' ) ) {
					return false;
				}
				if ( false !== strpos( $spec['message'], 'rewrite-me' ) ) {
					$spec['message'] = 'rewritten with password=hunter2';
				}
				if ( false !== strpos( $spec['message'], 'garbage' ) ) {
					return 'not an array';
				}
				return $spec;
			}
		);
		$r = $this->recorder();
		$this->assertFalse( $r->record( 'developer', 'error', 'ignore-me' ) );
		$this->assertFalse( $r->record( 'developer', 'error', 'drop-me' ) );
		$this->assertFalse( $r->record( 'developer', 'error', 'garbage' ) );
		$this->assertTrue( $r->record( 'developer', 'error', 'rewrite-me' ) );
		$r->flush();
		$stored = implode( '', $this->queued_payloads() );
		$this->assertStringContainsString( 'rewritten', $stored );
		$this->assertStringNotContainsString( 'hunter2', $stored, 'A filter cannot smuggle a secret past redaction.' );
	}

	public function test_a_filter_cannot_lower_severity_below_the_threshold() {
		$this->enable( array( 'min_severity' => 'error' ) );
		add_filter(
			'ziplogger_event',
			static function ( $spec ) {
				$spec['severity'] = 'debug';
				return $spec;
			}
		);
		$this->assertFalse( $this->recorder()->record( 'developer', 'error', 'x' ) );
	}

	public function test_recording_from_inside_a_filter_does_not_recurse() {
		$this->enable();
		$r     = $this->recorder();
		$inner = null;
		add_filter(
			'ziplogger_event',
			static function ( $spec ) use ( $r, &$inner ) {
				$inner = $r->record( 'developer', 'error', 'from inside the pipeline' );
				return $spec;
			}
		);
		$this->assertTrue( $r->record( 'developer', 'error', 'outer' ) );
		$this->assertFalse( $inner );
		$this->assertSame( 1, $r->buffered() );
	}

	public function test_an_exception_inside_a_filter_never_escapes() {
		$this->enable();
		add_filter(
			'ziplogger_event',
			static function () {
				throw new RuntimeException( 'plugin bug' );
			}
		);
		$this->assertFalse( $this->recorder()->record( 'developer', 'error', 'x' ) );
	}

	public function test_a_full_buffer_is_flushed_mid_request() {
		$this->enable();
		$this->limit( array( 'buffer_flush_at' => 3 ) );
		$r = $this->recorder();
		$r->record( 'developer', 'error', 'a' );
		$r->record( 'developer', 'error', 'b' );
		$this->assertSame( 0, $this->queue_count() );
		$r->record( 'developer', 'error', 'c' );
		$this->assertSame( 3, $this->queue_count(), 'Long-running processes (CLI, cron) must not hold events in memory.' );
	}

	public function test_oversize_events_are_shrunk_not_lost() {
		$this->enable();
		$this->limit( array( 'max_event_bytes' => 2048 ) );
		$context = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$context[ 'k' . $i ] = str_repeat( 'v', 500 );
		}
		$r = $this->recorder();
		$r->record( 'developer', 'error', 'shrink me', array( 'context' => $context ) );
		$r->flush();
		$payloads = $this->queued_payloads();
		$this->assertCount( 1, $payloads );
		$this->assertLessThanOrEqual( 2048, strlen( $payloads[0] ) );
	}
}
