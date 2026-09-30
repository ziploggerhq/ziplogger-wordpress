<?php
/**
 * The server-side request span.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Tracing;

use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Event_Factory;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Otlp;
use ZipLogger\WordPress\Queue_Writer;
use ZipLogger\WordPress\Redactor;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Severity;
use ZipLogger\WordPress\Signal;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per PHP request. It is created as early as the plugin loads (so the span starts when the
 * request started), decides sampling once, lets outbound HTTP calls attach child spans, and writes the
 * finished spans to the local queue when the request ends. From there the normal, bounded, retrying
 * delivery pipeline sends them to /v1/traces.
 *
 * Sampling is parent-aware: a valid inbound traceparent decides (its sampled flag is honoured, within a
 * per-minute budget so a stranger cannot make every request of this site a recorded one); otherwise the
 * site's own rate decides, derived from the trace id so the decision is reproducible.
 *
 * Nothing here is added to the response. In particular the trace id is never put in the HTML or in a
 * response header: those can be stored by a page cache and handed to other visitors.
 */
final class Tracer {

	/**
	 * The instance for this request.
	 *
	 * @var Tracer|null
	 */
	private static $instance = null;

	/**
	 * Test seam: the HTTP status the request is ending with (null in production).
	 *
	 * @var callable|null
	 */
	public static $status_source = null;

	/**
	 * Test seam: the queries WordPress recorded (null in production).
	 *
	 * @var callable|null
	 */
	public static $queries_source = null;

	/**
	 * Server variables to read (null: the real ones). Tests set this.
	 *
	 * @var array|null
	 */
	public static $server = null;

	/**
	 * Trace id (32 hex characters).
	 *
	 * @var string
	 */
	private $trace_id;

	/**
	 * Span id of the request span (16 hex characters).
	 *
	 * @var string
	 */
	private $span_id;

	/**
	 * Parent span id from the caller ('' for a root).
	 *
	 * @var string
	 */
	private $parent_id = '';

	/**
	 * Whether spans of this trace are recorded.
	 *
	 * @var bool
	 */
	private $sampled = false;

	/**
	 * Whether the trace came from another party.
	 *
	 * @var bool
	 */
	private $remote = false;

	/**
	 * Validated session id from inbound baggage, or ''.
	 *
	 * @var string
	 */
	private $session = '';

	/**
	 * Request start (Unix time, float).
	 *
	 * @var float
	 */
	private $start;

	/**
	 * Finished child span documents (OTLP arrays).
	 *
	 * @var array[]
	 */
	private $children = array();

	/**
	 * Children refused because of the per-request cap.
	 *
	 * @var int
	 */
	private $dropped_children = 0;

	/**
	 * First recorded error.
	 *
	 * @var array|null
	 */
	private $error = null;

	/**
	 * Whether finish() already ran.
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * Set for requests that must not produce spans (see exclude()).
	 *
	 * @var bool
	 */
	private $excluded = false;

	/**
	 * Suspend depth (the plugin's own traffic is never traced).
	 *
	 * @var int
	 */
	private $suspended = 0;

