<?php
/**
 * WP-CLI：自訂登入網址（v1.9.0）。救援用，在主機上執行，不受「外掛使用權限」限制。
 *
 *   wp ys-admin-menu login-url get
 *   wp ys-admin-menu login-url set <代稱> [--redirect=<路徑>] [--404]
 *   wp ys-admin-menu login-url reset
 *
 * @package YangSheep\AdminMenu\Cli
 * @since   1.9.0
 */

namespace YangSheep\AdminMenu\Cli;

use YangSheep\AdminMenu\Access\YSAccess;
use YangSheep\AdminMenu\Login\YSLoginUrl;

defined( 'ABSPATH' ) || exit;

/**
 * 查看、設定或關掉自訂登入網址。
 */
final class YSLoginUrlCommand {

	/** 狀態的說明。 */
	private const STATES = [
		'active'    => '作用中',
		'off'       => '沒有使用（登入在 wp-login.php）',
		'constant'  => '暫停中：wp-config.php 定義了 ' . YSLoginUrl::DISABLE_CONSTANT . '（登入在 wp-login.php）',
		'multisite' => '不作用：多站網路不支援（登入在 wp-login.php）',
		'conflict'  => '不作用：WPS Hide Login 也在作用，請停用其中一個（本外掛的設定不作用）',
		'invalid'   => '不作用：存的代稱不合法（登入在 wp-login.php）',
	];

	/**
	 * 目前的登入網址與狀態。
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table（預設）或 json。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-url get
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function get( $args, $assoc_args ): void {
		self::heal();
		$status = YSLoginUrl::status();
		$url    = '' !== $status['slug'] && '' === YSLoginUrl::slug_problem( $status['slug'] ) ? YSLoginUrl::login_url( 'login', $status['slug'] ) : '';
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					[
						'state'     => $status['state'],
						'slug'      => $status['slug'],
						'login_url' => 'active' === $status['state'] ? $url : YSLoginUrl::raw_wp_login_url(),
						'blocked'   => '' === $status['redirect'] ? '404' : 'redirect',
						'redirect'  => $status['redirect'],
					]
				)
			);
			return;
		}
		\WP_CLI::line( '狀態：' . ( self::STATES[ $status['state'] ] ?? $status['state'] ) );
		\WP_CLI::line( '代稱：' . ( '' !== $status['slug'] ? self::clean( $status['slug'] ) : '（沒有）' ) );
		\WP_CLI::line( '登入網址：' . ( 'active' === $status['state'] ? $url : YSLoginUrl::raw_wp_login_url() ) );
		if ( 'active' !== $status['state'] && '' !== $url ) {
			\WP_CLI::line( '設定的新網址（目前沒有作用）：' . $url );
		}
		\WP_CLI::line( '沒登入的人打開 wp-login.php、wp-admin：' . ( '' === $status['redirect'] ? '404' : '轉到 ' . home_url( $status['redirect'] ) ) );
	}

	/**
	 * 設定新的登入網址（跟設定頁同一套檢查）。
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : 代稱：小寫英文、數字、連字號，3～50 個字，例如 adminlogin。
	 *
	 * [--redirect=<path>]
	 * : 沒登入的人打開 wp-login.php、wp-admin 時轉到本站的這個路徑（/ 開頭，例如 /）。不給就保留原本的設定。
	 *
	 * [--404]
	 * : 改回顯示 404。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-url set adminlogin
	 *     wp ys-admin-menu login-url set adminlogin --redirect=/
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function set( $args, $assoc_args ): void {
		self::heal();
		$slug = YSLoginUrl::normalize_slug_input( (string) ( $args[0] ?? '' ) );
		$problem = YSLoginUrl::slug_problem( $slug );
		$current = YSLoginUrl::get();
		if ( '' === $problem && $slug !== $current['slug'] ) {
			$problem = YSLoginUrl::conflict_problem( $slug );
		}
		if ( 'format' === $problem ) {
			\WP_CLI::error( '代稱只能用小寫英文、數字與連字號（不能在頭尾），3～50 個字。' );
		}
		if ( 'reserved' === $problem ) {
			\WP_CLI::error( '這個代稱是 WordPress 自己用的路徑或查詢變數（例如 wp- 開頭、admin、login、feed、page），請換一個。' );
		}
		if ( 'exists' === $problem ) {
			\WP_CLI::error( '站上已經有頁面、文章或網址用這個代稱，請換一個。' );
		}
		$redirect = $current['redirect'];
		if ( isset( $assoc_args['404'] ) ) {
			$redirect = '';
		} elseif ( isset( $assoc_args['redirect'] ) ) {
			$redirect = YSLoginUrl::normalize_redirect_input( (string) $assoc_args['redirect'] );
		}
		if ( '' !== YSLoginUrl::redirect_problem( $redirect, $slug ) ) {
			\WP_CLI::error( '轉去的路徑要是本站的路徑（/ 開頭，例如 / 或 /contact/），不能有空白，也不能轉回登入、後台或新網址。' );
		}
		YSLoginUrl::store( $slug, $redirect );
		$status = YSLoginUrl::status();
		if ( 'active' !== $status['state'] ) {
			\WP_CLI::warning( '已存，但目前沒有作用：' . ( self::STATES[ $status['state'] ] ?? $status['state'] ) );
		}
		\WP_CLI::success( '登入網址：' . YSLoginUrl::login_url( 'login', $slug ) . '（請加入書籤；改完先用無痕視窗確認看得到登入頁再登出）' );
	}

	/**
	 * 關掉自訂登入網址，登入回到 wp-login.php（刪掉設定）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu login-url reset
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function reset( $args, $assoc_args ): void {
		self::heal();
		YSLoginUrl::store( '', '' );
		\WP_CLI::success( '已關掉自訂登入網址，登入回到 ' . YSLoginUrl::raw_wp_login_url() . '。' );
	}

	/** 設定被改成不自動載入時，YSLoginUrl::get() 會當成沒設定過；先改回自動載入。 */
	private static function heal(): void {
		if ( YSAccess::heal_autoload( YSLoginUrl::OPTION ) ) {
			\WP_CLI::warning( '設定被改成「不自動載入」，已經改回自動載入。' );
		}
	}

	/** 印到終端機前拿掉控制字元。 */
	private static function clean( string $s ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $s );
	}
}
