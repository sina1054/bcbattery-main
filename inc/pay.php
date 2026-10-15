<?php
/**
 * BC Battery - Pay: the payment pages and what comes after them (step 3 of the purchase flow and its results).
 *
 * Pages
 *   order-pay, with pay_for_order    retry after a failed payment, or another gateway      templates/form-pay.php
 *   order-pay, without it            the moment before the bank page                        templates/order-receipt.php
 *   order-received                   paid / placed / not paid yet / failed                  templates/thankyou.php
 *   /checkout/ after a bank "no"     the gateways send the visitor back with an error       templates/pay-result.php (state "fail")
 * Every result screen is drawn by ONE template, templates/pay-result.php; bc_pay_result_copy() holds the texts.
 *
 * What this file adds on top of WooCommerce
 *   - The gateway is started BEFORE the page prints anything (bc_pay_start_gateway): a redirect to the bank always works,
 *     and a bank form gets a clean "moving to the bank" screen instead of half a theme page.
 *   - A "came back from the payment" mark in the WooCommerce session, so /checkout/ can tell "the bank said no" from a normal visit.
 *   - Order based helpers (totals, shipping, items) so the order pages reuse the summary of the checkout.
 *
 * Not active until bc_flow_enabled() is true.
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Which payment page is this?                                         */
/* ------------------------------------------------------------------ */

/** order-pay without pay_for_order: WooCommerce shows order-receipt.php there, and the gateway sends the visitor to the bank. */
function bc_pay_is_receipt() {
	return function_exists( 'bc_flow_enabled' ) && bc_flow_enabled() && 'pay' === bc_flow_page() && empty( $_GET['pay_for_order'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

/** Available gateways, id => gateway. */
function bc_pay_gateways() {
	return ( function_exists( 'WC' ) && WC()->payment_gateways() ) ? (array) WC()->payment_gateways()->get_available_payment_gateways() : array();
}

/* ------------------------------------------------------------------ */
/* "The payment came back" mark and the failure screen on /checkout/    */
/* ------------------------------------------------------------------ */

/*
 * Both gateways answer a failed or cancelled payment with an error notice and a redirect to /checkout/. The mark below is set when
 * the visitor comes back from the bank (any wc-api callback) and when a payment is started; /checkout/ shows the failure screen
 * when the mark and an error notice are both there. An error without the mark (stock problem, validation) stays a normal notice.
 */
function bc_pay_mark( $on ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	if ( ! $on ) {
		WC()->session->set( 'bc_pay_back', null );
	} elseif ( WC()->session->has_session() ) {
		WC()->session->set( 'bc_pay_back', time() );
	}
}

add_action( 'woocommerce_api_request', function () {
	if ( function_exists( 'bc_flow_enabled' ) && bc_flow_enabled() ) {
		bc_pay_mark( true );
	}
} );

/** Error notices as short texts (and remove them from the session, so they are not shown a second time). */
function bc_pay_take_errors() {
	$texts = array();
	foreach ( (array) wc_get_notices( 'error' ) as $e ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( is_array( $e ) ? (string) ( $e['notice'] ?? '' ) : (string) $e ) ) );
		if ( '' !== $text && ! in_array( $text, $texts, true ) ) {
			$texts[] = mb_substr( $text, 0, 220 );
		}
	}
	$all = wc_get_notices();
	unset( $all['error'] );
	wc_set_notices( $all );
	return array_slice( $texts, 0, 3 );
}

/** array( 'order' => WC_Order|null, 'messages' => string[] ) while the failure screen is due on this request, otherwise null. */
function bc_pay_failure( $set = null ) {
	static $ctx = null;
	if ( is_array( $set ) ) {
		$ctx = $set;
	}
	return $ctx;
}

/* Runs on 'wp', before WooCommerce prints the session notices at the top of the checkout. */
add_action( 'wp', function () {
	if ( ! bc_flow_enabled() || 'checkout' !== bc_flow_page() || ! WC()->session || ! WC()->cart ) {
		return;
	}
	$mark = (int) WC()->session->get( 'bc_pay_back' );
	if ( ! $mark ) {
		return;
	}
	bc_pay_mark( false ); // one look only
	// An empty cart is sent to the cart page by WooCommerce: leave the notices to it.
	if ( $mark < time() - 1800 || ! wc_notice_count( 'error' ) || WC()->cart->is_empty() ) {
		return;
	}
	$order = null;
	$id    = absint( WC()->session->get( 'order_awaiting_payment' ) );
	if ( $id ) {
		$o = wc_get_order( $id );
		if ( $o instanceof WC_Order && $o->needs_payment() ) {
			$order = $o;
		}
	}
	bc_pay_failure( array( 'order' => $order, 'messages' => bc_pay_take_errors() ) );
}, 8 );

/* The payment page of an existing order prints its notices inside the page frame (templates/form-pay.php). */
add_action( 'wp', function () {
	if ( bc_flow_enabled() && 'pay' === bc_flow_page() ) {
		remove_action( 'before_woocommerce_pay', 'woocommerce_output_all_notices', 10 );
	}
}, 9 );

add_filter( 'woocommerce_pay_order_button_text', function ( $text ) {
	return ( function_exists( 'bc_flow_enabled' ) && bc_flow_enabled() ) ? 'پرداخت' : $text;
} );

/* ------------------------------------------------------------------ */
/* Starting the gateway (order-pay without pay_for_order)               */
/* ------------------------------------------------------------------ */

/** What the early start produced: array( 'ran' => bool, 'out' => html the gateway printed and returned from ). */
function bc_pay_receipt( $set = null ) {
	static $state = array( 'ran' => false, 'out' => '' );
	if ( is_array( $set ) ) {
		$state = $set;
	}
	return $state;
}

/*
 * WooCommerce calls the gateway from inside the page (woocommerce_receipt_{gateway}). Two gateways stop the script from there:
 * one with a redirect header (needs an empty output so far), one after printing a form that submits itself. Calling the gateway
 * before the theme prints anything makes both safe: the redirect header always goes out, and for the form we draw a clean screen.
 * The same checks WooCommerce makes before it shows order-receipt.php; when one fails WooCommerce prints its own notice.
 */
add_action( 'template_redirect', 'bc_pay_start_gateway', 1 );
function bc_pay_start_gateway() {
	if ( ! bc_pay_is_receipt() ) {
		return;
	}
	global $wp;
	$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
	$key      = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$order    = $order_id ? wc_get_order( $order_id ) : false;
	if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || ! $order->needs_payment() ) {
		return;
	}
	$hook = 'woocommerce_receipt_' . $order->get_payment_method();
	if ( ! has_action( $hook ) ) {
		return;
	}
	bc_pay_mark( true ); // a redirect back to /checkout/ from here on means the payment could not be started
	$done = false;
	ob_start(
		function ( $buffer ) use ( &$done ) {
			// Called with the buffer when the gateway stops the script (exit); on our own ob_get_clean() the answer is dropped.
			return $done ? '' : bc_pay_interstitial( $buffer );
		}
	);
	try {
		do_action( $hook, $order->get_id() );
	} catch ( \Throwable $e ) {
		error_log( 'BC pay: gateway start failed: ' . $e->getMessage() );
		wc_add_notice( 'اتصال به درگاه برقرار نشد.', 'error' );
	}
	$done = true;
	bc_pay_receipt( array( 'ran' => true, 'out' => (string) ob_get_clean() ) );
}

