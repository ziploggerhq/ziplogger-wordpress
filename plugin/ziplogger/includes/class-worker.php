<?php
/**
 * The delivery worker: claims batches, sends them, and applies the retry / isolation policy.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Policy by outcome (see Delivery_Result), applied independently per signal:
 *
 * The outcomes of a batch:
 *  success       delete the batch, clear that signal's failure streak.
 *  partial       keep the SAME batch and key; retry after Retry-After. Never split (that would resend
 *                items the backend already admitted under a different key).
 *  rate_limited  keep the batch, retry after Retry-After, pause that signal.
 *  auth / config keep everything, do not count an attempt, pause that signal (a fixed key or URL clears it).
 *  bad_request   400 & co: nothing was admitted, so split the batch in halves (new keys) until the
 *  too_large     offending item is alone, then drop just that item and count it.
 *  key_conflict  422: give the same items a new key.
 *  transient     retry the same batch and key with exponential backoff. Once a batch has failed
 *                several times WHILE OTHER DATA WENT THROUGH, it is treated as poison and isolated the
 *                same way. If nothing gets through (a real outage) nothing is ever dropped for
 *                failing: items wait, up to the retention window.
 *
 * The signals (logs, events, traces) are served round-robin so a backlog of one cannot starve another,
 * and each has its own pause and failure state: an unavailable traces endpoint does not stop logs.
 */
final class Worker {

	/**
	 * Queue.
	 *
	 * @var Queue_Store
	 */
	private $queue;

	/**
	 * Counters and state.
	 *
	 * @var Meta_Store
	 */
	private $meta;

	/**
	 * HTTP transport.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param Queue_Store|null $queue     Queue.
	 * @param Meta_Store|null  $meta      Meta store.
	 * @param Transport|null   $transport Transport.
	 */
	public function __construct( ?Queue_Store $queue = null, ?Meta_Store $meta = null, ?Transport $transport = null ) {
		$this->queue     = null !== $queue ? $queue : new Queue_Store();
		$this->meta      = null !== $meta ? $meta : new Meta_Store();
		$this->transport = null !== $transport ? $transport : new Transport();
	}

	/**
	 * Apply retention: expired items are deleted and counted.
	 *
	 * @return int Items expired.
	 */
	public function maintain() {
		$expired = $this->queue->purge_expired( Clock::time() - (int) Limits::get( 'retention_seconds' ) );
		if ( $expired > 0 ) {
			$this->meta->dropped( 'expired', $expired );
		}
		$this->meta->purge_stale( (int) Limits::get( 'meta_retention_seconds' ) );
		return $expired;
	}

