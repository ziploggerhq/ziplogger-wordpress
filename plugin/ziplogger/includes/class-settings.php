<?php
/**
 * Settings storage, defaults, validation, and secret resolution.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Two options per site:
 *
 *  - ziplogger_settings (autoloaded, tiny): switches and labels, read on every request.
 *  - ziplogger_api_key  (NOT autoloaded): the ingestion key, read only by the worker and the admin
 *    screen. It is never rendered, never registered with show_in_rest, never put in a REST response.
 *
 * A key from the ZIPLOGGER_API_KEY constant or environment variable wins over the stored one, so
 * deployments can keep the secret out of the database entirely.
 */
final class Settings {

	const OPTION     = 'ziplogger_settings';
	const KEY_OPTION = 'ziplogger_api_key';

	/**
	 * The three credentials, each with a constant, an environment variable and a stored option.
	 *
	 * The credentials:
	 *  server   private ingestion key: PHP sends logs, events and traces with it. Never leaves the server.
	 *  browser  ingestion-only key that is delivered to every visitor's browser. Public by design.
	 *  read     key with the read scope, used by the admin dashboard on the server. Never in a page.
	 */
	const KEY_KINDS = array(
		'server'  => array(
			'constant' => 'ZIPLOGGER_API_KEY',
			'env'      => 'ZIPLOGGER_API_KEY',
			'option'   => 'ziplogger_api_key',
		),
		'browser' => array(
			'constant' => 'ZIPLOGGER_BROWSER_KEY',
			'env'      => 'ZIPLOGGER_BROWSER_KEY',
			'option'   => 'ziplogger_browser_key',
		),
		'read'    => array(
			'constant' => 'ZIPLOGGER_READ_KEY',
			'env'      => 'ZIPLOGGER_READ_KEY',
			'option'   => 'ziplogger_read_key',
		),
	);

	const COLLECTORS = array(
		'php_errors',
		'failed_logins',
		'plugin_theme',
		'updates',
		'http_failures',
		'http_slow',
	);

	const ENVIRONMENTS = array( 'production', 'staging', 'development', 'local' );

	/**
	 * Per-request cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Per-request memo of endpoint validation results, keyed by the configured string.
	 *
	 * @var array<string,array>
	 */
	private static $endpoint_checks = array();

	/**
	 * Defaults. Collection is off and every noisy collector is off.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array_merge(
			array(
				'enabled'             => false,
				'endpoint'            => '',
				'source'              => 'wordpress',
				'environment'         => '',
				'min_severity'        => 'warn',
				'collectors'          => array(
					'php_errors'    => true,
					'failed_logins' => false,
					'plugin_theme'  => true,
					'updates'       => true,
					'http_failures' => false,
					'http_slow'     => false,
				),
				'slow_http_seconds'   => 5,
				'delete_on_uninstall' => false,
			),
			Settings_Schema::defaults()
		);
	}

	/**
	 * The effective settings.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::normalize( is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Drop the per-request cache (after a write, or in tests).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache           = null;
		self::$endpoint_checks = array();
	}

	/**
	 * Merge stored values over the defaults and coerce every type. Never fails.
	 *
	 * @param array $in Stored or submitted values.
	 * @return array
	 */
	public static function normalize( array $in ) {
		$d   = self::defaults();
		$out = $d;

		$out['enabled']             = ! empty( $in['enabled'] );
		$out['endpoint']            = isset( $in['endpoint'] ) && is_string( $in['endpoint'] ) ? trim( $in['endpoint'] ) : '';
		$out['source']              = self::clean_label( isset( $in['source'] ) ? $in['source'] : '', $d['source'] );
		$out['environment']         = isset( $in['environment'] ) && is_string( $in['environment'] ) ? self::clean_environment( $in['environment'] ) : '';
		$out['min_severity']        = isset( $in['min_severity'] ) ? Severity::normalize( $in['min_severity'], $d['min_severity'] ) : $d['min_severity'];
		$out['slow_http_seconds']   = isset( $in['slow_http_seconds'] ) ? max( 1, min( 60, (int) $in['slow_http_seconds'] ) ) : $d['slow_http_seconds'];
		$out['delete_on_uninstall'] = ! empty( $in['delete_on_uninstall'] );

		$collectors = isset( $in['collectors'] ) && is_array( $in['collectors'] ) ? $in['collectors'] : array();
		foreach ( self::COLLECTORS as $name ) {
			$out['collectors'][ $name ] = array_key_exists( $name, $collectors ) ? ! empty( $collectors[ $name ] ) : $d['collectors'][ $name ];
		}
		return array_merge( $out, Settings_Schema::normalize( $in ) );
	}

