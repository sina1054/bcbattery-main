<?php
/**
 * Order summary. Used instead of woocommerce/checkout/review-order.php: WooCommerce prints it with the page and
 * replaces .woocommerce-checkout-review-order-table with it after every update_order_review request, so the
 * outer element must keep that class.
 */
defined( 'ABSPATH' ) || exit;

$t = bc_checkout_totals();
?>
<div class="woocommerce-checkout-review-order-table bc-sumblock">
	<h2 class="bc-sumblock__title">خلاصه سفارش</h2>
	<?php bc_checkout_mini( $t ); ?>
	<?php bc_checkout_summary_rows( $t ); ?>
</div>