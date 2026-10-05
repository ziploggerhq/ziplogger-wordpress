<?php
/**
 * Suggested privacy-policy text, for Settings, Privacy in WordPress.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress asks plugins that collect information about visitors to offer text for the site's privacy policy
 * (Tools, Privacy, "Policy Guide"). The text here describes only what the site has switched on, so a site that
 * uses server logs alone is not made to describe session replay. It is a starting point, not legal advice: the
 * complete description of what each module collects is in the plugin's docs/PRIVACY.md.
 */
final class Privacy_Policy {

	/**
	 * Register the hook. The function it calls exists only in the administration screens.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'add' ) );
	}

	/**
	 * Hand the suggested text to WordPress.
	 *
	 * @return void
	 */
	public static function add() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'ZipLogger', wp_kses_post( self::content() ) );
		}
	}

	/**
	 * The suggested text, as HTML paragraphs, for the modules that are switched on.
	 *
	 * @return string
	 */
	public static function content() {
		$s       = Settings::get();
		$host    = Settings::endpoint_host();
		$parts   = array();
		$consent = false;
		$parts[] = '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'ziplogger-error-monitoring-session-replay' ) . '</strong> ' . esc_html(
			sprintf(
				/* translators: %s: host name of the ZipLogger service. */
				__( 'This site sends technical and usage information to ZipLogger (%s), a service that helps the site\'s owners find and fix problems and understand how the site is used. What is sent depends on the features the site\'s owners have switched on, which are described below.', 'ziplogger-error-monitoring-session-replay' ),
				'' !== $host ? $host : 'app.ziplogger.ai'
			)
		);

		if ( ! empty( $s['browser']['enabled'] ) || ! empty( $s['analytics']['enabled'] ) || ! empty( $s['replay']['enabled'] ) || ( ! empty( $s['tracing']['enabled'] ) && ! empty( $s['tracing']['browser'] ) ) ) {
			$parts[] = esc_html__( 'Information collected in your browser is sent directly from your browser to ZipLogger, so ZipLogger\'s servers receive your IP address and browser details (such as the user agent) with each request, as any website you connect to does. For usage events, ZipLogger uses the IP address only to work out an approximate location (country, region and city) and the browser details to record the browser, operating system and device type; the IP address is not stored with the event. How ZipLogger handles the connection itself is described in its privacy policy, linked below.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( ! empty( $s['enabled'] ) ) {
			$parts[] = esc_html__( 'Server errors and warnings: the message, the time, a file path inside the site, and technical details such as the WordPress and PHP versions. Passwords, keys, cookies and the contents of requests are removed before anything is sent.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( ! empty( $s['browser']['enabled'] ) ) {
			$parts[] = esc_html__( 'Errors and slow or failing requests in your browser, and page-load timing: the message, the address of the page without its query string or fragment, and timing figures. This sets no cookie and stores nothing on your device, except that a cookie named ziplogger_li (the value 1) tells the script that you are signed in, when you sign in.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( ! empty( $s['analytics']['enabled'] ) ) {
			$consent = true;
			$text    = __( 'With your consent, the pages you view and the actions you take on this site are recorded using a random identifier that is stored in your browser (localStorage, named zl_anon) and a session identifier (sessionStorage, named zl_sess). These identifiers are not your name or your email address.', 'ziplogger-error-monitoring-session-replay' );
			if ( ! empty( $s['analytics']['identify'] ) ) {
				$text .= ' ' . __( 'If you are signed in, a one-way pseudonym of your account is added, so that your visits can be counted together. It cannot be turned back into your user name, your email address or your user id.', 'ziplogger-error-monitoring-session-replay' );
			}
			$parts[] = esc_html( $text );
		}
		if ( ! empty( $s['replay']['enabled'] ) ) {
			$consent = true;
			$parts[] = esc_html__( 'For a sample of visits, and only with your consent, a recording of the page is made in which all text and all form fields are hidden. Payment and password fields, and the sign-in, account, cart and checkout pages, are never recorded. Recording starts when you consent and stops when you withdraw your consent.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( ! empty( $s['tracing']['enabled'] ) ) {
			$parts[] = esc_html__( 'Requests to this site carry a random trace identifier, so that the site\'s owners can follow one request through the site. The identifier contains no personal information and is not stored on your device.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( ! empty( $s['woocommerce']['enabled'] ) ) {
			$parts[] = esc_html__( 'When an order is placed, changes status or is refunded, its amount, currency, number of items, payment method and status are sent under a reference that is not the order number. Names, addresses, email addresses, phone numbers, order keys and payment details are never sent. If you have consented to analytics, a cookie named ziplogger_v (seven days) lets the order be counted together with your visit.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( $consent ) {
			$parts[] = esc_html__( 'Your choice about consent is stored in a cookie named ziplogger_consent (six months). You can withdraw your consent at any time; this stops the collection and removes the identifiers from your browser. The "Do Not Track" and "Global Privacy Control" signals of your browser are respected.', 'ziplogger-error-monitoring-session-replay' );
		}
		$parts[] = wp_kses_post(
			sprintf(
				/* translators: %s: link to the ZipLogger privacy policy. */
				__( 'How long ZipLogger keeps the information depends on the site\'s plan. ZipLogger\'s own privacy policy is at %s.', 'ziplogger-error-monitoring-session-replay' ),
				'<a href="https://ziplogger.ai/privacy" target="_blank" rel="noopener">https://ziplogger.ai/privacy</a>'
			)
		);

		return '<p>' . implode( "</p>\n<p>", $parts ) . '</p>';
	}
}