	/**
	 * Whether a module (browser, analytics, replay, tracing, woocommerce) is switched on.
	 *
	 * @param string $module Module name.
	 * @return bool
	 */
	public static function module_enabled( $module ) {
		$s = self::get();
		return ! empty( $s[ $module ]['enabled'] );
	}

	/**
	 * Whether collection is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$s = self::get();
		return ! empty( $s['enabled'] );
	}

	/**
	 * Whether one collector is on (and collection as a whole is on).
	 *
	 * @param string $name Collector key.
	 * @return bool
	 */
	public static function collector_enabled( $name ) {
		$s = self::get();
		return ! empty( $s['enabled'] ) && ! empty( $s['collectors'][ $name ] );
	}

	/**
	 * Validate submitted form values against the current settings. Invalid fields keep their previous
	 * value and produce an error; valid ones are applied.
	 *
	 * @param array    $post     Unslashed submitted values.
	 * @param array    $current  Current normalized settings.
	 * @param string[] $sections Which groups of fields this submission carries: 'connection' (label, environment,
	 *                           endpoint, destination policy), 'logs' (switch, severity, collectors) and 'basics'
	 *                           (uninstall policy). A checkbox that is absent means "off" only inside a group that
	 *                           was submitted, so saving one tab never resets another.
	 * @return array{settings:array,errors:string[],notices:string[]}
	 */
	public static function validate_submission( array $post, array $current, array $sections = array( 'connection', 'logs', 'basics' ) ) {
		$errors  = array();
		$notices = array();
		$new     = $current;
		$do      = static function ( $group ) use ( $sections ) {
			return in_array( $group, $sections, true );
		};

		if ( $do( 'logs' ) ) {
			$new['enabled'] = ! empty( $post['enabled'] );
		}
		if ( $do( 'basics' ) ) {
			$new['delete_on_uninstall'] = ! empty( $post['delete_on_uninstall'] );
		}

		if ( $do( 'connection' ) && isset( $post['source'] ) ) {
			$label = self::clean_label( $post['source'], '' );
			if ( '' === $label ) {
				$errors[] = __( 'The site label may only contain letters, numbers, dots, dashes and underscores (up to 64 characters). The previous value was kept.', 'ziplogger' );
			} else {
				$new['source'] = $label;
			}
		}

		if ( $do( 'connection' ) && isset( $post['environment'] ) ) {
			$env = is_string( $post['environment'] ) ? trim( $post['environment'] ) : '';
			if ( '' === $env ) {
				$new['environment'] = '';
			} elseif ( '' === self::clean_environment( $env ) ) {
				$errors[] = __( 'The environment name may only contain lowercase letters, numbers, dots, dashes and underscores. The previous value was kept.', 'ziplogger' );
			} else {
				$new['environment'] = self::clean_environment( $env );
			}
		}

		if ( $do( 'logs' ) && isset( $post['min_severity'] ) ) {
			if ( Severity::is_valid( $post['min_severity'] ) ) {
				$new['min_severity'] = $post['min_severity'];
			} else {
				$errors[] = __( 'Unknown minimum severity. The previous value was kept.', 'ziplogger' );
			}
		}

		if ( $do( 'logs' ) && isset( $post['slow_http_seconds'] ) ) {
			$secs = (int) $post['slow_http_seconds'];
			if ( $secs < 1 || $secs > 60 ) {
				$errors[] = __( 'The slow-request threshold must be between 1 and 60 seconds. The previous value was kept.', 'ziplogger' );
			} else {
				$new['slow_http_seconds'] = $secs;
			}
		}

		if ( $do( 'logs' ) ) {
			$posted = isset( $post['collectors'] ) && is_array( $post['collectors'] ) ? $post['collectors'] : array();
			foreach ( self::COLLECTORS as $name ) {
				$new['collectors'][ $name ] = ! empty( $posted[ $name ] );
			}
		}

		if ( $do( 'connection' ) && isset( $post['on_destination_change'] ) && in_array( $post['on_destination_change'], array( 'hold', 'retarget', 'discard' ), true ) ) {
			$new['on_destination_change'] = $post['on_destination_change'];
		}

		if ( $do( 'connection' ) && isset( $post['endpoint'] ) ) {
			$raw = is_string( $post['endpoint'] ) ? trim( $post['endpoint'] ) : '';
			if ( '' === $raw ) {
				$new['endpoint'] = '';
			} else {
				$checked = Endpoint::validate( $raw, array( 'resolve' => true ) );
				if ( $checked['ok'] ) {
					// Storing the default as "empty" keeps upgrades on the default automatic.
					$new['endpoint'] = Endpoint::same_base( $checked['base'], Endpoint::DEFAULT_BASE ) ? '' : $checked['base'];
				} else {
					/* translators: %s: reason the endpoint was rejected. */
					$errors[] = sprintf( __( 'Endpoint not saved: %s The previous value was kept.', 'ziplogger' ), $checked['error'] );
				}
			}
		}

		return array(
			'settings' => self::normalize( $new ),
			'errors'   => $errors,
			'notices'  => $notices,
		);
	}

