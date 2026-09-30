<?php
/**
 * Update outcome collector.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Collectors;

use ZipLogger\WordPress\Recorder;

defined( 'ABSPATH' ) || exit;

/**
 * Uses only documented hooks:
 *
 *  - upgrader_process_complete       success of core / plugin / theme / translation updates and installs
 *                                    (manual and automatic).
 *  - upgrader_install_package_result failure at the install stage (a WP_Error result), manual or automatic.
 *  - automatic_updates_complete      per-item failures of background updates, including download failures.
 *
 * Not covered: a manual update that fails while downloading the package (WordPress offers no
 * documented hook for that stage). Failures reported by more than one hook are collapsed by the
 * recorder's de-duplication.
 */
final class Updates {

	const MAX_ITEMS = 20;

	/**
	 * Recorder.
	 *
	 * @var Recorder
	 */
	private $recorder;

	/**
	 * Constructor.
	 *
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( Recorder $recorder ) {
		$this->recorder = $recorder;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'upgrader_process_complete', array( $this, 'on_complete' ), 10, 2 );
		add_filter( 'upgrader_install_package_result', array( $this, 'on_install_result' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'on_automatic_complete' ), 10, 1 );
	}

	/**
	 * A core / plugin / theme / translation upgrade or install finished.
	 *
	 * @param mixed $upgrader Upgrader instance (unused).
	 * @param array $extra    Hook extra: type, action, plugins / themes / translations.
	 * @return void
	 */
	public function on_complete( $upgrader = null, $extra = array() ) {
		unset( $upgrader );
		if ( ! is_array( $extra ) || empty( $extra['type'] ) ) {
			return;
		}
		$type   = preg_replace( '/[^a-z_]/', '', (string) $extra['type'] );
		$action = isset( $extra['action'] ) ? preg_replace( '/[^a-z_]/', '', (string) $extra['action'] ) : 'update';
		if ( ! in_array( $type, array( 'core', 'plugin', 'theme', 'translation' ), true ) ) {
			return;
		}
		$items = $this->items( $type, $extra );

		$this->recorder->record(
			'update_completed',
			'info',
			sprintf( 'WordPress %s %s completed', $type, $action ),
			array(
				'template' => 'WordPress {type} {action} completed',
				'fields'   => array(
					'updateType'   => $type,
					'updateAction' => $action,
					'items'        => $items,
					'result'       => 'success',
				),
				'exempt'   => true,
			)
		);
	}

	/**
	 * Failure at the install stage.
	 *
	 * @param mixed $result Result of WP_Upgrader::install_package() - passed through untouched.
	 * @param array $extra  Hook extra.
	 * @return mixed The unmodified result (this is a filter).
	 */
	public function on_install_result( $result, $extra = array() ) {
		if ( is_wp_error( $result ) ) {
			$type = is_array( $extra ) && isset( $extra['type'] ) ? preg_replace( '/[^a-z_]/', '', (string) $extra['type'] ) : 'unknown';
			$this->failure( $type, is_array( $extra ) ? $this->items( $type, $extra ) : array(), $result );
		}
		return $result;
	}

	/**
	 * Results of background updates.
	 *
	 * @param mixed $results Map of type => list of items with ->item and ->result.
	 * @return void
	 */
	public function on_automatic_complete( $results = array() ) {
		if ( ! is_array( $results ) ) {
			return;
		}
		$seen = 0;
		foreach ( $results as $type => $list ) {
			foreach ( is_array( $list ) ? $list : array() as $entry ) {
				if ( ++$seen > self::MAX_ITEMS ) {
					return;
				}
				$result = is_object( $entry ) && isset( $entry->result ) ? $entry->result : null;
				if ( ! is_wp_error( $result ) ) {
					continue;
				}
				$item = is_object( $entry ) && isset( $entry->item ) && is_object( $entry->item ) ? $entry->item : null;
				$slug = '';
				if ( null !== $item ) {
					foreach ( array( 'plugin', 'theme', 'slug', 'new_version' ) as $prop ) {
						if ( isset( $item->$prop ) && is_string( $item->$prop ) && '' !== $item->$prop ) {
							$slug = Plugin_Theme::slug( $item->$prop );
							break;
						}
					}
				}
				$this->failure( preg_replace( '/[^a-z_]/', '', (string) $type ), '' !== $slug ? array( $slug ) : array(), $result );
			}
		}
	}

	/**
	 * Record an update failure.
	 *
	 * @param string    $type  Update type.
	 * @param string[]  $items Slugs.
	 * @param \WP_Error $error Error.
	 * @return void
	 */
	private function failure( $type, array $items, \WP_Error $error ) {
		$code = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $error->get_error_code() ) );
		$this->recorder->record(
			'update_failed',
			'error',
			sprintf( 'WordPress %s update failed: %s', $type, $error->get_error_message() ),
			array(
				'template' => 'WordPress {type} update failed',
				'fields'   => array(
					'updateType' => $type,
					'items'      => $items,
					'result'     => 'failure',
					'errorCode'  => '' === $code ? 'unknown' : $code,
				),
				'dedupe'   => true,
				'fp_extra' => $type . '|' . implode( ',', $items ) . '|' . $code,
				'exempt'   => true,
			)
		);
	}

	/**
	 * Slugs of the items an update touched.
	 *
	 * @param string $type  Update type.
	 * @param array  $extra Hook extra.
	 * @return string[]
	 */
	private function items( $type, array $extra ) {
		$raw = array();
		if ( 'plugin' === $type ) {
			$raw = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			$raw = array_map( array( '\ZipLogger\WordPress\Collectors\Plugin_Theme', 'slug' ), array_filter( $raw, 'is_string' ) );
		} elseif ( 'theme' === $type ) {
			$raw = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			$raw = array_map( 'sanitize_key', array_filter( $raw, 'is_string' ) );
		} elseif ( 'translation' === $type && isset( $extra['translations'] ) && is_array( $extra['translations'] ) ) {
			foreach ( $extra['translations'] as $t ) {
				if ( is_array( $t ) && isset( $t['type'], $t['slug'] ) ) {
					$raw[] = sanitize_key( $t['type'] ) . ':' . sanitize_key( $t['slug'] );
				}
			}
		}
		return array_slice( array_values( array_unique( array_filter( $raw ) ) ), 0, self::MAX_ITEMS );
	}
}
