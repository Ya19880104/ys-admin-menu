<?php
/**
 * WP-CLI：誰能使用 YS Admin Menu（v1.7.0，設計 §6.5）。救援用，在主機上執行，不受「外掛使用權限」限制。
 *
 *   wp ys-admin-menu access status
 *   wp ys-admin-menu access reset
 *   wp ys-admin-menu access allow <帳號>...
 *
 * @package YangSheep\AdminMenu\Cli
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Cli;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

/**
 * 查看、重設「外掛使用權限」，或把帳號加進清單。
 */
final class YSAccessCommand {

	/**
	 * 目前的設定與實際生效的結果。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu access status
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( $args, $assoc_args ): void {
		self::heal();
		$saved     = YSAccess::get();
		$effective = YSAccess::effective();
		\WP_CLI::line( '設定：' . ( 'users' === $saved['mode'] ? '只有指定的帳號' : '所有能管理網站的管理員（預設）' ) );
		\WP_CLI::line( '實際：' . ( 'users' === $effective['mode'] ? '只有指定的帳號' : '所有能管理網站的管理員' )
			. ( $effective['reset_constant'] && 'users' === $saved['mode'] ? '（wp-config 定義了 ' . YSAccess::RESET_CONSTANT . '）' : '' )
			. ( $effective['fallback'] && ! $effective['reset_constant'] ? '（清單裡沒有還能管理網站的帳號，自動改回所有管理員）' : '' ) );
		\WP_CLI::line( '外掛清單對其他人隱藏：' . ( $saved['hide_plugin_row'] ? '是' : '否' ) );
		if ( [] === $saved['user_ids'] ) {
			\WP_CLI::line( '清單：（空）' );
			return;
		}
		$rows = [];
		foreach ( $saved['user_ids'] as $id ) {
			$user   = get_userdata( $id );
			$rows[] = [
				'ID'     => $id,
				'帳號'   => $user ? self::clean( (string) $user->user_login ) : '（已刪除）',
				'有效'   => user_can( $id, 'manage_options' ) ? '是' : '否（不存在或沒有 manage_options）',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', '帳號', '有效' ] );
	}

	/**
	 * 改回「所有能管理網站的管理員都能使用」，並取消外掛清單隱藏（保留帳號清單，之後要再用可以直接切回去）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu access reset
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function reset( $args, $assoc_args ): void {
		self::heal();
		$cfg                    = YSAccess::get();
		$cfg['mode']            = 'capability';
		$cfg['hide_plugin_row'] = false;
		update_option( YSAccess::OPTION, $cfg, true );
		\WP_CLI::success( '已改回所有能管理網站的管理員都能使用 YS 選單管理，外掛清單也不再隱藏。' );
	}

	/**
	 * 把帳號加進「只有指定的帳號」清單（不改模式）。帳號要存在而且有 manage_options。
	 *
	 * ## OPTIONS
	 *
	 * <user>...
	 * : 帳號 ID、登入名稱或 Email。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu access allow admin2
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function allow( $args, $assoc_args ): void {
		self::heal();
		$cfg   = YSAccess::get();
		$added = [];
		foreach ( (array) $args as $who ) {
			$who  = (string) $who;
			$user = ctype_digit( $who ) ? get_userdata( (int) $who ) : ( get_user_by( 'login', $who ) ?: get_user_by( 'email', $who ) );
			if ( ! $user || ! user_can( (int) $user->ID, 'manage_options' ) ) {
				\WP_CLI::error( '找不到帳號，或這個帳號沒有 manage_options：' . self::clean( $who ) );
			}
			$id = (int) $user->ID;
			if ( ! in_array( $id, $cfg['user_ids'], true ) ) {
				if ( count( $cfg['user_ids'] ) >= YSAccess::MAX_USERS ) {
					\WP_CLI::error( '清單已經有 ' . YSAccess::MAX_USERS . ' 個帳號。' );
				}
				$cfg['user_ids'][] = $id;
				$added[]           = $id;
			}
		}
		update_option( YSAccess::OPTION, YSAccess::sanitize( $cfg ), true );
		\WP_CLI::success( ( [] === $added ? '帳號本來就在清單裡。' : '已加進清單：ID ' . implode( ', ', $added ) . '。' )
			. ( 'users' === $cfg['mode'] ? '' : '目前是「所有管理員都能使用」，清單要在設定頁切到「只有指定的帳號」才生效。' ) );
	}

	/**
	 * v1.7.0：設定被改成不自動載入時，YSAccess::get() 會當成沒設定過（重設會把帳號清單寫成空的）；先改回自動載入。
	 */
	private static function heal(): void {
		if ( YSAccess::heal_autoload( YSAccess::OPTION ) ) {
			\WP_CLI::warning( '設定被改成「不自動載入」，已經改回自動載入。' );
		}
	}

	/** 印到終端機前拿掉控制字元（帳號名稱是使用者輸入的）。 */
	private static function clean( string $s ): string {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $s );
	}
}
