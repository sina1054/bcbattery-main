<?php
/**
 * Cart page. Used instead of woocommerce/cart/cart.php while the BC flow is on.
 * Each [data-bc] block is replaced by the cart AJAX endpoint with HTML from the matching cart-*.php template.
 */
defined( 'ABSPATH' ) || exit;

$d = bc_cart_data();
?>
<div class="bc-page bc-cart" data-bc-cart>
	<?php bc_flow_zone( 'cart-top' ); ?>
	<?php bc_flow_head( 'سبد خرید', $d['count'], wc_get_page_permalink( 'shop' ) ); ?>
	<?php bc_flow_steps( 1 ); ?>
	<div class="bc-notices" data-bc="notices"><?php wc_print_notices(); ?></div>

	<div class="bc-cols">
		<div class="bc-col bc-col--main">
			<?php bc_template( 'cart-items', array( 'd' => $d ) ); ?>
			<?php bc_template( 'cart-comp', array( 'd' => $d ) ); ?>
		</div>
		<aside class="bc-col bc-col--side">
			<?php bc_template( 'cart-coupon', array( 'd' => $d ) ); ?>
			<?php bc_template( 'cart-summary', array( 'd' => $d ) ); ?>
		</aside>
	</div>
	<?php bc_flow_zone( 'cart-bottom' ); ?>
</div>
