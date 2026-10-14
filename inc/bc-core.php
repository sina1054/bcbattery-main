<?php
/**
 * BC Battery - shared helpers and module loader for the purchase flow (cart, checkout, pay, account).
 *
 * Each module is a small file in this folder. A module enqueues only its own CSS/JS on its own pages,
 * versions assets by file modification time (no stale cache after edits) and can be switched off.
 *
 * Rollout : option bc_flow_rollout = 'off' (default) | 'admin' (administrators only) | 'all'.
 * Preview : ?bc_flow=1 or ?bc_flow=0 for administrators, per request.
 * Kill    : define( 'BC_FLOW_DISABLED', true ) in wp-config.php.
 */
defined( 'ABSPATH' ) || exit;

function bc_flow_enabled() {
    static $on = null;
    if ( null !== $on ) {
        return $on;
    }
    if ( defined( 'BC_FLOW_DISABLED' ) && BC_FLOW_DISABLED ) {
        return $on = false;
    }
    $is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    if ( $is_admin && isset( $_GET['bc_flow'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        return $on = ( '1' === $_GET['bc_flow'] ); // phpcs:ignore WordPress.Security.NonceVerification
    }
    $mode = get_option( 'bc_flow_rollout', 'off' );
    if ( 'all' === $mode ) {
        return $on = true;
    }
    return $on = ( 'admin' === $mode && $is_admin );
}

function bc_enqueue_module( $handle, $css = '', $js = '', $deps = array() ) {
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if ( $css && file_exists( $dir . '/assets/css/' . $css ) ) {
        wp_enqueue_style( $handle, $uri . '/assets/css/' . $css, array_merge( array( 'child-style' ), $deps ), filemtime( $dir . '/assets/css/' . $css ) );
    }
    if ( $js && file_exists( $dir . '/assets/js/' . $js ) ) {
        wp_enqueue_script( $handle, $uri . '/assets/js/' . $js, $deps, filemtime( $dir . '/assets/js/' . $js ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
    }
}

/* Load modules. A failure in one module must never take the site down. */
foreach ( array( 'product-fields', 'cart', 'checkout', 'pay', 'account' ) as $bc_module ) {
    $bc_file = get_stylesheet_directory() . '/inc/' . $bc_module . '.php';
    if ( file_exists( $bc_file ) ) {
        try {
            require_once $bc_file;
        } catch ( \Throwable $e ) {
            error_log( 'BC module ' . $bc_module . ' load failed: ' . $e->getMessage() );
        }
    }
}