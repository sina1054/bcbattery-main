<?php
/**
 * BC Battery - Checkout (steps 2 and 3 of the purchase flow). Guest checkout, mobile first.
 *
 * One page with two views inside ONE WooCommerce checkout form:
 *   details - contact, address, delivery method   (step 2)
 *   pay     - payment method, terms, pay button     (step 3)
 * WooCommerce still does everything that matters (totals, shipping, gateways, validation, order creation);
 * only the markup, the field list and the behaviour of the page are ours.
 *
 *  - Fields:    bc_checkout_field_spec() is the single place that decides labels, hints, order and validation.
 *  - Markup:    inc/templates/checkout.php (page), review-order.php (summary), payment.php (gateways),
 *               checkout-delivery.php (shipping methods), checkout-bar.php (mobile bottom bar).
 *  - Updates:   WooCommerce's own update_order_review request. Next to its two fragments we add ours with
 *               woocommerce_update_order_review_fragments (delivery card, totals in the bar).
 *  - Behaviour: assets/js/checkout.js (view switching, validation messages, mapping of server errors).
 *
 * Not active until bc_flow_enabled() is true.
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* Basics                                                              */
/* ------------------------------------------------------------------ */

function bc_checkout_active() {
	return function_exists( 'bc_flow_enabled' ) && function_exists( 'bc_flow_page' ) && bc_flow_enabled() && 'checkout' === bc_flow_page();
}

/* Persian/Arabic digits -> Latin. */
function bc_en_digits( $s ) {
	return strtr( (string) $s, array(
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	) );
}

/** "علی رضا رضایی" => array( 'علی رضا', 'رضایی' ): the last word is the surname. One word => surname stays empty. */
function bc_split_name( $full ) {
	$marks = array( html_entity_decode( '&rlm;', ENT_QUOTES, 'UTF-8' ), html_entity_decode( '&lrm;', ENT_QUOTES, 'UTF-8' ) );
	$full  = trim( preg_replace( '/[[:space:]]+/u', ' ', str_replace( $marks, '', (string) $full ) ) );
	if ( '' === $full ) {
		return array( '', '' );
	}
	$words = explode( ' ', $full );
	if ( 1 === count( $words ) ) {
		return array( $words[0], '' );
	}
	$last = array_pop( $words );
	return array( implode( ' ', $words ), $last );
}

/* ------------------------------------------------------------------ */
/* Fields                                                              */
/* ------------------------------------------------------------------ */

/* key => overrides. Several plugins touch the fields (Woodmart, Woodmart-Plus, Persian Woo Shipping, WP-Parsidate);
   this runs after all of them and never changes a field type, so the state/city selects keep working. */
function bc_checkout_field_spec() {
	return array(
		// Not shown: the page has one "full name" field (bc_full_name); it is split into these two before WooCommerce validates.
		'billing_first_name' => array( 'required' => false, 'priority' => 10 ),
		'billing_last_name'  => array( 'required' => false, 'priority' => 20 ),
		'billing_phone'      => array(
			'label'             => 'شماره موبایل',
			'placeholder'       => '09123456789',
			'class'             => array( 'form-row-wide', 'bc-half-lg', 'bc-ltr' ),
			'priority'          => 30,
			'required'          => true,
			'autocomplete'      => 'tel-national',
			'custom_attributes' => array( 'inputmode' => 'tel', 'dir' => 'ltr' ), // Any format is accepted (see validation below).
			'description'       => 'وضعیت سفارش و کد رهگیری به همین شماره پیامک می‌شود.',
		),
		'billing_state'      => array( 'label' => 'استان', 'class' => array( 'form-row-first' ), 'priority' => 40 ),
		'billing_city'       => array( 'label' => 'شهر', 'class' => array( 'form-row-last' ), 'priority' => 50 ),
		'billing_district'   => array( 'label' => 'محله', 'class' => array( 'form-row-wide' ), 'priority' => 55 ),
		'billing_address_1'  => array(
			'type'              => 'textarea',
			'label'             => 'آدرس کامل',
			'placeholder'       => 'خیابان، کوچه، پلاک، واحد',
			'class'             => array( 'form-row-wide' ),
			'priority'          => 60,
			'autocomplete'      => 'street-address',
			'custom_attributes' => array( 'rows' => '3' ),
		),
		'billing_postcode'   => array(
			'label'             => 'کد پستی',
			'placeholder'       => '۱۰ رقم، بدون خط تیره',
			'class'             => array( 'form-row-wide', 'bc-half-lg', 'bc-ltr' ),
			'priority'          => 70,
			'required'          => true,
			'autocomplete'      => 'postal-code',
			'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '10', 'dir' => 'ltr' ),
		),
		'billing_email'      => array(
			'label'             => 'ایمیل',
			'placeholder'       => 'name@example.com',
			'class'             => array( 'form-row-wide', 'bc-ltr' ),
			'priority'          => 80,
			'required'          => false,
			'autocomplete'      => 'email',
			'custom_attributes' => array( 'dir' => 'ltr' ),
		),
	);
}

