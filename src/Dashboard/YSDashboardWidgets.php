<?php
/**
 * v1.7.0：控制台小工具（設計 docs/plans/2026-10-26-ys-admin-menu-170-design.md §5；對標 Widget Disable）。
 *
 * 選項：
 *   - ys_admin_menu_dashboard_widgets（autoload yes）：{ version: 1, hidden: string[], hide_welcome: bool }
 *   - ys_admin_menu_dashboard_widgets_seen（autoload no，最多 200 筆）：{ items: { id: { title, context, priority } }, updated_at }
 *
 * 列舉：可以使用 YS 選單管理的人打開控制台時，在 wp_dashboard_setup 很晚的優先序讀 $wp_meta_boxes['dashboard']，
 * 內容有變才寫入。不在設定頁執行 wp_dashboard_setup()（那等於在設定頁跑每個外掛的控制台程式）。
 * 移除：同一個 hook、記錄之後，對每個要隱藏的 id 找出它現在實際所在的欄再 remove_meta_box()（不靠存檔當時的欄）。
 *
 * 對所有人生效（含管理員）。依角色、帳號隱藏留到 1.8.0。
 *
 * @package YangSheep\AdminMenu\Dashboard
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Dashboard;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSDashboardWidgets {

	public const OPTION      = 'ys_admin_menu_dashboard_widgets';
	public const SEEN_OPTION = 'ys_admin_menu_dashboard_widgets_seen';

	/** 記下的小工具與要隱藏的 id 最多幾個（防止異常資料）。 */
	public const MAX_ITEMS = 200;

	/** 控制台的 screen id。 */
	private const SCREEN = 'dashboard';

	/**
	 * @return array{version:int,hidden:string[],hide_welcome:bool}
	 */
	public static function defaults(): array {
		return [
			'version'      => 1,
			'hidden'       => [],
			'hide_welcome' => false,
		];
	}

	/** 小工具 id：英數、底線、連字號（核心與外掛用的都是這種），最長 100 字。 */
	public static function valid_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $id );
	}

	/**
	 * @param mixed $raw
	 * @return array{version:int,hidden:string[],hide_welcome:bool}
	 */
	public static function sanitize( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : [];
		$hidden = [];
		foreach ( is_array( $raw['hidden'] ?? null ) ? $raw['hidden'] : [] as $id ) {
			if ( self::valid_id( $id ) && count( $hidden ) < self::MAX_ITEMS ) {
				$hidden[ $id ] = $id;
			}
		}
		return [
			'version'      => 1,
			'hidden'       => array_values( $hidden ),
			'hide_welcome' => ! empty( $raw['hide_welcome'] ),
		];
	}

	/**
	 * 已存的設定。沒存過（不在 alloptions 裡）＝預設，不多查資料庫（存檔一律 autoload）。
	 *
	 * @return array{version:int,hidden:string[],hide_welcome:bool}
	 */
	public static function settings(): array {
		if ( function_exists( 'wp_load_alloptions' ) && ! array_key_exists( self::OPTION, (array) wp_load_alloptions() ) ) {
			return self::defaults();
		}
		return self::sanitize( get_option( self::OPTION, [] ) );
	}

	/**
	 * 記下的小工具清單（設定頁用）。
	 *
	 * @return array{items:array<string,array{title:string,context:string,priority:string}>,updated_at:int}
	 */
	public static function seen(): array {
		$raw   = get_option( self::SEEN_OPTION, [] );
		$raw   = is_array( $raw ) ? $raw : [];
		$items = [];
		foreach ( is_array( $raw['items'] ?? null ) ? $raw['items'] : [] as $id => $row ) {
			if ( self::valid_id( $id ) && is_array( $row ) && count( $items ) < self::MAX_ITEMS ) {
				$items[ $id ] = [
					'title'    => (string) ( $row['title'] ?? '' ),
					'context'  => (string) ( $row['context'] ?? '' ),
					'priority' => (string) ( $row['priority'] ?? '' ),
				];
			}
		}
		return [
			'items'      => $items,
			'updated_at' => (int) ( $raw['updated_at'] ?? 0 ),
		];
	}

	/**
	 * 存檔（後台精簡頁）。
	 *
	 * @param array<string, mixed> $raw 已 wp_unslash 的表單值（hidden、hide_welcome）。
	 */
	public static function save( array $raw ): void {
		update_option( self::OPTION, self::sanitize( $raw ), true );
	}

	public static function register(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'on_dashboard_setup' ], PHP_INT_MAX - 10 );
		add_action( 'load-index.php', [ self::class, 'maybe_hide_welcome' ] );
	}

	/** wp_dashboard_setup（很晚）：先記下這一頁的小工具（只替 YAM 管理者記），再拿掉要隱藏的。 */
	public static function on_dashboard_setup(): void {
		global $wp_meta_boxes;
		$boxes = is_array( $wp_meta_boxes[ self::SCREEN ] ?? null ) ? $wp_meta_boxes[ self::SCREEN ] : [];
		if ( YSAccess::can_manage() ) {
			self::remember( $boxes );
		}
		$hidden = self::settings()['hidden'];
		if ( [] === $hidden ) {
			return;
		}
		foreach ( $hidden as $id ) {
			foreach ( $boxes as $context => $priorities ) {
				foreach ( is_array( $priorities ) ? $priorities : [] as $list ) {
					if ( is_array( $list ) && ! empty( $list[ $id ] ) ) {
						remove_meta_box( $id, self::SCREEN, (string) $context );
						continue 3;
					}
				}
			}
		}
	}

	/**
	 * 把目前控制台上的小工具寫進記錄（id、去 HTML 的標題、欄、優先序）；跟已存的相同就不寫。
	 *
	 * @param array<string, mixed> $boxes $wp_meta_boxes['dashboard']
	 */
	public static function remember( array $boxes ): void {
		$items = [];
		foreach ( $boxes as $context => $priorities ) {
			foreach ( is_array( $priorities ) ? $priorities : [] as $priority => $list ) {
				foreach ( is_array( $list ) ? $list : [] as $id => $box ) {
					if ( ! is_array( $box ) || ! self::valid_id( (string) $id ) || count( $items ) >= self::MAX_ITEMS ) {
						continue; // 別人已經拿掉的（false）不記
					}
					$title         = trim( wp_strip_all_tags( (string) ( $box['title'] ?? '' ) ) );
					$items[ $id ] = [
						'title'    => '' !== $title ? $title : (string) $id,
						'context'  => (string) $context,
						'priority' => (string) $priority,
					];
				}
			}
		}
		if ( self::seen()['items'] === $items ) {
			return;
		}
		update_option( self::SEEN_OPTION, [ 'items' => $items, 'updated_at' => time() ], false );
	}

	/** load-index.php：設定要藏歡迎面板時，拿掉核心的 wp_welcome_panel。 */
	public static function maybe_hide_welcome(): void {
		if ( self::settings()['hide_welcome'] ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}
	}
}
