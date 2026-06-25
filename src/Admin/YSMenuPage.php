<?php
/**
 * 本外掛後台選單與設定頁註冊。
 *
 * 結構（WP 原生左側 sidebar 頂層）：
 *   ┌─ YS 選單管理（add_menu_page，icon dashicons-menu-alt3）
 *   │  ├─ 選單與權限（= 頂層 slug，YSPermissionAdmin：全部選單 / 子選單 / 使用者覆寫）
 *   │  ├─ 原生選單樣式（YSAdminThemeAdmin）
 *   │  └─ 白牌設定（YSWhiteLabelAdmin）
 *
 * 若使用者已上傳白牌 LOGO（option `ys_admin_menu_logo_url`），
 * 頂層選單 icon 會以該 LOGO URL 取代 dashicon。
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   1.0.0
 */

namespace YangSheep\AdminMenu\Admin;

defined( 'ABSPATH' ) || exit;

class YSMenuPage {

	/** 頂層選單 slug（同時是「選單與權限」頁 slug）。 */
	public const PARENT_SLUG = 'ys-admin-menu';

	/** 「選單與權限」頁 slug（= 頂層）。 */
	public const SLUG_PERMISSIONS = 'ys-admin-menu';

	/** 「原生選單樣式」頁 slug。 */
	public const SLUG_ADMIN_THEME = 'ys-admin-menu-theme';

	/** 「白牌設定」頁 slug。 */
	public const SLUG_SETTINGS = 'ys-admin-menu-whitelabel';

	/** 頂層選單位置。 */
	public const MENU_POSITION = 80;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_head', [ self::class, 'print_menu_icon_guard' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_shared' ] );
	}

	/**
	 * 註冊頂層選單與子頁。
	 */
	public static function add_menu(): void {
		$cap = 'manage_options';

		// 若 admin 上傳白牌 LOGO，icon 改用該 LOGO URL；否則用 dashicon。
		$logo_url = trim( (string) get_option( 'ys_admin_menu_logo_url', '' ) );
		$icon     = ( '' !== $logo_url ) ? esc_url( $logo_url ) : 'dashicons-menu-alt3';

		add_menu_page(
			__( 'YS 選單管理', 'ys-admin-menu' ),
			__( 'YS 選單管理', 'ys-admin-menu' ),
			$cap,
			self::PARENT_SLUG,
			[ YSPermissionAdmin::class, 'render' ],
			$icon,
			self::MENU_POSITION
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( '選單與權限', 'ys-admin-menu' ),
			__( '選單與權限', 'ys-admin-menu' ),
			$cap,
			self::PARENT_SLUG,
			[ YSPermissionAdmin::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( '原生選單樣式', 'ys-admin-menu' ),
			__( '原生選單樣式', 'ys-admin-menu' ),
			$cap,
			self::SLUG_ADMIN_THEME,
			[ YSAdminThemeAdmin::class, 'render' ]
		);

		add_submenu_page(
			self::PARENT_SLUG,
			__( '白牌設定', 'ys-admin-menu' ),
			__( '白牌設定', 'ys-admin-menu' ),
			$cap,
			self::SLUG_SETTINGS,
			[ YSWhiteLabelAdmin::class, 'render_settings' ]
		);

		// 移除 WP 自動加上的 parent 同 slug 子項（避免重複的「YS 選單管理」子選單）。
		remove_submenu_page( self::PARENT_SLUG, self::PARENT_SLUG );

		// 重新加回第一個子項「選單與權限」（指向頂層 slug）。
		add_submenu_page(
			self::PARENT_SLUG,
			__( '選單與權限', 'ys-admin-menu' ),
			__( '選單與權限', 'ys-admin-menu' ),
			$cap,
			self::PARENT_SLUG,
			[ YSPermissionAdmin::class, 'render' ]
		);
	}

	/**
	 * 共用設計系統樣式 — 在本外掛任一設定頁載入 .ysca-* primitives 與 token 變數，
	 * 確保移植自 YS CART 的視覺一致。各頁專屬 JS/CSS 由各自的 render() enqueue。
	 *
	 * @param string $hook 當前 admin 頁面 hook suffix。
	 */
	public static function enqueue_shared( $hook ): void {
		if ( false === strpos( (string) $hook, self::PARENT_SLUG ) ) {
			return;
		}

		$url = YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/';
		$ver = YS_ADMIN_MENU_VERSION;

		wp_enqueue_style( 'ys-admin-menu-tokens', $url . 'ys-admin-menu-tokens.css', [], $ver );
		wp_enqueue_style( 'ys-admin-menu-ds-base', $url . 'ysca-admin-base.css', [ 'ys-admin-menu-tokens' ], $ver );
		wp_enqueue_style( 'ys-admin-menu-ds-components', $url . 'ys-cart-admin-components.css', [ 'ys-admin-menu-ds-base' ], $ver );
		wp_enqueue_style( 'ys-admin-menu-whitelabel', $url . 'ys-admin-menu-whitelabel.css', [ 'ys-admin-menu-ds-base' ], $ver );
	}

	/**
	 * 限制白牌 LOGO 作為 WP 原生 menu icon 時的尺寸。
	 */
	public static function print_menu_icon_guard(): void {
		$logo_url = trim( (string) get_option( 'ys_admin_menu_logo_url', '' ) );
		if ( '' === $logo_url ) {
			return;
		}
		?>
		<style id="ys-admin-menu-icon-guard">
			#adminmenu #toplevel_page_<?php echo esc_attr( self::PARENT_SLUG ); ?> .wp-menu-image img {
				width: 20px;
				height: 20px;
				max-width: 20px;
				max-height: 20px;
				padding: 7px 0 0;
				object-fit: contain;
			}
		</style>
		<?php
	}

	/**
	 * 共用設定頁首列 HTML（三頁通用、indigo banner）。
	 */
	public static function pagehead_html( string $icon, string $title, string $sub ): string {
		return sprintf(
			'<div class="ys-am-pagehead"><span class="ys-am-pagehead__icon"><span class="dashicons dashicons-%s"></span></span><div class="ys-am-pagehead__text"><h1 class="ys-am-pagehead__title">%s</h1><p class="ys-am-pagehead__sub">%s</p></div></div><hr class="wp-header-end">',
			esc_attr( $icon ),
			esc_html( $title ),
			esc_html( $sub )
		);
	}
}
