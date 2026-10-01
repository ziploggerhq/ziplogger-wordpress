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
 * Multisite: the plugin keeps settings, a queue and a cron schedule per site, so it can be activated on one
 * site or for the whole network. Activating for the network sets up the sites that exist (the first
 * NETWORK_BATCH of them, so that a very large network cannot make the request time out), sites created later
 * are set up when they are created, and any other site is set up on its first request.
 */
final class Lifecycle {

	/**
	 * How many sites one network-wide activation or deactivation visits.
	 */
	const NETWORK_BATCH = 200;

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether this is a network-wide activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::each_site( array( __CLASS__, 'set_up_current_site' ) );
			return;
		}
		self::set_up_current_site();
	}

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Whether this is a network-wide deactivation.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			self::each_site( array( Scheduler::class, 'clear' ) );
			return;
		}
		Scheduler::clear();
	}

	/**
	 * The tables and the hourly maintenance event of the current site.
	 *
	 * @return void
	 */
	public static function set_up_current_site() {
		Schema::install();
		Scheduler::ensure_watchdog();
	}

	/**
	 * A site was created: when the plugin is active for the whole network, set the new site up.
	 *
	 * @param \WP_Site|mixed $site The new site.
	 * @return void
	 */
	public static function new_site( $site ) {
		if ( ! $site instanceof \WP_Site || ! self::network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::set_up_current_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Whether the plugin is active for the whole network.
	 *
	 * @return bool
	 */
	public static function network_active() {
		$plugins = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
		return is_array( $plugins ) && isset( $plugins[ plugin_basename( ZIPLOGGER_FILE ) ] );
	}

	/**
	 * Run a callback on each site of the current network (the first NETWORK_BATCH of them).
	 *
	 * @param callable $callback Called with the site as the current one.
	 * @return void
	 */
	private static function each_site( $callback ) {
		$ids = get_sites(
			array(
				'fields'     => 'ids',
				'number'     => self::NETWORK_BATCH,
				'orderby'    => 'id',
				'network_id' => get_current_network_id(),
			)
		);
		foreach ( $ids as $id ) {
			switch_to_blog( (int) $id );
			try {
				call_user_func( $callback );
			} finally {
				restore_current_blog();
			}
		}
	}
}
