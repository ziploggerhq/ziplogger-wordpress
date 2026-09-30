<?php
/**
 * Money amounts as analytics wants them: an ISO 4217 currency and exact minor units.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * A shop can display a currency with any number of decimals, but what an amount MEANS is set by the
 * currency: one yen is one yen, one thousand fils make a dinar. Analytics that sums "minor units" must
 * use the currency's own exponent, not the shop's display setting, or a 500 JPY order shows up as 50000.
 *
 * Amounts are handled as decimal strings and integers, never accumulated as floats.
 */
final class Money {

	/**
	 * Currencies whose minor unit is not 1/100 (ISO 4217 exponents).
	 */
	const EXPONENTS = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
		'CLF' => 4,
		'UYW' => 4,
	);

	/**
	 * The currency's exponent (digits after the decimal point in its minor unit).
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function exponent( $currency ) {
		$currency = strtoupper( (string) $currency );
		return isset( self::EXPONENTS[ $currency ] ) ? self::EXPONENTS[ $currency ] : 2;
	}

	/**
	 * A valid three-letter currency code, or ''.
	 *
	 * @param mixed $currency Candidate.
	 * @return string
	 */
	public static function currency( $currency ) {
		$currency = strtoupper( trim( (string) $currency ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '';
	}

	/**
	 * Convert a decimal amount (string or number, "12.34", "-5", 12.3) to minor units, rounding half away
	 * from zero at the currency's exponent.
	 *
	 * @param string|int|float $amount   Amount in major units.
	 * @param string           $currency ISO 4217 code.
	 * @return int
	 */
	public static function minor( $amount, $currency ) {
		$exp    = self::exponent( $currency );
		$string = is_float( $amount ) ? rtrim( rtrim( sprintf( '%.8F', $amount ), '0' ), '.' ) : trim( (string) $amount );
		if ( 1 !== preg_match( '/^(-?)(\d*)(?:\.(\d*))?$/', $string, $m ) || ( '' === $m[2] && ( ! isset( $m[3] ) || '' === $m[3] ) ) ) {
			return 0;
		}
		$negative = '-' === $m[1];
		$whole    = '' === $m[2] ? '0' : $m[2];
		$frac     = isset( $m[3] ) ? $m[3] : '';
		$digits   = str_pad( substr( $frac, 0, $exp ), $exp, '0' );
		$round_up = strlen( $frac ) > $exp && (int) $frac[ $exp ] >= 5;
		$value    = (int) ( $whole . $digits ) + ( $round_up ? 1 : 0 );
		return $negative ? -$value : $value;
	}

	/**
	 * Minor units back to a decimal string in major units ("1234" JPY -> "1234", "1234" USD -> "12.34").
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public static function decimal( $minor, $currency ) {
		$exp      = self::exponent( $currency );
		$negative = $minor < 0;
		$digits   = (string) abs( (int) $minor );
		if ( 0 === $exp ) {
			return ( $negative ? '-' : '' ) . $digits;
		}
		$digits = str_pad( $digits, $exp + 1, '0', STR_PAD_LEFT );
		return ( $negative ? '-' : '' ) . substr( $digits, 0, -$exp ) . '.' . substr( $digits, -$exp );
	}

	/**
	 * The three amount properties of an event.
	 *
	 * @param string           $prefix   Property prefix ('' gives value/valueMinor; 'refund' gives refundValue ...).
	 * @param string|int|float $amount   Amount in major units.
	 * @param string           $currency ISO 4217 code.
	 * @return array
	 */
	public static function properties( $prefix, $amount, $currency ) {
		$minor = self::minor( $amount, $currency );
		$name  = '' === $prefix ? 'value' : $prefix . 'Value';
		return array(
			$name           => (float) self::decimal( $minor, $currency ),
			$name . 'Minor' => $minor,
		);
	}
}
