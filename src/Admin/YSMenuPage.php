<?php
/**
 * 本外掛後台選單與設定頁註冊。
 *
 * 結構（WP 原生左側 sidebar 頂層）：
 *   ┌─ YS 選單管理（add_menu_page，icon dashicons-menu-alt3）
 *   │  ├─ 選單與權限（= 頂層 slug，YSPermissionAdmin：主選單 / 全部選單 / 使用者覆寫 / 外掛使用權限）
 *   │  ├─ 原生選單樣式（YSAdminThemeAdmin）
 *   │  ├─ 白牌設定（YSWhiteLabelAdmin）
 *   │  ├─ 登入安全（YSLoginSecurityAdmin，v1.6.0）
 *   │  └─ 後台精簡（YSDeclutterAdmin，v1.7.0：關閉留言、控制台小工具）
 *
 * v1.7.0：整組選單只給能使用本外掛的帳號（YSAccess::can_manage()）。
 *
 * 若使用者已上傳白牌 LOGO（option `ys_admin_menu_logo_url`），
 * 頂層選單 icon 會以該 LOGO URL 取代 dashicon。
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   1.0.0
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Access\YSAccess;

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
		add_filter( 'admin_body_class', [ self::class, 'add_body_class' ] );
	}

	/**
	 * 註冊頂層選單與子頁。
	 */
	public static function add_menu(): void {
		// v1.7.0：「外掛使用權限」選了只有指定帳號時，其他管理員側欄沒有「YS 選單管理」，直接開設定頁也是沒有權限。
		if ( ! YSAccess::can_manage() ) {
			return;
		}
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

		// v1.7.0：關閉留言、控制台小工具。
		add_submenu_page(
			self::PARENT_SLUG,
			__( '後台精簡', 'ys-admin-menu' ),
			__( '後台精簡', 'ys-admin-menu' ),
			$cap,
			YSDeclutterAdmin::PAGE_SLUG,
			[ YSDeclutterAdmin::class, 'render' ]
		);
	}

	/**
	 * 設定頁的樣式：YS 後台中央樣式（YSAdminUi 註冊的 ys-admin-ui，含設計系統三支）＋本外掛獨有的元件。
	 * v1.8.0（10-27 使用者：「後台你有中央化ＣＳＳ嗎」「YS ADMIN 的也要整理」）：原本白牌頁另有一套 .ysca-wl-* 樣式、
	 * 頁首是藍色橫幅、各頁分頁籤與卡片各長各的；改成全部用中央樣式的元件。各頁專屬 JS 與色彩挑選器、排序表的 CSS 由各自的 render() enqueue。
	 *
	 * @param string $hook 當前 admin 頁面 hook suffix。
	 */
	public static function enqueue_shared( $hook ): void {
		if ( false === strpos( (string) $hook, self::PARENT_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'ys-admin-menu-settings', YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/ys-admin-menu-settings.css', [ 'ys-admin-ui' ], YS_ADMIN_MENU_VERSION );
	}

	/**
	 * v1.8.0：本外掛設定頁的 body 加 ys-admin-screen（中央樣式的白底）。
	 *
	 * @param string $classes 現有 body class 字串。
	 * @return string
	 */
	public static function add_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PARENT_SLUG ) ) {
			$classes .= ' ys-admin-screen';
		}
		return $classes;
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
	 * v1.8.0：設定頁的外框與頁首（YS 後台中央樣式的頁面骨架，和其他 YS 外掛的後台頁相同）：
	 * 白色頁首（麵包屑「YS 選單管理」、標題、說明），內容放在 .ysca-page-root。原本是藍色橫幅＋圖示（.ys-am-pagehead）。
	 * 收尾用 page_close()。
	 *
	 * @param string $title 頁面標題。
	 * @param string $desc  標題下的說明。
	 * @param string $class 外框另外加的 class（各頁自己的範圍，例如 ys-am-declutter）。
	 */
	public static function page_open( string $title, string $desc, string $class = '' ): string {
		return '<div class="wrap ysca-native-wrap ys-admin-page' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '">'
			. '<div class="ysca-page-head ysca-page-head--native"><div>'
			. '<p class="ysca-page-head__breadcrumb ysca-page-head__breadcrumb--native">YS 選單管理</p>'
			. '<h1 class="ysca-page-head__title ysca-page-head__title--native">' . esc_html( $title ) . '</h1>'
			. '<p class="ys-admin-page-desc">' . esc_html( $desc ) . '</p>'
			. '</div></div><hr class="wp-header-end"><div class="ysca-page-root ysca-page-root--flush">';
	}

	/** page_open() 的收尾。 */
	public static function page_close(): string {
		return '</div></div>';
	}
}
