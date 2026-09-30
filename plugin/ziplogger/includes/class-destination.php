<?php
/**
 * Which workspace and endpoint queued data belongs to.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Every queued row remembers the destination it was collected for: a keyed fingerprint of
 * (endpoint, server API key). If the administrator changes the key or the endpoint, rows collected
 * for the OLD destination are never sent to the new one automatically - a different key can mean a
 * different workspace, and sending one customer's telemetry to another workspace is the failure this
 * design exists to prevent. Those rows are "held" until the administrator chooses to send them to the
 * new destination or discard them (Settings -> ZipLogger -> Connection). Held rows still expire with
 * the normal retention window.
 *
 * Rows recorded while no key was configured are unbound; they attach to whatever destination is
 * configured first.
 */
final class Destination {

	/**
	 * Memoized fingerprint for this request, with the inputs it was computed from.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * The current destination fingerprint, or '' when no server key is configured.
	 *
	 * @return string
	 */
	public static function current() {
		$key  = Settings::api_key();
		$base = Settings::endpoint_base();
		if ( '' === $key || '' === $base ) {
			return '';
		}
		$input = $base . '|' . $key;
		if ( null === self::$memo || self::$memo['input'] !== $input ) {
			self::$memo = array(
				'input'       => $input,
				'fingerprint' => Secrets::fingerprint( 'destination|' . $input ),
			);
		}
		return self::$memo['fingerprint'];
	}

	/**
	 * Forget the memoized value.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$memo = null;
	}
}
