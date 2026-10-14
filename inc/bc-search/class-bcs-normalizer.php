<?php
/**
 * BC Search - text normalization helpers (Persian / Arabic / Latin).
 *
 * @package woodmart-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BCS_Normalizer' ) ) :

	final class BCS_Normalizer {

		/** @var array|null */
		private static $char_map = null;

		/** @var array|null */
		private static $en_fa = null;

		/**
		 * Character unification map.
		 *
		 * @return array
		 */
		private static function char_map() {
			if ( null === self::$char_map ) {
				self::$char_map = array(
					'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'ہ' => 'ه',
					'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
					'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
					'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
					'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
					'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
					"\u{200C}" => ' ', "\u{200B}" => '', "\u{200D}" => '', "\u{200E}" => '', "\u{200F}" => '',
					"\u{202A}" => '', "\u{202B}" => '', "\u{202C}" => '', "\u{202D}" => '', "\u{202E}" => '',
					"\u{2066}" => '', "\u{2067}" => '', "\u{2068}" => '', "\u{2069}" => '', "\u{FEFF}" => '',
					'ـ' => '',
				);
			}
			return self::$char_map;
		}

		/**
		 * Normalize a string for matching: unify letters/digits, lowercase,
		 * strip punctuation and diacritics, collapse whitespace.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		public static function normalize( $text ) {
			$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
			$text = wp_strip_all_tags( $text );
			$text = strtr( $text, self::char_map() );
			$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text );
			$text = mb_strtolower( $text, 'UTF-8' );
			$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
			return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		}

		/**
		 * Light Persian plural stemming: "باتریها" => "باتری", "شارژرهای" => "شارژر".
		 *
		 * @param string $token Token.
		 * @return string
		 */
		public static function stem( $token ) {
			$len = mb_strlen( $token );
			if ( $len >= 5 && preg_match( '/(هایی|های|ها)$/u', $token, $m ) ) {
				$stem = mb_substr( $token, 0, $len - mb_strlen( $m[1] ) );
				if ( mb_strlen( $stem ) >= 3 ) {
					return $stem;
				}
			}
			return $token;
		}

		/**
		 * Split a token on letter/digit boundaries (s25 => s, 25).
		 *
		 * @param string $token Token.
		 * @return array
		 */
		public static function split_alnum( $token ) {
			$parts = preg_split( '/(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', $token, -1, PREG_SPLIT_NO_EMPTY );
			return is_array( $parts ) ? $parts : array( $token );
		}

		/**
		 * Unique tokens of a (raw or normalized) text, optionally with alnum parts.
		 *
		 * @param string $text       Text.
		 * @param bool   $with_parts Add letter/digit sub-parts.
		 * @return array
		 */
		public static function tokens( $text, $with_parts = true ) {
			$norm = self::normalize( $text );
			if ( '' === $norm ) {
				return array();
			}
			$tokens = explode( ' ', $norm );
			if ( $with_parts ) {
				foreach ( $tokens as $tok ) {
					$parts = self::split_alnum( $tok );
					if ( count( $parts ) > 1 ) {
						$tokens = array_merge( $tokens, $parts );
					}
					$stem = self::stem( $tok );
					if ( $stem !== $tok ) {
						$tokens[] = $stem;
					}
				}
			}
			return array_values( array_unique( $tokens ) );
		}

		/**
		 * Padded token string used for fast exact / prefix lookups with strpos.
		 *
		 * @param array $tokens Tokens.
		 * @return string
		 */
		public static function pad( array $tokens ) {
			$tokens = array_values( array_unique( array_filter( $tokens, 'strlen' ) ) );
			return $tokens ? ' ' . implode( ' ', $tokens ) . ' ' : '';
		}

		/**
		 * Multibyte-safe Levenshtein distance with an upper bound.
		 *
		 * @param string $a   First.
		 * @param string $b   Second.
		 * @param int    $max Max distance of interest.
		 * @return int Distance, or $max + 1 when larger.
		 */
		public static function distance( $a, $b, $max = 2 ) {
			if ( $a === $b ) {
				return 0;
			}
			$x  = mb_str_split( $a );
			$y  = mb_str_split( $b );
			$lx = count( $x );
			$ly = count( $y );
			if ( abs( $lx - $ly ) > $max ) {
				return $max + 1;
			}
			$prev = range( 0, $ly );
			for ( $i = 1; $i <= $lx; $i++ ) {
				$cur     = array( $i );
				$row_min = $i;
				for ( $j = 1; $j <= $ly; $j++ ) {
					$cost    = ( $x[ $i - 1 ] === $y[ $j - 1 ] ) ? 0 : 1;
					$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
					if ( $cur[ $j ] < $row_min ) {
						$row_min = $cur[ $j ];
					}
				}
				if ( $row_min > $max ) {
					return $max + 1;
				}
				$prev = $cur;
			}
			return $prev[ $ly ];
		}

		/**
		 * English (QWERTY) to Persian standard keyboard map.
		 *
		 * @return array
		 */
		private static function en_fa() {
			if ( null === self::$en_fa ) {
				self::$en_fa = array(
					'q' => 'ض', 'w' => 'ص', 'e' => 'ث', 'r' => 'ق', 't' => 'ف', 'y' => 'غ', 'u' => 'ع', 'i' => 'ه', 'o' => 'خ', 'p' => 'ح',
					'[' => 'ج', ']' => 'چ', 'a' => 'ش', 's' => 'س', 'd' => 'ی', 'f' => 'ب', 'g' => 'ل', 'h' => 'ا', 'j' => 'ت', 'k' => 'ن',
					'l' => 'م', ';' => 'ک', "'" => 'گ', 'z' => 'ظ', 'x' => 'ط', 'c' => 'ز', 'v' => 'ر', 'b' => 'ذ', 'n' => 'د', 'm' => 'پ',
					',' => 'و', '\\' => 'پ',
				);
			}
			return self::$en_fa;
		}

		/**
		 * Convert text typed with the wrong keyboard layout.
		 *
		 * "fhjvd" => "باتری" and "ذپ40" => "bm40".
		 *
		 * @param string $raw Raw query.
		 * @return string Converted text, or '' when not applicable.
		 */
		public static function switch_layout( $raw ) {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				return '';
			}
			$has_fa    = (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $raw );
			$has_latin = (bool) preg_match( '/[a-z]/i', $raw );

			if ( $has_latin && ! $has_fa ) {
				$map = self::en_fa();
				$map['C'] = 'ژ';
				$map['H'] = 'آ';
				$out = '';
				foreach ( mb_str_split( $raw ) as $ch ) {
					if ( isset( $map[ $ch ] ) ) {
						$out .= $map[ $ch ];
					} else {
						$lc   = strtolower( $ch );
						$out .= isset( $map[ $lc ] ) ? $map[ $lc ] : $ch;
					}
				}
				return $out;
			}

			if ( $has_fa && ! $has_latin ) {
				$map            = array_flip( self::en_fa() );
				$map['پ']       = 'm';
				$map['ژ']       = 'c';
				$map['آ']       = 'h';
				$map['ي']       = 'd';
				$map['ك']       = ';';
				$out            = '';
				$converted_any  = false;
				foreach ( mb_str_split( $raw ) as $ch ) {
					if ( isset( $map[ $ch ] ) ) {
						$out          .= $map[ $ch ];
						$converted_any = true;
					} else {
						$out .= $ch;
					}
				}
				return $converted_any ? $out : '';
			}

			return '';
		}
	}

endif;
