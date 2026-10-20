<?php
/**
 * 白牌設置與授權預留頁面（v2.37.1 / v2.45.20 shell 統一）
 *
 * 渲染 WP 原生「YS CART」頂層選單下的兩個頁面：
 *   - 白牌設置（render_settings）：上傳 LOGO + Footer 文字
 *   - 授權設置（render_license） ：授權狀態 + 授權碼啟用 + 更新查詢
 *
 * v2.45.20（Phase R2.4-G）：套用 YSAdminApp shell — 與其他 YS CART admin 頁面
 * 視覺一致（白底 ysca-card / sidebar + topbar 統一）。User LIVE 1920x1080
 * 確認 v2.39.12 的「白牌頁面保留 WP-native UI」決定造成視覺斷裂：白牌設置 page
 * 完全沒 ysca-page-root shell、跟其他 YS CART 後台 page 視覺落差太大。
 *
 * v2.45.20 行為：
 *   - render_settings() / render_license() 都用 YSAdminApp::open() + close() 包覆
 *   - 既有 wp.media（wp_enqueue_media）modal 完全保留（在 shell 內仍正常 trigger）
 *   - 所有 form 用 Admin REST direct POST（不是 admin-ajax XHR）
 *   - 既有 admin_post hook 已下線，handle_* method 僅保留為舊碼參考
 *
 * 歷史（v2.39.12 → v2.45.20）：
 *   v2.39.12 原本以「白牌頁面是給架站者用」為由保留 WP-native UI；user 在
 *   v2.45.20 LIVE 操作回報視覺斷裂，重新評估後採用統一 shell — 既有 user 反饋
 *   重於假設性的角色區分，且 YSAdminApp shell 本身已有 ysca-wp-chrome-toggle
 *   讓需要切換 WP 原生選單的 user 一鍵 reveal。
 *
 * 兩個 wp_options：
 *   - `ys_ec_whitelabel_logo_url`    string  LOGO URL（empty = 顯示 "YS CART" 文字）
 *   - `ys_ec_whitelabel_footer_text` string  Footer HTML（empty = 不顯示 Footer，
 *                                            "WP as Carrier" 完全消失）
 *
 * 安全：
 *   - cap：manage_options
 *   - nonce action：ys_ec_save_whitelabel
 *   - admin_post action：ys_ec_save_whitelabel
 *   - logo URL 經 esc_url_raw + 限制 http(s) 協定
 *   - footer text 經 wp_kses_post（允許基本 HTML）
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   2.37.1
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Login\YSLoginPage;
use YangSheep\AdminMenu\Login\YSTurnstile;
use YangSheep\AdminMenu\Menu\YSAdminBarFilter;

defined( 'ABSPATH' ) || exit;

class YSWhiteLabelAdmin {

	/** v1.4.0 wp_options key — 是否在左側選單最上方顯示 LOGO 區塊（yes／no，預設 no）。 */
	public const OPTION_SHOW_MENU_LOGO = 'ys_admin_menu_show_menu_logo';

	/** v1.4.0 wp_options key — LOGO 區塊旁的站名文字（空白＝只顯示 LOGO）。 */
	public const OPTION_MENU_LOGO_TEXT = 'ys_admin_menu_menu_logo_text';

	/** v1.4.0：白牌設定頁的分頁。 */
	public const TABS = [ 'brand', 'adminbar', 'login', 'turnstile' ];

	/** wp_options key — LOGO URL */
	public const OPTION_LOGO_URL = 'ys_admin_menu_logo_url';

	/** wp_options key — Footer 文字 */
	public const OPTION_FOOTER_TEXT = 'ys_admin_menu_footer_text';

	/** Nonce action（白牌設置儲存） */
	public const NONCE_ACTION = 'ys_admin_menu_save_whitelabel';

	/** admin_post action（白牌設置儲存） */
	public const ADMIN_POST_ACTION = 'ys_admin_menu_save_whitelabel';

	/** wp_options key — 是否隱藏後台原生頁尾 */
	public const OPTION_HIDE_FOOTER = 'ys_admin_menu_hide_footer';

	/** wp_options key — 是否隱藏 admin bar 左上角 WordPress LOGO */
	public const OPTION_HIDE_WP_LOGO = 'ys_admin_menu_hide_wp_logo';

	/** wp_options key — 整個後台背景色（空字串＝不套用、維持 WP 預設） */
	public const OPTION_ADMIN_BG_COLOR = 'ys_admin_menu_admin_bg_color';

	/** wp_options key — 刪除外掛時是否一併清除所有設定（預設否＝保留設定） */
	public const OPTION_PURGE_ON_UNINSTALL = 'ys_admin_menu_purge_on_uninstall';

	/**
	 * 註冊 hook
	 */
	public static function register(): void {
		// 白牌設置以經典 admin-post PRG 表單儲存（自包含、不依賴 REST）。
		add_action( 'admin_post_' . self::ADMIN_POST_ACTION, [ self::class, 'handle_save_settings' ] );

		// 白牌頁啟用 indigo design system（body.ysca-wl-active 讓 whitelabel.css 生效）。
		add_filter( 'admin_body_class', [ self::class, 'add_wl_body_class' ] );

		// 整個後台背景色（有設定才輸出，無條件套用、不需啟用主題皮膚）。
		if ( '' !== self::get_admin_bg_color() ) {
			add_action( 'admin_head', [ self::class, 'print_admin_bg_style' ] );
		}

		if ( self::get_hide_footer() ) {
			// 完全隱藏後台頁尾（左側謝詞 + 右側版本號 + 容器）。
			add_filter( 'admin_footer_text', '__return_empty_string', 99 );
			add_filter( 'update_footer', '__return_empty_string', 99 );
			add_action( 'admin_head', [ self::class, 'print_hide_footer_style' ] );
		} else {
			// 否則：以 Footer 文字取代 wp-admin 所有頁面右下角頁尾。
			add_filter( 'admin_footer_text', [ self::class, 'filter_admin_footer_text' ] );
		}

		// v1.4.0：左側選單最上方的 LOGO 區塊（開關在 callback 裡讀，預設關）。
		add_action( 'adminmenu', [ self::class, 'print_menu_brand' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_menu_brand_style' ] );

		// v1.4.0：白牌設定頁的分頁、媒體庫按鈕、Turnstile 測試。
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_settings_assets' ] );

		// v1.6.0：登入次數限制「鎖定與紀錄」的動作（各自的 nonce）與全後台的健康提醒。
		YSLoginSecurityAdmin::register();
	}

	/* ─────────────────────────── v1.4.0 選單上方 LOGO 區塊 ─────────────────────────── */

	public static function get_show_menu_logo(): bool {
		return 'yes' === get_option( self::OPTION_SHOW_MENU_LOGO, 'no' );
	}

	public static function get_menu_logo_text(): string {
		return trim( (string) get_option( self::OPTION_MENU_LOGO_TEXT, '' ) );
	}

	/**
	 * adminmenu（#adminmenu 的最後面）：輸出 LOGO 區塊，再用一小段同步腳本把它移到第一個選單項之前。
	 *
	 * 區塊是一個連到控制台的連結：文字顏色跟著 WordPress 的後台配色或原生選單樣式走。
	 * 沒有 LOGO 也沒有站名文字時，顯示網站標題。
	 */
	public static function print_menu_brand(): void {
		if ( ! self::get_show_menu_logo() ) {
			return;
		}

		$logo = self::get_logo_url();
		$text = self::get_menu_logo_text();
		if ( '' === $logo && '' === $text ) {
			$text = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		echo '<li id="ys-am-menu-brand" class="ys-am-menu-brand">';
		printf( '<a class="ys-am-menu-brand__link" href="%s">', esc_url( admin_url() ) );
		if ( '' !== $logo ) {
			// 有站名文字時 LOGO 只是裝飾；沒有文字時 LOGO 就是這個連結的名稱。
			printf(
				'<img class="ys-am-menu-brand__logo" src="%s" alt="%s" decoding="async">',
				esc_url( $logo ),
				esc_attr( '' !== $text ? '' : $site )
			);
		} else {
			// 沒有 LOGO：收合成圖示列時顯示第一個字。
			printf(
				'<span class="ys-am-menu-brand__mark" aria-hidden="true">%s</span>',
				esc_html( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1 ) : substr( $text, 0, 1 ) )
			);
		}
		if ( '' !== $text ) {
			printf( '<span class="ys-am-menu-brand__text">%s</span>', esc_html( $text ) );
		}
		echo '</a></li>';

		wp_print_inline_script_tag(
			"(function(){var b=document.getElementById('ys-am-menu-brand'),m=b&&b.parentNode;if(m&&m.firstElementChild!==b){m.insertBefore(b,m.firstElementChild);}})();"
		);
	}

	/**
	 * LOGO 區塊的樣式（開關有開才載入）。
	 *
	 * @param string $hook
	 */
	public static function enqueue_menu_brand_style( $hook = '' ): void {
		if ( ! self::get_show_menu_logo() ) {
			return;
		}
		wp_enqueue_style(
			'ys-admin-menu-menu-brand',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/ys-admin-menu-menu-brand.css',
			[ 'admin-menu' ],
			YS_ADMIN_MENU_VERSION
		);
	}

	/**
	 * 白牌設定頁的腳本：分頁、媒體庫、WordPress 標誌同步、Turnstile 測試。
	 *
	 * @param string $hook
	 */
	public static function enqueue_settings_assets( $hook = '' ): void {
		if ( false === strpos( (string) $hook, YSMenuPage::SLUG_SETTINGS ) ) {
			return;
		}

		wp_enqueue_script(
			'ys-admin-menu-whitelabel',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/js/ys-admin-menu-whitelabel.js',
			[],
			YS_ADMIN_MENU_VERSION,
			true
		);
		wp_localize_script(
			'ys-admin-menu-whitelabel',
			'ysAmWhiteLabel',
			[
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'testAction' => YSTurnstile::AJAX_TEST_ACTION,
				'testNonce'  => wp_create_nonce( YSTurnstile::AJAX_TEST_ACTION ),
				'i18n'       => [
					'mediaTitle'   => __( '選擇圖片', 'ys-admin-menu' ),
					'mediaButton'  => __( '使用這張圖片', 'ys-admin-menu' ),
					'testing'      => __( '驗證中…', 'ys-admin-menu' ),
					'needToken'    => __( '請先完成上方的 Turnstile 驗證。', 'ys-admin-menu' ),
					'requestError' => __( '測試請求失敗，請重新整理頁面後再試。', 'ys-admin-menu' ),
					'widgetError'  => __( 'Turnstile widget 載入失敗：多半是 Site Key 不正確，或這個網域沒有加進 Cloudflare 這個 widget 的網域清單。', 'ys-admin-menu' ),
					// v1.6.0：進階：訪客 IP。
					'ipPreview'    => __( '存了之後，你這次的請求會是：', 'ys-admin-menu' ),
					'ipTrusted'    => __( '已加進清單，記得儲存', 'ys-admin-menu' ),
				],
			]
		);

		// 已存好 Site Key 與 Secret Key 才載入 Cloudflare 的腳本（給「測試設定」用）。
		$view = YSTurnstile::settings_view();
		if ( ! empty( $view['can_test'] ) ) {
			YSTurnstile::enqueue_script();
		}
	}

	/**
	 * 以白牌 Footer 文字取代 wp-admin 頁尾（未設定則保留原值）。
	 *
	 * @param string $text 預設頁尾文字。
	 * @return string
	 */
	public static function filter_admin_footer_text( $text ) {
		$footer = trim( self::get_footer_text() );
		return ( '' !== $footer ) ? wp_kses_post( $footer ) : $text;
	}

	/**
	 * 隱藏後台頁尾容器（與清空文字搭配，連空白列都不留）。
	 */
	public static function print_hide_footer_style(): void {
		echo '<style id="ys-admin-menu-hide-footer">#wpfooter{display:none !important;}</style>';
	}

	/**
	 * 是否隱藏後台原生頁尾。
	 */
	public static function get_hide_footer(): bool {
		return 'yes' === get_option( self::OPTION_HIDE_FOOTER, 'no' );
	}

	/**
	 * 刪除外掛時是否一併清除所有設定（預設否＝保留）。
	 */
	public static function get_purge_on_uninstall(): bool {
		return 'yes' === get_option( self::OPTION_PURGE_ON_UNINSTALL, 'no' );
	}

	/**
	 * 在白牌設定頁的 body 加上 ysca-wl-active，啟用 indigo design system。
	 *
	 * @param string $classes 現有 body class 字串。
	 * @return string
	 */
	public static function add_wl_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, YSMenuPage::SLUG_SETTINGS ) ) {
			$classes .= ' ysca-wl-active';
		}
		return $classes;
	}

	/**
	 * 是否隱藏 admin bar 左上角 WordPress LOGO。
	 */
	public static function get_hide_wp_logo(): bool {
		return 'yes' === get_option( self::OPTION_HIDE_WP_LOGO, 'no' );
	}

	/**
	 * 取得整個後台背景色（已 sanitize 的 hex；空字串＝未設定）。
	 */
	public static function get_admin_bg_color(): string {
		$color = sanitize_hex_color( (string) get_option( self::OPTION_ADMIN_BG_COLOR, '' ) );
		return is_string( $color ) ? $color : '';
	}

	/**
	 * 從 admin bar 移除 WP LOGO 節點（含其 about / 官網 / 文件等子項）。
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 */
	public static function remove_wp_logo_node( $wp_admin_bar ): void {
		if ( is_object( $wp_admin_bar ) && method_exists( $wp_admin_bar, 'remove_node' ) ) {
			$wp_admin_bar->remove_node( 'wp-logo' );
		}
	}

	/**
	 * 輸出整個後台背景色 CSS（套用於 body.wp-admin，無條件、不需主題皮膚）。
	 */
	public static function print_admin_bg_style(): void {
		$color = self::get_admin_bg_color();
		if ( '' === $color ) {
			return;
		}
		echo '<style id="ys-admin-menu-admin-bg">body.wp-admin{background-color:' . esc_attr( $color ) . ' !important;}</style>';
	}

	/**
	 * 取得目前 LOGO URL（給其他模組讀取，例：YSAdminApp 渲染 brand）
	 */
	public static function get_logo_url(): string {
		return trim( (string) get_option( self::OPTION_LOGO_URL, '' ) );
	}

	/**
	 * 取得目前 Footer 文字（給其他模組讀取，例：YSAdminApp 渲染 sidebar footer）
	 */
	public static function get_footer_text(): string {
		return (string) get_option( self::OPTION_FOOTER_TEXT, '' );
	}

	/**
	 * 渲染「白牌設置」頁
	 *
	 * v2.45.20：套用 YSAdminApp shell（sidebar + topbar）；wp_enqueue_media 仍保留，
	 * wp.media modal 在 shell 內可正常運作（modal 是 body-level overlay、不受
	 * shell wrapper 影響）。
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '權限不足。' );
		}

		// 為 logo upload 載入 WP media library（v2.45.20：在 shell 內 modal 仍正常）
		wp_enqueue_media();

		// 後台背景色用 WP 內建 color picker（屬 WP core，不違反白牌頁純 native UI 原則）。
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		$logo_url     = self::get_logo_url();
		$footer_text  = self::get_footer_text();
		$hide_footer  = self::get_hide_footer();
		$hide_wp_logo = self::get_hide_wp_logo();
		$admin_bg_color = self::get_admin_bg_color();
		$purge_on_uninstall = self::get_purge_on_uninstall();
		$notice      = '';
		if ( ! empty( $_GET['ys_ec_wl_saved'] ) ) {
			$notice = '白牌設定已儲存。';
		}

		// v1.4.0：選單上方 LOGO 區塊、Admin Bar、登入頁、Turnstile。
		$show_menu_logo = self::get_show_menu_logo();
		$menu_logo_text = self::get_menu_logo_text();
		$adminbar       = YSAdminBarFilter::settings_view();
		$login_page     = YSLoginPage::settings_view();
		$turnstile      = YSTurnstile::settings_view();
		// v1.6.0：「登入防護」分頁的「登入入口」與「進階：訪客 IP」。
		$entrance_view  = YSLoginSecurityAdmin::entrance_view();
		$ip_view        = YSLoginSecurityAdmin::ip_view();
		// v1.6.0：登入次數限制卡片、鎖定與紀錄（只讀 lg_ip、lg_page；動作做完的代碼過白名單）。
		$guard_view     = YSLoginSecurityAdmin::guard_view();
		$records_view   = YSLoginSecurityAdmin::records_view( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只決定篩選與頁數
		$lg_done        = isset( $_GET['ys_am_lg_done'] ) && is_string( $_GET['ys_am_lg_done'] ) && in_array( $_GET['ys_am_lg_done'], YSLoginSecurityAdmin::DONE_CODES, true ) ? (string) $_GET['ys_am_lg_done'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- 白名單比對
		$field_errors   = self::read_field_errors();
		$active_tab     = self::read_tab( $_GET['tab'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只決定先顯示哪個分頁

		// v2.45.29: 完全純 WP-native UI（user 明確要求：白牌設置是給架站者用、
		// 應該完全使用 WP 原生介面、不共用 YS CART 樣式、避免污染 native pages）。
		// 不開 YSAdminApp shell、不套 .ysca-page-root、不加 .ysca-card class。
		// is_ys_cart_admin_page() 排除這 3 page、YS CSS 不會載入、無樣式 cascade 風險。
		echo '<div class="wrap">';
		echo YSMenuPage::pagehead_html( 'admin-customizer', '白牌設定', 'LOGO、頁尾文字、Admin Bar、自訂登入頁與 Turnstile 登入防護，打造專屬後台品牌。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pagehead_html 內已逐欄 escape

		$template = YS_ADMIN_MENU_PLUGIN_DIR . 'templates/admin/whitelabel-settings.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<div class="card"><p>白牌設定樣板不存在。</p></div>';
		}

		echo '</div>';
	}

	/**
	 * 處理 POST — 儲存白牌設置
	 */
	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '權限不足。' );
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( '安全驗證失敗。' );
		}

		// LOGO URL — 必須為 http/https URL；空字串表示移除（恢復文字 "YS CART"）
		$raw_logo = trim( (string) ( $_POST['logo_url'] ?? '' ) );
		$logo_url = '';
		if ( '' !== $raw_logo ) {
			$candidate = esc_url_raw( $raw_logo, [ 'http', 'https' ] );
			if ( '' !== $candidate ) {
				$logo_url = $candidate;
			}
		}
		update_option( self::OPTION_LOGO_URL, $logo_url, true );

		// Footer 文字 — 允許基本 HTML（顧及 white-label 客戶可能要放連結 / 版權符號等）
		$raw_footer = (string) wp_unslash( $_POST['footer_text'] ?? '' );
		$footer_text = wp_kses_post( $raw_footer );
		update_option( self::OPTION_FOOTER_TEXT, $footer_text, true );

		// 隱藏後台原生頁尾開關。
		$hide_footer = isset( $_POST['hide_footer'] ) ? 'yes' : 'no';
		update_option( self::OPTION_HIDE_FOOTER, $hide_footer, true );

		// 隱藏 admin bar 左上角 WP LOGO 開關。
		$hide_wp_logo = isset( $_POST['hide_wp_logo'] ) ? 'yes' : 'no';
		update_option( self::OPTION_HIDE_WP_LOGO, $hide_wp_logo, true );

		// 整個後台背景色（空字串＝清除、恢復 WP 預設）。
		$raw_bg    = (string) ( $_POST['admin_bg_color'] ?? '' );
		$bg_color  = '' !== trim( $raw_bg ) ? sanitize_hex_color( trim( $raw_bg ) ) : '';
		update_option( self::OPTION_ADMIN_BG_COLOR, is_string( $bg_color ) ? $bg_color : '', true );

		// 刪除外掛時是否一併清除所有設定（預設否＝保留）。
		$purge = isset( $_POST['purge_on_uninstall'] ) ? 'yes' : 'no';
		update_option( self::OPTION_PURGE_ON_UNINSTALL, $purge, true );

		// v1.4.0：以下各模組只在這裡（已驗過 manage_options 與 nonce）被呼叫；各自再檢查一次權限。
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 各模組逐欄消毒

		// 左側選單最上方的 LOGO 區塊。
		update_option( self::OPTION_SHOW_MENU_LOGO, ! empty( $post['show_menu_logo'] ) ? 'yes' : 'no', true );
		$menu_logo_text = sanitize_text_field( is_string( $post['menu_logo_text'] ?? null ) ? $post['menu_logo_text'] : '' );
		update_option( self::OPTION_MENU_LOGO_TEXT, function_exists( 'mb_substr' ) ? mb_substr( $menu_logo_text, 0, 60 ) : substr( $menu_logo_text, 0, 60 ), true );

		YSAdminBarFilter::save_settings( $post );
		YSLoginPage::save_settings( $post );
		$errors = YSTurnstile::save_settings( $post );
		// v1.6.0：登入入口的兩個開關。
		YSLoginSecurityAdmin::save_entrance_settings( $post );
		// v1.6.0：訪客 IP 偵測。不合法的欄位不存、保留原本的值，錯誤代碼一起帶回設定頁。
		$errors = array_merge( $errors, YSLoginSecurityAdmin::save_ip_settings( $post ) );
		// v1.6.0：登入次數限制（卡片有送出才存；觀察期只在關到開時開始；總開關打開時建表）。
		$errors = array_merge( $errors, YSLoginSecurityAdmin::save_guard_settings( $post ) );

		$args = [
			'page'           => YSMenuPage::SLUG_SETTINGS,
			'ys_ec_wl_saved' => '1',
			'tab'            => self::read_tab( $post['ys_am_tab'] ?? '' ),
		];
		if ( ! empty( $errors ) ) {
			$args['ys_am_wl_err'] = implode( ',', $errors );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 白牌設定頁的分頁代稱（白名單；不認得就回第一個分頁）。
	 *
	 * @param mixed $value
	 */
	private static function read_tab( $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, self::TABS, true ) ? $value : self::TABS[0];
	}

	/**
	 * 儲存後被擋下來的欄位（只認得已知的代碼）。
	 *
	 * @return string[]
	 */
	private static function read_field_errors(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只決定要不要顯示提示
		$raw   = isset( $_GET['ys_am_wl_err'] ) && is_string( $_GET['ys_am_wl_err'] ) ? sanitize_text_field( wp_unslash( $_GET['ys_am_wl_err'] ) ) : '';
		$codes = explode( ',', $raw );
		// v1.6.0：訪客 IP 的代碼（含行號）另外過白名單。
		return array_values( array_merge( array_intersect( [ 'site_key_format', 'secret_format' ], $codes ), YSLoginSecurityAdmin::filter_codes( $codes ) ) );
	}
}
