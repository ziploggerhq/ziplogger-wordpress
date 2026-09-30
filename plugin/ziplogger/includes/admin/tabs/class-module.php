<?php
/**
 * One generic tab for every module form (browser, analytics, replay, tracing, WooCommerce).
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Fields;
use ZipLogger\WordPress\Admin\Module_Specs;
use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Dashboard\Panels;
use ZipLogger\WordPress\Meta_Store;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Each module screen says what it collects, what it does to traffic and to storage, what it needs,
 * and how consent applies to it, then shows its switches.
 */
final class Module {

	/**
	 * Render a module tab.
	 *
	 * @param string $module Module.
	 * @param array  $s      Settings.
	 * @param array  $h      Health snapshot.
	 * @return void
	 */
	public static function render( $module, array $s, array $h ) {
		unset( $h );
		$spec = Module_Specs::get( $module );

		echo '<h2>' . esc_html( $spec['title'] ) . '</h2>';
		echo '<p>' . esc_html( $spec['intro'] ) . '</p>';

		self::requirements( $module, $s );

		echo '<dl class="ziplogger-effects">';
		echo '<dt>' . esc_html__( 'Data collected', 'ziplogger' ) . '</dt><dd>' . esc_html( $spec['data'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Effect on traffic', 'ziplogger' ) . '</dt><dd>' . esc_html( $spec['traffic'] ) . '</dd>';
		echo '<dt>' . esc_html__( 'Effect on storage', 'ziplogger' ) . '</dt><dd>' . esc_html( $spec['storage'] ) . '</dd>';
		$consent = self::consent_sentence( $module );
		if ( '' !== $consent ) {
			echo '<dt>' . esc_html__( 'Consent', 'ziplogger' ) . '</dt><dd>' . esc_html( $consent ) . '</dd>';
		}
		echo '</dl>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ziplogger-form">';
		Fields::form_header( $module, $module );
		Fields::render_module_rows( $module, $spec['fields'], $s[ $module ] );
		/* translators: %s: module name */
		submit_button( sprintf( __( 'Save %s settings', 'ziplogger' ), $spec['title'] ) );
		echo '</form>';

		self::dashboard( $module, $s );
	}

	/**
	 * What this module has been doing: live panels where ZipLogger's read interface can answer, local facts
	 * where it cannot, and links into ZipLogger for the rest.
	 *
	 * @param string $module Module.
	 * @param array  $s      Settings.
	 * @return void
	 */
	private static function dashboard( $module, array $s ) {
		echo '<h2>' . esc_html__( 'Activity', 'ziplogger' ) . '</h2>';
		switch ( $module ) {
			case 'browser':
				Panels::slot( 'browser_errors', __( 'Recent JavaScript errors', 'ziplogger' ) );
				Panels::slot( 'browser_failed', __( 'Failed browser requests, last 24 hours', 'ziplogger' ) );
				Panels::slot( 'vitals', __( 'Core Web Vitals', 'ziplogger' ) );
				break;
			case 'tracing':
				Panels::slot( 'tracing_stats', __( 'Server requests', 'ziplogger' ) );
				Panels::slot( 'traces', __( 'Traces', 'ziplogger' ) );
				break;
			case 'analytics':
				self::local_note( __( 'ZipLogger\'s read interface exposes logs, request metrics and traces; it has no product-analytics query, so numbers for page views and events are not shown here. Open them in ZipLogger.', 'ziplogger' ) );
				self::links( array( array( '/events', __( 'Events', 'ziplogger' ) ), array( '/investigate', __( 'Investigate', 'ziplogger' ) ) ) );
				break;
			case 'replay':
				self::local_note( __( 'Recordings are watched in ZipLogger. Open the list of recordings there; a single recording links from a session or an error that knows its session.', 'ziplogger' ) );
				self::links( array( array( '/replays', __( 'Session replays', 'ziplogger' ) ) ) );
				break;
			case 'woocommerce':
				self::commerce_counters();
				self::links(
					array(
						array( '/events/name/order_created', __( 'Orders created', 'ziplogger' ) ),
						array( '/events/name/payment_completed', __( 'Payments completed', 'ziplogger' ) ),
						array( '/events/name/payment_failed', __( 'Payment failures', 'ziplogger' ) ),
						array( '/events/name/order_refunded', __( 'Refunds', 'ziplogger' ) ),
					)
				);
				break;
		}
		unset( $s );
	}

	/**
	 * A plain explanatory note.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private static function local_note( $text ) {
		echo '<p class="description">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Links into the ZipLogger application.
	 *
	 * @param array $links Each: path, label.
	 * @return void
	 */
	private static function links( array $links ) {
		echo '<ul class="ziplogger-links">';
		foreach ( $links as $link ) {
			echo '<li><a href="' . esc_url( Panels::app( $link[0] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link[1] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'ziplogger' ) . '</span></a></li>';
		}
		echo '</ul>';
	}

	/**
	 * What the shop side has queued for ZipLogger since the plugin started counting: local numbers, so
	 * they need no permission to query anything.
	 *
	 * @return void
	 */
	private static function commerce_counters() {
		$meta   = new Meta_Store();
		$labels = array(
			'order_created'        => __( 'Orders created', 'ziplogger' ),
			'payment_completed'    => __( 'Payments confirmed', 'ziplogger' ),
			'payment_failed'       => __( 'Payment failures', 'ziplogger' ),
			'order_status_changed' => __( 'Status changes', 'ziplogger' ),
			'order_refunded'       => __( 'Refunds', 'ziplogger' ),
		);
		echo '<table class="widefat striped ziplogger-live-table"><caption class="screen-reader-text">' . esc_html__( 'Order events handed to the delivery queue', 'ziplogger' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Event', 'ziplogger' ) . '</th><th scope="col">' . esc_html__( 'Queued for ZipLogger', 'ziplogger' ) . '</th></tr></thead><tbody>';
		foreach ( $labels as $name => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $meta->get_num( 'ev:' . $name ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$last = $meta->get_num( 'ev_last' );
		echo '<p class="description">' . esc_html( $last ? sprintf( /* translators: %s: how long ago. */ __( 'Last order event queued %s.', 'ziplogger' ), \ZipLogger\WordPress\Admin\Settings_Page::ago( $last, \ZipLogger\WordPress\Clock::time() ) ) : __( 'No order event has been queued yet.', 'ziplogger' ) ) . ' ' . esc_html__( 'These are counts of events handed to the local delivery queue on this site; whether ZipLogger has received them is shown under Server delivery on the Overview tab.', 'ziplogger' ) . '</p>';
	}

	/**
	 * What the module needs and whether it has it.
	 *
	 * @param string $module Module.
	 * @param array  $s      Settings.
	 * @return void
	 */
	private static function requirements( $module, array $s ) {
		$items = array();
		if ( in_array( $module, array( 'browser', 'analytics', 'replay' ), true ) ) {
			$items[] = array( '' !== Settings::browser_key(), __( 'A browser API key (Connection tab)', 'ziplogger' ) );
		}
		if ( 'tracing' === $module ) {
			$items[] = array( '' !== Settings::api_key(), __( 'A server API key (Connection tab)', 'ziplogger' ) );
			if ( ! empty( $s['tracing']['browser'] ) ) {
				$items[] = array( '' !== Settings::browser_key(), __( 'A browser API key, for browser-to-server tracing', 'ziplogger' ) );
			}
		}
		if ( 'woocommerce' === $module ) {
			$items[] = array( Modules::woocommerce_active(), __( 'WooCommerce active on this site', 'ziplogger' ) );
			$items[] = array( '' !== Settings::api_key(), __( 'A server API key (Connection tab)', 'ziplogger' ) );
			if ( Modules::woocommerce_active() ) {
				$hpos    = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
				$items[] = array( true, $hpos ? __( 'Orders are stored in WooCommerce\'s high-performance order tables - supported', 'ziplogger' ) : __( 'Orders are stored as WordPress posts - supported', 'ziplogger' ) );
			}
		}
		if ( ! $items ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Requirements', 'ziplogger' ) . '</h3><ul class="ziplogger-requirements">';
		foreach ( $items as $item ) {
			echo '<li><span class="dashicons ' . ( $item[0] ? 'dashicons-yes-alt' : 'dashicons-dismiss' ) . '" aria-hidden="true"></span> ' . esc_html( $item[1] ) . ' <span class="screen-reader-text">' . esc_html( $item[0] ? __( '(met)', 'ziplogger' ) : __( '(missing)', 'ziplogger' ) ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * How the consent policy applies to this module right now.
	 *
	 * @param string $module Module.
	 * @return string
	 */
	private static function consent_sentence( $module ) {
		$category = array(
			'browser'     => 'browser',
			'analytics'   => 'analytics',
			'replay'      => 'replay',
			'woocommerce' => 'commerce',
		);
		if ( ! isset( $category[ $module ] ) ) {
			return 'tracing' === $module ? __( 'Server-side spans carry no visitor identifiers. Browser-to-server tracing follows the "browser" consent policy (Privacy and consent tab).', 'ziplogger' ) : '';
		}
		$policy = Consent::policy( $category[ $module ] );
		if ( 'none' === $policy ) {
			return __( 'Collected without asking, under your current policy. Change this on the Privacy and consent tab.', 'ziplogger' );
		}
		return __( 'Collected only after the visitor has consented, according to the Privacy and consent tab. Before consent nothing is collected or queued, and consent given later does not send earlier activity.', 'ziplogger' );
	}
}
