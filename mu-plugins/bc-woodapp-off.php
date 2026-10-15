<?php
/**
 * Plugin Name: BC WoodApp Off
 * Description: Switches off WoodApp (the mobile-app API inside "Woodmart Plus") while the shop has no app. Nothing is deleted. To turn WoodApp back on: delete or rename this file, or add define( 'BC_WOODAPP_ON', true ) to wp-config.php.
 * Version:     1.0.0
 * Author:      BC Battery
 *
 * خلاصه: WoodApp (API اپلیکیشن موبایل داخل افزونه وودمارت پلاس) خاموش است؛ هیچ داده یا تنظیمی پاک نمی‌شود.
 * روشن کردن دوباره: این فایل را حذف یا تغییر نام دهید (مثلاً bc-woodapp-off.php.off)،
 * یا در wp-config.php بنویسید: define( 'BC_WOODAPP_ON', true );
 *
 * What WoodApp adds today, and what this file turns off:
 *   - about 30 REST route groups under /wp-json/woodapp-wc-api/v1/ (registered on every REST request),
 *   - a cart loader on every request (wp_loaded), a footer hook, page-template switches for the app,
 *   - "&app_checkout_order_id=" added to the order-received link,
 *   - its admin menu, profile fields and category / colour columns.
 *
 * How: Woodmart Plus always loads WoodApp's code (it has no setting for it). This file
 *   1. removes every hook that belongs to WoodApp - functions and classes whose name contains "woodapp", plus
 *      the app's endpoint classes listed in bc_app_api_off_classes() - right after plugins load, and again on
 *      later boot steps in case WoodApp adds hooks late;
 *   2. as a safety net, drops any REST route still left under /wp-json/woodapp-wc-api/.
 * WoodApp's data (options, user and term meta) stays in the database untouched, so switching it back on
 * restores it exactly as it was.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BC_WOODAPP_ON' ) && BC_WOODAPP_ON ) {
	return;
}

/** WoodApp classes whose names do not contain "woodapp" (they only register the app's REST routes). */
function bc_app_api_off_classes() {
	return array(
		'home_app', 'home_slider_endpoint', 'main_category_endpoint', 'banner_ad_endpoint', 'category_banner_endpoint',
		'custom_section_endpoint', 'product_home_endpoint', 'blog_home_endpoint', 'footer_home_endpoint',
		'home_post_categires', 'home_product_categires', 'user_register_with_mobile', 'app_all_option',
		'get_category_list_by_id', 'plus_app_wishlist', 'plus_app_social_link', 'plus_app_edit_detial_account',
		'review_post', 'plus_app_coupon', 'plus_app_static_page', 'Wc_Api_Stories',
	);
}

/** True when a hook callback belongs to WoodApp. */
function bc_app_api_off_is_app_callback( $fn ) {
	if ( is_string( $fn ) ) {
		return false !== stripos( $fn, 'woodapp' );
	}
	if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) && is_string( $fn[1] ) ) {
		$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
		return false !== stripos( $class, 'woodapp' ) || in_array( $class, bc_app_api_off_classes(), true );
	}
	return false; // Closures: WoodApp does not use them.
}

/** Remove every WoodApp callback from every hook. Returns how many were removed.
 * (The helper names here avoid the word "woodapp" on purpose, so they do not remove themselves.) */
function bc_app_api_off_unhook() {
	global $wp_filter;
	$removed = 0;
	foreach ( $wp_filter as $hook => $handlers ) {
		if ( ! $handlers instanceof WP_Hook ) {
			continue;
		}
		foreach ( $handlers->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( bc_app_api_off_is_app_callback( $cb['function'] ) ) {
					remove_filter( $hook, $cb['function'], $priority );
					++$removed;
				}
			}
		}
	}
	return $removed;
}

// Right after all plugins have loaded, then once more on each later boot step.
add_action( 'plugins_loaded', 'bc_app_api_off_unhook', PHP_INT_MAX );
add_action( 'after_setup_theme', 'bc_app_api_off_unhook', 0 );
add_action( 'init', 'bc_app_api_off_unhook', PHP_INT_MAX );
add_action( 'rest_api_init', 'bc_app_api_off_unhook', 0 );

/** Safety net: no REST route under /woodapp-wc-api/ survives, whoever registered it. */
function bc_app_api_off_routes( $endpoints ) {
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( 0 === strpos( $route, '/woodapp-wc-api/' ) || '/woodapp-wc-api' === $route ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'bc_app_api_off_routes' );
