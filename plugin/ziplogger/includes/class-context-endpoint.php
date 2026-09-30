<?php
/**
 * The uncached endpoint that answers per-visitor questions for the browser script.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Page caches and CDNs serve one copy of a page to everybody, so nothing about the visitor can be in the
 * page. The script instead asks this endpoint (admin-ajax.php?action=ziplogger_context, a POST, never
 * cached) three things about the person who is asking:
 *
 * The three answers:
 *   loggedIn        whether they are signed in;
 *   replayAllowed   whether their role may be recorded at all (administrators and editors by default,
 *                   every super admin always). Session replay fails closed: without a "true" answer the
 *                   script does not record;
 *   userRef         a keyed-hash pseudonym of their WordPress user id, only when the administrator switched
 *                   identification on. Never an email address, login name or user id.
 *
 * The answer describes only the visitor who asked, is readable only by that visitor's own browser (the
 * endpoint sends no CORS headers), and is sent with no-cache headers. It accepts no input.
 *
 * A second, tiny helper lives here: a cookie ("ziplogger_li", value "1") that tells the script that a user
 * is signed in, so anonymous visitors never make the request. It is a hint, not a credential: the server
 * never trusts it, and it holds no data.
 */
final class Context_Endpoint {

	/**
	 * Register hooks (only when the front-end script can be on).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_' . Frontend::CONTEXT_ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . Frontend::CONTEXT_ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'set_logged_in_cookie', array( __CLASS__, 'set_hint' ), 10, 3 );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'clear_hint' ) );
	}

	/**
	 * AJAX handler.
	 *
	 * @return void
	 */
	public static function handle() {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex' );
		}
		if ( ! Modules::frontend_needed() ) {
			wp_send_json_error( array( 'code' => 'off' ), 404 );
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( array( 'code' => 'method' ), 405 );
		}
		wp_send_json_success( self::payload() );
	}

	/**
	 * The answer for the current visitor.
	 *
	 * @return array{loggedIn:bool,replayAllowed:bool,userRef:?string}
	 */
	public static function payload() {
		$user   = is_user_logged_in() ? wp_get_current_user() : null;
		$logged = $user instanceof \WP_User && $user->exists();
		$s      = Settings::get();

		$ref = null;
		if ( $logged && Modules::effective( 'analytics' ) && ! empty( $s['analytics']['identify'] ) ) {
			$ref = Secrets::pseudonym( 'wpu', $user->ID );
		}
		return array(
			'loggedIn'      => $logged,
			'replayAllowed' => self::replay_allowed( $logged ? $user : null ),
			'userRef'       => $ref,
		);
	}

	/**
	 * May this visitor's session be recorded? True only when replay is running and nothing excludes them.
	 *
	 * @param \WP_User|null $user Signed-in user, or null for an anonymous visitor.
	 * @return bool
	 */
	public static function replay_allowed( $user ) {
		if ( ! Modules::effective( 'replay' ) ) {
			return false;
		}
		if ( $user instanceof \WP_User ) {
			if ( is_multisite() && is_super_admin( $user->ID ) ) {
				return false;
			}
			$s = Settings::get();
			foreach ( (array) $user->roles as $role ) {
				if ( in_array( $role, $s['replay']['exclude_roles'], true ) ) {
					return false;
				}
			}
		}
		/**
		 * Lets a site exclude more visitors from session replay. Return false to exclude.
		 *
		 * @param bool          $allowed Whether the visitor may be recorded.
		 * @param \WP_User|null $user    The signed-in user, or null.
		 */
		return true === apply_filters( 'ziplogger_replay_allowed_for_user', true, $user );
	}

	/**
	 * Set the signed-in hint cookie.
	 *
	 * @param string $logged_in_cookie Unused.
	 * @param int    $expire           Cookie expiry.
	 * @param int    $expiration       Unused.
	 * @return void
	 */
	public static function set_hint( $logged_in_cookie = '', $expire = 0, $expiration = 0 ) {
		unset( $logged_in_cookie, $expiration );
		if ( Modules::frontend_needed() ) {
			self::send_cookie( '1', (int) $expire );
		}
	}

	/**
	 * Remove the hint cookie (sign-out). Nothing is sent unless the cookie exists.
	 *
	 * @return void
	 */
	public static function clear_hint() {
		if ( isset( $_COOKIE[ Frontend::HINT_COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the visitor's own cookie; nothing is changed by this request.
			self::send_cookie( '', time() - YEAR_IN_SECONDS );
		}
	}

	/**
	 * Write the cookie: readable by the page script (that is its purpose), never sent to another site.
	 *
	 * @param string $value  Value.
	 * @param int    $expire Expiry timestamp (0 = session).
	 * @return void
	 */
	private static function send_cookie( $value, $expire ) {
		if ( headers_sent() ) {
			return;
		}
		$path   = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '';
		setcookie(
			Frontend::HINT_COOKIE,
			$value,
			array(
				'expires'  => $expire,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}
}
