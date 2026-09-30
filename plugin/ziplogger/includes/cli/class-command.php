<?php
/**
 * WP-CLI commands.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Cli;

use ZipLogger\WordPress\Health;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * Manage ZipLogger delivery.
 *
 * ## EXAMPLES
 *
 *     # Deliver everything that is waiting (use from a system cron on sites with DISABLE_WP_CRON)
 *     wp ziplogger flush
 *
 *     # Show delivery health
 *     wp ziplogger status
 */
class Command extends \WP_CLI_Command {

	/**
	 * Show delivery health.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$h    = Health::snapshot();
		$rows = array(
			array(
				'field' => 'state',
				'value' => $h['state'],
			),
			array(
				'field' => 'collection',
				'value' => $h['enabled'] ? 'on' : 'off',
			),
			array(
				'field' => 'api_key',
				'value' => $h['key_source'],
			),
			array(
				'field' => 'endpoint',
				'value' => $h['endpoint'],
			),
			array(
				'field' => 'pending',
				'value' => $h['pending'],
			),
			array(
				'field' => 'in_retry',
				'value' => $h['in_retry'],
			),
			array(
				'field' => 'dropped_total',
				'value' => $h['dropped_total'],
			),
			array(
				'field' => 'delivered_total',
				'value' => $h['sent_events'],
			),
			array(
				'field' => 'last_success',
				'value' => $h['last_success'] ? gmdate( 'c', $h['last_success'] ) : '',
			),
			array(
				'field' => 'last_error',
				'value' => isset( $h['last_error']['message'] ) ? $h['last_error']['message'] : '',
			),
			array(
				'field' => 'wp_cron_disabled',
				'value' => $h['cron_disabled'] ? 'yes' : 'no',
			),
			array(
				'field' => 'worker_overdue',
				'value' => $h['overdue'] ? 'yes' : 'no',
			),
		);
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			\WP_CLI::print_value( $h, array( 'format' => 'json' ) );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Deliver queued events now, ignoring retry timers.
	 *
	 * Exits with a non-zero status when delivery is blocked or events remain after a failure, so a
	 * system cron or monitoring can notice.
	 *
	 * ## OPTIONS
	 *
	 * [--max-batches=<n>]
	 * : Maximum batches to send in this run.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--max-seconds=<n>]
	 * : Maximum run time in seconds.
	 * ---
	 * default: 300
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function flush( $args, $assoc_args ) {
		unset( $args );
		$worker = new Worker();
		$report = $worker->run(
			array(
				'force'       => true,
				'max_batches' => max( 1, (int) ( isset( $assoc_args['max-batches'] ) ? $assoc_args['max-batches'] : 100 ) ),
				'max_seconds' => max( 5, (int) ( isset( $assoc_args['max-seconds'] ) ? $assoc_args['max-seconds'] : 300 ) ),
			)
		);

		if ( 'disabled' === $report['status'] ) {
			\WP_CLI::error( 'Collection is switched off (Settings > ZipLogger), so nothing was delivered.' );
		}
		if ( 'not_configured' === $report['status'] ) {
			\WP_CLI::error( $report['error'] );
		}
		\WP_CLI::log( sprintf( 'Delivered %d events in %d batches; %d still pending; %d dropped as undeliverable.', $report['events_sent'], $report['batches'], $report['pending'], $report['dropped'] ) );
		if ( '' !== $report['error'] && $report['pending'] > 0 ) {
			\WP_CLI::error( 'Delivery problem: ' . $report['error'] );
		}
		\WP_CLI::success( 0 === $report['pending'] ? 'Queue is empty.' : 'Done; the rest will retry on schedule.' );
	}

	/**
	 * Send one test event and report what ZipLogger answered.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$result = ( new Worker() )->send_test_event();
		if ( $result['ok'] ) {
			\WP_CLI::success( sprintf( 'ZipLogger accepted the test event (HTTP %d).', $result['status'] ) );
			return; // success() does not exit.
		}
		\WP_CLI::error( $result['message'] );
	}

	/**
	 * Delete every queued event.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function clear( $args, $assoc_args ) {
		unset( $args );
		\WP_CLI::confirm( 'Delete every queued event that has not been delivered?', $assoc_args );
		$deleted = ( new Queue_Store() )->clear();
		if ( $deleted > 0 ) {
			( new Meta_Store() )->dropped( 'cleared', $deleted );
		}
		\WP_CLI::success( sprintf( 'Cleared %d queued events.', $deleted ) );
	}
}
