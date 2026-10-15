<?php
/**
 * BC Battery - shared pieces of the purchase flow (cart, checkout, pay, order received).
 *
 * - bc_flow_page() / bc_flow_active(): which flow page this is, and whether the new flow is on.
 * - Woodmart "Layouts" (Elementor based cart/checkout/thank-you layouts) are switched off on the flow
 *   pages and WooCommerce templates are swapped for the ones in inc/templates/ (only while the flow is on).
 * - Small helpers used by every template: bc_icon(), bc_money(), bc_flow_head(), bc_flow_steps().
 * - Zones: places in the pages where an editor can drop content built with Elementor or a Woodmart HTML block.
 * - One shared stylesheet and one shared script (flow.css / flow.js) for the whole flow.
 *
 * Nothing here runs for visitors until bc_flow_enabled() is true.
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Which page is this?                                                 */
/* ------------------------------------------------------------------ */

/** 'cart' | 'checkout' | 'pay' | 'thanks' | '' (not a flow page). Safe to call during AJAX. */
function bc_flow_page() {
	if ( ! function_exists( 'is_cart' ) ) {
		return '';
	}
	if ( is_cart() ) {
		return 'cart';
	}
	if ( is_checkout() ) {
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return 'thanks';
		}
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return 'pay';
		}
		return 'checkout';
	}
	return '';
}

function bc_flow_active() {
	return function_exists( 'bc_flow_enabled' ) && bc_flow_enabled() && '' !== bc_flow_page();
}

/* ------------------------------------------------------------------ */
/* Templates                                                           */
/* ------------------------------------------------------------------ */

/** Include inc/templates/{name}.php with $vars as local variables. */
function bc_template( $name, array $vars = array() ) {
	$file = get_stylesheet_directory() . '/inc/templates/' . $name . '.php';
	if ( ! is_readable( $file ) ) {
		return;
	}
	( static function ( $bc_file, $bc_vars ) {
		extract( $bc_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		include $bc_file;
	} )( $file, $vars );
}

function bc_template_html( $name, array $vars = array() ) {
	ob_start();
	bc_template( $name, $vars );
	return (string) ob_get_clean();
}

/* WooCommerce template => our template. Only while the flow is on for this page. */
add_filter( 'wc_get_template', function ( $template, $name ) {
	static $map = array(
		'cart/cart.php'              => 'cart',
		'cart/cart-empty.php'        => 'cart-empty',
		'checkout/form-checkout.php' => 'checkout',
		'checkout/review-order.php'  => 'review-order',
		'checkout/payment.php'       => 'payment',
		'checkout/form-pay.php'      => 'form-pay',
		'checkout/order-receipt.php' => 'order-receipt',
		'checkout/thankyou.php'      => 'thankyou',
	);
	if ( isset( $map[ $name ] ) && bc_flow_active() ) {
		$file = get_stylesheet_directory() . '/inc/templates/' . $map[ $name ] . '.php';
		if ( is_readable( $file ) ) {
			return $file;
		}
	}
	return $template;
}, 20, 2 );

/* Woodmart's Elementor-based Layouts must not take over the flow pages: remove their template_include hooks. */
add_filter( 'template_include', function ( $template ) {
	if ( bc_flow_active() ) {
		foreach ( array( 'Cart', 'Checkout', 'Thank_You_Page' ) as $name ) {
			$class = '\\XTS\\Modules\\Layouts\\' . $name;
			if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
				remove_filter( 'template_include', array( $class::get_instance(), 'override_template' ), 20 );
			}
		}
	}
	return $template;
}, 5 );

add_filter( 'body_class', function ( $classes ) {
	if ( bc_flow_active() ) {
		$classes[] = 'bc-flow';
		$classes[] = 'bc-flow--' . bc_flow_page();
	}
	return $classes;
} );

/* ------------------------------------------------------------------ */
/* Small helpers                                                       */
/* ------------------------------------------------------------------ */

