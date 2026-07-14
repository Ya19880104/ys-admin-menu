<?php
/**
 * 後台選單路由器（v2.39.0 BATCH Q3）
 *
 * 在 admin_menu hook（priority 9999，AFTER all add_menu_page 呼叫）讀取
 * `ys_ec_menu_config` JSON option，套用於 WordPress 全域 $menu / $submenu：
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
		$ys_cart_items = (array) ( $config['ys_cart']['items'] ?? [] );
		if ( ! empty( $ys_cart_items ) ) {
			self::reorder_submenus( $ys_cart_items );
		}
	}

	/**
	 * 依 ys_cart.items[] 中 level=sub 項目的 order，重排各父選單底下的子選單。
	 *
	 * WordPress 以 $submenu[$parent] 的陣列順序決定側欄子選單顯示順序；本方法
	 * 針對每個在設定中有排序資料的 parent，把其子項依儲存的 order 升冪重排。
	 * 未列入設定的子項（例如稍後才安裝的外掛新增的子頁）給最大排序值，保留原
	 * 相對順序並接在後面。排序完以 array_values 重新索引，確保不論 WP 以 foreach
	 * 或 ksort 渲染都維持正確順序。
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

			// 穩定排序（PHP 8 usort 為穩定排序）：有設定 order 的依 order 升冪；
			// 未列入設定的子項給 PHP_INT_MAX → 保留原相對順序、排在最後。
			$rows = $submenu[ $parent ];
			usort(
				$rows,
				static function ( $a, $b ) use ( $child_orders ) {
					$slug_a = is_array( $a ) && isset( $a[2] ) ? (string) $a[2] : '';
					$slug_b = is_array( $b ) && isset( $b[2] ) ? (string) $b[2] : '';
					$order_a = $child_orders[ $slug_a ] ?? PHP_INT_MAX;
					$order_b = $child_orders[ $slug_b ] ?? PHP_INT_MAX;
					return $order_a <=> $order_b;
				}
			);

			$submenu[ $parent ] = array_values( $rows );
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
		// 本外掛自己的設定頁面 slug（「受保護管理選單」集合的基底）。
		$slugs = [ 'ys-admin-menu' ];

		// 第三方可透過 filter 把自家 slug 加入受保護集合
		// （影響 per-user「僅允許指定後台」模式下哪些 slug 仍可見）。
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
