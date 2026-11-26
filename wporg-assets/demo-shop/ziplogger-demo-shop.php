<?php
/**
 * Plugin Name: ZipLogger demo shop helpers
 * Description: For the ziplogger.dev demo shop only. Completes "Demo payment" orders so WooCommerce's one-day "anonymize completed orders" rule cleans them up, and trims checkout to country, name and email.
 * Version: 1.0.0
 *
 * Install as a must-use plugin: wp-content/mu-plugins/ziplogger-demo-shop.php
 * (or paste everything below the opening "<?php" line into a Code Snippets snippet set to "Run everywhere").
 */

defined( 'ABSPATH' ) || exit;

// 1. The demo payment method is WooCommerce's "Cash on delivery", renamed. Its orders normally stay
//    "processing", which no retention rule covers; "completed" is anonymised after one day
//    (WooCommerce, Settings, Accounts & Privacy). The completed-order email is switched off.
add_filter(
	'woocommerce_cod_process_payment_order_status',
	static function () {
		return 'completed';
	}
);

// 2. Collect as little as possible: hide the address and contact fields on the block and classic checkouts.
function ziplogger_demo_shop_hidden_fields() {
	return array( 'address_1', 'address_2', 'city', 'state', 'postcode', 'company', 'phone' );
}

function ziplogger_demo_shop_hide_locale_fields( $fields ) {
	foreach ( ziplogger_demo_shop_hidden_fields() as $key ) {
		$current        = isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ? $fields[ $key ] : array();
		$fields[ $key ] = array_merge( $current, array( 'required' => false, 'hidden' => true ) );
	}
	return $fields;
}

// Fields for countries without their own rules.
add_filter( 'woocommerce_get_country_locale_default', 'ziplogger_demo_shop_hide_locale_fields' );

// Countries with their own rules (the US among them).
add_filter(
	'woocommerce_get_country_locale',
	static function ( $locales ) {
		foreach ( $locales as $country => $fields ) {
			$locales[ $country ] = ziplogger_demo_shop_hide_locale_fields( is_array( $fields ) ? $fields : array() );
		}
		return $locales;
	}
);

// Classic (shortcode) checkout.
add_filter(
	'woocommerce_billing_fields',
	static function ( $fields ) {
		foreach ( ziplogger_demo_shop_hidden_fields() as $key ) {
			unset( $fields[ 'billing_' . $key ] );
		}
		return $fields;
	}
);
