<?php
/**
 * BC Battery - asset hygiene for the purchase flow (product > cart > checkout > pay > order received).
 *
 * Stops scripts/styles that nothing on these pages uses from loading. Only LEAF assets are
 * dequeued: if another enqueued asset depends on a handle, WordPress still loads it, so a wrong
 * entry can never break the dependency chain. Everything is data-driven (see bc_asset_rules())
 * and filterable with 'bc_asset_hygiene_rules'. Disable all of it with BC_ASSET_HYGIENE_DISABLED.
 */
defined( 'ABSPATH' ) || exit;

/* Which part of the flow is this request? '' when it is not a flow page. */
function bc_flow_context() {
	static $ctx = null;
	if ( null !== $ctx || ! did_action( 'wp' ) ) {
		return (string) $ctx;
	}
	$ctx = '';
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return $ctx;
	}
	if ( is_checkout_pay_page() ) {
		$ctx = 'pay';
	} elseif ( is_order_received_page() ) {
		$ctx = 'thanks';
	} elseif ( is_checkout() ) {
		$ctx = 'checkout';
	} elseif ( is_cart() ) {
		$ctx = 'cart';
	} elseif ( is_product() && function_exists( 'bc_pdp_enabled' ) && bc_pdp_enabled() ) {
		$ctx = 'product';
	}
	return $ctx;
}

/**
 * Rules: group => array( contexts, scripts, styles ). 'when' is an optional callback that must
 * return true for the group to apply.
 */
