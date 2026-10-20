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
 * v1.5.0 的 YS CART 選單搬家另有五種 option：遷移標記 ys_admin_menu_moves_applied、遷移紀錄
 * ys_admin_menu_moves_log、遷移鎖 ys_admin_menu_moves_lock、遷移前備份 ys_admin_menu_config_pre_moves_<版本>，
 * 以及啟用時改用 YS CART 搬家前備份之前另存的 YS CART 那份 ys_admin_menu_core_copy_pre_activation。
 * 內嵌的 YS Plugin Hub Client 另有兩張資料表（{prefix}ys_hub_client_logs、{prefix}ys_hub_client_settings），
 * 那是站上所有 YS 外掛共用的，這裡不刪。
 *
 * @package YangSheep\AdminMenu
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// v1.4.0：Cloudflare Turnstile 的 Secret Key 不論有沒有勾「刪除外掛時一併清除所有設定」都刪掉——
// 金鑰不該留在已移除外掛的資料庫裡。重新安裝後要重新填 Secret Key；在那之前 Turnstile 不會生效（不會擋登入）。
// 多站網路：每個站都有自己的 option，逐站刪除（其他設定的清除照舊只看主站的勾選）。
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ys_admin_menu_site_id ) {
		switch_to_blog( (int) $ys_admin_menu_site_id );
		delete_option( 'ys_admin_menu_turnstile_secret_key' );
		restore_current_blog();
	}
} else {
	delete_option( 'ys_admin_menu_turnstile_secret_key' );
}

// v1.5.0：YS CART 選單搬家的遷移標記、遷移紀錄、遷移鎖、遷移前備份（ys_admin_menu_config_pre_moves_<版本>），以及啟用時
// 另存的 YS CART 那份（ys_admin_menu_core_copy_pre_activation）。
// 勾了「刪除外掛時一併清除所有設定」才刪；多站網路逐站看那個站自己的勾選、刪那個站的（單站在下面一起刪）。
$ys_admin_menu_purge_moves = static function () {
	global $wpdb;
	foreach ( array( 'ys_admin_menu_moves_applied', 'ys_admin_menu_moves_log', 'ys_admin_menu_moves_lock', 'ys_admin_menu_core_copy_pre_activation' ) as $ys_admin_menu_moves_option ) {
		delete_option( $ys_admin_menu_moves_option );
	}
	$ys_admin_menu_backups = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ys_admin_menu_config_pre_moves_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 備份的 option 名稱帶版本，要用前綴找。
	foreach ( (array) $ys_admin_menu_backups as $ys_admin_menu_backup ) {
		delete_option( (string) $ys_admin_menu_backup );
	}
};
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ys_admin_menu_site_id ) {
		switch_to_blog( (int) $ys_admin_menu_site_id );
		if ( 'yes' === get_option( 'ys_admin_menu_purge_on_uninstall', 'no' ) ) {
			$ys_admin_menu_purge_moves();
		}
		restore_current_blog();
	}
}

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
	// v1.5.1：自訂登入頁的「顯示語言切換」。
	'ys_admin_menu_login_language_switcher',
	// v1.6.0：自訂登入頁的樣式（主色、背景、自訂背景色、左上字標）。
	'ys_admin_menu_login_primary_color',
	'ys_admin_menu_login_background',
	'ys_admin_menu_login_background_color',
	'ys_admin_menu_login_wordmark',
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

// v1.5.0：單站的遷移標記、紀錄、鎖、備份（多站已在上面逐站處理）。
if ( ! is_multisite() ) {
	$ys_admin_menu_purge_moves();
}

// v1.4.0：Turnstile 事件紀錄的節流暫存（一分鐘後本來就會過期）。
foreach ( [ 'unreachable', 'config_blocked', 'secret_invalid' ] as $ys_admin_menu_reason ) {
	delete_transient( 'ys_admin_menu_ts_log_' . $ys_admin_menu_reason );
}
