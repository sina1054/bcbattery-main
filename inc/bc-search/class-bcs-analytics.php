<?php
/**
 * BC Search - analytics (top / zero-result searches), click learning, admin page.
 *
 * Queries are logged by the browser once the visitor stops typing or submits,
 * so there is one write per real search, not one per keystroke. Clicks on a
 * suggestion teach the engine which product people want for that query.
 *
 * @package woodmart-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BCS_Analytics' ) ) :

	final class BCS_Analytics {

		const OPTION = 'bcs_search_log';
		const CLICKS = 'bcs_search_clicks';
		const MAX    = 500;

		/** @var array|null */
		private static $clicks = null;

		/**
		 * Boot.
		 */
		public static function init() {
			add_action( 'wp_ajax_bcs_log', array( __CLASS__, 'ajax_log' ) );
			add_action( 'wp_ajax_nopriv_bcs_log', array( __CLASS__, 'ajax_log' ) );
			add_action( 'wp_ajax_bcs_click', array( __CLASS__, 'ajax_click' ) );
			add_action( 'wp_ajax_nopriv_bcs_click', array( __CLASS__, 'ajax_click' ) );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
			add_action( 'admin_post_bcs_action', array( __CLASS__, 'handle_action' ) );
		}

		/**
		 * Skip bots and shop staff.
		 *
		 * @return bool
		 */
		private static function is_real_visitor() {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|headless/i', $ua ) ) {
				return false;
			}
			return ! current_user_can( 'manage_woocommerce' );
		}

		/**
		 * Record one search coming from the browser.
		 */
		public static function ajax_log() {
			$raw = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( apply_filters( 'bcs_analytics_enabled', true ) && self::is_real_visitor() ) {
				self::record( $raw );
			}
			wp_send_json_success();
		}

		/**
		 * @param string $raw Raw query.
		 */
		public static function record( $raw ) {
			$raw = trim( (string) $raw );
			$key = BCS_Engine::query_key( $raw );
			if ( mb_strlen( $key ) < 2 || mb_strlen( $key ) > 80 ) {
				return;
			}

			$res = BCS_Engine::search( $raw, array( 'limit' => 1 ) );
			$log = get_option( self::OPTION, array() );
			$log = is_array( $log ) ? $log : array();

			if ( ! isset( $log[ $key ] ) ) {
				$log[ $key ] = array(
					'q' => '',
					'c' => 0,
					'r' => 0,
					'm' => '',
					't' => 0,
				);
			}
			$log[ $key ]['q'] = $raw;
			$log[ $key ]['c']++;
			$log[ $key ]['r'] = (int) $res['total'];
			$log[ $key ]['m'] = $res['mode'];
			$log[ $key ]['t'] = time();

			if ( count( $log ) > self::MAX ) {
				uasort(
					$log,
					function ( $a, $b ) {
						return ( $b['c'] <=> $a['c'] ) ?: ( $b['t'] <=> $a['t'] );
					}
				);
				$log = array_slice( $log, 0, self::MAX, true );
			}
			update_option( self::OPTION, $log, false );
		}

		/**
		 * Learn from clicks: which product a shopper picked for a query.
		 */
		public static function ajax_click() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- public, write-limited counter.
			$raw = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
			$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			// phpcs:enable
			if ( $id && self::is_real_visitor() ) {
				$key = BCS_Engine::query_key( $raw );
				$res = BCS_Engine::search( $raw, array( 'limit' => 50 ) );
				// Only count products that were actually offered for this query.
				if ( mb_strlen( $key ) >= 2 && in_array( $id, $res['ids'], true ) ) {
					self::add_click( $key, $id );
				}
			}
			wp_send_json_success();
		}

		/**
		 * @param string $key Query key.
		 * @param int    $id  Product ID.
		 */
		private static function add_click( $key, $id ) {
			$all = get_option( self::CLICKS, array() );
			$all = is_array( $all ) ? $all : array();
			if ( ! isset( $all[ $key ] ) ) {
				$all[ $key ] = array();
			}
			$all[ $key ][ $id ] = ( isset( $all[ $key ][ $id ] ) ? (int) $all[ $key ][ $id ] : 0 ) + 1;
			arsort( $all[ $key ] );
			$all[ $key ] = array_slice( $all[ $key ], 0, 10, true );

			if ( count( $all ) > 400 ) {
				uasort(
					$all,
					function ( $a, $b ) {
						return array_sum( $b ) <=> array_sum( $a );
					}
				);
				$all = array_slice( $all, 0, 400, true );
			}
			update_option( self::CLICKS, $all, false );
			self::$clicks = $all;
		}

		/**
		 * Click counts for a query key (product id => clicks).
		 *
		 * @param string $key Query key.
		 * @return array
		 */
		public static function clicks_for( $key ) {
			if ( null === self::$clicks ) {
				$all          = get_option( self::CLICKS, array() );
				self::$clicks = is_array( $all ) ? $all : array();
			}
			return isset( self::$clicks[ $key ] ) ? self::$clicks[ $key ] : array();
		}

		/**
		 * Admin menu under WooCommerce.
		 */
		public static function menu() {
			add_submenu_page( 'woocommerce', 'گزارش جستجو', 'گزارش جستجو', 'manage_woocommerce', 'bcs-search', array( __CLASS__, 'render' ) );
		}

		/**
		 * Rebuild index / clear log.
		 */
		public static function handle_action() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ) );
			}
			check_admin_referer( 'bcs_action' );
			$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
			if ( 'rebuild' === $do ) {
				BCS_Index::invalidate();
				BCS_Index::get();
			} elseif ( 'clear' === $do ) {
				delete_option( self::OPTION );
				delete_option( self::CLICKS );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'bcs-search', 'done' => $do ), admin_url( 'admin.php' ) ) );
			exit;
		}

		/**
		 * @param array  $rows  Rows.
		 * @param string $title Title.
		 * @param string $empty Empty message.
		 */
		private static function table( $rows, $title, $empty ) {
			$modes = array(
				'exact'   => 'مستقیم',
				'layout'  => 'اصلاح کیبورد',
				'fuzzy'   => 'اصلاح غلط املایی',
				'partial' => 'تطبیق بخشی',
				'none'    => '—',
			);
			echo '<h2>' . esc_html( $title ) . '</h2>';
			if ( ! $rows ) {
				echo '<p>' . esc_html( $empty ) . '</p>';
				return;
			}
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>عبارت</th><th>تعداد جستجو</th><th>تعداد نتیجه</th><th>نوع تطبیق</th><th>آخرین بار</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$url = add_query_arg(
					array(
						's'         => $row['q'],
						'post_type' => 'product',
					),
					home_url( '/' )
				);
				printf(
					'<tr><td><a href="%1$s" target="_blank" rel="noopener">%2$s</a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
					esc_url( $url ),
					esc_html( $row['q'] ),
					esc_html( number_format_i18n( $row['c'] ) ),
					esc_html( number_format_i18n( $row['r'] ) ),
					esc_html( isset( $modes[ $row['m'] ] ) ? $modes[ $row['m'] ] : $row['m'] ),
					esc_html( human_time_diff( $row['t'] ) . ' پیش' )
				);
			}
			echo '</tbody></table>';
		}

		/**
		 * Admin page.
		 */
		public static function render() {
			$log = get_option( self::OPTION, array() );
			$log = is_array( $log ) ? array_values( $log ) : array();

			$top = $log;
			usort(
				$top,
				function ( $a, $b ) {
					return ( $b['c'] <=> $a['c'] ) ?: ( $b['t'] <=> $a['t'] );
				}
			);
			$zero = array_values(
				array_filter(
					$top,
					function ( $row ) {
						return 0 === (int) $row['r'];
					}
				)
			);

			$index  = get_option( BCS_Index::OPTION );
			$docs   = ( is_array( $index ) && isset( $index['docs'] ) ) ? count( $index['docs'] ) : 0;
			$built  = ( is_array( $index ) && isset( $index['built'] ) ) ? human_time_diff( $index['built'] ) . ' پیش' : 'هنوز ساخته نشده (با اولین جستجو ساخته می‌شود)';
			$clicks = get_option( self::CLICKS, array() );
			$learnt = is_array( $clicks ) ? count( $clicks ) : 0;
			?>
			<div class="wrap">
				<h1>گزارش جستجوی سایت</h1>
				<?php if ( isset( $_GET['done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p>انجام شد.</p></div>
				<?php endif; ?>
				<p>ایندکس جستجو: <strong><?php echo esc_html( number_format_i18n( $docs ) ); ?></strong> محصول — آخرین ساخت: <?php echo esc_html( $built ); ?>. ایندکس با هر تغییر محصول، موجودی یا دسته‌بندی خودکار به‌روز می‌شود.</p>
				<p>یادگیری از کلیک‌ها: برای <strong><?php echo esc_html( number_format_i18n( $learnt ) ); ?></strong> عبارت، محصولی که مشتری‌ها بیشتر انتخاب کرده‌اند بالاتر نمایش داده می‌شود.</p>
				<p>مترادف‌ها از <em>تنظیمات قالب وودمارت ← جستجو ← مترادف‌ها</em> خوانده می‌شوند. برای عبارت‌های بدون نتیجه، مترادف اضافه کنید یا محصولش را به فروشگاه بیاورید.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px">
					<?php wp_nonce_field( 'bcs_action' ); ?>
					<input type="hidden" name="action" value="bcs_action" />
					<button class="button button-primary" name="do" value="rebuild">بازسازی ایندکس</button>
					<button class="button" name="do" value="clear" onclick="return confirm('گزارش و داده‌های یادگیری پاک شود؟');">پاک کردن گزارش</button>
				</form>
				<?php
				self::table( array_slice( $zero, 0, 50 ), 'جستجوهای بدون نتیجه', 'هنوز جستجوی بدون نتیجه‌ای ثبت نشده.' );
				self::table( array_slice( $top, 0, 50 ), 'پرتکرارترین جستجوها', 'هنوز جستجویی ثبت نشده.' );
				?>
			</div>
			<?php
		}
	}

endif;
