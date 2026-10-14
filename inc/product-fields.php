<?php
/**
 * BC Battery - manual product links, set per product in the product editor.
 *
 * Two fields in Product data > "Linked Products":
 *  - _bc_complementary_ids : complementary products (shown in the cart / buy flow)
 *  - _bc_related_ids       : related products (shown on the product page)
 *
 * Only stores the choice; nothing is shown on the site until a template reads it with
 * bc_get_complementary_ids() / bc_get_related_ids().
 */
defined( 'ABSPATH' ) || exit;

function bc_linked_fields() {
	return array(
		'_bc_complementary_ids' => array(
			'label' => 'محصولات مکمل',
			'tip'   => 'محصولاتی که معمولاً همراه این کالا خریداری می‌شوند (مثلاً قاب، محافظ صفحه، ابزار تعویض باتری). در سبد خرید و صفحهٔ محصول پیشنهاد داده می‌شوند.',
		),
		'_bc_related_ids'      => array(
			'label' => 'محصولات مرتبط',
			'tip'   => 'محصولات مشابه یا جایگزین که در صفحهٔ این کالا نمایش داده می‌شوند. اگر خالی بماند، پیش‌فرض ووکامرس استفاده می‌شود.',
		),
	);
}

/* Read: published, visible products only, never the product itself. */
function bc_get_linked_ids( $product_id, $meta_key, $limit = 0 ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) get_post_meta( $product_id, $meta_key, true ) ) ) ) );
	$out = array();
	foreach ( $ids as $id ) {
		if ( (int) $id === (int) $product_id || 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$p = wc_get_product( $id );
		if ( $p && $p->is_visible() ) {
			$out[] = $id;
		}
	}
	return $limit > 0 ? array_slice( $out, 0, (int) $limit ) : $out;
}
function bc_get_complementary_ids( $product_id, $limit = 0 ) {
	return bc_get_linked_ids( $product_id, '_bc_complementary_ids', $limit );
}
function bc_get_related_ids( $product_id, $limit = 0 ) {
	return bc_get_linked_ids( $product_id, '_bc_related_ids', $limit );
}

/* Admin: fields in the Linked Products tab. */
add_action( 'woocommerce_product_options_related', function () {
	global $post;
	if ( ! $post ) {
		return;
	}
	echo '<div class="options_group">';
	foreach ( bc_linked_fields() as $key => $conf ) {
		$ids = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, $key, true ) ) );
		echo '<p class="form-field"><label for="' . esc_attr( $key ) . '">' . esc_html( $conf['label'] ) . '</label>';
		echo '<select class="wc-product-search" multiple="multiple" style="width:50%;" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '[]" data-placeholder="' . esc_attr__( 'جستجوی محصول با نام یا کد کالا…', 'woodmart' ) . '" data-action="woocommerce_json_search_products" data-exclude="' . intval( $post->ID ) . '">';
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				echo '<option value="' . esc_attr( $id ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select>' . wc_help_tip( $conf['tip'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
} );

/* Save (WooCommerce has already verified the nonce and the user's capability). */
add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	foreach ( array_keys( bc_linked_fields() ) as $key ) {
		$ids = isset( $_POST[ $key ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ $key ] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$ids = array_values( array_unique( array_diff( array_filter( $ids ), array( (int) $post_id ) ) ) );
		if ( $ids ) {
			update_post_meta( $post_id, $key, $ids );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}
} );
