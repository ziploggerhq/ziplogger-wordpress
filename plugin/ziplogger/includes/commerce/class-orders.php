<?php
/**
 * WooCommerce order, payment and refund events.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Commerce;

use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Secrets;
use ZipLogger\WordPress\Server_Events;
use ZipLogger\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * These are the AUTHORITATIVE events: they come from WooCommerce's own order lifecycle on the server, not
 * from the browser. A thank-you page is never treated as proof of payment (a customer can open it, reload
 * it, or land on it after a failed payment); only the order changing state, or a gateway reporting success,
 * counts.
 *
 * Events (all flat, all carrying an opaque order reference and never a name, address, email, order key or
 * payment detail):
 *
 *   order_created          the order left the "draft" state and became a real order
 *   payment_completed      money confirmed: the gateway said so (woocommerce_payment_complete), or the order
 *                          moved from an unpaid status into a paid one. Once per order.
 *   payment_failed         the order moved into "failed". Once per failure; a retry that fails again counts again.
 *   order_status_changed   any other real status change (from, to)
 *   order_refunded         a refund was created. Once per refund; partial refunds each count.
 *
 * Duplicate hooks (a gateway calling payment_complete twice, a webhook delivered twice, a reload of the
 * order-received page) are absorbed twice over: markers on the order (kept in a private meta field, written
 * through WooCommerce's CRUD, so they work with High-Performance Order Storage), and deterministic event ids
 * that make the platform store a repeated event once.
 */
final class Orders {

	const STATE_META = '_ziplogger_events';

	/**
	 * Statuses in which an order is not a real order yet.
	 */
	const DRAFT_STATUSES = array( '', 'new', 'auto-draft', 'draft', 'checkout-draft', 'trash' );

	/**
	 * Statuses that WooCommerce treats as "paid".
	 */
	const PAID_STATUSES = array( 'processing', 'completed' );

	/**
	 * Statuses an order can be in while unpaid.
	 */
	const UNPAID_STATUSES = array( '', 'new', 'auto-draft', 'draft', 'checkout-draft', 'pending', 'failed', 'on-hold', 'cancelled' );

	/**
	 * Install the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'on_checkout' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_checkout' ), 10, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refunded' ), 20, 2 );
	}

	/**
	 * Whether server-side commerce events are on at all.
	 *
	 * @param string $family orders, payments, refunds or status_changes.
	 * @return bool
	 */
	private static function on( $family ) {
		if ( ! Modules::effective( 'woocommerce' ) ) {
			return false;
		}
		$s = Settings::get();
		return ! empty( $s['woocommerce'][ $family ] );
	}

	// ---------------------------------------------------------------------------------------------
	// Hooks.
	// ---------------------------------------------------------------------------------------------

