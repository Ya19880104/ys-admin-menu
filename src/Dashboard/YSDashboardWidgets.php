<?php
/**
 * v1.7.0：控制台小工具（設計 docs/plans/2026-10-26-ys-admin-menu-170-design.md §5；對標 Widget Disable）。
 *
 * 選項：
 *   - ys_admin_menu_dashboard_widgets（autoload yes）：{ version: 1, hidden: string[], hide_welcome: bool }
 *   - ys_admin_menu_dashboard_widgets_seen（autoload no，最多 200 筆）：{ items: { id: { title, context, priority, seen_at } }, updated_at }
 *     （各管理員看到的合併記下；90 天沒出現的拿掉）
 *
 * 列舉：可以使用 YS 選單管理的人打開控制台時，在 wp_dashboard_setup 很晚的優先序讀 $wp_meta_boxes['dashboard']，
 * 內容有變才寫入。v1.7.1 起打開「後台精簡」頁時也會直接列舉一次（collect_now()，做法同 Widget Disable），不必先打開控制台。
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
	 * @return array{items:array<string,array{title:string,context:string,priority:string,seen_at:int}>,updated_at:int}
	 */
	public static function seen(): array {
		$raw   = get_option( self::SEEN_OPTION, [] );
		$raw   = is_array( $raw ) ? $raw : [];
		$items = [];
		foreach ( is_array( $raw['items'] ?? null ) ? $raw['items'] : [] as $id => $row ) {
			$id = (string) $id; // 純數字的 id 在 PHP 陣列裡是整數鍵。
			if ( self::valid_id( $id ) && is_array( $row ) && count( $items ) < self::MAX_ITEMS ) {
				$items[ $id ] = [
					'title'    => (string) ( $row['title'] ?? '' ),
					'context'  => (string) ( $row['context'] ?? '' ),
					'priority' => (string) ( $row['priority'] ?? '' ),
					'seen_at'  => (int) ( $row['seen_at'] ?? ( $raw['updated_at'] ?? 0 ) ),
				];
			}
		}
		return [
			'items'      => $items,
			'updated_at' => (int) ( $raw['updated_at'] ?? 0 ),
		];
	}

	/** v1.7.0：記錄裡多久沒在任何人的控制台出現就拿掉（秒，90 天）。 */
	private const FORGET_AFTER = 90 * 86400;

	/** v1.7.0：同一個小工具多久更新一次「最後出現時間」（秒，7 天；避免每次打開控制台都寫入）。 */
	private const TOUCH_AFTER = 7 * 86400;

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
		// 同一個 id 出現在好幾欄時（少見）每一欄都拿掉。
		foreach ( $hidden as $id ) {
			foreach ( $boxes as $context => $priorities ) {
				foreach ( is_array( $priorities ) ? $priorities : [] as $list ) {
					if ( is_array( $list ) && ! empty( $list[ $id ] ) ) {
						remove_meta_box( $id, self::SCREEN, (string) $context );
						continue 2;
					}
				}
			}
		}
	}

	/**
	 * 把目前控制台上的小工具併進記錄（id、去 HTML 的標題、欄、優先序、最後出現時間）。
	 *
	 * 各管理員看到的小工具不一定一樣（權限、外掛依角色顯示），所以是合併、不是整份取代；90 天沒在任何人的控制台出現的才拿掉。
	 * 沒有新的小工具、名稱與位置也沒變（名稱只差數字的算沒變，例如帶待處理數量的標題）、最後出現時間也還新，就不寫。
	 *
	 * @param array<string, mixed> $boxes $wp_meta_boxes['dashboard']
	 */
	public static function remember( array $boxes ): void {
		$now   = time();
		$old   = self::seen()['items'];
		$items = $old;
		$dirty = false;
		$same  = static fn( string $a, string $b ): bool => preg_replace( '/\d+/', '#', $a ) === preg_replace( '/\d+/', '#', $b );
		foreach ( $boxes as $context => $priorities ) {
			foreach ( is_array( $priorities ) ? $priorities : [] as $priority => $list ) {
				foreach ( is_array( $list ) ? $list : [] as $id => $box ) {
					$id = (string) $id;
					if ( ! is_array( $box ) || ! self::valid_id( $id ) ) {
						continue; // 別人已經拿掉的（false）不記
					}
					$title = trim( html_entity_decode( wp_strip_all_tags( (string) ( $box['title'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
					$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 600 );
					$row   = [
						'title'    => '' !== $title ? $title : $id,
						'context'  => (string) $context,
						'priority' => (string) $priority,
						'seen_at'  => $now,
					];
					$prev = $old[ $id ] ?? null;
					if ( null === $prev ) {
						if ( count( $items ) >= self::MAX_ITEMS ) {
							continue;
						}
						$dirty = true;
					} elseif ( ! $same( $prev['title'], $row['title'] ) || $prev['context'] !== $row['context'] || $prev['priority'] !== $row['priority']
						|| $now - $prev['seen_at'] >= self::TOUCH_AFTER ) {
						$dirty = true;
					} else {
						continue; // 沒變：保留原本那一筆（包含原本的最後出現時間）。
					}
					$items[ $id ] = $row;
				}
			}
		}
		foreach ( $items as $id => $row ) {
			if ( $now - $row['seen_at'] > self::FORGET_AFTER ) {
				unset( $items[ $id ] );
				$dirty = true;
			}
		}
		if ( ! $dirty ) {
			return;
		}
		update_option( self::SEEN_OPTION, [ 'items' => $items, 'updated_at' => $now ], false );
	}

	/**
	 * v1.7.1：打開「後台精簡」頁時直接列舉控制台小工具，不必先打開一次控制台（使用者 10-27：「控制台為什麼需要打開一次？」，對標 Widget Disable）。
	 *
	 * 做法同 Widget Disable：載入 wp-admin/includes/dashboard.php、暫時把目前畫面設成控制台、執行 wp_dashboard_setup()。
	 * 核心與各外掛在這一步只「登記」小工具，不執行小工具的內容；本類別在 wp_dashboard_setup 很晚的那一步照常記下（on_dashboard_setup() → remember()）。
	 * 結束後還原目前畫面與 $wp_meta_boxes['dashboard']。只給 YAM 管理者；環境沒有這些函式（例如測試替身）就不做。
	 */
	public static function collect_now(): void {
		if ( ! YSAccess::can_manage() ) {
			return;
		}
		if ( ! function_exists( 'wp_dashboard_setup' ) && is_readable( ABSPATH . 'wp-admin/includes/dashboard.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		}
		if ( ! function_exists( 'wp_dashboard_setup' ) || ! function_exists( 'set_current_screen' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		global $wp_meta_boxes;
		$screen = get_current_screen();
		$had    = is_array( $wp_meta_boxes ) && array_key_exists( self::SCREEN, $wp_meta_boxes );
		$boxes  = $had ? $wp_meta_boxes[ self::SCREEN ] : null;
		set_current_screen( self::SCREEN );
		try {
			wp_dashboard_setup();
		} finally {
			if ( $had ) {
				$wp_meta_boxes[ self::SCREEN ] = $boxes;
			} elseif ( is_array( $wp_meta_boxes ) ) {
				unset( $wp_meta_boxes[ self::SCREEN ] );
			}
			if ( $screen ) {
				set_current_screen( $screen );
			}
		}
	}

	/** load-index.php：設定要藏歡迎面板時，拿掉核心的 wp_welcome_panel。 */
	public static function maybe_hide_welcome(): void {
		if ( self::settings()['hide_welcome'] ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}
	}
}
