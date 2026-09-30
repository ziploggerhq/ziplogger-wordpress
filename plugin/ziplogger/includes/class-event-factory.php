<?php
/**
 * Builds ZipLogger log records (the wire contract) from raw events.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Produces exactly the record shape POST /ingest/v1/logs accepts:
 *
 * The record:
 *   timestamp, source, severity, message, [release], [commitSha], [stackTrace], fields{}, tags[]
 *
 * Everything user-influenced passes through the Redactor here, before anything is persisted.
 * Extra context goes under "fields" - never invent top-level properties.
 */
final class Event_Factory {

	/**
	 * Field names this plugin owns. Developer context can never overwrite them.
	 */
	const RESERVED_FIELDS = array(
		'environment',
		'siteHost',
		'requestId',
		'wordpressVersion',
		'phpVersion',
		'pluginVersion',
		'eventType',
		'messageTemplate',
		'traceId',
		'spanId',
	);

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Per-request identifier shared by all events of one request.
	 *
	 * @var string|null
	 */
	private static $request_id = null;

	/**
	 * Constructor.
	 *
	 * @param Redactor|null $redactor Redactor; built from filters when omitted.
	 */
	public function __construct( ?Redactor $redactor = null ) {
		$this->redactor = null !== $redactor ? $redactor : Redactor::from_filters( array( 'secrets' => array( Settings::api_key() ) ) );
	}

	/**
	 * The redactor in use.
	 *
	 * @return Redactor
	 */
	public function redactor() {
		return $this->redactor;
	}

	/**
	 * Build a redacted record.
	 *
	 * @param array $spec type, severity, message, template, fields (trusted collector fields),
	 *                    context (developer fields), stack, timestamp.
	 * @return array The record, ready for json_encode via encode().
	 */
	public function build( array $spec ) {
		$r    = $this->redactor;
		$type = isset( $spec['type'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $spec['type'] ) ) : 'log';
		$type = '' === $type ? 'log' : $type;

		$message = $r->text( isset( $spec['message'] ) ? (string) $spec['message'] : '', (int) Limits::get( 'max_message_bytes' ) );
		if ( '' === trim( $message ) ) {
			$message = '(empty message)';
		}
		$template = isset( $spec['template'] ) && is_string( $spec['template'] ) && '' !== $spec['template']
			? $r->text( $spec['template'], 512 )
			: $this->template( $message );

		$settings    = Settings::get();
		$environment = Settings::environment();

		$fields = array(
			'environment'      => $environment,
			'siteHost'         => self::site_host(),
			'requestId'        => self::request_id(),
			'wordpressVersion' => self::wordpress_version(),
			'phpVersion'       => PHP_VERSION,
			'pluginVersion'    => ZIPLOGGER_VERSION,
			'eventType'        => $type,
			'messageTemplate'  => $template,
		);

		// While a request is being traced, its logs carry the trace and span id: ZipLogger links logs to the
		// trace that produced them by these two fields.
		$ids = Tracing\Tracer::ids();
		if ( null !== $ids ) {
			$fields['traceId'] = $ids['traceId'];
			$fields['spanId']  = $ids['spanId'];
		}

		// Trusted collector fields: still redacted, but keep their own names.
		if ( ! empty( $spec['fields'] ) && is_array( $spec['fields'] ) ) {
			$safe = $r->value( $spec['fields'] );
			if ( is_array( $safe ) ) {
				foreach ( $safe as $name => $value ) {
					if ( ! in_array( $name, self::RESERVED_FIELDS, true ) && ! isset( $fields[ $name ] ) ) {
						$fields[ $name ] = $value;
					}
				}
			}
		}

		// Developer context: a name clash with a reserved or collector field is renamed, not dropped.
		if ( ! empty( $spec['context'] ) && is_array( $spec['context'] ) ) {
			$safe = $r->value( $r->is_list( $spec['context'] ) ? array( 'context' => $spec['context'] ) : $spec['context'] );
			if ( is_array( $safe ) ) {
				foreach ( $safe as $name => $value ) {
					$key = isset( $fields[ $name ] ) ? 'context_' . $name : $name;
					if ( ! isset( $fields[ $key ] ) ) {
						$fields[ $key ] = $value;
					}
				}
			}
		}

		$max_fields = (int) Limits::get( 'max_fields' );
		if ( count( $fields ) > $max_fields ) {
			$fields = array_slice( $fields, 0, $max_fields, true );
		}

		$record = array(
			'timestamp' => Clock::iso( isset( $spec['timestamp'] ) ? (float) $spec['timestamp'] : null ),
			'source'    => $settings['source'],
			'severity'  => Severity::normalize( isset( $spec['severity'] ) ? $spec['severity'] : 'info' ),
			'message'   => $message,
		);

		$release = self::release_value();
		if ( '' !== $release ) {
			$record['release'] = $release;
		}
		$commit = self::commit_sha_value();
		if ( '' !== $commit ) {
			$record['commitSha'] = $commit;
		}

		$stack_limit = (int) Limits::get( 'max_stack_bytes' );
		if ( ! empty( $spec['stack'] ) && is_string( $spec['stack'] ) && $stack_limit > 0 ) {
			$stack = $r->trace( $spec['stack'], $stack_limit );
			if ( '' !== $stack ) {
				$record['stackTrace'] = $stack;
			}
		}

		$record['fields'] = $fields;
		$record['tags']   = $this->tags( $environment );
		return $record;
	}

