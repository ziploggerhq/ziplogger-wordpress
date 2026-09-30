<?php
/**
 * Severity normalization and PHP error-level mapping.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The backend accepts debug, info, warn, error and fatal; anything else silently becomes "info",
 * so every value is normalized here instead of trusting callers.
 */
final class Severity {

	/**
	 * The value of E_STRICT. PHP 8.4 deprecates the constant itself (nothing has raised the level since PHP 8.0),
	 * and merely naming it would make this plugin the source of a deprecation notice on every request.
	 */
	const LEVEL_STRICT = 2048;

	const RANKS = array(
		'debug' => 0,
		'info'  => 1,
		'warn'  => 2,
		'error' => 3,
		'fatal' => 4,
	);

	/**
	 * Common aliases (PSR-3, syslog, PHP-style) to the five wire values.
	 */
	const ALIASES = array(
		'trace'       => 'debug',
		'notice'      => 'info',
		'information' => 'info',
		'warning'     => 'warn',
		'err'         => 'error',
		'critical'    => 'fatal',
		'crit'        => 'fatal',
		'alert'       => 'fatal',
		'emergency'   => 'fatal',
		'emerg'       => 'fatal',
	);

	/**
	 * Normalize a severity to one of the five wire values.
	 *
	 * @param mixed  $value    Caller-supplied severity.
	 * @param string $fallback Value used when the input is not recognised.
	 * @return string
	 */
	public static function normalize( $value, $fallback = 'info' ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$s = strtolower( trim( $value ) );
		if ( isset( self::RANKS[ $s ] ) ) {
			return $s;
		}
		return isset( self::ALIASES[ $s ] ) ? self::ALIASES[ $s ] : $fallback;
	}

	/**
	 * Whether the value is already one of the five wire values.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return is_string( $value ) && isset( self::RANKS[ $value ] );
	}

	/**
	 * Numeric rank, higher is more severe.
	 *
	 * @param string $severity Severity.
	 * @return int
	 */
	public static function rank( $severity ) {
		$s = self::normalize( $severity );
		return self::RANKS[ $s ];
	}

	/**
	 * Map a PHP error level (E_*) to a severity.
	 *
	 * Notices are "info" and deprecations "debug" so that the default threshold ("warn") keeps them
	 * out, while an administrator can opt in to them.
	 *
	 * @param int $errno PHP error level.
	 * @return string
	 */
	public static function from_php_errno( $errno ) {
		switch ( $errno ) {
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_PARSE:
				return 'fatal';
			case E_USER_ERROR:
			case E_RECOVERABLE_ERROR:
				return 'error';
			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				return 'warn';
			case E_NOTICE:
			case E_USER_NOTICE:
				return 'info';
			case E_DEPRECATED:
			case E_USER_DEPRECATED:
			case self::LEVEL_STRICT:
				return 'debug';
			default:
				return 'warn';
		}
	}

	/**
	 * Symbolic name and a readable label for a PHP error level.
	 *
	 * @param int $errno PHP error level.
	 * @return array{0:string,1:string} [ constant name, label ]
	 */
	public static function php_error_name( $errno ) {
		$names = array(
			E_ERROR             => array( 'E_ERROR', 'Fatal error' ),
			E_WARNING           => array( 'E_WARNING', 'Warning' ),
			E_PARSE             => array( 'E_PARSE', 'Parse error' ),
			E_NOTICE            => array( 'E_NOTICE', 'Notice' ),
			E_CORE_ERROR        => array( 'E_CORE_ERROR', 'Core error' ),
			E_CORE_WARNING      => array( 'E_CORE_WARNING', 'Core warning' ),
			E_COMPILE_ERROR     => array( 'E_COMPILE_ERROR', 'Compile error' ),
			E_COMPILE_WARNING   => array( 'E_COMPILE_WARNING', 'Compile warning' ),
			E_USER_ERROR        => array( 'E_USER_ERROR', 'Fatal error' ),
			E_USER_WARNING      => array( 'E_USER_WARNING', 'Warning' ),
			E_USER_NOTICE       => array( 'E_USER_NOTICE', 'Notice' ),
			self::LEVEL_STRICT  => array( 'E_STRICT', 'Strict standards' ),
			E_RECOVERABLE_ERROR => array( 'E_RECOVERABLE_ERROR', 'Recoverable error' ),
			E_DEPRECATED        => array( 'E_DEPRECATED', 'Deprecated' ),
			E_USER_DEPRECATED   => array( 'E_USER_DEPRECATED', 'Deprecated' ),
		);
		return isset( $names[ $errno ] ) ? $names[ $errno ] : array( 'E_UNKNOWN', 'Error' );
	}
}
