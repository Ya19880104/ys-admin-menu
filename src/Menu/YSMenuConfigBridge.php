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

		$standalone_raw = get_option( YSMenuRouter::OPTION_KEY, null );
		$core_config    = self::normalize_config( get_option( self::CORE_OPTION_KEY, [] ) );
		if ( null === $standalone_raw ) {
			$config = $core_config;
			update_option( YSMenuRouter::OPTION_KEY, $config, true );
		} else {
			$config = self::normalize_config( $standalone_raw );
			$last_fingerprint = (string) get_option( self::FINGERPRINT_OPTION, '' );
			$core_changed     = '' !== $last_fingerprint
				&& self::fingerprint( $core_config ) !== $last_fingerprint;
			$standalone_unchanged = '' !== $last_fingerprint
				&& self::fingerprint( $config ) === $last_fingerprint;

			if ( $force && $core_changed && $standalone_unchanged ) {
				$config = $core_config;
				update_option( YSMenuRouter::OPTION_KEY, $config, true );
			} elseif ( $config !== $standalone_raw ) {
				update_option( YSMenuRouter::OPTION_KEY, $config, true );
			}
		}

		self::mirror_to_core( $config );
		self::remember_fingerprint( $config );
		update_option( self::MIGRATION_OPTION, self::BRIDGE_VERSION, false );
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
