<?php
/**
 * Public developer API.
 *
 * Two equivalent entry points, so other plugins never have to depend on this one being installed:
 *
 *   ziplogger_log( 'info', 'Background import completed', array( 'processed_count' => 42 ) );
 *   do_action( 'ziplogger_log', 'info', 'Background import completed', array( 'processed_count' => 42 ) );
 *
 * The event goes through the same validation, redaction, severity filtering and limits as every
 * collected event. It is queued locally; nothing is sent from the call itself.
 *
 * @package ZipLogger
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ziplogger_log' ) ) {
	/**
	 * Record a log event.
	 *
	 * @param string $severity One of debug, info, warn, error, fatal (PSR-3 level names are also accepted).
	 * @param string $message  Human-readable message. Do not put secrets in it: redaction is best-effort.
	 * @param array  $context  Additional structured fields. Pass a Throwable under the key "exception" to
	 *                         attach its (argument-free) stack trace.
	 * @return bool True when the event was accepted into this request's buffer; false when collection is off,
	 *              the event is below the severity threshold, or a limit was hit.
	 */
	function ziplogger_log( $severity, $message, $context = array() ) {
		return \ZipLogger\WordPress\Plugin::instance()->log( $severity, $message, is_array( $context ) ? $context : array() );
	}
}

// The action form: silently does nothing when this plugin is inactive, because nobody hooks it then.
add_action(
	'ziplogger_log',
	static function ( $severity = 'info', $message = '', $context = array() ) {
		ziplogger_log( $severity, $message, $context );
	},
	10,
	3
);

if ( ! function_exists( 'ziplogger_track' ) ) {
	/**
	 * Record a product-analytics event from the server: a signup, an import, a subscription change.
	 *
	 * Sent to ZipLogger's events endpoint with the server key, through the same bounded queue. Nothing is sent
	 * from the call itself. Requires collection to be on AND the Analytics module to be enabled.
	 *
	 * Who the event belongs to: only what the visitor agreed to. When analytics consent is evident in this
	 * request, the event carries the visitor's anonymous and session ids (from the browser script's cookie,
	 * when it has set one) and, for a signed-in user, a keyed-hash pseudonym of their id. Otherwise the event
	 * belongs to a fixed "system" actor, which is right for events about the site rather than about a person.
	 * Pass 'actor' => 'system' to force that. Never pass an email address or a user name: properties are
	 * cleaned, but the safest data is data you did not send.
	 *
	 * @param string $name       Event name, e.g. "newsletter_confirmed". Lower-cased; only a-z 0-9 _ . : are kept.
	 * @param array  $properties Flat properties (scalars, or short lists of scalars).
	 * @param array  $options    'insert_id' (string) makes retries of the same logical event count once;
	 *                           'actor' => 'system' skips visitor attribution.
	 * @return bool True when the event was queued.
	 */
	function ziplogger_track( $name, $properties = array(), $options = array() ) {
		return \ZipLogger\WordPress\Plugin::instance()->track( $name, is_array( $properties ) ? $properties : array(), is_array( $options ) ? $options : array() );
	}
}

add_action(
	'ziplogger_track',
	static function ( $name = '', $properties = array(), $options = array() ) {
		ziplogger_track( $name, $properties, $options );
	},
	10,
	3
);
