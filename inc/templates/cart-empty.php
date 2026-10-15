<?php
/** Empty cart. Used instead of woocommerce/cart/cart-empty.php, and as the "root" fragment when the last item is removed. */
defined( 'ABSPATH' ) || exit;

$contact = bc_flow_contact();
$shop    = apply_filters( 'bc_cart_empty_url', wc_get_page_permalink( 'shop' ) );
?>
<div class="bc-page bc-cart bc-cart--empty" data-bc-cart>
	<?php bc_flow_zone( 'cart-top' ); ?>
	<?php bc_flow_head( 'سبد خرید', null, $shop ); ?>
	<div class="bc-empty">
		<span class="bc-empty__icon"><?php echo bc_icon( 'bag', 48 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
		<h2 class="bc-empty__title">سبد خرید شما خالی است</h2>
		<p class="bc-empty__text">باتری گوشی خود را با نام مدل پیدا کنید و به سبد اضافه کنید.</p>
		<a class="bc-btn bc-btn--primary bc-btn--lg" href="<?php echo esc_url( $shop ); ?>">مشاهده باتری‌های موبایل</a>
		<?php if ( ! empty( $contact['phone'] ) ) : ?>
			<p class="bc-empty__help">مدل گوشی را نمی‌دانید؟ با پشتیبانی تماس بگیرید
				<a class="bc-empty__tel" href="tel:<?php echo esc_attr( $contact['phone'] ); ?>" dir="ltr"><?php echo bc_icon( 'phone', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $contact['phone'] ); ?></a>
			</p>
		<?php endif; ?>
	</div>
	<?php bc_flow_zone( 'cart-bottom' ); ?>
</div>
