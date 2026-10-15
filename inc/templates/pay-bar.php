<?php
/**
 * Phones: bottom bar of the payment page of an existing order (total and pay button). Printed in the footer.
 *
 * @var array $t bc_pay_totals()
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="bc-bar bc-bar--pay" data-bc-bar>
	<button type="button" class="bc-btn bc-btn--primary bc-btn--lg bc-bar__cta" data-bc-pay>پرداخت</button>
	<div class="bc-bar__total"><span class="bc-bar__count"><?php echo bc_num( $t['count'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> کالا</span><?php echo bc_money( $t['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
</div>