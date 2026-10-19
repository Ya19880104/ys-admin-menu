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
		// v1.1.1：hook 型提升頁，WP 核心以 $self（請求檔名）比對仍會讓原父層帶
		// current/展開 class（繞過 parent_file filter）→ footer 收斂殘留 class。
		add_action( 'admin_footer', [ self::class, 'print_promoted_highlight_fix' ], 5 );
		// v1.3.0：子選單「複製到主選單」——原位置在這個帳號的側欄裡看不到時，目前頁的高亮改標在複製項。
		// 判斷排在 fix_promoted_highlight（10）之後；實際改 class 在 footer（WordPress 在 parent_file
		// filter 之後還會用 get_admin_page_parent() 重算父選單，伺服器端改不動）。
		add_filter( 'parent_file', [ self::class, 'fix_copy_highlight' ], 11 );
		add_action( 'admin_footer', [ self::class, 'print_copy_highlight_fix' ], 5 );
	}

	/** 目前請求是否為「已提升」頁（由 fix_promoted_highlight 判定後設定）。 */
	private static bool $current_is_promoted = false;

	/**
	 * v1.3.0：本次請求實際插入側欄的「複製到主選單」項目：選單代稱（$menu[2]）=> 來源資訊。
	 * v1.5.1：source＝原子選單在側欄上實際的 slug（網址帶 %xx 時跟存下的 slug 不同；判斷目前頁用它）。
	 *
	 * @var array<string, array{id:string,slug:string,source:string,parent:string,title:string,parent_title:string,parent_icon:string}>
	 */
	private static array $copy_entries = [];

	/** v1.3.0：本次請求改標在複製項上的選單代稱（只有原位置看不到時才有值）。 */
	private static string $current_copy_key = '';

	/** v1.5.0：站上有沒有 YS CART 的選單搬家清單（V3）。null＝還沒查；apply_config()、guard_hidden_pages() 開始時重查。 */
	private static ?bool $moves_site = null;

	/**
	 * v1.3.0：過濾前的頂層選單（複製項沿用原父選單圖示與位置、設定頁顯示原位置用）：
	 * 選單代稱 => [ $menu 鍵, 圖示, 名稱 ]。
	 *
	 * @var array<string, array{key:int|string,icon:string,title:string}>
	 */
	private static array $parent_meta = [];

	/**
	 * 已提升、但暫時還留在原父層的子選單列：[ 父層 slug, 子頁 slug ]。
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private static array $promoted_rows = [];

	/**
	 * v1.5.1：這次請求移到主選單的頂層項實際用的連結：「存下的 slug \0 存下的上層」=> 連結（fix_promoted_highlight() 用）。
	 *
	 * @var array<string, string>
	 */
	private static array $promoted_links = [];

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

		// 文字色：有設定＝該色不透明；未設定＝繼承選單色並淡化。淡化只套在連結這一層，裡面的文字用
		// inherit 跟著走：兩層各套一次會變成原本的四分之一，在深色側欄上幾乎看不見。
		$color_rule = '' !== $color ? 'color:' . $color . ' !important;opacity:1 !important;' : 'opacity:.62 !important;';
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
			. '#adminmenu li.ys-am-section-label>a.menu-top .wp-menu-name{color:inherit !important;opacity:1 !important;'
			. 'font-size:inherit !important;font-weight:inherit !important;line-height:1.4 !important;}'
			. '#adminmenu li.ys-am-section-label>a.menu-top:hover,'
			. '#adminmenu li.ys-am-section-label>a.menu-top:focus,'
			. '#adminmenu li.ys-am-section-label.opensub>a.menu-top,'
			. 'body.ys-theme-active #adminmenu li.ys-am-section-label>a.menu-top:hover,'
			. 'body.ys-theme-active #adminmenu li.ys-am-section-label>a.menu-top:focus{'
			. 'background:' . $hover_bg . ' !important;color:' . $hover_col . ' !important;box-shadow:none !important;}'
			. '#adminmenu li.ys-am-section-label .wp-menu-image,#adminmenu li.ys-am-section-label .wp-menu-arrow{display:none;}'
			. '#adminmenu li.ys-am-section-label .wp-menu-name{padding-left:0;}'
			// 側欄收成圖示列時（手動收合，或 783–960px 被 WordPress 自動收合）標籤沒有文字可顯示，整列收掉。
			. '@media screen and (min-width:783px){.folded #adminmenu li.ys-am-section-label{display:none;}}'
			. '@media screen and (min-width:783px) and (max-width:960px){.auto-fold #adminmenu li.ys-am-section-label{display:none;}}'
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

		self::$moves_site = null;
		$config           = self::get_config();
		if ( empty( $config ) ) {
			return;
		}

		$user            = wp_get_current_user();
		$current_user_id = (int) $user->ID;

		if ( ! $user || ! $user->exists() ) {
			return;
		}

		// v1.5.1：「電商工具箱」的共用整理（Hub client 2.0.4 起）先在這裡跑，本外掛的設定在它之後生效。
		self::take_over_toolbox_normalizer();

		// ─────────────────────────────────────────────
		// 1. 隱藏 YS CART 給特定使用者（v2.37.1 legacy hide_for_user_ids
		//    + v2.39.0 user_overrides.items[].hide_ys_cart）
		//     super-admin 不被影響
		// ─────────────────────────────────────────────
		$user_roles = array_map( 'sanitize_key', (array) $user->roles );

		// ─────────────────────────────────────────────
		// 1.4 v1.2.0：套用「全部選單」tab 的自訂顯示名稱
		//     在 promote 之前執行 → 提升後的頂層項自動帶自訂名。
		// ─────────────────────────────────────────────
		$ys_cart_items = (array) ( $config['ys_cart']['items'] ?? [] );
		if ( ! empty( $ys_cart_items ) ) {
			self::apply_ys_cart_title_overrides( $ys_cart_items );
		}

		// ─────────────────────────────────────────────
		// 1.5 v1.1.0：子選單提升至頂層（promote_to_top）
		//     放在所有過濾/排序之前，讓 roles / hide / 僅限本人 /
		//     頂層排序等既有機制對提升後的頂層項一體適用。
		// ─────────────────────────────────────────────
		if ( ! empty( $ys_cart_items ) ) {
			self::promote_submenus_to_top( $ys_cart_items );
		}

		// 1.6 v1.3.0：記下各頂層選單的圖示與名稱。複製項預設沿用原父選單的圖示；原父選單對這個帳號
		//     隱藏時，過濾之後就查不到了，所以在過濾之前先記。
		self::remember_parent_menus( $config );

		// ─────────────────────────────────────────────
		// 2. WP 原生選單 — role-based 過濾
		// ─────────────────────────────────────────────
		$wp_native_items = (array) ( $config['wp_native']['items'] ?? [] );
		if ( ! empty( $wp_native_items ) ) {
			// v1.5.1：外部網址複製項自己的那一列只管複製項（rule_rows()），不套到同網址的其他頂層選單。
			self::filter_menu_by_roles( self::rule_rows( $config, 'wp_native' ), $user_roles, $current_user_id );
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

		// 5.5 v1.3.0：子選單「複製到主選單」（top_copies）。放在所有過濾之後：原子選單對這個帳號
		//     還在（沒被隱藏、角色、僅限本人、白名單拿掉），複製項才會出現；複製項自己的
		//     隱藏／角色／僅限本人在插入時另外判斷。
		self::insert_top_level_copies( $config, $current_user_id, $user_roles );

		// ─────────────────────────────────────────────
		// 5. 重新排序頂層 + 注入分隔列
		//    v1.3.0：還沒排過序的複製項跟著原父選單（with_copy_placeholders）。
		//    v1.3.2：「全部選單」分頁指定了位置（after＝接在哪個頂層項後面）的複製項與已移到主選單
		//            的項目不照 order 排，排完再放到指定位置（place_anchored_top_items）。設定裡沒有
		//            其他頂層列時不照 order 重排（沒用過「主選單（頂層）」分頁的站維持 WordPress 原本順序）。
		// ─────────────────────────────────────────────
		[ $ordered_items, $anchored_items ] = self::split_anchored_rows( $wp_native_items );
		if ( self::has_top_level_rows( $ordered_items ) ) {
			self::reorder_and_insert_separators( self::with_copy_placeholders( $ordered_items, $anchored_items, $ys_cart_items ) );
		}
		self::place_anchored_top_items( $anchored_items );

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
	 * v1.5.1：內附的 YS Plugin Hub Client 2.0.4 起有 YSToolboxMenuNormalizer：在 admin_menu 的最後（PHP_INT_MAX）
	 * 把「電商工具箱」改回固定的名稱、圖示與位置 56，系統資訊、聯絡我們補回並排在最後。那一步跑在本外掛
	 * （9999）之後，會蓋掉本外掛對電商工具箱的改名、排序、隱藏、角色、僅限本人與白名單。
	 *
	 * 本外掛有設定、要套用到側欄時：先在這裡跑一次（名稱、圖示、位置先照 Hub client 的共用規則，之後再照本外掛的設定），
	 * 再拿掉它最後那一步。沒有本外掛的設定、或站上的 Hub client 沒有這個類別時，什麼都不做。
	 */
	private static function take_over_toolbox_normalizer(): void {
		$class = 'YangSheep\\PluginHubClient\\Admin\\YSToolboxMenuNormalizer';
		if ( ! class_exists( $class, false ) || ! is_callable( [ $class, 'normalize' ] ) ) {
			return;
		}
		$callback = [ $class, 'normalize' ];
		$priority = has_action( 'admin_menu', $callback );
		if ( false === $priority ) {
			return;
		}
		call_user_func( $callback );
		remove_action( 'admin_menu', $callback, (int) $priority );
	}

	/**
	 * v1.2.0：套用「全部選單（含子選單）」tab 的自訂顯示名稱（title_override）。
	 *
	 * 頂層項改 $menu[i][0]；子項改 $submenu[parent][i][0]。在 promote 與
	 * wp_native 重排之前執行：提升後的頂層項會帶著自訂名，而若「主選單（頂層）」
	 * tab 對同一頂層 slug 另設了 title_override，稍後 reorder_and_insert_separators
	 * 會以該值覆蓋（頂層 tab 為頂層命名的權威）。
	 *
	 * @param array<int, array<string, mixed>> $ys_cart_items ys_cart.items[]
	 */
	private static function apply_ys_cart_title_overrides( array $ys_cart_items ): void {
		global $menu, $submenu;

		foreach ( $ys_cart_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['title_override'] ) ) {
				continue;
			}
			$slug = (string) ( $item['slug'] ?? '' );
			$name = wp_strip_all_tags( (string) $item['title_override'] );
			if ( '' === $slug || '' === $name ) {
				continue;
			}

			// v1.5.1：有原字串完全相同的列就用它（跟 1.5.0 一樣），沒有才比鍵（matching_rows()：網址帶 %xx 的子選單、「自訂」）。
			if ( 'sub' === (string) ( $item['level'] ?? 'top' ) ) {
				$parent = self::submenu_parent( (string) ( $item['parent_slug'] ?? '' ) );
				if ( null === $parent || empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
					continue;
				}
				$hit = self::matching_rows( $submenu[ $parent ], $slug );
				if ( [] !== $hit ) {
					$submenu[ $parent ][ $hit[0] ][0] = $name;
				}
			} elseif ( is_array( $menu ) ) {
				$hit = self::matching_rows( $menu, $slug );
				if ( [] !== $hit ) {
					$menu[ $hit[0] ][0] = $name;
				}
			}
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
		self::$promoted_links = [];
		if ( ! is_array( $menu ) ) {
			return;
		}

		$promoted = []; // v1.5.1：已經移上去的列（上層 + 實際 slug）。用鍵對到的列只移一次。
		foreach ( $ys_cart_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['promote_to_top'] ) ) {
				continue;
			}
			if ( 'sub' !== (string) ( $item['level'] ?? 'top' ) ) {
				continue;
			}
			$slug          = (string) ( $item['slug'] ?? '' );
			$stored_parent = (string) ( $item['parent_slug'] ?? '' );
			$parent        = self::submenu_parent( $stored_parent );
			if ( '' === $slug || null === $parent || empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}
			// v1.5.1：外部網址的子選單一律不移（連結會組成 admin.php?page=https://…，打不開；1.1.0 起）。
			// 以前存下的設定也一樣，子選單留在原位；要放上主選單請用「複製到主選單」。
			if ( self::is_external_url( $slug ) ) {
				continue;
			}

			// 從原父層子選單取出該列（v1.5.1：原字串對不到時比鍵，matching_rows()）。
			$hit = self::matching_rows( $submenu[ $parent ], $slug );
			if ( [] === $hit ) {
				continue;
			}
			$row = $submenu[ $parent ][ $hit[0] ];
			$raw = (string) $row[2];
			if ( $raw !== $slug && isset( $promoted[ $parent . "\0" . $raw ] ) ) {
				continue; // 同一列存了兩份字串不同的設定（例如「自訂」從不同網址存過）：只移一次。
			}
			$promoted[ $parent . "\0" . $raw ] = true;
			// 這一列先留在原父層：WordPress 要靠它找回這個頁面註冊時的父層（權限檢查與 page hook
			// 都從父層推導）。父層是外掛頂層（admin.php?page=…）時，提早拿掉會讓該頁對所有人 403。
			// 輸出側欄前才由 prepare_menu_for_render() 拿掉，畫面上仍然只出現在頂層。
			self::$promoted_rows[] = [ $parent, $raw ];

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
			// v1.5.1：連結用側欄上那一列實際的 slug 與上層（存下的字串少了 %xx，組出來的網址打不開）。
			$link = self::promoted_menu_url( $raw, $parent );
			self::$promoted_links[ $slug . "\0" . $stored_parent ] = $link;

			// PHP 自動 max-int-key+1 append；之後 reorder_and_insert_separators 可依
			// 「主選單（頂層）」tab 設定的 order 重排此項。
			$menu[] = [
				$title,
				(string) ( $row[1] ?? 'read' ),
				$link,
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
	 * v1.5.1：選單代稱是不是外部網址（https:// 這類「名稱://」開頭，跟 copy_menu_key() 同一個判斷）。
	 * 外部網址的子選單不移到主選單（promote_submenus_to_top()），設定頁也不提供「移到主選單」。
	 */
	public static function is_external_url( string $slug ): bool {
		return 1 === preg_match( '#^[a-z][a-z0-9+.\-]*://#i', trim( wp_specialchars_decode( $slug ) ) );
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
			if ( '' === $slug || self::slug_key( $slug ) !== $current ) {
				continue;
			}

			global $submenu_file;
			$submenu_file = null; // 提升項無子選單，不高亮任何子項。

			self::$current_is_promoted = true;

			$parent = (string) ( $item['parent_slug'] ?? '' );
			return self::$promoted_links[ $slug . "\0" . $parent ] ?? self::promoted_menu_url( $slug, $parent );
		}

		return $parent_file;
	}

	/**
	 * 輸出側欄前的最後整理（掛在 parent_file filter，wp-admin/menu-header.php 一開始就會跑）。
	 *
	 * 這個時間點 WordPress 已經做完權限檢查、也算好 page hook，可以安全地：
	 *   1. 把已提升的子選單列從原父層拿掉（頂層已有對應項）。
	 *   2. 拿掉底下沒有任何選單的分組標籤（這個身分看不到該組的選單時）。
	 *
	 * @param string $parent_file WP 推導的父層檔案（原樣回傳）。
	 */
	public static function prepare_menu_for_render( $parent_file ) {
		self::detach_promoted_rows();
		self::prune_empty_section_labels();
		return $parent_file;
	}

	private static function detach_promoted_rows(): void {
		global $submenu;
		if ( empty( self::$promoted_rows ) || ! is_array( $submenu ) ) {
			return;
		}

		foreach ( self::$promoted_rows as [ $parent, $slug ] ) {
			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}
			foreach ( $submenu[ $parent ] as $idx => $sub ) {
				if ( is_array( $sub ) && (string) ( $sub[2] ?? '' ) === $slug ) {
					unset( $submenu[ $parent ][ $idx ] );
					break;
				}
			}
			$submenu[ $parent ] = array_values( $submenu[ $parent ] );

			// 只剩「父選單自己」那一列時不必再展開成子選單（與 WordPress 自己的處理一致）。
			if ( 1 === count( $submenu[ $parent ] ) && (string) ( $submenu[ $parent ][0][2] ?? '' ) === $parent ) {
				unset( $submenu[ $parent ] );
			}
		}
		self::$promoted_rows = [];
	}

	private static function prune_empty_section_labels(): void {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return;
		}

		$pending = null; // 後面還沒出現任何選單項的標籤。
		foreach ( $menu as $key => $item ) {
			$classes = is_array( $item ) ? (string) ( $item[4] ?? '' ) : '';
			if ( false !== strpos( $classes, 'ys-am-section-label' ) ) {
				if ( null !== $pending ) {
					unset( $menu[ $pending ] );
				}
				$pending = $key;
			} elseif ( false === strpos( $classes, 'wp-menu-separator' ) ) {
				$pending = null;
			}
		}
		if ( null !== $pending ) {
			unset( $menu[ $pending ] );
		}
	}

	/**
	 * 分組標籤是 WordPress 以連結輸出的頂層項，但它不是可以點的東西：拿掉 href 與鍵盤焦點，
	 * 避免用 Tab 走到它、按 Enter 之後跳到不存在的網址。
	 */
	public static function print_section_label_fix(): void {
		echo '<script id="ys-am-section-label-fix">'
			. 'document.querySelectorAll("#adminmenu li.ys-am-section-label > a").forEach(function(a){'
			. 'a.removeAttribute("href");a.removeAttribute("aria-haspopup");a.setAttribute("tabindex","-1");});'
			. '</script>';
	}

	/**
	 * v1.1.1：造訪提升頁時，收斂原父層殘留的 current／展開 class。
	 *
	 * WP core menu-header 對頂層項有一條 `$self == $item[2]` 判定（請求檔名直接
	 * 比對），不經過 parent_file filter——hook 型提升頁（原生 URL 為
	 * parent.php?page=slug）會因此讓原父層同時帶 wp-has-current-submenu／
	 * wp-menu-open。此處於 footer（priority 5，早於 footer scripts）移除殘留，
	 * 高亮唯一落在提升項；亦避免 accordion 依 current-submenu 誤展開原父層。
	 */
	public static function print_promoted_highlight_fix(): void {
		if ( ! self::$current_is_promoted ) {
			return;
		}
		echo '<script id="ys-am-promoted-highlight-fix">'
			. 'document.querySelectorAll("#adminmenu li.wp-has-current-submenu:not(.ys-am-promoted)")'
			. '.forEach(function(li){li.classList.remove("wp-has-current-submenu","wp-menu-open");'
			. 'li.classList.add("wp-not-current-submenu");});'
			. '</script>';
	}

	// ─────────────────────────────────────────────
	// v1.3.0：子選單「複製到主選單」（top_copies）
	//
	// 設定：
	//   - top_copies.items[] = { slug, parent_slug }：要複製的子選單（原子選單保留在原位）。
	//   - 複製項在主選單的排序、改名、圖示、顏色、角色、隱藏、僅限本人，存在「主選單（頂層）」分頁
	//     （wp_native.items[]），slug 是 copy_menu_key() 算出的選單代稱，跟一般頂層項同一套欄位。
	// ─────────────────────────────────────────────

	/**
	 * v1.3.0：「複製到主選單」頂層項的選單代稱（$menu[2]，也就是側欄連結）。
	 *
	 * 與「移到主選單」一樣以原生父層組合連結（promoted_menu_url，AME original-parent URL 策略），
	 * 前面再加 "./"：
	 *   - 瀏覽器把 ./options-writing.php 解析成與原子選單完全相同的網址，伺服器收到的請求一樣，
	 *     page hook、權限檢查、載入流程照舊命中。
	 *   - 字串和原子選單、父選單都不一樣。WordPress 用 $menu[2] 比對目前頁（$self、$parent_file）、
	 *     也用它當 $submenu 的索引；若和原子選單同字串，會把複製項也標成目前頁，複製「設定 → 一般」
	 *     （options-general.php 與父選單同名）時還會把整組設定子選單掛到複製項底下。
	 *   - 字串只由來源決定：同一個子選單只會有一個複製項（同 Admin Menu Editor「頂層連結不得重複」
	 *     的規則），主選單分頁的設定、匯出匯入都以它為鍵，換網域也不變。
	 * 外部網址（含 ://）原樣使用；結果含引號、角括號、空白或反斜線時不建立複製項（避免破壞側欄 HTML）。
	 */
	public static function copy_menu_key( string $slug, string $parent ): string {
		$slug   = trim( wp_specialchars_decode( $slug ) );
		$parent = trim( wp_specialchars_decode( $parent ) );
		if ( '' === $slug || '' === $parent ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $slug ) ) {
			$url = $slug;
		} else {
			$url  = self::promoted_menu_url( $slug, $parent );
			$file = explode( '?', $slug, 2 )[0];
			// 舊式外掛頁的代稱是外掛目錄裡的檔案（例：my-plugin/page.php），WordPress 以 ?page= 開啟它。
			if ( $url === $slug && false !== strpos( $file, '/' )
				&& defined( 'WP_PLUGIN_DIR' ) && is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
				$base = ( false !== strpos( $parent, '.php' ) && false === strpos( $parent, '/' ) ) ? $parent : 'admin.php';
				$url  = $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . 'page=' . $slug;
			}
			$url = './' . $url;
		}

		return preg_match( '/[\'"<>\s\\\\]/', $url ) ? '' : $url;
	}

	/**
	 * v1.3.0：這次請求插入側欄的複製項（設定頁標示「複製」、排序與 badge 用）。
	 *
	 * @return array<string, array{id:string,slug:string,source:string,parent:string,title:string,parent_title:string,parent_icon:string}>
	 */
	public static function copy_entries(): array {
		return self::$copy_entries;
	}

	/**
	 * v1.3.0：複製項的圖示只接受 WordPress 側欄認得的格式。
	 *
	 * dashicons-*、none／div（不顯示圖示）、data:image/svg+xml;base64、http(s) 圖片網址；其餘回空字串（沿用原父選單圖示）。
	 */
	public static function sanitize_menu_icon( string $icon ): string {
		$icon = trim( $icon );
		if ( '' === $icon ) {
			return '';
		}
		if ( preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) || 'none' === $icon || 'div' === $icon ) {
			return $icon;
		}
		if ( preg_match( '#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $icon ) ) {
			return strlen( $icon ) <= 20000 ? $icon : '';
		}
		if ( preg_match( '#^https?://#i', $icon ) && function_exists( 'esc_url_raw' ) ) {
			return (string) esc_url_raw( $icon, [ 'http', 'https' ] );
		}
		return '';
	}

	/**
	 * v1.3.0：把「複製到主選單」的子選單插入為頂層項（原子選單保留在原位）。
	 *
	 * 顯示規則：
	 *   - 原子選單這時還在 $submenu 裡（沒被本外掛的隱藏、角色、僅限本人、白名單規則拿掉）才插入；
	 *     複製項沿用原子選單的 capability，WordPress 稍後照同一個權限拿掉沒權限的人看到的複製項。
	 *   - 複製項自己的規則（主選單分頁以 copy_menu_key 為 slug 的那一列）：僅限本人對所有人生效；
	 *     隱藏、角色對 super admin 以外生效——與其他選單一致。它們只管複製項，不會擋原頁面。
	 *   - 位置：插在原父選單正下方；主選單分頁排過序之後依設定（見 with_copy_placeholders）。
	 *   - 圖示：主選單分頁設定的圖示，沒設定沿用原父選單的圖示。
	 */
	private static function insert_top_level_copies( array $config, int $user_id, array $user_roles ): void {
		global $menu, $_wp_real_parent_file;

		self::$copy_entries     = [];
		self::$current_copy_key = '';

		$copies = $config['top_copies']['items'] ?? [];
		if ( empty( $copies ) || ! is_array( $copies ) || ! is_array( $menu ) ) {
			return;
		}

		// v1.5.1：複製項自己的設定列以鍵索引（「自訂」這類 return 每頁不同的複製項，兩個分頁存下的字串不一樣）。
		$native_rows = [];
		foreach ( (array) ( $config['wp_native']['items'] ?? [] ) as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && ! empty( $row['slug'] ) ) {
				$native_rows[ self::slug_key( (string) $row['slug'] ) ] = $row;
			}
		}

		$is_super   = is_super_admin( $user_id );
		$last_after = []; // 父選單鍵 => 最後一個插在它後面的鍵（同一個父選單的多個複製項依設定順序排）。

		foreach ( $copies as $copy ) {
			if ( ! is_array( $copy ) ) {
				continue;
			}
			$slug   = (string) ( $copy['slug'] ?? '' );
			$parent = (string) ( $copy['parent_slug'] ?? '' );
			$key    = self::copy_menu_key( $slug, $parent );
			if ( '' === $key ) {
				continue;
			}

			$source = self::find_copy_source( $parent, $slug );
			if ( null === $source ) {
				continue; // 原子選單不在（外掛停用，或這個帳號看不到、進不去）。
			}
			[ $row, $real_parent ] = $source;

			// v1.5.1：原子選單或它的上層是用鍵對到的（網址帶 %xx），連結改用側欄上實際的 slug 與上層組（存下的字串少了 %xx）。
			// 上層照原字串找得到（或是 WordPress 換過代稱的 $_wp_real_parent_file）時照 1.5.0 用存下的上層。
			$source_slug = (string) ( $row[2] ?? '' );
			$link_parent = ( $real_parent === $parent || ( is_array( $_wp_real_parent_file ?? null ) && ( $_wp_real_parent_file[ $parent ] ?? null ) === $real_parent ) )
				? $parent
				: $real_parent;
			if ( wp_specialchars_decode( $source_slug ) !== wp_specialchars_decode( $slug ) || $link_parent !== $parent ) {
				$key = self::copy_menu_key( $source_slug, $link_parent );
			}
			if ( '' === $key || isset( self::$copy_entries[ $key ] ) ) {
				continue;
			}

			$own     = $native_rows[ self::slug_key( $key ) ] ?? [];
			$own_uid = (int) ( $own['self_only_uid'] ?? 0 );
			if ( $own_uid > 0 && $own_uid !== $user_id ) {
				continue;
			}
			if ( ! $is_super ) {
				if ( ! empty( $own['hide'] ) ) {
					continue;
				}
				$allowed = array_values( array_filter( array_map( 'sanitize_key', (array) ( $own['roles'] ?? [] ) ) ) );
				if ( ! empty( $allowed ) && empty( array_intersect( $user_roles, $allowed ) ) ) {
					continue;
				}
			}

			$parent_entry = self::find_menu_entry( $real_parent );
			$parent_meta  = self::$parent_meta[ $real_parent ] ?? ( self::$parent_meta[ $parent ] ?? [ 'icon' => '', 'title' => $parent ] );
			$parent_icon  = '' !== $parent_meta['icon'] ? $parent_meta['icon'] : 'dashicons-admin-generic';
			$icon         = self::sanitize_menu_icon( (string) ( $own['icon'] ?? '' ) );
			$title        = (string) ( $row[0] ?? $slug );
			$id_base      = substr( md5( $parent . '|' . $slug ), 0, 10 );
			// v1.3.2：改名在插入時就套用（指定了位置的複製項不經過依 order 的重排，那裡才套改名）。
			$name = isset( $own['title_override'] ) ? trim( wp_strip_all_tags( (string) $own['title_override'] ) ) : '';

			$entry = [
				'' !== $name ? $name : $title,
				(string) ( $row[1] ?? 'read' ),
				$key,
				(string) ( $row[3] ?? wp_strip_all_tags( $title ) ),
				'menu-top ys-am-copy menu-top-ys-am-copy-' . $id_base,
				'ys-am-copy-' . $id_base,
				'' !== $icon ? $icon : $parent_icon,
			];

			// 原父選單對這個帳號隱藏時，仍用它原本的鍵排位置（WordPress 之後依鍵排序）。
			$base = null !== $parent_entry ? $parent_entry[0] : ( $parent_meta['key'] ?? null );
			if ( null !== $base ) {
				$last_after[ (string) $base ] = self::insert_menu_entry_after(
					$last_after[ (string) $base ] ?? ( null !== $parent_entry ? $base : null ),
					$base,
					$entry
				);
			} else {
				self::insert_menu_entry_after( null, null, $entry );
			}

			self::$copy_entries[ $key ] = [
				'id'           => 'ys-am-copy-' . $id_base,
				'slug'         => $slug,
				'source'       => $source_slug,
				'parent'       => $parent,
				'title'        => trim( wp_strip_all_tags( $title ) ),
				'parent_title' => $parent_meta['title'],
				'parent_icon'  => $parent_icon,
			];
		}
	}

	/**
	 * v1.3.0：記下各頂層選單的圖示與名稱（只在設定裡有複製項時才做）。
	 */
	private static function remember_parent_menus( array $config ): void {
		global $menu;

		self::$parent_meta = [];
		if ( empty( $config['top_copies']['items'] ) || ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $k => $m ) {
			if ( is_array( $m ) && ! empty( $m[2] ) ) {
				$title = trim( wp_strip_all_tags( (string) ( $m[0] ?? '' ) ) );
				self::$parent_meta[ (string) $m[2] ] = [
					'key'   => $k,
					'icon'  => (string) ( $m[6] ?? '' ),
					'title' => '' !== $title ? $title : (string) $m[2],
				];
			}
		}
	}

	/**
	 * v1.3.0：在 $submenu 裡找複製來源的那一列。
	 *
	 * 父選單被 WordPress 換過代稱（這個帳號進不了父選單本身時，$_wp_real_parent_file）也找得到。
	 * 代稱比對前先還原 &amp;（自訂文章類型的分類子選單以 &amp; 註冊，設定頁存的是 &）。
	 *
	 * @return array{0:array<int,mixed>,1:string}|null [ 子選單列, 實際父選單代稱 ]
	 */
	private static function find_copy_source( string $parent, string $slug ): ?array {
		global $submenu, $_wp_real_parent_file;
		if ( ! is_array( $submenu ) ) {
			return null;
		}

		$want    = wp_specialchars_decode( $slug );
		$parents = [ $parent ];
		if ( is_array( $_wp_real_parent_file ?? null ) && isset( $_wp_real_parent_file[ $parent ] ) ) {
			$parents[] = (string) $_wp_real_parent_file[ $parent ];
		}
		// v1.5.1：上層照原字串找不到時比鍵（submenu_parent()）。
		$found = [];
		foreach ( array_unique( $parents ) as $p ) {
			$actual = self::submenu_parent( (string) $p );
			if ( null !== $actual ) {
				$found[] = $actual;
			}
		}
		$found = array_values( array_unique( $found ) );

		foreach ( $found as $p ) {
			if ( empty( $submenu[ $p ] ) || ! is_array( $submenu[ $p ] ) ) {
				continue;
			}
			foreach ( $submenu[ $p ] as $sub ) {
				if ( is_array( $sub ) && wp_specialchars_decode( (string) ( $sub[2] ?? '' ) ) === $want ) {
					return [ $sub, (string) $p ];
				}
			}
		}

		// v1.5.1：原字串都對不到，才找存檔時會被改掉、鍵又相同的子選單（網址帶 %xx）。
		$key = self::slug_key( $want );
		if ( '' === $key ) {
			return null;
		}
		foreach ( $found as $p ) {
			if ( empty( $submenu[ $p ] ) || ! is_array( $submenu[ $p ] ) ) {
				continue;
			}
			foreach ( $submenu[ $p ] as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$raw = wp_specialchars_decode( (string) ( $sub[2] ?? '' ) );
				if ( self::slug_is_altered( $raw ) && self::slug_key( $raw ) === $key ) {
					return [ $sub, (string) $p ];
				}
			}
		}
		return null;
	}

	/**
	 * v1.3.0：找頂層選單項。
	 *
	 * @return array{0:int|string,1:array<int,mixed>}|null [ $menu 鍵, 選單項 ]
	 */
	private static function find_menu_entry( string $slug ): ?array {
		global $menu;
		foreach ( (array) $menu as $k => $m ) {
			if ( is_array( $m ) && (string) ( $m[2] ?? '' ) === $slug ) {
				return [ $k, $m ];
			}
		}
		return null;
	}

	/**
	 * v1.3.0：把一個頂層項插在指定鍵之後（陣列順序與鍵的排序都緊接在後）；沒有基準鍵就接在最後。
	 *
	 * WordPress 在 admin_menu 之後以 strnatcasecmp 依鍵排序 $menu，所以新鍵是「父選單鍵.0001」
	 * 這類字串（排在父選單之後、下一個整數之前；同一個父選單的第二個是 .0002）；陣列裡也緊接在後，
	 * 給之後依陣列順序重排的程式（reorder_and_insert_separators）用。父選單這次不在 $menu 裡時
	 * （對這個帳號隱藏），只用它原本的鍵產生新鍵、接在陣列最後。
	 *
	 * @param int|string|null   $after_key 要接在哪個鍵之後（同一個父選單的上一個複製項，或父選單本身）。
	 * @param int|string|null   $base_key  新鍵以哪個鍵為基準（父選單的鍵）。
	 * @param array<int, mixed> $entry     頂層選單項。
	 * @return int|string 新項目的鍵。
	 */
	private static function insert_menu_entry_after( $after_key, $base_key, array $entry ) {
		global $menu;

		if ( null === $base_key ) {
			$menu[] = $entry;
			end( $menu );
			return key( $menu );
		}

		$n = 1;
		do {
			$new_key = (string) $base_key . '.' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
			++$n;
		} while ( array_key_exists( $new_key, $menu ) );

		if ( null === $after_key || ! array_key_exists( $after_key, $menu ) ) {
			$menu[ $new_key ] = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 選單管理外掛本來就在調整側欄。
			return $new_key;
		}

		$rebuilt = [];
		foreach ( $menu as $k => $m ) {
			$rebuilt[ $k ] = $m;
			if ( (string) $k === (string) $after_key ) {
				$rebuilt[ $new_key ] = $entry;
			}
		}
		$menu = $rebuilt; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 選單管理外掛本來就在調整側欄。

		return $new_key;
	}

	/**
	 * v1.3.0：主選單分頁還沒排過序的複製項，排序時跟在原父選單之後（同一個 order，穩定排序保留先後）。
	 *
	 * 原父選單也沒排過序時不必處理：兩者都接在已排序項目之後，複製項本來就緊接在父選單後面。
	 *
	 * v1.3.2：指定了位置（after）的複製項與已移到主選單項目不參與依 order 的排序，這裡同樣先讓它們
	 * 跟在原父選單之後；之後 place_anchored_top_items 會放到指定位置。指定的錨點對這個帳號不在側欄裡
	 * 時，就留在原父選單之後。
	 *
	 * @param array<int, array<string, mixed>> $wp_native_items 依 order 排的 wp_native.items[]（不含指定位置的列）。
	 * @param array<int, array<string, mixed>> $anchored        指定了位置的 wp_native.items[] 列。
	 * @param array<int, array<string, mixed>> $ys_cart_items   ys_cart.items[]（找已移到主選單項目的原父選單）。
	 * @return array<int, array<string, mixed>>
	 */
	private static function with_copy_placeholders( array $wp_native_items, array $anchored = [], array $ys_cart_items = [] ): array {
		$parents = []; // 頂層代稱 => 原父選單
		foreach ( self::$copy_entries as $key => $info ) {
			$parents[ $key ] = $info['parent'];
		}
		if ( ! empty( $anchored ) ) {
			$anchored_ids = []; // v1.5.1：以鍵比（「自訂」移到主選單後，兩個分頁存下的代稱字串不同）。
			foreach ( $anchored as $row ) {
				$anchored_ids[ self::slug_key( wp_specialchars_decode( (string) $row['slug'] ) ) ] = true;
			}
			foreach ( $ys_cart_items as $item ) {
				if ( ! is_array( $item ) || empty( $item['promote_to_top'] ) || 'sub' !== (string) ( $item['level'] ?? '' ) ) {
					continue;
				}
				$parent = (string) ( $item['parent_slug'] ?? '' );
				$key    = self::promoted_menu_url( (string) ( $item['slug'] ?? '' ), $parent );
				if ( isset( $anchored_ids[ self::slug_key( wp_specialchars_decode( $key ) ) ] ) ) {
					$parents[ $key ] = $parent;
				}
			}
		}
		if ( empty( $parents ) ) {
			return $wp_native_items;
		}

		// v1.5.1：以鍵比（複製項的代稱是側欄上實際的連結，網址帶 %xx 時跟存下的字串不同）。
		$order_by_slug = [];
		foreach ( $wp_native_items as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && isset( $row['slug'] ) ) {
				$order_by_slug[ self::slug_key( (string) $row['slug'] ) ] = (int) ( $row['order'] ?? 0 );
			}
		}

		foreach ( $parents as $key => $parent ) {
			$key = (string) $key;
			if ( isset( $order_by_slug[ self::slug_key( $key ) ] ) || ! isset( $order_by_slug[ self::slug_key( (string) $parent ) ] ) ) {
				continue;
			}
			$wp_native_items[] = [
				'slug'  => $key,
				'order' => $order_by_slug[ self::slug_key( (string) $parent ) ],
			];
		}

		return $wp_native_items;
	}

	/**
	 * v1.3.2：把 wp_native.items 分成「依 order 排」與「指定了位置（after）」兩組，各自保留原本的先後。
	 *
	 * @param array<int, mixed> $items wp_native.items[]
	 * @return array{0:array<int, mixed>,1:array<int, array<string, mixed>>}
	 */
	private static function split_anchored_rows( array $items ): array {
		$ordered  = [];
		$anchored = [];
		foreach ( $items as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && ! empty( $row['slug'] )
				&& isset( $row['after'] ) && is_string( $row['after'] ) ) {
				$anchored[] = $row;
			} else {
				$ordered[] = $row;
			}
		}
		return [ $ordered, $anchored ];
	}

	/**
	 * v1.3.2：清單裡有沒有一般的頂層列（不算分隔列）。只有分隔列或空的時候不做依 order 的重排。
	 *
	 * @param array<int, mixed> $items
	 */
	private static function has_top_level_rows( array $items ): bool {
		foreach ( $items as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && ! empty( $row['slug'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * v1.3.2：把指定了位置的頂層項放到「緊接在某個頂層項後面」。
	 *
	 * after 是頂層項的代稱（$menu[2]；複製項是 ./ 開頭的代稱、已移到主選單的是原生網址），空字串＝最前面。
	 * 依 WordPress 之後的排序方式（鍵的 strnatcasecmp）給移動的項目一個緊接在錨點後面的鍵
	 * （「錨點鍵.0001」，同一個錨點的第二個是 .0002）。錨點本身也是指定位置的項目時，等它先放好。
	 * 錨點這次不在側欄裡（對這個帳號隱藏、沒有權限、已刪除）或互相指定成環時，項目留在原本的位置。
	 *
	 * @param array<int, array<string, mixed>> $anchored 指定了位置的 wp_native.items[] 列（依設定順序）。
	 */
	private static function place_anchored_top_items( array $anchored ): void {
		global $menu;
		if ( empty( $anchored ) || ! is_array( $menu ) ) {
			return;
		}

		$key_of = []; // 代稱（還原 &amp;）=> $menu 鍵
		$by_key = []; // v1.5.1：存檔時會被改掉的代稱，鍵 => 代稱（第一個）
		foreach ( $menu as $k => $m ) {
			if ( ! is_array( $m ) || ! isset( $m[2] ) || '' === (string) $m[2] ) {
				continue;
			}
			$id = wp_specialchars_decode( (string) $m[2] );
			if ( ! isset( $key_of[ $id ] ) ) {
				$key_of[ $id ] = $k;
			}
			if ( self::slug_is_altered( $id ) && ! isset( $by_key[ self::slug_key( $id ) ] ) ) {
				$by_key[ self::slug_key( $id ) ] = $id;
			}
		}
		// v1.5.1：存下的代稱照原字串找不到時，換成鍵相同的實際代稱（網址帶 %xx、「自訂」的 return 每頁不同）。
		$live_id = static function ( string $stored ) use ( $key_of, $by_key ): string {
			if ( '' === $stored || isset( $key_of[ $stored ] ) ) {
				return $stored;
			}
			return $by_key[ self::slug_key( $stored ) ] ?? $stored;
		};

		$pending = []; // 代稱 => [ 錨點, 設定列 ]
		foreach ( $anchored as $row ) {
			$id    = $live_id( wp_specialchars_decode( (string) $row['slug'] ) );
			$after = $live_id( wp_specialchars_decode( (string) $row['after'] ) );
			if ( isset( $key_of[ $id ] ) && $after !== $id && ! isset( $pending[ $id ] ) ) {
				$pending[ $id ] = [ $after, $row ];
			}
		}

		$used = []; // 錨點鍵 => 已用到的序號
		do {
			$progress = false;
			foreach ( $pending as $id => $job ) {
				[ $after, $row ] = $job;
				if ( '' === $after ) {
					$base = '-1'; // 排在最前面（WordPress 依鍵排序時排在 0、1、2… 之前）。
				} elseif ( isset( $key_of[ $after ] ) && ! isset( $pending[ $after ] ) ) {
					$base = (string) $key_of[ $after ];
				} else {
					continue; // 錨點還沒放好，或這個帳號看不到錨點。
				}

				$n = $used[ $base ] ?? 0;
				do {
					++$n;
					$new_key = $base . '.' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
				} while ( array_key_exists( $new_key, $menu ) );
				$used[ $base ] = $n;

				$old_key = $key_of[ $id ];
				$entry   = $menu[ $old_key ];
				unset( $menu[ $old_key ] );
				$name = isset( $row['title_override'] ) ? trim( wp_strip_all_tags( (string) $row['title_override'] ) ) : '';
				if ( '' !== $name ) {
					$entry[0] = $name;
				}
				$menu[ $new_key ] = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 選單管理外掛本來就在調整側欄。
				$key_of[ $id ]    = $new_key;
				unset( $pending[ $id ] );
				$progress = true;
			}
		} while ( $progress && ! empty( $pending ) );
	}

	/**
	 * v1.3.0：同一頁在側欄有兩個入口（原位置＋複製項）時，「目前所在」只標一個。
	 *
	 * 規則：原位置優先。原位置在這個帳號的側欄裡看得到時，完全交給 WordPress 原生判斷——原父選單
	 * 展開、原子選單標為目前頁；複製項的代稱前面有 "./"，WordPress 不會把它當成目前頁，所以不必處理。
	 * 原位置看不到時（例如原父選單對這個帳號隱藏），才把高亮改標在指向目前頁的複製項
	 * （print_copy_highlight_fix）。目前頁是「已提升」頁時沿用提升的規則（提升後的頂層項就是原位置）。
	 *
	 * 這裡只做判斷、原樣回傳：WordPress 在這個 filter 之後會用 get_admin_page_parent() 依 $submenu
	 * 重算父選單，複製來源那一列一直在 $submenu 裡，改了也會被蓋回去。
	 *
	 * @param string $parent_file WP 推導的父層檔案。
	 */
	public static function fix_copy_highlight( $parent_file ) {
		global $menu, $self, $typenow;

		self::$current_copy_key = '';
		if ( self::$current_is_promoted || empty( self::$copy_entries ) || ! is_array( $menu ) ) {
			return $parent_file;
		}

		$present = [];
		foreach ( $menu as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$item = (string) ( $m[2] ?? '' );
			if ( isset( self::$copy_entries[ $item ] ) ) {
				$present[ $item ] = true;
				continue;
			}
			// 這一項會被 WordPress 標成目前所在（與 menu-header.php 的判斷相同）＝原位置看得到。
			if ( '' !== $item
				&& ( ( is_string( $parent_file ) && '' !== $parent_file && $item === $parent_file )
					|| ( empty( $typenow ) && is_string( $self ) && $self === $item ) ) ) {
				return $parent_file;
			}
		}

		foreach ( self::$copy_entries as $key => $info ) {
			if ( isset( $present[ $key ] ) && self::copy_matches_request( $info['source'] ) ) {
				self::$current_copy_key = $key;
				break;
			}
		}

		return $parent_file;
	}

	/**
	 * v1.3.0：原位置看不到時，把目前頁標在複製項上（footer，早於其他 footer script）。
	 *
	 * 先拿掉側欄裡其他「目前所在」的標記（原父選單被隱藏時通常沒有），確保只標一個。
	 */
	public static function print_copy_highlight_fix(): void {
		if ( '' === self::$current_copy_key || ! isset( self::$copy_entries[ self::$current_copy_key ] ) ) {
			return;
		}
		$id = self::$copy_entries[ self::$current_copy_key ]['id'];

		echo '<script id="ys-am-copy-highlight-fix">(function(){'
			. 'var li=document.getElementById(' . wp_json_encode( $id ) . ');if(!li){return;}'
			. 'document.querySelectorAll("#adminmenu li.wp-has-current-submenu,#adminmenu li.wp-has-current-submenu > a").forEach(function(el){'
			. 'el.classList.remove("wp-has-current-submenu","wp-menu-open");el.classList.add("wp-not-current-submenu");});'
			. 'document.querySelectorAll("#adminmenu .current").forEach(function(el){el.classList.remove("current");el.removeAttribute("aria-current");});'
			. 'var a=li.querySelector(":scope > a");'
			. '[li,a].forEach(function(el){if(el){el.classList.remove("wp-not-current-submenu");el.classList.add("current");}});'
			. 'if(a){a.setAttribute("aria-current","page");}'
			. '})();</script>';
	}

	/**
	 * v1.3.0：目前請求是不是複製來源的那一頁（只在原位置看不到、要改標複製項時用）。
	 *
	 * 外掛頁比 page 參數；.php 頁比檔名，代稱帶的查詢參數也要相同（edit.php／post-new.php 沒帶
	 * post_type 時視為 post，與 WordPress 一致）。
	 */
	private static function copy_matches_request( string $slug ): bool {
		global $pagenow, $plugin_page;

		$slug = trim( wp_specialchars_decode( $slug ) );
		if ( '' === $slug ) {
			return false;
		}
		$page = ( is_string( $plugin_page ?? null ) && '' !== $plugin_page ) ? (string) $plugin_page : '';

		if ( '' !== $page || false === strpos( $slug, '.php' ) ) {
			return '' !== $page && $page === $slug;
		}

		$parts = explode( '?', $slug, 2 );
		if ( ! is_string( $pagenow ) || $parts[0] !== $pagenow ) {
			return false;
		}

		$args = [];
		if ( isset( $parts[1] ) ) {
			wp_parse_str( $parts[1], $args );
		}
		$post_default = in_array( $parts[0], [ 'edit.php', 'post-new.php' ], true );
		if ( $post_default && ! isset( $args['post_type'] ) ) {
			$args['post_type'] = 'post';
		}

		foreach ( $args as $name => $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只比對網址，不處理資料。
			$current = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? (string) wp_unslash( $_GET[ $name ] ) : null;
			if ( null === $current && 'post_type' === $name && $post_default ) {
				$current = 'post';
			}
			if ( ! is_string( $value ) || $current !== $value ) {
				return false;
			}
		}
		return true;
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

		// 實際的上層 => [ 實際的子選單 slug => order ]
		// v1.5.1：存下的上層、子選單照原字串找不到時比鍵（submenu_parent()、matching_rows()），換成側欄上實際的字串。
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
			// v1.5.0：沒有順序值的列（例如選單搬家時只帶隱藏規則的列）不參與排序，照目前的順序排在已排序的後面。
			if ( ! isset( $item['order'] ) ) {
				continue;
			}
			$parent = self::submenu_parent( $parent );
			if ( null === $parent || ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}
			$hit = self::matching_rows( $submenu[ $parent ], $slug );
			if ( [] === $hit ) {
				continue;
			}
			$order_map[ $parent ][ (string) $submenu[ $parent ][ $hit[0] ][2] ] = (int) $item['order'];
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

		// slug → allowed roles 映射（v1.5.1：另存一份以鍵索引的，給存檔時會被改掉的實際 slug 用）
		$rule     = [];
		$rule_key = [];
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
			if ( '' !== self::slug_key( $slug ) ) {
				$rule_key[ self::slug_key( $slug ) ] = $roles;
			}
		}

		foreach ( $menu as $position => $menu_item ) {
			if ( ! is_array( $menu_item ) ) {
				continue;
			}
			$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $rule[ $slug ] ) ) {
				$allowed = $rule[ $slug ];
			} elseif ( self::slug_is_altered( $slug ) && isset( $rule_key[ self::slug_key( $slug ) ] ) ) {
				$allowed = $rule_key[ self::slug_key( $slug ) ];
			} else {
				continue;
			}
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
		$visible     = array_flip( $visible_slugs );
		$visible_key = self::key_set( $visible_slugs ); // v1.5.1：存檔時會被改掉的實際 slug 用鍵比。
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
			// v1.5.0：導覽列（admin.php?page=<YS CART 頁面>&…）在 V3 上用它指向的頁面比對白名單（nav_target()）。
			$target = self::nav_target( $slug );
			if ( isset( $visible[ $target ] ) ) {
				continue;
			}
			if ( self::slug_is_altered( $target ) && isset( $visible_key[ self::slug_key( $target ) ] ) ) {
				continue;
			}
			unset( $menu[ $position ] );
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
	public static function current_user_can_access_admin_slug( string $slug, ?int $user_id = null, ?string $parent = null ): bool {
		$slug = self::normalize_admin_slug( $slug );
		if ( '' === $slug ) {
			return true;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( $user_id <= 0 ) {
			return true;
		}

		return self::access_decision( $slug, self::get_config(), $user_id, $parent );
	}

	/**
	 * current_user_can_access_admin_slug() 的判斷本體。v1.5.0 抽出來，讓選單搬家遷移能拿「某一份設定」來判斷；規則不變。
	 *
	 * @param string              $slug   已正規化、不是空字串。
	 * @param array<string,mixed> $config
	 * @param string|null         $parent 側欄上這一列的上層（子選單才有；nav_target() 用）。
	 */
	private static function access_decision( string $slug, array $config, int $user_id, ?string $parent = null ): bool {
		// v1.5.0：指向 YS CART 某一頁的導覽列（例如 V3 的「會員生日」）：導覽列自己與它指向的頁面兩邊都要過。
		$target = self::nav_target( $slug, $parent );
		if ( $target !== $slug ) {
			return self::passes_slug( $slug, $config, $user_id, false ) && self::passes_slug( $target, $config, $user_id, true );
		}

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

	/**
	 * 「僅允許存取受保護的管理選單」（與舊版 hide_for_user_ids）的帳號看得到、進得去的頁面。
	 *
	 * v1.5.1 起照字面：YS CART 核心的登錄表、YS CART 的頁面（ys-cart、ys-cart-*、ys-ec-*、ys-provider-*；導覽列照它指向的頁面）、
	 * 本外掛的頁面，以及 ys_admin_menu_extra_admin_slugs、ys_admin_menu_external_admin_pages 指定的頁面。
	 * 1.5.0 以前「全部選單」分頁存過的每一列也算，存過那個分頁的站幾乎每一頁都算受保護。
	 * YS CART 2.37.1～2.52.8 有核心的 YSMenuRouter 類別、沒有 is_ys_cart_admin_slug()：叫不到時當成核心沒有提供這個判斷。
	 */
	public static function is_ys_cart_admin_slug( string $slug ): bool {
		$slug = self::normalize_admin_slug( $slug );
		if ( '' === $slug ) {
			return false;
		}

		if (
			is_callable( [ \YangSheep\Ecommerce\Permission\YSMenuRouter::class, 'is_ys_cart_admin_slug' ] )
			&& \YangSheep\Ecommerce\Permission\YSMenuRouter::is_ys_cart_admin_slug( $slug )
		) {
			return true;
		}

		$page = self::admin_page_of( $slug );
		if ( self::is_ys_cart_namespace_slug( $slug ) || ( '' !== $page && self::is_ys_cart_namespace_slug( $page ) ) ) {
			return true;
		}

		if ( in_array( $slug, self::external_admin_slugs(), true ) ) {
			return true;
		}

		$slugs = self::ys_cart_admin_slug_map();
		return isset( $slugs[ $slug ] );
	}

	/**
	 * v1.5.0：「導覽列」對應到它指向的頁面。
	 *
	 * 只處理 admin.php?page=<YS CART 頁面>&… 這一種寫法（頁面 slug 是 ys-cart、ys-cart-*、ys-ec-*，與 YS CART 核心的
	 * access_decision_slug() 相同）。這種子選單不是獨立的頁面，只是那一頁的入口（例如 V3「顧客」底下的「會員生日」＝
	 * admin.php?page=ys-ec-settings&tab=birthday）。其他網址型 slug 原樣回傳。這裡只看寫法；側欄與存取判斷什麼時候真的換成
	 * 那一頁，由 nav_target() 決定。
	 */
	public static function access_target_slug( string $slug ): string {
		$decoded = wp_specialchars_decode( trim( $slug ) );
		if ( ! preg_match( '/^admin\.php\?page=([^&#=]+)&./', $decoded, $m ) ) {
			return $slug;
		}
		$page = self::normalize_admin_slug( $m[1] );
		if ( 'ys-cart' === $page || str_starts_with( $page, 'ys-cart-' ) || str_starts_with( $page, 'ys-ec-' ) ) {
			return $page;
		}
		return $slug;
	}

	/**
	 * v1.5.0：側欄上這一列要照哪一頁判斷（存取、白名單、「僅限本人」）。
	 *   - 站上沒有 YS CART 的選單搬家清單（2.69 等舊版：YSMenuMovesMigrator::detect() 是 null）：一律看它自己，跟 1.4.0 一樣。
	 *   - 有（V3）：導覽列（access_target_slug() 認得的寫法）不在它指向的那一頁底下時，換成那一頁，例如「顧客」底下的
	 *     「會員生日」；就在那一頁底下的（「商店設定」底下的分頁連結）照 1.4.0 看它自己。頂層沒有上層，照樣換。
	 *
	 * @param string|null $parent 子選單的上層；null＝頂層，或不知道在哪裡（例如直接問 current_user_can_access_admin_slug()）。
	 */
	public static function nav_target( string $slug, ?string $parent = null ): string {
		$target = self::access_target_slug( $slug );
		if ( $target === $slug || ! self::moves_site() ) {
			return $slug;
		}
		if ( null !== $parent && self::normalize_admin_slug( $parent ) === $target ) {
			return $slug;
		}
		return $target;
	}

	/**
	 * v1.5.0：站上有沒有 YS CART 的選單搬家清單（同一個請求只查一次）。
	 */
	private static function moves_site(): bool {
		if ( null === self::$moves_site ) {
			self::$moves_site = class_exists( YSMenuMovesMigrator::class ) && null !== YSMenuMovesMigrator::detect();
		}
		return self::$moves_site;
	}

	/**
	 * v1.5.0：導覽列與它指向的頁面各自判斷，任一邊拒絕就拒絕。
	 *   - 導覽列自己：current_user_can_access_admin_slug 的同一套規則（綁給自己就放行，跟 1.4.0 一樣）。
	 *   - 指向的頁面：「僅限本人」是純限制——綁給別人就拒絕，綁給自己也照樣看那一頁的隱藏、角色與白名單；不然被綁的人
	 *     會在導覽列上繞過那一頁的隱藏與白名單。super admin 照 1.4.0 不受隱藏、角色、白名單限制。
	 *   - 白名單與「僅允許存取受保護的管理選單」只看指向的頁面（白名單只會列真正的頁面）。
	 */
	private static function passes_slug( string $slug, array $config, int $user_id, bool $is_target ): bool {
		$self_only_uid = self::self_only_uid_for_slug( $slug, $config );
		if ( $self_only_uid > 0 && $self_only_uid !== $user_id ) {
			return false;
		}
		if ( $self_only_uid > 0 && ! $is_target ) {
			return true;
		}
		if ( is_super_admin( $user_id ) || user_can( $user_id, 'manage_network' ) ) {
			return true;
		}
		if ( empty( $config ) ) {
			return true;
		}
		$user       = get_userdata( $user_id );
		$user_roles = $user ? array_map( 'sanitize_key', (array) $user->roles ) : [];

		return ( ! $is_target || self::passes_user_overrides( $slug, $config, $user_id ) )
			&& self::passes_item_rules( $slug, $config, $user_roles );
	}

	/**
	 * v1.5.0（選單搬家遷移用）：照 $config，某個帳號在側欄看不看得到某個頂層選單或子選單。只看本外掛的規則，
	 * 跟 apply_config() 對這一列做的事相同：
	 *   - 頂層：主選單分頁同 slug 最後一列的角色（filter_menu_by_roles）、白名單（apply_user_override_whitelist）、
	 *     頂層的隱藏（apply_hide_flags）。
	 *   - 子選單：子選單的隱藏（apply_hide_flags）。
	 *   - 兩者：存取判斷（filter_menus_by_current_access）、「僅限本人」（apply_self_only_filter，同一個 slug 後面的列蓋掉前面）。
	 *   - super admin 只看「僅限本人」。
	 *
	 * @param array<string,mixed> $config
	 * @param array<int,string>   $also_listed 比對白名單時當作也有勾的 slug（判斷「白名單變多」這個事先宣告的例外用）。
	 * @param string|null         $parent      子選單在側欄上的上層（導覽列照 nav_target() 判斷，跟側欄一樣）；頂層不用給。
	 */
	public static function sidebar_allows( string $slug, bool $top, array $config, int $user_id, array $also_listed = [], ?string $parent = null ): bool {
		$slug = self::normalize_admin_slug( $slug );
		if ( '' === $slug || $user_id <= 0 ) {
			return true;
		}
		$under = $top ? null : $parent;

		foreach ( array_unique( [ $slug, self::nav_target( $slug, $under ) ] ) as $s ) {
			$bound = self::sidebar_self_only_uid( $s, $config );
			if ( $bound > 0 && $bound !== $user_id ) {
				return false;
			}
		}
		if ( is_super_admin( $user_id ) ) {
			return true;
		}
		if ( ! empty( $also_listed ) ) {
			$config = self::with_listed_slugs( $config, $user_id, $also_listed );
		}

		$user       = get_userdata( $user_id );
		$user_roles = $user ? array_map( 'sanitize_key', (array) $user->roles ) : [];

		// v1.5.1：$slug 已經是鍵，存下的那邊也換成鍵再比（跟 apply_config() 對存檔時會被改掉的 slug 一樣）。
		if ( $top ) {
			$allowed = [];
			foreach ( self::rule_rows( $config, 'wp_native' ) as $item ) {
				if ( is_array( $item ) && empty( $item['separator'] ) && self::slug_key( (string) ( $item['slug'] ?? '' ) ) === $slug ) {
					$allowed = array_map( 'sanitize_key', (array) ( $item['roles'] ?? [] ) );
				}
			}
			if ( ! empty( $allowed ) && empty( array_intersect( $user_roles, $allowed ) ) ) {
				return false;
			}
			$visible = self::resolve_visible_slugs_for_user( $config, $user_id );
			if ( ! empty( $visible ) && ! isset( self::key_set( $visible )[ self::nav_target( $slug ) ] ) ) {
				return false;
			}
		}

		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( self::rule_rows( $config, $tab ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['hide'] ) || self::slug_key( (string) ( $item['slug'] ?? '' ) ) !== $slug ) {
					continue;
				}
				$sub_row = 'sub' === (string) ( $item['level'] ?? 'top' );
				if ( $top ? ! $sub_row : $sub_row ) {
					return false;
				}
			}
		}

		return self::access_decision( $slug, $config, $user_id, $under );
	}

	/**
	 * v1.5.0（選單搬家遷移用）：照 $config，某個帳號在側欄看不看得到「複製到主選單」的這一項（insert_top_level_copies()
	 * 的同一套判斷）：設定裡有這個複製項、原子選單過完子選單的過濾還在，複製項自己的僅限本人、隱藏、角色也過得了。
	 * 原父選單本身看不看得到（頂層的白名單、角色、隱藏）不影響複製項。
	 *
	 * @param array<string,mixed> $config
	 */
	public static function copy_allows( string $slug, string $parent, array $config, int $user_id ): bool {
		// v1.5.1：以鍵比（跟側欄 insert_top_level_copies() 一樣）。
		$found = false;
		foreach ( (array) ( $config['top_copies']['items'] ?? [] ) as $copy ) {
			if ( is_array( $copy ) && self::slug_key( (string) ( $copy['slug'] ?? '' ) ) === self::slug_key( $slug )
				&& self::slug_key( (string) ( $copy['parent_slug'] ?? '' ) ) === self::slug_key( $parent ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found || ! self::sidebar_allows( $slug, false, $config, $user_id, [], $parent ) ) {
			return false;
		}

		$key = self::slug_key( self::copy_menu_key( $slug, $parent ) );
		$own = [];
		foreach ( (array) ( $config['wp_native']['items'] ?? [] ) as $row ) {
			if ( '' !== $key && is_array( $row ) && empty( $row['separator'] ) && ! empty( $row['slug'] ) && self::slug_key( (string) $row['slug'] ) === $key ) {
				$own = $row;
			}
		}
		$own_uid = (int) ( $own['self_only_uid'] ?? 0 );
		if ( $own_uid > 0 && $own_uid !== $user_id ) {
			return false;
		}
		if ( is_super_admin( $user_id ) ) {
			return true;
		}
		if ( ! empty( $own['hide'] ) ) {
			return false;
		}
		$allowed = array_values( array_filter( array_map( 'sanitize_key', (array) ( $own['roles'] ?? [] ) ) ) );
		if ( empty( $allowed ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return ! empty( array_intersect( $user ? array_map( 'sanitize_key', (array) $user->roles ) : [], $allowed ) );
	}

	/**
	 * v1.5.0（選單搬家遷移用）：照 $config，某個帳號直接開這一頁的網址會不會被本外掛擋下（guard_hidden_pages() 的同一套判斷）。
	 *
	 * @param array<string,mixed> $config
	 */
	public static function url_allows( string $page, array $config, int $user_id ): bool {
		return self::page_access_decision( self::normalize_admin_slug( $page ), $config, $user_id, is_super_admin( $user_id ) || user_can( $user_id, 'manage_network' ) );
	}

	/**
	 * v1.5.0（選單搬家遷移用）：這個帳號的白名單（側欄用的那一份：新格式優先、沒有才看舊格式；沒有白名單回 null）。
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,string>|null
	 */
	public static function visible_slugs_for_user( array $config, int $user_id ): ?array {
		return self::resolve_visible_slugs_for_user( $config, $user_id );
	}

	/**
	 * 側欄「僅限本人」實際生效的帳號：apply_self_only_filter() 的對照表，同一個 slug 後面的列蓋掉前面（0＝沒綁）。
	 *
	 * @param array<string,mixed> $config
	 */
	private static function sidebar_self_only_uid( string $slug, array $config ): int {
		$uid = 0;
		foreach ( [ 'wp_native', 'ys_cart' ] as $section ) {
			foreach ( self::rule_rows( $config, $section ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['slug'] ) || (int) ( $item['self_only_uid'] ?? 0 ) <= 0 ) {
					continue;
				}
				if ( self::normalize_admin_slug( (string) $item['slug'] ) === $slug ) {
					$uid = (int) $item['self_only_uid'];
				}
			}
		}
		return $uid;
	}

	/**
	 * 把幾個 slug 加進某個帳號已有的白名單（新舊兩種存法都加；沒有白名單的帳號不動）。
	 *
	 * @param array<string,mixed> $config
	 * @param array<int,string>   $slugs
	 * @return array<string,mixed>
	 */
	private static function with_listed_slugs( array $config, int $user_id, array $slugs ): array {
		foreach ( (array) ( $config['user_overrides']['items'] ?? [] ) as $i => $uo ) {
			if ( is_array( $uo ) && (int) ( $uo['user_id'] ?? 0 ) === $user_id && ! empty( $uo['visible_slugs'] ) ) {
				$config['user_overrides']['items'][ $i ]['visible_slugs'] = array_merge( (array) $uo['visible_slugs'], $slugs );
			}
		}
		$key = (string) $user_id;
		if ( ! empty( $config['wp_native']['user_overrides'][ $key ]['visible_slugs'] ) ) {
			$config['wp_native']['user_overrides'][ $key ]['visible_slugs'] = array_merge( (array) $config['wp_native']['user_overrides'][ $key ]['visible_slugs'], $slugs );
		}
		return $config;
	}

	private static function user_can_access_admin_slug( string $slug, array $config, int $user_id, array $user_roles ): bool {
		return self::passes_user_overrides( $slug, $config, $user_id ) && self::passes_item_rules( $slug, $config, $user_roles );
	}

	/**
	 * 個別使用者的限制：舊版 hide_for_user_ids、「僅允許存取受保護的管理選單」、白名單（新舊兩種存法）。
	 */
	private static function passes_user_overrides( string $slug, array $config, int $user_id ): bool {
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
			if ( ! empty( $visible_slugs ) && ! self::whitelist_contains( $visible_slugs, $slug ) ) {
				return false;
			}
			break;
		}

		$legacy = (array) ( $config['wp_native']['user_overrides'] ?? [] );
		$key    = (string) $user_id;
		if ( isset( $legacy[ $key ] ) && is_array( $legacy[ $key ] ) ) {
			$visible_slugs = self::normalize_slug_list_for_match( (array) ( $legacy[ $key ]['visible_slugs'] ?? [] ) );
			if ( ! empty( $visible_slugs ) && ! self::whitelist_contains( $visible_slugs, $slug ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 設定列的規則：任一列（兩個分頁）slug 相同、勾了隱藏或限制角色而不符合，就擋。
	 * v1.5.1：「複製到主選單」的複製項自己的那一列不算（rule_rows()），它只管複製項。
	 *
	 * @param array<int, string> $user_roles
	 */
	private static function passes_item_rules( string $slug, array $config, array $user_roles ): bool {
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( self::rule_rows( $config, $tab ) as $item ) {
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

	/**
	 * 白名單是否涵蓋這個 slug。
	 *
	 * 編輯畫面（post.php、term.php）與上傳端點沒有自己的選單項，勾不到；它們沿用所屬列表頁的結果，
	 * 否則白名單裡的使用者看得到列表、卻打不開任何一筆。只在判斷「目前這個請求」時才這樣對應。
	 *
	 * @param array<int, string> $visible_slugs 已正規化的白名單。
	 */
	private static function whitelist_contains( array $visible_slugs, string $slug ): bool {
		if ( in_array( $slug, $visible_slugs, true ) ) {
			return true;
		}
		foreach ( self::request_owner_slugs( $slug ) as $owner ) {
			if ( in_array( $owner, $visible_slugs, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 目前請求若是沒有選單項的編輯畫面，回傳它所屬的選單 slug（可能有多種寫法）。
	 *
	 * @return array<int, string>
	 */
	private static function request_owner_slugs( string $slug ): array {
		global $pagenow;
		if ( ! is_string( $pagenow ) || $slug !== $pagenow ) {
			return [];
		}

		if ( 'post.php' === $slug ) {
			$post_id   = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : ( isset( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0 );
			$post_type = $post_id > 0 ? (string) get_post_type( $post_id ) : '';
			if ( '' === $post_type ) {
				return [];
			}
			if ( 'attachment' === $post_type ) {
				return [ 'upload.php' ];
			}
			return [ 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . $post_type ];
		}

		if ( 'term.php' === $slug || 'edit-tags.php' === $slug ) {
			$taxonomy  = isset( $_REQUEST['taxonomy'] ) ? sanitize_key( wp_unslash( $_REQUEST['taxonomy'] ) ) : 'post_tag';
			$post_type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : '';
			$owners    = [ 'edit-tags.php?taxonomy=' . $taxonomy ];
			if ( '' !== $post_type && 'post' !== $post_type ) {
				// WordPress 註冊自訂文章類型的分類子選單時用 &amp;；設定頁存下來的是 &。兩種都比對。
				$owners[] = 'edit-tags.php?taxonomy=' . $taxonomy . '&amp;post_type=' . $post_type;
				$owners[] = 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . $post_type;
			}
			return self::normalize_slug_list_for_match( $owners );
		}

		if ( 'async-upload.php' === $slug || 'media-upload.php' === $slug ) {
			return [ 'upload.php', 'media-new.php' ];
		}

		return [];
	}

	/**
	 * v1.5.1：admin.php?page=X（後面可以接其他參數：導覽列、移到主選單的頁面）的 X；其他寫法回空字串。
	 * 跟 YS CART 核心判斷受保護頁面時一樣，看網址指向的那一頁。
	 */
	private static function admin_page_of( string $slug ): string {
		$decoded = wp_specialchars_decode( trim( $slug ) );
		return 1 === preg_match( '/^admin\.php\?page=([^&#=]+)/', $decoded, $m ) ? self::normalize_admin_slug( $m[1] ) : '';
	}

	/**
	 * 本外掛的頁面，以及 YS CART 的頁面（v1.5.1；跟 YS CART 核心的命名空間相同，沒裝 YS CART 時也照這個判斷）。
	 */
	private static function is_ys_cart_namespace_slug( string $slug ): bool {
		return 'ys-admin-menu' === $slug
			|| str_starts_with( $slug, 'ys-admin-menu-' )
			|| 'ys-cart' === $slug
			|| str_starts_with( $slug, 'ys-cart-' )
			|| str_starts_with( $slug, 'ys-ec-' )
			|| str_starts_with( $slug, 'ys-provider-' );
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
					if ( '' !== $slug && ! self::current_user_can_access_admin_slug( $slug, null, (string) $parent ) ) {
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
			$items = self::rule_rows( $config, $section );
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
			$items = self::rule_rows( $config, $section );
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

		// v1.5.0：導覽列（admin.php?page=<YS CART 頁面>&…）在 V3 上也看它指向的那一頁有沒有綁定別人（nav_target()）。
		$bound_elsewhere = static function ( string $slug, ?string $parent = null ) use ( $map, $current_user_id ): bool {
			if ( isset( $map[ $slug ] ) && $map[ $slug ] !== $current_user_id ) {
				return true;
			}
			$target = self::nav_target( $slug, $parent );
			return $target !== $slug && isset( $map[ $target ] ) && $map[ $target ] !== $current_user_id;
		};

		if ( is_array( $menu ) ) {
			foreach ( $menu as $position => $menu_item ) {
				if ( ! is_array( $menu_item ) ) {
					continue;
				}
				$slug = self::normalize_admin_slug( (string) ( $menu_item[2] ?? '' ) );
				if ( $bound_elsewhere( $slug ) ) {
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
					if ( $bound_elsewhere( $slug, (string) $parent ) ) {
						unset( $submenu[ $parent ][ $idx ] );
					}
				}
			}
		}
	}

	/**
	 * 「僅允許存取受保護的管理選單」額外放行的頁面：本外掛的首頁與兩個 filter 指定的頁面。
	 * v1.5.1：不再收「全部選單」分頁存過的列（那張表存的是整個側欄，收進來就幾乎每一頁都放行）。
	 *
	 * @return array<string,bool>
	 */
	private static function ys_cart_admin_slug_map(): array {
		$slugs = [ 'ys-admin-menu' ];

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
		return self::slug_key( $slug );
	}

	/** v1.5.1：slug_key() 的結果（同一個請求裡同一個字串會算很多次）。 */
	private static array $key_cache = [];

	/**
	 * v1.5.1：比對「存下的 slug」與「側欄上實際的 slug」用的鍵。
	 *
	 * - 存檔時 slug 經過 sanitize_text_field()，WordPress 會把 %xx 這種百分比編碼整段拿掉，舊存檔就是這個樣子。
	 *   實際的 slug 經過同一個函式就對得上，不用重存；sanitize_text_field() 對清過的字串不會再改，存下的鍵就是它自己。
	 * - customize.php 的 return 參數是 WordPress 依「目前這一頁」加上的，每一頁都不一樣：只留參數名、不比值。
	 *   直接開 customize.php 的請求鍵是 customize.php（不帶參數），跟「自訂」那一列的鍵不同，直連守門照舊。
	 * - 帶 %xx 的網址與 customize.php 的連結先還原 HTML 實體（設定頁存下的是瀏覽器還原過的字串）。
	 *   其他 slug 不還原（例如自訂文章類型分類子選單的 &amp;）：原本先還原再比的地方照舊先還原，再算鍵。
	 *
	 * 什麼時候用鍵：原字串對得上的列一律照原字串（跟 1.5.0 完全一樣）；只有「存檔時會被改掉」的實際 slug
	 * （slug_is_altered()）原字串對不上，才改用鍵。權限判斷（current_user_can_access_admin_slug() 這一系列）
	 * 從 1.4.0 起就是兩邊都正規化再比，這裡只是讓 customize.php 的 return 也不比。
	 */
	public static function slug_key( string $slug ): string {
		if ( isset( self::$key_cache[ $slug ] ) ) {
			return self::$key_cache[ $slug ];
		}
		$key = trim( $slug );
		if ( '' !== $key ) {
			// 帶 %xx 的網址與 customize.php 的連結：設定頁從表格（data-slug）讀回 slug 時，瀏覽器已經把 HTML 實體還原
			// （&#038;、&amp; 變成 &，例如「頁首」「背景」的 &#038;autofocus），存下的是還原過的；比對時這邊也還原。
			if ( ( self::is_customize_link( $key ) || 1 === preg_match( '/%[0-9a-f]{2}/i', $key ) ) && function_exists( 'wp_specialchars_decode' ) ) {
				$key = (string) wp_specialchars_decode( $key );
			}
			if ( self::is_customize_link( $key ) ) {
				$key = (string) preg_replace( '/([?&;])return=[^&]*/', '$1return=', $key );
			}
			$key = function_exists( 'sanitize_text_field' )
				? (string) sanitize_text_field( $key )
				: ( preg_replace( '/[^\w.\-?=&:\/]/', '', $key ) ?: '' );
		}
		if ( count( self::$key_cache ) >= 5000 ) {
			self::$key_cache = [];
		}
		self::$key_cache[ $slug ] = $key;
		return $key;
	}

	/** v1.5.1：customize.php 帶參數的連結（「自訂」「頁首」「背景」；複製到主選單的 ./customize.php?… 也算）。 */
	private static function is_customize_link( string $slug ): bool {
		return 1 === preg_match( '#^(?:\./)?customize\.php\?#', trim( $slug ) );
	}

	/**
	 * v1.5.1：這個實際的 slug 存檔時會不會被改掉（帶 %xx、前後空白、customize.php 的 return…）。
	 * 會被改掉的，存下的設定跟它的原字串永遠對不上，只能用鍵對。
	 */
	public static function slug_is_altered( string $slug ): bool {
		return '' !== $slug && self::slug_key( $slug ) !== $slug;
	}

	/**
	 * v1.5.1：在一串側欄列（$menu，或 $submenu 的某個上層）裡找存下的 slug 對到的列。
	 *   - 有原字串完全相同的列：就是它們（跟 1.5.0 一樣）。
	 *   - 一列都沒有：找「存檔時會被改掉」、鍵又相同的列（網址帶 %xx 的子選單、「自訂」）。
	 *
	 * @param array<int|string, mixed> $rows
	 * @return array<int, int|string> 對到的列在陣列裡的鍵（依陣列順序）。
	 */
	private static function matching_rows( array $rows, string $stored ): array {
		$exact = [];
		$keyed = [];
		$want  = self::slug_key( $stored );
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$raw = (string) ( $row[2] ?? '' );
			if ( '' === $raw ) {
				continue;
			}
			if ( $raw === $stored ) {
				$exact[] = $i;
			} elseif ( '' !== $want && self::slug_is_altered( $raw ) && self::slug_key( $raw ) === $want ) {
				$keyed[] = $i;
			}
		}
		return [] !== $exact ? $exact : $keyed;
	}

	/**
	 * v1.5.1：存下的上層 slug 對到的實際 $submenu 鍵：原字串有就用它，沒有才找鍵相同、存檔時會被改掉的上層。
	 */
	private static function submenu_parent( string $parent ): ?string {
		global $submenu;
		if ( '' === $parent || ! is_array( $submenu ) ) {
			return null;
		}
		if ( isset( $submenu[ $parent ] ) ) {
			return $parent;
		}
		$want = self::slug_key( $parent );
		if ( '' === $want ) {
			return null;
		}
		foreach ( array_keys( $submenu ) as $p ) {
			$p = (string) $p;
			if ( self::slug_is_altered( $p ) && self::slug_key( $p ) === $want ) {
				return $p;
			}
		}
		return null;
	}

	/** v1.5.1：copy_row_keys() 的結果（同一份 top_copies 只算一次）。 */
	private static array $copy_keys_cache = [];

	/**
	 * v1.5.1：「複製到主選單」的複製項在主選單分頁那一列（slug＝copy_menu_key()）的鍵。
	 *
	 * 這些列只管複製項（insert_top_level_copies()、copy_allows() 另外讀），不參與其他選單的權限判斷：外部網址的複製項代稱就是
	 * 網址本身，跟原子選單同一個字串，不排除的話複製項的角色、隱藏、僅限本人會一起套到原子選單（1.3.0 起）。
	 * ./ 開頭的代稱本來就對不到任何選單，排除了也一樣。
	 *
	 * @param array<string,mixed> $config
	 * @return array<string,bool>
	 */
	private static function copy_row_keys( array $config ): array {
		$copies = $config['top_copies']['items'] ?? [];
		if ( empty( $copies ) || ! is_array( $copies ) ) {
			return [];
		}
		$sig = md5( serialize( $copies ) );
		if ( isset( self::$copy_keys_cache[ $sig ] ) ) {
			return self::$copy_keys_cache[ $sig ];
		}
		$out = [];
		foreach ( $copies as $copy ) {
			if ( ! is_array( $copy ) ) {
				continue;
			}
			$key = self::slug_key( self::copy_menu_key( (string) ( $copy['slug'] ?? '' ), (string) ( $copy['parent_slug'] ?? '' ) ) );
			if ( '' !== $key ) {
				$out[ $key ] = true;
			}
		}
		if ( count( self::$copy_keys_cache ) >= 50 ) {
			self::$copy_keys_cache = [];
		}
		self::$copy_keys_cache[ $sig ] = $out;
		return $out;
	}

	/**
	 * v1.5.1：隱藏、角色、僅限本人要看的設定列：主選單分頁拿掉複製項自己的那幾列（copy_row_keys()）。
	 * 側欄的角色與隱藏過濾、權限判斷、僅限本人、sidebar_allows() 都讀這一份。外部網址的複製項代稱就是網址本身，
	 * 同網址的頂層選單分不出哪一列是誰的，這幾列一律算複製項的，只在插入複製項時用（insert_top_level_copies()）。
	 *
	 * @param array<string,mixed> $config
	 * @return array<int|string, mixed>
	 */
	private static function rule_rows( array $config, string $tab ): array {
		$items = $config[ $tab ]['items'] ?? [];
		if ( ! is_array( $items ) ) {
			return [];
		}
		if ( 'wp_native' !== $tab ) {
			return $items;
		}
		$copy_keys = self::copy_row_keys( $config );
		if ( [] === $copy_keys ) {
			return $items;
		}
		return array_filter(
			$items,
			static fn( $row ): bool => ! ( is_array( $row ) && empty( $row['separator'] ) && isset( $copy_keys[ self::slug_key( (string) ( $row['slug'] ?? '' ) ) ] ) )
		);
	}

	/**
	 * v1.5.1：一串 slug 換成鍵的對照表（鍵 => true）；空字串不收。
	 *
	 * @param array<int, mixed> $slugs
	 * @return array<string, bool>
	 */
	private static function key_set( array $slugs ): array {
		$out = [];
		foreach ( $slugs as $slug ) {
			$key = is_scalar( $slug ) ? self::slug_key( (string) $slug ) : '';
			if ( '' !== $key ) {
				$out[ $key ] = true;
			}
		}
		return $out;
	}

	private static function apply_hide_flags( array $config ): void {
		global $menu, $submenu;

		// 收集所有 hide=true 的 slug（v1.5.1：另收一份鍵，給存檔時會被改掉的實際 slug 用：網址帶 %xx、「自訂」）
		$hidden_top = [];
		$hidden_sub = []; // [ slug => true ]
		$top_keys   = [];
		$sub_keys   = [];

		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			// v1.5.1：外部網址複製項自己的那一列只管複製項（rule_rows()；複製項的隱藏在插入時另外判斷）。
			$items = self::rule_rows( $config, $tab );
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['hide'] ) ) {
					continue;
				}
				$slug  = (string) ( $item['slug'] ?? '' );
				$level = (string) ( $item['level'] ?? 'top' );
				if ( '' === $slug ) {
					continue;
				}
				$key = self::slug_key( $slug );
				if ( 'sub' === $level ) {
					$hidden_sub[ $slug ] = true;
					if ( '' !== $key ) {
						$sub_keys[ $key ] = true;
					}
				} else {
					$hidden_top[ $slug ] = true;
					if ( '' !== $key ) {
						$top_keys[ $key ] = true;
					}
				}
			}
		}

		if ( empty( $hidden_top ) && empty( $hidden_sub ) ) {
			return;
		}

		$hidden = static function ( string $slug, array $exact, array $keys ): bool {
			if ( '' === $slug ) {
				return false;
			}
			return isset( $exact[ $slug ] ) || ( self::slug_is_altered( $slug ) && isset( $keys[ self::slug_key( $slug ) ] ) );
		};

		// Top-level：從 $menu 移除
		if ( ! empty( $hidden_top ) && is_array( $menu ) ) {
			foreach ( $menu as $position => $menu_item ) {
				if ( ! is_array( $menu_item ) ) {
					continue;
				}
				$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
				if ( $hidden( $slug, $hidden_top, $top_keys ) ) {
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
					if ( $hidden( $sub_slug, $hidden_sub, $sub_keys ) ) {
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

		// admin-post.php 是表單處理端點、不是頁面：交給各 handler 自己的 capability 與 nonce 檢查。
		// 擋在這裡會讓白名單裡的頁面送不出表單。
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}

		self::$moves_site = null;
		$page             = self::current_admin_request_slug();
		if ( ! self::page_access_decision( $page, self::get_config(), get_current_user_id(), current_user_can( 'manage_network' ) || is_super_admin() ) ) {
			self::deny_access( $page );
		}
	}

	/**
	 * guard_hidden_pages() 的判斷本體（v1.5.0 抽出來，讓選單搬家遷移能拿「某一份設定」來判斷；順序與規則不變）：
	 *   1. 「僅限本人」優先於 super-admin bypass：非綁定帳號（含其他 super-admin）直接擋。
	 *   2. super-admin bypass。
	 *   3. 存取判斷（current_user_can_access_admin_slug 的規則）。
	 *   4. Layer 1+2：user_overrides.items[] 該使用者的白名單（visible_slugs）。
	 *   5. Layer 3：wp_native + ys_cart items[].hide=true 全域生效。
	 *
	 * @param string              $page 目前請求的頁面（current_admin_request_slug() 的結果）。
	 * @param array<string,mixed> $config
	 */
	private static function page_access_decision( string $page, array $config, int $user_id, bool $is_super ): bool {
		if ( '' === $page ) {
			return true;
		}

		$self_uid = self::self_only_uid_for_slug( $page, $config );
		if ( $self_uid > 0 && $self_uid !== $user_id ) {
			return false;
		}

		if ( $is_super || $user_id <= 0 ) {
			return true;
		}

		$slug = self::normalize_admin_slug( $page );
		if ( '' !== $slug && ! self::access_decision( $slug, $config, $user_id ) ) {
			return false;
		}

		foreach ( (array) ( $config['user_overrides']['items'] ?? [] ) as $uo ) {
			if ( ! is_array( $uo ) || (int) ( $uo['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			$visible = self::normalize_slug_list_for_match( (array) ( $uo['visible_slugs'] ?? [] ) );
			if ( ! empty( $visible ) && ! self::whitelist_contains( $visible, $page ) ) {
				return false;
			}
		}

		// v1.5.1：存下的 slug 也換成鍵再比（$page 已經是鍵；存檔時清過的字串，鍵就是它自己）。
		// 「自訂」那一列的鍵是 customize.php?return=，直接開 customize.php 的鍵是 customize.php，照舊不相等。
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( (array) ( $config[ $tab ]['items'] ?? [] ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['hide'] ) && is_string( $item['slug'] ?? null ) && self::slug_key( $item['slug'] ) === $page ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * v2.39.0 BATCH Q3：終止 request 並回 404
	 *
	 * @param string $page 用於 log / debug
	 */
	private static function current_admin_request_slug(): string {
		global $pagenow, $plugin_page;
		if ( isset( $_GET['page'] ) ) {
			// WordPress 載入外掛頁面前會先正規化 page 參數（去掉前後的斜線等），結果放在 $plugin_page。
			// 用同一個值比對：直接拿網址上的原字串比，多打一個斜線就比不到、頁面卻照常載入。
			if ( is_string( $plugin_page ?? null ) && '' !== $plugin_page ) {
				return self::normalize_admin_slug( $plugin_page );
			}
			return is_string( $_GET['page'] )
				? self::normalize_admin_slug( trim( str_replace( '\\', '/', (string) wp_unslash( $_GET['page'] ) ), '/' ) )
				: '';
		}

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

		// 控制台是登入後的落點。它被規則擋下時，改送到這個帳號看得到的第一個選單，
		// 不讓人一登入就看到 404；沒有任何看得到的選單才照舊顯示 404。
		if ( 'index.php' === $page && 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			$target = self::first_accessible_menu_url();
			if ( '' !== $target ) {
				wp_safe_redirect( $target );
				exit;
			}
		}

		wp_die(
			esc_html__( '此頁面不存在或您無權存取。', 'ys-admin-menu' ),
			esc_html__( '404', 'ys-admin-menu' ),
			[ 'response' => 404 ]
		);
	}

	/**
	 * 這個帳號目前看得到、也進得去的第一個選單的網址（控制台除外）；沒有就回空字串。
	 *
	 * admin_init 時 $menu 已經套完本外掛的規則，WordPress 也已拿掉沒有權限的項目。
	 */
	private static function first_accessible_menu_url(): string {
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) {
			return '';
		}

		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug    = (string) ( $item[2] ?? '' );
			$classes = (string) ( $item[4] ?? '' );
			if ( '' === $slug || 'index.php' === $slug
				|| false !== strpos( $classes, 'wp-menu-separator' )
				|| false !== strpos( $classes, 'ys-am-section-label' ) ) {
				continue;
			}
			if ( ! current_user_can( (string) ( $item[1] ?? 'read' ) ) ) {
				continue;
			}

			// 有子選單時，WordPress 的頂層連結指向第一個子項。
			$target = $slug;
			$parent = null;
			if ( ! empty( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
				$first = reset( $submenu[ $slug ] );
				if ( is_array( $first ) && ! empty( $first[2] ) ) {
					$target = (string) $first[2];
					$parent = $slug;
				}
			}
			if ( ! self::current_user_can_access_admin_slug( $target, null, $parent ) ) {
				continue;
			}

			$target = wp_specialchars_decode( $target );
			if ( false !== strpos( $target, '.php' ) ) {
				return admin_url( $target );
			}
			$url = function_exists( 'menu_page_url' ) ? (string) menu_page_url( $target, false ) : '';
			return '' !== $url ? $url : admin_url( 'admin.php?page=' . rawurlencode( $target ) );
		}

		return '';
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
		$altered_by_key   = []; // v1.5.1：存檔時會被改掉的 slug：鍵 => [ slug, … ]（側欄順序）
		foreach ( $menu as $menu_item ) {
			if ( ! is_array( $menu_item ) ) {
				continue;
			}
			$slug = isset( $menu_item[2] ) ? (string) $menu_item[2] : '';
			if ( '' === $slug ) {
				continue;
			}
			$existing_by_slug[ $slug ] = $menu_item;
			if ( self::slug_is_altered( $slug ) && '' !== self::slug_key( $slug ) ) {
				$altered_by_key[ self::slug_key( $slug ) ][ $slug ] = true;
			}
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
			if ( '' === $slug ) {
				continue;
			}
			if ( ! isset( $existing_by_slug[ $slug ] ) ) {
				// v1.5.1：原字串對不到時，改用鍵相同、還沒排進去的第一列（網址帶 %xx、「自訂」的 return 每頁不同）。
				$found = null;
				foreach ( array_keys( $altered_by_key[ self::slug_key( $slug ) ] ?? [] ) as $candidate ) {
					if ( ! isset( $used_slugs[ (string) $candidate ] ) ) {
						$found = (string) $candidate;
						break;
					}
				}
				if ( null === $found ) {
					continue;
				}
				$slug = $found;
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
	 * 這些 id 無法從 slug 推導，所以從 $menu 讀出每一項實際輸出的 id 再產生規則。
	 */
	public static function output_colors(): void {
		$config = self::get_config();
		$items  = (array) ( $config['wp_native']['items'] ?? [] );
		if ( empty( $items ) ) {
			return;
		}

		// slug → 側欄 <li> 的 id。產生方式與 wp-admin/menu-header.php 相同（核心選單的 id 是
		// menu-dashboard、menu-pages 這種語意名稱，無法從 slug 推出來，只能查 $menu）。
		global $menu;
		$id_by_slug = [];
		$id_by_key  = []; // v1.5.1：存檔時會被改掉的 slug（網址帶 %xx、「自訂」）：鍵 => id（第一個）
		foreach ( (array) $menu as $menu_item ) {
			if ( is_array( $menu_item ) && isset( $menu_item[2] ) && ! empty( $menu_item[5] ) ) {
				$li_id = (string) preg_replace( '|[^a-zA-Z0-9_:.]|', '-', (string) $menu_item[5] );
				$slug  = (string) $menu_item[2];
				$id_by_slug[ $slug ] = $li_id;
				if ( self::slug_is_altered( $slug ) && '' !== self::slug_key( $slug ) && ! isset( $id_by_key[ self::slug_key( $slug ) ] ) ) {
					$id_by_key[ self::slug_key( $slug ) ] = $li_id;
				}
			}
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
			$li_id = $id_by_slug[ $slug ] ?? ( $id_by_key[ self::slug_key( $slug ) ] ?? '' );
			if ( '' === $li_id ) {
				continue; // 這個身分的側欄沒有這一項。
			}
			// 文字與字型圖示一起上色：啟用原生選單樣式時，文字與圖示各有自己的顏色規則，只改連結看不出來。
			$rules[] = sprintf(
				'#adminmenu li#%1$s > a.menu-top, #adminmenu li#%1$s > a.menu-top .wp-menu-name, #adminmenu li#%1$s > a.menu-top .wp-menu-image::before { color: %2$s !important; }',
				esc_attr( $li_id ),
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
