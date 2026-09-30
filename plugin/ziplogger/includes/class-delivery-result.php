<?php
/**
 * The classified outcome of one delivery attempt.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Outcomes map to distinct worker behaviour:
 *
 * The outcomes:
 *  success      every event in the batch was accepted -> delete the batch.
 *  partial      HTTP said "accepted N, rejected M" (or fewer than sent) -> retry the SAME batch with the
 *               SAME key; the backend only ingests the remainder.
 *  rate_limited 429 with nothing accepted -> back off (Retry-After).
 *  auth         401/403 -> pause delivery, keep the data; do not burn attempts.
 *  config       404/405/3xx/unusable endpoint -> pause like auth.
 *  bad_request  400 and other client errors -> isolate the offending event by splitting.
 *  too_large    413 -> split; a single oversize event is dropped.
 *  key_conflict 422 (idempotency key already bound to a different body) -> new key, same events.
 *  transient    5xx, timeouts, network errors, unreadable responses, 409 in-flight -> retry, same key.
 */
final class Delivery_Result {

	const SUCCESS      = 'success';
	const PARTIAL      = 'partial';
	const RATE_LIMITED = 'rate_limited';
	const AUTH         = 'auth';
	const CONFIG       = 'config';
	const BAD_REQUEST  = 'bad_request';
	const TOO_LARGE    = 'too_large';
	const KEY_CONFLICT = 'key_conflict';
	const TRANSIENT    = 'transient';

	/**
	 * Outcome constant.
	 *
	 * @var string
	 */
	public $outcome;

	/**
	 * HTTP status, or 0 when no response was received.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Events the backend reported accepting (null when not reported).
	 *
	 * @var int|null
	 */
	public $accepted;

	/**
	 * Events the backend reported rejecting (null when not reported).
	 *
	 * @var int|null
	 */
	public $rejected;

	/**
	 * Server-requested delay in seconds.
	 *
	 * @var int|null
	 */
	public $retry_after;

	/**
	 * Short machine-readable detail (timeout, network, server_error, malformed_response, ...).
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Sanitized human-readable detail. Safe to store and display.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Constructor.
	 *
	 * @param string   $outcome     Outcome.
	 * @param int      $status      HTTP status.
	 * @param string   $kind        Detail code.
	 * @param string   $message     Sanitized message.
	 * @param int|null $accepted    Accepted count.
	 * @param int|null $rejected    Rejected count.
	 * @param int|null $retry_after Retry-After seconds.
	 */
	public function __construct( $outcome, $status, $kind, $message, $accepted = null, $rejected = null, $retry_after = null ) {
		$this->outcome     = $outcome;
		$this->status      = (int) $status;
		$this->kind        = $kind;
		$this->message     = $message;
		$this->accepted    = $accepted;
		$this->rejected    = $rejected;
		$this->retry_after = $retry_after;
	}

	/**
	 * Whether the batch is fully delivered.
	 *
	 * @return bool
	 */
	public function is_success() {
		return self::SUCCESS === $this->outcome;
	}

	/**
	 * Whether this outcome should pause all delivery (it says nothing about a particular batch).
	 *
	 * @return bool
	 */
	public function is_blocking() {
		return self::AUTH === $this->outcome || self::CONFIG === $this->outcome;
	}
}
