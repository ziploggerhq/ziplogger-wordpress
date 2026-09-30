<?php
/**
 * Pieces shared by several tabs.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Signal;

defined( 'ABSPATH' ) || exit;

/**
 * Status card, per-signal delivery table, held-data panel and the link to the ZipLogger application.
 */
final class Common {

	/**
	 * Overall status card. State is conveyed by text and icon, not by colour alone.
	 *
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function status_card( array $h ) {
		$labels                        = array(
			'off'          => array( 'dashicons-controls-pause', __( 'Server collection is off', 'ziplogger' ), __( 'Nothing from your server is being collected or sent. Add a server API key, then switch server collection on (Server logs tab). Browser and other modules have their own switches.', 'ziplogger' ) ),
			'unconfigured' => array( 'dashicons-warning', __( 'Needs a server API key', 'ziplogger' ), __( 'Server collection is on, but events cannot be delivered until a server API key is set. They wait in the local queue.', 'ziplogger' ) ),
			'ready'        => array( 'dashicons-yes-alt', __( 'Ready', 'ziplogger' ), __( 'Server collection is on. Use "Send test event" to confirm ZipLogger accepts events from this site.', 'ziplogger' ) ),
			'healthy'      => array( 'dashicons-yes-alt', __( 'Delivering normally', 'ziplogger' ), __( 'Events are reaching ZipLogger.', 'ziplogger' ) ),
			'waiting'      => array( 'dashicons-clock', __( 'Events are waiting to be sent', 'ziplogger' ), __( 'Delivery runs in the background shortly after events are queued.', 'ziplogger' ) ),
			'failing'      => array( 'dashicons-warning', __( 'Delivery is failing - retrying', 'ziplogger' ), __( 'The last attempt failed. Events are kept and retried with increasing delays.', 'ziplogger' ) ),
			'blocked'      => array( 'dashicons-dismiss', __( 'Delivery is paused', 'ziplogger' ), __( 'ZipLogger did not accept this site\'s credentials or endpoint. Events are kept while you fix it; saving the connection settings or sending a test event retries at once.', 'ziplogger' ) ),
			'held'         => array( 'dashicons-warning', __( 'Queued data is on hold', 'ziplogger' ), __( 'The API key or endpoint changed while events were waiting. They will not be sent to the new destination until you decide (Connection tab).', 'ziplogger' ) ),
			'overdue'      => array( 'dashicons-clock', __( 'The delivery worker is overdue', 'ziplogger' ), __( 'Events are due to be sent but the background job has not run. See the worker status below.', 'ziplogger' ) ),
			'broken'       => array( 'dashicons-dismiss', __( 'Database tables are missing', 'ziplogger' ), __( 'Deactivate and reactivate the plugin to recreate them.', 'ziplogger' ) ),
		);
		$state                         = isset( $labels[ $h['state'] ] ) ? $h['state'] : 'healthy';
		list( $icon, $title, $detail ) = $labels[ $state ];

		printf(
			'<div class="ziplogger-status ziplogger-status--%1$s" role="status"><span class="dashicons %2$s" aria-hidden="true"></span><div><strong>%3$s</strong><p>%4$s</p></div></div>',
			esc_attr( $state ),
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $detail )
		);
	}

	/**
	 * Per-signal delivery table.
	 *
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function delivery_table( array $h ) {
		$reason_labels = self::drop_labels();
		echo '<div class="ziplogger-scroll" role="region" tabindex="0" aria-label="' . esc_attr__( 'Delivery by signal', 'ziplogger' ) . '">';
		echo '<table class="widefat striped ziplogger-health"><caption class="screen-reader-text">' . esc_html__( 'Delivery by signal', 'ziplogger' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Signal', 'ziplogger' ), __( 'Waiting', 'ziplogger' ), __( 'Delivered', 'ziplogger' ), __( 'Last success', 'ziplogger' ), __( 'Dropped', 'ziplogger' ), __( 'Last error', 'ziplogger' ) ) as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Signal::ALL as $signal ) {
			$s       = $h['signals'][ $signal ];
			$dropped = array();
			foreach ( $s['drops'] as $reason => $n ) {
				if ( $n > 0 && isset( $reason_labels[ $reason ] ) ) {
					$dropped[] = $reason_labels[ $reason ] . ': ' . number_format_i18n( $n );
				}
			}
			$error = '';
			if ( ! empty( $s['last_error']['message'] ) ) {
				$e     = $s['last_error'];
				$error = ( isset( $e['at'] ) ? Settings_Page::ago( (int) $e['at'], $h['now'] ) . ': ' : '' ) . ( ! empty( $e['status'] ) ? 'HTTP ' . (int) $e['status'] . '. ' : '' ) . (string) $e['message'];
			}
			echo '<tr>';
			echo '<th scope="row">' . esc_html( Signal::label( $signal ) ) . '</th>';
			echo '<td>' . esc_html( number_format_i18n( $s['pending'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $s['sent_events'] ) ) . ( $s['server_rejected'] > 0 ? ' <span class="description">' . esc_html( sprintf( /* translators: %s: number */ __( '(%s refused by ZipLogger validation)', 'ziplogger' ), number_format_i18n( $s['server_rejected'] ) ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . ( $s['last_success'] ? esc_html( Settings_Page::ago( $s['last_success'], $h['now'] ) ) : esc_html__( 'None yet', 'ziplogger' ) ) . '</td>';
			echo '<td>' . esc_html( $dropped ? implode( '; ', $dropped ) : '0' ) . '</td>';
			echo '<td>' . ( '' !== $error ? esc_html( $error ) : esc_html__( 'None', 'ziplogger' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The worker / WP-Cron sentence for the current state.
	 *
	 * @param array $h Health snapshot.
	 * @return string
	 */
	public static function worker_sentence( array $h ) {
		if ( $h['cron_disabled'] ) {
			return __( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Delivery only happens if a system cron runs "wp cron event run --due-now" or "wp ziplogger flush".', 'ziplogger' );
		}
		if ( $h['overdue'] ) {
			return sprintf(
				/* translators: %s: how late */
				__( 'Overdue by %s. WP-Cron only runs when someone visits the site, so a quiet site can fall behind. A system cron fixes this - see the plugin documentation.', 'ziplogger' ),
				human_time_diff( $h['now'] - $h['overdue_by'], $h['now'] )
			);
		}
		$gate = 0;
		foreach ( $h['signals'] as $s ) {
			$gate = max( $gate, $s['gate_until'] );
		}
		if ( $gate > $h['now'] ) {
			/* translators: %s: how long from now */
			return sprintf( __( 'Waiting before the next attempt (about %s).', 'ziplogger' ), human_time_diff( $h['now'], $gate ) );
		}
		if ( $h['next_run'] ) {
			/* translators: %s: how long from now */
			return $h['next_run'] > $h['now'] ? sprintf( __( 'Next run scheduled in about %s.', 'ziplogger' ), human_time_diff( $h['now'], $h['next_run'] ) ) : __( 'A run is due and will start on the next site visit.', 'ziplogger' );
		}
		return __( 'Idle. A run is scheduled automatically when items are queued.', 'ziplogger' );
	}

	/**
	 * Labels for drop reasons.
	 *
	 * @return array<string,string>
	 */
	public static function drop_labels() {
		return array(
			'overflow'    => __( 'Queue was full', 'ziplogger' ),
			'request_cap' => __( 'Per-request limit reached', 'ziplogger' ),
			'oversize'    => __( 'Too large', 'ziplogger' ),
			'unencodable' => __( 'Could not be encoded', 'ziplogger' ),
			'expired'     => __( 'Older than the retention window', 'ziplogger' ),
			'poison'      => __( 'Repeatedly failed delivery', 'ziplogger' ),
			'rejected'    => __( 'Rejected by ZipLogger', 'ziplogger' ),
			'cleared'     => __( 'Cleared by an administrator', 'ziplogger' ),
			'storage'     => __( 'Local database write failed', 'ziplogger' ),
		);
	}

	/**
	 * The panel offering to send or discard data held for another destination.
	 *
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function held_panel( array $h ) {
		if ( $h['held'] < 1 ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( sprintf( /* translators: %s: number of queued items. */ _n( '%s queued item is on hold.', '%s queued items are on hold.', $h['held'], 'ziplogger' ), number_format_i18n( $h['held'] ) ) ) . '</strong> ';
		echo esc_html__( 'They were collected for the previous API key or endpoint. A different key can belong to a different workspace, so they are not sent automatically. If the new key belongs to the same workspace (for example you rotated the key), send them; otherwise discard them. They expire on their own after the retention window.', 'ziplogger' ) . '</p>';
		echo '<div class="ziplogger-actions">';
		Settings_Page::action_form( 'ziplogger_held', __( 'Send to the current destination', 'ziplogger' ), 'secondary', 'connection', null, array( 'choice' => 'retarget' ) );
		Settings_Page::action_form( 'ziplogger_held', __( 'Discard held items', 'ziplogger' ), 'delete', 'connection', __( 'Discard the held items? They cannot be recovered.', 'ziplogger' ), array( 'choice' => 'discard' ) );
		echo '</div></div>';
	}

