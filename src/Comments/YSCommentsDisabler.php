<?php
/**
 * v1.7.0：關閉留言（設計 docs/plans/2026-10-26-ys-admin-menu-170-design.md §4；對標 Disable Comments 的做法與踩過的坑）。
 *
 * 選項 ys_admin_menu_comments（autoload yes）：
 *   { version: 1, mode: 'off' | 'everywhere' | 'post_types', post_types: string[], keep_existing: bool }
 *   - off（預設）：一個 hook 都不掛。
 *   - everywhere：全站關閉，連後台「留言」選單、工具列、控制台的留言數、留言 RSS、Pingback、XML-RPC 新增留言一起關。
 *   - post_types：只關指定的文章類型（後台選單照舊，因為其他類型還有留言）。
 *   - keep_existing：舊留言照樣顯示，只是不能再留；false＝舊留言也不顯示（前台頁面、留言 RSS、「最新留言」區塊與小工具、
 *     沒有審核權限的人用 REST 讀取都不顯示；後台「留言」頁與資料庫裡的留言不動）。
 *
 * 這是功能開關，不是權限：對所有人生效（含管理員），跟選單的「隱藏」（管理員不受影響）不同。
 * 擋的是走 WordPress 留言流程的新增（留言表單、REST、XML-RPC、後台「回覆」）；外掛自己用 wp_insert_comment() 寫入的
 * （例如 WooCommerce 的訂單備註）不在範圍內。
 * 區塊編輯器的「備註」（留言類型 note，WordPress 6.9 起）照樣可以用：能編輯那一篇的人新增備註一律放行。
 *
 * @package YangSheep\AdminMenu\Comments
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Comments;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSCommentsDisabler {

	public const OPTION = 'ys_admin_menu_comments';

	/** 關閉留言時照樣放行的留言類型（區塊編輯器的備註；只限能編輯那一篇的人）。 */
	public const ALLOWED_TYPES = [ 'note' ];

	/** 全站關閉時，直接開這兩頁回 403（「設定 › 討論」只拿掉選單：那一頁還有大頭貼設定，網址照樣能進）。 */
	public const BLOCKED_PAGES = [ 'edit-comments.php', 'comment.php' ];

	/** @var array{version:int,mode:string,post_types:string[],keep_existing:bool}|null register() 讀到的設定（這個請求內不變）。 */
	private static ?array $settings = null;

	/** @var array<int,string> 這個請求在 wp_loaded 被本模組拿掉 comments 支援的文章類型（設定頁與存檔仍當成支援留言）。 */
	private static array $stripped = [];

	/**
	 * @return array{version:int,mode:string,post_types:string[],keep_existing:bool}
	 */
	public static function defaults(): array {
		return [
			'version'       => 1,
			'mode'          => 'off',
			'post_types'    => [],
			'keep_existing' => false,
		];
	}

	/**
	 * @param mixed $raw
	 * @return array{version:int,mode:string,post_types:string[],keep_existing:bool}
	 */
	public static function sanitize( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : [];
		$mode  = in_array( $raw['mode'] ?? '', [ 'everywhere', 'post_types' ], true ) ? (string) $raw['mode'] : 'off';
		$types = [];
		foreach ( is_array( $raw['post_types'] ?? null ) ? $raw['post_types'] : [] as $type ) {
			$type = is_string( $type ) ? sanitize_key( $type ) : '';
			if ( '' !== $type && count( $types ) < 100 ) {
				$types[ $type ] = $type;
			}
		}
		return [
			'version'       => 1,
			'mode'          => $mode,
			'post_types'    => array_values( $types ),
			'keep_existing' => ! empty( $raw['keep_existing'] ),
		];
	}

	/**
	 * 已存的設定。沒存過（不在 alloptions 裡）＝預設 off，不多查資料庫（存檔一律 autoload；被別的工具改成不自動載入時，
	 * 打開「後台精簡」頁會自動改回來並提示，見 YSAccess::heal_autoload()）。
	 *
	 * @return array{version:int,mode:string,post_types:string[],keep_existing:bool}
	 */
	public static function settings(): array {
		if ( function_exists( 'wp_load_alloptions' ) && ! array_key_exists( self::OPTION, (array) wp_load_alloptions() ) ) {
			return self::defaults();
		}
		return self::sanitize( get_option( self::OPTION, [] ) );
	}

	/**
	 * 支援留言的文章類型，包含這個請求被本模組拿掉支援的（strip_post_type_support()）。設定頁與存檔共用：
	 * 不保留舊留言時每個請求都會拿掉關閉範圍的 comments 支援，只看 get_post_types_by_support() 的話，
	 * 已關閉的類型會被當成「不支援留言」，再存一次就從清單裡掉出去、留言重新打開。
	 *
	 * @return array<int,string>
	 */
	public static function comment_capable_types(): array {
		$supported = function_exists( 'get_post_types_by_support' ) ? array_map( 'strval', (array) get_post_types_by_support( 'comments' ) ) : [];
		return array_values( array_unique( array_merge( $supported, self::$stripped ) ) );
	}

	/**
	 * 存檔（後台精簡頁）。指定類型模式新勾的類型要「現在有註冊、而且支援留言」（含本模組拿掉支援的）；
	 * 已經存過的類型照樣保留：外掛暫時停用（沒註冊），或別的設定讓它暫時不支援留言（例如 WooCommerce 關閉商品評論），
	 * 打開設定頁按儲存都不會把它吃掉，之後重新打開時照樣關閉。
	 *
	 * @param array<string, mixed> $raw 已 wp_unslash 的表單值（mode、post_types、keep_existing）。
	 */
	public static function save( array $raw ): void {
		$cfg       = self::sanitize( $raw );
		$supported = self::comment_capable_types();
		$stored    = self::settings()['post_types'];
		$cfg['post_types'] = array_values(
			array_filter(
				$cfg['post_types'],
				static fn( string $type ): bool => in_array( $type, $supported, true ) || in_array( $type, $stored, true )
			)
		);
		update_option( self::OPTION, $cfg, true );
	}

	/** 這個請求要不要動手（register() 之後才有值）。 */
	public static function active(): bool {
		return null !== self::$settings && 'off' !== self::$settings['mode'];
	}

	public static function register(): void {
		self::$stripped = [];
		$s              = self::settings();
		if ( 'off' === $s['mode'] ) {
			self::$settings = null;
			return; // 預設：一個 hook 都不掛
		}
		self::$settings = $s;

		add_action( 'wp_loaded', [ self::class, 'strip_post_type_support' ] );
		add_filter( 'comments_open', [ self::class, 'filter_open' ], 20, 2 );
		add_filter( 'pings_open', [ self::class, 'filter_open' ], 20, 2 );
		if ( ! $s['keep_existing'] ) {
			add_filter( 'comments_array', [ self::class, 'filter_comments_array' ], 20, 2 );
			add_filter( 'get_comments_number', [ self::class, 'filter_count' ], 20, 2 );
			// 「最新留言」區塊與「近期留言」小工具都用這個 filter 查留言；REST 讀取、全站留言 RSS 也不列關閉範圍的舊留言。
			add_filter( 'widget_comments_args', [ self::class, 'filter_comment_query_args' ], 20 );
			add_filter( 'rest_comment_query', [ self::class, 'filter_rest_comment_query' ], 20, 2 );
			add_filter( 'comment_feed_where', [ self::class, 'filter_comment_feed_where' ], 20, 2 );
		}
		add_action( 'template_redirect', [ self::class, 'front' ], 9 );
		add_filter( 'rest_pre_insert_comment', [ self::class, 'rest_guard' ], 20, 2 );
		// 所有走 wp_new_comment() 的新增（後台「回覆」、留言表單、XML-RPC）最後一道關卡。
		add_filter( 'pre_comment_approved', [ self::class, 'guard_new_comment' ], 99, 2 );

		if ( 'everywhere' !== $s['mode'] ) {
			return;
		}
		add_filter( 'wp_headers', [ self::class, 'filter_headers' ] );
		// 不送 Pingback：寄送前把要通知的連結清空（不改核心選項 default_pingback_flag 的讀值，「設定 › 討論」存檔不會把它寫掉）。
		add_action( 'pre_ping', [ self::class, 'drop_pings' ] );
		add_filter( 'xmlrpc_methods', [ self::class, 'filter_xmlrpc' ] );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_action( 'widgets_init', [ self::class, 'unregister_widget' ], 20 );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_assets' ] );
		// 在選單路由（admin_menu 9999）之後才拿掉：YS 選單管理的設定表不會列出它，已存的那一列照舊保留。
		add_action( 'admin_menu', [ self::class, 'admin_menu' ], PHP_INT_MAX );
		add_action( 'admin_init', [ self::class, 'block_admin_pages' ] );
		add_action( 'admin_bar_menu', [ self::class, 'admin_bar' ], 999 );
		add_action( 'admin_print_styles-index.php', [ self::class, 'dashboard_css' ] );
	}

	/** 這個文章類型的留言是不是關閉的。 */
	public static function disabled_for( string $post_type ): bool {
		if ( null === self::$settings || 'off' === self::$settings['mode'] ) {
			return false;
		}
		if ( 'everywhere' === self::$settings['mode'] ) {
			return true;
		}
		return '' !== $post_type && in_array( $post_type, self::$settings['post_types'], true );
	}

	/** 某篇文章的留言是不是關閉的（$post 可以是 ID、WP_Post 或 null）。全站模式不看文章。 */
	private static function disabled_for_post( $post ): bool {
		if ( null !== self::$settings && 'everywhere' === self::$settings['mode'] ) {
			return true;
		}
		$type = $post ? get_post_type( $post ) : false;
		return is_string( $type ) && self::disabled_for( $type );
	}

	/** wp_loaded：等所有文章類型都註冊完，拿掉關閉範圍的 trackbacks（不保留舊留言時連 comments 也拿掉）支援。 */
	public static function strip_post_type_support(): void {
		foreach ( get_post_types() as $type ) {
			$type = (string) $type;
			if ( ! self::disabled_for( $type ) ) {
				continue;
			}
			if ( post_type_supports( $type, 'trackbacks' ) ) {
				remove_post_type_support( $type, 'trackbacks' );
			}
			if ( ! self::$settings['keep_existing'] && post_type_supports( $type, 'comments' ) ) {
				remove_post_type_support( $type, 'comments' );
				self::$stripped[] = $type;
			}
		}
	}

	/**
	 * comments_open／pings_open（20）：關閉範圍一律 false（wp-comments-post.php、REST、XML-RPC 都會問它）。
	 *
	 * @param mixed $open
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function filter_open( $open, $post_id = null ) {
		return self::disabled_for_post( $post_id ) ? false : $open;
	}

	/**
	 * comments_array（20）：不保留舊留言時，關閉範圍的文章不顯示留言。
	 *
	 * @param mixed $comments
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function filter_comments_array( $comments, $post_id = null ) {
		return self::disabled_for_post( $post_id ) ? [] : $comments;
	}

	/**
	 * get_comments_number（20）：同上，留言數顯示 0。
	 *
	 * @param mixed $count
	 * @param mixed $post_id
	 * @return mixed
	 */
	public static function filter_count( $count, $post_id = null ) {
		return self::disabled_for_post( $post_id ) ? 0 : $count;
	}

	/**
	 * 不保留舊留言時，查留言的參數排除關閉範圍：全站模式什麼都查不到，指定類型模式只查其他類型的文章。
	 *
	 * @param mixed $args
	 * @return mixed
	 */
	public static function filter_comment_query_args( $args ) {
		if ( ! is_array( $args ) || null === self::$settings ) {
			return $args;
		}
		if ( 'everywhere' === self::$settings['mode'] ) {
			$args['comment__in'] = [ 0 ];
			return $args;
		}
		$open = array_values( array_diff( array_map( 'strval', get_post_types() ), self::$settings['post_types'] ) );
		$args['post_type'] = [] !== $open ? $open : [ '__ys_admin_menu_none__' ];
		return $args;
	}

	/**
	 * rest_comment_query（20）：沒有審核留言權限的人用 REST 讀留言時，不列關閉範圍的舊留言（備註照常）。
	 *
	 * @param mixed $args
	 * @param mixed $request
	 * @return mixed
	 */
	public static function filter_rest_comment_query( $args, $request = null ) {
		if ( ! is_array( $args ) || current_user_can( 'moderate_comments' ) ) {
			return $args;
		}
		$types = isset( $args['type'] ) ? (array) $args['type'] : [];
		if ( [] !== $types && [] === array_diff( array_map( 'strval', $types ), self::ALLOWED_TYPES ) ) {
			return $args; // 只查備註。
		}
		return self::filter_comment_query_args( $args );
	}

	/**
	 * comment_feed_where（20）：指定類型模式不保留舊留言時，全站與彙整的留言 RSS 不列關閉類型的留言（全站模式整個 RSS 轉址）。
	 *
	 * 單篇的留言 RSS 不動：那個查詢沒有 JOIN posts（WP_Query::get_posts() 的 is_singular 分支），加上 posts 的欄位會整個出錯；
	 * 關閉類型的單篇由 front() 轉回文章，沒關閉的單篇本來就不用排除。不知道是哪一種查詢時（沒帶 $query）改用子查詢，兩種都適用。
	 *
	 * @param mixed $where
	 * @param mixed $query WP_Query（WordPress 以參照傳入）。
	 * @return mixed
	 */
	public static function filter_comment_feed_where( $where, $query = null ) {
		global $wpdb;
		if ( ! is_string( $where ) || null === self::$settings || 'post_types' !== self::$settings['mode'] || [] === self::$settings['post_types'] || ! isset( $wpdb ) ) {
			return $where;
		}
		if ( is_object( $query ) && ! empty( $query->is_singular ) ) {
			return $where;
		}
		$holders = implode( ',', array_fill( 0, count( self::$settings['post_types'] ), '%s' ) );
		if ( ! is_object( $query ) ) {
			return $where . $wpdb->prepare( " AND {$wpdb->comments}.comment_post_ID NOT IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($holders) )", self::$settings['post_types'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- 佔位符數量照清單產生
		}
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_type NOT IN ($holders)", self::$settings['post_types'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- 佔位符數量照清單產生
	}

	/**
	 * template_redirect（9）：留言 RSS 轉回文章或首頁（301）；單篇關閉時換成空的留言模板、不載入回覆留言的 script。
	 */
	public static function front(): void {
		$object = get_queried_object();
		$single = is_singular() && $object instanceof \WP_Post;
		if ( is_comment_feed() ) {
			if ( $single && self::disabled_for_post( $object ) ) {
				$link = get_permalink( $object );
				wp_safe_redirect( is_string( $link ) && '' !== $link ? $link : home_url( '/' ), 301 );
				exit;
			}
			if ( ! $single && 'everywhere' === self::$settings['mode'] ) {
				wp_safe_redirect( home_url( '/' ), 301 );
				exit;
			}
		}
		if ( ! $single || ! self::disabled_for_post( $object ) ) {
			return;
		}
		wp_deregister_script( 'comment-reply' );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		if ( ! self::$settings['keep_existing'] ) {
			add_filter( 'comments_template', [ self::class, 'empty_template' ], 20 );
		}
	}

	public static function empty_template(): string {
		return YS_ADMIN_MENU_PLUGIN_DIR . 'templates/comments-empty.php';
	}

	/** 備註（note）：能編輯那一篇的人照樣可以新增。 */
	private static function allowed_note( string $type, int $post_id ): bool {
		return in_array( $type, self::ALLOWED_TYPES, true ) && $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * rest_pre_insert_comment（20）：REST 新增留言的第二道檢查（第一道是核心的 comments_open）。能編輯那一篇的人新增備註（note）放行。
	 *
	 * @param mixed $prepared
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rest_guard( $prepared, $request = null ) {
		if ( ! is_array( $prepared ) ) {
			return $prepared;
		}
		$post_id = (int) ( $prepared['comment_post_ID'] ?? 0 );
		if ( self::allowed_note( (string) ( $prepared['comment_type'] ?? 'comment' ), $post_id ) ) {
			return $prepared;
		}
		if ( self::disabled_for_post( $post_id ) ) {
			return new \WP_Error( 'ys_admin_menu_comments_closed', __( '這個網站已關閉留言。', 'ys-admin-menu' ), [ 'status' => 403 ] );
		}
		return $prepared;
	}

	/**
	 * pre_comment_approved（99）：走 wp_new_comment() 的新增（後台「留言」頁與控制台的「回覆」、留言表單、XML-RPC），
	 * 目標文章在關閉範圍就拒絕。後台的「回覆」不問 comments_open，只能在這裡擋。REST 交給 rest_guard()（那裡回 403）。
	 *
	 * @param mixed $approved
	 * @param mixed $commentdata
	 * @return mixed
	 */
	public static function guard_new_comment( $approved, $commentdata = [] ) {
		if ( is_wp_error( $approved ) || ! is_array( $commentdata ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $approved;
		}
		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( $post_id <= 0 || self::allowed_note( (string) ( $commentdata['comment_type'] ?? 'comment' ), $post_id ) ) {
			return $approved;
		}
		return self::disabled_for_post( $post_id )
			? new \WP_Error( 'ys_admin_menu_comments_closed', __( '這個網站已關閉留言。', 'ys-admin-menu' ), 403 )
			: $approved;
	}

	/**
	 * wp_headers：全站關閉時不送 X-Pingback。
	 *
	 * @param mixed $headers
	 * @return mixed
	 */
	public static function filter_headers( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/**
	 * pre_ping：全站關閉時不通知文章裡連到的網站（清空這次要送的連結）。
	 *
	 * @param mixed $links
	 */
	public static function drop_pings( &$links ): void {
		$links = [];
	}

	/**
	 * xmlrpc_methods：全站關閉時拿掉新增留言與 Pingback 的方法（不保留舊留言時連查詢舊 Pingback 的方法也拿掉）。
	 *
	 * @param mixed $methods
	 * @return mixed
	 */
	public static function filter_xmlrpc( $methods ) {
		if ( is_array( $methods ) ) {
			unset( $methods['wp.newComment'], $methods['pingback.ping'] );
			if ( null !== self::$settings && ! self::$settings['keep_existing'] ) {
				unset( $methods['pingback.extensions.getPingbacks'] );
			}
		}
		return $methods;
	}

	/** widgets_init（20，核心在 1 註冊）：取消「近期留言」小工具。 */
	public static function unregister_widget(): void {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/**
	 * 區塊編輯器：「最新留言」區塊不能再插入（區塊註冊時就套用，不管腳本先後；已經放好的區塊照樣是那個區塊，
	 * 不保留舊留言時它查不到任何留言）。FSE 的留言區塊靠 comments_open 為 false 與空模板處理。
	 */
	public static function editor_assets(): void {
		wp_enqueue_script(
			'ys-admin-menu-no-latest-comments',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/js/ys-admin-menu-no-latest-comments.js',
			[ 'wp-hooks', 'wp-blocks' ],
			YS_ADMIN_MENU_VERSION,
			false
		);
	}

	/** admin_menu（PHP_INT_MAX）：拿掉「留言」選單與「設定 › 討論」。 */
	public static function admin_menu(): void {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/** admin_init：直接開留言管理頁回 403，並說明在哪裡打開。AJAX 的「回覆」由 guard_new_comment() 擋。 */
	public static function block_admin_pages(): void {
		global $pagenow;
		if ( wp_doing_ajax() || ! in_array( (string) $pagenow, self::BLOCKED_PAGES, true ) ) {
			return;
		}
		$where = YSAccess::can_manage()
			? '<a href="' . esc_url( admin_url( 'admin.php?page=ys-admin-menu-declutter' ) ) . '">' . esc_html__( 'YS 選單管理 › 後台精簡', 'ys-admin-menu' ) . '</a>'
			: esc_html__( 'YS 選單管理 › 後台精簡', 'ys-admin-menu' );
		wp_die(
			esc_html__( '這個網站已關閉留言。', 'ys-admin-menu' ) . ' ' . sprintf( /* translators: %s: settings page */ esc_html__( '要重新開啟，請到 %s。', 'ys-admin-menu' ), $where ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各段已 escape
			esc_html__( '留言已關閉', 'ys-admin-menu' ),
			[ 'response' => 403 ]
		);
	}

	/**
	 * admin_bar_menu（999）：拿掉工具列的留言圖示（多站「我的網站」底下這個站的「留言」也拿掉；其他站的設定各自決定）。
	 *
	 * @param mixed $bar
	 */
	public static function admin_bar( $bar ): void {
		if ( ! is_object( $bar ) || ! method_exists( $bar, 'remove_node' ) ) {
			return;
		}
		$bar->remove_node( 'comments' );
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			$bar->remove_node( 'blog-' . (int) get_current_blog_id() . '-c' );
		}
	}

	/** 控制台：「概覽」的留言數、「活動」的近期留言（含回覆表單）、歡迎面板的留言項目用 CSS 藏起來（核心沒有 hook 可以拿掉）。 */
	public static function dashboard_css(): void {
		echo '<style id="ys-admin-menu-no-comments">#dashboard_right_now .comment-count,#dashboard_right_now .comment-mod-count,#activity-widget #latest-comments,#welcome-panel .welcome-comments{display:none!important}</style>' . "\n";
	}
}