	/**
	 * Persist settings.
	 *
	 * @param array $settings Normalized settings.
	 * @return void
	 */
	public static function save( array $settings ) {
		$clean = self::normalize( $settings );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $clean, '', 'yes' );
		} else {
			update_option( self::OPTION, $clean );
		}
		self::reset_cache();
	}

	/**
	 * Store a new API key. Returns an error string, or '' on success.
	 *
	 * @param string $key Candidate key.
	 * @return string
	 */
	public static function save_api_key( $key ) {
		return self::save_key( 'server', $key );
	}

	/**
	 * Store a credential of one kind. Returns an error string, or '' on success. Two different kinds
	 * may never hold the same key: the browser key is public by design and the read key can read data,
	 * so neither may be the private server key.
	 *
	 * @param string $kind  server, browser or read.
	 * @param string $key   Candidate key.
	 * @return string
	 */
	public static function save_key( $kind, $key ) {
		if ( ! isset( self::KEY_KINDS[ $kind ] ) ) {
			return __( 'Unknown credential type.', 'ziplogger' );
		}
		$key = trim( (string) $key );
		if ( ! self::is_valid_key( $key ) ) {
			return __( 'That does not look like a ZipLogger API key. Keys contain only letters, numbers and _ - . ~ + / = (8 to 256 characters). The saved key was not changed.', 'ziplogger' );
		}
		foreach ( array_keys( self::KEY_KINDS ) as $other ) {
			if ( $other !== $kind && self::raw_key( $other ) === $key ) {
				/* translators: %s: the other credential's name (server, browser or read). */
				return sprintf( __( 'That key is already used as the %s key. Create a separate key in ZipLogger so each one can be revoked on its own. The saved key was not changed.', 'ziplogger' ), $other );
			}
		}
		$option = self::KEY_KINDS[ $kind ]['option'];
		if ( false === get_option( $option, false ) ) {
			add_option( $option, $key, '', 'no' ); // Not autoloaded: it must not sit in the alloptions cache.
		} else {
			update_option( $option, $key, false );
		}
		return '';
	}

	/**
	 * Remove the stored API key.
	 *
	 * @return void
	 */
	public static function remove_api_key() {
		self::remove_key( 'server' );
	}

	/**
	 * Remove a stored credential.
	 *
	 * @param string $kind server, browser or read.
	 * @return void
	 */
	public static function remove_key( $kind ) {
		if ( isset( self::KEY_KINDS[ $kind ] ) ) {
			delete_option( self::KEY_KINDS[ $kind ]['option'] );
		}
	}

	/**
	 * Header-safe key check: no whitespace or control characters can reach a request header.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_\-.~+\/=]{8,256}$/', $key );
	}

	/**
	 * The effective API key: constant, then environment, then the stored option. '' when none or invalid.
	 *
	 * @return string
	 */
	public static function api_key() {
		return self::key( 'server' );
	}

	/**
	 * Where the effective server key comes from: constant, environment, saved, invalid or none.
	 *
	 * @return string
	 */
	public static function api_key_source() {
		return self::key_source( 'server' );
	}

	/**
	 * A masked rendering of the server key ("zk_••••••a1b2") for the admin screen.
	 *
	 * @return string
	 */
	public static function api_key_hint() {
		return self::key_hint( 'server' );
	}

	/**
	 * The public ingestion key for browser code. '' when none is set, when it is invalid, or when it is
	 * identical to the server or read key: the browser key is delivered to every visitor, so it must
	 * never be a key that can do more.
	 *
	 * @return string
	 */
	public static function browser_key() {
		return '' === self::key_problem( 'browser' ) ? self::key( 'browser' ) : '';
	}

	/**
	 * The server-side read key. '' when none, invalid, or identical to another credential.
	 *
	 * @return string
	 */
	public static function read_key() {
		return '' === self::key_problem( 'read' ) ? self::key( 'read' ) : '';
	}

	/**
	 * The effective key of one kind: constant, then environment, then the stored option. '' when none or invalid.
	 *
	 * @param string $kind server, browser or read.
	 * @return string
	 */
	public static function key( $kind ) {
		$key = self::raw_key( $kind );
		return self::is_valid_key( $key ) ? $key : '';
	}

	/**
	 * Why a key of this kind cannot be used ('' when it can, or when none is set).
	 *
	 * @param string $kind server, browser or read.
	 * @return string
	 */
	public static function key_problem( $kind ) {
		$key = self::key( $kind );
		if ( '' === $key ) {
			return 'invalid' === self::key_source( $kind ) ? __( 'The configured key is not valid.', 'ziplogger' ) : '';
		}
		foreach ( array_keys( self::KEY_KINDS ) as $other ) {
			if ( $other !== $kind && self::key( $other ) === $key ) {
				/* translators: %s: the other credential's name. */
				return sprintf( __( 'This key is identical to the %s key. Use a separate key for each purpose.', 'ziplogger' ), $other );
			}
		}
		return '';
	}

	/**
	 * Where a key comes from: constant, environment, saved, invalid or none.
	 *
	 * @param string $kind server, browser or read.
	 * @return string
	 */
	public static function key_source( $kind ) {
		if ( ! isset( self::KEY_KINDS[ $kind ] ) ) {
			return 'none';
		}
		$spec     = self::KEY_KINDS[ $kind ];
		$constant = self::string_constant( $spec['constant'] );
		if ( '' !== $constant ) {
			return self::is_valid_key( $constant ) ? 'constant' : 'invalid';
		}
		$env = self::env_value( $spec['env'] );
		if ( '' !== $env ) {
			return self::is_valid_key( $env ) ? 'environment' : 'invalid';
		}
		$saved = get_option( $spec['option'], '' );
		if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
			return 'none';
		}
		return self::is_valid_key( trim( $saved ) ) ? 'saved' : 'invalid';
	}

	/**
	 * A masked rendering of a key ("zk_••••••a1b2") for the admin screen. '' when there is no key.
	 *
	 * @param string $kind server, browser or read.
	 * @return string
	 */
	public static function key_hint( $kind ) {
		$key = self::key( $kind );
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) < 12 ) {
			return '••••••••';
		}
		$prefix = 0 === strpos( $key, 'zk_' ) ? 'zk_' : '';
		return $prefix . '••••••••' . substr( $key, -4 );
	}

	/**
	 * The endpoint base URL in force, validated. Falls back to the default when the configured value
	 * is invalid (and reports that through endpoint_problem()).
	 *
	 * @return string
	 */
	public static function endpoint_base() {
		$configured = self::configured_endpoint();
		if ( '' === $configured ) {
			return Endpoint::DEFAULT_BASE;
		}
		$checked = self::checked_endpoint( $configured );
		return $checked['ok'] ? $checked['base'] : '';
	}

	/**
	 * The endpoint base URL for code that runs in a visitor's browser. Same rules as endpoint_base()
	 * (HTTPS, no credentials, valid public-looking host) but without any DNS lookup: this is called while
	 * rendering pages, and a request forgery check has no meaning for a request the visitor's own browser
	 * makes. '' when the endpoint is unusable.
	 *
	 * @return string
	 */
	public static function browser_endpoint() {
		$configured = self::configured_endpoint();
		if ( '' === $configured ) {
			return Endpoint::DEFAULT_BASE;
		}
		$key = 'browser|' . $configured;
		if ( ! isset( self::$endpoint_checks[ $key ] ) ) {
			self::$endpoint_checks[ $key ] = Endpoint::validate( $configured, array( 'dns' => false ) );
		}
		return self::$endpoint_checks[ $key ]['ok'] ? self::$endpoint_checks[ $key ]['base'] : '';
	}

	/**
	 * Host name of the configured endpoint, without validating it or touching DNS. For cheap
	 * comparisons (for example "is this request to ZipLogger itself?").
	 *
	 * @return string
	 */
	public static function endpoint_host() {
		$configured = self::configured_endpoint();
		$host       = wp_parse_url( '' === $configured ? Endpoint::DEFAULT_BASE : $configured, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Validate the configured endpoint once per request and per value (validation may resolve DNS).
	 *
	 * @param string $configured Configured endpoint.
	 * @return array Result of Endpoint::validate().
	 */
	private static function checked_endpoint( $configured ) {
		if ( ! isset( self::$endpoint_checks[ $configured ] ) ) {
			self::$endpoint_checks[ $configured ] = Endpoint::validate( $configured );
		}
		return self::$endpoint_checks[ $configured ];
	}

	/**
	 * Why the configured endpoint cannot be used, or '' when it is fine.
	 *
	 * @return string
	 */
	public static function endpoint_problem() {
		$configured = self::configured_endpoint();
		if ( '' === $configured ) {
			return '';
		}
		$checked = self::checked_endpoint( $configured );
		return $checked['ok'] ? '' : $checked['error'];
	}

	/**
	 * Where the endpoint comes from: constant, saved, default.
	 *
	 * @return string
	 */
	public static function endpoint_source() {
		if ( '' !== self::string_constant( 'ZIPLOGGER_ENDPOINT' ) ) {
			return 'constant';
		}
		$s = self::get();
		return '' !== $s['endpoint'] ? 'saved' : 'default';
	}

	/**
	 * The environment label: the setting, else WordPress's own environment type.
	 *
	 * @return string
	 */
	public static function environment() {
		$s = self::get();
		if ( '' !== $s['environment'] ) {
			return $s['environment'];
		}
		$type = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return is_string( $type ) && '' !== $type ? $type : 'production';
	}

	/**
	 * Whether delivery could run right now (a valid key and a usable endpoint).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key() && '' !== self::endpoint_base();
	}

	/**
	 * Sanitize a site/source label.
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value when empty or invalid.
	 * @return string
	 */
	public static function clean_label( $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$v = trim( $value );
		if ( '' === $v ) {
			return $fallback;
		}
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,63}$/', $v ) ? $v : $fallback;
	}

	/**
	 * Sanitize an environment name (lower-case token). '' when invalid.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_environment( $value ) {
		$v = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '/^[a-z0-9][a-z0-9._\-]{0,31}$/', $v ) ? $v : '';
	}

	/**
	 * The configured endpoint string: constant wins over the option.
	 *
	 * @return string
	 */
	private static function configured_endpoint() {
		$constant = self::string_constant( 'ZIPLOGGER_ENDPOINT' );
		if ( '' !== $constant ) {
			return $constant;
		}
		$s = self::get();
		return $s['endpoint'];
	}

	/**
	 * Raw key of one kind by precedence (constant, environment, option), unvalidated.
	 *
	 * @param string $kind server, browser or read.
	 * @return string
	 */
	private static function raw_key( $kind ) {
		if ( ! isset( self::KEY_KINDS[ $kind ] ) ) {
			return '';
		}
		$spec     = self::KEY_KINDS[ $kind ];
		$constant = self::string_constant( $spec['constant'] );
		if ( '' !== $constant ) {
			return $constant;
		}
		$env = self::env_value( $spec['env'] );
		if ( '' !== $env ) {
			return $env;
		}
		$saved = get_option( $spec['option'], '' );
		return is_string( $saved ) ? trim( $saved ) : '';
	}

	/**
	 * Overrides for constants, so tests can exercise wp-config.php settings without defining real
	 * constants (which cannot be undone). Empty in production.
	 *
	 * @var array<string,mixed>
	 */
	public static $constants = array();

	/**
	 * A constant's value, or null when it is not defined.
	 *
	 * @param string $name Constant name.
	 * @return mixed
	 */
	public static function constant( $name ) {
		if ( array_key_exists( $name, self::$constants ) ) {
			return self::$constants[ $name ];
		}
		return defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * A constant's value as a trimmed string ('' when undefined or not a string).
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private static function string_constant( $name ) {
		$value = self::constant( $name );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * An environment variable's value, trimmed ('' when unset).
	 *
	 * @param string $name Variable name.
	 * @return string
	 */
	private static function env_value( $name ) {
		$env = getenv( $name );
		return is_string( $env ) ? trim( $env ) : '';
	}
}
