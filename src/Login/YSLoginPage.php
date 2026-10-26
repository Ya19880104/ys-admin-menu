<?php
/**
 * 自訂 wp-login.php 登入頁（v1.4.0）：YS CRM 的品牌版面。v1.5.1 改成 YS CRM 線上版登入頁的樣子：
 * 接近黑的深藍底、白色大圓角登入卡片、膠囊形欄位與按鈕。
 *
 * 只改外觀與文字：
 *   - 用 login_enqueue_scripts 掛獨立的 CSS（assets/css/ys-admin-menu-login.css），不改 wp-login.php。
 *   - 登入表單的欄位 id（#loginform、#user_login、#user_pass、#wp-submit…）、label、送出流程、
 *     錯誤訊息、密碼處理全部維持 WordPress 原樣；其他外掛掛在表單上的欄位照常出現。
 *   - 品牌區（左上 LOGO 或字標＋品牌名稱＋標語）用 login_header 輸出，卡片標題用 login_message 輸出，
 *     頁尾文字用 login_footer 輸出；「返回首頁」用 login_site_html_link。
 *   - v1.5.2：卡片上方不放品牌名稱（左側大標、手機上卡片上方那一行已經有），卡片從標題開始。
 *   - v1.6.0：主色、背景（預設深藍／淺色／自訂顏色）、左上字標，見 YSLoginStyle。都沒設定時外觀跟 1.5.2 一樣。
 *   - 忘記密碼、註冊、設定新密碼等畫面沿用同一套配色。
 *   - WordPress 的語言切換預設不顯示（login_display_language_dropdown），白牌設定可以打開。
 *   - 登入網址被其他外掛改掉（例如 WPS Hide Login）時照樣套用：它們在新網址載入的還是 wp-login.php。
 *
 * 總開關 ys_admin_menu_login_enabled（預設關）。沒開時不載入任何東西，登入頁維持 WordPress 預設。
 *
 * @package YangSheep\AdminMenu\Login
 * @since   1.4.0
 */

namespace YangSheep\AdminMenu\Login;

use YangSheep\AdminMenu\Access\YSAccess;
use YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin;

defined( 'ABSPATH' ) || exit;

final class YSLoginPage {

	/** wp_options key — 總開關（yes／no，預設 no）。 */
	public const OPTION_ENABLED = 'ys_admin_menu_login_enabled';

	/** wp_options key — 品牌名稱（左側大標；手機上在卡片上方那一行；空白＝網站標題）。 */
	public const OPTION_BRAND = 'ys_admin_menu_login_brand';

	/** wp_options key — 卡片標題（空白＝「登入」）。 */
	public const OPTION_TITLE = 'ys_admin_menu_login_title';

	/** wp_options key — 卡片副標（沒存過＝「請輸入您的帳號密碼」；存成空白＝不顯示）。 */
	public const OPTION_SUBTITLE = 'ys_admin_menu_login_subtitle';

	/** wp_options key — 標語（左側大標下面那一行；空白＝不顯示）。 */
	public const OPTION_TAGLINE = 'ys_admin_menu_login_tagline';

	/** wp_options key — 登入頁 LOGO（空白＝沿用白牌 LOGO）。 */
	public const OPTION_LOGO_URL = 'ys_admin_menu_login_logo_url';

	/** wp_options key — 背景圖（空白＝深藍漸層）。 */
	public const OPTION_BG_URL = 'ys_admin_menu_login_bg_url';

	/** wp_options key — 頁尾文字（允許 a／br／strong／em／span；空白＝不顯示）。 */
	public const OPTION_FOOTER = 'ys_admin_menu_login_footer_text';

	/** wp_options key — 是否顯示「返回首頁」（yes／no，預設 yes＝與 WordPress 預設一樣有這個連結）。 */
	public const OPTION_BACK_LINK = 'ys_admin_menu_login_back_link';

