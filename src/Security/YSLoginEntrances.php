<?php
/**
 * 登入入口的兩個獨立開關（v1.6.0）：關閉 XML-RPC 登入、停用應用程式密碼。
 *
 * - 兩個開關預設關，跟登入次數限制的總開關、停用常數都無關，可以單獨使用。
 * - 關閉 XML-RPC 登入：`xmlrpc_enabled` 回 false。核心只關掉需要登入的方法：wp_xmlrpc_server::login() 直接回 405，
 *   不呼叫 wp_authenticate()，不檢查帳密、也不觸發 wp_login_failed。Pingback 這類不用登入的方法照常。
 * - 停用應用程式密碼：`wp_is_application_passwords_available` 回 false。REST 與 XML-RPC 都不能用應用程式密碼登入，
 *   個人資料頁也不能再建立；已建立的保留，關掉開關就恢復。WooCommerce 的 REST API 金鑰不是應用程式密碼，不受影響。
 * - 兩個 filter 都掛在 PHP_INT_MAX：開關打開時，別的外掛在後面把值改回 true 也蓋不掉；開關關著時原樣回傳收到的值。
 * - 在外掛主檔載入時就掛（不等 plugins_loaded）：有外掛會在 plugins_loaded 就呼叫 wp_get_current_user()；
 *   用 `application_password_is_api_request` 把自己的請求也當成 API 請求的外掛，那時就會驗應用程式密碼。
 *   設定等 filter 真的被呼叫時才讀。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginEntrances {

	/** 關閉 XML-RPC 登入：yes／no，預設 no。只有 XML-RPC 請求會讀，不自動載入。 */
	public const OPTION_DISABLE_XMLRPC = 'ys_admin_menu_disable_xmlrpc_login';

	/** 停用應用程式密碼：yes／no，預設 no。核心判斷目前使用者時幾乎每個請求都會問，所以自動載入。 */
	public const OPTION_DISABLE_APP_PASSWORDS = 'ys_admin_menu_disable_app_passwords';

	/**
	 * 掛上兩個 filter（重複呼叫不會重複掛）。
	 */
	public static function register(): void {
		add_filter( 'xmlrpc_enabled', [ self::class, 'filter_xmlrpc_enabled' ], PHP_INT_MAX );
		add_filter( 'wp_is_application_passwords_available', [ self::class, 'filter_app_passwords_available' ], PHP_INT_MAX );
	}

	public static function xmlrpc_login_disabled(): bool {
		return 'yes' === get_option( self::OPTION_DISABLE_XMLRPC, 'no' );
	}

	/** 幾乎每個沒登入的請求都會問：沒存過設定的站不為不存在的 option 查資料庫（YSLoginGuardSettings::autoloaded()，實作審查 A 線 P3-1）。 */
	public static function app_passwords_disabled(): bool {
		return 'yes' === YSLoginGuardSettings::autoloaded( self::OPTION_DISABLE_APP_PASSWORDS, 'no' );
	}

	/**
	 * `xmlrpc_enabled`：開關打開時回 false；已經是 false 就不讀設定、原樣回傳。
	 *
	 * @param mixed $enabled
	 * @return mixed
	 */
	public static function filter_xmlrpc_enabled( $enabled ) {
		if ( ! $enabled ) {
			return $enabled;
		}
		return self::xmlrpc_login_disabled() ? false : $enabled;
	}

	/**
	 * `wp_is_application_passwords_available`：開關打開時回 false；已經是 false（例如沒有 HTTPS）就不讀設定、原樣回傳。
	 *
	 * @param mixed $available
	 * @return mixed
	 */
	public static function filter_app_passwords_available( $available ) {
		if ( ! $available ) {
			return $available;
		}
		return self::app_passwords_disabled() ? false : $available;
	}
}
