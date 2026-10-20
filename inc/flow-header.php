<?php
/**
 * BC Battery - simple header on the checkout and order-pay pages (Notion row C3, owner approved 20 Oct).
 *
 * Woodmart's header (top bar, search, menus, account, cart) is hidden there and this one row takes its place:
 * logo (to the home page) | "پرداخت امن" in the middle | support phone. Not sticky, so the form gets the screen.
 * The cart page keeps the full header. The logo is the one the active Woodmart header uses.
 *
 * Off: add_filter( 'bc_flow_simple_header', '__return_false' ).
 */
defined( 'ABSPATH' ) || exit;

function bc_flow_simple_header_on() {
	if ( ! function_exists( 'bc_flow_active' ) || ! function_exists( 'bc_flow_page' ) || ! bc_flow_active() ) {
		return false;
	}
	return in_array( bc_flow_page(), array( 'checkout', 'pay' ), true ) && apply_filters( 'bc_flow_simple_header', true );
}

/** Logo of the active Woodmart header: [ url, width, height ], cached until the header is saved again. */
function bc_flow_header_logo() {
	$id     = get_option( 'whb_main_header' );
	$header = $id ? get_option( 'whb_' . $id ) : null;
	$found  = null;
	$walk   = function ( $node ) use ( &$walk, &$found ) {
		if ( $found || ! is_array( $node ) ) {
			return;
		}
		if ( 'logo' === ( $node['type'] ?? '' ) && ! empty( $node['params']['image']['value']['url'] ) ) {
			$found = $node['params']['image']['value'];
			return;
		}
		foreach ( (array) ( $node['content'] ?? array() ) as $child ) {
			$walk( $child );
		}
	};
	if ( is_array( $header ) && isset( $header['structure'] ) ) {
		$walk( $header['structure'] );
	}
	if ( $found ) {
		return array( $found['url'], (int) ( $found['width'] ?? 0 ), (int) ( $found['height'] ?? 0 ) );
	}
	$logo = (int) get_theme_mod( 'custom_logo' );
	$src  = $logo ? wp_get_attachment_image_src( $logo, 'full' ) : false;
	return $src ? array( $src[0], (int) $src[1], (int) $src[2] ) : null;
}

function bc_flow_header_phone() {
	$c   = function_exists( 'bc_pdp_contact' ) ? bc_pdp_contact() : array();
	$tel = ! empty( $c['wholesale'] ) ? $c['wholesale'] : ( $c['phone'] ?? '' );
	return (string) apply_filters( 'bc_flow_header_phone', preg_replace( '/[^0-9+]/', '', (string) $tel ) );
}

/** "02166170454" -> "۰۲۱-۶۶۱۷۰۴۵۴" (Persian digits; area code split off for landlines). */
function bc_flow_header_phone_label( $tel ) {
	$label = preg_match( '/^0(2\d)(\d{8})$/', $tel, $m ) ? '0' . $m[1] . '-' . $m[2] : $tel;
	return strtr( $label, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
}

add_filter( 'body_class', function ( $classes ) {
	if ( bc_flow_simple_header_on() ) {
		$classes[] = 'bc-flow--simple-head';
	}
	return $classes;
} );

add_action( 'wp_body_open', function () {
	if ( ! bc_flow_simple_header_on() ) {
		return;
	}
	$logo  = bc_flow_header_logo();
	$tel   = bc_flow_header_phone();
	$name  = get_bloginfo( 'name' );
	$icon  = function ( $name, $size ) {
		return function_exists( 'bc_icon' ) ? bc_icon( $name, $size ) : '';
	};
	echo '<header class="bc-mhead" role="banner"><div class="bc-mhead__in">';
	echo '<a class="bc-mhead__logo" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( $name . ' - صفحه اصلی' ) . '">';
	if ( $logo ) {
		echo '<img src="' . esc_url( $logo[0] ) . '" alt="" width="' . (int) $logo[1] . '" height="' . (int) $logo[2] . '" decoding="async">';
	} else {
		echo '<span class="bc-mhead__name">' . esc_html( $name ) . '</span>';
	}
	echo '</a>';
	echo '<span class="bc-mhead__secure">' . $icon( 'lock', 15 ) . 'پرداخت امن</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<div class="bc-mhead__end">';
	if ( '' !== $tel ) {
		$label = bc_flow_header_phone_label( $tel );
		echo '<a class="bc-mhead__tel" href="tel:' . esc_attr( $tel ) . '" aria-label="' . esc_attr( 'تماس با پشتیبانی ' . $label ) . '">' . $icon( 'phone', 20 ) . '<span class="bc-mhead__tel-t">پشتیبانی <b dir="ltr">' . esc_html( $label ) . '</b></span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div></div></header>';
}, 1 );

/* Woodmart's own per-page switch: no header markup at all on these pages (whb_generate_header() is skipped). */
add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key ) {
	if ( '_woodmart_header_off' !== $meta_key || is_admin() || ! did_action( 'wp' ) || ! bc_flow_simple_header_on() ) {
		return $value;
	}
	return array( '1' );
}, 10, 3 );
