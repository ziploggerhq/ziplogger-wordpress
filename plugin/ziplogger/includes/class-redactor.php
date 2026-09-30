<?php
/**
 * Redaction and sanitization of everything that may leave the site.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Recursively redacts structured values and sanitizes free text (messages, stack traces).
 *
 * Runs BEFORE an event is persisted, so neither the queue table nor a request to ZipLogger ever
 * holds the raw value. Redaction reduces exposure; it cannot guarantee that no secret survives in
 * an arbitrary application message (see docs/PRIVACY.md).
 */
final class Redactor {

	const MASK = '[redacted]';

	/**
	 * Substrings that mark a field name as sensitive (compared against the lower-cased,
	 * alphanumeric-only name, so "API-Key", "api_key" and "apiKey" are all "apikey").
	 */
	const KEY_FRAGMENTS = array(
		'password',
		'passwd',
		'passphrase',
		'secret',
		'token',
		'apikey',
		'authorization',
		'cookie',
		'credential',
		'privatekey',
		'accesskey',
		'signature',
		'bearer',
		'sessionid',
		'phpsessid',
		'creditcard',
		'cardnumber',
		'email',
		'username',
		'userlogin',
		'ipaddress',
		'remoteaddr',
		'forwardedfor',
		'clientip',
		'phone',
		'csrf',
		'xsrf',
		'nonce',
	);

	/**
	 * Short names that are only sensitive as a whole word (a substring test would hit "classname").
	 */
	const KEY_TOKENS = array(
		'pwd',
		'pw',
		'pass',
		'auth',
		'session',
		'sid',
		'login',
		'ip',
		'otp',
		'pin',
		'card',
		'cvv',
		'cvc',
		'ssn',
		'iban',
		'key',
	);

	/**
	 * Unix top-level directories that mark a string as a server filesystem path.
	 */
	const UNIX_ROOTS = 'var|home|srv|usr|opt|data|mnt|app|www|Users|tmp|private|etc|root|nas|Volumes|vhosts|htdocs|public_html|websites';

	/**
	 * Limits.
	 *
	 * @var int
	 */
	private $max_depth;

	/**
	 * Limits.
	 *
	 * @var int
	 */
	private $max_items;

	/**
	 * Limits.
	 *
	 * @var int
	 */
	private $max_string;

	/**
	 * Extra sensitive key fragments (lower-case, alphanumeric).
	 *
	 * @var string[]
	 */
	private $extra_keys;

	/**
	 * Extra regular expressions whose matches are masked.
	 *
	 * @var string[]
	 */
	private $extra_patterns;

	/**
	 * Exact secret values to mask wherever they appear (for example the saved API key).
	 *
	 * @var string[]
	 */
	private $secrets;

	/**
	 * Cached [ root => label ] map for path rewriting.
	 *
	 * @var array<string,string>|null
	 */
	private $roots = null;

