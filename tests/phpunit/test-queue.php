<?php
/**
 * The persistent queue: claiming, leases, crash recovery, splitting, retention.
 */

use ZipLogger\WordPress\Batch;
use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Schema;

class Test_Queue extends ZL_TestCase {

	private function limit( array $values ) {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) use ( $values ) {
				return array_merge( $l, $values );
			}
		);
		Limits::reset();
	}

	public function test_insert_and_stats() {
		$this->seed( 5 );
		$s = $this->queue->stats();
		$this->assertSame( 5, $s['count'] );
		$this->assertGreaterThan( 0, $s['bytes'] );
		$this->assertSame( Clock::time(), $s['oldest'] );
		$this->assertSame( 0, $s['batched'] );
	}

	public function test_claim_forms_a_batch_in_insertion_order() {
		$this->limit( array( 'batch_max_events' => 4 ) );
		$docs = $this->seed( 10 );

		$b1 = $this->queue->claim_next();
		$this->assertSame( 4, $b1->count );
		$this->assertSame( '[' . implode( ',', array_slice( $docs, 0, 4 ) ) . ']', $b1->payload );
		$this->assertSame( $b1->ids, array_values( array_unique( $b1->ids ) ) );
		$this->assertSame( 'zlwp-' . $b1->id, $b1->key() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $b1->id );

		$b2 = $this->queue->claim_next();
		$this->assertSame( '[' . implode( ',', array_slice( $docs, 4, 4 ) ) . ']', $b2->payload );
		$b3 = $this->queue->claim_next();
		$this->assertSame( 2, $b3->count );
		$this->assertNull( $this->queue->claim_next() );
	}

	public function test_concurrent_claims_are_disjoint() {
		$this->limit( array( 'batch_max_events' => 5 ) );
		$this->seed( 12 );
		$worker_a = new ZipLogger\WordPress\Queue_Store();
		$worker_b = new ZipLogger\WordPress\Queue_Store();

		$claims = array( $worker_a->claim_next(), $worker_b->claim_next(), $worker_a->claim_next(), $worker_b->claim_next() );
		$seen   = array();
		foreach ( array_filter( $claims ) as $batch ) {
			foreach ( $batch->ids as $id ) {
				$this->assertArrayNotHasKey( $id, $seen, "Row $id was claimed twice" );
				$seen[ $id ] = true;
			}
		}
		$this->assertCount( 12, $seen );
		$this->assertNull( $claims[3], 'Only three batches exist.' );
	}

	public function test_batch_byte_ceiling_hands_the_tail_back() {
		$this->limit( array( 'batch_max_bytes' => 16384 ) );
		$rows = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$payload = wp_json_encode( array( 'message' => str_repeat( 'x', 2000 ) . $i ) );
			$rows[]  = array( 'payload' => $payload, 'size' => strlen( $payload ), 'severity' => 2, 'fingerprint' => '', 'created_at' => Clock::time() );
		}
		$this->queue->insert_many( $rows );

		$b = $this->queue->claim_next();
		$this->assertLessThanOrEqual( 8, $b->count );
		$this->assertGreaterThanOrEqual( 1, $b->count );
		$this->assertLessThanOrEqual( 16384 + 2100, $b->bytes() );
		$this->assertSame( 30, $this->queue_count() );
		$s = $this->queue->stats();
		$this->assertSame( $b->count, $s['batched'], 'The tail must be unbatched again, not left half-claimed.' );

		$b2 = $this->queue->claim_next();
		$this->assertNotSame( $b->id, $b2->id );
		$this->assertSame( $b->ids[ $b->count - 1 ] + 1, $b2->ids[0], 'The next batch continues where the last one stopped.' );
	}

	public function test_a_single_oversize_row_is_still_claimable() {
		$this->limit( array( 'batch_max_bytes' => 16384 ) );
		$payload = wp_json_encode( array( 'message' => str_repeat( 'x', 20000 ) ) );
		$this->queue->insert_many( array( array( 'payload' => $payload, 'size' => strlen( $payload ), 'severity' => 2, 'fingerprint' => '', 'created_at' => Clock::time() ) ) );
		$b = $this->queue->claim_next();
		$this->assertSame( 1, $b->count );
	}

	public function test_ack_deletes_the_batch_only() {
		$this->limit( array( 'batch_max_events' => 3 ) );
		$this->seed( 5 );
		$b = $this->queue->claim_next();
		$this->assertSame( 3, $this->queue->ack( $b ) );
		$this->assertSame( 2, $this->queue_count() );
	}

	public function test_release_keeps_identity_and_waits_for_the_retry_time() {
		$this->seed( 3 );
		$b = $this->queue->claim_next();
		$this->queue->release( $b, Clock::time() + 60 );

		$this->assertNull( $this->queue->claim_next(), 'A released batch must not be claimed before its retry time.' );
		Clock::advance( 59 );
		$this->assertNull( $this->queue->claim_next() );
		Clock::advance( 2 );

		$again = $this->queue->claim_next();
		$this->assertNotNull( $again );
		$this->assertSame( $b->id, $again->id, 'Same batch identity, so the same idempotency key.' );
		$this->assertSame( $b->key(), $again->key() );
		$this->assertSame( $b->payload, $again->payload, 'Byte-identical payload.' );
		$this->assertSame( 1, $again->attempts );
	}

	public function test_a_crashed_workers_batch_is_recovered_unchanged_after_the_lease_expires() {
		$this->seed( 4 );
		$crashed = $this->queue->claim_next(); // Worker dies here: no ack, no release.

		$this->assertNull( $this->queue->claim_next(), 'A live lease must protect the batch from a second worker.' );
		Clock::advance( (int) Limits::get( 'lease_seconds' ) - 1 );
		$this->assertNull( $this->queue->claim_next() );
		Clock::advance( 2 );

		$this->assertSame( 1, $this->queue->stats()['stale_leases'] > 0 ? 1 : 0, 'The expired lease is visible to health checks.' );
		$recovered = $this->queue->claim_next();
		$this->assertNotNull( $recovered );
		$this->assertSame( $crashed->id, $recovered->id );
		$this->assertSame( $crashed->key(), $recovered->key() );
		$this->assertSame( $crashed->payload, $recovered->payload );
		$this->assertNotSame( $crashed->token, $recovered->token, 'The recovering worker holds a fresh lease.' );
	}

	public function test_a_stale_worker_cannot_release_a_batch_it_no_longer_owns() {
		$this->seed( 2 );
		$old = $this->queue->claim_next();
		Clock::advance( 1000 );
		$new = $this->queue->claim_next();
		$this->assertSame( $old->id, $new->id );

		// The first worker wakes up late and releases: it must not clear the new owner's lease.
		$this->queue->release( $old, Clock::time() + 500 );
		$this->assertNull( $this->queue->claim_next(), 'The new owner still holds the lease.' );
	}

	public function test_split_creates_two_new_ids_and_preserves_order() {
		$docs = $this->seed( 5 );
		$b    = $this->queue->claim_next();
		list( $first, $second ) = $this->queue->split( $b, 2 );

		$this->assertNotSame( $b->id, $first );
		$this->assertNotSame( $b->id, $second );
		$this->assertNotSame( $first, $second );

		$a = $this->queue->claim_next();
		$c = $this->queue->claim_next();
		$this->assertSame( array( $first, $second ), array( $a->id, $c->id ) );
		$this->assertSame( '[' . implode( ',', array_slice( $docs, 0, 3 ) ) . ']', $a->payload );
		$this->assertSame( '[' . implode( ',', array_slice( $docs, 3 ) ) . ']', $c->payload );
		$this->assertSame( 2, $a->attempts );
		$this->assertNull( $this->queue->claim_next() );
	}

	public function test_rekey_assigns_a_new_identity_to_the_same_events() {
		$this->seed( 3 );
		$b   = $this->queue->claim_next();
		$new = $this->queue->rekey( $b );
		$r   = $this->queue->claim_next();
		$this->assertSame( $new, $r->id );
		$this->assertNotSame( $b->key(), $r->key() );
		$this->assertSame( $b->payload, $r->payload );
	}

	public function test_purge_removes_expired_events_whole_batches_and_never_live_leases() {
		$this->limit( array( 'batch_max_events' => 2, 'retention_seconds' => 3600 ) );
		$this->seed( 5 );
		$b = $this->queue->claim_next(); // 2 rows batched, 3 unbatched.
		$this->queue->release( $b, Clock::time() + 10 ); // Waiting for retry.

		Clock::advance( 3601 ); // Everything is now older than retention.
		$live = $this->queue->claim_next(); // Claims the waiting batch: live lease.
		$this->assertNotNull( $live );

		$cutoff  = Clock::time() - (int) Limits::get( 'retention_seconds' );
		$deleted = $this->queue->purge_expired( $cutoff );
		$this->assertSame( 3, $deleted, 'Unbatched expired rows go; the leased batch stays.' );
		$this->assertSame( 2, $this->queue_count() );

		$this->queue->release( $live, Clock::time() );
		$deleted = $this->queue->purge_expired( $cutoff );
		$this->assertSame( 2, $deleted, 'Once released, the expired batch is removed whole.' );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_purge_keeps_fresh_events() {
		$this->seed( 3 );
		$this->assertSame( 0, $this->queue->purge_expired( Clock::time() - 3600 ) );
		$this->assertSame( 3, $this->queue_count() );
	}

	public function test_clear_removes_everything_including_leased_rows() {
		$this->seed( 4 );
		$this->queue->claim_next();
		$this->assertSame( 4, $this->queue->clear() );
		$this->assertSame( 0, $this->queue_count() );
	}

	public function test_release_backoff_makes_waiting_batches_claimable() {
		$this->seed( 2 );
		$b = $this->queue->claim_next();
		$this->queue->release( $b, Clock::time() + 3000 );
		$this->assertNull( $this->queue->claim_next() );
		$this->queue->release_backoff();
		$this->assertNotNull( $this->queue->claim_next() );
	}

	public function test_release_backoff_does_not_steal_a_live_lease() {
		$this->seed( 2 );
		$this->queue->claim_next();
		$this->queue->release_backoff();
		$this->assertNull( $this->queue->claim_next() );
	}

	public function test_pending_fingerprints() {
		$rows = array();
		foreach ( array( 'aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', '' ) as $fp ) {
			$rows[] = array( 'payload' => '{}', 'size' => 2, 'severity' => 2, 'fingerprint' => $fp, 'created_at' => Clock::time() );
		}
		$this->queue->insert_many( $rows );
		$found = $this->queue->pending_fingerprints( array( 'aaaaaaaaaaaaaaaa', 'cccccccccccccccc', '' ) );
		$this->assertSame( array( 'aaaaaaaaaaaaaaaa' ), $found );
		$this->assertSame( array(), $this->queue->pending_fingerprints( array() ) );
	}

	public function test_next_ready_at() {
		$this->assertSame( 0, $this->queue->next_ready_at() );
		$this->seed( 1 );
		$this->assertSame( Clock::time(), $this->queue->next_ready_at() );
		$b = $this->queue->claim_next();
		$this->queue->release( $b, Clock::time() + 500 );
		$this->assertSame( Clock::time() + 500, $this->queue->next_ready_at() );
	}

	public function test_special_characters_survive_storage_byte_for_byte() {
		$payload = wp_json_encode( array( 'message' => "100% sure %s %d {hash} 'quote' \"dq\" \\ back \u{1F600} <b>x</b>" ) );
		$this->queue->insert_many( array( array( 'payload' => $payload, 'size' => strlen( $payload ), 'severity' => 2, 'fingerprint' => '', 'created_at' => Clock::time() ) ) );
		$b = $this->queue->claim_next();
		$this->assertSame( '[' . $payload . ']', $b->payload );
	}

	public function test_schema_is_versioned_and_idempotent() {
		$this->assertSame( ZIPLOGGER_DB_VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Schema::tables_exist() );
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
		foreach ( array( 'id', 'created_at', 'available_at', 'batch_id', 'lease_token', 'lease_until', 'attempts', 'payload', 'fingerprint' ) as $col ) {
			$this->assertContains( $col, $columns );
		}
		$indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . Schema::queue_table(), 2 ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'batch_id', $indexes );
		$this->assertContains( 'fingerprint', $indexes );
		$this->assertContains( 'created_at', $indexes );
	}
}
