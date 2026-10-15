<?php
/**
 * BC Battery - automatic product suggestions.
 *
 * Used only when a product's manual list is empty (Product data > Linked Products):
 *  - Complementary ("usually bought together"): what you need next to the product. A battery gets a charger and a
 *    charging cable of the same brand plus a power bank. When the brand has no charger / cable in the shop,
 *    B&C cables take their place, then any compatible charger / cable. A power bank is always among the picks.
 *  - Related (product page): alternatives to the product: same kind, same brand, same model family, similar price.
 *
 * Everything is read from the catalog (categories, brand taxonomy, pa_brand, titles), never hard-coded ids, so new
 * products and brands are picked up automatically. Results are cached per product and invalidated when any product
 * is saved or changes stock status.
 *
 * Public API:  bc_reco_complementary( $product_id, $limit )  /  bc_reco_related( $product_id, $limit )
 * Filters:     bc_reco_complementary, bc_reco_related (final id lists), bc_reco_kinds, bc_reco_brands, bc_reco_slots
 * Disable:     define( 'BC_RECO_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BC_RECO_DISABLED' ) && BC_RECO_DISABLED ) {
	return;
}

/* ------------------------------------------------------------------ */
/* Vocabulary                                                          */
/* ------------------------------------------------------------------ */

/** Product kind by top-level category slug (first match wins, in this order). */
function bc_reco_kinds() {
	return apply_filters(
		'bc_reco_kinds',
		array(
			'battery'   => array( 'mobile-battery', 'tablet-battery', 'modem-battery' ),
			'charger'   => array( 'phone-charger' ),
			'cable'     => array( 'charging-cable' ),
			'powerbank' => array( 'power-bank' ),
			'audio'     => array( 'headphones-earphones' ),
			'console'   => array( 'game-console' ),
		)
	);
}

/** Brand key => aliases found in category slugs/names, brand terms and titles. Latin aliases match whole words. */
function bc_reco_brands() {
	return apply_filters(
		'bc_reco_brands',
		array(
			'apple'      => array( 'apple', 'iphone', 'ipad', 'airpods', 'اپل', 'آیفون', 'ایفون', 'آیپد', 'ایپد' ),
			'samsung'    => array( 'samsung', 'galaxy', 'سامسونگ', 'گلکسی' ),
			'xiaomi'     => array( 'xiaomi', 'redmi', 'poco', 'شیائومی', 'ردمی', 'پوکو' ),
			'huawei'     => array( 'huawei', 'honor', 'هواوی', 'هوآوی', 'آنر' ),
			'lg'         => array( 'lg', 'ال جی', 'ال-جی' ),
			'sony'       => array( 'sony', 'xperia', 'سونی' ),
			'nokia'      => array( 'nokia', 'نوکیا' ),
			'htc'        => array( 'htc', 'اچ تی سی', 'اچ-تی-سی' ),
			'asus'       => array( 'asus', 'ایسوس' ),
			'blackberry' => array( 'blackberry', 'بلک بری', 'بلک-بری' ),
			'motorola'   => array( 'motorola', 'موتورولا' ),
			'lenovo'     => array( 'lenovo', 'لنوو' ),
			'bc'         => array( 'b&c', 'b&amp;c', 'bc', 'بی اند سی', 'بی-اند-سی' ),
		)
	);
}

/** Brand key found in a piece of text ('' if none). */
function bc_reco_brand_in( $text ) {
	$text = ' ' . mb_strtolower( str_replace( array( '-', '_', "\u{200C}" ), ' ', urldecode( (string) $text ) ) ) . ' ';
	foreach ( bc_reco_brands() as $key => $aliases ) {
		foreach ( $aliases as $a ) {
			$a = mb_strtolower( str_replace( '-', ' ', $a ) );
			// Whole words only: "ال جی" must not match inside "دیجیتال جیبی", "bc" not inside "abc".
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $a, '/' ) . '(?!\p{L})/u', $text ) ) {
				return $key;
			}
		}
	}
	return '';
}

