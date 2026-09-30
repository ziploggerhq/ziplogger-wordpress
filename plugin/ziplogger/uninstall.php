<?php
/**
 * Uninstall: runs when the plugin is DELETED from the Plugins screen (not on deactivation).
 *
 * Data-retention policy, controlled by the administrator:
 *
 *  - Default: keep everything. Deleting and reinstalling the plugin resumes with the same settings and
 *    any undelivered events. Scheduled events are always removed, because they would call code that
 *    no longer exists.
 *  - If "Also delete the queue, counters and settings" was ticked on the settings screen (option
 *    delete_on_uninstall), that site's tables, options and transients are removed too.
 *
 * On multisite every site is evaluated separately, using that site's own setting.
 *
 * This file must not depend on the rest of the plugin: it is not loaded when this runs.
 *
 * @package ZipLogger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'ziplogger_uninstall_current_site' ) ) {
	/**
	 * Clean up the current site (the current blog, in multisite).
	 *
	 * @return void
	 */
	function ziplogger_uninstall_current_site() {
		global $wpdb;

		wp_clear_scheduled_hook( 'ziplogger_deliver' );
		wp_clear_scheduled_hook( 'ziplogger_watchdog' );

		$settings = get_option( 'ziplogger_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		// Table names are the site's prefix plus a constant: no user input is involved.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ziplogger_queue' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- drops the plugin's own table (the site prefix plus a constant); no input is involved.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ziplogger_meta' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- drops the plugin's own table (the site prefix plus a constant); no input is involved.

		delete_option( 'ziplogger_settings' );
		delete_option( 'ziplogger_api_key' );
		delete_option( 'ziplogger_browser_key' );
		delete_option( 'ziplogger_read_key' );
		delete_option( 'ziplogger_secret' );
		delete_option( 'ziplogger_db_version' );

		// One-shot admin notices, keyed by user id. Deleted through the API so any object cache stays coherent.
		$like  = $wpdb->esc_like( '_transient_ziplogger_notices_' ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the pattern is bound with prepare(); the lookup is of this plugin's own transient names.
		foreach ( (array) $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}
}

if ( is_multisite() ) {
	// Deleting a network plugin runs this once; visit every site in pages so a big network cannot exhaust memory.
	$ziplogger_offset = 0;
	do {
		$ziplogger_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $ziplogger_offset,
			)
		);
		foreach ( $ziplogger_sites as $ziplogger_site_id ) {
			switch_to_blog( (int) $ziplogger_site_id );
			ziplogger_uninstall_current_site();
			restore_current_blog();
		}
		$ziplogger_offset += 100;
		$ziplogger_page    = count( $ziplogger_sites );
	} while ( 100 === $ziplogger_page );
} else {
	ziplogger_uninstall_current_site();
}