/* Apply to a group of fields (used for the checkout list and for the locale data). */
function bc_checkout_apply_spec( array $fields ) {
	foreach ( bc_checkout_field_spec() as $key => $spec ) {
		if ( ! isset( $fields[ $key ] ) ) {
			continue;
		}
		foreach ( $spec as $prop => $val ) {
			if ( 'class' === $prop ) {
				// Keep functional classes (address-field, validate-*), swap only the width classes.
				$keep                    = array_diff( isset( $fields[ $key ]['class'] ) ? (array) $fields[ $key ]['class'] : array(), array( 'form-row-first', 'form-row-last', 'form-row-wide' ) );
				$fields[ $key ]['class'] = array_values( array_unique( array_merge( $keep, $val ) ) );
			} elseif ( 'custom_attributes' === $prop ) {
				$fields[ $key ][ $prop ] = array_merge( isset( $fields[ $key ][ $prop ] ) ? (array) $fields[ $key ][ $prop ] : array(), $val );
			} else {
				$fields[ $key ][ $prop ] = $val;
			}
		}
	}
	return $fields;
}

add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || empty( $fields['billing'] ) ) {
		return $fields;
	}
	$fields['billing'] = bc_checkout_apply_spec( $fields['billing'] );
	// Never asked in the guest flow. (billing_country stays: it is printed hidden, so the order keeps its country.)
	foreach ( array( 'billing_company', 'billing_address_2' ) as $k ) {
		unset( $fields['billing'][ $k ] );
	}
	if ( ! apply_filters( 'bc_checkout_show_email', false ) ) {
		unset( $fields['billing']['billing_email'] );
	}
	if ( ! apply_filters( 'bc_checkout_show_notes', false ) ) {
		unset( $fields['order']['order_comments'] );
	} elseif ( isset( $fields['order']['order_comments'] ) ) {
		$fields['order']['order_comments']['label']             = 'توضیحات سفارش';
		$fields['order']['order_comments']['placeholder']       = 'مثلاً زمان تحویل یا نکته‌ای برای پیک';
		$fields['order']['order_comments']['custom_attributes'] = array( 'rows' => '2' );
	}
	unset( $fields['account'] );
	return $fields;
}, 100000 );

/* The address JS re-labels fields per country from this locale data; keep it in step with the spec. */
add_filter( 'woocommerce_get_country_locale', function ( $locale ) {
	if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() ) {
		return $locale;
	}
	$spec = bc_checkout_field_spec();
	$ir   = isset( $locale['IR'] ) ? (array) $locale['IR'] : array();
	foreach ( array( 'address_1', 'postcode', 'state', 'city' ) as $k ) {
		if ( isset( $spec[ 'billing_' . $k ] ) ) {
			$ir[ $k ] = array_merge( isset( $ir[ $k ] ) ? (array) $ir[ $k ] : array(), array_intersect_key( $spec[ 'billing_' . $k ], array_flip( array( 'label', 'placeholder' ) ) ) );
		}
	}
	$locale['IR'] = $ir;
	return $locale;
}, 100000 );

