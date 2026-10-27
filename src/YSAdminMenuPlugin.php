<?php
/**
 * YS Admin Menu — 主外掛類別（Singleton）
 *
 * 統一啟動四大模組：
 *   1. 選單路由引擎 YSMenuRouter（admin_menu@9999 套用 ys_admin_menu_config）
 *   2. 原生選單樣式渲染 YSAdminThemeRenderer（全 wp-admin 皮膚）
 *   3. REST 路由 YSRouteRegistrar（ys-admin-menu/v1）
 *   4. 後台設定頁 YSMenuPage（頂層選單 + 子頁）+ 白牌 admin-post handler
 *   5. v1.4.0 自訂登入頁 YSLoginPage、Cloudflare Turnstile 登入防護 YSTurnstile（wp-login.php）
 *   6. v1.5.0 YS CART 選單搬家配套 YSMenuMovesMigrator（admin_menu@9000）＋ WP-CLI `wp ys-admin-menu moves`
 *   7. v1.6.0 登入次數限制與訪客 IP 偵測的 WP-CLI：`wp ys-admin-menu login-guard`、`wp ys-admin-menu ip`
 *   8. v1.7.0 誰能使用本外掛 YSAccess（＋`wp ys-admin-menu access`）、關閉留言 YSCommentsDisabler、
 *      控制台小工具 YSDashboardWidgets、「後台精簡」頁 YSDeclutterAdmin；選單的逐項目權限、自訂連結等在 YSMenuRouter。
 *   9. v1.8.0 YS 後台中央樣式 YSAdminUi（assets/ys-admin-ui/，設定頁只用它的元件）
 *
 * @package YangSheep\AdminMenu
 * @since   1.0.0
 */

namespace YangSheep\AdminMenu;

use YangSheep\AdminMenu\Access\YSAccess;
use YangSheep\AdminMenu\Admin\YSAccessAdmin;
use YangSheep\AdminMenu\Admin\YSAdminUi;
use YangSheep\AdminMenu\Admin\YSDeclutterAdmin;
use YangSheep\AdminMenu\Cli\YSAccessCommand;
use YangSheep\AdminMenu\Comments\YSCommentsDisabler;
use YangSheep\AdminMenu\Dashboard\YSDashboardWidgets;
use YangSheep\AdminMenu\Menu\YSAdminBarFilter;
use YangSheep\AdminMenu\Menu\YSMenuConfigBridge;
use YangSheep\AdminMenu\Menu\YSMenuMovesMigrator;
use YangSheep\AdminMenu\Menu\YSMenuRouter;
use YangSheep\AdminMenu\Cli\YSMenuMovesCommand;
use YangSheep\AdminMenu\Cli\YSLoginGuardCommand;
use YangSheep\AdminMenu\Cli\YSClientIpCommand;
use YangSheep\AdminMenu\Theme\YSAdminThemeRenderer;
use YangSheep\AdminMenu\Rest\YSRouteRegistrar;
use YangSheep\AdminMenu\Admin\YSMenuPage;
use YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin;
use YangSheep\AdminMenu\Login\YSLoginPage;
use YangSheep\AdminMenu\Login\YSTurnstile;

defined( 'ABSPATH' ) || exit;

final class YSAdminMenuPlugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_textdomain();
		$this->init();
	}

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'ys-admin-menu',
			false,
			dirname( YS_ADMIN_MENU_BASENAME ) . '/languages'
		);
	}

	private function init(): void {
		YSMenuConfigBridge::register();

		// 1. 選單路由引擎 — 套用排序/隱藏/重命名/改色/role/per-user/URL guard
		YSMenuRouter::register();
		// v1.5.0：YS CART V3 重新分組選單時，把已存的設定搬到新的上層選單（admin_menu 9000，比路由引擎早）。
		YSMenuMovesMigrator::register();
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'ys-admin-menu moves', YSMenuMovesCommand::class );
			// v1.6.0：登入次數限制（狀態、解鎖、白名單、開關、清理、紀錄、刪表）與訪客 IP 偵測的試算（都只在主機上執行）。
			\WP_CLI::add_command( 'ys-admin-menu login-guard', YSLoginGuardCommand::class );
			\WP_CLI::add_command( 'ys-admin-menu ip', YSClientIpCommand::class );
			// v1.7.0：誰能使用本外掛的救援（status／reset／allow）。
			\WP_CLI::add_command( 'ys-admin-menu access', YSAccessCommand::class );
		}
		// 輸出側欄前的最後整理：拿掉已提升子選單在原父層的那一列、沒有內容的分組標籤。
		add_filter( 'parent_file', [ YSMenuRouter::class, 'prepare_menu_for_render' ], 9 );
		add_action( 'admin_footer', [ YSMenuRouter::class, 'print_section_label_fix' ], 5 );
		// 上方工具列：指向「這個帳號被隱藏的選單」的連結一併拿掉，不留點了是 404 的入口。
		YSAdminBarFilter::register();

		// 2. 原生選單樣式渲染 — 全 wp-admin 皮膚（CSS 變數 + runtime CSS）
		YSAdminThemeRenderer::register();

		// 3. REST 路由 — ys-admin-menu/v1（選單列舉 / 設定讀寫）
		add_action( 'rest_api_init', [ YSRouteRegistrar::class, 'register_routes' ] );

		// 白牌「隱藏 WordPress LOGO」前台與後台的工具列都要生效，所以不放在 is_admin() 裡。
		if ( YSWhiteLabelAdmin::get_hide_wp_logo() ) {
			add_action( 'admin_bar_menu', [ YSWhiteLabelAdmin::class, 'remove_wp_logo_node' ], 999 );
		}

		// v1.4.0：自訂登入頁與 Turnstile 都在 wp-login.php（不是 is_admin()），所以不放在後台區塊裡。
		// 兩者都等到 login_init 才讀設定；其他請求不受影響。
		YSLoginPage::register();
		YSTurnstile::register();

		// v1.7.0：關閉留言（前台、REST、XML-RPC 都要；預設關，關著時一個 hook 都不掛）。
		YSCommentsDisabler::register();
		// v1.7.0：停用本外掛的最後一道關卡（REST、外掛市集也會停用外掛，它們不是 is_admin()）。只掛一個 action，
		// 真的有人要停用本外掛時才讀設定。
		YSAccess::register_deactivation_guard();

		// 4. 後台設定頁 + 白牌 admin-post handler（僅後台）
		if ( is_admin() ) {
			// v1.8.0：YS 後台中央樣式（設計系統＋共用版面，和其他 YS 外掛共用同一組代稱，取版本最高的一份）。
			YSAdminUi::register();
			YSMenuPage::register();
			YSWhiteLabelAdmin::register();
			// v1.7.0：誰能使用本外掛（外掛清單隱藏與停用、刪除防護）與「外掛使用權限」分頁的存檔。
			YSAccess::register();
			YSAccessAdmin::register();
			// v1.7.0：控制台小工具（只在控制台動作）與「後台精簡」頁的存檔。
			YSDashboardWidgets::register();
			YSDeclutterAdmin::register();
		}
	}
}
