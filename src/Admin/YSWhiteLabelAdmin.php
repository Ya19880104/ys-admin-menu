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

defined( 'ABSPATH' ) || exit;

class YSWhiteLabelAdmin {

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

	/**
	 * 註冊 hook
	 */
	public static function register(): void {
		// 白牌設置以經典 admin-post PRG 表單儲存（自包含、不依賴 REST）。
		add_action( 'admin_post_' . self::ADMIN_POST_ACTION, [ self::class, 'handle_save_settings' ] );

		// 隱藏 admin bar 左上角的 WordPress LOGO（白牌：前後台 admin bar 都移除）。
		if ( self::get_hide_wp_logo() ) {
			add_action( 'admin_bar_menu', [ self::class, 'remove_wp_logo_node' ], 999 );
		}

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
		$notice      = '';
		if ( ! empty( $_GET['ys_ec_wl_saved'] ) ) {
			$notice = '白牌設置已儲存。';
		}

		// v2.45.29: 完全純 WP-native UI（user 明確要求：白牌設置是給架站者用、
		// 應該完全使用 WP 原生介面、不共用 YS CART 樣式、避免污染 native pages）。
		// 不開 YSAdminApp shell、不套 .ysca-page-root、不加 .ysca-card class。
		// is_ys_cart_admin_page() 排除這 3 page、YS CSS 不會載入、無樣式 cascade 風險。
		echo '<div class="wrap">';
		echo '<h1>白牌設置</h1>';

		$template = YS_ADMIN_MENU_PLUGIN_DIR . 'templates/admin/whitelabel-settings.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<div class="card"><p>白牌設置樣板不存在。</p></div>';
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

		wp_safe_redirect(
			add_query_arg(
				[
					'page'             => YSMenuPage::SLUG_SETTINGS,
					'ys_ec_wl_saved'   => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
