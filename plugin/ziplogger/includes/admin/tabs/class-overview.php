<?php
/**
 * Overview tab.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Where a site administrator lands: is anything wrong, what is on, what is being sent.
 */
final class Overview {

	/**
	 * Render.
	 *
	 * @param array $s Settings.
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function render( array $s, array $h ) {
		Common::status_card( $h );
		Common::held_panel( $h );

		echo '<h2>' . esc_html__( 'Live from ZipLogger', 'ziplogger-error-monitoring-session-replay' ) . '</h2>';
		\ZipLogger\WordPress\Dashboard\Panels::slot( 'errors', __( 'Recent server errors', 'ziplogger-error-monitoring-session-replay' ) );
		\ZipLogger\WordPress\Dashboard\Panels::slot( 'error_trend', __( 'Server errors, last 24 hours', 'ziplogger-error-monitoring-session-replay' ) );

		echo '<h2>' . esc_html__( 'Modules', 'ziplogger-error-monitoring-session-replay' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Nothing is switched on automatically. Each module has its own tab, its own switch and its own sampling.', 'ziplogger-error-monitoring-session-replay' ) . '</p>';
		echo '<table class="widefat striped ziplogger-modules"><caption class="screen-reader-text">' . esc_html__( 'Modules and their state', 'ziplogger-error-monitoring-session-replay' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Module', 'ziplogger-error-monitoring-session-replay' ), __( 'State', 'ziplogger-error-monitoring-session-replay' ), __( 'What it sends', 'ziplogger-error-monitoring-session-replay' ) ) as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$tabs    = Settings_Page::tabs();
		$summary = Modules::summary();
		$what    = array(
			'logs'        => __( 'PHP errors and selected WordPress events, from the server', 'ziplogger-error-monitoring-session-replay' ),
			'browser'     => __( 'JavaScript errors, failing requests and page performance, from the browser', 'ziplogger-error-monitoring-session-replay' ),
			'analytics'   => __( 'Page views, chosen interactions and custom events, from the browser', 'ziplogger-error-monitoring-session-replay' ),
			'replay'      => __( 'Masked recordings of sampled sessions, from the browser', 'ziplogger-error-monitoring-session-replay' ),
			'tracing'     => __( 'Request and outbound-call traces, from the server (and optionally the browser)', 'ziplogger-error-monitoring-session-replay' ),
			'woocommerce' => __( 'Shopping journey, orders, payments and refunds', 'ziplogger-error-monitoring-session-replay' ),
		);
		$tab_for = array(
			'logs' => 'logs',
		);
		foreach ( Modules::ALL as $module ) {
			$slug  = isset( $tab_for[ $module ] ) ? $tab_for[ $module ] : $module;
			$state = $summary[ $module ]['state'];
			if ( 'on' === $state ) {
				$state_text = __( 'On', 'ziplogger-error-monitoring-session-replay' );
				$icon       = 'dashicons-yes-alt';
			} elseif ( 'blocked' === $state ) {
				$state_text = __( 'On, but not running', 'ziplogger-error-monitoring-session-replay' );
				$icon       = 'dashicons-warning';
			} else {
				$state_text = __( 'Off', 'ziplogger-error-monitoring-session-replay' );
				$icon       = 'dashicons-minus';
			}
			echo '<tr><th scope="row"><a href="' . esc_url( Settings_Page::url( $slug ) ) . '">' . esc_html( 'logs' === $module ? $tabs['logs'] : $tabs[ $module ] ) . '</a></th>';
			echo '<td><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ' . esc_html( $state_text );
			if ( $summary[ $module ]['blockers'] && 'off' !== $state ) {
				echo '<br /><span class="description">' . esc_html( implode( ' ', $summary[ $module ]['blockers'] ) ) . '</span>';
			}
			echo '</td><td>' . esc_html( $what[ $module ] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Server delivery', 'ziplogger-error-monitoring-session-replay' ) . '</h2>';
		Common::delivery_table( $h );
		echo '<table class="widefat striped ziplogger-health" role="presentation"><tbody>';
		Settings_Page::row( __( 'Worker (WP-Cron)', 'ziplogger-error-monitoring-session-replay' ), Common::worker_sentence( $h ) );
		if ( $h['worker_last_run'] ) {
			Settings_Page::row( __( 'Worker last ran', 'ziplogger-error-monitoring-session-replay' ), Settings_Page::ago( $h['worker_last_run'], $h['now'] ) );
		}
		if ( $h['oldest'] ) {
			Settings_Page::row( __( 'Oldest waiting item', 'ziplogger-error-monitoring-session-replay' ), Settings_Page::ago( $h['oldest'], $h['now'] ) );
		}
		echo '</tbody></table>';
		Common::guarantee_note();

		echo '<h2>' . esc_html__( 'Actions', 'ziplogger-error-monitoring-session-replay' ) . '</h2>';
		Common::actions( 'overview' );
		Common::open_link( $h, $s['source'] );
	}
}
