<?php
/**
 * 後台選單路由器（v2.39.0 BATCH Q3）
 *
 * 在 admin_menu hook（priority 9999，AFTER all add_menu_page 呼叫）讀取
 * `ys_admin_menu_config` JSON option，套用於 WordPress 全域 $menu / $submenu：
 *
 *   1. 若使用者 ID 在 ys_cart.hide_for_user_ids → 移除所有 ys-* 開頭的頂層選單
 *   2. 套用 wp_native.items 的 role-based 過濾
 *   3. 套用 wp_native.user_overrides — 指定使用者只看到允許的 slug
 *   4. **v2.39.0 Q3**：套用 items[].hide=true → 從 sidebar 完全移除（含 sub-page）
 *   5. 依 wp_native.items[].order 重排頂層選單順序
 *   6. 注入「空白標題」分隔列（separator items）
 *   7. 透過 admin_head echo inline CSS 套用顏色
 *   8. **v2.39.0 Q3**：admin_init 攔截 ?page= 直接訪問 hidden slugs（非 super-admin → 404）
 *   9. **v2.39.0 Q1**：套用 user_overrides.items[]（含 hide_ys_cart 完全隱藏）
 *
 * Schema 範例見 YSPermissionAdmin phpdoc。
 *
 * 容錯設計：
 *   - 沒有 config → 不動 $menu（pass-through）
 *   - super-admin（is_super_admin）：永遠不被 hide_for_user_ids / hide / user_overrides 影響
 *   - color sanitize via sanitize_hex_color
 *   - slug sanitize via sanitize_html_class
 *   - REST / WP-CLI / cron 上下文：guard_hidden_pages 自動 bypass
 *
 * @package YangSheep\AdminMenu\Menu
 * @since   2.37.1
 * @updated 2.39.0
 */

namespace YangSheep\AdminMenu\Menu;

defined( 'ABSPATH' ) || exit;

class YSMenuRouter {

	/** wp_options key — 選單設定（JSON / array） */
	public const OPTION_KEY = 'ys_admin_menu_config';

	/**
	 * 註冊 hook
	 */
	public static function register(): void {
		// admin_menu 預設 priority 為 10；此處用 9999 確保 AFTER 全部 add_menu_page
		add_action( 'admin_menu', [ self::class, 'apply_config' ], 9999 );
		add_action( 'admin_head', [ self::class, 'output_colors' ] );
		add_action( 'admin_head', [ self::class, 'output_section_label_css' ] );
		// v2.39.0 BATCH Q3：擋直接訪問 hidden slugs（非 super-admin）
		add_action( 'admin_init', [ self::class, 'guard_hidden_pages' ] );
		// v1.1.0：造訪「已提升至頂層」的頁面時，把側欄高亮指到提升後的頂層項。
		add_filter( 'parent_file', [ self::class, 'fix_promoted_highlight' ] );
	}

	/**
	 * 輸出「分組標籤」CSS（帶標題的分隔列）。
	 *
	 * 無條件輸出（不依賴原生選單樣式皮膚是否啟用），讓 .ys-am-section-label
	 * 呈現為不可點的灰色分組小標，而非可點的選單項。
	 */
	public static function output_section_label_css(): void {
		$s     = \YangSheep\AdminMenu\Theme\YSAdminThemeRenderer::get_section_label_style();
		$fs    = $s['font_size'];
		$fw    = $s['font_weight'];
		$pt    = $s['padding_top'];
		$pr    = $s['padding_right'];
		$pb    = $s['padding_bottom'];
		$pl    = $s['padding_left'];
		$mt    = $s['margin_top'];
		$mr    = $s['margin_right'];
		$mb    = $s['margin_bottom'];
		$ml    = $s['margin_left'];
		$color = $s['color'];
		$bg    = $s['bg'];

		// 文字色：有設定＝該色不透明；未設定＝繼承選單色並淡化。
		$color_rule = '' !== $color ? 'color:' . $color . ' !important;opacity:1 !important;' : 'opacity:.5 !important;';
		$bg_rule    = '' !== $bg ? 'background:' . $bg . ' !important;' : 'background:transparent !important;';
		// hover / focus / opensub 固定回非 hover 外觀（!important 蓋皮膚 runtime hover-bg）。
		$hover_bg  = '' !== $bg ? $bg : 'transparent';
		$hover_col = '' !== $color ? $color : 'inherit';

		echo '<style id="ys-am-section-label">'
			. '#adminmenu li.ys-am-section-label>a.menu-top{'
			. 'pointer-events:none;cursor:default;margin:' . $mt . 'px ' . $mr . 'px ' . $mb . 'px ' . $ml . 'px !important;min-height:0 !important;height:auto !important;align-items:flex-start;'
			. 'padding:' . $pt . 'px ' . $pr . 'px ' . $pb . 'px ' . $pl . 'px !important;'
			. 'font-size:' . $fs . 'px !important;font-weight:' . $fw . ' !important;letter-spacing:.06em;text-transform:uppercase;'
			. $color_rule . $bg_rule . 'box-shadow:none !important;}'
			. '#adminmenu li.ys-am-section-label>a.menu-top .wp-menu-name{' . $color_rule . '}'
			. '#adminmenu li.ys-am-section-label>a.menu-top:hover,'
			. '#adminmenu li.ys-am-section-label>a.menu-top:focus,'
			. '#adminmenu li.ys-am-section-label.opensub>a.menu-top,'
			. 'body.ys-theme-active #adminmenu li.ys-am-section-label>a.menu-top:hover,'
			. 'body.ys-theme-active #adminmenu li.ys-am-section-label>a.menu-top:focus{'
			. 'background:' . $hover_bg . ' !important;color:' . $hover_col . ' !important;box-shadow:none !important;}'
			. '#adminmenu li.ys-am-section-label .wp-menu-image,#adminmenu li.ys-am-section-label .wp-menu-arrow{display:none;}'
			. '#adminmenu li.ys-am-section-label .wp-menu-name{padding-left:0;}'
			. '</style>';
	}