	/**
	 * Serialize a record, shrinking it if it exceeds the size cap.
	 *
	 * @param array $record Record from build().
	 * @return array{json:?string,reason:?string} json is null when the record cannot be sent;
	 *                                            reason is "unencodable" or "oversize".
	 */
	public function encode( array $record ) {
		$max = (int) Limits::get( 'max_event_bytes' );

		$json = $this->json( $record );
		if ( null === $json ) {
			return array(
				'json'   => null,
				'reason' => 'unencodable',
			);
		}
		if ( strlen( $json ) <= $max ) {
			return array(
				'json'   => $json,
				'reason' => null,
			);
		}

		// Shrink in order of least value: the stack trace, then the message tail, then optional fields.
		unset( $record['stackTrace'] );
		$json = $this->json( $record );
		if ( null !== $json && strlen( $json ) <= $max ) {
			return array(
				'json'   => $json,
				'reason' => null,
			);
		}

		$record['message'] = $this->redactor->truncate( $record['message'], 1024 );
		$json              = $this->json( $record );
		if ( null !== $json && strlen( $json ) <= $max ) {
			return array(
				'json'   => $json,
				'reason' => null,
			);
		}

		$keep = array();
		foreach ( self::RESERVED_FIELDS as $name ) {
			if ( isset( $record['fields'][ $name ] ) ) {
				$keep[ $name ] = $record['fields'][ $name ];
			}
		}
		$record['fields'] = $keep;
		$json             = $this->json( $record );
		if ( null !== $json && strlen( $json ) <= $max ) {
			return array(
				'json'   => $json,
				'reason' => null,
			);
		}
		return array(
			'json'   => null,
			'reason' => 'oversize',
		);
	}

	/**
	 * Strict JSON encoding: null when the value cannot be represented.
	 *
	 * @param array $record Record.
	 * @return string|null
	 */
	private function json( array $record ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
			$flags |= JSON_INVALID_UTF8_SUBSTITUTE;
		}
		if ( isset( $record['fields'] ) && is_array( $record['fields'] ) ) {
			$record['fields'] = (object) $record['fields']; // Always a JSON object, never [].
		}
		$json = json_encode( $record, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the flags decide the exact bytes that are stored and hashed; wp_json_encode() may re-encode on failure and change them.
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		return $json;
	}

	/**
	 * A stable "message template": the message with variable parts replaced, so ZipLogger can group
	 * occurrences of the same event (the backend hashes fields.messageTemplate at ingest).
	 *
	 * @param string $message Redacted message.
	 * @return string
	 */
	public function template( $message ) {
		$t = (string) preg_replace( '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '{uuid}', $message );
		$t = (string) preg_replace( '/\b[0-9a-f]{16,}\b/i', '{hex}', $t );
		$t = (string) preg_replace( '/"[^"\n]{0,80}"|\'[^\'\n]{0,80}\'/', '{value}', $t );
		$t = (string) preg_replace( '/\b\d+(?:\.\d+)?\b/', '{n}', $t );
		return $this->redactor->truncate( $t, 512 );
	}

	/**
	 * Per-request identifier.
	 *
	 * @return string
	 */
	public static function request_id() {
		if ( null === self::$request_id ) {
			try {
				self::$request_id = bin2hex( random_bytes( 8 ) );
			} catch ( \Exception $e ) {
				self::$request_id = substr( md5( uniqid( '', true ) ), 0, 16 );
			}
		}
		return self::$request_id;
	}

	/**
	 * Forget the request id (tests).
	 *
	 * @return void
	 */
	public static function reset_request_id() {
		self::$request_id = null;
	}

	/**
	 * Host name of this site (no scheme, path or credentials).
	 *
	 * @return string
	 */
	public static function site_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * WordPress version.
	 *
	 * @return string
	 */
	private static function wordpress_version() {
		global $wp_version;
		return isset( $wp_version ) ? (string) $wp_version : '';
	}

	/**
	 * Optional release identifier (constant, environment, or filter).
	 *
	 * @return string
	 */
	public static function release_value() {
		$value = defined( 'ZIPLOGGER_RELEASE' ) ? (string) ZIPLOGGER_RELEASE : (string) getenv( 'ZIPLOGGER_RELEASE' );
		/**
		 * Filters the release (deployment version) attached to every event. Useful for regression attribution.
		 *
		 * @param string $release Release identifier.
		 */
		$value = apply_filters( 'ziplogger_release', $value );
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+\-\/]{0,127}$/', $value ) ? $value : '';
	}

	/**
	 * Optional commit SHA (constant, environment, or filter).
	 *
	 * @return string
	 */
	public static function commit_sha_value() {
		$value = defined( 'ZIPLOGGER_COMMIT_SHA' ) ? (string) ZIPLOGGER_COMMIT_SHA : (string) getenv( 'ZIPLOGGER_COMMIT_SHA' );
		/**
		 * Filters the commit SHA attached to every event (enables ZipLogger's regression attribution).
		 *
		 * @param string $sha Hexadecimal commit id.
		 */
		$value = strtolower( (string) apply_filters( 'ziplogger_commit_sha', $value ) );
		return 1 === preg_match( '/^[0-9a-f]{7,64}$/', $value ) ? $value : '';
	}

	/**
	 * Tags: "WordPress", the environment, plus any filtered extras.
	 *
	 * @param string $environment Environment name.
	 * @return string[]
	 */
	private function tags( $environment ) {
		$tags = array( 'wordpress', $environment );
		/**
		 * Filters extra tags added to every event.
		 *
		 * @param string[] $tags Tags.
		 */
		$filtered = apply_filters( 'ziplogger_tags', $tags );
		$out      = array();
		foreach ( is_array( $filtered ) ? $filtered : $tags as $tag ) {
			if ( is_string( $tag ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,47}$/', $tag ) && ! in_array( $tag, $out, true ) ) {
				$out[] = $tag;
			}
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out ? $out : array( 'wordpress' );
	}
}
