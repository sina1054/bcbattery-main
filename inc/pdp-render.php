<?php
/**
 * BC Battery - PDP: page model, hook registration and the top part of the page
 * (gallery, head, variation pills, key-feature chips, buy card, services, help).
 * Tabs / sections, related products and product cards are in pdp-sections.php.
 *
 * The page is assembled from the WooCommerce hooks below (priorities are the order on the page):
 *   woocommerce_before_single_product_summary : gallery
 *   woocommerce_single_product_summary        : crumb, head, variants, chips, buy card, services, help
 *   woocommerce_after_single_product_summary  : sections, Woodmart after-tabs, upsells, related
 * Remove or re-order any callback from a snippet / child theme with remove_action() after 'wp'.
 */
defined( 'ABSPATH' ) || exit;

function bc_pdp_register_hooks() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	remove_all_actions( 'woocommerce_before_single_product_summary' );
	remove_all_actions( 'woocommerce_single_product_summary' );
	remove_all_actions( 'woocommerce_after_single_product_summary' );
	// Woodmart's related / upsell output is replaced by our cards; its other callbacks on this hook (extra content) stay.
	remove_action( 'woodmart_woocommerce_after_sidebar', 'woocommerce_upsell_display', 10 );
	remove_action( 'woodmart_woocommerce_after_sidebar', 'woocommerce_output_related_products', 20 );

	add_action( 'woocommerce_before_single_product_summary', 'bc_pdp_gallery', 20 );

	add_action( 'woocommerce_single_product_summary', 'bc_pdp_crumb', 1 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_col_a', 2 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_head_open', 3 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_kicker', 4 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_title_en', 6 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_meta_row', 8 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_excerpt', 9 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_div', 10 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_variants', 12 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_chips', 14 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_div', 15 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_col_b', 19 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_buy_open', 20 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_perks_buy', 21 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_price', 22 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_buybox', 23 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_buy_extras', 26 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_section', 29 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_help', 40 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_close_div', 41 );
	add_action( 'woocommerce_single_product_summary', 'bc_pdp_services', 50 );

	add_action( 'woocommerce_after_single_product_summary', 'bc_pdp_sections', 10 );
	add_action( 'woocommerce_after_single_product_summary', 'bc_pdp_after_tabs', 12 );
	add_action( 'woocommerce_after_single_product_summary', 'bc_pdp_upsells', 15 );
	add_action( 'woocommerce_after_single_product_summary', 'bc_pdp_related', 20 );
}

function bc_pdp_close_div() {
	echo '</div>';
}

/** Closes the buy box section opened by bc_pdp_buy_open(). */
function bc_pdp_close_section() {
	echo '</section>';
}

/** Two column blocks: A = head + variants + chips, B = buy card + help. Phones: no box (display: contents); tablet and desktop: grid columns. */
function bc_pdp_col_a() {
	echo '<div class="bcp-col bcp-col--a">';
}

function bc_pdp_col_b() {
	echo '<div class="bcp-col bcp-col--b">';
}

/** Editable zone (Woodmart HTML block, buildable with Elementor): bc-product-top / bc-product-bottom. */
function bc_pdp_zone( $zone ) {
	if ( function_exists( 'bc_flow_zone' ) ) {
		bc_flow_zone( $zone );
	}
}

/* ------------------------------------------------------------------ */
/* Model: what the page shows and what the script needs                */
/* ------------------------------------------------------------------ */

