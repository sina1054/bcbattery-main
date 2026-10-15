<?php
/** Cart lines + the banners about stock and price changes. Block: [data-bc="items"]. */
defined( 'ABSPATH' ) || exit;

$out_count   = 0;
$price_lines = array();
foreach ( $d['rows'] as $r ) {
	if ( $r['out'] || $r['short'] ) {
		++$out_count;
	}
	if ( null !== $r['old'] ) {
		$price_lines[] = $r;
	}
}
?>
<div class="bc-items" data-bc="items">
	<?php if ( $out_count ) : ?>
		<div class="bc-alert bc-alert--err" role="alert">
			<?php echo bc_icon( 'alert', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div>
				<b><?php echo 1 === $out_count ? 'یک کالا ناموجود شد' : esc_html( bc_num( $out_count ) ) . ' کالا ناموجود شد'; ?></b>
				<span>برای ادامه خرید، کالای ناموجود را از سبد حذف کنید یا تعداد آن را کم کنید.</span>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $price_lines ) : ?>
		<div class="bc-alert bc-alert--warn" role="status">
			<?php echo bc_icon( 'info', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div>
				<b><?php echo 1 === count( $price_lines ) ? 'قیمت یک کالا تغییر کرد' : 'قیمت چند کالا تغییر کرد'; ?></b>
				<?php foreach ( $price_lines as $r ) : ?>
					<span><?php echo esc_html( wp_trim_words( $r['name'], 6, '…' ) ); ?>: از <?php echo bc_money( $r['old'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> به <?php echo bc_money( $r['unit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?> تغییر کرده است. مبلغ سفارش به‌روز شد.</span>
				<?php endforeach; ?>
				<button type="button" class="bc-btn bc-btn--warn bc-btn--sm" data-bc-act="ack_price">متوجه شدم</button>
			</div>
		</div>
	<?php endif; ?>

	<ul class="bc-lines">
		<?php foreach ( $d['rows'] as $r ) : ?>
			<li class="bc-line<?php echo $r['out'] ? ' is-out' : ''; ?><?php echo $r['short'] ? ' is-short' : ''; ?>" data-key="<?php echo esc_attr( $r['key'] ); ?>" data-qty="<?php echo (int) $r['qty']; ?>">
				<a class="bc-line__img" href="<?php echo esc_url( $r['url'] ); ?>" tabindex="-1" aria-hidden="true"><?php echo $r['img']; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				<div class="bc-line__body">
					<div class="bc-line__head">
						<a class="bc-line__title" href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['name'] ); ?></a>
						<?php if ( '' !== $r['attr'] ) : ?>
							<span class="bc-line__attr"><?php echo esc_html( $r['attr'] ); ?></span>
						<?php endif; ?>
					</div>

					<div class="bc-line__row">
						<?php if ( $r['out'] ) : ?>
							<button type="button" class="bc-btn bc-btn--danger-ghost" data-bc-act="remove"><?php echo bc_icon( 'trash', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> حذف از سبد</button>
							<span class="bc-line__flag">ناموجود</span>
						<?php else : ?>
							<div class="bc-qty" role="group" aria-label="تعداد">
								<button type="button" class="bc-qty__btn" data-bc-act="inc" aria-label="افزایش تعداد"<?php echo ( $r['max'] > -1 && $r['qty'] >= $r['max'] ) ? ' disabled' : ''; ?>><?php echo bc_icon( 'plus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
								<span class="bc-qty__n" aria-live="polite"><?php echo esc_html( number_format_i18n( $r['qty'] ) ); ?></span>
								<?php if ( $r['qty'] > 1 ) : ?>
									<button type="button" class="bc-qty__btn" data-bc-act="dec" aria-label="کاهش تعداد"><?php echo bc_icon( 'minus', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
								<?php else : ?>
									<button type="button" class="bc-qty__btn" data-bc-act="remove" aria-label="حذف از سبد"><?php echo bc_icon( 'trash', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
								<?php endif; ?>
							</div>
							<div class="bc-line__price">
								<?php if ( $r['was'] > $r['line'] ) : ?>
									<s class="bc-line__was"><?php echo esc_html( number_format_i18n( round( $r['was'] ) ) ); ?></s>
								<?php endif; ?>
								<?php echo bc_money( $r['line'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $r['out'] ) : ?>
						<p class="bc-line__note bc-line__note--err">این کالا هم‌اکنون موجود نیست. مبلغ آن از جمع سفارش کم شده است.</p>
					<?php elseif ( $r['short'] ) : ?>
						<p class="bc-line__note bc-line__note--err">
							<?php echo $r['max'] > 0 ? 'فقط ' . esc_html( number_format_i18n( $r['max'] ) ) . ' عدد از این کالا موجود است.' : 'این کالا هم‌اکنون موجود نیست.'; ?>
							<?php if ( $r['max'] > 0 ) : ?>
								<button type="button" class="bc-link" data-bc-act="set" data-qty="<?php echo (int) $r['max']; ?>">تنظیم روی <?php echo esc_html( number_format_i18n( $r['max'] ) ); ?> عدد</button>
							<?php endif; ?>
						</p>
					<?php elseif ( null !== $r['old'] ) : ?>
						<p class="bc-line__note bc-line__note--warn">قیمت قبلی: <?php echo bc_money( $r['old'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
