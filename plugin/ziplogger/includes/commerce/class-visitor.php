<?php
/**
 * Who an order belongs to, for analytics: only what the visitor agreed to, and never personal data.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Commerce;

use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Order and payment events are sent by the server, often from a gateway's webhook, where there is no
 * visitor in the request. To connect them to the person's browsing (which browser-side analytics knows
 * under an anonymous id) the browser script leaves a first-party cookie ("ziplogger_v": anonymous id and
 * session id, both random) while the visitor has agreed to analytics and is shopping. When the order is
 * created, this class copies those two ids into a private order field, so a webhook that arrives hours later
 * can still be attributed.
 *
 * Rules:
 *  - ids are stored only when analytics consent is evident in THAT request (the same evidence the browser
 *    used: the WordPress Consent API, a consent manager filter, or this plugin's own consent cookie);
 *  - a signed-in customer additionally gets the keyed-hash pseudonym of their user id, never the id;
 *  - without consent nothing about the visitor is stored, and the event's only identity is an opaque
 *    reference of the order itself (one "visitor" per order, so counts and revenue stay right and nobody
 *    is followed);
 *  - names, addresses, emails, phone numbers and order keys are never read.
 */
final class Visitor {

	const COOKIE = 'ziplogger_v';
	const META   = '_ziplogger_identity';

	/**
	 * The ids in the request's cookie when they are well formed, else null.
	 *
	 * @return array{a:string,s:string}|null
	 */
	public static function from_cookie() {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads the visitor's own cookie; nothing is changed by this request.
			return null;
		}
		$raw = wp_unslash( $_COOKIE[ self::COOKIE ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		if ( 1 !== preg_match( '/^(anon_[0-9a-f]{20})?\.(sess_[0-9a-f]{20})?$/D', $raw, $m ) ) {
			return null;
		}
		$anon = isset( $m[1] ) ? $m[1] : '';
		$sess = isset( $m[2] ) ? $m[2] : '';
		return ( '' === $anon && '' === $sess ) ? null : array(
			'a' => $anon,
			's' => $sess,
		);
	}

	/**
	 * The identity of the person making THIS request, when they agreed to analytics: the ids from the browser
	 * script's cookie, and the pseudonym of the signed-in user. Empty without consent.
	 *
	 * @return array{userId?:string,anonymousId?:string,sessionId?:string}
	 */
	public static function for_request() {
		if ( ! Consent::allows( 'analytics' ) ) {
			return array();
		}
		$out = array();
		$ids = self::from_cookie();
		if ( null !== $ids ) {
			if ( '' !== $ids['a'] ) {
				$out['anonymousId'] = $ids['a'];
			}
			if ( '' !== $ids['s'] ) {
				$out['sessionId'] = $ids['s'];
			}
		}
		if ( is_user_logged_in() && $out ) {
			$out['userId'] = Secrets::pseudonym( 'wpu', get_current_user_id() );
		}
		return $out;
	}

	/**
	 * Remember who this order belongs to, if the visitor agreed to it. Idempotent: the first capture wins.
	 *
	 * @param \WC_Order $order Order (not necessarily saved yet).
	 * @return void
	 */
	public static function capture( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META, true ) ) {
			return;
		}
		$data = array();

		// "Commerce" consent (default: not required). When it IS required, the order remembers that the
		// customer agreed, because the events about it are sent later, from webhooks with no visitor.
		if ( 'required' === Consent::policy( 'commerce' ) && Consent::allows( 'commerce' ) ) {
			$data['k'] = 1;
		}

		// Linking the order to the visitor's browsing needs analytics consent.
		if ( Consent::allows( 'analytics' ) ) {
			$ids = self::from_cookie();
			if ( null !== $ids ) {
				if ( '' !== $ids['a'] ) {
					$data['a'] = $ids['a'];
				}
				if ( '' !== $ids['s'] ) {
					$data['s'] = $ids['s'];
				}
				$customer = (int) $order->get_customer_id();
				if ( $customer > 0 ) {
					// Only linked to a person when the browser side is linked too: consent covers both.
					$data['u'] = Secrets::pseudonym( 'wpu', $customer );
				}
			}
		}
		if ( $data ) {
			$order->update_meta_data( self::META, wp_json_encode( $data ) );
		}
	}

	/**
	 * The identity to put on an event about this order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{userId?:string,anonymousId:string,sessionId?:string}
	 */
	public static function for_order( $order ) {
		$identity = array();
		$stored   = json_decode( (string) $order->get_meta( self::META, true ), true );
		if ( is_array( $stored ) ) {
			if ( isset( $stored['u'] ) && is_string( $stored['u'] ) && 1 === preg_match( '/^wpu_[0-9a-f]{24}$/', $stored['u'] ) ) {
				$identity['userId'] = $stored['u'];
			}
			if ( isset( $stored['a'] ) && is_string( $stored['a'] ) && 1 === preg_match( '/^anon_[0-9a-f]{20}$/', $stored['a'] ) ) {
				$identity['anonymousId'] = $stored['a'];
			}
			if ( isset( $stored['s'] ) && is_string( $stored['s'] ) && 1 === preg_match( '/^sess_[0-9a-f]{20}$/', $stored['s'] ) ) {
				$identity['sessionId'] = $stored['s'];
			}
		}
		if ( ! isset( $identity['anonymousId'] ) && ! isset( $identity['userId'] ) ) {
			$identity['anonymousId'] = self::order_ref( $order );
		}
		return $identity;
	}

	/**
	 * An opaque reference for an order: a keyed hash of its id. Stable, so all events of one order can be
	 * connected; not reversible or guessable without the site's secret, so it reveals neither the order
	 * number (and therefore how many orders the shop has) nor anything else.
	 *
	 * @param \WC_Order $order Order.
	 * @return string "ord_" and 24 hex characters.
	 */
	public static function order_ref( $order ) {
		return Secrets::pseudonym( 'ord', (int) $order->get_id() );
	}
}
