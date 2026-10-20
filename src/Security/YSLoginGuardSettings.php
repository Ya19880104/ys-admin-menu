<?php
/**
 * 登入次數限制的設定（v1.6.0，設計 §4.1）：鍵名、預設值、範圍，與讀取時的檢查。
 *
 * - 讀到不合法的值（不是數字、超出範圍、不認得的字）一律照預設或夾到範圍內，不信任資料庫裡的值。
 * - 自動載入的只有總開關、內部的資料表版本與跨站判斷的開始時間；其他設定只在登入相關的請求與設定頁讀。
 * - 存檔與驗證（設定頁、WP-CLI）在之後的提交；這裡只負責讀。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardSettings {

	/** 總開關（yes／no，預設 no；自動載入：每個請求都要決定掛不掛 hook）。 */
	public const OPTION_ENABLED = 'ys_admin_menu_login_guard_enabled';

	public const OPTION_MAX_FAILURES      = 'ys_admin_menu_login_guard_max_failures';
	public const OPTION_WINDOW            = 'ys_admin_menu_login_guard_window';
	public const OPTION_LOCKOUT           = 'ys_admin_menu_login_guard_lockout';
	public const OPTION_ESCALATE          = 'ys_admin_menu_login_guard_escalate';
	public const OPTION_ESCALATE_AFTER    = 'ys_admin_menu_login_guard_escalate_after';
	public const OPTION_ESCALATE_PERIOD   = 'ys_admin_menu_login_guard_escalate_period';
	public const OPTION_ESCALATED_LOCKOUT = 'ys_admin_menu_login_guard_escalated_lockout';
	public const OPTION_IPV6_AGGREGATE    = 'ys_admin_menu_login_guard_ipv6_aggregate';
	public const OPTION_LOCK_PRIVATE      = 'ys_admin_menu_login_guard_lock_private';
	public const OPTION_CROSS_SITE_REJECT = 'ys_admin_menu_login_guard_cross_site_reject';
	public const OPTION_CROSS_ORIGINS     = 'ys_admin_menu_login_guard_cross_site_origins';
	public const OPTION_ALLOWLIST         = 'ys_admin_menu_login_guard_allowlist';
	public const OPTION_MESSAGE           = 'ys_admin_menu_login_guard_message';
	public const OPTION_NOTIFY            = 'ys_admin_menu_login_guard_notify';
	public const OPTION_NOTIFY_EMAIL      = 'ys_admin_menu_login_guard_notify_email';
	public const OPTION_RETENTION_DAYS    = 'ys_admin_menu_login_guard_retention_days';

	/** 內部：資料表版本（自動載入）。 */
	public const OPTION_DB_VERSION = 'ys_admin_menu_login_guard_db_version';

	/** 內部：上次清理的時間（UNIX 秒）。 */
	public const OPTION_LAST_CLEANUP = 'ys_admin_menu_login_guard_last_cleanup';

	/** 內部：健康狀態（YSLoginGuardHealth）。 */
	public const OPTION_HEALTH = 'ys_admin_menu_login_guard_health';

	/** 內部：通知的節流狀態。 */
	public const OPTION_NOTIFY_STATE = 'ys_admin_menu_login_guard_notify_state';

	/** 內部：跨站判斷開始的時間（觀察期從這裡算；自動載入；只在關到開時寫入，§4.13.1）。 */
	public const OPTION_CROSS_SITE_SINCE = 'ys_admin_menu_login_guard_cross_site_since';

	/** 停用常數：在 wp-config.php 定義成 true，整個登入次數限制不作用（兩個獨立開關不受影響）。 */
	public const DISABLE_CONSTANT = 'YS_ADMIN_MENU_LOGIN_GUARD_DISABLED';

	/** 數字設定：[ 預設, 最小, 最大 ]。window、lockout 是分鐘；escalate_period、escalated_lockout 是小時。 */
	public const RANGES = [
		'max_failures'      => [ 10, 3, 100 ],
		'window'            => [ 15, 1, 1440 ],
		'lockout'           => [ 30, 1, 10080 ],
		'escalate_after'    => [ 3, 2, 20 ],
		'escalate_period'   => [ 24, 1, 720 ],
		'escalated_lockout' => [ 24, 1, 720 ],
		'retention_days'    => [ 30, 1, 365 ],
	];

	/** 數字設定對應的鍵。 */
	private const INT_OPTIONS = [
		'max_failures'      => self::OPTION_MAX_FAILURES,
		'window'            => self::OPTION_WINDOW,
		'lockout'           => self::OPTION_LOCKOUT,
		'escalate_after'    => self::OPTION_ESCALATE_AFTER,
		'escalate_period'   => self::OPTION_ESCALATE_PERIOD,
		'escalated_lockout' => self::OPTION_ESCALATED_LOCKOUT,
		'retention_days'    => self::OPTION_RETENTION_DAYS,
	];

	/** 觀察期（秒）。 */
	public const OBSERVE_SECONDS = 86400;

	/** 鎖定時訊息的最長字數、允許跨站登入的來源最多幾行。 */
	public const MESSAGE_MAX = 200;
	public const ORIGINS_MAX = 50;

	/** 停用常數有沒有定義成 true。 */
	public static function disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && true === constant( self::DISABLE_CONSTANT );
	}

	/** 總開關（不看常數）。 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/** 總開關打開、常數沒有停用。 */
	public static function active(): bool {
		return self::enabled() && ! self::disabled_by_constant();
	}

	/**
	 * 數字設定：不是整數或超出範圍時照預設（超出範圍夾到邊界）。
	 */
	public static function int( string $name ): int {
		if ( ! isset( self::RANGES[ $name ] ) ) {
			return 0;
		}
		[ $default, $min, $max ] = self::RANGES[ $name ];
		$raw                     = get_option( self::INT_OPTIONS[ $name ], $default );
		if ( is_int( $raw ) ) {
			$value = $raw;
		} elseif ( is_string( $raw ) && 1 === preg_match( '/^\d{1,9}$/', trim( $raw ) ) ) {
			$value = (int) trim( $raw );
		} else {
			return $default;
		}
		return max( $min, min( $max, $value ) );
	}

	public static function max_failures(): int {
		return self::int( 'max_failures' );
	}

	public static function window_seconds(): int {
		return 60 * self::int( 'window' );
	}

	public static function lockout_seconds(): int {
		return 60 * self::int( 'lockout' );
	}

	/** 逐次加長：預設打開（打開登入次數限制時一起開）。 */
	public static function escalate(): bool {
		return 'no' !== get_option( self::OPTION_ESCALATE, 'yes' );
	}

	public static function escalate_after(): int {
		return self::int( 'escalate_after' );
	}

	public static function escalate_period_seconds(): int {
		return 3600 * self::int( 'escalate_period' );
	}

	/** 加長後鎖多久（秒）；存檔時要求比「鎖多久」長，讀取時至少等於「鎖多久」。 */
	public static function escalated_lockout_seconds(): int {
		return max( self::lockout_seconds(), 3600 * self::int( 'escalated_lockout' ) );
	}

	public static function ipv6_aggregate(): bool {
		return 'yes' === get_option( self::OPTION_IPV6_AGGREGATE, 'no' );
	}

	/** 內網位址逐一計數（設定，加上同名 filter 可以覆寫）。 */
	public static function lock_private(): bool {
		$value = 'yes' === get_option( self::OPTION_LOCK_PRIVATE, 'no' );
		/**
		 * 內網位址逐一計數（v1.6.0）。打開時，沒有用到標頭、從內部位址直接連進來的登入失敗改回逐一位址計數，
		 * 不併進 via:internal。只在網站只給內網用、前面沒有代理時打開。
		 *
		 * @since 1.6.0
		 *
		 * @param bool $value 設定的值。
		 */
		return (bool) apply_filters( 'ys_admin_menu_login_guard_lock_private', $value );
	}

	/** 拒絕從其他網站送來的登入（總開關底下的子開關，預設勾選）。 */
	public static function cross_site_reject(): bool {
		return 'no' !== get_option( self::OPTION_CROSS_SITE_REJECT, 'yes' );
	}

	/**
	 * 跨站判斷開始的時間。讀不到（不存在、不是正整數）回 0：當成觀察期已過（§4.13.1，r5）。
	 */
	public static function cross_site_since(): int {
		$raw = get_option( self::OPTION_CROSS_SITE_SINCE, 0 );
		if ( is_int( $raw ) ) {
			return max( 0, $raw );
		}
		return is_string( $raw ) && 1 === preg_match( '/^\d{1,12}$/', $raw ) ? (int) $raw : 0;
	}

	/**
	 * 允許跨站登入的來源（設定裡的，已正規化；不合法的行略過，存檔時本來就會擋下）。
	 *
	 * @return string[]
	 */
	public static function cross_site_origins(): array {
		$raw   = get_option( self::OPTION_CROSS_ORIGINS, '' );
		$lines = preg_split( '/\r\n|\r|\n/', is_string( $raw ) ? $raw : '' );
		$out   = [];
		foreach ( array_slice( (array) $lines, 0, self::ORIGINS_MAX ) as $line ) {
			$origin = self::normalize_origin( (string) $line );
			if ( '' !== $origin ) {
				$out[ $origin ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * 白名單（解析過的 CIDR；跟信任的代理同一套解析）。
	 *
	 * @return array<int, array{0:string,1:int}>
	 */
	public static function allowlist(): array {
		$raw = get_option( self::OPTION_ALLOWLIST, '' );
		return YSClientIp::parse_list( is_string( $raw ) ? $raw : '' )['ranges'];
	}

	/** 鎖定時的訊息（第一句；留空用內建文字）。純文字，最多 200 字。 */
	public static function message(): string {
		$raw = get_option( self::OPTION_MESSAGE, '' );
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $raw ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MESSAGE_MAX ) : substr( $text, 0, self::MESSAGE_MAX );
	}

	public static function retention_seconds(): int {
		return 86400 * self::int( 'retention_days' );
	}

	/**
	 * 來源的正規化（跟 YS CART 的 normalize_origin() 一樣）：去掉結尾的 /、scheme 與主機轉小寫、
	 * 標準埠省略，只留 scheme://主機[:埠]。只收 http 與 https；解析不出 scheme 或主機回空字串。
	 */
	public static function normalize_origin( string $url ): string {
		$url = trim( rtrim( trim( $url ), '/' ) );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parsed['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}
		$host = strtolower( (string) $parsed['host'] );
		if ( 1 !== preg_match( '/^(?:[a-z0-9.-]+|\[[0-9a-f:.]+\])$/', $host ) ) {
			return '';
		}
		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : null;
		if ( null !== $port && ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) ) {
			$port = null;
		}
		return $scheme . '://' . $host . ( null === $port ? '' : ':' . $port );
	}
}
