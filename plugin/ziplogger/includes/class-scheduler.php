<?php
/**
 * WP-Cron scheduling for the delivery worker.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery runs from WP-Cron:
 *
 *  - ziplogger_deliver:  single events, self-chaining. Scheduled when events are queued and after
 *                        each run while anything is still waiting; nothing is scheduled when the
 *                        queue is empty, so an idle site pays nothing.
 *  - ziplogger_watchdog: an hourly safety net that re-arms delivery if the single event was lost
 *                        (a wiped cron option, a migration) and applies retention.
 *
 * WP-Cron is triggered by visits: on a quiet site an event can be overdue until the next request,
 * and it does not run at all when DISABLE_WP_CRON is set unless a system cron calls it. The admin
 * screen shows both situations; docs/CRON.md describes the system-cron setup.
 */
final class Scheduler {

	const HOOK_DELIVER  = 'ziplogger_deliver';
	const HOOK_WATCHDOG = 'ziplogger_watchdog';

	/**
	 * Make sure a delivery run is scheduled no later than $when (Unix time).
	 *
	 * @param int $when Desired time.
	 * @return void
	 */
	public static function ensure_scheduled( $when ) {
		$when = max( (int) $when, Clock::time() );
		$next = wp_next_scheduled( self::HOOK_DELIVER );
		if ( $next && $next <= $when + 60 ) {
			return;
		}
		if ( $next ) {
			// WordPress ignores a new single event within ten minutes of an existing identical one,
			// so an earlier run has to replace the later one explicitly.
			wp_unschedule_event( $next, self::HOOK_DELIVER );
		}
		wp_schedule_single_event( $when, self::HOOK_DELIVER );
	}

	/**
	 * Schedule the hourly watchdog if it is missing.
	 *
	 * @return void
	 */
	public static function ensure_watchdog() {
		if ( ! wp_next_scheduled( self::HOOK_WATCHDOG ) ) {
			wp_schedule_event( Clock::time() + 300, 'hourly', self::HOOK_WATCHDOG );
		}
	}

	/**
	 * Remove every scheduled event (deactivation, uninstall).
	 *
	 * @return void
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK_DELIVER );
		wp_clear_scheduled_hook( self::HOOK_WATCHDOG );
	}

	/**
	 * When the next delivery run is scheduled (Unix), or 0.
	 *
	 * @return int
	 */
	public static function next_delivery() {
		$next = wp_next_scheduled( self::HOOK_DELIVER );
		return $next ? (int) $next : 0;
	}

	/**
	 * Whether WP-Cron is switched off on this site.
	 *
	 * @return bool
	 */
	public static function cron_disabled() {
		return (bool) Settings::constant( 'DISABLE_WP_CRON' );
	}
}