	/**
	 * A checkout is creating an order (classic checkout, or the block checkout through the Store API):
	 * remember whom it belongs to, if they agreed to that.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function on_checkout( $order ) {
		try {
			if ( Modules::effective( 'woocommerce' ) ) {
				Visitor::capture( $order );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * An order was saved for the first time.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order.
	 * @return void
	 */
	public static function on_new_order( $order_id, $order = null ) {
		try {
			$order = self::order( $order_id, $order );
			if ( null === $order || in_array( $order->get_status(), self::DRAFT_STATUSES, true ) ) {
				return;
			}
			self::defer_created( $order, $order->get_status() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The status changed.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status (without "wc-").
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		try {
			$order = self::order( $order_id, $order );
			$from  = (string) $from;
			$to    = (string) $to;
			if ( null === $order || $from === $to || in_array( $to, self::DRAFT_STATUSES, true ) ) {
				return;
			}
			self::defer_created( $order, $to );

			$was_draft = in_array( $from, self::DRAFT_STATUSES, true );
			if ( ! $was_draft && self::on( 'status_changes' ) ) {
				self::status_event( $order, $from, $to );
			}
			if ( 'failed' === $to && 'failed' !== $from ) {
				self::failed_event( $order, $from );
			}
			$cod         = 'cod' === $order->get_payment_method();
			$into_paid   = in_array( $to, self::PAID_STATUSES, true ) && in_array( $from, self::UNPAID_STATUSES, true );
			$cod_settled = $cod && 'completed' === $to && 'processing' === $from; // Cash collected on delivery.
			if ( $into_paid || $cod_settled ) {
				/**
				 * Whether a move into a paid status counts as confirmed payment. Cash on delivery is "processing"
				 * from the moment of checkout although no money has changed hands, so by default that transition
				 * does not count; the later move to "completed" does.
				 *
				 * @param bool      $confirmed Default answer.
				 * @param \WC_Order $order     Order.
				 * @param string    $source    "status".
				 * @param string    $from      Previous status.
				 * @param string    $to        New status.
				 */
				$confirmed = ! ( $cod && 'processing' === $to );
				if ( apply_filters( 'ziplogger_wc_payment_confirmed', $confirmed, $order, 'status', $from, $to ) ) {
					// Deferred: a gateway that also calls woocommerce_payment_complete() in this request is the
					// better witness ("confirmedBy: gateway") and is heard first.
					self::defer_paid( $order );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A gateway reports that payment succeeded.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function on_payment_complete( $order_id ) {
		try {
			$order = self::order( $order_id, null );
			if ( null !== $order ) {
				self::maybe_created( $order );
				self::maybe_paid( $order, 'gateway' );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * A refund was created.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public static function on_refunded( $order_id, $refund_id ) {
		try {
			if ( ! self::on( 'refunds' ) ) {
				return;
			}
			$order  = self::order( $order_id, null );
			$refund = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $refund_id ) : null;
			if ( null === $order || ! is_object( $refund ) || ! self::may_emit( $order ) ) {
				return;
			}
			self::maybe_created( $order );
			$state = self::state( $order );
			$key   = (string) (int) $refund_id;
			if ( in_array( $key, $state['r'], true ) ) {
				return; // The same refund reported twice.
			}
			$state['r'][] = $key;
			self::save_state( $order, $state );

			$currency = Money::currency( $order->get_currency() );
			$props    = array(
				'orderRef' => Visitor::order_ref( $order ),
				'origin'   => 'server',
			);
			if ( '' !== $currency ) {
				$props['currency'] = $currency;
				$props            += Money::properties( 'refund', (string) $refund->get_amount(), $currency );
				$props            += Money::properties( 'refunded', (string) $order->get_total_refunded(), $currency ); // Cumulative.
			}
			$props['fullyRefunded'] = (float) $order->get_remaining_refund_amount() <= 0.0;
			self::send( 'order_refunded', $props, $order, 'rf:' . Visitor::order_ref( $order ) . ':' . Secrets::pseudonym( 'rf', (int) $refund_id, 16 ), true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Emitters (each idempotent).
	// ---------------------------------------------------------------------------------------------

	/**
	 * Order ids waiting for the end of the request to be reported as created.
	 *
	 * @var array<int,bool>
	 */
	private static $deferred = array();

	/**
	 * The status each order had when it became real (what "order_created" reports as its status).
	 *
	 * @var array<int,string>
	 */
	private static $creation_status = array();

	/**
	 * An order became real, but its items and totals may still be being added (an order created through the
	 * REST API or by an admin gets them after the first save). Report it when the request ends, so the amount
	 * is the final one. Any later event about the same order in this request reports it first.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $status Its status at this moment (reported as the status it was created in).
	 * @return void
	 */
	private static function defer_created( $order, $status ) {
		if ( empty( self::$deferred ) && empty( self::$deferred_paid ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_deferred' ), 20 );
		}
		$id = (int) $order->get_id();
		if ( ! isset( self::$creation_status[ $id ] ) ) {
			self::$creation_status[ $id ] = sanitize_key( $status );
		}
		self::$deferred[ $id ] = true;
	}

	/**
	 * Order ids whose move into a paid status is waiting for the end of the request.
	 *
	 * @var array<int,bool>
	 */
	private static $deferred_paid = array();

	/**
	 * An order moved into a paid status.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private static function defer_paid( $order ) {
		if ( empty( self::$deferred ) && empty( self::$deferred_paid ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_deferred' ), 20 );
		}
		self::$deferred_paid[ (int) $order->get_id() ] = true;
	}

	/**
	 * Report what became true during this request (shutdown; tests call it directly): orders that became
	 * real first, then payments confirmed by a status change (a gateway's own report already went out).
	 *
	 * @return void
	 */
	public static function flush_deferred() {
		$created             = array_keys( self::$deferred );
		$paid                = array_keys( self::$deferred_paid );
		self::$deferred      = array();
		self::$deferred_paid = array();
		remove_action( 'shutdown', array( __CLASS__, 'flush_deferred' ), 20 );
		foreach ( array_unique( array_merge( $created, $paid ) ) as $id ) {
			try {
				$order = self::order( $id, null );
				if ( null === $order || in_array( $order->get_status(), self::DRAFT_STATUSES, true ) ) {
					continue;
				}
				self::maybe_created( $order );
				if ( in_array( $id, $paid, true ) ) {
					self::maybe_paid( $order, 'status' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Once per order: it became real.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private static function maybe_created( $order ) {
		if ( ! self::on( 'orders' ) || ! self::may_emit( $order ) ) {
			return;
		}
		$state = self::state( $order );
		if ( ! empty( $state['c'] ) ) {
			return;
		}
		$state['c'] = 1;
		Visitor::capture( $order ); // A cookie may be present even when no checkout hook ran (REST, custom flows).
		self::save_state( $order, $state );

		$props           = self::properties( $order );
		$id              = (int) $order->get_id();
		$props['status'] = isset( self::$creation_status[ $id ] ) ? self::$creation_status[ $id ] : sanitize_key( $order->get_status() );
		self::send( 'order_created', $props, $order, 'oc:' . Visitor::order_ref( $order ), true );
	}

	/**
	 * Once per order: money confirmed.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $source "gateway" or "status".
	 * @return void
	 */
	private static function maybe_paid( $order, $source ) {
		if ( ! self::on( 'payments' ) || ! self::may_emit( $order ) ) {
			return;
		}
		self::maybe_created( $order );
		$state = self::state( $order );
		if ( ! empty( $state['p'] ) ) {
			return;
		}
		$state['p'] = 1;
		self::save_state( $order, $state ); // Claimed before sending: a second hook finding the flag stops.

		$props                = self::properties( $order );
		$props['confirmedBy'] = $source;
		self::send( 'payment_completed', $props, $order, 'pc:' . Visitor::order_ref( $order ), true );
	}

	/**
	 * A failure. Each move INTO "failed" counts once (a retried payment that fails again is a new failure).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $from  Previous status.
	 * @return void
	 */
	private static function failed_event( $order, $from ) {
		if ( ! self::on( 'payments' ) || ! self::may_emit( $order ) ) {
			return;
		}
		self::maybe_created( $order );
		$state      = self::state( $order );
		$n          = (int) $state['f'] + 1;
		$state['f'] = $n;
		self::save_state( $order, $state );

		$props               = self::properties( $order );
		$props['failedFrom'] = sanitize_key( $from );
		$props['failureNo']  = $n;
		self::send( 'payment_failed', $props, $order, 'pf:' . Visitor::order_ref( $order ) . ':' . $n, true );
	}

	/**
	 * A status change (other than becoming a real order).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $from  Previous status.
	 * @param string    $to    New status.
	 * @return void
	 */
	private static function status_event( $order, $from, $to ) {
		if ( ! self::may_emit( $order ) ) {
			return;
		}
		self::maybe_created( $order );
		$state      = self::state( $order );
		$n          = (int) $state['s'] + 1;
		$state['s'] = $n;
		self::save_state( $order, $state );

		$currency = Money::currency( $order->get_currency() );
		$props    = array(
			'orderRef' => Visitor::order_ref( $order ),
			'origin'   => 'server',
			'from'     => sanitize_key( $from ),
			'to'       => sanitize_key( $to ),
			'changeNo' => $n,
		);
		if ( '' !== $currency ) {
			$props['currency'] = $currency;
			$props            += Money::properties( '', (string) $order->get_total(), $currency );
		}
		self::send( 'order_status_changed', $props, $order, 'st:' . Visitor::order_ref( $order ) . ':' . $n, false );
	}

	// ---------------------------------------------------------------------------------------------
	// Building blocks.
	// ---------------------------------------------------------------------------------------------

	/**
	 * The properties every order event carries: an opaque reference, the currency, the amount in that
	 * currency's minor units, quantities and (if configured) product identifiers. Nothing else about the
	 * order or the customer.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function properties( $order ) {
		$settings = Settings::get();
		$mode     = $settings['woocommerce']['product_identifier'];
		$include  = ! empty( $settings['woocommerce']['include_items'] );

		$currency = Money::currency( $order->get_currency() );
		$created  = sanitize_key( (string) $order->get_created_via() );
		$props    = array(
			'orderRef'      => Visitor::order_ref( $order ),
			'origin'        => 'server',
			'createdVia'    => '' === $created ? 'unknown' : $created,
			'paymentMethod' => sanitize_key( (string) $order->get_payment_method() ),
			'isGuest'       => 0 === (int) $order->get_customer_id(),
			'couponUsed'    => count( (array) $order->get_coupon_codes() ) > 0,
		);
		if ( '' !== $currency ) {
			$props['currency'] = $currency;
			$props            += Money::properties( '', (string) $order->get_total(), $currency );
		}

		$ids   = array();
		$qtys  = array();
		$count = 0;
		foreach ( $order->get_items() as $item ) {
			$qty    = (int) $item->get_quantity();
			$count += $qty;
			if ( ! $include || 'none' === $mode || count( $ids ) >= 50 ) {
				continue;
			}
			$id = 'sku' === $mode ? Products::identifier_of_item( $item, 'sku' ) : (string) (int) $item->get_product_id();
			if ( '' !== $id ) {
				$ids[]  = $id;
				$qtys[] = $qty;
			}
		}
		$props['itemCount'] = $count;
		if ( $ids ) {
			$props['productIds'] = $ids;
			$props['quantities'] = $qtys;
		}
		return $props;
	}

	/**
	 * Queue an event about an order.
	 *
	 * @param string    $name      Event name.
	 * @param array     $props     Properties.
	 * @param \WC_Order $order     Order.
	 * @param string    $insert_id Deterministic id.
	 * @param bool      $important Survive a nearly full queue.
	 * @return bool
	 */
	private static function send( $name, array $props, $order, $insert_id, $important ) {
		return Server_Events::emit( $name, $props, Visitor::for_order( $order ), $insert_id, $important );
	}

	/**
	 * Whether events about this order may be sent, given the "commerce" consent policy. Under "none" (the
	 * default: order and payment events are the shop's own operational data) always. Under "required" only
	 * for orders whose customer agreed at checkout, which the order remembers - a gateway's webhook arrives
	 * with no visitor and no cookies to ask.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function may_emit( $order ) {
		if ( 'none' === Consent::policy( 'commerce' ) ) {
			return true;
		}
		$stored = json_decode( (string) $order->get_meta( Visitor::META, true ), true );
		return is_array( $stored ) && ! empty( $stored['k'] );
	}

	/**
	 * The order object for a hook's arguments.
	 *
	 * @param int|string $order_id Order id.
	 * @param mixed      $order    Order the hook passed, if any.
	 * @return \WC_Order|null
	 */
	private static function order( $order_id, $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) && method_exists( $order, 'get_meta' ) ) {
			return $order;
		}
		$found = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		return ( is_object( $found ) && method_exists( $found, 'get_meta' ) && 'shop_order_refund' !== $found->get_type() ) ? $found : null;
	}

	/**
	 * The dedupe markers of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{c:int,p:int,f:int,s:int,r:string[]}
	 */
	private static function state( $order ) {
		$state = json_decode( (string) $order->get_meta( self::STATE_META, true ), true );
		$state = is_array( $state ) ? $state : array();
		return array(
			'c' => empty( $state['c'] ) ? 0 : 1,
			'p' => empty( $state['p'] ) ? 0 : 1,
			'f' => isset( $state['f'] ) ? (int) $state['f'] : 0,
			's' => isset( $state['s'] ) ? (int) $state['s'] : 0,
			'r' => isset( $state['r'] ) && is_array( $state['r'] ) ? array_values( array_map( 'strval', $state['r'] ) ) : array(),
		);
	}

	/**
	 * Persist the markers (meta only: never a full order save from inside a status hook).
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $state Markers.
	 * @return void
	 */
	private static function save_state( $order, array $state ) {
		$order->update_meta_data( self::STATE_META, wp_json_encode( $state ) );
		$order->save_meta_data();
	}
}
