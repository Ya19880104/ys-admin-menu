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
use YangSheep\AdminMenu\Menu\YSMenuRouter;

class YSPermissionController {

	/** REST namespace（本外掛專屬） */
	private const NAMESPACE_NAME = 'ys-admin-menu/v1';

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

		$existing = YSMenuRouter::get_config();
		$existing['wp_native'] = (array) ( $existing['wp_native'] ?? [] );
		$existing['ys_cart']   = (array) ( $existing['ys_cart'] ?? [] );

		// wp_native slice
		if ( isset( $payload['wp_native'] ) && is_array( $payload['wp_native'] ) ) {
			$slice = $payload['wp_native'];
			if ( isset( $slice['items'] ) && is_array( $slice['items'] ) ) {
				$existing['wp_native']['items'] = self::sanitize_native_items( $slice['items'] );
			}
		}

		// ys_cart slice
		if ( isset( $payload['ys_cart'] ) && is_array( $payload['ys_cart'] ) ) {
			$slice = $payload['ys_cart'];
			if ( isset( $slice['items'] ) && is_array( $slice['items'] ) ) {
				$existing['ys_cart']['items'] = self::sanitize_ys_cart_items( $slice['items'] );
			}
			if ( isset( $slice['hide_for_user_ids'] ) ) {
				$existing['ys_cart']['hide_for_user_ids'] = self::sanitize_user_id_list( $slice['hide_for_user_ids'] );
			}
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

		YSMenuConfigBridge::save_authoritative( $existing );

		return new \WP_REST_Response(
			[ 'success' => true, 'saved_at' => time() ],
			200
		);
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
	public static function get_all_admin_menu_items(): array {
		global $menu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
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
			];
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
		global $menu, $submenu;

		$out = [];
		if ( empty( $menu ) || ! is_array( $menu ) ) {
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

			$out[] = [
				'slug'        => $slug,
				'title'       => wp_strip_all_tags( (string) ( $item[0] ?? '' ) ),
				'level'       => 'top',
				'parent_slug' => null,
			];

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
					];
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

		$listed = [];
		foreach ( $out as $row ) {
			$listed[ (string) $row['slug'] ] = true;
		}

		// parent_slug => 補回列
		$insertions = [];
		foreach ( $cfg_items as $ci ) {
			if ( ! is_array( $ci ) || empty( $ci['promote_to_top'] ) || 'sub' !== (string) ( $ci['level'] ?? '' ) ) {
				continue;
			}
			$slug   = (string) ( $ci['slug'] ?? '' );
			$parent = (string) ( $ci['parent_slug'] ?? '' );
			if ( '' === $slug || '' === $parent || isset( $listed[ $slug ] ) ) {
				continue;
			}

			// title：從提升後的頂層項（classes 含 ys-am-promoted）以 URL 對照取回。
			$title        = $slug;
			$promoted_url = \YangSheep\AdminMenu\Menu\YSMenuRouter::promoted_menu_url( $slug, $parent );
			foreach ( (array) $menu as $m ) {
				if ( is_array( $m )
					&& false !== strpos( (string) ( $m[4] ?? '' ), 'ys-am-promoted' )
					&& (string) ( $m[2] ?? '' ) === $promoted_url ) {
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
			$listed[ $slug ] = true;
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

			$out[] = [
				'slug'           => $slug,
				'title_override' => '' !== $title_override ? $title_override : null,
				'order'          => $order,
				'color'          => $color ?: null,
				'roles'          => $roles,
				'hide'           => $hide,
				'self_only_uid'  => isset( $row['self_only_uid'] ) ? absint( $row['self_only_uid'] ) : 0,
			];
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
			// v2.38.2：YS CART tab 也支援 separator（與 wp_native 一致）
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

			$out[] = [
				'slug'        => $slug,
				'order'       => $order,
				'roles'       => $roles,
				'level'       => in_array( $level, [ 'top', 'sub' ], true ) ? $level : 'top',
				'parent_slug' => $parent_slug,
				'hide'        => $hide,
				'self_only_uid'  => isset( $row['self_only_uid'] ) ? absint( $row['self_only_uid'] ) : 0,
				// v1.1.0：升頂層（僅 sub 項有效）
				'promote_to_top' => ( 'sub' === $level ) && ! empty( $row['promote_to_top'] ),
			];
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