	/**
	 * Run one delivery pass.
	 *
	 * @param array $opts force (bypass the pauses and retry timers), max_seconds, max_batches, signals.
	 * @return array Report: status, batches, events_sent, splits, dropped, error, pending, held, next_run.
	 */
	public function run( array $opts = array() ) {
		$opts = array_merge(
			array(
				'force'       => false,
				'max_seconds' => (int) Limits::get( 'worker_max_seconds' ),
				'max_batches' => (int) Limits::get( 'worker_max_batches' ),
				'signals'     => Signal::ALL,
			),
			$opts
		);

		$report = array(
			'status'      => 'idle',
			'batches'     => 0,
			'events_sent' => 0,
			'splits'      => 0,
			'dropped'     => 0,
			'error'       => '',
			'pending'     => 0,
			'held'        => 0,
			'next_run'    => 0,
		);

		$this->meta->set_num( Meta_Store::WORKER_LAST_RUN, Clock::time() );
		$this->maintain();

		if ( ! Settings::is_enabled() ) {
			$report['status']  = 'disabled';
			$report['pending'] = $this->queue->stats()['count'];
			return $this->finish( $report, false );
		}
		if ( ! Settings::is_configured() ) {
			$report['status']  = 'not_configured';
			$report['error']   = '' !== Settings::endpoint_problem() ? Settings::endpoint_problem() : __( 'No API key is configured.', 'ziplogger' );
			$report['pending'] = $this->queue->stats()['count'];
			return $this->finish( $report, false );
		}

		$destination = Destination::current();
		// Rows collected before a key existed attach to the first destination that does.
		$this->queue->bind_unbound( $destination );

		// Keep going if the visitor who triggered WP-Cron leaves, and ask for enough time.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) && (int) $opts['max_seconds'] < 3600 ) {
			@set_time_limit( (int) $opts['max_seconds'] + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- some hosts disable it; failing to raise the limit is harmless.
		}

		$has_rows = $this->queue->counts_by_signal();
		$signals  = array();
		foreach ( Signal::ALL as $signal ) {
			if ( in_array( $signal, (array) $opts['signals'], true ) && $has_rows[ $signal ] > 0 ) {
				$signals[] = $signal;
			}
		}

		// Signals that are paused right now sit this run out (unless the administrator forced a flush).
		$active = array();
		$paused = array();
		foreach ( $signals as $signal ) {
			if ( ! $opts['force'] && $this->meta->get_num( $this->meta->sk( Meta_Store::GATE_UNTIL, $signal ) ) > Clock::time() ) {
				$paused[] = $signal;
			} else {
				$active[] = $signal;
			}
		}
		if ( $signals && ! $active ) {
			$report['status'] = 'gated';
			$last             = $this->meta->get_json( $this->meta->sk( Meta_Store::LAST_ERROR, $paused[0] ) );
			$report['error']  = isset( $last['message'] ) ? (string) $last['message'] : $this->meta->get_str( $this->meta->sk( Meta_Store::BLOCKED, $paused[0] ) );
			return $this->finish( $report, true );
		}
		if ( $opts['force'] ) {
			$this->queue->release_backoff();
		}

		$api_key = Settings::api_key();
		$base    = Settings::endpoint_base();
		$started = Clock::now();
		$timeout = (int) Limits::get( 'http_timeout' );
		$state   = array();
		foreach ( $active as $signal ) {
			$state[ $signal ] = array(
				'run_fail' => 0,
				'done'     => false,
			);
		}

		while ( $active && $report['batches'] < (int) $opts['max_batches'] ) {
			$progress = false;
			foreach ( $active as $signal ) {
				if ( $state[ $signal ]['done'] ) {
					continue;
				}
				// The first batch always gets a try; after that, stop when another request could overrun the budget.
				if ( $report['batches'] > 0 && ( Clock::now() - $started ) + $timeout + 1 > (int) $opts['max_seconds'] ) {
					break 2;
				}
				$batch = $this->queue->claim_next( $signal, $destination );
				if ( null === $batch ) {
					$state[ $signal ]['done'] = true;
					continue;
				}
				$result   = $this->transport->send( $batch->payload, $batch->key(), $batch->count, $api_key, $base, $signal );
				$progress = true;
				++$report['batches'];
				if ( ! $this->handle( $batch, $result, $report, $state[ $signal ]['run_fail'], $signal ) ) {
					$state[ $signal ]['done'] = true;
				}
				if ( $report['batches'] >= (int) $opts['max_batches'] ) {
					break 2;
				}
			}
			if ( ! $progress ) {
				break;
			}
		}

		if ( $report['events_sent'] > 0 && '' === $report['error'] ) {
			$report['status'] = 'sent';
		} elseif ( '' !== $report['error'] ) {
			$report['status'] = $report['events_sent'] > 0 ? 'sent' : 'failed';
		}
		return $this->finish( $report, true );
	}

