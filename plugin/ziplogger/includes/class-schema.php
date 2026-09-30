<?php
/**
 * Database schema: creation, versioning and safe upgrades.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Two tables per site (per blog in a multisite network, using that blog's own prefix):
 *
 *  - {prefix}ziplogger_queue: the bounded, persistent event queue.
 *  - {prefix}ziplogger_meta:  counters and delivery state, updated with atomic upserts.
 *
 * The schema version lives in the ziplogger_db_version option. install() is idempotent (dbDelta),
 * so an upgrade is "run install() when the stored version is behind".
 */
final class Schema {

	const VERSION_OPTION = 'ziplogger_db_version';

	/**
	 * Queue table name for the current blog.
	 *
	 * @return string
	 */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'ziplogger_queue';
	}

	/**
	 * Meta table name for the current blog.
	 *
	 * @return string
	 */
	public static function meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'ziplogger_meta';
	}

	/**
	 * Create or upgrade the tables and record the version.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$queue   = self::queue_table();
		$meta    = self::meta_table();

		// Row lifecycle: inserted unbatched (batch_id empty) -> claimed into a batch with a lease ->
		// deleted on acknowledgement, or released for retry (batch_id stays: the batch keeps its
		// identity, so a retry sends the same bytes under the same idempotency key).
		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY, no trailing comma.
		$sql_queue = "CREATE TABLE {$queue} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  available_at datetime NOT NULL,
  severity tinyint(3) unsigned NOT NULL DEFAULT 1,
  fingerprint char(16) NOT NULL DEFAULT '',
  sig varchar(12) NOT NULL DEFAULT 'logs',
  destination char(16) NOT NULL DEFAULT '',
  size int(10) unsigned NOT NULL DEFAULT 0,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  partial tinyint(1) unsigned NOT NULL DEFAULT 0,
  batch_id char(32) NOT NULL DEFAULT '',
  lease_token char(32) NOT NULL DEFAULT '',
  lease_until datetime DEFAULT NULL,
  first_attempt_at datetime DEFAULT NULL,
  payload text NOT NULL,
  PRIMARY KEY  (id),
  KEY batch_id (batch_id),
  KEY claim (sig, batch_id, destination),
  KEY fingerprint (fingerprint),
  KEY created_at (created_at)
) {$charset};";

		$sql_meta = "CREATE TABLE {$meta} (
  k varchar(64) NOT NULL,
  num bigint(20) unsigned NOT NULL DEFAULT 0,
  str text DEFAULT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (k),
  KEY updated_at (updated_at)
) {$charset};";

		dbDelta( $sql_queue );
		dbDelta( $sql_meta );

		update_option( self::VERSION_OPTION, ZIPLOGGER_DB_VERSION, true );
	}

	/**
	 * Run install() when the stored schema version is behind the code (plugin auto-updates do not
	 * fire the activation hook).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $installed < ZIPLOGGER_DB_VERSION ) {
			self::install();
			if ( $installed >= 1 && $installed < 2 ) {
				// Version 2 added per-row destinations. Rows queued by version 1 were collected for whatever
				// destination was configured at the time, which is the current one unless it changed since.
				$destination = Destination::current();
				if ( '' !== $destination ) {
					( new Queue_Store() )->bind_unbound( $destination );
				}
			}
		}
	}

	/**
	 * Whether the tables exist (used by health checks; a missing table must degrade, not fatal).
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::queue_table() ) ) );
		return self::queue_table() === $found;
	}

	/**
	 * Drop both tables (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		// Table names come from $wpdb->prefix plus a constant; they cannot carry user input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::queue_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- drops the plugin's own table (the site prefix plus a constant); no input is involved.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::meta_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- drops the plugin's own table (the site prefix plus a constant); no input is involved.
		delete_option( self::VERSION_OPTION );
	}
}
