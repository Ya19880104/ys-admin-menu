<?php
/**
 * 登入次數限制（v1.6.0，設計 §4）：判斷流程與 hook。
 *
 * - 外掛主檔載入時呼叫 bootstrap()：重設請求內的狀態（閘門、只算一次的旗標、IP 結果、這次記在哪個鍵、REST 標記），
 *   總開關打開、停用常數沒定義時才掛登入的 hook（不等 plugins_loaded，§4.3）。每天清理的 callback 與 login_init 的重設
 *   不看總開關（關掉之後紀錄照樣依保留天數清掉）。
 * - authenticate 1：跨站送來的瀏覽器請求（§4.13.1）與被鎖的鍵（§4.4），用跟 Turnstile 共用的閘門擋下：
 *   這一次請求暫停核心的帳密檢查（含應用程式密碼），最後一關一律回同一個錯誤，猜對猜錯、帳號存不存在都一樣。
 * - wp_login_failed 10：計數、到門檻建立鎖定；帳號存在時另記「這個鍵＋這個帳號」（§4.4.2）。
 * - wp_login 10：只原諒這個帳號自己在這次的鍵上的失敗。
 * - determine_current_user 19、rest_authentication_errors 80：REST 的應用程式密碼只執行鎖定、不計數。
 * - 這次記在哪個鍵（§4.4.1）：分不出訪客是誰的請求記到共用桶（via: 開頭），照常計數、照常鎖、不逐次加長。
 * - 計數表的上限與依網段的 overflow（§4.4 第 5 步、§4.7.3）在 YSLoginGuardCapacity：查鎖定時一起讀自己網段的
 *   overflow 桶與記列數的保留列；失敗記到哪裡、/48 彙總與「鍵＋帳號」的上限、立刻清理都經過它。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

use YangSheep\AdminMenu\Login\YSAuthGate;
use YangSheep\AdminMenu\Login\YSTurnstile;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuard {

	public const ERROR_LOCKED     = 'ys_admin_menu_login_locked';
	public const ERROR_CROSS_SITE = 'ys_admin_menu_login_cross_site';

	/** 擋下時暫停的核心 authenticate callback：帳號密碼、信箱密碼、應用程式密碼（XML-RPC 三種都收，§4.11.1）。 */
	public const LOCK_FILTERS = [ 'wp_authenticate_username_password', 'wp_authenticate_email_password', 'wp_authenticate_application_password' ];

	/** 共用桶與 /48 彙總的門檻倍數（外站 Worker 是 1 倍）。 */
	public const SHARED_MULT = 5;
	public const AGG48_MULT  = 5;

	/** 每天一次的清理（只是額外的，§4.7.3）。 */
	public const CRON_HOOK = 'ys_admin_menu_login_guard_cleanup';

	/** 查詢失敗在多久之內算成「沒有正常作用」（秒）。 */
	public const QUERY_FAILED_SECONDS = 3600;

	/** 暫時性的查詢失敗：最近一小時有幾分鐘以上才算「一再失敗」、沒有正常作用（只有一分鐘時只是提醒）。 */
	public const UNSTABLE_MINUTES = 2;

	/** 不算失敗的錯誤代碼（本外掛的鎖定與跨站拒絕、Turnstile、核心本來就不觸發的兩個）。 */
	private const NOT_COUNTED = [ self::ERROR_LOCKED, self::ERROR_CROSS_SITE, 'empty_username', 'empty_password' ];

	/** 同一個請求只算一次。 */
	private static bool $counted = false;

	/** @var null|array{d:null|array} 這個請求的鍵（null＝不判斷）。 */
	private static ?array $decision = null;

	/** @var null|array{lock:null|array{key:string,until:int}} 這個請求查過的鎖定狀態。 */
	private static ?array $lock = null;

	/** @var null|array{until:int} REST 標記：19 判斷這個 IP 被鎖、拿掉了應用程式密碼的驗證。 */
	private static ?array $rest_flag = null;

	/** @var null|string[] 這個請求的允許跨站來源。 */
	private static ?array $origins = null;

	/**
	 * 這個請求已經由本外掛擋下（跨站拒絕或鎖定）。登入頁的提醒看這個，不看閘門：wp_login_failed 0 的
	 * YSAuthGate::restore() 在 login_header 之前就把閘門打開了（實作審查 A 線 P2-1）。
	 */
	private static bool $blocked = false;

	/* ─────────────────────────── 啟動 ─────────────────────────── */

	/**
	 * 外掛主檔載入時呼叫：每個請求最早的時點（WP::init() 與 determine_current_user 之前）重設，再決定要不要掛 hook。
	 */
	public static function bootstrap(): void {
		self::reset();
		YSAuthGate::reset();
		YSClientIp::reset();
		YSLoginGuardHealth::reset();
		YSLoginGuardCapacity::reset();
		YSLoginGuardNotifier::reset();
		// 不看總開關的三個（安全審查 B 線 P2-2、P3-4；差異審查 SEC P3-1、CODE N1）：
		// - 每天清理的 callback：總開關關掉之後，紀錄照樣依保留天數清掉（cron_cleanup 自己會看資料表在不在；
		//   排程只在打開過時才建立，從沒打開過的站不會多出排程或查詢）。
		// - login_init 再重設一次這個請求記住的 IP 與判斷（設計 §3.3 第 4 步）。
		// - 管理員打開後台時，把被別的工具改成不自動載入的開關改回自動載入（只在一個都不在自動載入裡時才查）。
		add_action( self::CRON_HOOK, [ self::class, 'cron_cleanup' ] );
		add_action( 'login_init', [ self::class, 'reset_on_login_init' ], 0 );
		add_action( 'admin_init', [ YSLoginGuardSettings::class, 'heal_on_admin' ] );
		if ( ! YSLoginGuardSettings::active() ) {
			return;
		}
		self::register();
	}

	/**
	 * login_init：IP 結果與這次的判斷一起重設（只重設其中一個的話，判斷會沿用舊的 IP）。wp-login.php 上沒有 REST 的標記，
	 * 這時還沒有計數，可以安全地重設。
	 */
	public static function reset_on_login_init(): void {
		YSClientIp::reset();
		self::reset();
	}

	/** 清掉請求內的狀態。 */
	public static function reset(): void {
		self::$counted   = false;
		self::$decision  = null;
		self::$lock      = null;
		self::$rest_flag = null;
		self::$origins   = null;
		self::$blocked   = false;
	}

	public static function register(): void {
		add_filter( 'authenticate', [ self::class, 'check' ], 1, 3 );
		add_action( 'wp_login_failed', [ self::class, 'on_failed' ], 10, 2 );
		add_action( 'wp_login', [ self::class, 'on_login' ], 10, 2 );
		add_filter( 'determine_current_user', [ self::class, 'rest_pre_auth' ], 19 );
		add_filter( 'rest_authentication_errors', [ self::class, 'rest_errors' ], 80 );
		add_filter( 'shake_error_codes', [ self::class, 'shake_codes' ] );
		add_filter( 'login_message', [ self::class, 'login_message' ] );
		add_action( 'init', [ self::class, 'schedule_cleanup' ] );
		add_action( 'admin_init', [ self::class, 'maybe_ensure' ] );
		YSLoginGuardNotifier::register();
	}

	/**
	 * 外掛啟用時：總開關是開的才建表（§4.7.4）。資料表在（打開過）就把每天的清理排回去：停用時排程被取消，
	 * 再啟用時總開關若關著，紀錄照樣要依保留天數清掉（安全審查 B 線 P2-2）。沒有表的站不排。
	 */
	public static function activate(): void {
		if ( YSLoginGuardSettings::enabled() ) {
			YSLoginGuardSchema::ensure( true );
		}
		if ( YSLoginGuardSchema::is_ready() ) {
			self::schedule_cleanup();
		}
	}

	/** 外掛停用時：取消排程，不刪表、不刪設定（§6）。 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * admin_init：總開關是開的（register 才會掛）、有 manage_options、不是 admin-ajax，而且版本鍵不是目前版本或要補表
	 * （repair_pending()）時才建表。admin-ajax.php 與 admin-post.php 在權限檢查前就觸發 admin_init，所以要自己看權限。
	 * 先看權限與 admin-ajax，再讀 health：health 不自動載入，讀它可能要查一句，前台訪客的 admin-ajax、admin-post 請求不能多這一句
	 * （最終核對 A 線 F2）。
	 */
	public static function maybe_ensure(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( YSLoginGuardSchema::is_ready() && ! self::repair_pending() ) {
			return;
		}
		YSLoginGuardSchema::ensure();
	}

	/**
	 * 版本鍵是目前版本，但要檢查並補表：
	 * - 計數表在、只少紀錄表或「鍵＋帳號」小表（table_missing）；
	 * - 最近一小時有查詢回報表不存在、再確認時 SHOW TABLES 也失敗（query_failed 的 unverified，最終核對 A 線 F4）。
	 *   不知道是哪一張，交給 ensure()：dbDelta 只補不在的表與欄位，表都在時什麼都不改。問題消失一小時後就不再觸發。
	 * ensure() 成功時兩筆都會清掉。
	 */
	public static function repair_pending(): bool {
		if ( null !== YSLoginGuardHealth::get( 'table_missing' ) ) {
			return true;
		}
		$failed = YSLoginGuardHealth::get( 'query_failed' );
		return null !== $failed && 'unverified' === ( $failed['data']['kind'] ?? '' ) && time() - (int) $failed['last'] < self::QUERY_FAILED_SECONDS;
	}

	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON_HOOK );
		}
	}

	public static function cron_cleanup(): void {
		if ( ! YSLoginGuardSchema::is_ready() ) {
			return;
		}
		try {
			YSLoginGuardCapacity::run_cleanup( time(), 'manual' );
		} catch ( \Throwable $e ) {
			YSLoginGuardHealth::record( 'cleanup_failed', [ 'why' => 'cron' ] );
		}
	}

	/**
	 * 存設定之後（設定頁、WP-CLI 都呼叫這一個）：
	 * - 總開關從關到開時建表。
	 * - 跨站判斷的觀察期只在「關到開」時開始（r5，審查 R4-P3-3）：存檔前不是「總開關與子開關都開」、存檔後是，才寫開始時間。
	 *   整頁只有一張表單，存其他分頁時兩個值都沒變，不會重算。
	 *
	 * @param array{enabled?:bool,cross_site_reject?:bool} $old 存檔前的值。
	 * @param array{enabled?:bool,cross_site_reject?:bool} $new 存檔後的值。
	 */
	public static function settings_changed( array $old, array $new ): void {
		$was = ! empty( $old['enabled'] ) && ! empty( $old['cross_site_reject'] );
		$is  = ! empty( $new['enabled'] ) && ! empty( $new['cross_site_reject'] );
		if ( $is && ! $was ) {
			update_option( YSLoginGuardSettings::OPTION_CROSS_SITE_SINCE, time(), true );
		}
		// 從關到開，或開著但表不可用、要補表（少了紀錄表或小表，或表不存在而沒辦法再確認；repair_pending()）：建表。
		// 這是管理員手動存檔，不管建表失敗後 10 分鐘的冷卻（實作審查 A 線 P3-4）。
		if ( ! empty( $new['enabled'] ) && ( empty( $old['enabled'] ) || ! YSLoginGuardSchema::is_ready() || self::repair_pending() ) ) {
			YSLoginGuardSchema::ensure( true );
		}
	}

	/** 「現在就開始拒絕」：把開始時間設成觀察期以前。 */
	public static function end_observation(): void {
		update_option( YSLoginGuardSettings::OPTION_CROSS_SITE_SINCE, max( 1, time() - YSLoginGuardSettings::OBSERVE_SECONDS ), true );
	}

	/** 觀察期內（只記錄、不拒絕）。開始時間讀不到時當成已過。 */
	public static function observing( int $now ): bool {
		$since = YSLoginGuardSettings::cross_site_since();
		return $since > 0 && $now < $since + YSLoginGuardSettings::OBSERVE_SECONDS;
	}

	/* ─────────────────────────── 這次記在哪個鍵（§4.4.1） ─────────────────────────── */

	/**
	 * authenticate 1 查鎖定、wp_login_failed 10 計數、wp_login 10 原諒，三邊用同一個函式（同一個請求只算一次）。
	 *
	 * @return null|array{key:string,mult:int,shared:bool,ip:string,from_header:bool} null＝不判斷（WP-CLI、白名單）。
	 */
	public static function decide(): ?array {
		if ( null === self::$decision ) {
			self::$decision = [ 'd' => self::compute_decision() ];
		}
		return self::$decision['d'];
	}

	/**
	 * @return null|array{key:string,mult:int,shared:bool,ip:string,from_header:bool}
	 */
	private static function compute_decision(): ?array {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return null;
		}
		$r  = YSClientIp::resolve();
		$ip = (string) $r['ip'];
		if ( '' === $ip ) {
			return self::shared( 'via:unknown', self::SHARED_MULT, '', false );
		}
		// 外站的 Cloudflare Worker 在白名單之前判斷（§4.4.1 那張表的「最先判斷」；安全審查 B 線 P2-1）：它帶來的位址不可信，
		// 不能拿去比白名單。站方自己另一個 zone 的 Worker 用 ys_admin_menu_ip_own_domains 加進來。
		if ( YSClientIp::is_foreign_worker() ) {
			return self::shared( 'via:cf-worker', 1, $ip, (bool) $r['from_header'] );
		}
		if ( YSClientIp::in_any( $ip, YSLoginGuardSettings::allowlist() ) ) {
			return null;
		}
		if ( empty( $r['from_header'] ) ) {
			if ( YSClientIp::is_trusted_proxy( $ip ) ) {
				return self::shared( 'via:proxy', self::SHARED_MULT, $ip, false );
			}
			if ( YSClientIp::in_cloudflare_ranges( $ip ) ) {
				return self::shared( 'via:cloudflare', self::SHARED_MULT, $ip, false );
			}
			if ( YSClientIp::is_internal( $ip ) && ! YSLoginGuardSettings::lock_private() ) {
				return self::shared( 'via:internal', self::SHARED_MULT, $ip, false );
			}
		}
		return [
			'key'         => YSClientIp::key( $ip ),
			'mult'        => 1,
			'shared'      => false,
			'ip'          => $ip,
			'from_header' => (bool) $r['from_header'],
		];
	}

	/** @return array{key:string,mult:int,shared:bool,ip:string,from_header:bool} */
	private static function shared( string $key, int $mult, string $ip, bool $from_header ): array {
		return [ 'key' => $key, 'mult' => $mult, 'shared' => true, 'ip' => $ip, 'from_header' => $from_header ];
	}

	/** IPv6 的 IP 鍵在打開 /48 彙總時的 /48 鍵；共用桶不做 /48。 */
	public static function agg48_key( array $d ): ?string {
		if ( ! empty( $d['shared'] ) || ! str_contains( (string) $d['ip'], ':' ) || ! YSLoginGuardSettings::ipv6_aggregate() ) {
			return null;
		}
		$net = YSClientIp::network( (string) $d['ip'], 48 );
		return '' === $net ? null : 'agg48:' . $net;
	}

	/**
	 * 查鎖定時一句 IN 要帶的鍵：這次的鍵、/48 彙總的鍵；不是共用桶時再加自己網段的 overflow 桶與記列數的保留列（§4.4 第 5 步）。
	 * 全站的 via:overflow 只計數、不鎖，不在裡面。
	 *
	 * @return string[]
	 */
	private static function lookup_keys( array $d ): array {
		$keys = [ (string) $d['key'] ];
		$agg  = self::agg48_key( $d );
		if ( null !== $agg ) {
			$keys[] = $agg;
		}
		return array_values( array_unique( array_merge( $keys, YSLoginGuardCapacity::lookup_keys( $d ) ) ) );
	}

	/**
	 * 這次的鍵（與 /48 鍵）現在有沒有被鎖；這個鍵不在表裡時，再看自己網段的 overflow 桶擋不擋
	 * （表已經超過上限，或 IPv6 的 /48 已經有 256 列，r7）。查詢失敗當成沒鎖（fail-open，§4.6），已經記 health。
	 *
	 * @return null|array{key:string,until:int}
	 */
	public static function current_lock( array $d, int $now ): ?array {
		if ( null !== self::$lock ) {
			return self::$lock['lock'];
		}
		$rows = YSLoginGuardStore::rows( self::lookup_keys( $d ) );
		$lock = null;
		if ( is_array( $rows ) ) {
			if ( empty( $d['shared'] ) ) {
				YSLoginGuardCapacity::remember( $rows );
			}
			$candidates = [ (string) $d['key'] ];
			$agg        = self::agg48_key( $d );
			if ( null !== $agg ) {
				$candidates[] = $agg;
			}
			foreach ( $candidates as $key ) {
				$until = (int) ( $rows[ $key ]['locked_until'] ?? 0 );
				if ( $until > $now && ( null === $lock || $until > $lock['until'] ) ) {
					$lock = [ 'key' => $key, 'until' => $until ];
				}
			}
			$overflow = YSLoginGuardCapacity::overflow_lock( $d, $rows, $now );
			if ( null !== $overflow && ( null === $lock || $overflow['until'] > $lock['until'] ) ) {
				$lock = $overflow;
			}
		}
		self::$lock = [ 'lock' => $lock ];
		return $lock;
	}

	/* ─────────────────────────── authenticate 1 ─────────────────────────── */

	/**
	 * 帳號與密碼都不是空的時：跨站拒絕、被鎖就關閘門。看到 $user 已經是 WP_User 也一樣要檢查（不提早 return）。
	 *
	 * @param null|\WP_User|\WP_Error $user
	 * @param mixed                   $username
	 * @param mixed                   $password
	 * @return null|\WP_User|\WP_Error
	 */
	public static function check( $user, $username = '', $password = '' ) {
		if ( ! is_string( $username ) || '' === trim( $username ) || ! is_string( $password ) || '' === $password ) {
			return $user;
		}
		if ( ! YSLoginGuardSchema::is_ready() ) {
			YSLoginGuardHealth::record( 'table_not_ready' );
			return $user;
		}
		$now = time();

		// 1. 跨站送來的瀏覽器請求（§4.13.1）。
		if ( YSLoginGuardSettings::cross_site_reject() && self::is_cross_site_browser_request() ) {
			$origin = self::request_origin();
			if ( '' === $origin['normalized'] || ! in_array( $origin['normalized'], self::allowed_origins(), true ) ) {
				$observing = self::observing( $now );
				YSLoginGuardStore::log_xsite( '' !== $origin['normalized'] ? $origin['normalized'] : $origin['label'], self::entry_class(), $observing, $now );
				if ( ! $observing ) {
					self::$blocked = true;
					YSAuthGate::close( new \WP_Error( self::ERROR_CROSS_SITE, self::cross_site_message() ), self::LOCK_FILTERS );
					return YSAuthGate::error() ?? $user;
				}
			}
		}

		// 2. 被鎖的鍵（§4.4）。
		$d = self::decide();
		if ( null === $d ) {
			return $user;
		}
		$lock = self::current_lock( $d, $now );
		if ( null !== $lock ) {
			self::$blocked = true;
			YSLoginGuardStore::note_blocked( $lock['key'], $now );
			YSAuthGate::close( self::locked_error( $lock['until'] - $now ), self::LOCK_FILTERS );
			return YSAuthGate::error() ?? $user;
		}
		return $user;
	}

	/* ─────────────────────────── wp_login_failed 10 ─────────────────────────── */

	/**
	 * 計數。涵蓋 wp-login.php、改過的網址、XML-RPC、前台 wp_signon()、任何 wp_authenticate()。
	 *
	 * @param mixed $username
	 * @param mixed $error
	 */
	public static function on_failed( $username, $error = null ): void {
		if ( self::$counted || ! YSLoginGuardSchema::is_ready() ) {
			return;
		}
		$code = $error instanceof \WP_Error ? (string) $error->get_error_code() : '';
		if ( in_array( $code, self::NOT_COUNTED, true ) || YSTurnstile::ERROR_CODE === $code ) {
			return;
		}
		$username = is_string( $username ) ? $username : '';
		/**
		 * 這一次登入失敗要不要計數（v1.6.0）。例如 2FA 外掛回「需要第二步驗證」時密碼其實是對的，可以用這個 filter 排除。
		 *
		 * @since 1.6.0
		 *
		 * @param bool          $count    預設 true。
		 * @param null|WP_Error $error    核心最後回的錯誤。
		 * @param string        $username 嘗試的帳號（原文，不要寫進紀錄或輸出）。
		 */
		if ( ! apply_filters( 'ys_admin_menu_login_guard_should_count', true, $error instanceof \WP_Error ? $error : null, $username ) ) {
			return;
		}
		$d = self::decide();
		if ( null === $d ) {
			return;
		}
		self::$counted = true;
		$now           = time();
		$source        = self::source();
		$ip            = (string) $d['ip'];

		// 記到哪裡由上限決定（§4.4 失敗時第 2 步）：自己的鍵、自己網段的 overflow 桶，或全站的 via:overflow（只計數、不鎖）。
		$target = YSLoginGuardCapacity::record( $d, $now, $source );
		if ( null !== $target ) {
			self::maybe_lock( $target, $ip, $now, $source, $username );
		}

		$agg = self::agg48_key( $d );
		if ( null !== $agg ) {
			$agg_count = YSLoginGuardCapacity::record_agg48( $agg, $ip, $now, $source );
			if ( null !== $agg_count ) {
				self::maybe_lock( [ 'key' => $agg, 'count' => $agg_count, 'mult' => self::AGG48_MULT, 'shared' => false, 'lockable' => true, 'escalate' => true, 'overflow' => '' ], $ip, $now, $source, $username );
			}
		}

		if ( null !== $target ) {
			YSLoginGuardStore::set_username( $target['key'], $username );
			$user = self::existing_user( $username );
			if ( null !== $user && 'global' !== $target['overflow'] ) {
				$window = YSLoginGuardStore::window_start( $target['key'] );
				if ( null !== $window && $window > 0 ) {
					YSLoginGuardCapacity::record_acct( $target['key'], YSLoginGuardStore::acct_hash( (int) $user->ID ), $window, $now );
				}
			}
			YSLoginGuardStore::log_fail( $target['key'], $ip, $username, $source, $code, $now );
		}
		self::note_stats( $d, $now );
		YSLoginGuardCapacity::after_failure( $now );
	}

	/**
	 * 計數到門檻就建立鎖定（只有建立了的那個請求寫鎖定紀錄、觸發通知）。全站的 via:overflow 只計數、不鎖（lockable＝false）。
	 * 共用桶與 overflow 分桶不逐次加長（escalate＝false）。
	 *
	 * @param array{key:string,count:int,mult:int,shared:bool,lockable:bool,escalate:bool,overflow:string} $target
	 */
	private static function maybe_lock( array $target, string $ip, int $now, string $source, string $username ): void {
		if ( empty( $target['lockable'] ) ) {
			return;
		}
		$threshold = YSLoginGuardSettings::max_failures() * max( 1, (int) $target['mult'] );
		if ( (int) $target['count'] < $threshold ) {
			return;
		}
		$until = YSLoginGuardStore::lock(
			(string) $target['key'],
			$now,
			$threshold,
			YSLoginGuardSettings::lockout_seconds(),
			! empty( $target['escalate'] ) && YSLoginGuardSettings::escalate(),
			YSLoginGuardSettings::escalate_after(),
			YSLoginGuardSettings::escalate_period_seconds(),
			YSLoginGuardSettings::escalated_lockout_seconds()
		);
		if ( null === $until ) {
			return;
		}
		self::$lock = null;
		YSLoginGuardStore::log_event(
			YSLoginGuardStore::EVENT_LOCKOUT,
			[
				'ip'       => $ip,
				'ip_key'   => (string) $target['key'],
				'username' => $username,
				'source'   => $source,
				'code'     => (string) ( $until - $now ),
			],
			$now
		);
		/**
		 * 建立了一次鎖定（v1.6.0，通知用）。同一次鎖定只有一個請求會觸發。
		 *
		 * @since 1.6.0
		 *
		 * @param array{key:string,ip:string,until:int,source:string,shared:bool,count:int} $lock count：鎖定時的失敗次數。
		 */
		do_action(
			'ys_admin_menu_login_guard_locked',
			[
				'key'    => (string) $target['key'],
				'ip'     => $ip,
				'until'  => $until,
				'source' => $source,
				'shared' => ! empty( $target['shared'] ),
				'count'  => (int) $target['count'],
			]
		);
	}

	/** 健康統計：記到共用桶、外站 Worker、從標頭取到內部或 CDN 位址（只在發生時寫，§4.7.2）。 */
	private static function note_stats( array $d, int $now ): void {
		if ( 'via:cf-worker' === $d['key'] ) {
			YSLoginGuardStore::stat( [ 'lockout_count' => 1 ], $now );
			YSLoginGuardHealth::record( 'cf_worker' );
			return;
		}
		if ( ! empty( $d['shared'] ) ) {
			YSLoginGuardStore::stat( [ 'total_failures' => 1 ], $now );
			YSLoginGuardHealth::record( 'shared_bucket', [ 'key' => (string) $d['key'] ] );
			return;
		}
		if ( ! empty( $d['from_header'] ) && ( YSClientIp::is_internal( (string) $d['ip'] ) || YSClientIp::in_cloudflare_ranges( (string) $d['ip'] ) ) ) {
			YSLoginGuardStore::stat( [ 'blocked_count' => 1 ], $now );
		}
	}

	/* ─────────────────────────── wp_login 10 ─────────────────────────── */

	/**
	 * 登入成功：只原諒這個帳號自己在這次的鍵上、同一個視窗裡的失敗（§4.4.2）。XML-RPC 與 REST 的成功不觸發 wp_login。
	 * 計數表滿了的時候失敗會記到自己網段的 overflow 桶（§4.7.3），那裡的也一樣只扣這個帳號自己的。
	 *
	 * @param mixed $user_login
	 * @param mixed $user
	 */
	public static function on_login( $user_login, $user = null ): void {
		if ( ! $user instanceof \WP_User || ! YSLoginGuardSchema::is_ready() ) {
			return;
		}
		$d = self::decide();
		if ( null === $d ) {
			return;
		}
		$keys = [ (string) $d['key'] ];
		if ( empty( $d['shared'] ) ) {
			$keys[] = YSLoginGuardCapacity::overflow_key( (string) $d['ip'] );
		}
		$acct = YSLoginGuardStore::acct_hash( (int) $user->ID );
		$now  = time();
		foreach ( array_filter( array_unique( $keys ) ) as $key ) {
			YSLoginGuardStore::acct_forgive( $key, $acct, $now );
		}
	}

	/* ─────────────────────────── REST（19／80） ─────────────────────────── */

	/**
	 * determine_current_user 19：還沒有人認證、帶 Basic、站上用過應用程式密碼、這個 IP 被鎖 →
	 * 這一次請求拿掉核心 20 的 wp_validate_application_password（不算雜湊，猜對猜錯回應一樣），做標記。
	 *
	 * @param int|false $user_id
	 * @return int|false
	 */
	public static function rest_pre_auth( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		if ( ! isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			return $user_id;
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! \WP_Application_Passwords::is_in_use() || ! YSLoginGuardSchema::is_ready() ) {
			return $user_id;
		}
		$d = self::decide();
		if ( null === $d ) {
			return $user_id;
		}
		$lock = self::current_lock( $d, time() );
		if ( null === $lock ) {
			return $user_id;
		}
		remove_filter( 'determine_current_user', 'wp_validate_application_password', 20 );
		self::$rest_flag = [ 'until' => $lock['until'] ];
		return $user_id;
	}

	/**
	 * rest_authentication_errors 80：$result 非空或沒有標記 → 原樣回傳，不碰目前使用者。
	 * 有標記時：已經被 cookie 認證（目前使用者大於 0）→ 原樣回傳；否則回 429。
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function rest_errors( $result ) {
		if ( ! empty( $result ) || null === self::$rest_flag ) {
			return $result;
		}
		if ( get_current_user_id() > 0 ) {
			return $result;
		}
		add_filter( 'rest_post_dispatch', [ self::class, 'rest_retry_after' ], 10, 1 );
		return new \WP_Error( self::ERROR_LOCKED, self::lock_message_text( self::$rest_flag['until'] - time() ), [ 'status' => 429 ] );
	}

	/**
	 * 429 的回應帶 Retry-After（秒）。
	 *
	 * @param mixed $response
	 * @return mixed
	 */
	public static function rest_retry_after( $response ) {
		if ( null !== self::$rest_flag && $response instanceof \WP_REST_Response && 429 === $response->get_status() ) {
			$response->header( 'Retry-After', (string) max( 1, self::$rest_flag['until'] - time() ) );
		}
		return $response;
	}

	/* ─────────────────────────── 登入頁 ─────────────────────────── */

	/**
	 * 鎖定與跨站拒絕的錯誤也讓登入框晃一下。
	 *
	 * @param string[] $codes
	 * @return string[]
	 */
	public static function shake_codes( $codes ): array {
		$codes   = is_array( $codes ) ? $codes : [];
		$codes[] = self::ERROR_LOCKED;
		$codes[] = self::ERROR_CROSS_SITE;
		return $codes;
	}

	/**
	 * 打開登入頁（login 動作）時，這個 IP 正在鎖定中，就在表單上方顯示鎖定訊息；不計數。
	 * 這一次請求已經由本外掛擋下（回了鎖定或跨站拒絕的錯誤，#login_error 會顯示）時不重複顯示。忘記密碼、註冊畫面不顯示。
	 *
	 * @param mixed $message
	 * @return mixed
	 */
	public static function login_message( $message ) {
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只決定要不要顯示提醒
		if ( 'login' !== $action || self::$blocked || ! YSLoginGuardSchema::is_ready() ) {
			return $message;
		}
		$d = self::decide();
		if ( null === $d ) {
			return $message;
		}
		$now  = time();
		$lock = self::current_lock( $d, $now );
		if ( null === $lock ) {
			return $message;
		}
		return (string) $message . '<div class="notice notice-error message ys-am-login-locked"><p>' . esc_html( self::lock_message_text( $lock['until'] - $now ) ) . '</p></div>';
	}

	/* ─────────────────────────── 訊息（§4.5） ─────────────────────────── */

	public static function locked_error( int $seconds ): \WP_Error {
		return new \WP_Error(
			self::ERROR_LOCKED,
			'<strong>' . esc_html__( '錯誤', 'ys-admin-menu' ) . '</strong>：' . esc_html( self::lock_message_text( $seconds ) )
		);
	}

	/** 純文字的鎖定訊息：第一句可以換（設定，留空用內建文字），後面接大約幾分鐘後可以再試。 */
	public static function lock_message_text( int $seconds ): string {
		$first = YSLoginGuardSettings::message();
		if ( '' === $first ) {
			$first = '登入失敗太多次，這個網路位址暫時不能登入。';
		}
		return $first . self::retry_text( $seconds );
	}

	/** 分鐘數往上取整；不到 1 分鐘寫「不到 1 分鐘」。 */
	public static function retry_text( int $seconds ): string {
		if ( $seconds < 60 ) {
			return '不到 1 分鐘後可以再試。';
		}
		return sprintf( '大約 %d 分鐘後可以再試。', (int) ceil( $seconds / 60 ) );
	}

	public static function cross_site_message(): string {
		return '<strong>' . esc_html__( '錯誤', 'ys-admin-menu' ) . '</strong>：' . esc_html__( '這個登入要求來自其他網站，已經拒絕。請直接打開本站的登入頁再試一次。', 'ys-admin-menu' );
	}

	/* ─────────────────────────── 跨站判斷（§4.13.1） ─────────────────────────── */

	/**
	 * 瀏覽器宣告的跨站請求：有 Sec-Fetch-Site 就只看它（cross-site 才算）；沒有時拿 Origin 跟這次請求的 Host
	 * （加上本站主機與 filter）比；Origin: null 算跨站；兩個都沒有（非瀏覽器用戶端、很舊的瀏覽器）不算。
	 */
	public static function is_cross_site_browser_request(): bool {
		$site = strtolower( trim( self::server( 'HTTP_SEC_FETCH_SITE' ) ) );
		if ( '' !== $site ) {
			return 'cross-site' === $site;
		}
		$origin = trim( self::server( 'HTTP_ORIGIN' ) );
		if ( '' === $origin ) {
			return false;
		}
		if ( 'null' === strtolower( $origin ) ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$req  = strtolower( (string) wp_parse_url( 'http://' . self::server( 'HTTP_HOST' ), PHP_URL_HOST ) );
		return '' === $host || ( $host !== $req && ! in_array( $host, self::own_hosts(), true ) );
	}

	/**
	 * 本站的主機（主機完全相同才算；跟 CF-Worker 的 zone 規則分開，審查 R3-P3-3）。
	 *
	 * @return string[]
	 */
	public static function own_hosts(): array {
		$hosts = [];
		foreach ( [ home_url(), site_url() ] as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		/**
		 * 跨站判斷時算成本站的主機（v1.6.0）。主機要完全相同才算。
		 *
		 * @since 1.6.0
		 *
		 * @param string[] $hosts 預設是 home_url 與 siteurl 的主機。
		 */
		$hosts = apply_filters( 'ys_admin_menu_login_guard_own_hosts', $hosts );
		return array_values( array_unique( array_filter( array_map( static fn( $h ) => is_string( $h ) ? strtolower( trim( $h ) ) : '', (array) $hosts ) ) ) );
	}

	/**
	 * 這次請求的 Origin：正規化後的值（不合法時空字串），與記紀錄用的標籤（null、沒有，或截短的原文）。
	 *
	 * @return array{normalized:string,label:string}
	 */
	public static function request_origin(): array {
		$raw = trim( self::server( 'HTTP_ORIGIN' ) );
		if ( '' === $raw ) {
			return [ 'normalized' => '', 'label' => '(none)' ];
		}
		$normalized = YSLoginGuardSettings::normalize_origin( $raw );
		$label      = substr( (string) preg_replace( '/[^\x21-\x7E]/', '', $raw ), 0, 255 );
		return [ 'normalized' => $normalized, 'label' => '' !== $label ? $label : '(invalid)' ];
	}

	/**
	 * 允許跨站登入的來源：設定、filter、YS CART 的 headless 前台設定、核心的 allowed_http_origins（都正規化）。
	 *
	 * @return string[]
	 */
	public static function allowed_origins(): array {
		if ( null === self::$origins ) {
			$list = YSLoginGuardSettings::cross_site_origins();
			/**
			 * 另外允許跨站送帳密的來源（v1.6.0），一個 https://主機[:埠] 一個字串。給另一個網域上的前台用。
			 *
			 * @since 1.6.0
			 *
			 * @param string[] $origins 預設空陣列。
			 */
			foreach ( (array) apply_filters( 'ys_admin_menu_login_guard_allow_cross_site', [] ) as $origin ) {
				$list[] = is_string( $origin ) ? YSLoginGuardSettings::normalize_origin( $origin ) : '';
			}
			$auto          = self::auto_origins();
			$list          = array_merge( $list, $auto['yscart'], $auto['core'] );
			self::$origins = array_values( array_unique( array_filter( $list ) ) );
		}
		return self::$origins;
	}

	/**
	 * 自動帶入的來源（設定頁唯讀列出來）：YS CART 的 headless_cors_origins、核心的 get_allowed_http_origins()。
	 * YS CART 不在、方法不存在或出錯時略過。
	 *
	 * @return array{yscart:string[],core:string[]}
	 */
	public static function auto_origins(): array {
		$yscart = [];
		$class  = '\\YangSheep\\Ecommerce\\YSEcommerce';
		try {
			if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
				$ec = $class::get_instance();
				if ( is_object( $ec ) && method_exists( $ec, 'get_setting' ) ) {
					$raw = $ec->get_setting( 'headless_cors_origins', '' );
					foreach ( preg_split( '/\r\n|\r|\n/', is_string( $raw ) ? $raw : '' ) as $line ) {
						$yscart[] = YSLoginGuardSettings::normalize_origin( (string) $line );
					}
				}
			}
		} catch ( \Throwable $e ) {
			$yscart = [];
		}
		$core = [];
		if ( function_exists( 'get_allowed_http_origins' ) ) {
			foreach ( (array) get_allowed_http_origins() as $origin ) {
				$core[] = is_string( $origin ) ? YSLoginGuardSettings::normalize_origin( $origin ) : '';
			}
		}
		return [
			'yscart' => array_values( array_unique( array_filter( $yscart ) ) ),
			'core'   => array_values( array_unique( array_filter( $core ) ) ),
		];
	}

	/** 入口類別：xmlrpc、REST 路徑前綴（例如 /ys-ecommerce-headless/v1）、wp-login、other。 */
	public static function entry_class(): string {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ? (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ) : '';
			$parts = array_values( array_filter( explode( '/', strtolower( $route ) ) ) );
			$path  = '/' . implode( '/', array_slice( $parts, 0, 2 ) );
			$path  = (string) preg_replace( '/[^a-z0-9_\/-]/', '', $path );
			return '/' !== $path ? substr( $path, 0, 40 ) : 'rest';
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return 'wp-login';
		}
		return 'other';
	}

	/* ─────────────────────────── 健康狀態 ─────────────────────────── */

	/**
	 * 有開但沒有正常作用（§4.10）：資料表不可用；最近一小時有查詢失敗（query_failed）；最近一小時有兩分鐘以上暫時性的
	 * 查詢失敗（query_unstable）；或最近一輪記到 via:proxy、
	 * via:cloudflare、via:internal、via:unknown 的次數合計 20 次以上、而且占失敗總數一半以上（站多半在代理後面、IP 偵測沒設好）。
	 *
	 * @return array{effective:bool,reasons:string[],stats:array}
	 */
	public static function status(): array {
		if ( ! YSLoginGuardSchema::is_ready() ) {
			return [ 'effective' => false, 'reasons' => [ 'table_not_ready' ], 'stats' => [] ];
		}
		$stats   = YSLoginGuardStore::stat24();
		$reasons = [];
		// 最近一小時有查詢失敗（計數表不見、沒有權限、不明的錯誤）：失敗的那幾次照設計放行，但一定要算成沒有正常作用
		// （§4.6、§4.10；實作審查 A 線 P2-3）。死結、逾時、連線斷掉這類暫時性的錯誤另外算：一小時內有兩分鐘以上才算
		// （差異審查 SEC P3-4、CODE N2）。
		$failed = YSLoginGuardHealth::get( 'query_failed' );
		if ( null !== $failed && time() - (int) $failed['last'] < self::QUERY_FAILED_SECONDS ) {
			$reasons[] = 'query_failed';
		}
		if ( self::transient_minutes( time() ) >= self::UNSTABLE_MINUTES ) {
			$reasons[] = 'query_unstable';
		}
		if ( $stats['shared'] >= 20 && 2 * $stats['shared'] > $stats['failures'] ) {
			$reasons[] = 'shared_bucket';
		}
		return [ 'effective' => [] === $reasons, 'reasons' => $reasons, 'stats' => $stats ];
	}

	/** 暫時性的查詢失敗：最近一小時有幾分鐘有（health 同一個原因每分鐘最多寫一次，一筆就是一分鐘）。 */
	public static function transient_minutes( int $now ): int {
		$row = YSLoginGuardHealth::get( 'query_transient' );
		$n   = 0;
		foreach ( (array) ( $row['data']['recent'] ?? [] ) as $t ) {
			if ( is_numeric( $t ) && $now - (int) $t < self::QUERY_FAILED_SECONDS ) {
				++$n;
			}
		}
		return $n;
	}

	/* ─────────────────────────── 工具 ─────────────────────────── */

	/** 來源：login（wp-login.php）、xmlrpc、rest、other。 */
	private static function source(): string {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ? 'login' : 'other';
	}

	/** 嘗試的帳號是不是站上存在的使用者（帳號或信箱）。 */
	private static function existing_user( string $username ): ?\WP_User {
		if ( '' === $username ) {
			return null;
		}
		$user = get_user_by( 'login', $username );
		if ( ! $user instanceof \WP_User && is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}
		return $user instanceof \WP_User ? $user : null;
	}

	private static function server( string $key ): string {
		$value = $_SERVER[ $key ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 呼叫的地方自己驗證
		return is_string( $value ) ? $value : '';
	}
}
