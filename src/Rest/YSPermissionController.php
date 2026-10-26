<?php
/**
 * 權限設置 REST Controller（v2.38.2 BATCH I7 + I8）
 *
 * 取代 v2.37.1 的 admin-post 寫法，全面改成 REST：
 *
 *   GET  /admin/permissions/menu-config       — 取得目前 menu_config（含三個 tab slice）
 *   POST /admin/permissions/menu-config       — 儲存指定 tab 的 slice
 *   GET  /admin/permissions/menu-enumeration  — 動態列出所有 wp-admin sidebar top-level
 *                                                  + YS CART top-level + sub-pages
 *
 * 設計重點：
 *   - 為了讓 enumeration 能拿到完整 $menu / $submenu，
 *     當全域 $menu 為空時（典型 REST request 不會載入 admin context），
 *     主動 require wp-admin/includes/menu.php + 觸發 admin_menu hook。
 *   - 列舉 enumeration 時 include 所有 plugin 註冊的 top-level（含 YS CART 自家 +
 *     第三方如「內容權限」/ 「YS POS」），跳過 wp-menu-separator slug。
 *
 * Auth（v2.52.22 起統一走 YSRestAuth::permission_admin）：
 *   - capability：manage_options（floor，不可降低）
 *   - nonce：X-WP-Nonce / x_wp_nonce / _wpnonce verify against 'wp_rest'
 *   - request body size cap：一般 64KB / multipart 16MB
 *
 * @package YangSheep\AdminMenu\Rest
 * @since   2.38.2
 */

namespace YangSheep\AdminMenu\Rest;

defined( 'ABSPATH' ) || exit;

use YangSheep\AdminMenu\Menu\YSMenuConfigBridge;
use YangSheep\AdminMenu\Menu\YSMenuMovesMigrator;
use YangSheep\AdminMenu\Menu\YSMenuRouter;

class YSPermissionController {

	/** REST namespace（本外掛專屬） */
	private const NAMESPACE_NAME = 'ys-admin-menu/v1';

	/** v1.3.0：「複製到主選單」最多幾個（防止異常 payload 塞爆設定）。 */
	private const MAX_TOP_COPIES = 100;

	/** v1.7.0：sanitize_native_items() 認得的欄位（keep_unknown_fields() 用；其他欄位存檔時照已存的值保留）。 */
	private const NATIVE_FIELDS = [ 'slug', 'title_override', 'order', 'color', 'roles', 'hide', 'self_only_uid', 'icon', 'after', 'access', 'target' ];

	/** v1.7.0：sanitize_ys_cart_items() 認得的欄位。 */
	private const YS_CART_FIELDS = [ 'slug', 'order', 'roles', 'level', 'parent_slug', 'hide', 'self_only_uid', 'promote_to_top', 'title_override', 'access', 'target', 'display_parent' ];

	/** v1.7.0：設定格式版本。1.7.0 起用設定頁存檔或匯入時寫進設定（1.6.0 以前沒有這個鍵＝第 1 版）。 */
	public const SCHEMA_VERSION = 2;

	/** v1.7.0：第一次用 1.7.0 存檔前，原本（第 1 版）設定的備份。只寫一次、不自動載入；解除安裝時一併清除。 */
	public const V1_BACKUP_OPTION = 'ys_admin_menu_config_v1_backup';

	/** v1.7.0：以某位使用者試算一次最多幾列。 */
	private const MAX_PREVIEW_ROWS = 600;

	/**
	 * 註冊路由（由 YSRouteRegistrar 在 rest_api_init 呼叫）
	 */
	public static function register_routes(): void {
		// GET：拿目前已存的 config（給 JS 預先 hydrate 表單）
		register_rest_route( self::NAMESPACE_NAME, '/admin/permissions/menu-config', [
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_menu_config' ],
				'permission_callback' => [ YSRestAuth::class, 'permission_admin' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'save_menu_config' ],
				'permission_callback' => [ YSRestAuth::class, 'permission_admin' ],
			],
		] );

