<?php
/**
 * Mobile bottom bar of the checkout: main button, total, and the "payment details" sheet. Printed in the footer.
 * The total and the sheet rows are refreshed by fragments (see inc/checkout.php); the button is driven by checkout.js.
 *
 * @var array $t bc_checkout_totals()
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="bc-bar bc-bar--checkout" data-bc-bar>
	<button type="button" class="bc-btn bc-btn--primary bc-btn--lg bc-bar__cta" data-bc-next<?php echo $t['blocked'] ? ' disabled' : ''; ?>>ادامه فرایند خرید</button>
	<?php echo bc_checkout_bar_total_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

	<dialog class="bc-sheet" id="bc-sheet" aria-labelledby="bc-sheet-title">
		<div class="bc-sheet__grip" aria-hidden="true"></div>
		<div class="bc-sheet__head">
			<h2 id="bc-sheet-title">جزئیات پرداخت</h2>
			<button type="button" class="bc-sheet__close" data-bc-close aria-label="بستن"><?php echo bc_icon( 'close', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		</div>
		<?php echo bc_checkout_sheet_sum_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<p class="bc-sheet__note">کالاهای سبد هنوز رزرو نشده‌اند و تا پرداخت نهایی ممکن است ناموجود شوند.</p>
		<button type="button" class="bc-btn bc-btn--primary bc-btn--lg" data-bc-close>متوجه شدم</button>
	</dialog>
</div>