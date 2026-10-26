<?php
/**
 * Plugin Name: YS Admin Menu
 * Plugin URI:  https://yangsheep.com.tw
 * Description: 後台選單管理工具 — wp-admin 原生選單的拖拉排序、隱藏、重新命名、改色、角色權限、個別使用者覆寫、直接網址防護，外加原生選單樣式美化與白牌。從 YS CART 抽離的獨立工具。
 * Version:     1.6.0
 * Author:      YANGSHEEP DESIGN
 * Author URI:  https://yangsheep.com.tw
 * License:     GPL-2.0-or-later
 * Text Domain: ys-admin-menu
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package YangSheep\AdminMenu
 */

defined( 'ABSPATH' ) || exit;

/* ──────────────────────────────────────────────
 * 常數定義
 * ────────────────────────────────────────────── */
define( 'YS_ADMIN_MENU_VERSION', '1.6.0' );
define( 'YS_ADMIN_MENU_PLUGIN_FILE', __FILE__ );
define( 'YS_ADMIN_MENU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_ADMIN_MENU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_ADMIN_MENU_BASENAME', plugin_basename( __FILE__ ) );
define( 'YS_ADMIN_MENU_OWNS_MENU_ROUTING', true );

/* ──────────────────────────────────────────────
 * Hub Client（內嵌子外掛，自帶 PSR-4 autoloader 與初始化）
 * ────────────────────────────────────────────── */
$ys_admin_menu_hub = YS_ADMIN_MENU_PLUGIN_DIR . 'vendor/yangsheep/ys-plugin-hub-client/ys-plugin-hub-client.php';
if ( file_exists( $ys_admin_menu_hub ) ) {
	require_once $ys_admin_menu_hub;
}
unset( $ys_admin_menu_hub );

/* ──────────────────────────────────────────────
 * Fallback PSR-4 Autoloader（本外掛 src/）
 * ────────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
	$prefix   = 'YangSheep\\AdminMenu\\';
	$base_dir = YS_ADMIN_MENU_PLUGIN_DIR . 'src/';
	$len      = strlen( $prefix );

	if ( 0 !== strncmp( $prefix, $class, $len ) ) {
		return;
	}

	$relative = substr( $class, $len );
	$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook(
	__FILE__,
	[ \YangSheep\AdminMenu\Menu\YSMenuConfigBridge::class, 'activate' ]
);
register_deactivation_hook(
	__FILE__,
	[ \YangSheep\AdminMenu\Menu\YSMenuConfigBridge::class, 'deactivate' ]
);

/* ──────────────────────────────────────────────
 * v1.6.0 登入入口的兩個獨立開關（關閉 XML-RPC 登入、停用應用程式密碼）
 * 主檔載入時就掛，不等 plugins_loaded：有外掛在 plugins_loaded 就呼叫 wp_get_current_user()。
 * 設定等 filter 真的被呼叫時才讀；跟登入次數限制的總開關無關。
 * ────────────────────────────────────────────── */
\YangSheep\AdminMenu\Security\YSLoginEntrances::register();

/* ──────────────────────────────────────────────
 * v1.6.0 登入次數限制：主檔載入時重設請求內的狀態（閘門、只算一次的旗標、IP 結果、REST 標記），
 * 總開關打開、停用常數沒定義才掛 hook。在 WP::init() 與 determine_current_user 之前，不等 plugins_loaded。
 * ────────────────────────────────────────────── */
\YangSheep\AdminMenu\Security\YSLoginGuard::bootstrap();
register_activation_hook( __FILE__, [ \YangSheep\AdminMenu\Security\YSLoginGuard::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \YangSheep\AdminMenu\Security\YSLoginGuard::class, 'deactivate' ] );

/* ──────────────────────────────────────────────
 * Hub Client 註冊（priority 5，比主外掛早）
 * ────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
	if ( class_exists( '\YangSheep\PluginHubClient\YSPluginHubClient' ) ) {
		\YangSheep\PluginHubClient\YSPluginHubClient::register( array(
			'slug'        => 'ys-admin-menu',
			'version'     => YS_ADMIN_MENU_VERSION,
			'plugin_file' => __FILE__,
			'name'        => 'YS Admin Menu',
		) );
	}
}, 5 );

/* ──────────────────────────────────────────────
 * 主外掛初始化（priority 11，在 Hub Client 之後）
 * ────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
	\YangSheep\AdminMenu\YSAdminMenuPlugin::instance();
}, 11 );

/* ──────────────────────────────────────────────
 * 外掛動作連結（設定頁快捷）
 * ────────────────────────────────────────────── */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
	// v1.7.0：不能使用本外掛的管理員（外掛使用權限選了只有指定帳號）不顯示「設定」連結。
	if ( ! \YangSheep\AdminMenu\Access\YSAccess::can_manage() ) {
		return $links;
	}
	$url = admin_url( 'admin.php?page=' . \YangSheep\AdminMenu\Admin\YSMenuPage::PARENT_SLUG );
	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html__( '設定', 'ys-admin-menu' ) . '</a>'
	);
	return $links;
} );
