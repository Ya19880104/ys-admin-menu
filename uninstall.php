<?php
/**
 * 解除安裝清理。
 *
 * 預設「保留」所有設定 — 只有使用者在白牌設定頁明確勾選
 * 「刪除外掛時一併清除所有設定」（option `ys_admin_menu_purge_on_uninstall` = 'yes'）
 * 時，才會移除本外掛的所有 wp_options。
 *
 * 注意：本外掛的選單設定與樣式設定皆儲存於 wp_options；v1.6.0 的登入次數限制另有三張資料表（見下面，一律刪）。
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

// v1.6.0：登入次數限制的三張資料表（計數、「鍵＋帳號」、紀錄）、內部用的鍵與每天的清理排程，不論有沒有勾
// 「刪除外掛時一併清除所有設定」都刪——表裡有 IP 與嘗試的帳號名稱，不該留在已移除外掛的資料庫裡。多站網路逐站刪。
// 這裡不載入外掛的類別（解除安裝時主檔不會載入），表名與鍵名直接寫。
$ys_admin_menu_drop_login_guard = static function () {
	global $wpdb;
	foreach ( array( 'ys_admin_menu_login_guard', 'ys_admin_menu_login_guard_acct', 'ys_admin_menu_login_log' ) as $ys_admin_menu_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $ys_admin_menu_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- 表名是固定的
	}
	foreach ( array( 'ys_admin_menu_login_guard_db_version', 'ys_admin_menu_login_guard_last_cleanup', 'ys_admin_menu_login_guard_health', 'ys_admin_menu_login_guard_notify_state', 'ys_admin_menu_login_guard_cross_site_since' ) as $ys_admin_menu_internal ) {
		delete_option( $ys_admin_menu_internal );
	}
	wp_clear_scheduled_hook( 'ys_admin_menu_login_guard_cleanup' );
};
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ys_admin_menu_site_id ) {
		switch_to_blog( (int) $ys_admin_menu_site_id );
		$ys_admin_menu_drop_login_guard();
		restore_current_blog();
	}
} else {
	$ys_admin_menu_drop_login_guard();
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
	// v1.6.0：訪客 IP 偵測（偵測方式、自訂標頭、信任的代理、信任 Cloudflare、Turnstile 也使用偵測到的 IP）。
	'ys_admin_menu_ip_source',
	'ys_admin_menu_ip_custom_header',
	'ys_admin_menu_ip_trusted_proxies',
	'ys_admin_menu_ip_trust_cloudflare',
	'ys_admin_menu_ip_turnstile_use',
	// v1.6.0：登入入口的兩個獨立開關（關閉 XML-RPC 登入、停用應用程式密碼）。
	'ys_admin_menu_disable_xmlrpc_login',
	'ys_admin_menu_disable_app_passwords',
	// v1.6.0：登入次數限制的設定（資料表與內部用的鍵在上面一律刪）。
	'ys_admin_menu_login_guard_enabled',
	'ys_admin_menu_login_guard_max_failures',
	'ys_admin_menu_login_guard_window',
	'ys_admin_menu_login_guard_lockout',
	'ys_admin_menu_login_guard_escalate',
	'ys_admin_menu_login_guard_escalate_after',
	'ys_admin_menu_login_guard_escalate_period',
	'ys_admin_menu_login_guard_escalated_lockout',
	'ys_admin_menu_login_guard_ipv6_aggregate',
	'ys_admin_menu_login_guard_lock_private',
	'ys_admin_menu_login_guard_cross_site_reject',
	'ys_admin_menu_login_guard_cross_site_origins',
	'ys_admin_menu_login_guard_allowlist',
	'ys_admin_menu_login_guard_message',
	'ys_admin_menu_login_guard_notify',
	'ys_admin_menu_login_guard_notify_email',
	'ys_admin_menu_login_guard_retention_days',
	'ys_admin_menu_turnstile_enabled',
	'ys_admin_menu_turnstile_site_key',
	'ys_admin_menu_turnstile_secret_key',
	'ys_admin_menu_turnstile_scope',
	'ys_admin_menu_turnstile_mode',
	'ys_admin_menu_turnstile_log',
	'ys_admin_menu_turnstile_health',
	// v1.7.0：誰能使用本外掛、關閉留言、控制台小工具（含記下的小工具清單）。
	'ys_admin_menu_access',
	'ys_admin_menu_comments',
	'ys_admin_menu_dashboard_widgets',
	'ys_admin_menu_dashboard_widgets_seen',
	// v1.7.0：第一次用 1.7.0 存檔前的原本設定（第 1 版）備份。
	'ys_admin_menu_config_v1_backup',
	// v1.9.0：自訂登入網址（沒勾清除時保留：重新安裝、啟用後新網址照舊作用）。
	'ys_admin_menu_login_url',
	// v1.10.0：隨機文章網址的設定與上次重建網址規則時的結果（後者停用外掛時已經刪掉，這裡再保險一次）。
	'ys_admin_menu_random_content',
	'ys_admin_menu_random_rules_state',
];

// ys_ec_menu_config 是給 YS CART 接手用的設定副本。站上沒有 YS CART 時它只是本外掛留下的複本，一起刪；
// 有 YS CART 時那是它的設定，不動。
// v1.11.0：「有 YS CART」不只看啟用中才有的常數，也看外掛檔在不在：YS CART 裝著、當下沒啟用時，這份是它之後再啟用時讀的
// 選單設定（1.10.0 以前會刪掉，YS CART 再啟用時本外掛設的限制就不見了）。
// 只有「確定沒裝」才刪：外掛目錄讀得到、每個外掛子目錄都讀得到、硬碟上沒有 ys-cart.php、重新讀的外掛清單與啟用中清單都沒有
// YS CART（主檔 ys-cart.php、名稱 YS CART 或 Text Domain ys-cart）。get_plugins() 讀不到外掛目錄時回空陣列、讀不到的子目錄直接
// 略過，清單也可能是快取，所以清單是空的或少東西都不能當成沒裝；讀不到、不確定時一律保留。
$ys_admin_menu_ys_cart_absent = static function (): bool {
	if ( defined( 'YS_ECOMMERCE_VERSION' ) ) {
		return false;
	}
	// 1. 外掛目錄與每個子目錄都讀得到，硬碟上沒有 ys-cart.php。
	$dir = defined( 'WP_PLUGIN_DIR' ) ? rtrim( (string) WP_PLUGIN_DIR, '/\\' ) : '';
	if ( '' === $dir || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
		return false;
	}
	$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 讀不到就當成不確定（保留），不要警告。
	if ( false === $entries ) {
		return false;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . DIRECTORY_SEPARATOR . $entry;
		if ( 'ys-cart.php' === $entry || ( @is_link( $path ) && ! @file_exists( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false; // 單檔的 YS CART，或指到不存在位置的連結（例如還沒掛上的磁碟）
		}
		if ( ! @is_dir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( @is_file( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				continue;
			}
			return false; // 不能判定類型，無法確定是否已移除。
		}
		// 掃到主檔名稱就不能確定已移除：包含主檔是壞掉的連結或讀不到的情況。
		$children = is_readable( $path ) ? @scandir( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $children || in_array( 'ys-cart.php', $children, true ) ) {
			return false;
		}
	}
	// 2. 重新讀一次外掛清單（先清掉快取）。
	if ( ! function_exists( 'get_plugins' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'get_plugins' ) ) {
		return false;
	}
	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( 'plugins', 'plugins' );
	}
	foreach ( (array) get_plugins() as $file => $data ) {
		$data = (array) $data;
		if ( 'ys-cart.php' === basename( (string) $file ) || 'YS CART' === ( $data['Name'] ?? '' ) || 'ys-cart' === ( $data['TextDomain'] ?? '' ) ) {
			return false;
		}
	}
	// 3. 啟用中清單（單站與網路啟用）有 YS CART 的主檔：外掛檔暫時讀不到也不刪。
	$active = (array) get_option( 'active_plugins', [] );
	if ( is_multisite() && function_exists( 'get_site_option' ) ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
	}
	foreach ( $active as $file ) {
		if ( is_string( $file ) && 'ys-cart.php' === basename( $file ) ) {
			return false;
		}
	}
	return true;
};
if ( $ys_admin_menu_ys_cart_absent() ) {
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
