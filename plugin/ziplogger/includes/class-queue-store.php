<?php
/**
 * The bounded, persistent queue.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Concurrency model
 * -----------------
 * Claiming is a single UPDATE ... ORDER BY id LIMIT n that sets batch_id / lease_token / lease_until
 * on rows that have no batch yet. InnoDB row locks serialize concurrent claims, so two workers get
 * disjoint rows. A batch that failed keeps its batch_id (identity) with the lease cleared and an
 * available_at in the future; when available_at passes it is claimed again as the same batch. A
 * worker that crashes simply stops renewing: its lease_until passes and the batch is claimed again
 * as the same batch, with the same bytes and the same Idempotency-Key.
 *
 * Signals and destinations
 * ------------------------
 * Rows carry the signal they belong to (a batch never mixes signals) and the destination they were
 * collected for (see Destination). Claiming for a destination never touches rows collected for
 * another one. Passing null for either filter means "any" (used by maintenance and by tests).
 *
 * All statements use $wpdb->prepare(); table names come from $wpdb->prefix and a constant.
 */
final class Queue_Store {

	/**
	 * Insert rows. Each: payload (JSON text), size, severity (rank), fingerprint, created_at (Unix),
	 * and optionally sig (default logs) and destination (default '').
	 *
	 * @param array[] $rows Rows.
	 * @return int Rows inserted.
	 */
	public function insert_many( array $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return 0;
		}
		$table    = Schema::queue_table();
		$inserted = 0;
		foreach ( array_chunk( $rows, 50 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$created  = Clock::mysql( isset( $row['created_at'] ) ? (int) $row['created_at'] : null );
				$values[] = $wpdb->prepare(
					'(%s, %s, %d, %s, %s, %s, %d, %s)',
					$created,
					$created,
					(int) $row['severity'],
					(string) $row['fingerprint'],
					isset( $row['sig'] ) ? (string) $row['sig'] : Signal::LOGS,
					isset( $row['destination'] ) ? (string) $row['destination'] : '',
					(int) $row['size'],
					(string) $row['payload']
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
			$result = $wpdb->query( "INSERT INTO {$table} (created_at, available_at, severity, fingerprint, sig, destination, size, payload) VALUES " . implode( ', ', $values ) );
			if ( false !== $result ) {
				$inserted += (int) $result;
			}
		}
		return $inserted;
	}

	/**
	 * Queue totals.
	 *
	 * @param string|null $signal      Limit to one signal, or null for all.
	 * @param string|null $destination When given, also reports "held": rows bound to another destination.
	 * @return array{count:int,bytes:int,oldest:int,batched:int,stale_leases:int,held:int}
	 */
	public function stats( $signal = null, $destination = null ) {
		global $wpdb;
		$table = Schema::queue_table();
		$now   = Clock::mysql();
		$where = null !== $signal ? $wpdb->prepare( 'WHERE sig = %s', $signal ) : '';
		$held  = null !== $destination && '' !== $destination
			? $wpdb->prepare( "COALESCE(SUM(destination <> '' AND destination <> %s), 0)", $destination )
			: '0';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS b, MIN(created_at) AS o, COALESCE(SUM(batch_id <> ''), 0) AS batched, COALESCE(SUM(lease_until IS NOT NULL AND lease_until < %s), 0) AS stale, {$held} AS held FROM {$table} {$where}", $now ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array(
				'count'        => 0,
				'bytes'        => 0,
				'oldest'       => 0,
				'batched'      => 0,
				'stale_leases' => 0,
				'held'         => 0,
			);
		}
		return array(
			'count'        => (int) $row['c'],
			'bytes'        => (int) $row['b'],
			'oldest'       => Clock::from_mysql( $row['o'] ),
			'batched'      => (int) $row['batched'],
			'stale_leases' => (int) $row['stale'],
			'held'         => (int) $row['held'],
		);
	}

	/**
	 * Row counts per signal.
	 *
	 * @return array<string,int>
	 */
	public function counts_by_signal() {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$rows = $wpdb->get_results( "SELECT sig, COUNT(*) AS c FROM {$table} GROUP BY sig", ARRAY_A );
		$out  = array_fill_keys( Signal::ALL, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['sig'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Which of these fingerprints already have a pending row.
	 *
	 * @param string[] $fingerprints Fingerprints.
	 * @return string[]
	 */
	public function pending_fingerprints( array $fingerprints ) {
		global $wpdb;
		$fingerprints = array_values( array_filter( array_unique( $fingerprints ) ) );
		if ( ! $fingerprints ) {
			return array();
		}
		$table        = Schema::queue_table();
		$placeholders = implode( ', ', array_fill( 0, count( $fingerprints ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT fingerprint FROM {$table} WHERE fingerprint IN ({$placeholders})", $fingerprints ) );
		return is_array( $found ) ? $found : array();
	}

	/**
	 * SQL fragment restricting rows to a destination (rows bound elsewhere are "held").
	 *
	 * @param string|null $destination Destination or null for any.
	 * @return string
	 */
	private function destination_clause( $destination ) {
		global $wpdb;
		// Unbound rows (collected before any key existed) belong to whatever destination is configured.
		return null === $destination ? '' : $wpdb->prepare( " AND (destination = %s OR destination = '')", $destination );
	}

	/**
	 * Claim the next batch of a signal, or null when nothing is ready.
	 *
	 * Batches that failed earlier (and whose retry time has come, or whose lease expired after a
	 * crash) are preferred over fresh rows, oldest first.
	 *
	 * @param string      $signal      Signal.
	 * @param string|null $destination Destination fingerprint, or null for any.
	 * @return Batch|null
	 */
	public function claim_next( $signal = Signal::LOGS, $destination = null ) {
		$batch = $this->claim_existing( $signal, $destination );
		if ( null !== $batch ) {
			return $batch;
		}
		return $this->claim_new( $signal, $destination );
	}

	/**
	 * Claim a previously formed batch whose retry time has passed or whose lease has expired.
	 *
	 * @param string      $signal      Signal.
	 * @param string|null $destination Destination or null.
	 * @return Batch|null
	 */
	private function claim_existing( $signal, $destination ) {
		global $wpdb;
		$table = Schema::queue_table();
		$now   = Clock::mysql();
		$lease = Clock::mysql( Clock::time() + (int) Limits::get( 'lease_seconds' ) );
		$dest  = $this->destination_clause( $destination );

		for ( $try = 0; $try < 3; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
			$batch_id = $wpdb->get_var( $wpdb->prepare( "SELECT batch_id FROM {$table} WHERE sig = %s AND batch_id <> '' AND available_at <= %s AND (lease_until IS NULL OR lease_until < %s){$dest} GROUP BY batch_id ORDER BY MIN(id) ASC LIMIT 1", $signal, $now, $now ) );
			if ( ! is_string( $batch_id ) || '' === $batch_id ) {
				return null;
			}
			$token = Batch::new_id();
			// The lease condition is repeated in the UPDATE: of two workers that saw the same batch,
			// exactly one changes rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_token = %s, lease_until = %s, first_attempt_at = COALESCE(first_attempt_at, %s) WHERE batch_id = %s AND (lease_until IS NULL OR lease_until < %s)", $token, $lease, $now, $batch_id, $now ) );
			if ( $claimed ) {
				$batch = $this->load( $batch_id, $token, $signal );
				if ( null !== $batch ) {
					return $batch;
				}
			}
		}
		return null;
	}

	/**
	 * Form a new batch from rows that have none.
	 *
	 * @param string      $signal      Signal.
	 * @param string|null $destination Destination or null.
	 * @return Batch|null
	 */
	private function claim_new( $signal, $destination ) {
		global $wpdb;
		$table    = Schema::queue_table();
		$now      = Clock::mysql();
		$lease    = Clock::mysql( Clock::time() + (int) Limits::get( 'lease_seconds' ) );
		$batch_id = Batch::new_id();
		$token    = Batch::new_id();
		$dest     = $this->destination_clause( $destination );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET batch_id = %s, lease_token = %s, lease_until = %s, first_attempt_at = %s WHERE sig = %s AND batch_id = ''{$dest} ORDER BY id ASC LIMIT %d", $batch_id, $token, $lease, $now, $signal, (int) Limits::get( 'batch_max_events' ) ) );
		if ( ! $claimed ) {
			return null;
		}

		// Enforce the byte ceiling by handing the tail back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$sizes    = $wpdb->get_results( $wpdb->prepare( "SELECT id, size FROM {$table} WHERE batch_id = %s ORDER BY id ASC", $batch_id ), ARRAY_A );
		$sizes    = is_array( $sizes ) ? $sizes : array();
		$total    = 0;
		$keep_to  = 0;
		$kept     = 0;
		$last_row = 0;
		$max      = (int) Limits::get( 'batch_max_bytes' );
		$cut      = false;
		foreach ( $sizes as $row ) {
			$last_row = (int) $row['id'];
			$total   += (int) $row['size'];
			if ( ! $cut && $kept > 0 && $total > $max ) {
				$cut = true;
			}
			if ( ! $cut ) {
				$keep_to = (int) $row['id'];
				++$kept;
			}
		}
		if ( $cut && $keep_to > 0 && $last_row > $keep_to ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET batch_id = '', lease_token = '', lease_until = NULL, first_attempt_at = NULL WHERE batch_id = %s AND id > %d", $batch_id, $keep_to ) );
		}
		return $this->load( $batch_id, $token, $signal );
	}

	/**
	 * Load the rows of a batch that this token has leased.
	 *
	 * @param string $batch_id Batch id.
	 * @param string $token    Lease token.
	 * @param string $signal   Signal.
	 * @return Batch|null
	 */
	private function load( $batch_id, $token, $signal ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload, attempts, partial, first_attempt_at FROM {$table} WHERE batch_id = %s AND lease_token = %s ORDER BY id ASC", $batch_id, $token ), ARRAY_A );
		if ( ! $rows ) {
			return null;
		}
		$ids      = array();
		$payloads = array();
		$attempts = 0;
		$partial  = false;
		$first    = 0;
		foreach ( $rows as $row ) {
			$ids[]      = (int) $row['id'];
			$payloads[] = $row['payload'];
			$attempts   = max( $attempts, (int) $row['attempts'] );
			$partial    = $partial || 1 === (int) $row['partial'];
			$first      = $first > 0 ? $first : Clock::from_mysql( $row['first_attempt_at'] );
		}
		return new Batch( $batch_id, $token, $ids, $payloads, $attempts, $first, $partial, $signal );
	}

	/**
	 * Delete a delivered batch.
	 *
	 * @param Batch $batch Batch.
	 * @return int Rows deleted.
	 */
	public function ack( Batch $batch ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE batch_id = %s", $batch->id ) );
	}

	/**
	 * Release a batch for a later retry. The batch keeps its id, so the retry is the same request.
	 *
	 * @param Batch $batch         Batch.
	 * @param int   $retry_at      Unix time before which it must not be claimed.
	 * @param bool  $count_attempt Whether this counts as a failed attempt.
	 * @param bool  $partial       Whether the backend reported partial acceptance.
	 * @return void
	 */
	public function release( Batch $batch, $retry_at, $count_attempt = true, $partial = false ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_token = '', lease_until = NULL, available_at = %s, attempts = attempts + %d, partial = GREATEST(partial, %d) WHERE batch_id = %s AND lease_token = %s", Clock::mysql( (int) $retry_at ), $count_attempt ? 1 : 0, $partial ? 1 : 0, $batch->id, $batch->token ) );
		$batch->attempts += $count_attempt ? 1 : 0;
		$batch->partial   = $batch->partial || $partial;
	}

	/**
	 * Split a batch into two halves with NEW ids (new payloads need new idempotency keys). One
	 * statement, so a crash cannot leave half of the batch under the old id.
	 *
	 * @param Batch $batch    Batch to split (must hold at least two items).
	 * @param int   $attempts Attempts credited to both halves.
	 * @return string[] The two new batch ids.
	 */
	public function split( Batch $batch, $attempts ) {
		global $wpdb;
		$table     = Schema::queue_table();
		$mid       = (int) ceil( $batch->count / 2 );
		$boundary  = $batch->ids[ $mid - 1 ];
		$first_id  = Batch::new_id();
		$second_id = Batch::new_id();
		$now       = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET batch_id = CASE WHEN id <= %d THEN %s ELSE %s END, lease_token = '', lease_until = NULL, attempts = %d, available_at = %s, first_attempt_at = %s WHERE batch_id = %s AND lease_token = %s", $boundary, $first_id, $second_id, (int) $attempts, $now, $now, $batch->id, $batch->token ) );
		return array( $first_id, $second_id );
	}

	/**
	 * Give a batch a new id (a new idempotency key for the same items) and make it claimable at once.
	 *
	 * @param Batch $batch Batch.
	 * @return string The new batch id.
	 */
	public function rekey( Batch $batch ) {
		global $wpdb;
		$table  = Schema::queue_table();
		$new_id = Batch::new_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET batch_id = %s, lease_token = '', lease_until = NULL, available_at = %s, attempts = attempts + 1 WHERE batch_id = %s AND lease_token = %s", $new_id, Clock::mysql(), $batch->id, $batch->token ) );
		return $new_id;
	}

	/**
	 * Delete rows older than the cutoff. Batches are removed whole (a batch whose membership changed
	 * would no longer match the payload its idempotency key was first used with), and never while leased.
	 *
	 * @param int $cutoff Unix time; rows created before it expire.
	 * @return int Rows deleted.
	 */
	public function purge_expired( $cutoff ) {
		global $wpdb;
		$table   = Schema::queue_table();
		$cut     = Clock::mysql( (int) $cutoff );
		$now     = Clock::mysql();
		$deleted = 0;

		for ( $i = 0; $i < 5; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
			$n        = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE batch_id = '' AND created_at < %s LIMIT 2000", $cut ) );
			$deleted += $n;
			if ( $n < 2000 ) {
				break;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$batches = $wpdb->get_col( $wpdb->prepare( "SELECT batch_id FROM {$table} WHERE batch_id <> '' GROUP BY batch_id HAVING MIN(created_at) < %s LIMIT 50", $cut ) );
		foreach ( (array) $batches as $batch_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE batch_id = %s AND (lease_until IS NULL OR lease_until < %s)", $batch_id, $now ) );
		}
		return $deleted;
	}

	/**
	 * Make every waiting (unleased) batch claimable right now. Used by a manual flush, which should
	 * not wait out retry timers.
	 *
	 * @return void
	 */
	public function release_backoff() {
		global $wpdb;
		$table = Schema::queue_table();
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET available_at = %s WHERE batch_id <> '' AND available_at > %s AND (lease_until IS NULL OR lease_until < %s)", $now, $now, $now ) );
	}

	/**
	 * Delete everything, including leased rows (an administrator's explicit action).
	 *
	 * @return int Rows deleted.
	 */
	public function clear() {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Attach rows that were collected without a destination to the given one.
	 *
	 * @param string $destination Destination fingerprint.
	 * @return int Rows bound.
	 */
	public function bind_unbound( $destination ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET destination = %s WHERE destination = ''", $destination ) );
	}

	/**
	 * Rows collected for a different destination than the given one.
	 *
	 * @param string $destination Current destination.
	 * @return int
	 */
	public function held_count( $destination ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE destination <> '' AND destination <> %s", $destination ) );
	}

	/**
	 * Send held rows to the current destination: the administrator has confirmed that the new key or
	 * endpoint belongs to the same workspace. Batches keep their identity.
	 *
	 * @param string $destination Current destination.
	 * @return int Rows re-targeted.
	 */
	public function retarget_held( $destination ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET destination = %s WHERE destination <> '' AND destination <> %s", $destination, $destination ) );
	}

	/**
	 * Delete held rows.
	 *
	 * @param string $destination Current destination.
	 * @return int Rows deleted.
	 */
	public function discard_held( $destination ) {
		global $wpdb;
		$table = Schema::queue_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE destination <> '' AND destination <> %s", $destination ) );
	}

	/**
	 * When the oldest waiting work of a signal became due (Unix), or 0 when there is none. Fresh rows
	 * are due one delivery delay after they were queued; a batch waiting for a retry is due when its
	 * timer ends. Health checks compare this with "now" to tell whether the worker is overdue.
	 *
	 * @param string|null $signal      Signal or null for all.
	 * @param string|null $destination Destination or null for any.
	 * @return int
	 */
	public function due_at( $signal = null, $destination = null ) {
		global $wpdb;
		$table = Schema::queue_table();
		$where = ( null !== $signal ? $wpdb->prepare( ' AND sig = %s', $signal ) : '' ) . $this->destination_clause( $destination );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
		$fresh = Clock::from_mysql( $wpdb->get_var( "SELECT MIN(created_at) FROM {$table} WHERE batch_id = ''{$where}" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
		$retry = Clock::from_mysql( $wpdb->get_var( "SELECT MIN(GREATEST(available_at, COALESCE(lease_until, available_at))) FROM {$table} WHERE batch_id <> ''{$where}" ) );

		$fresh_due = $fresh > 0 ? $fresh + (int) Limits::get( 'first_delivery_delay' ) : 0;
		if ( $fresh_due > 0 && $retry > 0 ) {
			return min( $fresh_due, $retry );
		}
		return max( $fresh_due, $retry );
	}

	/**
	 * The earliest time something can be sent (Unix), or 0 when there is nothing.
	 *
	 * @param string|null $signal      Signal or null for all.
	 * @param string|null $destination Destination or null for any.
	 * @return int
	 */
	public function next_ready_at( $signal = null, $destination = null ) {
		global $wpdb;
		$table = Schema::queue_table();
		$where = ( null !== $signal ? $wpdb->prepare( ' AND sig = %s', $signal ) : '' ) . $this->destination_clause( $destination );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
		$unbatched = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$table} WHERE batch_id = ''{$where} LIMIT 1) AS t" );
		if ( $unbatched > 0 ) {
			return Clock::time();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table (the site prefix plus a constant); the only other interpolated text is a fragment that was itself produced by prepare().
		$min = $wpdb->get_var( "SELECT MIN(GREATEST(available_at, COALESCE(lease_until, available_at))) FROM {$table} WHERE 1 = 1{$where}" );
		return Clock::from_mysql( $min );
	}
}
