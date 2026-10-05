<?php
/**
 * Diagnostics tab.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Health;
use ZipLogger\WordPress\Limits;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Queue_Store;
use ZipLogger\WordPress\Redactor;
use ZipLogger\WordPress\Settings;
use ZipLogger\WordPress\Signal;

defined( 'ABSPATH' ) || exit;

/**
 * Local facts an administrator or support person needs, none of them secret: versions, environment,
 * module states, credential isolation, queue contents by signal, and page-cache hints.
 */
final class Diagnostics {

	/**
	 * Render.
	 *
	 * @param array $s Settings.
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function render( array $s, array $h ) {
		unset( $s );
		$r = self::report( $h );

		echo '<h2>' . esc_html__( 'Diagnostics', 'ziplogger-error-monitoring-session-replay' ) . '</h2>';
		echo '<p>' . esc_html__( 'Everything here is local to your site. The downloadable report contains no API keys or personal data.', 'ziplogger-error-monitoring-session-replay' ) . '</p>';

		echo '<table class="widefat striped ziplogger-health" role="presentation"><tbody>';
		Settings_Page::row( __( 'Plugin version', 'ziplogger-error-monitoring-session-replay' ), $r['plugin']['version'] );
		Settings_Page::row( __( 'WordPress', 'ziplogger-error-monitoring-session-replay' ), $r['wordpress']['version'] . ' (' . $r['wordpress']['environment'] . ')' . ( $r['wordpress']['multisite'] ? ' - multisite' : '' ) );
		Settings_Page::row( __( 'PHP', 'ziplogger-error-monitoring-session-replay' ), $r['php']['version'] );
		Settings_Page::row( __( 'HTTPS site', 'ziplogger-error-monitoring-session-replay' ), $r['wordpress']['https'] ? __( 'Yes', 'ziplogger-error-monitoring-session-replay' ) : __( 'No - browser monitoring works on HTTP, but keys travel unencrypted from visitors\' browsers', 'ziplogger-error-monitoring-session-replay' ) );
		Settings_Page::row( __( 'WP-Cron', 'ziplogger-error-monitoring-session-replay' ), $r['wordpress']['wp_cron_disabled'] ? __( 'Disabled (a system cron must run "wp cron event run --due-now")', 'ziplogger-error-monitoring-session-replay' ) : __( 'Enabled (runs when the site gets visits)', 'ziplogger-error-monitoring-session-replay' ) );
		Settings_Page::row( __( 'Persistent object cache', 'ziplogger-error-monitoring-session-replay' ), $r['wordpress']['object_cache'] ? __( 'Yes', 'ziplogger-error-monitoring-session-replay' ) : __( 'No', 'ziplogger-error-monitoring-session-replay' ) );
		Settings_Page::row(
			__( 'Page cache', 'ziplogger-error-monitoring-session-replay' ),
			$r['page_cache']['detected']
				? sprintf( /* translators: %s: cache plugin names */ __( 'Detected (%s). ZipLogger never writes visitor- or request-specific identifiers into pages, so cached pages are safe; requests answered from the cache do not run PHP and produce no server spans.', 'ziplogger-error-monitoring-session-replay' ), implode( ', ', $r['page_cache']['signals'] ) )
				: __( 'None detected. If you use a caching service in front of WordPress, requests it answers do not reach PHP and produce no server spans.', 'ziplogger-error-monitoring-session-replay' )
		);
		Settings_Page::row( __( 'Consent evidence', 'ziplogger-error-monitoring-session-replay' ), $r['consent']['wp_consent_api'] ? __( 'WP Consent API detected', 'ziplogger-error-monitoring-session-replay' ) : __( 'WP Consent API not installed (use the JavaScript API or the ziplogger_has_consent filter)', 'ziplogger-error-monitoring-session-replay' ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Credential isolation', 'ziplogger-error-monitoring-session-replay' ) . '</h3>';
		echo '<ul class="ziplogger-requirements">';
		foreach ( $r['credentials'] as $c ) {
			echo '<li><span class="dashicons ' . ( $c['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '" aria-hidden="true"></span> ' . esc_html( $c['label'] ) . '</li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Modules', 'ziplogger-error-monitoring-session-replay' ) . '</h3>';
		echo '<table class="widefat striped ziplogger-health"><thead><tr><th scope="col">' . esc_html__( 'Module', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Switched on', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Running', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Blocked by', 'ziplogger-error-monitoring-session-replay' ) . '</th></tr></thead><tbody>';
		foreach ( $r['modules'] as $name => $m ) {
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( $m['enabled'] ? __( 'Yes', 'ziplogger-error-monitoring-session-replay' ) : __( 'No', 'ziplogger-error-monitoring-session-replay' ) ) . '</td><td>' . esc_html( $m['effective'] ? __( 'Yes', 'ziplogger-error-monitoring-session-replay' ) : __( 'No', 'ziplogger-error-monitoring-session-replay' ) ) . '</td><td>' . esc_html( implode( ' ', $m['blockers'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Delivery', 'ziplogger-error-monitoring-session-replay' ) . '</h3>';
		Common::delivery_table( $h );
		Common::held_panel( $h );
		echo '<table class="widefat striped ziplogger-health" role="presentation"><tbody>';
		Settings_Page::row( __( 'Worker (WP-Cron)', 'ziplogger-error-monitoring-session-replay' ), Common::worker_sentence( $h ) );
		Settings_Page::row( __( 'Queue size', 'ziplogger-error-monitoring-session-replay' ), number_format_i18n( $h['pending'] ) . ' / ' . number_format_i18n( (int) Limits::get( 'queue_max_events' ) ) );
		Settings_Page::row( __( 'Items in retry', 'ziplogger-error-monitoring-session-replay' ), number_format_i18n( $h['in_retry'] ) );
		Settings_Page::row( __( 'Leases expired after a crash (recovered automatically)', 'ziplogger-error-monitoring-session-replay' ), number_format_i18n( $h['stale_leases'] ) );
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Report', 'ziplogger-error-monitoring-session-replay' ) . '</h3>';
		Settings_Page::action_form( 'ziplogger_diagnostics', __( 'Download diagnostics (JSON)', 'ziplogger-error-monitoring-session-replay' ), 'secondary', 'diagnostics' );
	}

	/**
	 * A redacted, credential-free report.
	 *
	 * @param array|null $health Health snapshot (computed when omitted).
	 * @return array
	 */
	public static function report( $health = null ) {
		global $wp_version;
		$h       = null === $health ? Health::snapshot() : $health;
		$s       = Settings::get();
		$modules = array();
		foreach ( Modules::ALL as $module ) {
			$modules[ $module ] = array(
				'enabled'   => Modules::enabled( $module ),
				'effective' => Modules::effective( $module ),
				'blockers'  => Modules::blockers( $module ),
			);
		}

		$redactor = new Redactor();
		$queue    = new Queue_Store();
		$signals  = array();
		foreach ( Signal::ALL as $signal ) {
			$sig                = $h['signals'][ $signal ];
			$sig['last_error']  = isset( $sig['last_error']['message'] ) ? $sig['last_error'] : array();
			$signals[ $signal ] = $sig;
		}

		$server  = Settings::api_key();
		$browser = Settings::key( 'browser' );
		$read    = Settings::key( 'read' );
		$creds   = array(
			array(
				'ok'    => '' === $browser || $browser !== $server,
				/* translators: this is a check result line. */
				'label' => __( 'The browser key is different from the server key', 'ziplogger-error-monitoring-session-replay' ),
			),
			array(
				'ok'    => '' === $read || ( $read !== $server && $read !== $browser ),
				'label' => __( 'The read key is different from the server and browser keys', 'ziplogger-error-monitoring-session-replay' ),
			),
			array(
				'ok'    => true,
				'label' => __( 'Only the browser key can be delivered to visitors; the server and read keys are never placed in a page or script', 'ziplogger-error-monitoring-session-replay' ),
			),
		);

		return array(
			'generated'   => gmdate( 'c' ),
			'plugin'      => array(
				'version'    => ZIPLOGGER_VERSION,
				'db_version' => ZIPLOGGER_DB_VERSION,
			),
			'wordpress'   => array(
				'version'          => (string) $wp_version,
				'environment'      => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
				'multisite'        => is_multisite(),
				'https'            => is_ssl(),
				'wp_cron_disabled' => Settings::constant( 'DISABLE_WP_CRON' ) ? true : false,
				'object_cache'     => (bool) wp_using_ext_object_cache(),
				'active_plugins'   => array_map(
					static function ( $p ) {
						return dirname( $p ) === '.' ? basename( $p, '.php' ) : dirname( $p );
					},
					(array) get_option( 'active_plugins', array() )
				),
			),
			'php'         => array(
				'version'      => PHP_VERSION,
				'memory_limit' => (string) ini_get( 'memory_limit' ),
			),
			'woocommerce' => array(
				'active'  => Modules::woocommerce_active(),
				'version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
				'hpos'    => Modules::woocommerce_active() && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			),
			'page_cache'  => self::page_cache(),
			'consent'     => array(
				'wp_consent_api' => function_exists( 'wp_has_consent' ),
				'policy'         => $s['consent'],
			),
			'credentials' => $creds,
			'key_sources' => array(
				'server'  => Settings::key_source( 'server' ),
				'browser' => Settings::key_source( 'browser' ),
				'read'    => Settings::key_source( 'read' ),
			),
			'endpoint'    => array(
				'host'    => Settings::endpoint_host(),
				'problem' => $redactor->diagnostic( Settings::endpoint_problem() ),
			),
			'modules'     => $modules,
			'queue'       => array(
				'by_signal' => $queue->counts_by_signal(),
				'held'      => $h['held'],
				'state'     => $h['state'],
			),
			'signals'     => $signals,
			'settings'    => array(
				'source'       => $s['source'],
				'min_severity' => $s['min_severity'],
				'collectors'   => $s['collectors'],
				'browser'      => $s['browser'],
				'analytics'    => array_diff_key( $s['analytics'], array( 'interaction_selectors' => 1 ) ),
				'replay'       => array_diff_key(
					$s['replay'],
					array(
						'block_selector' => 1,
						'mask_selector'  => 1,
					)
				),
				'tracing'      => $s['tracing'],
				'woocommerce'  => $s['woocommerce'],
			),
		);
	}

	/**
	 * Signs of a page cache in front of PHP.
	 *
	 * @return array{detected:bool,signals:string[]}
	 */
	private static function page_cache() {
		$signals = array();
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			$signals[] = 'WP_CACHE';
		}
		if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$signals[] = 'advanced-cache.php';
		}
		$known = array(
			'wp-rocket'               => 'WP Rocket',
			'w3-total-cache'          => 'W3 Total Cache',
			'wp-super-cache'          => 'WP Super Cache',
			'litespeed-cache'         => 'LiteSpeed Cache',
			'wp-fastest-cache'        => 'WP Fastest Cache',
			'cache-enabler'           => 'Cache Enabler',
			'sg-cachepress'           => 'SiteGround Optimizer',
			'breeze'                  => 'Breeze',
			'hummingbird-performance' => 'Hummingbird',
		);
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$slug = dirname( $plugin );
			if ( isset( $known[ $slug ] ) ) {
				$signals[] = $known[ $slug ];
			}
		}
		return array(
			'detected' => (bool) $signals,
			'signals'  => array_values( array_unique( $signals ) ),
		);
	}
}
