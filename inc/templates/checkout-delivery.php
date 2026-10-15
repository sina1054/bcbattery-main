<?php
/**
 * Delivery methods card. Block: [data-bc="delivery"]; replaced after every update_order_review request.
 * The radios keep WooCommerce's names (shipping_method[0]) so its own script and order creation work unchanged.
 *
 * @var array $t bc_checkout_totals()
 */
defined( 'ABSPATH' ) || exit;

$s       = $t['shipping'];
$contact = bc_flow_contact();

if ( ! $s['needs'] ) {
	echo '<section data-bc="delivery" hidden></section>';
	return;
}
?>
<section class="bc-card bc-formcard bc-delivery" data-bc="delivery" aria-labelledby="bc-h-delivery">
	<h2 id="bc-h-delivery" class="bc-card__title">روش ارسال</h2>

	<?php if ( $s['rates'] ) : ?>
		<div class="bc-opts" role="radiogroup" aria-labelledby="bc-h-delivery">
			<?php foreach ( $s['rates'] as $r ) : ?>
				<?php $rid = 'shipping_method_0_' . sanitize_title( $r['id'] ); ?>
				<label class="bc-opt" for="<?php echo esc_attr( $rid ); ?>">
					<input type="radio" class="shipping_method bc-opt__input" name="shipping_method[0]" data-index="0" id="<?php echo esc_attr( $rid ); ?>" value="<?php echo esc_attr( $r['id'] ); ?>" <?php checked( $r['chosen'] ); ?>>
					<span class="bc-opt__dot" aria-hidden="true"></span>
					<span class="bc-opt__text">
						<span class="bc-opt__title"><?php echo esc_html( $r['label'] ); ?></span>
						<?php if ( '' !== $r['desc'] ) : ?>
							<span class="bc-opt__sub"><?php echo esc_html( $r['desc'] ); ?></span>
						<?php endif; ?>
					</span>
					<span class="bc-opt__cost bc-opt__cost--<?php echo esc_attr( $r['kind'] ); ?>"><?php echo bc_checkout_rate_cost_html( $r ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				</label>
			<?php endforeach; ?>
		</div>
	<?php elseif ( $s['ready'] ) : ?>
		<div class="bc-alert bc-alert--warn" role="alert">
			<?php echo bc_icon( 'alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div>
				<b>برای این آدرس روش ارسالی تعریف نشده است</b>
				<span>آدرس را بررسی کنید یا با پشتیبانی تماس بگیرید<?php echo ! empty( $contact['phone'] ) ? ': ' . esc_html( $contact['phone'] ) : ''; ?>.</span>
			</div>
		</div>
	<?php else : ?>
		<p class="bc-hint">پس از انتخاب استان و شهر، روش‌های ارسال و هزینهٔ آن‌ها اینجا نمایش داده می‌شود.</p>
	<?php endif; ?>
</section>