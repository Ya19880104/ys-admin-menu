<?php
/**
 * v1.7.0：誰能使用 YS Admin Menu（設計 docs/plans/2026-10-26-ys-admin-menu-170-design.md §6）。
 *
 * 選項 ys_admin_menu_access（autoload yes）：
 *   { mode: 'capability' | 'users', user_ids: int[], hide_plugin_row: bool }
 *   - capability（預設）＝1.6.0：有 manage_options 就能用。
 *   - users：只有 user_ids 裡的帳號能用（仍要有 manage_options）。
 *   - hide_plugin_row：外掛清單對不能使用的人隱藏本外掛，並擋下他們停用、刪除本外掛。
 * 這份設定不放進選單設定、不鏡像給 YS CART、不進匯出檔（帳號 ID 是站內的）。
 *
 * 防鎖死：
 *   - 「只有指定帳號」時，清單裡沒有任何還存在而且有 manage_options 的帳號，就當成 capability（每次判斷都算）。
 *   - wp-config 定義 YS_ADMIN_MENU_ACCESS_RESET 為 true 也當成 capability。
 *   - 多站台的網路超級管理員一律可以。
 *   - 存檔時清單一定包含按儲存的人；WP-CLI `wp ys-admin-menu access reset`。
 *
 * 只管 YS Admin Menu 自己的設定頁、REST、表單動作與提醒；資料表維護這類系統工作照舊看 manage_options，WP-CLI 不受影響。
 *
 * @package YangSheep\AdminMenu\Access
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Access;

defined( 'ABSPATH' ) || exit;

final class YSAccess {

	public const OPTION = 'ys_admin_menu_access';

	/** wp-config 定義成 true：「只有指定帳號」暫時失效，回到有 manage_options 就能用（救援用）。 */
	public const RESET_CONSTANT = 'YS_ADMIN_MENU_ACCESS_RESET';

	/** 指定帳號最多幾個（防止異常資料）。 */
	public const MAX_USERS = 50;

	public static function register(): void {
		add_filter( 'all_plugins', [ self::class, 'filter_plugin_list' ] );
		add_action( 'check_admin_referer', [ self::class, 'guard_plugin_action' ], 10, 2 );
		add_action( 'check_ajax_referer', [ self::class, 'guard_plugin_ajax' ], 10, 2 );
	}

	/**
	 * @return array{mode:string,user_ids:int[],hide_plugin_row:bool}
	 */
	public static function defaults(): array {
		return [
			'mode'            => 'capability',
			'user_ids'        => [],
			'hide_plugin_row' => false,
		];
	}

	/**
	 * @param mixed $raw
	 * @return array{mode:string,user_ids:int[],hide_plugin_row:bool}
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$ids = [];
		foreach ( is_array( $raw['user_ids'] ?? null ) ? $raw['user_ids'] : [] as $id ) {
			// 只收正整數（或只有數字的字串）；absint() 會把 -2 變成 2，這裡不用。
			$id = is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? (int) $id : 0;
			if ( $id > 0 && count( $ids ) < self::MAX_USERS ) {
				$ids[ $id ] = $id;
			}
		}
		return [
			'mode'            => 'users' === ( $raw['mode'] ?? '' ) ? 'users' : 'capability',
			'user_ids'        => array_values( $ids ),
			'hide_plugin_row' => ! empty( $raw['hide_plugin_row'] ),
		];
	}

	/**
	 * 已存的設定（清理過）。
	 *
	 * @return array{mode:string,user_ids:int[],hide_plugin_row:bool}
	 */
	public static function get(): array {
		// 沒存過（大多數站）就不查資料庫：這個選項存檔時一律 autoload，不在 alloptions 裡＝沒存過＝預設。
		// 直接 get_option() 不存在的選項，沒有常駐物件快取的站每個後台請求都會多查一次資料庫（v150 NOTE-3 量的就是這個）。
		if ( function_exists( 'wp_load_alloptions' ) && ! array_key_exists( self::OPTION, (array) wp_load_alloptions() ) ) {
			return self::defaults();
		}
		return self::sanitize( get_option( self::OPTION, [] ) );
	}

	/**
	 * 實際生效的設定。「只有指定帳號」時：常數要求重設、或清單裡沒有任何還存在而且有 manage_options 的帳號，
	 * 就當成 capability（fallback＝true）。清單只留有效的帳號。
	 *
	 * @return array{mode:string,user_ids:int[],hide_plugin_row:bool,fallback:bool,reset_constant:bool}
	 */
	public static function effective(): array {
		$cfg                   = self::get();
		$cfg['fallback']       = false;
		$cfg['reset_constant'] = defined( self::RESET_CONSTANT ) && (bool) constant( self::RESET_CONSTANT );
		if ( 'users' !== $cfg['mode'] ) {
			return $cfg;
		}
		$valid = [];
		if ( ! $cfg['reset_constant'] ) {
			foreach ( $cfg['user_ids'] as $id ) {
				if ( user_can( $id, 'manage_options' ) ) {
					$valid[] = $id;
				}
			}
		}
		$cfg['user_ids'] = $valid;
		if ( [] === $valid ) {
			$cfg['mode']     = 'capability';
			$cfg['fallback'] = true;
		}
		return $cfg;
	}

	/**
	 * 目前登入的人能不能使用 YS Admin Menu（設定頁、REST、表單動作與提醒）。
	 * 預設（capability）就是 current_user_can( 'manage_options' )，跟 1.6.0 一樣。
	 */
	public static function can_manage(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$cfg = self::effective();
		if ( 'users' !== $cfg['mode'] ) {
			return true;
		}
		if ( is_multisite() && is_super_admin() ) {
			return true;
		}
		return in_array( (int) get_current_user_id(), $cfg['user_ids'], true );
	}

	/**
	 * 設定頁存檔。呼叫端已經驗過 can_manage() 與 nonce。
	 *
	 * - 「只有指定帳號」：只收存在而且有 manage_options 的帳號；按儲存的人一定在清單裡（沒勾就自動加上）。
	 * - 回到「所有管理員」時保留清單（之後再切回來不用重勾），但不生效。
	 *
	 * @param array<string, mixed> $raw 已 wp_unslash 的表單值（mode、user_ids、hide_plugin_row）。
	 * @return string[] 提示代碼：self_added（自動加上自己）、dropped（有帳號不存在或沒有管理權限，沒存）。
	 */
	public static function save( array $raw, int $current_user_id ): array {
		$cfg   = self::sanitize( $raw );
		$notes = [];
		$ids   = array_values( array_filter( $cfg['user_ids'], static fn( int $id ): bool => user_can( $id, 'manage_options' ) ) );
		if ( count( $ids ) !== count( $cfg['user_ids'] ) ) {
			$notes[] = 'dropped';
		}
		if ( 'users' === $cfg['mode'] && $current_user_id > 0 && ! in_array( $current_user_id, $ids, true ) ) {
			$ids[]   = $current_user_id;
			$notes[] = 'self_added';
		}
		$cfg['user_ids'] = array_slice( $ids, 0, self::MAX_USERS );
		update_option( self::OPTION, $cfg, true );
		return $notes;
	}

	/** 外掛清單裡本外掛那一列要不要對目前的人藏起來（也決定要不要擋停用、刪除）。 */
	public static function protects_plugin_row(): bool {
		return self::get()['hide_plugin_row'] && ! self::can_manage();
	}

	/**
	 * all_plugins：不能使用本外掛的人看不到這一列。
	 *
	 * @param mixed $plugins
	 * @return mixed
	 */
	public static function filter_plugin_list( $plugins ) {
		if ( ! is_array( $plugins ) || ! defined( 'YS_ADMIN_MENU_BASENAME' ) || ! self::protects_plugin_row() ) {
			return $plugins;
		}
		unset( $plugins[ YS_ADMIN_MENU_BASENAME ] );
		return $plugins;
	}

	/**
	 * check_admin_referer（nonce 驗過之後）：不能使用本外掛的人停用本外掛、或批次停用／刪除時選了本外掛，一律 403。
	 * 只擋 WordPress 外掛頁的這幾個動作；WP-CLI、主機面板與其他外掛管理工具擋不住（設定頁有寫）。
	 *
	 * @param mixed $action
	 * @param mixed $result
	 */
	public static function guard_plugin_action( $action, $result = null ): void {
		if ( ! is_string( $action ) || ! defined( 'YS_ADMIN_MENU_BASENAME' ) ) {
			return;
		}
		$base = YS_ADMIN_MENU_BASENAME;
		$hit  = 'deactivate-plugin_' . $base === $action;
		if ( ! $hit && 'bulk-plugins' === $action ) {
			$checked = isset( $_REQUEST['checked'] ) && is_array( $_REQUEST['checked'] ) ? wp_unslash( $_REQUEST['checked'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce 剛驗過；只比對字串
			$hit     = in_array( $base, array_map( 'strval', (array) $checked ), true );
		}
		if ( $hit && self::protects_plugin_row() ) {
			wp_die( esc_html__( '你沒有權限停用或刪除 YS Admin Menu。', 'ys-admin-menu' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * check_ajax_referer：外掛頁用 AJAX 刪除外掛（action=delete-plugin、nonce updates）時，同上。
	 *
	 * @param mixed $action
	 * @param mixed $result
	 */
	public static function guard_plugin_ajax( $action, $result = null ): void {
		if ( 'updates' !== $action || ! defined( 'YS_ADMIN_MENU_BASENAME' ) ) {
			return;
		}
		$ajax   = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- nonce 剛驗過
		$plugin = isset( $_POST['plugin'] ) && is_string( $_POST['plugin'] ) ? (string) wp_unslash( $_POST['plugin'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只比對字串
		if ( 'delete-plugin' === $ajax && YS_ADMIN_MENU_BASENAME === $plugin && self::protects_plugin_row() ) {
			wp_send_json_error( [ 'errorMessage' => __( '你沒有權限刪除 YS Admin Menu。', 'ys-admin-menu' ) ], 403 );
		}
	}
}
