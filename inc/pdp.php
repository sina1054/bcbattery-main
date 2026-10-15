<?php
/**
 * BC Battery - single product page (PDP), built on WooCommerce hooks.
 *
 * Files   : pdp.php (this file: switches, Woodmart settings, contact data, assets)
 *           pdp-render.php   (page sections, registered on the WooCommerce hooks)
 *           pdp-overlays.php (sticky buy bar, after-add sheet, messenger dialog, lightbox)
 *           pdp-cart.php     (add to cart / quantity / remove: /?wc-ajax=bc_pdp_cart, one request per change)
 *
 * Enabled products skip the Woodmart single-product layout (Layouts / Elementor) and render
 * woocommerce/content-single-product.php from this child theme. Nothing in Elementor is edited.
 *
 * Rollout  : option bc_pdp_rollout = 'ids' (default; bc_pdp_rollout_ids, default [28968]) or 'all'.
 * Preview  : ?bc_pdp=1 / ?bc_pdp=0 for administrators, on any product.
 * Kill     : define( 'BC_PDP_DISABLED', true ) in wp-config.php.
 * Settings : what Woodmart offers under Theme Settings > Product page is read in bc_pdp_settings().
 * Texts    : option / filter bc_pdp_texts (warranty, delivery, services); contact data: bc_pdp_contact().
 * Editable : Woodmart HTML blocks (buildable with Elementor) with the slugs bc-product-top / bc-product-bottom
 *            are shown above / below the product page (see bc_flow_zones() in flow-ui.php).
 *
 * Site-wide fix (all products): <h1> inside product descriptions becomes <h2> (one H1 per page).
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ */
/* 1. Is the new page on for this request?                             */
/* ------------------------------------------------------------------ */
function bc_pdp_enabled() {
	static $on = null;
	if ( null !== $on ) {
		return $on;
	}
	if ( defined( 'BC_PDP_DISABLED' ) && BC_PDP_DISABLED ) {
		return $on = false;
	}
	if ( ! function_exists( 'is_product' ) || ! did_action( 'wp' ) || ! is_product() ) {
		return false;
	}
	if ( isset( $_GET['bc_pdp'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $on = ( '1' === sanitize_text_field( wp_unslash( $_GET['bc_pdp'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( 'all' === get_option( 'bc_pdp_rollout', 'ids' ) ) {
		return $on = true;
	}
	$ids = array_map( 'absint', (array) get_option( 'bc_pdp_rollout_ids', array( 28968 ) ) );
	return $on = in_array( (int) get_queried_object_id(), $ids, true );
}

/* ------------------------------------------------------------------ */
/* 2. Woodmart settings                                                */
/* ------------------------------------------------------------------ */

/** A Woodmart theme option, with a default for when the theme (or the option) is missing. */
function bc_pdp_opt( $key, $default = '' ) {
	if ( ! function_exists( 'woodmart_get_opt' ) ) {
		return $default;
	}
	$v = woodmart_get_opt( $key, $default );
	return ( '' === $v || null === $v ) ? $default : $v;
}

/**
 * Every Woodmart switch the page respects, read once per request.
 * Change them in Theme Settings > Product page; filter 'bc_pdp_settings' can override any of them.
 */
function bc_pdp_settings() {
	static $s = null;
	if ( null !== $s ) {
		return $s;
	}
	$on  = static function ( $key, $default = '0' ) {
		return (bool) (int) bc_pdp_opt( $key, $default );
	};
	$id  = (int) get_queried_object_id();
	$bar = $on( 'single_sticky_add_to_cart', '0' );

	$s = array(
		'breadcrumbs'    => $on( 'product_page_breadcrumbs', '1' ),
		'short_desc'     => $on( 'product_short_description', '1' ),
		'sku'            => $on( 'sku_under_title', '0' ),
		'brand'          => $on( 'product_page_brand', '0' ),
		'brand_taxonomy' => (string) bc_pdp_opt( 'brands_attribute', 'pa_brand' ),
		'stock_top'      => 'after_title' === bc_pdp_opt( 'stock_status_position', '' ),
		'meta'           => (string) bc_pdp_opt( 'product_show_meta', 'after_tabs' ), // hide | add_to_cart | after_tabs
		'share'          => $on( 'product_share', '0' ),
		'zoom'           => $on( 'photoswipe_icon', '1' ),
		'bar_desktop'    => $bar,
		'bar_mobile'     => $bar && $on( 'mobile_single_sticky_add_to_cart', '0' ),
		'ajax'           => $on( 'single_ajax_add_to_cart', '1' ),
		'after_add'      => (string) bc_pdp_opt( 'add_to_cart_action', 'widget' ), // widget | popup | nothing
		'tab_description' => $on( 'enable_description_tab', '1' ),
		'tab_reviews'    => $on( 'enable_reviews_tab', '1' ) && function_exists( 'wc_reviews_enabled' ) && wc_reviews_enabled(),
		'countdown'      => $on( 'product_countdown', '0' ),
		'upsells'        => 'hide' !== bc_pdp_opt( 'upsells_position', 'standard' ),
		'related'        => $on( 'related_products', '1' ) && ! get_post_meta( $id, '_woodmart_related_off', true ),
		'related_count'  => max( 1, (int) bc_pdp_opt( 'related_product_count', 8 ) ),
	);
	return $s = (array) apply_filters( 'bc_pdp_settings', $s );
}

/* ------------------------------------------------------------------ */
/* 3. Texts and contact data                                           */
/* ------------------------------------------------------------------ */

/** Persian digits for numbers we print ourselves. */
function bc_pdp_fa( $value ) {
	return strtr( (string) $value, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
}

/** "1,250,000" in Persian digits with the Persian thousands separator. */
function bc_pdp_number( $n ) {
	return bc_pdp_fa( number_format( (float) round( (float) $n ), 0, '', '٬' ) );
}

/** Texts the page shows. Override with option bc_pdp_texts (array) or filter bc_pdp_texts. */
function bc_pdp_texts() {
	static $t = null;
	if ( null === $t ) {
		$t = (array) apply_filters(
			'bc_pdp_texts',
			wp_parse_args(
				(array) get_option( 'bc_pdp_texts', array() ),
				array(
					'warranty'        => '۳ ماه گارانتی تعویض کالا',
					'fast'            => 'ارسال زیر ۳ ساعت در تهران',
					'repair_title'    => 'نیاز به تعمیرات دارید؟',
					'repair'          => 'برای تعمیرات و تعویض باتری می‌توانید با مراجعه حضوری یا هماهنگی قبلی از این سرویس استفاده کنید.',
					'wholesale_title' => 'خرید عمده و همکاری',
					'wholesale'       => 'جهت اطلاع از نحوه همکاری و لیست قیمت می‌توانید با شماره زیر تماس بگیرید.',
					'help_title'      => 'نمی‌دانید کدام باتری مناسب گوشی شماست؟',
					'messenger_hours' => 'پاسخ‌گویی در ساعات کاری',
				)
			)
		);
	}
	return $t;
}

/** Links of the multi-contact plugin (ArContactUs) that are switched on, cached for a few hours. */
function bc_pdp_contact_from_plugin() {
	$found = get_transient( 'bc_pdp_contact_v1' );
	if ( is_array( $found ) ) {
		return $found;
	}
	global $wpdb;
	$found = array();
	$table = $wpdb->prefix . 'arcontactus';
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col( "SELECT link FROM `{$table}` WHERE status = 1 ORDER BY position" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $link ) {
			$link = trim( (string) $link );
			if ( 0 === strpos( $link, 'tel:' ) && empty( $found['phone'] ) ) {
				$found['phone'] = preg_replace( '/[^0-9+]/', '', substr( $link, 4 ) );
			} elseif ( empty( $found['bale'] ) && preg_match( '~^https?://(www\.)?ble\.ir/~i', $link ) ) {
				$found['bale'] = esc_url_raw( $link );
			} elseif ( empty( $found['whatsapp'] ) && preg_match( '~^https?://(wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com)/~i', $link ) ) {
				$found['whatsapp'] = esc_url_raw( $link );
			}
		}
	}
	set_transient( 'bc_pdp_contact_v1', $found, 6 * HOUR_IN_SECONDS );
	return $found;
}

/** phone / bale / whatsapp / wholesale. Order of precedence: filter > option bc_pdp_contact > plugin links > defaults. */
function bc_pdp_contact() {
	static $c = null;
	if ( null === $c ) {
		$defaults = array(
			'phone'     => '09109777350',
			'bale'      => 'https://ble.ir/bc_battery',
			'whatsapp'  => '',
			'wholesale' => '02166170454',
		);
		$base = array_merge( $defaults, array_filter( bc_pdp_contact_from_plugin() ) );
		$c    = (array) apply_filters( 'bc_pdp_contact', wp_parse_args( (array) get_option( 'bc_pdp_contact', array() ), $base ) );
	}
	return $c;
}

/* ------------------------------------------------------------------ */
/* 4. Body class, template bypass, footer parts                        */
/* ------------------------------------------------------------------ */
add_filter(
	'body_class',
	function ( $classes ) {
		if ( bc_pdp_enabled() ) {
			$s         = bc_pdp_settings();
			$classes[] = 'bc-pdp';
			$classes[] = 'bcp-page';
			if ( $s['bar_mobile'] ) {
				$classes[] = 'bcp-bar-m';
			}
			if ( $s['bar_desktop'] ) {
				$classes[] = 'bcp-bar-d';
			}
		}
		return $classes;
	}
);

/* The Woodmart Layouts module (Elementor single-product layouts) must not take the template over. */
add_filter(
	'template_include',
	function ( $template ) {
		if ( bc_pdp_enabled() && class_exists( '\\XTS\\Modules\\Layouts\\Single_Product' ) ) {
			remove_filter( 'template_include', array( \XTS\Modules\Layouts\Single_Product::get_instance(), 'override_template' ), 20 );
		}
		return $template;
	},
	5
);

/* WooCommerce remembers where it found content-single-product.php in the object cache, and that cache does not know
 * which theme is active: always answer with the active theme's own file. */
add_filter(
	'wc_get_template_part',
	function ( $template, $slug, $name ) {
		if ( 'content' === $slug && 'single-product' === $name && bc_pdp_enabled() ) {
			$own = get_stylesheet_directory() . '/woocommerce/content-single-product.php';
			if ( is_readable( $own ) ) {
				return $own;
			}
		}
		return $template;
	},
	20,
	3
);

add_action(
	'wp',
	function () {
		if ( ! bc_pdp_enabled() ) {
			return;
		}
		// Woodmart's scroll-triggered buy bar and its bottom toolbar are replaced by our own bar (pdp-overlays.php).
		remove_action( 'woodmart_before_wp_footer', 'woodmart_sticky_single_add_to_cart', 999 );
		remove_action( 'woodmart_before_wp_footer', 'woodmart_sticky_toolbar_template', 250 );
		add_action( 'wp_footer', 'bc_pdp_render_overlays', 5 );
	},
	30
);

/* ------------------------------------------------------------------ */
/* 5. Assets                                                           */
/* ------------------------------------------------------------------ */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! bc_pdp_enabled() || ! function_exists( 'bc_enqueue_module' ) ) {
			return;
		}
		$s = bc_pdp_settings();
		bc_enqueue_module( 'bc-pdp', 'pdp.css', 'pdp.js' );
		wp_add_inline_script(
			'bc-pdp',
			'window.bcPdp=' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'bc_pdp_cart' ) : home_url( '/?wc-ajax=bc_pdp_cart' ) ),
					'cart'     => esc_url_raw( wc_get_cart_url() ),
					'id'       => (int) get_queried_object_id(),
					'ajax'     => $s['ajax'],
					'sheet'    => 'nothing' !== $s['after_add'],
					'barMobile' => $s['bar_mobile'],
					'barDesktop' => $s['bar_desktop'],
				)
			) . ';',
			'before'
		);
	},
	10020
);

