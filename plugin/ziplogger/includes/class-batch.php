<?php
/**
 * A leased batch of queued items, frozen as exact bytes.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The payload is built only from the rows' stored JSON, so it is byte-for-byte identical every time
 * the same rows are read: a retry after a timeout or a crash sends the same bytes under the same
 * Idempotency-Key, which is what lets the backend recognise it as the same request. A batch's
 * identity (batch_id) never changes while its membership is unchanged; splitting or re-keying a
 * batch always produces NEW ids, so a key is never reused for a different payload.
 */
final class Batch {

	const KEY_PREFIX = 'zlwp-';

	/**
	 * Batch identifier (32 hex characters).
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Lease token held by the worker that claimed it.
	 *
	 * @var string
	 */
	public $token;

	/**
	 * Row ids, ascending.
	 *
	 * @var int[]
	 */
	public $ids;

	/**
	 * Request body.
	 *
	 * @var string
	 */
	public $payload;

	/**
	 * Number of items.
	 *
	 * @var int
	 */
	public $count;

	/**
	 * Failed delivery attempts so far.
	 *
	 * @var int
	 */
	public $attempts;

	/**
	 * When the first attempt was made (Unix time), 0 if none.
	 *
	 * @var int
	 */
	public $first_attempt_at;

	/**
	 * Whether the backend has already reported accepting part of this batch.
	 *
	 * @var bool
	 */
	public $partial;

	/**
	 * Which signal the batch carries.
	 *
	 * @var string
	 */
	public $signal;

	/**
	 * Constructor.
	 *
	 * @param string   $id               Batch id.
	 * @param string   $token            Lease token.
	 * @param int[]    $ids              Row ids.
	 * @param string[] $payloads         Row JSON documents in id order.
	 * @param int      $attempts         Attempts so far.
	 * @param int      $first_attempt_at First attempt time.
	 * @param bool     $partial          Partially accepted earlier.
	 * @param string   $signal           Signal.
	 */
	public function __construct( $id, $token, array $ids, array $payloads, $attempts, $first_attempt_at, $partial, $signal = Signal::LOGS ) {
		$this->id               = $id;
		$this->token            = $token;
		$this->ids              = $ids;
		$this->signal           = $signal;
		$this->payload          = Signal::payload( $signal, $payloads );
		$this->count            = count( $ids );
		$this->attempts         = (int) $attempts;
		$this->first_attempt_at = (int) $first_attempt_at;
		$this->partial          = (bool) $partial;
	}

	/**
	 * The Idempotency-Key for this batch.
	 *
	 * @return string
	 */
	public function key() {
		return Signal::key_prefix( $this->signal ) . $this->id;
	}

	/**
	 * Payload size in bytes.
	 *
	 * @return int
	 */
	public function bytes() {
		return strlen( $this->payload );
	}

	/**
	 * A fresh random batch id.
	 *
	 * @return string
	 */
	public static function new_id() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}
