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

	/** 啟用時改用 Core 的搬家前備份之前，Core 那份的原字串另存在這裡（autoload no；每次走這條就覆寫成最新一份）。 */
	public const CORE_COPY_OPTION = 'ys_admin_menu_core_copy_pre_activation';

	/** 本外掛認得的 Core 搬家批次（Core 搬家標記的 version）。之後的版本看得懂新的批次時再加。 */
	private const KNOWN_CORE_MOVES_VERSIONS = [ 'b1' ];

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
				// v2 規則）。本外掛自己還沒搬過這一批時，一律改用 Core 的搬家前備份，交給本外掛自己遷移（再鏡像回去）：備份就是
				// 當時 Core 那份，停用期間、V3 遷移之前在 Core 改過的也照樣採用；V3 遷移之後在 Core 改過的分不出來，照 D6 不放寬，
				// 不帶過來（Core 那份先原樣另存）。其他情況照 1.4.0：改用 Core 那份（當成在 Core 改過）。
				$backup = self::core_pre_moves_backup();
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
					'saved'          => $backup['saved'] ? self::CORE_COPY_OPTION : null,
					'same_as_mirror' => self::fingerprint( $backup['config'] ) === $last_fingerprint,
				],
				[ 'core_backup' => true ]
			);
		}
		if ( $from_core ) {
			$v2 = self::v2_rule_slugs( $config );
			if ( ! empty( $v2 ) ) {
				YSMenuMovesMigrator::record_activation( 'core_copy_v2', [ 'slugs' => $v2 ], [ 'v2_rules' => $v2 ] );
			}
		}
	}

	/**
	 * v1.5.0：Core 自己遷移之前的那份設定（Core 的搬家前備份）。全部成立才回傳，否則回 null（照 1.4.0）：
	 *   - 讀得到 Core 的常數（YSAdminNavigationMigration::MOVES_MARKER、BACKUP_PREFIX）。
	 *   - Core 的搬家標記存在、解得開，version 是本外掛認得的批次。
	 *   - 本外掛自己還沒套用這一批（自己的遷移標記裡沒有這個 since 的鍵）。1.5.0 遷移過、停用後由 Core 接手又改過的，照 1.4.0
	 *     沿用 Core 那份；不然結果會取決於 Core 有沒有寫備份。
	 *   - 那一批的備份存在、解得開。Core 用 INSERT IGNORE 存原字串：原本是陣列的拿到陣列，原本是 JSON 字串的拿到 JSON 字串。
	 *   - Core 目前那份的原字串已經另存（CORE_COPY_OPTION）並讀回確認；存不進去就不換掉它。Core 那份不在資料庫裡時沒有可另存的
	 *     （saved＝false），先前另存的那份拿掉。
	 * Core 的 option 只讀，不寫、不刪。
	 *
	 * @return array{option:string,config:array<string,mixed>,saved:bool}|null
	 */
	private static function core_pre_moves_backup(): ?array {
		$migration = self::CORE_MIGRATION_CLASS;
		if ( ! class_exists( $migration ) || ! defined( $migration . '::MOVES_MARKER' ) || ! defined( $migration . '::BACKUP_PREFIX' ) ) {
			return null;
		}
		$marker_key = constant( $migration . '::MOVES_MARKER' );
		$prefix     = constant( $migration . '::BACKUP_PREFIX' );
		if ( ! is_string( $marker_key ) || '' === $marker_key || ! is_string( $prefix ) || '' === $prefix ) {
			return null;
		}

		$marker  = self::decode_array( get_option( $marker_key, null ) );
		$version = null === $marker ? null : ( $marker['version'] ?? null );
		if ( ! is_string( $version ) || ! in_array( $version, self::KNOWN_CORE_MOVES_VERSIONS, true ) ) {
			return null;
		}
		if ( self::own_marker_has_batch( $version ) ) {
			return null;
		}

		$option = $prefix . $version;
		$backup = self::decode_array( get_option( $option, null ) );
		if ( null === $backup ) {
			return null;
		}

		$raw = self::db_raw( self::CORE_OPTION_KEY );
		if ( null === $raw ) {
			// Core 那份不在資料庫裡（Core 不會刪它，只可能是手動刪的）：沒有可另存的；先前另存的也拿掉，免得查到更早的那份。
			delete_option( self::CORE_COPY_OPTION );
		} else {
			update_option( self::CORE_COPY_OPTION, $raw, false );
			if ( self::db_raw( self::CORE_COPY_OPTION ) !== (string) maybe_serialize( $raw ) ) {
				return null;
			}
		}
		return [ 'option' => $option, 'config' => $backup, 'saved' => null !== $raw ];
	}

	/**
	 * 本外掛自己的遷移標記裡有沒有這一批（since）的鍵。
	 */
	private static function own_marker_has_batch( string $since ): bool {
		if ( ! class_exists( YSMenuMovesMigrator::class ) ) {
			return false;
		}
		foreach ( YSMenuMovesMigrator::read_marker( get_option( YSMenuMovesMigrator::MARKER_OPTION, null ) )['applied'] as $key ) {
			if ( 0 === strpos( (string) $key, $since . ':' ) ) {
				return true;
			}
		}
		return false;
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
