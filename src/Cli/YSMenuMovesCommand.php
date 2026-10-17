<?php
/**
 * WP-CLI：YS CART 選單搬家的遷移狀態（v1.5.0，只讀）。
 *
 *   wp ys-admin-menu moves status
 *   wp ys-admin-menu moves dry-run [--format=table|json]
 *
 * 兩個指令都不寫入任何資料，給驗收與客服用。不做自動還原；需要還原時用備份
 * （ys_admin_menu_config_pre_moves_<版本>）。
 *
 * @package YangSheep\AdminMenu\Cli
 * @since   1.5.0
 */

namespace YangSheep\AdminMenu\Cli;

use YangSheep\AdminMenu\Menu\YSMenuMovesMigrator;
use YangSheep\AdminMenu\Menu\YSMenuRouter;

defined( 'ABSPATH' ) || exit;

/**
 * 查看 YS CART 選單搬家的遷移狀態（只讀）。
 */
final class YSMenuMovesCommand {

	/**
	 * 顯示 YS CART 有沒有提供搬家對照、遷移標記、鎖、備份與後台提醒的狀態。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu moves status
	 *
	 * @subcommand status
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function status( $args, $assoc_args ): void {
		$core   = YSMenuMovesMigrator::detect();
		$marker = YSMenuMovesMigrator::read_marker( get_option( YSMenuMovesMigrator::MARKER_OPTION, null ) );
		$log    = YSMenuMovesMigrator::read_log( get_option( YSMenuMovesMigrator::LOG_OPTION, null ) );
		$lock   = get_option( YSMenuMovesMigrator::LOCK_OPTION, null );

		$rows   = [];
		$rows[] = [
			'項目' => 'YS CART 搬家對照',
			'值'   => null === $core
				? '沒有（舊版 YS CART，或站上沒有啟用 YS CART）：不會遷移'
				: sprintf( 'MOVES_VERSION %s，%d 筆，sha256 %s', $core['version'], count( $core['moves'] ), $core['hash'] ),
		];
		$rows[] = [
			'項目' => '遷移標記',
			'值'   => '' === $marker['version']
				? '沒有（還沒遷移過）'
				: sprintf( '%s，sha256 %s，規則版本 %s，已套用 %d 筆', $marker['version'], $marker['sha256'], '' === $marker['rules'] ? '（無）' : $marker['rules'], count( $marker['applied'] ) ),
		];
		$rows[] = [
			'項目' => '和目前的對照相同',
			'值'   => null === $core ? '—' : ( YSMenuMovesMigrator::marker_is_current( $marker, $core ) ? '是（不會再遷移）' : '否（下一個後台頁面請求會遷移）' ),
		];
		$rows[] = [
			'項目' => '鎖',
			'值'   => null === $lock ? '沒有' : '有（' . gmdate( 'Y-m-d H:i:s', YSMenuMovesMigrator::lock_time( (string) $lock ) ) . ' UTC 取得）',
		];
		$backups = self::backup_names();
		$rows[]  = [
			'項目' => '遷移前備份',
			'值'   => empty( $backups ) ? '沒有' : implode( '、', $backups ),
		];
		$last   = empty( $log['runs'] ) ? null : $log['runs'][ count( $log['runs'] ) - 1 ];
		$rows[] = [
			'項目' => '最後一次遷移',
			'值'   => null === $last
				? '沒有紀錄'
				: sprintf( '%s UTC，版本 %s，%s，新套用 %d 筆', gmdate( 'Y-m-d H:i:s', (int) ( $last['at'] ?? 0 ) ), (string) ( $last['version'] ?? '' ), ! empty( $last['changed'] ) ? '改了設定' : '設定不用改', count( (array) ( $last['applied'] ?? [] ) ) ),
		];
		$event  = empty( $log['events'] ) ? null : $log['events'][ count( $log['events'] ) - 1 ];
		$rows[] = [
			'項目' => '啟用時的處理',
			'值'   => null === $event
				? '沒有紀錄'
				: sprintf( '%s UTC，%s', gmdate( 'Y-m-d H:i:s', (int) ( $event['at'] ?? 0 ) ), self::label( (string) ( $event['type'] ?? '' ) ) )
					. ( is_string( $event['saved'] ?? null ) ? '（YS CART 那份另存在 ' . $event['saved'] . '）' : '' ),
		];
		$rows[] = [
			'項目' => '後台提醒',
			'值'   => $log['dismissed'] ? '已關閉' : ( YSMenuMovesMigrator::notice_has_content( $log['notice'] ) ? '顯示中' : '沒有內容' ),
		];

		\WP_CLI\Utils\format_items( 'table', $rows, [ '項目', '值' ] );

		if ( ! empty( $marker['applied'] ) ) {
			\WP_CLI::line( '已套用的鍵：' . implode( ', ', $marker['applied'] ) );
		}
	}

	/**
	 * 列出現在跑遷移會改哪些設定列（不寫入）。
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : 輸出格式。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu moves dry-run
	 *     wp ys-admin-menu moves dry-run --format=json
	 *
	 * @subcommand dry-run
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function dry_run( $args, $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ? 'json' : 'table';
		$core   = YSMenuMovesMigrator::detect();
		if ( null === $core ) {
			\WP_CLI::line( '站上的 YS CART 沒有提供選單搬家對照（舊版，或沒有啟用），不會遷移。' );
			return;
		}
		$marker = YSMenuMovesMigrator::read_marker( get_option( YSMenuMovesMigrator::MARKER_OPTION, null ) );
		if ( YSMenuMovesMigrator::marker_is_current( $marker, $core ) ) {
			\WP_CLI::line( sprintf( '遷移標記和目前的對照相同（%s），不會再遷移，也不會改任何設定。', $core['version'] ) );
			return;
		}

		$plan = YSMenuMovesMigrator::plan( YSMenuRouter::get_config(), $core['moves'], $marker['applied'] );

		if ( 'json' === $format ) {
			\WP_CLI::line(
				(string) wp_json_encode(
					[
						'version' => $core['version'],
						'sha256'  => $core['hash'],
						'changed' => $plan['changed'],
						'applied' => $plan['applied'],
						'rows'    => $plan['rows'],
						'log'     => $plan['log'],
						'notice'  => $plan['notice'],
						'invalid' => $core['invalid'],
					],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		\WP_CLI::line( sprintf( 'YS CART 搬家對照 %s（%d 筆）；這一輪會新套用 %d 筆：%s', $core['version'], count( $core['moves'] ), count( $plan['applied'] ), empty( $plan['applied'] ) ? '—' : implode( ', ', $plan['applied'] ) ) );

		if ( ! $plan['changed'] ) {
			\WP_CLI::line( '設定不用改（只會寫入遷移標記與紀錄）。' );
		} else {
			$table = [];
			foreach ( $plan['rows'] as $r ) {
				$before  = is_array( $r['before'] ) ? $r['before'] : [];
				$after   = is_array( $r['after'] ) ? $r['after'] : [];
				$table[] = [
					'分頁' => [ 'ys_cart' => '全部選單', 'wp_native' => '主選單', 'top_copies' => '複製到主選單' ][ $r['section'] ] ?? $r['section'],
					'動作' => [ 'changed' => '修改', 'added' => '新增', 'removed' => '拿掉' ][ $r['action'] ] ?? $r['action'],
					'slug' => (string) ( $after['slug'] ?? $before['slug'] ?? '' ),
					'改了什麼' => self::describe_change( $before, $after ),
				];
			}
			\WP_CLI::line( '會改的設定列：' );
			\WP_CLI\Utils\format_items( 'table', $table, [ '分頁', '動作', 'slug', '改了什麼' ] );
		}

		$entries = $plan['log'];
		foreach ( $core['invalid'] as $bad ) {
			$entries[] = [ 'type' => 'invalid' ] + $bad;
		}
		if ( ! empty( $entries ) ) {
			\WP_CLI::line( '紀錄：' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static fn( $e ) => [
						'項目' => self::label( (string) ( $e['type'] ?? '' ) ),
						'內容' => self::details( $e ),
					],
					$entries
				),
				[ '項目', '內容' ]
			);
		}
		\WP_CLI::line( '這是預覽，沒有寫入任何資料。' );
	}

	/**
	 * @return array<int,string>
	 */
	private static function backup_names(): array {
		global $wpdb;
		$like = $wpdb->esc_like( YSMenuMovesMigrator::BACKUP_PREFIX ) . '%';
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $like ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 只讀列出備份。
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private static function describe_change( array $before, array $after ): string {
		$parts = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$a = array_key_exists( $key, $before ) ? $before[ $key ] : '（無）';
			$b = array_key_exists( $key, $after ) ? $after[ $key ] : '（無）';
			if ( $a !== $b ) {
				$parts[] = $key . ': ' . self::scalar( $a ) . ' → ' . self::scalar( $b );
			}
		}
		return implode( '；', $parts );
	}