	/**
	 * Apply the policy for one attempt.
	 *
	 * @param Batch           $batch    The leased batch.
	 * @param Delivery_Result $result   Classified outcome.
	 * @param array           $report   Run report (updated).
	 * @param int             $run_fail Failures so far in this run for this signal (updated).
	 * @param string          $signal   Signal.
	 * @return bool Whether to keep working through this signal's queue.
	 */
	private function handle( Batch $batch, Delivery_Result $result, array &$report, &$run_fail, $signal ) {
		$now = Clock::time();
		$m   = $this->meta;

		switch ( $result->outcome ) {
			case Delivery_Result::SUCCESS:
				$this->queue->ack( $batch );
				$m->incr( $m->sk( Meta_Store::SENT_EVENTS, $signal ), $batch->count );
				$m->incr( $m->sk( Meta_Store::SENT_BATCHES, $signal ), 1 );
				if ( $result->rejected > 0 ) {
					// Events the backend refused on validation: final, so counted rather than retried.
					$m->incr( $m->sk( Meta_Store::SERVER_REJECTED, $signal ), (int) $result->rejected );
				}
				$m->set_num( $m->sk( Meta_Store::LAST_SUCCESS, $signal ), $now );
				$m->clear_gate( $signal );
				$report['events_sent'] += $batch->count;
				$run_fail               = 0;
				return true;

			case Delivery_Result::PARTIAL:
			case Delivery_Result::RATE_LIMITED:
				// Same batch, same key: the backend ingests only what it has not admitted yet.
				$delay = Backoff::delay( $batch->attempts + 1, $result->retry_after );
				$this->queue->release( $batch, $now + $delay, true, Delivery_Result::PARTIAL === $result->outcome );
				$this->note_failure( $result, $delay, $report, $signal );
				return false;

			case Delivery_Result::AUTH:
			case Delivery_Result::CONFIG:
				$fails = $m->get_num( $m->sk( Meta_Store::FAILURES, $signal ) ) + 1;
				$delay = (int) min( 3600, max( 900, Backoff::delay( $fails, $result->retry_after ) ) );
				$this->queue->release( $batch, $now + $delay, false, false );
				$m->set_str( $m->sk( Meta_Store::BLOCKED, $signal ), $result->outcome );
				$this->note_failure( $result, $delay, $report, $signal );
				return false;

			case Delivery_Result::BAD_REQUEST:
			case Delivery_Result::TOO_LARGE:
				if ( $batch->partial ) {
					// Something was admitted before: splitting would resend it under new keys.
					$delay = Backoff::delay( $batch->attempts + 1, null );
					$this->queue->release( $batch, $now + $delay, true, false );
					$this->note_failure( $result, $delay, $report, $signal );
					return false;
				}
				$this->isolate( $batch, Delivery_Result::TOO_LARGE === $result->outcome ? 'oversize' : 'rejected', $report, $signal );
				$this->note_failure( $result, 0, $report, $signal, false );
				return true;

			case Delivery_Result::KEY_CONFLICT:
				// The backend has this key bound to different bytes. The items were never admitted
				// under it, so a new key for the same items is correct.
				if ( $batch->attempts + 1 >= (int) Limits::get( 'max_attempts' ) ) {
					$delay = Backoff::delay( $batch->attempts + 1, null );
					$this->queue->release( $batch, $now + $delay, true, false );
					$this->note_failure( $result, $delay, $report, $signal );
					return false;
				}
				$this->queue->rekey( $batch );
				$this->note_failure( $result, 0, $report, $signal, false );
				return true;

			case Delivery_Result::TRANSIENT:
			default:
				$attempts_after = $batch->attempts + 1;
				$limit          = $batch->count > 1 ? (int) Limits::get( 'isolate_after' ) : (int) Limits::get( 'max_attempts' );
				if ( $attempts_after >= $limit && ! $batch->partial && $this->endpoint_has_worked_since( $batch->first_attempt_at, $signal ) ) {
					$this->isolate( $batch, 'poison', $report, $signal );
					$this->note_failure( $result, 0, $report, $signal, false );
					return true;
				}
				$this->queue->release( $batch, $now + Backoff::delay( $attempts_after, $result->retry_after ), true, false );
				++$run_fail;
				$gate = $run_fail >= 2 ? Backoff::delay( $m->get_num( $m->sk( Meta_Store::FAILURES, $signal ) ) + 1, $result->retry_after ) : 0;
				$this->note_failure( $result, $gate, $report, $signal );
				return $run_fail < 2;
		}
	}

	/**
	 * Isolate a batch that cannot be delivered: halve it, or drop the single item that is left.
	 *
	 * @param Batch  $batch  Leased batch.
	 * @param string $reason Drop reason (rejected, oversize, poison).
	 * @param array  $report Report (updated).
	 * @param string $signal Signal.
	 * @return void
	 */
	private function isolate( Batch $batch, $reason, array &$report, $signal ) {
		if ( $batch->count > 1 ) {
			$this->queue->split( $batch, max( 0, (int) Limits::get( 'isolate_after' ) - 1 ) );
			++$report['splits'];
			return;
		}
		$this->queue->ack( $batch );
		$this->meta->dropped( $reason, 1, $signal );
		++$report['dropped'];
	}

	/**
	 * Whether ZipLogger accepted something for this signal after this batch first failed. That is the
	 * evidence that the endpoint works and this batch specifically is the problem.
	 *
	 * @param int    $first_attempt_at Unix time of the batch's first attempt.
	 * @param string $signal           Signal.
	 * @return bool
	 */
	private function endpoint_has_worked_since( $first_attempt_at, $signal ) {
		$last_success = $this->meta->get_num( $this->meta->sk( Meta_Store::LAST_SUCCESS, $signal ) );
		return $last_success > 0 && $last_success >= (int) $first_attempt_at;
	}

	/**
	 * Record a failed attempt: diagnostics for the admin screen, the failure streak and the pause.
	 *
	 * @param Delivery_Result $result  Outcome.
	 * @param int             $gate_in Seconds to pause this signal (0 for none).
	 * @param array           $report  Report (updated).
	 * @param string          $signal  Signal.
	 * @param bool            $streak  Whether this counts toward the endpoint failure streak. Isolating one
	 *                                 bad batch says nothing about the endpoint, so it does not.
	 * @return void
	 */
	private function note_failure( Delivery_Result $result, $gate_in, array &$report, $signal, $streak = true ) {
		$m = $this->meta;
		if ( $streak ) {
			$m->set_num( $m->sk( Meta_Store::FAILURES, $signal ), $m->get_num( $m->sk( Meta_Store::FAILURES, $signal ) ) + 1 );
		}
		$m->set_json(
			$m->sk( Meta_Store::LAST_ERROR, $signal ),
			array(
				'at'      => Clock::time(),
				'outcome' => $result->outcome,
				'kind'    => $result->kind,
				'status'  => $result->status,
				'message' => $result->message,
			)
		);
		if ( $gate_in > 0 ) {
			$m->set_num( $m->sk( Meta_Store::GATE_UNTIL, $signal ), Clock::time() + (int) $gate_in );
		}
		$report['error'] = ( Signal::LOGS === $signal ? '' : Signal::label( $signal ) . ': ' ) . $result->message;
	}