/** A bank form that submits itself (what the gateway printed before it stopped the script), on a screen of its own. */
function bc_pay_interstitial( $inner ) {
	if ( '' === trim( (string) $inner ) ) {
		return $inner; // nothing printed: the gateway sent a redirect header
	}
	// The gateway's own sentence is a bare text node in the body; font-size 0 hides it, the screen below says the same in Persian.
	$css = 'html{background:#F7F8FA}body{margin:0;font-size:0;font-family:system-ui,Tahoma,sans-serif;color:#242424}'
		. '.bc-go{box-sizing:border-box;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;min-height:100vh;padding:24px;text-align:center;font-size:16px;line-height:1.8}'
		. '.bc-go i{width:44px;height:44px;border:4px solid #D6E2FA;border-top-color:#1D57D0;border-radius:50%;animation:s .8s linear infinite}@keyframes s{to{transform:rotate(360deg)}}'
		. '.bc-go h1{margin:0;font-size:18px}.bc-go p{margin:0;font-size:13px;color:#5B5B5B}'
		. '.bc-go button{display:none;min-height:48px;padding:0 20px;border:0;border-radius:10px;background:#1D57D0;color:#fff;font:inherit;font-size:15px;font-weight:700;cursor:pointer}';
	$html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
		. '<title>انتقال به درگاه بانک</title><style>' . $css . '</style></head><body>'
		. '<main class="bc-go" role="status"><i aria-hidden="true"></i><h1>در حال انتقال به درگاه امن بانک…</h1><p>لطفاً صفحه را نبندید.</p>'
		. '<button type="button" id="bc-go-retry" onclick="var f=document.forms[0];if(f){f.submit()}else{history.back()}">اگر منتقل نشدید، اینجا را بزنید</button></main>'
		. $inner
		. '<script>setTimeout(function(){var b=document.getElementById("bc-go-retry");if(b){b.style.display="inline-block"}},8000)</script></body></html>';
	return $html;
}

