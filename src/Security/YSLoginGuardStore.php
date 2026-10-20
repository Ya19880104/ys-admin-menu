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
 * - 紀錄：一般失敗每分鐘 120 筆、被拒的跨站登入每分鐘 20 筆（各自的保留列原子計數），每個 IP 每分鐘一筆；
 *   鎖定、解鎖、清空不受額度限制。
 *
 * 計數表的列數上限、依網段的 overflow、立刻清理與它的互斥在另外一顆提交（設計 r5 審查中）。
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

	/** 每分鐘的紀錄預算。 */
	public const LOG_BUDGET   = 120;
	public const XSITE_BUDGET = 20;

	/** 清理：一批刪幾列、一次最多跑多久（毫秒）、紀錄表最多留幾筆、記失敗時多久順手清一次（秒）。 */
	public const BATCH            = 5000;
	public const CLEANUP_MS       = 200;
	public const LOG_MAX          = 50000;
	public const CLEANUP_INTERVAL = 3600;

	/** 「鍵＋帳號」的 HMAC 用途前綴（審查 R3-P3-1）。 */
	public const ACCT_PREFIX = 'ys_admin_menu_lg_acct|';

	/* ─────────────────────────── 查詢 ─────────────────────────── */

	/**
	 * 一次有主鍵的查詢，取回這些鍵的列。
	 *
	 * @param string[] $keys
	 * @return null|array<string, array{locked_until:int,fail_count:int,window_start:int,lockout_count:int}> 查詢失敗回 null。
	 */
	public static function rows( array $keys ): ?array {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( $keys, static fn( $k ) => is_string( $k ) && '' !== $k ) ) );
		if ( [] === $keys ) {
			return [];
		}
		$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql  = $wpdb->prepare( 'SELECT ip_key, locked_until, fail_count, window_start, lockout_count FROM ' . YSLoginGuardSchema::counter_table() . " WHERE ip_key IN ({$in})", $keys ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與佔位符是系統產生的
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 上一行 prepare 過
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			self::query_failed( 'rows' );
			return null;
		}
		$out = [];
		foreach ( $rows as $row ) {
			$out[ (string) $row['ip_key'] ] = [
				'locked_until'  => (int) $row['locked_until'],
				'fail_count'    => (int) $row['fail_count'],
				'window_start'  => (int) $row['window_start'],
				'lockout_count' => (int) $row['lockout_count'],
			];
		}
		return $out;
	}

	/**
	 * 計數（設計 §4.7.2 的寫法）。
	 *
	 * @return null|array{count:int,inserted:bool} 加完的次數、這一句是不是新增了這個鍵。查詢失敗回 null。
	 */
	public static function count_failure( string $key, string $ip, string $source, int $now, int $window ): ?array {
		global $wpdb;
		$table   = YSLoginGuardSchema::counter_table();
		$expired = $now - $window;
		$sql     = $wpdb->prepare(
			"INSERT INTO {$table} (ip_key, last_ip, fail_count, window_start, total_failures, last_source, created_at, updated_at)
			VALUES (%s, %s, 1, %d, 1, %s, %d, %d)
			ON DUPLICATE KEY UPDATE
				fail_count = LAST_INSERT_ID( IF( window_start <= %d, 1, fail_count + 1 ) ),
				window_start = IF( window_start <= %d, %d, window_start ),
				total_failures = total_failures + 1,
				last_ip = %s,
				last_source = %s,
				updated_at = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
			$key,
			$ip,
			$now,
			$source,
			$now,
			$now,
			$expired,
			$expired,
			$now,
			$ip,
			$source,
			$now
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
	 */
	public static function acct_record( string $key, string $acct, int $window_start, int $now ): void {
		global $wpdb;
		$table = YSLoginGuardSchema::acct_table();
		$sql   = $wpdb->prepare(
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
		if ( false === self::query_with_retry( $sql ) ) {
			self::query_failed( 'acct' );
		}
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

	/**
	 * 這個鍵在目前的視窗裡，有幾個不同的（存在的）帳號失敗過（鎖定紀錄與通知用）。
	 */
	public static function acct_distinct( string $key, int $window_start ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . YSLoginGuardSchema::acct_table() . ' WHERE ip_key = %s AND acct_window = %d', $key, $window_start ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名是系統產生的
	}

	/* ─────────────────────────── 紀錄 ─────────────────────────── */

	/**
	 * 一般失敗的紀錄：每個 IP 鍵每分鐘一筆，加上全站每分鐘 120 筆。用完時只加計數。
	 * 不管有沒有寫紀錄，每一次失敗都算進 ~log_budget（換分鐘時彙總進 ~stat24 的失敗總數）。
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
	 * 這一分鐘的第一個請求把它讀回來：超過預算就寫一筆 omitted，一般失敗的另外併進 ~stat24。
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
	 * 目前這一輪的統計（健康狀態用）。
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
	 * 記失敗時順手清理：距上次一小時以上才做。先寫下時間再做，同一時間被很多請求觸發的機會比較小。
	 */
	public static function maybe_cleanup( int $now ): void {
		$last = (int) get_option( YSLoginGuardSettings::OPTION_LAST_CLEANUP, 0 );
		if ( $now - $last < self::CLEANUP_INTERVAL ) {
			return;
		}
		update_option( YSLoginGuardSettings::OPTION_LAST_CLEANUP, $now, false );
		self::cleanup( $now );
	}

	/**
	 * 清理：沒在鎖定中、而且很久沒動的列，視窗過了的「鍵＋帳號」，超過保留天數或超過 50,000 筆的紀錄。
	 * 一次可以連跑多輪 LIMIT 5000，時間上限 200 毫秒。保留列不刪，清理開頭先補齊。
	 *
	 * @return array{counter:int,acct:int,log:int,done:bool} 刪了幾列、有沒有在時間內清完。
	 */
	public static function cleanup( int $now ): array {
		global $wpdb;
		$deadline = microtime( true ) + self::CLEANUP_MS / 1000;
		YSLoginGuardSchema::insert_reserved_rows();
		update_option( YSLoginGuardSettings::OPTION_LAST_CLEANUP, $now, false );

		$keep    = max( YSLoginGuardSettings::window_seconds(), YSLoginGuardSettings::escalate_period_seconds(), 86400 );
		$counter = YSLoginGuardSchema::counter_table();
		$done    = true;
		$deleted = [ 'counter' => 0, 'acct' => 0, 'log' => 0 ];

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
		return $deleted + [ 'done' => $done ];
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
		$username = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $username );
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

	private static function query_failed( string $where ): void {
		YSLoginGuardHealth::record( 'query_failed', [ 'where' => $where ] );
	}
}
