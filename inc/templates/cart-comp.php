<?php
/** "Complete your purchase" carousel (manual complementary products). Block: [data-bc="comp"]. */
defined( 'ABSPATH' ) || exit;

if ( empty( $d['comp'] ) ) {
	echo '<div data-bc="comp"></div>';
	return;
}
?>
<section class="bc-comp" data-bc="comp" aria-labelledby="bc-comp-title">
	<header class="bc-comp__head">
		<span class="bc-comp__badge"><?php echo bc_icon( 'gift', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		<div class="bc-comp__heading">
			<h2 class="bc-comp__title" id="bc-comp-title">خریدت رو کامل کن</h2>
			<p class="bc-comp__sub">محصولات مکمل مناسب این سفارش</p>
		</div>
		<div class="bc-comp__nav" hidden>
			<button type="button" class="bc-comp__arrow" data-bc-act="comp_prev" aria-label="قبلی"><?php echo bc_icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
			<button type="button" class="bc-comp__arrow" data-bc-act="comp_next" aria-label="بعدی"><?php echo bc_icon( 'next', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
		</div>
	</header>

	<div class="bc-comp__track" tabindex="0" role="region" aria-label="محصولات مکمل">
		<?php foreach ( $d['comp'] as $c ) : ?>
			<article class="bc-comp__card" data-pid="<?php echo (int) $c['id']; ?>" data-vid="<?php echo (int) $c['selected']; ?>">
				<a class="bc-comp__img" href="<?php echo esc_url( $c['url'] ); ?>" tabindex="-1" aria-hidden="true"><?php echo $c['img']; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				<a class="bc-comp__name" href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a>
				<div class="bc-comp__price"><?php echo $c['choose'] ? '<span class="bc-muted">از</span> ' : ''; ?><?php echo bc_money( $c['price'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>

				<?php if ( $c['variants'] ) : ?>
					<div class="bc-comp__var">
						<span class="bc-comp__label"><?php echo esc_html( $c['label'] ); ?>: <b data-bc-varname><?php foreach ( $c['variants'] as $v ) { if ( (int) $v['id'] === (int) $c['selected'] ) { echo esc_html( $v['label'] ); } } ?></b></span>
						<div class="bc-chips" role="group" aria-label="<?php echo esc_attr( $c['label'] ); ?>">
							<?php foreach ( $c['variants'] as $v ) : ?>
								<button type="button" class="bc-chip" data-bc-act="comp_var" data-vid="<?php echo (int) $v['id']; ?>" data-price="<?php echo esc_attr( number_format_i18n( round( $v['price'] ) ) ); ?>" data-in-cart="<?php echo $v['in_cart'] ? '1' : '0'; ?>" data-key="<?php echo esc_attr( $v['line'] ? $v['line']['key'] : '' ); ?>" data-qty="<?php echo (int) ( $v['line'] ? $v['line']['qty'] : 0 ); ?>" data-max="<?php echo (int) ( $v['line'] ? $v['line']['max'] : -1 ); ?>" aria-pressed="<?php echo (int) $v['id'] === (int) $c['selected'] ? 'true' : 'false'; ?>"><?php echo esc_html( $v['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $c['choose'] ) : ?>
					<a class="bc-btn bc-btn--outline bc-comp__add" href="<?php echo esc_url( $c['url'] ); ?>">انتخاب گزینه‌ها</a>
				<?php else : ?>
					<?php
					$line = $c['line'];
					if ( $c['variants'] ) { // the stepper follows the chosen variant
						$line = null;
						foreach ( $c['variants'] as $v ) {
							if ( (int) $v['id'] === (int) $c['selected'] ) {
								$line = $v['line'];
							}
						}
					}
					?>
					<button type="button" class="bc-btn bc-comp__add" data-bc-act="comp_add"<?php echo $line ? ' hidden' : ''; ?>>
						<?php echo bc_icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>افزودن</span>
					</button>
					<?php bc_comp_mini_step( $line ); ?>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
	<div class="bc-comp__dots" aria-hidden="true"></div>
</section>