/* ------------------------------------------------------------------ */
/* Order helpers                                                       */
/* ------------------------------------------------------------------ */

/** The shipping line of an order in the shape of a checkout rate (id, label, cost, kind), or null. */
function bc_pay_shipping( WC_Order $order ) {
	$items = $order->get_shipping_methods();
	if ( ! $items ) {
		return null;
	}
	$item = reset( $items );
	$cost = (float) $item->get_total() + ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) ? (float) $item->get_total_tax() : 0 );
	$rate = new WC_Shipping_Rate( $item->get_method_id() . ':' . $item->get_instance_id(), $item->get_name(), (float) $item->get_total(), array(), $item->get_method_id(), $item->get_instance_id() );
	return array(
		'id'     => $rate->get_id(),
		'label'  => $item->get_name(),
		'cost'   => $cost,
		'kind'   => bc_checkout_rate_kind( $rate, $cost ),
		'desc'   => '',
		'chosen' => true,
	);
}

/** Same shape as bc_checkout_totals(), so bc_checkout_summary_rows() draws the price rows of an order too. */
function bc_pay_totals( WC_Order $order ) {
	$incl  = 'incl' === get_option( 'woocommerce_tax_display_cart' );
	$ship  = bc_pay_shipping( $order );
	$count = 0;
	foreach ( $order->get_items() as $item ) {
		$count += (int) $item->get_quantity();
	}
	$t = array(
		'rows'     => array(),
		'count'    => $count,
		'blocked'  => false,
		'changed'  => array(),
		'subtotal' => (float) $order->get_subtotal() + ( $incl ? (float) $order->get_cart_tax() : 0 ),
		'discount' => (float) $order->get_total_discount( ! $incl ),
		'shipping' => array( 'needs' => (bool) $ship, 'ready' => true, 'rates' => array(), 'chosen' => '', 'rate' => $ship ),
		'fees'     => array(),
		'taxes'    => array(),
		'total'    => (float) $order->get_total(),
		'collect'  => $ship && 'collect' === $ship['kind'],
	);
	foreach ( $order->get_fees() as $fee ) {
		$t['fees'][] = array( 'name' => $fee->get_name(), 'amount' => (float) $fee->get_total() + ( $incl ? (float) $fee->get_total_tax() : 0 ) );
	}
	if ( wc_tax_enabled() && ! $incl ) {
		foreach ( $order->get_tax_totals() as $tax ) {
			$t['taxes'][] = array( 'label' => $tax->label, 'amount' => (float) $tax->amount );
		}
	}
	return $t;
}

/** The order lines as a compact list (thumbnail, name, quantity): the same markup as the checkout summary. */
function bc_pay_items( WC_Order $order ) {
	echo '<ul class="bc-mini">';
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$name    = $item->get_name();
		$attr    = '';
		if ( $product && $product->is_type( 'variation' ) ) {
			$name = get_the_title( $product->get_parent_id() );
			$attr = wc_get_formatted_variation( $product, true );
		}
		$img = $product
			? $product->get_image( 'woocommerce_gallery_thumbnail', array( 'class' => 'bc-mini__thumb', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '56px' ) )
			: wc_placeholder_img( 'woocommerce_gallery_thumbnail', array( 'class' => 'bc-mini__thumb' ) );
		echo '<li class="bc-mini__row"><span class="bc-mini__img">' . $img . '</span><span class="bc-mini__name">' . esc_html( $name ) . ( '' !== $attr ? '<small class="bc-mini__attr">' . esc_html( $attr ) . '</small>' : '' ) . '</span><span class="bc-mini__qty">×' . bc_num( $item->get_quantity() ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}

/** Where "track my order" leads: the order page of a logged-in owner. Guests have none (the shop has no tracking page); a filter can add one. */
function bc_pay_track_url( WC_Order $order ) {
	$url = '';
	if ( is_user_logged_in() && (int) $order->get_user_id() === get_current_user_id() ) {
		$url = $order->get_view_order_url();
	}
	return (string) apply_filters( 'bc_pay_track_url', $url, $order );
}

function bc_pay_collect_note( $ship ) {
	$courier = $ship && false !== stripos( (string) $ship['id'], 'courier' );
	$text    = $courier
		? 'هزینه ارسال را هنگام تحویل به پیک پرداخت کنید. پیک تا حدود ۳ ساعت دیگر با شما تماس می‌گیرد.'
		: 'هزینه ارسال به‌صورت پس‌کرایه هنگام دریافت بسته پرداخت می‌شود و خارج از مبلغ پرداخت آنلاین است.';
	return (string) apply_filters( 'bc_pay_collect_note', $text, $ship );
}

/** HTML printed by plugins (gateway notes, tracking scripts): boxed when there is something to read, passed through when it is only a script or a pixel. */
function bc_pay_plugin_html( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return '';
	}
	if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
		return $html;
	}
	return '<div class="bc-result__extra">' . $html . '</div>';
}

