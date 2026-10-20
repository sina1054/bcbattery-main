<?php
/**
 * Pay for an existing order: retry after a failed payment, or pay with another gateway. Used instead of
 * woocommerce/checkout/form-pay.php. Looks like step 3 of the checkout (same classes, same stylesheet).
 * WooCommerce's own names stay (#order_review, #payment, payment_method, woocommerce_pay, #place_order): its script,
 * pay_action() and the gateways rely on them. The visitor presses our button; pay.js clicks #place_order.
 *
 * @var WC_Order $order
 * @var array    $available_gateways
 * @var string   $order_button_text
 */
defined( 'ABSPATH' ) || exit;

$t        = bc_pay_totals( $order );
$cart_url = wc_get_cart_url();
$contact  = bc_flow_contact();
$terms    = wc_terms_and_conditions_checkbox_enabled();

// Start from the gateway the customer used last.
$bc_last = $order->get_payment_method();
if ( $bc_last && isset( $available_gateways[ $bc_last ] ) ) {
	foreach ( $available_gateways as $bc_gateway ) {
		$bc_gateway->chosen = ( $bc_gateway->id === $bc_last );
	}
}

// Phones: the bar is printed in the footer so "position: fixed" is never trapped inside the theme wrappers.
add_action(
	'wp_footer',
	static function () use ( $t ) {
		bc_template( 'pay-bar', array( 't' => $t ) );
	},
	5
);
?>
<div class="bc-page bc-checkout bc-checkout--order" data-bc-payorder>
	<?php bc_flow_head( 'پرداخت', null, $cart_url ); ?>
	<?php bc_flow_steps( 3, array( 1 => $cart_url, 2 => wc_get_checkout_url() ) ); ?>

	<div class="bc-notices" data-bc="notices"><?php wc_print_notices(); ?></div>

	<form id="order_review" method="post" class="bc-form" novalidate>
		<div class="bc-cols">
			<div class="bc-col bc-col--main">
				<div class="bc-stack">
					<?php do_action( 'woocommerce_pay_order_before_payment' ); ?>
					<div id="payment" class="woocommerce-checkout-payment bc-pay">
						<?php if ( $order->needs_payment() ) : ?>
							<section class="bc-card bc-formcard bc-paymethods" aria-labelledby="bc-h-pay">
								<h2 id="bc-h-pay" class="bc-card__title">روش پرداخت <span class="bc-card__sub">· <?php echo esc_html( apply_filters( 'bc_checkout_payment_sub', 'پرداخت آنلاین با کارت بانکی' ) ); ?></span></h2>
								<?php if ( $available_gateways ) : ?>
									<?php bc_template( 'gateway-list', array( 'gateways' => $available_gateways ) ); ?>
								<?php else : ?>
									<div class="bc-alert bc-alert--warn" role="alert">
										<?php echo bc_icon( 'alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<div>
											<b>در حال حاضر روش پرداختی در دسترس نیست</b>
											<span>لطفاً کمی بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید<?php echo ! empty( $contact['phone'] ) ? ': ' . esc_html( $contact['phone'] ) : ''; ?>.</span>
										</div>
									</div>
								<?php endif; ?>
							</section>
						<?php endif; ?>

						<div class="form-row place-order bc-place">
							<input type="hidden" name="woocommerce_pay" value="1">
							<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>
							<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt bc-place__btn" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>
							<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
						</div>
					</div>
				</div>
			</div>

			<aside class="bc-col bc-col--side">
				<div class="bc-sidecard">
					<div class="bc-sumblock">
						<h2 class="bc-sumblock__title">خلاصه سفارش</h2>
						<?php bc_pay_items( $order ); ?>
						<?php bc_checkout_summary_rows( $t ); ?>
					</div>

					<?php if ( $terms ) : ?>
						<div class="bc-terms" data-bc-terms>
							<label class="bc-check" for="terms">
								<input type="checkbox" id="terms" name="terms" value="1" class="bc-check__input"<?php checked( (bool) apply_filters( 'bc_terms_checked_default', true ) ); // ticked by default ?>>
								<span class="bc-check__box" aria-hidden="true"><?php echo bc_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="bc-check__text"><?php wc_terms_and_conditions_checkbox_text(); ?></span>
							</label>
							<input type="hidden" name="terms-field" value="1">
						</div>
					<?php endif; ?>

					<button type="button" class="bc-btn bc-btn--primary bc-btn--lg bc-summary__cta" data-bc-pay>پرداخت</button>
					<p class="bc-summary__note">پرداخت در درگاه امن بانکی انجام می‌شود.</p>
					<p class="bc-trust"><?php echo bc_icon( 'shield', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> گارانتی اصالت · پرداخت امن<?php echo ! empty( $contact['phone'] ) ? ' · پشتیبانی ' . esc_html( $contact['phone'] ) : ''; ?></p>
				</div>
			</aside>
		</div>
	</form>

	<?php bc_flow_zone( 'pay-bottom' ); ?>
</div>