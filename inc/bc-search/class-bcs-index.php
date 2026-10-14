<?php
/**
 * BC Search - lightweight product index (built from SQL, stored in one option).
 *
 * @package woodmart-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BCS_Index' ) ) :

	final class BCS_Index {

		const OPTION  = 'bcs_search_index';
		const VERSION = 4;

		/** @var array|null In-request cache. */
		private static $data = null;

		/** @var bool */
		private static $invalidated = false;

		/**
		 * Register invalidation hooks.
		 */
		public static function init() {
			$product_hooks = array(
				'woocommerce_new_product',
				'woocommerce_update_product',
				'woocommerce_delete_product',
				'woocommerce_trash_product',
				'woocommerce_product_set_stock_status',
				'woocommerce_variation_set_stock_status',
				'woocommerce_update_product_variation',
			);
			foreach ( $product_hooks as $hook ) {
				add_action( $hook, array( __CLASS__, 'invalidate' ) );
			}

			add_action( 'transition_post_status', array( __CLASS__, 'on_status_change' ), 10, 3 );
			add_action( 'deleted_post', array( __CLASS__, 'on_post_deleted' ), 10, 2 );
			add_action( 'set_object_terms', array( __CLASS__, 'on_set_terms' ), 10, 4 );
			add_action( 'edited_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
			add_action( 'created_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
			add_action( 'delete_term', array( __CLASS__, 'on_term_change' ), 10, 3 );
		}

		/**
		 * @param string  $new  New status.
		 * @param string  $old  Old status.
		 * @param WP_Post $post Post.
		 */
		public static function on_status_change( $new, $old, $post ) {
			if ( $new !== $old && $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				self::invalidate();
			}
		}

		/**
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post.
		 */
		public static function on_post_deleted( $post_id, $post = null ) {
			if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				self::invalidate();
			}
		}

		/**
		 * @param int    $object_id Object ID.
		 * @param array  $terms     Terms.
		 * @param array  $tt_ids    Term taxonomy IDs.
		 * @param string $taxonomy  Taxonomy.
		 */
		public static function on_set_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
			if ( self::is_indexed_taxonomy( $taxonomy ) && 'product' === get_post_type( $object_id ) ) {
				self::invalidate();
			}
		}

		/**
		 * @param int    $term_id  Term ID.
		 * @param int    $tt_id    Term taxonomy ID.
		 * @param string $taxonomy Taxonomy.
		 */
		public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
			if ( self::is_indexed_taxonomy( $taxonomy ) ) {
				self::invalidate();
			}
		}

		/**
		 * @param string $taxonomy Taxonomy.
		 * @return bool
		 */
		private static function is_indexed_taxonomy( $taxonomy ) {
			return in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand', 'product_visibility' ), true ) || 0 === strpos( (string) $taxonomy, 'pa_' );
		}

		/**
		 * Drop the stored index; it is rebuilt lazily on the next search.
		 */
		public static function invalidate() {
			if ( self::$invalidated ) {
				return;
			}
			self::$invalidated = true;
			self::$data        = null;
			delete_option( self::OPTION );
			wp_cache_delete( self::OPTION, 'bcs' );
			do_action( 'litespeed_purge', 'bcs_search' ); // Cached AJAX search responses.
			update_option( 'bcs_index_ver', time(), true ); // Changes the AJAX URL, so cached responses are bypassed.
		}

		/**
		 * Get the index (build if missing).
		 *
		 * @return array
		 */
		public static function get() {
			if ( null !== self::$data ) {
				return self::$data;
			}

			$data = wp_cache_get( self::OPTION, 'bcs' );
			if ( ! is_array( $data ) ) {
				$data = get_option( self::OPTION );
			}

			if ( ! is_array( $data ) || ! isset( $data['v'] ) || self::VERSION !== (int) $data['v'] ) {
				$data = self::build();
				update_option( self::OPTION, $data, false );
			}

			wp_cache_set( self::OPTION, $data, 'bcs', HOUR_IN_SECONDS );
			self::$data = $data;
			return $data;
		}

		/**
		 * Build the index from the database.
		 *
		 * @return array
		 */
		public static function build() {
			global $wpdb;

			$rows = $wpdb->get_results(
				"SELECT p.ID, p.post_title, l.sku, l.stock_status, l.total_sales
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID
				WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''"
			);

			$docs = array();
			$skus = array();
			foreach ( (array) $rows as $row ) {
				$id          = (int) $row->ID;
				$docs[ $id ] = array(
					'title' => $row->post_title,
					'in'    => ( 'outofstock' === $row->stock_status ) ? 0 : 1,
					'pop'   => (int) $row->total_sales,
				);
				$skus[ $id ] = array();
				if ( '' !== (string) $row->sku ) {
					$skus[ $id ][] = $row->sku;
				}
			}

			// Variation SKUs belong to their parent product.
			$var_rows = $wpdb->get_results(
				"SELECT p.post_parent, l.sku
				FROM {$wpdb->posts} p
				JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID
				WHERE p.post_type = 'product_variation' AND p.post_status = 'publish' AND l.sku <> ''"
			);
			foreach ( (array) $var_rows as $row ) {
				$parent = (int) $row->post_parent;
				if ( isset( $skus[ $parent ] ) ) {
					$skus[ $parent ][] = $row->sku;
				}
			}

			// All product terms in one query.
			$term_rows = $wpdb->get_results(
				"SELECT tr.object_id, tt.taxonomy, t.term_id, t.name, t.slug
				FROM {$wpdb->term_relationships} tr
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
				WHERE tt.taxonomy IN ('product_cat','product_tag','product_brand','product_visibility') OR tt.taxonomy LIKE 'pa\\_%'"
			);

			$hide_oos   = ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) );
			$cats_of    = array();
			$brands_of  = array();
			$attrs_of   = array();
			$excluded   = array();
			$cat_parent = array();

			foreach ( (array) $term_rows as $tr ) {
				$id = (int) $tr->object_id;
				if ( ! isset( $docs[ $id ] ) ) {
					continue;
				}
				switch ( $tr->taxonomy ) {
					case 'product_visibility':
						if ( 'exclude-from-search' === $tr->slug ) {
							$excluded[ $id ] = true;
						}
						break;
					case 'product_cat':
						$cats_of[ $id ][ (int) $tr->term_id ] = $tr->name;
						break;
					case 'product_brand':
					case 'pa_brand':
						$brands_of[ $id ][] = $tr->name;
						break;
					default:
						$attrs_of[ $id ][] = $tr->name;
				}
			}

			// Custom (non-taxonomy) product attributes.
			$meta_rows = $wpdb->get_results(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
				WHERE pm.meta_key = '_product_attributes' AND pm.meta_value <> '' AND pm.meta_value <> 'a:0:{}'"
			);
			foreach ( (array) $meta_rows as $mr ) {
				$attrs = maybe_unserialize( $mr->meta_value );
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				foreach ( $attrs as $attr ) {
					if ( is_array( $attr ) && empty( $attr['is_taxonomy'] ) && ! empty( $attr['value'] ) ) {
						$attrs_of[ (int) $mr->post_id ][] = str_replace( '|', ' ', (string) $attr['value'] );
					}
				}
			}

			// Category tree (for ancestor filtering and suggestions).
			$cat_terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			$cats = array();
			if ( ! is_wp_error( $cat_terms ) ) {
				foreach ( $cat_terms as $term ) {
					$cat_parent[ $term->term_id ] = (int) $term->parent;
					if ( 'uncategorized' === $term->slug ) {
						continue;
					}
					$cats[ $term->term_id ] = array(
						'n'     => BCS_Normalizer::normalize( $term->name ),
						'tk'    => BCS_Normalizer::pad( BCS_Normalizer::tokens( $term->name ) ),
						'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'slug'  => $term->slug,
						'count' => (int) $term->count,
					);
				}
			}

			$brands      = array();
			$brand_terms = taxonomy_exists( 'product_brand' ) ? get_terms(
				array(
					'taxonomy'   => 'product_brand',
					'hide_empty' => true,
				)
			) : array();
			if ( ! is_wp_error( $brand_terms ) ) {
				foreach ( $brand_terms as $term ) {
					$brands[ $term->term_id ] = array(
						'n'     => BCS_Normalizer::normalize( $term->name ),
						'tk'    => BCS_Normalizer::pad( BCS_Normalizer::tokens( $term->name ) ),
						'name'  => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
						'slug'  => $term->slug,
						'count' => (int) $term->count,
					);
				}
			}

			$index = array();
			foreach ( $docs as $id => $doc ) {
				if ( isset( $excluded[ $id ] ) || ( $hide_oos && ! $doc['in'] ) ) {
					continue;
				}

				$title_tokens = BCS_Normalizer::tokens( $doc['title'] );
				$sku_tokens   = array();
				$sku_compact  = array();
				foreach ( array_unique( $skus[ $id ] ) as $sku ) {
					$sku_tokens    = array_merge( $sku_tokens, BCS_Normalizer::tokens( $sku ) );
					$sku_compact[] = str_replace( ' ', '', BCS_Normalizer::normalize( $sku ) );
				}

				// Category IDs with ancestors.
				$cat_ids   = array();
				$cat_names = array();
				if ( ! empty( $cats_of[ $id ] ) ) {
					foreach ( $cats_of[ $id ] as $cid => $cname ) {
						$cat_names[] = $cname;
						$guard       = 0;
						while ( $cid && $guard++ < 10 ) {
							$cat_ids[ $cid ] = true;
							$cid             = isset( $cat_parent[ $cid ] ) ? $cat_parent[ $cid ] : 0;
						}
					}
				}

				$brand_tokens = array();
				if ( ! empty( $brands_of[ $id ] ) ) {
					$brand_tokens = BCS_Normalizer::tokens( implode( ' ', $brands_of[ $id ] ) );
				}
				$attr_tokens = array();
				if ( ! empty( $attrs_of[ $id ] ) ) {
					$attr_tokens = BCS_Normalizer::tokens( implode( ' ', $attrs_of[ $id ] ) );
				}

				$norm_title   = BCS_Normalizer::normalize( $doc['title'] );
				$index[ $id ] = array(
					't'   => $norm_title,
					'tt'  => BCS_Normalizer::pad( $title_tokens ),
					'tc'  => str_replace( ' ', '', $norm_title ),
					'sk'  => BCS_Normalizer::pad( $sku_tokens ),
					'skc' => $sku_compact ? ' ' . implode( ' ', $sku_compact ) . ' ' : '',
					'ct'  => BCS_Normalizer::pad( BCS_Normalizer::tokens( implode( ' ', $cat_names ) ) ),
					'br'  => BCS_Normalizer::pad( $brand_tokens ),
					'at'  => BCS_Normalizer::pad( $attr_tokens ),
					'cid' => array_keys( $cat_ids ),
					'in'  => $doc['in'],
					'pop' => $doc['pop'],
					'len' => count( explode( ' ', $norm_title ) ),
				);
			}

			// Inverse document frequency over title, brand and category tokens.
			$df = array();
			foreach ( $index as $doc ) {
				foreach ( array_unique( explode( ' ', trim( $doc['tt'] . ' ' . $doc['br'] . ' ' . $doc['ct'] ) ) ) as $tok ) {
					if ( '' !== $tok ) {
						$df[ $tok ] = isset( $df[ $tok ] ) ? $df[ $tok ] + 1 : 1;
					}
				}
			}
			$n   = max( 1, count( $index ) );
			$idf = array();
			foreach ( $df as $tok => $count ) {
				$idf[ $tok ] = round( log( 1 + ( $n - $count + 0.5 ) / ( $count + 0.5 ) ), 3 );
			}

			return array(
				'idf'    => $idf,
				'v'      => self::VERSION,
				'built'  => time(),
				'docs'   => $index,
				'cats'   => $cats,
				'brands' => $brands,
			);
		}
	}

endif;
