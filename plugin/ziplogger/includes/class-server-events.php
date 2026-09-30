<?php
/**
 * Product-analytics events sent from the server (order and payment outcomes).
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one event in the shape POST /ingest/v1/events accepts and puts it on the local queue (signal
 * "events"), from where the normal bounded, retrying delivery sends it.
 *
 * The platform rejects an event that names neither a user nor an anonymous visitor, so the caller must
 * supply an identity; this class refuses to queue one without. Every event carries an "insertId": the
 * platform stores an event under an id derived from it, so sending the same event twice (a repeated
 * webhook, a retried batch) lands on the same record instead of counting twice.
 *
 * Properties pass through the redactor (a safety net: callers choose their property names carefully) and
 * are flat: scalars and short lists of scalars.
 */
final class Server_Events {

	/**
	 * Queue one event.
	 *
	 * @param string $name       Event name (lower case, [a-z0-9_.:]).
	 * @param array  $properties Flat properties.
	 * @param array  $identity   userId, anonymousId, sessionId (at least one of the first two).
	 * @param string $insert_id  Deterministic id of this logical event.
	 * @param bool   $important  Whether it should survive when the queue is nearly full (revenue events).
	 * @return bool True when queued.
	 */
	public static function emit( $name, array $properties, array $identity, $insert_id, $important = false ) {
		try {
			if ( ! Settings::is_enabled() && ! Modules::effective( 'woocommerce' ) ) {
				return false;
			}
			$name = self::clean_name( $name );
			$user = self::id( isset( $identity['userId'] ) ? $identity['userId'] : '' );
			$anon = self::id( isset( $identity['anonymousId'] ) ? $identity['anonymousId'] : '' );
			$sess = self::id( isset( $identity['sessionId'] ) ? $identity['sessionId'] : '' );
			$key  = self::id( $insert_id );
			if ( '' === $name || ( '' === $user && '' === $anon ) || '' === $key ) {
				return false;
			}

			$settings = Settings::get();
			$redactor = Redactor::from_filters( array( 'secrets' => array( Settings::api_key() ) ) );
			$event    = array(
				'type'        => 'track',
				'name'        => $name,
				'timestamp'   => Clock::iso(),
				'service'     => $settings['source'],
				'environment' => Settings::environment(),
				'insertId'    => $key,
			);
			if ( '' !== $user ) {
				$event['userId'] = $user;
			}
			if ( '' !== $anon ) {
				$event['anonymousId'] = $anon;
			}
			if ( '' !== $sess ) {
				$event['sessionId'] = $sess;
			}
			$release = Event_Factory::release_value();
			if ( '' !== $release ) {
				$event['release'] = $release;
			}
			$commit = Event_Factory::commit_sha_value();
			if ( '' !== $commit ) {
				$event['commitSha'] = $commit;
			}
			$props = $redactor->value( $properties );
			if ( is_array( $props ) && $props ) {
				$event['properties'] = $props;
			}

			$ids = Tracing\Tracer::ids();
			if ( null !== $ids ) {
				$event['requestId'] = $ids['traceId']; // Links the event to the trace of the request that produced it.
			}

			/**
			 * Filters an event before it is queued. Return false to drop it.
			 *
			 * @param array|false $event Event (the wire shape).
			 */
			$event = apply_filters( 'ziplogger_server_event', $event );
			if ( ! is_array( $event ) ) {
				return false;
			}

			$json = wp_json_encode( $event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) || strlen( $json ) > (int) Limits::get( 'max_event_bytes' ) ) {
				return false;
			}
			$written = ( new Queue_Writer() )->write(
				Signal::EVENTS,
				array(
					array(
						'payload'     => $json,
						'size'        => strlen( $json ),
						'severity'    => Severity::rank( $important ? 'warn' : 'info' ),
						'fingerprint' => '',
					),
				)
			);
			if ( $written > 0 ) {
				// Local counters for the dashboard: what was handed to the delivery queue, per event name.
				$meta = new Meta_Store();
				$meta->incr( 'ev:' . substr( $name, 0, 40 ) );
				$meta->set_num( 'ev_last', Clock::time() );
			}
			return $written > 0;
		} catch ( \Throwable $e ) {
			return false; // Telemetry must never break the shop.
		}
	}

	/**
	 * Event names: lower case, letters, digits and _ . :, at most 120 characters.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_.:]+/', '_', $name );
		return substr( trim( (string) $name, '_' ), 0, 120 );
	}

	/**
	 * Identifiers: printable ASCII without spaces, at most 200 characters (the platform's own limit).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function id( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9_.:\-]{1,200}$/', $value ) ? $value : '';
	}
}