/* ------------------------------------------------------------------ */
/* 6. One H1 per product page; Store API is never cached               */
/* ------------------------------------------------------------------ */
function bc_pdp_demote_h1( $html ) {
	return is_string( $html ) ? preg_replace( '~<(/?)h1(?=[\s>])~i', '<$1h2', $html ) : $html;
}
add_filter(
	'the_content',
	function ( $content ) {
		return ( 'product' === get_post_type() ) ? bc_pdp_demote_h1( $content ) : $content;
	},
	9
);
add_filter( 'woocommerce_short_description', 'bc_pdp_demote_h1', 9 );

/* WooCommerce Store API responses contain the visitor's cart: never let LiteSpeed cache them. */
add_filter(
	'rest_post_dispatch',
	function ( $result, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/wc/store' ) ) {
			do_action( 'litespeed_control_set_nocache', 'WooCommerce Store API (cart state)' );
		}
		return $result;
	},
	10,
	3
);

/* ------------------------------------------------------------------ */
/* 7. Parts                                                            */
/* ------------------------------------------------------------------ */
foreach ( array( 'pdp-render', 'pdp-sections', 'pdp-overlays', 'pdp-cart' ) as $bc_pdp_part ) {
	$bc_pdp_file = __DIR__ . '/' . $bc_pdp_part . '.php';
	if ( file_exists( $bc_pdp_file ) ) {
		require_once $bc_pdp_file;
	}
}