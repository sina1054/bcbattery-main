<?php
/**
 * BC Battery - PDP cart endpoint: /?wc-ajax=bc_pdp_cart (POST).
 *
 * Why not the Store API: every Store API call boots the whole REST stack (each plugin registers all of its
 * REST routes first, ~0.35 s here), a first-time guest needs one more request just to get a nonce, and the
 * header mini cart needed yet another request (get_refreshed_fragments). Here a change is ONE request that
 * answers with the cart lines the page needs AND the mini-cart fragments.
 *
 * Actions : add (id, quantity + the product form's own fields), qty (key, qty), remove (key), get.
 * Answer  : { ok, msg, items: [{key, id, quantity, max}], count, fragments?, cart_hash? }
 * Security: no nonce (product pages come from the page cache, the same HTML for every guest). The request
 *           must carry the X-BC-PDP header instead: another site cannot send a custom header to this origin
 *           (it needs a CORS preflight, which is refused). Cart keys only ever reach the visitor's own cart.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'wc_ajax_bc_pdp_cart', 'bc_pdp_cart_ajax' );

/** The cart lines the product page needs. */
function bc_pdp_cart_items() {
	$items = array();
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$p       = isset( $item['data'] ) ? $item['data'] : null;
		$max     = $p instanceof WC_Product ? (int) $p->get_max_purchase_quantity() : -1;
		$items[] = array(
			'key'      => (string) $key,
			'id'       => (int) ( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] ),
			'quantity' => (int) $item['quantity'],
			'max'      => $max > 0 ? $max : 0, // 0 = no limit
		);
	}
	return $items;
}

/** First error WooCommerce queued (plain text), then clear every queued notice: the page shows its own messages. */
function bc_pdp_cart_error( $fallback ) {
	$msg = '';
	$all = wc_get_notices( 'error' );
	if ( $all ) {
		$first = reset( $all );
		$msg   = trim( html_entity_decode( wp_strip_all_tags( is_array( $first ) ? $first['notice'] : $first ), ENT_QUOTES, 'UTF-8' ) );
	}
	wc_clear_notices();
	return '' !== $msg ? $msg : $fallback;
}

/**
 * Add a product or a variation. The product form's own fields are in $_POST as well (attribute_* values and
 * whatever plugins print on woocommerce_before_add_to_cart_button), exactly as the classic form posts them.
 * Returns array( ok, message ).
 */
function bc_pdp_cart_add( $id, $qty ) {
	$product = $id ? wc_get_product( $id ) : null;
	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return array( false, 'این کالا فعلاً موجود نیست.' );
	}
	$product_id   = $id;
	$variation_id = 0;
	$variation    = array();

	if ( $product->is_type( 'variation' ) ) {
		$variation_id = $id;
		$product_id   = $product->get_parent_id();
		$parent       = wc_get_product( $product_id );
		$allowed      = $parent ? $parent->get_variation_attributes() : array();
		foreach ( $product->get_variation_attributes() as $key => $value ) {
			if ( '' === (string) $value ) { // "Any value" attribute: the visitor's choice, if it is one of the options.
				$posted = isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				foreach ( $allowed as $name => $options ) {
					if ( 'attribute_' . sanitize_title( $name ) === $key && in_array( $posted, array_map( 'strval', (array) $options ), true ) ) {
						$value = $posted;
					}
				}
				if ( '' === (string) $value ) {
					return array( false, 'لطفاً گزینه‌های محصول را انتخاب کنید.' );
				}
			}
			$variation[ $key ] = $value;
		}
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $qty, $variation_id, $variation );
	} elseif ( $product->is_type( 'simple' ) ) {
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $qty );
	} else {
		return array( false, 'لطفاً گزینه‌های این محصول را در صفحه محصول انتخاب کنید.' );
	}

	if ( ! $passed || false === WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation ) ) {
		return array( false, bc_pdp_cart_error( 'افزودن این کالا ممکن نشد. دوباره تلاش کنید.' ) );
	}
	do_action( 'woocommerce_ajax_added_to_cart', $product_id ); // as WooCommerce's own AJAX add to cart does
	return array( true, '' );
}

