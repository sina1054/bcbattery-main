<?php
/**
 * BC Battery - Account: customer dashboard (later phase, not part of the checkout rollout).
 * Not active until bc_flow_enabled() is true. Add hooks below; keep markup on WooCommerce hooks.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || ! function_exists( 'is_woocommerce' ) ) {
        return;
    }
    if ( ! (is_account_page()) ) {
        return;
    }
    bc_enqueue_module( 'bc-account', 'account.css', 'account.js' );
}, 20 );