	/**
	 * Schedule the next run when work remains, and stamp the report.
	 *
	 * @param array $report   Report.
	 * @param bool  $schedule Whether to schedule a follow-up.
	 * @return array
	 */
	private function finish( array $report, $schedule ) {
		$destination       = Destination::current();
		$stats             = $this->queue->stats( null, $destination );
		$report['pending'] = $stats['count'];
		$report['held']    = $stats['held'];
		$this->meta->set_str( Meta_Store::WORKER_LAST_RESULT, $report['status'] );

		if ( $schedule && '' !== $destination && $stats['count'] - $stats['held'] > 0 ) {
			$counts = $this->queue->counts_by_signal();
			$when   = 0;
			foreach ( Signal::ALL as $signal ) {
				if ( $counts[ $signal ] < 1 ) {
					continue;
				}
				$ready = $this->queue->next_ready_at( $signal, $destination );
				if ( $ready <= 0 ) {
					continue; // Nothing of this signal is bound for the current destination.
				}
				$gate = $this->meta->get_num( $this->meta->sk( Meta_Store::GATE_UNTIL, $signal ) );
				$t    = max( $ready, $gate );
				$when = 0 === $when ? $t : min( $when, $t );
			}
			if ( $when > 0 ) {
				$when = max( $when, Clock::time() + 5 );
				Scheduler::ensure_scheduled( $when );
				$report['next_run'] = $when;
			}
		}
		return $report;
	}

	/**
	 * Send one test event immediately, bypassing the queue. This is a deliberate administrator action.
	 *
	 * @return array{ok:bool,message:string,status:int,accepted:int|null}
	 */
	public function send_test_event() {
		$api_key = Settings::api_key();
		$base    = Settings::endpoint_base();
		if ( '' === $api_key ) {
			return $this->test_outcome( false, __( 'No API key is configured. Add one and save the settings first.', 'ziplogger' ), 0, null );
		}
		if ( '' === $base ) {
			$problem = Settings::endpoint_problem();
			return $this->test_outcome( false, '' !== $problem ? $problem : __( 'The endpoint is not usable.', 'ziplogger' ), 0, null );
		}

		$factory = new Event_Factory( new Redactor( array( 'secrets' => array( $api_key ) ) ) );
		$record  = $factory->build(
			array(
				'type'     => 'connection_test',
				'severity' => 'info',
				'message'  => 'ZipLogger connection test',
				'template' => 'ZipLogger connection test',
				'fields'   => array( 'test' => true ),
			)
		);
		$encoded = $factory->encode( $record );
		if ( null === $encoded['json'] ) {
			return $this->test_outcome( false, __( 'The test event could not be encoded.', 'ziplogger' ), 0, null );
		}

		$payload = '[' . $encoded['json'] . ']';
		$result  = $this->transport->send( $payload, Batch::KEY_PREFIX . 'test-' . Batch::new_id(), 1, $api_key, $base, Signal::LOGS );

		if ( $result->is_success() ) {
			$this->meta->clear_gate( Signal::LOGS );
			$this->meta->set_num( Meta_Store::LAST_SUCCESS, Clock::time() );
			return $this->test_outcome( true, __( 'ZipLogger accepted the test event.', 'ziplogger' ), $result->status, $result->accepted );
		}
		if ( Delivery_Result::AUTH === $result->outcome ) {
			return $this->test_outcome( false, __( 'ZipLogger rejected the API key. Check that it is an ingestion key, that it has not been revoked, and that you copied all of it.', 'ziplogger' ) . ' (' . $result->message . ')', $result->status, null );
		}
		/* translators: %s: sanitized error detail. */
		return $this->test_outcome( false, sprintf( __( 'The test event was not delivered: %s', 'ziplogger' ), $result->message ), $result->status, $result->accepted );
	}

	/**
	 * Remember and return a test outcome.
	 *
	 * @param bool     $ok       Success.
	 * @param string   $message  Message.
	 * @param int      $status   HTTP status.
	 * @param int|null $accepted Accepted count.
	 * @return array
	 */
	private function test_outcome( $ok, $message, $status, $accepted ) {
		$out = array(
			'ok'       => $ok,
			'message'  => $message,
			'status'   => (int) $status,
			'accepted' => $accepted,
		);
		$this->meta->set_json(
			Meta_Store::LAST_TEST,
			array(
				'at'      => Clock::time(),
				'ok'      => $ok,
				'status'  => (int) $status,
				'message' => $message,
			)
		);
		return $out;
	}
}
