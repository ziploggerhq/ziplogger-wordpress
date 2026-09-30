<?php
/**
 * The recording pipeline: threshold, limits, redaction, de-duplication, buffering, persistence.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Every event - collected or developer-supplied - enters here. Nothing here touches the network.
 *
 *  1. Collection must be on; the event must meet the severity threshold (unless the collector is
 *     exempt) and pass the "ziplogger_should_log" / "ziplogger_event" filters.
 *  2. Per-request cap and in-request de-duplication keep a noisy loop from costing anything.
 *  3. The event is redacted and held in memory. One multi-row INSERT at the end of the request
 *     (or when the buffer is large, for CLI / cron) persists the whole request's events.
 *  4. Identical auto-collected events that are still pending in the queue are suppressed and counted
 *     (the count is attached to the next event that gets in as "suppressedRepeats").
 *  5. The queue is bounded: when full, NEW events are dropped and counted (drop-newest), and
 *     debug/info events cannot use the last slice of capacity so that warnings and errors still fit.
 */
final class Recorder {

	/**
	 * Queue.
	 *
	 * @var Queue_Store
	 */
	private $store;

	/**
	 * Counters.
	 *
	 * @var Meta_Store
	 */
	private $meta;

	/**
	 * Queue writer (capacity policy, destination binding, scheduling).
	 *
	 * @var Queue_Writer
	 */
	private $writer;

	/**
	 * Record builder (created on first use).
	 *
	 * @var Event_Factory|null
	 */
	private $factory = null;

	/**
	 * Events accepted in this request and not yet persisted.
	 *
	 * @var array[]
	 */
	private $buffer = array();

	/**
	 * Fingerprint -> buffer index (in-request de-duplication).
	 *
	 * @var array<string,int>
	 */
	private $index = array();

	/**
	 * Events accepted in this request (cap accounting).
	 *
	 * @var int
	 */
	private $accepted = 0;

	/**
	 * Drops observed in this request, persisted at flush: reason => count.
	 *
	 * @var array<string,int>
	 */
	private $tally = array();

	/**
	 * Whether the shutdown flush is registered.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Re-entrancy guard for record().
	 *
	 * @var bool
	 */
	private $busy = false;

	/**
	 * Re-entrancy guard for flush().
	 *
	 * @var bool
	 */
	private $flushing = false;

	/**
	 * Constructor.
	 *
	 * @param Queue_Store|null   $store   Queue.
	 * @param Meta_Store|null    $meta    Counters.
	 * @param Event_Factory|null $factory Record builder.
	 */
	public function __construct( ?Queue_Store $store = null, ?Meta_Store $meta = null, ?Event_Factory $factory = null ) {
		$this->store   = null !== $store ? $store : new Queue_Store();
		$this->meta    = null !== $meta ? $meta : new Meta_Store();
		$this->writer  = new Queue_Writer( $this->store, $this->meta );
		$this->factory = $factory;
	}

	/**
	 * Whether record() is running (collectors use this to ignore errors raised by the pipeline itself).
	 *
	 * @return bool
	 */
	public function is_busy() {
		return $this->busy || $this->flushing;
	}

	/**
	 * Cheap pre-check so collectors can skip expensive work (backtraces) for events that would be discarded.
	 *
	 * @param string $severity Severity of the would-be event.
	 * @param bool   $exempt   Whether the collector ignores the severity threshold.
	 * @return bool
	 */
	public function would_record( $severity, $exempt = false ) {
		if ( ! Settings::is_enabled() || $this->is_busy() ) {
			return false;
		}
		if ( ! $exempt ) {
			$s = Settings::get();
			if ( Severity::rank( $severity ) < Severity::rank( $s['min_severity'] ) ) {
				return false;
			}
		}
		return $this->accepted < (int) Limits::get( 'request_event_cap' );
	}

	/**
	 * Record an event.
	 *
	 * @param string $type     eventType field (php_error, developer, login_failed, ...).
	 * @param string $severity Severity.
	 * @param string $message  Message (redacted here).
	 * @param array  $args     fields, context, stack, template, timestamp, dedupe (bool), fp_extra (string),
	 *                         exempt (bool: ignore the severity threshold).
	 * @return bool True when the event was buffered.
	 */
	public function record( $type, $severity, $message, array $args = array() ) {
		if ( $this->busy ) {
			return false; // The pipeline logged something about itself: never recurse.
		}
		$this->busy = true;
		try {
			return $this->do_record( $type, $severity, $message, $args );
		} catch ( \Throwable $e ) {
			return false; // Logging must never break the site.
		} finally {
			$this->busy = false;
		}
	}

