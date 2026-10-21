<?php
/**
 * WP-CLI：登入次數限制（v1.6.0，設計 §4.10、§4.13.3）。防止把自己鎖在外面，也給客服查狀態用。
 *
 *   wp ys-admin-menu login-guard status [--repair]
 *   wp ys-admin-menu login-guard list [--locked] [--ip=<ip或鍵>] [--format=table|json]
 *   wp ys-admin-menu login-guard unlock <ip或鍵> | --all
 *   wp ys-admin-menu login-guard allowlist <list|add|remove> [<ip或cidr>]
 *   wp ys-admin-menu login-guard enable | disable
 *   wp ys-admin-menu login-guard cleanup
 *   wp ys-admin-menu login-guard log [--since=<時間>] [--ip=<ip或鍵>] [--format=table|json]
 *   wp ys-admin-menu login-guard purge --drop-tables [--yes]
 *   wp ys-admin-menu login-guard xmlrpc <on|off>
 *   wp ys-admin-menu login-guard app-passwords <on|off>
 *
 * WP-CLI 本身不會被判斷或計數（沒有遠端的用戶端，§4.4.1）。輸出裡從資料庫來的字串（帳號名稱、來源）都先清掉控制字元，
 * 不會變成終端機的控制序列（審查 P2-9）。list 與 log 的 --format=json 用英文鍵、時間是 UNIX 秒（給腳本用）；表格是中文（給人看）。
 *
 * @package YangSheep\AdminMenu\Cli
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Cli;

use YangSheep\AdminMenu\Security\YSClientIp;
use YangSheep\AdminMenu\Security\YSLoginEntrances;
use YangSheep\AdminMenu\Security\YSLoginGuard;
use YangSheep\AdminMenu\Security\YSLoginGuardCapacity;
use YangSheep\AdminMenu\Security\YSLoginGuardHealth;
use YangSheep\AdminMenu\Security\YSLoginGuardNotices;
use YangSheep\AdminMenu\Security\YSLoginGuardSchema;
use YangSheep\AdminMenu\Security\YSLoginGuardSettings;
use YangSheep\AdminMenu\Security\YSLoginGuardStore;

defined( 'ABSPATH' ) || exit;

/**
 * 登入次數限制：狀態、解鎖、白名單、開關、清理、紀錄、刪表。
 */
final class YSLoginGuardCommand {

	/** purge 會刪掉的內部鍵（跟 uninstall.php 同一份）。 */
	public const INTERNAL_OPTIONS = [
		YSLoginGuardSettings::OPTION_DB_VERSION,
		YSLoginGuardSettings::OPTION_LAST_CLEANUP,
		YSLoginGuardSettings::OPTION_HEALTH,
		YSLoginGuardSettings::OPTION_NOTIFY_STATE,
		YSLoginGuardSettings::OPTION_CROSS_SITE_SINCE,
	];

