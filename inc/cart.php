<?php
/**
 * BC Battery - Cart (step 1 of the purchase flow).
 *
 * The page is rendered by the templates in inc/templates/cart*.php. Every change (quantity, remove,
 * add a complementary product, coupon) goes through ONE small endpoint: /?wc-ajax=bc_cart. The endpoint
 * answers with HTML fragments built by the same templates, so what the visitor sees after a change is
 * exactly what a page reload would show.
 *
 * Not active until bc_flow_enabled() is true.
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Data                                                                */
/* ------------------------------------------------------------------ */

/** Prices the visitor has already seen, per cart line (session). Used to announce a price change once. */
function bc_cart_seen() {
	return WC()->session ? (array) WC()->session->get( 'bc_seen_prices', array() ) : array();
}

function bc_cart_set_seen( array $seen ) {
	if ( WC()->session ) {
		WC()->session->set( 'bc_seen_prices', $seen );
	}
}

/* Remember the price at the moment a product goes into the cart. */
add_action( 'woocommerce_add_to_cart', function ( $key ) {
	if ( ! bc_flow_enabled() || ! WC()->cart ) {
		return;
	}
	$item = WC()->cart->get_cart_item( $key );
	if ( ! empty( $item['data'] ) ) {
		$seen         = bc_cart_seen();
		$seen[ $key ] = (float) wc_get_price_to_display( $item['data'] );
		bc_cart_set_seen( $seen );
	}
}, 20 );

/** One row per cart line, with everything the templates need. */
function bc_cart_rows() {
	$rows    = array();
	$seen    = bc_cart_seen();
	$changed = false;
	$incl    = 'incl' === get_option( 'woocommerce_tax_display_cart' );

	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$product = isset( $item['data'] ) ? $item['data'] : null;
		if ( ! $product instanceof WC_Product || ! $product->exists() ) {
			continue;
		}
		$qty   = (int) $item['quantity'];
		$unit  = (float) wc_get_price_to_display( $product );
		$line  = (float) $item['line_subtotal'] + ( $incl ? (float) $item['line_subtotal_tax'] : 0 );
		$max   = $product->get_max_purchase_quantity(); // -1 = no limit.
		$was   = 0;
		if ( $product->is_on_sale() ) {
			$reg = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
			$was = $reg > $unit ? $reg * $qty : 0;
		}

		$in_stock = $product->is_in_stock() && $product->is_purchasable();
		$short    = $in_stock && $max > -1 && $qty > $max; // More in the cart than is in stock.

		$old = isset( $seen[ $key ] ) ? (float) $seen[ $key ] : null;
		if ( null === $old ) { // Line from before this feature: take the current price as the reference.
			$seen[ $key ] = $unit;
			$changed      = true;
		}

		$rows[ $key ] = array(
			'key'     => $key,
			'product' => $product,
			'pid'     => $item['product_id'],
			'name'    => $product->is_type( 'variation' ) ? get_the_title( $product->get_parent_id() ) : $product->get_name(), // A variation's own name repeats the option ("Phone - Original").
			'attr'    => $product->is_type( 'variation' ) ? wc_get_formatted_variation( $product, true ) : '',
			'url'     => $product->get_permalink( $item ),
			'img'     => $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'bc-line__thumb', 'loading' => count( $rows ) < 2 ? 'eager' : 'lazy', 'decoding' => 'async', 'sizes' => '84px' ) ),
			'qty'     => $qty,
			'unit'    => $unit,
			'line'    => $line,
			'was'     => $was,
			'max'     => $max,
			'out'     => ! $in_stock,
			'short'   => $short,
			'old'     => ( null !== $old && abs( $old - $unit ) >= 1 ) ? $old : null,
		);
	}

	// Forget lines that are no longer in the cart.
	$seen = array_intersect_key( $seen, $rows );
	if ( $changed || $seen !== bc_cart_seen() ) {
		bc_cart_set_seen( $seen );
	}
	return $rows;
}

