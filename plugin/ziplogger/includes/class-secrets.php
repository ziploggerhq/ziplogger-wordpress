<?php
/**
 * A per-site secret and the pseudonymous identifiers derived from it.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Personal identifiers must never leave the site raw. Instead of WordPress user ids, order ids or
 * emails, telemetry carries keyed hashes (HMAC-SHA256, truncated). They are:
 *
 *  - stable: the same user gets the same id every time, so analytics can count and link them;
 *  - opaque: without the site secret nobody can turn an id back into (or derive it from) a WordPress id;
 *  - per site: another site with the same user id produces a different value.
 *
 * The secret lives in its own option (not autoloaded) and is independent of the WordPress salts, so
 * rotating salts (a common security hygiene step) does not silently re-identify everyone. Define
 * ZIPLOGGER_SECRET in wp-config.php to keep it out of the database. Replacing the secret is a
 * deliberate reset: every derived id changes.
 */
final class Secrets {

	const OPTION = 'ziplogger_secret';

	/**
	 * Memoized secret for this request.
	 *
	 * @var string|null
	 */
	private static $memo = null;

	/**
	 * The site secret, created on first use.
	 *
	 * @return string
	 */
	public static function site_secret() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$constant = Settings::constant( 'ZIPLOGGER_SECRET' );
		if ( is_string( $constant ) && strlen( $constant ) >= 16 ) {
			self::$memo = $constant;
			return self::$memo;
		}
		$stored = get_option( self::OPTION, '' );
		if ( ! is_string( $stored ) || strlen( $stored ) < 32 ) {
			try {
				$stored = bin2hex( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				$stored = hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) );
			}
			add_option( self::OPTION, $stored, '', 'no' );
		}
		self::$memo = $stored;
		return self::$memo;
	}

	/**
	 * Forget the memoized secret (tests, or after a deliberate reset).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$memo = null;
	}

	/**
	 * A stable opaque identifier for a value, e.g. pseudonym( 'wpu', 42 ) => "wpu_3f9a...".
	 *
	 * @param string     $kind  Short lowercase prefix naming what the id stands for.
	 * @param string|int $value The private value (user id, order id ...).
	 * @param int        $len   Hex characters to keep (default 24 = 96 bits).
	 * @return string
	 */
	public static function pseudonym( $kind, $value, $len = 24 ) {
		$kind = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $kind ) );
		return $kind . '_' . substr( hash_hmac( 'sha256', $kind . '|' . (string) $value, self::site_secret() ), 0, max( 8, min( 64, (int) $len ) ) );
	}

	/**
	 * A keyed fingerprint for comparing values without keeping them (used for destinations).
	 *
	 * @param string $value Value.
	 * @return string 16 hex characters.
	 */
	public static function fingerprint( $value ) {
		return substr( hash_hmac( 'sha256', 'fp|' . (string) $value, self::site_secret() ), 0, 16 );
	}
}
