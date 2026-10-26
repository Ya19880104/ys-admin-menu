<?php
/**
 * 上方工具列（Admin Bar）。
 *
 * 1. 與選單規則同步（v1.2.4）：選單被隱藏（或不在這個帳號的白名單裡）時，工具列上指向同一頁的連結
 *    也一併拿掉：例如隱藏「留言」之後，工具列的留言圖示點了只會得到 404，不如不顯示。
 *    只處理「連到 wp-admin 選單頁」的節點。站名、帳號、登出這類結構性節點，以及編輯畫面
 *    （post.php 等沒有對應選單項的頁面）不動。
 *
 * 2. 白牌（v1.4.0）：
 *    - 依白牌設定隱藏頂層節點，後台與前台各一份清單（ys_admin_menu_adminbar_hidden）。
 *      隱藏只是工具列上看不到，不是權限：功能本身照常可以用（例如直接輸入網址）。
 *    - 前台 Admin Bar 載入方式（ys_admin_menu_frontend_adminbar）：照 WordPress 預設／完全不載入／
 *      載入並套用前台隱藏清單；可再限定只有哪些角色在前台看得到（ys_admin_menu_frontend_adminbar_roles）。
 *      只影響前台，後台的工具列不受影響。
 *    - 記下看過的頂層節點（ys_admin_menu_adminbar_seen），白牌設定頁才列得出外掛加的節點：
 *      後台只在白牌設定頁記、前台在管理員瀏覽時記，只有出現新的節點才寫入。
 *
 * @package YangSheep\AdminMenu\Menu
 * @since   1.2.4
 */

namespace YangSheep\AdminMenu\Menu;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSAdminBarFilter {

	/** wp_options key — 要隱藏的頂層節點：['admin' => string[], 'front' => string[]]。 */
	public const OPTION_HIDDEN = 'ys_admin_menu_adminbar_hidden';

	/** wp_options key — 看過的頂層節點：['admin' => [id => 標題], 'front' => [id => 標題]]（不自動載入）。 */
	public const OPTION_SEEN = 'ys_admin_menu_adminbar_seen';

	/** wp_options key — 前台 Admin Bar 載入方式（default／hide／filtered，預設 default）。 */
	public const OPTION_FRONT_MODE = 'ys_admin_menu_frontend_adminbar';

	/** wp_options key — 前台 Admin Bar 限定角色（空陣列＝不限制）。 */
	public const OPTION_FRONT_ROLES = 'ys_admin_menu_frontend_adminbar_roles';

	/** 前台：照 WordPress 預設（各帳號「瀏覽網站時顯示工具列」的設定）。 */
	public const FRONT_DEFAULT = 'default';

	/** 前台：完全不載入（不輸出工具列，也不載入它的 CSS／JS）。 */
	public const FRONT_HIDE = 'hide';

	/** 前台：載入，並套用「前台隱藏」清單。 */
	public const FRONT_FILTERED = 'filtered';

	/** 節點 id 允許的字元。 */
	private const NODE_ID_PATTERN = '/^[A-Za-z0-9_.:\-]{1,100}$/';

	/** 不能隱藏的節點：手機版選單鈕（藏了手機上就打不開後台選單）、復原模式的離開連結、群組本身。 */
	private const UNHIDEABLE_NODES = [ 'menu-toggle', 'recovery-mode', 'top-secondary', 'root-default', 'root' ];

	/** 每個位置最多記幾個看過的節點。 */
	private const SEEN_MAX = 80;

	/** @var array{admin: array<string,string>, front: array<string,string>} 這一次請求實際看到的頂層節點。 */
	private static array $live = [
		'admin' => [],
		'front' => [],
	];

	/** 不論規則如何都保留的節點。 */
	private const PROTECTED_NODES = [
		'menu-toggle',
		'wp-logo',
		'site-name',
		'dashboard',
		'view-site',
		'view',
		'my-account',
		'user-actions',
		'user-info',
		'edit-profile',
		'logout',
		'search',
		'top-secondary',
	];

	/** 沒有對應選單項的頁面（編輯畫面、處理端點）：不依選單規則判斷。 */
	private const NON_MENU_SCRIPTS = [
		'index.php',
		'profile.php',
		'post.php',
		'term.php',
		'comment.php',
		'user-edit.php',
		'about.php',
		'admin.php',
		'admin-ajax.php',
		'admin-post.php',
	];

