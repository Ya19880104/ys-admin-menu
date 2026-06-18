<?php
/**
 * 解除安裝清理 — 移除本外掛所有 wp_options。
 *
 * 注意：選單設定與樣式設定皆儲存於 wp_options，無自訂資料表。
 *
 * @package YangSheep\AdminMenu
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ys_admin_menu_config' );
delete_option( 'ys_admin_menu_theme_config' );
delete_option( 'ys_admin_menu_logo_url' );
delete_option( 'ys_admin_menu_footer_text' );
delete_option( 'ys_admin_menu_hide_footer' );
