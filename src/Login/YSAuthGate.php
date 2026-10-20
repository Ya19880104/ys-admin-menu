<?php
/**
 * 登入的「閘門」（v1.6.0，從 1.5.2 的 YSTurnstile 搬出來）：某一次登入要擋下時，這一次請求暫停核心的
 * 帳密檢查，authenticate 的最後一關（PHP_INT_MAX）一律回同一個錯誤，等真正的 wp_login_failed 才放回。
 *
 * - 為什麼要暫停核心的帳密檢查：核心 priority 20 的 wp_authenticate_username_password（與 email 版本）會忽略
 *   前面傳來的 WP_Error，自己再查一次帳號與密碼，只回 WP_Error 根本擋不住。暫停之後也不會去算密碼雜湊，
 *   回應內容與時間都不會因為帳號存不存在而不同。
 * - 最後一關：就算中間有其他外掛（LDAP、SSO 之類在 30、99 自己驗帳密的）回了 WP_User，也一律換成閘門記的錯誤。
 * - 放回的時機：authenticate 整條跑完、真正的 wp_login_failed 才放回。還在 authenticate 鏈中間時（其他外掛自己
 *   觸發 wp_login_failed、或巢狀呼叫 wp_authenticate()）不放回，否則外層鏈接下來的核心 priority 20 又會查一次密碼、
 *   最後一關也不見了。
 * - 先擋的先贏：閘門已經關著時，再關一次什麼都不改（錯誤沿用第一次的）。
 * - 每個請求開始時重設（目前是 Turnstile 的 login_init）：常駐 worker 裡 static 與 hook 會跨請求保留，
 *   前一個請求留下被暫停的核心帳密檢查時，先放回去再清空。
 *
 * 1.6.0 第 1 顆只把 1.5.2 Turnstile 的這段搬過來，Turnstile 的行為不變。
 *
 * @package YangSheep\AdminMenu\Login
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Login;

defined( 'ABSPATH' ) || exit;

final class YSAuthGate {

	/** 核心 priority 20 的帳號密碼與信箱密碼檢查（Turnstile 沒過時暫停這兩個）。 */
	public const CORE_PASSWORD_FILTERS = [ 'wp_authenticate_username_password', 'wp_authenticate_email_password' ];

	/** 這一次請求擋下時回的錯誤（最後一關回傳它）；null＝閘門開著。 */
	private static ?\WP_Error $error = null;

	/** @var string[] 暫時拿掉的核心 filter，wp_login_failed 時放回。 */
	private static array $removed = [];

	/**
	 * 關上閘門：拿掉還在 priority 20 的指定核心 filter，掛上最後一關與放回的 hook。
	 *
	 * @param string[] $callbacks 要暫停的核心 authenticate callback（都在 priority 20）。
	 * @return bool 這一次有沒有真的關上（已經關著時回 false，錯誤沿用第一次的）。
	 */
	public static function close( \WP_Error $error, array $callbacks = self::CORE_PASSWORD_FILTERS ): bool {
		if ( null !== self::$error ) {
			return false;
		}
		self::$error = $error;

		foreach ( $callbacks as $callback ) {
			if ( is_string( $callback ) && 20 === has_filter( 'authenticate', $callback ) ) {
				remove_filter( 'authenticate', $callback, 20 );
				self::$removed[] = $callback;
			}
		}

		add_filter( 'authenticate', [ self::class, 'enforce' ], PHP_INT_MAX );
		add_action( 'wp_login_failed', [ self::class, 'restore' ], 0 );
		return true;
	}

	/** 這一次請求的閘門是不是關著。 */
	public static function is_closed(): bool {
		return null !== self::$error;
	}

	/** 閘門關著時記的錯誤；開著時是 null。 */
	public static function error(): ?\WP_Error {
		return self::$error;
	}

	/**
	 * authenticate 的最後一關（PHP_INT_MAX）：閘門關著時，不管中間誰回了什麼，一律回閘門記的錯誤。
	 *
	 * @param null|\WP_User|\WP_Error $user
	 * @return null|\WP_User|\WP_Error
	 */
	public static function enforce( $user ) {
		return self::$error instanceof \WP_Error ? self::$error : $user;
	}

	/**
	 * wp_login_failed（priority 0，authenticate 跑完之後）：把暫時拿掉的核心 filter 放回去、打開閘門。
	 * 還在 authenticate 鏈中間就不放回（見檔頭說明）。
	 */
	public static function restore(): void {
		if ( doing_filter( 'authenticate' ) ) {
			return;
		}
		remove_action( 'wp_login_failed', [ self::class, 'restore' ], 0 );
		remove_filter( 'authenticate', [ self::class, 'enforce' ], PHP_INT_MAX );
		foreach ( self::$removed as $callback ) {
			add_filter( 'authenticate', $callback, 20, 3 );
		}
		self::$removed = [];
		self::$error   = null;
	}

	/**
	 * 每個請求開始時重設。一般 PHP 每個請求本來就是新的行程，這裡什麼都不會做；常駐 worker 裡前一個請求若留下
	 * 被暫停的核心帳密檢查（例如沒有觸發 wp_login_failed 就結束），先把它放回去，再清空。
	 */
	public static function reset(): void {
		foreach ( self::$removed as $callback ) {
			if ( false === has_filter( 'authenticate', $callback ) ) {
				add_filter( 'authenticate', $callback, 20, 3 );
			}
		}
		remove_filter( 'authenticate', [ self::class, 'enforce' ], PHP_INT_MAX );
		remove_action( 'wp_login_failed', [ self::class, 'restore' ], 0 );

		self::$removed = [];
		self::$error   = null;
	}
}
