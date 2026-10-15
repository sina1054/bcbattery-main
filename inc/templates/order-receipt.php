<?php
/**
 * The moment before the bank page. Used instead of woocommerce/checkout/order-receipt.php.
 * bc_pay_start_gateway() (inc/pay.php) has already started the gateway before the page printed anything. When the visitor can
 * read this page the gateway did not redirect: it reported a problem, or it printed something the visitor has to press.
 *
 * @var WC_Order $order
 */
defined( 'ABSPATH' ) || exit;

$bc_rc = bc_pay_receipt();
if ( ! $bc_rc['ran'] ) {
	// The early start did not run: start the gateway here, the WooCommerce way.
	ob_start();
	do_action( 'woocommerce_receipt_' . $order->get_payment_method(), $order->get_id() );
	$bc_rc['out'] = (string) ob_get_clean();
}
$bc_errors = bc_pay_take_errors();

bc_template(
	'pay-result',
	array(
		'state'    => $bc_errors ? 'gateway' : 'go',
		'order'    => $order,
		'messages' => $bc_errors,
		'before'   => '',
		'extra'    => bc_pay_plugin_html( $bc_rc['out'] ),
	)
);