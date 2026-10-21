<?php
/**
 * 登入次數限制的通知信（v1.6.0，設計 §4.9）。預設關閉（設定「鎖定時寄信通知」）。
 *
 * - 建立一次鎖定（ys_admin_menu_login_guard_locked，同一次鎖定只有一個請求會觸發）就排一封純文字信，在 shutdown 寄。
 * - 內容：時間、IP 或網段（共用桶寫明是哪一條路）、鎖到幾點、失敗次數、這個視窗內有幾個不同的帳號失敗、來源、
 *   設定頁連結、WP-CLI 解鎖指令。帳號只列站上存在的；不存在的只寫「另有 N 個不存在的帳號名稱」（審查 P2-10）。
 * - 節流：同一個鍵 24 小時一封（計數表 last_notified_at 的有條件 UPDATE）；全站每 24 小時十封（option 讀改寫，
 *   並發時可能多寄一兩封，可以接受）。
 * - 全站的 via:overflow 出現時（打開通知才寄）另寄一封，全站一天一封。
 * - 寄不出去不重試，只記 health。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardNotifier {

	/** 同一個鍵多久一封、全站多久最多幾封（秒、封）。 */
	public const PER_KEY_SECONDS = 86400;
	public const SITE_SECONDS    = 86400;
	public const SITE_MAX        = 10;

	/** 信裡最多列幾個存在的帳號。 */
	public const ACCOUNTS_MAX = 10;

	/** @var array<int, array<string, mixed>> 這個請求排好、等 shutdown 寄的信。 */
	private static array $queue = [];

	private static bool $hooked = false;

	public static function register(): void {
		add_action( 'ys_admin_menu_login_guard_locked', [ self::class, 'on_locked' ] );
		add_action( 'ys_admin_menu_login_guard_overflow', [ self::class, 'on_overflow' ] );
	}

	/** 清掉這個請求排好的信（每個請求開始時）。 */
	public static function reset(): void {
		self::$queue  = [];
		self::$hooked = false;
	}

	/**
	 * 建立了一次鎖定。
	 *
	 * @param mixed $lock array{key:string,ip:string,until:int,source:string,shared:bool,count?:int}
	 */
	public static function on_locked( $lock ): void {
		if ( ! is_array( $lock ) || ! YSLoginGuardSettings::notify() ) {
			return;
		}
		self::queue( [ 'type' => 'lock' ] + $lock );
	}

	/**
	 * 記到了 overflow：只有全站的 via:overflow 要寄。
	 *
	 * @param mixed $info array{key:string,global:bool,why:string}
	 */
	public static function on_overflow( $info ): void {
		if ( ! is_array( $info ) || empty( $info['global'] ) || ! YSLoginGuardSettings::notify() ) {
			return;
		}
		self::queue( [ 'type' => 'overflow_global' ] );
	}

	private static function queue( array $item ): void {
		self::$queue[] = $item;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', [ self::class, 'flush' ] );
		}
	}

	/** shutdown：寄出這個請求排好的信。 */
	public static function flush(): void {
		$items        = self::$queue;
		self::$queue  = [];
		self::$hooked = false;
		$now          = time();
		$global_sent  = false;
		foreach ( $items as $item ) {
			if ( 'overflow_global' === ( $item['type'] ?? '' ) ) {
				if ( ! $global_sent ) {
					$global_sent = true;
					self::send_overflow_global( $now );
				}
				continue;
			}
			self::send_lock( $item, $now );
		}
	}

	/**
	 * 一封鎖定通知。
	 *
	 * @param array<string, mixed> $lock
	 */
	private static function send_lock( array $lock, int $now ): void {
		$key = (string) ( $lock['key'] ?? '' );
		if ( ! YSLoginGuardStore::is_valid_key( $key ) || ! YSLoginGuardStore::claim_notify( $key, $now, self::PER_KEY_SECONDS ) || ! self::claim_site( $now, 'lock' ) ) {
			return;
		}
		$to = YSLoginGuardSettings::notify_email();
		if ( '' === $to ) {
			YSLoginGuardHealth::record( 'notify_failed', [ 'why' => 'no_recipient' ] );
			return;
		}
		[ $existing, $missing ] = self::accounts( $key, $now );
		$ip     = YSClientIp::normalize( (string) ( $lock['ip'] ?? '' ) );
		$until  = (int) ( $lock['until'] ?? 0 );
		$source = (string) preg_replace( '/[^a-z0-9_-]/', '', (string) ( $lock['source'] ?? '' ) );
		$lines  = [
			'網站「' . self::site_name() . '」的登入防護剛剛暫時鎖定了一個網路位址：',
			'',
			'時間：' . wp_date( 'Y-m-d H:i', $now ),
			'鎖定的對象：' . YSLoginGuardNotices::describe_key( $key ),
		];
		if ( '' !== $ip && ! YSLoginGuardNotices::is_shared( $key ) && $ip !== $key ) {
			$lines[] = '最後一次的位址：' . $ip;
		}
		$lines[] = '鎖到：' . wp_date( 'Y-m-d H:i', $until );
		$lines[] = '這段時間失敗的次數：' . (int) ( $lock['count'] ?? 0 );
		$lines[] = '這段時間有幾個不同的（站上存在的）帳號失敗：' . YSLoginGuardStore::acct_count( $key );
		if ( [] !== $existing ) {
			$lines[] = '嘗試過的帳號（只列站上存在的）：' . implode( '、', $existing );
		}
		if ( $missing > 0 ) {
			$lines[] = '另有 ' . $missing . ' 個不存在的帳號名稱。';
		}
		$lines[] = '來源：' . ( '' !== $source ? $source : 'other' );
		$lines[] = '';
		$lines[] = '已經登入的人不受影響。如果這是你們自己的人，可以到設定頁解鎖：';
		$lines[] = YSLoginGuardNotices::settings_url( 'ys-am-lg-records' );
		$lines[] = '或用 WP-CLI：wp ys-admin-menu login-guard unlock ' . $key;
		$lines[] = '';
		$lines[] = '（同一個位址 24 小時內只寄一封，全站每 24 小時最多 ' . self::SITE_MAX . ' 封。）';
		self::mail( $to, '[' . self::site_name() . '] 登入防護：已暫時鎖定一個網路位址', implode( "\n", $lines ) );
	}

	/** 全站的 via:overflow：全站一天一封。 */
	private static function send_overflow_global( int $now ): void {
		$state = self::state();
		if ( $now - (int) ( $state['overflow_global_at'] ?? 0 ) < self::SITE_SECONDS ) {
			return;
		}
		$state['overflow_global_at'] = $now;
		update_option( YSLoginGuardSettings::OPTION_NOTIFY_STATE, $state, false );
		$to = YSLoginGuardSettings::notify_email();
		if ( '' === $to ) {
			YSLoginGuardHealth::record( 'notify_failed', [ 'why' => 'no_recipient' ] );
			return;
		}
		$lines = [
			'網站「' . self::site_name() . '」的登入失敗分散在非常多個網段，超出計數空間的部分只計數、不擋人：以 IP 計數的限制擋不住這麼分散的攻擊。',
			'',
			'請打開 Cloudflare Turnstile，或在主機、Cloudflare 加 WAF 規則。',
			YSLoginGuardNotices::settings_url(),
			'',
			'（這封信全站一天最多寄一封。）',
		];
		self::mail( $to, '[' . self::site_name() . '] 登入防護：登入失敗來自非常多個網段', implode( "\n", $lines ) );
	}

	/**
	 * 這個鍵這段時間的帳號名稱：站上存在的列出（最多 10 個），不存在的只數個數。
	 *
	 * @return array{0:string[],1:int}
	 */
	private static function accounts( string $key, int $now ): array {
		$since    = $now - YSLoginGuardSettings::window_seconds() - YSLoginGuardSettings::lockout_seconds();
		$existing = [];
		$missing  = 0;
		foreach ( YSLoginGuardStore::log_usernames( $key, $since, 100 ) as $name ) {
			$user = get_user_by( 'login', $name );
			if ( ! $user instanceof \WP_User && is_email( $name ) ) {
				$user = get_user_by( 'email', $name );
			}
			if ( $user instanceof \WP_User ) {
				if ( count( $existing ) < self::ACCOUNTS_MAX ) {
					$existing[] = (string) $user->user_login;
				}
			} else {
				++$missing;
			}
		}
		return [ array_values( array_unique( $existing ) ), $missing ];
	}

	/** 全站每 24 小時最多十封（讀改寫；並發時可能多一兩封）。 */
	private static function claim_site( int $now, string $kind ): bool {
		$state = self::state();
		if ( $now - (int) ( $state['since'] ?? 0 ) >= self::SITE_SECONDS ) {
			$state['since'] = $now;
			$state['count'] = 0;
		}
		if ( (int) ( $state['count'] ?? 0 ) >= self::SITE_MAX ) {
			return false;
		}
		$state['count'] = (int) ( $state['count'] ?? 0 ) + 1;
		update_option( YSLoginGuardSettings::OPTION_NOTIFY_STATE, $state, false );
		return true;
	}

	/** @return array<string, int> */
	private static function state(): array {
		$raw = get_option( YSLoginGuardSettings::OPTION_NOTIFY_STATE, [] );
		$out = [];
		foreach ( [ 'since', 'count', 'overflow_global_at' ] as $field ) {
			$out[ $field ] = is_array( $raw ) && isset( $raw[ $field ] ) && is_numeric( $raw[ $field ] ) ? (int) $raw[ $field ] : 0;
		}
		return $out;
	}

	/** 純文字信用的網站名稱：blogname 存的是 esc_html 過的值，跟核心寄信一樣先解回來（「A &amp; B」寄成「A & B」；實作審查 A 線 P3-13）。 */
	private static function site_name(): string {
		$name = wp_strip_all_tags( wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ) );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private static function mail( string $to, string $subject, string $body ): void {
		$sent = wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		if ( ! $sent ) {
			YSLoginGuardHealth::record( 'notify_failed', [ 'why' => 'wp_mail' ] );
		}
	}
}
