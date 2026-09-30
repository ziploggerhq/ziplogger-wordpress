<?php
/**
 * Stack trace formatting without arguments.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Frames look like "#0 wp-content/plugins/x/x.php:12 Class->method()". The "file:line" shape is
 * what ZipLogger's stack-trace parser recognises for regression attribution, and no frame ever
 * carries function arguments (they routinely contain passwords, tokens and personal data): the
 * "args" key of PHP traces is never read.
 */
final class Trace {

	/**
	 * Format debug_backtrace() / Exception::getTrace() frames.
	 *
	 * @param array $frames Frames.
	 * @param int   $skip   Leading frames to skip.
	 * @return string
	 */
	public static function from_frames( array $frames, $skip = 0 ) {
		$max   = (int) Limits::get( 'max_frames' );
		$lines = array();
		$n     = 0;
		foreach ( array_slice( $frames, $skip ) as $frame ) {
			if ( $n >= $max ) {
				$lines[] = sprintf( '... %d more frames', count( $frames ) - $skip - $n );
				break;
			}
			$call = '';
			if ( isset( $frame['function'] ) ) {
				$call = ( isset( $frame['class'] ) ? $frame['class'] . ( isset( $frame['type'] ) ? $frame['type'] : '::' ) : '' ) . $frame['function'] . '()';
			}
			$where   = isset( $frame['file'] ) ? $frame['file'] . ( isset( $frame['line'] ) ? ':' . (int) $frame['line'] : '' ) : '[internal]';
			$lines[] = '#' . $n . ' ' . $where . ( '' !== $call ? ' ' . $call : '' );
			++$n;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Format a Throwable, including up to two previous exceptions.
	 *
	 * @param \Throwable $e Throwable.
	 * @return string
	 */
	public static function from_throwable( \Throwable $e ) {
		$out   = array();
		$depth = 0;
		for ( $cur = $e; null !== $cur && $depth < 3; $cur = $cur->getPrevious(), $depth++ ) {
			$head  = ( 0 === $depth ? '' : 'Caused by: ' ) . get_class( $cur ) . ': ' . $cur->getMessage() . ' in ' . $cur->getFile() . ':' . $cur->getLine();
			$out[] = $head . "\n" . self::from_frames( $cur->getTrace() );
		}
		return implode( "\n", $out );
	}

	/**
	 * Split the "Stack trace:" section off a PHP fatal error message ("Uncaught X: msg in f:1\nStack trace:\n#0 ...").
	 *
	 * @param string $message Raw error message.
	 * @return array{0:string,1:string} [ message, trace ]
	 */
	public static function split_fatal_message( $message ) {
		$pos = strpos( $message, "\nStack trace:\n" );
		if ( false === $pos ) {
			return array( $message, '' );
		}
		$trace = substr( $message, $pos + strlen( "\nStack trace:\n" ) );
		// PHP appends "  thrown in file on line N" after the trace.
		$trace = (string) preg_replace( '/\n\s*thrown in .*$/s', '', $trace );
		return array( substr( $message, 0, $pos ), $trace );
	}
}
