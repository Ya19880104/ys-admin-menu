<?php
/**
 * 解除安裝清理。
 *
 * 預設「保留」所有設定 — 只有使用者在白牌設定頁明確勾選
 * 「刪除外掛時一併清除所有設定」（option `ys_admin_menu_purge_on_uninstall` = 'yes'）
 * 時，才會移除本外掛的所有 wp_options。
 *
 * 注意：選單設定與樣式設定皆儲存於 wp_options，無自訂資料表。
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
];

foreach ( $ys_admin_menu_options as $ys_admin_menu_option ) {
	delete_option( $ys_admin_menu_option );
}
