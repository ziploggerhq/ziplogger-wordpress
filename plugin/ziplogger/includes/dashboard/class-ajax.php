<?php
/**
 * The admin-ajax endpoint behind the dashboard panels.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Administrators only, with a nonce, POST only, and only for the fixed list of panels. There is no
 * "nopriv" variant: a visitor cannot reach it. The response is finished HTML built on the server (escaped
 * there), so the browser needs neither the read key nor any ability to talk to ZipLogger.
 */
final class Ajax {

	const ACTION = 'ziplogger_panel';

	/**
	 * Register the handler (admin requests only).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Answer a panel request.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to see this.', 'ziplogger-error-monitoring-session-replay' ) ), 403 );
		}
		check_ajax_referer( 'ziplogger_panel', 'nonce' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( array( 'message' => __( 'Bad request.', 'ziplogger-error-monitoring-session-replay' ) ), 405 );
		}
		$id      = isset( $_POST['panel'] ) ? sanitize_key( wp_unslash( $_POST['panel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$refresh = ! empty( $_POST['refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- a boolean flag; the nonce was verified above.
		$result  = Panels::render( $id, $refresh );
		if ( $result['ok'] ) {
			wp_send_json_success( array( 'html' => $result['html'] ) );
		}
		wp_send_json_error( array( 'message' => $result['error'] ) );
	}
}
