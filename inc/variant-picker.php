<?php
/**
 * BC Battery - "انتخاب تنوع" popup for product cards (owner request, 20 Oct).
 *
 * Complementary cards (cart page, after-add sheet) and related cards on the product page carry no option chips any
 * more. A variable product with one varying attribute keeps an add button; the card holds its options as data
 * (data-variants, data-vlabel) and the button opens assets/js/variant-picker.js, where the visitor picks and adds.
 * Products that need more than one choice still link to their own page.
 * The same popup serves product lists (shop, categories, archives, home page): see bc_vp_loop_card() below.
 */
defined( 'ABSPATH' ) || exit;

/** 7809000 -> "۷٬۸۰۹٬۰۰۰" */
function bc_vp_money( $n ) {
	$s = number_format( (float) round( (float) $n ), 0, '', '٬' );
	return strtr( $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
}

/** The data attributes a card with options carries (empty for simple products and "choose on the product page" ones). */
function bc_vp_card_attrs( array $card ) {
	if ( empty( $card['variants'] ) || ! empty( $card['choose'] ) ) {
		return '';
	}
	$list = array();
	foreach ( $card['variants'] as $v ) {
		$list[] = array(
			'id'    => (int) $v['id'],
			'label' => wp_strip_all_tags( (string) $v['label'] ),
			'price' => bc_vp_money( $v['price'] ),
		);
	}
	return ' data-variants="' . esc_attr( wp_json_encode( $list ) ) . '" data-vlabel="' . esc_attr( wp_strip_all_tags( (string) $card['label'] ) ) . '"';
}

/** [ show "از", price ] for a card: the lowest option price when the options cost different amounts. */
function bc_vp_card_price( array $card ) {
	if ( ! empty( $card['choose'] ) ) {
		return array( true, (float) $card['price'] );
	}
	if ( empty( $card['variants'] ) || count( $card['variants'] ) < 2 ) {
		return array( false, (float) $card['price'] );
	}
	$prices = array_map( 'floatval', wp_list_pluck( $card['variants'], 'price' ) );
	return array( min( $prices ) !== max( $prices ), min( $prices ) );
}

/**
 * Product lists (shop, categories, archives, home page carousels): the options of a variable product with one choice to
 * make, cached per request. Null when the product needs its own page (several choices, "any" options, nothing in stock).
 */
function bc_vp_loop_card( $product ) {
	static $cache = array();
	if ( ! $product instanceof WC_Product_Variable || ! function_exists( 'bc_cart_comp_variants' ) || ! apply_filters( 'bc_vp_loop', true, $product ) ) {
		return null;
	}
	$id = $product->get_id();
	if ( ! array_key_exists( $id, $cache ) ) {
		$card = null;
		if ( $product->is_purchasable() && $product->is_in_stock() ) {
			$card = bc_cart_comp_variants( $product, array( 'variants' => array(), 'label' => '', 'choose' => false, 'selected' => 0, 'price' => 0, 'in_cart' => false ), array() );
			if ( ! empty( $card['choose'] ) || empty( $card['variants'] ) ) {
				$card = null;
			}
		}
		$cache[ $id ] = $card;
	}
	return $cache[ $id ];
}

function bc_vp_loop_on() {
	return ! is_admin() || wp_doing_ajax();
}

add_filter( 'woocommerce_product_add_to_cart_text', function ( $text, $product ) {
	return bc_vp_loop_on() && bc_vp_loop_card( $product ) ? 'افزودن به سبد خرید' : $text;
}, 20, 2 );

add_filter( 'woocommerce_product_add_to_cart_description', function ( $text, $product ) {
	return bc_vp_loop_on() && bc_vp_loop_card( $product ) ? 'افزودن «' . wp_strip_all_tags( $product->get_name() ) . '» به سبد خرید' : $text;
}, 20, 2 );

add_filter( 'woocommerce_product_add_to_cart_aria_describedby', function ( $text, $product = null ) {
	return $product && bc_vp_loop_on() && bc_vp_loop_card( $product ) ? '' : $text;
}, 20, 2 );

add_filter( 'woocommerce_loop_add_to_cart_args', function ( $args, $product ) {
	$card = bc_vp_loop_on() ? bc_vp_loop_card( $product ) : null;
	if ( ! $card ) {
		return $args;
	}
	$list = array();
	foreach ( $card['variants'] as $v ) {
		$list[] = array( 'id' => (int) $v['id'], 'label' => wp_strip_all_tags( (string) $v['label'] ), 'price' => bc_vp_money( $v['price'] ) );
	}
	$args['class'] = trim( ( $args['class'] ?? '' ) . ' bc-vp-loop' );
	$args['attributes']['data-variants'] = wp_json_encode( $list ); // escaped by wc_implode_html_attributes()
	$args['attributes']['data-vlabel']   = wp_strip_all_tags( (string) $card['label'] );
	$args['attributes']['data-vid']      = (int) $card['selected'];
	$args['attributes']['role']          = 'button';
	return $args;
}, 20, 2 );

/* Everywhere products are listed or sold, except checkout and order-pay. */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'bc_enqueue_module' ) ) {
		return;
	}
	if ( function_exists( 'bc_flow_active' ) && function_exists( 'bc_flow_page' ) && bc_flow_active() && in_array( bc_flow_page(), array( 'checkout', 'pay' ), true ) ) {
		return;
	}
	if ( is_checkout() || is_account_page() ) {
		return;
	}
	bc_enqueue_module( 'bc-vp', 'variant-picker.css', 'variant-picker.js' );
}, 10030 );
