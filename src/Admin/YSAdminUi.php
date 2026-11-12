<?php
/**
 * YS 後台中央樣式（v1.8.0）
 *
 * 後台樣式中央化：本外掛的設定頁也一起整理。
 *
 * assets/ys-admin-ui/ 四支：設計系統三支（ysca-tokens、ysca-admin-base、ys-cart-admin-components）＋共用版面 ys-admin-ui.css。
 * 其他 YS 外掛內附同一份、用同一組代稱；同一頁只註冊一份，取版本最高的那份。
 * 本外掛的設定頁只用這裡的元件，自己的 CSS 只放獨有的元件（ys-admin-menu-settings.css、選單排序表、色彩挑選器）。
 *
 * @package YangSheep\AdminMenu\Admin
 */

namespace YangSheep\AdminMenu\Admin;

defined( 'ABSPATH' ) || exit;

final class YSAdminUi {

	/** 內附的中央樣式版本（assets/ys-admin-ui/ys-admin-ui.css 檔頭的 @version）。本外掛 1.11.0 起內附 1.1.1（1.1.0 字級、1.1.1 窄螢幕）。 */
	public const VERSION = '1.1.1';

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'register_styles' ], 1 );
	}

	/**
	 * 註冊四支樣式（只註冊，用到的頁面再 enqueue ys-admin-ui）。
	 * 別的外掛已經註冊、版本不低於本外掛內附的就沿用；比較舊就換成本外掛這份。
	 */
	public static function register_styles(): void {
		$have = wp_styles()->query( 'ys-admin-ui', 'registered' );
		if ( $have && version_compare( (string) $have->ver, self::VERSION, '>=' ) ) {
			return;
		}
		$url = YS_ADMIN_MENU_PLUGIN_URL . 'assets/ys-admin-ui/';
		foreach ( [ 'ys-admin-ui', 'ys-admin-ui-components', 'ys-admin-ui-base', 'ys-admin-ui-tokens' ] as $handle ) {
			wp_deregister_style( $handle );
		}
		wp_register_style( 'ys-admin-ui-tokens', $url . 'ysca-tokens.css', [], self::VERSION );
		wp_register_style( 'ys-admin-ui-base', $url . 'ysca-admin-base.css', [ 'ys-admin-ui-tokens' ], self::VERSION );
		wp_register_style( 'ys-admin-ui-components', $url . 'ys-cart-admin-components.css', [ 'ys-admin-ui-base' ], self::VERSION );
		wp_register_style( 'ys-admin-ui', $url . 'ys-admin-ui.css', [ 'ys-admin-ui-components' ], self::VERSION );
	}
}
