<?php
/**
 * The one place items enter the queue: capacity policy, destination binding, scheduling.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Overflow policy (explicit, and visible as counters):
 *
 *  - The queue is bounded in items and bytes. When full, NEW items are dropped and counted
 *    ("drop-newest"); what is already queued is kept.
 *  - Each signal may occupy at most its share of the item bound (logs 100%, events 70%, traces 50% by
 *    default), so a burst of spans can never push errors out during an outage.
 *  - Low-severity items (debug/info logs) cannot use the last slice of capacity, so warnings and errors
 *    still fit when the queue is nearly full.
 *
 * The bounds are enforced against one snapshot of the queue taken before the insert, so concurrent
 * requests can overshoot by at most what one request may add (the per-request cap).
 */
final class Queue_Writer {

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
	 * Constructor.
	 *
	 * @param Queue_Store|null $store Queue.
	 * @param Meta_Store|null  $meta  Counters.
	 */
	public function __construct( ?Queue_Store $store = null, ?Meta_Store $meta = null ) {
		$this->store = null !== $store ? $store : new Queue_Store();
		$this->meta  = null !== $meta ? $meta : new Meta_Store();
	}

	/**
	 * Enqueue items of one signal.
	 *
	 * @param string  $signal     Signal.
	 * @param array[] $candidates Each: payload (JSON text), size (bytes), severity (rank), fingerprint.
	 * @return int Items inserted.
	 */
	public function write( $signal, array $candidates ) {
		if ( ! $candidates ) {
			return 0;
		}
		$dropped     = array();
		$total       = $this->store->stats();
		$mine        = $this->store->stats( $signal );
		$count       = $total['count'];
		$bytes       = $total['bytes'];
		$signal_n    = $mine['count'];
		$max_events  = (int) Limits::get( 'queue_max_events' );
		$max_bytes   = (int) Limits::get( 'queue_max_bytes' );
		$signal_cap  = (int) floor( $max_events * Signal::queue_share( $signal ) );
		$low_cap     = (int) floor( $max_events * (float) Limits::get( 'low_priority_ceiling' ) );
		$warn_rank   = Severity::RANKS['warn'];
		$destination = Destination::current();
		$now         = Clock::time();

		$rows = array();
		foreach ( $candidates as $c ) {
			$size = (int) $c['size'];
			$rank = (int) $c['severity'];
			if ( $count >= $max_events || $signal_n >= $signal_cap || ( $rank < $warn_rank && $count >= $low_cap ) || $bytes + $size > $max_bytes ) {
				$dropped['overflow'] = ( isset( $dropped['overflow'] ) ? $dropped['overflow'] : 0 ) + 1;
				continue;
			}
			$rows[] = array(
				'payload'     => $c['payload'],
				'size'        => $size,
				'severity'    => $rank,
				'fingerprint' => isset( $c['fingerprint'] ) ? $c['fingerprint'] : '',
				'sig'         => $signal,
				'destination' => $destination,
				'created_at'  => $now,
			);
			++$count;
			++$signal_n;
			$bytes += $size;
		}

		$inserted = $this->store->insert_many( $rows );
		if ( $inserted < count( $rows ) ) {
			$dropped['storage'] = count( $rows ) - $inserted;
		}
		foreach ( $dropped as $reason => $n ) {
			$this->meta->dropped( $reason, $n, $signal );
		}
		if ( $inserted > 0 ) {
			Scheduler::ensure_scheduled( $now + (int) Limits::get( 'first_delivery_delay' ) );
		}
		return $inserted;
	}
}