/* Guest checkout: no account creation or sign-in prompt inside the flow. */
add_action( 'wp', function () {
	if ( ! bc_checkout_active() ) {
		return;
	}
	add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', fn() => 'no' );
	add_filter( 'pre_option_woocommerce_enable_checkout_login_reminder', fn() => 'no' );
}, 5 );

/* ------------------------------------------------------------------ */
/* Posting and validation                                              */
/* ------------------------------------------------------------------ */

/* Before WooCommerce reads the form: Latin digits, and the full name split into first and last name. */
add_action( 'woocommerce_checkout_process', function () {
	if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || ! isset( $_POST['bc_full_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	foreach ( array( 'billing_phone', 'billing_postcode' ) as $k ) {
		if ( isset( $_POST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$_POST[ $k ] = preg_replace( '/[[:space:]-]/', '', bc_en_digits( wp_unslash( $_POST[ $k ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
	}
	list( $first, $last )        = bc_split_name( wc_clean( wp_unslash( $_POST['bc_full_name'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$_POST['billing_first_name'] = wp_slash( $first );
	$_POST['billing_last_name']  = wp_slash( $last );
}, 5 );

/*
 * The SMS plugin (Persian WooCommerce SMS) refuses the order when the phone is not an Iranian mobile number.
 * The shop takes any phone format, so its check is taken off for this flow and the number is kept as typed
 * (only Persian digits, spaces and dashes are normalised above). SMS still go out whenever it is a mobile number.
 */
add_action( 'woocommerce_checkout_process', function () {
	if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() || ! function_exists( 'PWSMS' ) ) {
		return;
	}
	global $wp_filter;
	if ( isset( $wp_filter['woocommerce_checkout_process'] ) ) {
		foreach ( $wp_filter['woocommerce_checkout_process']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && 'PW\\PWSMS\\Orders' === get_class( $fn[0] ) && 'checkout_fields_validation' === $fn[1] ) {
					remove_action( 'woocommerce_checkout_process', $fn, $priority );
				}
			}
		}
	}
}, 6 );

add_filter( 'woocommerce_checkout_posted_data', function ( $data ) {
	foreach ( array( 'billing_phone', 'billing_postcode' ) as $k ) {
		if ( isset( $data[ $k ] ) ) {
			$data[ $k ] = preg_replace( '/[[:space:]-]/', '', bc_en_digits( $data[ $k ] ) );
		}
	}
	return $data;
}, 5 );

add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ! function_exists( 'bc_flow_enabled' ) || ! bc_flow_enabled() ) {
		return;
	}
	if ( isset( $_POST['bc_full_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		// Any name is accepted, a first name alone included; it only must not be empty.
		if ( '' === trim( (string) ( $data['billing_first_name'] ?? '' ) ) ) {
			$errors->add( 'bc_name', '<strong>نام</strong> را وارد کنید.', array( 'id' => 'bc_full_name' ) );
		}
		if ( bc_checkout_terms() && empty( $_POST['bc_terms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$errors->add( 'bc_terms', 'برای ثبت سفارش باید قوانین و شرایط خرید را بپذیرید.', array( 'id' => 'bc_terms' ) );
		}
	}
	// The phone number is taken in any format (09…, +98…, landline); only "required" applies to it.
	if ( ! empty( $data['billing_postcode'] ) && ! preg_match( '/^[0-9]{10}$/', $data['billing_postcode'] ) ) {
		$errors->add( 'bc_postcode', '<strong>کد پستی</strong> باید ۱۰ رقم باشد.', array( 'id' => 'billing_postcode' ) );
	}
}, 20, 2 );

/* Keep what was typed when the page is shown again (WooCommerce stores the address fields itself, not our name field). */
add_action( 'woocommerce_checkout_update_order_review', function ( $post_data ) {
	if ( ! bc_checkout_active() || ! WC()->customer ) {
		return;
	}
	parse_str( (string) $post_data, $d );
	if ( empty( $d['bc_full_name'] ) ) {
		return;
	}
	list( $first, $last ) = bc_split_name( wc_clean( $d['bc_full_name'] ) );
	WC()->customer->set_billing_first_name( $first );
	WC()->customer->set_billing_last_name( $last );
} );

/* ------------------------------------------------------------------ */
/* Data for the templates                                              */
/* ------------------------------------------------------------------ */

/**
 * 'paid'    - has a price
 * 'free'    - costs nothing
 * 'collect' - the customer pays the courier / carrier on delivery (cost is 0 in the shop, not part of the online payment)
 * Which rates are 'collect' is guessed from the method and its name; a filter can correct it.
 */
function bc_checkout_rate_kind( WC_Shipping_Rate $rate, $cost ) {
	if ( $cost > 0 ) {
		$kind = 'paid';
	} else {
		$plain   = str_replace( array( html_entity_decode( '&zwnj;', ENT_QUOTES, 'UTF-8' ), ' ' ), '', (string) $rate->get_label() );
		$collect = 'free_shipping' !== $rate->get_method_id() && ( 'WC_Courier_Method' === $rate->get_method_id() || (bool) preg_match( '/پسکرایه|بهعهده|درمحل|هنگامتحویل|پیک/u', $plain ) );
		$kind    = $collect ? 'collect' : 'free';
	}
	return apply_filters( 'bc_checkout_rate_kind', $kind, $rate, $cost );
}

/** Shipping rates of the (single) package, ready to show. */
function bc_checkout_shipping() {
	$out  = array( 'needs' => false, 'ready' => false, 'rates' => array(), 'chosen' => '', 'rate' => null );
	$cart = WC()->cart;
	if ( ! $cart || ! $cart->needs_shipping() ) {
		return $out;
	}
	$out['needs'] = true;
	$packages     = WC()->shipping()->get_packages();
	if ( ! $packages ) {
		return $out;
	}
	$index        = (int) array_key_first( $packages );
	$package      = $packages[ $index ];
	$dest         = isset( $package['destination'] ) ? (array) $package['destination'] : array();
	$out['ready'] = ! empty( $dest['state'] ) && ! empty( $dest['city'] );
	$rates        = isset( $package['rates'] ) ? (array) $package['rates'] : array();
	if ( ! $rates ) {
		return $out;
	}
	$selected = (array) WC()->session->get( 'chosen_shipping_methods', array() );
	$chosen   = isset( $selected[ $index ] ) ? (string) $selected[ $index ] : '';
	if ( ! isset( $rates[ $chosen ] ) ) {
		$chosen = (string) array_key_first( $rates );
	}
	$incl = 'incl' === get_option( 'woocommerce_tax_display_cart' );
	foreach ( $rates as $id => $rate ) {
		$cost = (float) $rate->get_cost() + ( $incl ? array_sum( (array) $rate->get_taxes() ) : 0 );
		// Plugins print a short description after each rate (Persian Woo Shipping does); take its text.
		ob_start();
		do_action( 'woocommerce_after_shipping_rate', $rate, $index );
		$desc = trim( preg_replace( '/[[:space:]]+/u', ' ', wp_strip_all_tags( (string) ob_get_clean() ) ) );
		$row  = array(
			'id'     => (string) $id,
			'label'  => $rate->get_label(),
			'cost'   => $cost,
			'kind'   => bc_checkout_rate_kind( $rate, $cost ),
			'desc'   => $desc,
			'chosen' => (string) $id === $chosen,
		);
		$out['rates'][] = $row;
		if ( $row['chosen'] ) {
			$out['rate'] = $row;
		}
	}
	$out['chosen'] = $chosen;
	return $out;
}

/** Cost cell of a shipping rate (HTML). */
function bc_checkout_rate_cost_html( array $r ) {
	if ( 'paid' === $r['kind'] ) {
		return bc_money( $r['cost'] );
	}
	if ( 'free' === $r['kind'] ) {
		return '<span class="bc-ok">رایگان</span>';
	}
	return '<span class="bc-muted">به عهده مشتری</span>';
}

/** Everything the checkout templates show. Computed once per cart calculation. */
function bc_checkout_totals( $reset = false ) {
	static $cache = null;
	if ( $reset ) {
		$cache = null;
		return array();
	}
	if ( null !== $cache ) {
		return $cache;
	}
	$cart = WC()->cart;
	$incl = 'incl' === get_option( 'woocommerce_tax_display_cart' );
	$rows = bc_cart_rows();
	$t    = array(
		'rows'     => $rows,
		'count'    => 0,
		'blocked'  => false,
		'changed'  => array(),
		'subtotal' => (float) $cart->get_subtotal() + ( $incl ? (float) $cart->get_subtotal_tax() : 0 ),
		'discount' => (float) $cart->get_discount_total() + ( $incl ? (float) $cart->get_discount_tax() : 0 ),
		'shipping' => bc_checkout_shipping(),
		'fees'     => array(),
		'taxes'    => array(),
		'total'    => (float) $cart->get_total( 'edit' ),
		'collect'  => false,
	);
	foreach ( $rows as $r ) {
		$t['count'] += $r['qty'];
		if ( $r['out'] || $r['short'] ) {
			$t['blocked'] = true;
		}
		if ( null !== $r['old'] ) {
			$t['changed'][] = $r;
		}
	}
	foreach ( $cart->get_fees() as $fee ) {
		$t['fees'][] = array( 'name' => $fee->name, 'amount' => (float) $fee->total + ( $incl ? (float) $fee->tax : 0 ) );
	}
	if ( wc_tax_enabled() && ! $incl ) {
		foreach ( $cart->get_tax_totals() as $tax ) {
			$t['taxes'][] = array( 'label' => $tax->label, 'amount' => (float) $tax->amount );
		}
	}
	$t['collect'] = ! empty( $t['shipping']['rate'] ) && 'collect' === $t['shipping']['rate']['kind'];
	return $cache = $t;
}
add_action( 'woocommerce_after_calculate_totals', function () {
	bc_checkout_totals( true );
} );

/** Links to the pages the customer agrees to. A page counts only when it is published (so a draft never shows). */
function bc_checkout_terms() {
	$terms = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
	if ( ! $terms ) {
		$page  = get_page_by_path( 'terms-and-conditions' );
		$terms = $page ? (int) $page->ID : 0;
	}
	$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
	if ( ! $privacy ) {
		$page    = get_page_by_path( 'privacy-policy' );
		$privacy = $page ? (int) $page->ID : 0;
	}
	$links = array();
	foreach ( array( array( $terms, 'قوانین و شرایط خرید' ), array( $privacy, 'حریم خصوصی' ) ) as $p ) {
		if ( $p[0] && 'publish' === get_post_status( $p[0] ) ) {
			$links[] = array( 'url' => get_permalink( $p[0] ), 'label' => $p[1] );
		}
	}
	return apply_filters( 'bc_checkout_terms', $links );
}

function bc_checkout_terms_text( array $links ) {
	$a = array();
	foreach ( $links as $l ) {
		$a[] = '<a href="' . esc_url( $l['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $l['label'] ) . '</a>';
	}
	return implode( ' و ', $a );
}

/* ------------------------------------------------------------------ */
/* Pieces of markup used by more than one template                     */
/* ------------------------------------------------------------------ */

function bc_checkout_field( $checkout, $key ) {
	$fields = $checkout->get_checkout_fields( 'billing' );
	if ( isset( $fields[ $key ] ) ) {
		woocommerce_form_field( $key, $fields[ $key ], $checkout->get_value( $key ) );
	}
}

/* One "full name" field instead of first + last name (split again in woocommerce_checkout_process). */
function bc_checkout_name_field( $checkout ) {
	$name = trim( (string) $checkout->get_value( 'billing_first_name' ) . ' ' . (string) $checkout->get_value( 'billing_last_name' ) );
	woocommerce_form_field( 'bc_full_name', array(
		'type'         => 'text',
		'label'        => 'نام و نام خانوادگی',
		'placeholder'  => 'مثال: علی رضایی',
		'required'     => true,
		'class'        => array( 'form-row-wide', 'bc-half-lg' ),
		'autocomplete' => 'name',
	), $name );
}

/** Price rows of the summary and of the bottom sheet. */
function bc_checkout_summary_rows( array $t ) {
	$s    = $t['shipping'];
	$rate = $s['rate'];
	echo '<dl class="bc-sum">';
	echo '<div class="bc-sum__row"><dt>جمع کالاها <span class="bc-muted">(' . bc_num( $t['count'] ) . ' کالا)</span></dt><dd>' . bc_money( $t['subtotal'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( $t['discount'] > 0 ) {
		echo '<div class="bc-sum__row bc-sum__row--discount"><dt>تخفیف</dt><dd>' . bc_money( -$t['discount'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( $s['needs'] ) {
		echo '<div class="bc-sum__row"><dt>هزینه ارسال' . ( $rate ? ' <span class="bc-muted">· ' . esc_html( $rate['label'] ) . '</span>' : '' ) . '</dt><dd>' . ( $rate ? bc_checkout_rate_cost_html( $rate ) : '<span class="bc-muted bc-sum__later">پس از انتخاب آدرس</span>' ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	foreach ( $t['fees'] as $fee ) {
		echo '<div class="bc-sum__row"><dt>' . esc_html( $fee['name'] ) . '</dt><dd>' . bc_money( $fee['amount'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	foreach ( $t['taxes'] as $tax ) {
		echo '<div class="bc-sum__row"><dt>' . esc_html( $tax['label'] ) . '</dt><dd>' . bc_money( $tax['amount'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<div class="bc-sum__row bc-sum__row--total"><dt>' . ( $t['collect'] ? 'مبلغ قابل پرداخت اینترنتی' : 'مبلغ قابل پرداخت' ) . '</dt><dd>' . bc_money( $t['total'] ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</dl>';
	if ( $t['collect'] ) {
		echo '<p class="bc-sum__note">هزینه ارسال هنگام تحویل پرداخت می‌شود؛ خارج از مبلغ پرداخت آنلاین.</p>';
	}
}

/** The cart lines as a compact list (thumbnail, name, quantity). */
function bc_checkout_mini( array $t ) {
	echo '<ul class="bc-mini">';
	foreach ( $t['rows'] as $r ) {
		$img = $r['product']->get_image( 'woocommerce_gallery_thumbnail', array( 'class' => 'bc-mini__thumb', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '56px' ) );
		echo '<li class="bc-mini__row' . ( $r['out'] ? ' is-out' : '' ) . '"><span class="bc-mini__img">' . $img . '</span><span class="bc-mini__name">' . esc_html( $r['name'] ) . ( '' !== $r['attr'] ? '<small class="bc-mini__attr">' . esc_html( $r['attr'] ) . '</small>' : '' ) . '</span><span class="bc-mini__qty">×' . bc_num( $r['qty'] ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}

/* The three small blocks WooCommerce's update request replaces (they come back as fragments). */
function bc_checkout_acc_total_html( array $t ) {
	return '<b class="bc-acc__total" data-bc="acc-total">' . bc_money( $t['total'] ) . '</b>';
}

function bc_checkout_bar_total_html( array $t ) {
	return '<button type="button" class="bc-bar__total" data-bc="bar-total" data-bc-open="bc-sheet" aria-haspopup="dialog" aria-label="جزئیات پرداخت"><span class="bc-bar__count">' . bc_num( $t['count'] ) . ' کالا ' . bc_icon( 'up', 16 ) . '</span>' . bc_money( $t['total'] ) . '</button>';
}

function bc_checkout_sheet_sum_html( array $t ) {
	ob_start();
	echo '<div data-bc="sheet-sum">';
	bc_checkout_summary_rows( $t );
	echo '</div>';
	return (string) ob_get_clean();
}

add_filter( 'woocommerce_update_order_review_fragments', function ( $fragments ) {
	if ( ! bc_checkout_active() ) {
		return $fragments;
	}
	$t = bc_checkout_totals();
	$fragments['[data-bc="delivery"]']  = bc_template_html( 'checkout-delivery', array( 't' => $t ) );
	$fragments['[data-bc="acc-total"]'] = bc_checkout_acc_total_html( $t );
	$fragments['[data-bc="bar-total"]'] = bc_checkout_bar_total_html( $t );
	$fragments['[data-bc="sheet-sum"]'] = bc_checkout_sheet_sum_html( $t );
	return $fragments;
} );

/* Mobile bottom bar: printed in the footer so "position: fixed" is never trapped inside the theme wrappers. */
add_action( 'wp_footer', function () {
	if ( ! bc_checkout_active() || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	bc_template( 'checkout-bar', array( 't' => bc_checkout_totals() ) );
}, 5 );

/* ------------------------------------------------------------------ */
/* Assets                                                              */
/* ------------------------------------------------------------------ */

function bc_checkout_js_data() {
	return array(
		'cartUrl' => wc_get_cart_url(),
		'terms'   => (bool) bc_checkout_terms(),
		// Checks the browser runs before it moves on; the server repeats them (see woocommerce_after_checkout_validation).
		'rules'   => array(
			'billing_address_1' => array( 'min' => 8, 'msg' => 'آدرس کامل را وارد کنید (خیابان، کوچه، پلاک)' ),
			'billing_postcode'  => array( 'digits' => true, 'pattern' => '^[0-9]{10}$', 'msg' => 'کد پستی باید ۱۰ رقم باشد' ),
			'billing_email'     => array( 'pattern' => '^[^@ ]+@[^@ ]+[.][^@ ]+$', 'msg' => 'ایمیل را درست وارد کنید' ),
		),
		'i18n'    => array(
			'enter'  => '%s را وارد کنید',
			'select' => '%s را انتخاب کنید',
			'fix'    => '%s مورد نیاز به اصلاح دارد',
			'fixOne' => 'یک مورد نیاز به اصلاح دارد',
			'hint'   => 'لطفاً فیلدهای مشخص‌شده را درست کنید و دوباره ادامه دهید.',
			'terms'  => 'برای ثبت سفارش باید قوانین و شرایط خرید را بپذیرید',
			'ship'     => 'روش ارسال را انتخاب کنید',
			'shipNone' => 'برای این آدرس روش ارسالی در دسترس نیست؛ آدرس را بررسی کنید یا با پشتیبانی تماس بگیرید',
			'pay'    => 'پرداخت',
			'next'   => 'ادامه فرایند خرید',
			'place'  => 'ثبت سفارش',
		),
	);
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! bc_checkout_active() ) {
		return;
	}
	bc_enqueue_module( 'bc-checkout', 'checkout.css', 'checkout.js', array( 'bc-flow', 'jquery', 'wc-checkout' ), array( 'bc-flow' ) );
	wp_add_inline_script( 'bc-checkout', 'window.bcCheckout=' . wp_json_encode( bc_checkout_js_data() ) . ';', 'before' );
}, 21 );