	public static function register(): void {
		// v1.4.0：先記下這一頁的頂層節點（要在任何隱藏之前）。
		add_action( 'wp_before_admin_bar_render', [ self::class, 'remember_nodes' ], 1 );
		add_action( 'wp_before_admin_bar_render', [ self::class, 'prune' ], 999 );
		// v1.4.0：白牌設定的隱藏清單。
		add_action( 'wp_before_admin_bar_render', [ self::class, 'hide_configured_nodes' ], 1000 );
		// v1.4.0：前台 Admin Bar 載入方式（後台不受影響）。
		add_filter( 'show_admin_bar', [ self::class, 'filter_show_admin_bar' ], 20 );
	}

	public static function prune(): void {
		global $wp_admin_bar;

		if ( ! is_object( $wp_admin_bar ) || ! is_user_logged_in() || empty( YSMenuRouter::get_config() ) ) {
			return;
		}

		$nodes = $wp_admin_bar->get_nodes();
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return;
		}

		$denied = [];
		foreach ( $nodes as $node ) {
			$id = (string) $node->id;
			if ( in_array( $id, self::PROTECTED_NODES, true ) ) {
				continue;
			}
			$slug = self::menu_slug_from_href( is_string( $node->href ?? null ) ? $node->href : '' );
			if ( '' !== $slug && ! YSMenuRouter::current_user_can_access_admin_slug( $slug ) ) {
				$denied[ $id ] = true;
			}
		}
		if ( empty( $denied ) ) {
			return;
		}

		// 仍有可用子項的父節點（例如「新增項目」）保留下來，連結改指向第一個可用的子項。
		$first_child_href = [];
		foreach ( $nodes as $node ) {
			$parent = (string) ( $node->parent ?? '' );
			if ( '' === $parent || ! isset( $denied[ $parent ] ) || isset( $denied[ (string) $node->id ] ) ) {
				continue;
			}
			if ( ! isset( $first_child_href[ $parent ] ) && is_string( $node->href ?? null ) && '' !== $node->href ) {
				$first_child_href[ $parent ] = $node->href;
			}
		}

