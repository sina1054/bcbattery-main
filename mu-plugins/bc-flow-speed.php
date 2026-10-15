<?php
/**
 * Plugin Name: BC Flow Speed
 * Description: Faster purchase flow. (1) Cart AJAX requests load only the plugins a cart change needs. (2) Query Monitor stays out of the way for visitors who are not signed in. (3) Cart and checkout pages skip three style files they never use. Turn off with define( 'BC_FLOW_SPEED_DISABLE', true ) in wp-config.php.
 * Version:     1.1.0
 * Author:      BC Battery
 *
 * خلاصه: درخواست‌های AJAX سبد خرید فقط افزونه‌های لازم را بارگذاری می‌کنند، Query Monitor برای بازدیدکننده‌ای که
 * وارد نشده خاموش است، و سه فایل استایل بی‌استفاده از سبد و تسویه حذف می‌شود.
 * خاموش کردن کامل: در wp-config.php بنویسید define( 'BC_FLOW_SPEED_DISABLE', true );
 *
 * ---------------------------------------------------------------------------------------------------------
 * PART 1 - Lighter cart AJAX requests
 *
 * Which requests: POST /?wc-ajax=<one of BC_FLOW_SPEED_ENDPOINTS>. These change or read the cart and answer
 * with cart fragments. Coupon requests are NOT touched (coupon rules can come from any plugin).
 * Never touched: wp-admin, checkout (update_order_review / checkout submit), payment gateway callbacks, REST,
 * cron, and every normal page view.
 *
 * How: during those requests the list of active plugins is filtered, so the plugins in
 * bc_flow_speed_skip_plugins() are simply not loaded. Everything else (WooCommerce, theme, shipping, gateways,
 * currency, dates, permalinks, cache, WPCode, Novamira) loads as usual. A plugin that is not in the skip list
 * (for example one installed later) always loads.
 *
 * Safety: while a request is trimmed, nothing can save the trimmed list back to the database
 * (see bc_flow_speed_protect_option), so no plugin is ever deactivated by this file.
 *
 * ---------------------------------------------------------------------------------------------------------
 * PART 2 - Query Monitor stays out of a visitor's request
 *
 * Query Monitor shows its panel to signed-in people who have permission, and to nobody else. For a visitor
 * who is not signed in it still loaded about 80 files on every request and - the expensive part - switched
 * WordPress to recording every database query together with its call stack (SAVEQUERIES).
 *
 * ---------------------------------------------------------------------------------------------------------
 * PART 3 - Lighter cart and checkout pages
 *
 * Styles that Elementor Pro adds to every page, but which nothing on the cart or checkout page uses
 * (the flow templates render these pages; the header block uses Elementor free only).
 * List: bc_flow_speed_page_styles().
 * ---------------------------------------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BC_FLOW_SPEED_DISABLE' ) && BC_FLOW_SPEED_DISABLE ) {
	return;
}

/* ========================================================================================================= */
/* PART 1 - Lighter cart AJAX requests                                                                        */
/* ========================================================================================================= */

/** wc-ajax endpoints that only read or change the cart. */
const BC_FLOW_SPEED_ENDPOINTS = array(
	'bc_pdp_cart',              // Product page: add / quantity / remove (child theme, inc/pdp-cart.php).
	'bc_cart',                  // Cart page: quantity / remove / undo / add (child theme, inc/cart.php).
	'get_refreshed_fragments',  // WooCommerce: header mini cart refresh.
	'add_to_cart',              // WooCommerce: AJAX add to cart from product lists.
	'remove_from_cart',         // WooCommerce: remove from the mini cart.
);

/**
 * Plugins that a cart request never needs (plugin file => why it is safe to skip).
 * Not in this list = always loaded. WPCode is deliberately NOT here: it always loads.
 */
function bc_flow_speed_skip_plugins() {
	return array(
		'elementor/elementor.php'                                     => 'Page builder: only draws pages.',
		'elementor-pro/elementor-pro.php'                             => 'Page builder: only draws pages.',
		'woodmart-plus/woodmart-plus.php'                             => 'Login popup, my account, SMS, WoodApp: none of it runs on a cart change.',
		'query-monitor/query-monitor.php'                             => 'Debug tool.',
		'contact-form-7/wp-contact-form-7.php'                        => 'Contact forms.',
		'ar-contactus/ar-contactus.php'                               => 'Floating contact button.',
		'classic-editor/classic-editor.php'                           => 'Admin editor.',
		'perfmatters/perfmatters.php'                                 => 'Optimises page HTML; an AJAX answer has none.',
		'sync-basalam/sync-basalam.php'                               => 'Basalam marketplace sync (admin and cron).',
		'emalls-extraction-api-official/emalls-extraction-api-official.php' => 'Emalls price feed (own API).',
		'products-extractor-for-woocommerce/wcpe.php'                 => 'Torob price feed (own API).',
		'faktor-yar/faktor-yar.php'                                   => 'Invoices for orders (admin).',
		'ux-linker/ux-linker.php'                                     => 'Internal links in content.',
		'shetabwp-analyzer-client/shetabwp-analyzer-client.php'       => 'Site analyzer client (admin).',
		'RTL-CareUnit/RTL-CareUnit.php'                               => 'Licence and update manager (admin).',
		'persian-woocommerce-sms/WoocommerceIR_SMS.php'               => 'Order SMS: sent on order events, not on cart changes.',
	);
}

