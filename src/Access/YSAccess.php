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
 * 這是讓管理員分工、避免誤操作的介面設定，不是防範其他管理員的安全機制：有管理員權限的帳號仍然可以繞過
 * （WordPress 內建的「全部設定」頁 options.php、上傳外掛取代本外掛、檔案編輯器、安裝其他外掛、把清單裡的帳號降級…）。
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
	 * v1.7.0：停用的最後一道關卡，每個請求都掛（REST 不是 is_admin()）：外掛頁單筆與批次停用、核心 REST
	 * （/wp/v2/plugins）、電商工具箱的外掛市集，都是經過 deactivate_plugins() 停用，它在改 active_plugins 之前觸發 deactivate_plugin。
	 * 外掛更新用的是靜默停用（不觸發），不受影響；WP-CLI 與沒登入的系統流程不擋。停用不了就刪不了（WordPress 不准刪除啟用中的外掛）。
	 */
	public static function register_deactivation_guard(): void {
		add_action( 'deactivate_plugin', [ self::class, 'guard_deactivation' ], 0, 2 );
	}

	/**
	 * @param mixed $plugin
	 * @param mixed $network_wide
	 */
	public static function guard_deactivation( $plugin, $network_wide = false ): void {
		if ( ! defined( 'YS_ADMIN_MENU_BASENAME' ) || ! is_string( $plugin ) || self::normalize_plugin_path( $plugin ) !== YS_ADMIN_MENU_BASENAME ) {
			return;
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return;
		}
		if ( self::protects_plugin_row() ) {
			wp_die( esc_html__( '你沒有權限停用或刪除 YS Admin Menu。', 'ys-admin-menu' ), esc_html__( '權限不足', 'ys-admin-menu' ), [ 'response' => 403 ] );
		}
	}

	/** v1.7.0：外掛路徑正規化（反斜線、重複斜線、./ 段、前後斜線），比對本外掛用。 */
	public static function normalize_plugin_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		do {
			$before = $path;
			$path   = (string) preg_replace( '#(^|/)\./#', '$1', $path );
		} while ( $before !== $path );
		return trim( $path, '/' );
	}

	/** v1.7.0：這個外掛路徑是不是本外掛（或本外掛資料夾裡的檔案：刪除會刪掉整個資料夾）。 */
	private static function is_own_plugin_path( string $path ): bool {
		if ( ! defined( 'YS_ADMIN_MENU_BASENAME' ) ) {
			return false;
		}
		$path = self::normalize_plugin_path( $path );
		$dir  = dirname( YS_ADMIN_MENU_BASENAME );
		return YS_ADMIN_MENU_BASENAME === $path || ( '.' !== $dir && str_starts_with( $path, $dir . '/' ) );
	}

	/**
	 * v1.7.0：本外掛的設定選項存檔時一律自動載入（讀取時不在 alloptions＝沒存過＝預設，一般請求省一次查詢）。被別的工具改成
	 * 不自動載入時設定會靜默回到預設；本外掛的設定頁與 WP-CLI 打開時呼叫這裡：選項存在、卻不在 alloptions，就改回自動載入。
	 * 回傳有沒有修（設定頁顯示提示）。
	 */
	public static function heal_autoload( string $option ): bool {
		if ( ! function_exists( 'wp_load_alloptions' ) || array_key_exists( $option, (array) wp_load_alloptions() ) ) {
			return false;
		}
		$value = get_option( $option, null );
		if ( null === $value ) {
			return false;
		}
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( $option, true );
		} else {
			delete_option( $option );
			add_option( $option, $value, '', 'yes' );
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
		return true;
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
		return [
			'mode'            => 'users' === ( $raw['mode'] ?? '' ) ? 'users' : 'capability',
			'user_ids'        => self::clean_ids( $raw['user_ids'] ?? null, self::MAX_USERS ),
			'hide_plugin_row' => ! empty( $raw['hide_plugin_row'] ),
		];
	}

	/**
	 * 只收正整數（或只有數字的字串）；absint() 會把 -2 變成 2，這裡不用。重複的只留一個，最多 $limit 個。
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function clean_ids( $raw, int $limit ): array {
		$ids = [];
		foreach ( is_array( $raw ) ? $raw : [] as $id ) {
			$id = is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? (int) $id : 0;
			if ( $id > 0 && count( $ids ) < $limit ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
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
		$cfg = self::get();
		if ( 'users' !== $cfg['mode'] ) {
			return true;
		}
		return self::listed_or_fallback( $cfg, (int) get_current_user_id(), is_multisite() && is_super_admin() );
	}

	/**
	 * v1.7.0：某個帳號能不能使用 YS Admin Menu（新出現的頂層選單、試算用）。規則跟 can_manage() 相同。
	 */
	public static function user_can_manage( int $user_id ): bool {
		if ( $user_id <= 0 || ! user_can( $user_id, 'manage_options' ) ) {
			return false;
		}
		$cfg = self::get();
		if ( 'users' !== $cfg['mode'] ) {
			return true;
		}
		return self::listed_or_fallback( $cfg, $user_id, is_multisite() && is_super_admin( $user_id ) );
	}

	/**
	 * 「只有指定帳號」模式下、已確認有 manage_options 的帳號：在不在清單裡（清單失效時一律可以）。
	 * 在清單裡就直接可以（自己有效＝清單至少有一個有效帳號，不會退回預設）；不在清單裡時，找到第一個有效帳號就確定不行，
	 * 不必對整份清單逐一查權限（effective() 才需要整份）。
	 *
	 * @param array{mode:string,user_ids:int[],hide_plugin_row:bool} $cfg
	 */
	private static function listed_or_fallback( array $cfg, int $user_id, bool $network_admin ): bool {
		if ( ( defined( self::RESET_CONSTANT ) && (bool) constant( self::RESET_CONSTANT ) ) || $network_admin ) {
			return true;
		}
		if ( in_array( $user_id, $cfg['user_ids'], true ) ) {
			return true;
		}
		foreach ( $cfg['user_ids'] as $id ) {
			if ( user_can( $id, 'manage_options' ) ) {
				return false;
			}
		}
		return true; // 清單裡沒有任何有效帳號：暫時改回所有管理員都能用。
	}

	/**
	 * 設定頁存檔。呼叫端已經驗過 can_manage() 與 nonce。
	 *
	 * - 「只有指定帳號」：只收存在而且有 manage_options 的帳號；按儲存的人一定在清單裡（沒勾就自動加上）。
	 * - 回到「所有管理員」時保留清單（之後再切回來不用重勾），但不生效。
	 *
	 * - 按儲存的人一律排在最前面，超過 MAX_USERS 個時截掉的是後面的帳號，不會把自己截掉（提示 too_many）。
	 *
	 * @param array<string, mixed> $raw 已 wp_unslash 的表單值（mode、user_ids、hide_plugin_row）。
	 * @return string[] 提示代碼：self_added（自動加上自己）、dropped（有帳號不存在或沒有管理權限，沒存）、too_many（超過上限，後面的沒存）。
	 */
	public static function save( array $raw, int $current_user_id ): array {
		$cfg   = self::sanitize( $raw );
		$every = self::clean_ids( $raw['user_ids'] ?? null, PHP_INT_MAX );
		// 逐一查權限之前先截到上限的兩倍（送來幾千個 ID 也只查這麼多）；按儲存的人先排到最前面，不會被截掉。
		if ( $current_user_id > 0 && in_array( $current_user_id, $every, true ) ) {
			$every = array_merge( [ $current_user_id ], array_values( array_diff( $every, [ $current_user_id ] ) ) );
		}
		$all   = array_slice( $every, 0, self::MAX_USERS * 2 );
		$notes = [];
		$ids   = array_values( array_filter( $all, static fn( int $id ): bool => user_can( $id, 'manage_options' ) ) );
		if ( count( $ids ) !== count( $all ) ) {
			$notes[] = 'dropped';
		}
		if ( 'users' === $cfg['mode'] && $current_user_id > 0 ) {
			if ( ! in_array( $current_user_id, $ids, true ) ) {
				$notes[] = 'self_added';
			}
			$ids = array_values( array_diff( $ids, [ $current_user_id ] ) );
			array_unshift( $ids, $current_user_id );
		}
		if ( count( $ids ) > self::MAX_USERS || count( $every ) > count( $all ) ) {
			$notes[] = 'too_many';
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
	 * check_admin_referer（nonce 驗過之後）：不能使用本外掛的人在外掛頁停用本外掛、或批次動作選了本外掛，一律 403
	 * （比 deactivate_plugin 那一道早，畫面上的錯誤訊息比較清楚）。checked 照核心的方式當成陣列（送純量也算），路徑先正規化。
	 *
	 * @param mixed $action
	 * @param mixed $result
	 */
	public static function guard_plugin_action( $action, $result = null ): void {
		if ( ! is_string( $action ) || ! defined( 'YS_ADMIN_MENU_BASENAME' ) ) {
			return;
		}
		$hit = str_starts_with( $action, 'deactivate-plugin_' ) && self::is_own_plugin_path( substr( $action, strlen( 'deactivate-plugin_' ) ) );
		if ( ! $hit && 'bulk-plugins' === $action && isset( $_REQUEST['checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- nonce 剛驗過
			$raw     = wp_unslash( $_REQUEST['checked'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只比對字串
			$checked = is_array( $raw ) ? $raw : [ $raw ];
			foreach ( $checked as $path ) {
				if ( is_scalar( $path ) && self::is_own_plugin_path( (string) $path ) ) {
					$hit = true;
					break;
				}
			}
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
		if ( 'delete-plugin' === $ajax && self::is_own_plugin_path( $plugin ) && self::protects_plugin_row() ) {
			wp_send_json_error( [ 'errorMessage' => __( '你沒有權限刪除 YS Admin Menu。', 'ys-admin-menu' ) ], 403 );
		}
	}
}
