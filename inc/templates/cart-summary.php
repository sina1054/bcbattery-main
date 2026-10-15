<?php
/** Price summary (+ the order button on desktop). Block: [data-bc="summary"]. */
defined( 'ABSPATH' ) || exit;
?>
<div class="bc-card bc-summary" data-bc="summary">
	<?php bc_cart_summary_rows( $d ); ?>

	<?php if ( $d['blocked'] ) : ?>
		<span class="bc-btn bc-btn--primary bc-btn--lg bc-summary__cta is-disabled" role="link" aria-disabled="true">ثبت سفارش</span>
	<?php else : ?>
		<a class="bc-btn bc-btn--primary bc-btn--lg bc-summary__cta" href="<?php echo esc_url( $d['checkout'] ); ?>">ثبت سفارش</a>
	<?php endif; ?>

	<p class="bc-summary__note">کالاهای سبد هنوز رزرو نشده‌اند و تا پرداخت نهایی ممکن است ناموجود شوند.</p>
	<p class="bc-trust"><?php echo bc_icon( 'shield', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> گارانتی اصالت و سلامت · پرداخت امن با درگاه بانکی</p>
</div>
