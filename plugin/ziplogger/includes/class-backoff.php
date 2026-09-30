<?php
/**
 * Retry delay calculation.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Exponential backoff with "equal jitter", and Retry-After as a floor.
 *
 * Formula: delay = min(cap, base * 2^(attempt-1)); the result is uniformly distributed in [delay/2, delay] so
 * a fleet of sites that failed together does not retry together.
 */
final class Backoff {

	/**
	 * Test hook: callable( int $min, int $max ): int. Null uses wp_rand().
	 *
	 * @var callable|null
	 */
	public static $rng = null;

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @param int      $attempt     Number of failed attempts so far (>= 1).
	 * @param int|null $retry_after Server-requested delay in seconds, or null.
	 * @return int
	 */
	public static function delay( $attempt, $retry_after = null ) {
		$base = (int) Limits::get( 'backoff_base' );
		$cap  = (int) Limits::get( 'backoff_cap' );
		$n    = max( 1, (int) $attempt );

		$delay = (int) min( $cap, $base * pow( 2, min( $n - 1, 20 ) ) );
		$delay = self::rand( (int) floor( $delay / 2 ), $delay );

		if ( null !== $retry_after && $retry_after > 0 ) {
			$ceiling = (int) Limits::get( 'retry_after_cap' );
			$floor   = min( (int) $retry_after, $ceiling );
			// Never earlier than the server asked; a little jitter on top spreads the herd.
			$delay = max( $delay, $floor + self::rand( 0, (int) min( 10, floor( $floor / 10 ) ) ) );
		}
		return $delay;
	}

	/**
	 * Parse a Retry-After header value (delta-seconds or an HTTP date) into seconds from now.
	 *
	 * @param string|null $value Header value.
	 * @return int|null Seconds, or null when absent or unparsable.
	 */
	public static function parse_retry_after( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( ctype_digit( $value ) ) {
			return min( (int) $value, (int) Limits::get( 'retry_after_cap' ) );
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return (int) max( 0, min( $ts - Clock::time(), (int) Limits::get( 'retry_after_cap' ) ) );
	}

	/**
	 * Random integer in [min, max].
	 *
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 * @return int
	 */
	private static function rand( $min, $max ) {
		if ( $max <= $min ) {
			return $min;
		}
		if ( null !== self::$rng ) {
			return (int) call_user_func( self::$rng, $min, $max );
		}
		return (int) wp_rand( $min, $max );
	}
}
