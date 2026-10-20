<?php
/**
 * 登入次數限制的健康狀態（v1.6.0，設計 §4.10）：記下「有開但沒有正常作用」的原因，給後台提醒與 WP-CLI 用。
 *
 * - 存在一個不自動載入的 option，結構是「原因 => [ 第一次、最後一次、次數、附帶資料 ]」。
 * - 寫入節流：同一個原因每分鐘最多寫一次（被攻擊時不會每個請求都 update_option）；節流期間只少記次數，
 *   「最後一次」最多晚一分鐘。
 * - 只記系統產生的值（原因代碼、時間、數字、資料表名稱），不記帳號、密碼、標頭原文。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSLoginGuardHealth {

	/** 同一個原因多久才寫一次（秒）。 */
	public const THROTTLE = 60;

	/** 最多記幾種原因（防呆）。 */
	private const MAX_REASONS = 40;

	/** @var null|array<string, array<string, mixed>> 這個請求讀過的值。 */
	private static ?array $cache = null;

	/** 清掉這個請求記住的值（每個請求開始時）。 */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * 全部的健康紀錄。
	 *
	 * @return array<string, array{first:int,last:int,count:int,data:array}>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( YSLoginGuardSettings::OPTION_HEALTH, [] );
			self::$cache = [];
			foreach ( is_array( $raw ) ? $raw : [] as $reason => $row ) {
				if ( is_string( $reason ) && 1 === preg_match( '/^[a-z0-9_]{1,40}$/', $reason ) && is_array( $row ) ) {
					self::$cache[ $reason ] = [
						'first' => (int) ( $row['first'] ?? 0 ),
						'last'  => (int) ( $row['last'] ?? 0 ),
						'count' => (int) ( $row['count'] ?? 0 ),
						'data'  => is_array( $row['data'] ?? null ) ? $row['data'] : [],
					];
				}
			}
		}
		return self::$cache;
	}

	/**
	 * 一種原因的紀錄；沒有時回 null。
	 *
	 * @return null|array{first:int,last:int,count:int,data:array}
	 */
	public static function get( string $reason ): ?array {
		return self::all()[ $reason ] ?? null;
	}

	/**
	 * 記一次。同一個原因上次寫入不到一分鐘就不寫（除非 $force）。
	 *
	 * @param array<string, scalar|array> $data 只放系統產生的值。
	 * @return bool 這一次有沒有寫入。
	 */
	public static function record( string $reason, array $data = [], bool $force = false ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,40}$/', $reason ) ) {
			return false;
		}
		$all = self::all();
		$now = time();
		$row = $all[ $reason ] ?? null;
		if ( null !== $row && ! $force && $now - $row['last'] < self::THROTTLE ) {
			return false;
		}
		if ( null === $row && count( $all ) >= self::MAX_REASONS ) {
			return false;
		}
		$all[ $reason ] = [
			'first' => null === $row ? $now : $row['first'],
			'last'  => $now,
			'count' => ( null === $row ? 0 : $row['count'] ) + 1,
			'data'  => $data,
		];
		self::$cache = $all;
		update_option( YSLoginGuardSettings::OPTION_HEALTH, $all, false );
		return true;
	}

	/** 清掉一種原因（問題排除之後，例如建表成功）。 */
	public static function clear( string $reason ): void {
		$all = self::all();
		if ( ! isset( $all[ $reason ] ) ) {
			return;
		}
		unset( $all[ $reason ] );
		self::$cache = $all;
		update_option( YSLoginGuardSettings::OPTION_HEALTH, $all, false );
	}
}
