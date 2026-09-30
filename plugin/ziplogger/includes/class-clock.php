<?php
/**
 * Time source. Everything time-dependent (leases, backoff, retention, timestamps) goes through here
 * so tests can move time without sleeping.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * UTC clock with a test override.
 */
final class Clock {

	/**
	 * Fixed time for tests, or null for the real clock.
	 *
	 * @var float|null
	 */
	private static $fixed = null;

	/**
	 * Current Unix time with microseconds.
	 *
	 * @return float
	 */
	public static function now() {
		return null !== self::$fixed ? self::$fixed : microtime( true );
	}

	/**
	 * Current Unix time in whole seconds.
	 *
	 * @return int
	 */
	public static function time() {
		return (int) floor( self::now() );
	}

	/**
	 * Freeze the clock at a timestamp (tests). Pass null to return to real time.
	 *
	 * @param float|int|null $timestamp Unix time.
	 * @return void
	 */
	public static function freeze( $timestamp ) {
		self::$fixed = null === $timestamp ? null : (float) $timestamp;
	}

	/**
	 * Move a frozen clock forward (tests). Freezes at the current time first if needed.
	 *
	 * @param int $seconds Seconds to advance.
	 * @return void
	 */
	public static function advance( $seconds ) {
		self::$fixed = self::now() + $seconds;
	}

	/**
	 * A UTC MySQL DATETIME string.
	 *
	 * @param int|null $timestamp Unix time; now when null.
	 * @return string
	 */
	public static function mysql( $timestamp = null ) {
		return gmdate( 'Y-m-d H:i:s', null === $timestamp ? self::time() : (int) $timestamp );
	}

	/**
	 * Parse a UTC MySQL DATETIME string back to Unix time.
	 *
	 * @param string|null $datetime Value from the database.
	 * @return int 0 when empty or unparsable.
	 */
	public static function from_mysql( $datetime ) {
		if ( ! is_string( $datetime ) || '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return 0;
		}
		$ts = strtotime( $datetime . ' UTC' );
		return false === $ts ? 0 : (int) $ts;
	}

	/**
	 * ISO-8601 UTC timestamp with milliseconds, e.g. 2026-09-30T12:00:00.123Z.
	 *
	 * The backend deserializes this into a DateTimeOffset; one malformed value would make it
	 * refuse the whole batch, so the format is fixed and never derived from user input.
	 *
	 * @param float|null $timestamp Unix time with fraction; now when null.
	 * @return string
	 */
	public static function iso( $timestamp = null ) {
		$t  = null === $timestamp ? self::now() : (float) $timestamp;
		$ms = (int) floor( ( $t - floor( $t ) ) * 1000 );
		return gmdate( 'Y-m-d\TH:i:s', (int) floor( $t ) ) . sprintf( '.%03dZ', $ms );
	}
}
