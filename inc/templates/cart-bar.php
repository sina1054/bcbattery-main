<?php
/** Mobile bottom bar: order button, total, and the "payment details" sheet. Block: [data-bc="bar"]. */
defined( 'ABSPATH' ) || exit;
?>
<div class="bc-bar" data-bc="bar">
	<?php if ( $d['blocked'] ) : ?>
		<span class="bc-btn bc-btn--primary bc-btn--lg bc-bar__cta is-disabled" role="link" aria-disabled="true">ثبت سفارش</span>
	<?php else : ?>
		<a class="bc-btn bc-btn--primary bc-btn--lg bc-bar__cta" href="<?php echo esc_url( $d['checkout'] ); ?>">ثبت سفارش</a>
	<?php endif; ?>
	<button type="button" class="bc-bar__total" data-bc-open="bc-sheet" aria-haspopup="dialog" aria-label="جزئیات پرداخت">
		<span class="bc-bar__count"><?php echo bc_num( $d['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> کالا <?php echo bc_icon( 'up', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		<?php echo bc_money( $d['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</button>

	<dialog class="bc-sheet" id="bc-sheet" aria-labelledby="bc-sheet-title">
		<div class="bc-sheet__grip" aria-hidden="true"></div>
		<div class="bc-sheet__head">
			<h2 id="bc-sheet-title">جزئیات پرداخت</h2>
			<button type="button" class="bc-sheet__close" data-bc-close aria-label="بستن"><?php echo bc_icon( 'close', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		</div>
		<?php bc_cart_summary_rows( $d ); ?>
		<p class="bc-sheet__note">کالاهای سبد هنوز رزرو نشده‌اند و تا پرداخت نهایی ممکن است ناموجود شوند.</p>
		<button type="button" class="bc-btn bc-btn--primary bc-btn--lg" data-bc-close>متوجه شدم</button>
	</dialog>
</div>
