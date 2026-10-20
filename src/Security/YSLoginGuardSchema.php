<?php
/**
 * 登入次數限制的資料表（v1.6.0，設計 §4.7.2、§4.7.4）：三張表的定義、唯一的建表入口 ensure()、刪表。
 *
 * - 計數表 {prefix}ys_admin_menu_login_guard：主鍵是 ip_key（IP 鍵、共用桶、/48 彙總、~ 開頭的保留列）。
 * - 「鍵＋帳號」小表 {prefix}ys_admin_menu_login_guard_acct：只原諒同一個帳號自己的失敗（§4.4.2）。
 * - 紀錄表 {prefix}ys_admin_menu_login_log：只有插入；origin 欄給被拒的跨站登入用。
 *
 * ensure() 只在有權限的地方呼叫（後台存設定、外掛啟用、admin_init 有 manage_options、WP-CLI），前台、wp-login.php、
 * REST、XML-RPC、cron、未登入的 admin-ajax 一律不建表。表的版本不對時整個功能不作用（記 health），照樣登得進去。
 *
 * 計數表的列數上限、依網段的 overflow 與立刻清理的互斥（設計 r5 審查中）在另外一顆提交。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardSchema {

	/** 資料表版本。表的結構改了就加一。 */
	public const DB_VERSION = '1';

	/** 建表失敗後多久內不再重試（秒，審查 P2-12）。 */
	public const RETRY_AFTER = 600;

	/**
	 * 保留列：建表時一起建好（INSERT IGNORE），清理開頭再補一次；加 1 一律用 INSERT … ON DUPLICATE KEY UPDATE。
	 * - ~log_budget：一般失敗紀錄的每分鐘預算。fail_count＝這一分鐘的失敗數，window_start＝這一分鐘，
	 *   total_failures＝換分鐘時暫存的上一分鐘失敗數。
	 * - ~log_budget_xsite：被拒的跨站登入自己的每分鐘預算（寫法相同）。
	 * - ~stat24：健康統計。window_start＝這一輪 24 小時的起點，fail_count＝失敗總數（每分鐘彙總一次），
	 *   total_failures＝記到共用桶（via:proxy、via:cloudflare、via:internal、via:unknown）的次數，
	 *   blocked_count＝從標頭取到內部或 CDN 位址的次數，lockout_count＝記到 via:cf-worker 的次數。
	 */
	public const RESERVED_ROWS = [ '~log_budget', '~log_budget_xsite', '~stat24' ];

	public static function counter_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ys_admin_menu_login_guard';
	}

	public static function acct_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ys_admin_menu_login_guard_acct';
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ys_admin_menu_login_log';
	}

	/** @return string[] */
	public static function tables(): array {
		return [ self::counter_table(), self::acct_table(), self::log_table() ];
	}

	/** 資料表是不是目前的版本（版本鍵自動載入，每次登入判斷都看它）。 */
	public static function is_ready(): bool {
		return self::DB_VERSION === (string) get_option( YSLoginGuardSettings::OPTION_DB_VERSION, '' );
	}

	/**
	 * dbDelta 格式的建表語句（每個欄位一行、PRIMARY KEY 後面兩個空白）。
	 *
	 * @return string[]
	 */
	public static function sql(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$counter = self::counter_table();
		$acct    = self::acct_table();
		$log     = self::log_table();

		return [
			"CREATE TABLE {$counter} (
  ip_key varchar(64) NOT NULL,
  last_ip varchar(45) NOT NULL DEFAULT '',
  fail_count int(10) unsigned NOT NULL DEFAULT 0,
  window_start int(10) unsigned NOT NULL DEFAULT 0,
  locked_until int(10) unsigned NOT NULL DEFAULT 0,
  lockout_count int(10) unsigned NOT NULL DEFAULT 0,
  lockout_period_start int(10) unsigned NOT NULL DEFAULT 0,
  blocked_count int(10) unsigned NOT NULL DEFAULT 0,
  last_blocked_at int(10) unsigned NOT NULL DEFAULT 0,
  total_failures int(10) unsigned NOT NULL DEFAULT 0,
  last_username varchar(60) NOT NULL DEFAULT '',
  last_source varchar(12) NOT NULL DEFAULT '',
  last_logged_at int(10) unsigned NOT NULL DEFAULT 0,
  last_notified_at int(10) unsigned NOT NULL DEFAULT 0,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ip_key),
  KEY locked_until (locked_until),
  KEY updated_at (updated_at)
) {$collate};",
			"CREATE TABLE {$acct} (
  ip_key varchar(64) NOT NULL,
  acct char(32) NOT NULL,
  fail_count int(10) unsigned NOT NULL DEFAULT 0,
  acct_window int(10) unsigned NOT NULL DEFAULT 0,
  updated_at int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ip_key,acct),
  KEY updated_at (updated_at)
) {$collate};",
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at int(10) unsigned NOT NULL DEFAULT 0,
  event varchar(12) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  ip_key varchar(64) NOT NULL DEFAULT '',
  username varchar(60) NOT NULL DEFAULT '',
  source varchar(12) NOT NULL DEFAULT '',
  code varchar(40) NOT NULL DEFAULT '',
  origin varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY ip_key_created (ip_key,created_at),
  KEY event_created (event,created_at)
) {$collate};",
		];
	}

	/**
	 * 唯一建表的地方（§4.7.4）。成功才把版本鍵寫成目前版本；失敗記 health，10 分鐘內不再重試。
	 */
	public static function ensure(): bool {
		global $wpdb;

		$failed = YSLoginGuardHealth::get( 'ensure_failed' );
		if ( null !== $failed && time() - $failed['last'] < self::RETRY_AFTER ) {
			return false;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade ) ) {
				require_once $upgrade;
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			YSLoginGuardHealth::record( 'ensure_failed', [ 'why' => 'no_dbdelta' ], true );
			return false;
		}

		try {
			foreach ( self::sql() as $sql ) {
				dbDelta( $sql );
			}
		} catch ( \Throwable $e ) {
			YSLoginGuardHealth::record( 'ensure_failed', [ 'why' => 'exception' ], true );
			return false;
		}

		foreach ( self::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				YSLoginGuardHealth::record( 'ensure_failed', [ 'why' => 'missing_table' ], true );
				return false;
			}
		}

		self::insert_reserved_rows();
		update_option( YSLoginGuardSettings::OPTION_DB_VERSION, self::DB_VERSION, true );
		YSLoginGuardHealth::clear( 'ensure_failed' );
		YSLoginGuardHealth::clear( 'table_not_ready' );
		return true;
	}

	/**
	 * 保留列一句 INSERT IGNORE 補齊（建表時、清理開頭）。已經存在的不動。
	 */
	public static function insert_reserved_rows(): void {
		global $wpdb;
		$rows = self::reserved_rows();
		if ( [] === $rows ) {
			return;
		}
		$values = implode( ', ', array_fill( 0, count( $rows ), '(%s, 0, 0)' ) );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::counter_table() . " (ip_key, created_at, updated_at) VALUES {$values}", $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名與佔位符是系統產生的
	}

	/**
	 * 這一版的保留列。
	 *
	 * @return string[]
	 */
	public static function reserved_rows(): array {
		return self::RESERVED_ROWS;
	}

	/** 刪掉三張表（解除安裝、purge --drop-tables）。 */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名是系統產生的
		}
		delete_option( YSLoginGuardSettings::OPTION_DB_VERSION );
	}
}