	/**
	 * @param mixed $v
	 */
	private static function scalar( $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( null === $v ) {
			return 'null';
		}
		if ( is_array( $v ) ) {
			return '[' . implode( ',', array_map( static fn( $x ) => is_scalar( $x ) ? (string) $x : '…', $v ) ) . ']';
		}
		return (string) $v;
	}

	private static function label( string $type ): string {
		$labels = [
			'move'               => '搬到新上層',
			'merge'              => '併入新上層已有的列',
			'rules'              => '帶上舊上層的規則',
			'self_only_conflict' => '僅限本人綁不同帳號，改成隱藏',
			'bind'               => '帶入舊上層的僅限本人',
			'gate'               => '綁定的帳號原本看不到，改成隱藏',
			'hide_sub_row'       => '拆掉的頂層原本隱藏，子選單也隱藏',
			'top_rules_row'      => '拆掉的頂層只在主選單分頁存過規則，補一列帶這些規則的子選單列',
			'mirror_failed'      => 'Core 那份鏡像沒寫成（指紋沒更新）',
			'core_backup'        => 'YS CART 已經自己搬過一次：改用它的搬家前備份，由本外掛重搬',
			'core_copy_v2'       => '沿用 YS CART 那份設定，裡面有本外掛看不懂的 v2 規則（已提醒）',
			'rules_row'          => '新增只帶規則的列',
			'convert'            => '頂層改成子選單',
			'merge_self'         => '併入同 slug 的子選單列',
			'drop_group_title'   => '群組名稱不帶過去',
			'copy'               => '複製項換來源上層',
			'copy_duplicate'     => '重複的複製項拿掉',
			'key'                => '主選單分頁換鍵',
			'key_conflict'       => '新鍵已經有了，沒換',
			'anchor'             => '改接在別的頂層後面',
			'anchor_unresolved'  => '找不到新的接續位置',
			'order'              => '順序',
			'order_default'      => '照預設順序（不寫順序值）',
			'order_shift'        => '後面的列往後挪',
			'whitelist'          => '白名單要重新勾',
			'unknown_kind'       => '不認得的 kind，略過',
			'invalid'            => '格式不對，略過',
		];
		return $labels[ $type ] ?? $type;
	}

	/**
	 * @param array<string,mixed> $e
	 */
	private static function details( array $e ): string {
		$parts = [];
		foreach ( $e as $k => $v ) {
			if ( 'type' === $k ) {
				continue;
			}
			$parts[] = $k . '=' . self::scalar( $v );
		}
		return implode( ' ', $parts );
	}
}