/** Words that say nothing about the model, dropped before comparing titles. */
function bc_reco_stopwords() {
	static $s = null;
	if ( null === $s ) {
		$s = array_flip(
			array( 'باتری', 'گوشی', 'موبایل', 'تبلت', 'مودم', 'مدل', 'اصلی', 'اصل', 'اورجینال', 'شارژر', 'کابل', 'شارژ', 'با', 'و', 'به', 'همراه', 'ظرفیت', 'میلی', 'آمپر', 'ساعت', 'گارانتی', 'ماهه', 'تقویت', 'شده', 'برای', 'از', 'پاوربانک', 'پاور', 'بانک', 'battery', 'original', 'model', 'for', 'mah', 'with' )
		);
		foreach ( bc_reco_brands() as $aliases ) {
			foreach ( $aliases as $a ) {
				foreach ( preg_split( '/[\s\-]+/u', mb_strtolower( $a ) ) as $w ) {
					$s[ $w ] = true;
				}
			}
		}
	}
	return $s;
}

function bc_reco_tokens( $title ) {
	$t = mb_strtolower( strtr( (string) $title, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) ) );
	$out  = array();
	$stop = bc_reco_stopwords();
	foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
		if ( ! isset( $stop[ $w ] ) && ( mb_strlen( $w ) > 1 || ctype_digit( $w ) ) ) {
			$out[ $w ] = true;
		}
	}
	return array_keys( $out );
}

/* ------------------------------------------------------------------ */
/* Catalog index (one row per visible, in-stock product)               */
/* ------------------------------------------------------------------ */

function bc_reco_version() {
	return (int) get_option( 'bc_reco_ver', 1 );
}
function bc_reco_bump() {
	static $done = false;
	if ( ! $done ) {
		$done = true;
		update_option( 'bc_reco_ver', bc_reco_version() + 1, false );
	}
}
add_action( 'save_post_product', 'bc_reco_bump' );
add_action( 'woocommerce_product_set_stock_status', 'bc_reco_bump' );
add_action( 'woocommerce_variation_set_stock_status', 'bc_reco_bump' );
add_action( 'trashed_post', 'bc_reco_bump' );

/**
 * Built with a handful of queries (no product objects) and cached until the catalog changes.
 * Row: kind, top (top category id), leaf (category ids), brand (what it is for), maker (who made it),
 * apple (cable/charger for Lightning/iPhone only), tok (title words), price, sales, rating, type.
 */