		// GET：動態列舉所有 wp-admin top-level + YS CART endpoints
		register_rest_route( self::NAMESPACE_NAME, '/admin/permissions/menu-enumeration', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'enumerate_menus' ],
			'permission_callback' => [ YSRestAuth::class, 'permission_admin' ],
		] );

		// v1.2.0：匯出（GET）／匯入（POST）全部設定（選單+權限、樣式、白牌）
		register_rest_route( self::NAMESPACE_NAME, '/admin/permissions/export', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'export_settings' ],
			'permission_callback' => [ YSRestAuth::class, 'permission_admin' ],
		] );
		register_rest_route( self::NAMESPACE_NAME, '/admin/permissions/import', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'import_settings' ],
			'permission_callback' => [ YSRestAuth::class, 'permission_admin' ],
		] );

		// v1.7.0：以某位使用者試算（只讀：照目前已存的設定算出每一列側欄看不看得到、網址進不進得去，不切換身分）。
		register_rest_route( self::NAMESPACE_NAME, '/admin/permissions/preview', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'preview_for_user' ],
			'permission_callback' => [ YSRestAuth::class, 'permission_admin' ],
		] );
	}

	/**
	 * v1.2.0：匯出的 option key 清單（選單設定除外，選單走 YSMenuRouter::OPTION_KEY）。
	 *
	 * @return array<int, string>
	 */
	private static function exportable_whitelabel_option_keys(): array {
		return [
			\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::OPTION_LOGO_URL,
			\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::OPTION_FOOTER_TEXT,
			\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::OPTION_HIDE_FOOTER,
			\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::OPTION_HIDE_WP_LOGO,
			\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::OPTION_ADMIN_BG_COLOR,
			\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::OPTION_PURGE_ON_UNINSTALL,
		];
	}

	/**
	 * GET /admin/permissions/export
	 *
	 * 打包全部設定為可下載 bundle：選單+權限（menu）、原生選單樣式（theme）、白牌（whitelabel）。
	 */
	public static function export_settings(): \WP_REST_Response {
		$whitelabel = [];
		foreach ( self::exportable_whitelabel_option_keys() as $key ) {
			$value = get_option( $key, null );
			if ( null !== $value ) {
				$whitelabel[ $key ] = $value;
			}
		}

		$bundle = [
			'_type'       => 'ys-admin-menu-export',
			'version'     => defined( 'YS_ADMIN_MENU_VERSION' ) ? YS_ADMIN_MENU_VERSION : '',
			'site'        => get_site_url(),
			'exported_at' => gmdate( 'c' ),
			'menu'        => (array) get_option( YSMenuRouter::OPTION_KEY, [] ),
			// 匯出實際生效的樣式（已存的值疊上預設值）。從沒存過樣式的站若只匯出空陣列，
			// 匯入端會把它存成「停用」，外觀就和來源站不一樣了。
			'theme'       => \YangSheep\AdminMenu\Theme\YSAdminThemeRenderer::effective_config(),
			'whitelabel'  => $whitelabel,
		];

		return new \WP_REST_Response( $bundle, 200 );
	}

	/**
	 * POST /admin/permissions/import
	 *
	 * 以匯出 bundle 覆蓋目前設定。每部分都重新 sanitize：
	 *   - menu：走 save_menu_config 相同的 per-slice sanitize + YSMenuConfigBridge。
	 *   - theme：白名單鍵；顏色 sanitize_hex_color、數值 clamp、其餘忽略（renderer 另會於輸出再 sanitize）。
	 *   - whitelabel：逐鍵 sanitize（URL / kses / bool）。
	 */
	public static function import_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ( ( $payload['_type'] ?? '' ) !== 'ys-admin-menu-export' ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => '檔案不是有效的設定匯出檔' ], 400 );
		}

		// 1. menu（選單+權限）
		$warnings = [];
		if ( isset( $payload['menu'] ) && is_array( $payload['menu'] ) ) {
			$menu     = $payload['menu'];
			$existing = [];
			$existing['wp_native']['items'] = self::sanitize_native_items( (array) ( $menu['wp_native']['items'] ?? [] ) );
			$existing['ys_cart']['items']   = self::sanitize_ys_cart_items( (array) ( $menu['ys_cart']['items'] ?? [] ) );
			if ( isset( $menu['ys_cart']['hide_for_user_ids'] ) ) {
				$existing['ys_cart']['hide_for_user_ids'] = self::sanitize_user_id_list( $menu['ys_cart']['hide_for_user_ids'] );
			}
			$existing['user_overrides']['items'] = self::sanitize_user_override_items( (array) ( $menu['user_overrides']['items'] ?? [] ) );
			// v1.3.0：子選單「複製到主選單」。舊版匯出檔沒有這一段＝沒有複製項（匯入是整份覆蓋）。
			$copies = self::sanitize_top_copies( (array) ( $menu['top_copies']['items'] ?? [] ) );
			if ( ! empty( $copies ) ) {
				$existing['top_copies']['items'] = $copies;
			}
			// v1.7.0：自訂連結、主選單分頁的預設行為。舊版匯出檔沒有＝沒有。
			$custom = self::sanitize_custom_items( (array) ( $menu['custom_items']['items'] ?? [] ) );
			if ( ! empty( $custom ) ) {
				$existing['custom_items']['items'] = $custom;
			}
			$defaults = self::sanitize_defaults( $menu['defaults'] ?? null );
			if ( ! empty( $defaults ) ) {
				$existing['defaults'] = $defaults;
			}

			// 維持 v2.37.1 legacy fallback（與 save_menu_config 一致）
			$legacy_map = [];
			foreach ( $existing['user_overrides']['items'] as $row ) {
				$uid = (int) ( $row['user_id'] ?? 0 );
				if ( $uid > 0 && ! empty( $row['visible_slugs'] ) ) {
					$legacy_map[ (string) $uid ] = [ 'visible_slugs' => $row['visible_slugs'] ];
				}
			}
			$existing['wp_native']['user_overrides'] = $legacy_map;

			// v1.7.0：設定裡指到這個站上沒有的帳號（僅限本人、指定帳號、使用者覆寫）：照樣匯入，回傳提示讓管理員檢查。
			$warnings = self::unknown_user_warnings( $existing );
			YSMenuConfigBridge::save_authoritative( self::stamp_schema( $existing ) );
		}

		// 2. theme（原生選單樣式）
		if ( isset( $payload['theme'] ) && is_array( $payload['theme'] ) ) {
			update_option(
				\YangSheep\AdminMenu\Theme\YSAdminThemeRenderer::OPTION_KEY,
				self::sanitize_theme_config( $payload['theme'] ),
				true
			);
		}

		// 3. whitelabel（白牌）
		if ( isset( $payload['whitelabel'] ) && is_array( $payload['whitelabel'] ) ) {
			self::import_whitelabel_options( $payload['whitelabel'] );
		}

		$out = [ 'success' => true, 'imported_at' => time() ];
		if ( ! empty( $warnings ) ) {
			$out['warnings'] = $warnings;
		}
		return new \WP_REST_Response( $out, 200 );
	}

	/**
	 * v1.7.0：設定裡指到的帳號 ID（僅限本人、逐項目權限的指定帳號、使用者覆寫），這個站上沒有的列成提示。
	 * 匯入別的站的設定時，帳號 ID 通常對不上：規則照樣存，但不會套到預期的人。
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,string>
	 */
	private static function unknown_user_warnings( array $config ): array {
		$ids = [];
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( (array) ( $config[ $tab ]['items'] ?? [] ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( (int) ( $row['self_only_uid'] ?? 0 ) > 0 ) {
					$ids[ (int) $row['self_only_uid'] ] = true;
				}
				$access = YSMenuRouter::sanitize_access( $row['access'] ?? null );
				foreach ( null !== $access ? array_merge( $access['allow']['users'], $access['deny']['users'] ) : [] as $uid ) {
					$ids[ (int) $uid ] = true;
				}
			}
		}
		foreach ( (array) ( $config['user_overrides']['items'] ?? [] ) as $uo ) {
			if ( is_array( $uo ) && (int) ( $uo['user_id'] ?? 0 ) > 0 ) {
				$ids[ (int) $uo['user_id'] ] = true;
			}
		}
		$missing = [];
		foreach ( array_keys( $ids ) as $uid ) {
			if ( ! get_userdata( $uid ) ) {
				$missing[] = $uid;
			}
		}
		if ( [] === $missing ) {
			return [];
		}
		sort( $missing );
		return [ '設定裡有 ' . count( $missing ) . ' 個帳號在這個站上找不到（ID：' . implode( '、', array_slice( $missing, 0, 20 ) ) . ( count( $missing ) > 20 ? '…' : '' ) . '）。相關的「僅限本人」、指定帳號與使用者覆寫已照樣匯入，但不會套到任何人；請到各分頁檢查。' ];
	}

	/**
	 * v1.7.0：用設定頁存檔或匯入時，設定標上格式版本（schema_version＝2）與寫入的外掛版本（_meta）。
	 * 第一次寫第 2 版之前，把原本的第 1 版設定原樣備份到 V1_BACKUP_OPTION（只寫一次、不自動載入），降版或出問題時可以還原。
	 * 只用舊功能的站，第 2 版跟第 1 版的差別只有這兩個鍵：1.6.0 以前的版本讀得懂、照舊運作。
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 */
	private static function stamp_schema( array $config ): array {
		$current = YSMenuRouter::get_config();
		if ( [] !== $current && ! isset( $current['schema_version'] ) && false === get_option( self::V1_BACKUP_OPTION, false ) ) {
			// 存原本的選項值（舊站可能存成 JSON 字串），不是解開後的陣列。
			update_option( self::V1_BACKUP_OPTION, get_option( YSMenuRouter::OPTION_KEY ), false );
		}
		$config['schema_version'] = self::SCHEMA_VERSION;
		$config['_meta']          = [
			'saved_by' => 'ys-admin-menu',
			'version'  => defined( 'YS_ADMIN_MENU_VERSION' ) ? (string) YS_ADMIN_MENU_VERSION : '',
		];
		return $config;
	}

	/**
	 * v1.7.0：主選單分頁的「預設行為」（defaults）。沒選任何一項回空陣列（不存這個鍵，舊設定的形狀不變）。
	 *   - new_items_visibility：managers_only＝新出現的頂層選單只給能使用本外掛的帳號看（只管側欄）。
	 *   - rename_page_titles：改名時頁面標題也跟著改。
	 *
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize_defaults( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		$out = [];
		if ( 'managers_only' === ( $raw['new_items_visibility'] ?? null ) ) {
			$out['new_items_visibility'] = 'managers_only';
		}
		if ( ! empty( $raw['rename_page_titles'] ) ) {
			$out['rename_page_titles'] = true;
		}
		return $out;
	}

	/**
	 * v1.7.0：自訂連結清單（custom_items.items[] = { url, title }）。網址照 YSMenuRouter::sanitize_custom_url() 清理，
	 * 名稱必填（最多 100 字），同一個網址只留第一筆，最多 YSMenuRouter::MAX_CUSTOM_ITEMS 筆。
	 *
	 * @param array<int, mixed> $raw
	 * @return array<int, array{url:string,title:string}>
	 */
	public static function sanitize_custom_items( array $raw ): array {
		$out  = [];
		$seen = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url   = YSMenuRouter::sanitize_custom_url( is_scalar( $row['url'] ?? null ) ? (string) $row['url'] : '' );
			$title = is_scalar( $row['title'] ?? null ) ? trim( wp_strip_all_tags( (string) $row['title'] ) ) : '';
			if ( '' === $url || '' === $title ) {
				continue;
			}
			$key = YSMenuRouter::slug_key( $url );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = [
				'url'   => $url,
				'title' => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 100 ) : substr( $title, 0, 300 ),
			];
			if ( count( $out ) >= YSMenuRouter::MAX_CUSTOM_ITEMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * v1.7.0：自訂連結——同 merge_unrendered_items() 的做法，頁面上沒列出的（例如設了僅限本人給別的帳號）原樣保留。
	 *
	 * @param array<int, array{url:string,title:string}> $submitted
	 * @param array<int, array{url:string,title:string}> $stored
	 * @param mixed                                      $rendered 頁面載入時列出的網址；沒帶就整份取代。
	 * @return array<int, array{url:string,title:string}>
	 */
	private static function merge_unrendered_custom( array $submitted, array $stored, $rendered ): array {
		if ( ! is_array( $rendered ) ) {
			return $submitted;
		}
		$known = [];
		foreach ( $rendered as $url ) {
			if ( is_string( $url ) && '' !== $url ) {
				$known[ YSMenuRouter::slug_key( $url ) ] = true;
			}
		}
		foreach ( $submitted as $row ) {
			$known[ YSMenuRouter::slug_key( $row['url'] ) ] = true;
		}
		foreach ( $stored as $row ) {
			if ( ! isset( $known[ YSMenuRouter::slug_key( $row['url'] ) ] ) ) {
				$submitted[] = $row;
			}
		}
		return self::sanitize_custom_items( $submitted );
	}

	/**
	 * v1.7.0：「僅限本人」兩個分頁一致。這次送出的列，同一個選單在兩個分頁的每一列都改成同一個帳號：
	 * 側欄看後面的列、直接網址看第一列（1.4.0 起），兩個分頁設得不一樣時會出現側欄看不到、網址卻進得去的情形。
	 * 送出的列裡同一個選單有好幾列時，以側欄實際生效的值為準（後面的列蓋前面）。複製項自己的列不動（它只管複製項）。
	 *
	 * @param array<string,mixed> $config    已合併這次送出內容的設定。
	 * @param array<int,mixed>    $submitted 這次送出的列（已清理）。
	 * @return array<string,mixed>
	 */
	private static function sync_self_only( array $config, array $submitted ): array {
		$copy = [];
		foreach ( (array) ( $config['top_copies']['items'] ?? [] ) as $c ) {
			if ( is_array( $c ) ) {
				$key = YSMenuRouter::slug_key( YSMenuRouter::copy_menu_key( (string) ( $c['slug'] ?? '' ), (string) ( $c['parent_slug'] ?? '' ) ) );
				if ( '' !== $key ) {
					$copy[ $key ] = true;
				}
			}
		}
		$want = [];
		foreach ( $submitted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || ! is_string( $row['slug'] ?? null ) || '' === $row['slug'] ) {
				continue;
			}
			$key = YSMenuRouter::slug_key( $row['slug'] );
			$uid = (int) ( $row['self_only_uid'] ?? 0 );
			if ( '' !== $key && ! isset( $copy[ $key ] ) && ( $uid > 0 || ! isset( $want[ $key ] ) ) ) {
				$want[ $key ] = $uid;
			}
		}
		if ( [] === $want ) {
			return $config;
		}
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( (array) ( $config[ $tab ]['items'] ?? [] ) as $i => $row ) {
				if ( ! is_array( $row ) || ! empty( $row['separator'] ) || ! is_string( $row['slug'] ?? null ) ) {
					continue;
				}
				$key = YSMenuRouter::slug_key( $row['slug'] );
				if ( isset( $want[ $key ] ) && ! ( 'wp_native' === $tab && isset( $copy[ $key ] ) )
					&& (int) ( $row['self_only_uid'] ?? 0 ) !== $want[ $key ] ) {
					$config[ $tab ]['items'][ $i ]['self_only_uid'] = $want[ $key ];
				}
			}
		}
		return $config;
	}

	/**
	 * v1.7.0：POST /admin/permissions/preview — 以某位使用者試算。
	 *
	 * Body：{ user_id, rows: [ { kind: top|sub|copy, slug, parent, cap } ] }（設定頁表格上的列）。
	 * 回傳每一列：sidebar＝側欄看不看得到、url＝直接開網址進不進得去（本外掛的規則＋WordPress 權限 cap）。
	 * 照目前已存的設定算；不切換身分、不寫任何東西。WordPress 自己的規則（例如子選單全部沒權限時頂層也不顯示）不在試算範圍。
	 */
	public static function preview_for_user( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$uid  = is_array( $body ) && isset( $body['user_id'] ) && is_numeric( $body['user_id'] ) ? (int) $body['user_id'] : 0;
		$user = $uid > 0 ? get_userdata( $uid ) : false;
		// 多站網路：get_userdata() 不分站，不是這個站的成員就當成找不到（不透露其他站帳號的名稱與角色）。
		if ( ! $user || ( is_multisite() && ! is_user_member_of_blog( $uid ) ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => '找不到這個帳號' ], 404 );
		}
		$config = YSMenuRouter::get_config();
		$rows   = is_array( $body['rows'] ?? null ) ? array_slice( $body['rows'], 0, self::MAX_PREVIEW_ROWS ) : [];
		$out    = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind   = in_array( $row['kind'] ?? '', [ 'top', 'sub', 'copy' ], true ) ? (string) $row['kind'] : 'top';
			$slug   = is_scalar( $row['slug'] ?? null ) ? wp_specialchars_decode( (string) $row['slug'] ) : '';
			$parent = is_scalar( $row['parent'] ?? null ) ? wp_specialchars_decode( (string) $row['parent'] ) : '';
			$cap    = is_scalar( $row['cap'] ?? null ) ? sanitize_key( (string) $row['cap'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$can = '' === $cap || user_can( $uid, $cap );
			if ( 'copy' === $kind ) {
				$sidebar = YSMenuRouter::copy_allows( $slug, $parent, $config, $uid );
			} elseif ( 'sub' === $kind ) {
				$sidebar = YSMenuRouter::sidebar_allows( $parent, true, $config, $uid ) && YSMenuRouter::sidebar_allows( $slug, false, $config, $uid, [], $parent );
			} else {
				$sidebar = YSMenuRouter::sidebar_allows( $slug, true, $config, $uid );
			}
			$page  = YSMenuRouter::is_external_url( $slug ) ? '' : self::preview_page_of( $slug );
			$out[] = [
				'i'       => isset( $row['i'] ) && is_numeric( $row['i'] ) ? (int) $row['i'] : null,
				'slug'    => $slug,
				'parent'  => $parent,
				'kind'    => $kind,
				'sidebar' => $can && $sidebar,
				'url'     => '' === $page ? null : ( $can && YSMenuRouter::url_allows( $page, $config, $uid ) ),
			];
		}
		return new \WP_REST_Response(
			[
				'success' => true,
				'user'    => [ 'id' => $uid, 'name' => (string) $user->display_name, 'roles' => array_values( (array) $user->roles ) ],
				'rows'    => $out,
			],
			200
		);
	}

	/**
	 * v1.7.0：選單代稱直接開網址時，本外掛判斷用的頁面（跟 guard_hidden_pages() 看的 current_admin_request_slug() 同一種寫法）：
	 * admin.php?page=X → X；edit.php?post_type=X、post-new.php?post_type=X 保留 post_type；其他 .php 頁只看檔名；外掛頁的代稱照舊。
	 */
	private static function preview_page_of( string $slug ): string {
		$slug = ltrim( trim( $slug ), './' );
		if ( preg_match( '/^[a-z0-9_\-]+\.php\?(?:.*&)?page=([^&#]+)/i', $slug, $m ) ) {
			return urldecode( $m[1] );
		}
		if ( false === strpos( $slug, '.php' ) ) {
			return $slug;
		}
		$file = explode( '?', $slug, 2 )[0];
		if ( in_array( $file, [ 'edit.php', 'post-new.php' ], true ) && preg_match( '/[?&]post_type=([a-z0-9_\-]+)/i', $slug, $m ) && 'post' !== strtolower( $m[1] ) ) {
			return $file . '?post_type=' . strtolower( $m[1] );
		}
		return $file;
	}

	/**
	 * v1.2.0：sanitize 匯入的原生選單樣式 config（白名單鍵 + 型別）。
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private static function sanitize_theme_config( array $raw ): array {
		$out = [];

		$out['enabled'] = ! empty( $raw['enabled'] );

		$color_keys = [
			'admin_main_bg', 'menu_bg', 'menu_text_color', 'menu_icon_color', 'admin_bar_bg',
			'admin_bar_text_color', 'submenu_bg', 'submenu_text_color', 'menu_hover_bg', 'menu_hover_text_color',
		];
		foreach ( $color_keys as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$clean = sanitize_hex_color( (string) $raw[ $key ] );
				if ( $clean ) {
					$out[ $key ] = $clean;
				}
			}
		}

		// 允許留空（繼承/透明）的顏色
		$optional_color_keys = [ 'section_label_color', 'section_label_bg', 'submenu_hover_bg', 'opensub_bg', 'current_bg' ];
		foreach ( $optional_color_keys as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$clean = sanitize_hex_color( (string) $raw[ $key ] );
				$out[ $key ] = is_string( $clean ) ? $clean : '';
			}
		}

		// 數值鍵：直接吸收為 float/int（renderer 於輸出時 clamp，故此處只需為數值）
		$numeric_keys = [
			'menu_font_size', 'menu_line_height', 'submenu_font_size', 'submenu_line_height', 'submenu_font_weight',
			'submenu_indent', 'section_label_font_size', 'section_label_font_weight',
			'section_label_padding_top', 'section_label_padding_right', 'section_label_padding_bottom', 'section_label_padding_left',
			'section_label_margin_top', 'section_label_margin_right', 'section_label_margin_bottom', 'section_label_margin_left',
			'menu_item_padding_top', 'menu_item_padding_right', 'menu_item_padding_bottom', 'menu_item_padding_left',
			'submenu_item_padding_top', 'submenu_item_padding_right', 'submenu_item_padding_bottom', 'submenu_item_padding_left',
		];
		foreach ( $numeric_keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$out[ $key ] = 0 + $raw[ $key ];
			}
		}

		return $out;
	}

	/**
	 * v1.2.0：sanitize + 儲存匯入的白牌 options。
	 *
	 * @param array<string, mixed> $raw
	 */
	private static function import_whitelabel_options( array $raw ): void {
		$wl = '\YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin';

		if ( isset( $raw[ $wl::OPTION_LOGO_URL ] ) ) {
			update_option( $wl::OPTION_LOGO_URL, esc_url_raw( (string) $raw[ $wl::OPTION_LOGO_URL ] ), true );
		}
		if ( isset( $raw[ $wl::OPTION_FOOTER_TEXT ] ) ) {
			update_option( $wl::OPTION_FOOTER_TEXT, wp_kses_post( (string) $raw[ $wl::OPTION_FOOTER_TEXT ] ), true );
		}
		if ( isset( $raw[ $wl::OPTION_ADMIN_BG_COLOR ] ) ) {
			$clean = sanitize_hex_color( (string) $raw[ $wl::OPTION_ADMIN_BG_COLOR ] );
			update_option( $wl::OPTION_ADMIN_BG_COLOR, is_string( $clean ) ? $clean : '', true );
		}
		foreach ( [ $wl::OPTION_HIDE_FOOTER, $wl::OPTION_HIDE_WP_LOGO, $wl::OPTION_PURGE_ON_UNINSTALL ] as $bool_key ) {
			if ( isset( $raw[ $bool_key ] ) ) {
				$val = $raw[ $bool_key ];
				$on  = ( 'yes' === $val ) || ( true === $val ) || ( 1 === $val ) || ( '1' === $val );
				update_option( $bool_key, $on ? 'yes' : 'no', true );
			}
		}
	}

	/**
	 * Permission callback — 統一走 YSRestAuth（v2.52.22）
	 *
	 * @deprecated 2.52.22 路由已直接掛 YSRestAuth::permission_admin()
	 *             （manage_options floor + nonce + request body size cap）。
	 *             先前自行實作的 cap+nonce 檢查缺 body size cap；本方法保留為
	 *             相容 shim，行為等同 permission_admin。
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function check_admin( \WP_REST_Request $request ) {
		return YSRestAuth::permission_admin( $request );
	}

	/**
	 * GET /admin/permissions/menu-config
	 *
	 * 回傳完整 ys_admin_menu_config（讓 JS 把已存項目套回 UI）
	 */
	public static function get_menu_config( \WP_REST_Request $request ): \WP_REST_Response {
		$config = YSMenuRouter::get_config();
		return new \WP_REST_Response(
			[
				'success' => true,
				'config'  => $config,
			],
			200
		);
	}

	/**
	 * POST /admin/permissions/menu-config
	 *
	 * Payload 結構（JSON body，per-tab slice）：
	 *
	 *   { "wp_native": { "items": [ ... ] } }
	 *   { "ys_cart":   { "items": [ ... ], "hide_for_user_ids": [5,7] } }
	 *   { "user_overrides": { ... } }  // future extension
	 *   { "top_copies": { "items": [ { "slug", "parent_slug" } ], "rendered": [ ... ] } }  // v1.3.0
	 *   { "top_rows":   { "items": [ { "slug":"./…"|promoted_url, "after", "title_override", "icon", "roles", "hide", "self_only_uid" } ], "rendered": [ ... ] } }  // v1.3.2
	 *
	 * 採 partial-merge：每次只覆寫提交的 tab，其他 slice 不動。
	 */
	public static function save_menu_config( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'error' => 'invalid_payload' ],
				400
			);
		}

		// v1.5.0：頁面帶著開頁當下的選單搬家標記（隱藏欄位）。跟目前的標記不同＝這一頁是在 YS CART 選單搬家遷移之前
		// 打開的，照舊分組畫的表格存回去會把搬好的設定寫回舊分組，所以不存，請管理員重新整理。沒有 Core moves 的站兩邊都是空字串。
		$token = isset( $payload['moves_token'] ) && is_string( $payload['moves_token'] ) ? $payload['moves_token'] : '';
		if ( ! hash_equals( YSMenuMovesMigrator::page_token( true ), $token ) ) {
			return new \WP_REST_Response(
				[
					'success' => false,
					'code'    => 'ys_admin_menu_stale_page',
					'error'   => '選單設定剛配合新版 YS CART 調整過，請重新整理這一頁再儲存。',
				],
				409
			);
		}

		$existing = YSMenuRouter::get_config();
		$existing['wp_native'] = (array) ( $existing['wp_native'] ?? [] );
		$existing['ys_cart']   = (array) ( $existing['ys_cart'] ?? [] );

		// wp_native slice
		// v1.7.0：每一列先跟已存的同一列合併不認得的欄位（keep_unknown_fields()），例如 YS CART V3 寫的 visibility。
		$synced = []; // v1.7.0：這次送出的列（「僅限本人」兩個分頁一致用，sync_self_only()）。
		if ( isset( $payload['wp_native'] ) && is_array( $payload['wp_native'] ) ) {
			$slice = $payload['wp_native'];
			if ( isset( $slice['items'] ) && is_array( $slice['items'] ) ) {
				$stored                         = (array) ( $existing['wp_native']['items'] ?? [] );
				$submitted                      = self::sanitize_native_items( $slice['items'] );
				$synced[]                       = $submitted;
				$existing['wp_native']['items'] = self::merge_unrendered_items(
					self::keep_unknown_fields( $submitted, $stored, 'wp_native' ),
					$stored,
					$slice['rendered_slugs'] ?? null
				);
			}
		}

		// v1.7.0：主選單分頁的預設行為（新出現的頂層選單、改名時頁面標題也跟著改）。都沒選就拿掉這個鍵。
		if ( isset( $payload['defaults'] ) && is_array( $payload['defaults'] ) ) {
			$defaults = self::sanitize_defaults( $payload['defaults'] );
			if ( [] === $defaults ) {
				unset( $existing['defaults'] );
			} else {
				$existing['defaults'] = $defaults;
			}
		}

		// v1.7.0：自訂連結。只動頁面載入時列出的（rendered），其餘原樣保留。
		if ( isset( $payload['custom_items'] ) && is_array( $payload['custom_items'] )
			&& isset( $payload['custom_items']['items'] ) && is_array( $payload['custom_items']['items'] ) ) {
			$custom = self::merge_unrendered_custom(
				self::sanitize_custom_items( $payload['custom_items']['items'] ),
				self::sanitize_custom_items( (array) ( $existing['custom_items']['items'] ?? [] ) ),
				$payload['custom_items']['rendered'] ?? null
			);
			if ( [] === $custom ) {
				unset( $existing['custom_items'] );
			} else {
				$existing['custom_items'] = [ 'items' => $custom ];
			}
		}

		// ys_cart slice
		if ( isset( $payload['ys_cart'] ) && is_array( $payload['ys_cart'] ) ) {
			$slice = $payload['ys_cart'];
			if ( isset( $slice['items'] ) && is_array( $slice['items'] ) ) {
				$stored                       = (array) ( $existing['ys_cart']['items'] ?? [] );
				$submitted                    = self::sanitize_ys_cart_items( $slice['items'] );
				$synced[]                     = $submitted;
				$existing['ys_cart']['items'] = self::merge_unrendered_items(
					self::keep_unknown_fields( $submitted, $stored, 'ys_cart' ),
					$stored,
					$slice['rendered_slugs'] ?? null
				);
			}
			if ( isset( $slice['hide_for_user_ids'] ) ) {
				$existing['ys_cart']['hide_for_user_ids'] = self::sanitize_user_id_list( $slice['hide_for_user_ids'] );
			}
		}

		// v1.3.0：子選單「複製到主選單」（top_copies）。兩個分頁都會送：「全部選單」新增與刪除、
		// 「主選單（頂層）」刪除。只動頁面載入時列出的複製項（rendered），其餘原樣保留；
		// 刪掉的複製項，它在主選單分頁的設定列（排序、名稱、圖示…）一併清掉。
		if ( isset( $payload['top_copies'] ) && is_array( $payload['top_copies'] )
			&& isset( $payload['top_copies']['items'] ) && is_array( $payload['top_copies']['items'] ) ) {
			$slice  = $payload['top_copies'];
			$stored = self::sanitize_top_copies( (array) ( $existing['top_copies']['items'] ?? [] ) );
			$merged = self::merge_unrendered_copies(
				self::sanitize_top_copies( $slice['items'] ),
				$stored,
				$slice['rendered'] ?? null
			);

			$existing['top_copies'] = [ 'items' => $merged ];
			if ( ! empty( $existing['wp_native']['items'] ) ) {
				$existing['wp_native']['items'] = self::drop_removed_copy_rows( (array) $existing['wp_native']['items'], $stored, $merged );
			}
		}

		// v1.3.2：「全部選單（含子選單）」分頁把複製列、已移到主選單的列當頂層列直接拖拉與改設定，
		// 用 top_rows 把它們的位置與設定寫回 wp_native.items，不動真正的頂層列。
		if ( isset( $payload['top_rows'] ) && is_array( $payload['top_rows'] )
			&& isset( $payload['top_rows']['items'] ) && is_array( $payload['top_rows']['items'] ) ) {
			$slice                          = $payload['top_rows'];
			$rendered                       = is_array( $slice['rendered'] ?? null ) ? $slice['rendered'] : [];
			$stored                         = (array) ( $existing['wp_native']['items'] ?? [] );
			$existing['wp_native']['items'] = self::merge_top_rows(
				$stored,
				self::keep_unknown_fields( self::sanitize_native_items( $slice['items'] ), $stored, 'wp_native' ),
				$rendered
			);
		}

		// user_overrides slice
		// v2.39.0 BATCH Q1：支援兩種 payload 結構
		//   - 新（v2.39+）：{ user_overrides: { items: [ { user_id, visible_slugs, hide_ys_cart }, ... ] } }
		//   - 舊（legacy）：{ user_overrides: { "5": { visible_slugs: [...] }, ... } }
		// 兩者都存進 $existing['user_overrides']['items'] 統一格式
		if ( isset( $payload['user_overrides'] ) && is_array( $payload['user_overrides'] ) ) {
			$uo_payload = $payload['user_overrides'];
			$existing['user_overrides'] = (array) ( $existing['user_overrides'] ?? [] );

			if ( isset( $uo_payload['items'] ) && is_array( $uo_payload['items'] ) ) {
				// 新 schema
				$existing['user_overrides']['items'] = self::sanitize_user_override_items( $uo_payload['items'] );
			} elseif ( ! empty( $uo_payload ) ) {
				// 舊 schema → 升級成新 items[]
				$existing['user_overrides']['items'] = self::upgrade_legacy_user_overrides( $uo_payload );
			}

			// 同步維持 v2.37.1 legacy 路徑（讓 YSMenuRouter 既有邏輯也能 fallback；
			// 待未來 router 完全切到 user_overrides.items 後可移除）
			$legacy_map = [];
			foreach ( $existing['user_overrides']['items'] ?? [] as $row ) {
				$uid = (int) ( $row['user_id'] ?? 0 );
				if ( $uid > 0 && ! empty( $row['visible_slugs'] ) ) {
					$legacy_map[ (string) $uid ] = [ 'visible_slugs' => $row['visible_slugs'] ];
				}
			}
			$existing['wp_native']['user_overrides'] = $legacy_map;
		}

		// v1.7.0：「僅限本人」兩個分頁一致（這次送出的列，同一個選單在另一個分頁的列改成同一個帳號）。
		foreach ( $synced as $rows ) {
			$existing = self::sync_self_only( $existing, $rows );
		}

		YSMenuConfigBridge::save_authoritative( self::stamp_schema( $existing ) );

		return new \WP_REST_Response(
			[ 'success' => true, 'saved_at' => time() ],
			200
		);
	}

	/**
	 * v1.7.0：已存列上「清理程式不認得的欄位」原樣保留，認得的欄位一律用這次送來的值。
	 *
	 * sanitize_native_items()／sanitize_ys_cart_items() 用白名單重建每一列；1.6.0 以前按一次儲存，已存列上別人寫的欄位
	 * （例如 YS CART V3 寫進設定的可見規則 visibility）、之後版本新增的欄位都會被刪掉。這裡以「同一列」比對：
	 *   - wp_native：slug_key( slug )
	 *   - ys_cart：slug_key( slug )＋level＋slug_key( parent_slug )
	 * 分組列不處理（沒有規則欄位）。同一列在已存資料裡出現多次時，取第一列的不認得欄位。
	 *
	 * @param array<int, array<string, mixed>> $submitted 這次送出的列（已清理）。
	 * @param array<int, mixed>                $stored    目前已存的列。
	 * @param string                           $section   'wp_native' | 'ys_cart'
	 * @return array<int, array<string, mixed>>
	 */
	private static function keep_unknown_fields( array $submitted, array $stored, string $section ): array {
		$known = array_flip( 'ys_cart' === $section ? self::YS_CART_FIELDS : self::NATIVE_FIELDS );
		$extra = [];
		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || empty( $row['slug'] ) || ! is_string( $row['slug'] ) ) {
				continue;
			}
			$unknown = array_diff_key( $row, $known );
			$key     = self::row_identity( $row, $section );
			if ( [] !== $unknown && ! isset( $extra[ $key ] ) ) {
				$extra[ $key ] = $unknown;
			}
		}
		if ( [] === $extra ) {
			return $submitted;
		}
		foreach ( $submitted as $i => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || empty( $row['slug'] ) ) {
				continue;
			}
			$key = self::row_identity( $row, $section );
			if ( isset( $extra[ $key ] ) ) {
				$submitted[ $i ] = $row + $extra[ $key ];
				unset( $extra[ $key ] ); // 只帶給第一列：送來的同一列有好幾筆時，不會每一筆都複製一份（設定不會被撐大）。
			}
		}
		return $submitted;
	}

	/** v1.7.0：每個分頁最多收幾列（防止異常 payload 把設定撐大；正常的站遠低於這個數）。 */
	private const MAX_ROWS = 1000;

	/** v1.7.0：keep_unknown_fields() 判斷「同一列」用的鍵。 */
	private static function row_identity( array $row, string $section ): string {
		$key = YSMenuRouter::slug_key( (string) $row['slug'] );
		if ( 'ys_cart' === $section ) {
			$parent = isset( $row['parent_slug'] ) && is_string( $row['parent_slug'] ) ? $row['parent_slug'] : '';
			$key   .= "\0" . ( 'sub' === ( $row['level'] ?? 'top' ) ? 'sub' : 'top' ) . "\0" . ( '' === $parent ? '' : YSMenuRouter::slug_key( $parent ) );
		}
		return $key;
	}

	/**
	 * 把「頁面上沒列出來」的既有設定併回這次要存的清單。
	 *
	 * 設定頁只列得出目前登入者的側欄裡有的選單。別的管理員設成「僅限本人」的列、暫時停用的外掛的
	 * 選單、只有其他角色才有的選單（例如非管理員的「個人資料」）都不在頁面上；整段取代的話，
	 * 按一次儲存它們的設定就消失。這裡只保留「頁面載入時沒列出」的列——使用者自己按 × 移除的列
	 * 在載入時有列出，仍然照他的意思刪掉。
	 *
	 * @param array<int, array<string, mixed>> $submitted 這次送出的列（已清理）。
	 * @param array<int, array<string, mixed>> $stored    目前已存的列。
	 * @param mixed                            $rendered  頁面載入時列出的全部 slug；沒帶就維持整段取代（匯入、舊版頁面）。
	 * @return array<int, array<string, mixed>>
	 */
	private static function merge_unrendered_items( array $submitted, array $stored, $rendered ): array {
		if ( ! is_array( $rendered ) ) {
			return $submitted;
		}

		// v1.5.1：以鍵比（YSMenuRouter::slug_key()）。「自訂」的 slug 帶著開頁那一頁的網址（return），存下的字串跟這次
		// 列出的不一定一樣；只比字串的話，舊的那一列會被當成「頁面上沒列出」一直留著。
		$known = [];
		foreach ( $rendered as $slug ) {
			if ( is_string( $slug ) && '' !== $slug ) {
				$known[ YSMenuRouter::slug_key( $slug ) ] = true;
			}
		}
		foreach ( $submitted as $row ) {
			if ( empty( $row['separator'] ) && isset( $row['slug'] ) ) {
				$known[ YSMenuRouter::slug_key( (string) $row['slug'] ) ] = true;
			}
		}

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || empty( $row['slug'] ) ) {
				continue;
			}
			if ( ! isset( $known[ YSMenuRouter::slug_key( (string) $row['slug'] ) ] ) ) {
				$submitted[] = $row;
			}
		}

		return $submitted;
	}

	/**
	 * v1.3.0：「複製到主選單」的清單——同 merge_unrendered_items 的做法，頁面上沒列出的複製項原樣保留。
	 *
	 * 「全部選單」分頁列出全部複製項；「主選單（頂層）」分頁只列出目前登入者側欄裡有的複製項
	 * （原子選單暫時不在、設了僅限本人給別人的不會列出）。以複製項的選單代稱（copy_menu_key）比對。
	 *
	 * @param array<int, array{slug:string,parent_slug:string}> $submitted 這次送出的複製項（已清理）。
	 * @param array<int, array{slug:string,parent_slug:string}> $stored    目前已存的複製項（已清理）。
	 * @param mixed                                             $rendered  頁面載入時列出的複製項；沒帶就整份取代。
	 * @return array<int, array{slug:string,parent_slug:string}>
	 */
	private static function merge_unrendered_copies( array $submitted, array $stored, $rendered ): array {
		if ( ! is_array( $rendered ) ) {
			return $submitted;
		}

		// v1.5.1：選單代稱也以鍵比（同 merge_unrendered_items()）。
		$known = [];
		foreach ( array_merge( self::sanitize_top_copies( $rendered ), $submitted ) as $row ) {
			$known[ YSMenuRouter::slug_key( YSMenuRouter::copy_menu_key( $row['slug'], $row['parent_slug'] ) ) ] = true;
		}
		foreach ( $stored as $row ) {
			if ( ! isset( $known[ YSMenuRouter::slug_key( YSMenuRouter::copy_menu_key( $row['slug'], $row['parent_slug'] ) ) ] ) ) {
				$submitted[] = $row;
			}
		}

		return self::sanitize_top_copies( $submitted );
	}

	/**
	 * v1.3.0：刪掉的複製項，它在「主選單（頂層）」分頁的設定列一併拿掉（重新複製時從預設值開始）。
	 *
	 * @param array<int, mixed>                                 $native_items wp_native.items[]
	 * @param array<int, array{slug:string,parent_slug:string}> $before       儲存前的複製項。
	 * @param array<int, array{slug:string,parent_slug:string}> $after        儲存後的複製項。
	 * @return array<int, mixed>
	 */
	private static function drop_removed_copy_rows( array $native_items, array $before, array $after ): array {
		// v1.5.1：以鍵比（同 merge_unrendered_items()）。
		$kept = [];
		foreach ( $after as $row ) {
			$kept[ YSMenuRouter::slug_key( YSMenuRouter::copy_menu_key( $row['slug'], $row['parent_slug'] ) ) ] = true;
		}
		$removed = [];
		foreach ( $before as $row ) {
			$key = YSMenuRouter::slug_key( YSMenuRouter::copy_menu_key( $row['slug'], $row['parent_slug'] ) );
			if ( '' !== $key && ! isset( $kept[ $key ] ) ) {
				$removed[ $key ] = true;
			}
		}
		if ( empty( $removed ) ) {
			return $native_items;
		}

		return array_values(
			array_filter(
				$native_items,
				static function ( $row ) use ( $removed ): bool {
					return ! ( is_array( $row ) && empty( $row['separator'] ) && isset( $removed[ YSMenuRouter::slug_key( (string) ( $row['slug'] ?? '' ) ) ] ) );
				}
			)
		);
	}

	/**
	 * v1.3.0：Sanitize「複製到主選單」清單（top_copies.items[]）。
	 *
	 * 每一筆只存來源：{ slug, parent_slug }（&amp; 還原成 &，與側欄比對時一致）。同一個子選單只留
	 * 第一筆（選單代稱相同即視為同一個，與 Admin Menu Editor 頂層連結不得重複的規則一致）；
	 * 代稱組不出安全連結的（含引號、角括號、空白）丟掉；最多 MAX_TOP_COPIES 筆。
	 *
	 * @param array<int, mixed> $raw
	 * @return array<int, array{slug:string,parent_slug:string}>
	 */
	public static function sanitize_top_copies( array $raw ): array {
		$out  = [];
		$seen = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug   = isset( $row['slug'] ) && is_scalar( $row['slug'] ) ? wp_specialchars_decode( sanitize_text_field( (string) $row['slug'] ) ) : '';
			$parent = isset( $row['parent_slug'] ) && is_scalar( $row['parent_slug'] ) ? wp_specialchars_decode( sanitize_text_field( (string) $row['parent_slug'] ) ) : '';
			$key    = YSMenuRouter::copy_menu_key( $slug, $parent );
			// v1.5.1：同一個複製項以鍵判斷（YSMenuRouter::slug_key()）。
			if ( '' === $key || isset( $seen[ YSMenuRouter::slug_key( $key ) ] ) ) {
				continue;
			}
			$seen[ YSMenuRouter::slug_key( $key ) ] = true;
			$out[]        = [
				'slug'        => $slug,
				'parent_slug' => $parent,
			];
			if ( count( $out ) >= self::MAX_TOP_COPIES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * GET /admin/permissions/menu-enumeration
	 *
	 * 回傳所有可被「管理」的 menu slug。
	 *
	 * 為了確保 $menu 已 populate，先檢查；空就 require admin/menu.php 並
	 * 主動 do_action( 'admin_menu' )，避免 REST 請求時拿到空陣列。
	 */
	public static function enumerate_menus( \WP_REST_Request $request ): \WP_REST_Response {
		self::ensure_admin_menu_populated();

		return new \WP_REST_Response(
			[
				'success'           => true,
				'wp_native_top'     => self::get_all_admin_menu_items(),
				'ys_cart_endpoints' => self::get_all_ys_cart_endpoints(),
			],
			200
		);
	}

	/**
	 * 嘗試確保全域 $menu / $submenu 已載入
	 *
	 * REST API 預設不載入 wp-admin context，$menu 通常為空。
	 * 我們需要動態列舉 sidebar 內容，所以主動 trigger admin_menu hook。
	 */
	private static function ensure_admin_menu_populated(): void {
		global $menu, $submenu;

		if ( ! empty( $menu ) ) {
			return;
		}

		// 載入 admin menu 系統檔案（含 $menu / $submenu globals 與相關 helpers）
		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// 在某些 minimal REST context 下，需要載入更多 admin 檔案
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		// 觸發所有 plugin 的 admin_menu hook（這是 $menu populate 的關鍵）
		// 注意：do_action 之後 $menu 和 $submenu 才會由各 plugin 的 add_menu_page() 建立
		if ( did_action( 'admin_menu' ) === 0 ) {
			do_action( '_admin_menu' );
			do_action( 'admin_menu' );
		}
	}

	/**
	 * 列舉 sidebar 中所有 top-level menu
	 *
	 * 來源 = WP 全域 $menu。包含 WP-core（文章/媒體/...）+
	 * 所有 plugin 註冊的 top-level（YS CART 自家 + 第三方）。
	 *
	 * 跳過 separator（slug 'separator1'/'separator2' 等）— 但保留在 sidebar
	 * 視覺上有意義的真實 menu items，使 admin 能完整管理。
	 *
	 * @return array<int, array{slug:string,title:string,icon:string,position:mixed}>
	 */
	/**
	 * v1.7.0：列舉用的側欄：目前帳號的側欄，加上這次被「誰看得到」拿掉的項目（標 access_hidden）。設定頁要列得出來，
	 * 管理員把自己排除之後才改得回去；它們接在最後面（表格照已存的順序排）。
	 *
	 * @return array{0:array<int|string,mixed>,1:array<string,mixed>}
	 */
	private static function listing_menus(): array {
		global $menu, $submenu;
		$m      = is_array( $menu ) ? $menu : [];
		$s      = is_array( $submenu ) ? $submenu : [];
		$hidden = YSMenuRouter::access_hidden_items();
		foreach ( $hidden['top'] as $item ) {
			$item['ys_access_hidden'] = true;
			$m[]                      = $item;
		}
		foreach ( $hidden['sub'] as $parent => $rows ) {
			foreach ( $rows as $row ) {
				$row['ys_access_hidden'] = true;
				$s[ $parent ][]          = $row;
			}
		}
		return [ $m, $s ];
	}

	public static function get_all_admin_menu_items(): array {
		[ $menu ] = self::listing_menus();

		if ( empty( $menu ) ) {
			return [];
		}

		$out = [];
		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' === $slug ) {
				continue;
			}
			// 跳過 WP 自動產生的 separator slug
			// （形如 'separator1' / 'separator2' / 'separator-last'）
			if ( 0 === strpos( $slug, 'separator' ) ) {
				continue;
			}
			// 跳過 wp-menu-separator class（保險）
			$classes = isset( $item[4] ) ? (string) $item[4] : '';
			if ( false !== strpos( $classes, 'wp-menu-separator' ) ) {
				continue;
			}
			// 跳過本外掛注入的分組標籤（帶標題的分隔列），避免被當成真實選單重複列出
			if ( 0 === strpos( $slug, 'ys-ec-sep-' ) || false !== strpos( $classes, 'ys-am-section-label' ) ) {
				continue;
			}

			$out[] = [
				'slug'     => $slug,
				'title'    => wp_strip_all_tags( (string) ( $item[0] ?? '' ) ),
				'icon'     => (string) ( $item[6] ?? '' ),
				'position' => $item[5] ?? '',
				'cap'      => (string) ( $item[1] ?? '' ), // v1.7.0：以某位使用者試算時一起看 WordPress 權限。
			] + ( empty( $item['ys_access_hidden'] ) ? [] : [ 'access_hidden' => true ] )
				// v1.7.0：本外掛加的自訂連結（YSMenuRouter::insert_custom_items()），設定頁畫成自訂連結列。
				+ ( false !== strpos( $classes, 'ys-am-custom' ) ? [ 'custom' => true ] : [] );
		}

		return $out;
	}

	/**
	 * 列舉 YS CART 自家 top-level 與所有 sub-pages
	 *
	 * 來源 = WP 全域 $menu + $submenu。先過 white-list（ys-ec-* + ys-cart-platform），
	 * 再用 $submenu[ slug ] 把每個 top-level 對應的 sub-page 接在後面，
	 * level 標記 'top' / 'sub'，parent_slug 標出層級關係。
	 *
	 * @return array<int, array{slug:string,title:string,level:string,parent_slug:?string}>
	 */
	public static function get_all_ys_cart_endpoints(): array {
		[ $menu, $submenu ] = self::listing_menus(); // v1.7.0：含這次被「誰看得到」拿掉的項目。

		$out = [];
		if ( empty( $menu ) ) {
			return $out;
		}

		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' === $slug ) {
				continue;
			}
			// 跳過 WP 自動產生的 separator（不可被管理）
			if ( 0 === strpos( $slug, 'separator' ) ) {
				continue;
			}
			$classes = isset( $item[4] ) ? (string) $item[4] : '';
			if ( false !== strpos( $classes, 'wp-menu-separator' ) ) {
				continue;
			}
			// 跳過本外掛注入的分組標籤（帶標題的分隔列），避免被當成真實選單重複列出
			if ( 0 === strpos( $slug, 'ys-ec-sep-' ) || false !== strpos( $classes, 'ys-am-section-label' ) ) {
				continue;
			}
			// v1.1.2：跳過「升頂層」的衍生頂層項——它的原始身分是子頁，
			// 由 append_promoted_endpoints 以 sub 列補回（否則 php 型 slug 與
			// 衍生頂層項同名，補回被誤判已列出，設定列消失無法取消提升）。
			if ( false !== strpos( $classes, 'ys-am-promoted' ) ) {
				continue;
			}
			// v1.3.0：跳過「複製到主選單」的頂層項——原子選單仍在原父選單底下列出，複製項由「主選單（頂層）」
			// 分頁管理（使用者覆寫的可見清單也不列：複製項跟著原子選單的權限走）。
			if ( false !== strpos( $classes, 'ys-am-copy' ) ) {
				continue;
			}

			$out[] = [
				'slug'        => $slug,
				'title'       => wp_strip_all_tags( (string) ( $item[0] ?? '' ) ),
				'level'       => 'top',
				'parent_slug' => null,
				'cap'         => (string) ( $item[1] ?? '' ),
			] + ( empty( $item['ys_access_hidden'] ) ? [] : [ 'access_hidden' => true ] );

			// 接上 sub-pages（若有）
			if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				foreach ( $submenu[ $slug ] as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$sub_slug = isset( $sub[2] ) ? (string) $sub[2] : '';
					// 跳過 parent 自身（add_menu_page + add_submenu_page 同 slug 的 case）
					if ( '' === $sub_slug || $sub_slug === $slug ) {
						continue;
					}
					$out[] = [
						'slug'        => $sub_slug,
						'title'       => wp_strip_all_tags( (string) ( $sub[0] ?? '' ) ),
						'level'       => 'sub',
						'parent_slug' => $slug,
						'cap'         => (string) ( $sub[1] ?? '' ),
					] + ( empty( $sub['ys_access_hidden'] ) ? [] : [ 'access_hidden' => true ] );
				}
			}
		}

		return self::append_promoted_endpoints( $out );
	}

	/**
	 * v1.1.0：把「已提升至頂層」的子頁補回列舉清單。
	 *
	 * 已提升的子頁在 router 執行後不存在於 $submenu（被搬去 $menu 頂層），
	 * 直接列舉會漏掉它們 → 設定頁看不到該列、也就無法取消「升頂層」。
	 * 此處從設定讀出 promoted 項，補回其原父層群組尾端（維持 level=sub、
	 * parent_slug 原值，checkbox 才能對回既有設定）。
	 *
	 * @param array<int, array{slug:string,title:string,level:string,parent_slug:?string}> $out
	 * @return array<int, array{slug:string,title:string,level:string,parent_slug:?string}>
	 */
	private static function append_promoted_endpoints( array $out ): array {
		global $menu;

		$cfg_items = (array) ( \YangSheep\AdminMenu\Menu\YSMenuRouter::get_config()['ys_cart']['items'] ?? [] );
		if ( empty( $cfg_items ) ) {
			return $out;
		}

		// v1.5.1：以鍵比（YSMenuRouter::slug_key()）：網址帶 %xx 的子選單，列舉出來的是實際字串，存下的少了 %xx。
		$listed = [];
		foreach ( $out as $row ) {
			$listed[ YSMenuRouter::slug_key( (string) $row['slug'] ) ] = true;
		}

		// parent_slug => 補回列
		$insertions = [];
		foreach ( $cfg_items as $ci ) {
			if ( ! is_array( $ci ) || empty( $ci['promote_to_top'] ) || 'sub' !== (string) ( $ci['level'] ?? '' ) ) {
				continue;
			}
			$slug   = (string) ( $ci['slug'] ?? '' );
			$parent = (string) ( $ci['parent_slug'] ?? '' );
			if ( '' === $slug || '' === $parent || isset( $listed[ YSMenuRouter::slug_key( $slug ) ] ) ) {
				continue;
			}

			// title：從提升後的頂層項（classes 含 ys-am-promoted）以 URL 對照取回。
			$title        = $slug;
			$promoted_key = YSMenuRouter::slug_key( YSMenuRouter::promoted_menu_url( $slug, $parent ) );
			foreach ( (array) $menu as $m ) {
				if ( is_array( $m )
					&& false !== strpos( (string) ( $m[4] ?? '' ), 'ys-am-promoted' )
					&& YSMenuRouter::slug_key( (string) ( $m[2] ?? '' ) ) === $promoted_key ) {
					$title = wp_strip_all_tags( (string) ( $m[0] ?? $slug ) );
					break;
				}
			}

			$insertions[ $parent ][] = [
				'slug'        => $slug,
				'title'       => $title,
				'level'       => 'sub',
				'parent_slug' => $parent,
			];
			$listed[ YSMenuRouter::slug_key( $slug ) ] = true;
		}

		if ( empty( $insertions ) ) {
			return $out;
		}

		// 找每個 parent 群組（top 列 + 其 sub 列）在清單中的最後 index，插到群組尾端。
		$last_index_for_parent = [];
		foreach ( $out as $i => $row ) {
			$p = ( 'sub' === (string) $row['level'] ) ? (string) $row['parent_slug'] : (string) $row['slug'];
			$last_index_for_parent[ $p ] = $i;
		}

		$splices = [];
		foreach ( $insertions as $parent => $rows ) {
			$at        = isset( $last_index_for_parent[ $parent ] ) ? $last_index_for_parent[ $parent ] + 1 : count( $out );
			$splices[] = [ $at, $rows ];
		}
		usort(
			$splices,
			static function ( $a, $b ) {
				return $b[0] <=> $a[0]; // 由後往前插，避免 index 位移。
			}
		);
		foreach ( $splices as $splice ) {
			array_splice( $out, $splice[0], 0, $splice[1] );
		}

		return $out;
	}

	// ─────────────────────────────────────────────
	// Sanitization helpers（從 YSPermissionAdmin v2.37.1 移植過來）
	// ─────────────────────────────────────────────

	/**
	 * Sanitize wp_native items
	 *
	 * 預期 raw shape 由 JS 送來：
	 *   [
	 *     { slug: 'index.php', title_override: '...', order: 10, color: '#0073aa', roles: ['administrator'] },
	 *     { separator: true, title: '------ 內容 ------', order: 30 },
	 *     ...
	 *   ]
	 */
	public static function sanitize_native_items( array $raw ): array {
		$out = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
			$is_separator = ! empty( $row['separator'] );
			$order        = isset( $row['order'] ) ? (int) $row['order'] : 0;

			if ( $is_separator ) {
				$title = isset( $row['title'] ) ? wp_strip_all_tags( (string) $row['title'] ) : '';
				if ( '' === $title ) {
					continue;
				}
				$out[] = [
					'separator' => true,
					'title'     => $title,
					'order'     => $order,
				];
				continue;
			}

			$slug = isset( $row['slug'] ) ? sanitize_text_field( (string) $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$color           = isset( $row['color'] ) ? sanitize_hex_color( (string) $row['color'] ) : null;
			$title_override  = isset( $row['title_override'] )
				? wp_strip_all_tags( (string) $row['title_override'] )
				: '';
			$roles = [];
			if ( isset( $row['roles'] ) && is_array( $row['roles'] ) ) {
				$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $row['roles'] ) ) ) );
			}

			// v2.39.0 BATCH Q3：hide flag（boolean）
			$hide = ! empty( $row['hide'] );

			$item = [
				'slug'           => $slug,
				'title_override' => '' !== $title_override ? $title_override : null,
				'order'          => $order,
				'color'          => $color ?: null,
				'roles'          => $roles,
				'hide'           => $hide,
				'self_only_uid'  => isset( $row['self_only_uid'] ) ? absint( $row['self_only_uid'] ) : 0,
			];

			// v1.3.0：圖示（目前只有「複製到主選單」的項目會用到）。沒設定就不存這個欄位，舊設定的形狀不變。
			$icon = isset( $row['icon'] ) && is_scalar( $row['icon'] ) ? YSMenuRouter::sanitize_menu_icon( (string) $row['icon'] ) : '';
			if ( '' !== $icon ) {
				$item['icon'] = $icon;
			}

			// v1.3.2：複製／已移到主選單的項目可以用「接在哪個頂層項後面」決定位置（after＝頂層代稱，
			// 空字串＝排在最前面）。只有送了 after 的列才存，real top 列的形狀不變。
			if ( isset( $row['after'] ) && is_string( $row['after'] ) ) {
				$item['after'] = sanitize_text_field( $row['after'] );
			}

			$out[] = self::with_row_extras( $item, $row );
		}
		return $out;
	}

	/**
	 * v1.7.0：列上的逐項目權限（access）與開新分頁（target）。沒設定就不存這兩個欄位，舊設定的形狀不變。
	 *   - access 照 YSMenuRouter::sanitize_access() 清理。清完等於「只有這些角色、管理員不受限、側欄與網址都擋」、而且這一列
	 *     沒有角色時，改存成舊的 roles：1.6.0 以前的版本也看得懂，降版不會變寬。
	 *   - target 只存 _blank（開新分頁）。
	 *
	 * @param array<string,mixed> $item 已清理的列。
	 * @param array<string,mixed> $row  送來的原始列。
	 * @return array<string,mixed>
	 */
	private static function with_row_extras( array $item, array $row ): array {
		$access = YSMenuRouter::sanitize_access( $row['access'] ?? null );
		if ( null !== $access ) {
			$only_roles = 'deny' === $access['others'] && 'exempt' === $access['super_admin'] && 'block' === $access['scope']
				&& [] !== $access['allow']['roles'] && [] === $access['allow']['users']
				&& [] === $access['deny']['roles'] && [] === $access['deny']['users'];
			if ( $only_roles && empty( $item['roles'] ) ) {
				$item['roles'] = $access['allow']['roles'];
			} else {
				$item['access'] = $access;
			}
		}
		if ( '_blank' === ( $row['target'] ?? null ) ) {
			$item['target'] = '_blank';
		}
		return $item;
	}

	/**
	 * v1.3.2：合併「複製／已移到主選單」頂層列的設定（top_rows）進 wp_native.items，不動真正的頂層列。
	 *
	 * 「全部選單（含子選單）」分頁把複製列、已移到主選單的列當成主列表裡的頂層列直接拖拉、改設定，
	 * 存檔時用這個管道把它們的位置（after）、顯示名稱、圖示、角色、隱藏、僅限本人寫回 wp_native.items
	 * 的對應列（複製項是 ./ 代稱、已移到主選單的是原生網址代稱）；真正的頂層列（有 order 的那些）原樣保留。
	 *
	 * 只動「這次頁面上列出的」代稱（rendered）：頁面上列出、但這次沒送回的＝使用者移除的，從 wp_native
	 * 移掉它的設定列；沒列出的（別的帳號看不到的複製項等）原樣保留。
	 *
	 * @param array<int, mixed>                $native_items wp_native.items[]
	 * @param array<int, array<string,mixed>>  $submitted    這次送回的頂層列設定（已 sanitize_native_items）。
	 * @param array<int, string>               $rendered     頁面載入時列出的代稱。
	 * @return array<int, mixed>
	 */
	private static function merge_top_rows( array $native_items, array $submitted, array $rendered ): array {
		// v1.5.1：以鍵比（YSMenuRouter::slug_key()）。「自訂」移到主選單後的代稱帶著開頁那一頁的網址，兩個分頁存下的
		// 字串不一樣；同一個鍵的既有列只留第一列（換成這次送回的），其餘是同一項的舊字串。
		$submitted_by_key = [];
		foreach ( $submitted as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && ! empty( $row['slug'] ) ) {
				$submitted_by_key[ YSMenuRouter::slug_key( (string) $row['slug'] ) ] = $row;
			}
		}

		$rendered_set = [];
		foreach ( $rendered as $slug ) {
			if ( is_string( $slug ) && '' !== $slug ) {
				$rendered_set[ YSMenuRouter::slug_key( $slug ) ] = true;
			}
		}

		$out  = [];
		$seen = [];
		// 先走既有列：複製／已移到主選單的列若這次有送回就用新值；頁面上列出卻沒送回＝移除。其餘原樣保留。
		foreach ( $native_items as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || empty( $row['slug'] ) ) {
				$out[] = $row;
				continue;
			}
			$key = YSMenuRouter::slug_key( (string) $row['slug'] );
			if ( isset( $submitted_by_key[ $key ] ) ) {
				if ( empty( $seen[ $key ] ) ) {
					// v1.7.0：全部選單分頁沒有顏色與順序欄（top_rows 不送），沿用主選單分頁存的值（1.3.2～1.6.0 存一次就被清掉）。
					$new = $submitted_by_key[ $key ];
					foreach ( [ 'color', 'order' ] as $field ) {
						if ( array_key_exists( $field, $row ) ) {
							$new[ $field ] = $row[ $field ];
						}
					}
					$out[]        = $new;
					$seen[ $key ] = true;
				}
				continue;
			}
			if ( isset( $rendered_set[ $key ] ) ) {
				continue; // 頁面列出、這次沒送回 → 使用者移除。
			}
			$out[] = $row; // 沒列出的原樣保留。
		}
		// 新增的（第一次複製／移到主選單的列）。
		foreach ( $submitted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || empty( $row['slug'] ) ) {
				continue;
			}
			$key = YSMenuRouter::slug_key( (string) $row['slug'] );
			if ( empty( $seen[ $key ] ) ) {
				$out[]        = $row;
				$seen[ $key ] = true;
			}
		}
		return $out;
	}

	/**
	 * Sanitize ys_cart items（無 color / 無 separator — sub-page 也允許）
	 */
	public static function sanitize_ys_cart_items( array $raw ): array {
		$out = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
			// v2.38.2：YS CART tab 也支援 separator（與 wp_native 一致）
			$is_separator = ! empty( $row['separator'] );
			$order        = isset( $row['order'] ) ? (int) $row['order'] : 0;

			if ( $is_separator ) {
				$title = isset( $row['title'] ) ? wp_strip_all_tags( (string) $row['title'] ) : '';
				if ( '' === $title ) {
					continue;
				}
				$sep = [
					'separator' => true,
					'title'     => $title,
					'order'     => $order,
				];
				// v1.7.0：子選單分組標題（「新增子選單標題」的列）：記下它在哪個上層的子選單之間。沒有 subhead 的分隔列照舊。
				if ( ! empty( $row['subhead'] ) ) {
					$sep['subhead'] = true;
					$parent         = isset( $row['parent_slug'] ) && is_scalar( $row['parent_slug'] ) ? sanitize_text_field( (string) $row['parent_slug'] ) : '';
					if ( '' !== $parent ) {
						$sep['parent_slug'] = $parent;
					}
				}
				$out[] = $sep;
				continue;
			}

			$slug = isset( $row['slug'] ) ? sanitize_text_field( (string) $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$roles = [];
			if ( isset( $row['roles'] ) && is_array( $row['roles'] ) ) {
				$roles = array_values( array_unique( array_filter( array_map( 'sanitize_key', $row['roles'] ) ) ) );
			}
			// 保留 level / parent_slug — 給後續還原 sub-page 順序時用
			$level       = isset( $row['level'] ) ? sanitize_key( (string) $row['level'] ) : 'top';
			$parent_slug = isset( $row['parent_slug'] ) && '' !== $row['parent_slug']
				? sanitize_text_field( (string) $row['parent_slug'] )
				: null;

			// v2.39.0 BATCH Q3：hide flag（boolean）
			$hide = ! empty( $row['hide'] );

			// v1.2.0：自訂顯示名稱（先前 ys_cart 遺漏此欄位 → 使用者填了必被清空）
			$title_override = isset( $row['title_override'] )
				? wp_strip_all_tags( (string) $row['title_override'] )
				: '';

			$item = [
				'slug'        => $slug,
				'order'       => $order,
				'roles'       => $roles,
				'level'       => in_array( $level, [ 'top', 'sub' ], true ) ? $level : 'top',
				'parent_slug' => $parent_slug,
				'hide'        => $hide,
				'self_only_uid'  => isset( $row['self_only_uid'] ) ? absint( $row['self_only_uid'] ) : 0,
				// v1.1.0：升頂層（僅 sub 項有效）
				'promote_to_top' => ( 'sub' === $level ) && ! empty( $row['promote_to_top'] ),
				'title_override' => '' !== $title_override ? $title_override : null,
			];
			// v1.5.0：沒有順序值的列（選單搬家時只帶規則或照預設順序的列）維持沒有順序值，匯入後才不會變成 0 排到最前面。
			// 設定頁存檔每一列都會帶順序值，不受影響。
			if ( ! isset( $row['order'] ) ) {
				unset( $item['order'] );
			}
			// v1.7.0：換到別的上層顯示（display_parent＝新上層的 slug）。已移到主選單的列不用（它已經是頂層）；沒設定就不存。
			$display_parent = isset( $row['display_parent'] ) && is_scalar( $row['display_parent'] ) ? sanitize_text_field( (string) $row['display_parent'] ) : '';
			if ( '' !== $display_parent && empty( $item['promote_to_top'] )
				&& YSMenuRouter::slug_key( $display_parent ) !== YSMenuRouter::slug_key( $slug )
				&& ( null === $parent_slug || YSMenuRouter::slug_key( $display_parent ) !== YSMenuRouter::slug_key( $parent_slug ) ) ) {
				$item['display_parent'] = $display_parent;
			}
			$out[] = self::with_row_extras( $item, $row );
		}
		return $out;
	}

	/**
	 * v2.39.0 BATCH Q1：Sanitize user_overrides items（新 schema）
	 *
	 * Payload shape:
	 *   [
	 *     { user_id: 5, user_login: 'alice', user_email: 'a@e.com',
	 *       visible_slugs: ['index.php','ys-ec-products'], hide_ys_cart: false },
	 *     ...
	 *   ]
	 *
	 * 規則：
	 *   - user_id 必須 > 0
	 *   - 若 visible_slugs 為空 → 不寫入 visible_slugs（= 不限制 whitelist 模式）
	 *   - hide_ys_cart 強制 cast bool
	 *   - user_login / user_email 用於 UI 顯示，會優先以 get_userdata 校正
	 *
	 * @return array<int, array{user_id:int,user_login:string,user_email:string,visible_slugs:array,hide_ys_cart:bool}>
	 */
	public static function sanitize_user_override_items( array $raw ): array {
		$out  = [];
		$seen = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$user_id = (int) ( $row['user_id'] ?? 0 );
			if ( $user_id <= 0 || isset( $seen[ $user_id ] ) ) {
				continue;
			}
			$seen[ $user_id ] = true;

			// 以 get_userdata 為準（避免前端 spoof user_login/email）
			$user        = get_userdata( $user_id );
			$user_login  = $user
				? (string) ( $user->user_login ?: $user->display_name )
				: sanitize_text_field( (string) ( $row['user_login'] ?? '' ) );
			$user_email  = $user
				? (string) $user->user_email
				: sanitize_email( (string) ( $row['user_email'] ?? '' ) );

			$slugs_raw = $row['visible_slugs'] ?? [];
			$slugs     = is_array( $slugs_raw )
				? array_values( array_unique( array_filter( array_map(
					static function ( $s ) { return sanitize_text_field( (string) $s ); },
					$slugs_raw
				) ) ) )
				: [];

			$out[] = [
				'user_id'       => $user_id,
				'user_login'    => $user_login,
				'user_email'    => $user_email,
				'visible_slugs' => $slugs,
				'ys_cart_only'  => ! empty( $row['ys_cart_only'] ) || ! empty( $row['hide_ys_cart'] ),
			];
		}
		return $out;
	}

	/**
	 * v2.39.0 BATCH Q1：把 v2.37.1 legacy schema upgrade 成新 items[]
	 *
	 * Legacy:
	 *   { "5": { "visible_slugs": [...] }, "7": { "visible_slugs": [...] } }
	 *
	 * @return array<int, array{user_id:int,user_login:string,user_email:string,visible_slugs:array,hide_ys_cart:bool}>
	 */
	public static function upgrade_legacy_user_overrides( array $legacy ): array {
		$items = [];
		foreach ( $legacy as $user_id_raw => $row ) {
			$user_id = absint( $user_id_raw );
			if ( $user_id <= 0 || ! is_array( $row ) ) {
				continue;
			}
			$slugs_raw = $row['visible_slugs'] ?? [];
			if ( ! is_array( $slugs_raw ) ) {
				continue;
			}
			$user        = get_userdata( $user_id );
			$items[] = [
				'user_id'       => $user_id,
				'user_login'    => $user ? (string) $user->user_login : '',
				'user_email'    => $user ? (string) $user->user_email : '',
				'visible_slugs' => array_values( array_unique( array_filter( array_map(
					static function ( $s ) { return sanitize_text_field( (string) $s ); },
					$slugs_raw
				) ) ) ),
				'ys_cart_only'  => false,
			];
		}
		return self::sanitize_user_override_items( $items );
	}

	/**
	 * Sanitize user_overrides（v2.37.1 legacy — 保留以維 BC）
	 */
	public static function sanitize_user_overrides( array $raw ): array {
		$out = [];
		foreach ( $raw as $user_id_raw => $row ) {
			$user_id = absint( $user_id_raw );
			if ( $user_id <= 0 || ! is_array( $row ) ) {
				continue;
			}
			$slugs_raw = $row['visible_slugs'] ?? [];
			if ( ! is_array( $slugs_raw ) ) {
				continue;
			}
			$slugs = array_values( array_unique( array_filter( array_map(
				static function ( $s ) {
					return sanitize_text_field( (string) $s );
				},
				$slugs_raw
			) ) ) );
			if ( empty( $slugs ) ) {
				continue;
			}
			$out[ (string) $user_id ] = [ 'visible_slugs' => $slugs ];
		}
		return $out;
	}

	/**
	 * Sanitize user-id list（接受 array 或 CSV string）
	 *
	 * @param mixed $raw
	 * @return array<int, int>
	 */
	public static function sanitize_user_id_list( $raw ): array {
		if ( is_array( $raw ) ) {
			$tokens = $raw;
		} else {
			$tokens = preg_split( '/[\s,]+/', (string) $raw ) ?: [];
		}
		$out = [];
		foreach ( $tokens as $tok ) {
			$id = absint( $tok );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
