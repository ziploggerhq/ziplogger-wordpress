<?php
/**
 * Endpoint validation and URL building, with SSRF protections.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The API key is sent to whatever endpoint is configured, so the endpoint is validated when it is
 * saved AND again when a request is made:
 *
 *  - HTTPS only, no credentials, query or fragment in the URL;
 *  - no IP literals, no localhost / .local / .internal names;
 *  - only port 443 (filterable);
 *  - every address the host resolves to must be public (blocks private, loopback, link-local and
 *    cloud-metadata ranges).
 *
 * A local development server needs the explicit constant ZIPLOGGER_ALLOW_INSECURE_ENDPOINT = true,
 * which relaxes exactly these checks and nothing else. Redirects are never followed by the transport.
 */
final class Endpoint {

	const DEFAULT_BASE = 'https://app.ziplogger.ai';
	const INGEST_PATH  = '/ingest/v1/logs';

	/**
	 * Test hook: callable( string $host ): string[] returning resolved IPs. Null uses DNS.
	 *
	 * @var callable|null
	 */
	public static $resolver = null;

	/**
	 * Whether the explicit local-development exception is on.
	 *
	 * @return bool
	 */
	public static function insecure_allowed() {
		return true === Settings::constant( 'ZIPLOGGER_ALLOW_INSECURE_ENDPOINT' );
	}

	/**
	 * Validate and normalize an endpoint base URL.
	 *
	 * @param string $url  Candidate, e.g. "https://app.ziplogger.ai" (a pasted full ingest URL is accepted).
	 * @param array  $opts resolve: bool, force DNS check even for the default host.
	 *                     dns:     false skips every DNS lookup. Only for callers whose requests are made by a
	 *                     visitor's browser, where server-side request forgery does not apply, and which must not
	 *                     resolve names while rendering a page.
	 * @return array{ok:bool,base:string,error:string,code:string}
	 */
	public static function validate( $url, array $opts = array() ) {
		$fail = static function ( $code, $message ) {
			return array(
				'ok'    => false,
				'base'  => '',
				'error' => $message,
				'code'  => $code,
			);
		};

		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			return $fail( 'malformed', __( 'The URL is empty, too long, or contains spaces or control characters.', 'ziplogger-error-monitoring-session-replay' ) );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return $fail( 'malformed', __( 'That is not a complete URL (expected https://host).', 'ziplogger-error-monitoring-session-replay' ) );
		}