	/**
	 * 顯示開關、常數、資料表版本、IP 偵測、是否有正常作用與原因、鎖定中幾個、最近 24 小時的統計與提醒。
	 *
	 * ## OPTIONS
	 *
	 * [--repair]
	 * : 資料表不在或版本不對時重新建表。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard status
	 *     wp ys-admin-menu login-guard status --repair
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( $args, $assoc_args ): void {
		// 開關被別的工具改成不自動載入時，前台會先讀成「關」：在這裡改回自動載入（差異審查 SEC P3-1、CODE N1）。
		$healed = YSLoginGuardSettings::heal_autoload();
		if ( [] !== $healed ) {
			\WP_CLI::line( '這幾個設定原本不是自動載入（前台會讀成預設值），已經改回自動載入：' . implode( '、', $healed ) );
		}
		if ( isset( $assoc_args['repair'] ) ) {
			$ok = YSLoginGuardSchema::ensure( true ); // 手動修復：不管建表失敗後 10 分鐘的冷卻
			\WP_CLI::line( $ok ? '資料表已經是目前的版本。' : '建表失敗（原因記在健康狀態裡；請確認資料庫帳號有建表的權限）。' );
		}
		$now    = time();
		$ready  = YSLoginGuardSchema::is_ready();
		$status = $ready ? YSLoginGuard::status() : [ 'effective' => false, 'reasons' => [ 'table_not_ready' ], 'stats' => [] ];
		$stats  = (array) ( $status['stats'] ?? [] );
		$locked = $ready ? count( YSLoginGuardStore::list_rows( $now, true, '', 1000 ) ) : 0;
		$since  = YSLoginGuardSettings::cross_site_since();
		$rows   = [
			[ '項目' => '總開關', '值' => YSLoginGuardSettings::enabled() ? '開' : '關' ],
			[ '項目' => '停用常數 YS_ADMIN_MENU_LOGIN_GUARD_DISABLED', '值' => YSLoginGuardSettings::disabled_by_constant() ? '有（整個功能不作用）' : '沒有' ],
			[ '項目' => '資料表', '值' => $ready ? '版本 ' . YSLoginGuardSchema::DB_VERSION . '，正常' : '不在或版本不對（照常可以登入；用 --repair 重建）' ],
			[ '項目' => 'IP 偵測方式', '值' => YSClientIp::get_source() . '；信任 Cloudflare：' . ( YSClientIp::trust_cloudflare() ? '是' : '否' ) . '；信任的代理 ' . count( YSClientIp::get_trusted() ) . ' 段' ],
			[ '項目' => '有正常作用', '值' => ! empty( $status['effective'] ) ? '是' : '否（' . implode( '、', array_map( [ self::class, 'reason' ], (array) $status['reasons'] ) ) . '）' ],
			[ '項目' => '鎖定中', '值' => (string) $locked ],
			[ '項目' => '最近一輪的失敗', '值' => (string) (int) ( $stats['failures'] ?? 0 ) . '（記到共用桶 ' . (int) ( $stats['shared'] ?? 0 ) . '、外站 Worker ' . (int) ( $stats['cf_worker'] ?? 0 ) . '）' ],
			[ '項目' => '跨站判斷', '值' => ! YSLoginGuardSettings::active() ? '不作用（總開關關著或有停用常數）' : ( ! YSLoginGuardSettings::cross_site_reject() ? '關' : ( YSLoginGuard::observing( $now ) ? '觀察中，到 ' . wp_date( 'Y-m-d H:i', $since + YSLoginGuardSettings::OBSERVE_SECONDS ) . ' 開始拒絕' : '拒絕中' ) ) ],
			[ '項目' => '關閉 XML-RPC 登入', '值' => YSLoginEntrances::xmlrpc_login_disabled() ? '開' : '關' ],
			[ '項目' => '停用應用程式密碼', '值' => YSLoginEntrances::app_passwords_disabled() ? '開' : '關' ],
		];
		\WP_CLI\Utils\format_items( 'table', $rows, [ '項目', '值' ] );
		foreach ( YSLoginGuardNotices::collect( $now ) as $notice ) {
			\WP_CLI::warning( self::clean( $notice['text'] . ( [] !== $notice['items'] ? ' ' . implode( '、', $notice['items'] ) : '' ) ) );
		}
	}

	/**
	 * 列出計數表（鎖定中的，或全部）。
	 *
	 * ## OPTIONS
	 *
	 * [--locked]
	 * : 只列鎖定中的。
	 *
	 * [--ip=<ip>]
	 * : 只看這個 IP 或鍵（例如 9.9.9.9、2001:db8::/64、via:internal）。
	 *
	 * [--format=<format>]
	 * : 輸出格式。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard list --locked
	 *
	 * @subcommand list
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function list_( $args, $assoc_args ): void {
		self::require_ready();
		$filter = self::filter( (string) ( $assoc_args['ip'] ?? '' ) );
		$now    = time();
		$json   = 'json' === self::format( $assoc_args );
		$rows   = [];
		foreach ( YSLoginGuardStore::list_rows( $now, isset( $assoc_args['locked'] ), $filter, 1000 ) as $row ) {
			$until    = (int) $row['locked_until'];
			$key      = self::clean( (string) $row['ip_key'] );
			$label    = self::clean( YSLoginGuardNotices::describe_key( (string) $row['ip_key'] ) );
			$last_ip  = self::clean( YSClientIp::normalize( (string) $row['last_ip'] ) );
			$username = self::clean( YSLoginGuardStore::sanitize_username( (string) $row['last_username'] ) );
			$source   = self::clean( (string) $row['last_source'] );
			// --format=json 用英文鍵、時間給 UNIX 秒（給腳本用，差異審查 CODE P3-5）；終端機的表格照樣中文。
			$rows[] = $json
				? [
					'key'             => $key,
					'label'           => $label,
					'last_ip'         => $last_ip,
					'window_failures' => (int) $row['fail_count'],
					'total_failures'  => (int) $row['total_failures'],
					'locked_until'    => $until > $now ? $until : 0,
					'lockout_count'   => (int) $row['lockout_count'],
					'blocked_count'   => (int) $row['blocked_count'],
					'last_username'   => $username,
					'last_source'     => $source,
				]
				: [
					'鍵'         => $key,
					'說明'       => $label,
					'最後位址'   => $last_ip,
					'這段時間失敗' => (int) $row['fail_count'],
					'累計失敗'   => (int) $row['total_failures'],
					'鎖到'       => $until > $now ? wp_date( 'Y-m-d H:i', $until ) : '—',
					'被鎖次數'   => (int) $row['lockout_count'],
					'被擋'       => (int) $row['blocked_count'],
					'最後帳號'   => $username,
					'來源'       => $source,
				];
		}
		if ( [] === $rows ) {
			\WP_CLI::line( '沒有符合的列。' );
			return;
		}
		\WP_CLI\Utils\format_items( $json ? 'json' : 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * 解除鎖定（一個 IP 位址或鍵，或 --all）。
	 *
	 * IPv4 位址解它自己；IPv6 位址解它的 /64（打開 /48 彙總時一併解它的 /48）；也可以直接給鍵（via:internal、
	 * via:overflow:203.0.113.0/24、agg48:…）。
	 *
	 * ## OPTIONS
	 *
	 * [<target>]
	 * : IP 位址或鍵。
	 *
	 * [--all]
	 * : 全部解鎖。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard unlock 9.9.9.9
	 *     wp ys-admin-menu login-guard unlock 2001:4860:4860::8888
	 *     wp ys-admin-menu login-guard unlock via:internal
	 *     wp ys-admin-menu login-guard unlock --all
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function unlock( $args, $assoc_args ): void {
		self::require_ready();
		$now = time();
		if ( isset( $assoc_args['all'] ) ) {
			$n = YSLoginGuardStore::unlock_all( $now );
			YSLoginGuardStore::log_event( YSLoginGuardStore::EVENT_UNLOCK, [ 'code' => 'cli_all_' . $n ], $now );
			\WP_CLI::success( sprintf( '已經全部解鎖（%d 列）。', $n ) );
			return;
		}
		$keys = self::target_keys( (string) ( $args[0] ?? '' ) );
		if ( [] === $keys ) {
			\WP_CLI::error( '請給一個 IP 位址或鍵（例如 9.9.9.9、2001:db8::1、via:internal），或用 --all。' );
		}
		$done = 0;
		foreach ( $keys as $key ) {
			$n     = YSLoginGuardStore::unlock( $key );
			$done += $n;
			YSLoginGuardStore::log_event( YSLoginGuardStore::EVENT_UNLOCK, [ 'ip_key' => $key, 'code' => 'cli' ], $now );
			\WP_CLI::line( ( $n > 0 ? '已經解鎖：' : '沒有這個鍵的紀錄：' ) . $key );
		}
		if ( $done > 0 ) {
			\WP_CLI::success( '完成。可以用 list --locked 確認。' );
		}
	}

	/**
	 * 白名單：列出、加入、移除（跟設定頁同一套驗證：IPv4 至少 /8、IPv6 至少 /32）。
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list、add 或 remove。
	 * ---
	 * options:
	 *   - list
	 *   - add
	 *   - remove
	 * ---
	 *
	 * [<cidr>]
	 * : IP 或 CIDR。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard allowlist add 203.0.113.0/24
	 *     wp ys-admin-menu login-guard allowlist remove 203.0.113.0/24
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function allowlist( $args, $assoc_args ): void {
		$action = (string) ( $args[0] ?? 'list' );
		$raw    = get_option( YSLoginGuardSettings::OPTION_ALLOWLIST, '' );
		$lines  = YSClientIp::parse_list( is_string( $raw ) ? $raw : '' )['lines'];
		if ( 'list' === $action ) {
			if ( [] === $lines ) {
				\WP_CLI::line( '白名單是空的。' );
				return;
			}
			foreach ( $lines as $line ) {
				\WP_CLI::line( $line );
			}
			return;
		}
		$parsed = YSClientIp::parse_list( trim( (string) ( $args[1] ?? '' ) ) );
		if ( 1 !== count( $parsed['lines'] ) || [] !== $parsed['invalid'] || [] !== $parsed['too_wide'] ) {
			\WP_CLI::error( '不是合法的 IP 或 CIDR，或網段太寬（IPv4 要 /8 以上、IPv6 要 /32 以上）。' );
		}
		$entry = $parsed['lines'][0];
		if ( 'add' === $action ) {
			if ( in_array( $entry, $lines, true ) ) {
				\WP_CLI::line( '已經在白名單裡：' . $entry );
				return;
			}
			if ( count( $lines ) >= YSClientIp::TRUSTED_MAX_LINES ) {
				\WP_CLI::error( '白名單已經 200 行。' );
			}
			$lines[] = $entry;
			update_option( YSLoginGuardSettings::OPTION_ALLOWLIST, implode( "\n", $lines ), false );
			if ( [] !== $parsed['wide'] ) {
				\WP_CLI::warning( '這是寬於 /16 的公開網段：那整段的位址都不會被計數。' );
			}
			\WP_CLI::success( '已經加入白名單：' . $entry . '（白名單裡的位址不計數、不鎖）' );
			return;
		}
		if ( 'remove' === $action ) {
			$kept = array_values( array_filter( $lines, static fn( $l ) => $l !== $entry ) );
			if ( count( $kept ) === count( $lines ) ) {
				\WP_CLI::line( '白名單裡沒有：' . $entry );
				return;
			}
			update_option( YSLoginGuardSettings::OPTION_ALLOWLIST, implode( "\n", $kept ), false );
			\WP_CLI::success( '已經從白名單移除：' . $entry );
			return;
		}
		\WP_CLI::error( '只收 list、add、remove。' );
	}

	/**
	 * 打開登入次數限制（會建表；跨站判斷從這時候開始 24 小時觀察期）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard enable
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function enable( $args, $assoc_args ): void {
		$old = [ 'enabled' => YSLoginGuardSettings::enabled(), 'cross_site_reject' => YSLoginGuardSettings::cross_site_reject() ];
		update_option( YSLoginGuardSettings::OPTION_ENABLED, 'yes', true );
		YSLoginGuardSettings::seed_switches(); // 另一個開關還沒有時寫預設值：前台讀它才不用查資料庫
		YSLoginGuard::settings_changed( $old, [ 'enabled' => true, 'cross_site_reject' => YSLoginGuardSettings::cross_site_reject() ] );
		// settings_changed() 在「關到開」或「開著但表不可用」時已經建表（手動，不管冷卻）。
		if ( ! YSLoginGuardSchema::is_ready() ) {
			\WP_CLI::warning( '總開關已經打開，但建表失敗：目前不作用，照常可以登入。請用 status 看原因。' );
		} elseif ( YSLoginGuardSettings::disabled_by_constant() ) {
			\WP_CLI::warning( '總開關已經打開，但 wp-config.php 定義了 YS_ADMIN_MENU_LOGIN_GUARD_DISABLED：拿掉那一行之前都不作用。' );
		} else {
			\WP_CLI::success( '登入次數限制已經打開。' );
		}
	}

	/**
	 * 關閉登入次數限制（不刪表、不刪紀錄）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard disable
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function disable( $args, $assoc_args ): void {
		update_option( YSLoginGuardSettings::OPTION_ENABLED, 'no', true );
		YSLoginGuardSettings::seed_switches();
		\WP_CLI::success( '登入次數限制已經關閉（資料表與紀錄留著；要刪請用 purge --drop-tables）。' );
	}

	/**
	 * 立刻清理（同一時間只有一個在清）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard cleanup
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function cleanup( $args, $assoc_args ): void {
		self::require_ready();
		$result = YSLoginGuardCapacity::run_cleanup( time(), 'manual' );
		if ( 'done' !== $result['result'] ) {
			\WP_CLI::warning( '別的請求正在清理，這次沒有做；稍後再試。' );
			return;
		}
		$s = (array) $result['stats'];
		\WP_CLI::success(
			sprintf(
				'清理完成：計數表刪 %d 列（照上限刪 %d 列）、「鍵＋帳號」刪 %d 列、紀錄刪 %d 筆%s。',
				(int) ( $s['counter'] ?? 0 ),
				(int) ( $s['cap'] ?? 0 ),
				(int) ( $s['acct'] ?? 0 ),
				(int) ( $s['log'] ?? 0 ),
				empty( $s['done'] ) ? '（時間到了，還沒清完，下次會接著清）' : ''
			)
		);
	}

	/**
	 * 最近的紀錄。
	 *
	 * ## OPTIONS
	 *
	 * [--since=<since>]
	 * : 多久以內，例如 30m、24h、7d。
	 * ---
	 * default: 24h
	 * ---
	 *
	 * [--ip=<ip>]
	 * : 只看這個 IP 或鍵。
	 *
	 * [--format=<format>]
	 * : 輸出格式。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard log --since=24h --ip=9.9.9.9
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function log( $args, $assoc_args ): void {
		self::require_ready();
		$seconds = self::since_seconds( (string) ( $assoc_args['since'] ?? '24h' ) );
		if ( null === $seconds ) {
			\WP_CLI::error( '--since 要寫成數字加 m、h 或 d，例如 24h。' );
		}
		$filter = self::filter( (string) ( $assoc_args['ip'] ?? '' ) );
		$logs   = YSLoginGuardStore::recent_logs( $filter, time() - $seconds, 1, 500 );
		$json   = 'json' === self::format( $assoc_args );
		$rows   = [];
		foreach ( $logs['rows'] as $log ) {
			// --format=json 用英文鍵、時間給 UNIX 秒、code 與 origin 分開（差異審查 CODE P3-5）；終端機的表格照樣中文。
			$code   = self::clean( (string) $log['code'] );
			$origin = self::clean( (string) $log['origin'] );
			$rows[] = $json
				? [
					'created_at' => (int) $log['created_at'],
					'event'      => self::clean( (string) $log['event'] ),
					'ip'         => self::clean( YSClientIp::normalize( (string) $log['ip'] ) ),
					'key'        => self::clean( (string) $log['ip_key'] ),
					'username'   => self::clean( YSLoginGuardStore::sanitize_username( (string) $log['username'] ) ),
					'source'     => self::clean( (string) $log['source'] ),
					'code'       => $code,
					'origin'     => $origin,
				]
				: [
					'時間' => wp_date( 'Y-m-d H:i:s', (int) $log['created_at'] ),
					'事件' => self::clean( (string) $log['event'] ),
					'IP'   => self::clean( YSClientIp::normalize( (string) $log['ip'] ) ),
					'鍵'   => self::clean( (string) $log['ip_key'] ),
					'帳號' => self::clean( YSLoginGuardStore::sanitize_username( (string) $log['username'] ) ),
					'來源' => self::clean( (string) $log['source'] ),
					'說明' => $code . ( '' !== $origin ? ' ' . $origin : '' ),
				];
		}
		if ( [] === $rows ) {
			\WP_CLI::line( '沒有紀錄。' );
			return;
		}
		\WP_CLI\Utils\format_items( $json ? 'json' : 'table', $rows, array_keys( $rows[0] ) );
		if ( $logs['total'] > count( $rows ) ) {
			\WP_CLI::line( sprintf( '（只列最新的 %d 筆，共 %d 筆）', count( $rows ), (int) $logs['total'] ) );
		}
	}

	/**
	 * 刪掉登入次數限制的三張資料表與內部用的鍵（降版前用）。不能復原；沒帶 --yes 只印會刪什麼、不動手。設定不動。
	 *
	 * ## OPTIONS
	 *
	 * [--drop-tables]
	 * : 要刪資料表（一定要帶，確認你知道要做什麼；WP-CLI 的旗標只能寫成選填，沒帶時指令自己報錯）。
	 *
	 * [--yes]
	 * : 真的刪。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard purge --drop-tables
	 *     wp ys-admin-menu login-guard purge --drop-tables --yes
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function purge( $args, $assoc_args ): void {
		if ( ! isset( $assoc_args['drop-tables'] ) ) {
			\WP_CLI::error( '要加 --drop-tables（會刪掉三張資料表與內部用的鍵，不能復原）。' );
		}
		$tables = YSLoginGuardSchema::tables();
		\WP_CLI::line( '會刪掉的資料表：' . implode( '、', $tables ) );
		\WP_CLI::line( '會刪掉的內部鍵：' . implode( '、', self::INTERNAL_OPTIONS ) );
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::line( '沒有帶 --yes：什麼都沒刪。確定要刪請加 --yes。' );
			return;
		}
		YSLoginGuardSchema::drop();
		foreach ( self::INTERNAL_OPTIONS as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( YSLoginGuard::CRON_HOOK );
		\WP_CLI::success( '已經刪掉。設定沒有動；總開關還開著的話，下一次有管理員打開後台時會重新建表。' );
	}

	/**
	 * 關閉 XML-RPC 登入（獨立開關，跟總開關無關）。
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on 或 off。
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard xmlrpc on
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function xmlrpc( $args, $assoc_args ): void {
		$on = self::on_off( (string) ( $args[0] ?? '' ) );
		update_option( YSLoginEntrances::OPTION_DISABLE_XMLRPC, $on ? 'yes' : 'no', false );
		\WP_CLI::success( $on ? '已經關閉 XML-RPC 登入。' : 'XML-RPC 登入恢復。' );
	}

	/**
	 * 停用應用程式密碼（獨立開關，跟總開關無關）。
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on（停用）或 off（恢復）。
	 * ---
	 * options:
	 *   - on
	 *   - off
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-guard app-passwords on
	 *
	 * @subcommand app-passwords
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function app_passwords( $args, $assoc_args ): void {
		$on = self::on_off( (string) ( $args[0] ?? '' ) );
		update_option( YSLoginEntrances::OPTION_DISABLE_APP_PASSWORDS, $on ? 'yes' : 'no', true );
		YSLoginGuardSettings::seed_switches(); // 總開關還沒有時寫預設值：前台讀它才不用查資料庫
		\WP_CLI::success( $on ? '已經停用應用程式密碼（已建立的保留，關掉就恢復）。' : '應用程式密碼恢復。' );
	}

	/* ─────────────────────────── 工具 ─────────────────────────── */

