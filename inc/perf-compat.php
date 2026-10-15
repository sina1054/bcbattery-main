<?php
/**
 * BC Battery - compatibility with the cache / speed plugins (LiteSpeed Cache, Perfmatters).
 *
 * - Cart, checkout, order-pay, order-received and account pages are never cached (belt and braces;
 *   WooCommerce and LiteSpeed already do this, this makes it explicit and survives settings changes).
 *   Perfmatters already skips these pages by itself (is_cart / is_checkout / is_account_page).
 * - Perfmatters "remove unused CSS" only sees the HTML as delivered, so it would drop rules for classes
 *   that JavaScript adds later (.is-incart, .is-loading, ...). Every class used in the theme's own
 *   assets/css/*.css is passed to it as an excluded selector, read from the files (cached by file time).
 * Fail-safe: every callback returns its input unchanged on any problem.
 */
defined( 'ABSPATH' ) || exit;

function bc_perf_is_flow_page() {
	if ( ! function_exists( 'is_woocommerce' ) ) {
		return false;
	}
	return ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() );
}

/* LiteSpeed: never cache the flow pages. */
add_action( 'template_redirect', function () {
	if ( bc_perf_is_flow_page() ) {
		do_action( 'litespeed_control_set_nocache', 'bc purchase flow page' );
	}
}, 1 );

/* Classes used by the theme's own CSS. */
function bc_perf_theme_selectors() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$files = glob( get_stylesheet_directory() . '/assets/css/*.css' );
	$files = is_array( $files ) ? $files : array();
	sort( $files );
	$sig = '';
	foreach ( $files as $f ) {
		$sig .= basename( $f ) . filemtime( $f ) . ';';
	}
	$key  = 'bc_perf_sel_' . md5( $sig );
	$list = get_transient( $key );
	if ( ! is_array( $list ) ) {
		$names = array();
		foreach ( $files as $f ) {
			$css = file_get_contents( $f );
			if ( $css && preg_match_all( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', preg_replace( '#/\*.*?\*/#s', '', $css ), $m ) ) {
				foreach ( $m[1] as $n ) {
					$names[ $n ] = true;
				}
			}
		}
		$list = array_map( function ( $n ) {
			return '.' . $n;
		}, array_keys( $names ) );
		set_transient( $key, $list, WEEK_IN_SECONDS );
	}
	return $cache = $list;
}

add_filter( 'perfmatters_rucss_excluded_selectors', function ( $selectors ) {
	try {
		return array_values( array_unique( array_merge( (array) $selectors, bc_perf_theme_selectors() ) ) );
	} catch ( \Throwable $e ) {
		return $selectors;
	}
} );

/*
 * Perfmatters preloads: a script preloaded without its "?ver=" is not the URL the page then loads, so the
 * browser downloads it twice (jQuery, 30 KB, at high priority). Give such entries the version WordPress prints.
 */
add_filter( 'perfmatters_preloads', function ( $preloads ) {
	if ( ! is_array( $preloads ) ) {
		return $preloads;
	}
	try {
		$scripts = wp_scripts();
		foreach ( $preloads as $i => $p ) {
			if ( empty( $p['url'] ) || ! isset( $p['as'] ) || 'script' !== $p['as'] || false !== strpos( $p['url'], '?' ) ) {
				continue;
			}
			$path = wp_parse_url( $p['url'], PHP_URL_PATH );
			foreach ( $scripts->registered as $s ) {
				if ( ! is_string( $s->src ) || '' === $s->src || wp_parse_url( $s->src, PHP_URL_PATH ) !== $path ) {
					continue;
				}
				$ver = false === $s->ver ? $scripts->default_version : $s->ver;
				if ( is_string( $ver ) && '' !== $ver ) {
					$preloads[ $i ]['url'] = add_query_arg( 'ver', $ver, $p['url'] );
				}
				break;
			}
		}
	} catch ( \Throwable $e ) {
		return $preloads;
	}
	return $preloads;
} );

/*
 * ArContactUs (floating contact button) sets Roboto, a Latin font fetched from Google, on its Persian labels:
 * the browser downloads it and still draws the Persian text in a fallback font. Use the site's font instead.
 */
add_action( 'wp_enqueue_scripts', function () {
	wp_add_inline_style( 'child-style', '.arcu-widget,.arcu-widget *{font-family:inherit!important}' );
}, 10020 );

/*
 * Perfmatters "Disable cart fragmentation" + "Defer JavaScript": its loader adds WooCommerce's cart-fragments.js
 * from <head> with "async" as soon as the cart cookie exists, i.e. before the deferred jQuery and before the
 * script's own settings are printed. It fails on every page ("jQuery is not defined") and only costs a download.
 * The header cart is right without it (LiteSpeed keeps a private copy of the page for visitors with a cart, and
 * the product page refreshes the mini cart itself), so the loader is skipped: it stops when an element with its
 * id already exists. Only while both Perfmatters options are on. Off: add_filter( 'bc_block_broken_cart_fragments', '__return_false' ).
 */
add_action( 'wp_head', function () {
	$o = get_option( 'perfmatters_options' );
	if ( empty( $o['disable_woocommerce_cart_fragmentation'] ) || empty( $o['assets']['defer_js'] ) || ! apply_filters( 'bc_block_broken_cart_fragments', true ) ) {
		return;
	}
	echo '<meta id="perfmatters-cart-fragments" name="bc-cart-fragments" content="skipped">' . "\n";
}, 1 );

/*
 * ArContactUs (floating contact button): when no animation is chosen for the desktop button, the plugin prints
 * $arcuWidget.classList.add('') inside its "arcontactus.init" listener. An empty class name throws, and the
 * rest of that listener - the part that builds the button's menu - never runs. The setting belongs to the
 * plugin, so nothing is saved here: an empty value is swapped, for this request only, for a class name that
 * has no styles of its own, so the call succeeds and nothing moves. Choose an animation in the plugin's
 * settings and this does nothing at all.
 */
add_filter( 'option_arcub', function ( $value ) {
	if ( is_admin() || ! is_string( $value ) ) {
		return $value; // The settings page must see the real value.
	}
	return str_replace( '"ARCUB_ANIMATION":""', '"ARCUB_ANIMATION":"arcuNoAnimation"', $value );
} );
