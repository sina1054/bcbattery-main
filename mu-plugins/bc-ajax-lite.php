<?php
/**
 * Plugin Name: BC Checkout Ajax Lite
 * Description: The checkout and its refresh requests (?wc-ajax=checkout / update_order_review) do not need the page builder or the SEO plugins. Not loading them saves about half a second per request.
 *             Only these two requests are touched; pages, the cart and the admin load every plugin as usual. Turn off with: add_filter( 'bc_ajax_lite', '__return_false' ) or delete this file.
 */
defined( 'ABSPATH' ) || exit;

function bc_ajax_lite_request() {
	static $on = null;
	if ( null === $on ) {
		$ajax = isset( $_GET['wc-ajax'] ) && is_string( $_GET['wc-ajax'] ) ? $_GET['wc-ajax'] : ''; // phpcs:ignore WordPress.Security
		$on   = in_array( $ajax, array( 'checkout', 'update_order_review' ), true ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! defined( 'WP_ADMIN' ); // phpcs:ignore WordPress.Security
	}
	return $on;
}

add_filter(
	'option_active_plugins',
	function ( $plugins ) {
		if ( ! is_array( $plugins ) || ! bc_ajax_lite_request() || ! apply_filters( 'bc_ajax_lite', true ) ) {
			return $plugins;
		}
		$drop = array(
			'elementor/elementor.php',
			'elementor-pro/elementor-pro.php',
			'seo-by-rank-math/rank-math.php',
			'classic-editor/classic-editor.php',
			'covert-to-webp/convert-to-webp.php',
			'emalls-extraction-api-official/emalls-extraction-api-official.php',
			'products-extractor-for-woocommerce/wcpe.php',
		);
		return array_values( array_diff( $plugins, $drop ) );
	}
);