<?php
/**
 * PHP error, exception and fatal-error collector.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Collectors;

use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Severity;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Trace;

defined( 'ABSPATH' ) || exit;

/**
 * Cooperates with PHP and WordPress instead of replacing them:
 *
 *  - Warnings and notices: an error handler chained in front of any existing one. It always calls the
 *    previous handler and returns its result (or false, so PHP's own handling - display_errors,
 *    log_errors, WP_DEBUG_LOG - carries on exactly as before). Notices, deprecations and strict-standards
 *    messages are recorded only while WP_DEBUG is on, which is WordPress's own default reporting level.
 *    PHP's own reporting level is neither changed nor read, so a warning silenced with the @ operator
 *    is recorded like any other warning.
 *  - Uncaught exceptions: if another exception handler already exists (an error tracker, WP-CLI), ours
 *    is chained in front of it - log, then hand over. If there is none, NOTHING is installed: PHP raises
 *    its normal "Uncaught ..." fatal error and the fatal-error path below records it, so PHP and
 *    WordPress's recovery mode behave exactly as if this plugin were not installed.
 *  - Fatal errors: read from error_get_last() at shutdown. WordPress's own fatal-error handler runs
 *    first and usually ends the request inside wp_die(), which would skip later shutdown functions,
 *    so the wp_die handlers are wrapped to record the fatal error just before that happens.
 *
 * Nothing here talks to the network; events go to the in-memory buffer and then the local queue.
 * Recursion is prevented (errors raised by the pipeline itself are ignored) and every path is wrapped
 * so a failure while logging can never surface in the response.
 *
 * Limits, stated plainly: errors that happen before this plugin loads (wp-config.php, must-use
 * plugins and plugins loaded earlier), out-of-memory conditions that leave no room to run anything,
 * and processes killed from outside cannot be captured by a normal plugin.
 */
final class Php_Errors {

	/**
	 * Error types that end the request and reach shutdown as error_get_last().
	 */
	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	/**
	 * Test seam standing in for error_get_last() (a real fatal error cannot be produced inside a test
	 * run). Null in production.
	 *
	 * @var callable|null
	 */
	public static $last_error_source = null;

	/**
	 * Levels WordPress hides unless WP_DEBUG is on (see wp_debug_mode()).
	 */
	const QUIET_TYPES = array( E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, Severity::LEVEL_STRICT );

	/**
	 * Recorder.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * Handler that was installed before ours.
	 *
	 * @var callable|null
	 */
	private $previous_error = null;

	/**
	 * Exception handler that was installed before ours.
	 *
	 * @var callable|null
	 */
	private $previous_exception = null;

	/**
	 * Whether notices, deprecations and strict-standards messages are recorded (WordPress shows them
	 * only while WP_DEBUG is on).
	 *
	 * @var bool
	 */
	private $record_notices = false;

	/**
	 * Memory released at shutdown so a fatal out-of-memory error still has room to be recorded.
	 *
	 * @var string|null
	 */
	private $reserve = null;

	/**
	 * Whether register() ran.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private $in_handler = false;

	/**
	 * Signatures of fatal-type errors already reported (handler vs. shutdown vs. wp_die).
	 *
	 * @var array<string,bool>
	 */
	private $reported = array();

	/**
	 * The uncaught exception this collector reported, so the fatal error PHP raises for it afterwards
	 * is not reported a second time.
	 *
	 * @var array|null
	 */
	private $uncaught = null;

	/**
	 * Constructor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Install the handlers.
	 *
	 * @return void
	 */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered     = true;
		$this->record_notices = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$this->reserve        = str_repeat( ' ', 32768 );

		$this->previous_error = set_error_handler( array( $this, 'handle_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- observing errors is the purpose of this class; the previous handler is kept and still called.

		// Uncaught exceptions: chain in front of an existing handler (log, then hand over). When there is
		// none, install nothing: PHP then raises its normal "Uncaught ..." fatal error, which the shutdown
		// and wp_die paths below record - and PHP and WordPress behave exactly as if this plugin were
		// absent. Removing our own handler from inside itself, to re-throw, is not safe: it frees the
		// callable PHP is executing.
		$previous = set_exception_handler( array( $this, 'handle_exception' ) );
		if ( null === $previous ) {
			restore_exception_handler();
		} else {
			$this->previous_exception = $previous;
		}

		register_shutdown_function( array( $this, 'handle_shutdown' ) );

		foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' ) as $filter ) {
			add_filter( $filter, array( $this, 'wrap_die_handler' ), PHP_INT_MAX );
		}
	}

