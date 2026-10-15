<?php
/**
 * BC Battery - PDP: parts printed in the footer.
 *   - sticky buy bar (always visible on phones; on larger screens after the buy card scrolls away)
 *   - "added to cart" sheet with complementary products (bottom sheet / modal / side panel, CSS decides)
 *   - messenger chooser (Bale / WhatsApp), photo lightbox, small toast
 * They are <dialog> elements: focus handling, Escape key and the backdrop come from the browser.
 */
defined( 'ABSPATH' ) || exit;

function bc_pdp_render_overlays() {
	if ( ! bc_pdp_enabled() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product ) {
		return;
	}
	$s = bc_pdp_settings();
	if ( $s['bar_mobile'] || $s['bar_desktop'] ) {
		bc_pdp_render_bar( $product );
	}
	if ( 'nothing' !== $s['after_add'] ) {
		bc_pdp_render_sheet( $product );
	}
	bc_pdp_render_messenger();
	bc_pdp_render_lightbox();
	echo '<div class="bcp-toast" data-bcp="toast" role="status" aria-live="polite" hidden></div>';
}

function bc_pdp_render_bar( WC_Product $p ) {
	$m     = bc_pdp_model( $p );
	$d     = bc_pdp_price_data( $p );
	$cur   = esc_html( get_woocommerce_currency_symbol() );
	$own   = $p->is_type( 'simple' ) || ( $p->is_type( 'variable' ) && $m['pills'] );
	$out   = $own && 'outofstock' === $m['current']->get_stock_status();
	$en    = function_exists( 'bc_get_title_en' ) ? bc_get_title_en( $p->get_id() ) : '';
	$label = $out ? 'ناموجود' : ( $own ? 'افزودن به سبد خرید' : 'مشاهده گزینه‌ها' );

	echo '<div class="bcp-bar" data-bcp="bar" role="region" aria-label="' . esc_attr( 'خرید سریع' ) . '"><div class="bcp-bar__in">';
	echo '<a class="bcp-bar__btn' . ( $out ? ' is-disabled' : '' ) . '" href="#bcp-buy" data-bcp="bar-btn"' . ( $out ? ' aria-disabled="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
	echo '<div class="bcp-bar__prod"><span class="bcp-bar__img">' . $p->get_image( 'woocommerce_gallery_thumbnail', array( 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<span class="bcp-bar__names"><b>' . esc_html( wp_strip_all_tags( $p->get_name() ) ) . '</b>' . ( '' !== $en ? '<span dir="ltr" lang="en">' . esc_html( $en ) . '</span>' : '' ) . '</span></div>';
	if ( $d['p'] > 0 ) {
		echo '<div class="bcp-bar__price"><del data-bcp="bar-old"' . ( $d['r'] ? '' : ' hidden' ) . '><span data-bcp="bar-old-n">' . esc_html( bc_pdp_number( $d['r'] ) ) . '</span></del>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<b><span class="bcp-bar__from" data-bcp="bar-from" hidden>از</span> <span data-bcp="bar-now">' . esc_html( bc_pdp_number( $d['p'] ) ) . '</span> <small>' . $cur . '</small></b></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div></div>';
}

function bc_pdp_render_sheet( WC_Product $p ) {
	$ids   = function_exists( 'bc_get_complementary_ids' ) ? bc_get_complementary_ids( $p->get_id() ) : array();
	$cards = ( $ids && function_exists( 'bc_comp_cards' ) ) ? bc_comp_cards( $ids, array(), (int) apply_filters( 'bc_pdp_sheet_limit', 8 ) ) : array();
	bc_template( 'pdp-sheet', array( 'cards' => $cards ) );
}

function bc_pdp_render_messenger() {
	$msg = bc_pdp_messengers();
	if ( count( $msg ) < 2 ) {
		return;
	}
	$t = bc_pdp_texts();
	echo '<dialog class="bcp-msg" data-bcp="msg" aria-labelledby="bcp-msg-title"><div class="bcp-msg__box">';
	echo '<header class="bcp-msg__head"><div><b id="bcp-msg-title">پیام در پیام‌رسان</b><span>پیام‌رسان مورد نظرت را انتخاب کن</span></div><button type="button" class="bcp-x" data-bcp-close aria-label="' . esc_attr( 'بستن' ) . '">' . bc_icon( 'close', 22 ) . '</button></header>'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $msg as $key => $row ) {
		echo '<a class="bcp-msg__row" href="' . esc_url( $row[1] ) . '" target="_blank" rel="noopener"><span class="bcp-msg__ic bcp-msg__ic--' . esc_attr( $key ) . '">' . bc_icon( $key, 24 ) . '</span><span class="bcp-msg__t"><b>' . esc_html( $row[0] ) . '</b><span>' . esc_html( $t['messenger_hours'] ) . '</span></span>' . bc_icon( 'next', 20 ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div></dialog>';
}

function bc_pdp_render_lightbox() {
	if ( ! bc_pdp_settings()['zoom'] ) {
		return;
	}
	echo '<dialog class="bcp-lb" data-bcp="lb" aria-label="' . esc_attr( 'تصاویر محصول' ) . '">';
	echo '<button type="button" class="bcp-lb__x" data-bcp-close aria-label="' . esc_attr( 'بستن' ) . '">' . bc_icon( 'close', 24 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<button type="button" class="bcp-lb__nav bcp-lb__nav--next" data-bcp="lb-next" aria-label="' . esc_attr( 'بعدی' ) . '">' . bc_icon( 'next', 28 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<div class="bcp-lb__stage"><img alt="" data-bcp="lb-img"></div>';
	echo '<button type="button" class="bcp-lb__nav bcp-lb__nav--prev" data-bcp="lb-prev" aria-label="' . esc_attr( 'قبلی' ) . '">' . bc_icon( 'back', 28 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<span class="bcp-lb__count" data-bcp="lb-count"></span></dialog>';
}