		$dev    = self::insecure_allowed();
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( $dev && 'http' === $scheme ) ) {
			return $fail( 'scheme', __( 'The endpoint must use https:// so the API key is encrypted in transit.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $fail( 'credentials', __( 'The URL must not contain a username or password.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return $fail( 'query', __( 'The URL must not contain a query string or fragment.', 'ziplogger-error-monitoring-session-replay' ) );
		}

		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( function_exists( 'idn_to_ascii' ) && 1 !== preg_match( '/^[\x00-\x7F]+$/', $host ) ) {
			$ascii = idn_to_ascii( $host );
			$host  = false === $ascii ? $host : strtolower( $ascii );
		}
		$host_error = self::host_error( $host, $dev );
		if ( '' !== $host_error ) {
			return $fail( 'host', $host_error );
		}

		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
		$allowed = apply_filters( 'ziplogger_allowed_endpoint_ports', array( 443 ) );
		$allowed = is_array( $allowed ) ? array_map( 'intval', $allowed ) : array( 443 );
		if ( ! $dev && ! in_array( $port, $allowed, true ) ) {
			/* translators: %d: port number. */
			return $fail( 'port', sprintf( __( 'Port %d is not allowed. Use the standard HTTPS port (443).', 'ziplogger-error-monitoring-session-replay' ), $port ) );
		}

		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		if ( self::INGEST_PATH === substr( $path, -strlen( self::INGEST_PATH ) ) ) {
			$path = substr( $path, 0, -strlen( self::INGEST_PATH ) );
		}
		if ( '' !== $path && 1 !== preg_match( '#^(?:/[A-Za-z0-9._~\-]+)+$#', $path ) ) {
			return $fail( 'path', __( 'The URL path contains unsupported characters.', 'ziplogger-error-monitoring-session-replay' ) );
		}

		$is_default = self::same_host( $host, self::DEFAULT_BASE ) && '' === $path && 443 === $port && 'https' === $scheme;
		$check_dns  = ! isset( $opts['dns'] ) || false !== $opts['dns'];
		if ( $check_dns && ! $is_default && ( ! empty( $opts['resolve'] ) || ! $dev ) ) {
			$dns_error = self::resolution_error( $host, $dev );
			if ( '' !== $dns_error ) {
				return $fail( 'dns', $dns_error );
			}
		}

		$base = $scheme . '://' . $host . ( 443 === $port && 'https' === $scheme ? '' : ':' . $port ) . $path;
		return array(
			'ok'    => true,
			'base'  => $base,
			'error' => '',
			'code'  => '',
		);
	}

	/**
	 * The full ingestion URL for a base.
	 *
	 * @param string $base Validated base URL.
	 * @return string
	 */
	public static function ingest_url( $base ) {
		return rtrim( $base, '/' ) . self::INGEST_PATH;
	}

	/**
	 * The ZipLogger application URL for the admin "Open ZipLogger" link. For the default endpoint this
	 * is https://app.ziplogger.ai/ (the search page is the application root); for a custom endpoint
	 * it is that server's origin.
	 *
	 * @param string $base Validated base URL.
	 * @return string
	 */
	public static function app_url( $base ) {
		return rtrim( '' === $base ? self::DEFAULT_BASE : $base, '/' ) . '/';
	}

	/**
	 * Whether two base URLs are the same server and path.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_base( $a, $b ) {
		return strtolower( rtrim( (string) $a, '/' ) ) === strtolower( rtrim( (string) $b, '/' ) );
	}

	/**
	 * Whether a host equals the host of a base URL.
	 *
	 * @param string $host Host name.
	 * @param string $base Base URL.
	 * @return bool
	 */
	public static function same_host( $host, $base ) {
		$h = wp_parse_url( $base, PHP_URL_HOST );
		return is_string( $h ) && strtolower( $h ) === strtolower( $host );
	}

	/**
	 * Re-check an endpoint immediately before a request. Returns '' when the request may proceed.
	 *
	 * @param string $base Endpoint base URL.
	 * @return string Error message or ''.
	 */
	public static function preflight( $base ) {
		$checked = self::validate( $base );
		return $checked['ok'] ? '' : $checked['error'];
	}

	/**
	 * Reject unsafe host names and IP literals.
	 *
	 * @param string $host Lower-case host.
	 * @param bool   $dev  Local-development exception.
	 * @return string Error or ''.
	 */
	private static function host_error( $host, $dev ) {
		if ( '' === $host || strlen( $host ) > 253 ) {
			return __( 'The host name is missing or too long.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( $dev ) {
			return 1 === preg_match( '/^[a-z0-9.\-]+$/', $host ) || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP )
				? ''
				: __( 'The host name contains unsupported characters.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return __( 'Use a host name, not an IP address.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( 1 !== preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9\-]{0,61}[a-z0-9]$/', $host ) ) {
			return __( 'That is not a valid public host name.', 'ziplogger-error-monitoring-session-replay' );
		}
		$blocked = array( 'localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp', 'test', 'invalid', 'example' );
		$tld     = substr( $host, (int) strrpos( $host, '.' ) + 1 );
		if ( 'localhost' === $host || in_array( $tld, $blocked, true ) ) {
			return __( 'Local and internal host names are not allowed.', 'ziplogger-error-monitoring-session-replay' );
		}
		return '';
	}

	/**
	 * Resolve the host and require every address to be public.
	 *
	 * @param string $host Host name.
	 * @param bool   $dev  Local-development exception (skips the public-address requirement).
	 * @return string Error or ''.
	 */
	private static function resolution_error( $host, $dev ) {
		$ips = self::resolve( $host );
		if ( ! $ips ) {
			return __( 'The host name could not be resolved. Check the spelling, or try again in a moment.', 'ziplogger-error-monitoring-session-replay' );
		}
		if ( $dev ) {
			return '';
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return __( 'The host resolves to a private or reserved address, which is not allowed.', 'ziplogger-error-monitoring-session-replay' );
			}
		}
		return '';
	}

	/**
	 * Is this a public address?
	 *
	 * PHP's own filter knows the private and reserved ranges, but older PHP versions (7.4) do not look inside an
	 * IPv6 address that carries an IPv4 one (::ffff:10.0.0.1, the NAT64 prefix 64:ff9b::/96, 6to4 addresses),
	 * which would let a name that resolves to such an address reach an internal host. The embedded IPv4 address
	 * is therefore checked on its own, and Teredo tunnel addresses are refused.
	 *
	 * @param string $ip Address (IPv4 or IPv6 text form).
	 * @return bool
	 */
	private static function is_public_ip( $ip ) {
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return false;
		}
		if ( false === strpos( $ip, ':' ) ) {
			return true;
		}
		$bin = inet_pton( $ip );
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return false;
		}
		if ( 0 === strncmp( $bin, "\x20\x01\x00\x00", 4 ) ) {
			return false; // Teredo: a tunnel whose far end is not the address's owner.
		}
		$embedded = array();
		$zeros    = str_repeat( "\0", 10 );
		if ( 0 === strncmp( $bin, $zeros . "\xff\xff", 12 ) || 0 === strncmp( $bin, $zeros . "\0\0", 12 ) || 0 === strncmp( $bin, "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ), 12 ) ) {
			$embedded[] = substr( $bin, 12, 4 ); // IPv4-mapped, IPv4-compatible and NAT64.
		}
		if ( 0 === strncmp( $bin, "\x20\x02", 2 ) ) {
			$embedded[] = substr( $bin, 2, 4 ); // 6to4.
		}
		foreach ( $embedded as $v4 ) {
			if ( false === filter_var( inet_ntop( $v4 ), FILTER_VALIDATE_IP, $flags ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve A and AAAA records.
	 *
	 * @param string $host Host name.
	 * @return string[]
	 */
	private static function resolve( $host ) {
		if ( null !== self::$resolver ) {
			$r = call_user_func( self::$resolver, $host );
			return is_array( $r ) ? array_values( array_filter( array_map( 'strval', $r ) ) ) : array();
		}
		$ips = array();
		$v4  = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an expected outcome, handled through the return value.
		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an expected outcome, handled through the return value.
			if ( is_array( $v6 ) ) {
				foreach ( $v6 as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		return array_values( array_unique( $ips ) );
	}
}