	/**
	 * PHP error handler (E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_* ...).
	 *
	 * @param int    $errno      Level.
	 * @param string $errstr     Message.
	 * @param string $errfile    File.
	 * @param int    $errline    Line.
	 * @param mixed  $errcontext Passed by PHP < 8; forwarded untouched.
	 * @return bool
	 */
	public function handle_error( $errno, $errstr, $errfile = '', $errline = 0, $errcontext = null ) {
		$arguments = func_get_args(); // Taken before anything is touched, so a previous handler gets what PHP passed.
		unset( $errcontext );
		if ( ! $this->in_handler && ! $this->recorder->is_busy() && ( $this->record_notices || ! in_array( (int) $errno, self::QUIET_TYPES, true ) ) ) {
			$this->in_handler = true;
			try {
				$this->capture_error( (int) $errno, (string) $errstr, (string) $errfile, (int) $errline );
			} catch ( \Throwable $e ) {
				unset( $e ); // Logging must never break the request.
			} finally {
				$this->in_handler = false;
			}
		}

		if ( null !== $this->previous_error && is_callable( $this->previous_error ) ) {
			return call_user_func_array( $this->previous_error, $arguments );
		}
		return false; // Let PHP's standard handling continue unchanged.
	}

	/**
	 * Uncaught exception handler.
	 *
	 * @param \Throwable $e Exception.
	 * @return void
	 */
	public function handle_exception( $e ) {
		try {
			if ( $e instanceof \Throwable && ! $this->recorder->is_busy() ) {
				$this->capture_throwable( $e );
			}
		} catch ( \Throwable $inner ) {
			unset( $inner );
		}

		if ( null !== $this->previous_exception && is_callable( $this->previous_exception ) ) {
			call_user_func( $this->previous_exception, $e );
		}
	}

