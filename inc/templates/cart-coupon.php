<?php
/** Coupon: collapsed link, field when opened, applied codes. Block: [data-bc="coupon"]. */
defined( 'ABSPATH' ) || exit;

if ( ! wc_coupons_enabled() ) {
	echo '<div data-bc="coupon"></div>';
	return;
}
?>
<div class="bc-coupon" data-bc="coupon">
	<?php if ( $d['coupons'] ) : ?>
		<ul class="bc-coupon__applied">
			<?php foreach ( $d['coupons'] as $c ) : ?>
				<li class="bc-coupon__chip">
					<?php echo bc_icon( 'tag', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<span>کد <b dir="ltr"><?php echo esc_html( strtoupper( $c['code'] ) ); ?></b> اعمال شد</span>
					<button type="button" class="bc-coupon__x" data-bc-act="coupon_remove" data-code="<?php echo esc_attr( $c['code'] ); ?>" aria-label="حذف کد تخفیف <?php echo esc_attr( $c['code'] ); ?>"><?php echo bc_icon( 'close', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<button type="button" class="bc-card bc-coupon__toggle" data-bc-act="coupon_toggle" aria-expanded="false" aria-controls="bc-coupon-form">
		<span class="bc-coupon__label"><?php echo bc_icon( 'tag', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo $d['coupons'] ? 'افزودن کد تخفیف دیگر' : 'کد تخفیف دارید؟'; ?></span>
		<?php echo bc_icon( 'down', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</button>

	<form class="bc-card bc-coupon__form" id="bc-coupon-form" data-bc-form="coupon_add" hidden novalidate>
		<div class="bc-coupon__head">
			<label for="bc-coupon-code">کد تخفیف</label>
			<button type="button" class="bc-link" data-bc-act="coupon_toggle">بستن</button>
		</div>
		<div class="bc-coupon__field">
			<input type="text" id="bc-coupon-code" name="code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done" aria-describedby="bc-coupon-msg">
			<button type="submit" class="bc-btn bc-btn--primary">اعمال</button>
		</div>
		<p class="bc-coupon__msg" id="bc-coupon-msg" role="alert" hidden></p>
	</form>
</div>
