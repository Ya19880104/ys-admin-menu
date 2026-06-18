<?php
/**
 * 權限與選單管理（v2.39.0 BATCH Q — 權限設置完善）
 *
 * v2.45.20 Phase R2.4-H：權限設置改套 YS CART admin shell（YSAdminApp::open/close）
 * --------------------------------------------------------------------------------
 * 過往（v2.39.12）刻意保留 WP-native UI，理由：權限設置給「架站者」使用、需切
 * 換到使用者/外掛/外觀等 WP 原生 sidebar。v2.45.20 重新評估：
 *
 *   1. 業界白牌標準是「整個 admin 介面 by default 都應同 visual surface」，即使
 *      此頁需要 WP-native sidebar、user 仍可透過 topbar 的 WP chrome toggle 開回。
 *   2. v2.45.x 系列 R2.4-G（白牌設置）/ R2.4-H（權限設置）同步收編到 YS shell，
 *      避免 sidebar nav 跳出 YS 視覺、影響白牌一致性。
 *   3. 「商店設定 / 權限」改放在 breadcrumb 表達層級，YS sidebar nav 仍導回電商
 *      工作流程；架站者真的要切 WP-native menu，topbar WP chrome toggle 一鍵展開。
 *
 * 變更：
 *   - render() 在 cap check 後、tab 分派之前統一 YSAdminApp::open( '權限設置',
 *     '商店設定 / 權限' )，render_rest_ui / render_user_overrides 結尾不再各自重複
 *     wrap，且舊 <h1>YS CART — 權限設置</h1> 由 shell page-head 提供、不再 render。
 *   - 舊 <div class="wrap ys-ec-permission-admin"> 改成 <div class="ys-ec-permission-admin
 *     ysca-section">（hub-style section 容器），維持既有 CSS scope。
 *   - capability matrix 表單 nonce / cap_check / REST save 邏輯**完全不變**，只動 wrapper。
 *
 * v2.38.2 → v2.39.0 變更（BATCH Q）：
 *   - Q1：使用者覆寫 tab 從 legacy UI 完整重做為 server-side render + REST save
 *           （schema：user_overrides.items[] = { user_id, user_login, user_email, visible_slugs, hide_ys_cart }）
 *   - Q2：個別選單 row 高度由 ~80px → ~38-40px（compact rows）；title + slug 同行 inline；
 *           title_override 變成 dashed-border 行內 input（hover-edit pattern）
 *   - Q3：新增「隱藏」checkbox 欄位，勾選後（YSMenuRouter）既不顯示也擋直接訪問
 *   - Q4：進階介面 已 OK；授權設置 placeholder 留待未來版本（v2.x license server）
 *
 * 三個子分頁（?tab=）：
 *   - wp_native       ：WordPress 原生選單（順序 / role / 顏色 / 隱藏 / 分隔列）
 *   - ys_cart         ：YS CART 選單（top + sub，順序 / role / 隱藏 / 分隔列）
 *   - user_overrides  ：個別使用者覆寫（v2.39 完整重做：search user → card list + REST save）
 *
 * Schema（wp_options 'ys_admin_menu_config'，由 YSMenuRouter 消費）：
 * ```
 * {
 *   "wp_native": {
 *     "items": [
 *       { "slug": "index.php", "title_override": null, "order": 10, "color": "#0073aa",
 *         "roles": ["administrator"], "hide": false },
 *       { "separator": true, "title": "— 內容 —", "order": 30 },
 *       ...
 *     ]
 *   },
 *   "ys_cart": {
 *     "items": [
 *       { "slug": "ys-ec-orders", "order": 10, "roles": ["administrator","shop_manager"],
 *         "level": "sub", "parent_slug": "ys-ec-dashboard", "hide": false },
 *       ...
 *     ],
 *     "hide_for_user_ids": [5, 7]   // legacy；v2.39 改走 user_overrides.items
 *   },
 *   "user_overrides": {
 *     "items": [
 *       { "user_id": 5, "user_login": "alice", "user_email": "alice@example.com",
 *         "visible_slugs": ["index.php","ys-ec-products"], "hide_ys_cart": false }
 *     ]
 *   }
 * }
 * ```
 *
 * 安全：
 *   - cap：manage_options
 *   - REST nonce：wp_rest（X-WP-Nonce header）
 *   - sanitize 移到 YSPermissionController 集中管理
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   2.37.1
 * @updated 2.39.0
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Rest\YSPermissionController;
use YangSheep\AdminMenu\Menu\YSMenuRouter;

defined( 'ABSPATH' ) || exit;

class YSPermissionAdmin {

	private const SORTABLEJS_CDN       = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
	private const SORTABLEJS_INTEGRITY = 'sha384-BSxuMLxX+FCbTdYec3TbXlnMGEEM2QXTFdtDaveen71o+jswm2J36+xFqp8k4VHM';
	private static bool $sortablejs_tag_filter_registered = false;

	/**
	 * v2.37.1 legacy nonce / action 常數 — 保留以避免外部相依破壞，
	 * 但 register() 已不再 hook admin_post，handle_save() 也不再運作。
	 */
	public const NONCE_ACTION      = 'ys_ec_save_menu_config';
	public const ADMIN_POST_ACTION = 'ys_ec_save_menu_config';

	/**
	 * 註冊 hook
	 *
	 * v2.38.2：刪除 admin_post hook，所有寫入改走 REST
	 * （YSPermissionController 由 YSAdminRouteRegistrar 註冊）。
	 */
	public static function register(): void {
		// no-op：v2.38.2 起所有 admin 互動走 REST。
		// 保留方法簽章避免外部呼叫者壞掉。
	}

	/**
	 * 渲染權限設置頁
	 *
	 * 分頁 ?tab=：
	 *   - wp_native      （預設）動態列出所有 sidebar top-level
	 *   - ys_cart        列出 YS CART top + 全部 sub-page
	 *   - user_overrides v2.39.0 完整重做（search user + card list + REST save）
	 *
	 * v2.45.20 Phase R2.4-H：cap check 後統一 YSAdminApp::open() / close()。
	 * Capability matrix nonce / cap_check / REST save 邏輯**完全不變**，只動 wrapper。
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '權限不足。' );
		}

		$tab_raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'wp_native';
		$tab     = in_array( $tab_raw, [ 'wp_native', 'ys_cart', 'user_overrides' ], true ) ? $tab_raw : 'wp_native';

		// 三個 tab 都共用一份 enqueue（含 sortablejs / wp-color-picker / 主 JS / 主 CSS）
		self::enqueue_assets();

		// v2.45.29: 完全純 WP-native UI（user 明確要求：白牌/授權/權限是給架站者用、
		// 不共用 YS CART 樣式、避免污染 native pages）。不開 YSAdminApp shell、
		// 不套 .ysca-page-root、用 WP-native .wrap + <h1>。
		echo '<div class="wrap">';
		echo '<h1>權限設置</h1>';

		if ( 'user_overrides' === $tab ) {
			self::render_user_overrides();
		} else {
			// wp_native / ys_cart 走新 REST + SortableJS UI
			self::render_rest_ui( $tab );
		}

		echo '</div>';
	}

	/**
	 * Enqueue scripts/styles for 新 REST UI
	 */
	private static function enqueue_assets(): void {
		// WP color picker（wp_native tab 才用，但兩個 tab 都共用 admin page → 統一 enqueue）
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// SortableJS — CDN（jsDelivr 為主來源，可被 filter override 成 local bundle）
		// 註：之所以用 CDN 而非 local，原因：
		//   1. Sortable.min.js 約 28KB，CDN 命中率高
		//   2. 整個 plugin 目前沒有其他第三方 vendor JS 內含
		//   3. 開放 filter `ys_ec_sortablejs_src` 給離線環境改 local
		$sortable_src = apply_filters(
			'ys_admin_menu_sortablejs_src',
			self::SORTABLEJS_CDN
		);
		wp_enqueue_script( 'sortablejs', $sortable_src, [], '1.15.2', true );
		if ( self::SORTABLEJS_CDN === $sortable_src && ! self::$sortablejs_tag_filter_registered ) {
			add_filter( 'script_loader_tag', [ self::class, 'add_sortablejs_sri' ], 10, 3 );
			self::$sortablejs_tag_filter_registered = true;
		}

		wp_enqueue_script(
			'ys-admin-menu-permission',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/js/ys-admin-menu-permission.js',
			[ 'sortablejs', 'wp-color-picker', 'jquery' ],
			YS_ADMIN_MENU_VERSION,
			true
		);

		wp_localize_script( 'ys-admin-menu-permission', 'ysPermissionAdmin', [
			'restUrl'    => esc_url_raw( rest_url( 'ys-admin-menu/v1/admin/permissions' ) ),
			'usersUrl'   => esc_url_raw( rest_url( 'wp/v2/users' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		] );

		wp_enqueue_style(
			'ys-admin-menu-permission',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/ys-admin-menu-permission.css',
			[ 'wp-color-picker', 'ys-admin-menu-ds-components' ],
			YS_ADMIN_MENU_VERSION
		);
	}

	public static function add_sortablejs_sri( string $tag, string $handle, string $src ): string {
		if ( 'sortablejs' !== $handle || self::SORTABLEJS_CDN !== $src || false !== strpos( $tag, ' integrity=' ) ) {
			return $tag;
		}

		return str_replace(
			'<script ',
			'<script integrity="' . esc_attr( self::SORTABLEJS_INTEGRITY ) . '" crossorigin="anonymous" referrerpolicy="no-referrer" ',
			$tag
		);
	}

	/**
	 * 主 render — wp_native / ys_cart 新 UI（v2.38.2 hotfix：改成 server-side render）
	 *
	 * 為何不再靠 JS fetch /menu-enumeration：
	 *   - REST request context 的 $menu / $submenu 通常為空（多數 plugin 的 admin_menu
	 *     hook 內有 if(!is_admin()) return guard，is_admin() 在 REST 為 false → skip）
	 *   - 即使 ensure_admin_menu_populated() 主動 do_action('admin_menu')，多數 plugin 仍
	 *     不會註冊（原因如上）
	 *   - 結果是 enumeration 永遠回傳 0 / 0，user 看到的表格永遠是空
	 *
	 * 解法：render() 是由 add_submenu_page 的 callback 執行，這個 callback 跑在
	 * wp-admin 完整 context、admin_menu hook 完成後 → $menu / $submenu 必定 populated
	 * → 直接讀全域 + 在 PHP 內輸出 <tr> rows，根本不需要再 fetch enumeration。
	 *
	 * JS 仍處理：drag (SortableJS)、新增分隔列、刪除 row、儲存（POST /menu-config）
	 *
	 * v2.39.0 BATCH Q2 + Q3：compact rows + hide checkbox column
	 *
	 * @param string $tab 'wp_native' | 'ys_cart'
	 */
	private static function render_rest_ui( string $tab ): void {
		$base_url   = admin_url( 'admin.php?page=' . YSMenuPage::SLUG_PERMISSIONS );
		$tabs       = [
			'wp_native'      => '主選單（頂層）',
			'ys_cart'        => '全部選單（含子選單）',
			'user_overrides' => '使用者覆寫',
		];
		$tab_title  = $tabs[ $tab ] ?? '選單';
		$tab_icon   = ( 'wp_native' === $tab ) ? 'dashicons-admin-settings' : 'dashicons-menu';
		$tab_desc   = ( 'wp_native' === $tab )
			? '管理 wp-admin 左側 sidebar 上 <strong>所有</strong>頂層選單（含 WordPress 核心與第三方外掛）：拖拉調整順序、限制角色可見、套色、勾選「隱藏」完全藏起來，或插入「空白標題」分隔列。未列入此處的選單會接續顯示於設定排序之後。'
			: '管理 <strong>所有</strong>選單端點 — 不只頂層，包含每個頂層底下的子選單（sub-page）。可拖拉調整順序、限制角色可見、勾選「隱藏」、插入分隔列。';

		// v2.38.2 hotfix：直接在 admin context 讀全域 $menu / $submenu，在伺服器端輸出 rows
		$items        = ( 'wp_native' === $tab )
			? YSPermissionController::get_all_admin_menu_items()
			: YSPermissionController::get_all_ys_cart_endpoints();
		$saved_config = YSMenuRouter::get_config();
		$saved_slice  = (array) ( $saved_config[ $tab ] ?? [] );
		$saved_items  = (array) ( $saved_slice['items'] ?? [] );

		// Index saved items by slug + 收集 separator
		$saved_by_slug    = [];
		$saved_separators = [];
		foreach ( $saved_items as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! empty( $row['separator'] ) ) {
				$saved_separators[] = $row;
				continue;
			}
			if ( ! empty( $row['slug'] ) ) {
				$saved_by_slug[ (string) $row['slug'] ] = $row;
			}
		}
		?>
		<div class="ys-ec-permission-admin ysca-section">
			<?php // v2.45.20 Phase R2.4-H：page title 由 YSAdminApp::open() 的 page-head 提供，不再 echo <h1>。 ?>

			<nav class="ysca-wl-tabs" role="tablist" aria-label="權限設置分頁">
				<?php foreach ( $tabs as $key => $label ) :
					$is_active = ( $tab === $key );
					$class     = 'ysca-wl-tab' . ( $is_active ? ' ysca-wl-tab--active' : '' );
					$href      = add_query_arg( 'tab', $key, $base_url );
					?>
					<a href="<?php echo esc_url( $href ); ?>" class="<?php echo esc_attr( $class ); ?>"
						role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ysca-card ysca-section-card">
				<h3>
					<span class="dashicons <?php echo esc_attr( $tab_icon ); ?>"></span>
					<?php echo esc_html( $tab_title ); ?>
				</h3>
				<div class="inside">
					<p class="description ysca-muted-text ysca-permission-description">
						<?php echo wp_kses_post( $tab_desc ); ?>
					</p>

					<p>
						<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--ghost" id="ys-ec-add-separator">
							<span class="dashicons dashicons-plus ysca-icon-align-middle"></span>
							新增空白標題（分隔列）
						</button>
						<button type="button" class="ysca-btn ysca-btn--primary ysca-btn--sm" id="ys-ec-save-permissions">
							儲存設定
						</button>
						<span id="ys-ec-save-status" aria-live="polite"></span>
					</p>

					<div class="ysca-table-wrap ysca-table-scroll">
					<table class="ys-ec-permission-table widefat ysca-table" data-tab="<?php echo esc_attr( $tab ); ?>">
						<thead>
							<tr>
								<th class="ysca-table__drag"></th>
								<th class="ysca-table__order">順序</th>
								<th>選單</th>
								<th class="ysca-table__roles">角色可見</th>
								<?php if ( 'wp_native' === $tab ) : ?>
									<th class="ysca-table__color">顏色</th>
								<?php endif; ?>
								<th class="ysca-table__state ysca-text-center">隱藏</th>
								<th class="ysca-table__actions">操作</th>
							</tr>
						</thead>
						<tbody id="ys-ec-permission-rows">
							<?php
							if ( empty( $items ) ) :
								$colspan = ( 'wp_native' === $tab ) ? 7 : 6;
								?>
								<tr>
									<td colspan="<?php echo (int) $colspan; ?>"
										class="ysca-empty-row ysca-text-center">
										目前讀取不到任何選單項目。請確認此頁面從 wp-admin 直接開啟（而非 REST API 呼叫）。
									</td>
								</tr>
								<?php
							else :
								$idx = 0;
								foreach ( $items as $item ) :
									$idx++;
									$slug  = (string) ( $item['slug'] ?? '' );
									if ( '' === $slug ) {
										continue;
									}
									$saved = $saved_by_slug[ $slug ] ?? [];
									$order = isset( $saved['order'] ) ? (int) $saved['order'] : ( $idx * 10 );
									self::render_item_row( $tab, $item, $saved, $order );
								endforeach;

								// 已存 separator 接尾巴（user 拖拉到適當位置 / 儲存後保持順序）
								foreach ( $saved_separators as $sep ) :
									self::render_separator_row(
										$tab,
										(string) ( $sep['title'] ?? '' ),
										(int) ( $sep['order'] ?? 0 )
									);
								endforeach;
							endif;
							?>
						</tbody>
					</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染一般 menu item row（v2.39.0 BATCH Q2 + Q3：compact + hide cb）
	 *
	 * 結構（單行 ~38px 高）：
	 *   | drag | order | menu-line(strong+code+badge) + title-override-inline | roles | [color?] | hide | × |
	 *
	 * @param string               $tab    'wp_native' | 'ys_cart'
	 * @param array<string, mixed> $item   enumeration item（slug / title / level / parent_slug / icon）
	 * @param array<string, mixed> $saved  該 slug 的已存設定（color / roles / title_override / hide）
	 * @param int                  $order  最終要寫入 input 的 order 值
	 */
	private static function render_item_row( string $tab, array $item, array $saved, int $order ): void {
		$slug           = (string) ( $item['slug'] ?? '' );
		$title          = (string) ( $item['title'] ?? $slug );
		$level          = (string) ( $item['level'] ?? 'top' );
		$parent_slug    = isset( $item['parent_slug'] ) && '' !== (string) $item['parent_slug']
			? (string) $item['parent_slug']
			: null;
		$roles          = isset( $saved['roles'] ) && is_array( $saved['roles'] ) ? $saved['roles'] : [];
		$color          = (string) ( $saved['color'] ?? '' );
		$title_override = (string) ( $saved['title_override'] ?? '' );
		$hide           = ! empty( $saved['hide'] );

		$role_options = [
			'administrator' => 'Admin',
			'shop_manager'  => 'SM',
			'editor'        => 'Editor',
		];

		$parent_attr = ( null !== $parent_slug )
			? ' data-parent="' . esc_attr( $parent_slug ) . '"'
			: '';

		$is_sub = ( 'sub' === $level );
		?>
		<tr data-slug="<?php echo esc_attr( $slug ); ?>" data-level="<?php echo esc_attr( $level ); ?>"<?php echo $parent_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>>
			<td class="ys-ec-drag-handle" title="拖拉以排序">&#x205E;&#x205E;</td>
			<td>
				<input type="number" class="ys-ec-order-input" value="<?php echo esc_attr( (string) $order ); ?>"
					min="0" max="9999">
			</td>
			<td>
				<div class="menu-line">
					<?php if ( $is_sub ) : ?>
						<span class="menu-indent">&#8627;</span>
					<?php endif; ?>
					<strong><?php echo esc_html( $title ); ?></strong>
					<code><?php echo esc_html( $slug ); ?></code>
					<span class="level-badge<?php echo $is_sub ? ' level-sub' : ''; ?>"><?php echo $is_sub ? '子頁' : '頂層'; ?></span>
				</div>
				<input type="text" class="title-override-inline ys-ec-title-override"
					value="<?php echo esc_attr( $title_override ); ?>"
					placeholder="自訂顯示名稱（留空保留原名）">
			</td>
			<td>
				<div class="role-cb-group">
					<?php foreach ( $role_options as $role_key => $role_short ) : ?>
						<label>
							<input type="checkbox" class="ys-ec-role-cb"
								data-role="<?php echo esc_attr( $role_key ); ?>"
								<?php checked( in_array( $role_key, $roles, true ) ); ?>>
							<?php echo esc_html( $role_short ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</td>
			<?php if ( 'wp_native' === $tab ) : ?>
				<td>
					<input type="text" class="ys-ec-color-picker"
						value="<?php echo esc_attr( $color ); ?>"
						data-default-color=""
						placeholder="#0073aa" maxlength="7">
				</td>
			<?php endif; ?>
			<td class="ys-ec-hide-cb">
				<input type="checkbox" class="ys-ec-hide-checkbox" title="勾選後完全隱藏（含 sidebar + 直接訪問）"
					<?php checked( $hide ); ?>>
			</td>
			<td>
				<button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-delete-row" title="從清單移除">×</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * 渲染 separator row（v2.39.0：與 main row colspan 對齊）
	 */
	private static function render_separator_row( string $tab, string $title, int $order ): void {
		// 主 row column 數：wp_native = 7（drag/order/menu/role/color/hide/del），ys_cart = 6（無 color）
		// separator 的 colspan = main 中間欄（menu+role+color+hide）= wp_native:4 / ys_cart:3
		$colspan = ( 'wp_native' === $tab ) ? 4 : 3;
		?>
		<tr data-separator="1" class="ys-ec-separator-row ysca-separator-row">
			<td class="ys-ec-drag-handle" title="拖拉以排序">&#x205E;&#x205E;</td>
			<td>
				<input type="number" class="ys-ec-order-input" value="<?php echo esc_attr( (string) $order ); ?>"
					min="0" max="9999">
			</td>
			<td colspan="<?php echo (int) $colspan; ?>" class="ysca-separator-cell">
				<strong class="ysca-separator-title">&mdash;
					<input type="text" class="ys-ec-separator-title ysca-separator-input"
						value="<?php echo esc_attr( $title ); ?>"
						placeholder="標題文字">
					&mdash;</strong>
				<em class="ysca-separator-note ysca-text-muted">(分隔列)</em>
			</td>
			<td>
				<button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-delete-row" title="從清單移除">×</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * 渲染 user_overrides tab（v2.39.0 BATCH Q1 完整重做）
	 *
	 * 取代 v2.37.1 的 render_legacy_user_overrides() — 改成：
	 *   1. 上方 toolbar：search input + 「+ 新增使用者覆寫」 button + 「儲存」 button
	 *   2. 主清單：每個 user 一張 card（user_login + email + 「移除」 button）
	 *   3. card 內：「完全隱藏 YS CART」checkbox + details（展開可見 slug 多選 grid）
	 *   4. JS：debounced fetch wp/v2/users 自動完成，append/remove card
	 *   5. Save：JS 收集 cards → POST /menu-config payload.user_overrides.items[]
	 */
	private static function render_user_overrides(): void {
		$base_url        = admin_url( 'admin.php?page=' . YSMenuPage::SLUG_PERMISSIONS );
		$config          = YSMenuRouter::get_config();
		$saved_overrides = self::normalize_user_overrides_for_render( $config );
		$all_slugs       = self::collect_all_known_slugs();

		$tabs = [
			'wp_native'      => '主選單（頂層）',
			'ys_cart'        => '全部選單（含子選單）',
			'user_overrides' => '使用者覆寫',
		];
		?>
		<div class="ys-ec-permission-admin ysca-section">
			<?php // v2.45.20 Phase R2.4-H：page title 由 YSAdminApp::open() 的 page-head 提供，不再 echo <h1>。 ?>

			<nav class="ysca-wl-tabs" role="tablist" aria-label="權限設置分頁">
				<?php foreach ( $tabs as $key => $label ) :
					$is_active = ( 'user_overrides' === $key );
					$class     = 'ysca-wl-tab' . ( $is_active ? ' ysca-wl-tab--active' : '' );
					$href      = add_query_arg( 'tab', $key, $base_url );
					?>
					<a href="<?php echo esc_url( $href ); ?>" class="<?php echo esc_attr( $class ); ?>"
						role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ysca-card ysca-section-card">
				<h3>
					<span class="dashicons dashicons-admin-users"></span>
					使用者覆寫（指定使用者可見的選單）
				</h3>
				<div class="inside">
					<p class="description ysca-muted-text ysca-permission-description">
						為特定使用者設定「白名單」選單可見範圍。勾選的選單以外，該使用者
						<strong>看不到</strong>且<strong>無法直接訪問</strong>。<br>
						勾選「僅允許存取受保護的管理選單」後，該使用者只能看到本外掛與透過 <code>ys_admin_menu_extra_admin_slugs</code> filter 指定的選單（含 sidebar 與直接 URL 訪問）。
					</p>

					<div class="ys-ec-user-overrides-toolbar">
						<div class="ys-ec-user-search-wrap">
							<input type="text" id="ys-ec-user-search"
								placeholder="搜尋使用者（輸入帳號或 email，至少 2 字元）"
								autocomplete="off">
							<ul class="ys-ec-user-search-results" id="ys-ec-user-search-results" hidden></ul>
						</div>
						<button type="button" class="ysca-btn ysca-btn--primary ysca-btn--sm" id="ys-ec-save-permissions">
							儲存設定
						</button>
						<span id="ys-ec-save-status" aria-live="polite"></span>
					</div>

					<div id="ys-ec-user-overrides-list" data-all-slugs='<?php echo esc_attr( wp_json_encode( $all_slugs ) ); ?>'>
						<?php
						if ( empty( $saved_overrides ) ) :
							?>
							<div class="empty-overrides" id="ys-ec-empty-overrides">
								目前還沒有任何使用者覆寫。<br>
								搜尋使用者後點擊建立第一筆覆寫設定。
							</div>
							<?php
						else :
							foreach ( $saved_overrides as $ov ) :
								self::render_user_override_card( $ov, $all_slugs );
							endforeach;
						endif;
						?>
					</div>

					<!-- 隱藏的 template，給 JS 動態 append 新 card 用 -->
					<template id="ys-ec-user-override-card-template">
						<?php self::render_user_override_card_template( $all_slugs ); ?>
					</template>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染單一 user override card（v2.39.0 Q1）
	 *
	 * @param array<string, mixed>  $ov         user override row（user_id / user_login / user_email / visible_slugs / hide_ys_cart）
	 * @param array<string, string> $all_slugs  全部可選 slug → title 對應表
	 */
	private static function render_user_override_card( array $ov, array $all_slugs ): void {
		$user_id       = (int) ( $ov['user_id'] ?? 0 );
		$user_login    = (string) ( $ov['user_login'] ?? '' );
		$user_email    = (string) ( $ov['user_email'] ?? '' );
		$visible_slugs = (array) ( $ov['visible_slugs'] ?? [] );
		$ys_cart_only  = ! empty( $ov['ys_cart_only'] );
		$slug_count    = count( $all_slugs );
		$picked_count  = count( $visible_slugs );
		?>
		<div class="ys-ec-user-override-card" data-user-id="<?php echo (int) $user_id; ?>">
			<div class="card-header">
				<strong><?php echo esc_html( $user_login ); ?></strong>
				<code><?php echo esc_html( $user_email ); ?></code>
				<span class="ysca-table__id ysca-text-muted">ID #<?php echo (int) $user_id; ?></span>
				<button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-remove-override" title="移除此使用者覆寫">移除此使用者覆寫</button>
			</div>
			<div class="card-body">
				<label class="ys-cart-only-label">
					<input type="checkbox" class="ys-cart-only" <?php checked( $ys_cart_only ); ?>>
					僅允許存取受保護的管理選單
				</label>
				<details>
					<summary>
						展開可見 slug 清單（已選 <span class="picked-count"><?php echo (int) $picked_count; ?></span> / <?php echo (int) $slug_count; ?> 個；空白 = 不限制）
					</summary>
					<div class="slug-grid">
						<?php foreach ( $all_slugs as $slug => $title ) : ?>
							<label>
								<input type="checkbox" class="visible-slug" value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $visible_slugs, true ) ); ?>>
								<span><?php echo esc_html( $title ); ?></span>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</details>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染 user override card template（給 JS 動態 append 用）
	 *
	 * 輸出與 render_user_override_card 結構相同，但用 placeholder（{{user_login}} 等）
	 * 給 JS 在 clone 時 string-replace。
	 */
	private static function render_user_override_card_template( array $all_slugs ): void {
		$slug_count = count( $all_slugs );
		?>
		<div class="ys-ec-user-override-card" data-user-id="{{user_id}}">
			<div class="card-header">
				<strong>{{user_login}}</strong>
				<code>{{user_email}}</code>
				<span class="ysca-table__id ysca-text-muted">ID #{{user_id}}</span>
				<button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-remove-override" title="移除此使用者覆寫">移除此使用者覆寫</button>
			</div>
			<div class="card-body">
				<label class="ys-cart-only-label">
					<input type="checkbox" class="ys-cart-only" checked>
					僅允許存取受保護的管理選單
				</label>
				<details>
					<summary>
						展開可見 slug 清單（已選 <span class="picked-count">0</span> / <?php echo (int) $slug_count; ?> 個；空白 = 不限制）
					</summary>
					<div class="slug-grid">
						<?php foreach ( $all_slugs as $slug => $title ) : ?>
							<label>
								<input type="checkbox" class="visible-slug" value="<?php echo esc_attr( $slug ); ?>">
								<span><?php echo esc_html( $title ); ?></span>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</div>
				</details>
			</div>
		</div>
		<?php
	}

	/**
	 * 把 v2.37.1 / v2.38.x 的舊 schema 與 v2.39.0 新 items[] schema 都標準化成 render-ready 格式
	 *
	 * 舊 schema 1（wp_native.user_overrides[user_id]）：
	 *   { "5": { "visible_slugs": [...] } }
	 * 舊 schema 2（ys_cart.hide_for_user_ids[]）：
	 *   [5, 7]
	 * 新 schema（user_overrides.items[]）：
	 *   [ { "user_id": 5, "user_login": "alice", "user_email": "...", "visible_slugs": [...], "hide_ys_cart": false }, ... ]
	 *
	 * @return array<int, array{user_id:int,user_login:string,user_email:string,visible_slugs:array,hide_ys_cart:bool}>
	 */
	private static function normalize_user_overrides_for_render( array $config ): array {
		$out = [];

		// 1. v2.39 新 schema 優先
		$new_items = (array) ( $config['user_overrides']['items'] ?? [] );
		foreach ( $new_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$user_id = (int) ( $item['user_id'] ?? 0 );
			if ( $user_id <= 0 ) {
				continue;
			}
			$user        = get_userdata( $user_id );
			$visible     = (array) ( $item['visible_slugs'] ?? [] );
			$out[ $user_id ] = [
				'user_id'       => $user_id,
				'user_login'    => $user
					? (string) ( $user->user_login ?: $user->display_name )
					: (string) ( $item['user_login'] ?? ( '使用者 ID ' . $user_id ) ),
				'user_email'    => $user
					? (string) $user->user_email
					: (string) ( $item['user_email'] ?? '（已不存在）' ),
				'visible_slugs' => array_values( array_unique( array_map( 'sanitize_text_field', $visible ) ) ),
				'ys_cart_only'  => ! empty( $item['ys_cart_only'] ) || ! empty( $item['hide_ys_cart'] ),
			];
		}

		// 2. v2.37.1 legacy: wp_native.user_overrides → 補進去（避免遺漏舊資料）
		$legacy = (array) ( $config['wp_native']['user_overrides'] ?? [] );
		foreach ( $legacy as $uid => $row ) {
			$user_id = (int) $uid;
			if ( $user_id <= 0 || isset( $out[ $user_id ] ) ) {
				continue;
			}
			$user    = get_userdata( $user_id );
			$visible = (array) ( $row['visible_slugs'] ?? [] );
			$out[ $user_id ] = [
				'user_id'       => $user_id,
				'user_login'    => $user
					? (string) ( $user->user_login ?: $user->display_name )
					: '使用者 ID ' . $user_id,
				'user_email'    => $user ? (string) $user->user_email : '（已不存在）',
				'visible_slugs' => array_values( array_unique( array_map( 'sanitize_text_field', $visible ) ) ),
				'ys_cart_only'  => false,
			];
		}

		// 3. v2.37.1 legacy: ys_cart.hide_for_user_ids → 補成 hide_ys_cart=true（若未在 1/2 出現）
		$ys_hide = array_map( 'absint', (array) ( $config['ys_cart']['hide_for_user_ids'] ?? [] ) );
		foreach ( $ys_hide as $user_id ) {
			if ( $user_id <= 0 || isset( $out[ $user_id ] ) ) {
				continue;
			}
			$user            = get_userdata( $user_id );
			$out[ $user_id ] = [
				'user_id'       => $user_id,
				'user_login'    => $user
					? (string) ( $user->user_login ?: $user->display_name )
					: '使用者 ID ' . $user_id,
				'user_email'    => $user ? (string) $user->user_email : '（已不存在）',
				'visible_slugs' => [],
				'ys_cart_only'  => true,
			];
		}

		return array_values( $out );
	}

	/**
	 * 收集所有可選 slug → title（給 user override card 內的 slug grid 使用）
	 *
	 * 來源：
	 *   - YSPermissionController::get_all_admin_menu_items()（WP 原生 + 第三方 top-level）
	 *   - YSPermissionController::get_all_ys_cart_endpoints()（YS CART top + sub）
	 *
	 * @return array<string, string> slug => title
	 */
	private static function collect_all_known_slugs(): array {
		$out = [];
		foreach ( YSPermissionController::get_all_ys_cart_endpoints() as $item ) {
			$slug = (string) ( $item['slug'] ?? '' );
			if ( '' !== $slug && ! isset( $out[ $slug ] ) ) {
				$prefix       = ( ( $item['level'] ?? 'top' ) === 'sub' ) ? '↳ ' : '';
				$out[ $slug ] = $prefix . (string) ( $item['title'] ?? $slug );
			}
		}
		return $out;
	}

	/**
	 * 取得目前 WP 全域 $menu 中所有 top-level slug + label（snapshot）
	 *
	 * 保留 v2.37.1 簽章 — 給其他可能 import 此 method 的程式碼用。
	 *
	 * @return array<int, array{slug:string,title:string,is_separator:bool}>
	 */
	public static function current_wp_menu_snapshot(): array {
		global $menu;
		$out = [];
		if ( ! is_array( $menu ) ) {
			return $out;
		}
		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug    = isset( $item[2] ) ? (string) $item[2] : '';
			$title   = isset( $item[0] ) ? wp_strip_all_tags( (string) $item[0] ) : '';
			$classes = isset( $item[4] ) ? (string) $item[4] : '';
			if ( '' === $slug ) {
				continue;
			}
			$out[] = [
				'slug'         => $slug,
				'title'        => $title,
				'is_separator' => ( false !== strpos( $classes, 'wp-menu-separator' ) ),
			];
		}
		return $out;
	}

	/**
	 * 取得 YS CART 自家頂層 menu snapshot（slug 以 ys- 開頭）
	 *
	 * 保留 v2.37.1 簽章。
	 *
	 * @return array<int, array{slug:string,title:string}>
	 */
	public static function current_ys_cart_menu_snapshot(): array {
		global $menu;
		$out = [];
		if ( ! is_array( $menu ) ) {
			return $out;
		}
		foreach ( $menu as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug  = isset( $item[2] ) ? (string) $item[2] : '';
			$title = isset( $item[0] ) ? wp_strip_all_tags( (string) $item[0] ) : '';
			if ( '' === $slug || 0 !== strpos( $slug, 'ys-' ) ) {
				continue;
			}
			$out[] = [ 'slug' => $slug, 'title' => $title ];
		}
		return $out;
	}

	/**
	 * 取得全部 WP role keys（含 display label）
	 *
	 * @return array<string, string>
	 */
	public static function all_role_keys_with_label(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return [
				'administrator' => '管理員',
				'editor'        => '編輯',
				'author'        => '作者',
				'contributor'   => '投稿者',
				'subscriber'    => '訂閱者',
				'shop_manager'  => '商店管理員',
			];
		}
		$wp_roles = wp_roles();
		$out      = [];
		foreach ( $wp_roles->roles as $key => $info ) {
			$out[ sanitize_key( $key ) ] = (string) ( $info['name'] ?? $key );
		}
		return $out;
	}
}
