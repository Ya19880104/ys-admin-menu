<?php
/**
 * Authoritative standalone menu config with a non-destructive core mirror.
 *
 * @package YangSheep\AdminMenu\Menu
 */

namespace YangSheep\AdminMenu\Menu;

defined( 'ABSPATH' ) || exit;

final class YSMenuConfigBridge {

	public const CORE_OPTION_KEY = 'ys_ec_menu_config';

	/** YS CART V3 自己的選單搬家遷移（只讀它的常數：MOVES_MARKER、BACKUP_PREFIX）。 */
	public const CORE_MIGRATION_CLASS = 'YangSheep\\Ecommerce\\Admin\\YSAdminNavigationMigration';

	/** YS CART 的導覽登錄表（只讀 MOVES_VERSION）。 */
	public const CORE_REGISTRY_CLASS = 'YangSheep\\Ecommerce\\Admin\\YSAdminNavigationRegistry';

	private const MIGRATION_OPTION = 'ys_admin_menu_config_bridge_version';

	private const FINGERPRINT_OPTION = 'ys_admin_menu_config_bridge_fingerprint';

	private const BRIDGE_VERSION = '1';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		self::synchronize();
	}

	public static function activate(): void {
		self::synchronize( true );
	}

	public static function deactivate(): void {
		$config = self::normalize_config( get_option( YSMenuRouter::OPTION_KEY, [] ) );
		self::mirror_to_core( $config );
		self::remember_fingerprint( $config );
	}

	public static function synchronize( bool $force = false ): void {
		if ( ! $force && self::BRIDGE_VERSION === (string) get_option( self::MIGRATION_OPTION, '' ) ) {
			return;
		}

		$standalone_raw   = get_option( YSMenuRouter::OPTION_KEY, null );
		$core_config      = self::normalize_config( get_option( self::CORE_OPTION_KEY, [] ) );
		$from_core        = false; // 啟用時改用了 Core 那份（之後檢查裡面有沒有本外掛看不懂的 v2 規則）
		$backup           = null;
		$last_fingerprint = '';
		if ( null === $standalone_raw ) {
			$config    = $core_config;
			$from_core = $force;
			update_option( YSMenuRouter::OPTION_KEY, $config, true );
		} else {
			$config = self::normalize_config( $standalone_raw );
			$last_fingerprint = (string) get_option( self::FINGERPRINT_OPTION, '' );
			$core_changed     = '' !== $last_fingerprint
				&& self::fingerprint( $core_config ) !== $last_fingerprint;
			$standalone_unchanged = '' !== $last_fingerprint
				&& self::fingerprint( $config ) === $last_fingerprint;

			if ( $force && $core_changed && $standalone_unchanged ) {
				// v1.5.0：Core 那份變了，可能是本外掛停用期間 YS CART V3 自己做了選單搬家遷移（照 Core 的規則，含本外掛看不懂的
				// v2 規則）。Core 遷移後沒人再改過的話，改用 Core 的搬家前備份，交給本外掛自己遷移（再鏡像回去）；備份就是當時
				// Core 那份，停用期間（V3 遷移之前）在 Core 改過的也照樣採用。否則照 1.4.0：改用 Core 那份（當成在 Core 改過）。
				$backup = self::core_pre_moves_backup( $core_config );
				if ( null !== $backup ) {
					$config = $backup['config'];
				} else {
					$config    = $core_config;
					$from_core = true;
				}
				update_option( YSMenuRouter::OPTION_KEY, $config, true );
			} elseif ( $config !== $standalone_raw ) {
				update_option( YSMenuRouter::OPTION_KEY, $config, true );
			}
		}

		self::mirror_to_core( $config );
		self::remember_fingerprint( $config );
		update_option( self::MIGRATION_OPTION, self::BRIDGE_VERSION, false );

		if ( ! class_exists( YSMenuMovesMigrator::class ) ) {
			return;
		}
		if ( null !== $backup ) {
			YSMenuMovesMigrator::record_activation(
				'core_backup',
				[
					'option'         => $backup['option'],
					'same_as_mirror' => self::fingerprint( $backup['config'] ) === $last_fingerprint,
				]
			);
		}
		if ( $from_core ) {
			$v2 = self::v2_rule_slugs( $config );
			if ( ! empty( $v2 ) ) {
				YSMenuMovesMigrator::record_activation( 'core_copy_v2', [ 'slugs' => $v2 ], $v2 );
			}
		}
	}

	/**
	 * v1.5.0：Core 自己遷移之前的那份設定（Core 的搬家前備份）。三件事都成立才回傳，否則回 null（照 1.4.0）：
	 *   - 讀得到 Core 的常數（YSAdminNavigationMigration::MOVES_MARKER、BACKUP_PREFIX，YSAdminNavigationRegistry::MOVES_VERSION）。
	 *   - Core 的搬家標記存在、格式對（有 fingerprint）、版本是 Core 目前的 MOVES_VERSION，而且 fingerprint 等於 Core 目前那份的
	 *     指紋（算法同 Core：解成陣列後 sha256( serialize() )）。
	 *   - 那一版的備份存在、解得開。Core 用 INSERT IGNORE 存原字串：原本是陣列的拿到陣列，原本是 JSON 字串的拿到 JSON 字串。
	 * Core 的 option 只讀，不寫、不刪。
	 *
	 * @param array<string, mixed> $core_config Core 目前那份（已解成陣列）。
	 * @return array{option:string,config:array<string,mixed>}|null
	 */
	private static function core_pre_moves_backup( array $core_config ): ?array {
		$migration = self::CORE_MIGRATION_CLASS;
		$registry  = self::CORE_REGISTRY_CLASS;
		if ( ! class_exists( $migration ) || ! class_exists( $registry )
			|| ! defined( $migration . '::MOVES_MARKER' ) || ! defined( $migration . '::BACKUP_PREFIX' ) || ! defined( $registry . '::MOVES_VERSION' ) ) {
			return null;
		}
		$marker_key = constant( $migration . '::MOVES_MARKER' );
		$prefix     = constant( $migration . '::BACKUP_PREFIX' );
		$version    = constant( $registry . '::MOVES_VERSION' );
		if ( ! is_string( $marker_key ) || '' === $marker_key || ! is_string( $prefix ) || '' === $prefix || ! is_string( $version ) || '' === $version ) {
			return null;
		}

		$marker = self::decode_array( get_option( $marker_key, null ) );
		if ( null === $marker || ! is_string( $marker['fingerprint'] ?? null ) || $version !== ( $marker['version'] ?? null ) ) {
			return null;
		}
		if ( ! hash_equals( $marker['fingerprint'], self::fingerprint( $core_config ) ) ) {
			return null; // Core 遷移之後又有人在 Core 改過：照 1.4.0 採用那些修改。
		}

		$option = $prefix . $version;
		$backup = self::decode_array( get_option( $option, null ) );
		if ( null === $backup ) {
			return null;
		}
		return [ 'option' => $option, 'config' => $backup ];
	}

	/**
	 * 陣列照原樣；JSON 字串解開；沒有或解不開回 null。
	 *
	 * @param mixed $raw
	 * @return array<string, mixed>|null
	 */
	private static function decode_array( $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? $raw : null;
	}

	/**
	 * 設定裡用了 v2 規則（YS CART V3 的 visibility 欄位）的選單；本外掛看不懂這種規則。
	 *
	 * @param array<string, mixed> $config
	 * @return array<int, string>
	 */
	private static function v2_rule_slugs( array $config ): array {
		$slugs = [];
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( (array) ( $config[ $tab ]['items'] ?? [] ) as $row ) {
				if ( is_array( $row ) && array_key_exists( 'visibility', $row ) && '' !== (string) ( $row['slug'] ?? '' ) ) {
					$slugs[] = (string) $row['slug'];
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Persist the standalone source of truth and refresh the core fallback.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function save_authoritative( array $config ): void {
		$config = self::normalize_config( $config );

		update_option( YSMenuRouter::OPTION_KEY, $config, true );
		self::mirror_to_core( $config );
		self::remember_fingerprint( $config );
		update_option( self::MIGRATION_OPTION, self::BRIDGE_VERSION, false );
	}

	/**
	 * v1.5.0：比對舊值再寫入主設定（給選單搬家遷移用；設定頁存檔照舊走 save_authoritative()）。
	 *
	 * - 主設定用一條 UPDATE … WHERE option_value＝呼叫端讀到的原值（逐位元組比對）寫入，影響 1 列才算寫成，寫完直接讀
	 *   資料庫確認。讀到原值之後、寫入之前若有別的請求存過設定，這一條就對不到，不會蓋掉別人剛存的（回 conflict）。
	 * - 主設定寫成之後才補 Core 那份鏡像；鏡像確認寫成才更新指紋。指紋若比鏡像新，重新啟用時會把 Core 那份舊設定
	 *   當成「Core 改過」蓋回來，所以鏡像沒寫成時指紋維持原值（停用時會再鏡像一次）。
	 *
	 * @param array<string, mixed> $config
	 * @param string               $expected_raw 資料庫裡的原值（option_value 原字串）。
	 * @return array{result:string,mirror:bool} result：saved（已寫入）、conflict（原值已經被改過）、failed（資料庫寫入失敗或讀回不符）。
	 */
	public static function save_authoritative_if_unchanged( array $config, string $expected_raw ): array {
		global $wpdb;

		$config  = self::normalize_config( $config );
		$new_raw = (string) maybe_serialize( $config );

		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $new_raw, YSMenuRouter::OPTION_KEY, $expected_raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 比對舊值與寫入要是同一個原子操作。
		self::forget_cached( YSMenuRouter::OPTION_KEY );
		if ( false === $result ) {
			return [ 'result' => 'failed', 'mirror' => false ];
		}
		if ( 1 !== (int) $result ) {
			return [ 'result' => 'conflict', 'mirror' => false ];
		}
		if ( self::db_raw( YSMenuRouter::OPTION_KEY ) !== $new_raw ) {
			return [ 'result' => 'failed', 'mirror' => false ];
		}

		self::mirror_to_core( $config );
		$mirror = self::db_raw( self::CORE_OPTION_KEY ) === $new_raw;
		if ( $mirror ) {
			self::remember_fingerprint( $config );
			update_option( self::MIGRATION_OPTION, self::BRIDGE_VERSION, false );
		}
		return [ 'result' => 'saved', 'mirror' => $mirror ];
	}

	/**
	 * 直接讀資料庫裡的原值（不經快取）。
	 */
	private static function db_raw( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 寫完要從資料庫確認。
		return null === $value ? null : (string) $value;
	}

	/**
	 * 直接改資料庫之後，讓這個請求與物件快取重新讀資料庫。
	 */
	private static function forget_cached( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function mirror_to_core( array $config ): void {
		update_option( self::CORE_OPTION_KEY, $config, true );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function remember_fingerprint( array $config ): void {
		update_option( self::FINGERPRINT_OPTION, self::fingerprint( $config ), false );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function fingerprint( array $config ): string {
		return hash( 'sha256', serialize( $config ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function normalize_config( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}

		return is_array( $raw ) ? $raw : [];
	}
}
