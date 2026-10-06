<?php
/**
 * 解除安裝清理。
 *
 * 預設「保留」所有設定 — 只有使用者在白牌設定頁明確勾選
 * 「刪除外掛時一併清除所有設定」（option `ys_admin_menu_purge_on_uninstall` = 'yes'）
 * 時，才會移除本外掛的所有 wp_options。
 *
 * 注意：本外掛的選單設定與樣式設定皆儲存於 wp_options，自己沒有資料表。
 * 內嵌的 YS Plugin Hub Client 另有兩張資料表（{prefix}ys_hub_client_logs、{prefix}ys_hub_client_settings），
 * 那是站上所有 YS 外掛共用的，這裡不刪。
 *
 * @package YangSheep\AdminMenu
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 未勾選清除 → 保留所有設定（重新安裝時沿用）。
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
];

// ys_ec_menu_config 是給 YS CART 接手用的設定副本。站上沒有 YS CART 時它只是本外掛留下的複本，一起刪；
// 有 YS CART 時那是它的設定，不動。
if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) {
	$ys_admin_menu_options[] = 'ys_ec_menu_config';
}

foreach ( $ys_admin_menu_options as $ys_admin_menu_option ) {
	delete_option( $ys_admin_menu_option );
}