	/**
	 * "Open ZipLogger" link to the application.
	 *
	 * @param array  $h      Health snapshot.
	 * @param string $source Site label.
	 * @return void
	 */
	public static function open_link( array $h, $source ) {
		$app = Endpoint::app_url( '' !== $h['endpoint'] ? $h['endpoint'] : Endpoint::DEFAULT_BASE );
		echo '<p class="ziplogger-open"><a class="button button-primary" href="' . esc_url( $app ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open ZipLogger', 'ziplogger' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ziplogger' ) . '</span></a> ';
		printf(
			/* translators: %s: the site label, shown in code style. */
			esc_html__( 'Then search for events with source %s.', 'ziplogger' ),
			'<code>' . esc_html( $source ) . '</code>'
		);
		echo '</p>';
	}

	/**
	 * The three standard action buttons.
	 *
	 * @param string $tab Tab to return to.
	 * @return void
	 */
	public static function actions( $tab ) {
		echo '<div class="ziplogger-actions">';
		Settings_Page::action_form( 'ziplogger_test', __( 'Send test event', 'ziplogger' ), 'secondary', $tab );
		Settings_Page::action_form( 'ziplogger_flush', __( 'Deliver queued items now', 'ziplogger' ), 'secondary', $tab );
		Settings_Page::action_form( 'ziplogger_clear', __( 'Clear queue', 'ziplogger' ), 'delete', $tab, __( 'Delete every queued item that has not been delivered yet? This cannot be undone.', 'ziplogger' ) );
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'These actions use the saved settings, so save any changes first. "Send test event" works even while collection is off.', 'ziplogger' ) . '</p>';
	}

	/**
	 * Explanatory footnote about delivery guarantees.
	 *
	 * @return void
	 */
	public static function guarantee_note() {
		echo '<p class="description">' . esc_html__( 'Delivery is at-least-once, not exactly-once: a batch retried after a timeout carries the same idempotency key so ZipLogger can recognise it, but a rare duplicate or loss is possible when the site or server dies at the wrong moment. "Accepted" means ZipLogger received the event; it can take a short while to be searchable.', 'ziplogger' ) . '</p>';
	}

	/**
	 * Read the last-error state helper for a signal (used by diagnostics).
	 *
	 * @param string $signal Signal.
	 * @return array
	 */
	public static function last_error( $signal ) {
		$meta = new Meta_Store();
		return $meta->get_json( $meta->sk( Meta_Store::LAST_ERROR, $signal ) );
	}
}
