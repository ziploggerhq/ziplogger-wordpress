<?php
/**
 * Product identifiers, as the administrator chose to share them.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Commerce;

use ZipLogger\WordPress\Modules;
use ZipLogger\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics needs to tell products apart, and a shop may not want its catalog ids or SKUs to leave the
 * site. The "product identifier" setting decides: the product id, the SKU, or nothing at all.
 */
final class Products {

	/**
	 * The identifier of a product under a mode.
	 *
	 * @param \WC_Product $product Product.
	 * @param string|null $mode    id, sku or none; the setting when null.
	 * @return string '' when nothing is to be shared.
	 */
	public static function identifier( $product, $mode = null ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return '';
		}
		if ( null === $mode ) {
			$s    = Settings::get();
			$mode = $s['woocommerce']['product_identifier'];
		}
		if ( 'sku' === $mode ) {
			$sku = (string) $product->get_sku();
			return 1 === preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $sku ) ? $sku : '';
		}
		if ( 'id' === $mode ) {
			$id = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
			return $id > 0 ? (string) $id : '';
		}
		return '';
	}

	/**
	 * The identifier of an order line's product.
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @param string                 $mode id or sku.
	 * @return string
	 */
	public static function identifier_of_item( $item, $mode ) {
		$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
		return $product ? self::identifier( $product, $mode ) : '';
	}

	/**
	 * What the browser needs on a product page: the product's identifier. It follows from the URL alone, so
	 * it is the same for every visitor and safe in a cached page. Empty on other pages.
	 *
	 * @return array{product?:array{id:string}}
	 */
	public static function page_block() {
		if ( ! Modules::effective( 'woocommerce' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return array();
		}
		$s = Settings::get();
		if ( 'none' === $s['woocommerce']['product_identifier'] ) {
			return array();
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;
		$id      = null === $product ? '' : self::identifier( $product );
		return '' === $id ? array() : array( 'product' => array( 'id' => $id ) );
	}
}