	/** wp_options key — 是否顯示 WordPress 的語言切換（yes／no，預設 no＝不顯示；v1.5.1）。 */
	public const OPTION_LANGUAGE_SWITCHER = 'ys_admin_menu_login_language_switcher';

	private const TEXT_MAX   = 120;
	private const FOOTER_MAX = 2000;

	/**
	 * 註冊 hook：只在 wp-login.php（login_init）才讀設定，其他請求不受影響。
	 */
	public static function register(): void {
		add_action( 'login_init', [ self::class, 'boot' ], 1 );
	}

	/**
	 * wp-login.php 開始時：總開關有開才掛外觀相關的 hook。
	 */
	public static function boot(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'login_enqueue_scripts', [ self::class, 'enqueue_style' ] );
		add_filter( 'login_body_class', [ self::class, 'body_class' ], 10, 2 );
		add_filter( 'login_headerurl', [ self::class, 'header_url' ] );
		add_filter( 'login_headertext', [ self::class, 'header_text' ] );
		add_filter( 'login_title', [ self::class, 'document_title' ], 10, 2 );
		add_action( 'login_header', [ self::class, 'render_brand_panel' ] );
		// 最後才跑、把卡片標題放在最前面（其他外掛的 login_message 接在後面）。
		add_filter( 'login_message', [ self::class, 'prepend_card_head' ], 9999 );
		add_filter( 'login_site_html_link', [ self::class, 'back_link' ] );
		add_filter( 'login_display_language_dropdown', [ self::class, 'language_dropdown' ] );
		// 在 wp_print_footer_scripts（priority 20）之前輸出頁尾。
		add_action( 'login_footer', [ self::class, 'render_footer' ], 5 );
	}

	/* ─────────────────────────── 設定讀取 ─────────────────────────── */

	public static function is_enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	public static function default_title(): string {
		return __( '登入', 'ys-admin-menu' );
	}

	public static function default_subtitle(): string {
		return __( '請輸入您的帳號密碼', 'ys-admin-menu' );
	}

	/** 已存的品牌名稱（可能是空白）。 */
	public static function get_brand_setting(): string {
		return trim( (string) get_option( self::OPTION_BRAND, '' ) );
	}

	/** 實際顯示的品牌名稱：空白時用網站標題。 */
	public static function get_brand(): string {
		$brand = self::get_brand_setting();
		if ( '' !== $brand ) {
			return $brand;
		}
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** 已存的標題（可能是空白）。 */
	public static function get_title_setting(): string {
		return trim( (string) get_option( self::OPTION_TITLE, '' ) );
	}

	public static function get_title(): string {
		$title = self::get_title_setting();
		return '' !== $title ? $title : self::default_title();
	}

	/** 副標：沒存過用預設字；存成空白就不顯示。 */
	public static function get_subtitle(): string {
		$value = get_option( self::OPTION_SUBTITLE, false );
		return false === $value ? self::default_subtitle() : trim( (string) $value );
	}

	public static function get_tagline(): string {
		return trim( (string) get_option( self::OPTION_TAGLINE, '' ) );
	}

	/** 已存的登入頁專用 LOGO（可能是空白）。 */
	public static function get_logo_setting(): string {
		return self::clean_url( get_option( self::OPTION_LOGO_URL, '' ) );
	}

	/** 實際顯示的 LOGO：沒設登入頁專用的就沿用白牌 LOGO。 */
	public static function get_logo_url(): string {
		$own = self::get_logo_setting();
		return '' !== $own ? $own : self::clean_url( YSWhiteLabelAdmin::get_logo_url() );
	}

	public static function get_bg_url(): string {
		return self::clean_url( get_option( self::OPTION_BG_URL, '' ) );
	}

	public static function get_footer_html(): string {
		return self::sanitize_footer( (string) get_option( self::OPTION_FOOTER, '' ) );
	}

	public static function show_back_link(): bool {
		return 'no' !== get_option( self::OPTION_BACK_LINK, 'yes' );
	}

	/** 語言切換：沒存過或存 no 都不顯示（YS CRM 的登入頁沒有這一項）。 */
	public static function show_language_switcher(): bool {
		return 'yes' === get_option( self::OPTION_LANGUAGE_SWITCHER, 'no' );
	}

	/**
	 * 左上角的字標：有 LOGO 時放 LOGO（回傳空字串）；v1.6.0 起有自己填的字標就放它（和品牌名稱一樣也照放）；
	 * 都沒有時放網站標題（字距拉開），和品牌名稱（左側大標）一樣時不重複，回傳空字串。
	 */
	public static function get_wordmark(): string {
		if ( '' !== self::get_logo_url() ) {
			return '';
		}
		$own = YSLoginStyle::get_wordmark_setting();
		if ( '' !== $own ) {
			return $own;
		}
		$site = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return ( '' === $site || $site === self::get_brand() ) ? '' : $site;
	}

	/* ─────────────────────────── 消毒 ─────────────────────────── */

	/**
	 * 頁尾允許的 HTML：a（href／title／target／rel）、br、strong、em、span（class）。
	 * 不允許 style 與任何 on* 事件屬性；href 的協定由 wp_kses 限制（擋 javascript: 等）。
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function footer_allowed_html(): array {
		return [
			'a'      => [
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			],
			'br'     => [],
			'strong' => [],
			'em'     => [],
			'span'   => [
				'class' => true,
			],
		];
	}

	public static function sanitize_footer( string $html ): string {
		if ( function_exists( 'mb_substr' ) ) {
			$html = mb_substr( $html, 0, self::FOOTER_MAX );
		}
		return trim( force_balance_tags( wp_kses( $html, self::footer_allowed_html() ) ) );
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_text( $value ): string {
		$value = sanitize_text_field( is_string( $value ) ? $value : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::TEXT_MAX ) : substr( $value, 0, self::TEXT_MAX );
	}

	/**
	 * 只接受 http／https（esc_url_raw 會把 javascript:、data: 等協定變成空字串）。
	 *
	 * @param mixed $value
	 */
	public static function clean_url( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		return (string) esc_url_raw( $value, [ 'http', 'https' ] );
	}

	/* ─────────────────────────── 輸出 ─────────────────────────── */

	public static function enqueue_style(): void {
		wp_enqueue_style(
			'ys-admin-menu-login',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/ys-admin-menu-login.css',
			[ 'login' ],
			YS_ADMIN_MENU_VERSION
		);
		// v1.6.0：主色、自訂背景色的變數。都是預設時不輸出（登入頁跟 1.5.2 一模一樣）。
		$css = YSLoginStyle::inline_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'ys-admin-menu-login', $css );
		}
	}

	/**
	 * @param string[] $classes
	 * @param string   $action
	 * @return string[]
	 */
	public static function body_class( $classes, $action = '' ) {
		$classes   = is_array( $classes ) ? $classes : [];
		$classes[] = 'ys-am-login';
		if ( '' !== self::get_bg_url() ) {
			$classes[] = 'ys-am-login--has-bg';
		}
		if ( ! self::show_back_link() ) {
			$classes[] = 'ys-am-login--no-back';
		}
		if ( '' !== self::get_footer_html() ) {
			$classes[] = 'ys-am-login--has-footer';
		}
		// v1.6.0：背景是淺色或自訂顏色時才多一個 class。
		foreach ( YSLoginStyle::body_classes() as $class ) {
			$classes[] = $class;
		}
		return $classes;
	}

	/** 隱藏的 WordPress 標誌連結改指向本站首頁。 */
	public static function header_url(): string {
		return home_url( '/' );
	}

	/** 隱藏的 WordPress 標誌連結文字（核心會直接輸出，所以這裡先 escape）。 */
	public static function header_text(): string {
		return esc_html( self::get_brand() );
	}

	/**
	 * 瀏覽器分頁標題拿掉「— WordPress」，其餘（含復原模式前綴）維持核心原樣。
	 *
	 * @param string $login_title
	 * @param string $title
	 * @return string
	 */
	public static function document_title( $login_title, $title = '' ) {
		return str_replace( ' &#8212; WordPress', '', (string) $login_title );
	}

	/**
	 * login_header：背景層與左側品牌區（左上 LOGO 或字標、品牌名稱、標語）。
	 */
	public static function render_brand_panel(): void {
		$bg = self::get_bg_url();
		echo '<div class="ys-am-login-bg" aria-hidden="true">';
		if ( '' !== $bg ) {
			printf( '<img class="ys-am-login-bg__img" src="%s" alt="" decoding="async">', esc_url( $bg ) );
		}
		echo '</div>';

		if ( self::is_interim() ) {
			return;
		}

		$logo     = self::get_logo_url();
		$wordmark = self::get_wordmark();
		$tagline  = self::get_tagline();

		echo '<div class="ys-am-login-brand">';
		if ( '' !== $logo ) {
			// 品牌名稱就在旁邊顯示，LOGO 當裝飾（alt 留空，讀屏不重複唸）。
			printf(
				'<div class="ys-am-login-brand__top"><img class="ys-am-login-brand__logo" src="%s" alt="" decoding="async"></div>',
				esc_url( $logo )
			);
		} elseif ( '' !== $wordmark ) {
			// 沒有 LOGO：左上角放網站標題當字標（只在寬螢幕顯示）。
			printf(
				'<div class="ys-am-login-brand__top ys-am-login-brand__top--text"><p class="ys-am-login-brand__wordmark">%s</p></div>',
				esc_html( $wordmark )
			);
		}
		echo '<div class="ys-am-login-brand__hero">';
		printf( '<p class="ys-am-login-brand__title">%s</p>', esc_html( self::get_brand() ) );
		if ( '' !== $tagline ) {
			printf( '<p class="ys-am-login-brand__tagline">%s</p>', esc_html( $tagline ) );
		}
		echo '</div></div>';
	}

	/**
	 * login_message：卡片的標題與副標（副標只在登入畫面顯示）。
	 *
	 * @param string $message 核心或其他外掛的訊息。
	 * @return string
	 */
	public static function prepend_card_head( $message ) {
		$message = is_string( $message ) ? $message : '';
		$action  = self::current_action();

		// 管理員電子郵件確認畫面在表單裡有自己的標題。
		if ( 'confirm_admin_email' === $action ) {
			return $message;
		}

		$titles = [
			'lostpassword'  => __( '忘記密碼', 'ys-admin-menu' ),
			'register'      => __( '註冊帳號', 'ys-admin-menu' ),
			'resetpass'     => __( '設定新密碼', 'ys-admin-menu' ),
			'confirmaction' => __( '確認', 'ys-admin-menu' ),
		];
		$title    = $titles[ $action ] ?? self::get_title();
		$subtitle = 'login' === $action ? self::get_subtitle() : '';

		$head  = '<div class="ys-am-login-head">';
		$head .= '<p class="ys-am-login-head__title">' . esc_html( $title ) . '</p>';
		if ( '' !== $subtitle ) {
			$head .= '<p class="ys-am-login-head__sub">' . esc_html( $subtitle ) . '</p>';
		}
		$head .= '</div>';

		return $head . $message;
	}

	/**
	 * login_site_html_link：「← 返回首頁」，或關掉時整個不輸出。
	 *
	 * @param string $html
	 * @return string
	 */
	public static function back_link( $html ) {
		if ( ! self::show_back_link() ) {
			return '';
		}
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( home_url( '/' ) ),
			esc_html__( '← 返回首頁', 'ys-admin-menu' )
		);
	}

	/**
	 * login_display_language_dropdown：白牌設定沒打開就不顯示語言切換；打開時照 WordPress（與其他外掛）原本的判斷。
	 *
	 * @param bool $display
	 * @return bool
	 */
	public static function language_dropdown( $display ) {
		return self::show_language_switcher() ? (bool) $display : false;
	}

	/**
	 * login_footer：頁尾文字（空白就不輸出）。
	 */
	public static function render_footer(): void {
		if ( self::is_interim() ) {
			return;
		}
		$html = self::get_footer_html();
		if ( '' === $html ) {
			return;
		}
		echo '<footer class="ys-am-login-footer"><p>' . wp_kses( $html, self::footer_allowed_html() ) . '</p></footer>';
	}

	/**
	 * wp-login.php 的 $action（全域變數）；別名換成同一個畫面。
	 */
	private static function current_action(): string {
		$action = isset( $GLOBALS['action'] ) && is_string( $GLOBALS['action'] ) ? sanitize_key( $GLOBALS['action'] ) : 'login';
		$alias  = [
			'retrievepassword' => 'lostpassword',
			'rp'               => 'resetpass',
		];
		return $alias[ $action ] ?? ( '' !== $action ? $action : 'login' );
	}

	/**
	 * 後台登入逾時跳出的小視窗（interim-login）：只留卡片，不放品牌區與頁尾。
	 */
	private static function is_interim(): bool {
		return ! empty( $GLOBALS['interim_login'] );
	}

	/* ─────────────────────────── 設定頁 ─────────────────────────── */

	/**
	 * 儲存設定（由 YSWhiteLabelAdmin::handle_save_settings 在驗過權限與 nonce 之後呼叫）。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 */
	public static function save_settings( array $post ): void {
		if ( ! YSAccess::can_manage() ) {
			return;
		}

		update_option( self::OPTION_ENABLED, ! empty( $post['login_enabled'] ) ? 'yes' : 'no', true );

		$texts = [
			self::OPTION_BRAND    => 'login_brand',
			self::OPTION_TITLE    => 'login_title',
			self::OPTION_SUBTITLE => 'login_subtitle',
			self::OPTION_TAGLINE  => 'login_tagline',
		];
		foreach ( $texts as $option => $field ) {
			update_option( $option, self::sanitize_text( $post[ $field ] ?? '' ), false );
		}

		update_option( self::OPTION_LOGO_URL, self::clean_url( $post['login_logo_url'] ?? '' ), false );
		update_option( self::OPTION_BG_URL, self::clean_url( $post['login_bg_url'] ?? '' ), false );

		$footer = isset( $post['login_footer_text'] ) && is_string( $post['login_footer_text'] ) ? $post['login_footer_text'] : '';
		update_option( self::OPTION_FOOTER, self::sanitize_footer( $footer ), false );

		update_option( self::OPTION_BACK_LINK, ! empty( $post['login_back_link'] ) ? 'yes' : 'no', false );
		update_option( self::OPTION_LANGUAGE_SWITCHER, ! empty( $post['login_language_switcher'] ) ? 'yes' : 'no', false );

		// v1.6.0：主色、背景、左上字標。
		YSLoginStyle::save_settings( $post );
	}

	/**
	 * 設定頁需要的值。
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_view(): array {
		return [
			'enabled'           => self::is_enabled(),
			'brand'             => self::get_brand_setting(),
			'brand_default'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'title'             => self::get_title_setting(),
			'title_default'     => self::default_title(),
			'subtitle'          => self::get_subtitle(),
			'subtitle_default'  => self::default_subtitle(),
			'tagline'           => self::get_tagline(),
			'logo_url'          => self::get_logo_setting(),
			'bg_url'            => self::get_bg_url(),
			'footer'            => self::get_footer_html(),
			'back_link'         => self::show_back_link(),
			'language_switcher' => self::show_language_switcher(),
			'login_url'         => wp_login_url(),
			'style'             => YSLoginStyle::settings_view(),
		];
	}
}
