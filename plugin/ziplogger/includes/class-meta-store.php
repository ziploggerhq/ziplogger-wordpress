<?php
/**
 * Counters and delivery state, stored with atomic SQL upserts.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Counters must survive concurrent requests: a read-modify-write on a WordPress option would lose
 * increments whenever two PHP workers dropped events at the same moment. Every counter here is a
 * single INSERT ... ON DUPLICATE KEY UPDATE num = num + n.
 */
final class Meta_Store {

	// Counters (num).
	const SENT_EVENTS  = 'sent_events';
	const SENT_BATCHES = 'sent_batches';
	const SUPPRESSED   = 'suppressed';

	// Counted per signal (the key gets a signal suffix, see sk()).
	const SERVER_REJECTED = 'server_rejected';

	// Drop reasons: "drop_<reason>".
	const DROP_REASONS = array(
		'overflow',
		'request_cap',
		'oversize',
		'unencodable',
		'expired',
		'poison',
		'rejected',
		'cleared',
		'storage',
	);

	// State.
	const LAST_SUCCESS       = 'last_success_at';
	const LAST_ERROR         = 'last_error';
	const LAST_TEST          = 'last_test';
	const GATE_UNTIL         = 'gate_until';
	const FAILURES           = 'consecutive_failures';
	const BLOCKED            = 'blocked';
	const WORKER_LAST_RUN    = 'worker_last_run';
	const WORKER_LAST_RESULT = 'worker_last_result';
	const ACTIVATED_AT       = 'activated_at';

	/**
	 * Atomically add to a counter.
	 *
	 * @param string $key Counter name.
	 * @param int    $by  Amount (> 0).
	 * @return void
	 */
	public function incr( $key, $by = 1 ) {
		global $wpdb;
		$by = (int) $by;
		if ( $by <= 0 ) {
			return;
		}
		$table = Schema::meta_table();
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (k, num, updated_at) VALUES (%s, %d, %s) ON DUPLICATE KEY UPDATE num = num + %d, updated_at = %s", $key, $by, $now, $by, $now ) );
	}

	/**
	 * Count a dropped event under a reason.
	 *
	 * @param string $reason One of DROP_REASONS.
	 * @param int    $count  Number of items.
	 * @param string $signal Signal the items belonged to.
	 * @return void
	 */
	public function dropped( $reason, $count = 1, $signal = Signal::LOGS ) {
		$this->incr( 'drop_' . $reason . Signal::suffix( $signal ), $count );
	}

	/**
	 * A state key for a signal. Logs keep the original, unsuffixed key.
	 *
	 * @param string $base   Base key (for example Meta_Store::GATE_UNTIL).
	 * @param string $signal Signal.
	 * @return string
	 */
	public function sk( $base, $signal ) {
		return $base . Signal::suffix( $signal );
	}

	/**
	 * Set a numeric value.
	 *
	 * @param string $key   Key.
	 * @param int    $value Value (>= 0).
	 * @return void
	 */
	public function set_num( $key, $value ) {
		global $wpdb;
		$table = Schema::meta_table();
		$now   = Clock::mysql();
		$value = max( 0, (int) $value );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (k, num, updated_at) VALUES (%s, %d, %s) ON DUPLICATE KEY UPDATE num = %d, updated_at = %s", $key, $value, $now, $value, $now ) );
	}

	/**
	 * Set a string value.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 * @return void
	 */
	public function set_str( $key, $value ) {
		global $wpdb;
		$table = Schema::meta_table();
		$now   = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (k, num, str, updated_at) VALUES (%s, 0, %s, %s) ON DUPLICATE KEY UPDATE str = %s, updated_at = %s", $key, $value, $now, $value, $now ) );
	}

	/**
	 * Read a numeric value.
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public function get_num( $key ) {
		global $wpdb;
		$table = Schema::meta_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT num FROM {$table} WHERE k = %s", $key ) );
	}

	/**
	 * Read a string value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function get_str( $key ) {
		global $wpdb;
		$table = Schema::meta_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT str FROM {$table} WHERE k = %s", $key ) );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * Read a JSON-encoded state value.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public function get_json( $key ) {
		$raw = $this->get_str( $key );
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Store a JSON-encoded state value.
	 *
	 * @param string $key   Key.
	 * @param array  $value Value.
	 * @return void
	 */
	public function set_json( $key, array $value ) {
		$this->set_str( $key, (string) wp_json_encode( $value ) );
	}

