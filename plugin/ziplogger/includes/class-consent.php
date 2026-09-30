<?php
/**
 * Consent policy and evidence.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * ZipLogger itself has no consent or Do-Not-Track handling, so this plugin owns it.
 *
 * Categories (each with its own policy):
 *
 *   browser    browser errors, failed requests and performance. Works WITHOUT any persistent identifier
 *              (ids live in memory for one page view), so the default policy is "none".
 *   analytics  page views, interactions, custom events, identification. Creates identifiers.
 *   replay     session replay. Creates identifiers and records the screen.
 *   commerce   server-side WooCommerce order/payment events. Transactional; default "none". Visitor
 *              identifiers are attached only when "analytics" consent evidence exists.
 *
 * Policy "none" means the category is collected without asking. Policy "required" means it is collected
 * only while there is evidence of consent. Evidence, in order:
 *
 *   1. the "ziplogger_consent" filter (for consent managers: return true or false, null to abstain);
 *   2. the WordPress Consent API (wp_has_consent), when that plugin is active and the setting is on;
 *   3. this plugin's own first-party cookie "ziplogger_consent", written by ZipLoggerWP.consent.grant().
 *
 * No evidence counts as "unknown" and is treated as NOT granted. Data is never queued, buffered or
 * replayed on behalf of a category that has not been granted: what happens before consent is simply
 * not collected, and granting later does not send it retroactively.
 */
final class Consent {

	const COOKIE = 'ziplogger_consent';

	/**
	 * How our categories map onto the WordPress Consent API's categories.
	 */
	const WP_CATEGORY_MAP = array(
		'browser'   => 'statistics-anonymous',
		'analytics' => 'statistics',
		'replay'    => 'statistics',
		'commerce'  => 'functional',
	);

	/**
	 * Per-request memo of evidence.
	 *
	 * @var array<string,string>
	 */
	private static $memo = array();

	/**
	 * Forget memoized evidence (tests, or after consent changed within the request).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$memo = array();
	}

	/**
	 * A category's policy: "none" or "required".
	 *
	 * @param string $category browser, analytics, replay or commerce.
	 * @return string
	 */
	public static function policy( $category ) {
		$s = Settings::get();
		return isset( $s['consent'][ $category ] ) && 'none' === $s['consent'][ $category ] ? 'none' : 'required';
	}

	/**
	 * Evidence for a category: "granted", "denied" or "unknown".
	 *
	 * @param string $category Category.
	 * @return string
	 */
	public static function state( $category ) {
		if ( isset( self::$memo[ $category ] ) ) {
			return self::$memo[ $category ];
		}
		$state = self::detect( $category );
		/**
		 * Filters the consent state ("granted", "denied", "unknown") after evidence has been read.
		 *
		 * @param string $state    Detected state.
		 * @param string $category Category.
		 */
		$state = apply_filters( 'ziplogger_consent_state', $state, $category );
		$state = in_array( $state, array( 'granted', 'denied' ), true ) ? $state : 'unknown';

		self::$memo[ $category ] = $state;
		return $state;
	}

	/**
	 * Whether collection for a category may proceed right now.
	 *
	 * @param string $category Category.
	 * @return bool
	 */
	public static function allows( $category ) {
		return 'none' === self::policy( $category ) || 'granted' === self::state( $category );
	}

	/**
	 * Read the evidence.
	 *
	 * @param string $category Category.
	 * @return string
	 */
	private static function detect( $category ) {
		/**
		 * Consent-manager integration point. Return true when the visitor has consented to the category,
		 * false when they have refused it, or null when this integration has no answer.
		 *
		 * @param bool|null $consent  Null by default.
		 * @param string    $category browser, analytics, replay or commerce.
		 */
		$filtered = apply_filters( 'ziplogger_has_consent', null, $category );
		if ( true === $filtered ) {
			return 'granted';
		}
		if ( false === $filtered ) {
			return 'denied';
		}

		$s = Settings::get();
		if ( ! empty( $s['consent']['wp_consent_api'] ) && function_exists( 'wp_has_consent' ) ) {
			$map = apply_filters( 'ziplogger_wp_consent_categories', self::WP_CATEGORY_MAP );
			if ( isset( $map[ $category ] ) && is_string( $map[ $category ] ) ) {
				return wp_has_consent( $map[ $category ] ) ? 'granted' : 'denied';
			}
		}

		$granted = self::cookie_categories();
		if ( null !== $granted ) {
			return in_array( $category, $granted, true ) ? 'granted' : 'denied';
		}
		return 'unknown';
	}

	/**
	 * Categories recorded in the plugin's own consent cookie, or null when there is no cookie.
	 *
	 * @return string[]|null
	 */
	public static function cookie_categories() {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the visitor's own cookie; nothing is changed by this request.
			return null;
		}
		$raw = wp_unslash( $_COOKIE[ self::COOKIE ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		if ( 1 !== preg_match( '/^[a-z,]{0,64}$/', $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( explode( ',', $raw ) as $part ) {
			if ( in_array( $part, Settings_Schema::CONSENT_CATEGORIES, true ) ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * What the browser needs to evaluate consent itself: policies, the WP Consent API mapping and
	 * whether to use it. Contains no per-visitor data, so it is safe in cached pages.
	 *
	 * @return array
	 */
	public static function for_browser() {
		$s = Settings::get();
		return array(
			'policy'       => array(
				'browser'   => self::policy( 'browser' ),
				'analytics' => self::policy( 'analytics' ),
				'replay'    => self::policy( 'replay' ),
				'commerce'  => self::policy( 'commerce' ),
			),
			'wpConsentApi' => ! empty( $s['consent']['wp_consent_api'] ),
			'wpCategories' => apply_filters( 'ziplogger_wp_consent_categories', self::WP_CATEGORY_MAP ),
			'cookie'       => self::COOKIE,
		);
	}
}
