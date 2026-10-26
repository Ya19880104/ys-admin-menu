<?php
/**
 * v1.7.0：關閉留言（設計 docs/plans/2026-10-26-ys-admin-menu-170-design.md §4；對標 Disable Comments 的做法與踩過的坑）。
 *
 * 選項 ys_admin_menu_comments（autoload yes）：
 *   { version: 1, mode: 'off' | 'everywhere' | 'post_types', post_types: string[], keep_existing: bool }
 *   - off（預設）：一個 hook 都不掛。
 *   - everywhere：全站關閉，連後台「留言」選單、工具列、控制台的留言數、留言 RSS、Pingback、XML-RPC 新增留言一起關。
 *   - post_types：只關指定的文章類型（後台選單照舊，因為其他類型還有留言）。
 *   - keep_existing：舊留言照樣顯示，只是不能再留；false＝舊留言也不顯示。
 *
 * 這是功能開關，不是權限：對所有人生效（含管理員），跟選單的「隱藏」（管理員不受影響）不同。
 * 區塊編輯器的「備註」（留言類型 note，WordPress 6.9 起）照樣可以用：REST 新增備註不經過 comments_open，這裡的 REST 檢查也放行。
 *
 * @package YangSheep\AdminMenu\Comments
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Comments;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSCommentsDisabler {

	public const OPTION = 'ys_admin_menu_comments';

	/** 關閉留言時照樣放行的留言類型（區塊編輯器的備註）。 */
	public const ALLOWED_TYPES = [ 'note' ];

	/** 全站關閉時，直接開這兩頁回 403（「設定 › 討論」只拿掉選單：那一頁還有大頭貼設定，網址照樣能進）。 */
	public const BLOCKED_PAGES = [ 'edit-comments.php', 'comment.php' ];

	/** @var array{version:int,mode:string,post_types:string[],keep_existing:bool}|null register() 讀到的設定（這個請求內不變）。 */
	private static ?array $settings = null;

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
	 * 已存的設定。沒存過（不在 alloptions 裡）＝預設 off，不多查資料庫（存檔一律 autoload）。
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
	 * 存檔（後台精簡頁）。指定類型模式只收「現在有註冊、而且支援留言」的類型；已存但現在沒註冊的類型保留
	 * （外掛暫時停用時，打開設定頁按儲存不會把它吃掉）。
	 *
	 * @param array<string, mixed> $raw 已 wp_unslash 的表單值（mode、post_types、keep_existing）。
	 */
	public static function save( array $raw ): void {
		$cfg       = self::sanitize( $raw );
		$supported = function_exists( 'get_post_types_by_support' ) ? (array) get_post_types_by_support( 'comments' ) : [];
		$stored    = self::settings()['post_types'];
		$cfg['post_types'] = array_values(
			array_filter(
				$cfg['post_types'],
				static fn( string $type ): bool => in_array( $type, $supported, true ) || ( ! post_type_exists( $type ) && in_array( $type, $stored, true ) )
			)
		);
		update_option( self::OPTION, $cfg, true );
	}

	/** 這個請求要不要動手（register() 之後才有值）。 */
	public static function active(): bool {
		return null !== self::$settings && 'off' !== self::$settings['mode'];
	}

	public static function register(): void {
		$s = self::settings();
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
		}
		add_action( 'template_redirect', [ self::class, 'front' ], 9 );
		add_filter( 'rest_pre_insert_comment', [ self::class, 'rest_guard' ], 20, 2 );

		if ( 'everywhere' !== $s['mode'] ) {
			return;
		}
		add_filter( 'wp_headers', [ self::class, 'filter_headers' ] );
		add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
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
			if ( ! self::disabled_for( (string) $type ) ) {
				continue;
			}
			if ( post_type_supports( $type, 'trackbacks' ) ) {
				remove_post_type_support( $type, 'trackbacks' );
			}
			if ( ! self::$settings['keep_existing'] && post_type_supports( $type, 'comments' ) ) {
				remove_post_type_support( $type, 'comments' );
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
	 * template_redirect（9）：留言 RSS 轉回文章或首頁（301）；單篇關閉時換成空的留言模板、不載入回覆留言的 script。
	 */
	public static function front(): void {
		$object = get_queried_object();
		$single = is_singular() && $object instanceof \WP_Post;
		if ( is_comment_feed() ) {
			if ( $single && self::disabled_for_post( $object ) ) {
				wp_safe_redirect( (string) get_permalink( $object ), 301 );
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

	/**
	 * rest_pre_insert_comment（20）：REST 新增留言的第二道檢查（第一道是核心的 comments_open）。備註（note）放行。
	 *
	 * @param mixed $prepared
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rest_guard( $prepared, $request = null ) {
		if ( ! is_array( $prepared ) ) {
			return $prepared;
		}
		$type = (string) ( $prepared['comment_type'] ?? 'comment' );
		if ( in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return $prepared;
		}
		if ( self::disabled_for_post( (int) ( $prepared['comment_post_ID'] ?? 0 ) ) ) {
			return new \WP_Error( 'ys_admin_menu_comments_closed', __( '這個網站已關閉留言。', 'ys-admin-menu' ), [ 'status' => 403 ] );
		}
		return $prepared;
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
	 * xmlrpc_methods：全站關閉時拿掉 wp.newComment。
	 *
	 * @param mixed $methods
	 * @return mixed
	 */
	public static function filter_xmlrpc( $methods ) {
		if ( is_array( $methods ) ) {
			unset( $methods['wp.newComment'] );
		}
		return $methods;
	}

	/** widgets_init（20，核心在 1 註冊）：取消「近期留言」小工具。 */
	public static function unregister_widget(): void {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/** 區塊編輯器：取消「最新留言」區塊（FSE 的留言區塊靠 comments_open 為 false 與空模板處理）。 */
	public static function editor_assets(): void {
		wp_enqueue_script(
			'ys-admin-menu-no-latest-comments',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/js/ys-admin-menu-no-latest-comments.js',
			[ 'wp-blocks', 'wp-dom-ready' ],
			YS_ADMIN_MENU_VERSION,
			true
		);
	}

	/** admin_menu（PHP_INT_MAX）：拿掉「留言」選單與「設定 › 討論」。 */
	public static function admin_menu(): void {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/** admin_init：直接開留言管理頁回 403，並說明在哪裡打開。AJAX 不經過這裡（核心的留言 AJAX 另外問 comments_open）。 */
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
	 * admin_bar_menu（999）：拿掉工具列的留言圖示。
	 *
	 * @param mixed $bar
	 */
	public static function admin_bar( $bar ): void {
		if ( is_object( $bar ) && method_exists( $bar, 'remove_node' ) ) {
			$bar->remove_node( 'comments' );
		}
	}

	/** 控制台：「概覽」的留言數、「活動」的近期留言、歡迎面板的留言項目用 CSS 藏起來（核心沒有 hook 可以拿掉）。 */
	public static function dashboard_css(): void {
		echo '<style id="ys-admin-menu-no-comments">#dashboard_right_now .comment-count,#dashboard_right_now .comment-mod-count,#activity-widget #latest-comments,#welcome-panel .welcome-comments{display:none!important}</style>' . "\n";
	}
}
