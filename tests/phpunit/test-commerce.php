<?php
/**
 * WooCommerce: money, order/payment/refund events, deduplication, identity and consent, privacy, HPOS-safe
 * data access, page configuration. The order tests need WooCommerce; without it they are skipped (the image
 * builds with it: see docker-compose.test.yml).
 */

use ZipLogger\WordPress\Commerce\Money;
use ZipLogger\WordPress\Commerce\Orders;
use ZipLogger\WordPress\Commerce\Visitor;
use ZipLogger\WordPress\Consent;
use ZipLogger\WordPress\Frontend;
use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Schema;
use ZipLogger\WordPress\Secrets;
use ZipLogger\WordPress\Server_Events;
use ZipLogger\WordPress\Settings;

class Test_Commerce extends ZL_TestCase {

	const BROWSER_KEY = 'zk_browser_public_0123456789abcd';

	public function set_up() {
		parent::set_up();
		Secrets::reset();
		Consent::reset();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
		unset( $_COOKIE[ Visitor::COOKIE ], $_COOKIE[ Consent::COOKIE ] );
	}

	public function tear_down() {
		unset( $_COOKIE[ Visitor::COOKIE ], $_COOKIE[ Consent::COOKIE ] );
		Consent::reset();
		remove_all_filters( 'ziplogger_wc_payment_confirmed' );
		remove_all_filters( 'ziplogger_server_event' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function need_woocommerce() {
		if ( ! Modules::woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is not installed in this image.' );
		}
	}

	private function turn_on( array $woo = array(), array $consent = array() ) {
		Settings::save(
			array(
				'enabled'     => true,
				'woocommerce' => array_merge( array( 'enabled' => true ), $woo ),
				'consent'     => $consent,
			)
		);
		Settings::save_api_key( self::KEY );
		Settings::reset_cache();
		Consent::reset();
	}

	private function sent_events() {
		Orders::flush_deferred(); // What a real request does at shutdown.
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT payload, severity FROM ' . Schema::queue_table() . " WHERE sig = 'events' ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( $r ) {
				$e             = json_decode( $r['payload'], true );
				$e['_rank']    = (int) $r['severity'];
				return $e;
			},
			$rows
		);
	}

	private function names() {
		return array_column( $this->sent_events(), 'name' );
	}