	/**
	 * 取得設定（保證為 array）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config(): array {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		return $raw;
	}

	/**
	 * 套用 config 到全域 $menu / $submenu
	 */
	public static function apply_config(): void {
		global $menu;

		$config = self::get_config();
		if ( empty( $config ) ) {
			return;
		}

		$user            = wp_get_current_user();
		$current_user_id = (int) $user->ID;

		if ( ! $user || ! $user->exists() ) {
			return;
		}

		// ─────────────────────────────────────────────
		// 1. 隱藏 YS CART 給特定使用者（v2.37.1 legacy hide_for_user_ids
		//    + v2.39.0 user_overrides.items[].hide_ys_cart）
		//     super-admin 不被影響
		// ─────────────────────────────────────────────
		$user_roles = array_map( 'sanitize_key', (array) $user->roles );

		// ─────────────────────────────────────────────
		// 1.5 v1.1.0：子選單提升至頂層（promote_to_top）
		//     放在所有過濾/排序之前，讓 roles / hide / 僅限本人 /
		//     頂層排序等既有機制對提升後的頂層項一體適用。
		// ─────────────────────────────────────────────
		$ys_cart_items = (array) ( $config['ys_cart']['items'] ?? [] );
		if ( ! empty( $ys_cart_items ) ) {
			self::promote_submenus_to_top( $ys_cart_items );
		}

		// ─────────────────────────────────────────────
		// 2. WP 原生選單 — role-based 過濾
		// ─────────────────────────────────────────────
		$wp_native_items = (array) ( $config['wp_native']['items'] ?? [] );
		if ( ! empty( $wp_native_items ) ) {
			self::filter_menu_by_roles( $wp_native_items, $user_roles, $current_user_id );
		}

		// ─────────────────────────────────────────────
		// 3. WP 原生選單 — user override（白名單模式：指定 user 只看到允許的 slug）
		//    v2.39.0 Q1：優先讀新 user_overrides.items[]，回退 v2.37.1 wp_native.user_overrides
		// ─────────────────────────────────────────────
		$visible_slugs = self::resolve_visible_slugs_for_user( $config, $current_user_id );
		if ( null !== $visible_slugs && ! is_super_admin( $current_user_id ) ) {
			self::apply_user_override_whitelist( $visible_slugs );
		}

		// ─────────────────────────────────────────────
		// 4. v2.39.0 Q3：套用 hide flag — 從 sidebar 移除（含 sub-page）
		//     super-admin 不受影響
		// ─────────────────────────────────────────────
		if ( ! is_super_admin( $current_user_id ) ) {
			self::apply_hide_flags( $config );
			self::filter_menus_by_current_access();
		}

		// 5.「僅限本人」：不分 super-admin，綁定特定帳號的選單只有該帳號的 sidebar 看得到
		self::apply_self_only_filter( $config, $current_user_id );

		// ─────────────────────────────────────────────
		// 5. 重新排序頂層 + 注入分隔列
		// ─────────────────────────────────────────────
		if ( ! empty( $wp_native_items ) ) {
			self::reorder_and_insert_separators( $wp_native_items );
		}

		// ─────────────────────────────────────────────
		// 6. 重新排序子選單（sub-page）— 依「全部選單（含子選單）」tab
		//    儲存的 ys_cart.items[] 中 level=sub 項目的 order。
		//    與頂層排序獨立，即使未使用頂層 tab 也會生效。
		// ─────────────────────────────────────────────
		if ( ! empty( $ys_cart_items ) ) {
			self::reorder_submenus( $ys_cart_items );
		}
	}

	/**
	 * v1.1.0：把勾選「升頂層」的子選單提升為頂層選單。
	 *
	 * 策略（參考 Admin Menu Editor 的 original-parent URL 作法）：搬移的只是
	 * 「顯示位置」——從 $submenu[parent] 移除該列、在 $menu 尾端插入一筆頂層項，
	 * 但頂層項的連結一律以「原生註冊時的父層」組合（promoted_menu_url）。
	 * WordPress 的 page hook 名稱依請求 URL 的父層檔案推導，保持原生組合即代表
	 * callback、capability、載入流程全部照舊命中，頁面不會因搬移而失效。
	 *
	 * 提升後的頂層項會出現在「主選單（頂層）」設定清單中（enumeration 讀 live
	 * $menu），可如一般頂層項排序、套色、限制角色。
	 *
	 * @param array<int, array<string, mixed>> $ys_cart_items ys_cart.items[]
	 */
	private static function promote_submenus_to_top( array $ys_cart_items ): void {
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) {
			return;
		}

