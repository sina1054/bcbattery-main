<?php
/**
 * BC Battery - small purchase-flow fixes from the Notion review (role 1 table).
 *
 *  D2 : Tehran - the courier is the first and default shipping method; a method the customer picked stays picked.
 *  D4 : the hidden WooCommerce #place_order button can no longer be reached with Tab or read by screen readers
 *       (our own visible buttons click it from checkout.js / pay.js).
 *  D5 : an out-of-stock product page does not promise fast delivery.
 *
 * Each fix can be switched off on its own: add_filter( 'bc_flow_fix_<code>', '__return_false' ), e.g. bc_flow_fix_d2.
 */
defined( 'ABSPATH' ) || exit;

function bc_flow_fix_on( $code ) {
	return (bool) apply_filters( 'bc_flow_fix_' . $code, true );
}

/* ---------------- D2: courier first and default in Tehran ---------------- */

function bc_flow_is_base_state( $package ) {
	$state = isset( $package['destination']['state'] ) ? (string) $package['destination']['state'] : '';
	return '' !== $state && function_exists( 'WC' ) && (string) WC()->countries->get_base_state() === $state;
}

add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	if ( ! bc_flow_fix_on( 'd2' ) || ! is_array( $rates ) || ! bc_flow_is_base_state( $package ) ) {
		return $rates;
	}
	$first = array();
	foreach ( $rates as $id => $rate ) {
		if ( 'WC_Courier_Method' === $rate->get_method_id() ) {
			$first[ $id ] = $rate;
		}
	}
	return $first ? $first + array_diff_key( $rates, $first ) : $rates;
}, 50, 2 );

/* Only used by WooCommerce when the customer has no valid choice yet (first visit, or the address moved to another zone). */
add_filter( 'woocommerce_shipping_chosen_method', function ( $default, $rates, $chosen ) {
	if ( ! bc_flow_fix_on( 'd2' ) || ! is_array( $rates ) ) {
		return $default;
	}
	foreach ( $rates as $id => $rate ) {
		if ( 'WC_Courier_Method' === $rate->get_method_id() ) {
			// The courier only exists in the Tehran zone, so its presence means a Tehran address.
			return (string) $id;
		}
	}
	return $default;
}, 50, 3 );

/* ---------------- D4: hidden #place_order out of the tab order ---------------- */

function bc_flow_hide_place_order( $html ) {
	if ( ! bc_flow_fix_on( 'd4' ) || ! is_string( $html ) || false !== strpos( $html, 'tabindex=' ) ) {
		return $html;
	}
	return preg_replace( '/<button\b(?=[^>]*\bid="place_order")/', '<button tabindex="-1" aria-hidden="true"', $html, 1 );
}
add_filter( 'woocommerce_order_button_html', 'bc_flow_hide_place_order', 50 );
add_filter( 'woocommerce_pay_order_button_html', 'bc_flow_hide_place_order', 50 );

/* ---------------- D5: no "fast delivery" pill on an out-of-stock product ---------------- */

add_filter( 'bc_pdp_texts', function ( $t ) {
	if ( ! bc_flow_fix_on( 'd5' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
		return $t;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( $product && ! $product->is_in_stock() ) {
		$t['fast'] = '';
	}
	return $t;
} );

/* ---------------- C2: complementary products that fit the price of what is being bought ---------------- */

/*
 * Automatic complementary lists keep their order, but anything costing more than 3x the product it is suggested
 * for moves to the end (a 450,000 toman cable no longer leads with a 15.8 million power bank). Nothing is removed,
 * so a short list never becomes empty. The cart merges these per-item lists in order, so the cart follows too.
 * Ratio: filter bc_reco_price_ratio (0 = off).
 */
add_filter( 'bc_reco_complementary', function ( $ids, $pid ) {
	$ratio = (float) apply_filters( 'bc_reco_price_ratio', 3 );
	if ( ! bc_flow_fix_on( 'c2' ) || $ratio <= 0 || ! is_array( $ids ) || count( $ids ) < 2 || ! function_exists( 'bc_reco_index' ) ) {
		return $ids;
	}
	$index  = bc_reco_index();
	$anchor = isset( $index[ $pid ] ) ? (float) $index[ $pid ]['price'] : 0;
	if ( $anchor <= 0 ) {
		return $ids;
	}
	$fit  = array();
	$over = array();
	foreach ( $ids as $id ) {
		$price = isset( $index[ $id ] ) ? (float) $index[ $id ]['price'] : 0;
		if ( $price > $anchor * $ratio ) {
			$over[] = $id;
		} else {
			$fit[] = $id;
		}
	}
	return array_merge( $fit, $over );
}, 20, 2 );

/* Fewer cards: 6 in the cart and in the "added to cart" sheet (was 8). */
add_filter( 'bc_cart_comp_limit', function ( $n ) {
	return bc_flow_fix_on( 'c2' ) ? min( (int) $n, 6 ) : $n;
} );
add_filter( 'bc_pdp_sheet_limit', function ( $n ) {
	return bc_flow_fix_on( 'c2' ) ? min( (int) $n, 6 ) : $n;
} );

/* ---------------- S7: product photos without alt text get the product name ---------------- */

add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	if ( ! bc_flow_fix_on( 's7' ) || is_admin() || ! empty( $attr['alt'] ) || ! $attachment instanceof WP_Post ) {
		return $attr;
	}
	$name = '';
	global $product;
	if ( $product instanceof WC_Product && ( (int) $product->get_image_id() === (int) $attachment->ID || in_array( (int) $attachment->ID, array_map( 'intval', $product->get_gallery_image_ids() ), true ) ) ) {
		$name = $product->get_name();
	} elseif ( $attachment->post_parent && 'product' === get_post_type( $attachment->post_parent ) ) {
		$name = get_the_title( $attachment->post_parent );
	}
	if ( '' !== $name ) {
		$attr['alt'] = trim( wp_strip_all_tags( $name ) );
	}
	return $attr;
}, 20, 2 );

