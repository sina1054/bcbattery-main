<?php
/**
 * BC Search - Woodmart / WooCommerce integration.
 *
 * Replaces the product branch of Woodmart's AJAX search endpoint and the
 * product search results page with the BCS engine. Woodmart's own front-end
 * (dropdown, mobile full-screen, "view all" link) is reused unchanged.
 *
 * @package woodmart-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BCS_Integration' ) ) :

	final class BCS_Integration {

		const VERSION = '1.0.0';

		/**
		 * Boot.
		 */
		public static function init() {
			if ( ! self::enabled() ) {
				return;
			}

			BCS_Index::init();

			// Remember LiteSpeed's tag prefix so cached search responses can be purged
			// even from fast AJAX requests where LiteSpeed Cache is not loaded.
			if ( defined( 'LSWCP_TAG_PREFIX' ) && get_option( 'bcs_ls_prefix' ) !== LSWCP_TAG_PREFIX ) {
				update_option( 'bcs_ls_prefix', LSWCP_TAG_PREFIX, true );
			}

			// AJAX suggestions: run before Woodmart (priority 10) and exit.
			add_action( 'wp_ajax_woodmart_ajax_search', array( __CLASS__, 'ajax_suggestions' ), 1 );
			add_action( 'wp_ajax_nopriv_woodmart_ajax_search', array( __CLASS__, 'ajax_suggestions' ), 1 );

			// Product details box.
			add_action( 'wp_ajax_bcs_details', array( __CLASS__, 'ajax_details' ) );
			add_action( 'wp_ajax_nopriv_bcs_details', array( __CLASS__, 'ajax_details' ) );

			// Search results page.
			add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ), 20 );
			add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 100, 2 );
			add_filter( 'posts_search_orderby', array( __CLASS__, 'posts_search_orderby' ), 100, 2 );

			// Tell shoppers when the query was corrected.
			add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'results_notice' ), 5 );
			add_action( 'woocommerce_no_products_found', array( __CLASS__, 'results_notice' ), 5 );

			// Front-end tweaks.
			add_filter( 'woodmart_ajax_search_symbols_count', array( __CLASS__, 'min_chars' ), 20 );
			add_filter( 'gettext_with_context', array( __CLASS__, 'placeholder' ), 20, 4 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		}

		/**
		 * Kill switch: define( 'BCS_DISABLE', true ) in wp-config.php, or the bcs_enabled filter.
		 *
		 * @return bool
		 */
		public static function enabled() {
			$enabled = ! ( defined( 'BCS_DISABLE' ) && BCS_DISABLE ) && function_exists( 'WC' );
			return (bool) apply_filters( 'bcs_enabled', $enabled );
		}

		/**
		 * @return int
		 */
		public static function min_chars() {
			return (int) apply_filters( 'bcs_min_chars', 2 );
		}

		/**
		 * Search input placeholder (was set in FiboSearch).
		 *
		 * @param string $translation Translated text.
		 * @param string $text        Original text.
		 * @param string $context     Context.
		 * @param string $domain      Domain.
		 * @return string
		 */
		public static function placeholder( $translation, $text, $context, $domain ) {
			if ( 'woodmart' === $domain && 'Search for products' === $text && 'submit button' === $context ) {
				return apply_filters( 'bcs_placeholder', 'دنبال چی میگردی؟ راحت پیدا کن 😉' );
			}
			return $translation;
		}

		/**
		 * AJAX autocomplete in Woodmart's response format.
		 */
		public static function ajax_suggestions() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only search.
			$post_type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : 'product';
			if ( 'product' !== $post_type ) {
				return; // Blog / portfolio searches stay with Woodmart.
			}

			$raw   = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
			$limit = isset( $_REQUEST['number'] ) ? absint( $_REQUEST['number'] ) : 8;
			$limit = max( 1, min( 30, $limit ? $limit : 8 ) );
			$cat   = isset( $_REQUEST['product_cat'] ) ? sanitize_title( wp_unslash( $_REQUEST['product_cat'] ) ) : '';
			// phpcs:enable

			$res         = BCS_Engine::search( $raw, array( 'limit' => $limit, 'cat' => $cat ) );
			$suggestions = array();

			if ( '' === $cat && apply_filters( 'bcs_show_term_suggestions', true ) ) {
				$term_query = ( 'layout' === $res['mode'] ) ? $res['query'] : $raw;
				$groups     = array(
					'categories' => array( 'cats', 'product_cat', 'دسته‌بندی‌ها', (int) apply_filters( 'bcs_category_suggestions', 3 ) ),
					'brands'     => array( 'brands', 'product_brand', 'برندها', (int) apply_filters( 'bcs_brand_suggestions', 2 ) ),
				);
				foreach ( $groups as $group => $conf ) {
					if ( 'brands' === $group && ! taxonomy_exists( 'product_brand' ) ) {
						continue;
					}
					$terms = BCS_Engine::match_terms( $term_query, $conf[0], $conf[3] );
					if ( ! $terms ) {
						continue;
					}
					$suggestions[] = array(
						'value'   => '',
						'divider' => $conf[2],
						'group'   => $group,
					);
					foreach ( $terms as $term ) {
						$link = get_term_link( $term['term_id'], $conf[1] );
						if ( is_wp_error( $link ) ) {
							continue;
						}
						$suggestions[] = array(
							'value'     => $term['name'],
							'permalink' => $link,
							'group'     => $group,
							'bcs_type'  => 'term',
							'bcs_count' => $term['count'],
						);
					}
				}
			}

			if ( $res['ids'] ) {
				if ( 'layout' === $res['mode'] ) {
					/* translators: %s: corrected search phrase. */
					$divider = sprintf( 'نتایج برای «%s»', $res['query'] );
				} elseif ( in_array( $res['mode'], array( 'fuzzy', 'partial' ), true ) ) {
					$divider = 'نزدیک‌ترین نتایج';
				} elseif ( $suggestions ) {
					$divider = sprintf( 'محصولات (%s)', number_format_i18n( $res['total'] ) );
				} else {
					$divider = '';
				}
				if ( '' !== $divider ) {
					$suggestions[] = array(
						'value'   => '',
						'divider' => $divider,
						'group'   => 'product',
					);
				}

				foreach ( $res['ids'] as $id ) {
					$product = wc_get_product( $id );
					if ( ! $product ) {
						continue;
					}
					$suggestions[] = array(
						'value'     => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
						'permalink' => $product->get_permalink(),
						'price'     => $product->get_price_html(),
						'thumbnail' => $product->get_image( 'woocommerce_gallery_thumbnail', array( 'sizes' => '80px', 'loading' => 'eager' ) ),
						'sku'       => $product->get_sku() ? esc_html__( 'SKU:', 'woodmart' ) . ' ' . $product->get_sku() : '',
						'group'     => 'product',
						'bcs_id'    => $id,
						'bcs_oos'   => $product->is_in_stock() ? 0 : 1,
					);
				}
			} elseif ( ! $suggestions ) {
				$suggestions[] = array(
					'value'              => esc_html__( 'No products found', 'woodmart' ),
					'products_not_found' => true,
					'no_results'         => true,
					'permalink'          => '',
					'group'              => 'product',
				);
			}

			if ( ! is_user_logged_in() && apply_filters( 'bcs_server_cache', true ) ) {
				// Let LiteSpeed serve repeat searches from cache for a short time.
				$ttl = (int) apply_filters( 'bcs_server_cache_ttl', 300 );
				header( 'Cache-Control: public, max-age=' . $ttl );
				header( 'X-LiteSpeed-Cache-Control: public,max-age=' . $ttl );
				header( 'X-LiteSpeed-Tag: ' . get_option( 'bcs_ls_prefix', '' ) . '_bcs_search' );
			} else {
				nocache_headers();
			}
			wp_send_json(
				array(
					'suggestions' => $suggestions,
					'bcs_total'   => $res['total'],
					'bcs_mode'    => $res['mode'],
				)
			);
		}

		/**
		 * Product details box (desktop hover), like FiboSearch's details panel.
		 */
		public static function ajax_details() {
			$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product || 'publish' !== get_post_status( $id ) || ! $product->is_visible() ) {
				wp_send_json_error();
			}

			$key  = 'bcs_d_' . $id . '_' . get_post_modified_time( 'U', true, $id );
			$html = get_transient( $key );
			if ( false === $html ) {
				$html = self::details_html( $product );
				set_transient( $key, $html, HOUR_IN_SECONDS );
			}
			wp_send_json_success( array( 'html' => $html ) );
		}

		/**
		 * @param WC_Product $product Product.
		 * @return string
		 */
		private static function details_html( $product ) {
			$link  = $product->get_permalink();
			$desc  = $product->get_short_description();
			$desc  = $desc ? $desc : $product->get_description();
			$desc  = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $desc ) ), 28, '…' );
			$in    = $product->is_in_stock();
			$stock = $in ? 'موجود در انبار' : 'ناموجود';

			ob_start();
			?>
			<div class="bcs-d">
				<a class="bcs-d-img" href="<?php echo esc_url( $link ); ?>"><?php echo $product->get_image( 'woocommerce_thumbnail', array( 'sizes' => '300px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				<a class="bcs-d-title" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
				<?php if ( $product->get_sku() ) : ?>
					<div class="bcs-d-sku">کد محصول: <?php echo esc_html( $product->get_sku() ); ?></div>
				<?php endif; ?>
				<div class="bcs-d-meta">
					<span class="bcs-d-price price"><?php echo $product->get_price_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<span class="bcs-d-stock <?php echo $in ? 'is-in' : 'is-out'; ?>"><?php echo esc_html( $stock ); ?></span>
				</div>
				<?php if ( $desc ) : ?>
					<p class="bcs-d-desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
				<div class="bcs-d-actions">
					<?php if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $in ) : ?>
						<input type="number" class="bcs-d-qty" min="1" step="1" value="1" aria-label="تعداد" />
						<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" data-quantity="1" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>" data-product_sku="<?php echo esc_attr( $product->get_sku() ); ?>" class="button bcs-d-atc add_to_cart_button ajax_add_to_cart" rel="nofollow">افزودن به سبد</a>
					<?php else : ?>
						<a href="<?php echo esc_url( $link ); ?>" class="button bcs-d-view"><?php echo $product->is_type( 'variable' ) && $in ? 'انتخاب گزینه‌ها' : 'مشاهده محصول'; ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		/**
		 * Is this the product search results page query?
		 *
		 * @param WP_Query $q Query.
		 * @return bool
		 */
		private static function is_product_search( $q ) {
			if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) {
				return false;
			}
			$pt = $q->get( 'post_type' );
			return 'product' === $pt || ( is_array( $pt ) && array( 'product' ) === array_values( $pt ) );
		}

		/**
		 * Rank the search results page with the engine.
		 *
		 * @param WP_Query $q Query.
		 */
		public static function pre_get_posts( $q ) {
			if ( ! self::is_product_search( $q ) ) {
				return;
			}
			$raw = (string) $q->get( 's' );
			if ( '' === trim( $raw ) ) {
				return;
			}
			$cat = $q->get( 'product_cat' );
			$res = BCS_Engine::search(
				$raw,
				array(
					'limit' => (int) apply_filters( 'bcs_results_page_limit', 500 ),
					'cat'   => is_string( $cat ) ? $cat : '',
				)
			);
			$q->set( 'bcs_ids', $res['ids'] ? $res['ids'] : array( 0 ) );
			$q->set( 'bcs_mode', $res['mode'] );
			$q->set( 'bcs_query', $res['query'] );
		}

		/**
		 * @param string   $search SQL.
		 * @param WP_Query $q      Query.
		 * @return string
		 */
		public static function posts_search( $search, $q ) {
			global $wpdb;
			$ids = $q->get( 'bcs_ids' );
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				return $search;
			}
			return " AND {$wpdb->posts}.ID IN (" . implode( ',', array_map( 'absint', $ids ) ) . ') ';
		}

		/**
		 * Keep engine ranking when sorted by relevance (the default for searches).
		 *
		 * @param string   $orderby SQL.
		 * @param WP_Query $q       Query.
		 * @return string
		 */
		public static function posts_search_orderby( $orderby, $q ) {
			global $wpdb;
			$ids = $q->get( 'bcs_ids' );
			if ( empty( $ids ) || ! is_array( $ids ) || count( $ids ) < 2 ) {
				return $orderby;
			}
			return "FIELD({$wpdb->posts}.ID," . implode( ',', array_map( 'absint', $ids ) ) . ')';
		}

		/**
		 * Notice above results when the engine corrected or loosened the query.
		 */
		public static function results_notice() {
			static $done = false;
			global $wp_query;
			if ( $done || ! $wp_query instanceof WP_Query || ! $wp_query->get( 'bcs_mode' ) ) {
				return;
			}
			$done = true;
			$mode = $wp_query->get( 'bcs_mode' );
			$raw  = (string) $wp_query->get( 's' );
			if ( 'layout' === $mode ) {
				$text = sprintf( 'نتایج برای «%1$s» نمایش داده شد (شما تایپ کردید «%2$s»).', $wp_query->get( 'bcs_query' ), $raw );
			} elseif ( 'fuzzy' === $mode ) {
				$text = sprintf( 'نتیجه دقیقی برای «%s» پیدا نشد؛ نزدیک‌ترین محصولات نمایش داده شد.', $raw );
			} elseif ( 'partial' === $mode ) {
				$text = sprintf( 'محصولی با همه کلمات «%s» پیدا نشد؛ مرتبط‌ترین محصولات نمایش داده شد.', $raw );
			} else {
				return;
			}
			echo '<div class="woocommerce-info bcs-notice">' . esc_html( $text ) . '</div>';
		}

		/**
		 * Front-end assets.
		 */
		public static function assets() {
			$dir = get_stylesheet_directory();
			$uri = get_stylesheet_directory_uri();
			if ( ! file_exists( $dir . '/assets/js/bc-search.js' ) ) {
				return;
			}
			$ver = self::VERSION . '.' . filemtime( $dir . '/assets/js/bc-search.js' );
			wp_enqueue_style( 'bcs-search', $uri . '/assets/css/bc-search.css', array(), $ver );
			wp_enqueue_script( 'bcs-search', $uri . '/assets/js/bc-search.js', array( 'jquery' ), $ver, true );
			wp_localize_script(
				'bcs-search',
				'bcsSearch',
				array(
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
					'details'    => (bool) apply_filters( 'bcs_details_box', false ),
					'breakpoint' => (int) apply_filters( 'bcs_details_breakpoint', 992 ),
					'analytics'  => (bool) apply_filters( 'bcs_analytics_enabled', true ),
					'oos'        => 'ناموجود',
					'ver'        => (int) get_option( 'bcs_index_ver', 1 ),
				)
			);
		}
	}

endif;