	/**
	 * Constructor.
	 *
	 * @param array $opts max_depth, max_items, max_string, extra_keys, extra_patterns, secrets.
	 */
	public function __construct( array $opts = array() ) {
		$this->max_depth  = isset( $opts['max_depth'] ) ? (int) $opts['max_depth'] : (int) Limits::get( 'max_depth' );
		$this->max_items  = isset( $opts['max_items'] ) ? (int) $opts['max_items'] : (int) Limits::get( 'max_items' );
		$this->max_string = isset( $opts['max_string'] ) ? (int) $opts['max_string'] : (int) Limits::get( 'max_string_bytes' );
		$this->extra_keys = array();
		foreach ( isset( $opts['extra_keys'] ) ? (array) $opts['extra_keys'] : array() as $k ) {
			$k = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $k ) );
			if ( '' !== $k ) {
				$this->extra_keys[] = $k;
			}
		}
		$this->extra_patterns = array();
		foreach ( isset( $opts['extra_patterns'] ) ? (array) $opts['extra_patterns'] : array() as $p ) {
			// A pattern that fails to compile must never break logging.
			if ( is_string( $p ) && '' !== $p && false !== @preg_match( $p, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
				$this->extra_patterns[] = $p;
			}
		}
		$this->secrets = array();
		foreach ( isset( $opts['secrets'] ) ? (array) $opts['secrets'] : array() as $s ) {
			if ( is_string( $s ) && strlen( $s ) >= 8 ) {
				$this->secrets[] = $s;
			}
		}
	}

	/**
	 * Build a redactor with the site's filter-provided extensions.
	 *
	 * @param array $opts Overrides.
	 * @return self
	 */
	public static function from_filters( array $opts = array() ) {
		/**
		 * Filters extra field-name fragments that must always be redacted (for example "iban" or "member_number").
		 *
		 * @param string[] $keys Fragments; compared against lower-cased alphanumeric field names.
		 */
		$opts['extra_keys'] = apply_filters( 'ziplogger_redact_keys', isset( $opts['extra_keys'] ) ? $opts['extra_keys'] : array() );
		/**
		 * Filters extra regular expressions (full PCRE, with delimiters) whose matches are masked in free text.
		 *
		 * @param string[] $patterns Patterns, e.g. '/\bINV-\d{8}\b/'.
		 */
		$opts['extra_patterns'] = apply_filters( 'ziplogger_redact_patterns', isset( $opts['extra_patterns'] ) ? $opts['extra_patterns'] : array() );
		return new self( $opts );
	}

	/**
	 * Whether a field name looks sensitive.
	 *
	 * @param string|int $key Field name.
	 * @return bool
	 */
	public function is_sensitive_key( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}
		$alnum = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $key ) );
		foreach ( self::KEY_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $alnum, $fragment ) ) {
				return true;
			}
		}
		foreach ( $this->extra_keys as $fragment ) {
			if ( false !== strpos( $alnum, $fragment ) ) {
				return true;
			}
		}
		$tokens = preg_split( '/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $key, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $tokens as $token ) {
			if ( in_array( strtolower( $token ), self::KEY_TOKENS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redact a structured value: scalars are sanitized, arrays are walked with bounded depth and width.
	 *
	 * @param mixed $value Any value.
	 * @param int   $depth Current depth (internal).
	 * @return mixed A JSON-safe value.
	 */
	public function value( $value, $depth = 0 ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}
		if ( is_string( $value ) ) {
			return $this->text( $value, $this->max_string );
		}
		if ( is_array( $value ) ) {
			return $this->walk_array( $value, $depth );
		}
		if ( $value instanceof \WP_Error ) {
			return array(
				'code'    => $this->text( (string) $value->get_error_code(), 128 ),
				'message' => $this->text( (string) $value->get_error_message(), $this->max_string ),
			);
		}
		if ( $value instanceof \Throwable ) {
			return array(
				'class'   => get_class( $value ),
				'message' => $this->text( $value->getMessage(), $this->max_string ),
			);
		}
		if ( is_object( $value ) ) {
			// Never inspect properties or call __toString(): both can run arbitrary code or expose secrets.
			return '[object ' . get_class( $value ) . ']';
		}
		return '[' . gettype( $value ) . ']';
	}

	/**
	 * Walk an array.
	 *
	 * @param array $data  Array.
	 * @param int   $depth Depth.
	 * @return array|string
	 */
	private function walk_array( array $data, $depth ) {
		if ( $depth >= $this->max_depth ) {
			return '[truncated: too deep]';
		}
		$is_list = $this->is_list( $data );
		$out     = array();
		$n       = 0;
		foreach ( $data as $key => $item ) {
			if ( $n >= $this->max_items ) {
				$out['_truncated'] = sprintf( '%d more not shown', count( $data ) - $n );
				break;
			}
			++$n;
			if ( $is_list ) {
				$out[] = $this->value( $item, $depth + 1 );
				continue;
			}
			$name = $this->field_name( $key );
			if ( $this->is_sensitive_key( (string) $key ) ) {
				$out[ $name ] = self::MASK;
			} else {
				$out[ $name ] = $this->value( $item, $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * A field name safe for the backend's index mapping: no dots (they would create nested objects),
	 * a restricted character set and a bounded length.
	 *
	 * @param string|int $key Original key.
	 * @return string
	 */
	public function field_name( $key ) {
		$name = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $this->to_utf8( (string) $key ) );
		$name = substr( (string) $name, 0, 64 );
		return '' === $name ? '_' : $name;
	}

	/**
	 * Whether an array is a plain list (0..n-1 keys in order).
	 *
	 * @param array $data Array.
	 * @return bool
	 */
	public function is_list( array $data ) {
		if ( array() === $data ) {
			return true;
		}
		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}

	/**
	 * Sanitize free text.
	 *
	 * @param string $text      Raw text.
	 * @param int    $max_bytes Maximum output size in bytes.
	 * @return string
	 */
	public function text( $text, $max_bytes = 4096 ) {
		$s = $this->to_utf8( (string) $text );
		if ( '' === $s ) {
			return '';
		}
		// Bound the work first: everything below is linear or worse in the input length.
		if ( strlen( $s ) > $max_bytes * 4 ) {
			$s = $this->truncate( $s, $max_bytes * 4 );
		}

		$s = $this->mask_secrets( $s );
		$s = $this->mask_urls( $s );
		$s = $this->mask_query_strings( $s );
		$s = $this->mask_emails( $s );
		$s = $this->mask_ips( $s );
		$s = $this->mask_cards( $s );
		$s = $this->mask_sql_literals( $s );
		$s = $this->path( $s );

		foreach ( $this->extra_patterns as $pattern ) {
			$r = @preg_replace( $pattern, self::MASK, $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
			if ( is_string( $r ) ) {
				$s = $r;
			}
		}

		return $this->truncate( $s, $max_bytes );
	}

	/**
	 * Sanitize a stack trace: normalizes PHP's embedded "#0 file(12): call(args)" lines into
	 * "#0 file:12 call()" (no arguments, ever), then applies the text rules.
	 *
	 * @param string $trace     Raw trace text.
	 * @param int    $max_bytes Maximum output size.
	 * @return string
	 */
	public function trace( $trace, $max_bytes = 8192 ) {
		$t = $this->to_utf8( (string) $trace );
		$t = preg_replace_callback(
			'/^#(\d+)\s+(.*?)\((\d+)\):\s*(.*)$/m',
			static function ( $m ) {
				$call = $m[4];
				$open = strpos( $call, '(' );
				if ( false !== $open ) {
					$call = substr( $call, 0, $open ) . '()';
				}
				return '#' . $m[1] . ' ' . $m[2] . ':' . $m[3] . ' ' . $call;
			},
			$t
		);
		return $this->text( (string) $t, $max_bytes );
	}

	/**
	 * Rewrite absolute server paths: WordPress locations become relative ("wp-content/plugins/x/y.php"),
	 * any other absolute path keeps only its last two segments.
	 *
	 * @param string $text Text that may contain paths.
	 * @return string
	 */
	public function path( $text ) {
		$s = (string) $text;
		if ( '' === $s ) {
			return $s;
		}
		foreach ( $this->roots() as $root => $label ) {
			$pattern = '#(?<![\w./\-])' . str_replace( '/', '[\\\\/]', preg_quote( $root, '#' ) ) . '(?![\w\-])((?:[\\\\/][^\s:\'"<>()|*?]*)?)#i';
			$s       = (string) preg_replace_callback(
				$pattern,
				static function ( $m ) use ( $label ) {
					$rest = ltrim( str_replace( '\\', '/', $m[1] ), '/' );
					if ( '' === $label ) {
						return '' === $rest ? '.' : $rest;
					}
					return '' === $rest ? $label : $label . '/' . $rest;
				},
				$s
			);
		}

		$generic = array(
			'#(?<![\w:/.\-])/(?:' . self::UNIX_ROOTS . ')/[^\s:\'"<>()|*?]+#',
			'#(?<![\w])[A-Za-z]:[\\\\/][^\s:\'"<>()|*?]*#',
			'#(?<![\w])\\\\\\\\[^\s\\\\]+\\\\[^\s:\'"<>()|*?]+#',
		);
		foreach ( $generic as $pattern ) {
			$s = (string) preg_replace_callback(
				$pattern,
				static function ( $m ) {
					$parts = (array) preg_split( '#[\\\\/]+#', $m[0], -1, PREG_SPLIT_NO_EMPTY );
					// Keep two segments only when the path is deep enough that neither can be a home
					// directory ("/home/alice/x.php" keeps just "x.php").
					$tail = array_slice( $parts, count( $parts ) >= 4 ? -2 : -1 );
					return '[path]/' . implode( '/', $tail );
				},
				$s
			);
		}
		return $s;
	}

	/**
	 * Redact an internal diagnostic (an HTTP error, a failed update) before it is stored or displayed.
	 *
	 * @param string $message Raw diagnostic.
	 * @return string
	 */
	public function diagnostic( $message ) {
		$s = (string) $message;
		foreach ( $this->secrets as $secret ) {
			$s = str_replace( $secret, self::MASK, $s );
		}
		return $this->text( $s, 300 );
	}

	/**
	 * Force valid UTF-8 and drop control characters (except tab and newline).
	 *
	 * @param string $s Input.
	 * @return string
	 */
	public function to_utf8( $s ) {
		$s = (string) $s;
		if ( '' === $s ) {
			return '';
		}
		if ( 1 !== @preg_match( '//u', $s ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$s = (string) mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
			}
			if ( 1 !== @preg_match( '//u', $s ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
				$clean = @iconv( 'UTF-8', 'UTF-8//IGNORE', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
				$s     = false !== $clean && 1 === @preg_match( '//u', $clean ) ? $clean : preg_replace( '/[\x80-\xFF]/', '?', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
			}
		}
		$out = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $s );
		return is_string( $out ) ? $out : '';
	}

	/**
	 * Truncate to a byte length without cutting a UTF-8 sequence.
	 *
	 * @param string $s         Input.
	 * @param int    $max_bytes Limit.
	 * @return string
	 */
	public function truncate( $s, $max_bytes ) {
		if ( strlen( $s ) <= $max_bytes ) {
			return $s;
		}
		$marker = '…';
		$keep   = max( 0, $max_bytes - strlen( $marker ) );
		$cut    = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $keep, 'UTF-8' ) : substr( $s, 0, $keep );
		// Without mbstring, back off to a character boundary manually.
		while ( '' !== $cut && 1 !== preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}
		return $cut . $marker;
	}

	/**
	 * Credentials in headers, tokens with recognisable shapes, and key=value pairs with sensitive keys.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_secrets( $s ) {
		foreach ( $this->secrets as $secret ) {
			$s = str_replace( $secret, self::MASK, $s );
		}

		$s = (string) preg_replace( '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', '[redacted private key]', $s );
		$s = (string) preg_replace( '/\b(Set-Cookie|Cookie|Proxy-Authorization|Authorization|X-Api-Key)\s*:[ \t]*[^\r\n]*/i', '$1: ' . self::MASK, $s );
		$s = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=\-]{8,}/i', 'Bearer ' . self::MASK, $s );
		$s = (string) preg_replace( '/\bBasic\s+(?=[A-Za-z0-9+\/]*[0-9+\/=])[A-Za-z0-9+\/]{8,}={0,2}/', 'Basic ' . self::MASK, $s );
		$s = (string) preg_replace( '/\beyJ[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]*/', self::MASK, $s );
		$s = (string) preg_replace(
			array(
				'/\bzk_[A-Za-z0-9_\-]{8,}/',
				'/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/',
				'/\bAKIA[0-9A-Z]{16}\b/',
				'/\bgh[pousr]_[A-Za-z0-9]{20,}/',
				'/\bxox[abprs]-[A-Za-z0-9\-]{10,}/',
				'/\bAIza[0-9A-Za-z_\-]{30,}/',
				'/\bSG\.[A-Za-z0-9_\-]{16,}\.[A-Za-z0-9_\-]{16,}/',
				'/\b(?:wordpress_(?:logged_in|sec|test_cookie)|wp-settings(?:-time)?)[\w\-]*=[^\s;,\'"]+/i',
			),
			self::MASK,
			$s
		);

		// key=value, key: value, "key":"value", key => 'value' where the key names something sensitive.
		$words = 'password|passwd|passphrase|pwd|secret|token|api[_\-]?key|apikey|authorization|credentials?|private[_\-]?key|access[_\-]?key|session[_\-]?id|phpsessid|signature|cvv|cvc|otp|nonce';
		$s     = (string) preg_replace_callback(
			'/(["\']?)\b([A-Za-z0-9_\-]*(?:' . $words . ')[A-Za-z0-9_\-]*)\1(\s*(?:=>|[=:])\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s,;&)}\]]+)/i',
			static function ( $m ) {
				if ( self::MASK === $m[4] || '"' . self::MASK . '"' === $m[4] ) {
					return $m[0];
				}
				return $m[1] . $m[2] . $m[1] . $m[3] . self::MASK;
			},
			$s
		);
		return $s;
	}

	/**
	 * Strip credentials, query strings and fragments from every URL.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_urls( $s ) {
		return (string) preg_replace_callback(
			'#\b([a-z][a-z0-9+.\-]{1,15})://[^\s\'"<>()\[\]{}|\\\\^`]+#i',
			static function ( $m ) {
				$url      = $m[0];
				$trailing = '';
				if ( preg_match( '/[.,;:!]+$/', $url, $t ) ) {
					$trailing = $t[0];
					$url      = substr( $url, 0, -strlen( $trailing ) );
				}
				$parts = @parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.PHP.NoSilencedErrors.Discouraged -- malformed input is expected here and is handled through the return value; a notice must not be raised while a message is being redacted.
				if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
					return '[url]' . $trailing;
				}
				$out = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
				if ( isset( $parts['port'] ) ) {
					$out .= ':' . $parts['port'];
				}
				if ( isset( $parts['path'] ) ) {
					$out .= $parts['path'];
				}
				return $out . $trailing;
			},
			$s
		);
	}

	/**
	 * Remove "?a=b&c=d" tails from relative URLs and paths.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_query_strings( $s ) {
		return (string) preg_replace( '/(?<=[\w\/.\-])\?[A-Za-z0-9_\-\[\]%.]+=[^\s\'"<>]*/', '', $s );
	}

	/**
	 * Email addresses.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_emails( $s ) {
		return (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', '[email]', $s );
	}

	/**
	 * IPv4 and IPv6 addresses.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_ips( $s ) {
		$s = (string) preg_replace( '/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?!\.?\d)/', '[ip]', $s );
		// Full form (at least four groups, so a 12:30:45 timestamp is left alone).
		$s = (string) preg_replace( '/(?<![\w:])(?:[0-9a-fA-F]{1,4}:){3,7}[0-9a-fA-F]{1,4}(?![\w:])/', '[ip]', $s );
		// Compressed form; requires a digit so "Ace::add" (a PHP static call) is not mistaken for an address.
		$s = (string) preg_replace_callback(
			'/(?<![\w:])(?:[0-9a-fA-F]{0,4}:){1,7}:?[0-9a-fA-F]{0,4}(?![\w:])/',
			static function ( $m ) {
				return ( false !== strpos( $m[0], '::' ) && 1 === preg_match( '/\d/', $m[0] ) && strlen( $m[0] ) >= 3 ) ? '[ip]' : $m[0];
			},
			$s
		);
		return $s;
	}

	/**
	 * Payment card numbers (13-19 digits passing the Luhn check).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_cards( $s ) {
		return (string) preg_replace_callback(
			'/(?<![\d\-])(?:\d[ \-]?){12,18}\d(?![\d\-])/',
			static function ( $m ) {
				$digits = preg_replace( '/\D/', '', $m[0] );
				$len    = strlen( (string) $digits );
				if ( $len < 13 || $len > 19 ) {
					return $m[0];
				}
				$sum = 0;
				$alt = false;
				for ( $i = $len - 1; $i >= 0; $i-- ) {
					$n = (int) $digits[ $i ];
					if ( $alt ) {
						$n *= 2;
						if ( $n > 9 ) {
							$n -= 9;
						}
					}
					$sum += $n;
					$alt  = ! $alt;
				}
				return 0 === $sum % 10 ? '[card]' : $m[0];
			},
			$s
		);
	}

	/**
	 * Quoted literals inside SQL text (WordPress database errors quote the whole query, data included).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function mask_sql_literals( $s ) {
		if ( 1 !== preg_match( '/\b(?:INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+\S+\s+SET|SELECT\s.+?\sFROM)\b/i', $s ) && 1 !== preg_match( '/\b(?:WHERE|VALUES)\b/', $s ) ) {
			return $s;
		}
		return (string) preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/s", "'?'", $s );
	}

	/**
	 * The known WordPress roots, longest first.
	 *
	 * @return array<string,string>
	 */
	private function roots() {
		if ( null !== $this->roots ) {
			return $this->roots;
		}
		$map = array();
		$add = static function ( $dir, $label ) use ( &$map ) {
			if ( is_string( $dir ) && '' !== $dir ) {
				$d = rtrim( str_replace( '\\', '/', $dir ), '/' );
				if ( strlen( $d ) > 1 ) {
					$map[ $d ] = $label;
				}
			}
		};
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$add( WP_PLUGIN_DIR, 'wp-content/plugins' );
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$add( WPMU_PLUGIN_DIR, 'wp-content/mu-plugins' );
		}
		if ( function_exists( 'get_theme_root' ) ) {
			$add( get_theme_root(), 'wp-content/themes' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$add( WP_CONTENT_DIR, 'wp-content' );
		}
		if ( defined( 'ABSPATH' ) ) {
			$add( ABSPATH, '' );
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->roots = $map;
		return $map;
	}
}