/** True when this request is one of the cart AJAX requests above (decided before any plugin loads). */
function bc_flow_speed_is_cart_ajax() {
	// phpcs:disable WordPress.Security.NonceVerification -- read-only routing check; the handlers verify their own requests.
	if ( empty( $_GET['wc-ajax'] ) || ! is_string( $_GET['wc-ajax'] ) ) {
		return false;
	}
	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
		return false;
	}
	if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return false;
	}
	$endpoint = strtolower( preg_replace( '/[^a-z0-9_]/i', '', $_GET['wc-ajax'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! in_array( $endpoint, BC_FLOW_SPEED_ENDPOINTS, true ) ) {
		return false;
	}
	// Coupons: keep the full plugin set, any plugin may add coupon rules.
	$act = isset( $_POST['act'] ) && is_string( $_POST['act'] ) ? strtolower( $_POST['act'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( 'bc_cart' === $endpoint && 0 === strpos( $act, 'coupon' ) ) {
		return false;
	}
	// phpcs:enable
	return true;
}

/** Remove the skipped plugins from the list WordPress is about to load. */
function bc_flow_speed_filter_plugins( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}
	return array_values( array_diff( $plugins, array_keys( bc_flow_speed_skip_plugins() ) ) );
}

/** While a request is trimmed, never let the trimmed list be saved as the real one. */
function bc_flow_speed_protect_option( $value, $old_value ) {
	return $old_value; // Equal values: update_option() writes nothing.
}

if ( bc_flow_speed_is_cart_ajax() ) {
	define( 'BC_FLOW_SPEED_TRIMMED', true ); // Lets other code (and debugging) know this request is trimmed.
	add_filter( 'option_active_plugins', 'bc_flow_speed_filter_plugins', 1 );
	add_filter( 'pre_update_option_active_plugins', 'bc_flow_speed_protect_option', 1, 2 );
}

/* ========================================================================================================= */
/* PART 2 - Query Monitor stays out of a visitor's request                                                    */
/* ========================================================================================================= */

/**
 * Is someone signed in? Read straight from the cookie, because this is decided before WordPress has loaded
 * the user. The cookie's full name is compared, so no other cookie can be mistaken for it.
 */
function bc_flow_speed_signed_in() {
	$cookie = defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : 'wordpress_logged_in_';
	foreach ( array_keys( $_COOKIE ) as $name ) {
		if ( 0 === strpos( (string) $name, $cookie ) ) {
			return true;
		}
	}
	return false;
}

/**
 * QM_DISABLED is Query Monitor's own way of being told to stay out of a request. It is defined here, at the
 * moment WordPress reads the list of plugins, which is the last point before it loads them. The list itself
 * is handed back untouched. Sign in and Query Monitor works exactly as before; it is never deactivated, and
 * turning it off in the admin is still the cheapest option while nobody is debugging the site.
 */
function bc_flow_speed_quiet_query_monitor( $plugins ) {
	if ( ! defined( 'QM_DISABLED' ) && ! is_admin() && ! bc_flow_speed_signed_in() ) {
		define( 'QM_DISABLED', true );
	}
	return $plugins;
}
add_filter( 'option_active_plugins', 'bc_flow_speed_quiet_query_monitor', 1 );

/* ========================================================================================================= */
/* PART 3 - Lighter cart and checkout pages                                                                  */
/* ========================================================================================================= */

/** Style handles (as registered) that the cart and checkout pages do not use. */
function bc_flow_speed_page_styles() {
	return array(
		'elementor-pro-custom-rtl',             // Elementor Pro RTL helpers for Pro widgets (none on these pages).
		'elementor-custom-frontend-rtl-icons',  // Elementor Pro "rtlicons" icon font (no icon on these pages uses it).
		'widget-woocommerce-checkout-page',     // Elementor Pro checkout widget (the flow template draws the checkout).
	);
}

/** Drop those styles on the cart and checkout pages (including order-pay and order-received). */
function bc_flow_speed_dequeue_page_styles() {
	if ( is_admin() || ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) ) {
		return;
	}
	if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- never in the Elementor editor.
		return;
	}
	foreach ( bc_flow_speed_page_styles() as $handle ) {
		wp_dequeue_style( $handle );
	}
}
// Elementor adds some styles late (while it draws a block), so check again right before head and footer print.
add_action( 'wp_enqueue_scripts', 'bc_flow_speed_dequeue_page_styles', 9999 );
add_action( 'wp_print_styles', 'bc_flow_speed_dequeue_page_styles', 1 );
add_action( 'wp_print_footer_scripts', 'bc_flow_speed_dequeue_page_styles', 1 );