function bc_reco_index() {
	static $mem = null;
	$ver = bc_reco_version();
	if ( null !== $mem && $mem['v'] === $ver ) {
		return $mem['rows'];
	}
	$cached = get_transient( 'bc_reco_index' );
	if ( is_array( $cached ) && isset( $cached['v'] ) && $cached['v'] === $ver ) {
		$mem = $cached;
		return $cached['rows'];
	}

	global $wpdb;
	$posts = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_password = ''", OBJECT_K ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows  = array();
	if ( $posts ) {
		$ids   = array_map( 'intval', array_keys( $posts ) );
		$terms = wp_get_object_terms( $ids, array( 'product_cat', 'product_brand', 'pa_brand', 'product_visibility', 'product_type' ), array( 'fields' => 'all_with_object_id' ) );
		$by    = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$by[ (int) $t->object_id ][ $t->taxonomy ][] = $t;
			}
		}
		$in   = implode( ',', $ids );
		$meta = array();
		foreach ( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_price','total_sales','_wc_average_rating')" ) as $m ) { // phpcs:ignore WordPress.DB
			$k = (int) $m->post_id;
			if ( '_price' === $m->meta_key ) {
				$meta[ $k ]['price'] = isset( $meta[ $k ]['price'] ) ? min( $meta[ $k ]['price'], (float) $m->meta_value ) : (float) $m->meta_value; // variable: lowest price
			} else {
				$meta[ $k ][ $m->meta_key ] = (float) $m->meta_value;
			}
		}

		$kinds = bc_reco_kinds();
		$cats  = array();
		foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $c ) {
			$cats[ $c->term_id ] = $c;
		}
		$root = function ( $id ) use ( $cats ) {
			$guard = 0;
			while ( isset( $cats[ $id ] ) && $cats[ $id ]->parent && $guard++ < 10 ) {
				$id = $cats[ $id ]->parent;
			}
			return $id;
		};

		foreach ( $posts as $id => $post ) {
			$t   = isset( $by[ $id ] ) ? $by[ $id ] : array();
			$vis = isset( $t['product_visibility'] ) ? wp_list_pluck( $t['product_visibility'], 'slug' ) : array();
			if ( array_intersect( $vis, array( 'exclude-from-catalog', 'outofstock' ) ) ) {
				continue;
			}
			$type = isset( $t['product_type'][0] ) ? $t['product_type'][0]->slug : 'simple';
			if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
				continue;
			}
			$kind = '';
			$top  = 0;
			$leaf = array();
			$cat_brand = '';
			foreach ( isset( $t['product_cat'] ) ? $t['product_cat'] : array() as $c ) {
				$leaf[] = (int) $c->term_id;
				$r      = $root( $c->term_id );
				$slug   = isset( $cats[ $r ] ) ? $cats[ $r ]->slug : '';
				foreach ( $kinds as $k => $slugs ) {
					if ( in_array( $slug, $slugs, true ) && ( '' === $kind || array_search( $k, array_keys( $kinds ), true ) < array_search( $kind, array_keys( $kinds ), true ) ) ) {
						$kind = $k;
						$top  = (int) $r;
					}
				}
				if ( '' === $cat_brand && $c->parent ) { // sub-categories carry the brand: iphone-charger, xiaomi-battery...
					$b = bc_reco_brand_in( $c->slug . ' ' . $c->name );
					if ( $b && 'bc' !== $b ) {
						$cat_brand = $b;
					}
				}
			}
			$maker = '';
			foreach ( array( 'product_brand', 'pa_brand' ) as $tax ) {
				foreach ( isset( $t[ $tax ] ) ? $t[ $tax ] : array() as $bt ) {
					$maker = $maker ? $maker : bc_reco_brand_in( $bt->slug . ' ' . $bt->name );
				}
			}
			$title_brand = bc_reco_brand_in( $post->post_title );
			// What it is for: a brand named in the title wins (a Huawei cable filed under Samsung cables is still for Huawei),
			// then the brand sub-category, then the maker. B&C is a maker, never a "for" brand.
			$title_for   = 'bc' !== $title_brand ? $title_brand : '';
			$brand       = $title_for ? $title_for : ( $cat_brand ? $cat_brand : ( $maker && 'bc' !== $maker ? $maker : '' ) );
			$apple_only  = in_array( $kind, array( 'cable', 'charger' ), true )
				&& ( 'apple' === $brand || preg_match( '/lightning|لایتنینگ|آیفون|ایفون|iphone/iu', $post->post_title ) )
				&& ! preg_match( '/type[\s\-]?c|تایپ[\s\x{200C}]?سی|تایپ c|usb[\s\-]?c/iu', $post->post_title );

			$m           = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
			$rows[ $id ] = array(
				'kind'   => $kind ? $kind : 'other',
				'top'    => $top,
				'leaf'   => $leaf,
				'brand'  => $brand,
				'maker'  => $maker ? $maker : ( 'bc' === $title_brand ? 'bc' : '' ),
				'apple'  => (bool) $apple_only,
				'tok'    => bc_reco_tokens( $post->post_title ),
				'price'  => isset( $m['price'] ) ? (float) $m['price'] : 0.0,
				'sales'  => isset( $m['total_sales'] ) ? (float) $m['total_sales'] : 0.0,
				'rating' => isset( $m['_wc_average_rating'] ) ? (float) $m['_wc_average_rating'] : 0.0,
				'type'   => $type,
			);
		}
	}
	$mem = array( 'v' => $ver, 'rows' => $rows );
	set_transient( 'bc_reco_index', $mem, DAY_IN_SECONDS );
	return $rows;
}

/* ------------------------------------------------------------------ */
/* Scoring helpers                                                     */
/* ------------------------------------------------------------------ */

/** Stable pseudo-random 0..1 per (product, candidate, week): varies between products, steady for caching. */
function bc_reco_jitter( $a, $b ) {
	return ( crc32( $a . '|' . $b . '|' . gmdate( 'o-W' ) ) % 10000 ) / 10000;
}

/** Popularity 0..1 within a set of rows (log of total sales, plus rating). */
function bc_reco_popularity( array $rows ) {
	$max = 0.0;
	foreach ( $rows as $r ) {
		$max = max( $max, log1p( $r['sales'] ) );
	}
	$out = array();
	foreach ( $rows as $id => $r ) {
		$out[ $id ] = ( $max > 0 ? log1p( $r['sales'] ) / $max : 0 ) * 0.8 + ( $r['rating'] / 5 ) * 0.2;
	}
	return $out;
}

/** Can this accessory be used with a device of $brand? */
function bc_reco_fits( array $acc, $brand ) {
	if ( $acc['apple'] ) {
		return 'apple' === $brand || '' === $brand;
	}
	return true;
}

/**
 * Ranked candidates for one slot: $kind products, best tier first.
 * Tiers: 1 same brand, 2 B&C (fits), 3 brand-neutral (fits), 4 anything that fits.
 */
