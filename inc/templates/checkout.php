<?php
/**
 * Checkout page. Used instead of woocommerce/checkout/form-checkout.php while the BC flow is on.
 * One WooCommerce form, two views: "details" (step 2) and "pay" (step 3). assets/js/checkout.js switches the view
 * (data-view on the root); CSS shows what belongs to the current view.
 *
 * @var WC_Checkout $checkout
 */
defined( 'ABSPATH' ) || exit;

// The bank said "no" and the gateway sent the visitor back here: show the result screen instead of the form (see inc/pay.php).
$bc_fail = bc_pay_failure();
if ( $bc_fail ) {
	bc_template( 'pay-result', array( 'state' => 'fail', 'order' => $bc_fail['order'], 'messages' => $bc_fail['messages'], 'before' => '', 'extra' => '' ) );
	return;
}

$t        = bc_checkout_totals();
$cart_url = wc_get_cart_url();
$terms    = bc_checkout_terms();
$notes    = $checkout->get_checkout_fields( 'order' );
$contact  = bc_flow_contact();
?>
<div class="bc-page bc-checkout" data-bc-checkout data-view="details">
	<?php bc_flow_zone( 'checkout-top' ); ?>

	<div class="bc-view-details">
		<?php bc_flow_head( 'تکمیل سفارش', null, $cart_url ); ?>
		<?php bc_flow_steps( 2, array( 1 => $cart_url ) ); ?>
	</div>
	<div class="bc-view-pay">
		<?php bc_flow_head( 'پرداخت', null, '#details' ); ?>
		<?php bc_flow_steps( 3, array( 1 => $cart_url, 2 => '#details' ) ); ?>
	</div>

	<div class="bc-notices" data-bc="notices"><?php wc_print_notices(); ?></div>

	<?php if ( $t['blocked'] ) : ?>
		<div class="bc-alert bc-alert--err" role="alert">
			<?php echo bc_icon( 'alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div>
				<b>یک یا چند کالای سبد موجود نیست</b>
				<span>برای ادامه، ابتدا سبد خرید را اصلاح کنید.</span>
				<a class="bc-btn bc-btn--danger-ghost" href="<?php echo esc_url( $cart_url ); ?>">بازگشت به سبد خرید</a>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $t['changed'] ) : ?>
		<div class="bc-alert bc-alert--warn" role="status" data-bc-price-alert>
			<?php echo bc_icon( 'info', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div>
				<b><?php echo 1 === count( $t['changed'] ) ? 'قیمت یک کالا تغییر کرد' : 'قیمت چند کالا تغییر کرد'; ?></b>
				<span>مبلغ سفارش به‌روز شد؛ جمع نهایی را در همین صفحه ببینید.</span>
				<button type="button" class="bc-btn bc-btn--warn bc-btn--sm" data-bc-ack>متوجه شدم</button>
			</div>
		</div>
	<?php endif; ?>

	<?php /* Filled by checkout.js: field problems found in the browser or reported by the server. */ ?>
	<div class="bc-alert bc-alert--err" data-bc-errors role="alert" tabindex="-1" hidden>
		<?php echo bc_icon( 'alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div><b data-bc-errors-title></b><span data-bc-errors-text></span></div>
	</div>

	<form name="checkout" method="post" class="checkout woocommerce-checkout bc-form" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" novalidate>

		<?php /* Phones: the order in short, collapsed. */ ?>
		<details class="bc-acc bc-view-details" data-bc-acc>
			<summary class="bc-acc__head">
				<span class="bc-acc__label">خلاصه سفارش (<?php echo bc_num( $t['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> کالا) <?php echo bc_icon( 'down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php echo bc_checkout_acc_total_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</summary>
			<?php bc_checkout_mini( $t ); ?>
		</details>

		<div class="bc-cols">
			<div class="bc-col bc-col--main">

				<div class="bc-stack bc-view-details">
					<section class="bc-card bc-formcard" aria-labelledby="bc-h-contact">
						<h2 id="bc-h-contact" class="bc-card__title">اطلاعات تماس</h2>
						<div class="bc-fields">
							<?php bc_checkout_name_field( $checkout ); ?>
							<?php bc_checkout_field( $checkout, 'billing_phone' ); ?>
						</div>
					</section>

					<section class="bc-card bc-formcard" aria-labelledby="bc-h-address">
						<h2 id="bc-h-address" class="bc-card__title">آدرس تحویل</h2>
						<div class="bc-fields">
							<?php
							foreach ( array( 'billing_country', 'billing_state', 'billing_city', 'billing_district', 'billing_address_1', 'billing_postcode', 'billing_email' ) as $bc_key ) {
								bc_checkout_field( $checkout, $bc_key );
							}
							?>
						</div>
					</section>

					<?php bc_template( 'checkout-delivery', array( 't' => $t ) ); ?>

					<?php if ( $notes ) : ?>
						<section class="bc-card bc-formcard" aria-labelledby="bc-h-notes">
							<h2 id="bc-h-notes" class="bc-card__title">توضیحات سفارش</h2>
							<div class="bc-fields">
								<?php
								foreach ( $notes as $bc_key => $bc_field ) {
									woocommerce_form_field( $bc_key, $bc_field, $checkout->get_value( $bc_key ) );
								}
								?>
							</div>
						</section>
					<?php endif; ?>
				</div>

				<div class="bc-stack bc-view-pay">
					<?php woocommerce_checkout_payment(); ?>
				</div>

			</div>

			<aside class="bc-col bc-col--side">
				<div class="bc-sidecard">
					<?php woocommerce_order_review(); ?>

					<?php if ( $terms ) : ?>
						<div class="bc-terms bc-view-pay" data-bc-terms>
							<label class="bc-check" for="bc_terms">
								<input type="checkbox" id="bc_terms" name="bc_terms" value="1" class="bc-check__input">
								<span class="bc-check__box" aria-hidden="true"><?php echo bc_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
								<span class="bc-check__text"><?php echo bc_checkout_terms_text( $terms ); // phpcs:ignore WordPress.Security.EscapeOutput ?> را مطالعه کرده‌ام و می‌پذیرم.</span>
							</label>
						</div>
					<?php endif; ?>

					<button type="button" class="bc-btn bc-btn--primary bc-btn--lg bc-summary__cta" data-bc-next<?php echo $t['blocked'] ? ' disabled' : ''; ?>>ادامه فرایند خرید</button>
					<p class="bc-summary__note">کالاهای سبد هنوز رزرو نشده‌اند و تا پرداخت نهایی ممکن است ناموجود شوند.</p>
					<p class="bc-trust"><?php echo bc_icon( 'shield', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> گارانتی اصالت · پرداخت امن<?php echo ! empty( $contact['phone'] ) ? ' · پشتیبانی ' . esc_html( $contact['phone'] ) : ''; ?></p>
				</div>
			</aside>
		</div>

		<?php do_action( 'woocommerce_checkout_after_customer_details' ); // Order attribution (WooCommerce analytics) adds its hidden fields here. ?>
	</form>

	<?php bc_flow_zone( 'checkout-bottom' ); ?>
</div>
<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>