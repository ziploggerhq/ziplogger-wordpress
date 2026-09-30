<?php
/**
 * Defaults, normalization and validation for the module settings.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One nested array per module. Every module is OFF by default: activating the plugin, or enabling one
 * module, never turns another one on. Normalization never fails (it coerces); validation of a
 * submitted form reports problems and keeps the previous value for anything invalid.
 */
final class Settings_Schema {

	const MODULES = array( 'browser', 'analytics', 'replay', 'tracing', 'woocommerce', 'consent' );

	/**
	 * Consent categories the plugin gates on. "browser" covers browser errors and performance, which
	 * work without any persistent identifier; the others create or use identifiers.
	 */
	const CONSENT_CATEGORIES = array( 'browser', 'analytics', 'replay', 'commerce' );

	/**
	 * Default values of every module.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'browser'               => array(
				'enabled'           => false,
				'errors'            => true,
				'failed_requests'   => true,
				'slow_requests'     => false,
				'slow_threshold_ms' => 3000,
				'navigation_timing' => false,
				'web_vitals'        => false,
				'perf_sample_rate'  => 10,
				'error_sample_rate' => 100,
				'max_errors_page'   => 20,
			),
			'analytics'             => array(
				'enabled'               => false,
				'page_views'            => true,
				'spa_navigation'        => true,
				'interactions'          => false,
				'interaction_selectors' => '',
				'identify'              => false,
				'sample_rate'           => 100,
			),
			'replay'                => array(
				'enabled'        => false,
				'sample_rate'    => 5,
				'mask_all_text'  => true,
				'block_selector' => '',
				'mask_selector'  => '',
				'exclude_paths'  => '',
				'exclude_roles'  => array( 'administrator', 'editor' ),
				'max_minutes'    => 30,
				'max_megabytes'  => 20,
			),
			'tracing'               => array(
				'enabled'         => false,
				'server_spans'    => true,
				'outbound_spans'  => true,
				'sample_rate'     => 10,
				'propagate_hosts' => '',
				'service_name'    => '',
				'record_path'     => false,
				'db_query_count'  => true,
				'db_timing'       => false,
				'browser'         => false,
			),
			'woocommerce'           => array(
				'enabled'            => false,
				'product_views'      => true,
				'cart_events'        => true,
				'checkout'           => true,
				'orders'             => true,
				'payments'           => true,
				'refunds'            => true,
				'status_changes'     => true,
				'product_identifier' => 'id',
				'include_items'      => true,
			),
			'consent'               => array(
				'browser'        => 'none',
				'analytics'      => 'required',
				'replay'         => 'required',
				'commerce'       => 'none',
				'wp_consent_api' => true,
			),
			'on_destination_change' => 'hold',
		);
	}

	/**
	 * Merge stored module values over the defaults and coerce every type. Never fails.
	 *
	 * @param array $in Stored or submitted values.
	 * @return array Module settings only.
	 */
	public static function normalize( array $in ) {
		$d   = self::defaults();
		$out = $d;

		foreach ( self::MODULES as $module ) {
			$given = isset( $in[ $module ] ) && is_array( $in[ $module ] ) ? $in[ $module ] : array();
			foreach ( $d[ $module ] as $key => $fallback ) {
				$out[ $module ][ $key ] = array_key_exists( $key, $given ) ? self::coerce( $module, $key, $given[ $key ], $fallback ) : $fallback;
			}
		}
		$out['on_destination_change'] = isset( $in['on_destination_change'] ) && in_array( $in['on_destination_change'], array( 'hold', 'retarget', 'discard' ), true ) ? $in['on_destination_change'] : 'hold';
		return $out;
	}

	/**
	 * Coerce one value to the type and range of its default.
	 *
	 * @param string $module  Module.
	 * @param string $key     Key.
	 * @param mixed  $value   Raw.
	 * @param mixed  $fallback Default (its type decides).
	 * @return mixed
	 */
	private static function coerce( $module, $key, $value, $fallback ) {
		if ( 'consent' === $module && in_array( $key, self::CONSENT_CATEGORIES, true ) ) {
			return 'none' === $value ? 'none' : 'required';
		}
		if ( 'product_identifier' === $key ) {
			return in_array( $value, array( 'id', 'sku', 'none' ), true ) ? $value : 'id';
		}
		if ( 'exclude_roles' === $key ) {
			return self::roles( is_array( $value ) ? $value : array() );
		}
		if ( is_bool( $fallback ) ) {
			return ! empty( $value );
		}
		if ( is_int( $fallback ) ) {
			return self::int_in_range( $module, $key, $value, $fallback );
		}
		if ( in_array( $key, array( 'interaction_selectors', 'block_selector', 'mask_selector' ), true ) ) {
			return self::selectors( (string) $value )['clean'];
		}
		if ( in_array( $key, array( 'exclude_paths' ), true ) ) {
			return self::path_patterns( (string) $value )['clean'];
		}
		if ( 'propagate_hosts' === $key ) {
			return self::hosts( (string) $value )['clean'];
		}
		if ( 'service_name' === $key ) {
			return Settings::clean_label( $value, '' );
		}
		return is_string( $value ) ? trim( $value ) : $fallback;
	}