function bc_reco_slot( $pid, $kind, $brand, array $index ) {
	$mine = isset( $index[ $pid ] ) ? array_flip( $index[ $pid ]['tok'] ) : array();
	$pool = array();
	foreach ( $index as $id => $r ) {
		if ( $id !== $pid && $r['kind'] === $kind && bc_reco_fits( $r, $brand ) ) {
			$pool[ $id ] = $r;
		}
	}
	if ( ! $pool ) {
		return array();
	}
	$pop    = bc_reco_popularity( $pool );
	$scored = array();
	foreach ( $pool as $id => $r ) {
		if ( $brand && $r['brand'] === $brand ) {
			$tier = 1;
		} elseif ( 'bc' === $r['maker'] ) {
			$tier = 2;
		} elseif ( '' === $r['brand'] ) {
			$tier = 3;
		} else {
			$tier = 4;
		}
		if ( 'powerbank' === $kind ) { // power banks fit everything: one tier, B&C gets a small nudge
			$tier = 1;
		}
		$bonus  = ( 'apple' === $brand && $r['apple'] ) ? 0.15 : 0;
		$bonus += ( 'powerbank' === $kind && 'bc' === $r['maker'] ) ? 0.1 : 0;
		// Same model family in the title (iPhone 12 Pro Max -> a "Pro Max" cable rather than an iPhone 3GS one).
		$shared = $mine ? count( array_intersect_key( $mine, array_flip( $r['tok'] ) ) ) : 0;
		$sim    = $shared ? $shared / ( count( $mine ) + count( $r['tok'] ) - $shared ) : 0;
		$scored[ $id ] = ( 10 - $tier ) + 1.2 * $sim + 0.6 * $pop[ $id ] + 0.4 * bc_reco_jitter( $pid, $id ) + $bonus;
	}
	arsort( $scored );
	return array_keys( $scored );
}

/** Which accessories to suggest for a product kind (ordered slots). */
function bc_reco_slots( $kind ) {
	$map = array(
		'battery'   => array( 'charger', 'cable', 'powerbank' ),
		'charger'   => array( 'cable', 'powerbank' ),
		'cable'     => array( 'charger', 'powerbank' ),
		'powerbank' => array( 'cable', 'charger' ),
	);
	$slots = isset( $map[ $kind ] ) ? $map[ $kind ] : array( 'powerbank', 'cable', 'charger' );
	return apply_filters( 'bc_reco_slots', $slots, $kind );
}

/* ------------------------------------------------------------------ */
/* Complementary                                                        */
/* ------------------------------------------------------------------ */