		foreach ( array_keys( $denied ) as $id ) {
			if ( isset( $first_child_href[ $id ] ) ) {
				$wp_admin_bar->add_node( [
					'id'   => $id,
					'href' => $first_child_href[ $id ],
				] );
				continue;
			}
			$wp_admin_bar->remove_node( $id );
		}
	}

	/**
	 * 把工具列連結換成選單 slug；不是 wp-admin 的選單頁就回空字串。
	 *
	 * 例：…/wp-admin/edit-comments.php → edit-comments.php
	 *     …/wp-admin/post-new.php?post_type=page → post-new.php?post_type=page
	 *     …/wp-admin/admin.php?page=foo → foo
	 */
	private static function menu_slug_from_href( string $href ): string {
		$base = admin_url();
		if ( '' === $href || 0 !== strpos( $href, $base ) ) {
			return '';
		}

		$relative = wp_specialchars_decode( substr( $href, strlen( $base ) ) );
		$parts    = explode( '?', $relative, 2 );
		$script   = (string) $parts[0];
		if ( '' === $script || false !== strpos( $script, '/' ) ) {
			return '';
		}

		$args = [];
		if ( isset( $parts[1] ) ) {
			wp_parse_str( $parts[1], $args );
		}
		if ( isset( $args['page'] ) && is_string( $args['page'] ) && '' !== $args['page'] ) {
			return sanitize_text_field( $args['page'] );
		}
		if ( in_array( $script, self::NON_MENU_SCRIPTS, true ) ) {
			return '';
		}

		$post_type = isset( $args['post_type'] ) && is_string( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : '';
		if ( '' !== $post_type && 'post' !== $post_type && in_array( $script, [ 'edit.php', 'post-new.php' ], true ) ) {
			return $script . '?post_type=' . $post_type;
		}

		return sanitize_text_field( $script );
	}

	/* ═══════════════════════════ v1.4.0 白牌：隱藏節點、前台載入方式 ═══════════════════════════ */

	/**
	 * 內建的頂層節點與顯示名稱（設定頁用固定名稱，不用節點標題：標題裡可能有帳號名稱、數字徽章）。
	 *
	 * @return array<string, string>
	 */
	public static function known_nodes(): array {
		return [
			'wp-logo'         => __( 'WordPress 標誌（左上角，含關於、文件等子選單）', 'ys-admin-menu' ),
			'site-name'       => __( '站點名稱（後台的「造訪網站」；前台的「控制台」等）', 'ys-admin-menu' ),
			'site-editor'     => __( '編輯網站（前台，區塊佈景主題）', 'ys-admin-menu' ),
			'customize'       => __( '自訂（前台，傳統佈景主題）', 'ys-admin-menu' ),
			'updates'         => __( '更新', 'ys-admin-menu' ),
			'comments'        => __( '留言', 'ys-admin-menu' ),
			'new-content'     => __( '新增項目（＋新增）', 'ys-admin-menu' ),
			'edit'            => __( '編輯（前台的「編輯頁面／文章」）', 'ys-admin-menu' ),
			'view'            => __( '檢視（後台編輯畫面的「檢視頁面／文章」）', 'ys-admin-menu' ),
			'archive'         => __( '檢視彙整（後台列表頁）', 'ys-admin-menu' ),
			'command-palette' => __( '命令面板（Ctrl+K）', 'ys-admin-menu' ),
			'search'          => __( '搜尋（前台）', 'ys-admin-menu' ),
			'my-account'      => __( '你好，使用者（右上角帳號選單，含登出）', 'ys-admin-menu' ),
			'my-sites'        => __( '我的網站（多站網路）', 'ys-admin-menu' ),
		];
	}

	/**
	 * 設定頁一定列出的內建節點（其他內建節點與外掛節點要看過才列）。
	 *
	 * @return string[]
	 */
	private static function always_listed_nodes(): array {
		return [ 'wp-logo', 'site-name', 'updates', 'comments', 'new-content', 'search', 'my-account' ];
	}

	/**
	 * wp_before_admin_bar_render（priority 1，任何隱藏之前）：記下這一頁的頂層節點。
	 * 只替管理員記；後台只在白牌設定頁記；只有出現新的節點 id 才寫入。
	 */
	public static function remember_nodes(): void {
		global $wp_admin_bar;

		if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'get_nodes' ) || ! YSAccess::can_manage() ) {
			return;
		}

		$context = is_admin() ? 'admin' : 'front';
		if ( 'admin' === $context && ! self::is_settings_screen() ) {
			return;
		}

		$top                    = self::top_level_nodes( $wp_admin_bar );
		self::$live[ $context ] = $top;
		self::merge_seen( $context, $top );
	}

	/**
	 * wp_before_admin_bar_render（priority 1000）：拿掉白牌設定要隱藏的頂層節點（連同子項）。
	 * 前台只有在「載入，並套用前台隱藏清單」時才套用。
	 */
	public static function hide_configured_nodes(): void {
		global $wp_admin_bar;

		if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'remove_node' ) ) {
			return;
		}

		$context = is_admin() ? 'admin' : 'front';
		if ( 'front' === $context && self::FRONT_FILTERED !== self::get_front_mode() ) {
			return;
		}

		foreach ( self::get_hidden( $context ) as $id ) {
			$wp_admin_bar->remove_node( $id );
		}
	}

	/**
	 * show_admin_bar：前台的載入方式與限定角色。只會把 true 改成 false，不會強制顯示；後台一律不動。
	 *
	 * @param bool $show
	 * @return bool
	 */
	public static function filter_show_admin_bar( $show ) {
		if ( is_admin() || ! $show ) {
			return $show;
		}

		if ( self::FRONT_HIDE === self::get_front_mode() ) {
			return false;
		}

		$roles = self::get_front_roles();
		if ( ! empty( $roles ) && ! self::current_user_has_any_role( $roles ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * @return string[] 要隱藏的節點 id（context：admin／front）。
	 */
	public static function get_hidden( string $context ): array {
		$raw  = get_option( self::OPTION_HIDDEN, [] );
		$list = ( is_array( $raw ) && isset( $raw[ $context ] ) && is_array( $raw[ $context ] ) ) ? $raw[ $context ] : [];
		$out  = [];
		foreach ( $list as $id ) {
			if ( is_string( $id ) && self::is_hideable_id( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function get_front_mode(): string {
		$mode = get_option( self::OPTION_FRONT_MODE, self::FRONT_DEFAULT );
		return in_array( $mode, [ self::FRONT_DEFAULT, self::FRONT_HIDE, self::FRONT_FILTERED ], true ) ? $mode : self::FRONT_DEFAULT;
	}

	/**
	 * @return string[] 前台看得到 Admin Bar 的角色（空陣列＝不限制）。
	 */
	public static function get_front_roles(): array {
		$raw = get_option( self::OPTION_FRONT_ROLES, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $role ) {
			if ( is_string( $role ) && '' !== $role && sanitize_key( $role ) === $role && ! in_array( $role, $out, true ) ) {
				$out[] = $role;
			}
		}
		return $out;
	}

	/**
	 * 設定頁要列的節點：一定列的內建節點、看過的節點、已經設成隱藏的節點（就算現在偵測不到也要能取消）。
	 *
	 * @return array<string, array{label:string,known:bool,seen_admin:bool,seen_front:bool,hide_admin:bool,hide_front:bool}>
	 */
	public static function listable_nodes(): array {
		$known  = self::known_nodes();
		$seen   = self::get_seen();
		$hidden = [
			'admin' => self::get_hidden( 'admin' ),
			'front' => self::get_hidden( 'front' ),
		];
		foreach ( [ 'admin', 'front' ] as $context ) {
			foreach ( self::$live[ $context ] as $id => $label ) {
				if ( ! array_key_exists( $id, $seen[ $context ] ) ) {
					$seen[ $context ][ $id ] = isset( $known[ $id ] ) ? '' : $label;
				}
			}
		}

		$labels = [];
		foreach ( $known as $id => $label ) {
			$listed = in_array( $id, self::always_listed_nodes(), true )
				|| isset( $seen['admin'][ $id ] ) || isset( $seen['front'][ $id ] )
				|| in_array( $id, $hidden['admin'], true ) || in_array( $id, $hidden['front'], true );
			if ( $listed ) {
				$labels[ $id ] = $label;
			}
		}
		foreach ( [ 'admin', 'front' ] as $context ) {
			foreach ( $seen[ $context ] as $id => $label ) {
				$id = (string) $id;
				if ( ! isset( $labels[ $id ] ) ) {
					$labels[ $id ] = '' !== $label ? $label : $id;
				}
			}
			foreach ( $hidden[ $context ] as $id ) {
				if ( ! isset( $labels[ $id ] ) ) {
					$labels[ $id ] = $id;
				}
			}
		}

		$rows = [];
		foreach ( $labels as $id => $label ) {
			$id          = (string) $id;
			$rows[ $id ] = [
				'label'      => (string) $label,
				'known'      => isset( $known[ $id ] ),
				'seen_admin' => isset( $seen['admin'][ $id ] ),
				'seen_front' => isset( $seen['front'][ $id ] ),
				'hide_admin' => in_array( $id, $hidden['admin'], true ),
				'hide_front' => in_array( $id, $hidden['front'], true ),
			];
		}
		return $rows;
	}

	/**
	 * 儲存設定（由 YSWhiteLabelAdmin::handle_save_settings 在驗過權限與 nonce 之後呼叫）。
	 * 節點 id 只收設定頁列得出來的（白名單比對）；角色只收站上存在的角色。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 */
	public static function save_settings( array $post ): void {
		if ( ! YSAccess::can_manage() ) {
			return;
		}

		$allowed = array_map( 'strval', array_keys( self::listable_nodes() ) );
		$hidden  = [];
		foreach ( [ 'admin' => 'adminbar_hide_admin', 'front' => 'adminbar_hide_front' ] as $context => $field ) {
			$hidden[ $context ] = [];
			$raw                = isset( $post[ $field ] ) && is_array( $post[ $field ] ) ? $post[ $field ] : [];
			foreach ( $raw as $id ) {
				if ( is_string( $id ) && self::is_hideable_id( $id ) && in_array( $id, $allowed, true ) && ! in_array( $id, $hidden[ $context ], true ) ) {
					$hidden[ $context ][] = $id;
				}
			}
		}
		update_option( self::OPTION_HIDDEN, $hidden, true );

		$mode = isset( $post['frontend_adminbar'] ) && is_string( $post['frontend_adminbar'] ) ? $post['frontend_adminbar'] : self::FRONT_DEFAULT;
		if ( ! in_array( $mode, [ self::FRONT_DEFAULT, self::FRONT_HIDE, self::FRONT_FILTERED ], true ) ) {
			$mode = self::FRONT_DEFAULT;
		}
		update_option( self::OPTION_FRONT_MODE, $mode, true );

		$names = self::role_names();
		$roles = [];
		$raw   = isset( $post['frontend_adminbar_roles'] ) && is_array( $post['frontend_adminbar_roles'] ) ? $post['frontend_adminbar_roles'] : [];
		foreach ( $raw as $role ) {
			if ( is_string( $role ) && isset( $names[ $role ] ) && ! in_array( $role, $roles, true ) ) {
				$roles[] = $role;
			}
		}
		update_option( self::OPTION_FRONT_ROLES, $roles, true );
	}

	/**
	 * 設定頁需要的值。
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_view(): array {
		return [
			'nodes'      => self::listable_nodes(),
			'mode'       => self::get_front_mode(),
			'roles'      => self::get_front_roles(),
			'role_names' => self::role_names(),
		];
	}

	/**
	 * @return array<string, string> 角色 slug => 顯示名稱。
	 */
	private static function role_names(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return [];
		}
		$out = [];
		foreach ( (array) wp_roles()->get_names() as $slug => $name ) {
			$out[ (string) $slug ] = function_exists( 'translate_user_role' ) ? translate_user_role( (string) $name ) : (string) $name;
		}
		return $out;
	}

	/**
	 * @param object $bar WP_Admin_Bar
	 * @return array<string, string> 頂層節點 id => 去掉 HTML 的標題。
	 */
	private static function top_level_nodes( $bar ): array {
		$out   = [];
		$nodes = $bar->get_nodes();
		foreach ( is_array( $nodes ) ? $nodes : [] as $node ) {
			if ( ! is_object( $node ) || ! empty( $node->group ) ) {
				continue;
			}
			$parent = isset( $node->parent ) && is_string( $node->parent ) ? $node->parent : '';
			if ( ! in_array( $parent, [ '', 'root-default', 'top-secondary' ], true ) ) {
				continue;
			}
			$id = isset( $node->id ) ? (string) $node->id : '';
			if ( ! self::is_hideable_id( $id ) ) {
				continue;
			}
			$out[ $id ] = self::node_label( $node->title ?? '' );
		}
		return $out;
	}

	private static function is_hideable_id( string $id ): bool {
		return 1 === preg_match( self::NODE_ID_PATTERN, $id ) && ! in_array( $id, self::UNHIDEABLE_NODES, true );
	}

	/**
	 * @param mixed $title
	 */
	private static function node_label( $title ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( is_string( $title ) ? $title : '' ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 60 ) : substr( $text, 0, 60 );
	}

	/**
	 * @param array<string, string> $top
	 */
	private static function merge_seen( string $context, array $top ): void {
		$seen    = self::get_seen();
		$known   = self::known_nodes();
		$changed = false;
		foreach ( $top as $id => $label ) {
			$id = (string) $id;
			if ( array_key_exists( $id, $seen[ $context ] ) ) {
				continue;
			}
			if ( count( $seen[ $context ] ) >= self::SEEN_MAX ) {
				break;
			}
			// 內建節點用固定名稱，不存標題（例如「你好，帳號名稱」）。
			$seen[ $context ][ $id ] = isset( $known[ $id ] ) ? '' : $label;
			$changed                 = true;
		}
		if ( $changed ) {
			update_option( self::OPTION_SEEN, $seen, false );
		}
	}

	/**
	 * @return array{admin: array<string,string>, front: array<string,string>}
	 */
	private static function get_seen(): array {
		$raw = get_option( self::OPTION_SEEN, [] );
		$out = [
			'admin' => [],
			'front' => [],
		];
		foreach ( [ 'admin', 'front' ] as $context ) {
			$list = ( is_array( $raw ) && isset( $raw[ $context ] ) && is_array( $raw[ $context ] ) ) ? $raw[ $context ] : [];
			foreach ( $list as $id => $label ) {
				$id = (string) $id;
				if ( self::is_hideable_id( $id ) ) {
					$out[ $context ][ $id ] = is_string( $label ) ? $label : '';
				}
			}
		}
		return $out;
	}

	private static function current_user_has_any_role( array $roles ): bool {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		// 多站網路的超級管理員在子站不一定有 administrator 角色：限定角色含管理員時一併放行。
		if ( is_multisite() && is_super_admin( $user->ID ) && in_array( 'administrator', $roles, true ) ) {
			return true;
		}
		return (bool) array_intersect( (array) $user->roles, $roles );
	}

	private static function is_settings_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && false !== strpos( (string) $screen->id, \YangSheep\AdminMenu\Admin\YSMenuPage::SLUG_SETTINGS );
	}
}
