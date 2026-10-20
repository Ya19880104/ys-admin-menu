<?php
/**
 * 白牌設定頁「登入防護」分頁的新設定（v1.6.0）。
 *
 * - 「進階：訪客 IP」卡片：偵測方式、信任的代理、信任 Cloudflare、Turnstile 也使用偵測到的 IP，以及診斷。
 *
 * 存檔由 YSWhiteLabelAdmin::handle_save_settings 在驗過 manage_options 與 nonce 之後呼叫；這裡每個欄位再驗證一次，
 * 不合法的值不存、保留原本的值，回傳錯誤代碼給設定頁顯示。
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Security\YSClientIp;

defined( 'ABSPATH' ) || exit;

final class YSLoginSecurityAdmin {

	/** 錯誤代碼裡最多列幾個行號（避免網址太長）。 */
	private const MAX_LINE_CODES = 5;

	/**
	 * 存「訪客 IP」設定。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 * @return string[] 錯誤或提醒代碼。
	 */
	public static function save_ip_settings( array $post ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}
		$codes = [];

		// 信任的代理：有任何一行不合法或太寬就整欄不存（保留原本的值），並列出是第幾行。
		$raw    = isset( $post['ip_trusted_proxies'] ) && is_string( $post['ip_trusted_proxies'] ) ? $post['ip_trusted_proxies'] : '';
		$parsed = YSClientIp::parse_list( $raw );
		if ( $parsed['too_many'] ) {
			$codes[] = 'ip_trusted_too_many';
		}
		foreach ( array_slice( $parsed['invalid'], 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'ip_trusted_invalid_' . (int) $line;
		}
		foreach ( array_slice( $parsed['too_wide'], 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'ip_trusted_wide_' . (int) $line;
		}
		if ( ! $parsed['too_many'] && [] === $parsed['invalid'] && [] === $parsed['too_wide'] ) {
			update_option( YSClientIp::OPTION_TRUSTED_PROXIES, implode( "\n", $parsed['lines'] ), false );
			foreach ( array_slice( $parsed['wide'], 0, self::MAX_LINE_CODES ) as $line ) {
				$codes[] = 'ip_trusted_warn_' . (int) $line;
			}
		}

		$trust = ! empty( $post['ip_trust_cloudflare'] ) ? 'yes' : 'no';
		update_option( YSClientIp::OPTION_TRUST_CLOUDFLARE, $trust, false );

		$header    = isset( $post['ip_custom_header'] ) && is_string( $post['ip_custom_header'] ) ? trim( $post['ip_custom_header'] ) : '';
		$header_ok = '' === $header || '' === YSClientIp::validate_header_name( $header );
		if ( $header_ok ) {
			update_option( YSClientIp::OPTION_CUSTOM_HEADER, $header, false );
		} else {
			$codes[] = 'ip_custom_header';
		}

		$source = isset( $post['ip_source'] ) && is_string( $post['ip_source'] ) && in_array( $post['ip_source'], YSClientIp::SOURCES, true )
			? $post['ip_source']
			: 'auto';
		if ( 'cf_connecting_ip' === $source && 'yes' !== $trust ) {
			$codes[] = 'ip_cf_needs_trust';
		} elseif ( 'custom' === $source && ( '' === $header || ! $header_ok ) ) {
			$codes[] = 'ip_custom_needs_header';
		} else {
			update_option( YSClientIp::OPTION_SOURCE, $source, false );
		}

		update_option( YSClientIp::OPTION_TURNSTILE_USE, ! empty( $post['ip_turnstile_use'] ) ? 'yes' : 'no', false );

		YSClientIp::reset();
		return $codes;
	}

	/**
	 * 「訪客 IP」卡片需要的值：診斷、已存的信任清單原文、Turnstile 勾選。
	 *
	 * @return array<string, mixed>
	 */
	public static function ip_view(): array {
		$raw = get_option( YSClientIp::OPTION_TRUSTED_PROXIES, '' );
		return [
			'diagnose'      => YSClientIp::diagnose(),
			'trusted_raw'   => is_string( $raw ) ? $raw : '',
			'turnstile_use' => YSClientIp::turnstile_uses_detected_ip(),
		];
	}

	/**
	 * 設定頁看得懂的錯誤與提醒代碼（白名單；行號只收 1～999）。
	 *
	 * @param string[] $codes
	 * @return string[]
	 */
	public static function filter_codes( array $codes ): array {
		$fixed = [ 'ip_trusted_too_many', 'ip_custom_header', 'ip_cf_needs_trust', 'ip_custom_needs_header' ];
		return array_values(
			array_filter(
				$codes,
				static fn( $c ) => is_string( $c ) && ( in_array( $c, $fixed, true ) || 1 === preg_match( '/^ip_trusted_(invalid|wide|warn)_[1-9]\d{0,2}$/', $c ) )
			)
		);
	}
}
