<?php
/**
 * Severity mapping and retry delay maths.
 */

use ZipLogger\WordPress\Backoff;
use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Severity;

class Test_Severity_Backoff extends ZL_TestCase {

	/**
	 * @dataProvider errno_provider
	 */
	public function test_php_error_levels_map_to_severities( $errno, $expected ) {
		$this->assertSame( $expected, Severity::from_php_errno( $errno ) );
	}

	public function errno_provider() {
		return array(
			array( E_ERROR, 'fatal' ),
			array( E_PARSE, 'fatal' ),
			array( E_CORE_ERROR, 'fatal' ),
			array( E_COMPILE_ERROR, 'fatal' ),
			array( E_USER_ERROR, 'error' ),
			array( E_RECOVERABLE_ERROR, 'error' ),
			array( E_WARNING, 'warn' ),
			array( E_USER_WARNING, 'warn' ),
			array( E_CORE_WARNING, 'warn' ),
			array( E_NOTICE, 'info' ),
			array( E_USER_NOTICE, 'info' ),
			array( E_DEPRECATED, 'debug' ),
			array( E_USER_DEPRECATED, 'debug' ),
			array( 2048, 'debug' ), // E_STRICT: naming the constant is deprecated since PHP 8.4.
		);
	}

	public function test_rank_orders_severities() {
		$this->assertLessThan( Severity::rank( 'warn' ), Severity::rank( 'info' ) );
		$this->assertLessThan( Severity::rank( 'fatal' ), Severity::rank( 'error' ) );
		$this->assertTrue( Severity::is_valid( 'warn' ) );
		$this->assertFalse( Severity::is_valid( 'warning' ) );
	}

	public function test_backoff_doubles_up_to_the_cap() {
		Backoff::$rng = static function ( $min, $max ) {
			return $max;
		};
		$base = Limits::get( 'backoff_base' );
		$cap  = Limits::get( 'backoff_cap' );
		$this->assertSame( $base, Backoff::delay( 1 ) );
		$this->assertSame( $base * 2, Backoff::delay( 2 ) );
		$this->assertSame( $base * 4, Backoff::delay( 3 ) );
		$this->assertSame( $cap, Backoff::delay( 30 ) );
		$this->assertSame( $cap, Backoff::delay( 500 ) );
	}

	public function test_backoff_jitter_stays_within_half_to_full_delay() {
		$seen = array();
		Backoff::$rng = static function ( $min, $max ) use ( &$seen ) {
			$seen[] = array( $min, $max );
			return $min;
		};
		$low = Backoff::delay( 3 ); // Nominal 120.
		Backoff::$rng = static function ( $min, $max ) {
			return $max;
		};
		$high = Backoff::delay( 3 );
		$this->assertSame( 60, $low );
		$this->assertSame( 120, $high );
	}

	public function test_backoff_without_a_test_rng_uses_real_randomness_within_bounds() {
		Backoff::$rng = null;
		for ( $i = 0; $i < 50; $i++ ) {
			$d = Backoff::delay( 4 ); // Nominal 240.
			$this->assertGreaterThanOrEqual( 120, $d );
			$this->assertLessThanOrEqual( 240, $d );
		}
	}

	public function test_retry_after_is_a_floor() {
		Backoff::$rng = static function ( $min, $max ) {
			return $min;
		};
		// Nominal backoff for attempt 1 is 15..30s; the server asked for 600s.
		$this->assertGreaterThanOrEqual( 600, Backoff::delay( 1, 600 ) );
		// A short Retry-After never shortens the computed backoff.
		$this->assertGreaterThanOrEqual( Backoff::delay( 5 ), Backoff::delay( 5, 1 ) );
	}

	public function test_retry_after_is_capped() {
		Backoff::$rng = static function ( $min, $max ) {
			return $min;
		};
		$cap = Limits::get( 'retry_after_cap' );
		$this->assertLessThanOrEqual( $cap + 10, Backoff::delay( 1, 10 * $cap ) );
	}

	public function test_retry_after_parsing() {
		$this->assertSame( 120, Backoff::parse_retry_after( '120' ) );
		$this->assertSame( 0, Backoff::parse_retry_after( '0' ) );
		$this->assertNull( Backoff::parse_retry_after( '' ) );
		$this->assertNull( Backoff::parse_retry_after( null ) );
		$this->assertNull( Backoff::parse_retry_after( 'soon' ) );
		$this->assertSame( (int) Limits::get( 'retry_after_cap' ), Backoff::parse_retry_after( '99999999' ) );

		$date = gmdate( 'D, d M Y H:i:s', Clock::time() + 300 ) . ' GMT';
		$this->assertSame( 300, Backoff::parse_retry_after( $date ) );
		$past = gmdate( 'D, d M Y H:i:s', Clock::time() - 300 ) . ' GMT';
		$this->assertSame( 0, Backoff::parse_retry_after( $past ) );
	}

	public function test_limits_are_clamped_and_consistent() {
		add_filter(
			'ziplogger_limits',
			static function ( $l ) {
				$l['queue_max_events'] = PHP_INT_MAX;
				$l['http_timeout']     = 9999;
				$l['batch_max_events'] = -5;
				$l['lease_seconds']    = 1;
				$l['isolate_after']    = 99;
				$l['max_attempts']     = 4;
				return $l;
			}
		);
		Limits::reset();
		$this->assertSame( 100000, Limits::get( 'queue_max_events' ) );
		$this->assertSame( 15, Limits::get( 'http_timeout' ) );
		$this->assertSame( 1, Limits::get( 'batch_max_events' ) );
		$this->assertGreaterThanOrEqual( Limits::get( 'http_timeout' ) + 20, Limits::get( 'lease_seconds' ), 'A lease must outlive its request.' );
		$this->assertLessThanOrEqual( Limits::get( 'max_attempts' ), Limits::get( 'isolate_after' ) );
	}
}
