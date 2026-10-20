<?php
/**
 * BC Battery - PDP: tab bar and content sections (description, specs, reviews, plugin tabs),
 * Woodmart's after-tabs block, upsells and related products, and the product card used in them.
 */
defined( 'ABSPATH' ) || exit;

/** [ label, value ] rows: weight, dimensions and every visible attribute. */
function bc_pdp_spec_rows( WC_Product $product ) {
	$rows = array();
	if ( function_exists( 'wc_product_weight_enabled' ) && wc_product_weight_enabled() && $product->has_weight() ) {
		$rows[] = array( 'وزن', wc_format_weight( $product->get_weight() ) );
	}
	if ( function_exists( 'wc_product_dimensions_enabled' ) && wc_product_dimensions_enabled() && $product->has_dimensions() ) {
		$rows[] = array( 'ابعاد', wc_format_dimensions( $product->get_dimensions( false ) ) );
	}
	foreach ( $product->get_attributes() as $attr ) {
		if ( ! $attr->get_visible() ) {
			continue;
		}
		$value = $product->get_attribute( $attr->get_name() );
		if ( '' !== trim( (string) $value ) ) {
			$rows[] = array( wc_attribute_label( $attr->get_name(), $product ), $value );
		}
	}
	return $rows;
}

function bc_pdp_block_desc() {
	echo '<div class="bcp-desc" data-bcp="desc">';
	the_content();
	echo '</div><button type="button" class="bcp-more" data-bcp="more" hidden>' . bc_icon( 'down', 18 ) . '<span>مشاهده بیشتر</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

function bc_pdp_block_specs() {
	global $product;
	echo '<dl class="bcp-specs">';
	foreach ( bc_pdp_spec_rows( $product ) as $row ) {
		echo '<div class="bcp-spec"><dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd></div>';
	}
	echo '</dl>';
}

/** Reviews: WooCommerce's own list and form. With no reviews yet the form stays folded behind a button. */
function bc_pdp_block_reviews() {
	global $product;
	$count = (int) $product->get_review_count();
	echo '<div class="bcp-reviews' . ( $count ? '' : ' is-empty' ) . '" data-bcp="reviews">';
	if ( ! $count ) {
		echo '<div class="bcp-empty"><span class="bcp-empty__ic">' . bc_icon( 'comment', 26 ) . '</span><b>هنوز دیدگاهی ثبت نشده</b><span>اولین نفری باشید که تجربه خرید این باتری را می‌نویسد.</span><button type="button" class="bcp-btn bcp-btn--outline" data-bcp="review-open">' . bc_icon( 'comment', 20 ) . 'ثبت دیدگاه</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<div class="bcp-reviews__wp">';
	ob_start();
	comments_template();
	$html = (string) ob_get_clean();
	if ( ! $count ) { // S9: no second reviews heading while there are no reviews (the section already has its own H2)
		$html = preg_replace( '~<h2(\s+class="woocommerce-Reviews-title"[^>]*)>(.*?)</h2>~s', '<div$1>$2</div>', $html, 1 );
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div></div>';
}

/** The tab bar and one card per section. Plugin tabs (woocommerce_product_tabs) are added after the built-in ones. */
function bc_pdp_sections() {
	global $product, $post;
	$s = bc_pdp_settings();
	do_action( 'woodmart_after_product_content' ); // Woodmart: product extra content (position "after")

	$blocks = array();
	if ( $s['tab_description'] && '' !== trim( (string) $post->post_content ) ) {
		$blocks['desc'] = array( 'توضیحات', 'file', 'bc_pdp_block_desc' );
	}
	if ( bc_pdp_spec_rows( $product ) ) {
		$blocks['specs'] = array( 'مشخصات', 'list', 'bc_pdp_block_specs' );
	}
	if ( $s['tab_reviews'] ) {
		$blocks['reviews'] = array( 'دیدگاه‌ها', 'comment', 'bc_pdp_block_reviews' );
	}
	foreach ( (array) apply_filters( 'woocommerce_product_tabs', array() ) as $key => $tab ) {
		if ( in_array( $key, array( 'description', 'additional_information', 'reviews' ), true ) || empty( $tab['callback'] ) || ! is_callable( $tab['callback'] ) ) {
			continue;
		}
		$blocks[ 'x-' . sanitize_key( $key ) ] = array( wp_strip_all_tags( (string) ( isset( $tab['title'] ) ? $tab['title'] : $key ) ), 'info', $tab['callback'], $key, $tab );
	}
	if ( ! $blocks ) {
		return;
	}

	$title = wp_strip_all_tags( get_the_title() );
	echo '<section class="bcp-sections" data-bcp="sections"><nav class="bcp-tabs" aria-label="بخش‌های صفحه"><div class="bcp-tabs__row">';
	$first = true;
	foreach ( $blocks as $id => $b ) {
		echo '<a class="bcp-tab' . ( $first ? ' is-on' : '' ) . '" href="#bcp-' . esc_attr( $id ) . '">' . esc_html( $b[0] ) . bc_icon( $b[1], 18 ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$first = false;
	}
	echo '</div></nav>';
	foreach ( $blocks as $id => $b ) {
		if ( 'desc' === $id ) { // the description brings its own headings (owner request, 20 Oct): no "توضیحات" title and product-name line
			echo '<section class="bcp-block bcp-block--nohead" id="bcp-desc" aria-label="' . esc_attr( $b[0] ) . '"><div class="bcp-block__body">';
		} else {
			echo '<section class="bcp-block" id="bcp-' . esc_attr( $id ) . '"><header class="bcp-block__head"><h2>' . esc_html( $b[0] ) . '</h2><span>' . esc_html( $title ) . '</span></header><div class="bcp-block__body">';
		}
		call_user_func( $b[2], isset( $b[3] ) ? $b[3] : $id, isset( $b[4] ) ? $b[4] : array() );
		echo '</div></section>';
	}
	echo '</section>';
}

/** Woodmart: meta / share (position "after tabs") and frequently-bought-together. */
function bc_pdp_after_tabs() {
	do_action( 'woodmart_after_product_tabs' );
}

/* ------------------------------------------------------------------ */
/* Product cards                                                       */
/* ------------------------------------------------------------------ */

function bc_pdp_card( WC_Product $p ) {
	$url   = $p->get_permalink();
	$price = '' === $p->get_price() ? 0 : (float) wc_get_price_to_display( $p );
	$vcard = null; // a product with one choice to make: added through the "انتخاب تنوع" popup (variant-picker.js)
	if ( $p->is_type( 'variable' ) && $p->is_in_stock() && $price > 0 && function_exists( 'bc_cart_comp_variants' ) && function_exists( 'bc_vp_card_attrs' ) ) {
		$vcard = bc_cart_comp_variants( $p, array( 'variants' => array(), 'label' => '', 'choose' => false, 'selected' => 0, 'price' => $price, 'in_cart' => false ), array() );
		if ( ! empty( $vcard['choose'] ) || empty( $vcard['variants'] ) ) {
			$vcard = null;
		}
	}
	echo '<article class="bcp-pc" data-pid="' . (int) $p->get_id() . '"' . ( $vcard ? ' data-vid="' . (int) $vcard['selected'] . '"' . bc_vp_card_attrs( $vcard ) : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<a class="bcp-pc__img" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . $p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'sizes' => '(max-width: 767px) 160px, 190px' ) ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<a class="bcp-pc__name" href="' . esc_url( $url ) . '">' . esc_html( $p->get_name() ) . '</a>';
	echo '<div class="bcp-pc__price">';
	if ( $price > 0 ) {
		echo ( $p->is_type( 'variable' ) ? '<span class="bcp-pc__from">از</span> ' : '' ) . '<b>' . esc_html( bc_pdp_number( $price ) ) . '</b> <small>' . esc_html( get_woocommerce_currency_symbol() ) . '</small>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<small>تماس بگیرید</small>';
	}
	echo '</div>';
	if ( ! $p->is_in_stock() ) {
		echo '<span class="bcp-btn bcp-btn--ghost" aria-disabled="true">ناموجود</span>';
	} elseif ( $p->is_type( 'simple' ) && $p->is_purchasable() && $price > 0 ) {
		echo '<button type="button" class="bcp-btn bcp-add" data-bcp-add="' . (int) $p->get_id() . '">' . bc_icon( 'plus', 16 ) . 'افزودن به سبد</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo bc_pdp_mini_step(); // phpcs:ignore WordPress.Security.EscapeOutput
	} elseif ( $vcard ) {
		echo '<button type="button" class="bcp-btn bcp-add" data-bcp-add="' . (int) $p->get_id() . '">' . bc_icon( 'plus', 16 ) . 'افزودن به سبد</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo bc_pdp_mini_step(); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<a class="bcp-btn bcp-btn--outline" href="' . esc_url( $url ) . '">مشاهده و انتخاب</a>';
	}
	echo '</article>';
}

/**
 * Small quantity stepper for a product card: + / count / - (a bin at one). Hidden until pdp.js finds the card's
 * product in the visitor's cart; it then replaces the add button, so the visitor changes or removes it in place.
 * Filled in by the script, never by PHP, so cached pages stay right.
 */
function bc_pdp_mini_step() {
	return '<div class="bcp-mq is-one" data-bcp-mq role="group" aria-label="' . esc_attr( 'تعداد در سبد خرید' ) . '" hidden>'
		. '<button type="button" class="bcp-mq__btn bcp-mq__btn--inc" data-bcp-mq-act="inc" aria-label="' . esc_attr( 'افزایش تعداد' ) . '">' . bc_icon( 'plus', 16 ) . '</button>'
		. '<output class="bcp-mq__n" data-bcp-mq-n>۱</output>'
		. '<button type="button" class="bcp-mq__btn bcp-mq__btn--dec" data-bcp-mq-act="dec" aria-label="' . esc_attr( 'حذف از سبد' ) . '"><span class="bcp-ic-trash">' . bc_icon( 'trash', 15 ) . '</span><span class="bcp-ic-minus">' . bc_icon( 'minus', 16 ) . '</span></button>'
		. '</div>';
}

function bc_pdp_cards_section( $title, array $ids, $class = '' ) {
	$products = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() ) {
			$products[] = $p;
		}
	}
	if ( ! $products ) {
		return;
	}
	echo '<section class="bcp-rel ' . esc_attr( $class ) . '"><h2 class="bcp-rel__title">' . esc_html( $title ) . '</h2><div class="bcp-rel__track">';
	foreach ( $products as $p ) {
		bc_pdp_card( $p );
	}
	echo '</div></section>';
}

/** Related products: the manual field (_bc_related_ids) first, else WooCommerce's own related list. Count: Woodmart "Related products" settings. */
function bc_pdp_related() {
	global $product;
	$s = bc_pdp_settings();
	if ( ! $s['related'] ) {
		return;
	}
	// Owner's choice (20 Oct): this section uses the complementary algorithm (manual list first, then automatic picks).
	$ids = function_exists( 'bc_get_complementary_ids' ) ? bc_get_complementary_ids( $product->get_id(), $s['related_count'] ) : array();
	if ( ! $ids && function_exists( 'bc_get_related_ids' ) ) {
		$ids = bc_get_related_ids( $product->get_id(), $s['related_count'] );
	}
	if ( ! $ids ) {
		$ids = array_slice( array_map( 'absint', wc_get_related_products( $product->get_id(), $s['related_count'] ) ), 0, $s['related_count'] );
	}
	bc_pdp_cards_section( 'محصولات مرتبط', $ids, 'bcp-rel--related' );
}

/** Upsells (Woodmart "Upsells position" other than hide). */
function bc_pdp_upsells() {
	global $product;
	if ( ! bc_pdp_settings()['upsells'] ) {
		return;
	}
	bc_pdp_cards_section( 'شاید بپسندید', array_slice( array_map( 'absint', $product->get_upsell_ids() ), 0, 6 ), 'bcp-rel--upsells' );
}