function bc_reco_complementary_compute( $pid, array $index ) {
	$me    = $index[ $pid ];
	$brand = $me['brand'];
	$lists = array();
	foreach ( bc_reco_slots( $me['kind'] ) as $slot ) {
		$lists[ $slot ] = bc_reco_slot( $pid, $slot, $brand, $index );
	}
	/*
	 * A brand with no own charger / cable in the shop: the B&C cables move up (tier 2 of the cable slot already
	 * holds them), and an empty charger slot hands its place to a second cable.
	 */
	if ( isset( $lists['charger'], $lists['cable'] ) && $brand ) {
		$has_charger = $lists['charger'] && $index[ $lists['charger'][0] ]['brand'] === $brand;
		$has_cable   = $lists['cable'] && $index[ $lists['cable'][0] ]['brand'] === $brand;
		if ( ! $has_charger && ! $has_cable ) {
			$order = array( 'cable', 'powerbank', 'charger' );
			$lists = array_merge( array_flip( $order ), array_intersect_key( $lists, array_flip( $order ) ) );
			$lists = array_filter( $lists, 'is_array' );
		}
	}
	// Round robin: best of each slot first, so the first three cards are a charger, a cable and a power bank.
	$out  = array();
	$more = true;
	for ( $i = 0; $more && count( $out ) < 12; $i++ ) {
		$more = false;
		foreach ( $lists as $list ) {
			if ( isset( $list[ $i ] ) ) {
				$more = true;
				if ( ! in_array( $list[ $i ], $out, true ) ) {
					$out[] = $list[ $i ];
				}
			}
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* Related (alternatives)                                              */
/* ------------------------------------------------------------------ */

function bc_reco_related_compute( $pid, array $index ) {
	$me   = $index[ $pid ];
	$pool = array();
	foreach ( $index as $id => $r ) {
		if ( $id !== $pid && $r['kind'] === $me['kind'] ) {
			$pool[ $id ] = $r;
		}
	}
	if ( ! $pool ) {
		return array();
	}
	$pop    = bc_reco_popularity( $pool );
	$mine   = array_flip( $me['tok'] );
	$scored = array();
	foreach ( $pool as $id => $r ) {
		$shared = count( array_intersect_key( $mine, array_flip( $r['tok'] ) ) );
		$union  = count( $mine ) + count( $r['tok'] ) - $shared;
		$sim    = $union ? $shared / $union : 0;
		$price  = 0;
		if ( $me['price'] > 0 && $r['price'] > 0 ) {
			$price = max( 0, 1 - abs( log( $r['price'] / $me['price'] ) ) / log( 3 ) ); // 1 = same price, 0 = 3x apart
		}
		$score = 4 * $sim
			- ( bc_reco_fits( $r, $me['brand'] ) ? 0 : 6 ) // a Lightning-only cable is no alternative for a Huawei cable
			+ ( array_intersect( $me['leaf'], $r['leaf'] ) ? 3 : 0 )
			+ ( $me['brand'] && $r['brand'] === $me['brand'] ? 3 : 0 )
			+ ( $r['top'] === $me['top'] ? 1.5 : 0 )
			+ 1 * $price
			+ 0.6 * $pop[ $id ]
			+ 0.2 * bc_reco_jitter( $pid, $id );
		$scored[ $id ] = $score;
	}
	arsort( $scored );
	return array_slice( array_keys( $scored ), 0, 24 );
}

/* ------------------------------------------------------------------ */
/* Public API (cached per product)                                     */
/* ------------------------------------------------------------------ */

function bc_reco_get( $pid, $which ) {
	$pid = (int) $pid;
	if ( $pid <= 0 ) {
		return array();
	}
	$ver = bc_reco_version();
	$key = 'bc_reco_' . $pid;
	$hit = get_transient( $key );
	if ( ! is_array( $hit ) || ! isset( $hit['v'] ) || $hit['v'] !== $ver ) {
		$index = bc_reco_index();
		$hit   = array( 'v' => $ver, 'c' => array(), 'r' => array() );
		if ( isset( $index[ $pid ] ) ) {
			$hit['c'] = bc_reco_complementary_compute( $pid, $index );
			$hit['r'] = bc_reco_related_compute( $pid, $index );
		}
		set_transient( $key, $hit, 12 * HOUR_IN_SECONDS );
	}
	return $hit[ $which ];
}

function bc_reco_complementary( $product_id, $limit = 0 ) {
	$ids = apply_filters( 'bc_reco_complementary', bc_reco_get( $product_id, 'c' ), $product_id );
	return $limit > 0 ? array_slice( $ids, 0, (int) $limit ) : $ids;
}
function bc_reco_related( $product_id, $limit = 0 ) {
	$ids = apply_filters( 'bc_reco_related', bc_reco_get( $product_id, 'r' ), $product_id );
	return $limit > 0 ? array_slice( $ids, 0, (int) $limit ) : $ids;
}

/* ------------------------------------------------------------------ */
/* Admin: show what the automatic picks are, under the manual fields   */
/* ------------------------------------------------------------------ */

add_action(
	'woocommerce_product_options_related',
	function () {
		global $post;
		if ( ! $post || 'auto-draft' === $post->post_status ) {
			return;
		}
		$rows = array(
			'_bc_complementary_ids' => array( 'محصولات مکمل', bc_reco_complementary( $post->ID, 6 ) ),
			'_bc_related_ids'       => array( 'محصولات مرتبط', bc_reco_related( $post->ID, 6 ) ),
		);
		echo '<div class="options_group"><p class="form-field" style="margin:0;"><label>پیشنهاد خودکار</label><span class="description" style="display:block;">وقتی فیلد دستی خالی باشد، این‌ها نمایش داده می‌شوند (بر اساس برند و دسته؛ هر هفته کمی جابه‌جا می‌شوند).</span></p>';
		foreach ( $rows as $key => $row ) {
			$manual = array_filter( (array) get_post_meta( $post->ID, $key, true ) );
			$names  = array();
			foreach ( $row[1] as $id ) {
				$names[] = esc_html( get_the_title( $id ) );
			}
			echo '<p class="form-field" style="margin:0;"><label>' . esc_html( $row[0] ) . '</label><span class="description" style="display:block;">';
			echo $manual ? 'فیلد دستی پر است؛ پیشنهاد خودکار استفاده نمی‌شود.' : ( $names ? implode( '، ', $names ) : 'پیشنهادی پیدا نشد.' ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</span></p>';
		}
		echo '</div>';
	},
	20
);
