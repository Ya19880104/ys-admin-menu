<?php
/**
 * 登入次數限制的上限與 overflow（v1.6.0，設計 §4.4 第 5 步、§4.4.1、§4.7.2、§4.7.3，r7）。SQL 都在 YSLoginGuardStore。
 *
 * 寫入時就強制上限：
 * - 計數表的 IP 鍵（含 agg48:）最多 50,000 列，用保留列 ~rows 原子計數：只在真的新增新鍵時加 1，清理後設成實際列數。
 * - 每個 /48 最多 256 列（net48 欄）：IPv6 的 IP 鍵一律先 UPDATE 自己那一列；沒有列時才數它的 /48，到 256 就不新增。
 *   寫入 net48 與比對 net48 都用 YSClientIp::network( $ip, 48 )（跟 key() 同一套正規化，審查 P3-1）。
 * - 表已經滿了的新鍵、/48 已經有 256 列的新 /64：記到自己網段的 overflow 桶（via:overflow: 加 IPv6 /48 或 IPv4 /24），
 *   5 倍門檻、不逐次加長。分桶可以超過上限新增，有自己的上限 5,000 列（~rows_ovf）。
 * - 分桶也滿了：記到全站的 via:overflow，只計數、不鎖，查鎖定時也不看它（審查 R4-P2-1）。
 * - 表滿了時也不新增 agg48: 鍵（overflow 已經按 /48 分桶）；共用桶不受上限影響。
 * - 「鍵＋帳號」小表最多 50,000 列（~rows_acct），滿了不記新列（只是少了原諒）。
 * - 記列數的保留列讀不到時當成已經滿了（安全的方向），記 health，同一個請求立刻清理把它補回來、設成實際列數。
 *
 * 查鎖定（§4.4 第 5 步，r7 依審查附註更正）：這個鍵不在表裡，而且「表已經超過上限」**或**「是 IPv6、它的 /48 已經有 256 列」
 * → 看自己網段的 overflow 桶，桶鎖著就擋。表已經超過上限時第一個條件就成立，不數列數；/48 的列數只在表還沒滿、
 * 那個桶又鎖著時才數。表滿之後 IPv4 與 IPv6 一樣：每個網段共用一個 5 倍的桶。
 *
 * 立刻清理（§4.7.3 第 1 項）：同一時間只有一個請求在做。GET_LOCK 的鎖名帶資料庫名稱與資料表前綴；回 NULL 或查詢錯誤時
 * 改用 ~cleanup 的 30 秒租約；兩種都在 finally 裡釋放。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardCapacity {

	/** 計數表的 IP 鍵（含 agg48:）最多幾列；照上限刪時刪到幾列（騰出空間給新鍵）。 */
	public const ROWS_MAX = 50000;
	public const ROWS_LOW = 45000;

	/** overflow 分桶最多幾列、「鍵＋帳號」小表最多幾列、每個 /48 最多幾列。 */
	public const OVF_MAX   = 5000;
	public const ACCT_MAX  = 50000;
	public const PER48_MAX = 256;

	/** 記失敗時順手清理的觸發條件：上次清理後新增超過幾列、記到 overflow 超過幾次。 */
	public const NEW_ROWS_TRIGGER = 2000;
	public const OVF_TRIGGER      = 200;

	/** ~cleanup 租約的長度（秒）；別的請求剛清完多久內不重做（秒）。 */
	public const LEASE_SECONDS  = 30;
	public const RECENT_SECONDS = 1;

	/** overflow 分桶的鍵前綴、全站的 overflow 鍵。 */
	public const OVERFLOW_PREFIX = 'via:overflow:';
	public const OVERFLOW_GLOBAL = 'via:overflow';

	/** 記列數的保留列（查鎖定那一句 IN 一起讀）。 */
	public const STATE_ROWS = [ '~rows', '~rows_ovf', '~rows_acct' ];

	/** @var null|array{ok:bool,rows:array<string,null|array>} 這個請求讀到的記列數保留列。 */
	private static ?array $state = null;

	/** 這個請求要不要立刻清理：''＝不用、full＝表滿了記到 overflow、reserved＝記列數的保留列不見了或剛補回來。 */
	private static string $cleanup_now = '';

	/** 清掉請求內的狀態（每個請求開始時、清理之後）。 */
	public static function reset(): void {
		self::$state       = null;
		self::$cleanup_now = '';
	}

	/* ─────────────────────────── 保留列 ─────────────────────────── */

	/**
	 * 查鎖定那一句一起讀到的列：只留記列數的保留列。
	 *
	 * @param array<string, array> $rows
	 */
	public static function remember( array $rows ): void {
		$state = [];
		foreach ( self::STATE_ROWS as $row ) {
			$state[ $row ] = isset( $rows[ $row ] ) && is_array( $rows[ $row ] ) ? $rows[ $row ] : null;
		}
		self::$state = [ 'ok' => true, 'rows' => $state ];
	}

	/** @return array{ok:bool,rows:array<string,null|array>} */
	private static function state(): array {
		if ( null === self::$state ) {
			$rows = YSLoginGuardStore::rows( self::STATE_ROWS );
			if ( null === $rows ) {
				self::$state = [ 'ok' => false, 'rows' => [] ];
			} else {
				self::remember( $rows );
			}
		}
		return self::$state;
	}

	/**
	 * 一列記列數的保留列。讀不到（不見了，或查詢失敗）回 null：記 health；不見了時要求立刻清理（清理開頭會補回來）。
	 *
	 * @return null|array<string, int>
	 */
	private static function reserved( string $row ): ?array {
		$state = self::state();
		$value = $state['rows'][ $row ] ?? null;
		if ( null === $value ) {
			YSLoginGuardHealth::record( 'reserved_row_missing', [ 'row' => $row, 'why' => $state['ok'] ? 'missing' : 'query' ] );
			if ( $state['ok'] ) {
				self::$cleanup_now = 'reserved';
			}
		}
		return $value;
	}

	/** 計數表已經超過上限（~rows 讀不到時當成已經超過，r5 審查 R4-P2-2）。 */
	public static function table_full(): bool {
		$row = self::reserved( '~rows' );
		return null === $row || (int) $row['fail_count'] >= self::ROWS_MAX;
	}

	/** overflow 分桶已經滿了（~rows_ovf 讀不到時當成滿了：新網段記到全站的 via:overflow，表的大小有界）。 */
	public static function ovf_full(): bool {
		$row = self::reserved( '~rows_ovf' );
		return null === $row || (int) $row['fail_count'] >= self::OVF_MAX;
	}

	/** 「鍵＋帳號」小表已經滿了（讀不到時當成滿了：只是少了原諒）。 */
	public static function acct_full(): bool {
		$row = self::reserved( '~rows_acct' );
		return null === $row || (int) $row['fail_count'] >= self::ACCT_MAX;
	}

	/**
	 * 保留列的原子加 1。這一列剛剛不見了、現在補回來時記 health，同一個請求立刻清理（把它設回實際列數）。
	 *
	 * @param array<string, int> $add
	 */
	private static function bump( string $row, array $add, int $now ): void {
		if ( true === YSLoginGuardStore::bump_reserved( $row, $add, $now ) ) {
			YSLoginGuardHealth::record( 'reserved_row_recreated', [ 'row' => $row ] );
			self::$cleanup_now = 'reserved';
		}
	}

	/* ─────────────────────────── 鍵 ─────────────────────────── */

	/** 這個位址的 overflow 分桶：IPv6 用 /48、IPv4 用 /24（r5 審查 R4-P1-1）。位址不合法回空字串。 */
	public static function overflow_key( string $ip ): string {
		$net = YSClientIp::network( $ip, str_contains( $ip, ':' ) ? 48 : 24 );
		return '' === $net ? '' : self::OVERFLOW_PREFIX . $net;
	}

	/**
	 * 查鎖定那一句要多帶的鍵：自己網段的 overflow 桶與記列數的保留列。共用桶不受上限影響，什麼都不多帶。
	 *
	 * @param array{key:string,shared:bool,ip:string} $d
	 * @return string[]
	 */
	public static function lookup_keys( array $d ): array {
		if ( ! empty( $d['shared'] ) ) {
			return [];
		}
		return array_values( array_filter( array_merge( [ self::overflow_key( (string) $d['ip'] ) ], self::STATE_ROWS ) ) );
	}

	/**
	 * 這個鍵不在表裡時，自己網段的 overflow 桶擋不擋（§4.4 第 5 步的「或」，r7）：
	 * 桶鎖著，而且「表已經超過上限」或「是 IPv6、它的 /48 已經有 256 列」。表已經超過上限時不數列數。
	 *
	 * @param array{key:string,shared:bool,ip:string} $d
	 * @param array<string, array>                    $rows 查鎖定那一句的結果。
	 * @return null|array{key:string,until:int}
	 */
	public static function overflow_lock( array $d, array $rows, int $now ): ?array {
		if ( ! empty( $d['shared'] ) || isset( $rows[ (string) $d['key'] ] ) ) {
			return null;
		}
		$ip     = (string) $d['ip'];
		$bucket = self::overflow_key( $ip );
		$until  = '' === $bucket ? 0 : (int) ( $rows[ $bucket ]['locked_until'] ?? 0 );
		if ( $until <= $now ) {
			return null;
		}
		if ( self::table_full() || ( str_contains( $ip, ':' ) && self::per48_full( $ip ) ) ) {
			return [ 'key' => $bucket, 'until' => $until ];
		}
		return null;
	}

	/** 這個位址的 /48 已經有 256 列（數不出來時當成有，安全的方向；只用在表還沒滿的時候）。 */
	private static function per48_full( string $ip ): bool {
		$net48 = YSClientIp::network( $ip, 48 );
		$count = '' === $net48 ? null : YSLoginGuardStore::count48( $net48, self::PER48_MAX );
		return null === $count || $count >= self::PER48_MAX;
	}

	/* ─────────────────────────── 記失敗 ─────────────────────────── */

	/**
	 * 記一次失敗（§4.4 失敗時第 2 步）：決定記到哪個鍵，在資料庫裡加 1。
	 *
	 * - 共用桶：照常計數（不受上限影響）。
	 * - IPv6 的 IP 鍵：先 UPDATE 自己那一列；沒有列時，表滿了或它的 /48 已經有 256 列 → 自己網段的 overflow 桶；
	 *   否則新增（帶 net48），~rows 加 1。
	 * - IPv4 的 IP 鍵：表沒滿時一句 INSERT … ON DUPLICATE KEY UPDATE（新增時 ~rows 加 1）；表滿了時先 UPDATE 自己那一列，
	 *   沒有列 → 自己網段的 overflow 桶。
	 *
	 * @param array{key:string,mult:int,shared:bool,ip:string} $d
	 * @return null|array{key:string,count:int,mult:int,shared:bool,lockable:bool,escalate:bool,overflow:string}
	 *   記到哪個鍵、加完的次數、門檻倍數、是不是共用的桶、能不能建立鎖定、能不能逐次加長、overflow（''、bucket、global）。
	 *   查詢失敗回 null（已經記 health）。
	 */
	public static function record( array $d, int $now, string $source ): ?array {
		$key    = (string) $d['key'];
		$ip     = (string) $d['ip'];
		$window = YSLoginGuardSettings::window_seconds();
		if ( ! empty( $d['shared'] ) ) {
			$r = YSLoginGuardStore::count_failure( $key, $ip, $source, $now, $window );
			return null === $r ? null : self::target( $key, $r['count'], max( 1, (int) $d['mult'] ), true, true, false, '' );
		}
		if ( str_contains( $ip, ':' ) ) {
			$r = YSLoginGuardStore::count_existing( $key, $ip, $source, $now, $window );
			if ( null === $r ) {
				return null;
			}
			if ( $r['matched'] ) {
				return self::target( $key, $r['count'], 1, false, true, true, '' );
			}
			if ( self::table_full() ) {
				return self::record_overflow( $ip, $now, $source, 'full' );
			}
			$net48 = YSClientIp::network( $ip, 48 );
			$count = YSLoginGuardStore::count48( $net48, self::PER48_MAX );
			if ( null === $count || $count >= self::PER48_MAX ) {
				return self::record_overflow( $ip, $now, $source, 'per48' );
			}
			$r = YSLoginGuardStore::count_failure( $key, $ip, $source, $now, $window, $net48 );
		} elseif ( self::table_full() ) {
			$r = YSLoginGuardStore::count_existing( $key, $ip, $source, $now, $window );
			if ( null === $r ) {
				return null;
			}
			if ( $r['matched'] ) {
				return self::target( $key, $r['count'], 1, false, true, true, '' );
			}
			return self::record_overflow( $ip, $now, $source, 'full' );
		} else {
			$r = YSLoginGuardStore::count_failure( $key, $ip, $source, $now, $window );
		}
		if ( null === $r ) {
			return null;
		}
		if ( $r['inserted'] ) {
			self::bump( '~rows', [ 'fail_count' => 1, 'total_failures' => 1 ], $now );
		}
		return self::target( $key, $r['count'], 1, false, true, true, '' );
	}

	/**
	 * 記到自己網段的 overflow 桶（5 倍、不逐次加長）；分桶也滿了而且這個網段還沒有桶時，記到全站的 via:overflow（只計數、不鎖）。
	 * 表滿了時，記到 overflow 的這個請求立刻清理。
	 *
	 * @param string $why full（表滿了）、per48（這個 /48 已經有 256 列）。
	 * @return null|array{key:string,count:int,mult:int,shared:bool,lockable:bool,escalate:bool,overflow:string}
	 */
	private static function record_overflow( string $ip, int $now, string $source, string $why ): ?array {
		$window = YSLoginGuardSettings::window_seconds();
		$bucket = self::overflow_key( $ip );
		if ( self::table_full() && '' === self::$cleanup_now ) {
			self::$cleanup_now = 'full';
		}
		self::bump( '~rows', [ 'blocked_count' => 1 ], $now );
		if ( '' !== $bucket ) {
			$r = null;
			if ( self::ovf_full() ) {
				$existing = YSLoginGuardStore::count_existing( $bucket, $ip, $source, $now, $window );
				if ( null === $existing ) {
					return null;
				}
				if ( $existing['matched'] ) {
					$r = [ 'count' => $existing['count'] ];
				}
			} else {
				$inserted = YSLoginGuardStore::count_failure( $bucket, $ip, $source, $now, $window );
				if ( null === $inserted ) {
					return null;
				}
				if ( $inserted['inserted'] ) {
					self::bump( '~rows_ovf', [ 'fail_count' => 1 ], $now );
				}
				$r = [ 'count' => $inserted['count'] ];
			}
			if ( null !== $r ) {
				self::note_overflow( 'overflow', $bucket, $why );
				return self::target( $bucket, (int) $r['count'], YSLoginGuard::SHARED_MULT, true, true, false, 'bucket' );
			}
		}
		$global = YSLoginGuardStore::count_failure( self::OVERFLOW_GLOBAL, $ip, $source, $now, $window );
		if ( null === $global ) {
			return null;
		}
		self::note_overflow( 'overflow_global', self::OVERFLOW_GLOBAL, $why );
		return self::target( self::OVERFLOW_GLOBAL, $global['count'], YSLoginGuard::SHARED_MULT, true, false, false, 'global' );
	}

	/**
	 * 記 health（同一種原因每分鐘最多一次）；有寫入時觸發 ys_admin_menu_login_guard_overflow（通知用）。
	 */
	private static function note_overflow( string $reason, string $key, string $why ): void {
		if ( ! YSLoginGuardHealth::record( $reason, [ 'key' => $key, 'why' => $why ] ) ) {
			return;
		}
		/**
		 * 登入失敗記到了 overflow（v1.6.0）：計數表被灌滿，或同一個 /48 的 /64 太多。同一種情況每分鐘最多觸發一次。
		 *
		 * @since 1.6.0
		 *
		 * @param array{key:string,global:bool,why:string} $info key：記到哪個桶；global：全站的 via:overflow（只計數、不擋）。
		 */
		do_action( 'ys_admin_menu_login_guard_overflow', [ 'key' => $key, 'global' => 'overflow_global' === $reason, 'why' => $why ] );
	}

	/**
	 * /48 彙總的計數（§4.13.2）：表滿了時只更新已經有的 agg48: 列，不新增（overflow 已經按 /48 分桶）；新增時 ~rows 加 1。
	 *
	 * @return null|int 加完的次數；沒有記（表滿了又沒有這一列）或查詢失敗回 null。
	 */
	public static function record_agg48( string $agg, string $ip, int $now, string $source ): ?int {
		$window = YSLoginGuardSettings::window_seconds();
		if ( self::table_full() ) {
			$r = YSLoginGuardStore::count_existing( $agg, $ip, $source, $now, $window );
			return null === $r || ! $r['matched'] ? null : $r['count'];
		}
		$r = YSLoginGuardStore::count_failure( $agg, $ip, $source, $now, $window );
		if ( null === $r ) {
			return null;
		}
		if ( $r['inserted'] ) {
			self::bump( '~rows', [ 'fail_count' => 1, 'total_failures' => 1 ], $now );
		}
		return $r['count'];
	}

	/** 「鍵＋帳號」（§4.4.2）：小表滿了時只更新已經有的列；新增時 ~rows_acct 加 1。 */
	public static function record_acct( string $key, string $acct, int $window_start, int $now ): void {
		if ( true === YSLoginGuardStore::acct_record( $key, $acct, $window_start, $now, ! self::acct_full() ) ) {
			self::bump( '~rows_acct', [ 'fail_count' => 1 ], $now );
		}
	}

	/**
	 * @return array{key:string,count:int,mult:int,shared:bool,lockable:bool,escalate:bool,overflow:string}
	 */
	private static function target( string $key, int $count, int $mult, bool $shared, bool $lockable, bool $escalate, string $overflow ): array {
		return [
			'key'      => $key,
			'count'    => $count,
			'mult'     => $mult,
			'shared'   => $shared,
			'lockable' => $lockable,
			'escalate' => $escalate,
			'overflow' => $overflow,
		];
	}

	/* ─────────────────────────── 清理 ─────────────────────────── */

	/**
	 * 記完一次失敗之後：該清理就清（§4.7.3 第 1 項）。這個請求要求立刻清理（表滿了記到 overflow、記列數的保留列不見了
	 * 或剛補回來）、距上次清理超過一小時、上次清理後新增超過 2,000 列、記到 overflow 超過 200 次。
	 * 後兩個條件看這個請求已經讀過的 ~rows（沒讀過就不多查，下一個用到 IP 鍵的請求會看到）。
	 * 清理出錯（例外）不能讓登入請求壞掉：接住、記 health（鎖與租約在 run_cleanup 的 finally 已經釋放）。
	 */
	public static function after_failure( int $now ): void {
		$why = self::$cleanup_now;
		if ( '' === $why ) {
			$rows = self::$state['rows']['~rows'] ?? null;
			if ( $now - (int) get_option( YSLoginGuardSettings::OPTION_LAST_CLEANUP, 0 ) >= YSLoginGuardStore::CLEANUP_INTERVAL
				|| ( is_array( $rows ) && ( (int) $rows['total_failures'] > self::NEW_ROWS_TRIGGER || (int) $rows['blocked_count'] > self::OVF_TRIGGER ) ) ) {
				$why = 'due';
			}
		}
		if ( '' === $why ) {
			return;
		}
		try {
			self::run_cleanup( $now, $why );
		} catch ( \Throwable $e ) {
			YSLoginGuardHealth::record( 'cleanup_failed', [ 'why' => $why ] );
		}
	}

	/**
	 * 後台打開設定頁時順手清理（§4.7.3 第 2 項）：距上次清理超過一小時才做。功能關掉之後也照做（表還在的話），
	 * 出錯不影響頁面（記 health）。
	 */
	public static function cleanup_if_due( int $now ): void {
		if ( ! YSLoginGuardSchema::is_ready() || $now - (int) get_option( YSLoginGuardSettings::OPTION_LAST_CLEANUP, 0 ) < YSLoginGuardStore::CLEANUP_INTERVAL ) {
			return;
		}
		try {
			self::run_cleanup( $now, 'due' );
		} catch ( \Throwable $e ) {
			YSLoginGuardHealth::record( 'cleanup_failed', [ 'why' => 'admin' ] );
		}
	}

	/**
	 * 清理，同一時間只有一個請求在做（§4.7.3）：GET_LOCK；回 NULL 或查詢錯誤時改用 ~cleanup 的 30 秒租約；在 finally 裡釋放。
	 * 別的請求 1 秒內剛清完時不重做（被灌爆時不讓每個請求都清），保留列的問題與手動執行例外。
	 *
	 * @param string $why manual（後台、WP-Cron、WP-CLI）、reserved（記列數的保留列不見了或剛補回來）、full（表滿了記到 overflow）、due（觸發條件）。
	 * @return array{result:string,lock:string,stats:array} result：done（清了）、busy（別的請求正在清）、recent（剛清完）。
	 */
	public static function run_cleanup( int $now, string $why = 'manual' ): array {
		$name  = self::lock_name();
		$got   = YSLoginGuardStore::get_lock( $name );
		$lease = 0;
		if ( 0 === $got ) {
			return [ 'result' => 'busy', 'lock' => 'get_lock', 'stats' => [] ];
		}
		if ( null === $got ) {
			$lease = YSLoginGuardStore::take_lease( $now, self::LEASE_SECONDS );
			if ( 0 === $lease ) {
				return [ 'result' => 'busy', 'lock' => 'lease', 'stats' => [] ];
			}
		}
		$lock = 0 !== $lease ? 'lease' : 'get_lock';
		try {
			if ( in_array( $why, [ 'full', 'due' ], true ) && $now - YSLoginGuardStore::last_cleaned() < self::RECENT_SECONDS ) {
				return [ 'result' => 'recent', 'lock' => $lock, 'stats' => [] ];
			}
			$started = microtime( true );
			$stats   = YSLoginGuardStore::cleanup( $now );
			YSLoginGuardStore::mark_cleaned( $now );
			/**
			 * 清理做完了（v1.6.0）。在互斥裡觸發：started 之後、釋放之前，同一時間只會有一個。
			 *
			 * @since 1.6.0
			 *
			 * @param array{started:float,finished:float,lock:string,why:string,stats:array} $info
			 */
			do_action(
				'ys_admin_menu_login_guard_cleanup_done',
				[ 'started' => $started, 'finished' => microtime( true ), 'lock' => $lock, 'why' => $why, 'stats' => $stats ]
			);
			return [ 'result' => 'done', 'lock' => $lock, 'stats' => $stats ];
		} finally {
			if ( 0 !== $lease ) {
				YSLoginGuardStore::release_lease( $lease );
			} else {
				YSLoginGuardStore::release_lock( $name );
			}
			self::reset();
		}
	}

	/**
	 * GET_LOCK 的鎖名：ysam_lg_ 加 md5( DB_NAME . 資料表前綴 ) 的前 16 碼（24 字元）。GET_LOCK 是整台 MySQL 共用的命名空間、
	 * 不分資料庫，共用主機上不同的站（或多站網路的各站）不能用同一個名稱互相擋（審查 R3-P2-2）。
	 */
	public static function lock_name(): string {
		global $wpdb;
		$db     = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';
		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		return 'ysam_lg_' . substr( md5( $db . $prefix ), 0, 16 );
	}
}