function bc_asset_rules() {
	$all   = array( 'product', 'cart', 'checkout', 'pay', 'thanks' );
	$flow  = array( 'cart', 'checkout', 'pay', 'thanks' );
	$rules = array(
		// Contact Form 7 is not used on these pages.
		'cf7'          => array(
			'ctx'     => $all,
			'scripts' => array( 'contact-form-7', 'swv' ),
			'styles'  => array( 'contact-form-7' ),
		),
		// Woodmart widget that only the home page / builder uses.
		'wd-amazing'   => array(
			'ctx'     => $all,
			'scripts' => array( 'woodmart-product-amazing', 'woodmart-product-amazing-swiper' ),
			'styles'  => array( 'woodmart-product-amazing' ),
		),
		'comment-reply' => array(
			'ctx'     => $all,
			'scripts' => array( 'comment-reply' ),
			'styles'  => array(),
		),
		// Header/footer are Woodmart elements (no Elementor JS needed). Skipped when the page itself is Elementor.
		'elementor-js' => array(
			'ctx'     => $all,
			'scripts' => array( 'elementor-frontend', 'elementor-frontend-modules', 'elementor-webpack-runtime', 'elementor-pro-frontend', 'elementor-pro-webpack-runtime', 'pro-elements-handlers', 'wd-elementor-integration', 'alfa_swiper_js', 'swiper', 'elementor-waypoints', 'e-sticky' ),
			'styles'  => array(),
			'when'    => 'bc_asset_page_not_elementor',
		),
		// Password strength meter: guests check out without a password.
		'password'     => array(
			'ctx'     => $flow,
			'scripts' => array( 'zxcvbn-async', 'password-strength-meter', 'wc-password-strength-meter' ),
			'styles'  => array(),
		),
		// Floating contact button and Woodmart sticky social: hidden in the purchase flow by design.
		'floating'     => array(
			'ctx'     => $flow,
			'scripts' => array( 'contactus', 'contactus.scripts', 'wd-sticky-social-buttons' ),
			'styles'  => array( 'contactus.css', 'contactus.generated.desktop.css', 'contactus.generated.mobile.css', 'wd-sticky-social-buttons', 'wd-social-icons', 'wd-social-icons-styles', 'wd-bottom-toolbar' ),
		),
		// Persian Woo Shipping map (OSM/Leaflet): only when the map option is switched on.
		'pws-map'      => array(
			'ctx'     => array( 'checkout', 'pay', 'thanks' ),
			'scripts' => array( 'pws-map-leaflet', 'pws-map-general', 'pws-map-OSM' ),
			'styles'  => array( 'pws-map-leaflet' ),
			'when'    => 'bc_asset_pws_map_off',
		),
		'pwsms'        => array(
			'ctx'     => $flow,
			'scripts' => array( 'pwoosms-multiselect' ),
			'styles'  => array( 'pwoosms-multiselect' ),
		),
		// The cart page is our own markup: WooCommerce's cart script (shipping calculator, country/state selects) and the
		// Woodmart cart-table styles are not used. Only while the new flow is on; the native cart page needs them.
		'cart-page'    => array(
			'ctx'     => array( 'cart' ),
			'scripts' => array( 'wc-cart', 'wc-country-select', 'wc-address-i18n', 'selectWoo', 'select2', 'wc-add-to-cart', 'wd-cart-quantity', 'wd-woocommerce-wrapp-table' ),
			'styles'  => array( 'select2', 'wd-select2', 'wd-woo-mod-shop-table', 'wd-page-cart', 'wd-woo-page-cart-predefined', 'wd-woo-opt-fbt-cart', 'wd-woo-mod-cart-labels', 'wd-woo-opt-hide-larger-price', 'wd-woo-mod-shop-attributes' ),
			'when'    => 'bc_flow_active',
		),
		// City select (WP-Parsidate) and Woodmart-Plus extra-field styles: only the checkout form uses them.
		'checkout-only' => array(
			'ctx'     => array( 'product', 'cart', 'pay', 'thanks' ),
			'scripts' => array( 'wc-city-select' ),
			'styles'  => array( 'wp-wc-extra-field' ),
		),
		// Result pages and the payment page of an order are our own markup: no address form (country/state/city selects,
		// Persian Woo Shipping), no WooCommerce/Woodmart checkout or thank-you styles. Only while the new flow is on.
		'pay-pages'    => array(
			'ctx'     => array( 'pay', 'thanks' ),
			'scripts' => array( 'wc-country-select', 'wc-address-i18n', 'selectWoo', 'select2', 'pwsCheckout', 'pwsCities', 'checkout-fields', 'wd-woocommerce-wrapp-table' ),
			'styles'  => array( 'select2', 'wd-select2', 'wd-woo-mod-shop-table', 'wd-page-checkout', 'wd-page-checkout-payment-methods', 'wd-woo-page-checkout-predefined', 'wd-woo-opt-manage-checkout-prod', 'wd-woo-page-checkout-builder', 'widget-woocommerce-checkout-page', 'wd-woo-thank-you-page', 'wd-woo-thank-you-page-predefined', 'wd-woo-mod-order-details', 'wd-woo-opt-fbt-cart', 'wd-woo-mod-cart-labels', 'wd-woo-opt-hide-larger-price', 'wd-woo-mod-shop-attributes' ),
			'when'    => 'bc_flow_active',
		),
		// The order-received page has no form: WooCommerce's checkout script has nothing to do there.
		'thanks-js'    => array(
			'ctx'     => array( 'thanks' ),
			'scripts' => array( 'wc-checkout', 'wc-custom-place-order-button' ),
			'styles'  => array(),
			'when'    => 'bc_flow_active',
		),
		// The new product page has its own gallery, so Woodmart/WooCommerce gallery code is dead weight.
		'pdp-gallery'  => array(
			'ctx'     => array( 'product' ),
			'scripts' => array( 'wc-zoom', 'wd-product-images-gallery', 'wd-photoswipe', 'photoswipe', 'photoswipe-ui-default' ),
			'styles'  => array( 'photoswipe', 'photoswipe-default-skin', 'wd-photoswipe', 'wd-mfp-popup' ),
		),
		// The new product page adds to the cart itself (Store API) and opens its own sheet: the theme's add-to-cart scripts are not used.
		'pdp-cart'     => array(
			'ctx'     => array( 'product' ),
			'scripts' => array( 'wc-add-to-cart', 'wd-add-to-cart-all-types', 'wd-action-after-add-to-cart' ),
			'styles'  => array(),
		),
		// WooCommerce's single-product script drives its gallery, tabs and rating stars. The new product page has its
		// own gallery and sections, and with review ratings switched off there are no stars either.
		'pdp-wc-single' => array(
			'ctx'     => array( 'product' ),
			'scripts' => array( 'wc-single-product' ),
			'styles'  => array(),
			'when'    => 'bc_asset_no_review_rating',
		),
		// Variation scripts are only needed for variable products.
		'pdp-simple'   => array(
			'ctx'     => array( 'product' ),
			'scripts' => array( 'wc-add-to-cart-variation', 'wd-swatches-variations', 'wd-variations-price' ),
			'styles'  => array(),
			'when'    => 'bc_asset_product_is_simple',
		),
	);
	return apply_filters( 'bc_asset_hygiene_rules', $rules );
}