/** First variation row that matches every chosen attribute ('' in a variation means any value). */
function bc_pdp_match( array $vars, array $attrs, array $sel ) {
	foreach ( $vars as $v ) {
		$ok = true;
		foreach ( $attrs as $a ) {
			$want = isset( $sel[ $a['key'] ] ) ? (string) $sel[ $a['key'] ] : '';
			$has  = isset( $v['a'][ $a['key'] ] ) ? (string) $v['a'][ $a['key'] ] : '';
			if ( '' === $want || ( '' !== $has && $has !== $want ) ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			return $v;
		}
	}
	return null;
}

/**
 * type    : product type
 * pills   : true when variation pills + our own cart form are used (variable product with a manageable number of variations)
 * attrs   : [ key, name, label, opts[ v, l ] ]   vars: [ id, a{key:value}, p, r, st, sku, img ]
 * sel     : preselected value per attribute key   cur: the preselected variation row (or null)
 * current : WC_Product that describes the first screen (the variation, or the product itself)
 */
function bc_pdp_model( WC_Product $product ) {
	static $cache = array();
	$pid = $product->get_id();
	if ( isset( $cache[ $pid ] ) ) {
		return $cache[ $pid ];
	}
	$m = array(
		'type'    => $product->get_type(),
		'pills'   => false,
		'attrs'   => array(),
		'vars'    => array(),
		'sel'     => array(),
		'cur'     => null,
		'min'     => 0,
		'current' => $product,
	);
	if ( $product->is_type( 'variable' ) ) {
		$m = bc_pdp_variable_model( $product, $m );
	}
	return $cache[ $pid ] = $m;
}

function bc_pdp_variable_model( WC_Product_Variable $p, array $m ) {
	if ( count( $p->get_children() ) > (int) apply_filters( 'bc_pdp_variation_limit', 100 ) ) {
		return $m; // too many combinations for pills: the standard WooCommerce form is used.
	}
	$vars = array();
	foreach ( (array) $p->get_available_variations( 'objects' ) as $v ) {
		if ( ! $v->is_purchasable() ) {
			continue;
		}
		$vars[] = array(
			'id'  => $v->get_id(),
			'a'   => $v->get_variation_attributes(),
			'p'   => (float) wc_get_price_to_display( $v ),
			'r'   => (float) wc_get_price_to_display( $v, array( 'price' => $v->get_regular_price() ) ),
			'st'  => $v->get_stock_status(),
			'sku' => (string) $v->get_sku(),
			'img' => (int) $v->get_image_id(),
		);
	}

	$attrs = array();
	foreach ( $p->get_variation_attributes() as $name => $options ) {
		$opts = array();
		if ( taxonomy_exists( $name ) ) {
			foreach ( wc_get_product_terms( $p->get_id(), $name, array( 'fields' => 'all' ) ) as $t ) {
				if ( in_array( $t->slug, (array) $options, true ) ) {
					$opts[] = array( 'v' => $t->slug, 'l' => $t->name );
				}
			}
		} else {
			foreach ( (array) $options as $o ) {
				$opts[] = array( 'v' => (string) $o, 'l' => (string) $o );
			}
		}
		if ( $opts ) {
			$attrs[] = array(
				'key'   => 'attribute_' . sanitize_title( $name ),
				'name'  => $name,
				'label' => wc_attribute_label( $name, $p ),
				'opts'  => $opts,
			);
		}
	}
	if ( ! $vars || ! $attrs ) {
		return $m;
	}

	// Preselect: the product's default attributes, else the first variation that is in stock.
	$default = (array) $p->get_default_attributes();
	$sel     = array();
	foreach ( $attrs as $a ) {
		$k = sanitize_title( $a['name'] );
		if ( ! empty( $default[ $k ] ) ) {
			$sel[ $a['key'] ] = (string) $default[ $k ];
		}
	}
	$cur = bc_pdp_match( $vars, $attrs, $sel );
	if ( ! $cur ) {
		$sel = array();
		foreach ( $vars as $v ) {
			if ( 'outofstock' !== $v['st'] ) {
				$cur = $v;
				break;
			}
		}
		if ( ! $cur ) {
			$cur = $vars[0];
		}
		foreach ( $attrs as $a ) {
			$val              = isset( $cur['a'][ $a['key'] ] ) ? (string) $cur['a'][ $a['key'] ] : '';
			$sel[ $a['key'] ] = '' !== $val ? $val : $a['opts'][0]['v'];
		}
	}

	$prices = array();
	foreach ( $vars as $v ) {
		if ( 'outofstock' !== $v['st'] && $v['p'] > 0 ) {
			$prices[] = $v['p'];
		}
	}
	if ( ! $prices ) {
		foreach ( $vars as $v ) {
			if ( $v['p'] > 0 ) {
				$prices[] = $v['p'];
			}
		}
	}

	$m['pills']   = true;
	$m['attrs']   = $attrs;
	$m['vars']    = $vars;
	$m['sel']     = $sel;
	$m['cur']     = $cur;
	$m['min']     = $prices ? min( $prices ) : 0;
	$m['current'] = wc_get_product( $cur['id'] ) ? wc_get_product( $cur['id'] ) : $p;
	return $m;
}

/** p = price to display, r = regular price, off = discount percent, from = show "from" (variable product, nothing chosen). */
function bc_pdp_price_data( WC_Product $product ) {
	$m = bc_pdp_model( $product );
	if ( $m['pills'] && $m['cur'] ) {
		$p = $m['cur']['p'];
		$r = $m['cur']['r'];
	} else {
		$p = '' === $product->get_price() ? 0 : (float) wc_get_price_to_display( $product );
		$r = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
	}
	$r   = ( $r > $p && $p > 0 ) ? $r : 0;
	$off = $r ? (int) round( ( $r - $p ) / $r * 100 ) : 0;
	return array( 'p' => $p, 'r' => $r, 'off' => $off, 'from' => false );
}

/* ------------------------------------------------------------------ */
/* Gallery                                                             */
/* ------------------------------------------------------------------ */

/** Two reassurance pills; $ctx = 'gal' (over the photo) or 'buy' (in the buy card). */
function bc_pdp_perks( $ctx ) {
	$t     = bc_pdp_texts();
	$items = array();
	if ( '' !== trim( (string) $t['warranty'] ) ) {
		$items[] = array( 'shield', $t['warranty'], 'ok' );
	}
	if ( '' !== trim( (string) $t['fast'] ) ) {
		$items[] = array( 'rocket', $t['fast'], 'fast' );
	}
	if ( ! $items ) {
		return;
	}
	$size = 'gal' === $ctx ? 13 : 16;
	echo '<ul class="bcp-perks bcp-perks--' . esc_attr( $ctx ) . '">';
	foreach ( $items as $it ) {
		echo '<li class="bcp-perk bcp-perk--' . esc_attr( $it[2] ) . '"><span class="bcp-perk__ic">' . bc_icon( $it[0], $size ) . '</span>' . esc_html( $it[1] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}

function bc_pdp_perks_buy() {
	bc_pdp_perks( 'buy' );
}

function bc_pdp_gallery() {
	global $product;
	$s   = bc_pdp_settings();
	$ids = array_values( array_unique( array_filter( array_map( 'absint', array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) ) ) );
	$n   = count( $ids );
	$d   = bc_pdp_price_data( $product );

	echo '<div class="bcp-gal" data-bcp="gal">';
	echo '<div class="bcp-gal__stage">';
	if ( ! $ids ) {
		echo '<div class="bcp-gal__track"><div class="bcp-gal__slide">' . wc_placeholder_img( 'woocommerce_single' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<div class="bcp-gal__track" data-bcp="track" tabindex="0" aria-label="' . esc_attr( 'تصاویر محصول' ) . '">';
		foreach ( $ids as $i => $id ) {
			$alt  = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$full = wp_get_attachment_image_src( $id, 'full' );
			$attr = array(
				'class'    => 'bcp-gal__img',
				'alt'      => $alt ? $alt : wp_strip_all_tags( get_the_title() ),
				'sizes'    => '(max-width: 767px) 100vw, (max-width: 1024px) 340px, 480px',
				'loading'  => $i ? 'lazy' : 'eager',
				'decoding' => 'async',
			);
			if ( 0 === $i ) {
				$attr['fetchpriority'] = 'high';
			}
			echo '<div class="bcp-gal__slide" data-id="' . (int) $id . '" data-full="' . esc_url( $full ? $full[0] : '' ) . '">' . wp_get_attachment_image( $id, 'large', false, $attr ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	if ( $n > 1 ) {
		echo '<span class="bcp-gal__count" data-bcp="count" aria-hidden="true">' . esc_html( bc_pdp_fa( '1 / ' . $n ) ) . '</span>';
	}
	echo '<span class="bcp-off" data-bcp="off"' . ( $d['off'] ? '' : ' hidden' ) . '>' . esc_html( bc_pdp_fa( $d['off'] ) ) . '٪ تخفیف</span>';
	bc_pdp_perks( 'gal' );
	if ( $n > 1 ) {
		echo '<div class="bcp-gal__dots" aria-hidden="true">' . str_repeat( '<i></i>', $n ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( $s['zoom'] && $ids ) {
		echo '<button type="button" class="bcp-gal__zoom" data-bcp="zoom" aria-label="' . esc_attr( 'بزرگنمایی تصویر' ) . '">' . bc_icon( 'zoom', 22 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';

	if ( $n > 1 ) {
		echo '<div class="bcp-gal__thumbs">';
		foreach ( $ids as $i => $id ) {
			echo '<button type="button" class="bcp-gal__thumb' . ( $i ? '' : ' is-on' ) . '" aria-label="' . esc_attr( 'تصویر ' . bc_pdp_fa( $i + 1 ) ) . '">' . wp_get_attachment_image( $id, 'thumbnail', false, array( 'alt' => '', 'loading' => 'lazy', 'sizes' => '84px' ) ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}
	echo '</div>';
}

/* ------------------------------------------------------------------ */
/* Breadcrumb and head                                                 */
/* ------------------------------------------------------------------ */

/** The category shown in the kicker and the breadcrumb: Rank Math primary category, else the deepest one. */
function bc_pdp_main_term( WC_Product $product ) {
	static $cache = array();
	$pid = $product->get_id();
	if ( array_key_exists( $pid, $cache ) ) {
		return $cache[ $pid ];
	}
	$terms = get_the_terms( $pid, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return $cache[ $pid ] = null;
	}
	$primary = (int) get_post_meta( $pid, 'rank_math_primary_product_cat', true );
	$best    = null;
	$deep    = -1;
	foreach ( $terms as $t ) {
		if ( $primary && (int) $t->term_id === $primary ) {
			return $cache[ $pid ] = $t;
		}
		$d = count( get_ancestors( $t->term_id, 'product_cat' ) );
		if ( $d > $deep ) {
			$deep = $d;
			$best = $t;
		}
	}
	return $cache[ $pid ] = $best;
}

function bc_pdp_crumb() {
	global $product;
	if ( ! bc_pdp_settings()['breadcrumbs'] ) {
		return;
	}
	$items = array( array( 'خانه', home_url( '/' ) ) );
	$term  = bc_pdp_main_term( $product );
	if ( $term ) {
		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat' ) ) as $tid ) {
			$t = get_term( $tid, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$items[] = array( $t->name, get_term_link( $t ) );
			}
		}
		$items[] = array( $term->name, get_term_link( $term ) );
	}
	echo '<nav class="bcp-crumb" aria-label="' . esc_attr( 'مسیر صفحه' ) . '"><ol>';
	foreach ( $items as $it ) {
		if ( ! is_wp_error( $it[1] ) ) {
			echo '<li><a href="' . esc_url( $it[1] ) . '">' . esc_html( $it[0] ) . '</a></li>';
		}
	}
	echo '<li aria-current="page"><span>' . esc_html( wp_strip_all_tags( get_the_title() ) ) . '</span></li></ol></nav>';
}

function bc_pdp_head_open() {
	echo '<div class="bcp-head">';
}

/** "Brand · Category" (the brand only when Woodmart's "Product brand" option is on). */
function bc_pdp_kicker() {
	global $product;
	$s     = bc_pdp_settings();
	$parts = array();
	if ( $s['brand'] && $s['brand_taxonomy'] && taxonomy_exists( $s['brand_taxonomy'] ) ) {
		$brands = wc_get_product_terms( $product->get_id(), $s['brand_taxonomy'], array( 'fields' => 'all' ) );
		if ( $brands && ! is_wp_error( $brands ) ) {
			$link    = get_term_link( $brands[0] );
			$parts[] = is_wp_error( $link ) ? esc_html( $brands[0]->name ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $brands[0]->name ) . '</a>';
		}
	}
	$term = bc_pdp_main_term( $product );
	if ( $term ) {
		$link    = get_term_link( $term );
		$parts[] = is_wp_error( $link ) ? esc_html( $term->name ) : '<a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a>';
	}
	if ( $parts ) {
		echo '<div class="bcp-kicker">' . implode( ' <span aria-hidden="true">·</span> ', $parts ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

function bc_pdp_title() {
	echo '<h1 class="bcp-title product_title entry-title">' . esc_html( wp_strip_all_tags( get_the_title() ) ) . '</h1>';
}

/** Optional English line (product field _bc_title_en). */
function bc_pdp_title_en() {
	global $product;
	$en = function_exists( 'bc_get_title_en' ) ? bc_get_title_en( $product->get_id() ) : '';
	if ( '' !== $en ) {
		echo '<div class="bcp-en" dir="ltr" lang="en">' . esc_html( $en ) . '</div>';
	}
}

function bc_pdp_stock_info( $status ) {
	$map = array(
		'instock'     => array( 'موجود در انبار', 'ok' ),
		'outofstock'  => array( 'ناموجود', 'out' ),
		'onbackorder' => array( 'پیش‌سفارش', 'back' ),
	);
	return isset( $map[ $status ] ) ? $map[ $status ] : $map['instock'];
}

/** Stock chip (Woodmart "stock status position" = after title; otherwise only shown when sold out) and the SKU line (Woodmart "SKU under title"). */
function bc_pdp_meta_row() {
	global $product;
	$s      = bc_pdp_settings();
	$m      = bc_pdp_model( $product );
	$cur    = $m['current'];
	$status = $cur->get_stock_status();
	$info   = bc_pdp_stock_info( $status );
	$show   = $s['stock_top'] || 'outofstock' === $status;
	$sku    = (string) $cur->get_sku();
	echo '<div class="bcp-meta">';
	echo '<span class="bcp-chip-stock bcp-chip-stock--' . esc_attr( $info[1] ) . '" data-bcp="stock" data-always="' . ( $s['stock_top'] ? '1' : '0' ) . '"' . ( $show ? '' : ' hidden' ) . '>' . esc_html( $info[0] ) . '</span>';
	if ( $s['sku'] ) {
		echo '<span class="bcp-sku" data-bcp="sku"' . ( '' !== $sku ? '' : ' hidden' ) . '>کد کالا: <b dir="ltr">' . esc_html( $sku ) . '</b></span>';
	}
	echo '</div>';
}

/** Short description (Woodmart "Short description" option). */
function bc_pdp_excerpt() {
	global $post;
	if ( bc_pdp_settings()['short_desc'] && ! empty( $post->post_excerpt ) ) {
		echo '<div class="bcp-excerpt">' . apply_filters( 'woocommerce_short_description', $post->post_excerpt ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/* ------------------------------------------------------------------ */
/* Variation pills and key-feature chips                               */
/* ------------------------------------------------------------------ */

function bc_pdp_variants() {
	global $product;
	$m = bc_pdp_model( $product );
	if ( ! $m['pills'] ) {
		return;
	}
	$multi = false;
	echo '<div class="bcp-variants" id="bcp-variants" data-bcp="variants">';
	foreach ( $m['attrs'] as $a ) {
		$cur   = isset( $m['sel'][ $a['key'] ] ) ? $m['sel'][ $a['key'] ] : '';
		$label = '';
		foreach ( $a['opts'] as $o ) {
			if ( $o['v'] === $cur ) {
				$label = $o['l'];
			}
		}
		$multi = $multi || count( $a['opts'] ) > 1;
		echo '<div class="bcp-var" data-key="' . esc_attr( $a['key'] ) . '"><div class="bcp-var__label"><span>' . esc_html( $a['label'] ) . ':</span> <b data-bcp="varname">' . esc_html( $label ) . '</b></div>';
		echo '<div class="bcp-var__opts" role="group" aria-label="' . esc_attr( $a['label'] ) . '">';
		foreach ( $a['opts'] as $o ) {
			$on = $o['v'] === $cur;
			echo '<button type="button" class="bcp-pill' . ( $on ? ' is-on' : '' ) . '" data-value="' . esc_attr( $o['v'] ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . esc_html( $o['l'] ) . '</button>';
		}
		echo '</div></div>';
	}
	if ( $multi ) {
		echo '<button type="button" class="bcp-clear" data-bcp="clear">' . bc_icon( 'refresh', 16 ) . 'پاک کردن</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
}

/** [ label, value ] pairs for the chip row: preferred attributes first, then any other visible one. Variation attributes are shown as pills instead. */
function bc_pdp_chip_data( WC_Product $product ) {
	$max       = (int) apply_filters( 'bc_pdp_chip_limit', 6 );
	$preferred = (array) apply_filters( 'bc_pdp_chip_attributes', array( 'pa_battery-capacity', 'pa_battery-voltage', 'pa_battery-type', 'pa_battery-model', 'pa_guarantee', 'pa_country-of-manufacture', 'pa_originality' ) );
	$skip      = $product->is_type( 'variable' ) ? array_map( 'sanitize_title', array_keys( (array) $product->get_variation_attributes() ) ) : array();
	$skip[]    = 'pa_brand';
	$chips     = array();
	$used      = array();

	$push = static function ( $name ) use ( $product, &$chips, &$used, $skip ) {
		if ( in_array( $name, $used, true ) || in_array( sanitize_title( $name ), $skip, true ) ) {
			return;
		}
		$value = $product->get_attribute( $name );
		if ( '' === trim( (string) $value ) ) {
			return;
		}
		$used[]  = $name;
		$chips[] = array( wc_attribute_label( $name, $product ), $value );
	};
	foreach ( $preferred as $name ) {
		if ( count( $chips ) < $max ) {
			$push( $name );
		}
	}
	foreach ( $product->get_attributes() as $attr ) {
		if ( count( $chips ) < $max && $attr->get_visible() && ! $attr->get_variation() ) {
			$push( $attr->get_name() );
		}
	}
	return $chips;
}

function bc_pdp_chips() {
	global $product;
	$chips = bc_pdp_chip_data( $product );
	if ( ! $chips ) {
		return;
	}
	echo '<div class="bcp-chips" aria-label="' . esc_attr( 'ویژگی‌های کلیدی' ) . '"><div class="bcp-chips__row">';
	foreach ( $chips as $c ) {
		echo '<div class="bcp-chip"><span>' . esc_html( $c[0] ) . '</span><b>' . esc_html( $c[1] ) . '</b></div>';
	}
	echo '<a class="bcp-chip bcp-chip--more" href="#bcp-specs">همه مشخصات' . bc_icon( 'next', 16 ) . '</a></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/* ------------------------------------------------------------------ */
/* Buy card                                                            */
/* ------------------------------------------------------------------ */

function bc_pdp_buy_open() {
	echo '<section class="bcp-buy" id="bcp-buy" aria-label="' . esc_attr( 'خرید' ) . '">';
}

function bc_pdp_price() {
	global $product;
	$d = bc_pdp_price_data( $product );
	echo '<div class="bcp-price" data-bcp="price">';
	if ( $d['p'] <= 0 ) {
		echo '<span class="bcp-price__ask">برای اطلاع از قیمت تماس بگیرید</span></div>';
		return;
	}
	$cur = esc_html( get_woocommerce_currency_symbol() );
	echo '<del class="bcp-price__old" data-bcp="old"' . ( $d['r'] ? '' : ' hidden' ) . '><span data-bcp="old-n">' . esc_html( bc_pdp_number( $d['r'] ) ) . '</span> ' . $cur . '</del>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<span class="bcp-price__now"><span class="bcp-price__from" data-bcp="from" hidden>از</span> <b data-bcp="now">' . esc_html( bc_pdp_number( $d['p'] ) ) . '</b> <small>' . $cur . '</small></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Our own cart form (simple products, variable products with pills); every other type uses the standard WooCommerce form. */
function bc_pdp_buybox() {
	global $product;
	$m   = bc_pdp_model( $product );
	$own = $product->is_type( 'simple' ) || ( $product->is_type( 'variable' ) && $m['pills'] );

	echo '<div class="bcp-buybox" data-bcp="buybox">';
	if ( function_exists( 'woodmart_before_add_to_cart_area' ) ) {
		woodmart_before_add_to_cart_area(); // Woodmart: content before add to cart
	}
	if ( $own ) {
		do_action( 'woocommerce_before_add_to_cart_form' );
		bc_pdp_form( $product, $m );
		bc_pdp_incart();
		bc_pdp_data_json( $product, $m );
		do_action( 'woocommerce_after_add_to_cart_form' );
	} else {
		woocommerce_template_single_add_to_cart();
	}
	if ( function_exists( 'woodmart_after_add_to_cart_area' ) ) {
		woodmart_after_add_to_cart_area(); // Woodmart: content after add to cart
	}
	echo '</div>';
}

function bc_pdp_form( WC_Product $product, array $m ) {
	$d   = bc_pdp_price_data( $product );
	$id  = (int) $product->get_id();
	$cur = $m['current'];
	$out = 'outofstock' === $cur->get_stock_status();

	if ( $d['p'] <= 0 && ! $m['pills'] ) { // no price: ask by phone
		$c = bc_pdp_contact();
		echo '<p class="bcp-note">برای اطلاع از قیمت و سفارش با ما تماس بگیرید.</p>';
		if ( ! empty( $c['phone'] ) ) {
			echo '<a class="bcp-add" data-bcp="call" href="tel:' . esc_attr( $c['phone'] ) . '">تماس با ما</a>';
		}
		return;
	}

	echo '<form class="bcp-form" method="post" action="' . esc_url( get_permalink( $id ) ) . '" data-bcp="form" novalidate>';
	do_action( 'woocommerce_before_add_to_cart_button' );
	echo '<input type="hidden" name="quantity" value="1"><input type="hidden" name="add-to-cart" value="' . $id . '">';
	if ( $m['pills'] ) {
		echo '<input type="hidden" name="product_id" value="' . $id . '"><input type="hidden" name="variation_id" value="' . (int) $m['cur']['id'] . '" data-bcp="vid">';
		foreach ( $m['attrs'] as $a ) {
			echo '<input type="hidden" name="' . esc_attr( $a['key'] ) . '" value="' . esc_attr( isset( $m['sel'][ $a['key'] ] ) ? $m['sel'][ $a['key'] ] : '' ) . '" data-bcp="attr" data-key="' . esc_attr( $a['key'] ) . '">';
		}
	}
	echo '<button type="submit" class="bcp-add" data-bcp="add"' . ( $out ? ' disabled' : '' ) . '>' . ( $out ? 'ناموجود' : 'افزودن به سبد خرید' ) . '</button>';
	do_action( 'woocommerce_after_add_to_cart_button' );
	echo '</form>';
	echo '<p class="bcp-soldout" data-bcp="soldout"' . ( $out ? '' : ' hidden' ) . '>این محصول فعلاً ناموجود است. برای اطلاع از زمان موجود شدن با ما تماس بگیرید.</p>';
}

/** Hidden until the script finds the product in the visitor's cart (Store API), so cached pages stay correct. */
function bc_pdp_incart() {
	echo '<div class="bcp-incart" data-bcp="incart" hidden>';
	echo '<div class="bcp-incart__row"><div class="bcp-step is-one" role="group" aria-label="' . esc_attr( 'تعداد در سبد خرید' ) . '">';
	echo '<button type="button" class="bcp-step__btn" data-bcp="inc" aria-label="' . esc_attr( 'افزایش تعداد' ) . '">' . bc_icon( 'plus', 22 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<output class="bcp-step__qty" data-bcp="qty">۱</output>';
	echo '<button type="button" class="bcp-step__btn" data-bcp="dec" aria-label="' . esc_attr( 'کاهش تعداد' ) . '"><span class="bcp-ic-trash">' . bc_icon( 'trash', 20 ) . '</span><span class="bcp-ic-minus">' . bc_icon( 'minus', 22 ) . '</span></button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<a class="bcp-viewcart" href="' . esc_url( wc_get_cart_url() ) . '">مشاهده سبد خرید</a></div>';
	echo '<p class="bcp-incart__note" data-bcp="incart-note">این کالا در سبد خرید شماست</p></div>';
}

/** Static product data for the script (prices come from the server, never from the page text). */
function bc_pdp_data_json( WC_Product $product, array $m ) {
	$d    = bc_pdp_price_data( $product );
	$img  = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_gallery_thumbnail' );
	$data = array(
		'id'    => $product->get_id(),
		'type'  => $product->get_type(),
		'name'  => wp_strip_all_tags( $product->get_name() ),
		'en'    => function_exists( 'bc_get_title_en' ) ? bc_get_title_en( $product->get_id() ) : '',
		'img'   => $img ? $img : '',
		'p'     => $d['p'],
		'r'     => $d['r'],
		'st'    => $product->get_stock_status(),
		'min'   => $m['min'],
		'attrs' => $m['attrs'],
		'vars'  => $m['vars'],
		'sel'   => (object) $m['sel'],
	);
	echo '<script type="application/json" id="bcp-data">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Woodmart options that print inside the buy area: sale countdown, meta (position "add to cart"), share buttons. */
function bc_pdp_buy_extras() {
	$s = bc_pdp_settings();
	if ( $s['countdown'] && function_exists( 'woodmart_single_product_countdown' ) ) {
		woodmart_single_product_countdown();
	}
	if ( 'add_to_cart' === $s['meta'] ) {
		woocommerce_template_single_meta();
	}
	if ( $s['share'] && 'after_tabs' !== $s['meta'] && function_exists( 'woodmart_product_share_buttons' ) ) {
		woodmart_product_share_buttons();
	}
}

/** Woodmart prints "SKU: N/A" for variable products without a SKU. Hide the line when there is nothing to show. */
function bc_pdp_hide_empty_sku( $enabled ) {
	global $product;
	if ( $enabled && ! is_admin() && bc_pdp_enabled() && $product instanceof WC_Product && '' === (string) $product->get_sku() ) {
		return false;
	}
	return $enabled;
}
add_filter( 'wc_product_sku_enabled', 'bc_pdp_hide_empty_sku' );

/* ------------------------------------------------------------------ */
/* Services and help                                                   */
/* ------------------------------------------------------------------ */

function bc_pdp_services() {
	$t = bc_pdp_texts();
	$c = bc_pdp_contact();
	echo '<section class="bcp-services">';
	if ( '' !== trim( (string) $t['repair'] ) ) {
		echo '<details><summary><span class="bcp-services__t bcp-services__t--repair">' . bc_icon( 'wrench', 22 ) . '<b>' . esc_html( $t['repair_title'] ) . '</b></span>' . bc_icon( 'down', 20 ) . '</summary><p>' . esc_html( $t['repair'] ) . '</p></details>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( '' !== trim( (string) $t['wholesale'] ) ) {
		echo '<details><summary><span class="bcp-services__t bcp-services__t--wholesale">' . bc_icon( 'boxes', 22 ) . '<b>' . esc_html( $t['wholesale_title'] ) . '</b></span>' . bc_icon( 'down', 20 ) . '</summary><p>' . esc_html( $t['wholesale'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! empty( $c['wholesale'] ) ) {
			echo '<a class="bcp-services__tel" dir="ltr" href="tel:' . esc_attr( $c['wholesale'] ) . '">' . esc_html( bc_pdp_fa( $c['wholesale'] ) ) . '</a>';
		}
		echo '</p></details>';
	}
	echo '</section>';
}

/** Messengers that have a link (Bale, WhatsApp). One of them: direct link; both: a small chooser dialog (pdp-overlays.php). */
function bc_pdp_messengers() {
	$c   = bc_pdp_contact();
	$out = array();
	if ( ! empty( $c['bale'] ) ) {
		$out['bale'] = array( 'پیام‌رسان بله', $c['bale'] );
	}
	if ( ! empty( $c['whatsapp'] ) ) {
		$out['whatsapp'] = array( 'واتس‌اپ', $c['whatsapp'] );
	}
	return $out;
}

function bc_pdp_help() {
	$t   = bc_pdp_texts();
	$c   = bc_pdp_contact();
	$msg = bc_pdp_messengers();
	echo '<aside class="bcp-help"><p class="bcp-help__title">' . esc_html( $t['help_title'] ) . '</p><div class="bcp-help__actions">';
	if ( ! empty( $c['phone'] ) ) {
		echo '<a class="bcp-btn bcp-btn--primary" href="tel:' . esc_attr( $c['phone'] ) . '">تماس <span dir="ltr">' . esc_html( bc_pdp_fa( $c['phone'] ) ) . '</span></a>';
	}
	if ( $msg ) {
		$first = reset( $msg );
		echo '<a class="bcp-btn bcp-btn--outline" href="' . esc_url( $first[1] ) . '" target="_blank" rel="noopener"' . ( count( $msg ) > 1 ? ' data-bcp="messenger"' : '' ) . '>پیام در پیام‌رسان</a>';
	}
	echo '</div></aside>';
}