	private function clear_events() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::queue_table() ); // phpcs:ignore WordPress.DB
	}

	private function product( $sku = 'SHIRT-1', $price = '19.99' ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Blue shirt' );
		$p->set_regular_price( $price );
		$p->set_sku( $sku . '-' . wp_generate_password( 4, false ) );
		$p->save();
		return $p;
	}

	private function order( array $o = array() ) {
		$order = wc_create_order( array( 'status' => isset( $o['status'] ) ? $o['status'] : 'pending', 'customer_id' => isset( $o['customer'] ) ? $o['customer'] : 0 ) );
		$order->set_currency( isset( $o['currency'] ) ? $o['currency'] : 'USD' );
		$order->add_product( isset( $o['product'] ) ? $o['product'] : $this->product(), isset( $o['qty'] ) ? $o['qty'] : 2 );
		$order->set_billing_first_name( 'Jane' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_email( 'jane.customer@example.com' );
		$order->set_billing_phone( '+1 555 0100 77' );
		$order->set_billing_address_1( 'Baker Street 221B' );
		$order->set_billing_city( 'London' );
		$order->set_billing_postcode( 'NW1 6XE' );
		$order->set_shipping_address_1( 'Secret Shipping Lane 9' );
		$order->set_customer_note( 'leave it at the back door' );
		$order->set_payment_method( isset( $o['gateway'] ) ? $o['gateway'] : 'stripe' );
		$order->calculate_totals();
		$order->save();
		Orders::flush_deferred();
		return $order;
	}

	// ---------------------------------------------------------------------------------------------
	// Money.
	// ---------------------------------------------------------------------------------------------

	public function test_minor_units_follow_the_currency_not_the_shop_display_settings() {
		$this->assertSame( 1234, Money::minor( '12.34', 'USD' ) );
		$this->assertSame( 500, Money::minor( '500', 'JPY' ), 'One yen is one yen.' );
		$this->assertSame( 500, Money::minor( '500.00', 'JPY' ), 'Extra decimals shown by a shop do not change what a yen is.' );
		$this->assertSame( 1234, Money::minor( '1.234', 'KWD' ), 'A dinar has 1000 fils.' );
		$this->assertSame( 12340000, Money::minor( '1234', 'CLF' ), 'A few currencies have four.' );
		$this->assertSame( 1999, Money::minor( 19.99, 'USD' ), 'Floats are converted through their decimal form.' );
		$this->assertSame( 1235, Money::minor( '12.345', 'USD' ), 'Half a cent rounds up, away from zero.' );
		$this->assertSame( 1234, Money::minor( '12.344', 'USD' ) );
		$this->assertSame( -1235, Money::minor( '-12.345', 'USD' ) );
		$this->assertSame( 5, Money::minor( '.05', 'USD' ) );
		$this->assertSame( 700, Money::minor( '7.', 'USD' ) );
		$this->assertSame( 0, Money::minor( '0', 'USD' ) );
		$this->assertSame( 0, Money::minor( '', 'USD' ) );
		$this->assertSame( 0, Money::minor( 'abc', 'USD' ) );
		$this->assertSame( 0, Money::minor( '1e5', 'USD' ), 'Not a decimal amount: refused rather than guessed.' );
		$this->assertSame( 999999999999, Money::minor( '9999999999.99', 'USD' ), 'Large amounts stay exact.' );
	}

	public function test_decimal_rendering_and_the_three_event_properties() {
		$this->assertSame( '12.34', Money::decimal( 1234, 'USD' ) );
		$this->assertSame( '0.05', Money::decimal( 5, 'USD' ) );
		$this->assertSame( '-0.05', Money::decimal( -5, 'USD' ) );
		$this->assertSame( '500', Money::decimal( 500, 'JPY' ) );
		$this->assertSame( '1.234', Money::decimal( 1234, 'KWD' ) );
		$this->assertSame( array( 'value' => 39.98, 'valueMinor' => 3998 ), Money::properties( '', '39.98', 'USD' ) );
		$this->assertSame( array( 'refundValue' => 5.0, 'refundValueMinor' => 500 ), Money::properties( 'refund', '5.00', 'USD' ) );
		$this->assertSame( array( 'value' => 500.0, 'valueMinor' => 500 ), Money::properties( '', '500.00', 'JPY' ) );
	}

	public function test_currency_codes_are_validated() {
		$this->assertSame( 'USD', Money::currency( 'usd' ) );
		$this->assertSame( '', Money::currency( 'US' ) );
		$this->assertSame( '', Money::currency( 'US$' ) );
		$this->assertSame( '', Money::currency( '<script>' ) );
		$this->assertSame( '', Money::currency( null ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Server events.
	// ---------------------------------------------------------------------------------------------

	public function test_a_server_event_has_the_platform_shape_and_needs_an_identity() {
		$this->turn_on();
		$this->assertFalse( Server_Events::emit( 'x', array(), array(), 'k1' ), 'No identity: the platform would drop it silently, so it is not queued.' );
		$this->assertFalse( Server_Events::emit( 'x', array(), array( 'anonymousId' => 'bad id with spaces' ), 'k1' ) );
		$this->assertFalse( Server_Events::emit( 'x', array(), array( 'anonymousId' => 'anon_1' ), '' ), 'No insert id.' );
		$this->assertFalse( Server_Events::emit( '!!!', array(), array( 'anonymousId' => 'anon_1' ), 'k1' ), 'No usable name.' );
		$this->assertTrue( Server_Events::emit( 'Order Created!', array( 'a' => 1, 'note' => 'hello', 'list' => array( 1, 2 ) ), array( 'anonymousId' => 'anon_1', 'sessionId' => 'sess_1' ), 'oc:1', true ) );
		$e = $this->sent_events()[0];
		$this->assertSame( 'track', $e['type'] );
		$this->assertSame( 'order_created', $e['name'] );
		$this->assertSame( 'oc:1', $e['insertId'] );
		$this->assertSame( 'anon_1', $e['anonymousId'] );
		$this->assertSame( 'sess_1', $e['sessionId'] );
		$this->assertArrayNotHasKey( 'userId', $e );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $e['timestamp'] );
		$this->assertSame( 'wordpress', $e['service'] );
		$this->assertSame( array( 'a' => 1, 'note' => 'hello', 'list' => array( 1, 2 ) ), $e['properties'] );
		$this->assertSame( \ZipLogger\WordPress\Severity::rank( 'warn' ), $e['_rank'], 'Important events survive a nearly full queue.' );
	}

	public function test_properties_are_redacted_by_name_as_a_safety_net() {
		$this->turn_on();
		Server_Events::emit( 'x', array( 'email' => 'jane@example.com', 'password' => 'x', 'note' => 'call 203.0.113.9 or jane@example.com', 'count' => 3 ), array( 'anonymousId' => 'anon_1' ), 'k' );
		$p = $this->sent_events()[0]['properties'];
		$this->assertNotSame( 'jane@example.com', $p['email'] );
		$this->assertNotSame( 'x', $p['password'] );
		$this->assertStringNotContainsString( 'jane@example.com', $p['note'] );
		$this->assertStringNotContainsString( '203.0.113.9', $p['note'] );
		$this->assertSame( 3, $p['count'] );
	}

	public function test_a_site_can_drop_or_change_an_event_before_it_is_queued() {
		$this->turn_on();
		add_filter( 'ziplogger_server_event', '__return_false' );
		$this->assertFalse( Server_Events::emit( 'x', array(), array( 'anonymousId' => 'anon_1' ), 'k' ) );
		$this->assertSame( array(), $this->sent_events() );
	}

	public function test_an_oversize_event_is_dropped_not_truncated_into_something_misleading() {
		$this->turn_on();
		$this->assertFalse( Server_Events::emit( 'x', array( 'blob' => array_fill( 0, 200, str_repeat( 'x', 900 ) ) ), array( 'anonymousId' => 'anon_1' ), 'k' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The developer API for custom server events.
	// ---------------------------------------------------------------------------------------------

	private function analytics_on() {
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ), 'analytics' => array( 'enabled' => true ) ) );
		Settings::save_api_key( self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		Settings::reset_cache();
		Consent::reset();
	}

	public function test_ziplogger_track_is_off_unless_collection_and_the_analytics_module_are_on() {
		Settings::save( array( 'enabled' => true ) );
		Settings::save_api_key( self::KEY );
		Settings::reset_cache();
		$this->assertFalse( ziplogger_track( 'signup', array( 'plan' => 'pro' ) ), 'Analytics module off.' );
		$this->analytics_on();
		$this->assertFalse( ziplogger_track( array( 'not a string' ) ) );
		$this->assertTrue( ziplogger_track( 'signup', array( 'plan' => 'pro' ) ) );
		Settings::save( array( 'enabled' => false, 'browser' => array( 'enabled' => true ), 'analytics' => array( 'enabled' => true ) ) );
		Settings::reset_cache();
		$this->assertFalse( ziplogger_track( 'signup' ), 'Collection off.' );
	}

	public function test_a_custom_server_event_without_consent_belongs_to_the_system_actor() {
		$this->analytics_on();
		$_COOKIE[ Visitor::COOKIE ] = 'anon_0123456789abcdef0123.sess_abcdef01234567890123'; // A cookie alone is not consent.
		self::factory()->user->create();
		wp_set_current_user( self::factory()->user->create( array( 'user_email' => 'someone@example.com' ) ) );
		ziplogger_track( 'Import Finished', array( 'rows' => 42, 'email' => 'someone@example.com' ) );
		$e = $this->sent_events()[0];
		$this->assertSame( 'import_finished', $e['name'] );
		$this->assertSame( Secrets::pseudonym( 'sys', get_current_blog_id() ), $e['anonymousId'] );
		$this->assertArrayNotHasKey( 'userId', $e );
		$this->assertArrayNotHasKey( 'sessionId', $e );
		$this->assertSame( 42, $e['properties']['rows'] );
		$this->assertSame( 'server', $e['properties']['origin'] );
		$this->assertNotSame( 'someone@example.com', $e['properties']['email'], 'Properties are cleaned by name.' );
		$this->assertMatchesRegularExpression( '/^dev:[0-9a-f]{24}$/', $e['insertId'] );
	}

	public function test_with_consent_a_custom_event_carries_the_visitors_ids_and_the_users_pseudonym() {
		$this->analytics_on();
		$this->consent_to( array( 'analytics' ) );
		$_COOKIE[ Visitor::COOKIE ] = 'anon_0123456789abcdef0123.sess_abcdef01234567890123';
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		ziplogger_track( 'plan_changed', array( 'plan' => 'pro' ), array( 'insert_id' => 'plan-change-77' ) );
		$e = $this->sent_events()[0];
		$this->assertSame( 'anon_0123456789abcdef0123', $e['anonymousId'] );
		$this->assertSame( 'sess_abcdef01234567890123', $e['sessionId'] );
		$this->assertSame( Secrets::pseudonym( 'wpu', $user ), $e['userId'] );
		$this->assertSame( 'dev:plan-change-77', $e['insertId'], 'The caller controls idempotency.' );

		$this->clear_events();
		ziplogger_track( 'cron_ran', array(), array( 'actor' => 'system' ) );
		$this->assertSame( Secrets::pseudonym( 'sys', get_current_blog_id() ), $this->sent_events()[0]['anonymousId'], 'actor => system skips attribution.' );
	}

	public function test_the_action_form_works_too() {
		$this->analytics_on();
		do_action( 'ziplogger_track', 'via_action', array( 'a' => 1 ) );
		$this->assertSame( array( 'via_action' ), $this->names() );
	}

	// ---------------------------------------------------------------------------------------------
	// Off means off.
	// ---------------------------------------------------------------------------------------------

	public function test_nothing_is_sent_while_the_module_is_off_or_has_no_server_key() {
		$this->need_woocommerce();
		Settings::save( array( 'enabled' => true ) );
		Settings::save_api_key( self::KEY );
		$o = $this->order();
		$o->payment_complete( 'txn' );
		$this->assertSame( array(), $this->sent_events(), 'Module off.' );

		$this->turn_on();
		delete_option( Settings::KEY_OPTION );
		Settings::reset_cache();
		$this->order()->payment_complete( 'txn' );
		$this->assertSame( array(), $this->sent_events(), 'No server key.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The order lifecycle.
	// ---------------------------------------------------------------------------------------------

	public function test_a_new_order_is_reported_once_with_minimal_data_and_no_personal_data() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order( array( 'qty' => 2 ) );
		$this->assertSame( array( 'order_created' ), $this->names() );
		$e = $this->sent_events()[0];
		$p = $e['properties'];
		$this->assertMatchesRegularExpression( '/^ord_[0-9a-f]{24}$/', $p['orderRef'] );
		$this->assertSame( 'USD', $p['currency'] );
		$this->assertSame( 39.98, $p['value'] );
		$this->assertSame( 3998, $p['valueMinor'] );
		$this->assertSame( 2, $p['itemCount'] );
		$this->assertSame( array( (string) array_values( $order->get_items() )[0]->get_product_id() ), $p['productIds'] );
		$this->assertSame( array( 2 ), $p['quantities'] );
		$this->assertSame( 'stripe', $p['paymentMethod'] );
		$this->assertSame( 'server', $p['origin'] );
		$this->assertTrue( $p['isGuest'] );
		$this->assertSame( 'pending', $p['status'] );
		$this->assertSame( $p['orderRef'], $e['anonymousId'], 'Without consent the only identity is the order\'s own opaque reference.' );
		$this->assertArrayNotHasKey( 'sessionId', $e );
		$this->assertSame( 'oc:' . $p['orderRef'], $e['insertId'] );

		$raw = wp_json_encode( $this->sent_events() );
		foreach ( array( 'Jane', 'Customer', 'jane.customer', 'example.com', '555', 'Baker', 'London', 'NW1', 'Secret Shipping', 'back door', $order->get_order_key(), 'billing', 'shipping' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $raw, "Personal data leaked: $leak" );
		}
		$this->assertStringNotContainsString( '"' . $order->get_id() . '"', $raw, 'The order number is not disclosed.' );
		$this->assertDoesNotMatchRegularExpression( '/[:_"]' . $order->get_id() . '[:_"]/', $raw );
	}

	public function test_an_order_a_checkout_creates_with_no_status_yet_is_reported_as_created_pending() {
		$this->need_woocommerce();
		$this->turn_on();
		// The classic checkout builds an order object and saves it without setting a status: at
		// woocommerce_new_order its raw status is still empty (the default "pending" applies when it is read).
		$order = new WC_Order();
		$order->set_currency( 'USD' );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		$order->save();
		Orders::flush_deferred();
		$events = $this->sent_events();
		$this->assertSame( array( 'order_created' ), array_column( $events, 'name' ) );
		$this->assertSame( 'pending', $events[0]['properties']['status'] );
		$this->assertSame( 1999, $events[0]['properties']['valueMinor'] );

		$order->payment_complete( 'txn' );
		$this->assertSame( array( 'order_created', 'order_status_changed', 'payment_completed' ), array_column( $this->sent_events(), 'name' ) );
		$this->assertSame( 'pending', $this->sent_events()[0]['properties']['status'], 'Still reported as created pending, not as processing.' );
	}

	public function test_the_order_reference_is_stable_opaque_and_specific_to_the_site() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$ref   = Visitor::order_ref( $order );
		$this->assertSame( $ref, Visitor::order_ref( $order ) );
		$this->assertNotSame( $ref, Visitor::order_ref( $this->order() ) );
		$this->assertSame( Secrets::pseudonym( 'ord', $order->get_id() ), $ref );
		$this->assertDoesNotMatchRegularExpression( '/^ord_' . $order->get_id() . '$/', $ref );
	}

	public function test_draft_orders_are_not_orders_until_they_leave_the_draft_state() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order( array( 'status' => 'checkout-draft' ) );
		$this->assertSame( array(), $this->names(), 'A block-checkout draft is not an order.' );
		$order->update_status( 'pending' );
		Orders::flush_deferred();
		$this->assertSame( array( 'order_created' ), $this->names(), 'Reported when it becomes real, once.' );
		$order->update_status( 'on-hold' );
		Orders::flush_deferred();
		$this->assertSame( array( 'order_created', 'order_status_changed' ), $this->names() );
	}

	public function test_the_amount_is_the_final_one_even_when_items_are_added_after_the_first_save() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = wc_create_order( array( 'status' => 'pending' ) ); // Saved with no items yet.
		$order->set_currency( 'USD' );
		$order->add_product( $this->product( 'LATE', '10.00' ), 3 );
		$order->calculate_totals();
		$order->save();
		Orders::flush_deferred();
		$e = $this->sent_events();
		$this->assertCount( 1, $e );
		$this->assertSame( 3000, $e[0]['properties']['valueMinor'], 'The order was reported at the end of the request, with its final total.' );
	}

	public function test_confirmed_payment_is_reported_once_however_many_times_the_hook_fires() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$this->clear_events();
		$order->payment_complete( 'txn_1' );
		$order->payment_complete( 'txn_1' );
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn_1' ); // A duplicate webhook.
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn_1' );
		Orders::flush_deferred();
		$names = $this->names();
		$this->assertSame( 1, count( array_keys( $names, 'payment_completed', true ) ), 'Exactly one payment_completed: ' . implode( ',', $names ) );
		$event = $this->sent_events()[ array_search( 'payment_completed', $names, true ) ];
		$this->assertSame( 'gateway', $event['properties']['confirmedBy'] );
		$this->assertSame( 3998, $event['properties']['valueMinor'] );
		$this->assertSame( 'pc:' . Visitor::order_ref( $order ), $event['insertId'], 'A deterministic id: the platform also stores a repeat once.' );
	}

	public function test_a_reload_of_the_thank_you_page_is_not_a_payment() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$this->clear_events();
		// What WooCommerce does when the order-received page renders (for an order that is still unpaid).
		ob_start(); // WooCommerce prints the order details.
		do_action( 'woocommerce_thankyou', $order->get_id() );
		do_action( 'woocommerce_thankyou', $order->get_id() );
		ob_end_clean();
		$this->assertSame( array(), $this->names(), 'The thank-you page is never proof of payment.' );
	}

	public function test_offline_payment_confirmed_by_moving_the_order_to_a_paid_status() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order( array( 'gateway' => 'bacs', 'status' => 'on-hold' ) );
		$this->clear_events();
		$order->update_status( 'processing' ); // The shop owner saw the transfer arrive.
		$this->assertContains( 'payment_completed', $this->names() );
		$paid = $this->sent_events()[ array_search( 'payment_completed', $this->names(), true ) ];
		$this->assertSame( 'status', $paid['properties']['confirmedBy'] );
		$order->update_status( 'completed' );
		$this->assertSame( 1, count( array_keys( $this->names(), 'payment_completed', true ) ), 'Not again when it completes.' );
	}

	public function test_cash_on_delivery_is_not_paid_at_checkout_but_is_when_completed() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order( array( 'gateway' => 'cod' ) );
		$this->clear_events();
		$order->update_status( 'processing' );
		$this->assertNotContains( 'payment_completed', $this->names(), 'No money has changed hands yet.' );
		$order->update_status( 'completed' );
		$this->assertContains( 'payment_completed', $this->names() );
	}

	public function test_the_paid_transition_can_be_vetoed_by_the_site() {
		$this->need_woocommerce();
		$this->turn_on();
		add_filter( 'ziplogger_wc_payment_confirmed', '__return_false' );
		$order = $this->order( array( 'status' => 'on-hold' ) );
		$order->update_status( 'processing' );
		$this->assertNotContains( 'payment_completed', $this->names() );
	}

	public function test_each_failure_is_reported_once_and_a_repeat_of_the_same_failure_is_not() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$this->clear_events();
		$order->update_status( 'failed' );
		$order->update_status( 'failed' ); // Already failed: no transition.
		do_action( 'woocommerce_order_status_failed', $order->get_id() ); // A gateway calling the hook again.
		$fails = array_values( array_filter( $this->sent_events(), static function ( $e ) {
			return 'payment_failed' === $e['name'];
		} ) );
		$this->assertCount( 1, $fails );
		$this->assertSame( 1, $fails[0]['properties']['failureNo'] );
		$this->assertSame( 'pf:' . Visitor::order_ref( $order ) . ':1', $fails[0]['insertId'] );

		$order->update_status( 'pending' ); // The customer tries again...
		$order->update_status( 'failed' );  // ...and it fails again: a second failure.
		$fails = array_values( array_filter( $this->sent_events(), static function ( $e ) {
			return 'payment_failed' === $e['name'];
		} ) );
		$this->assertCount( 2, $fails );
		$this->assertSame( 2, $fails[1]['properties']['failureNo'] );
	}

	public function test_a_payment_that_fails_then_succeeds_reports_both_in_order() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$this->clear_events();
		$order->update_status( 'failed' );
		$order->update_status( 'pending' );
		$order->payment_complete( 'txn_ok' );
		$names = array_values( array_diff( $this->names(), array( 'order_status_changed' ) ) );
		$this->assertSame( array( 'payment_failed', 'payment_completed' ), $names );
	}

	public function test_status_changes_carry_from_to_and_a_running_number() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$this->clear_events();
		$order->update_status( 'on-hold' );
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
		$changes = array_values( array_filter( $this->sent_events(), static function ( $e ) {
			return 'order_status_changed' === $e['name'];
		} ) );
		$this->assertSame( array( array( 'pending', 'on-hold', 1 ), array( 'on-hold', 'processing', 2 ), array( 'processing', 'completed', 3 ) ), array_map( static function ( $e ) {
			return array( $e['properties']['from'], $e['properties']['to'], $e['properties']['changeNo'] );
		}, $changes ) );
		$this->assertCount( 3, array_unique( array_column( $changes, 'insertId' ) ), 'Every change has its own id.' );
	}

	public function test_partial_refunds_each_count_once_and_a_repeated_hook_does_not() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$order->payment_complete( 'txn' );
		$this->clear_events();

		$r1 = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '10.00', 'reason' => 'partial 1' ) );
		$r2 = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '5.50', 'reason' => 'partial 2' ) );
		do_action( 'woocommerce_order_refunded', $order->get_id(), $r1->get_id() ); // Duplicate report of the first.
		$refunds = array_values( array_filter( $this->sent_events(), static function ( $e ) {
			return 'order_refunded' === $e['name'];
		} ) );
		$this->assertCount( 2, $refunds, 'Two partial refunds, both counted.' );
		$this->assertSame( 1000, $refunds[0]['properties']['refundValueMinor'] );
		$this->assertSame( 550, $refunds[1]['properties']['refundValueMinor'] );
		$this->assertSame( 1550, $refunds[1]['properties']['refundedValueMinor'] );
		$this->assertFalse( $refunds[1]['properties']['fullyRefunded'] );
		$this->assertNotSame( $refunds[0]['insertId'], $refunds[1]['insertId'] );
		$this->assertDoesNotMatchRegularExpression( '/:' . $r1->get_id() . '$/', $refunds[0]['insertId'], 'The refund number is hashed, not exposed.' );

		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '24.48', 'reason' => 'rest' ) );
		$last = array_values( array_filter( $this->sent_events(), static function ( $e ) {
			return 'order_refunded' === $e['name'];
		} ) )[2];
		$this->assertTrue( $last['properties']['fullyRefunded'] );
		$this->assertStringNotContainsString( 'partial', wp_json_encode( $this->sent_events() ), 'Refund reasons are free text and never sent.' );
	}

	public function test_an_amount_in_a_zero_decimal_currency_is_exact() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order( array( 'currency' => 'JPY', 'product' => $this->product( 'YEN', '500' ), 'qty' => 1 ) );
		$p     = $this->sent_events()[0]['properties'];
		$this->assertSame( 'JPY', $p['currency'] );
		$this->assertSame( 500, $p['valueMinor'] );
		$this->assertEquals( 500, $p['value'] );
	}

	public function test_every_event_family_can_be_switched_off_separately() {
		$this->need_woocommerce();
		$this->turn_on( array( 'orders' => false, 'payments' => false, 'refunds' => false, 'status_changes' => false ) );
		$order = $this->order();
		$order->payment_complete( 'x' );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00' ) );
		$this->assertSame( array(), $this->names() );

		$this->turn_on( array( 'orders' => true, 'payments' => false, 'refunds' => false, 'status_changes' => false ) );
		$order = $this->order();
		$order->payment_complete( 'x' );
		$this->assertSame( array( 'order_created' ), $this->names() );

		$this->clear_events();
		$this->turn_on( array( 'orders' => false, 'payments' => true, 'refunds' => false, 'status_changes' => false ) );
		$order = $this->order();
		$order->payment_complete( 'x' );
		$this->assertSame( array( 'payment_completed' ), $this->names() );
	}

	public function test_product_identifiers_follow_the_setting() {
		$this->need_woocommerce();
		$product = $this->product( 'SKU-77' );
		$this->turn_on( array( 'product_identifier' => 'sku' ) );
		$this->order( array( 'product' => $product ) );
		$this->assertSame( array( $product->get_sku() ), $this->sent_events()[0]['properties']['productIds'] );

		$this->clear_events();
		$this->turn_on( array( 'product_identifier' => 'none' ) );
		$this->order( array( 'product' => $product ) );
		$p = $this->sent_events()[0]['properties'];
		$this->assertArrayNotHasKey( 'productIds', $p );
		$this->assertSame( 2, $p['itemCount'], 'Counts remain.' );

		$this->clear_events();
		$this->turn_on( array( 'product_identifier' => 'id', 'include_items' => false ) );
		$this->order( array( 'product' => $product ) );
		$this->assertArrayNotHasKey( 'productIds', $this->sent_events()[0]['properties'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Identity and consent.
	// ---------------------------------------------------------------------------------------------

	private function consent_to( $categories ) {
		$_COOKIE[ Consent::COOKIE ] = implode( ',', $categories );
		Consent::reset();
	}

	public function test_with_consent_the_order_is_linked_to_the_visitors_browsing_and_stays_linked_for_later_webhooks() {
		$this->need_woocommerce();
		$this->turn_on();
		$this->consent_to( array( 'analytics' ) );
		$_COOKIE[ Visitor::COOKIE ] = 'anon_0123456789abcdef0123.sess_abcdef01234567890123';

		$order = wc_create_order( array( 'status' => 'pending' ) );
		$order->set_currency( 'USD' );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		do_action( 'woocommerce_checkout_create_order', $order, array() ); // The checkout, with the visitor's cookies.
		$order->save();
		Orders::flush_deferred();

		// The gateway's webhook arrives later: no cookies, no consent evidence in this request.
		unset( $_COOKIE[ Visitor::COOKIE ], $_COOKIE[ Consent::COOKIE ] );
		Consent::reset();
		$order = wc_get_order( $order->get_id() );
		$order->payment_complete( 'txn' );

		foreach ( $this->sent_events() as $e ) {
			$this->assertSame( 'anon_0123456789abcdef0123', $e['anonymousId'], $e['name'] );
			$this->assertSame( 'sess_abcdef01234567890123', $e['sessionId'], $e['name'] );
			$this->assertArrayNotHasKey( 'userId', $e );
		}
		$this->assertContains( 'payment_completed', $this->names() );
	}

	public function test_a_signed_in_customer_gets_the_pseudonym_never_the_user_id() {
		$this->need_woocommerce();
		$this->turn_on();
		$this->consent_to( array( 'analytics' ) );
		$_COOKIE[ Visitor::COOKIE ] = 'anon_0123456789abcdef0123.sess_abcdef01234567890123';
		$user = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'shopper@example.com', 'user_login' => 'shopper_login' ) );

		$order = wc_create_order( array( 'status' => 'pending', 'customer_id' => $user ) );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$order->save();
		Orders::flush_deferred();
		$e = $this->sent_events()[0];
		$this->assertSame( Secrets::pseudonym( 'wpu', $user ), $e['userId'], 'The same pseudonym the browser side uses.' );
		$raw = wp_json_encode( $this->sent_events() );
		$this->assertStringNotContainsString( 'shopper', $raw );
		$this->assertStringNotContainsString( '"' . $user . '"', $raw );
		$this->assertFalse( $e['properties']['isGuest'] );
	}

	public function test_without_consent_nothing_about_the_visitor_is_stored_even_if_the_cookie_is_present() {
		$this->need_woocommerce();
		$this->turn_on();
		$_COOKIE[ Visitor::COOKIE ] = 'anon_0123456789abcdef0123.sess_abcdef01234567890123'; // No consent evidence at all.
		$order = wc_create_order( array( 'status' => 'pending' ) );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$order->save();
		Orders::flush_deferred();
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( Visitor::META ) );
		$e = $this->sent_events()[0];
		$this->assertMatchesRegularExpression( '/^ord_/', $e['anonymousId'] );
		$this->assertArrayNotHasKey( 'sessionId', $e );
	}

	public function test_a_consent_denial_is_respected_and_a_tampered_cookie_is_ignored() {
		$this->need_woocommerce();
		$this->turn_on();
		$this->consent_to( array() ); // Explicitly nothing.
		$_COOKIE[ Visitor::COOKIE ] = 'anon_0123456789abcdef0123.sess_abcdef01234567890123';
		$this->assertNull( null );
		$order = wc_create_order( array( 'status' => 'pending' ) );
		Visitor::capture( $order );
		$this->assertSame( '', (string) $order->get_meta( Visitor::META ) );

		$this->consent_to( array( 'analytics' ) );
		foreach ( array( 'anon_x.sess_y', '../../etc/passwd', "anon_0123456789abcdef0123.sess_abcdef01234567890123\n", 'anon_0123456789abcdef0123', str_repeat( 'a', 500 ), '.' ) as $bad ) {
			$_COOKIE[ Visitor::COOKIE ] = $bad;
			$this->assertNull( Visitor::from_cookie(), 'Must be ignored: ' . json_encode( $bad ) );
		}
		$_COOKIE[ Visitor::COOKIE ] = '.sess_abcdef01234567890123';
		$this->assertSame( array( 'a' => '', 's' => 'sess_abcdef01234567890123' ), Visitor::from_cookie(), 'Either id alone is fine.' );
	}

	public function test_when_commerce_consent_is_required_only_consenting_customers_orders_are_reported() {
		$this->need_woocommerce();
		$this->turn_on( array(), array( 'commerce' => 'required' ) );

		$declined = $this->order();
		$declined->payment_complete( 'x' );
		$this->assertSame( array(), $this->names(), 'No evidence of consent at checkout: nothing, including later webhooks.' );

		$this->consent_to( array( 'commerce' ) );
		$order = wc_create_order( array( 'status' => 'pending' ) );
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals();
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$order->save();
		Orders::flush_deferred();
		unset( $_COOKIE[ Consent::COOKIE ] );
		Consent::reset();
		wc_get_order( $order->get_id() )->payment_complete( 'y' ); // Webhook: no cookies.
		$this->assertContains( 'order_created', $this->names() );
		$this->assertContains( 'payment_completed', $this->names(), 'The customer\'s earlier consent, remembered by the order, covers the webhook.' );
	}

	public function test_the_default_policy_for_commerce_is_no_consent_needed() {
		$this->turn_on();
		$this->assertSame( 'none', Consent::policy( 'commerce' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Data access and compatibility.
	// ---------------------------------------------------------------------------------------------

	public function test_markers_and_identity_live_in_order_meta_written_through_the_crud_api() {
		$this->need_woocommerce();
		$this->turn_on();
		$order = $this->order();
		$fresh = wc_get_order( $order->get_id() );
		$state = json_decode( (string) $fresh->get_meta( Orders::STATE_META ), true );
		$this->assertSame( 1, $state['c'] );
		$this->assertStringStartsWith( '_', Orders::STATE_META, 'Protected meta: hidden from the custom-fields UI.' );
	}

	public function test_the_plugin_never_touches_order_posts_or_post_meta_directly() {
		$forbidden = array( 'get_post_meta', 'update_post_meta', 'add_post_meta', 'delete_post_meta', 'get_posts(', 'wp_insert_post', 'wc_get_orders_from_posts', '$wpdb->posts', '$wpdb->postmeta', 'shop_order' );
		foreach ( glob( ZIPLOGGER_DIR . 'includes/commerce/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			foreach ( $forbidden as $needle ) {
				if ( 'shop_order' === $needle ) {
					$source = str_replace( 'shop_order_refund', '', $source );
				}
				$this->assertStringNotContainsString( $needle, $source, basename( $file ) . ' uses ' . $needle . ': orders must be accessed through WooCommerce\'s CRUD objects (High-Performance Order Storage).' );
			}
		}
	}

	public function test_high_performance_order_storage_and_block_checkout_compatibility_are_declared_when_woocommerce_asks() {
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( 'ZipLogger\WordPress\Commerce\Integration', 'declare_compatibility' ) ) );
		// WooCommerce only lists plugins that are installed AND active, which a unit test is not; the registry is
		// checked against the real, installed plugin in the end-to-end suite (tests/e2e/m5-commerce.test.mjs).
	}

	// ---------------------------------------------------------------------------------------------
	// Page configuration (cached pages).
	// ---------------------------------------------------------------------------------------------

	public function test_a_product_page_tells_the_browser_the_product_identifier_and_it_is_the_same_for_everyone() {
		$this->need_woocommerce();
		$this->turn_on( array(), array() );
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ), 'analytics' => array( 'enabled' => true ), 'woocommerce' => array( 'enabled' => true ) ) );
		Settings::save_api_key( self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		Settings::reset_cache();
		$product = $this->product( 'PAGE-1' );
		$this->go_to( get_permalink( $product->get_id() ) );
		$this->assertTrue( is_product() );
		$this->assertSame( array( 'product' => array( 'id' => (string) $product->get_id() ) ), Frontend::page()['commerce'] );

		wp_set_current_user( 0 );
		$anon = wp_json_encode( Frontend::config() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( $anon, wp_json_encode( Frontend::config() ), 'Cache-safe: nothing about the visitor.' );
		$this->assertSame( 'USD', Frontend::config()['commerce']['currency'] );
		$this->assertTrue( Frontend::config()['modules']['commerce'] );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( array(), Frontend::page()['commerce'], 'Only product pages carry a product.' );
	}

	public function test_the_sku_identifier_and_none_reach_the_page_config() {
		$this->need_woocommerce();
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ), 'analytics' => array( 'enabled' => true ), 'woocommerce' => array( 'enabled' => true, 'product_identifier' => 'sku' ) ) );
		Settings::save_api_key( self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		Settings::reset_cache();
		$product = $this->product( 'SKUPAGE' );
		$this->go_to( get_permalink( $product->get_id() ) );
		$this->assertSame( $product->get_sku(), Frontend::page()['commerce']['product']['id'] );
		$this->assertSame( 'sku', Frontend::config()['commerce']['productIdentifier'] );

		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ), 'analytics' => array( 'enabled' => true ), 'woocommerce' => array( 'enabled' => true, 'product_identifier' => 'none' ) ) );
		Settings::reset_cache();
		$this->assertSame( array(), Frontend::page()['commerce'] );
	}

	public function test_the_commerce_block_is_inert_when_the_module_is_off() {
		Settings::save( array( 'enabled' => true, 'browser' => array( 'enabled' => true ) ) );
		Settings::save_api_key( self::KEY );
		Settings::save_key( 'browser', self::BROWSER_KEY );
		Settings::reset_cache();
		$c = Frontend::config()['commerce'];
		$this->assertFalse( $c['productViews'] );
		$this->assertFalse( $c['cartEvents'] );
		$this->assertFalse( $c['checkout'] );
		$this->assertSame( '', $c['currency'] );
		$this->assertSame( 'none', $c['productIdentifier'] );
		$this->assertFalse( Frontend::config()['modules']['commerce'] );
	}

	public function test_without_woocommerce_the_module_reports_why_it_cannot_run() {
		if ( Modules::woocommerce_active() ) {
			$this->markTestSkipped( 'Only meaningful without WooCommerce.' );
		}
		Settings::save( array( 'enabled' => true, 'woocommerce' => array( 'enabled' => true ) ) );
		Settings::save_api_key( self::KEY );
		Settings::reset_cache();
		$this->assertFalse( Modules::effective( 'woocommerce' ) );
		$this->assertContains( 'WooCommerce is not active on this site.', Modules::blockers( 'woocommerce' ) );
	}
}
