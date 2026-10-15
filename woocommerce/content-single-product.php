<?php
/**
 * Single product content (child theme override).
 * Products with the BC page on (see inc/pdp.php) render the layout built on WooCommerce hooks;
 * every other product falls through to the parent theme's template untouched.
 *
 * Editable zones (Woodmart HTML blocks, buildable with Elementor): bc-product-top, bc-product-bottom.
 *
 * @package woodmart-child
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bc_pdp_register_hooks' ) ) { // another theme's page code is active: use that theme's template
	$bc_live = get_theme_root() . '/woodmart-child/woocommerce/content-single-product.php';
	include is_readable( $bc_live ) ? $bc_live : get_template_directory() . '/woocommerce/content-single-product.php';
	return;
}
if ( ! function_exists( 'bc_pdp_enabled' ) || ! bc_pdp_enabled() ) {
	include get_template_directory() . '/woocommerce/content-single-product.php';
	return;
}

global $product;

do_action( 'woocommerce_before_single_product' ); // notices, Woodmart extra content (position "before")

if ( post_password_required() ) {
	echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput
	return;
}

bc_pdp_register_hooks();
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'bcp', $product ); ?>>
	<?php bc_pdp_zone( 'product-top' ); ?>

	<div class="bcp-wrap">
		<div class="bcp-top">
			<div class="bcp-media">
				<?php do_action( 'woocommerce_before_single_product_summary' ); ?>
			</div>

			<div class="bcp-card">
				<span class="bcp-grab" aria-hidden="true"></span>
				<?php do_action( 'woocommerce_single_product_summary' ); ?>

				<div class="bcp-below">
					<?php do_action( 'woocommerce_after_single_product_summary' ); ?>
				</div>
			</div>
		</div>
	</div>

	<?php do_action( 'woodmart_woocommerce_after_sidebar' ); // Woodmart: product extra content (position "prefooter") ?>
	<?php bc_pdp_zone( 'product-bottom' ); ?>
</div>
<?php do_action( 'woocommerce_after_single_product' ); ?>