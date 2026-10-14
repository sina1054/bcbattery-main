<?php
/**
 * BC Battery - single product page (PDP), built on WooCommerce hooks.
 *
 * Enabled products skip the Woodmart/Elementor single-product layout (nothing in Elementor is edited)
 * and render woocommerce/content-single-product.php from this child theme instead.
 *
 * Rollout : option bc_pdp_rollout = 'ids' (default; bc_pdp_rollout_ids, default [28968]) or 'all'.
 * Preview : ?bc_pdp=1 / ?bc_pdp=0 for administrators, on any product.
 * Kill    : define( 'BC_PDP_DISABLED', true ) in wp-config.php.
 * Contact : filter / option bc_pdp_contact = array( 'phone' => '...', 'bale' => 'https://...' ).
 *
 * Site-wide fix (all products): <h1> inside product descriptions becomes <h2> (one H1 per page).
 */
defined( 'ABSPATH' ) || exit;

function bc_pdp_enabled() {
	static $on = null;
	if ( null !== $on ) {
		return $on;
	}
	if ( defined( 'BC_PDP_DISABLED' ) && BC_PDP_DISABLED ) {
		return $on = false;
	}
	if ( ! function_exists( 'is_product' ) || ! did_action( 'wp' ) || ! is_product() ) {
		return false;
	}
	if ( isset( $_GET['bc_pdp'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $on = ( '1' === sanitize_text_field( wp_unslash( $_GET['bc_pdp'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( 'all' === get_option( 'bc_pdp_rollout', 'ids' ) ) {
		return $on = true;
	}
	$ids = array_map( 'absint', (array) get_option( 'bc_pdp_rollout_ids', array( 28968 ) ) );
	return $on = in_array( (int) get_queried_object_id(), $ids, true );
}

function bc_pdp_contact() {
	return wp_parse_args(
		(array) apply_filters( 'bc_pdp_contact', (array) get_option( 'bc_pdp_contact', array() ) ),
		array(
			'phone' => '09109777350',
			'bale'  => 'https://ble.ir/bc_battery',
		)
	);
}

function bc_pdp_icon( $name ) {
	$p = array(
		'shield' => '<path d="M12 3l7 3v5c0 5-3.5 8.5-7 10-3.5-1.5-7-5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/>',
		'truck'  => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
		'bolt'   => '<path d="M13 2L5 14h6l-1 8 8-12h-6l1-8z"/>',
		'users'  => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 5a3 3 0 010 6M18 14c2 .7 3 2.6 3 6"/>',
		'gift'   => '<path d="M4 10h16v10H4z"/><path d="M3 7h18v3H3zM12 7v13"/><path d="M12 7c-2-4-6-3-5 0M12 7c2-4 6-3 5 0"/>',
		'phone'  => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
		'chat'   => '<path d="M4 5h16v11H9l-5 4V5z"/>',
	);
	$d = isset( $p[ $name ] ) ? $p[ $name ] : '';
	return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/* ------------------------------------------------------------------ */
/* 1. Body class, assets, bypass of the Woodmart builder layout        */
/* ------------------------------------------------------------------ */
add_filter( 'body_class', function ( $classes ) {
	if ( bc_pdp_enabled() ) {
		$classes[] = 'bc-pdp';
	}
	return $classes;
} );

add_filter( 'template_include', function ( $template ) {
	if ( bc_pdp_enabled() && class_exists( '\\XTS\\Modules\\Layouts\\Single_Product' ) ) {
		remove_filter( 'template_include', array( \XTS\Modules\Layouts\Single_Product::get_instance(), 'override_template' ), 20 );
	}
	return $template;
}, 5 );

add_action( 'wp', function () {
	if ( ! bc_pdp_enabled() ) {
		return;
	}
	// Replace Woodmart's scroll-triggered sticky bar and bottom toolbar with the always-visible buy bar.
	remove_action( 'woodmart_before_wp_footer', 'woodmart_sticky_single_add_to_cart', 999 );
	remove_action( 'woodmart_before_wp_footer', 'woodmart_sticky_toolbar_template', 250 );
	add_action( 'wp_footer', 'bc_pdp_render_buybar', 5 );
}, 30 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! bc_pdp_enabled() ) {
		return;
	}
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	$css = '/assets/css/pdp.css';
	$js  = '/assets/js/pdp.js';
	wp_enqueue_style( 'bc-pdp', $uri . $css, array( 'child-style' ), file_exists( $dir . $css ) ? filemtime( $dir . $css ) : null );
	wp_enqueue_script( 'bc-pdp', $uri . $js, array(), file_exists( $dir . $js ) ? filemtime( $dir . $js ) : null, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_add_inline_script(
		'bc-pdp',
		'window.bcPdp=' . wp_json_encode(
			array(
				'api'  => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
				'cart' => esc_url_raw( wc_get_cart_url() ),
				'id'   => (int) get_queried_object_id(),
			)
		) . ';',
		'before'
	);
}, 10020 );

/* ------------------------------------------------------------------ */
/* 2. One H1 per product page                                          */
/* ------------------------------------------------------------------ */
function bc_pdp_demote_h1( $html ) {
	return is_string( $html ) ? preg_replace( '~<(/?)h1(?=[\s>])~i', '<$1h2', $html ) : $html;
}
add_filter( 'the_content', function ( $content ) {
	return ( 'product' === get_post_type() ) ? bc_pdp_demote_h1( $content ) : $content;
}, 9 );
add_filter( 'woocommerce_short_description', 'bc_pdp_demote_h1', 9 );

/* ------------------------------------------------------------------ */
/* 3. WooCommerce hooks: the page is assembled from these callbacks    */
/* ------------------------------------------------------------------ */
function bc_pdp_register_hooks() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	remove_all_actions( 'woocommerce_before_single_product_summary' );
	remove_all_actions( 'woocommerce_single_product_summary' );
	remove_all_actions( 'woocommerce_after_single_product_summary' );

	add_action( 'woocommerce_before_single_product_summary', 'bc_pdp_gallery', 20 );

	add_action( 'woocommerce_single_product_summary', 'bc_pdp_open_head', 2 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_kicker', 4 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_meta_row', 8 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_excerpt', 9 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_div', 10 );

	add_action( 'woocommerce_single_product_summary', 'bc_pdp_open_buy', 11 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_card_perks', 12 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_price', 13 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_buybox', 14 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_div', 19 );

	add_action( 'woocommerce_single_product_summary', 'bc_pdp_open_details', 20 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_spec_tiles', 21 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_trust', 22 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_help', 23 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_div', 29 );

	add_action( 'woocommerce_after_single_product_summary', 'bc_pdp_details_anchor', 9 );
	add_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
	add_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
	add_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
}

/* Three blocks inside the summary: head (title) | buy (price + cart) | details (specs, services, contact).
 * Desktop: CSS grid places them as gallery | head + details | buy. Mobile: DOM order = head, buy, details. */
function bc_pdp_open_head() { echo '<div class="bc-head">'; }
function bc_pdp_open_buy() { echo '<div class="bc-buy">'; }
function bc_pdp_open_details() { echo '<div class="bc-details">'; }
function bc_pdp_close_div() { echo '</div>'; }
function bc_pdp_breadcrumb() {
	echo '<div class="bc-crumb">';
	if ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
		rank_math_the_breadcrumbs();
	} elseif ( function_exists( 'woocommerce_breadcrumb' ) ) {
		woocommerce_breadcrumb();
	}
	echo '</div>';
}

function bc_pdp_gallery() {
	global $product;
	$ids = array();
	if ( $product->get_image_id() ) {
		$ids[] = $product->get_image_id();
	}
	$ids = array_values( array_unique( array_filter( array_map( 'absint', array_merge( $ids, $product->get_gallery_image_ids() ) ) ) ) );

	echo '<div class="bc-gal">';
	if ( ! $ids ) {
		echo '<div class="bc-gal__track"><div class="bc-gal__slide">' . wc_placeholder_img( 'woocommerce_single' ) . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		return;
	}
	echo '<div class="bc-gal__track" tabindex="0" aria-label="' . esc_attr__( 'تصاویر محصول', 'woodmart' ) . '">';
	foreach ( $ids as $i => $id ) {
		$alt  = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$attr = array(
			'class'    => 'bc-gal__img',
			'alt'      => $alt ? $alt : wp_strip_all_tags( get_the_title() ),
			'sizes'    => '(max-width: 1024px) 100vw, 560px',
			'loading'  => $i ? 'lazy' : 'eager',
			'decoding' => 'async',
		);
		if ( 0 === $i ) {
			$attr['fetchpriority'] = 'high';
		}
		echo '<div class="bc-gal__slide">' . wp_get_attachment_image( $id, 'large', false, $attr ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
	if ( count( $ids ) > 1 ) {
		echo '<span class="bc-gal__count" aria-hidden="true">1 / ' . count( $ids ) . '</span><div class="bc-gal__thumbs">';
		foreach ( $ids as $i => $id ) {
			echo '<button type="button" class="bc-gal__thumb' . ( $i ? '' : ' is-active' ) . '" aria-label="' . esc_attr( 'تصویر ' . ( $i + 1 ) ) . '">' . wp_get_attachment_image( $id, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}
	echo '</div>';
}

function bc_pdp_kicker() {
	global $product;
	$terms = get_the_terms( $product->get_id(), 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return;
	}
	$best = null;
	$deep = -1;
	foreach ( $terms as $t ) {
		$d = count( get_ancestors( $t->term_id, 'product_cat' ) );
		if ( $d > $deep ) {
			$deep = $d;
			$best = $t;
		}
	}
	$link = $best ? get_term_link( $best ) : '';
	if ( $best && ! is_wp_error( $link ) ) {
		echo '<div class="bc-kicker"><a href="' . esc_url( $link ) . '">' . esc_html( $best->name ) . '</a></div>';
	}
}

function bc_pdp_title() {
	echo '<h1 class="bc-title product_title entry-title">' . esc_html( wp_strip_all_tags( get_the_title() ) ) . '</h1>';
}

function bc_pdp_meta_row() {
	global $product;
	$map    = array(
		'instock'     => array( 'موجود در انبار', 'bc-chip--ok' ),
		'outofstock'  => array( 'ناموجود', 'bc-chip--out' ),
		'onbackorder' => array( 'پیش‌سفارش', 'bc-chip--back' ),
	);
	$st     = isset( $map[ $product->get_stock_status() ] ) ? $map[ $product->get_stock_status() ] : $map['instock'];
	$chips  = '<span class="bc-chip ' . esc_attr( $st[1] ) . '">' . esc_html( $st[0] ) . '</span>';
	if ( $product->managing_stock() && $product->is_in_stock() ) {
		$q = (int) $product->get_stock_quantity();
		if ( $q > 0 && $q <= 5 ) {
			$chips .= '<span class="bc-chip bc-chip--warn">تنها ' . esc_html( $q ) . ' عدد در انبار</span>';
		}
	}
	if ( $product->get_review_count() > 0 ) {
		$chips .= '<span class="bc-chip">★ ' . esc_html( wc_format_decimal( $product->get_average_rating(), 1 ) ) . ' <small>(' . (int) $product->get_review_count() . ' دیدگاه)</small></span>';
	}
	echo '<div class="bc-meta">' . $chips . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

function bc_pdp_price() {
	global $product;
	if ( '' === $product->get_price() && ! $product->is_type( 'variable' ) ) {
		echo '<div class="bc-price bc-price--ask">برای اطلاع از قیمت تماس بگیرید</div>';
		return;
	}
	echo '<div class="bc-price"><p class="price">' . $product->get_price_html() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $product->is_type( 'simple' ) && $product->is_on_sale() ) {
		$r = (float) $product->get_regular_price();
		$s = (float) $product->get_sale_price();
		if ( $r > 0 && $s > 0 && $s < $r ) {
			echo '<span class="bc-save">' . esc_html( round( ( $r - $s ) / $r * 100 ) ) . '٪ تخفیف</span>';
		}
	}
	echo '</div>';
}

/* Perks shown at the top of the buy card (guarantee + fast delivery). */
function bc_pdp_card_perks() {
	global $product;
	$guarantee = $product->get_attribute( 'pa_guarantee' );
	$guarantee = $guarantee ? $guarantee : '۳ ماه گارانتی تعویض کالا';
	echo '<ul class="bc-perks">';
	echo '<li class="bc-perk">' . bc_pdp_icon( 'shield' ) . '<span>' . esc_html( $guarantee ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<li class="bc-perk bc-perk--fast">' . bc_pdp_icon( 'bolt' ) . '<span>ارسال فوری در تهران</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</ul>';
}

/* Hidden by default; pdp.js shows it (and fills the quantity) once the product is in the visitor's cart. */
function bc_pdp_incart_panel() {
	echo '<div class="bc-incart" aria-live="polite"><div class="bc-incart__info"><span>در سبد شما</span><a href="' . esc_url( wc_get_cart_url() ) . '">مشاهده سبد خرید</a></div>';
	echo '<div class="bc-step" role="group" aria-label="تعداد در سبد خرید"><button type="button" class="bc-step__dec" aria-label="کم کردن تعداد"></button><output class="bc-step__qty">1</output><button type="button" class="bc-step__inc" aria-label="افزایش تعداد"></button></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
function bc_pdp_tile_data( $product ) {
	$first = array( 'pa_battery-model', 'pa_battery-capacity', 'pa_battery-voltage', 'pa_originality', 'pa_guarantee', 'pa_battery-type', 'pa_country-of-manufacture' );
	$tiles = array();
	$used  = array();
	foreach ( $first as $tax ) {
		if ( count( $tiles ) >= 6 ) {
			break;
		}
		$v = $product->get_attribute( $tax );
		if ( '' === $v ) {
			continue;
		}
		$tiles[] = array( 'label' => wc_attribute_label( $tax ), 'value' => $v );
		$used[]  = $tax;
	}
	if ( count( $tiles ) < 6 ) { // products that only have custom (non-taxonomy) attributes
		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( count( $tiles ) >= 6 ) {
				break;
			}
			if ( in_array( $key, $used, true ) || 'pa_brand' === $key || ! $attr->get_visible() || $attr->get_variation() ) {
				continue;
			}
			$v = $product->get_attribute( $attr->get_name() );
			if ( '' === $v ) {
				continue;
			}
			$tiles[] = array( 'label' => wc_attribute_label( $attr->get_name(), $product ), 'value' => $v );
		}
	}
	return $tiles;
}

function bc_pdp_spec_tiles() {
	global $product;
	$tiles = bc_pdp_tile_data( $product );
	if ( ! $tiles ) {
		return;
	}
	echo '<section class="bc-spec" aria-label="مشخصات کلیدی"><h2 class="bc-spec__title">مشخصات کلیدی</h2><div class="bc-spec__grid">';
	foreach ( $tiles as $t ) {
		echo '<div class="bc-spec__tile"><span class="bc-spec__label">' . esc_html( $t['label'] ) . '</span><span class="bc-spec__value">' . esc_html( $t['value'] ) . '</span></div>';
	}
	echo '</div><a class="bc-spec__more" href="#bc-details">مشاهده همه مشخصات</a></section>';
}

function bc_pdp_excerpt() {
	global $post;
	if ( ! empty( $post->post_excerpt ) ) {
		echo '<div class="bc-excerpt">' . apply_filters( 'woocommerce_short_description', $post->post_excerpt ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

function bc_pdp_buybox() {
	global $product;
	echo '<div class="bc-buybox" id="bc-buybox">';
	if ( ! $product->is_in_stock() ) {
		echo '<p class="bc-soldout">این محصول فعلاً ناموجود است. برای اطلاع از زمان موجود شدن با ما تماس بگیرید.</p>';
	} elseif ( '' === $product->get_price() && ! $product->is_type( 'variable' ) ) {
		echo '<p class="bc-soldout">برای اطلاع از قیمت و سفارش با ما تماس بگیرید.</p>';
	} else {
		woocommerce_template_single_add_to_cart();
		bc_pdp_incart_panel();
	}
	echo '</div>';
}

function bc_pdp_trust() {
	global $product;
	$items = apply_filters(
		'bc_pdp_trust_items',
		array(
			array( 'truck', 'ارسال پستی ۲ تا ۳ روز کاری' ),
			array( 'users', 'امکان خرید عمده و همکاری' ),
		),
		$product
	);
	echo '<ul class="bc-trust">';
	foreach ( $items as $it ) {
		echo '<li>' . bc_pdp_icon( $it[0] ) . '<span>' . esc_html( $it[1] ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}

function bc_pdp_help() {
	$c = bc_pdp_contact();
	echo '<aside class="bc-help"><p class="bc-help__title">مطمئن نیستید باتری با گوشی شما سازگار است؟</p>';
	echo '<p class="bc-help__text">مدل گوشی‌تان را بفرستید؛ قبل از خرید راهنمایی می‌کنیم.</p><div class="bc-help__actions">';
	if ( ! empty( $c['phone'] ) ) {
		echo '<a class="bc-btn" href="tel:' . esc_attr( $c['phone'] ) . '">' . bc_pdp_icon( 'phone' ) . '<span>تماس تلفنی</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( ! empty( $c['bale'] ) ) {
		echo '<a class="bc-btn" href="' . esc_url( $c['bale'] ) . '" target="_blank" rel="noopener">' . bc_pdp_icon( 'chat' ) . '<span>پیام در بله</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div></aside>';
}

/* Anchor for the "all specs" link: placed right before the tabs. */
function bc_pdp_details_anchor() {
	echo '<span id="bc-details" class="bc-anchor"></span>';
}

/* ------------------------------------------------------------------ */
/* 4. Sticky buy bar (mobile / tablet, always visible)                 */
/* ------------------------------------------------------------------ */
function bc_pdp_render_buybar() {
	if ( ! bc_pdp_enabled() ) {
		return;
	}
	$p = wc_get_product( get_queried_object_id() );
	if ( ! $p ) {
		return;
	}
	$c       = bc_pdp_contact();
	$has_p   = ( '' !== $p->get_price() );
	echo '<div class="bc-buybar" data-type="' . esc_attr( $p->get_type() ) . '" role="region" aria-label="خرید سریع"><div class="bc-buybar__inner">';
	if ( $has_p ) {
		echo '<div class="bc-buybar__price"><p class="price">' . $p->get_price_html() . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( ! $p->is_in_stock() ) {
		echo '<a class="bc-buybar__btn is-disabled" href="tel:' . esc_attr( $c['phone'] ) . '">ناموجود · استعلام موجودی</a>';
	} elseif ( ! $has_p && ! $p->is_type( 'variable' ) ) {
		echo '<a class="bc-buybar__btn" href="tel:' . esc_attr( $c['phone'] ) . '">استعلام قیمت</a>';
	} elseif ( $p->is_type( 'simple' ) && $p->is_purchasable() ) {
		echo '<form class="bc-buybar__form" method="post" action="' . esc_url( get_permalink( $p->get_id() ) ) . '"><input type="hidden" name="quantity" value="1"><button type="submit" name="add-to-cart" value="' . esc_attr( $p->get_id() ) . '" class="bc-buybar__btn">افزودن به سبد خرید</button></form>';
	} elseif ( $p->is_type( 'external' ) ) {
		echo '<a class="bc-buybar__btn" href="' . esc_url( $p->get_product_url() ) . '" rel="nofollow noopener">' . esc_html( $p->single_add_to_cart_text() ) . '</a>';
	} else {
		echo '<a class="bc-buybar__btn" href="#bc-buybox">' . ( $p->is_type( 'variable' ) ? 'انتخاب گزینه‌ها' : 'مشاهده گزینه‌ها' ) . '</a>';
	}
	echo '</div></div>';
}

/* WooCommerce Store API responses contain the visitor's cart: never let LiteSpeed cache them. */
add_filter( 'rest_post_dispatch', function ( $result, $server, $request ) {
	if ( 0 === strpos( $request->get_route(), '/wc/store' ) ) {
		do_action( 'litespeed_control_set_nocache', 'WooCommerce Store API (cart state)' );
	}
	return $result;
}, 10, 3 );
