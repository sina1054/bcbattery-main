<?php
/**
 * BC Battery - Checkout: address and details (step 2), one WooCommerce form.
 * Not active until bc_flow_enabled() is true. Add hooks below; keep markup on WooCommerce hooks.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    if ( ! (is_checkout() && ! is_wc_endpoint_url( "order-pay" ) && ! is_wc_endpoint_url( "order-received" )) ) {
        return;
    }
    bc_enqueue_module( 'bc-checkout', 'checkout.css', 'checkout.js' );
}, 20 );
