<?php
/**
 * v1.7.0：「後台精簡」設定頁（關閉留言 YSCommentsDisabler、控制台小工具 YSDashboardWidgets）。
 *
 * 一張表單送 admin-post.php（action＝ys_admin_menu_save_declutter、自己的 nonce），兩個模組各自清理、存檔，存完回到這一頁。
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Access\YSAccess;
use YangSheep\AdminMenu\Comments\YSCommentsDisabler;
use YangSheep\AdminMenu\Dashboard\YSDashboardWidgets;

defined( 'ABSPATH' ) || exit;

final class YSDeclutterAdmin {

	public const PAGE_SLUG    = 'ys-admin-menu-declutter';
	public const POST_ACTION  = 'ys_admin_menu_save_declutter';
	public const NONCE_ACTION = 'ys_admin_menu_save_declutter';

	public static function register(): void {
		add_action( 'admin_post_' . self::POST_ACTION, [ self::class, 'handle_save' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function render(): void {
		if ( ! YSAccess::can_manage() ) {
			wp_die( esc_html__( '權限不足', 'ys-admin-menu' ) );
		}

		// v1.7.0：選項被別的工具改成不自動載入時，設定會靜默回到預設（留言重新打開、小工具全部回來）；打開這一頁時改回來並提示。
		$healed = YSAccess::heal_autoload( YSCommentsDisabler::OPTION );
		$healed = YSAccess::heal_autoload( YSDashboardWidgets::OPTION ) || $healed;

		$comments = YSCommentsDisabler::settings();
		$types    = [];
		// 包含本模組拿掉留言支援的類型（不保留舊留言時每個請求都會拿掉；只看 get_post_types_by_support() 會列不出已關閉的類型）。
		foreach ( YSCommentsDisabler::comment_capable_types() as $name ) {
			$object = get_post_type_object( $name );
			if ( $object && ( ! empty( $object->show_ui ) || ! empty( $object->public ) ) ) {
				$types[ (string) $name ] = (string) ( $object->labels->name ?? $name );
			}
		}
		// 已存的類型一律列出、保持勾選（存檔時也保留）：沒註冊（外掛停用），或暫時不支援留言（例如 WooCommerce 關閉商品評論）。
		foreach ( $comments['post_types'] as $name ) {
			if ( isset( $types[ $name ] ) ) {
				continue;
			}
			$object         = post_type_exists( $name ) ? get_post_type_object( $name ) : null;
			$types[ $name ] = $object
				? (string) ( $object->labels->name ?? $name ) . '（目前不支援留言）'
				: $name . '（目前沒有註冊）';
		}
		$dashboard = YSDashboardWidgets::settings();
		$seen      = YSDashboardWidgets::seen();
		$saved     = ! empty( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只顯示提示

		$template = YS_ADMIN_MENU_PLUGIN_DIR . 'templates/admin/declutter.php';
		if ( file_exists( $template ) ) {
			include $template;
			return;
		}
		echo '<div class="wrap"><div class="notice notice-error"><p>後台精簡模板不存在。</p></div></div>';
	}

	/** admin-post：存「後台精簡」。 */
	public static function handle_save(): void {
		if ( ! YSAccess::can_manage() ) {
			wp_die( esc_html__( '權限不足。', 'ys-admin-menu' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );
		$post      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 兩個模組的 sanitize() 逐欄清理
		$post      = is_array( $post ) ? $post : [];
		$comments  = is_array( $post['comments'] ?? null ) ? $post['comments'] : [];
		$dashboard = is_array( $post['dashboard'] ?? null ) ? $post['dashboard'] : [];
		YSCommentsDisabler::save( $comments );
		YSDashboardWidgets::save( $dashboard );
		wp_safe_redirect( self::url( [ 'saved' => '1' ] ) );
		exit;
	}

	/** 控制台欄位的中文名稱。 */
	public static function context_label( string $context ): string {
		$labels = [
			'normal'  => '左欄',
			'side'    => '右欄',
			'column3' => '第三欄',
			'column4' => '第四欄',
		];
		return $labels[ $context ] ?? $context;
	}
}
