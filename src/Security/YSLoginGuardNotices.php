<?php
/**
 * 登入次數限制給管理員看的說明與提醒（v1.6.0，設計 §4.8～§4.10）。
 *
 * - describe_key()：鍵給人看的說明（共用桶寫明是哪一條路、overflow 分桶寫明網段）。
 * - collect()：全後台的健康提醒（只在總開關打開、常數沒停用時）：資料表不可用、查詢失敗（計數表不見、表不存在而沒辦法再確認、
 *   沒有權限或不明的錯誤）、
 *   暫時性的查詢失敗（一再發生才是 error）、只少紀錄表或小表、IP 偵測沒設好（effective＝false）、
 *   最近一小時有記到 overflow、全站 overflow、記列數的保留列不見了、外站 Worker、被拒的跨站來源一天超過 5 次（全站一天算一次）、
 *   通知信寄不出去、清理出錯。
 * - 只產生純文字；輸出的人（設定頁、admin_notices、信件、WP-CLI）負責跳脫或清理。給使用者看的文字只講狀況、限制與怎麼補強，
 *   不寫攻擊要怎麼做，也不寫門檻的數字（設計 r7 §4.12）。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardNotices {

	/** 白牌設定頁的 slug（跟 YSMenuPage::SLUG_SETTINGS 相同；這裡不引用後台類別，WP-CLI 與前台也能用）。 */
	public const SETTINGS_PAGE = 'ys-admin-menu-whitelabel';

	/** overflow、保留列的提醒看最近多久；外站 Worker、寄信失敗看最近多久（秒）。 */
	public const RECENT_SECONDS = 3600;
	public const DAY_SECONDS    = 86400;

	/** 被拒的跨站來源：一天超過幾次要提醒、列出前幾個（§4.13.1，r5 審查 R4-P2-3）。 */
	public const XSITE_MIN = 5;
	public const XSITE_TOP = 5;

	/** overflow 提醒最多列出幾個被鎖住的網段。 */
	public const OVERFLOW_LIST = 10;

	/** 設定頁「登入防護」分頁的網址（可以帶錨點）。 */
	public static function settings_url( string $anchor = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE . '&tab=turnstile' );
		return '' === $anchor ? $url : $url . '#' . rawurlencode( $anchor );
	}

	/** 這個鍵是不是共用的桶（via: 開頭，包括 overflow 分桶與全站的 via:overflow）。 */
	public static function is_shared( string $key ): bool {
		return 0 === strpos( $key, 'via:' );
	}

	/**
	 * 鍵給人看的說明。共用桶寫明是哪一條路；overflow 分桶寫明網段；IPv6 寫明是網段。
	 */
	public static function describe_key( string $key ): string {
		$fixed = [
			'via:cloudflare' => '從 Cloudflare 的位址進來、沒有用到訪客 IP 的登入（併成一桶）',
			'via:internal'   => '從內部網路位址直接連進來的登入（併成一桶）',
			'via:proxy'      => '經過信任的代理、但沒有帶到訪客位址的登入（併成一桶）',
			'via:unknown'    => '伺服器沒有給出訪客位址的登入（併成一桶）',
			'via:cf-worker'  => '其他網站的 Cloudflare Worker 送來的登入（併成一桶）',
			YSLoginGuardCapacity::OVERFLOW_GLOBAL => '計數空間用完之後的登入（只計數、不鎖）',
		];
		if ( isset( $fixed[ $key ] ) ) {
			return $fixed[ $key ];
		}
		if ( 0 === strpos( $key, YSLoginGuardCapacity::OVERFLOW_PREFIX ) ) {
			return '網段 ' . substr( $key, strlen( YSLoginGuardCapacity::OVERFLOW_PREFIX ) ) . ' 裡還沒有自己紀錄的登入（併成一桶）';
		}
		if ( 0 === strpos( $key, 'agg48:' ) ) {
			return 'IPv6 網段 ' . substr( $key, 6 ) . '（/48 彙總）';
		}
		if ( str_contains( $key, '/' ) ) {
			return 'IPv6 網段 ' . $key;
		}
		return $key;
	}

	/**
	 * 全後台的提醒。只在總開關打開、常數沒有停用時才有。
	 *
	 * @return array<int, array{level:string,code:string,text:string,items:string[]}> level：error、warning、info。
	 */
	public static function collect( int $now ): array {
		if ( ! YSLoginGuardSettings::active() ) {
			return [];
		}
		if ( ! YSLoginGuardSchema::is_ready() ) {
			return [ self::notice( 'error', 'table_not_ready', '登入次數限制已經打開，但資料表還沒建好或版本不對，目前沒有作用（照常可以登入）。管理員打開後台時會重試（建表失敗之後隔 10 分鐘），在設定頁按儲存會馬上重試；一直出現時，請確認資料庫帳號有建表的權限。' ) ];
		}
		$out    = [];
		$status = YSLoginGuard::status();
		if ( in_array( 'query_failed', $status['reasons'], true ) ) {
			$out[] = self::notice( 'error', 'query_failed', self::query_failed_text() );
		}
		// 死結、逾時、連線斷掉這類暫時性的錯誤：一小時內一再發生才是 error；只有一分鐘時只提醒，不說成表被刪掉（差異審查 SEC P3-4、CODE N2）。
		if ( in_array( 'query_unstable', $status['reasons'], true ) ) {
			$out[] = self::notice( 'error', 'query_unstable', self::query_unstable_text() );
		} elseif ( self::recent( 'query_transient', $now, self::RECENT_SECONDS ) ) {
			$out[] = self::notice( 'warning', 'query_transient', self::query_transient_text() );
		}
		// 計數表在、只少紀錄表或「鍵＋帳號」小表：計數與鎖定照常，管理員打開後台時補表（差異審查 SEC P3-2、CODE N3）。
		if ( self::recent( 'table_missing', $now, self::DAY_SECONDS ) ) {
			$tables = array_values( array_filter( (array) ( YSLoginGuardHealth::get( 'table_missing' )['data']['tables'] ?? [] ), 'is_string' ) );
			$out[]  = self::notice( 'warning', 'table_missing', '登入防護有資料表不見了：計數與鎖定照常，紀錄或「只原諒同一個帳號」暫時不作用。管理員下一次打開後台會自動補回來，在設定頁按儲存也會馬上補。不見的表：', $tables );
		}
		if ( in_array( 'shared_bucket', $status['reasons'], true ) ) {
			$key   = (string) ( YSLoginGuardHealth::get( 'shared_bucket' )['data']['key'] ?? '' );
			$path  = '' !== $key && self::is_shared( $key ) ? self::describe_key( $key ) : '分不出訪客位址的登入';
			$out[] = self::notice( 'error', 'shared_bucket', '登入失敗大多是「' . $path . '」，IP 偵測可能沒設好，目前併在一起算。達到門檻時，從這條路登入的人會一起暫停。請到「進階：訪客 IP」確認偵測方式。' );
		}
		if ( self::recent( 'overflow_global', $now, self::RECENT_SECONDS ) ) {
			$out[] = self::notice( 'error', 'overflow_global', '登入失敗分散在非常多個網段，超出計數空間的部分只計數、不擋人：以 IP 計數的限制擋不住這麼分散的攻擊。請打開 Cloudflare Turnstile，或在主機、Cloudflare 加 WAF 規則。' );
		}
		if ( self::recent( 'overflow', $now, self::RECENT_SECONDS ) ) {
			$items = [];
			foreach ( YSLoginGuardStore::list_rows( $now, true, '', 200 ) as $row ) {
				$key = (string) $row['ip_key'];
				if ( 0 === strpos( $key, YSLoginGuardCapacity::OVERFLOW_PREFIX ) && count( $items ) < self::OVERFLOW_LIST ) {
					$items[] = substr( $key, strlen( YSLoginGuardCapacity::OVERFLOW_PREFIX ) );
				}
			}
			$out[] = self::notice( 'warning', 'overflow', '最近一小時有登入失敗改用網段一起計算（計數空間滿了，或同一個網段的位址太多）。表裡沒有自己紀錄的人，會跟同網段的人一起算。' . ( [] === $items ? '' : '目前被鎖住的網段：' ), $items );
		}
		// 保留列讀不到是因為查詢失敗時，上面 query_failed 那一條已經說了；這一條只講真的不見、補回來的情況。
		$missing_row = YSLoginGuardHealth::get( 'reserved_row_missing' );
		$missing_now = null !== $missing_row && $now - (int) $missing_row['last'] < self::RECENT_SECONDS && 'query' !== ( $missing_row['data']['why'] ?? '' );
		if ( $missing_now || self::recent( 'reserved_row_recreated', $now, self::RECENT_SECONDS ) ) {
			$out[] = self::notice( 'warning', 'reserved', '登入防護的計數資料有一部分不見了，已經改用最保守的方式處理並重建。' );
		}
		if ( self::recent( 'cf_worker', $now, self::DAY_SECONDS ) ) {
			$out[] = self::notice( 'warning', 'cf_worker', '最近有其他網站的 Cloudflare Worker 送來登入失敗，已經併成一桶計算（白名單對它們不適用）。如果是你們自己另一個網域的 Worker，請用 ys_admin_menu_ip_own_domains 這個 filter 把那個網域加進本站的網域。' );
		}
		$top = self::xsite_summary( $now );
		if ( [] !== $top ) {
			$items = [];
			foreach ( $top as $src ) {
				$items[] = (string) ( $src['origin'] ?? '' ) . '（' . (int) ( $src['count'] ?? 0 ) . ' 次）';
			}
			$out[] = self::notice( 'info', 'xsite', '有來源一天被「拒絕從其他網站送來的登入」擋下（或觀察期內本來會擋下）超過 5 次。如果是你們自己的前台，請到「鎖定與紀錄」按「允許這個來源」：', $items );
		}
		if ( self::recent( 'notify_failed', $now, self::DAY_SECONDS ) ) {
			$out[] = self::notice( 'warning', 'notify_failed', '鎖定通知信寄不出去，請確認網站的寄信設定。' );
		}
		if ( self::recent( 'cleanup_failed', $now, self::DAY_SECONDS ) ) {
			$out[] = self::notice( 'warning', 'cleanup_failed', '登入防護的自動清理出錯，已經略過，登入不受影響；之後會再試。' );
		}
		return $out;
	}

	/**
	 * 被拒的跨站來源每日彙總：一天裡被拒超過 5 次的、前 5 個。一天算一次，結果存在 health（不是每次打開後台都查）。
	 *
	 * @return array<int, array{origin:string,count:int}>
	 */
	public static function xsite_summary( int $now ): array {
		$row = YSLoginGuardHealth::get( 'xsite_summary' );
		if ( null !== $row && $now - (int) $row['last'] < self::DAY_SECONDS ) {
			return array_values( array_filter( (array) ( $row['data']['top'] ?? [] ), 'is_array' ) );
		}
		$top = [];
		foreach ( YSLoginGuardStore::xsite_sources( $now - self::DAY_SECONDS, self::XSITE_TOP, self::XSITE_MIN ) as $src ) {
			$top[] = [ 'origin' => substr( (string) $src['origin'], 0, 255 ), 'count' => (int) $src['count'] ];
		}
		YSLoginGuardHealth::record( 'xsite_summary', [ 'top' => $top ], true );
		return $top;
	}

	/** 查詢失敗（不是暫時性的）：依原因說（設定頁卡片、全後台提醒共用；差異審查 SEC P3-4）。 */
	public static function query_failed_text(): string {
		$data = (array) ( YSLoginGuardHealth::get( 'query_failed' )['data'] ?? [] );
		$code = self::code_text( (int) ( $data['code'] ?? 0 ) );
		$tail = '失敗的那幾次沒有作用（照常可以登入）。';
		switch ( (string) ( $data['kind'] ?? '' ) ) {
			case 'privilege':
				return '登入次數限制最近一小時有查詢失敗：資料庫帳號沒有權限' . $code . '。' . $tail . '請確認資料庫帳號對登入防護的三張資料表有讀寫的權限。';
			case 'missing':
				return '登入次數限制最近一小時有查詢失敗：計數的資料表不見了' . $code . '。' . $tail . '管理員下一次打開後台會自動重建。';
			case 'unverified':
				// 資料庫回報表不存在、再確認時 SHOW TABLES 也失敗（最終核對 A 線 F4）：照實說，不說成資料庫太忙。
				return '登入次數限制最近一小時有查詢失敗：資料庫回報資料表不存在' . $code . '，再確認時查詢也失敗' . self::code_text( (int) ( $data['check'] ?? 0 ) ) . '，無法確定是哪一張。' . $tail . '管理員下一次打開後台會自動檢查並補建，在設定頁按儲存也會馬上補；一直出現時，請確認資料庫帳號可以執行 SHOW TABLES。';
			default:
				return '登入次數限制最近一小時有查詢失敗' . $code . '。' . $tail . '一直出現時，請確認資料庫。';
		}
	}

	/** 暫時性的查詢失敗一再發生（一小時內兩分鐘以上）。 */
	public static function query_unstable_text(): string {
		$code = (int) ( YSLoginGuardHealth::get( 'query_transient' )['data']['code'] ?? 0 );
		return '最近一小時資料庫查詢一再暫時失敗（資料庫太忙或連線中斷' . ( $code > 0 ? '，最後一次的錯誤碼 ' . $code : '' ) . '），失敗的那幾次沒有作用（照常可以登入）。請檢查資料庫的負載與連線。';
	}

	/** 暫時性的查詢失敗只有一分鐘：只提醒。 */
	public static function query_transient_text(): string {
		$code = (int) ( YSLoginGuardHealth::get( 'query_transient' )['data']['code'] ?? 0 );
		return '最近一小時有資料庫查詢暫時失敗（資料庫太忙或連線中斷' . ( $code > 0 ? '，錯誤碼 ' . $code : '' ) . '），失敗的那幾次照常可以登入。偶爾發生不用處理；一直出現時，請檢查資料庫的負載與連線。';
	}

	private static function code_text( int $code ): string {
		return $code > 0 ? '（錯誤碼 ' . $code . '）' : '';
	}

	private static function recent( string $reason, int $now, int $seconds ): bool {
		$row = YSLoginGuardHealth::get( $reason );
		return null !== $row && $now - (int) $row['last'] < $seconds;
	}

	/**
	 * @param string[] $items
	 * @return array{level:string,code:string,text:string,items:string[]}
	 */
	private static function notice( string $level, string $code, string $text, array $items = [] ): array {
		return [ 'level' => $level, 'code' => $code, 'text' => $text, 'items' => array_values( $items ) ];
	}
}