/** Inline SVG icon (stroke = currentColor). */
function bc_icon( $name, $size = 20 ) {
	static $paths = array(
		'bag'     => '<path d="M6 8h12l-1 12H7z"/><path d="M9 8V6a3 3 0 016 0v2"/>',
		'pin'     => '<path d="M12 21s7-6 7-11a7 7 0 10-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		'card'    => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
		'back'    => '<path d="M9 6l6 6-6 6"/>',
		'next'    => '<path d="M15 6l-6 6 6 6"/>',
		'trash'   => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		'plus'    => '<path d="M12 5v14M5 12h14"/>',
		'minus'   => '<path d="M5 12h14"/>',
		'tag'     => '<path d="M3 9V6h18v3a3 3 0 000 6v3H3v-3a3 3 0 000-6zM10 9l4 6"/>',
		'down'    => '<path d="M6 9l6 6 6-6"/>',
		'up'      => '<path d="M6 15l6-6 6 6"/>',
		'shield'  => '<path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
		'gift'    => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8c-2 0-4-1-4-3s3-2 4 1c1-3 4-3 4-1s-2 3-4 3z"/>',
		'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
		'check'   => '<path d="M5 12l5 5 9-10"/>',
		'alert'   => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5v.5"/>',
		'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.5"/>',
		'phone'   => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
		'lock'    => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>',
		'truck'   => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
		'copy'    => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/>',
		'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'refresh' => '<path d="M20 11a8 8 0 00-14-4L4 9M4 4v5h5M4 13a8 8 0 0014 4l2-2M20 20v-5h-5"/>',
		'chat'    => '<path d="M4 5h16v11H9l-5 4V5z"/>',
		'rocket'  => '<path d="M12 3c3 1.5 5 4.5 5 8l-2.5 3.5h-5L7 11c0-3.5 2-6.5 5-8z"/><circle cx="12" cy="9.5" r="1.4"/><path d="M9.5 14.5L7 17.5l2.2.4M14.5 14.5l2.5 3-2.2.4M12 17v3.5"/>',
		'wrench'  => '<path d="M14.5 6.5a3.5 3.5 0 00-4.7 4.4L4 16.7 7.3 20l5.8-5.8a3.5 3.5 0 004.4-4.7l-2.3 2.3-2-.5-.5-2z"/>',
		'boxes'   => '<rect x="8" y="3" width="8" height="7" rx="1"/><rect x="3" y="13" width="8" height="7" rx="1"/><rect x="13" y="13" width="8" height="7" rx="1"/><path d="M12 3v3M7 13v3M17 13v3"/>',
		'file'    => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 12h6M10 16h6"/>',
		'list'    => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
		'comment' => '<path d="M20 12a8 8 0 01-11.8 7L4 20l1.2-4A8 8 0 1120 12z"/>',
		'help'    => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 114 2c-.9.6-1.5 1-1.5 2M12 17h.01"/>',
		'zoom'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/>',
		'whatsapp' => '<path d="M20 12a8 8 0 01-11.8 7L4 20l1.2-4A8 8 0 1120 12z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a3.5 3.5 0 01-1.8-1.8l.8-1-1-2L9 9.5z"/>',
		'bale'    => '<path d="M20 12a8 8 0 01-11.8 7L4 20l1.2-4A8 8 0 1120 12z"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	$size = (int) $size;
	return '<svg class="bc-i" aria-hidden="true" focusable="false" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths[ $name ] . '</svg>';
}

/** Amount as "1,250,000 تومان" (number in bold, currency small). Returns HTML. */
function bc_money( $amount, $class = '' ) {
	$amount = (float) $amount;
	$text   = ( $amount < 0 ? '-' : '' ) . number_format_i18n( abs( round( $amount ) ), 0 );
	return '<span class="bc-money' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '"><b class="bc-money__n">' . esc_html( $text ) . '</b> <span class="bc-money__u">' . esc_html( get_woocommerce_currency_symbol() ) . '</span></span>';
}

/** Digits in the visitor's language style (count text such as "2 کالا"). */
function bc_num( $n ) {
	return esc_html( number_format_i18n( (int) $n ) );
}

/** Support phone / messenger link, same source as the product page. */
function bc_flow_contact() {
	return function_exists( 'bc_pdp_contact' ) ? bc_pdp_contact() : array( 'phone' => '', 'bale' => '' );
}

/* ------------------------------------------------------------------ */
/* Page frame: heading + three-step indicator                          */
/* ------------------------------------------------------------------ */

function bc_flow_head( $title, $count = null, $back_url = '' ) {
	echo '<header class="bc-head">';
	if ( $back_url ) {
		echo '<a class="bc-head__back" href="' . esc_url( $back_url ) . '" data-bc-back aria-label="' . esc_attr__( 'بازگشت', 'woodmart' ) . '">' . bc_icon( 'back', 24 ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<h1 class="bc-head__title">' . esc_html( $title );
	if ( null !== $count ) {
		echo ' <span class="bc-head__count" data-bc="count">(' . bc_num( $count ) . ' کالا)</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</h1></header>';
}

/** $current: 1 = cart, 2 = address and details, 3 = payment. $links: step number => url for finished steps that can be revisited. */
function bc_flow_steps( $current, array $links = array() ) {
	$steps = array(
		1 => array( 'bag', 'سبد خرید' ),
		2 => array( 'pin', 'آدرس و مشخصات' ),
		3 => array( 'card', 'پرداخت' ),
	);
	echo '<ol class="bc-steps" data-bc="steps" data-step="' . (int) $current . '">';
	foreach ( $steps as $n => $s ) {
		$state = $n < $current ? 'is-done' : ( $n === (int) $current ? 'is-current' : '' );
		$link = ( $n < $current && ! empty( $links[ $n ] ) ) ? $links[ $n ] : '';
		echo '<li class="bc-steps__item ' . esc_attr( $state ) . '"' . ( $n === (int) $current ? ' aria-current="step"' : '' ) . '>';
		echo $link ? '<a class="bc-steps__link" href="' . esc_url( $link ) . '">' : '';
		echo '<span class="bc-steps__icon">' . bc_icon( $n < $current ? 'check' : $s[0], 24 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="bc-steps__label">' . esc_html( $s[1] ) . '</span>';
		echo $link ? '</a>' : '';
		echo '</li>';
		if ( $n < 3 ) {
			echo '<li class="bc-steps__line' . ( $n < $current ? ' is-done' : '' ) . '" aria-hidden="true"></li>';
		}
	}
	echo '</ol>';
}

/* ------------------------------------------------------------------ */
/* Zones: editable places                                              */
/* ------------------------------------------------------------------ */

/* Page => zones. The editor creates a Woodmart HTML block (Elementor can be used to build it) whose slug is bc-{zone}. */
function bc_flow_zones() {
	return array(
		'product'  => array( 'product-top', 'product-bottom' ),
		'cart'     => array( 'cart-top', 'cart-bottom' ),
		'checkout' => array( 'checkout-top', 'checkout-bottom' ),
		'pay'      => array( 'pay-bottom' ),
		'thanks'   => array( 'thanks-top', 'thanks-bottom' ),
	);
}

/** The published HTML block for a zone, or null. */
function bc_flow_zone_block( $zone ) {
	static $cache = array();
	if ( ! array_key_exists( $zone, $cache ) ) {
		$post           = get_page_by_path( 'bc-' . $zone, OBJECT, 'cms_block' );
		$cache[ $zone ] = ( $post && 'publish' === $post->post_status ) ? $post : null;
	}
	return $cache[ $zone ];
}

function bc_flow_zone( $zone ) {
	$block = bc_flow_zone_block( $zone );
	if ( ! $block ) {
		return;
	}
	$html = function_exists( 'woodmart_get_html_block' ) ? woodmart_get_html_block( $block->ID ) : do_shortcode( '[html_block id="' . (int) $block->ID . '"]' );
	if ( '' === trim( wp_strip_all_tags( (string) $html, true ) ) && false === strpos( (string) $html, '<img' ) ) {
		return;
	}
	echo '<div class="bc-zone bc-zone--' . esc_attr( $zone ) . '">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/** True when a zone on this page is built with Elementor (then the Elementor scripts must stay loaded). */
function bc_flow_zones_use_elementor() {
	$page = bc_flow_page();
	if ( '' === $page && function_exists( 'bc_pdp_enabled' ) && bc_pdp_enabled() ) {
		$page = 'product';
	}
	foreach ( bc_flow_zones()[ $page ] ?? array() as $zone ) {
		$block = bc_flow_zone_block( $zone );
		if ( $block && 'builder' === get_post_meta( $block->ID, '_elementor_edit_mode', true ) ) {
			return true;
		}
	}
	return false;
}

/* ------------------------------------------------------------------ */
/* Assets                                                              */
/* ------------------------------------------------------------------ */

function bc_flow_js_data() {
	return array(
		'ajax'     => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'bc_cart' ) : '',
		'nonce'    => wp_create_nonce( 'bc_flow' ),
		'page'     => bc_flow_page(),
		'cartUrl'  => wc_get_cart_url(),
		'checkout' => wc_get_checkout_url(),
		'shopUrl'  => wc_get_page_permalink( 'shop' ),
		'i18n'     => array(
			'error'   => 'ارتباط با سرور برقرار نشد. اینترنت خود را بررسی کنید و دوباره تلاش کنید.',
			'busy'    => 'در حال بروزرسانی…',
			'copied'  => 'کپی شد',
			'removed' => 'کالا از سبد حذف شد',
			'undo'    => 'بازگرداندن',
		),
	);
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! bc_flow_active() ) {
		return;
	}
	bc_enqueue_module( 'bc-flow', 'flow.css', 'flow.js' );
	wp_add_inline_script( 'bc-flow', 'window.bcFlow=' . wp_json_encode( bc_flow_js_data() ) . ';', 'before' );
}, 20 );