/* ------------------------------------------------------------------ */
/* The result screen                                                   */
/* ------------------------------------------------------------------ */

/** paid | placed | pending | fail | unknown, for the order-received page. */
function bc_pay_thanks_state( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return 'unknown';
	}
	if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
		return 'fail';
	}
	if ( $order->has_status( 'pending' ) ) {
		return 'pending';
	}
	// "Paid" only when money went through a gateway: cash on delivery and free orders are "placed".
	$online = (float) $order->get_total() > 0 && ( $order->get_date_paid() || '' !== (string) $order->get_transaction_id() );
	return apply_filters( 'bc_pay_thanks_state', $online ? 'paid' : 'placed', $order );
}

/** tone (ok | warn | err | go), icon, title and text of a state. */
function bc_pay_result_copy( $state, $order = null ) {
	$all  = array(
		'paid'    => array( 'tone' => 'ok', 'icon' => 'check', 'title' => 'پرداخت با موفقیت انجام شد', 'text' => 'سفارش شما ثبت شد و پیامک تأیید برای شما ارسال می‌شود.' ),
		'placed'  => array( 'tone' => 'ok', 'icon' => 'check', 'title' => 'سفارش شما ثبت شد', 'text' => 'پیامک تأیید برای شما ارسال می‌شود.' ),
		'unknown' => array( 'tone' => 'ok', 'icon' => 'check', 'title' => 'سفارش شما دریافت شد', 'text' => '' ),
		'pending' => array( 'tone' => 'warn', 'icon' => 'clock', 'title' => 'پرداخت هنوز کامل نشده است', 'text' => 'اگر پرداخت را انجام داده‌اید، چند دقیقه بعد این صفحه را دوباره باز کنید؛ در غیر این صورت می‌توانید پرداخت را ادامه دهید.' ),
		'fail'    => array( 'tone' => 'err', 'icon' => 'close', 'title' => 'پرداخت ناموفق بود', 'text' => 'پرداخت انجام نشد یا لغو شد. اگر مبلغی از حساب شما کسر شده، تا ۷۲ ساعت کاری به حساب شما برمی‌گردد.' ),
		'gateway' => array( 'tone' => 'err', 'icon' => 'close', 'title' => 'اتصال به درگاه برقرار نشد', 'text' => 'چند لحظه بعد دوباره تلاش کنید یا درگاه دیگری را انتخاب کنید.' ),
		'go'      => array( 'tone' => 'go', 'icon' => '', 'title' => 'در حال انتقال به درگاه بانک…', 'text' => 'لطفاً صفحه را نبندید و دکمهٔ بازگشت را نزنید.' ),
	);
	$copy = isset( $all[ $state ] ) ? $all[ $state ] : $all['unknown'];
	return apply_filters( 'bc_pay_result_copy', $copy, $state, $order );
}

/** Rows of the facts card: array( label, html, copy (text for the copy button or ''), sep (line above) ). */
function bc_pay_facts( $state, $order ) {
	$rows = array();
	if ( ! $order instanceof WC_Order || in_array( $state, array( 'unknown', 'fail', 'gateway' ), true ) ) {
		return $rows;
	}
	$number = (string) $order->get_order_number();
	$tx     = (string) $order->get_transaction_id();
	$title  = trim( wp_strip_all_tags( (string) $order->get_payment_method_title() ) );
	$free   = (float) $order->get_total() <= 0;
	$rows[] = array( 'label' => 'شماره سفارش', 'html' => esc_html( $number ), 'copy' => $number );
	if ( 'paid' === $state && '' !== $tx ) {
		$rows[] = array( 'label' => 'کد پیگیری بانک', 'html' => esc_html( $tx ), 'copy' => $tx );
	}
	if ( '' !== $title && ! $free ) {
		$rows[] = array( 'label' => in_array( $state, array( 'paid', 'go' ), true ) ? 'درگاه' : 'روش پرداخت', 'html' => esc_html( $title ), 'copy' => '' );
	}
	$rows[] = array( 'label' => 'paid' === $state ? 'مبلغ پرداخت‌شده' : 'مبلغ سفارش', 'html' => bc_money( $order->get_total() ), 'copy' => '' );
	$ship = bc_pay_shipping( $order );
	if ( $ship ) {
		$rows[] = array( 'label' => 'روش ارسال', 'html' => esc_html( $ship['label'] ), 'copy' => '', 'sep' => true );
	}
	return apply_filters( 'bc_pay_facts', $rows, $state, $order );
}