	/**
	 * 解鎖的目標：IP 位址（IPv6 解 /64，打開 /48 彙總時一併解 /48），或驗證過格式的鍵。
	 *
	 * @return string[]
	 */
	public static function target_keys( string $target ): array {
		$target = trim( $target );
		$ip     = YSClientIp::normalize( $target );
		if ( '' !== $ip && ! str_contains( $target, '/' ) ) {
			$keys = [ YSClientIp::key( $ip ) ];
			if ( str_contains( $ip, ':' ) && YSLoginGuardSettings::ipv6_aggregate() ) {
				$keys[] = 'agg48:' . YSClientIp::network( $ip, 48 );
			}
			return array_values( array_unique( array_filter( $keys ) ) );
		}
		$key = strtolower( $target );
		return YSLoginGuardStore::is_valid_key( $key ) ? [ $key ] : [];
	}

	/** --since 的秒數（30m、24h、7d；最多一年）；寫錯回 null。 */
	public static function since_seconds( string $since ): ?int {
		if ( 1 !== preg_match( '/^(\d{1,4})([mhd])$/D', trim( $since ), $m ) || 0 === (int) $m[1] ) {
			return null;
		}
		$unit = [ 'm' => 60, 'h' => 3600, 'd' => 86400 ][ $m[2] ];
		return min( 366 * 86400, (int) $m[1] * $unit );
	}

