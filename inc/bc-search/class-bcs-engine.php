<?php
/**
 * BC Search - matching and relevance engine.
 *
 * @package woodmart-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BCS_Engine' ) ) :

	final class BCS_Engine {

		const MAX_VARIANTS = 12;

		/** @var array|null Synonym groups (normalized phrases). */
		private static $groups = null;

		/** @var array Per-request result cache. */
		private static $cache = array();

		/**
		 * Built-in synonym groups; merged with the Woodmart "search synonyms" option.
		 *
		 * @return array
		 */
		private static function builtin_groups() {
			return array(
				array( 'باتری', 'باطری', 'battery' ),
				array( 'آیفون', 'iphone' ),
				array( 'اپل', 'apple' ),
				array( 'آیپد', 'آی پد', 'ipad' ),
				array( 'سامسونگ', 'samsung' ),
				array( 'گلکسی', 'گالاکسی', 'galaxy' ),
				array( 'شیائومی', 'شیامی', 'شیاومی', 'xiaomi' ),
				array( 'ردمی', 'redmi' ),
				array( 'پوکو', 'poco' ),
				array( 'هواوی', 'هوآوی', 'huawei' ),
				array( 'نوکیا', 'nokia' ),
				array( 'سونی', 'sony' ),
				array( 'اکسپریا', 'xperia' ),
				array( 'ال جی', 'الجی', 'lg' ),
				array( 'اچ تی سی', 'htc' ),
				array( 'بلک بری', 'بلکبری', 'blackberry' ),
				array( 'ایسوس', 'asus' ),
				array( 'لنوو', 'lenovo' ),
				array( 'موتورولا', 'motorola' ),
				array( 'پرو', 'pro' ),
				array( 'مکس', 'max' ),
				array( 'پلاس', 'plus' ),
				array( 'مینی', 'mini' ),
				array( 'لایت', 'lite' ),
				array( 'الترا', 'اولترا', 'ultra' ),
				array( 'نوت', 'note' ),
				array( 'شارژر', 'charger' ),
				array( 'کابل', 'cable' ),
				array( 'پاوربانک', 'پاور بانک', 'power bank', 'powerbank' ),
				array( 'هندزفری', 'هندز فری', 'handsfree' ),
				array( 'هدفون', 'headphone' ),
				array( 'ایرپاد', 'airpods', 'airpod' ),
				array( 'تبلت', 'tablet' ),
				array( 'مودم', 'modem' ),
				array( 'دسته', 'کنترلر', 'controller', 'گیم پد', 'gamepad' ),
				array( 'پلی استیشن', 'playstation' ),
				array( 'تایپ سی', 'type c', 'typec' ),
				array( 'لایتنینگ', 'lightning' ),
				array( 'اصلی', 'اورجینال', 'اوریجینال', 'original' ),
				array( 'تقویت شده', 'تقویتی', 'پر ظرفیت' ),
			);
		}

		/**
		 * Synonym groups: built-ins + Woodmart theme option (Theme Settings > Search > Synonyms).
		 *
		 * @return array
		 */
		public static function groups() {
			if ( null !== self::$groups ) {
				return self::$groups;
			}

			$raw_groups = self::builtin_groups();
			$config     = function_exists( 'woodmart_get_opt' ) ? (string) woodmart_get_opt( 'search_synonyms' ) : '';
			foreach ( preg_split( '/\r\n|\r|\n/', $config ) as $line ) {
				$parts = explode( '=', $line, 2 );
				if ( 2 !== count( $parts ) ) {
					continue;
				}
				$raw_groups[] = array_merge( array( $parts[0] ), explode( ',', $parts[1] ) );
			}
			$raw_groups = apply_filters( 'bcs_synonym_groups', $raw_groups );

			$groups = array();
			foreach ( $raw_groups as $group ) {
				$norm = array();
				foreach ( (array) $group as $phrase ) {
					$n = BCS_Normalizer::normalize( $phrase );
					if ( '' !== $n ) {
						$norm[ $n ] = true;
					}
				}
				if ( count( $norm ) > 1 ) {
					$members = array_keys( $norm );
					usort(
						$members,
						function ( $a, $b ) {
							return mb_strlen( $b ) - mb_strlen( $a );
						}
					);
					$groups[] = $members;
				}
			}
			self::$groups = $groups;
			return $groups;
		}

		/**
		 * Query variants produced by synonym replacement (original first).
		 *
		 * @param string $norm Normalized query.
		 * @return array List of [ phrase, weight ].
		 */
		public static function variants( $norm ) {
			$variants = array( $norm => 1.0 );
			$original = ' ' . $norm . ' ';
			foreach ( self::groups() as $members ) {
				foreach ( array_keys( $variants ) as $variant ) {
					if ( count( $variants ) >= self::MAX_VARIANTS ) {
						break 2;
					}
					$padded = ' ' . $variant . ' ';
					foreach ( $members as $member ) {
						// Only words the user actually typed are expanded (no synonym chaining).
						if ( false === strpos( $padded, ' ' . $member . ' ' ) || false === strpos( $original, ' ' . $member . ' ' ) ) {
							continue;
						}
						foreach ( $members as $other ) {
							if ( $other === $member ) {
								continue;
							}
							$new = trim( str_replace( ' ' . $member . ' ', ' ' . $other . ' ', $padded ) );
							if ( ! isset( $variants[ $new ] ) && count( $variants ) < self::MAX_VARIANTS ) {
								$variants[ $new ] = 0.9 * $variants[ $variant ];
							}
						}
						break;
					}
				}
			}
			return $variants;
		}

		/**
		 * Words that carry no product meaning ("خرید باتری آیفون" = "باتری آیفون").
		 *
		 * @return array
		 */
		private static function noise_words() {
			return apply_filters( 'bcs_noise_words', array( 'خرید', 'قیمت', 'فروش', 'ارزان', 'ارزون', 'بهترین', 'مشخصات', 'لیست', 'ها', 'های', 'هایی', 'یک', 'برای', 'به', 'از', 'و', 'با', 'که', 'را', 'for', 'the', 'of', 'buy', 'price' ) );
		}

		/**
		 * Words that are useful but may be missing from a matching product title.
		 *
		 * @return array
		 */
		private static function weak_words() {
			return apply_filters( 'bcs_weak_words', array( 'گوشی', 'موبایل', 'مدل', 'اصلی', 'اورجینال', 'اوریجینال', 'original', 'اصل', 'نو', 'جدید', 'باتری', 'باطری', 'battery' ) );
		}

		/**
		 * Clean a normalized query: drop noise words, stem plurals.
		 *
		 * @param string $norm Normalized query.
		 * @return string
		 */
		public static function clean( $norm ) {
			$tokens = array_filter( explode( ' ', (string) $norm ), 'strlen' );
			$noise  = array_flip( self::noise_words() );
			$kept   = array();
			foreach ( $tokens as $tok ) {
				if ( ! isset( $noise[ $tok ] ) ) {
					$kept[] = BCS_Normalizer::stem( $tok );
				}
			}
			if ( ! $kept ) {
				$kept = $tokens; // Query was only noise: search it as typed.
			}
			return implode( ' ', $kept );
		}

		/**
		 * Key used for analytics and click learning.
		 *
		 * @param string $raw Raw query.
		 * @return string
		 */
		public static function query_key( $raw ) {
			return self::clean( BCS_Normalizer::normalize( $raw ) );
		}

		/**
		 * Does the token exist in titles / brands / categories?
		 *
		 * @param string $tok Token.
		 * @return bool
		 */
		private static function is_known( $tok ) {
			$index = BCS_Index::get();
			return isset( $index['idf'][ $tok ] );
		}

		/**
		 * Importance of a query token (inverse document frequency).
		 * Rare words weigh ~1.3, very common ones (باتری) ~0.35.
		 * Anything with a digit is a model number and always weighs at least 1.
		 *
		 * @param string $q Token.
		 * @return float
		 */
		private static function weight( $q ) {
			static $cache = array();
			if ( isset( $cache[ $q ] ) ) {
				return $cache[ $q ];
			}
			$index = BCS_Index::get();
			$idf   = isset( $index['idf'][ $q ] ) ? (float) $index['idf'][ $q ] : 5.0;
			$w     = 0.3 + min( $idf, 5.0 ) / 5.0;
			if ( preg_match( '/\d/', $q ) ) {
				$w = max( $w, 1.0 );
			} elseif ( in_array( $q, self::weak_words(), true ) ) {
				$w = min( $w, 0.4 );
			}
			$cache[ $q ] = $w;
			return $w;
		}

		/**
		 * Score one query token against one document (0 = no match).
		 *
		 * @param string $q   Token.
		 * @param array  $doc Document.
		 * @return float
		 */
		private static function token_score( $q, $doc ) {
			$len    = mb_strlen( $q );
			$is_num = (bool) preg_match( '/^\d+$/', $q );
			$has_d  = $is_num || (bool) preg_match( '/\d/', $q );
			$s      = 0.0;

			if ( false !== strpos( $doc['tt'], ' ' . $q . ' ' ) ) {
				$s = 10.0;
			} elseif ( ( $len >= 2 || $is_num ) && false !== strpos( $doc['tt'], ' ' . $q ) ) {
				// "13" must not look as good as an exact "13" when the title says "130".
				$s = $is_num ? 3.0 : 7.0;
			} elseif ( $len >= 3 && ! $has_d && false !== strpos( $doc['tc'], $q ) ) {
				$s = 4.0;
			}

			if ( '' !== $doc['sk'] ) {
				if ( false !== strpos( $doc['sk'], ' ' . $q . ' ' ) ) {
					$s = max( $s, 12.0 );
				} elseif ( $len >= 2 && ! $is_num && false !== strpos( $doc['sk'], ' ' . $q ) ) {
					$s = max( $s, 9.0 );
				} elseif ( $len >= 3 && false !== strpos( $doc['skc'], $q ) ) {
					$s = max( $s, 6.0 );
				}
			}

			if ( $s < 6.0 && '' !== $doc['br'] ) {
				if ( false !== strpos( $doc['br'], ' ' . $q . ' ' ) ) {
					$s = max( $s, 6.0 );
				} elseif ( $len >= 2 && false !== strpos( $doc['br'], ' ' . $q ) ) {
					$s = max( $s, 4.0 );
				}
			}

			if ( $s < 4.0 && '' !== $doc['ct'] ) {
				if ( false !== strpos( $doc['ct'], ' ' . $q . ' ' ) ) {
					$s = max( $s, 4.0 );
				} elseif ( $len >= 2 && false !== strpos( $doc['ct'], ' ' . $q ) ) {
					$s = max( $s, 3.0 );
				}
			}

			if ( $s < 2.5 && '' !== $doc['at'] && $len >= 2 ) {
				if ( false !== strpos( $doc['at'], ' ' . $q . ' ' ) ) {
					$s = max( $s, 2.5 );
				} elseif ( $len >= 3 && false !== strpos( $doc['at'], ' ' . $q ) ) {
					$s = max( $s, 1.5 );
				}
			}

			return $s;
		}

		/**
		 * Typo correction against the catalogue vocabulary (done once per query
		 * word, not per product): "سامسونک" => "سامسونگ", "xiomi" => "xiaomi".
		 * Model numbers are never corrected (s21 must not become s22).
		 *
		 * @param string $tok Token.
		 * @return array Up to 4 [ vocabulary token => distance ].
		 */
		private static function corrections( $tok ) {
			static $cache = array();
			if ( isset( $cache[ $tok ] ) ) {
				return $cache[ $tok ];
			}
			$len = mb_strlen( $tok );
			$out = array();
			if ( $len >= 4 && ! preg_match( '/\d/', $tok ) && ! self::is_known( $tok ) ) {
				$max   = ( $len >= 7 ) ? 2 : 1;
				$index = BCS_Index::get();
				foreach ( $index['idf'] as $word => $idf ) {
					$word = (string) $word;
					if ( preg_match( '/\d/', $word ) ) {
						continue;
					}
					$wl = mb_strlen( $word );
					if ( $wl < $len - $max ) {
						continue;
					}
					$d = ( $wl - $len > $max ) ? $max + 1 : BCS_Normalizer::distance( $tok, $word, $max );
					if ( $wl > $len ) {
						// The shopper may still be typing: compare with the word's start.
						$d = min( $d, BCS_Normalizer::distance( $tok, mb_substr( $word, 0, $len ), $max ) + 0.5 );
					}
					if ( $d <= $max ) {
						$out[ $word ] = $d;
					}
				}
				asort( $out );
				$out = array_slice( $out, 0, 4, true );
			}
			$cache[ $tok ] = $out;
			return $out;
		}

		/**
		 * Split a cleaned query into slots. A slot is one query word (or a
		 * multi-word synonym phrase such as "پاور بانک") with its alternatives:
		 * the typed form, its synonyms and (optionally) typo corrections, each with
		 * a confidence factor. Slots are matched independently, so
		 * "شیائومی ردمی نوت 8" matches "Xiaomi Redmi Note 8".
		 *
		 * @param string $norm  Cleaned, normalized query.
		 * @param bool   $fuzzy Add typo corrections.
		 * @return array
		 */
		public static function slots( $norm, $fuzzy = false ) {
			static $lookup = null;
			if ( null === $lookup ) {
				$lookup = array();
				foreach ( self::groups() as $gi => $members ) {
					foreach ( $members as $member ) {
						$lookup[ $member ][] = $gi;
					}
				}
			}
			$groups = self::groups();
			$tokens = array_values( array_filter( explode( ' ', $norm ), 'strlen' ) );
			$weak   = self::weak_words();
			$slots  = array();
			$n      = count( $tokens );

			for ( $i = 0; $i < $n; ) {
				$taken = 1;
				$alts  = array( array( 't' => array( $tokens[ $i ] ), 'f' => 1.0 ) );
				for ( $len = min( 3, $n - $i ); $len >= 1; $len-- ) {
					$phrase = implode( ' ', array_slice( $tokens, $i, $len ) );
					if ( ! isset( $lookup[ $phrase ] ) ) {
						continue;
					}
					$taken = $len;
					$alts  = array( array( 't' => explode( ' ', $phrase ), 'f' => 1.0 ) );
					$seen  = array( $phrase => true );
					foreach ( $lookup[ $phrase ] as $gi ) {
						foreach ( $groups[ $gi ] as $member ) {
							if ( ! isset( $seen[ $member ] ) ) {
								$seen[ $member ] = true;
								// Synonyms are as good as the typed word: آیفون == iphone.
								$alts[] = array( 't' => explode( ' ', $member ), 'f' => 0.97 );
							}
						}
					}
					break;
				}

				$original = $alts[0]['t'];
				if ( $fuzzy && 1 === count( $original ) ) {
					foreach ( self::corrections( $original[0] ) as $word => $dist ) {
						$alts[] = array( 't' => array( (string) $word ), 'f' => max( 0.5, 0.85 - 0.15 * $dist ) );
						// A corrected word may itself have synonyms (سامسونک => سامسونگ => samsung).
						if ( isset( $lookup[ $word ] ) ) {
							foreach ( $lookup[ $word ] as $gi ) {
								foreach ( $groups[ $gi ] as $member ) {
									if ( $member !== $word ) {
										$alts[] = array( 't' => explode( ' ', $member ), 'f' => max( 0.45, 0.8 - 0.15 * $dist ) );
									}
								}
							}
						}
					}
				}

				// Slot weight: the most common (least telling) known form decides.
				$known = array();
				foreach ( $alts as $alt ) {
					if ( $alt['f'] < 0.9 ) {
						continue;
					}
					$ws = array();
					foreach ( $alt['t'] as $tok ) {
						if ( self::is_known( $tok ) ) {
							$ws[] = self::weight( $tok );
						}
					}
					if ( $ws ) {
						$known[] = array_sum( $ws ) / count( $ws );
					}
				}
				$w         = $known ? min( $known ) : self::weight( $original[0] );
				$has_digit = (bool) preg_match( '/\d/', implode( '', $original ) );
				$is_weak   = ! $has_digit && ! array_diff( $original, $weak );
				if ( $has_digit ) {
					$w = max( $w, 1.0 );
				} elseif ( $is_weak ) {
					$w = min( $w, 0.4 );
				}

				$slots[] = array(
					'alts'  => $alts,
					'w'     => $w,
					'weak'  => $is_weak,
					'model' => $has_digit,
				);
				$i      += $taken;
			}
			return $slots;
		}

		/**
		 * Score a document.
		 *
		 * Modes: strict (every slot must match), compact (spaces ignored),
		 * fuzzy (every slot, typo corrections allowed), partial (corrections
		 * allowed and less important words may be missing; model numbers never).
		 *
		 * @param array  $slots  Query slots.
		 * @param string $phrase Cleaned query (for phrase bonuses).
		 * @param array  $doc    Document.
		 * @param string $mode   Mode.
		 * @return float
		 */
		private static function score_doc( $slots, $phrase, $doc, $mode ) {
			$compact = str_replace( ' ', '', $phrase );
			$total   = 0.0;

			if ( 'compact' === $mode ) {
				if ( mb_strlen( $compact ) < 3 ) {
					return 0.0;
				}
				if ( false !== strpos( $doc['tc'], $compact ) ) {
					$total = 6.0;
				} elseif ( '' !== $doc['skc'] && false !== strpos( $doc['skc'], $compact ) ) {
					$total = 8.0;
				} else {
					return 0.0;
				}
			} else {
				$all_w  = 0.0;
				$hit_w  = 0.0;
				$strong = false;
				// Weak words (گوشی، اصلی، اورجینال ...) only lift results that contain them,
				// unless the whole query is weak words ("باتری گوشی").
				$optional_weak = (bool) array_filter(
					$slots,
					function ( $sl ) {
						return ! $sl['weak'];
					}
				);
				foreach ( $slots as $slot ) {
					$best = 0.0;
					foreach ( $slot['alts'] as $alt ) {
						if ( $alt['f'] * 12.0 <= $best ) {
							continue;
						}
						$sum = 0.0;
						foreach ( $alt['t'] as $q ) {
							$s = self::token_score( $q, $doc );
							if ( $s <= 0 ) {
								$sum = 0.0;
								break;
							}
							$sum += $s;
						}
						$sum  = $sum / count( $alt['t'] ) * $alt['f'];
						$best = max( $best, $sum );
					}
					$all_w += $slot['w'];
					if ( $best <= 0 ) {
						if ( $slot['weak'] && $optional_weak ) {
							continue;
						}
						if ( 'partial' !== $mode || $slot['model'] ) {
							return 0.0;
						}
						continue;
					}
					$hit_w += $slot['w'];
					$total += $best * $slot['w'];
					if ( ! $slot['weak'] ) {
						$strong = true;
					}
				}
				if ( 'partial' === $mode ) {
					$coverage = $all_w > 0 ? $hit_w / $all_w : 0;
					if ( ! $strong || $coverage < 0.6 ) {
						return 0.0;
					}
					$total *= $coverage * $coverage;
				}
			}

			// Phrase / position bonuses.
			$pos = strpos( $doc['t'], $phrase );
			if ( 0 === $pos ) {
				$total += 20.0;
			} elseif ( false !== $pos ) {
				$total += 12.0;
			}
			if ( $doc['t'] === $phrase ) {
				$total += 25.0;
			}

			// SKU exact hit wins.
			if ( '' !== $doc['skc'] && mb_strlen( $compact ) >= 2 ) {
				if ( false !== strpos( $doc['skc'], ' ' . $compact . ' ' ) ) {
					$total += 60.0;
				} elseif ( mb_strlen( $compact ) >= 3 && false !== strpos( $doc['skc'], ' ' . $compact ) ) {
					$total += 15.0;
				}
			}

			// Compact (space-insensitive) title hit: "s25" vs "s 25", "bl4j" vs "bl-4j".
			if ( mb_strlen( $compact ) >= 3 && false !== strpos( $doc['tc'], $compact ) ) {
				$total += 4.0;
			}

			// Prefer shorter, more specific titles.
			$total += max( 0, 10 - (int) $doc['len'] ) * 0.4;

			return $total;
		}

		/**
		 * Score every document in one mode.
		 *
		 * @param string $norm Cleaned query.
		 * @param array  $docs Documents.
		 * @param string $mode Mode.
		 * @return array id => score
		 */
		private static function run( $norm, $docs, $mode ) {
			$slots  = self::slots( $norm, in_array( $mode, array( 'fuzzy', 'partial' ), true ) );
			$scores = array();
			foreach ( $docs as $id => $doc ) {
				$s = self::score_doc( $slots, $norm, $doc, $mode );
				if ( $s > 0 ) {
					$scores[ $id ] = $s;
				}
			}
			return $scores;
		}

		/**
		 * Business signals: what shoppers clicked for this query, and sales.
		 *
		 * @param array  $scores Scores.
		 * @param array  $docs   Documents.
		 * @param string $key    Query key.
		 * @return array
		 */
		private static function boost( $scores, $docs, $key ) {
			$clicks = class_exists( 'BCS_Analytics' ) ? BCS_Analytics::clicks_for( $key ) : array();
			$top    = $scores ? max( $scores ) : 0;
			foreach ( $scores as $id => $s ) {
				// Only re-order among relevant results, never lift weak matches to the top.
				if ( $s < $top * 0.5 ) {
					continue;
				}
				if ( ! empty( $clicks[ $id ] ) ) {
					$s += min( 20.0, 8.0 * log( 1 + $clicks[ $id ] ) );
				}
				$s            += min( 3.0, log( 1 + (int) $docs[ $id ]['pop'] ) );
				$scores[ $id ] = $s;
			}
			return $scores;
		}

		/**
		 * Search products.
		 *
		 * @param string $raw  Raw user query.
		 * @param array  $args { limit:int, cat:string (product_cat slug) }.
		 * @return array { ids:int[], total:int, mode:string, query:string }
		 */
		public static function search( $raw, $args = array() ) {
			$args = wp_parse_args(
				$args,
				array(
					'limit' => 10,
					'cat'   => '',
				)
			);

			$norm = self::clean( BCS_Normalizer::normalize( $raw ) );
			if ( '' === $norm ) {
				return array(
					'ids'   => array(),
					'total' => 0,
					'mode'  => 'none',
					'query' => (string) $raw,
				);
			}

			$key = md5( $norm . '|' . $args['cat'] );
			if ( ! isset( self::$cache[ $key ] ) ) {
				self::$cache[ $key ] = self::search_all( $raw, $norm, $args['cat'] );
			}
			$res = self::$cache[ $key ];

			$res['total'] = count( $res['ids'] );
			if ( $args['limit'] > 0 ) {
				$res['ids'] = array_slice( $res['ids'], 0, (int) $args['limit'] );
			}
			return $res;
		}

		/**
		 * Full ranked result list. Tries progressively looser matching and stops
		 * at the first level that finds something:
		 * strict -> compact -> keyboard layout -> typo correction -> partial.
		 *
		 * @param string $raw  Raw query.
		 * @param string $norm Cleaned, normalized query.
		 * @param string $cat  Category slug filter.
		 * @return array
		 */
		private static function search_all( $raw, $norm, $cat ) {
			$index = BCS_Index::get();
			$docs  = $index['docs'];

			if ( '' !== $cat ) {
				$term = get_term_by( 'slug', $cat, 'product_cat' );
				if ( $term ) {
					$cid  = (int) $term->term_id;
					$docs = array_filter(
						$docs,
						function ( $doc ) use ( $cid ) {
							return in_array( $cid, $doc['cid'], true );
						}
					);
				}
			}

			$mode   = 'exact';
			$query  = $raw;
			$scores = self::run( $norm, $docs, 'strict' );

			if ( ! $scores ) {
				$scores = self::run( $norm, $docs, 'compact' );
			}

			if ( ! $scores ) {
				$switched = BCS_Normalizer::switch_layout( $raw );
				$snorm    = self::clean( BCS_Normalizer::normalize( $switched ) );
				if ( '' !== $snorm && $snorm !== $norm ) {
					$scores = self::run( $snorm, $docs, 'strict' );
					if ( ! $scores ) {
						$scores = self::run( $snorm, $docs, 'compact' );
					}
					if ( $scores ) {
						$mode  = 'layout';
						$query = $switched;
						$norm  = $snorm;
					}
				}
			}

			if ( ! $scores && apply_filters( 'bcs_fuzzy_enabled', true ) ) {
				$scores = self::run( $norm, $docs, 'fuzzy' );
				if ( $scores ) {
					$mode = 'fuzzy';
				}
			}

			if ( ! $scores && count( explode( ' ', $norm ) ) > 1 ) {
				$scores = self::run( $norm, $docs, 'partial' );
				if ( $scores ) {
					$mode = 'partial';
				}
			}

			$scores   = self::boost( $scores, $docs, $norm );
			$oos_last = apply_filters( 'bcs_out_of_stock_last', true );
			$ids      = array_keys( $scores );
			usort(
				$ids,
				function ( $a, $b ) use ( $scores, $docs, $oos_last ) {
					if ( $oos_last && $docs[ $a ]['in'] !== $docs[ $b ]['in'] ) {
						return $docs[ $b ]['in'] - $docs[ $a ]['in'];
					}
					if ( abs( $scores[ $a ] - $scores[ $b ] ) > 0.0001 ) {
						return ( $scores[ $b ] > $scores[ $a ] ) ? 1 : -1;
					}
					if ( $docs[ $a ]['pop'] !== $docs[ $b ]['pop'] ) {
						return $docs[ $b ]['pop'] - $docs[ $a ]['pop'];
					}
					return $b - $a;
				}
			);

			return array(
				'ids'   => array_map( 'intval', $ids ),
				'mode'  => $ids ? $mode : 'none',
				'query' => $query,
			);
		}

		/**
		 * Match taxonomy terms (categories / brands) for the suggestion dropdown.
		 *
		 * @param string $raw   Raw query (or the layout-corrected one).
		 * @param string $which cats|brands.
		 * @param int    $limit Max terms.
		 * @return array List of index term entries with term_id.
		 */
		public static function match_terms( $raw, $which = 'cats', $limit = 3 ) {
			$norm = self::clean( BCS_Normalizer::normalize( $raw ) );
			if ( '' === $norm || $limit < 1 ) {
				return array();
			}
			$index  = BCS_Index::get();
			$terms  = isset( $index[ $which ] ) ? $index[ $which ] : array();
			$scores = array();

			foreach ( $terms as $term_id => $term ) {
				$best = 0.0;
				foreach ( self::variants( $norm ) as $phrase => $weight ) {
					$score = 0.0;
					foreach ( explode( ' ', (string) $phrase ) as $q ) {
						if ( false !== strpos( $term['tk'], ' ' . $q . ' ' ) ) {
							$score += 10.0;
						} elseif ( mb_strlen( $q ) >= 2 && false !== strpos( $term['tk'], ' ' . $q ) ) {
							$score += 6.0;
						} else {
							$score = 0.0;
							break;
						}
					}
					if ( $score > 0 ) {
						if ( $term['n'] === $phrase ) {
							$score += 30.0;
						} elseif ( 0 === strpos( $term['n'], (string) $phrase ) ) {
							$score += 10.0;
						}
						$extra  = count( explode( ' ', $term['n'] ) ) - count( explode( ' ', (string) $phrase ) );
						$score -= max( 0, $extra ) * 2;
						$score  = $score * $weight;
					}
					$best = max( $best, $score );
				}
				if ( $best > 0 && $term['count'] > 0 ) {
					$scores[ $term_id ] = $best;
				}
			}

			arsort( $scores );
			$out = array();
			foreach ( array_slice( array_keys( $scores ), 0, $limit ) as $term_id ) {
				$out[] = $terms[ $term_id ] + array( 'term_id' => (int) $term_id );
			}
			return $out;
		}
	}

endif;