/** Everything the cart templates show. Computed once per response. */
function bc_cart_data( $with_comp = true ) {
	$cart = WC()->cart;
	$rows = bc_cart_rows();

	$subtotal = 0;
	$blocked  = false;
	$count    = 0;
	foreach ( $rows as $r ) {
		$count += $r['qty'];
		if ( $r['out'] || $r['short'] ) {
			$blocked = true;
		}
		if ( ! $r['out'] ) {
			$subtotal += $r['line'];
		}
	}

	$coupons  = array();
	$discount = 0;
	foreach ( $cart->get_applied_coupons() as $code ) {
		$amount    = (float) $cart->get_coupon_discount_amount( $code, ! ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) ) );
		$discount += $amount;
		$coupons[] = array( 'code' => $code, 'amount' => $amount );
	}
	$discount = min( $discount, $subtotal );

	return array(
		'rows'     => $rows,
		'empty'    => empty( $rows ),
		'count'    => $count,
		'blocked'  => $blocked,
		'subtotal' => $subtotal,
		'discount' => $discount,
		'coupons'  => $coupons,
		'total'    => max( 0, $subtotal - $discount ),
		'checkout' => wc_get_checkout_url(),
		'comp'     => $with_comp ? bc_cart_comp_cards( $rows ) : array(),
	);
}

/* ------------------------------------------------------------------ */
/* Complementary products (manual field _bc_complementary_ids)         */
/* ------------------------------------------------------------------ */

function bc_cart_comp_cards( array $rows ) {
	if ( ! function_exists( 'bc_get_complementary_ids' ) || ! $rows ) {
		return array();
	}
	$in_cart = array(); // product id / variation id => its cart line (key, qty, max), for the card steppers
	$score   = array();
	foreach ( $rows as $r ) {
		$line                 = array( 'key' => (string) $r['key'], 'qty' => (int) $r['qty'], 'max' => (int) $r['max'] );
		$in_cart[ $r['pid'] ] = $line;
		if ( $r['product']->is_type( 'variation' ) ) {
			$in_cart[ $r['product']->get_id() ] = $line;
		}
		foreach ( bc_get_complementary_ids( $r['pid'] ) as $id ) {
			$score[ $id ] = ( isset( $score[ $id ] ) ? $score[ $id ] : 0 ) + 1;
		}
	}
	arsort( $score );
	return bc_comp_cards( array_keys( $score ), $in_cart, (int) apply_filters( 'bc_cart_comp_limit', 8 ) );
}

/**
 * Cards for a list of product ids: simple products get an add button, variable products with exactly one varying
 * attribute get chips, other variable products link to the product page. Shared by the cart and the product page.
 * $in_cart: product / variation id => true for what the visitor already has in the cart.
 */
function bc_comp_cards( array $ids, array $in_cart = array(), $limit = 8 ) {
	$cards = array();
	foreach ( $ids as $id ) {
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_visible() || ! $p->is_in_stock() ) {
			continue;
		}
		$card = array(
			'id'       => $p->get_id(),
			'name'     => $p->get_name(),
			'url'      => $p->get_permalink(),
			'img'      => $p->get_image( 'woocommerce_thumbnail', array( 'class' => 'bc-comp__thumb', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '(min-width:1025px) 120px, 45vw' ) ),
			'variants' => array(),
			'label'    => '',
			'choose'   => false, // true: needs a choice we cannot show here, link to the product page.
			'selected' => 0,
			'price'    => (float) wc_get_price_to_display( $p ),
			'in_cart'  => isset( $in_cart[ $p->get_id() ] ),
			'line'     => bc_comp_line( $in_cart, $p->get_id() ),
		);
		if ( $p->is_type( 'variable' ) ) {
			$card = bc_cart_comp_variants( $p, $card, $in_cart );
		} elseif ( ! $p->is_type( 'simple' ) || ! $p->is_purchasable() ) {
			continue;
		}
		$cards[] = $card;
		if ( count( $cards ) >= $limit ) {
			break;
		}
	}
	return $cards;
}
/** The cart line (key, qty, max) of a product or variation, or null when it is not in the cart (or not known here). */
function bc_comp_line( array $in_cart, $id ) {
	return ( isset( $in_cart[ $id ] ) && is_array( $in_cart[ $id ] ) ) ? $in_cart[ $id ] : null;
}