/** "Try again" as a form: posts to the order's payment page like WooCommerce's own pay button, so the payment starts at once. */
function bc_pay_retry_form( WC_Order $order, $label ) {
	$gateways = bc_pay_gateways();
	$method   = $order->get_payment_method();
	if ( ! isset( $gateways[ $method ] ) ) {
		$method = (string) array_key_first( $gateways );
	}
	if ( '' === $method ) {
		return '';
	}
	return '<form class="bc-result__form" method="post" action="' . esc_url( $order->get_checkout_payment_url() ) . '">'
		. '<input type="hidden" name="woocommerce_pay" value="1"><input type="hidden" name="payment_method" value="' . esc_attr( $method ) . '">'
		. wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce', false, false )
		. '<button type="submit" class="bc-btn bc-btn--primary bc-btn--lg">' . esc_html( $label ) . '</button></form>';
}

/** Buttons: array of array( 'link' | 'form', label, url, 'primary' | 'ghost' ). */
function bc_pay_result_actions( $state, $order ) {
	$shop = wc_get_page_permalink( 'shop' );
	$pay  = ( $order && $order->needs_payment() ) ? $order->get_checkout_payment_url() : '';
	$many = count( bc_pay_gateways() ) > 1;
	$a    = array();
	switch ( $state ) {
		case 'paid':
		case 'placed':
			$track = $order ? bc_pay_track_url( $order ) : '';
			if ( $track ) {
				$a[] = array( 'link', 'پیگیری سفارش', $track, 'primary' );
			}
			$a[] = array( 'link', 'بازگشت به فروشگاه', $shop, $track ? 'ghost' : 'primary' );
			break;
		case 'pending':
			if ( $pay ) {
				$a[] = array( 'link', 'ادامه پرداخت', $pay, 'primary' );
			}
			$a[] = array( 'link', 'بازگشت به فروشگاه', $shop, $pay ? 'ghost' : 'primary' );
			break;
		case 'fail':
		case 'gateway':
			if ( $pay ) {
				$a[] = array( 'form', 'تلاش مجدد برای پرداخت', $pay, 'primary' );
				if ( $many ) {
					$a[] = array( 'link', 'انتخاب درگاه دیگر', $pay, 'ghost' );
				}
			} else {
				$a[] = array( 'link', 'بازگشت به سبد خرید', wc_get_cart_url(), 'primary' );
				$a[] = array( 'link', 'بازگشت به فروشگاه', $shop, 'ghost' );
			}
			break;
		case 'go':
			if ( $pay && $many ) {
				$a[] = array( 'link', 'انتخاب درگاه دیگر', $pay, 'ghost' );
			}
			break;
		default:
			$a[] = array( 'link', 'بازگشت به فروشگاه', $shop, 'primary' );
	}
	return apply_filters( 'bc_pay_result_actions', $a, $state, $order );
}

/* ------------------------------------------------------------------ */
/* Assets                                                              */
/* ------------------------------------------------------------------ */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! bc_flow_active() ) {
		return;
	}
	$page = bc_flow_page();
	if ( ! in_array( $page, array( 'pay', 'thanks', 'checkout' ), true ) || ( 'checkout' === $page && ! bc_pay_failure() ) ) {
		return;
	}
	$style_deps = array( 'bc-flow' );
	if ( 'pay' === $page && ! bc_pay_is_receipt() ) {
		// The payment page of an existing order looks like step 3 of the checkout.
		bc_enqueue_module( 'bc-checkout', 'checkout.css', '', array(), array( 'bc-flow' ) );
		$style_deps[] = 'bc-checkout';
	}
	bc_enqueue_module( 'bc-pay', 'pay.css', 'pay.js', array( 'bc-flow' ), $style_deps );
}, 20 );