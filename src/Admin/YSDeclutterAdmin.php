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

		$comments = YSCommentsDisabler::settings();
		$types    = [];
		foreach ( (array) get_post_types_by_support( 'comments' ) as $name ) {
			$object = get_post_type_object( $name );
			if ( $object && ( ! empty( $object->show_ui ) || ! empty( $object->public ) ) ) {
				$types[ (string) $name ] = (string) ( $object->labels->name ?? $name );
			}
		}
		foreach ( $comments['post_types'] as $name ) {
			if ( ! isset( $types[ $name ] ) ) {
				$types[ $name ] = $name . '（目前沒有註冊）';
			}
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
