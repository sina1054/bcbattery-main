<?php
/**
 * BC Battery - product structured data fixes (Rank Math Product entity) and the shared shipping estimate.
 *
 * Notion role 2 rows S3 and S12:
 *  - name is the product name, not the SEO title ("... | بی سی باتری");
 *  - brand, sku and mpn are filled (brand: product_brand > pa_brand > local "برند"; mpn: battery model code);
 *  - additionalProperty uses readable attribute labels instead of URL-encoded slugs;
 *  - category loses invisible bidi characters;
 *  - every Offer gets shippingDetails (Iran, lowest real PWS rate) and hasMerchantReturnPolicy (7 days).
 *
 * bc_ship_estimate() is also used by the product page and the cart (Notion row C1).
 * Kill switch: define( 'BC_SEO_SCHEMA_DISABLED', true ).
 */
defined( 'ABSPATH' ) || exit;

/** Strip invisible direction marks (LRE/RLE/PDF/LRO/RLO, isolates, LRM/RLM). */
function bc_strip_bidi( $s ) {
	return is_string( $s ) ? trim( preg_replace( '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s ) ) : $s;
}

/**
 * Lowest shipping cost the customer can see, in the shop currency (toman).
 * post_min    : cheapest "پست پیشتاز" to any province outside Tehran
 * post_max    : most expensive one
 * post_tehran : "پست پیشتاز" inside Tehran province
 * Computed from the live PWS methods once and cached for 12 hours. Returns null if PWS cannot answer.
 */
function bc_ship_estimate() {
	$key    = 'bc_ship_estimate_v1';
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( ! class_exists( 'WC_Shipping_Zones' ) || ! function_exists( 'WC' ) ) {
		return null;
	}
	$out = array( 'post_min' => 0, 'post_max' => 0, 'post_tehran' => 0 );
	try {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '1000000' );
		$product->set_price( '1000000' );
		$states = WC()->countries->get_states( 'IR' );
		$base   = (string) WC()->countries->get_base_state(); // 365 = Tehran
		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'WC_Forehand_Method' !== $method->id || ! $method->is_enabled() ) {
					continue;
				}
				$inside = false;
				foreach ( $zone['zone_locations'] as $loc ) {
					if ( 'IR:' . $base === $loc->code ) {
						$inside = true;
					}
				}
				foreach ( $states as $code => $name ) {
					if ( $inside !== ( (string) $code === $base ) ) {
						continue;
					}
					$cities = taxonomy_exists( 'state_city' ) ? get_terms( array( 'taxonomy' => 'state_city', 'parent' => (int) $code, 'hide_empty' => false, 'fields' => 'id=>name' ) ) : array();
					$city   = is_array( $cities ) && $cities ? ( array_search( $name, $cities, true ) ?: array_key_first( $cities ) ) : '';
					$method->rates = array();
					$method->calculate_shipping( array(
						'contents'        => array( 'x' => array( 'product_id' => 0, 'variation_id' => 0, 'quantity' => 1, 'data' => $product, 'line_total' => 1000000, 'line_tax' => 0, 'line_subtotal' => 1000000, 'line_subtotal_tax' => 0 ) ),
						'contents_cost'   => 1000000,
						'applied_coupons' => array(),
						'user'            => array( 'ID' => 0 ),
						'destination'     => array( 'country' => 'IR', 'state' => (string) $code, 'city' => (string) $city, 'postcode' => '', 'address' => '', 'address_1' => '', 'address_2' => '' ),
					) );
					$rate = $method->rates ? reset( $method->rates ) : null;
					$cost = $rate ? (int) round( (float) $rate->get_cost() ) : 0;
					if ( $cost <= 0 ) {
						continue;
					}
					if ( $inside ) {
						$out['post_tehran'] = $out['post_tehran'] ? min( $out['post_tehran'], $cost ) : $cost;
					} else {
						$out['post_min'] = $out['post_min'] ? min( $out['post_min'], $cost ) : $cost;
						$out['post_max'] = max( $out['post_max'], $cost );
					}
					if ( $inside ) {
						break; // one province
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		error_log( 'BC ship estimate failed: ' . $e->getMessage() );
		return null;
	}
	if ( ! $out['post_min'] ) {
		set_transient( $key, null, HOUR_IN_SECONDS );
		return null;
	}
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/** Values of the first product attribute whose taxonomy or label matches one of $wanted. */
function bc_schema_attr( WC_Product $product, array $wanted ) {
	foreach ( $product->get_attributes() as $attr ) {
		if ( ! is_a( $attr, 'WC_Product_Attribute' ) ) {
			continue;
		}
		$name  = $attr->get_name();
		$label = bc_strip_bidi( wc_attribute_label( $name, $product ) );
		if ( ! in_array( $name, $wanted, true ) && ! in_array( $label, $wanted, true ) && ! in_array( rawurldecode( $name ), $wanted, true ) ) {
			continue;
		}
		$vals = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) ) : $attr->get_options();
		$vals = array_values( array_filter( array_map( 'bc_strip_bidi', (array) $vals ) ) );
		if ( $vals ) {
			return $vals;
		}
	}
	return array();
}

function bc_schema_offer_extras( array $offer ) {
	$est  = bc_ship_estimate();
	$rate = $est ? (int) ( $est['post_tehran'] ? min( $est['post_tehran'], $est['post_min'] ) : $est['post_min'] ) : 0;
	if ( $rate ) {
		// Offers are priced in IRR by Rank Math (IRT is not an ISO code), so the rate is converted the same way.
		$offer['shippingDetails'] = array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array( '@type' => 'MonetaryAmount', 'value' => (string) ( $rate * 10 ), 'currency' => 'IRR' ),
			'shippingDestination' => array( '@type' => 'DefinedRegion', 'addressCountry' => 'IR' ),
			'deliveryTime'        => array(
				'@type'        => 'ShippingDeliveryTime',
				'handlingTime' => array( '@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY' ),
				'transitTime'  => array( '@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 5, 'unitCode' => 'DAY' ),
			),
		);
	}
	$offer['hasMerchantReturnPolicy'] = array(
		'@type'                => 'MerchantReturnPolicy',
		'applicableCountry'    => 'IR',
		'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
		'merchantReturnDays'   => 7,
		'returnMethod'         => 'https://schema.org/ReturnByMail',
	);
	return $offer;
}

add_filter( 'rank_math/snippet/rich_snippet_product_entity', function ( $entity ) {
	if ( defined( 'BC_SEO_SCHEMA_DISABLED' ) && BC_SEO_SCHEMA_DISABLED ) {
		return $entity;
	}
	try {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_queried_object_id() ) : null;
		if ( ! $product || ! is_array( $entity ) ) {
			return $entity;
		}

		$entity['name'] = bc_strip_bidi( wp_strip_all_tags( $product->get_name() ) );

		if ( isset( $entity['category'] ) ) {
			$entity['category'] = bc_strip_bidi( html_entity_decode( $entity['category'], ENT_QUOTES, 'UTF-8' ) );
		}

		$brand = array();
		if ( taxonomy_exists( 'product_brand' ) ) {
			$brand = wp_get_post_terms( $product->get_id(), 'product_brand', array( 'fields' => 'names' ) );
			$brand = is_wp_error( $brand ) ? array() : $brand;
		}
		if ( ! $brand ) {
			$brand = bc_schema_attr( $product, array( 'pa_brand', 'برند' ) );
		}
		if ( $brand ) {
			$entity['brand'] = array( '@type' => 'Brand', 'name' => bc_strip_bidi( $brand[0] ) );
		}

		$mpn = bc_schema_attr( $product, array( 'pa_battery-model', 'مدل باتری', 'مدل-باتری' ) );
		if ( $mpn ) {
			$entity['mpn'] = $mpn[0];
		}
		$entity['sku'] = $product->get_sku() ? $product->get_sku() : 'BC-' . $product->get_id();

		// Readable attribute names (Rank Math prints the encoded slug of local attributes).
		$props = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! is_a( $attr, 'WC_Product_Attribute' ) || ! $attr->get_visible() ) {
				continue;
			}
			$vals = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			$vals = array_filter( array_map( 'bc_strip_bidi', (array) $vals ) );
			if ( ! $vals ) {
				continue;
			}
			$label = bc_strip_bidi( wc_attribute_label( $attr->get_name(), $product ) );
			if ( false !== strpos( $label, '%' ) ) {
				$label = str_replace( '-', ' ', rawurldecode( $label ) );
			}
			$props[] = array( '@type' => 'PropertyValue', 'name' => $label, 'value' => implode( '، ', $vals ) );
		}
		if ( $props ) {
			$entity['additionalProperty'] = $props;
		} else {
			unset( $entity['additionalProperty'] );
		}

		if ( ! empty( $entity['offers'] ) && is_array( $entity['offers'] ) ) {
			if ( isset( $entity['offers']['@type'] ) ) {
				$entity['offers'] = bc_schema_offer_extras( $entity['offers'] );
			} else {
				foreach ( $entity['offers'] as $i => $offer ) {
					if ( is_array( $offer ) ) {
						$entity['offers'][ $i ] = bc_schema_offer_extras( $offer );
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		error_log( 'BC seo-schema failed: ' . $e->getMessage() );
	}
	return $entity;
}, 20 );

/* A new price list or shipping change should show up in the estimate: drop the cache when shipping settings are saved. */
add_action( 'woocommerce_update_options_shipping', function () {
	delete_transient( 'bc_ship_estimate_v1' );
} );

/* ------------------------------------------------------------------ */
/* Shipping estimate shown before the address step (Notion row C1)     */
/* ------------------------------------------------------------------ */

/** ['courier' => Tehran courier is on, 'post_min' => cheapest post outside Tehran (toman)] or null. */
function bc_ship_note_parts() {
	$est = bc_ship_estimate();
	if ( ! $est || empty( $est['post_min'] ) ) {
		return null;
	}
	$courier = false;
	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( $zone['shipping_methods'] as $method ) {
			if ( 'WC_Courier_Method' === $method->id && $method->is_enabled() ) {
				$courier = true;
			}
		}
	}
	return array( 'courier' => $courier, 'post_min' => (int) $est['post_min'] );
}

function bc_ship_note_number( $n ) {
	if ( function_exists( 'bc_pdp_number' ) ) {
		return bc_pdp_number( $n );
	}
	return function_exists( 'bc_num' ) ? bc_num( number_format( (float) $n, 0, '', '٬' ) ) : number_format( (float) $n );
}

/** Product page: one line under the price. Hidden for products that cannot be bought now. */
function bc_ship_note_pdp() {
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() || '' === $product->get_price() ) {
		return;
	}
	$p = bc_ship_note_parts();
	if ( ! $p ) {
		return;
	}
	$parts = array();
	if ( $p['courier'] ) {
		$parts[] = 'تهران با پیک ۳ ساعته، پرداخت هنگام تحویل';
	}
	$parts[] = 'سایر شهرها با پست از ' . bc_ship_note_number( $p['post_min'] ) . ' تومان';
	$icon = function_exists( 'bc_icon' ) ? bc_icon( 'truck', 16 ) : '';
	echo '<p class="bcp-ship"><span class="bcp-ship__ic" aria-hidden="true">' . $icon . '</span><span><b>هزینه ارسال:</b> ' . esc_html( implode( ' · ', $parts ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
// Registered by bc_pdp_register_hooks() in pdp-render.php (priority 22, right after the price).

/** Cart summary: replaces "در مرحله بعد محاسبه می‌شود". Returns HTML for the <dd>, or '' to keep the old text. */
function bc_ship_note_cart_html() {
	$p = bc_ship_note_parts();
	if ( ! $p ) {
		return '';
	}
	$html = '';
	if ( $p['courier'] ) {
		$html .= '<span>تهران: پیک ۳ ساعته، پرداخت هنگام تحویل</span>';
	}
	$html .= '<span>سایر شهرها: پست از ' . esc_html( bc_ship_note_number( $p['post_min'] ) ) . ' تومان</span>';
	return $html;
}
