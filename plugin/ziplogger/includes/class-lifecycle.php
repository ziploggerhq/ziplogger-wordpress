<?php
/**
 * Activation and deactivation.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Activation creates the tables and an hourly maintenance event. It does NOT enable collection and
 * does not contact ZipLogger: the administrator has to enter a key and switch collection on.
 *
 * Deactivation removes every scheduled event and stops all collection (the plugin code is simply no
 * longer loaded). Settings and queued events are kept, so reactivating resumes where things stood;
 * removing them is the uninstall policy's job (see uninstall.php).
 *
 * Multisite: the plugin keeps settings and a queue per site, so it is activated per site. Network
 * activation is refused rather than half-supported.
 */
final class Lifecycle {

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'ZipLogger cannot be network-activated: every site needs its own API key, queue and settings. Activate it on each site from that site\'s Plugins screen.', 'ziplogger' ),
				esc_html__( 'Plugin activation error', 'ziplogger' ),
				array(
					'response'  => 200,
					'back_link' => true,
				)
			);
		}
		Schema::install();
		Scheduler::ensure_watchdog();
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::clear();
	}
}