	/** 篩選參數：IP 或鍵的格式，不合法就報錯（不帶進查詢）。 */
	private static function filter( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}
		$ip = YSClientIp::normalize( $raw );
		if ( '' !== $ip ) {
			return $ip;
		}
		$key = strtolower( trim( $raw ) );
		if ( ! YSLoginGuardStore::is_valid_key( $key ) ) {
			\WP_CLI::error( '--ip 要是 IP 位址或鍵。' );
		}
		return $key;
	}

	/**
	 * 輸出到終端機前清掉控制字元（C0、C1、方向控制字元）與不合法的 UTF-8，太長的截斷（審查 P2-9）。
	 */
	public static function clean( string $value, int $max = 300 ): string {
		if ( 1 !== preg_match( '//u', $value ) ) {
			$value = function_exists( 'wp_scrub_utf8' ) ? (string) wp_scrub_utf8( $value ) : ( function_exists( 'mb_scrub' ) ? (string) mb_scrub( $value, 'UTF-8' ) : '' );
		}
		$value = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function on_off( string $value ): bool {
		if ( ! in_array( $value, [ 'on', 'off' ], true ) ) {
			\WP_CLI::error( '只收 on 或 off。' );
		}
		return 'on' === $value;
	}

	/** @param array<string,string> $assoc_args */
	private static function format( array $assoc_args ): string {
		return isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ? 'json' : 'table';
	}

	private static function require_ready(): void {
		if ( ! YSLoginGuardSchema::is_ready() ) {
			\WP_CLI::error( '登入次數限制的資料表還沒建立或版本不對。用 enable 或 status --repair 建表。' );
		}
	}

	private static function reason( string $reason ): string {
		$labels = [
			'table_not_ready' => '資料表不可用',
			'query_failed'    => '最近一小時有查詢失敗（原因見下面的提醒）',
			'query_unstable'  => '最近一小時資料庫查詢一再暫時失敗（資料庫太忙或連線中斷）',
			'shared_bucket'   => '最近的失敗大多分不出訪客 IP（IP 偵測可能沒設好）',
		];
		return $labels[ $reason ] ?? $reason;
	}
}
