<?php
/**
 * YS Admin Menu — 主外掛類別（Singleton）
 *
 * 統一啟動四大模組：
 *   1. 選單路由引擎 YSMenuRouter（admin_menu@9999 套用 ys_admin_menu_config）
 *   2. 原生選單樣式渲染 YSAdminThemeRenderer（全 wp-admin 皮膚）
 *   3. REST 路由 YSRouteRegistrar（ys-admin-menu/v1）
 *   4. 後台設定頁 YSMenuPage（頂層選單 + 子頁）+ 白牌 admin-post handler
 *
 * @package YangSheep\AdminMenu
 * @since   1.0.0
 */

namespace YangSheep\AdminMenu;

use YangSheep\AdminMenu\Menu\YSMenuConfigBridge;
use YangSheep\AdminMenu\Menu\YSMenuRouter;
use YangSheep\AdminMenu\Theme\YSAdminThemeRenderer;
use YangSheep\AdminMenu\Rest\YSRouteRegistrar;
use YangSheep\AdminMenu\Admin\YSMenuPage;
use YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin;

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

		// 2. 原生選單樣式渲染 — 全 wp-admin 皮膚（CSS 變數 + runtime CSS）
		YSAdminThemeRenderer::register();

		// 3. REST 路由 — ys-admin-menu/v1（選單列舉 / 設定讀寫）
		add_action( 'rest_api_init', [ YSRouteRegistrar::class, 'register_routes' ] );

		// 4. 後台設定頁 + 白牌 admin-post handler（僅後台）
		if ( is_admin() ) {
			YSMenuPage::register();
			YSWhiteLabelAdmin::register();
		}
	}
}
