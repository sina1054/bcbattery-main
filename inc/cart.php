<?php
/**
 * BC Battery - Cart: restyle of the WooCommerce cart (step 1).
 * Not active until bc_flow_enabled() is true. Add hooks below; keep markup on WooCommerce hooks.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    if ( ! (is_cart()) ) {
        return;
    }
    bc_enqueue_module( 'bc-cart', 'cart.css', 'cart.js' );
}, 20 );
