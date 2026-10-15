<?php
/**
 * 解除安裝清理。
 *
 * 預設「保留」所有設定 — 只有使用者在白牌設定頁明確勾選
 * 「刪除外掛時一併清除所有設定」（option `ys_admin_menu_purge_on_uninstall` = 'yes'）
 * 時，才會移除本外掛的所有 wp_options。
 *
 * 注意：本外掛的選單設定與樣式設定皆儲存於 wp_options，自己沒有資料表。
 * v1.3.0 的「複製到主選單」（top_copies）與複製項的排序、名稱、圖示（wp_native.items[]）都存在
 * ys_admin_menu_config 裡，刪掉這個 option 就一起清除，沒有另外的 option 或暫存資料。
 * 內嵌的 YS Plugin Hub Client 另有兩張資料表（{prefix}ys_hub_client_logs、{prefix}ys_hub_client_settings），
 * 那是站上所有 YS 外掛共用的，這裡不刪。
 *
 * @package YangSheep\AdminMenu
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// v1.4.0：Cloudflare Turnstile 的 Secret Key 不論有沒有勾「刪除外掛時一併清除所有設定」都刪掉——
// 金鑰不該留在已移除外掛的資料庫裡。重新安裝後要重新填 Secret Key；在那之前 Turnstile 不會生效（不會擋登入）。
delete_option( 'ys_admin_menu_turnstile_secret_key' );

// 未勾選清除 → 保留其他所有設定（重新安裝時沿用）。
if ( 'yes' !== get_option( 'ys_admin_menu_purge_on_uninstall', 'no' ) ) {
	return;
}

$ys_admin_menu_options = [
	'ys_admin_menu_config',
	'ys_admin_menu_theme_config',
	'ys_admin_menu_logo_url',
	'ys_admin_menu_footer_text',
	'ys_admin_menu_hide_footer',
	'ys_admin_menu_hide_wp_logo',
	'ys_admin_menu_admin_bg_color',
	'ys_admin_menu_purge_on_uninstall',
	'ys_admin_menu_config_bridge_version',
	'ys_admin_menu_config_bridge_fingerprint',
	'ys_admin_menu_theme_stand_down_logged_version',
	// v1.4.0：選單上方 LOGO 區塊、Admin Bar、自訂登入頁、Turnstile（含 Secret Key 與事件紀錄）。
	'ys_admin_menu_show_menu_logo',
	'ys_admin_menu_menu_logo_text',
	'ys_admin_menu_adminbar_hidden',
	'ys_admin_menu_adminbar_seen',
	'ys_admin_menu_frontend_adminbar',
	'ys_admin_menu_frontend_adminbar_roles',
	'ys_admin_menu_login_enabled',
	'ys_admin_menu_login_brand',
	'ys_admin_menu_login_title',
	'ys_admin_menu_login_subtitle',
	'ys_admin_menu_login_tagline',
	'ys_admin_menu_login_logo_url',
	'ys_admin_menu_login_bg_url',
	'ys_admin_menu_login_footer_text',
	'ys_admin_menu_login_back_link',
	'ys_admin_menu_turnstile_enabled',
	'ys_admin_menu_turnstile_site_key',
	'ys_admin_menu_turnstile_secret_key',
	'ys_admin_menu_turnstile_scope',
	'ys_admin_menu_turnstile_mode',
	'ys_admin_menu_turnstile_log',
	'ys_admin_menu_turnstile_health',
];

// ys_ec_menu_config 是給 YS CART 接手用的設定副本。站上沒有 YS CART 時它只是本外掛留下的複本，一起刪；
// 有 YS CART 時那是它的設定，不動。
if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) {
	$ys_admin_menu_options[] = 'ys_ec_menu_config';
}

foreach ( $ys_admin_menu_options as $ys_admin_menu_option ) {
	delete_option( $ys_admin_menu_option );
}

// v1.4.0：Turnstile 事件紀錄的節流暫存（一分鐘後本來就會過期）。
foreach ( [ 'unreachable', 'config_blocked', 'secret_invalid' ] as $ys_admin_menu_reason ) {
	delete_transient( 'ys_admin_menu_ts_log_' . $ys_admin_menu_reason );
}