/* ---------------- D6: coupon messages in the shop's words ---------------- */

add_filter( 'woocommerce_coupon_error', function ( $err, $code, $coupon ) {
	if ( ! bc_flow_fix_on( 'd6' ) ) {
		return $err;
	}
	// 100 invalid / filtered, 105 does not exist, 107 expired.
	if ( in_array( (int) $code, array( 100, 105, 107 ), true ) ) {
		return 'این کد تخفیف نامعتبر است یا منقضی شده. دوباره بررسی کنید.';
	}
	return is_string( $err ) ? str_replace( array( 'کوپن‌ها', 'کوپن' ), array( 'کدهای تخفیف', 'کد تخفیف' ), $err ) : $err;
}, 20, 3 );
add_filter( 'woocommerce_coupon_message', function ( $msg ) {
	return bc_flow_fix_on( 'd6' ) && is_string( $msg ) ? str_replace( array( 'کوپن‌ها', 'کوپن' ), array( 'کدهای تخفیف', 'کد تخفیف' ), $msg ) : $msg;
}, 20 );

/* ---------------- S9: no empty headings in product descriptions ---------------- */

/* About 100 product descriptions contain <h2></h2> or <h2>&nbsp;</h2>. Dropped when shown; the saved content is not touched. */
function bc_flow_drop_empty_headings( $html ) {
	if ( ! bc_flow_fix_on( 's9' ) || ! is_string( $html ) || false === stripos( $html, '<h' ) ) {
		return $html;
	}
	return preg_replace( '~<h([1-6])\b[^>]*>(?:\s|&nbsp;|&#160;|\x{00A0}|<br\s*/?>)*</h\1>~iu', '', $html );
}
add_filter( 'the_content', function ( $html ) {
	return 'product' === get_post_type() ? bc_flow_drop_empty_headings( $html ) : $html;
}, 20 );
add_filter( 'woocommerce_short_description', 'bc_flow_drop_empty_headings', 20 );


/*
 * P1 (21 Oct): order SMS no longer hold up the way to the bank.
 * On "pay", WooCommerce creates the order and two plugins send their SMS right there (Persian WooCommerce SMS on
 * order created + processed, Woodmart Plus to the customer and the manager): each one waits for the SMS web service
 * (up to 8 s when it is slow) before the visitor is sent to the gateway. During the checkout request those callbacks
 * are queued instead and run after the answer has gone to the browser (litespeed_finish_request), so the SMS still go
 * out, a second or two later. Off: add_filter( 'bc_flow_fix_P1', '__return_false' ).
 */
function bc_p1_is_sms_callback( $fn ) {
	if ( ! is_array( $fn ) || ! isset( $fn[0], $fn[1] ) ) {
		return false;
	}
	$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
	return 'PW\PWSMS\Orders' === ltrim( $class, '\\' ) || 'plus_order_notification' === $class;
}

add_action( 'woocommerce_checkout_process', function () {
	if ( ! apply_filters( 'bc_flow_fix_P1', true ) || ! function_exists( 'litespeed_finish_request' ) ) {
		return;
	}
	global $wp_filter;
	$GLOBALS['bc_p1_queue'] = array();
	foreach ( array( 'woocommerce_checkout_order_created', 'woocommerce_checkout_order_processed', 'woocommerce_order_status_changed' ) as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( ! bc_p1_is_sms_callback( $cb['function'] ) ) {
					continue;
				}
				$fn   = $cb['function'];
				$args = (int) $cb['accepted_args'];
				remove_action( $hook, $fn, $priority );
				add_action( $hook, function () use ( $fn ) {
					$GLOBALS['bc_p1_queue'][] = array( $fn, func_get_args() );
				}, $priority, $args );
			}
		}
	}
	register_shutdown_function( function () {
		if ( empty( $GLOBALS['bc_p1_queue'] ) ) {
			return;
		}
		ignore_user_abort( true );
		litespeed_finish_request(); // the browser has its answer (redirect to the bank) from here on
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		foreach ( $GLOBALS['bc_p1_queue'] as $job ) {
			try {
				call_user_func_array( $job[0], $job[1] );
			} catch ( \Throwable $e ) {
				error_log( 'bc P1 deferred SMS: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	} );
}, 1 );
