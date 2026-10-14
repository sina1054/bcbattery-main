<?php
/**
 * Single product content (child theme override).
 * Enabled products (see inc/pdp.php) render the BC Battery layout built on WooCommerce hooks;
 * every other product falls through to the parent theme's template untouched.
 *
 * @package woodmart-child
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bc_pdp_enabled' ) || ! bc_pdp_enabled() ) {
	include get_template_directory() . '/woocommerce/content-single-product.php';
	return;
}

global $product;

do_action( 'woocommerce_before_single_product' ); // notices

if ( post_password_required() ) {
	echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput
	return;
}

bc_pdp_register_hooks();
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'bc-product', $product ); ?>>
	<div class="bc-wrap">
		<?php bc_pdp_breadcrumb(); ?>

		<div class="bc-top">
			<div class="bc-media">
				<?php do_action( 'woocommerce_before_single_product_summary' ); ?>
			</div>
			<div class="bc-summary summary entry-summary">
				<?php do_action( 'woocommerce_single_product_summary' ); ?>
			</div>
		</div>

		<div class="bc-below">
			<?php do_action( 'woocommerce_after_single_product_summary' ); ?>
		</div>
	</div>
</div>
<?php do_action( 'woocommerce_after_single_product' ); ?>