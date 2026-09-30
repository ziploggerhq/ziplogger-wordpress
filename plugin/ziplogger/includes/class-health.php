<?php
/**
 * Delivery health snapshot for the admin screen and WP-CLI.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One place that answers "is delivery healthy?" so the admin UI and the CLI agree. Top-level
 * last_success / last_error / blocked / gate_until / failures describe the logs signal (kept for
 * compatibility); signals[<name>] carries the same for every signal.
 */
final class Health {

	/**
	 * Gather the current state.
	 *
	 * @return array
	 */
	public static function snapshot() {
		$meta  = new Meta_Store();
		$queue = new Queue_Store();
		$now   = Clock::time();

		$tables_ok   = Schema::tables_exist();
		$destination = Destination::current();
		$empty_stats = array(
			'count'        => 0,
			'bytes'        => 0,
			'oldest'       => 0,
			'batched'      => 0,
			'stale_leases' => 0,
			'held'         => 0,
		);
		$stats       = $tables_ok ? $queue->stats( null, $destination ) : $empty_stats;
		$counts      = $tables_ok ? $queue->counts_by_signal() : array_fill_keys( Signal::ALL, 0 );

		$enabled    = Settings::is_enabled();
		$configured = Settings::is_configured();
		$last_run   = $tables_ok ? $meta->get_num( Meta_Store::WORKER_LAST_RUN ) : 0;
		$overdue_by = 0;
		$drops_by   = $tables_ok ? $meta->drop_counts_by_signal() : array();

		$signals = array();
		foreach ( Signal::ALL as $signal ) {
			$gate    = $tables_ok ? $meta->get_num( $meta->sk( Meta_Store::GATE_UNTIL, $signal ) ) : 0;
			$due     = ( $tables_ok && $counts[ $signal ] > 0 ) ? max( $queue->due_at( $signal, '' !== $destination ? $destination : null ), $gate ) : 0;
			$overdue = $enabled && $configured && $counts[ $signal ] > 0 && $due > 0 && ( $now - max( $due, $last_run ) ) > (int) Limits::get( 'overdue_after' );
			if ( $overdue ) {
				$overdue_by = max( $overdue_by, $now - max( $due, $last_run ) );
			}
			$signals[ $signal ] = array(
				'pending'         => $counts[ $signal ],
				'last_success'    => $tables_ok ? $meta->get_num( $meta->sk( Meta_Store::LAST_SUCCESS, $signal ) ) : 0,
				'last_error'      => $tables_ok ? $meta->get_json( $meta->sk( Meta_Store::LAST_ERROR, $signal ) ) : array(),
				'blocked'         => $tables_ok ? $meta->get_str( $meta->sk( Meta_Store::BLOCKED, $signal ) ) : '',
				'gate_until'      => $gate,
				'failures'        => $tables_ok ? $meta->get_num( $meta->sk( Meta_Store::FAILURES, $signal ) ) : 0,
				'sent_events'     => $tables_ok ? $meta->get_num( $meta->sk( Meta_Store::SENT_EVENTS, $signal ) ) : 0,
				'sent_batches'    => $tables_ok ? $meta->get_num( $meta->sk( Meta_Store::SENT_BATCHES, $signal ) ) : 0,
				'server_rejected' => $tables_ok ? $meta->get_num( $meta->sk( Meta_Store::SERVER_REJECTED, $signal ) ) : 0,
				'drops'           => isset( $drops_by[ $signal ] ) ? $drops_by[ $signal ] : array_fill_keys( Meta_Store::DROP_REASONS, 0 ),
				'overdue'         => $overdue,
			);
		}

		$last_test = $tables_ok ? $meta->get_json( Meta_Store::LAST_TEST ) : array();
		$drops     = $tables_ok ? $meta->drop_counts() : array_fill_keys( Meta_Store::DROP_REASONS, 0 );
		$logs      = $signals[ Signal::LOGS ];

		$any_blocked = false;
		$any_failing = false;
		$any_overdue = false;
		$last_ok     = 0;
		foreach ( $signals as $sig ) {
			$any_blocked = $any_blocked || ( '' !== $sig['blocked'] && $sig['gate_until'] > $now );
			$any_failing = $any_failing || ( $sig['failures'] > 0 && $sig['gate_until'] > $now );
			$any_overdue = $any_overdue || $sig['overdue'];
			$last_ok     = max( $last_ok, $sig['last_success'] );
		}

		$state = 'healthy';
		if ( ! $tables_ok ) {
			$state = 'broken';
		} elseif ( ! $enabled ) {
			$state = 'off';
		} elseif ( ! $configured ) {
			$state = 'unconfigured';
		} elseif ( $stats['held'] > 0 ) {
			$state = 'held';
		} elseif ( $any_blocked ) {
			$state = 'blocked';
		} elseif ( $any_overdue ) {
			$state = 'overdue';
		} elseif ( $any_failing ) {
			$state = 'failing';
		} elseif ( $stats['count'] > 0 ) {
			$state = 'waiting';
		} elseif ( 0 === $last_ok ) {
			$state = 'ready';
		}

		return array(
			'state'            => $state,
			'tables_ok'        => $tables_ok,
			'enabled'          => $enabled,
			'configured'       => $configured,
			'key_source'       => Settings::api_key_source(),
			'endpoint'         => Settings::endpoint_base(),
			'endpoint_problem' => Settings::endpoint_problem(),
			'pending'          => $stats['count'],
			'held'             => $stats['held'],
			'pending_bytes'    => $stats['bytes'],
			'oldest'           => $stats['oldest'],
			'in_retry'         => $stats['batched'],
			'stale_leases'     => $stats['stale_leases'],
			'drops'            => $drops,
			'dropped_total'    => array_sum( $drops ),
			'suppressed'       => $tables_ok ? $meta->get_num( Meta_Store::SUPPRESSED ) : 0,
			'sent_events'      => $logs['sent_events'],
			'sent_batches'     => $logs['sent_batches'],
			'last_success'     => $logs['last_success'],
			'last_error'       => $logs['last_error'],
			'last_test'        => $last_test,
			'blocked'          => $logs['blocked'],
			'gate_until'       => $logs['gate_until'],
			'failures'         => $logs['failures'],
			'signals'          => $signals,
			'next_run'         => Scheduler::next_delivery(),
			'worker_last_run'  => $last_run,
			'cron_disabled'    => Scheduler::cron_disabled(),
			'overdue'          => $any_overdue,
			'overdue_by'       => $overdue_by,
			'now'              => $now,
		);
	}
}
