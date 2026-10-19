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
 *   },
 *   "top_copies": {                // v1.3.0：子選單「複製到主選單」（原子選單保留在原位）
 *     "items": [
 *       { "slug": "options-writing.php", "parent_slug": "options-general.php" }
 *     ]
 *   }
 * }
 * ```
 *
 * 複製項在主選單的排序、名稱、圖示（icon）、顏色、角色、隱藏、僅限本人存在 wp_native.items[]，
 * slug 是 YSMenuRouter::copy_menu_key() 算出的選單代稱（例：./options-writing.php）。
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
use YangSheep\AdminMenu\Menu\YSMenuMovesMigrator;
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
		echo YSMenuPage::pagehead_html( 'menu-alt3', '選單與權限', '拖拉排序、角色可見、僅限本人、隱藏與個別使用者覆寫，全面掌控 wp-admin 選單。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pagehead_html 內已逐欄 escape
		// v1.5.0：開頁當下的 YS CART 選單搬家標記。每次儲存都一起送回；這一頁若是在遷移之前打開的，存檔會被拒絕，
		// 不會把搬好的設定寫回舊分組。
		echo '<input type="hidden" id="ys-admin-menu-moves-token" value="' . esc_attr( YSMenuMovesMigrator::page_token() ) . '">';

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
			'currentUserId' => get_current_user_id(),
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
			? '管理 wp-admin 左側 sidebar 上 <strong>所有</strong>頂層選單（含 WordPress 核心與第三方外掛）：拖拉調整順序、限制角色可見、套色、勾選「隱藏」完全藏起來，或插入「空白標題」分隔列。未列入此處的選單會接續顯示於設定排序之後。從子選單複製來的項目標示「複製」，可在這裡排序、改名、換圖示、隱藏或刪除。'
			: '管理 <strong>所有</strong>選單端點 — 不只頂層，包含每個頂層底下的子選單（sub-page）。可拖拉調整順序、限制角色可見、勾選「隱藏」、插入分隔列；每個子選單列右側的「移到主選單」「複製到主選單」可把它提升或複製成 wp-admin 頂層選單。';

		// v2.38.2 hotfix：直接在 admin context 讀全域 $menu / $submenu，在伺服器端輸出 rows
		$items        = ( 'wp_native' === $tab )
			? YSPermissionController::get_all_admin_menu_items()
			: YSPermissionController::get_all_ys_cart_endpoints();
		$saved_config = YSMenuRouter::get_config();
		$saved_slice  = (array) ( $saved_config[ $tab ] ?? [] );
		$saved_items  = (array) ( $saved_slice['items'] ?? [] );

		// v1.1.0：頂層 tab 標記「已提升」的項目（promoted 項的 $menu[2] = promoted URL）。
		$promoted_urls = [];
		$promoted_keys = [];
		if ( 'wp_native' === $tab ) {
			foreach ( (array) ( $saved_config['ys_cart']['items'] ?? [] ) as $pi ) {
				// v1.5.1：外部網址的子選單不移到主選單（YSMenuRouter::is_external_url()），不標「已提升」。
				if ( is_array( $pi ) && ! empty( $pi['promote_to_top'] )
					&& 'sub' === (string) ( $pi['level'] ?? '' ) && ! empty( $pi['slug'] ) && ! YSMenuRouter::is_external_url( (string) $pi['slug'] ) ) {
					$url                   = YSMenuRouter::promoted_menu_url( (string) $pi['slug'], (string) ( $pi['parent_slug'] ?? '' ) );
					$promoted_urls[ $url ] = true;
					$promoted_keys[ YSMenuRouter::slug_key( $url ) ] = true;
				}
			}
		}

		// Index saved items by slug + 收集 separator
		$saved_by_slug    = [];
		$saved_by_key     = [];
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
				$saved_by_key[ YSMenuRouter::slug_key( (string) $row['slug'] ) ] = $row;
			}
		}

		// v1.5.1：網址帶 %xx 的選單（存檔時 %xx 被拿掉）、「自訂」（return 每頁不同）：這次列出的實際 slug 原字串對不到
		// 已存的設定時，以鍵（YSMenuRouter::slug_key()）找，表格才會顯示已存的順序、名稱、角色、隱藏。
		foreach ( $items as $it ) {
			$live = (string) ( $it['slug'] ?? '' );
			if ( '' === $live || ! YSMenuRouter::slug_is_altered( $live ) ) {
				continue;
			}
			$live_key = YSMenuRouter::slug_key( $live );
			if ( '' === $live_key ) {
				continue;
			}
			if ( ! isset( $saved_by_slug[ $live ] ) && isset( $saved_by_key[ $live_key ] ) ) {
				$saved_by_slug[ $live ] = $saved_by_key[ $live_key ];
			}
			if ( isset( $promoted_keys[ $live_key ] ) ) {
				$promoted_urls[ $live ] = true;
			}
		}

		// v1.3.0/v1.3.2：子選單「複製到主選單」。copy_entries＝這次側欄實際出現的複製項
		// （由 YSMenuRouter 插入 $menu 時記錄，含原父選單名稱與圖示）。
		$copy_entries = YSMenuRouter::copy_entries();

		// 全部選單分頁：頂層選單的名稱（複製／移到主選單列的「原位置」說明用）。
		$top_titles = [];
		if ( 'wp_native' !== $tab ) {
			foreach ( $items as $it ) {
				if ( 'sub' !== (string) ( $it['level'] ?? 'top' ) ) {
					$top_titles[ (string) ( $it['slug'] ?? '' ) ] = (string) ( $it['title'] ?? ( $it['slug'] ?? '' ) );
				}
			}
		}
		?>
		<div class="ys-ec-permission-admin ysca-section">
			<?php // v2.45.20 Phase R2.4-H：page title 由 YSAdminApp::open() 的 page-head 提供，不再 echo <h1>。 ?>

			<nav class="ysca-wl-tabs" role="tablist" aria-label="權限設定分頁">
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

					<p class="ys-ec-toolbar-hint description">
						<span class="dashicons dashicons-move ysca-icon-align-middle"></span>
						可拖拉每列最左側的 <code>⋮⋮</code> 圖示調整順序，或直接修改「順序」數字；調整後記得按「儲存設定」。
					</p>

					<p>
						<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--ghost" id="ys-ec-add-separator">
							<span class="dashicons dashicons-plus ysca-icon-align-middle"></span>
							新增空白標題（分隔列）
						</button>
						<button type="button" class="ysca-btn ysca-btn--primary ysca-btn--sm" id="ys-ec-save-permissions">
							儲存設定
						</button>
						<span class="ys-ec-toolbar-spacer"></span>
						<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--ghost" id="ys-ec-export-config" title="把目前全部選單／權限／樣式設定匯出成 JSON 檔備份">
							<span class="dashicons dashicons-download ysca-icon-align-middle"></span>
							匯出設定
						</button>
						<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--ghost" id="ys-ec-import-config" title="從先前匯出的 JSON 檔還原全部設定（會覆蓋目前設定）">
							<span class="dashicons dashicons-upload ysca-icon-align-middle"></span>
							匯入設定
						</button>
						<input type="file" id="ys-ec-import-file" accept="application/json,.json" style="display:none">
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
								<?php else : ?>
									<th class="ysca-table__state ysca-text-center" title="把子選單提升為 wp-admin 頂層選單（原位置移除；連結沿用原生網址，頁面功能不受影響）">升頂層</th>
								<?php endif; ?>
								<th class="ysca-table__state ysca-text-center">隱藏</th>
								<th class="ysca-table__actions">操作</th>
							</tr>
						</thead>
						<tbody id="ys-ec-permission-rows">
							<?php
							if ( empty( $items ) ) :
								?>
								<tr>
									<td colspan="7" class="ysca-empty-row ysca-text-center">
										目前讀取不到任何選單項目。請確認此頁面從 wp-admin 直接開啟（而非 REST API 呼叫）。
									</td>
								</tr>
								<?php
							elseif ( 'wp_native' === $tab ) :
								self::render_wp_native_rows( $items, $saved_by_slug, $saved_separators, $copy_entries, $promoted_urls );
							else :
								self::render_ys_cart_rows( $items, $saved_config, $saved_by_slug, $saved_separators, $copy_entries, $top_titles );
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
	 * v1.3.2：「主選單（頂層）」分頁的列。頂層列（含由子選單複製／移到主選單來的頂層項）依 order 排序。
	 *
	 * @param array<int, array<string, mixed>> $items            get_all_admin_menu_items()。
	 * @param array<string, array<string,mixed>> $saved_by_slug  wp_native 已存設定（以 slug 索引）。
	 * @param array<int, array<string, mixed>> $saved_separators wp_native 分隔列。
	 * @param array<string, array<string,mixed>> $copy_entries   YSMenuRouter::copy_entries()。
	 * @param array<string, bool> $promoted_urls                 已提升項的 URL => true。
	 */
	private static function render_wp_native_rows( array $items, array $saved_by_slug, array $saved_separators, array $copy_entries, array $promoted_urls ): void {
		$render_list = [];
		$idx         = 0;
		foreach ( $items as $item ) {
			++$idx;
			$slug = (string) ( $item['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$saved         = $saved_by_slug[ $slug ] ?? [];
			$render_list[] = [
				'kind'  => 'item',
				'order' => isset( $saved['order'] ) ? (int) $saved['order'] : ( $idx * 10 ),
				'item'  => $item,
				'saved' => $saved,
			];
		}
		foreach ( $saved_separators as $sep ) {
			$render_list[] = [
				'kind'  => 'separator',
				'order' => (int) ( $sep['order'] ?? 0 ),
				'title' => (string) ( $sep['title'] ?? '' ),
			];
		}
		usort(
			$render_list,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		foreach ( $render_list as $entry ) {
			if ( 'separator' === $entry['kind'] ) {
				self::render_separator_row( 'wp_native', $entry['title'], $entry['order'] );
				continue;
			}
			$extra = [ 'copy' => $copy_entries[ (string) ( $entry['item']['slug'] ?? '' ) ] ?? null ];
			self::render_item_row( 'wp_native', $entry['item'], $entry['saved'], $entry['order'], $promoted_urls, $extra );
		}
	}

	/**
	 * v1.3.2：「全部選單（含子選單）」分頁的列。
	 *
	 * 子選單按「複製到主選單」或「移到主選單」後，會在主列表裡以一列完整的頂層列出現（可拖拉排序、改名、
	 * 換圖示、角色、隱藏、刪除），位置緊接在原父選單那一組之後。設定裡已存的複製／已移到主選單的項目，
	 * 這裡也依它們在側欄的位置（after 錨點，退回原父選單）插在對應位置。
	 *
	 * @param array<int, array<string, mixed>>   $items            get_all_ys_cart_endpoints()（真實頂層＋子頁；不含複製／提升／分隔列）。
	 * @param array<string, mixed>               $saved_config     完整設定。
	 * @param array<string, array<string,mixed>> $saved_by_slug    ys_cart 已存設定（以 slug 索引）。
	 * @param array<int, array<string, mixed>>   $saved_separators ys_cart 分隔列。
	 * @param array<string, array<string,mixed>> $copy_entries     YSMenuRouter::copy_entries()。
	 * @param array<string, string>              $top_titles       頂層 slug => 名稱。
	 */
	private static function render_ys_cart_rows( array $items, array $saved_config, array $saved_by_slug, array $saved_separators, array $copy_entries, array $top_titles ): void {
		$ys_cart_items = (array) ( $saved_config['ys_cart']['items'] ?? [] );
		$native_items  = (array) ( $saved_config['wp_native']['items'] ?? [] );

		// wp_native 的頂層設定列（複製／已移到主選單項目的 after / 名稱 / 圖示 / 角色 / 隱藏 / 僅限本人）。
		// v1.5.1：另以鍵索引（YSMenuRouter::slug_key()）：「自訂」移到主選單後，兩個分頁存下的代稱字串不一樣。
		$native_by_slug = [];
		$native_by_key  = [];
		foreach ( $native_items as $r ) {
			if ( is_array( $r ) && empty( $r['separator'] ) && ! empty( $r['slug'] ) ) {
				$native_by_slug[ (string) $r['slug'] ] = $r;
				$native_by_key[ YSMenuRouter::slug_key( (string) $r['slug'] ) ] = $r;
			}
		}
		$native_for = static function ( string $key ) use ( $native_by_slug, $native_by_key ): array {
			return $native_by_slug[ $key ] ?? ( $native_by_key[ YSMenuRouter::slug_key( $key ) ] ?? [] );
		};

		// 已移到主選單（promote_to_top）的子選單：改成頂層列顯示，不在原父選單底下重複。
		// v1.5.1：外部網址的子選單不移到主選單，存過也照原位列成子選單（跟側欄一樣）。
		$promoted_subs   = [];
		$promoted_by_key = [];
		foreach ( $ys_cart_items as $ci ) {
			if ( is_array( $ci ) && ! empty( $ci['promote_to_top'] ) && 'sub' === (string) ( $ci['level'] ?? '' ) && ! empty( $ci['slug'] )
				&& ! YSMenuRouter::is_external_url( (string) $ci['slug'] ) ) {
				$promoted_subs[ (string) $ci['slug'] ]                            = $ci;
				$promoted_by_key[ YSMenuRouter::slug_key( (string) $ci['slug'] ) ] = $ci;
			}
		}

		// v1.5.1：複製項以鍵也找得到（原子選單網址帶 %xx 時，側欄上的複製項代稱是實際字串）。
		$copy_by_key = [];
		foreach ( $copy_entries as $ck => $ce ) {
			$ck = (string) $ck;
			if ( YSMenuRouter::slug_is_altered( $ck ) && ! isset( $copy_by_key[ YSMenuRouter::slug_key( $ck ) ] ) ) {
				$copy_by_key[ YSMenuRouter::slug_key( $ck ) ] = $ce;
			}
		}

		// 頂層名稱：存下的上層原字串對不到時，以鍵找存檔時會被改掉的頂層（網址帶 %xx）。
		$top_title_for = static function ( string $parent ) use ( $top_titles ): ?string {
			if ( isset( $top_titles[ $parent ] ) ) {
				return $top_titles[ $parent ];
			}
			foreach ( $top_titles as $top => $title ) {
				$top = (string) $top;
				if ( '' !== $parent && YSMenuRouter::slug_is_altered( $top ) && YSMenuRouter::slug_key( $top ) === YSMenuRouter::slug_key( $parent ) ) {
					return $title;
				}
			}
			return null;
		};

		// 1) 骨架：真實頂層＋父選單預設頁＋真實子頁＋分隔列，依 order 排序（略過已移到主選單的子頁）。
		//    v1.5.0：還沒有順序值的子頁（新出現的子選單、選單搬家時照預設順序或只帶規則的列）不照列舉序號排，
		//    排回它的上層選單那一組（place_unordered_subs）。
		$skeleton = [];
		$deferred = [];
		$idx      = 0;
		foreach ( $items as $item ) {
			++$idx;
			$slug = (string) ( $item['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$is_sub = 'sub' === (string) ( $item['level'] ?? 'top' );
			$promo  = $promoted_subs[ $slug ]
				?? ( YSMenuRouter::slug_is_altered( $slug ) ? ( $promoted_by_key[ YSMenuRouter::slug_key( $slug ) ] ?? null ) : null );
			if ( $is_sub && null !== $promo
				&& YSMenuRouter::slug_key( (string) ( $promo['parent_slug'] ?? '' ) ) === YSMenuRouter::slug_key( (string) ( $item['parent_slug'] ?? '' ) ) ) {
				continue;
			}
			$saved = $saved_by_slug[ $slug ] ?? [];
			$entry = [
				'kind'  => 'item',
				'order' => isset( $saved['order'] ) ? (int) $saved['order'] : ( $idx * 10 ),
				'item'  => $item,
				'saved' => $saved,
			];
			if ( $is_sub && ! isset( $saved['order'] ) ) {
				$deferred[] = $entry;
				continue;
			}
			$skeleton[] = $entry;
		}
		foreach ( $saved_separators as $sep ) {
			$skeleton[] = [
				'kind'  => 'separator',
				'order' => (int) ( $sep['order'] ?? 0 ),
				'title' => (string) ( $sep['title'] ?? '' ),
			];
		}
		usort(
			$skeleton,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);
		if ( ! empty( $deferred ) ) {
			$skeleton = self::place_unordered_subs( $skeleton, $deferred );
		}

		// 2) 展開成 token 串：每個真實頂層後面接它的「父選單預設頁」。擁有者 owner 用來稍後插入複製／提升列。
		$tokens = [];
		foreach ( $skeleton as $entry ) {
			if ( 'separator' === $entry['kind'] ) {
				$tokens[] = [ 'type' => 'separator', 'owner' => '', 'entry' => $entry ];
				continue;
			}
			$item    = $entry['item'];
			$slug    = (string) ( $item['slug'] ?? '' );
			$is_sub  = 'sub' === (string) ( $item['level'] ?? 'top' );
			$owner   = $is_sub ? (string) ( $item['parent_slug'] ?? '' ) : $slug;
			$tokens[] = [ 'type' => $is_sub ? 'sub' : 'top', 'owner' => $owner, 'entry' => $entry ];
			if ( ! $is_sub ) {
				$self_title = self::parent_self_row_title( $slug );
				if ( null !== $self_title ) {
					$tokens[] = [
						'type'  => 'self',
						'owner' => $slug,
						'self'  => [
							'parent'       => $slug,
							'title'        => $self_title,
							'parent_title' => (string) ( $item['title'] ?? $slug ),
							'key'          => self::copyable_key( $slug, $slug ),
						],
					];
				}
			}
		}

		// 3) 複製列與已移到主選單列的 descriptor（含錨點 after）。
		$copied_keys = [];
		$descriptors = [];
		foreach ( YSPermissionController::sanitize_top_copies( (array) ( $saved_config['top_copies']['items'] ?? [] ) ) as $sc ) {
			$key = YSMenuRouter::copy_menu_key( $sc['slug'], $sc['parent_slug'] );
			if ( '' === $key ) {
				continue;
			}
			$copied_keys[ $key ] = true;
			$live                = $copy_entries[ $key ] ?? ( $copy_by_key[ YSMenuRouter::slug_key( $key ) ] ?? null );
			$own                 = $native_for( $key );
			$parent_title        = $top_title_for( $sc['parent_slug'] ) ?? ( null !== $live ? $live['parent_title'] : $sc['parent_slug'] );
			$title               = null !== $live ? $live['title'] : self::copy_source_title( $sc['slug'], $sc['parent_slug'], $ys_cart_items );
			$descriptors[]       = [
				'kind'         => 'copy',
				'key'          => $key,
				'copy_slug'    => $sc['slug'],
				'copy_parent'  => $sc['parent_slug'],
				'title'        => $title,
				'parent_title' => $parent_title,
				'parent'       => $sc['parent_slug'],
				'after'        => isset( $own['after'] ) ? (string) $own['after'] : null,
				'own'          => $own,
				'default_icon' => null !== $live ? $live['parent_icon'] : 'dashicons-admin-generic',
				'live'         => null !== $live,
			];
		}
		foreach ( $promoted_subs as $sub_slug => $ci ) {
			$sub_slug = (string) $sub_slug;
			$parent   = (string) ( $ci['parent_slug'] ?? '' );
			$key      = YSMenuRouter::promoted_menu_url( $sub_slug, $parent );
			$own      = $native_for( $key );
			$descriptors[] = [
				'kind'         => 'promoted',
				'key'          => $key,
				'sub_slug'     => $sub_slug,
				'parent'       => $parent,
				'title'        => self::promoted_title( $key, $sub_slug, $ci ),
				'parent_title' => $top_title_for( $parent ) ?? $parent,
				'after'        => isset( $own['after'] ) ? (string) $own['after'] : null,
				'own'          => $own,
				'sub'          => $ci,
				'default_icon' => self::parent_icon_for( $parent ),
			];
		}

		// 4) 把 descriptor 插到錨點（after 的真實頂層；退回原父選單；再退回最後）。
		//    v1.5.1：after 與原父選單照原字串找不到時，以鍵找存檔時會被改掉的代稱（resolve_owner()）。
		foreach ( $descriptors as $d ) {
			$after  = null === $d['after'] ? '' : ( '' === $d['after'] ? '' : self::resolve_owner( $tokens, $d['after'] ) );
			$parent = self::resolve_owner( $tokens, $d['parent'] );
			$anchor = ( null !== $d['after'] && ( '' === $d['after'] || '' !== $after ) )
				? $after
				: ( '' !== $parent ? $parent : $d['parent'] );
			$at     = self::anchor_insert_index( $tokens, $anchor );
			array_splice( $tokens, $at, 0, [ [ 'type' => $d['kind'], 'owner' => $d['key'], 'desc' => $d ] ] );
		}

		// 5) 輸出。
		foreach ( $tokens as $token ) {
			switch ( $token['type'] ) {
				case 'separator':
					self::render_separator_row( 'ys_cart', (string) $token['entry']['title'], (int) $token['entry']['order'] );
					break;
				case 'self':
					$s = $token['self'];
					self::render_self_row( $s['parent'], $s['title'], $s['parent_title'], $s['key'], isset( $copied_keys[ $s['key'] ] ) );
					break;
				case 'copy':
				case 'promoted':
					self::render_top_copy_row( $token['desc'] );
					break;
				case 'sub':
					$item      = $token['entry']['item'];
					$item_slug = (string) ( $item['slug'] ?? '' );
					$item_parent = (string) ( $item['parent_slug'] ?? '' );
					$row_key   = self::copyable_key( $item_slug, $item_parent );
					$extra     = [
						'parent_title' => $top_titles[ $item_parent ] ?? $item_parent,
						'copy_key'     => $row_key,
						'copied'       => '' !== $row_key && isset( $copied_keys[ $row_key ] ),
						// 移到主選單用的頂層代稱（原生網址；% 編碼的代稱不提供；v1.5.1：外部網址不提供）。
						'promote_key'  => ( '' !== $row_key && false === strpos( $item_slug . $item_parent, '%' ) && ! YSMenuRouter::is_external_url( $item_slug ) )
							? YSMenuRouter::promoted_menu_url( $item_slug, $item_parent )
							: '',
					];
					self::render_item_row( 'ys_cart', $item, $token['entry']['saved'], (int) $token['entry']['order'], [], $extra );
					break;
				default:
					self::render_item_row( 'ys_cart', $token['entry']['item'], $token['entry']['saved'], (int) $token['entry']['order'], [], [] );
			}
		}
	}

	/**
	 * v1.5.0：還沒有順序值的子選單排回它的上層選單那一組（接在那一組最後一列之後，依列舉順序＝側欄目前的順序）。
	 *
	 * 「全部選單」分頁的順序是整份清單共用的一串數字。沒有順序值的子選單以前用列舉序號×10，上層那一組前後
	 * 都存過順序時，就可能排進別組中間（例如選單搬家後，新上層底下照預設順序的子選單）。這裡給它們前後兩列之間
	 * 的數字，存檔後位置不會跑掉：原本的列舉序號×10 夾得進去就用它（從沒排過序的站，畫面與數字都跟以前一樣），
	 * 夾不進去就用前一列＋1；連這樣都不行時，整份清單依畫面順序改成 10、20、30…（相對順序不變，按「儲存設定」才寫入）。
	 *
	 * @param array<int, array<string, mixed>> $skeleton 已依 order 排好的列。
	 * @param array<int, array<string, mixed>> $deferred 還沒有順序值的子選單列（依列舉順序）。
	 * @return array<int, array<string, mixed>>
	 */
	private static function place_unordered_subs( array $skeleton, array $deferred ): array {
		$out = array_values( $skeleton );
		foreach ( $deferred as $entry ) {
			$parent = (string) ( $entry['item']['parent_slug'] ?? '' );
			$at     = null;
			foreach ( $out as $i => $e ) {
				if ( 'item' !== $e['kind'] ) {
					continue;
				}
				$it     = $e['item'];
				$is_sub = 'sub' === (string) ( $it['level'] ?? 'top' );
				if ( ( ! $is_sub && (string) ( $it['slug'] ?? '' ) === $parent )
					|| ( $is_sub && (string) ( $it['parent_slug'] ?? '' ) === $parent ) ) {
					$at = $i;
				}
			}
			$entry['inserted'] = true;
			if ( null === $at ) {
				$out[] = $entry;
			} else {
				array_splice( $out, $at + 1, 0, [ $entry ] );
			}
		}

		$count = count( $out );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( empty( $out[ $i ]['inserted'] ) ) {
				continue;
			}
			$prev = $i > 0 ? (int) $out[ $i - 1 ]['order'] : null;
			$next = null;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( empty( $out[ $j ]['inserted'] ) ) {
					$next = (int) $out[ $j ]['order'];
					break;
				}
			}
			$fits = static function ( int $v ) use ( $prev, $next ): bool {
				return $v >= 0 && ( null === $prev || $v > $prev ) && ( null === $next || $v < $next );
			};
			if ( $fits( (int) $out[ $i ]['order'] ) ) {
				continue;
			}
			if ( null !== $prev && $fits( $prev + 1 ) ) {
				$out[ $i ]['order'] = $prev + 1;
				continue;
			}
			foreach ( $out as $k => $e ) {
				$out[ $k ]['order'] = ( $k + 1 ) * 10;
			}
			break;
		}
		return $out;
	}

	/**
	 * v1.3.2：token 串裡對到某個代稱的擁有者（複製／提升列可錨定在真實頂層，或另一個複製／提升列之後）。
	 *
	 * v1.5.1：原字串有就用它；沒有才找存檔時會被改掉、鍵又相同的擁有者（網址帶 %xx、「自訂」，YSMenuRouter::slug_key()）。
	 * 都沒有回空字串。
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 */
	private static function resolve_owner( array $tokens, string $want ): string {
		if ( '' === $want ) {
			return '';
		}
		$key      = YSMenuRouter::slug_key( $want );
		$fallback = '';
		foreach ( $tokens as $t ) {
			$owner = (string) ( $t['owner'] ?? '' );
			if ( '' === $owner ) {
				continue;
			}
			if ( $owner === $want ) {
				return $owner;
			}
			if ( '' === $fallback && '' !== $key && YSMenuRouter::slug_is_altered( $owner ) && YSMenuRouter::slug_key( $owner ) === $key ) {
				$fallback = $owner;
			}
		}
		return $fallback;
	}

	/**
	 * v1.3.2：某錨點那一組最後一列的下一個索引（錨點＝頂層 owner；空字串＝最前面）。
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 */
	private static function anchor_insert_index( array $tokens, string $anchor ): int {
		if ( '' === $anchor ) {
			return 0;
		}
		$last = -1;
		foreach ( $tokens as $i => $t ) {
			if ( (string) ( $t['owner'] ?? '' ) === $anchor ) {
				$last = $i;
			}
		}
		return $last < 0 ? count( $tokens ) : $last + 1;
	}

	/**
	 * v1.3.2：複製來源的名稱（側欄沒出現時從 ys_cart.items 找）。
	 *
	 * @param array<int, array<string, mixed>> $ys_cart_items
	 */
	private static function copy_source_title( string $slug, string $parent, array $ys_cart_items ): string {
		if ( $slug === $parent ) {
			$t = self::parent_self_row_title( $parent );
			return null !== $t ? $t : $slug;
		}
		foreach ( $ys_cart_items as $ci ) {
			if ( is_array( $ci ) && (string) ( $ci['slug'] ?? '' ) === $slug && ! empty( $ci['title_override'] ) ) {
				return (string) $ci['title_override'];
			}
		}
		return $slug;
	}

	/**
	 * v1.3.2：已移到主選單項目的標題（從 $menu 的頂層項找，退回子選單設定名稱、slug）。
	 *
	 * @param array<string, mixed> $ci ys_cart.items 的那一列。
	 */
	private static function promoted_title( string $key, string $sub_slug, array $ci ): string {
		foreach ( self::live_top_rows( $key ) as $m ) {
			$t = trim( wp_strip_all_tags( (string) ( $m[0] ?? '' ) ) );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return ! empty( $ci['title_override'] ) ? (string) $ci['title_override'] : $sub_slug;
	}

	/**
	 * v1.3.2：頂層選單的圖示（複製／提升列預設沿用原父選單圖示）。
	 */
	private static function parent_icon_for( string $parent ): string {
		foreach ( self::live_top_rows( $parent ) as $m ) {
			if ( ! empty( $m[6] ) ) {
				return (string) $m[6];
			}
		}
		return 'dashicons-admin-generic';
	}

	/**
	 * v1.5.1：側欄上對到某個存下代稱的頂層項：原字串相同的；一個都沒有才找存檔時會被改掉、鍵又相同的
	 * （網址帶 %xx、「自訂」移到主選單後的連結，YSMenuRouter::slug_key()）。
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private static function live_top_rows( string $stored ): array {
		global $menu;
		$exact = [];
		$keyed = [];
		$key   = YSMenuRouter::slug_key( $stored );
		foreach ( (array) $menu as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$live = (string) ( $m[2] ?? '' );
			if ( '' !== $stored && $live === $stored ) {
				$exact[] = $m;
			} elseif ( '' !== $key && YSMenuRouter::slug_is_altered( $live ) && YSMenuRouter::slug_key( $live ) === $key ) {
				$keyed[] = $m;
			}
		}
		return [] !== $exact ? $exact : $keyed;
	}

	/**
	 * 渲染一般 menu item row（v2.39.0 BATCH Q2 + Q3：compact + hide cb）
	 *
	 * 結構（單行 ~38px 高）：
	 *   | drag | order | menu-line(strong+code+badge) + title-override-inline | roles | [color?] | hide | 操作 |
	 *
	 * v1.3.0：
	 *   - 全部選單分頁的子選單列，「操作」欄多了「移到主選單」（等同升頂層，與勾選、提升區同步）與
	 *     「複製到主選單」（原位置保留，主選單另外多一個連到同一頁的項目）。
	 *   - 主選單分頁的複製項：標示「複製」與原位置，多一個圖示欄位，「×」換成「刪除複製」。
	 *
	 * @param string               $tab           'wp_native' | 'ys_cart'
	 * @param array<string, mixed> $item          enumeration item（slug / title / level / parent_slug / icon）
	 * @param array<string, mixed> $saved         該 slug 的已存設定（color / roles / title_override / hide / promote_to_top / icon）
	 * @param int                  $order         最終要寫入 input 的 order 值
	 * @param array<string, bool>  $promoted_urls （wp_native tab）promoted URL => true，用於標示「已提升」badge
	 * @param array<string, mixed> $extra         v1.3.0：全部選單分頁子選單列的 parent_title / copy_key / copied；
	 *                                            主選單分頁的 copy（YSMenuRouter::copy_entries() 的一筆，非複製項為 null）。
	 */
	private static function render_item_row( string $tab, array $item, array $saved, int $order, array $promoted_urls = [], array $extra = [] ): void {
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
		$self_only_uid  = (int) ( $saved['self_only_uid'] ?? 0 );
		$promoted       = ! empty( $saved['promote_to_top'] );
		$is_promoted_top = ( 'wp_native' === $tab ) && isset( $promoted_urls[ $slug ] );

		// v1.3.0：複製到主選單。
		$copy      = ( 'wp_native' === $tab && is_array( $extra['copy'] ?? null ) ) ? $extra['copy'] : null;
		$copy_key  = (string) ( $extra['copy_key'] ?? '' );
		$copied    = ! empty( $extra['copied'] );
		$copy_icon = (string) ( $saved['icon'] ?? '' );

		$role_options = [
			'administrator' => 'Admin',
			'shop_manager'  => 'SM',
			'editor'        => 'Editor',
		];

		$attrs = '';
		if ( null !== $parent_slug ) {
			$attrs .= ' data-parent="' . esc_attr( $parent_slug ) . '"';
		}
		if ( 'wp_native' !== $tab && isset( $extra['parent_title'] ) ) {
			$attrs .= ' data-parent-title="' . esc_attr( (string) $extra['parent_title'] ) . '"';
		}
		if ( '' !== $copy_key && null !== $parent_slug ) {
			$attrs .= ' data-copy-key="' . esc_attr( $copy_key ) . '" data-copy-slug="' . esc_attr( $slug ) . '" data-copy-parent="' . esc_attr( $parent_slug ) . '"';
		}
		// v1.3.2：移到主選單用的頂層代稱。
		if ( ! empty( $extra['promote_key'] ) ) {
			$attrs .= ' data-promote-key="' . esc_attr( (string) $extra['promote_key'] ) . '"';
		}
		if ( null !== $copy ) {
			$attrs .= ' class="ys-ec-copy-top-row" data-copy="1" data-copy-slug="' . esc_attr( $copy['slug'] ) . '" data-copy-parent="' . esc_attr( $copy['parent'] ) . '" data-default-icon="' . esc_attr( $copy['parent_icon'] ) . '"';
		}

		$is_sub = ( 'sub' === $level );
		?>
		<tr data-slug="<?php echo esc_attr( $slug ); ?>" data-level="<?php echo esc_attr( $level ); ?>" data-self-uid="<?php echo (int) $self_only_uid; ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>>
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
					<code><?php echo esc_html( null !== $copy ? $copy['slug'] : $slug ); ?></code>
					<span class="level-badge<?php echo $is_sub ? ' level-sub' : ''; ?>"><?php echo $is_sub ? '子頁' : '頂層'; ?></span>
					<?php if ( $is_promoted_top ) : ?>
						<span class="level-badge level-promoted" title="此項由「全部選單（含子選單）」的子頁提升而來；到該分頁取消勾選「升頂層」即可還原">已提升</span>
					<?php endif; ?>
					<?php if ( null !== $copy ) : ?>
						<span class="level-badge level-copy" title="從子選單複製到主選單的項目，連到同一頁；原子選單仍在原位">複製</span>
						<span class="ys-ec-copy-source">原位置：<?php echo esc_html( $copy['parent_title'] ); ?> › <?php echo esc_html( $copy['title'] ); ?></span>
					<?php endif; ?>
				</div>
				<input type="text" class="title-override-inline ys-ec-title-override"
					value="<?php echo esc_attr( $title_override ); ?>"
					placeholder="自訂顯示名稱（留空保留原名）">
				<?php if ( null !== $copy ) : ?>
					<div class="ys-ec-icon-field">
						<button type="button" class="ys-ec-icon-btn" aria-expanded="false" title="選擇圖示">
							<span class="ys-ec-icon-preview" aria-hidden="true"></span>
							<span class="screen-reader-text">選擇圖示</span>
						</button>
						<input type="text" class="ys-ec-icon-input" value="<?php echo esc_attr( $copy_icon ); ?>"
							placeholder="圖示：留空沿用原父選單（dashicons-… 或圖片網址）" spellcheck="false">
					</div>
				<?php endif; ?>
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
					<label class="ys-ec-self-only-label" title="勾選後此選單只有目前登入的帳號看得到（含其他管理員）">
						<input type="checkbox" class="ys-ec-self-only" <?php checked( $self_only_uid > 0 ); ?>>
						僅限本人
					</label>
			</td>
			<?php if ( 'wp_native' === $tab ) : ?>
				<td>
					<input type="text" class="ys-ec-color-picker"
						value="<?php echo esc_attr( $color ); ?>"
						data-default-color=""
						placeholder="#0073aa" maxlength="7">
				</td>
			<?php else : ?>
				<td class="ys-ec-promote-cell ysca-text-center">
					<span class="ysca-text-muted" aria-hidden="true">—</span>
				</td>
			<?php endif; ?>
			<td class="ys-ec-hide-cb">
				<input type="checkbox" class="ys-ec-hide-checkbox"
					title="<?php echo esc_attr( null !== $copy ? '對管理員以外的帳號隱藏這個複製項（原子選單與頁面不受影響）' : '勾選後完全隱藏（含 sidebar + 直接訪問）' ); ?>"
					<?php checked( $hide ); ?>>
			</td>
			<td class="ys-ec-row-actions">
				<?php if ( 'wp_native' !== $tab && $is_sub ) : ?>
					<?php if ( ! YSMenuRouter::is_external_url( $slug ) ) : // v1.5.1：外部網址不提供「移到主選單」（請用複製到主選單）。 ?>
					<button type="button" class="ys-ec-row-btn ys-ec-move-btn"
						title="移到主選單：變成 wp-admin 頂層選單，原位置移除">
						<span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span><span class="ys-ec-row-btn__label">移到主選單</span>
					</button>
					<?php endif; ?>
					<?php if ( '' !== $copy_key ) : ?>
						<?php self::render_copy_button( $copied ); ?>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( null !== $copy ) : ?>
					<button type="button" class="ys-ec-row-btn ys-ec-row-btn--danger ys-ec-delete-copy" title="刪除這個複製項（原子選單不受影響）">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="ys-ec-row-btn__label">刪除複製</span>
					</button>
				<?php else : ?>
					<button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-delete-row" title="從清單移除">×</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * v1.3.0：「複製到主選單」按鈕。已經有複製項時改成「已複製」（同一個子選單只能複製一次）。
	 */
	private static function render_copy_button( bool $copied ): void {
		?>
		<button type="button" class="ys-ec-row-btn ys-ec-copy-btn<?php echo $copied ? ' is-copied' : ''; ?>"
			<?php disabled( $copied ); ?>
			title="<?php echo esc_attr( $copied ? '已經複製到主選單（同一個子選單只能複製一次）；要刪除請按上方複製區的「刪除複製」' : '複製到主選單：主選單另外多一個連到同一頁的項目，原位置保留' ); ?>">
			<span class="dashicons <?php echo $copied ? 'dashicons-yes' : 'dashicons-admin-page'; ?>" aria-hidden="true"></span><span class="ys-ec-row-btn__label"><?php echo $copied ? '已複製' : '複製到主選單'; ?></span>
		</button>
		<?php
	}

	/**
	 * v1.3.0：子選單列可以複製時的選單代稱；不行（代稱帶 % 編碼、組不出安全連結）回空字串。
	 *
	 * 帶 % 的代稱（例：「自訂」的 customize.php?return=%2F…，每一頁都不同）存檔時會被清理掉 % 編碼，
	 * 之後對不回原子選單，所以不提供複製。
	 */
	private static function copyable_key( string $slug, string $parent ): string {
		if ( '' === $slug || '' === $parent || false !== strpos( $slug . $parent, '%' ) ) {
			return '';
		}
		return YSMenuRouter::copy_menu_key( $slug, $parent );
	}

	/**
	 * v1.3.0：父選單本身的預設頁（$submenu[ 父選單 ] 裡與父選單同代稱的那一列）的名稱；沒有回 null。
	 */
	private static function parent_self_row_title( string $parent ): ?string {
		global $submenu;
		if ( '' === $parent || empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return null;
		}
		foreach ( $submenu[ $parent ] as $sub ) {
			if ( is_array( $sub ) && (string) ( $sub[2] ?? '' ) === $parent ) {
				$title = trim( wp_strip_all_tags( (string) ( $sub[0] ?? '' ) ) );
				return '' !== $title ? $title : $parent;
			}
		}
		return null;
	}

	/**
	 * v1.3.0：全部選單分頁的「父選單預設頁」列（例如「設定 → 一般」）。
	 *
	 * 它和父選單是同一個代稱：排序固定在第一個、隱藏與角色由父選單那一列決定，所以這一列只提供
	 * 「複製到主選單」。不送進儲存的 ys_cart.items。
	 */
	private static function render_self_row( string $parent_slug, string $title, string $parent_title, string $copy_key, bool $copied ): void {
		$copy_attrs = '' !== $copy_key
			? ' data-copy-key="' . esc_attr( $copy_key ) . '" data-copy-slug="' . esc_attr( $parent_slug ) . '" data-copy-parent="' . esc_attr( $parent_slug ) . '"'
			: '';
		?>
		<tr class="ys-ec-self-row" data-level="self" data-self-row="1" data-parent="<?php echo esc_attr( $parent_slug ); ?>" data-parent-title="<?php echo esc_attr( $parent_title ); ?>"<?php echo $copy_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>>
			<td></td>
			<td class="ysca-text-center"><span class="ysca-text-muted" title="父選單的預設頁固定排在第一個">—</span></td>
			<td>
				<div class="menu-line">
					<span class="menu-indent">&#8627;</span>
					<strong><?php echo esc_html( $title ); ?></strong>
					<code><?php echo esc_html( $parent_slug ); ?></code>
					<span class="level-badge level-sub">子頁</span>
					<span class="level-badge level-self" title="<?php echo esc_attr( '點父選單「' . $parent_title . '」本身開啟的頁面' ); ?>">父選單預設頁</span>
				</div>
				<p class="ys-ec-self-note">固定排在第一個；隱藏、角色請設定在「<?php echo esc_html( $parent_title ); ?>」那一列。</p>
			</td>
			<td class="ysca-text-center"><span class="ysca-text-muted" aria-hidden="true">—</span></td>
			<td class="ysca-text-center"><span class="ysca-text-muted" aria-hidden="true">—</span></td>
			<td class="ysca-text-center"><span class="ysca-text-muted" aria-hidden="true">—</span></td>
			<td class="ys-ec-row-actions">
				<?php if ( '' !== $copy_key ) : ?>
					<?php self::render_copy_button( $copied ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * v1.3.2：「全部選單（含子選單）」分頁裡，由子選單複製或移到主選單而來的「頂層列」（主列表內、可拖拉）。
	 *
	 * 複製列（kind=copy）：原子選單保留，主選單多一個連到同一頁的項目；設定存在 top_copies.items
	 * ＋ wp_native.items 的 ./ 代稱列。已移到主選單列（kind=promoted）：原子選單移除；promote_to_top
	 * 與角色／隱藏／僅限本人存在 ys_cart.items 的子選單列，位置（after）／圖示／顯示名稱存在 wp_native.items
	 * 的原生網址代稱列。兩者的位置都由拖拉決定（after＝接在哪個頂層項後面）。
	 *
	 * @param array<string, mixed> $d descriptor（見 render_ys_cart_rows）。
	 */
	private static function render_top_copy_row( array $d ): void {
		$is_copy = 'copy' === $d['kind'];
		$own     = (array) ( $d['own'] ?? [] );
		$sub     = (array) ( $d['sub'] ?? [] );
		$source  = $is_copy ? $own : $sub; // 角色／隱藏／僅限本人：複製列用自己的 wp_native 列，提升列用子選單列。

		$roles          = isset( $source['roles'] ) && is_array( $source['roles'] ) ? $source['roles'] : [];
		$hide           = ! empty( $source['hide'] );
		$self_only_uid  = (int) ( $source['self_only_uid'] ?? 0 );
		$title_override  = (string) ( $own['title_override'] ?? '' );
		$icon           = (string) ( $own['icon'] ?? '' );
		$after          = isset( $own['after'] ) ? (string) $own['after'] : '';

		$role_options = [
			'administrator' => 'Admin',
			'shop_manager'  => 'SM',
			'editor'        => 'Editor',
		];

		$attrs  = ' data-row-kind="' . esc_attr( $d['kind'] ) . '"';
		$attrs .= ' data-copy-key="' . esc_attr( $d['key'] ) . '"';
		$attrs .= ' data-after="' . esc_attr( $after ) . '"';
		$attrs .= ' data-default-icon="' . esc_attr( (string) ( $d['default_icon'] ?? '' ) ) . '"';
		$attrs .= ' data-self-uid="' . (int) $self_only_uid . '"';
		$attrs .= ' data-parent="' . esc_attr( (string) ( $d['parent'] ?? '' ) ) . '"';
		$attrs .= ' data-parent-title="' . esc_attr( (string) ( $d['parent_title'] ?? '' ) ) . '"';
		if ( $is_copy ) {
			$class  = 'ys-ec-copy-top-row';
			$attrs .= ' data-copy="1" data-copy-slug="' . esc_attr( (string) $d['copy_slug'] ) . '" data-copy-parent="' . esc_attr( (string) $d['copy_parent'] ) . '"';
			$badge  = '複製';
			$badge_cls = 'level-copy';
			$badge_title = '從子選單複製到主選單的項目，連到同一頁；原子選單仍在原位';
		} else {
			$class  = 'ys-ec-promoted-top-row';
			$attrs .= ' data-promoted="1" data-slug="' . esc_attr( (string) $d['sub_slug'] ) . '"';
			$badge  = '已移到主選單';
			$badge_cls = 'level-promoted';
			$badge_title = '由子選單移到主選單的頂層項（原位置已移除）；按「移回原位」還原';
		}
		?>
		<tr class="<?php echo esc_attr( $class ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>>
			<td class="ys-ec-drag-handle" title="拖拉以排序（放到想要的頂層選單後面）">&#x205E;&#x205E;</td>
			<td class="ysca-text-center">
				<span class="ys-ec-order-drag ysca-text-muted" title="拖拉左側把手調整位置">&#x21C5;</span>
			</td>
			<td>
				<div class="menu-line">
					<strong><?php echo esc_html( (string) $d['title'] ); ?></strong>
					<code><?php echo esc_html( $is_copy ? (string) $d['copy_slug'] : (string) $d['sub_slug'] ); ?></code>
					<span class="level-badge <?php echo esc_attr( $badge_cls ); ?>" title="<?php echo esc_attr( $badge_title ); ?>"><?php echo esc_html( $badge ); ?></span>
					<span class="ys-ec-copy-source">原位置：<?php echo esc_html( (string) ( $d['parent_title'] ?? '' ) ); ?> › <?php echo esc_html( (string) $d['title'] ); ?></span>
				</div>
				<input type="text" class="title-override-inline ys-ec-title-override"
					value="<?php echo esc_attr( $title_override ); ?>"
					placeholder="自訂顯示名稱（留空保留原名）">
				<div class="ys-ec-icon-field">
					<button type="button" class="ys-ec-icon-btn" aria-expanded="false" title="選擇圖示">
						<span class="ys-ec-icon-preview" aria-hidden="true"></span>
						<span class="screen-reader-text">選擇圖示</span>
					</button>
					<input type="text" class="ys-ec-icon-input" value="<?php echo esc_attr( $icon ); ?>"
						placeholder="圖示：留空沿用原父選單（dashicons-… 或圖片網址）" spellcheck="false">
				</div>
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
				<label class="ys-ec-self-only-label" title="勾選後此選單只有目前登入的帳號看得到（含其他管理員）">
					<input type="checkbox" class="ys-ec-self-only" <?php checked( $self_only_uid > 0 ); ?>>
					僅限本人
				</label>
			</td>
			<td class="ys-ec-promote-cell ysca-text-center">
				<span class="ysca-text-muted" aria-hidden="true">—</span>
			</td>
			<td class="ys-ec-hide-cb">
				<input type="checkbox" class="ys-ec-hide-checkbox"
					title="<?php echo esc_attr( $is_copy ? '對管理員以外的帳號隱藏這個複製項（原子選單與頁面不受影響）' : '對管理員以外的帳號隱藏這個項目' ); ?>"
					<?php checked( $hide ); ?>>
			</td>
			<td class="ys-ec-row-actions">
				<?php if ( $is_copy ) : ?>
					<button type="button" class="ys-ec-row-btn ys-ec-row-btn--danger ys-ec-delete-copy" title="刪除這個複製項（原子選單不受影響）">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="ys-ec-row-btn__label">刪除複製</span>
					</button>
				<?php else : ?>
					<button type="button" class="ys-ec-row-btn ys-ec-move-back-btn" title="移回原位：把這個項目放回原本的子選單位置">
						<span class="dashicons dashicons-undo" aria-hidden="true"></span><span class="ys-ec-row-btn__label">移回原位</span>
					</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * 渲染 separator row（v2.39.0：與 main row colspan 對齊）
	 */
	private static function render_separator_row( string $tab, string $title, int $order ): void {
		// 主 row column 數：兩 tab 皆 7（wp_native: drag/order/menu/role/color/hide/del；
		// ys_cart: drag/order/menu/role/升頂層/hide/del）
		// separator 的 colspan = main 中間欄（menu+role+color|promote+hide）= 4
		$colspan = 4;
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

			<nav class="ysca-wl-tabs" role="tablist" aria-label="權限設定分頁">
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
						勾選「僅允許存取受保護的管理選單」後，該使用者只看得到、進得去 YS CART 的頁面、本外掛的頁面，以及透過 <code>ys_admin_menu_extra_admin_slugs</code> filter 指定的頁面（側欄與直接開網址都是）；綁給這位使用者的「僅限本人」選單照樣看得到。控制台、個人資料與其他外掛的頁面都進不去。
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
		// v1.5.1：勾選狀態以鍵也對得上（網址帶 %xx 的選單存檔時 %xx 被拿掉、「自訂」的 return 每頁不同；
		// 以前這種列顯示成沒勾，再按一次儲存就從清單裡掉了）。
		$visible_exact = array_fill_keys( array_map( 'strval', $visible_slugs ), true );
		$visible_keys  = [];
		foreach ( $visible_slugs as $vs ) {
			if ( is_scalar( $vs ) && '' !== YSMenuRouter::slug_key( (string) $vs ) ) {
				$visible_keys[ YSMenuRouter::slug_key( (string) $vs ) ] = true;
			}
		}
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
				<?php if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) : ?>
					<p class="description">這個站沒有安裝 YS CART，「受保護的管理選單」只有本外掛自己的頁面：勾選後，這位使用者會進不了後台的其他頁面（包含控制台）。一般情況請不要勾，改用下方的可見清單。</p>
				<?php endif; ?>
				<details>
					<summary>
						展開可見 slug 清單（已選 <span class="picked-count"><?php echo (int) $picked_count; ?></span> / <?php echo (int) $slug_count; ?> 個；空白 = 不限制）
					</summary>
					<div class="slug-grid">
						<?php foreach ( $all_slugs as $slug => $title ) :
							$slug   = (string) $slug;
							$picked = isset( $visible_exact[ $slug ] )
								|| ( YSMenuRouter::slug_is_altered( $slug ) && isset( $visible_keys[ YSMenuRouter::slug_key( $slug ) ] ) );
							?>
							<label>
								<input type="checkbox" class="visible-slug" value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( $picked ); ?>>
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
					<input type="checkbox" class="ys-cart-only">
					僅允許存取受保護的管理選單
				</label>
				<?php if ( ! defined( 'YS_ECOMMERCE_VERSION' ) ) : ?>
					<p class="description">這個站沒有安裝 YS CART，「受保護的管理選單」只有本外掛自己的頁面：勾選後，這位使用者會進不了後台的其他頁面（包含控制台）。一般情況請不要勾，改用下方的可見清單。</p>
				<?php endif; ?>
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
	 * v1.5.0：YS CART V3 上跟著它指向的頁面判斷的導覽列（YSMenuRouter::nav_target()，例如「顧客」底下的「會員生日」）不列：
	 * 可見清單比對的是那一頁，勾這一列沒有作用（要讓人看到就勾那一頁）。
	 *
	 * @return array<string, string> slug => title
	 */
	private static function collect_all_known_slugs(): array {
		$out = [];
		foreach ( YSPermissionController::get_all_ys_cart_endpoints() as $item ) {
			$slug   = (string) ( $item['slug'] ?? '' );
			$parent = 'sub' === ( $item['level'] ?? 'top' ) ? (string) ( $item['parent_slug'] ?? '' ) : null;
			if ( '' !== $slug && ! isset( $out[ $slug ] ) && YSMenuRouter::nav_target( $slug, $parent ) === $slug ) {
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
