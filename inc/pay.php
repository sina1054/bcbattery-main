<?php
/**
 * BC Battery - Pay: payment and order-received pages (step 3 and success / fail states).
 * Not active until bc_flow_enabled() is true. Add hooks below; keep markup on WooCommerce hooks.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    if ( ! (is_checkout()) ) {
        return;
    }
    bc_enqueue_module( 'bc-pay', 'pay.css', 'pay.js' );
}, 20 );
