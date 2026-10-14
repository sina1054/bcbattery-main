<?php
/**
 * BC Search - product search for bcbattery.ir (replaces FiboSearch).
 *
 * Loaded from the child theme's functions.php. Turn it off without touching
 * code by adding define( 'BCS_DISABLE', true ); to wp-config.php.
 *
 * Features:
 * - Relevance ranking (exact / prefix / phrase / SKU) over a cached product index.
 * - Persian normalization (ی/ي, ک/ك, آ/ا, ZWNJ, Persian & Arabic digits).
 * - Search in title, SKU (incl. variations), brand, category, tags, attributes.
 * - Synonyms (built-in + Woodmart theme option), typo tolerance, wrong keyboard layout fix.
 * - Category and brand suggestions, out-of-stock products last.
 * - Same ranking on the full search results page.
 * - Desktop hover details box with add to cart; search analytics admin page.
 *
 * @package woodmart-child
 */

defined( 'ABSPATH' ) || exit;

$bcs_dir = __DIR__;
require_once $bcs_dir . '/class-bcs-normalizer.php';
require_once $bcs_dir . '/class-bcs-index.php';
require_once $bcs_dir . '/class-bcs-engine.php';
require_once $bcs_dir . '/class-bcs-integration.php';
require_once $bcs_dir . '/class-bcs-analytics.php';
unset( $bcs_dir );

/*
 * Preview while FiboSearch is still active: open any page with ?bcs_preview=1
 * to see the theme search instead of FiboSearch's replacement form.
 */
if ( isset( $_GET['bcs_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	add_action(
		'wp_footer',
		function () {
			global $wp_filter;
			if ( empty( $wp_filter['wp_footer'] ) ) {
				return;
			}
			foreach ( $wp_filter['wp_footer']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( $cb['function'] instanceof Closure ) {
						$file = ( new ReflectionFunction( $cb['function'] ) )->getFileName();
						if ( false !== strpos( (string) $file, 'ajax-search-for-woocommerce/partials/themes/woodmart.php' ) ) {
							remove_action( 'wp_footer', $cb['function'], $priority );
						}
					}
				}
			}
		},
		-1000
	);
}

add_action(
	'after_setup_theme',
	function () {
		if ( BCS_Integration::enabled() ) {
			BCS_Integration::init();
			BCS_Analytics::init();
		}
	},
	20
);