	/**
	 * Numeric ranges.
	 *
	 * @param string $module  Module.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @param int    $fallback Default.
	 * @return int
	 */
	private static function int_in_range( $module, $key, $value, $fallback ) {
		$ranges = array(
			'slow_threshold_ms' => array( 200, 60000 ),
			'perf_sample_rate'  => array( 0, 100 ),
			'error_sample_rate' => array( 0, 100 ),
			'max_errors_page'   => array( 1, 200 ),
			'sample_rate'       => array( 0, 100 ),
			'max_minutes'       => array( 1, 60 ),
			'max_megabytes'     => array( 1, 50 ),
		);
		$range  = isset( $ranges[ $key ] ) ? $ranges[ $key ] : array( 0, PHP_INT_MAX );
		return is_numeric( $value ) ? (int) max( $range[0], min( $range[1], (int) $value ) ) : $fallback;
	}

	/**
	 * Keep only roles that exist.
	 *
	 * @param array $roles Role slugs.
	 * @return string[]
	 */
	public static function roles( array $roles ) {
		$known = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : array();
		$out   = array();
		foreach ( $roles as $role ) {
			$role = is_string( $role ) ? sanitize_key( $role ) : '';
			if ( '' !== $role && ( ! $known || in_array( $role, $known, true ) ) && ! in_array( $role, $out, true ) ) {
				$out[] = $role;
			}
		}
		return $out;
	}

	/**
	 * Validate a list of CSS selectors, one per line.
	 *
	 * @param string $text Text.
	 * @return array{clean:string,rejected:string[]}
	 */
	public static function selectors( $text ) {
		return self::lines(
			$text,
			20,
			static function ( $line ) {
				return strlen( $line ) <= 200 && 1 === preg_match( '/^[A-Za-z0-9_\-\s.#\[\]="\':>+~*,()^$|]+$/', $line ) && false === strpos( $line, 'url(' );
			}
		);
	}

	/**
	 * Validate URL path patterns, one per line: a leading slash, optional * wildcards.
	 *
	 * @param string $text Text.
	 * @return array{clean:string,rejected:string[]}
	 */
	public static function path_patterns( $text ) {
		return self::lines(
			$text,
			50,
			static function ( $line ) {
				return strlen( $line ) <= 200 && 1 === preg_match( '#^/[A-Za-z0-9_\-./*%~]*$#', $line );
			}
		);
	}

	/**
	 * Validate host names that may receive trace headers, one per line (exact, or "*.example.com").
	 *
	 * @param string $text Text.
	 * @return array{clean:string,rejected:string[]}
	 */
	public static function hosts( $text ) {
		return self::lines(
			strtolower( $text ),
			20,
			static function ( $line ) {
				return strlen( $line ) <= 253 && 1 === preg_match( '/^(\*\.)?([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(:\d{1,5})?$/', $line );
			}
		);
	}

