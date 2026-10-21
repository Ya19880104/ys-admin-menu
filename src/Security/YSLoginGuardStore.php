<?php
/**
 * 登入次數限制的資料存取（v1.6.0，設計 §4.6、§4.7.2、§4.7.3、§4.4.2）。所有 SQL 都在這裡。
 *
 * - 計數：一句 INSERT … ON DUPLICATE KEY UPDATE，在資料庫裡加 1（不會掉次數），用 LAST_INSERT_ID( 運算式 ) 取回加完的值。
 *   這一句只帶系統產生或驗證過的值（鍵、位址、時間、來源代碼）；帳號名稱另外一句寫入，那一句失敗不影響計數。
 * - 鎖定：有條件的 UPDATE，只有影響到一列的那個請求算「建立了這次鎖定」。
 * - SET 的順序讓每個值都只參考「還沒被這條指令改過」的欄位：MySQL 由左到右、MariaDB 開了 SIMULTANEOUS_ASSIGNMENT
 *   是同時，兩種都對。不用 VALUES()（MySQL 8.0.20 起不建議），也不用 AS 別名（MariaDB 不支援），直接再帶一次參數。
 * - 遇到死結（1213）或鎖等待逾時（1205）重試一次。
 * - 查詢失敗時回 null，呼叫的人照樣放行（fail-open），並記 health。
 * - 紀錄：全站每分鐘前 120 次一般失敗、前 20 次被拒的跨站登入才可能寫紀錄（各自的保留列原子計數），每個 IP 每分鐘一筆；
 *   預算算的是失敗（被拒）的次數，不是寫了幾筆紀錄，omitted 記的是超出的次數（實作審查 A 線 P3-15）。
 *   鎖定、解鎖、清空不受額度限制。
 * - 上限（§4.7.3）：哪一次失敗記到哪裡由 YSLoginGuardCapacity 決定；這裡只提供它用的 SQL：只更新既有列的 UPDATE、
 *   數同一個 /48 的列數、保留列的原子加 1、照上限刪與重算列數、清理互斥（GET_LOCK 與 ~cleanup 租約）。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardStore {

	public const EVENT_FAIL    = 'fail';
	public const EVENT_LOCKOUT = 'lockout';
	public const EVENT_UNLOCK  = 'unlock';
	public const EVENT_CLEAR   = 'clear';
	public const EVENT_OMITTED = 'omitted';
	public const EVENT_XSITE   = 'xsite';

	/** 每分鐘的紀錄預算：算的是失敗（被拒）的次數，不是寫了幾筆紀錄。 */
	public const LOG_BUDGET   = 120;
	public const XSITE_BUDGET = 20;

	/** 清理：一批刪幾列、一次最多跑多久（毫秒）、紀錄表最多留幾筆、記失敗時多久順手清一次（秒）。 */
	public const BATCH            = 5000;
	public const CLEANUP_MS       = 200;
	public const LOG_MAX          = 50000;
	public const CLEANUP_INTERVAL = 3600;

	/** 「鍵＋帳號」的 HMAC 用途前綴（審查 R3-P3-1）。 */
	public const ACCT_PREFIX = 'ys_admin_menu_lg_acct|';

	/**
	 * 暫時性的資料庫錯誤：死結（1213）、鎖等待逾時（1205）、連線斷掉（2006、2013）、連線數滿（1040、1203）、伺服器關閉中（1053）、
	 * 查詢被中斷或逾時（1317、1927、1969、3024、4031）。跟「表不見、沒有權限」分開說（實作審查差異 SEC P3-4、CODE N2）。
	 */
	public const TRANSIENT_ERRORS = [ 1040, 1053, 1203, 1205, 1213, 1317, 1927, 1969, 2006, 2013, 3024, 4031 ];

	/** 沒有權限。 */
	public const PRIVILEGE_ERRORS = [ 1044, 1045, 1142, 1143, 1227 ];

	/** 暫時性的失敗記最近多久、最多幾分鐘（health 同一個原因每分鐘最多寫一次，一筆就是一分鐘）。 */
	public const TRANSIENT_WINDOW = 3600;
	public const TRANSIENT_KEEP   = 10;

	/* ─────────────────────────── 查詢 ─────────────────────────── */

	/**
	 * 一次有主鍵的查詢，取回這些鍵的列。
	 *
	 * @param string[] $keys
	 * @return null|array<string, array{locked_until:int,fail_count:int,window_start:int,lockout_count:int,total_failures:int,blocked_count:int}> 查詢失敗回 null。
	 */
	public static function rows( array $keys ): ?array {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( $keys, static fn( $k ) => is_string( $k ) && '' !== $k ) ) );
		if ( [] === $keys ) {
			return [];
		}
		$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql  = $wpdb->prepare( 'SELECT ip_key, locked_until, fail_count, window_start, lockout_count, total_failures, blocked_count FROM ' . YSLoginGuardSchema::counter_table() . " WHERE ip_key IN ({$in})", $keys ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與佔位符是系統產生的
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 上一行 prepare 過
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			self::query_failed( 'rows' );
			return null;
		}
		$out = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row['ip_key'] ] = [
				'locked_until'   => (int) $row['locked_until'],
				'fail_count'     => (int) $row['fail_count'],
				'window_start'   => (int) $row['window_start'],
				'lockout_count'  => (int) $row['lockout_count'],
				'total_failures' => (int) $row['total_failures'],
				'blocked_count'  => (int) $row['blocked_count'],
			];
		}
		return $out;
	}

	/**
	 * 計數（設計 §4.7.2 的寫法）。新增的是 IPv6 的 IP 鍵時，帶上它所在的 /48（net48，§4.7.3）。
	 *
	 * @param null|string $net48 YSClientIp::network( $ip, 48 )；其他鍵是 null（存成 NULL）。
	 * @return null|array{count:int,inserted:bool} 加完的次數、這一句是不是新增了這個鍵。查詢失敗回 null。
	 */
	public static function count_failure( string $key, string $ip, string $source, int $now, int $window, ?string $net48 = null ): ?array {
		global $wpdb;
		$table   = YSLoginGuardSchema::counter_table();
		$expired = $now - $window;
		$sql     = $wpdb->prepare(
			"INSERT INTO {$table} (ip_key, net48, last_ip, fail_count, window_start, total_failures, last_source, created_at, updated_at)
			VALUES (%s, " . ( null === $net48 ? 'NULL' : '%s' ) . ", %s, 1, %d, 1, %s, %d, %d)
			ON DUPLICATE KEY UPDATE
				fail_count = LAST_INSERT_ID( IF( window_start <= %d, 1, fail_count + 1 ) ),
				window_start = IF( window_start <= %d, %d, window_start ),
				total_failures = total_failures + 1,
				last_ip = %s,
				last_source = %s,
				updated_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			array_merge(
				[ $key ],
				null === $net48 ? [] : [ $net48 ],
				[ $ip, $now, $source, $now, $now, $expired, $expired, $now, $ip, $source, $now ]
			)
		);
		$result = self::query_with_retry( $sql );
		if ( false === $result ) {
			self::query_failed( 'count' );
			return null;
		}
		$affected = (int) $wpdb->rows_affected;
		if ( 1 === $affected ) {
			return [ 'count' => 1, 'inserted' => true ];
		}
		return [ 'count' => max( 1, (int) $wpdb->insert_id ), 'inserted' => false ];
	}

	/**
	 * 只更新已經存在的列（表滿了、或 IPv6 的 IP 鍵要先看自己有沒有列時，§4.4 失敗時第 2 步）。SET 跟計數那一句一樣，
	 * 加完的次數用 LAST_INSERT_ID( 運算式 ) 記在這個連線上。wpdb 只在 INSERT／REPLACE 之後更新 insert_id，所以直接用
	 * mysqli_insert_id() 從剛剛那一句的連線取回；讀寫分離的 db.php（HyperDB、LudicrousDB）會把另一句 SELECT 送到讀庫，
	 * 那裡沒有這個值（實作審查 A 線 P3-2）。$wpdb->dbh 不是 mysqli 時（替身、其他驅動）才再送一句 SELECT LAST_INSERT_ID()。
	 *
	 * @return null|array{count:int,matched:bool} matched＝這個鍵有列（加過了）。查詢失敗回 null。
	 */
	public static function count_existing( string $key, string $ip, string $source, int $now, int $window ): ?array {
		global $wpdb;
		$table   = YSLoginGuardSchema::counter_table();
		$expired = $now - $window;
		$sql     = $wpdb->prepare(
			"UPDATE {$table} SET
				fail_count = LAST_INSERT_ID( IF( window_start <= %d, 1, fail_count + 1 ) ),
				window_start = IF( window_start <= %d, %d, window_start ),
				total_failures = total_failures + 1,
				last_ip = %s,
				last_source = %s,
				updated_at = %d
			WHERE ip_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			$expired,
			$expired,
			$now,
			$ip,
			$source,
			$now,
			$key
		);
		$result = self::query_with_retry( $sql );
		if ( false === $result ) {
			self::query_failed( 'count' );
			return null;
		}
		if ( (int) $wpdb->rows_affected < 1 ) {
			return [ 'count' => 0, 'matched' => false ];
		}
		$dbh   = $wpdb->dbh ?? null;
		$count = $dbh instanceof \mysqli ? mysqli_insert_id( $dbh ) : $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 同一個連線上剛剛那一句設的值
		return [ 'count' => max( 1, (int) $count ), 'matched' => true ];
	}

	/**
	 * 同一個 /48 已經有幾列（走 net48 的索引，最多數到 $limit 個，跟表多大無關）。查詢失敗回 null。
	 */
	public static function count48( string $net48, int $limit ): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ( SELECT 1 FROM ' . YSLoginGuardSchema::counter_table() . ' WHERE net48 = %s LIMIT %d ) t', $net48, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		if ( null === $value || '' !== (string) $wpdb->last_error ) {
			self::query_failed( 'count48' );
			return null;
		}
		return (int) $value;
	}

	/**
	 * 保留列的原子加 1（~rows、~rows_ovf、~rows_acct 的計數，§4.7.3）。INSERT … ON DUPLICATE KEY UPDATE：
	 * 這一列不見了就補回來，值就是這次加的量。
	 *
	 * @param array<string, int> $add fail_count、total_failures、blocked_count 要加多少。
	 * @return null|bool true＝這一列剛剛不見了、現在補回來（新插入）；false＝原本就在；查詢失敗回 null。
	 */
	public static function bump_reserved( string $row, array $add, int $now ): ?bool {
		global $wpdb;
		$inc = [];
		foreach ( [ 'fail_count', 'total_failures', 'blocked_count' ] as $field ) {
			$inc[ $field ] = max( 0, (int) ( $add[ $field ] ?? 0 ) );
		}
		$sql = $wpdb->prepare(
			'INSERT INTO ' . YSLoginGuardSchema::counter_table() . ' (ip_key, fail_count, total_failures, blocked_count, created_at, updated_at) VALUES (%s, %d, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE fail_count = fail_count + %d, total_failures = total_failures + %d, blocked_count = blocked_count + %d, updated_at = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			$row,
			$inc['fail_count'],
			$inc['total_failures'],
			$inc['blocked_count'],
			$now,
			$now,
			$inc['fail_count'],
			$inc['total_failures'],
			$inc['blocked_count'],
			$now
		);
		if ( false === self::query_with_retry( $sql ) ) {
			self::query_failed( 'reserved' );
			return null;
		}
		return 1 === (int) $wpdb->rows_affected;
	}

	/** 這個鍵目前的視窗起點（「鍵＋帳號」小表用，§4.4.2）。讀不到回 null。 */
	public static function window_start( string $key ): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT window_start FROM ' . YSLoginGuardSchema::counter_table() . ' WHERE ip_key = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return null === $value ? null : (int) $value;
	}

	/**
	 * 建立鎖定（有條件的 UPDATE）。
	 *
	 * locked_until 寫在 lockout_count、lockout_period_start 前面，三個都只看這條指令改之前的值：
	 * 新的 lockout_count＝計算期間過了就從 1 重算，否則加 1；到了「第幾次起加長」就用加長的時間。共用桶不加長。
	 *
	 * @return null|int 這個請求建立了鎖定時回鎖到幾點；沒有建立（還沒到門檻、別的請求先建了）回 null。
	 */
	public static function lock( string $key, int $now, int $threshold, int $lockout, bool $escalate, int $escalate_after, int $period, int $escalated ): ?int {
		global $wpdb;
		$table      = YSLoginGuardSchema::counter_table();
		$period_end = $now - $period;
		$sql        = $wpdb->prepare(
			"UPDATE {$table} SET
				locked_until = %d + IF( %d = 1 AND IF( lockout_period_start <= %d, 1, lockout_count + 1 ) >= %d, %d, %d ),
				lockout_count = IF( lockout_period_start <= %d, 1, lockout_count + 1 ),
				lockout_period_start = IF( lockout_period_start <= %d, %d, lockout_period_start ),
				fail_count = 0,
				window_start = 0,
				updated_at = %d
			WHERE ip_key = %s AND locked_until <= %d AND fail_count >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			$now,
			$escalate ? 1 : 0,
			$period_end,
			$escalate_after,
			$escalated,
			$lockout,
			$period_end,
			$period_end,
			$now,
			$now,
			$key,
			$now,
			$threshold
		);
		$result = self::query_with_retry( $sql );
		if ( false === $result ) {
			self::query_failed( 'lock' );
			return null;
		}
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return null;
		}
		$until = $wpdb->get_var( $wpdb->prepare( "SELECT locked_until FROM {$table} WHERE ip_key = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return null === $until ? $now + $lockout : (int) $until;
	}

	/** 鎖定期間被擋下一次（同一秒最多加一次，審查 P3-11）。 */
	public static function note_blocked( string $key, int $now ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . ' SET blocked_count = blocked_count + 1, last_blocked_at = %d WHERE ip_key = %s AND last_blocked_at < %d', $now, $key, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/** 最後嘗試的帳號（另外一句，失敗不影響計數）。 */
	public static function set_username( string $key, string $username ): void {
		global $wpdb;
		$table = YSLoginGuardSchema::counter_table();
		$clean = self::clean_text( $table, 'last_username', self::sanitize_username( $username ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_username = %s WHERE ip_key = %s", $clean, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/* ─────────────────────────── 「鍵＋帳號」小表（§4.4.2） ─────────────────────────── */

	/** 帳號的雜湊：使用者 ID 加用途前綴，HMAC-SHA256 取前 32 個十六進位字元。 */
	public static function acct_hash( int $user_id ): string {
		return substr( hash_hmac( 'sha256', self::ACCT_PREFIX . $user_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * 失敗時：這一列記的視窗跟計數表的 window_start 不同時從 1 重算，否則加 1。
	 * 小表已經滿了（$can_insert＝false，§4.4.2 的上限）時只更新已經有的列，不新增：結果只是少了原諒，計數照常。
	 *
	 * @return null|bool true＝新增了一列（呼叫的人把 ~rows_acct 加 1）；false＝更新了既有的列或沒有寫；查詢失敗回 null。
	 */
	public static function acct_record( string $key, string $acct, int $window_start, int $now, bool $can_insert = true ): ?bool {
		global $wpdb;
		$table = YSLoginGuardSchema::acct_table();
		if ( $can_insert ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (ip_key, acct, fail_count, acct_window, updated_at) VALUES (%s, %s, 1, %d, %d)
				ON DUPLICATE KEY UPDATE fail_count = IF( acct_window = %d, fail_count + 1, 1 ), acct_window = %d, updated_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
				$key,
				$acct,
				$window_start,
				$now,
				$window_start,
				$window_start,
				$now
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$table} SET fail_count = IF( acct_window = %d, fail_count + 1, 1 ), acct_window = %d, updated_at = %d WHERE ip_key = %s AND acct = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
				$window_start,
				$window_start,
				$now,
				$key,
				$acct
			);
		}
		if ( false === self::query_with_retry( $sql ) ) {
			self::query_failed( 'acct' );
			return null;
		}
		return $can_insert && 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * 成功時：只扣掉這個帳號自己在這個鍵、同一個視窗裡的失敗；已經建立的鎖定不動。扣完（或扣不到）都刪掉那一列。
	 *
	 * @return int 扣掉了幾次（0＝沒有可以扣的）。
	 */
	public static function acct_forgive( string $key, string $acct, int $now ): int {
		global $wpdb;
		$acct_table = YSLoginGuardSchema::acct_table();
		$row        = $wpdb->get_row( $wpdb->prepare( "SELECT fail_count, acct_window FROM {$acct_table} WHERE ip_key = %s AND acct = %s", $key, $acct ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		if ( ! is_array( $row ) ) {
			return 0;
		}
		$fails    = (int) $row['fail_count'];
		$window   = (int) $row['acct_window'];
		$forgiven = 0;
		if ( $fails > 0 ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . YSLoginGuardSchema::counter_table() . ' SET fail_count = GREATEST( CAST( fail_count AS SIGNED ) - %d, 0 ), updated_at = %d WHERE ip_key = %s AND window_start = %d AND locked_until <= %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
					$fails,
					$now,
					$key,
					$window,
					$now
				)
			);
			$forgiven = false !== $result && 1 === (int) $wpdb->rows_affected ? $fails : 0;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$acct_table} WHERE ip_key = %s AND acct = %s", $key, $acct ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return $forgiven;
	}

	/* ─────────────────────────── 紀錄 ─────────────────────────── */

	/**
	 * 一般失敗的紀錄：每個 IP 鍵每分鐘一筆，而且是全站這一分鐘的前 120 次失敗之一。用完時只加計數。
	 * 不管有沒有寫紀錄，每一次失敗都算進 ~log_budget（同一個 IP 這一分鐘已經寫過、這次沒寫，照樣用掉一次預算），
	 * 換分鐘時彙總進 ~stat24 的失敗總數。
	 */
	public static function log_fail( string $key, string $ip, string $username, string $source, string $code, int $now ): void {
		global $wpdb;
		$within_budget = self::take_budget( '~log_budget', self::LOG_BUDGET, self::EVENT_FAIL, $now );
		$table         = YSLoginGuardSchema::counter_table();
		$first_this_minute = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_logged_at = %d WHERE ip_key = %s AND last_logged_at <= %d", $now, $key, $now - 60 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		if ( ! $within_budget || false === $first_this_minute || 1 !== (int) $wpdb->rows_affected ) {
			return;
		}
		self::insert_log( self::EVENT_FAIL, $ip, $key, $username, $source, $code, '', $now );
	}

	/**
	 * 被拒的跨站登入（或觀察期內本來會被拒的）：自己的每分鐘 20 筆，不吃一般失敗的額度。不記帳號、不記 IP。
	 *
	 * @param string $entry 入口類別（wp-login、xmlrpc、REST 路徑前綴、other）。
	 */
	public static function log_xsite( string $origin, string $entry, bool $observing, int $now ): void {
		if ( ! self::take_budget( '~log_budget_xsite', self::XSITE_BUDGET, self::EVENT_XSITE, $now ) ) {
			return;
		}
		$code = substr( (string) preg_replace( '/[^a-z0-9_\/-]/', '', strtolower( $entry ) ), 0, 40 );
		self::insert_log( self::EVENT_XSITE, '', '', '', $observing ? 'observe' : 'reject', '' !== $code ? $code : 'other', $origin, $now );
	}

	/**
	 * 不受額度限制的事件：鎖定、解鎖、清空。
	 *
	 * @param array{ip?:string,ip_key?:string,username?:string,source?:string,code?:string} $fields
	 */
	public static function log_event( string $event, array $fields, int $now ): void {
		if ( ! in_array( $event, [ self::EVENT_LOCKOUT, self::EVENT_UNLOCK, self::EVENT_CLEAR ], true ) ) {
			return;
		}
		self::insert_log(
			$event,
			(string) ( $fields['ip'] ?? '' ),
			(string) ( $fields['ip_key'] ?? '' ),
			(string) ( $fields['username'] ?? '' ),
			(string) ( $fields['source'] ?? '' ),
			(string) ( $fields['code'] ?? '' ),
			'',
			$now
		);
	}

	/**
	 * 每分鐘的預算（INSERT … ON DUPLICATE KEY UPDATE，保留列不見了也會補回來）。
	 * 換分鐘時，上一分鐘的數量先暫存到 total_failures（寫在 fail_count、window_start 前面，只看改之前的值），
	 * 這一分鐘的第一個請求把它讀回來：超過預算就寫一筆 omitted（code 帶超出的次數），一般失敗的另外併進 ~stat24。
	 * 所以 ~stat24 的失敗總數比共用桶的次數慢一拍：上一分鐘的失敗要等下一個有失敗的分鐘才併進去。失敗總數偏小時，
	 * 健康狀態的 shared_bucket（共用桶占一半以上）比較容易成立：寧可早一點提醒，不會漏掉（設計允許）。
	 *
	 * @return bool 這一筆在預算內（可以寫紀錄）。查詢失敗回 false（不寫，大小有界）。
	 */
	private static function take_budget( string $row, int $limit, string $kind, int $now ): bool {
		global $wpdb;
		$table  = YSLoginGuardSchema::counter_table();
		$minute = $now - ( $now % 60 );
		$sql    = $wpdb->prepare(
			"INSERT INTO {$table} (ip_key, fail_count, window_start, created_at, updated_at) VALUES (%s, 1, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				total_failures = IF( window_start = %d, total_failures, fail_count ),
				fail_count = LAST_INSERT_ID( IF( window_start = %d, fail_count + 1, 1 ) ),
				window_start = %d,
				updated_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			$row,
			$minute,
			$now,
			$now,
			$minute,
			$minute,
			$minute,
			$now
		);
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 上面 prepare 過
			self::query_failed( 'budget' );
			return false;
		}
		if ( 1 === (int) $wpdb->rows_affected ) {
			// 新插入：保留列剛剛不見了（建表時就建好了），補回來就好。
			YSLoginGuardHealth::record( 'reserved_row_recreated', [ 'row' => $row ] );
			return true;
		}
		$count = (int) $wpdb->insert_id;
		if ( 1 === $count ) {
			$previous = (int) $wpdb->get_var( $wpdb->prepare( "SELECT total_failures FROM {$table} WHERE ip_key = %s", $row ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			if ( $previous > $limit ) {
				self::insert_log( self::EVENT_OMITTED, '', '', '', '', $kind . ':' . ( $previous - $limit ), '', $now );
			}
			if ( self::EVENT_FAIL === $kind && $previous > 0 ) {
				self::stat( [ 'fail_count' => $previous ], $now );
			}
		}
		return $count <= $limit;
	}

	private static function insert_log( string $event, string $ip, string $key, string $username, string $source, string $code, string $origin, int $now ): void {
		global $wpdb;
		$table = YSLoginGuardSchema::log_table();
		$row   = [
			'created_at' => $now,
			'event'      => substr( (string) preg_replace( '/[^a-z_]/', '', $event ), 0, 12 ),
			'ip'         => substr( YSClientIp::normalize( $ip ), 0, 45 ),
			'ip_key'     => substr( (string) preg_replace( '/[^a-z0-9.:\/~_-]/', '', strtolower( $key ) ), 0, 64 ),
			'username'   => self::clean_text( $table, 'username', self::sanitize_username( $username ) ),
			'source'     => substr( (string) preg_replace( '/[^a-z0-9_-]/', '', $source ), 0, 12 ),
			'code'       => substr( (string) preg_replace( '/[^a-z0-9_:\/-]/', '', strtolower( $code ) ), 0, 40 ),
			'origin'     => self::clean_text( $table, 'origin', substr( $origin, 0, 255 ) ),
		];
		$wpdb->insert( $table, $row, [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
	}

	/* ─────────────────────────── 健康統計 ─────────────────────────── */

	/**
	 * ~stat24 的累加（只在發生時寫：記到共用桶、從標頭取到內部或 CDN 位址、外站 Worker；失敗總數每分鐘彙總一次）。
	 * 這一輪 24 小時過了就全部從 0 重算。
	 *
	 * @param array<string, int> $add fail_count、total_failures、blocked_count、lockout_count 要加多少。
	 */
	public static function stat( array $add, int $now ): void {
		global $wpdb;
		$fields = [ 'fail_count', 'total_failures', 'blocked_count', 'lockout_count' ];
		$inc    = [];
		foreach ( $fields as $field ) {
			$inc[ $field ] = max( 0, (int) ( $add[ $field ] ?? 0 ) );
		}
		$table   = YSLoginGuardSchema::counter_table();
		$expired = $now - 86400;
		$sql     = $wpdb->prepare(
			"INSERT INTO {$table} (ip_key, fail_count, total_failures, blocked_count, lockout_count, window_start, created_at, updated_at)
			VALUES ('~stat24', %d, %d, %d, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE
				fail_count = IF( window_start <= %d, 0, fail_count ) + %d,
				total_failures = IF( window_start <= %d, 0, total_failures ) + %d,
				blocked_count = IF( window_start <= %d, 0, blocked_count ) + %d,
				lockout_count = IF( window_start <= %d, 0, lockout_count ) + %d,
				window_start = IF( window_start <= %d, %d, window_start ),
				updated_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			$inc['fail_count'],
			$inc['total_failures'],
			$inc['blocked_count'],
			$inc['lockout_count'],
			$now,
			$now,
			$now,
			$expired,
			$inc['fail_count'],
			$expired,
			$inc['total_failures'],
			$expired,
			$inc['blocked_count'],
			$expired,
			$inc['lockout_count'],
			$expired,
			$now,
			$now
		);
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 上面 prepare 過
	}

	/**
	 * 目前這一輪的統計（健康狀態用）。failures 不含還沒彙總的這一分鐘（見 take_budget()）。
	 *
	 * @return array{since:int,failures:int,shared:int,header_internal:int,cf_worker:int}
	 */
	public static function stat24(): array {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT fail_count, total_failures, blocked_count, lockout_count, window_start FROM " . YSLoginGuardSchema::counter_table() . " WHERE ip_key = '~stat24'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 沒有變數
		$row = is_array( $row ) ? $row : [];
		return [
			'since'           => (int) ( $row['window_start'] ?? 0 ),
			'failures'        => (int) ( $row['fail_count'] ?? 0 ),
			'shared'          => (int) ( $row['total_failures'] ?? 0 ),
			'header_internal' => (int) ( $row['blocked_count'] ?? 0 ),
			'cf_worker'       => (int) ( $row['lockout_count'] ?? 0 ),
		];
	}

	/* ─────────────────────────── 清理（§4.7.3） ─────────────────────────── */

	/**
	 * 清理。只在 YSLoginGuardCapacity::run_cleanup() 拿到互斥之後呼叫（記失敗時順手、後台開頁、WP-Cron、WP-CLI）。
	 *
	 * - 開頭先 INSERT IGNORE 全部保留列（不見的補回來）。
	 * - 刪：沒在鎖定中、而且很久沒動的列；視窗過了的「鍵＋帳號」；超過保留天數的紀錄。保留列不刪。
	 * - 照上限刪：計數表的 IP 鍵（含 agg48:，不含共用桶與 overflow 分桶）還在上限以上時，先刪最久沒動、沒在鎖定中的，
	 *   刪到上限的九成，騰出空間讓新鍵有自己的列。正在鎖定中的不刪。
	 * - 紀錄表超過 50,000 筆時從最舊的刪。
	 * - 一次可以連跑多輪 LIMIT 5000，時間上限 200 毫秒。
	 * - 最後把 ~rows、~rows_ovf、~rows_acct 設成實際的列數，~rows 的「新增幾列」「記到 overflow 幾次」歸零
	 *   （數完到寫回之間的新增會被蓋掉，量很小，下次清理就修正，§11 R4-P3-2）。
	 *
	 * @return array{counter:int,cap:int,acct:int,log:int,done:bool,rows:?int,ovf:?int,acct_rows:?int} 刪了幾列、有沒有在時間內清完、重算後的列數。
	 */
	public static function cleanup( int $now ): array {
		global $wpdb;
		$deadline = microtime( true ) + self::CLEANUP_MS / 1000;
		YSLoginGuardSchema::insert_reserved_rows();
		update_option( YSLoginGuardSettings::OPTION_LAST_CLEANUP, $now, false );

		$keep    = max( YSLoginGuardSettings::window_seconds(), YSLoginGuardSettings::escalate_period_seconds(), 86400 );
		$counter = YSLoginGuardSchema::counter_table();
		$done    = true;
		$deleted = [ 'counter' => 0, 'cap' => 0, 'acct' => 0, 'log' => 0 ];

		$deleted['counter'] = self::delete_batches(
			$wpdb->prepare( "DELETE FROM {$counter} WHERE ip_key NOT LIKE %s AND locked_until <= %d AND updated_at < %d LIMIT " . self::BATCH, $wpdb->esc_like( '~' ) . '%', $now, $now - $keep ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與數字是系統產生的
			$deadline,
			$done
		);
		$deleted['acct'] = self::delete_batches(
			$wpdb->prepare( 'DELETE FROM ' . YSLoginGuardSchema::acct_table() . ' WHERE updated_at < %d LIMIT ' . self::BATCH, $now - YSLoginGuardSettings::window_seconds() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與數字是系統產生的
			$deadline,
			$done
		);
		$log             = YSLoginGuardSchema::log_table();
		$deleted['log']  = self::delete_batches(
			$wpdb->prepare( "DELETE FROM {$log} WHERE created_at < %d LIMIT " . self::BATCH, $now - YSLoginGuardSettings::retention_seconds() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與數字是系統產生的
			$deadline,
			$done
		);

		// 照上限刪：IP 鍵還在上限以上，先刪最久沒動、沒在鎖定中的，刪到上限的九成。
		$ip_rows = self::count_ip_rows();
		if ( null !== $ip_rows && $ip_rows >= YSLoginGuardCapacity::ROWS_MAX ) {
			while ( $ip_rows > YSLoginGuardCapacity::ROWS_LOW ) {
				if ( microtime( true ) > $deadline ) {
					$done = false;
					break;
				}
				$want   = min( self::BATCH, $ip_rows - YSLoginGuardCapacity::ROWS_LOW );
				$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$counter} WHERE ip_key NOT LIKE %s AND ip_key NOT LIKE %s AND locked_until <= %d ORDER BY updated_at ASC LIMIT %d", $wpdb->esc_like( '~' ) . '%', $wpdb->esc_like( 'via:' ) . '%', $now, $want ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
				if ( false === $result ) {
					self::query_failed( 'cleanup' );
					$done = false;
					break;
				}
				$deleted['cap'] += (int) $result;
				$ip_rows        -= (int) $result;
				if ( (int) $result < $want ) {
					break; // 剩下的都在鎖定中
				}
			}
		}

		if ( $done ) {
			$cut = $wpdb->get_var( "SELECT id FROM {$log} ORDER BY id DESC LIMIT 1 OFFSET " . self::LOG_MAX ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與數字是系統產生的
			if ( null !== $cut ) {
				$deleted['log'] += self::delete_batches(
					$wpdb->prepare( "DELETE FROM {$log} WHERE id <= %d LIMIT " . self::BATCH, (int) $cut ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與數字是系統產生的
					$deadline,
					$done
				);
			}
		}
		return $deleted + [ 'done' => $done ] + self::recount( $now );
	}

	/**
	 * 計數表裡 IP 鍵的列數（含 agg48:，不含 ~ 開頭的保留列與 via: 開頭的共用桶、overflow 分桶），跟 ~rows 記的是同一種列。
	 */
	public static function count_ip_rows(): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . YSLoginGuardSchema::counter_table() . ' WHERE ip_key NOT LIKE %s AND ip_key NOT LIKE %s', $wpdb->esc_like( '~' ) . '%', $wpdb->esc_like( 'via:' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return null === $value || '' !== (string) $wpdb->last_error ? null : (int) $value;
	}

	/**
	 * 把記列數的三列設成實際的列數；~rows 的「上次清理後新增幾列」「記到 overflow 幾次」歸零。
	 *
	 * @return array{rows:?int,ovf:?int,acct_rows:?int}
	 */
	private static function recount( int $now ): array {
		global $wpdb;
		$counter = YSLoginGuardSchema::counter_table();
		$rows    = self::count_ip_rows();
		$ovf     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$counter} WHERE ip_key LIKE %s", $wpdb->esc_like( YSLoginGuardCapacity::OVERFLOW_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		$ovf     = null === $ovf || '' !== (string) $wpdb->last_error ? null : (int) $ovf;
		$acct    = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . YSLoginGuardSchema::acct_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名是系統產生的
		$acct    = null === $acct || '' !== (string) $wpdb->last_error ? null : (int) $acct;
		if ( null !== $rows ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$counter} SET fail_count = %d, total_failures = 0, blocked_count = 0, updated_at = %d WHERE ip_key = '~rows'", $rows, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		}
		if ( null !== $ovf ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$counter} SET fail_count = %d, updated_at = %d WHERE ip_key = '~rows_ovf'", $ovf, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		}
		if ( null !== $acct ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$counter} SET fail_count = %d, updated_at = %d WHERE ip_key = '~rows_acct'", $acct, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		}
		return [ 'rows' => $rows, 'ovf' => $ovf, 'acct_rows' => $acct ];
	}

	/* ─────────────────────────── 清理互斥（§4.7.3） ─────────────────────────── */

	/**
	 * GET_LOCK( 名稱, 0 )。
	 *
	 * @return null|int 1＝拿到、0＝別的請求正在清；回 NULL 或查詢錯誤（有些叢集或代理環境限制 GET_LOCK）回 null，改用租約。
	 */
	public static function get_lock( string $name ): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $name ) );
		if ( null === $value || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return '1' === (string) $value ? 1 : 0;
	}

	public static function release_lock( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
	}

	/**
	 * ~cleanup 的租約（GET_LOCK 不能用時）：先 INSERT IGNORE 這一列（不見了 UPDATE 永遠影響 0 列，清理就再也不會跑），
	 * 再用有條件的 UPDATE 拿，影響一列才算拿到。不用一句 INSERT … ON DUPLICATE KEY UPDATE：主機定義了 CLIENT_FOUND_ROWS 時
	 * 「沒改到」也回 1，分不出有沒有拿到。
	 *
	 * @return int 拿到時回寫進去的時間（釋放時要帶），沒拿到回 0。
	 */
	public static function take_lease( int $now, int $seconds ): int {
		global $wpdb;
		$table = YSLoginGuardSchema::counter_table();
		$wpdb->query( "INSERT IGNORE INTO {$table} (ip_key, created_at, updated_at) VALUES ('~cleanup', 0, 0)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET window_start = %d WHERE ip_key = '~cleanup' AND window_start < %d", $now, $now - $seconds ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return false !== $result && 1 === (int) $wpdb->rows_affected ? $now : 0;
	}

	/** 釋放租約：只放自己寫進去的那一個（審查 R4-P3-1；超過 30 秒還沒結束的舊請求不會把新請求的租約放掉）。 */
	public static function release_lease( int $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . " SET window_start = 0 WHERE ip_key = '~cleanup' AND window_start = %d", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/** 上一次清理做完的時間（~cleanup 的 updated_at；直接讀資料庫，不經過 option 的快取）。 */
	public static function last_cleaned(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT updated_at FROM ' . YSLoginGuardSchema::counter_table() . " WHERE ip_key = '~cleanup'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 沒有變數
	}

	public static function mark_cleaned( int $now ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . " SET updated_at = %d WHERE ip_key = '~cleanup'", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/**
	 * 同一句 DELETE … LIMIT 一直跑到刪不滿一批或超過時間。
	 */
	private static function delete_batches( string $sql, float $deadline, bool &$done ): int {
		global $wpdb;
		$total = 0;
		while ( true ) {
			if ( microtime( true ) > $deadline ) {
				$done = false;
				break;
			}
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 呼叫的地方 prepare 過
			if ( false === $result ) {
				self::query_failed( 'cleanup' );
				$done = false;
				break;
			}
			$total += (int) $result;
			if ( (int) $result < self::BATCH ) {
				break;
			}
		}
		return $total;
	}

	/* ─────────────────────────── 後台與 WP-CLI（§4.8、§4.10） ─────────────────────────── */

	/** 鍵的格式（後台與 WP-CLI 的目標先驗證再查）：IP 鍵、IPv6 網段、agg48:、via: 開頭的共用桶與 overflow 分桶。保留列不算。 */
	public static function is_valid_key( string $key ): bool {
		if ( '' === $key || strlen( $key ) > 64 || '~' === $key[0] ) {
			return false;
		}
		return 1 === preg_match( '/^(?:agg48:|via:)?[0-9a-z.:\/-]+$/D', $key ); // D：$ 不吃結尾的換行
	}

	/**
	 * 計數表的列（不含保留列）：只要鎖定中的，或全部；可以只看某一個鍵或某一個 IP（呼叫的人先驗證格式）。
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_rows( int $now, bool $locked_only, string $filter = '', int $limit = 200 ): array {
		global $wpdb;
		$table = YSLoginGuardSchema::counter_table();
		$where = [ 'ip_key NOT LIKE %s' ];
		$args  = [ $wpdb->esc_like( '~' ) . '%' ];
		if ( $locked_only ) {
			$where[] = 'locked_until > %d';
			$args[]  = $now;
		}
		if ( '' !== $filter ) {
			$where[] = '( ip_key = %s OR last_ip = %s )';
			$args[]  = $filter;
			$args[]  = $filter;
		}
		$args[] = max( 1, min( 1000, $limit ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT ip_key, last_ip, fail_count, window_start, locked_until, lockout_count, blocked_count, total_failures, last_username, last_source, updated_at FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY locked_until DESC, updated_at DESC LIMIT %d', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- 表名與條件是系統產生的
		return is_array( $rows ) ? $rows : [];
	}

	/** 「鍵＋帳號」小表裡這個鍵有幾個不同的（存在的）帳號失敗過（通知用）。 */
	public static function acct_count( string $key ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . YSLoginGuardSchema::acct_table() . ' WHERE ip_key = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/**
	 * 一次數好幾個鍵（設定頁的鎖定列表，最多 200 列）：一句 GROUP BY，不是每一列各查一次（實作審查 A 線 P3-11）。
	 *
	 * @param string[] $keys
	 * @return array<string, int> 鍵 => 帳號數；沒有列的鍵不在結果裡。
	 */
	public static function acct_counts( array $keys ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		if ( [] === $keys ) {
			return [];
		}
		$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ip_key, COUNT(*) AS n FROM ' . YSLoginGuardSchema::acct_table() . " WHERE ip_key IN ({$in}) GROUP BY ip_key", $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與佔位符是系統產生的
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['ip_key'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * 解除鎖定（後台、WP-CLI）：鎖定、這個視窗的次數與逐次加長的計數一起清掉（管理員判斷這是正常的使用者）；列留著（§4.7.3）。
	 *
	 * @return int 影響幾列（0＝本來就沒有這一列）。
	 */
	public static function unlock( string $key ): int {
		global $wpdb;
		if ( ! self::is_valid_key( $key ) ) {
			return 0;
		}
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . ' SET locked_until = 0, fail_count = 0, window_start = 0, lockout_count = 0, lockout_period_start = 0 WHERE ip_key = %s', $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return false === $result ? 0 : (int) $result;
	}

	/** 全部解鎖（後台、WP-CLI）。保留列不動。 */
	public static function unlock_all( int $now ): int {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . ' SET locked_until = 0, fail_count = 0, window_start = 0, lockout_count = 0, lockout_period_start = 0 WHERE locked_until > %d AND ip_key NOT LIKE %s', $now, $wpdb->esc_like( '~' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * 清空紀錄（後台、WP-CLI）：紀錄表（含被拒的跨站來源）與「鍵＋帳號」小表；計數與鎖定不動；~rows_acct 設成 0（§4.8）。
	 */
	public static function clear_records( int $now ): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . YSLoginGuardSchema::log_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名是系統產生的
		$wpdb->query( 'DELETE FROM ' . YSLoginGuardSchema::acct_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名是系統產生的
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . " SET fail_count = 0, updated_at = %d WHERE ip_key = '~rows_acct'", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/**
	 * 最近的紀錄（後台、WP-CLI），新到舊；可以只看某一個 IP 或鍵（呼叫的人先驗證格式）。
	 *
	 * @return array{rows:array<int, array<string, mixed>>,total:int}
	 */
	public static function recent_logs( string $filter, int $since, int $page, int $per_page = 50 ): array {
		global $wpdb;
		$table = YSLoginGuardSchema::log_table();
		$where = 'created_at >= %d';
		$args  = [ $since ];
		if ( '' !== $filter ) {
			$where .= ' AND ( ip = %s OR ip_key = %s )';
			$args[] = $filter;
			$args[] = $filter;
		}
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- 表名與條件是系統產生的
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = max( 0, ( max( 1, $page ) - 1 ) * $per_page );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, event, ip, ip_key, username, source, code, origin FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- 表名與條件是系統產生的
		return [ 'rows' => is_array( $rows ) ? $rows : [], 'total' => $total ];
	}

	/**
	 * 被拒的跨站登入來源（後台；觀察期內本來會被拒的也算），次數多的在前（§4.13.1）。
	 *
	 * @param int $min_count 只要次數大於這個數的（提醒用）；0＝都要。
	 * @return array<int, array{origin:string,entries:string,count:int,observed:int,last:int}>
	 */
	public static function xsite_sources( int $since, int $limit = 20, int $min_count = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT origin, GROUP_CONCAT( DISTINCT code ORDER BY code SEPARATOR %s ) AS entries, COUNT(*) AS n, SUM( source = %s ) AS observed, MAX( created_at ) AS last_at FROM ' . YSLoginGuardSchema::log_table() . ' WHERE event = %s AND created_at >= %d GROUP BY origin HAVING COUNT(*) > %d ORDER BY n DESC, last_at DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
				',',
				'observe',
				self::EVENT_XSITE,
				$since,
				max( 0, $min_count ),
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);
		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = [
				'origin'   => (string) $row['origin'],
				'entries'  => (string) $row['entries'],
				'count'    => (int) $row['n'],
				'observed' => (int) $row['observed'],
				'last'     => (int) $row['last_at'],
			];
		}
		return $out;
	}

	/**
	 * 這個鍵某個時間之後的失敗紀錄裡出現過的帳號名稱（通知用；紀錄是取樣的，每個鍵每分鐘一筆）。
	 *
	 * @return string[] 已清理過的帳號名稱。
	 */
	public static function log_usernames( string $key, int $since, int $limit = 50 ): array {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT username FROM ' . YSLoginGuardSchema::log_table() . " WHERE event = 'fail' AND ip_key = %s AND created_at >= %d AND username <> '' LIMIT %d", $key, $since, max( 1, min( 200, $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return array_values( array_unique( array_map( static fn( $n ) => self::sanitize_username( (string) $n ), is_array( $names ) ? $names : [] ) ) );
	}

	/**
	 * 通知的節流：同一個鍵 24 小時內一封（有條件的 UPDATE，並發時只有一個請求拿到）。
	 *
	 * @return bool 這個請求可以寄。
	 */
	public static function claim_notify( string $key, int $now, int $every ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . YSLoginGuardSchema::counter_table() . ' SET last_notified_at = %d WHERE ip_key = %s AND last_notified_at <= %d', $now, $key, $now - $every ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
		return false !== $result && 1 === (int) $wpdb->rows_affected;
	}

	/* ─────────────────────────── 工具 ─────────────────────────── */

	/**
	 * 帳號名稱的清理（後台、WP-CLI、信件、紀錄都用這一個）：不合法的 UTF-8、C0／C1 控制字元、
	 * Unicode 方向控制字元拿掉，截到 60 字。
	 */
	public static function sanitize_username( string $username ): string {
		if ( 1 !== preg_match( '//u', $username ) ) {
			// 不合法的 UTF-8：WordPress 6.9 起有 wp_scrub_utf8()；更舊的版本 wp_check_invalid_utf8( …, true ) 走 iconv，
			// 遇到不合法的位元組會報 notice、回 false，所以改用 mb_scrub()（換成 ?）。
			$username = function_exists( 'wp_scrub_utf8' ) ? (string) wp_scrub_utf8( $username ) : ( function_exists( 'mb_scrub' ) ? (string) mb_scrub( $username, 'UTF-8' ) : '' );
		}
		$username = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $username );
		return function_exists( 'mb_substr' ) ? mb_substr( $username, 0, 60 ) : substr( $username, 0, 60 );
	}

	/** 表是 utf8mb3 時拿掉 4 位元組字元（strip_invalid_text_for_column 失敗時整段不存）。 */
	private static function clean_text( string $table, string $column, string $value ): string {
		global $wpdb;
		if ( '' === $value || ! method_exists( $wpdb, 'strip_invalid_text_for_column' ) ) {
			return $value;
		}
		$clean = $wpdb->strip_invalid_text_for_column( $table, $column, $value );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * 執行一句會寫入的查詢；遇到死結（1213）或鎖等待逾時（1205）重試一次。
	 *
	 * @return int|bool wpdb::query 的回傳值。
	 */
	private static function query_with_retry( string $sql ) {
		global $wpdb;
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 呼叫的地方 prepare 過
		if ( false === $result && self::is_retryable() ) {
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 呼叫的地方 prepare 過
		}
		return $result;
	}

	private static function is_retryable(): bool {
		global $wpdb;
		$dbh = $wpdb->dbh ?? null;
		if ( $dbh instanceof \mysqli ) {
			$errno = mysqli_errno( $dbh );
			if ( in_array( $errno, [ 1213, 1205 ], true ) ) {
				return true;
			}
		}
		$error = (string) $wpdb->last_error;
		return str_contains( $error, 'Deadlock found' ) || str_contains( $error, 'Lock wait timeout' );
	}

	/**
	 * 查詢失敗：依原因分開記 health（設定頁、全後台提醒、WP-CLI status 看這幾筆；實作審查 A 線 P2-3、差異審查 SEC P3-2～4、CODE N2、N3）。
	 *
	 * - 表不存在（1146）：先用 SHOW TABLES 再確認一次（還原或搬家換表的那一瞬間，表可能已經回來了）。
	 *   - 計數表真的不在：記 query_failed（missing）、清掉版本鍵。之後當成「表還沒建好」，不再查這幾張表，
	 *     管理員下一次打開後台時 admin_init 重新建表。
	 *   - 計數表在、只少紀錄表或「鍵＋帳號」小表：版本鍵留著，計數與鎖定照常；記 table_missing，管理員打開後台時補表。
	 *   - 再確認時表都在，或 SHOW TABLES 本身也失敗：當成暫時性的錯誤，什麼都不清。
	 * - 死結、逾時、連線斷掉這類暫時性的錯誤：記 query_transient，帶最近一小時是哪幾分鐘
	 *   （YSLoginGuard::status() 一小時內有兩分鐘以上才算沒有正常作用；只有一次時只是提醒）。
	 * - 其他（沒有權限、不明的錯誤）：記 query_failed（privilege、other），最近一小時都算成沒有正常作用。
	 */
	private static function query_failed( string $where ): void {
		$code = self::error_code();
		if ( 1146 === $code ) {
			$counter = self::table_exists( YSLoginGuardSchema::counter_table() );
			if ( false === $counter ) {
				YSLoginGuardHealth::record( 'query_failed', [ 'where' => $where, 'code' => $code, 'kind' => 'missing' ] );
				delete_option( YSLoginGuardSettings::OPTION_DB_VERSION );
				return;
			}
			if ( true === $counter ) {
				$recent = YSLoginGuardHealth::get( 'table_missing' );
				if ( null !== $recent && time() - (int) $recent['last'] < YSLoginGuardHealth::THROTTLE ) {
					return; // 剛記過：不用每一句失敗都再查一次
				}
				$gone = [];
				foreach ( [ YSLoginGuardSchema::acct_table(), YSLoginGuardSchema::log_table() ] as $table ) {
					if ( false === self::table_exists( $table ) ) {
						$gone[] = $table;
					}
				}
				if ( [] !== $gone ) {
					YSLoginGuardHealth::record( 'table_missing', [ 'where' => $where, 'tables' => $gone ], true );
					return;
				}
			}
			self::record_transient( $where, $code );
			return;
		}
		if ( in_array( $code, self::TRANSIENT_ERRORS, true ) ) {
			self::record_transient( $where, $code );
			return;
		}
		YSLoginGuardHealth::record( 'query_failed', [ 'where' => $where, 'code' => $code, 'kind' => in_array( $code, self::PRIVILEGE_ERRORS, true ) ? 'privilege' : 'other' ] );
	}

	/** 暫時性的失敗：記這一分鐘（最近一小時的、最多 10 筆）。health 同一個原因每分鐘最多寫一次。 */
	private static function record_transient( string $where, int $code ): void {
		$now    = time();
		$row    = YSLoginGuardHealth::get( 'query_transient' );
		$recent = [];
		foreach ( (array) ( $row['data']['recent'] ?? [] ) as $t ) {
			if ( is_numeric( $t ) && $now - (int) $t < self::TRANSIENT_WINDOW ) {
				$recent[] = (int) $t;
			}
		}
		$recent[] = $now;
		YSLoginGuardHealth::record( 'query_transient', [ 'where' => $where, 'code' => $code, 'recent' => array_slice( $recent, -self::TRANSIENT_KEEP ) ] );
	}

	/**
	 * 剛才那一句失敗的錯誤代碼：mysqli 的 errno；不是 mysqli（替身、其他驅動）時從錯誤訊息認幾種常見的。認不出來是 0。
	 * 要在任何其他查詢之前讀（之後的查詢會蓋掉 errno 與 last_error）。
	 */
	private static function error_code(): int {
		global $wpdb;
		$dbh = $wpdb->dbh ?? null;
		if ( $dbh instanceof \mysqli && (int) mysqli_errno( $dbh ) > 0 ) {
			return (int) mysqli_errno( $dbh );
		}
		$error = (string) ( $wpdb->last_error ?? '' );
		$known = [
			"/^Table '[^']+' doesn't exist/" => 1146,
			'/^Deadlock found/'              => 1213,
			'/^Lock wait timeout exceeded/'  => 1205,
			'/server has gone away/i'        => 2006,
			'/^Lost connection to /'         => 2013,
			'/^Too many connections/'        => 1040,
			'/^\w+ command denied to user /' => 1142,
			'/^Access denied for user /'     => 1045,
		];
		foreach ( $known as $pattern => $code ) {
			if ( 1 === preg_match( $pattern, $error ) ) {
				return $code;
			}
		}
		return 0;
	}

	/**
	 * 這張表在不在（只在查詢因為「表不存在」失敗時再確認一次；大小寫不分，跟 ensure() 一樣）。SHOW TABLES 本身也失敗時回 null（不知道）。
	 */
	private static function table_exists( string $table ): ?bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return null;
		}
		return is_string( $found ) && 0 === strcasecmp( $found, $table );
	}
}