/** New quantity for a line (0 removes it). Same rules as the cart page. Returns array( ok, message ). */
function bc_pdp_cart_qty( $key, $qty ) {
	$cart = WC()->cart;
	$item = $cart->get_cart_item( $key );
	if ( ! $item ) {
		return array( true, '' ); // Out of date page: the answer carries the current cart.
	}
	if ( $qty < 1 ) {
		$cart->remove_cart_item( $key );
		return array( true, '' );
	}
	$max = $item['data']->get_max_purchase_quantity();
	$msg = '';
	if ( $max > -1 && $qty > $max ) {
		$qty = max( 1, $max );
		$msg = $max > 0 ? sprintf( 'حداکثر موجودی این کالا %s عدد است.', number_format_i18n( $max ) ) : 'این کالا فعلاً موجود نیست.';
	}
	if ( ! apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty ) ) {
		return array( false, bc_pdp_cart_error( 'تغییر تعداد ممکن نیست.' ) );
	}
	$cart->set_quantity( $key, $qty, true );
	return array( true, $msg );
}

/*
 * WooCommerce refreshes its cart cookies (items in cart, cart hash) on "add to cart" only; after a quantity
 * change or a removal it would do it too late (at shutdown, after the answer was sent). Ask its cart session now.
 */
function bc_pdp_cart_cookies() {
	global $wp_filter;
	if ( empty( $wp_filter['woocommerce_add_to_cart'] ) ) {
		return;
	}
	foreach ( $wp_filter['woocommerce_add_to_cart']->callbacks as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			$fn = $cb['function'];
			if ( is_array( $fn ) && $fn[0] instanceof WC_Cart_Session && 'maybe_set_cart_cookies' === $fn[1] ) {
				$fn[0]->maybe_set_cart_cookies();
				return;
			}
		}
	}
}

function bc_pdp_cart_ajax() {
	nocache_headers();
	do_action( 'litespeed_control_set_nocache', 'bc product page cart request' );
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	if ( ( defined( 'BC_PDP_DISABLED' ) && BC_PDP_DISABLED ) || 'post' !== $method || empty( $_SERVER['HTTP_X_BC_PDP'] ) ) {
		wp_send_json( array( 'ok' => false, 'msg' => '' ), 400 );
	}
	wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}
	// phpcs:disable WordPress.Security.NonceVerification -- guarded by the custom header, see the file comment.
	$act = isset( $_POST['act'] ) ? sanitize_key( wp_unslash( $_POST['act'] ) ) : '';
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	switch ( $act ) {
		case 'add':
			$res = bc_pdp_cart_add( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, isset( $_POST['quantity'] ) ? max( 1, absint( $_POST['quantity'] ) ) : 1 );
			break;
		case 'qty':
			$res = bc_pdp_cart_qty( $key, isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 1 );
			break;
		case 'remove':
			if ( WC()->cart->get_cart_item( $key ) ) {
				WC()->cart->remove_cart_item( $key );
			}
			$res = array( true, '' );
			break;
		case 'get':
			$res = array( true, '' );
			break;
		default:
			wp_send_json( array( 'ok' => false, 'msg' => '' ), 400 );
	}
	// phpcs:enable

	$out = array(
		'ok'  => (bool) $res[0],
		'msg' => (string) $res[1],
	);
	if ( 'get' !== $act ) {
		WC()->cart->calculate_totals();
		// The header mini cart, built the way WooCommerce's get_refreshed_fragments builds it.
		ob_start();
		woocommerce_mini_cart();
		$mini             = ob_get_clean();
		$out['fragments'] = apply_filters( 'woocommerce_add_to_cart_fragments', array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' ) );
		$out['cart_hash'] = WC()->cart->get_cart_hash();
		bc_pdp_cart_cookies();
	}
	wc_clear_notices();
	$out['items'] = bc_pdp_cart_items();
	$out['count'] = (int) WC()->cart->get_cart_contents_count();
	wp_send_json( $out );
}