/**
 * Small quantity stepper for a complementary card, shown once the product is in the cart (instead of the add button).
 * Its buttons are the cart lines' own (data-bc-act inc / dec / remove on a [data-bc-line] with the line's key), so
 * cart.js handles them like any other line. Empty $line: rendered hidden, for a variant that may be picked later.
 */
function bc_comp_mini_step( $line ) {
	$q   = $line ? (int) $line['qty'] : 1;
	$top = $line && $line['max'] > -1 && $q >= $line['max'];
	echo '<div class="bc-mq' . ( $q <= 1 ? ' is-one' : '' ) . '" data-bc-line data-key="' . esc_attr( $line ? $line['key'] : '' ) . '" data-qty="' . (int) $q . '" role="group" aria-label="تعداد در سبد خرید"' . ( $line ? '' : ' hidden' ) . '>';
	echo '<button type="button" class="bc-mq__btn bc-mq__btn--inc" data-bc-act="inc" aria-label="افزایش تعداد"' . ( $top ? ' disabled' : '' ) . '>' . bc_icon( 'plus', 16 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<span class="bc-mq__n bc-qty__n" aria-live="polite">' . esc_html( number_format_i18n( $q ) ) . '</span>';
	echo '<button type="button" class="bc-mq__btn bc-mq__btn--dec" data-bc-act="dec" aria-label="کاهش تعداد">' . bc_icon( 'minus', 16 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<button type="button" class="bc-mq__btn bc-mq__btn--dec" data-bc-act="remove" aria-label="حذف از سبد">' . bc_icon( 'trash', 15 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

/** Chips for a variable product that varies by exactly one attribute; otherwise "choose" (link to the product page). */
function bc_cart_comp_variants( WC_Product_Variable $p, array $card, array $in_cart ) {
	$attrs = $p->get_variation_attributes();
	if ( 1 !== count( $attrs ) ) {
		$card['choose'] = true;
		return $card;
	}
	$attr_name     = key( $attrs );
	$meta_key      = 'attribute_' . sanitize_title( $attr_name );
	$default       = (string) $p->get_variation_default_attribute( $attr_name );
	$card['label'] = wc_attribute_label( $attr_name, $p );

	foreach ( $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! $v->variation_is_visible() || ! $v->is_in_stock() || ! $v->is_purchasable() ) {
			continue;
		}
		$vattrs = $v->get_variation_attributes();
		$slug   = isset( $vattrs[ $meta_key ] ) ? (string) $vattrs[ $meta_key ] : '';
		if ( '' === $slug ) { // "any value" variations need a choice.
			$card['choose'] = true;
			return $card;
		}
		$label = $slug;
		if ( taxonomy_exists( $attr_name ) ) {
			$term = get_term_by( 'slug', $slug, $attr_name );
			if ( $term && ! is_wp_error( $term ) ) {
				$label = $term->name;
			}
		}
		$card['variants'][] = array(
			'id'      => $v->get_id(),
			'label'   => $label,
			'price'   => (float) wc_get_price_to_display( $v ),
			'in_cart' => isset( $in_cart[ $v->get_id() ] ),
			'line'    => bc_comp_line( $in_cart, $v->get_id() ),
			'default' => $slug === $default,
		);
	}
	if ( ! $card['variants'] ) {
		$card['choose'] = true;
		return $card;
	}
	$pick = $card['variants'][0];
	foreach ( $card['variants'] as $v ) {
		if ( $v['default'] ) {
			$pick = $v;
			break;
		}
	}
	$card['selected'] = $pick['id'];
	$card['price']    = $pick['price'];
	$card['in_cart']  = $pick['in_cart'];
	return $card;
}

/** The price rows shared by the summary card and the bottom sheet. */
function bc_cart_summary_rows( array $d ) {
	echo '<dl class="bc-sum">';
	echo '<div class="bc-sum__row"><dt>جمع کالاها' . ( $d['count'] ? ' <span class="bc-muted">(' . bc_num( $d['count'] ) . ' کالا)</span>' : '' ) . '</dt><dd>' . bc_money( $d['subtotal'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $d['discount'] > 0 ) {
		echo '<div class="bc-sum__row bc-sum__row--discount"><dt>تخفیف</dt><dd>' . bc_money( -$d['discount'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<div class="bc-sum__row"><dt>هزینه ارسال</dt><dd class="bc-muted bc-sum__later">در مرحله بعد محاسبه می‌شود</dd></div>';
	echo '<div class="bc-sum__row bc-sum__row--total"><dt>مبلغ قابل پرداخت</dt><dd>' . bc_money( $d['total'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</dl>';
}

/* ------------------------------------------------------------------ */
/* Bottom bar (mobile): printed in the footer so "position: fixed" is never trapped inside the theme wrappers */
/* ------------------------------------------------------------------ */

add_action( 'wp_footer', function () {
	if ( ! bc_flow_active() || 'cart' !== bc_flow_page() || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	bc_template( 'cart-bar', array( 'd' => bc_cart_data( false ) ) );
}, 5 );

/* ------------------------------------------------------------------ */
/* AJAX: /?wc-ajax=bc_cart                                              */
/* ------------------------------------------------------------------ */

add_action( 'wc_ajax_bc_cart', 'bc_cart_ajax' );

/** First error text WooCommerce queued, then clear all queued notices (we show our own messages). */
function bc_cart_take_error( $fallback = '' ) {
	$msg = '';
	$all = wc_get_notices( 'error' );
	if ( $all ) {
		$first = reset( $all );
		$msg   = trim( html_entity_decode( wp_strip_all_tags( is_array( $first ) ? $first['notice'] : $first ), ENT_QUOTES, 'UTF-8' ) );
	}
	wc_clear_notices();
	return '' !== $msg ? $msg : $fallback;
}

function bc_cart_ajax() {
	nocache_headers();
	if ( ! bc_flow_enabled() ) {
		wp_send_json( array( 'ok' => false, 'msg' => 'غیرفعال' ), 404 );
	}
	wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
	if ( null === WC()->cart ) {
		wc_load_cart();
	}
	$nonce = isset( $_POST['_bc'] ) ? sanitize_text_field( wp_unslash( $_POST['_bc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! wp_verify_nonce( $nonce, 'bc_flow' ) ) {
		wp_send_json( array( 'ok' => false, 'reload' => true, 'msg' => 'این صفحه منقضی شده است؛ صفحه دوباره بارگذاری می‌شود.' ), 403 );
	}

	$cart  = WC()->cart;
	$act   = isset( $_POST['act'] ) ? sanitize_key( wp_unslash( $_POST['act'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$key   = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$msg   = '';
	$ok    = true;
	$view  = ( isset( $_POST['view'] ) && 'empty' === $_POST['view'] ) ? 'empty' : 'cart'; // phpcs:ignore WordPress.Security.NonceVerification
	$parts = array( 'items', 'summary', 'bar', 'coupon' );
	$extra = array();

	switch ( $act ) {
		case 'qty':
			$item = $cart->get_cart_item( $key );
			$qty  = isset( $_POST['qty'] ) ? max( 0, absint( $_POST['qty'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $item ) {
				break; // Out of date page: just send the current state.
			}
			if ( 0 === $qty ) {
				$cart->remove_cart_item( $key );
				$parts[]       = 'comp';
				$extra['undo'] = $key;
				break;
			}
			$max = $item['data']->get_max_purchase_quantity();
			if ( $max > -1 && $qty > $max ) {
				$qty = max( 1, $max );
				$msg = $max > 0 ? sprintf( 'حداکثر موجودی این کالا %s عدد است.', number_format_i18n( $max ) ) : 'این کالا فعلاً موجود نیست.';
			}
			if ( ! apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
				$ok  = false;
				$msg = bc_cart_take_error( 'تغییر تعداد ممکن نیست.' );
				break;
			}
			$cart->set_quantity( $key, $qty, true );
			break;

		case 'remove':
			if ( $cart->get_cart_item( $key ) ) {
				$cart->remove_cart_item( $key );
				$extra['undo'] = $key;
			}
			$parts[] = 'comp';
			break;

		case 'undo':
			$cart->restore_cart_item( $key );
			$parts[] = 'comp';
			break;

		case 'add':
			$pid = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$vid = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$p   = wc_get_product( $vid ? $vid : $pid );
			if ( ! $p || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
				$ok  = false;
				$msg = 'این کالا فعلاً موجود نیست.';
				break;
			}
			$parent = $vid ? $p->get_parent_id() : $pid;
			$added  = $cart->add_to_cart( $parent, 1, $vid, $vid ? $p->get_variation_attributes() : array() );
			if ( ! $added ) {
				$ok  = false;
				$msg = bc_cart_take_error( 'افزودن این کالا ممکن نشد.' );
				break;
			}
			wc_clear_notices();
			$parts[] = 'comp';
			break;

		case 'coupon_add':
			$code = isset( $_POST['code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			wc_clear_notices();
			if ( '' === $code ) {
				$ok  = false;
				$msg = 'کد تخفیف را وارد کنید.';
			} elseif ( $cart->has_discount( $code ) ) {
				$ok  = false;
				$msg = 'این کد تخفیف قبلاً اعمال شده است.';
			} elseif ( ! $cart->apply_coupon( $code ) ) {
				$ok  = false;
				$msg = bc_cart_take_error( 'این کد تخفیف نامعتبر است یا منقضی شده. دوباره بررسی کنید.' );
			}
			wc_clear_notices();
			if ( ! $ok ) {
				$parts = array(); // Nothing changed: the page only shows the message next to the field.
			}
			break;

		case 'coupon_remove':
			$cart->remove_coupon( wc_format_coupon_code( $key ) );
			wc_clear_notices();
			break;

		case 'ack_price':
			$seen = array();
			foreach ( bc_cart_rows() as $k => $r ) {
				$seen[ $k ] = $r['unit'];
			}
			bc_cart_set_seen( $seen );
			break;

		default: // 'refresh' and anything unknown: just send the current state.
			break;
	}

	$cart->calculate_totals();
	$d = bc_cart_data( in_array( 'comp', $parts, true ) );

	$fragments = array();
	if ( $d['empty'] ) {
		$fragments['root'] = bc_template_html( 'cart-empty' );
	} elseif ( 'empty' === $view ) {
		// The page shows the empty cart and an item came back (undo, add): send the whole cart page.
		$fragments['root'] = bc_template_html( 'cart' );
		$fragments['bar']  = bc_template_html( 'cart-bar', array( 'd' => $d ) );
	} else {
		foreach ( array_unique( $parts ) as $part ) {
			$fragments[ $part ] = bc_template_html( 'cart-' . $part, array( 'd' => $d ) );
		}
	}

	wp_send_json( array_merge( array(
		'ok'        => $ok,
		'msg'       => $msg,
		'empty'     => $d['empty'],
		'count'     => $d['count'],
		'countText' => '(' . number_format_i18n( $d['count'] ) . ' کالا)',
		'fragments' => $fragments,
	), $extra ) );
}

/* ------------------------------------------------------------------ */
/* Assets                                                              */
/* ------------------------------------------------------------------ */

add_action( 'wp_enqueue_scripts', function () {
	if ( bc_flow_active() && 'cart' === bc_flow_page() ) {
		bc_enqueue_module( 'bc-cart', 'cart.css', 'cart.js', array( 'bc-flow' ), array( 'bc-flow' ) );
	}
}, 21 );
