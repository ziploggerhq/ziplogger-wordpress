<?php
/**
 * Failed login collector.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Collectors;

use ZipLogger\WordPress\Recorder;

defined( 'ABSPATH' ) || exit;

/**
 * Records THAT a login failed and why (WordPress's error code, e.g. incorrect_password), never WHO
 * tried: the attempted username, the password and the client IP address are not read at all.
 *
 * Identical failures are collapsed while one is still waiting in the queue and the number of
 * suppressed repeats is attached to the next one ("suppressedRepeats"), so a brute-force burst costs
 * one queue slot per delivery cycle instead of thousands.
 */
final class Failed_Logins {

	/**
	 * Recorder.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * Constructor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login_failed', array( $this, 'on_failed' ), 10, 2 );
	}

	/**
	 * Handle wp_login_failed. The first argument (the attempted username) is deliberately ignored.
	 *
	 * @param string          $username Attempted username. Not used, never stored.
	 * @param \WP_Error|mixed $error    Error (WordPress 5.4+).
	 * @return void
	 */
	public function on_failed( $username = '', $error = null ) {
		unset( $username );
		$code = is_wp_error( $error ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $error->get_error_code() ) ) : '';
		$code = '' === $code ? 'unknown' : substr( $code, 0, 64 );

		$channel = 'login';
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$channel = 'xmlrpc';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$channel = 'rest';
		}

		$this->recorder->record(
			'login_failed',
			'warn',
			'Failed login attempt',
			array(
				'template' => 'Failed login attempt',
				'fields'   => array(
					'errorCode' => $code,
					'channel'   => $channel,
				),
				'dedupe'   => true,
				'fp_extra' => $code . '|' . $channel,
				'exempt'   => true,
			)
		);
	}
}
