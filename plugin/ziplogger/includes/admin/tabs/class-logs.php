<?php
/**
 * Server logs tab.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Fields;
use ZipLogger\WordPress\Admin\Settings_Page;
use ZipLogger\WordPress\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * PHP errors and selected WordPress events, collected on the server and delivered in the background.
 */
final class Logs {

	/**
	 * Render.
	 *
	 * @param array $s Settings.
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function render( array $s, array $h ) {
		echo '<h2>' . esc_html__( 'Server logs', 'ziplogger' ) . '</h2>';
		echo '<p>' . esc_html__( 'Captures PHP warnings, uncaught exceptions and fatal errors without changing how PHP or WordPress report them, plus optional operational events. Events are redacted, stored in a small local queue and delivered in the background; your pages never wait for ZipLogger.', 'ziplogger' ) . '</p>';
		echo '<dl class="ziplogger-effects">';
		echo '<dt>' . esc_html__( 'Data', 'ziplogger' ) . '</dt><dd>' . esc_html__( 'Message, severity, time, the site\'s host name, WordPress and PHP versions, file paths relative to WordPress and a stack trace without function arguments. Never: passwords, keys, cookies, tokens, request or response bodies, query strings, payment details, email addresses, usernames or IP addresses. Redaction reduces exposure but cannot guarantee that a secret typed into an arbitrary message is caught.', 'ziplogger' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Traffic', 'ziplogger' ) . '</dt><dd>' . esc_html__( 'One background job sends batches over HTTPS. Nothing is sent from the request that produced the event.', 'ziplogger' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Storage', 'ziplogger' ) . '</dt><dd>' . esc_html__( 'A bounded queue in your database (events older than 48 hours, or beyond the queue\'s capacity, are dropped and counted).', 'ziplogger' ) . '</dd>';
		echo '</dl>';

		$severity_labels = array(
			'debug' => __( 'Debug - everything, including deprecations', 'ziplogger' ),
			'info'  => __( 'Info - also notices', 'ziplogger' ),
			'warn'  => __( 'Warning (recommended) - warnings and worse', 'ziplogger' ),
			'error' => __( 'Error - errors and fatals only', 'ziplogger' ),
			'fatal' => __( 'Fatal - only fatal errors', 'ziplogger' ),
		);
		$collectors      = array(
			'php_errors'    => array( __( 'PHP errors', 'ziplogger' ), __( 'Warnings, notices (per the minimum severity), uncaught exceptions and fatal errors.', 'ziplogger' ) ),
			'plugin_theme'  => array( __( 'Plugin and theme changes', 'ziplogger' ), __( 'A plugin was activated or deactivated, or the active theme changed. Low volume.', 'ziplogger' ) ),
			'updates'       => array( __( 'Update outcomes', 'ziplogger' ), __( 'Core, plugin, theme and translation updates that finished, and install-stage failures. Low volume.', 'ziplogger' ) ),
			'failed_logins' => array( __( 'Failed logins', 'ziplogger' ), __( 'That a login failed and WordPress\'s reason code. Never the username, password or IP address. Repeats are collapsed. Can be noisy during an attack, so it is off by default.', 'ziplogger' ) ),
			'http_failures' => array( __( 'Outbound HTTP failures', 'ziplogger' ), __( 'wp_remote_*() requests that fail or return a 5xx status. Only the host name, method and status are kept. Off by default.', 'ziplogger' ) ),
			'http_slow'     => array( __( 'Slow outbound HTTP requests', 'ziplogger' ), __( 'wp_remote_*() requests slower than the threshold below. Off by default.', 'ziplogger' ) ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ziplogger-form">';
		Fields::form_header( 'logs', 'logs' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Server collection', 'ziplogger' ) . '</th><td><label for="ziplogger-enabled"><input type="checkbox" id="ziplogger-enabled" name="enabled" value="1" ' . checked( $s['enabled'], true, false ) . ' /> ' . esc_html__( 'Collect events and send them to ZipLogger', 'ziplogger' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="ziplogger-min-severity">' . esc_html__( 'Minimum severity', 'ziplogger' ) . '</label></th><td>';
		echo '<select id="ziplogger-min-severity" name="min_severity" aria-describedby="ziplogger-severity-help">';
		foreach ( Severity::RANKS as $sev => $rank ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $sev ), selected( $s['min_severity'], $sev, false ), esc_html( $severity_labels[ $sev ] ) );
		}
		echo '</select><p class="description" id="ziplogger-severity-help">' . esc_html__( 'Applies to PHP errors and to events logged by code through ziplogger_log(). The operational events below have their own switches and are not affected.', 'ziplogger' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Collectors', 'ziplogger' ) . '</th><td><fieldset><legend class="screen-reader-text"><span>' . esc_html__( 'Collectors', 'ziplogger' ) . '</span></legend>';
		foreach ( $collectors as $key => $info ) {
			printf(
				'<p><label for="ziplogger-c-%1$s"><input type="checkbox" id="ziplogger-c-%1$s" name="collectors[%1$s]" value="1" %2$s aria-describedby="ziplogger-c-%1$s-help" /> <strong>%3$s</strong></label><br /><span class="description" id="ziplogger-c-%1$s-help">%4$s</span></p>',
				esc_attr( $key ),
				checked( ! empty( $s['collectors'][ $key ] ), true, false ),
				esc_html( $info[0] ),
				esc_html( $info[1] )
			);
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="ziplogger-slow">' . esc_html__( 'Slow request threshold', 'ziplogger' ) . '</label></th><td>';
		printf( '<input type="number" id="ziplogger-slow" name="slow_http_seconds" value="%d" min="1" max="60" step="1" class="small-text" /> ', (int) $s['slow_http_seconds'] );
		echo esc_html__( 'seconds', 'ziplogger' ) . '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save server logs settings', 'ziplogger' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Live from ZipLogger', 'ziplogger' ) . '</h2>';
		\ZipLogger\WordPress\Dashboard\Panels::slot( 'errors', __( 'Recent server errors', 'ziplogger' ) );
		\ZipLogger\WordPress\Dashboard\Panels::slot( 'error_trend', __( 'Server errors, last 24 hours', 'ziplogger' ) );

		echo '<h2>' . esc_html__( 'Delivery health', 'ziplogger' ) . '</h2>';
		Common::delivery_table( $h );
		echo '<table class="widefat striped ziplogger-health" role="presentation"><tbody>';
		Settings_Page::row( __( 'Repeats collapsed', 'ziplogger' ), number_format_i18n( $h['suppressed'] ) );
		Settings_Page::row( __( 'Worker (WP-Cron)', 'ziplogger' ), Common::worker_sentence( $h ) );
		if ( ! empty( $h['last_test']['at'] ) ) {
			Settings_Page::row( __( 'Last test event', 'ziplogger' ), Settings_Page::ago( (int) $h['last_test']['at'], $h['now'] ) . ' - ' . ( ! empty( $h['last_test']['ok'] ) ? __( 'accepted', 'ziplogger' ) : __( 'not delivered', 'ziplogger' ) ) );
		}
		echo '</tbody></table>';
		Common::guarantee_note();

		echo '<h2>' . esc_html__( 'Actions', 'ziplogger' ) . '</h2>';
		Common::actions( 'logs' );
		Common::open_link( $h, $s['source'] );
	}
}