		foreach ( $ys_cart_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['promote_to_top'] ) ) {
				continue;
			}
			if ( 'sub' !== (string) ( $item['level'] ?? 'top' ) ) {
				continue;
			}
			$slug   = (string) ( $item['slug'] ?? '' );
			$parent = (string) ( $item['parent_slug'] ?? '' );
			if ( '' === $slug || '' === $parent || empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}

			// 從原父層子選單取出該列
			$row = null;
			foreach ( $submenu[ $parent ] as $idx => $sub ) {
				if ( is_array( $sub ) && (string) ( $sub[2] ?? '' ) === $slug ) {
					$row = $sub;
					unset( $submenu[ $parent ][ $idx ] );
					break;
				}
			}
			if ( null === $row ) {
				continue;
			}
			$submenu[ $parent ] = array_values( $submenu[ $parent ] );

			// 頂層 icon：沿用原父層選單的 icon，找不到用預設。
			$icon = 'dashicons-admin-generic';
			foreach ( $menu as $m ) {
				if ( is_array( $m ) && (string) ( $m[2] ?? '' ) === $parent && ! empty( $m[6] ) ) {
					$icon = (string) $m[6];
					break;
				}
			}

			$title   = (string) ( $row[0] ?? $slug );
			$id_base = sanitize_html_class( str_replace( [ '.php', '?', '=', '&' ], '-', $slug ) );

			// PHP 自動 max-int-key+1 append；之後 reorder_and_insert_separators 可依
			// 「主選單（頂層）」tab 設定的 order 重排此項。
			$menu[] = [
				$title,
				(string) ( $row[1] ?? 'read' ),
				self::promoted_menu_url( $slug, $parent ),
				(string) ( $row[3] ?? $title ),
				'menu-top ys-am-promoted menu-top-' . $id_base,
				'ys-am-promoted-' . $id_base,
				$icon,
			];
		}
	}

	/**
	 * v1.1.0：組合「已提升子選單」作為頂層項時使用的連結。
	 *
	 * - slug 含 .php（wp-admin 檔案或含 query 的 .php）→ 直接使用 slug 本身。
	 * - hook 型 slug（外掛頁）→ 以「原生父層」組 URL：父層是 .php 檔用
	 *   `{parent}?page={slug}`，否則退回 `admin.php?page={slug}`。
	 *   page hook 依此父層推導，維持原生組合＝callback 照舊命中。
	 */
	public static function promoted_menu_url( string $slug, string $parent ): string {
		if ( false !== strpos( $slug, '.php' ) ) {
			return $slug;
		}
		$base = ( false !== strpos( $parent, '.php' ) ) ? $parent : 'admin.php';
		return $base . ( ( false === strpos( $base, '?' ) ) ? '?' : '&' ) . 'page=' . $slug;
	}

	/**
	 * v1.1.0：造訪已提升頁面時，讓側欄高亮落在提升後的頂層項。
	 *
	 * WordPress 以 $parent_file 與 $menu[2] 比對決定哪個頂層項高亮；已提升頁的
	 * 請求父層仍是原生父層（original-parent URL 策略），WP 會誤高亮原父層選單。
	 * 此 filter 偵測目前請求對應某個 promoted slug 時，回傳提升項的選單 URL。
	 *
	 * @param string $parent_file WP 推導的父層檔案。
	 */
	public static function fix_promoted_highlight( $parent_file ) {
		$config = self::get_config();
		$items  = (array) ( $config['ys_cart']['items'] ?? [] );
		if ( empty( $items ) ) {
			return $parent_file;
		}

		$current = self::current_admin_request_slug();
		if ( '' === $current ) {
			return $parent_file;
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['promote_to_top'] ) ) {
				continue;
			}
			if ( 'sub' !== (string) ( $item['level'] ?? 'top' ) ) {
				continue;
			}
			$slug = (string) ( $item['slug'] ?? '' );
			if ( '' === $slug || $slug !== $current ) {
				continue;
			}

			global $submenu_file;
			$submenu_file = null; // 提升項無子選單，不高亮任何子項。

			return self::promoted_menu_url( $slug, (string) ( $item['parent_slug'] ?? '' ) );
		}

		return $parent_file;
	}

	/**
	 * 依 ys_cart.items[] 中 level=sub 項目的 order，重排各父選單底下的子選單。
	 *
	 * WordPress 以 $submenu[$parent] 的陣列順序決定側欄子選單顯示順序。每個子項
	 * 依下列排序鍵升冪重排（穩定排序，同鍵維持原相對順序）：
	 *   - 父選單自身項（slug 與 parent 相同，如『設定 → 一般』，enumeration 會略過
	 *     故永遠未列入設定）→ 置頂（維持 WordPress 慣例的首位）。
	 *   - 有設定 order 的子項 → 依該 order。
	 *   - 未列入設定的子項（稍後才安裝的外掛子頁等）→ 排在最後。
	 * 如此不論使用者設定是否涵蓋全部子項，結果都可預測：已排序的照設定、父項置頂、
	 * 尚未排序的新項沉到底部。重建為 0 起始連續索引，確保 WP 以 foreach 或 ksort
	 * 渲染都維持正確順序。
	 *
	 * @param array<int, array<string, mixed>> $ys_cart_items ys_cart.items[]
	 */
	private static function reorder_submenus( array $ys_cart_items ): void {
		global $submenu;
		if ( ! is_array( $submenu ) || empty( $submenu ) ) {
			return;
		}

		// parent_slug => [ child_slug => order ]
		$order_map = [];
		foreach ( $ys_cart_items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['separator'] ) ) {
				continue;
			}
			if ( 'sub' !== (string) ( $item['level'] ?? 'top' ) ) {
				continue;
			}
			$slug   = (string) ( $item['slug'] ?? '' );
			$parent = (string) ( $item['parent_slug'] ?? '' );
			if ( '' === $slug || '' === $parent ) {
				continue;
			}
			$order_map[ $parent ][ $slug ] = (int) ( $item['order'] ?? 0 );
		}

		if ( empty( $order_map ) ) {
			return;
		}

		foreach ( $order_map as $parent => $child_orders ) {
			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}

			$rows        = array_values( $submenu[ $parent ] );
			$needs_sort  = false;
			$decorated   = [];
			foreach ( $rows as $idx => $sub ) {
				$slug = is_array( $sub ) && isset( $sub[2] ) ? (string) $sub[2] : '';
				if ( $slug === $parent ) {
					$key = PHP_INT_MIN; // 父選單自身項（如 設定→一般）永遠置頂。
				} elseif ( isset( $child_orders[ $slug ] ) ) {
					$key        = $child_orders[ $slug ];
					$needs_sort = true;
				} else {
					$key = PHP_INT_MAX; // 未列入設定的子項排在最後。
				}
				$decorated[] = [ 'key' => $key, 'idx' => $idx, 'sub' => $sub ];
			}

			// 該 parent 沒有任何「有設定 order」的子項 → 無需重排，避免無意義變動。
			if ( ! $needs_sort ) {
				continue;
			}

			// 穩定排序：主要依 key，key 相同時退回原始索引（保留原相對順序）。
			usort(
				$decorated,
				static function ( $a, $b ) {
					return ( $a['key'] <=> $b['key'] ) ?: ( $a['idx'] <=> $b['idx'] );
				}
			);

			$submenu[ $parent ] = array_map(
				static function ( $entry ) {
					return $entry['sub'];
				},
				$decorated
			);
		}
	}

	/**
	 * v2.39.0 Q1：解析該 user 的 visible_slugs 白名單
	 *
	 * 優先順序：
	 *   1. config.user_overrides.items[].user_id == $user_id 且 visible_slugs 非空 → 用此
	 *   2. config.wp_native.user_overrides[$user_id].visible_slugs 非空 → 用此（legacy）
	 *   3. 都沒設 → return null（= 不啟用 whitelist）
	 *
	 * @return array<int, string>|null
	 */
	private static function resolve_visible_slugs_for_user( array $config, int $user_id ): ?array {
		// v2.39 新 schema 優先
		$uo_items = (array) ( $config['user_overrides']['items'] ?? [] );
		foreach ( $uo_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( (int) ( $item['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$slugs = (array) ( $item['visible_slugs'] ?? [] );
			if ( ! empty( $slugs ) ) {
				return array_values( array_map( 'sanitize_text_field', $slugs ) );
			}
			return null; // 該 user 在 items 但沒勾任何 slug → 不啟用 whitelist
		}

		// v2.37.1 legacy fallback
		$legacy = (array) ( $config['wp_native']['user_overrides'] ?? [] );
		$key    = (string) $user_id;
		if ( isset( $legacy[ $key ] ) ) {
			$slugs = (array) ( $legacy[ $key ]['visible_slugs'] ?? [] );
			if ( ! empty( $slugs ) ) {
				return array_values( array_map( 'sanitize_text_field', $slugs ) );
			}
		}
		return null;
	}

	/**
	 * 隱藏所有 ys-* 開頭的頂層選單（含本白牌頂層 ys-cart-*）
	 */

	/**
	 * 依 role 設定隱藏頂層選單項
	 *
	 * 規則：每個 wp_native.items[i] 有 roles[]；若使用者所有 role 都不在裡面，
	 * 該頂層 menu 被移除。super-admin 不受影響。
	 *
	 * @param array $items           wp_native.items
	 * @param array $user_roles      目前使用者的 role keys
	 * @param int   $current_user_id 用以判斷 super-admin
	 */
	private static function filter_menu_by_roles( array $items, array $user_roles, int $current_user_id ): void {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return;
		}
		if ( is_super_admin( $current_user_id ) ) {
			return;
		}

		// slug → allowed roles 映射
		$rule = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['separator'] ) ) {
				continue;
			}
			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}
			$roles = array_map( 'sanitize_key', (array) ( $item['roles'] ?? [] ) );
			$rule[ $slug ] = $roles;
		}

		foreach ( $menu as $position => $menu_item ) {
			if ( ! is_array( $menu_item ) ) {
				continue;
			}
			$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
			if ( '' === $slug || ! isset( $rule[ $slug ] ) ) {
				continue;
			}
			$allowed = $rule[ $slug ];
			if ( empty( $allowed ) ) {
				// 沒設 roles → 不限制
				continue;
			}
			if ( empty( array_intersect( $user_roles, $allowed ) ) ) {
				unset( $menu[ $position ] );
			}
		}
	}

	/**
	 * 套用 user override 白名單模式：只保留 $visible_slugs 列出的頂層 menu
	 */
	private static function apply_user_override_whitelist( array $visible_slugs ): void {
		global $menu;
		if ( ! is_array( $menu ) || empty( $visible_slugs ) ) {
			return;
		}
		$visible = array_flip( $visible_slugs );
		foreach ( $menu as $position => $menu_item ) {
			if ( ! is_array( $menu_item ) ) {
				continue;
			}
			$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
			if ( '' === $slug ) {
				continue;
			}
			// separator class 保留（避免破壞視覺）
			$classes = isset( $menu_item[4] ) ? (string) $menu_item[4] : '';
			if ( false !== strpos( $classes, 'wp-menu-separator' ) ) {
				continue;
			}
			if ( ! isset( $visible[ $slug ] ) ) {
				unset( $menu[ $position ] );
			}
		}
	}

	/**
	 * v2.39.0 BATCH Q3：套用 hide flag — 從 sidebar 完全移除
	 *
	 * 規則：
	 *   - wp_native.items[].hide=true → unset $menu[i]（top-level）
	 *   - ys_cart.items[].hide=true：
	 *       - level=top → unset $menu[i]（同 wp_native）
	 *       - level=sub → unset $submenu[parent_slug][i]
	 */
	/**
	 * Canonical permission decision for native WP menu, YS shell, and direct URLs.
	 */
	public static function current_user_can_access_admin_slug( string $slug, ?int $user_id = null ): bool {
		$slug = self::normalize_admin_slug( $slug );
		if ( '' === $slug ) {
			return true;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 ) {
			return true;
		}

		$config = self::get_config();

		// 「僅限本人」優先於 super-admin bypass：選單若綁定特定帳號，只有該帳號可見
		// （其他帳號含其他 super-admin 一律擋）；綁定者自己永遠可見＝不會鎖死自己。
		$self_only_uid = self::self_only_uid_for_slug( $slug, $config );
		if ( $self_only_uid > 0 ) {
			return $self_only_uid === $user_id;
		}

		if ( is_super_admin( $user_id ) || user_can( $user_id, 'manage_network' ) ) {
			return true;
		}

		if ( empty( $config ) ) {
			return true;
		}

		$user       = get_userdata( $user_id );
		$user_roles = $user ? array_map( 'sanitize_key', (array) $user->roles ) : [];

		return self::user_can_access_admin_slug( $slug, $config, $user_id, $user_roles );
	}

	public static function is_ys_cart_admin_slug( string $slug ): bool {
		$slug = self::normalize_admin_slug( $slug );
		if ( '' === $slug ) {
			return false;
		}

		if (
			class_exists( \YangSheep\Ecommerce\Permission\YSMenuRouter::class )
			&& \YangSheep\Ecommerce\Permission\YSMenuRouter::is_ys_cart_admin_slug( $slug )
		) {
			return true;
		}

		if ( self::is_ys_cart_namespace_slug( $slug ) ) {
			return true;
		}

		if ( in_array( $slug, self::external_admin_slugs(), true ) ) {
			return true;
		}

		$slugs = self::ys_cart_admin_slug_map();
		return isset( $slugs[ $slug ] );
	}

	private static function user_can_access_admin_slug( string $slug, array $config, int $user_id, array $user_roles ): bool {
		$legacy_ys_cart_only_ids = array_map( 'absint', (array) ( $config['ys_cart']['hide_for_user_ids'] ?? [] ) );
		if ( in_array( $user_id, $legacy_ys_cart_only_ids, true ) && ! self::is_ys_cart_admin_slug( $slug ) ) {
			return false;
		}

		foreach ( (array) ( $config['user_overrides']['items'] ?? [] ) as $uo ) {
			if ( ! is_array( $uo ) || (int) ( $uo['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}

			$ys_cart_only = ! empty( $uo['ys_cart_only'] ) || ! empty( $uo['hide_ys_cart'] );
			if ( $ys_cart_only && ! self::is_ys_cart_admin_slug( $slug ) ) {
				return false;
			}

			$visible_slugs = self::normalize_slug_list_for_match( (array) ( $uo['visible_slugs'] ?? [] ) );
			if ( ! empty( $visible_slugs ) && ! in_array( $slug, $visible_slugs, true ) ) {
				return false;
			}
			break;
		}

		$legacy = (array) ( $config['wp_native']['user_overrides'] ?? [] );
		$key    = (string) $user_id;
		if ( isset( $legacy[ $key ] ) && is_array( $legacy[ $key ] ) ) {
			$visible_slugs = self::normalize_slug_list_for_match( (array) ( $legacy[ $key ]['visible_slugs'] ?? [] ) );
			if ( ! empty( $visible_slugs ) && ! in_array( $slug, $visible_slugs, true ) ) {
				return false;
			}
		}

		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( (array) ( $config[ $tab ]['items'] ?? [] ) as $item ) {
				if ( ! is_array( $item ) || ! empty( $item['separator'] ) ) {
					continue;
				}
				if ( self::normalize_admin_slug( (string) ( $item['slug'] ?? '' ) ) !== $slug ) {
					continue;
				}
				if ( ! empty( $item['hide'] ) ) {
					return false;
				}
				$allowed_roles = array_values( array_filter( array_map( 'sanitize_key', (array) ( $item['roles'] ?? [] ) ) ) );
				if ( ! empty( $allowed_roles ) && empty( array_intersect( $user_roles, $allowed_roles ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	private static function is_ys_cart_namespace_slug( string $slug ): bool {
		return 'ys-admin-menu' === $slug
			|| str_starts_with( $slug, 'ys-admin-menu-' );
	}

	/**
	 * @return array<int,string>
	 */
	private static function external_admin_slugs(): array {
		return self::normalize_slug_list_for_match(
			(array) apply_filters( 'ys_admin_menu_external_admin_pages', [] )
		);
	}

	private static function filter_menus_by_current_access(): void {
		global $menu, $submenu;

		if ( is_array( $menu ) ) {
			foreach ( $menu as $position => $menu_item ) {
				if ( ! is_array( $menu_item ) ) {
					continue;
				}
				$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
				if ( '' !== $slug && ! self::current_user_can_access_admin_slug( $slug ) ) {
					unset( $menu[ $position ] );
				}
			}
		}

		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $idx => $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$slug = isset( $sub[2] ) ? (string) $sub[2] : '';
					if ( '' !== $slug && ! self::current_user_can_access_admin_slug( $slug ) ) {
						unset( $submenu[ $parent ][ $idx ] );
					}
				}
			}
		}
	}

	/**
	 * 查某 slug 是否被設為「僅限本人」，回傳綁定的 user id（0＝未設定）。
	 */
	private static function self_only_uid_for_slug( string $slug, array $config ): int {
		$slug = self::normalize_admin_slug( $slug );
		foreach ( [ 'wp_native', 'ys_cart' ] as $section ) {
			$items = $config[ $section ]['items'] ?? [];
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['slug'] ) ) {
					continue;
				}
				if ( self::normalize_admin_slug( (string) $item['slug'] ) === $slug ) {
					return (int) ( $item['self_only_uid'] ?? 0 );
				}
			}
		}
		return 0;
	}

	/**
	 * 套用「僅限本人」：綁定特定帳號的選單，從非綁定帳號（含其他 super-admin）的 sidebar 移除。
	 */
	private static function apply_self_only_filter( array $config, int $current_user_id ): void {
		global $menu, $submenu;

		$map = [];
		foreach ( [ 'wp_native', 'ys_cart' ] as $section ) {
			$items = $config[ $section ]['items'] ?? [];
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['slug'] ) ) {
					continue;
				}
				$uid = (int) ( $item['self_only_uid'] ?? 0 );
				if ( $uid > 0 ) {
					$map[ self::normalize_admin_slug( (string) $item['slug'] ) ] = $uid;
				}
			}
		}
		if ( empty( $map ) ) {
			return;
		}

		if ( is_array( $menu ) ) {
			foreach ( $menu as $position => $menu_item ) {
				if ( ! is_array( $menu_item ) ) {
					continue;
				}
				$slug = self::normalize_admin_slug( (string) ( $menu_item[2] ?? '' ) );
				if ( isset( $map[ $slug ] ) && $map[ $slug ] !== $current_user_id ) {
					unset( $menu[ $position ] );
				}
			}
		}
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $idx => $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$slug = self::normalize_admin_slug( (string) ( $sub[2] ?? '' ) );
					if ( isset( $map[ $slug ] ) && $map[ $slug ] !== $current_user_id ) {
						unset( $submenu[ $parent ][ $idx ] );
					}
				}
			}
		}
	}

	/**
	 * @return array<string,bool>
	 */
	private static function ys_cart_admin_slug_map(): array {
		$slugs = [ 'ys-admin-menu' ];

		$config = self::get_config();
		foreach ( (array) ( $config['ys_cart']['items'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['separator'] ) ) {
				continue;
			}

			$slugs[] = (string) ( $item['slug'] ?? '' );
			$slugs[] = (string) ( $item['parent_slug'] ?? '' );
		}

		$slugs = array_merge(
			$slugs,
			array_filter(
				array_map(
					static fn( $value ): string => self::normalize_admin_slug( (string) $value ),
					(array) apply_filters( 'ys_admin_menu_extra_admin_slugs', [] )
				)
			)
		);
		$slugs = array_merge( $slugs, self::external_admin_slugs() );

		$map = [];
		foreach ( $slugs as $slug ) {
			$slug = self::normalize_admin_slug( (string) $slug );
			if ( '' !== $slug ) {
				$map[ $slug ] = true;
			}
		}

		return $map;
	}

	/**
	 * @param array<int,mixed> $slugs
	 * @return array<int,string>
	 */
	private static function normalize_slug_list_for_match( array $slugs ): array {
		$out = [];
		foreach ( $slugs as $slug ) {
			$slug = self::normalize_admin_slug( (string) $slug );
			if ( '' !== $slug ) {
				$out[] = $slug;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private static function normalize_admin_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}

		return function_exists( 'sanitize_text_field' )
			? sanitize_text_field( $slug )
			: ( preg_replace( '/[^\w.\-?=&:\/]/', '', $slug ) ?: '' );
	}

	private static function apply_hide_flags( array $config ): void {
		global $menu, $submenu;

		// 收集所有 hide=true 的 slug
		$hidden_top = [];
		$hidden_sub = []; // [ slug => true ]

		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			$items = (array) ( $config[ $tab ]['items'] ?? [] );
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['hide'] ) ) {
					continue;
				}
				$slug  = (string) ( $item['slug'] ?? '' );
				$level = (string) ( $item['level'] ?? 'top' );
				if ( '' === $slug ) {
					continue;
				}
				if ( 'sub' === $level ) {
					$hidden_sub[ $slug ] = true;
				} else {
					$hidden_top[ $slug ] = true;
				}
			}
		}

		if ( empty( $hidden_top ) && empty( $hidden_sub ) ) {
			return;
		}

		// Top-level：從 $menu 移除
		if ( ! empty( $hidden_top ) && is_array( $menu ) ) {
			foreach ( $menu as $position => $menu_item ) {
				if ( ! is_array( $menu_item ) ) {
					continue;
				}
				$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
				if ( '' !== $slug && isset( $hidden_top[ $slug ] ) ) {
					unset( $menu[ $position ] );
				}
			}
		}

		// Sub-page：從 $submenu[parent] 移除
		if ( ! empty( $hidden_sub ) && is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $idx => $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$sub_slug = isset( $sub[2] ) ? (string) $sub[2] : '';
					if ( '' !== $sub_slug && isset( $hidden_sub[ $sub_slug ] ) ) {
						unset( $submenu[ $parent ][ $idx ] );
					}
				}
			}
		}
	}

	/**
	 * v2.39.0 BATCH Q3：擋直接 URL 訪問 hidden / non-whitelisted pages
	 *
	 * Hooked on admin_init。super-admin / REST / cron / WP-CLI 自動 bypass。
	 *
	 * 三層檢查（順序執行）：
	 *   1. user_overrides.items.hide_ys_cart=true → 擋所有 ys-* 開頭 slug
	 *   2. user_overrides.items.visible_slugs 非空（白名單模式）→ 不在白名單就擋
	 *   3. wp_native/ys_cart items.hide=true → 該 slug 直接 404
	 */
	public static function guard_hidden_pages(): void {
		// REST / cron / WP-CLI bypass — 完全不影響非 admin context
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// 「僅限本人」優先於 super-admin bypass：非綁定帳號（含其他 super-admin）直接擋直接訪問
		$self_page = self::current_admin_request_slug();
		if ( '' !== $self_page ) {
			$self_uid = self::self_only_uid_for_slug( $self_page, self::get_config() );
			if ( $self_uid > 0 && $self_uid !== get_current_user_id() ) {
				self::deny_access( $self_page );
			}
		}

		// super-admin bypass
		if ( current_user_can( 'manage_network' ) || is_super_admin() ) {
			return;
		}

		$page = self::current_admin_request_slug();
		if ( '' === $page ) {
			// 沒有 ?page= 參數（在 wp-admin/index.php 等）— 不需檢查
			return;
		}

		$config  = self::get_config();
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		if ( ! self::current_user_can_access_admin_slug( $page, $user_id ) ) {
			self::deny_access( $page );
		}

		// ─── Layer 1+2：user_overrides.items[] 該使用者規則 ───
		$uo_items = (array) ( $config['user_overrides']['items'] ?? [] );
		foreach ( $uo_items as $uo ) {
			if ( ! is_array( $uo ) ) {
				continue;
			}
			if ( (int) ( $uo['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}

			// Layer 1：hide_ys_cart=true → 擋所有 ys- 開頭 slug
			// Layer 2：visible_slugs 白名單模式
			$visible = (array) ( $uo['visible_slugs'] ?? [] );
			if ( ! empty( $visible ) && ! in_array( $page, $visible, true ) ) {
				self::deny_access( $page );
			}
		}

		// ─── Layer 3：wp_native + ys_cart items[].hide=true 全域生效 ───
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			$items = (array) ( $config[ $tab ]['items'] ?? [] );
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['hide'] ) ) {
					continue;
				}
				if ( ( $item['slug'] ?? '' ) === $page ) {
					self::deny_access( $page );
				}
			}
		}
	}

	/**
	 * v2.39.0 BATCH Q3：終止 request 並回 404
	 *
	 * @param string $page 用於 log / debug
	 */
	private static function current_admin_request_slug(): string {
		if ( isset( $_GET['page'] ) ) {
			return self::normalize_admin_slug( (string) wp_unslash( $_GET['page'] ) );
		}

		global $pagenow;
		$script = is_string( $pagenow ) && '' !== $pagenow
			? $pagenow
			: basename( (string) ( $_SERVER['PHP_SELF'] ?? '' ) );
		$script = self::normalize_admin_slug( $script );
		if ( '' === $script ) {
			return '';
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( '' !== $post_type && 'post' !== $post_type && in_array( $script, [ 'edit.php', 'post-new.php' ], true ) ) {
			return $script . '?post_type=' . $post_type;
		}

		return $script;
	}

	private static function deny_access( string $page ): void {
		// 留個 hook 給 dev 監控
		do_action( 'ys_admin_menu_access_denied', $page, get_current_user_id() );

		wp_die(
			esc_html__( '此頁面不存在或您無權存取。', 'ys-admin-menu' ),
			esc_html__( '404', 'ys-admin-menu' ),
			[ 'response' => 404 ]
		);
	}

	/**
	 * 重排 + 注入空白標題分隔列
	 *
	 * 規則：依照 items[i].order 升冪排列，未在 config 內的選單放最後保留原順序。
	 * separator items 以 wp-menu-separator class 注入到對應 order 位置。
	 */
	private static function reorder_and_insert_separators( array $items ): void {
		global $menu;
		if ( ! is_array( $menu ) || empty( $menu ) ) {
			return;
		}

		// 收集現有 menu items（依 slug 索引）
		$existing_by_slug = [];
		$untracked        = []; // 未被 config 列出的（保留尾巴）
		foreach ( $menu as $menu_item ) {
			if ( ! is_array( $menu_item ) ) {
				continue;
			}
			$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
			if ( '' === $slug ) {
				continue;
			}
			$existing_by_slug[ $slug ] = $menu_item;
		}

		// 排序 config items
		$sorted = $items;
		usort( $sorted, static function ( $a, $b ) {
			return ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) );
		} );

		$used_slugs = [];
		$new_menu   = [];
		$position   = 1; // wp-admin 內 position 為任意 numeric key，遞增即可

		foreach ( $sorted as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// separator → 有標題＝分組標籤（sidebar 顯示文字）；無標題＝純視覺分隔線
			if ( ! empty( $item['separator'] ) ) {
				$title        = isset( $item['title'] ) ? wp_strip_all_tags( (string) $item['title'] ) : '';
				$separator_id = 'ys-ec-sep-' . $position;
				if ( '' === $title ) {
					// 無標題：WP 原生視覺分隔線
					$new_menu[ $position ] = [ '', 'read', $separator_id, '', 'wp-menu-separator', $separator_id, '' ];
				} else {
					// 有標題：以 menu-top 結構承載標題，再由 output_section_label_css 樣式化為不可點分組小標。
					$new_menu[ $position ] = [
						$title,
						'read',
						$separator_id,
						$title,
						'menu-top ys-am-section-label',
						$separator_id,
						'',
					];
				}
				$position += 2;
				continue;
			}

			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			if ( '' === $slug || ! isset( $existing_by_slug[ $slug ] ) ) {
				continue;
			}
			$menu_item = $existing_by_slug[ $slug ];

			// 套用 title_override（若有）
			if ( ! empty( $item['title_override'] ) ) {
				$menu_item[0] = wp_strip_all_tags( (string) $item['title_override'] );
			}

			$new_menu[ $position ] = $menu_item;
			$used_slugs[ $slug ]   = true;
			$position += 2;
		}

		// 把 config 沒列到的 menu items 接在後面（保留原順序）
		foreach ( $existing_by_slug as $slug => $menu_item ) {
			if ( isset( $used_slugs[ $slug ] ) ) {
				continue;
			}
			$new_menu[ $position ] = $menu_item;
			$position += 2;
		}

		$menu = $new_menu;
	}

	/**
	 * 在 admin_head 輸出 inline CSS — 為頂層 menu 套用自訂顏色
	 *
	 * WP admin menu li 的 ID 規則：menu-{slug-without-php-suffix}
	 * 例如：'index.php' → 'menu-dashboard'（WP 內建特殊 mapping）
	 *      'edit.php'  → 'menu-posts'
	 *      'admin.php?page=ys-cart' → 'toplevel_page_ys-cart'
	 *
	 * 為了通用性，使用 [class*="ys-cart"] 與 [id*="{slug}"] selector，
	 * 套用 a 顏色與 li > a 顏色。
	 */
	public static function output_colors(): void {
		$config = self::get_config();
		$items  = (array) ( $config['wp_native']['items'] ?? [] );
		if ( empty( $items ) ) {
			return;
		}

		$rules = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['separator'] ) ) {
				continue;
			}
			$slug  = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			$color = isset( $item['color'] ) ? (string) $item['color'] : '';
			if ( '' === $slug || '' === $color ) {
				continue;
			}
			$color = sanitize_hex_color( $color );
			if ( ! $color ) {
				continue;
			}
			$id_safe = sanitize_html_class( str_replace( [ '.php', '?', '=', '&' ], '-', $slug ) );
			if ( '' === $id_safe ) {
				continue;
			}
			// 同時匹配 toplevel_page_{slug} / menu-{id_safe} 兩種 WP 命名
			$rules[] = sprintf(
				'#adminmenu li#menu-%1$s > a, #adminmenu li#toplevel_page_%1$s > a { color: %2$s !important; }',
				esc_attr( $id_safe ),
				esc_attr( $color )
			);
		}

		if ( empty( $rules ) ) {
			return;
		}

		echo "<style id=\"ys-ec-menu-colors\">\n";
		echo implode( "\n", $rules ); // already escaped above
		echo "\n</style>\n";
	}
}
