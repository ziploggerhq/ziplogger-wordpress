<?php
/**
 * WooCommerce integration entry point.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Commerce;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WooCommerce hooks and the compatibility declarations.
 *
 * Compatibility: this plugin reads and writes orders only through WooCommerce's CRUD objects (get_meta,
 * update_meta_data, get_total ...), never through the posts tables or post meta functions, so it works with
 * High-Performance Order Storage on or off. It also declares support for the block-based cart and checkout,
 * to which it adds nothing that depends on the shortcode checkout.
 */
final class Integration {

	/**
	 * Install the hooks. Cheap when WooCommerce is absent: the hooks simply never fire.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );
		Orders::register();
	}

	/**
	 * Tell WooCommerce which features this plugin works with.
	 *
	 * @return void
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ZIPLOGGER_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ZIPLOGGER_FILE, true );
		}
	}
}
