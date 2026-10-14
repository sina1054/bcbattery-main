<?php
/**
 * Enqueue script and styles for child theme
 */
function woodmart_child_enqueue_styles() {
    wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array( 'woodmart-style' ), woodmart_get_theme_info( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'woodmart_child_enqueue_styles', 10010 );

function shetabwp_noindex_woocommerce_filtered_archives() {

    // 1. بررسی فعال بودن ووکامرس
    if ( ! function_exists( 'is_woocommerce' ) ) {
        return;
    }

    // 2. بررسی اینکه آیا در صفحه آرشیو ووکامرس هستیم
    if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) {
        return;
    }

    // 3. اگر پارامتر GET وجود ندارد، خروج
    if ( empty( $_GET ) ) {
        return;
    }

    // 4. کپی پارامترها برای بررسی
    $query_params = $_GET;

    // ------------------------------------------------------------------
	// لیست پارامترهایی که نباید باعث noindex شوند (لیست سفید)
    // شامل: صفحه‌بندی، پارامترهای تبلیغاتی (UTM)، سرچ کنسول و ...
    // Allowed parameters that SHOULD NOT trigger noindex (Whitelist)
    // You may expand this list as needed
    // ------------------------------------------------------------------
    $ignored_params = array(
        'page',
        'paged',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'fbclid',
        // 'orderby',       // uncomment if you want sorting to remain indexable
    );

    // حذف پارامترهای مجاز از لیست بررسی
    foreach ( $ignored_params as $param ) {
        if ( isset( $query_params[ $param ] ) ) {
            unset( $query_params[ $param ] );
        }
    }

    // 5. اگر بعد از حذف پارامترهای مجاز، آرایه خالی شد، یعنی فیلتری اعمال نشده
    // پس noindex اعمال نمی‌کنیم و تابع را می‌بندیم.
    if ( empty( $query_params ) ) {
        return;
    }

    // ------------------------------------------------------------------
    // اعمال noindex, follow برای تمام افزونه‌های سئو
    // ------------------------------------------------------------------

    // Yoast SEO
    add_filter( 'wpseo_robots', function() {
        return 'noindex, follow';
    }, 999 );

    // Rank Math
    add_filter( 'rank_math/frontend/robots', function( $robots ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
        return $robots;
    }, 999 );

    // All in One SEO (AIOSEO)
    add_filter( 'aioseo_robots', function( $attributes ) {
        $attributes['noindex']  = true;
        $attributes['nofollow'] = false;
        return $attributes;
    }, 999 );

    // WordPress Core (5.7+)
    add_filter( 'wp_robots', function( $robots ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
        return $robots;
    }, 999 );
}

// اتصال به هوک 'wp'
add_action( 'wp', 'shetabwp_noindex_woocommerce_filtered_archives' );

/* BC Battery PDP. Guarded: a failure here must never take the site down. */
if ( file_exists( get_stylesheet_directory() . '/inc/pdp.php' ) ) {
	try {
		require_once get_stylesheet_directory() . '/inc/pdp.php';
	} catch ( \Throwable $e ) {
		error_log( 'BC PDP load failed: ' . $e->getMessage() );
	}
}

require_once get_stylesheet_directory().'/inc/perf-home.php';

/* BC Search (replaces FiboSearch). Guarded: a failure here must never take the site down. */
if ( file_exists( get_stylesheet_directory() . '/inc/bc-search/bc-search.php' ) ) {
	try {
		require_once get_stylesheet_directory() . '/inc/bc-search/bc-search.php';
	} catch ( \Throwable $e ) {
		error_log( 'BC Search load failed: ' . $e->getMessage() );
	}
}

 
// پاک کردن rel next و perv برای بهینه کردن بودج خزش
add_filter( 'rank_math/frontend/next_rel_link', '__return_false' );
add_filter( 'rank_math/frontend/prev_rel_link', '__return_false' );

/* BC purchase flow (cart / checkout / pay / account). Guarded: a failure here must never take the site down. */
if ( file_exists( get_stylesheet_directory() . '/inc/bc-core.php' ) ) {
	try {
		require_once get_stylesheet_directory() . '/inc/bc-core.php';
	} catch ( \Throwable $e ) {
		error_log( 'BC flow load failed: ' . $e->getMessage() );
	}
}
