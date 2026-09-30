<?php
/**
 * Privacy and consent tab.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Admin\Tabs;

use ZipLogger\WordPress\Admin\Fields;
use ZipLogger\WordPress\Limits;

defined( 'ABSPATH' ) || exit;

/**
 * States, in plain language, which features use identifiers and which need consent under the
 * configured policy, and lets the administrator set that policy.
 */
final class Privacy {

	/**
	 * Render.
	 *
	 * @param array $s Settings.
	 * @param array $h Health snapshot.
	 * @return void
	 */
	public static function render( array $s, array $h ) {
		unset( $h );
		echo '<h2>' . esc_html__( 'What each feature does with identifiers', 'ziplogger' ) . '</h2>';
		echo '<p>' . esc_html__( 'ZipLogger itself has no consent or Do-Not-Track handling, so this plugin does it. Nothing here is legal advice: check the settings against the rules that apply to your visitors.', 'ziplogger' ) . '</p>';

		$rows = array(
			array( __( 'Server logs', 'ziplogger' ), __( 'None. Server events describe the site, not a visitor.', 'ziplogger' ), __( 'Not consent-gated.', 'ziplogger' ) ),
			array( __( 'Browser monitoring', 'ziplogger' ), __( 'Anonymous: a random id that exists only in memory for one page view. No cookies, no storage.', 'ziplogger' ), __( 'Policy "browser" below (default: no consent needed).', 'ziplogger' ) ),
			array( __( 'Analytics', 'ziplogger' ), __( 'A random anonymous visitor id in localStorage, a random session id in sessionStorage; with "identify", a keyed hash of the WordPress user id.', 'ziplogger' ), __( 'Policy "analytics" below (default: consent required).', 'ziplogger' ) ),
			array( __( 'Session replay', 'ziplogger' ), __( 'The same ids as analytics, plus a masked recording of the page.', 'ziplogger' ), __( 'Policy "replay" below (default: consent required).', 'ziplogger' ) ),
			array( __( 'Tracing', 'ziplogger' ), __( 'Server spans carry no visitor id. Browser-to-server tracing sends the page-view id in a "baggage" header to this site only.', 'ziplogger' ), __( 'Browser part follows policy "browser".', 'ziplogger' ) ),
			array( __( 'WooCommerce', 'ziplogger' ), __( 'Orders use an opaque order reference (a keyed hash). A visitor id is added to an order event only if the visitor had analytics consent at checkout.', 'ziplogger' ), __( 'Policy "commerce" below (default: transactional, no consent needed).', 'ziplogger' ) ),
		);
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Identifiers and consent by feature', 'ziplogger' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Feature', 'ziplogger' ), __( 'Identifiers it creates or uses', 'ziplogger' ), __( 'Consent', 'ziplogger' ) ) as $col ) {
			echo '<th scope="col">' . esc_html( $col ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td><td>' . esc_html( $row[2] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ziplogger-form">';
		Fields::form_header( 'privacy', 'privacy' );

		echo '<h2>' . esc_html__( 'Consent policy', 'ziplogger' ) . '</h2>';
		echo '<p>' . esc_html__( 'For each category choose whether visitors must consent first. With "consent required", nothing is collected, queued or buffered until consent is given, and consent given later does not send earlier activity. When consent is withdrawn, collection and recording stop.', 'ziplogger' ) . '</p>';
		$labels = array(
			'browser'   => __( 'Browser monitoring (errors, failing requests, performance)', 'ziplogger' ),
			'analytics' => __( 'Analytics (page views, interactions, identify)', 'ziplogger' ),
			'replay'    => __( 'Session replay', 'ziplogger' ),
			'commerce'  => __( 'WooCommerce server events', 'ziplogger' ),
		);
		$specs  = array();
		foreach ( $labels as $category => $label ) {
			$specs[] = array(
				'key'     => $category,
				'type'    => 'select',
				'label'   => $label,
				'options' => array(
					'none'     => __( 'No consent needed', 'ziplogger' ),
					'required' => __( 'Consent required', 'ziplogger' ),
				),
			);
		}
		$specs[] = array(
			'key'            => 'wp_consent_api',
			'type'           => 'checkbox',
			'label'          => __( 'WP Consent API', 'ziplogger' ),
			'checkbox_label' => function_exists( 'wp_has_consent' )
				? __( 'Use the WP Consent API (detected) as evidence of consent', 'ziplogger' )
				: __( 'Use the WP Consent API when it is installed (not detected now)', 'ziplogger' ),
			'help'           => __( 'Categories map to statistics-anonymous (browser), statistics (analytics, replay) and functional (commerce). The mapping can be changed with the ziplogger_wp_consent_categories filter.', 'ziplogger' ),
		);
		Fields::render_module_rows( 'consent', $specs, $s['consent'] );

		echo '<h3>' . esc_html__( 'Connecting your consent banner', 'ziplogger' ) . '</h3>';
		echo '<p>' . esc_html__( 'Evidence of consent is read, in this order, from: the ziplogger_has_consent PHP filter, the WP Consent API, and this plugin\'s own cookie. If your banner is not WP Consent API compatible, call the JavaScript API when the visitor chooses:', 'ziplogger' ) . '</p>';
		// Focusable so that a keyboard user can scroll it sideways on a narrow screen.
		echo '<pre class="ziplogger-code" tabindex="0" role="region" aria-label="' . esc_attr__( 'Consent banner integration example', 'ziplogger' ) . '"><code>' . esc_html(
			"// After the visitor accepts (any subset of: browser, analytics, replay, commerce)\n" .
			"ZipLoggerWP.consent.grant(['browser', 'analytics']);\n\n" .
			"// After the visitor withdraws consent\n" .
			"ZipLoggerWP.consent.revoke();            // everything\n" .
			"ZipLoggerWP.consent.revoke(['replay']);  // one category\n\n" .
			"// PHP: let your consent plugin answer server-side questions\n" .
			"add_filter( 'ziplogger_has_consent', function ( \$consent, \$category ) {\n" .
			"\treturn my_cmp_allows( \$category ) ? true : false;\n" .
			'} , 10, 2 );'
		) . '</code></pre>';
		echo '<p class="description">' . esc_html__( 'Withdrawing consent stops collection and recording at once and blocks uploads of unsent replay data. Events the browser had already handed to its sending queue a moment earlier may still be delivered. Data already sent to ZipLogger is not deleted by withdrawing consent - delete it in ZipLogger.', 'ziplogger' ) . '</p>';

		echo '<h2>' . esc_html__( 'Retention', 'ziplogger' ) . '</h2>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %d: hours. */
				__( 'Server events wait in the local queue for at most %d hours; older ones are dropped and counted.', 'ziplogger' ),
				(int) ( Limits::get( 'retention_seconds' ) / 3600 )
			)
		) . '</li>';
		echo '<li>' . esc_html__( 'How long ZipLogger keeps delivered logs, events, traces and replays depends on your ZipLogger plan and workspace settings.', 'ziplogger' ) . '</li>';
		echo '<li>' . esc_html__( 'Browser identifiers stay in the visitor\'s browser until they clear site data or consent is revoked.', 'ziplogger' ) . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__( 'When the plugin is deleted', 'ziplogger' ) . '</h2>';
		echo '<p><label for="ziplogger-delete"><input type="checkbox" id="ziplogger-delete" name="delete_on_uninstall" value="1" ' . checked( $s['delete_on_uninstall'], true, false ) . ' /> ' . esc_html__( 'Also delete the queue, counters, keys and settings from the database', 'ziplogger' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Deactivating the plugin always keeps your settings and any queued events. This only applies when you delete the plugin.', 'ziplogger' ) . '</p>';

		submit_button( __( 'Save privacy settings', 'ziplogger' ) );
		echo '</form>';
	}
}