	/**
	 * The actual work of record().
	 *
	 * @param string $type     Event type.
	 * @param string $severity Severity.
	 * @param string $message  Message.
	 * @param array  $args     Options.
	 * @return bool
	 */
	private function do_record( $type, $severity, $message, array $args ) {
		if ( ! Settings::is_enabled() ) {
			return false;
		}
		$severity = Severity::normalize( $severity );
		$settings = Settings::get();
		if ( empty( $args['exempt'] ) && Severity::rank( $severity ) < Severity::rank( $settings['min_severity'] ) ) {
			return false;
		}

		// Fatal errors bypass the per-request cap: they end the request, so they cannot be a flood.
		if ( empty( $args['bypass_cap'] ) && $this->accepted >= (int) Limits::get( 'request_event_cap' ) ) {
			$this->tally['request_cap'] = ( isset( $this->tally['request_cap'] ) ? $this->tally['request_cap'] : 0 ) + 1;
			return false;
		}

		$message = is_string( $message ) ? $message : (string) $message;
		$fp      = '';
		if ( ! empty( $args['dedupe'] ) ) {
			$raw = substr( $message, 0, 1024 );
			$fp  = substr( sha1( $type . '|' . $severity . '|' . $this->factory()->template( $raw ) . '|' . ( isset( $args['fp_extra'] ) ? $args['fp_extra'] : '' ) ), 0, 16 );
			if ( isset( $this->index[ $fp ] ) ) {
				++$this->buffer[ $this->index[ $fp ] ]['count'];
				return false;
			}
		}

		$spec = array(
			'type'      => $type,
			'severity'  => $severity,
			'message'   => $message,
			'template'  => isset( $args['template'] ) ? $args['template'] : null,
			'fields'    => isset( $args['fields'] ) ? $args['fields'] : array(),
			'context'   => isset( $args['context'] ) ? $args['context'] : array(),
			'stack'     => isset( $args['stack'] ) ? $args['stack'] : null,
			'timestamp' => isset( $args['timestamp'] ) ? $args['timestamp'] : Clock::now(),
		);

		/**
		 * Filters whether an event is recorded. Return false to exclude it.
		 *
		 * @param bool  $record Whether to record.
		 * @param array $spec   Event: type, severity, message, fields, context, stack.
		 */
		if ( false === apply_filters( 'ziplogger_should_log', true, $spec ) ) {
			return false;
		}

		/**
		 * Filters an event before redaction and persistence. Return false to drop it. Whatever this
		 * returns is still redacted afterwards.
		 *
		 * @param array $spec Event: type, severity, message, fields, context, stack, timestamp.
		 */
		$filtered = apply_filters( 'ziplogger_event', $spec );
		if ( ! is_array( $filtered ) || ! isset( $filtered['message'] ) ) {
			return false;
		}
		$spec             = array_merge( $spec, $filtered );
		$spec['severity'] = Severity::normalize( $spec['severity'] );
		if ( empty( $args['exempt'] ) && Severity::rank( $spec['severity'] ) < Severity::rank( $settings['min_severity'] ) ) {
			return false; // A filter may have lowered the severity below the threshold.
		}

		$record = $this->factory()->build( $spec );

		$this->buffer[] = array(
			'record'  => $record,
			'fp'      => $fp,
			'rank'    => Severity::rank( $record['severity'] ),
			'count'   => 1,
			'blog_id' => is_multisite() ? get_current_blog_id() : 1,
		);
		if ( '' !== $fp ) {
			$this->index[ $fp ] = count( $this->buffer ) - 1;
		}
		++$this->accepted;
		$this->ensure_registered();

		if ( count( $this->buffer ) >= (int) Limits::get( 'buffer_flush_at' ) ) {
			$this->flush_nested();
		}
		return true;
	}

	/**
	 * Flush from inside record() (buffer full) without tripping the record() guard.
	 *
	 * @return void
	 */
	private function flush_nested() {
		$this->flush();
	}

	/**
	 * Persist buffered events with one multi-row INSERT. Safe to call repeatedly; never throws.
	 *
	 * @return int Events inserted.
	 */
	public function flush() {
		if ( $this->flushing ) {
			return 0;
		}
		$this->flushing = true;
		try {
			return $this->do_flush();
		} catch ( \Throwable $e ) {
			return 0;
		} finally {
			$this->flushing = false;
		}
	}

