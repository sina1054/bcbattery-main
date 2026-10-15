<?php
/**
 * Order received. Used instead of woocommerce/checkout/thankyou.php while the BC flow is on.
 * Keeps the hooks plugins rely on (woocommerce_before_thankyou, woocommerce_thankyou_{gateway}, woocommerce_thankyou);
 * the screen itself is pay-result.php.
 *
 * @var WC_Order|false $order
 */
defined( 'ABSPATH' ) || exit;

$state  = bc_pay_thanks_state( $order );
$before = '';
$extra  = '';
if ( $order instanceof WC_Order ) {
	ob_start();
	do_action( 'woocommerce_before_thankyou', $order->get_id() );
	$before = bc_pay_plugin_html( ob_get_clean() );
	ob_start();
	do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
	do_action( 'woocommerce_thankyou', $order->get_id() );
	$extra = bc_pay_plugin_html( ob_get_clean() );
}

// The facts card says what a gateway's "payment succeeded" notice says; other notices stay.
$bc_notices = wc_get_notices();
unset( $bc_notices['success'] );
wc_set_notices( $bc_notices );
bc_pay_mark( false );

bc_template( 'pay-result', array( 'state' => $state, 'order' => $order, 'messages' => array(), 'before' => $before, 'extra' => $extra ) );