<?php
/**
 * Payment methods and the real (hidden) order button. Used instead of woocommerce/checkout/payment.php.
 * WooCommerce replaces .woocommerce-checkout-payment with this after every update_order_review request.
 * Class names of WooCommerce's own markup are kept (wc_payment_methods, payment_box ...): its script and the
 * gateway plugins rely on them. The visitor presses our buttons; they click #place_order.
 *
 * @var array  $available_gateways
 * @var string $order_button_text
 */
defined( 'ABSPATH' ) || exit;

$needs_payment = WC()->cart->needs_payment();
$contact       = bc_flow_contact();

if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_before_payment' );
}
?>
<div id="payment" class="woocommerce-checkout-payment bc-pay" data-bc-cta="<?php echo esc_attr( $needs_payment ? 'پرداخت' : 'ثبت سفارش' ); ?>">
	<?php if ( $needs_payment ) : ?>
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
	<?php else : ?>
		<p class="bc-hint">مبلغ این سفارش صفر است و پرداخت آنلاین لازم نیست.</p>
	<?php endif; ?>

	<div class="form-row place-order bc-place">
		<?php do_action( 'woocommerce_review_order_before_submit' ); ?>
		<?php echo apply_filters( 'woocommerce_order_button_html', '<button type="submit" class="button alt bc-place__btn" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php do_action( 'woocommerce_review_order_after_submit' ); ?>
		<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
	</div>
</div>
<?php
if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_after_payment' );
}