	/**
	 * The actual work of flush().
	 *
	 * @return int
	 */
	private function do_flush() {
		$entries      = $this->buffer;
		$tally        = $this->tally;
		$this->buffer = array();
		$this->index  = array();
		$this->tally  = array();

		$inserted = 0;
		if ( ! $entries && ! $tally ) {
			return 0;
		}

		$groups = array();
		foreach ( $entries as $entry ) {
			$groups[ $entry['blog_id'] ][] = $entry;
		}
		if ( ! $groups ) {
			$groups[ is_multisite() ? get_current_blog_id() : 1 ] = array();
		}

		foreach ( $groups as $blog_id => $group ) {
			$switched = false;
			if ( is_multisite() && (int) get_current_blog_id() !== (int) $blog_id ) {
				switch_to_blog( (int) $blog_id );
				$switched = true;
			}
			try {
				if ( $tally ) {
					foreach ( $tally as $reason => $n ) {
						$this->meta->dropped( $reason, $n );
					}
					$tally = array();
				}
				$inserted += $this->persist( $group );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
		return $inserted;
	}

	/**
	 * Persist one blog's entries, applying suppression and capacity limits.
	 *
	 * @param array[] $entries Buffered entries.
	 * @return int Rows inserted.
	 */
	private function persist( array $entries ) {
		if ( ! $entries ) {
			return 0;
		}
		$dropped = array();

		// Identical auto-collected events already waiting in the queue are suppressed and counted.
		$fps = array();
		foreach ( $entries as $entry ) {
			if ( '' !== $entry['fp'] ) {
				$fps[] = $entry['fp'];
			}
		}
		$pending = $this->store->pending_fingerprints( $fps );

		$keys = array();
		foreach ( $fps as $fp ) {
			if ( ! in_array( $fp, $pending, true ) ) {
				$keys[] = 'sup:' . $fp;
			}
		}
		$repeats = $this->meta->take_many( $keys );

		$candidates = array();
		foreach ( $entries as $entry ) {
			$fp = $entry['fp'];
			if ( '' !== $fp && in_array( $fp, $pending, true ) ) {
				$this->meta->incr( 'sup:' . $fp, $entry['count'] );
				$this->meta->incr( Meta_Store::SUPPRESSED, $entry['count'] );
				continue;
			}

			$record = $entry['record'];
			if ( $entry['count'] > 1 ) {
				$record['fields']['occurrencesInRequest'] = $entry['count'];
			}
			if ( '' !== $fp && isset( $repeats[ 'sup:' . $fp ] ) ) {
				$record['fields']['suppressedRepeats'] = $repeats[ 'sup:' . $fp ];
			}

			$encoded = $this->factory()->encode( $record );
			if ( null === $encoded['json'] ) {
				$reason             = (string) $encoded['reason'];
				$dropped[ $reason ] = ( isset( $dropped[ $reason ] ) ? $dropped[ $reason ] : 0 ) + 1;
				continue;
			}

			$candidates[] = array(
				'payload'     => $encoded['json'],
				'size'        => strlen( $encoded['json'] ),
				'severity'    => $entry['rank'],
				'fingerprint' => $fp,
			);
		}

		foreach ( $dropped as $reason => $n ) {
			$this->meta->dropped( $reason, $n );
		}
		return $this->writer->write( Signal::LOGS, $candidates );
	}

	/**
	 * Register the end-of-request flush once.
	 *
	 * @return void
	 */
	private function ensure_registered() {
		if ( ! $this->registered ) {
			register_shutdown_function( array( $this, 'flush' ) );
			$this->registered = true;
		}
	}

	/**
	 * The record builder.
	 *
	 * @return Event_Factory
	 */
	public function factory() {
		if ( null === $this->factory ) {
			$this->factory = new Event_Factory();
		}
		return $this->factory;
	}

	/**
	 * Forget everything held in memory: buffer, per-request counters and guards. Used by tests and by
	 * long-running processes that want a clean slate between units of work.
	 *
	 * @return void
	 */
	public function reset() {
		$this->buffer   = array();
		$this->index    = array();
		$this->tally    = array();
		$this->accepted = 0;
		$this->busy     = false;
		$this->flushing = false;
	}

	/**
	 * Number of events waiting in memory (tests, diagnostics).
	 *
	 * @return int
	 */
	public function buffered() {
		return count( $this->buffer );
	}
}