function bc_asset_page_not_elementor() {
	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		return true;
	}
	$id = get_queried_object_id();
	if ( ! $id ) {
		return true;
	}
	$doc = \Elementor\Plugin::$instance->documents->get( $id );
	if ( function_exists( 'bc_flow_zones_use_elementor' ) && bc_flow_zones_use_elementor() ) {
		return false; // A flow zone (Woodmart HTML block) is built with Elementor.
	}
	return ! ( $doc && $doc->is_built_with_elementor() );
}
function bc_asset_pws_map_off() {
	return ! get_option( 'pws_map' );
}
function bc_asset_no_review_rating() {
	return 'yes' !== get_option( 'woocommerce_enable_review_rating' );
}
function bc_asset_product_is_simple() {
	$p = is_product() ? wc_get_product( get_queried_object_id() ) : null;
	return $p && ! $p->is_type( array( 'variable', 'variation', 'grouped' ) );
}

/* Dequeue. Runs again late because some plugins enqueue in wp_footer. */
function bc_asset_hygiene_run() {
	if ( defined( 'BC_ASSET_HYGIENE_DISABLED' ) && BC_ASSET_HYGIENE_DISABLED ) {
		return;
	}
	$ctx = bc_flow_context();
	if ( '' === $ctx ) {
		return;
	}
	// Woodmart Plus makes Swiper (38 KB) a dependency of its script, for its home-page slider only. A dependency
	// cannot be dequeued, so it is unlinked first; the 'elementor-js' rule then drops it (filter: bc_asset_hygiene_unlink).
	$scripts = wp_scripts();
	foreach ( (array) apply_filters( 'bc_asset_hygiene_unlink', array( 'w-plus' => array( 'alfa_swiper_js' ) ), $ctx ) as $handle => $deps ) {
		if ( isset( $scripts->registered[ $handle ] ) ) {
			$scripts->registered[ $handle ]->deps = array_values( array_diff( (array) $scripts->registered[ $handle ]->deps, (array) $deps ) );
		}
	}
	foreach ( bc_asset_rules() as $rule ) {
		if ( ! in_array( $ctx, $rule['ctx'], true ) ) {
			continue;
		}
		if ( ! empty( $rule['when'] ) && ! call_user_func( $rule['when'] ) ) {
			continue;
		}
		foreach ( $rule['scripts'] as $h ) {
			wp_dequeue_script( $h );
		}
		foreach ( $rule['styles'] as $h ) {
			wp_dequeue_style( $h );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'bc_asset_hygiene_run', 9999 );
add_action( 'wp_enqueue_scripts', 'bc_asset_hygiene_run', 10200 ); // Woodmart enqueues its page styles (wd-*) at 10100.
add_action( 'wp_footer', 'bc_asset_hygiene_run', 0 );
add_action( 'wp_print_footer_scripts', 'bc_asset_hygiene_run', 0 );

/* Purchase flow: no floating contact button, social bar, bottom toolbar or sidebar toggle in the markup either. */
add_action( 'wp', function () {
	if ( defined( 'BC_ASSET_HYGIENE_DISABLED' ) && BC_ASSET_HYGIENE_DISABLED ) {
		return;
	}
	if ( ! in_array( bc_flow_context(), array( 'cart', 'checkout', 'pay', 'thanks' ), true ) ) {
		return;
	}
	remove_action( 'woodmart_before_wp_footer', 'woodmart_get_sticky_social', 200 );
	remove_action( 'woodmart_before_wp_footer', 'woodmart_sticky_toolbar_template', 250 );
	remove_action( 'woodmart_before_wp_footer', 'woodmart_sticky_sidebar_button', 200 );
	global $wp_filter;
	if ( isset( $wp_filter['wp_footer'] ) ) {
		foreach ( $wp_filter['wp_footer']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && 'ArContactUs' === get_class( $fn[0] ) ) {
					remove_action( 'wp_footer', $fn, $priority );
				}
			}
		}
	}
}, 20 );
