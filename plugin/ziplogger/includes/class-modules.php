<?php
/**
 * Which modules are actually active, and why not when they are switched on but blocked.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * "Enabled" is the administrator's switch; "effective" is what the plugin really does. A module is
 * effective only when its prerequisites hold (a suitable credential, WooCommerce present ...). The
 * admin screen, the front-end configuration and the collectors all ask this class, so the interface
 * can never claim a module is running when it is not - or run one it does not show as on.
 */
final class Modules {

	const ALL = array( 'logs', 'browser', 'analytics', 'replay', 'tracing', 'woocommerce' );

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Whether a module is switched on by the administrator.
	 *
	 * @param string $module Module.
	 * @return bool
	 */
	public static function enabled( $module ) {
		return 'logs' === $module ? Settings::is_enabled() : Settings::module_enabled( $module );
	}

	/**
	 * Reasons a module cannot run right now (empty when it can, or when it is off).
	 *
	 * @param string $module Module.
	 * @return string[]
	 */
	public static function blockers( $module ) {
		$reasons = array();
		$server  = '' !== Settings::api_key();
		$browser = '' !== Settings::browser_key();

		switch ( $module ) {
			case 'logs':
				if ( ! $server ) {
					$reasons[] = __( 'No server API key is set.', 'ziplogger-error-monitoring-session-replay' );
				}
				break;
			case 'browser':
			case 'analytics':
			case 'replay':
				if ( ! $browser ) {
					$problem   = Settings::key_problem( 'browser' );
					$reasons[] = '' !== $problem ? $problem : __( 'No browser API key is set (Connection tab).', 'ziplogger-error-monitoring-session-replay' );
				}
				if ( '' !== Settings::endpoint_problem() ) {
					$reasons[] = Settings::endpoint_problem();
				}
				break;
			case 'tracing':
				if ( ! $server ) {
					$reasons[] = __( 'No server API key is set.', 'ziplogger-error-monitoring-session-replay' );
				}
				break;
			case 'woocommerce':
				if ( ! self::woocommerce_active() ) {
					$reasons[] = __( 'WooCommerce is not active on this site.', 'ziplogger-error-monitoring-session-replay' );
				}
				if ( ! $server ) {
					$reasons[] = __( 'No server API key is set (order and payment events are sent from the server).', 'ziplogger-error-monitoring-session-replay' );
				}
				break;
		}
		return $reasons;
	}

	/**
	 * Whether a module is running: switched on and nothing blocks it.
	 *
	 * @param string $module Module.
	 * @return bool
	 */
	public static function effective( $module ) {
		return self::enabled( $module ) && array() === self::blockers( $module );
	}

	/**
	 * Whether any browser-side module needs the front-end script on the page.
	 *
	 * @return bool
	 */
	public static function frontend_needed() {
		if ( self::effective( 'browser' ) || self::effective( 'analytics' ) || self::effective( 'replay' ) ) {
			return true;
		}
		$s = Settings::get();
		return self::effective( 'tracing' ) && ! empty( $s['tracing']['browser'] ) && '' !== Settings::browser_key();
	}

	/**
	 * A summary of every module for the overview: state key, and blockers.
	 *
	 * @return array<string,array{state:string,blockers:string[]}>
	 */
	public static function summary() {
		$out = array();
		foreach ( self::ALL as $module ) {
			$blockers = self::blockers( $module );
			if ( ! self::enabled( $module ) ) {
				$state = 'off';
			} elseif ( $blockers ) {
				$state = 'blocked';
			} else {
				$state = 'on';
			}
			$out[ $module ] = array(
				'state'    => $state,
				'blockers' => $blockers,
			);
		}
		return $out;
	}
}
