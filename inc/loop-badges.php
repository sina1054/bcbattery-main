<?php
/**
 * BC Battery - product cards (shop, categories, archives, related products).
 *
 * Replaces the Woodmart "custom labels" on product cards with the two badges the product page shows:
 * warranty and fast delivery in Tehran. The texts come from bc_pdp_texts() (option / filter bc_pdp_texts),
 * so the cards and the product page always say the same thing. Woodmart's own labels (sale %, new,
 * out of stock) stay as they are.
 *
 * Rules  : fast delivery is not shown on a product that is out of stock.
 * Filter : bc_loop_badges (array of [icon, text, kind] per product).
 * Kill   : define( 'BC_LOOP_BADGES_DISABLED', true ) in wp-config.php.
 */
defined( 'ABSPATH' ) || exit;

if ( defined( 'BC_LOOP_BADGES_DISABLED' ) && BC_LOOP_BADGES_DISABLED ) {
	return;
}

/** The badges for one product card. */
function bc_loop_badges( $product ) {
	if ( ! function_exists( 'bc_pdp_texts' ) || ! $product instanceof WC_Product ) {
		return array();
	}
	$t     = bc_pdp_texts();
	$items = array();
	if ( '' !== trim( (string) $t['warranty'] ) ) {
		$items[] = array( 'shield', $t['warranty'], 'ok' );
	}
	if ( '' !== trim( (string) $t['fast'] ) && $product->is_in_stock() ) {
		$items[] = array( 'rocket', $t['fast'], 'fast' );
	}
	return (array) apply_filters( 'bc_loop_badges', $items, $product );
}

/*
 * Woodmart prints the card labels through the woodmart_product_label_output filter. On the front end:
 * drop every custom label, and on product cards (the woocommerce_sale_flash path) add ours.
 */
add_filter(
	'woodmart_product_label_output',
	function ( $output ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $output;
		}
		$output = array_filter(
			(array) $output,
			function ( $html ) {
				return false === strpos( (string) $html, 'wd-custom-label' );
			}
		);

		if ( ! doing_filter( 'woocommerce_sale_flash' ) ) {
			return $output;
		}
		global $product;
		foreach ( bc_loop_badges( $product ) as $i => $b ) {
			$icon = function_exists( 'bc_icon' ) ? bc_icon( $b[0], 12 ) : '';
			$output[ 'bc-badge-' . $i ] = '<span class="bc-lb bc-lb--' . esc_attr( $b[2] ) . '"><span class="bc-lb__ic">' . $icon . '</span>' . esc_html( $b[1] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return $output;
	},
	20
);

/* Styles: small, so printed inline after the child theme's stylesheet (loaded on every page). */
add_action(
	'wp_enqueue_scripts',
	function () {
		$css = '.product-labels .bc-lb{display:inline-flex;align-items:center;gap:5px;max-width:100%;padding-block:2px;padding-inline:3px 8px;border-radius:999px;font-size:11px;font-weight:500;line-height:18px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none}'
			. '.product-labels .bc-lb__ic{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:18px;height:18px;border-radius:50%;color:#fff}'
			. '.product-labels .bc-lb__ic svg{display:block}'
			. '.product-labels .bc-lb--ok{background:#E4F4E8;color:#14612C}'
			. '.product-labels .bc-lb--ok .bc-lb__ic{background:#1F9D4D}'
			. '.product-labels .bc-lb--fast{background:#FDECE4;color:#C2410C}'
			. '.product-labels .bc-lb--fast .bc-lb__ic{background:#F26A2E}'
			. '.product-labels .bc-lb--fast .bc-lb__ic svg{transform:rotate(45deg)}'
			. '@media (max-width:575px){.product-labels .bc-lb{font-size:10px;padding-inline-end:6px}.product-labels .bc-lb__ic{width:16px;height:16px}}';
		wp_add_inline_style( 'child-style', $css );
	},
	10020
);