	/**
	 * Split text into trimmed, de-duplicated lines and keep the ones the validator accepts.
	 *
	 * @param string   $text      Text.
	 * @param int      $max       Maximum accepted lines.
	 * @param callable $validator Returns true for an acceptable line.
	 * @return array{clean:string,rejected:string[]}
	 */
	private static function lines( $text, $max, callable $validator ) {
		$clean    = array();
		$rejected = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || in_array( $line, $clean, true ) ) {
				continue;
			}
			if ( count( $clean ) < $max && $validator( $line ) ) {
				$clean[] = $line;
			} else {
				$rejected[] = mb_substr( $line, 0, 60 );
			}
		}
		return array(
			'clean'    => implode( "\n", $clean ),
			'rejected' => $rejected,
		);
	}

	/**
	 * The fields each admin section may submit (everything else in the request is ignored).
	 *
	 * @return array<string,string[]> Section => list of module keys it owns.
	 */
	public static function section_modules() {
		return array(
			'browser'     => array( 'browser' ),
			'analytics'   => array( 'analytics' ),
			'replay'      => array( 'replay' ),
			'tracing'     => array( 'tracing' ),
			'woocommerce' => array( 'woocommerce' ),
			'privacy'     => array( 'consent' ),
		);
	}

	/**
	 * Validate the submission of one module section.
	 *
	 * @param string $module  Module (browser, analytics, replay, tracing, woocommerce, consent).
	 * @param array  $post    Unslashed submitted values for that module (the nested array).
	 * @param array  $current Current normalized module settings.
	 * @return array{values:array,errors:string[]}
	 */
	public static function validate_module( $module, array $post, array $current ) {
		$d      = self::defaults();
		$new    = $current;
		$errors = array();
		if ( ! isset( $d[ $module ] ) ) {
			return array(
				'values' => $current,
				'errors' => array(),
			);
		}

		foreach ( $d[ $module ] as $key => $fallback ) {
			if ( 'consent' === $module ) {
				if ( in_array( $key, self::CONSENT_CATEGORIES, true ) ) {
					if ( isset( $post[ $key ] ) ) {
						$new[ $key ] = 'none' === $post[ $key ] ? 'none' : 'required';
					}
				} else {
					$new[ $key ] = ! empty( $post[ $key ] );
				}
				continue;
			}
			if ( 'exclude_roles' === $key ) {
				$new[ $key ] = self::roles( isset( $post[ $key ] ) && is_array( $post[ $key ] ) ? $post[ $key ] : array() );
				continue;
			}
			if ( is_bool( $fallback ) ) {
				$new[ $key ] = ! empty( $post[ $key ] );
				continue;
			}
			if ( ! array_key_exists( $key, $post ) ) {
				continue;
			}
			$value = $post[ $key ];
			if ( is_int( $fallback ) ) {
				if ( ! is_numeric( $value ) || trim( (string) $value ) !== (string) (int) $value ) {
					/* translators: %s: setting name. */
					$errors[] = sprintf( __( '"%s" must be a whole number. The previous value was kept.', 'ziplogger' ), $key );
					continue;
				}
				$new[ $key ] = self::int_in_range( $module, $key, $value, $fallback );
				if ( (int) $value !== $new[ $key ] ) {
					/* translators: 1: setting name, 2: value that was used instead. */
					$errors[] = sprintf( __( '"%1$s" was outside the allowed range and was set to %2$d.', 'ziplogger' ), $key, $new[ $key ] );
				}
				continue;
			}
			if ( 'product_identifier' === $key ) {
				$new[ $key ] = in_array( $value, array( 'id', 'sku', 'none' ), true ) ? $value : $current[ $key ];
				continue;
			}
			if ( in_array( $key, array( 'interaction_selectors', 'block_selector', 'mask_selector' ), true ) ) {
				$r           = self::selectors( (string) $value );
				$new[ $key ] = $r['clean'];
				if ( $r['rejected'] ) {
					/* translators: %s: rejected values. */
					$errors[] = sprintf( __( 'These selectors were not accepted and were dropped: %s', 'ziplogger' ), implode( ', ', $r['rejected'] ) );
				}
				continue;
			}
			if ( 'exclude_paths' === $key ) {
				$r           = self::path_patterns( (string) $value );
				$new[ $key ] = $r['clean'];
				if ( $r['rejected'] ) {
					/* translators: %s: rejected values. */
					$errors[] = sprintf( __( 'These paths were not accepted (they must start with "/") and were dropped: %s', 'ziplogger' ), implode( ', ', $r['rejected'] ) );
				}
				continue;
			}
			if ( 'propagate_hosts' === $key ) {
				$r           = self::hosts( (string) $value );
				$new[ $key ] = $r['clean'];
				if ( $r['rejected'] ) {
					/* translators: %s: rejected values. */
					$errors[] = sprintf( __( 'These host names were not accepted and were dropped: %s', 'ziplogger' ), implode( ', ', $r['rejected'] ) );
				}
				continue;
			}
			if ( 'service_name' === $key ) {
				$label = Settings::clean_label( $value, '' );
				if ( '' === $label && '' !== trim( (string) $value ) ) {
					$errors[] = __( 'The service name may only contain letters, numbers, dots, dashes and underscores. The previous value was kept.', 'ziplogger' );
				} else {
					$new[ $key ] = $label;
				}
			}
		}
		return array(
			'values' => $new,
			'errors' => $errors,
		);
	}
}
