<?php
/**
 * Sheet shown after a product is added to the cart (dialog).
 * Phones: bottom sheet. Tablets: centered modal. Desktop: side panel. The script fills in the added item.
 *
 * @var array $cards Complementary product cards from bc_comp_cards() (may be empty: the section is then hidden).
 */
defined( 'ABSPATH' ) || exit;
$cards = isset( $cards ) ? (array) $cards : array();
$cur   = esc_html( get_woocommerce_currency_symbol() );
?>
<dialog class="bcp-sheet" data-bcp="sheet" aria-labelledby="bcp-sheet-title">
	<div class="bcp-sheet__box">
		<span class="bcp-sheet__grip" aria-hidden="true"></span>
		<header class="bcp-sheet__head">
			<span class="bcp-sheet__ok"><?php echo bc_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<b id="bcp-sheet-title">این کالا به سبد خرید اضافه شد</b>
			<button type="button" class="bcp-x" data-bcp-close aria-label="بستن"><?php echo bc_icon( 'close', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		</header>

		<div class="bcp-sheet__scroll">
			<div class="bcp-sheet__item">
				<span class="bcp-sheet__thumb" data-bcp="sheet-img"></span>
				<span class="bcp-sheet__info">
					<span class="bcp-sheet__name" data-bcp="sheet-name"></span>
					<span class="bcp-sheet__var" data-bcp="sheet-var" hidden></span>
					<b class="bcp-sheet__price" data-bcp="sheet-price"></b>
				</span>
			</div>

			<?php if ( $cards ) : ?>
				<section class="bcp-sheet__comp" data-bcp="comp" aria-labelledby="bcp-comp-title">
					<div class="bcp-sheet__comp-head">
						<b id="bcp-comp-title">معمولاً با این کالا می‌خرند</b>
						<span class="bcp-sheet__count"><?php echo esc_html( bc_pdp_fa( count( $cards ) ) ); ?> محصول</span>
						<span class="bcp-sheet__nav">
							<button type="button" class="bcp-round" data-bcp="comp-prev" aria-label="قبلی" disabled><?php echo bc_icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
							<button type="button" class="bcp-round" data-bcp="comp-next" aria-label="بعدی"><?php echo bc_icon( 'next', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
						</span>
					</div>

					<div class="bcp-sheet__track" data-bcp="comp-track" tabindex="0" role="region" aria-label="محصولات مکمل">
						<?php foreach ( $cards as $c ) : ?>
							<?php $vp_price = function_exists( 'bc_vp_card_price' ) ? bc_vp_card_price( $c ) : array( $c['choose'], $c['price'] ); ?>
							<article class="bcp-sc" data-pid="<?php echo (int) $c['id']; ?>" data-vid="<?php echo (int) $c['selected']; ?>"<?php echo function_exists( 'bc_vp_card_attrs' ) ? bc_vp_card_attrs( $c ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
								<a class="bcp-sc__img" href="<?php echo esc_url( $c['url'] ); ?>" tabindex="-1" aria-hidden="true"><?php echo $c['img']; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
								<div class="bcp-sc__body">
									<a class="bcp-sc__name" href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a>
									<div class="bcp-sc__price"><?php echo $vp_price[0] ? '<small>از</small> ' : ''; ?><b data-bcp="sc-price"><?php echo esc_html( bc_pdp_number( $vp_price[1] ) ); ?></b> <small><?php echo $cur; // phpcs:ignore WordPress.Security.EscapeOutput ?></small></div>
								</div>
								<?php if ( $c['choose'] ) : ?>
									<a class="bcp-btn bcp-btn--outline bcp-sc__add" href="<?php echo esc_url( $c['url'] ); ?>">انتخاب</a>
								<?php else : ?>
									<button type="button" class="bcp-btn bcp-add bcp-sc__add" data-bcp="sc-add"><?php echo bc_icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="bcp-sc__t-add">افزودن</span><span class="bcp-sc__t-done">اضافه شد</span></button>
									<?php echo bc_pdp_mini_step(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					</div>
					<div class="bcp-sheet__dots" data-bcp="comp-dots" aria-hidden="true"></div>
				</section>
			<?php endif; ?>
		</div>

		<footer class="bcp-sheet__foot">
			<a class="bcp-btn bcp-btn--primary bcp-btn--lg" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php echo bc_icon( 'bag', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>مشاهده سبد</a>
			<button type="button" class="bcp-btn bcp-btn--ghost" data-bcp-close>ادامه خرید</button>
		</footer>
	</div>
</dialog>