	/**
	 * Module settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Create the tracer for this request if tracing is on. Called once at plugin load.
	 *
	 * @return Tracer|null
	 */
	public static function maybe_start() {
		if ( null !== self::$instance ) {
			return self::$instance;
		}
		try {
			if ( ! self::wanted() ) {
				return null;
			}
			self::$instance = new self( null !== self::$server ? self::$server : $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read only to classify the request; never output or stored as given.
			add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), PHP_INT_MAX );
			Outbound::register();
			return self::$instance;
		} catch ( \Throwable $e ) {
			self::$instance = null;
			return null;
		}
	}

	/**
	 * Whether this request should be traced at all.
	 *
	 * @return bool
	 */
	private static function wanted() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return false;
		}
		if ( ! Modules::effective( 'tracing' ) ) {
			return false;
		}
		$s = Settings::get();
		if ( empty( $s['tracing']['server_spans'] ) && empty( $s['tracing']['outbound_spans'] ) ) {
			return false;
		}
		// The plugin's own visitor-context endpoint is called on every sampled page view; tracing it would
		// only trace ourselves.
		if ( isset( $_REQUEST['action'] ) && 'ziplogger_context' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- read only to name the request; nothing is changed or output.
			return false;
		}
		return true;
	}

	/**
	 * The tracer of this request, if any.
	 *
	 * @return Tracer|null
	 */
	public static function current() {
		return self::$instance;
	}

	/**
	 * Forget the tracer (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$instance = null;
		self::$server   = null;
		remove_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), PHP_INT_MAX );
		Outbound::unregister();
	}

	/**
	 * The trace and span ids for log correlation, or null when this request is not being traced.
	 *
	 * @return array{traceId:string,spanId:string}|null
	 */
	public static function ids() {
		$t = self::$instance;
		return null === $t ? null : array(
			'traceId' => $t->trace_id,
			'spanId'  => $t->span_id,
		);
	}

	/**
	 * Report an uncaught exception or fatal error so the request span shows it.
	 *
	 * @param string $type    Exception class or error type.
	 * @param string $message Message (redacted here).
	 * @param string $stack   Stack trace (redacted here).
	 * @return void
	 */
	public static function note_error( $type, $message, $stack = '' ) {
		if ( null !== self::$instance ) {
			self::$instance->record_error( $type, $message, $stack );
		}
	}

	/**
	 * Do not record this request. Used for requests that only exist to deliver telemetry: a span for
	 * "the request that sent the spans" would itself need delivering, and so on, forever.
	 *
	 * @return void
	 */
	public static function exclude() {
		if ( null !== self::$instance ) {
			self::$instance->excluded = true;
		}
	}

	/**
	 * Shutdown callback.
	 *
	 * @return void
	 */
	public static function on_shutdown() {
		if ( null !== self::$instance ) {
			self::$instance->finish();
		}
	}

	/**
	 * Constructor: adopts a valid inbound context or starts a new trace, and decides sampling.
	 *
	 * @param array $server Server variables.
	 */
	public function __construct( array $server ) {
		$this->settings = Settings::get();
		$this->start    = isset( $server['REQUEST_TIME_FLOAT'] ) && is_numeric( $server['REQUEST_TIME_FLOAT'] ) ? (float) $server['REQUEST_TIME_FLOAT'] : microtime( true );
		$this->span_id  = Context::random_id( 8 );

		$inbound = isset( $server['HTTP_TRACEPARENT'] ) ? Context::parse_traceparent( $server['HTTP_TRACEPARENT'] ) : null;
		if ( null !== $inbound ) {
			$this->trace_id  = $inbound['trace_id'];
			$this->parent_id = $inbound['parent_id'];
			$this->remote    = true;
			$this->session   = isset( $server['HTTP_BAGGAGE'] ) ? Context::parse_baggage_session( $server['HTTP_BAGGAGE'] ) : '';
			$this->sampled   = $inbound['sampled'] ? ( $this->inbound_budget() || $this->local_decision() ) : false;
		} else {
			$this->trace_id = Context::random_id( 16 );
			$this->sampled  = $this->local_decision();
		}
	}

	/**
	 * The site's own sampling decision for this trace id.
	 *
	 * @return bool
	 */
	private function local_decision() {
		$rate = (int) $this->settings['tracing']['sample_rate'];
		return $rate > 0 && Context::fraction( $this->trace_id ) * 100 < $rate;
	}

	/**
	 * May another sampled inbound trace be honoured this minute?
	 *
	 * @return bool
	 */
	private function inbound_budget() {
		$limit = (int) Limits::get( 'inbound_sampled_per_minute' );
		if ( $limit <= 0 ) {
			return false;
		}
		$key  = 'ziplogger_inb_' . gmdate( 'YmdHi', Clock::time() );
		$used = (int) get_transient( $key );
		if ( $used >= $limit ) {
			return false;
		}
		set_transient( $key, $used + 1, 120 );
		return true;
	}

	/**
	 * Trace id.
	 *
	 * @return string
	 */
	public function trace_id() {
		return $this->trace_id;
	}

	/**
	 * Span id of the request span.
	 *
	 * @return string
	 */
	public function span_id() {
		return $this->span_id;
	}

	/**
	 * Whether spans of this trace are recorded.
	 *
	 * @return bool
	 */
	public function sampled() {
		return $this->sampled;
	}

	/**
	 * Whether the trace came from another party.
	 *
	 * @return bool
	 */
	public function remote() {
		return $this->remote;
	}

	/**
	 * Session id from inbound baggage ('' when there was none or it was not valid).
	 *
	 * @return string
	 */
	public function session() {
		return $this->session;
	}

	/**
	 * Parent for child spans: the request span when it is exported, otherwise the caller's span.
	 *
	 * @return string
	 */
	public function child_parent() {
		return ! empty( $this->settings['tracing']['server_spans'] ) ? $this->span_id : $this->parent_id;
	}

	/**
	 * Stop tracing the plugin's own traffic while it runs.
	 *
	 * @return void
	 */
	public function suspend() {
		++$this->suspended;
	}

	/**
	 * Resume.
	 *
	 * @return void
	 */
	public function resume() {
		$this->suspended = max( 0, $this->suspended - 1 );
	}

	/**
	 * Whether tracing is paused.
	 *
	 * @return bool
	 */
	public function suspended() {
		return $this->suspended > 0;
	}

	/**
	 * Attach a finished child span. Refused (and counted) beyond the per-request cap.
	 *
	 * @param array $span OTLP span array from Otlp::span().
	 * @return bool
	 */
	public function add_child( array $span ) {
		if ( count( $this->children ) >= (int) Limits::get( 'request_span_cap' ) ) {
			++$this->dropped_children;
			return false;
		}
		$this->children[] = $span;
		return true;
	}

	/**
	 * Remember an error for the request span. The first one wins: a fatal error usually follows the
	 * exception that caused it.
	 *
	 * @param string $type    Exception class or error type.
	 * @param string $message Message.
	 * @param string $stack   Stack trace.
	 * @return void
	 */
	public function record_error( $type, $message, $stack = '' ) {
		if ( null !== $this->error ) {
			return;
		}
		try {
			$redactor    = Redactor::from_filters();
			$this->error = array(
				'type'    => preg_replace( '/[^A-Za-z0-9_\\\\.:\-]/', '', substr( (string) $type, 0, 120 ) ),
				'message' => $redactor->text( (string) $message, 512 ),
				'stack'   => '' === (string) $stack ? '' : $redactor->text( (string) $stack, 4096 ),
				'time'    => microtime( true ),
			);
		} catch ( \Throwable $e ) {
			$this->error = array(
				'type'    => 'error',
				'message' => '',
				'stack'   => '',
				'time'    => microtime( true ),
			);
		}
	}

	/**
	 * End the request: build the spans and hand them to the queue. Never throws.
	 *
	 * @return void
	 */
	public function finish() {
		if ( $this->finished ) {
			return;
		}
		$this->finished = true;
		try {
			if ( ! $this->sampled || $this->excluded ) {
				return;
			}
			$docs     = array();
			$is_error = false;
			$tracing  = $this->settings['tracing'];

			if ( ! empty( $tracing['server_spans'] ) ) {
				$root     = $this->build_request_span();
				$is_error = null !== $this->error || 2 === $root['status']['code'];
				$docs[]   = array(
					'span'  => $root,
					'error' => $is_error,
				);
			}
			foreach ( $this->children as $child ) {
				$docs[] = array(
					'span'  => $child,
					'error' => isset( $child['status']['code'] ) && 2 === (int) $child['status']['code'],
				);
			}
			if ( ! $docs ) {
				return;
			}

			$candidates = array();
			foreach ( $docs as $d ) {
				$json = wp_json_encode( $d['span'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( ! is_string( $json ) ) {
					continue;
				}
				$candidates[] = array(
					'payload'     => $json,
					'size'        => strlen( $json ),
					'severity'    => Severity::rank( $d['error'] ? 'error' : 'info' ),
					'fingerprint' => '',
				);
			}
			( new Queue_Writer() )->write( Signal::TRACES, $candidates );
		} catch ( \Throwable $e ) {
			unset( $e ); // Tracing must never break the request it observes.
		}
	}

	/**
	 * The request span as an OTLP span array.
	 *
	 * @return array
	 */
	private function build_request_span() {
		$tracing = $this->settings['tracing'];
		$end     = microtime( true );
		$status  = $this->status_code();
		$context = Route::request_context();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read only to classify the request; never output or stored as given.
		$method = in_array( $method, array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ? $method : '_OTHER';
		$route  = Route::name( $context );

		$attrs = array(
			'http.request.method'       => $method,
			'http.route'                => $route,
			'http.response.status_code' => $status,
			'url.scheme'                => is_ssl() ? 'https' : 'http',
			'server.address'            => Event_Factory::site_host(),
			'ziplogger.request.context' => $context,
			'php.memory.peak_bytes'     => (int) memory_get_peak_usage( true ),
		);
		if ( is_multisite() ) {
			$attrs['wordpress.blog_id'] = (int) get_current_blog_id();
		}
		if ( Route::FRONTEND === $context && did_action( 'template_redirect' ) && class_exists( '\ZipLogger\WordPress\Frontend' ) ) {
			$attrs['wordpress.page.type'] = \ZipLogger\WordPress\Frontend::page_type();
		}
		if ( ! empty( $tracing['record_path'] ) ) {
			$attrs['url.path'] = Route::normalize( Route::request_path() );
		}
		if ( '' !== $this->session ) {
			$attrs['session.id'] = $this->session;
		}
		if ( $this->remote ) {
			$attrs['ziplogger.trace.remote_parent'] = true;
		}
		if ( $this->dropped_children > 0 ) {
			$attrs['ziplogger.child_spans_dropped'] = $this->dropped_children;
		}
		$attrs = array_merge( $attrs, $this->db_attributes( $tracing ) );

		$failed  = null !== $this->error || $status >= 500;
		$events  = array();
		$message = '';
		if ( null !== $this->error ) {
			$message  = $this->error['type'];
			$events[] = array(
				'exception',
				$this->error['time'],
				array_filter(
					array(
						'exception.type'       => $this->error['type'],
						'exception.message'    => $this->error['message'],
						'exception.stacktrace' => $this->error['stack'],
					),
					'strlen'
				),
			);
		} elseif ( $status >= 500 ) {
			$message = 'HTTP ' . $status;
		}

		$span = array(
			'trace_id'       => $this->trace_id,
			'span_id'        => $this->span_id,
			'name'           => $method . ' ' . $route,
			'kind'           => Otlp::KIND_SERVER,
			'start'          => $this->start,
			'end'            => $end,
			'attributes'     => $attrs,
			'status'         => $failed ? Otlp::STATUS_ERROR : Otlp::STATUS_UNSET,
			'status_message' => $message,
			'events'         => $events,
		);
		if ( '' !== $this->parent_id ) {
			$span['parent_id'] = $this->parent_id;
		}
		return Otlp::span( $span );
	}

	/**
	 * Database attributes. The query COUNT is free (WordPress keeps it for every request). Timing exists
	 * only when the site already runs with SAVEQUERIES: this plugin never turns that on, because it stores
	 * every query with a backtrace and slows every request down.
	 *
	 * @param array $tracing Tracing settings.
	 * @return array
	 */
	private function db_attributes( array $tracing ) {
		global $wpdb;
		$out = array();
		if ( ! empty( $tracing['db_query_count'] ) && isset( $wpdb->num_queries ) ) {
			$out['wordpress.db.query_count'] = (int) $wpdb->num_queries;
		}
		if ( ! empty( $tracing['db_timing'] ) ) {
			$queries = null !== self::$queries_source ? call_user_func( self::$queries_source ) : ( ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && isset( $wpdb->queries ) ) ? $wpdb->queries : null );
			if ( is_array( $queries ) && $queries ) {
				$total   = 0.0;
				$slowest = 0.0;
				foreach ( $queries as $q ) {
					$t       = is_array( $q ) && isset( $q[1] ) ? (float) $q[1] : 0.0;
					$total  += $t;
					$slowest = max( $slowest, $t );
				}
				$out['wordpress.db.total_ms']   = (int) round( $total * 1000 );
				$out['wordpress.db.slowest_ms'] = (int) round( $slowest * 1000 );
				$out['wordpress.db.timing']     = 'SAVEQUERIES';
			}
		}
		return $out;
	}

	/**
	 * The HTTP status the request is ending with.
	 *
	 * @return int
	 */
	private function status_code() {
		$code = null !== self::$status_source ? call_user_func( self::$status_source ) : http_response_code();
		$code = is_numeric( $code ) ? (int) $code : 200;
		return ( $code >= 100 && $code <= 599 ) ? $code : 200;
	}
}