	/**
	 * Read a counter and reset it to zero (used for per-fingerprint suppression counts).
	 *
	 * @param string $key Key.
	 * @return int
	 */
	public function take( $key ) {
		$n = $this->get_num( $key );
		if ( $n > 0 ) {
			$this->set_num( $key, 0 );
		}
		return $n;
	}

	/**
	 * Read several counters and reset the non-zero ones (one SELECT, at most one UPDATE).
	 *
	 * @param string[] $keys Keys.
	 * @return array<string,int> Non-zero values keyed by key.
	 */
	public function take_many( array $keys ) {
		global $wpdb;
		$keys = array_values( array_unique( $keys ) );
		if ( ! $keys ) {
			return array();
		}
		$table        = Schema::meta_table();
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT k, num FROM {$table} WHERE num > 0 AND k IN ({$placeholders})", $keys ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['k'] ] = (int) $row['num'];
		}
		if ( $out ) {
			$hit          = array_keys( $out );
			$placeholders = implode( ', ', array_fill( 0, count( $hit ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET num = 0 WHERE k IN ({$placeholders})", $hit ) );
		}
		return $out;
	}

	/**
	 * All drop counters keyed by reason, summed over every signal.
	 *
	 * @return array<string,int>
	 */
	public function drop_counts() {
		$out = array_fill_keys( self::DROP_REASONS, 0 );
		foreach ( $this->drop_rows() as $row ) {
			if ( isset( $out[ $row['reason'] ] ) ) {
				$out[ $row['reason'] ] += $row['num'];
			}
		}
		return $out;
	}

	/**
	 * Drop counters per signal: [ signal => [ reason => count ] ].
	 *
	 * @return array<string,array<string,int>>
	 */
	public function drop_counts_by_signal() {
		$out = array();
		foreach ( Signal::ALL as $signal ) {
			$out[ $signal ] = array_fill_keys( self::DROP_REASONS, 0 );
		}
		foreach ( $this->drop_rows() as $row ) {
			if ( isset( $out[ $row['signal'] ][ $row['reason'] ] ) ) {
				$out[ $row['signal'] ][ $row['reason'] ] += $row['num'];
			}
		}
		return $out;
	}

	/**
	 * Parsed drop rows: reason, signal, num.
	 *
	 * @return array[]
	 */
	private function drop_rows() {
		global $wpdb;
		$table = Schema::meta_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		$rows = $wpdb->get_results( "SELECT k, num FROM {$table} WHERE k LIKE 'drop\_%'", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$rest   = substr( $row['k'], 5 );
			$signal = Signal::LOGS;
			$colon  = strpos( $rest, ':' );
			if ( false !== $colon ) {
				$signal = substr( $rest, $colon + 1 );
				$rest   = substr( $rest, 0, $colon );
			}
			$out[] = array(
				'reason' => $rest,
				'signal' => $signal,
				'num'    => (int) $row['num'],
			);
		}
		return $out;
	}

	/**
	 * Total dropped events across all reasons.
	 *
	 * @return int
	 */
	public function dropped_total() {
		return array_sum( $this->drop_counts() );
	}

	/**
	 * Delete per-fingerprint suppression counters that were not touched for a while.
	 *
	 * @param int $older_than_seconds Age threshold.
	 * @return int Rows deleted.
	 */
	public function purge_stale( $older_than_seconds ) {
		global $wpdb;
		$table  = Schema::meta_table();
		$cutoff = Clock::mysql( Clock::time() - (int) $older_than_seconds );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table (the site prefix plus a constant); values, where there are any, are bound with prepare().
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE k LIKE %s AND updated_at < %s", 'sup:%', $cutoff ) );
	}

	/**
	 * Reset the delivery gate and failure streak (after a settings change or a successful send).
	 *
	 * @param string|null $signal One signal, or null for all of them.
	 * @return void
	 */
	public function clear_gate( $signal = null ) {
		foreach ( null === $signal ? Signal::ALL : array( $signal ) as $sig ) {
			$this->set_num( $this->sk( self::GATE_UNTIL, $sig ), 0 );
			$this->set_num( $this->sk( self::FAILURES, $sig ), 0 );
			$this->set_str( $this->sk( self::BLOCKED, $sig ), '' );
		}
	}
}