	/**
	 * Shutdown function: record a fatal error that ended the request.
	 *
	 * @return void
	 */
	public function handle_shutdown() {
		$this->reserve = null; // Free the reserve first: an out-of-memory fatal needs room to run this.
		try {
			$this->capture_last_error();
			$this->recorder->flush();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Wrap a wp_die handler so a fatal error is recorded before wp_die() ends the request.
	 *
	 * @param callable $handler Original handler.
	 * @return callable
	 */
	public function wrap_die_handler( $handler ) {
		if ( ! is_callable( $handler ) ) {
			return $handler;
		}
		$collector = $this;
		return static function ( $message = '', $title = '', $args = array() ) use ( $handler, $collector ) {
			try {
				if ( $collector->capture_last_error() ) {
					$collector->flush_now();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			call_user_func( $handler, $message, $title, $args );
		};
	}

	/**
	 * Persist buffered events immediately (the request is about to end without shutdown functions).
	 *
	 * @return void
	 */
	public function flush_now() {
		$this->reserve = null;
		$this->recorder->flush();
	}

	/**
	 * Record a warning / notice / deprecation.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 * @return void
	 */
	private function capture_error( $errno, $errstr, $errfile, $errline ) {
		$severity = Severity::from_php_errno( $errno );
		if ( in_array( $errno, self::FATAL_TYPES, true ) ) {
			$this->reported[ $this->signature( $errno, $errfile, $errline, $errstr ) ] = true;
		}
		if ( ! $this->recorder->would_record( $severity ) ) {
			return;
		}

		list( $const, $label ) = Severity::php_error_name( $errno );
		$max                   = (int) Limits::get( 'max_frames' );

		$stack = '';
		if ( (int) Limits::get( 'max_stack_bytes' ) > 0 ) {
			// debug_backtrace with IGNORE_ARGS: function arguments are never read.
			$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $max + 3 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- called with DEBUG_BACKTRACE_IGNORE_ARGS to build a stack trace without function arguments.
			$stack  = $errfile . ':' . $errline . "\n" . Trace::from_frames( $frames, 2 );
		}

		$this->recorder->record(
			'php_error',
			$severity,
			sprintf( 'PHP %s: %s in %s on line %d', $label, $errstr, $errfile, $errline ),
			array(
				'fields'   => array_merge(
					array(
						'phpErrorType' => $const,
						'phpErrorCode' => $errno,
						'file'         => $errfile,
						'line'         => $errline,
					),
					$this->component( $errfile )
				),
				'stack'    => $stack,
				'dedupe'   => true,
				'fp_extra' => $errfile . ':' . $errline,
			)
		);
	}

	/**
	 * Record an uncaught exception.
	 *
	 * @param \Throwable $e Exception.
	 * @return void
	 */
	private function capture_throwable( \Throwable $e ) {
		$this->uncaught = array(
			'class' => get_class( $e ),
			'file'  => $e->getFile(),
			'line'  => $e->getLine(),
		);
		$this->recorder->record(
			'php_exception',
			'fatal',
			sprintf( 'PHP Fatal error: Uncaught %s: %s in %s on line %d', get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() ),
			array(
				'fields'     => array_merge(
					array(
						'phpErrorType'     => 'E_ERROR',
						'exceptionClass'   => get_class( $e ),
						'exceptionMessage' => $e->getMessage(),
						'file'             => $e->getFile(),
						'line'             => $e->getLine(),
					),
					$this->component( $e->getFile() )
				),
				'stack'      => Trace::from_throwable( $e ),
				'dedupe'     => true,
				'fp_extra'   => $e->getFile() . ':' . $e->getLine(),
				'bypass_cap' => true,
			)
		);
		\ZipLogger\WordPress\Tracing\Tracer::note_error( get_class( $e ), $e->getMessage(), Trace::from_throwable( $e ) );
		$this->recorder->flush();
	}

	/**
	 * Record the last PHP error if it was fatal and has not been reported yet.
	 *
	 * @return bool True when something was recorded.
	 */
	public function capture_last_error() {
		$last = null !== self::$last_error_source ? call_user_func( self::$last_error_source ) : error_get_last();
		if ( ! is_array( $last ) || ! in_array( $last['type'], self::FATAL_TYPES, true ) ) {
			return false;
		}
		$file = isset( $last['file'] ) ? (string) $last['file'] : '';
		$line = isset( $last['line'] ) ? (int) $last['line'] : 0;
		$text = isset( $last['message'] ) ? (string) $last['message'] : '';

		$sig = $this->signature( (int) $last['type'], $file, $line, $text );
		if ( isset( $this->reported[ $sig ] ) ) {
			return false;
		}
		$this->reported[ $sig ] = true;

		// The fatal error PHP raises for an uncaught exception that handle_exception() already reported.
		if ( null !== $this->uncaught && E_ERROR === (int) $last['type']
			&& 0 === strpos( $text, 'Uncaught ' . $this->uncaught['class'] )
			&& $file === $this->uncaught['file'] && $line === $this->uncaught['line'] ) {
			return false;
		}

		$this->reserve           = null;
		list( $message, $trace ) = Trace::split_fatal_message( $text );
		list( $const, $label )   = Severity::php_error_name( (int) $last['type'] );
		$where                   = ( '' !== $file && false === strpos( $message, $file ) ) ? sprintf( ' in %s on line %d', $file, $line ) : '';

		$extra = array();
		$type  = 'php_fatal';
		if ( E_ERROR === (int) $last['type'] && 1 === preg_match( '/^Uncaught ([A-Za-z_][A-Za-z0-9_\\\\]*): (.*?)(?: in \S+:\d+)?$/s', strtok( $message, "\n" ), $m ) ) {
			// PHP reports an uncaught exception as a fatal error; keep the class so it groups with handled ones.
			$type  = 'php_exception';
			$extra = array( 'exceptionClass' => $m[1] );
		}
		// The request span shows the failure too: the exception class when there is one, else the error level.
		\ZipLogger\WordPress\Tracing\Tracer::note_error( isset( $extra['exceptionClass'] ) ? $extra['exceptionClass'] : $const, $message, $trace );

		return $this->recorder->record(
			$type,
			'fatal',
			'PHP ' . $label . ': ' . $message . $where,
			array(
				'fields'     => array_merge(
					array(
						'phpErrorType' => $const,
						'phpErrorCode' => (int) $last['type'],
						'file'         => $file,
						'line'         => $line,
					),
					$extra,
					$this->component( $file )
				),
				'stack'      => '' !== $trace ? $trace : $file . ':' . $line,
				'dedupe'     => true,
				'fp_extra'   => $file . ':' . $line,
				'bypass_cap' => true,
			)
		);
	}

	/**
	 * A key for "have we seen this exact fatal error".
	 *
	 * @param int    $type Level.
	 * @param string $file File.
	 * @param int    $line Line.
	 * @param string $msg  Message.
	 * @return string
	 */
	private function signature( $type, $file, $line, $msg ) {
		return $type . '|' . $file . '|' . $line . '|' . md5( $msg );
	}

	/**
	 * Which part of the site the file belongs to: plugin, theme, mu-plugin or core, with the slug.
	 *
	 * @param string $file Absolute file path.
	 * @return array{component:string,componentSlug?:string}
	 */
	public function component( $file ) {
		$f  = str_replace( '\\', '/', $file );
		$in = static function ( $root ) use ( $f ) {
			if ( ! is_string( $root ) || '' === $root ) {
				return null;
			}
			$r = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
			if ( 0 === strpos( $f, $r ) ) {
				$rest  = substr( $f, strlen( $r ) );
				$slash = strpos( $rest, '/' );
				return false === $slash ? preg_replace( '/\.php$/', '', $rest ) : substr( $rest, 0, $slash );
			}
			return null;
		};

		$slug = defined( 'WP_PLUGIN_DIR' ) ? $in( WP_PLUGIN_DIR ) : null;
		if ( null !== $slug ) {
			return array(
				'component'     => 'plugin',
				'componentSlug' => $slug,
			);
		}
		$slug = defined( 'WPMU_PLUGIN_DIR' ) ? $in( WPMU_PLUGIN_DIR ) : null;
		if ( null !== $slug ) {
			return array(
				'component'     => 'mu-plugin',
				'componentSlug' => $slug,
			);
		}
		$slug = function_exists( 'get_theme_root' ) ? $in( get_theme_root() ) : null;
		if ( null !== $slug ) {
			return array(
				'component'     => 'theme',
				'componentSlug' => $slug,
			);
		}
		if ( defined( 'ABSPATH' ) && ( null !== $in( ABSPATH . 'wp-includes' ) || null !== $in( ABSPATH . 'wp-admin' ) || 0 === strpos( $f, rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/wp-' ) ) ) {
			return array( 'component' => 'core' );
		}
		return array( 'component' => 'other' );
	}
}
