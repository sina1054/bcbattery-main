<?php
/**
 * The gateway radios of the payment step (checkout and pay-for-order). WooCommerce's class names stay: its script and the
 * gateway plugins rely on them (wc_payment_method, payment_box, input-radio ...).
 *
 * @var WC_Payment_Gateway[] $gateways
 */
defined( 'ABSPATH' ) || exit;
?>
<ul class="wc_payment_methods payment_methods methods bc-opts">
	<?php foreach ( $gateways as $gateway ) : ?>
		<?php $gid = esc_attr( $gateway->id ); ?>
		<li class="wc_payment_method payment_method_<?php echo $gid; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
			<label class="bc-opt" for="payment_method_<?php echo $gid; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<input id="payment_method_<?php echo $gid; // phpcs:ignore WordPress.Security.EscapeOutput ?>" type="radio" class="input-radio bc-opt__input" name="payment_method" value="<?php echo $gid; // phpcs:ignore WordPress.Security.EscapeOutput ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( $gateway->order_button_text ); ?>">
				<span class="bc-opt__dot" aria-hidden="true"></span>
				<span class="bc-opt__text"><span class="bc-opt__title"><?php echo wp_kses_post( $gateway->get_title() ); ?></span></span>
				<span class="bc-opt__icon"><?php echo $gateway->get_icon(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</label>
			<?php if ( $gateway->has_fields() || $gateway->get_description() ) : ?>
				<div class="payment_box payment_method_<?php echo $gid; // phpcs:ignore WordPress.Security.EscapeOutput ?>"<?php echo $gateway->chosen ? '' : ' style="display:none;"'; ?>>
					<?php $gateway->payment_fields(); ?>
				</div>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>