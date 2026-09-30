<?php
/**
 * Shared test case: resets every piece of static state, provides an HTTP mock, and helpers.
 */

use ZipLogger\WordPress\Backoff;
use ZipLogger\WordPress\Clock;
use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Event_Factory;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Recorder;
use ZipLogger\WordPress\Schema;
use ZipLogger\WordPress\Settings;

abstract class ZL_TestCase extends WP_UnitTestCase {

	const KEY      = 'zk_test_0123456789abcdef0123456789';
	const ENDPOINT = 'https://app.ziplogger.ai';

	/**
	 * Requests captured by the HTTP mock: list of [ url, args ].
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Responses to return, in order. A callable receives ( $args, $url, $index ) and returns a response.
	 * When exhausted, the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	/**
	 * @var Queue_Store
	 */
	protected $queue;

	/**
	 * @var Meta_Store
	 */
	protected $meta;

	public function set_up() {
		parent::set_up();
		Schema::maybe_upgrade();
		Clock::freeze( 1790000000 ); // 2026-09-21T13:33:20Z - fixed so scheduling maths is exact.
		Limits::reset();
		Settings::reset_cache();
		Settings::$constants   = array();
		Backoff::$rng          = static function ( $min, $max ) {
			return $max; // Deterministic: the upper end of the jitter range.
		};
		Endpoint::$resolver    = static function () {
			return array( '93.184.216.34' ); // A public address.
		};
		Event_Factory::reset_request_id();
		\ZipLogger\WordPress\Collectors\Php_Errors::$last_error_source = null;

		$this->queue = new Queue_Store();
		$this->meta  = new Meta_Store();
		\ZipLogger\WordPress\Plugin::instance()->recorder()->reset();
		$this->queue->clear();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::meta_table() ); // phpcs:ignore WordPress.DB
		delete_option( Settings::OPTION );
		delete_option( Settings::KEY_OPTION );
		putenv( 'ZIPLOGGER_API_KEY' );

		$this->requests  = array();
		$this->responses = array();
		add_filter( 'pre_http_request', array( $this, 'http_mock' ), 10, 3 );
		wp_clear_scheduled_hook( \ZipLogger\WordPress\Scheduler::HOOK_DELIVER );
	}

	public function tear_down() {
		Clock::freeze( null );
		Backoff::$rng                 = null;
		Endpoint::$resolver           = null;
		Settings::$constants          = array();
		Limits::reset();
		Settings::reset_cache();
		remove_all_filters( 'ziplogger_limits' );
		remove_all_filters( 'ziplogger_should_log' );
		remove_all_filters( 'ziplogger_event' );
		remove_all_filters( 'ziplogger_redact_keys' );
		remove_all_filters( 'ziplogger_redact_patterns' );
		remove_all_filters( 'ziplogger_release' );
		remove_all_filters( 'ziplogger_commit_sha' );
		remove_all_filters( 'ziplogger_tags' );
		putenv( 'ZIPLOGGER_API_KEY' );
		parent::tear_down();
	}

	/**
	 * Switch collection on with a key.
	 *
	 * @param array $overrides Settings overrides.
	 */
	protected function enable( array $overrides = array() ) {
		Settings::save( array_merge( array( 'enabled' => true ), $overrides ) );
		Settings::save_api_key( self::KEY );
		Settings::reset_cache();
		Limits::reset();
	}

	/**
	 * pre_http_request handler: records the request and returns the next queued response.
	 */
	public function http_mock( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$i                = count( $this->requests );
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( ! $this->responses ) {
			return self::response( 202, array( 'accepted' => $this->count_events( $args['body'] ), 'rejected' => 0 ) );
		}
		$r = $i < count( $this->responses ) ? $this->responses[ $i ] : end( $this->responses );
		return is_callable( $r ) ? call_user_func( $r, $args, $url, $i ) : $r;
	}

	/**
	 * Build a WordPress-style HTTP response.
	 */
	public static function response( $status, $body = array(), array $headers = array() ) {
		return array(
			'headers'  => array_change_key_case( $headers, CASE_LOWER ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array( 'code' => $status, 'message' => 'x' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	protected function count_events( $body ) {
		$d = json_decode( (string) $body, true );
		return is_array( $d ) ? count( $d ) : 0;
	}

	/**
	 * Insert N events straight into the queue.
	 *
	 * @param int    $n        Count.
	 * @param string $prefix   Message prefix.
	 * @param int    $severity Rank.
	 * @return string[] The JSON documents inserted.
	 */
	protected function seed( $n, $prefix = 'event', $severity = 2 ) {
		$rows = array();
		$docs = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$doc    = wp_json_encode(
				array(
					'timestamp' => Clock::iso(),
					'source'    => 'wordpress',
					'severity'  => 'warn',
					'message'   => $prefix . ' ' . $i,
					'fields'    => array( 'n' => $i ),
					'tags'      => array( 'wordpress' ),
				)
			);
			$docs[] = $doc;
			$rows[] = array(
				'payload'     => $doc,
				'size'        => strlen( $doc ),
				'severity'    => $severity,
				'fingerprint' => '',
				'created_at'  => Clock::time(),
			);
		}
		$this->queue->insert_many( $rows );
		return $docs;
	}

	protected function queue_count() {
		return $this->queue->stats()['count'];
	}

	/**
	 * All queued payloads decoded, oldest first.
	 */
	protected function queued_events() {
		global $wpdb;
		$rows = $wpdb->get_col( 'SELECT payload FROM ' . Schema::queue_table() . ' ORDER BY id ASC' ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( $p ) {
				return json_decode( $p, true );
			},
			$rows
		);
	}

	/**
	 * Raw queued payload strings.
	 */
	protected function queued_payloads() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT payload FROM ' . Schema::queue_table() . ' ORDER BY id ASC' ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Run a callable that ends in wp_die() and return the WPDieException, or null if it did not die.
	 */
	protected function catch_die( callable $fn ) {
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		try {
			$fn();
		} catch ( WPDieException $e ) {
			return $e;
		} finally {
			remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		}
		return null;
	}

	public function die_handler() {
		return array( $this, 'die_throw' );
	}

	public function die_throw( $message ) {
		throw new WPDieException( is_string( $message ) ? $message : 'die' );
	}
}
