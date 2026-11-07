<?php
/**
 * 自訂登入網址（v1.9.0）：登入頁換到「網站網址/代稱」，沒登入的人打開 wp-login.php、wp-admin 看到 404（或轉到指定的頁面）。
 * 參考 WPS Hide Login 的做法，程式自己寫。
 *
 * 設定 ys_admin_menu_login_url（自動載入）＝{ slug, redirect }。沒存過（大多數站）＝關：
 *   - 外掛主檔載入時呼叫 bootstrap()。關著時只看一眼已經載入的 alloptions（不查資料庫），一個 hook 都不掛，跟 1.8.0 完全一樣。
 *   - wp-config.php 把 YS_ADMIN_MENU_LOGIN_URL_DISABLED 定義成 true、多站網路、WPS Hide Login 也在作用、存的代稱不合法時一律不作用
 *     （登入回到 wp-login.php）。
 *   - 存檔時驗代稱：小寫英文、數字、連字號，3～50 個字；不能是 WordPress 保留的路徑或查詢變數，也不能跟現有的頁面、文章、
 *     文章類型或分類的網址一樣。每個請求還會再驗一次格式與保留字（用別的工具直接改設定時，不合法就當成關）。
 *
 * 作用中：
 *   - 新網址（/代稱/，結尾斜線照固定網址設定；沒用固定網址時是 /?代稱）載入 wp-login.php：登入、登出、忘記密碼、重設密碼、註冊、
 *     文章密碼、隱私請求確認、管理員信箱確認、後台逾時跳出的登入小視窗（interim-login），都照 WordPress 原本的流程。
 *     wp-login.php 的最上層變數照原樣宣告成全域；$pagenow＝wp-login.php、SCRIPT_NAME 換成新網址的路徑（is_login() 為真），
 *     本外掛的自訂登入頁、Turnstile、登入次數限制與其他外掛掛在登入頁的功能照常作用。
 *   - site_url、network_site_url、login_url、logout_url、lostpassword_url、register_url 與 wp_redirect 裡指向本站 wp-login.php 的網址
 *     改成新網址（查詢字串原樣保留），寄出的信（重設密碼、新帳號、隱私請求確認）也帶新網址。
 *     例外：文章密碼表單（action=postpass）留在 wp-login.php，前台的密碼表單才不會公開新網址；復原模式的信也留在 wp-login.php
 *     （核心在外掛載入之前就處理那個連結）。
 *   - 沒登入的人：wp-login.php、單站的 wp-signup.php 顯示佈景主題的 404（或轉到指定的頁面）；wp-admin 轉到 wp-login.php
 *     （或指定的頁面），不在後台的環境裡載入佈景主題（有些佈景主題在 is_admin() 時不載入前台的程式）。wp-activate.php 那裡
 *     WordPress 不載入外掛（WP_INSTALLING），它轉去的 wp-login.php 一樣是 404。已登入的人照常。
 *     不看：admin-ajax.php、admin-post.php、WP-Cron、WP-CLI、REST、XML-RPC。放行：wp-login.php 的文章密碼送出（POST、action=postpass）、
 *     復原模式中、`ys_admin_menu_login_url_allow_request` filter 回 true 的請求（例如落在 wp-login.php 的單一登入）。
 *   - 核心的 /login、/admin、/dashboard 捷徑（wp_redirect_admin_locations）改成只對已登入的人轉址：沒登入的人從那裡會被帶到新網址。
 *
 * 不在匯出檔裡（匯入也不會改它）：登入網址是每個站自己的入口，跟著設定檔搬到別的站或交給別人，等於把新網址交出去。
 *
 * @package YangSheep\AdminMenu\Login
 * @since   1.9.0
 */

namespace YangSheep\AdminMenu\Login;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSLoginUrl {

	/** wp_options key — { slug, redirect }（自動載入；不存在＝關）。 */
	public const OPTION = 'ys_admin_menu_login_url';

	/** wp-config.php 定義成 true 時整個功能不作用（登入回到 wp-login.php）。 */
	public const DISABLE_CONSTANT = 'YS_ADMIN_MENU_LOGIN_URL_DISABLED';

	/** 讓信任的流程進得了 wp-login.php（或 wp-admin）：回傳 true 才放行，第二個參數是這次請求的資訊。 */
	public const FILTER_ALLOW = 'ys_admin_menu_login_url_allow_request';

	public const SLUG_MIN     = 3;
	public const SLUG_MAX     = 50;
	public const REDIRECT_MAX = 200;

	/** 存檔被擋下時帶回設定頁的代碼。 */
	public const ERROR_CODES = [ 'login_url_format', 'login_url_reserved', 'login_url_exists', 'login_url_redirect' ];

	/** 代稱不能用的字：WordPress 自己的路徑與捷徑（查詢變數、wp- 開頭另外比）。 */
	private const RESERVED = [
		'wp', 'admin', 'login', 'logout', 'dashboard', 'register', 'signup', 'activate', 'feed', 'rss', 'rss2', 'rdf', 'atom',
		'comments', 'trackback', 'embed', 'page', 'search', 'author', 'category', 'tag', 'type', 'attachment', 'index',
		'xmlrpc', 'sitemap', 'cgi-bin',
	];

	/** 沒登入的人打開時不能轉去的路徑第一段（會繞回登入或後台）。 */
	private const REDIRECT_DENY = [ 'wp-login.php', 'wp-admin', 'wp-signup.php', 'wp-activate.php', 'login', 'admin', 'dashboard' ];

	/** 這個請求有沒有在作用（bootstrap() 決定）。 */
	private static bool $active = false;

	private static string $slug = '';

	/** 被擋下時要轉去的本站路徑（空白＝404）。 */
	private static string $redirect = '';

	/** 這個請求是：slug（新網址）、slug_alias（新網址的其他寫法：大小寫、結尾斜線）、空字串（其他）。 */
	private static string $kind = '';

	/** bootstrap() 時 vars.php 算出來的 $pagenow（本外掛改掉之前），用來判斷進來的是哪一支檔案。 */
	private static string $entry = '';

	/** 載入 wp-login.php 之前的 error_reporting（wp-login.php 會重跑 wp-load.php，把它改回預設）。 */
	private static int $error_level = 0;

	/* ─────────────────────────── 啟動 ─────────────────────────── */

	/**
	 * 外掛主檔載入時呼叫（在 WP::init()、wp_magic_quotes() 與 load_default_textdomain() 之前）。關著時什麼都不掛。
	 */
	public static function bootstrap(): void {
		self::$active   = false;
		self::$slug     = '';
		self::$redirect = '';
		self::$kind     = '';
		self::$entry    = '';

		$cfg = self::effective();
		if ( '' === $cfg['slug'] ) {
			return;
		}
		self::$active   = true;
		self::$slug     = $cfg['slug'];
		self::$redirect = $cfg['redirect'];
		self::register();
	}

	private static function register(): void {
		// 網址：任何環境都換（WP-CLI、WP-Cron、REST 寄出的信也要帶新網址）。晚一點跑，其他外掛改過的網址也接得到。
		add_filter( 'site_url', [ self::class, 'filter_url' ], 9999 );
		add_filter( 'network_site_url', [ self::class, 'filter_url' ], 9999 );
		foreach ( [ 'login_url', 'logout_url', 'lostpassword_url', 'register_url' ] as $hook ) {
			add_filter( $hook, [ self::class, 'filter_url' ], 9999 );
		}
		add_filter( 'wp_redirect', [ self::class, 'filter_url' ], 9999 );
		add_filter( 'recovery_mode_begin_url', [ self::class, 'filter_recovery_begin_url' ], 9999, 3 );
		// 不把新網址加進推測式載入（speculation rules）的排除清單：那份清單印在每個前台頁面的 <head>，等於公開新網址。

		// 核心的 /login、/admin 捷徑：沒登入的人不轉（轉了就把新網址告訴他）。
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
		add_action( 'template_redirect', [ self::class, 'redirect_admin_locations' ], 1000 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		self::$entry = isset( $GLOBALS['pagenow'] ) && is_string( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		self::$kind  = self::classify();
		if ( '' !== self::$kind ) {
			// 跟真的打開 wp-login.php 一樣：語言（wp_lang）、復原模式的保護、工具列、is_login() 都照登入頁判斷。
			$GLOBALS['pagenow'] = 'wp-login.php';
			if ( self::pretty() ) {
				$_SERVER['SCRIPT_NAME'] = self::login_path();
			}
		}
		// 最後才跑：其他外掛在 init、wp_loaded 對這個請求做的事（例如單一登入）先做完，跟真的打開 wp-login.php 一樣。
		add_action( 'wp_loaded', [ self::class, 'route' ], PHP_INT_MAX );
	}

	/* ─────────────────────────── 設定讀取 ─────────────────────────── */

	public static function disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && true === constant( self::DISABLE_CONSTANT );
	}

	/** WPS Hide Login 也在作用時不作用（兩個一起改登入網址會互相蓋）。 */
	public static function other_plugin_active(): bool {
		return defined( 'WPS_HIDE_LOGIN_BASENAME' ) || class_exists( '\WPS\WPS_Hide_Login\Plugin', false );
	}

	/**
	 * 已存的設定（沒清理過）。沒存過的站不為不存在的 option 查資料庫：這個 option 一律自動載入，不在 alloptions 裡＝沒存過。
	 *
	 * @return array{slug:string,redirect:string}
	 */
	public static function get(): array {
		$out = [
			'slug'     => '',
			'redirect' => '',
		];
		if ( self::shortcut() ) {
			return $out;
		}
		$raw = get_option( self::OPTION, [] );
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$out['slug']     = is_string( $raw['slug'] ?? null ) ? $raw['slug'] : '';
		$out['redirect'] = is_string( $raw['redirect'] ?? null ) ? $raw['redirect'] : '';
		return $out;
	}

	/**
	 * 可以不查資料庫、直接當成沒存過嗎（同 YSLoginGuardSettings::shortcut()）：有持久物件快取、有人掛了 pre_option／default_option
	 * filter 時照常 get_option()；這個鍵不在 alloptions 裡才走捷徑。被別的工具改成不自動載入時讀成「關」，打開設定頁或執行
	 * `wp ys-admin-menu login-url get` 會改回自動載入。
	 */
	private static function shortcut(): bool {
		if ( ! function_exists( 'wp_load_alloptions' ) || ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ) {
			return false;
		}
		if ( false !== has_filter( 'pre_option_' . self::OPTION ) || false !== has_filter( 'pre_option' ) || false !== has_filter( 'default_option_' . self::OPTION ) ) {
			return false;
		}
		$all = wp_load_alloptions();
		return is_array( $all ) && ! array_key_exists( self::OPTION, $all );
	}

	/**
	 * 狀態：active（作用中）、off（沒設定）、constant（wp-config 停用）、multisite、conflict（WPS Hide Login）、invalid（存的代稱不合法）。
	 *
	 * @return array{slug:string,redirect:string,state:string}
	 */
	public static function status(): array {
		$saved = self::get();
		if ( '' === $saved['slug'] ) {
			$state = 'off';
		} elseif ( self::disabled_by_constant() ) {
			$state = 'constant';
		} elseif ( is_multisite() ) {
			$state = 'multisite';
		} elseif ( self::other_plugin_active() ) {
			$state = 'conflict';
		} elseif ( '' !== self::slug_problem( $saved['slug'] ) ) {
			$state = 'invalid';
		} else {
			$state = 'active';
		}
		return [
			'slug'     => $saved['slug'],
			'redirect' => '' === self::redirect_problem( $saved['redirect'], $saved['slug'] ) ? $saved['redirect'] : '',
			'state'    => $state,
		];
	}

	/**
	 * 這個請求實際用的設定（不作用時 slug 是空字串）。
	 *
	 * @return array{slug:string,redirect:string}
	 */
	public static function effective(): array {
		$status = self::status();
		if ( 'active' !== $status['state'] ) {
			return [
				'slug'     => '',
				'redirect' => '',
			];
		}
		return [
			'slug'     => $status['slug'],
			'redirect' => $status['redirect'],
		];
	}

	/** 這個請求有沒有在作用。 */
	public static function is_active(): bool {
		return self::$active;
	}

	/* ─────────────────────────── 驗證 ─────────────────────────── */

	/**
	 * 設定頁、WP-CLI 收到的代稱：去掉前後空白與斜線、轉小寫（貼上整段網址的最後一段也接受）。
	 *
	 * @param mixed $value
	 */
	public static function normalize_slug_input( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( 1 === preg_match( '#^https?://#', $value ) ) {
			$path  = (string) wp_parse_url( $value, PHP_URL_PATH );
			$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
			$value = (string) end( $parts );
		}
		return trim( $value, "/ \t\n\r\0\x0B" );
	}

	/**
	 * 代稱的格式與保留字（不查資料庫，每個請求也會跑）。空字串＝可以用；否則 format、reserved。
	 */
	public static function slug_problem( string $slug ): string {
		if ( strlen( $slug ) < self::SLUG_MIN || strlen( $slug ) > self::SLUG_MAX || 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug ) ) {
			return 'format';
		}
		if ( 0 === strpos( $slug, 'wp-' ) || false !== strpos( $slug, 'wp-login' ) || false !== strpos( $slug, 'wp-admin' ) || in_array( $slug, self::RESERVED, true ) ) {
			return 'reserved';
		}
		if ( in_array( $slug, self::query_vars(), true ) ) {
			return 'reserved';
		}
		if ( function_exists( 'rest_get_url_prefix' ) && strtolower( (string) rest_get_url_prefix() ) === $slug ) {
			return 'reserved';
		}
		return '';
	}

	/**
	 * WordPress 的查詢變數（沒用固定網址時新網址是 /?代稱，跟查詢變數撞名會被當成查詢）。
	 *
	 * @return string[]
	 */
	private static function query_vars(): array {
		$vars = [];
		if ( class_exists( '\WP', false ) ) {
			$wp   = isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ? $GLOBALS['wp'] : new \WP();
			$vars = array_merge( (array) $wp->public_query_vars, (array) $wp->private_query_vars );
		}
		return array_values( array_filter( array_map( 'strval', $vars ) ) );
	}

	/**
	 * 跟站上現有的網址撞名（只在存檔時查）：頁面、文章、附件（任何公開的文章類型，最上層），文章類型與分類的網址前綴、彙整，
	 * 分類與標籤的網址前綴。空字串＝沒有；否則 exists。
	 */
	public static function conflict_problem( string $slug ): string {
		if ( function_exists( 'get_post_types' ) && function_exists( 'get_page_by_path' ) ) {
			$types = array_values( array_map( 'strval', (array) get_post_types( [ 'public' => true ] ) ) );
			if ( [] !== $types && null !== get_page_by_path( $slug, OBJECT, $types ) ) {
				return 'exists';
			}
		}
		$taken = [];
		if ( function_exists( 'get_post_types' ) ) {
			foreach ( (array) get_post_types( [], 'objects' ) as $type ) {
				if ( ! is_object( $type ) ) {
					continue;
				}
				if ( is_array( $type->rewrite ?? null ) && is_string( $type->rewrite['slug'] ?? null ) ) {
					$taken[] = $type->rewrite['slug'];
				}
				if ( is_string( $type->has_archive ?? null ) ) {
					$taken[] = $type->has_archive;
				}
			}
		}
		if ( function_exists( 'get_taxonomies' ) ) {
			foreach ( (array) get_taxonomies( [], 'objects' ) as $tax ) {
				if ( is_object( $tax ) && is_array( $tax->rewrite ?? null ) && is_string( $tax->rewrite['slug'] ?? null ) ) {
					$taken[] = $tax->rewrite['slug'];
				}
			}
		}
		$taken[] = (string) get_option( 'category_base', '' );
		$taken[] = (string) get_option( 'tag_base', '' );
		foreach ( $taken as $path ) {
			$parts = array_values( array_filter( explode( '/', strtolower( trim( (string) $path ) ) ), 'strlen' ) );
			if ( isset( $parts[0] ) && $parts[0] === $slug ) {
				return 'exists';
			}
		}
		return '';
	}

	/**
	 * @param mixed $value
	 */
	public static function normalize_redirect_input( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * 被擋下時轉去的本站路徑：空白＝404；要 / 開頭、200 字以內、沒有空白、引號、角括號、反斜線與控制字元，
	 * 不能是別的網站（//），也不能轉回登入、後台或新網址。空字串＝可以用；否則 redirect。
	 */
	public static function redirect_problem( string $path, string $slug ): string {
		if ( '' === $path ) {
			return '';
		}
		if ( strlen( $path ) > self::REDIRECT_MAX || '/' !== $path[0] || 0 === strpos( $path, '//' ) || 1 === preg_match( '/[\s\\\\<>"\'\x00-\x1f\x7f]/', $path ) ) {
			return 'redirect';
		}
		$lower = strtolower( $path );
		$first = (string) strtok( ltrim( $lower, '/' ), '/?#' );
		if ( false !== strpos( $lower, 'wp-login' ) || in_array( $first, self::REDIRECT_DENY, true ) || ( '' !== $slug && $first === $slug ) ) {
			return 'redirect';
		}
		return '';
	}

	/* ─────────────────────────── 網址 ─────────────────────────── */

	private static function pretty(): bool {
		return '' !== (string) get_option( 'permalink_structure', '' );
	}

	/** 網站網址（home）的路徑，沒有結尾斜線（裝在根目錄時是空字串）。不經過 home_url filter：多語外掛會在第二語言的頁面加語言代碼。 */
	private static function home_path(): string {
		$path = wp_parse_url( (string) get_option( 'home', '' ), PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	/**
	 * 新網址的路徑（用固定網址時），例如 /adminlogin/、/blog/adminlogin；固定網址是 /index.php/… 時加 /index.php。
	 */
	public static function login_path( string $slug = '' ): string {
		$slug      = '' !== $slug ? $slug : self::$slug;
		$structure = (string) get_option( 'permalink_structure', '' );
		$index     = 0 === strpos( $structure, '/index.php' ) ? '/index.php' : '';
		return self::home_path() . $index . '/' . $slug . ( '/' === substr( $structure, -1 ) ? '/' : '' );
	}

	/**
	 * 新網址。$scheme 同 set_url_scheme()（'relative' 只給路徑）；$slug 不給就用這個請求作用中的代稱。
	 */
	public static function login_url( ?string $scheme = 'login', string $slug = '' ): string {
		$slug = '' !== $slug ? $slug : self::$slug;
		if ( '' === $slug ) {
			return '';
		}
		if ( ! self::pretty() ) {
			$base = 'relative' === $scheme ? self::home_path() : set_url_scheme( untrailingslashit( (string) get_option( 'home', '' ) ), $scheme );
			return $base . '/?' . $slug;
		}
		if ( 'relative' === $scheme ) {
			return self::login_path( $slug );
		}
		return self::origin( $scheme ) . self::login_path( $slug );
	}

	/** 網站網址的 scheme://host[:port]。 */
	private static function origin( ?string $scheme ): string {
		$parts = wp_parse_url( set_url_scheme( untrailingslashit( (string) get_option( 'home', '' ) ), $scheme ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return ( $parts['scheme'] ?? 'http' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/** 原本的 wp-login.php（不經過本外掛的換網址）：復原模式的信、沒登入的人打開 wp-admin 時轉去那裡顯示 404。 */
	public static function raw_wp_login_url( ?string $scheme = 'login' ): string {
		return set_url_scheme( untrailingslashit( (string) get_option( 'siteurl', '' ) ), $scheme ) . '/wp-login.php';
	}

	/** 本站的主機名稱（home、siteurl），小寫。 */
	private static function own_hosts(): array {
		$hosts = [];
		foreach ( [ 'home', 'siteurl' ] as $key ) {
			$host = wp_parse_url( (string) get_option( $key, '' ), PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * 指向本站 wp-login.php 的網址換成新網址；其他網址原樣回傳。查詢字串與 #片段原樣接在後面（不解碼再編碼）。
	 * 認得：完整網址（本站的主機）、// 開頭、/ 開頭、相對路徑（wp-login.php?…，wp-login.php 自己轉址時用）；路徑最後一段要是 wp-login.php。
	 * 文章密碼（action=postpass）留在 wp-login.php。
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public static function filter_url( $url ) {
		if ( ! self::$active || ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$fragment = '';
		$hash     = strpos( $url, '#' );
		$rest     = $url;
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$rest     = substr( $url, 0, $hash );
		}
		$query    = '';
		$question = strpos( $rest, '?' );
		$base     = $rest;
		if ( false !== $question ) {
			$query = substr( $rest, $question + 1 );
			$base  = substr( $rest, 0, $question );
		}
		$slash = strrpos( $base, '/' );
		if ( 'wp-login.php' !== ( false === $slash ? $base : substr( $base, $slash + 1 ) ) ) {
			return $url;
		}

		if ( 1 === preg_match( '#^([a-z][a-z0-9+.-]*):#i', $base, $m ) ) {
			$scheme = strtolower( $m[1] );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return $url;
			}
			$host = wp_parse_url( $base, PHP_URL_HOST );
			if ( ! is_string( $host ) || ! in_array( strtolower( $host ), self::own_hosts(), true ) ) {
				return $url;
			}
			$target = self::login_url( $scheme );
		} elseif ( 0 === strpos( $base, '//' ) ) {
			$host = wp_parse_url( 'http:' . $base, PHP_URL_HOST );
			if ( ! is_string( $host ) || ! in_array( strtolower( $host ), self::own_hosts(), true ) ) {
				return $url;
			}
			$target = (string) preg_replace( '#^https?:#i', '', self::login_url( null ) );
		} elseif ( 0 === strpos( $base, '/' ) ) {
			$target = self::login_url( 'relative' );
		} else {
			$target = self::login_url( null );
		}

		if ( self::stays_on_wp_login( $query ) || '' === $target ) {
			return $url;
		}
		if ( '' !== $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}
		return $target . $fragment;
	}

	/** 文章密碼表單（action=postpass）：前台的密碼表單送到 wp-login.php，才不會在公開的頁面上出現新網址。 */
	private static function stays_on_wp_login( string $query ): bool {
		if ( '' === $query ) {
			return false;
		}
		parse_str( $query, $args );
		return isset( $args['action'] ) && 'postpass' === $args['action'] && ! isset( $args['key'] ) && ! isset( $args['checkemail'] );
	}

	/**
	 * 復原模式的信：核心在外掛載入之前（$pagenow 是 wp-login.php 時）處理這個連結，所以留在 wp-login.php。
	 * 進入復原模式之後，核心把人轉到 wp-login.php?action=entered_recovery_mode：復原模式中的請求放行（見 allowed()）。
	 *
	 * @param mixed  $url
	 * @param string $token
	 * @param string $key
	 * @return mixed
	 */
	public static function filter_recovery_begin_url( $url, $token = '', $key = '' ) {
		if ( ! self::$active || ! is_string( $token ) || ! is_string( $key ) || '' === $token || '' === $key ) {
			return $url;
		}
		$action = class_exists( '\WP_Recovery_Mode_Link_Service', false ) ? \WP_Recovery_Mode_Link_Service::LOGIN_ACTION_ENTER : 'enter_recovery_mode';
		return add_query_arg(
			[
				'action'   => $action,
				'rm_token' => $token,
				'rm_key'   => $key,
			],
			self::raw_wp_login_url()
		);
	}

	/** template_redirect 1000（取代核心的 wp_redirect_admin_locations）：只對已登入的人轉 /admin、/login 這些捷徑。 */
	public static function redirect_admin_locations(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( function_exists( 'wp_redirect_admin_locations' ) ) {
			wp_redirect_admin_locations();
		}
	}

	/* ─────────────────────────── 請求 ─────────────────────────── */

	/** 這個請求的路徑（解碼後）。 */
	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 只拿來比對路徑
		if ( '' === $uri ) {
			return '';
		}
		$path = 1 === preg_match( '#^https?://#i', $uri ) ? wp_parse_url( $uri, PHP_URL_PATH ) : wp_parse_url( 'http://placeholder' . ( '/' === $uri[0] ? '' : '/' ) . $uri, PHP_URL_PATH );
		return is_string( $path ) ? rawurldecode( $path ) : '';
	}

	/** 這個請求是不是新網址：slug、slug_alias（大小寫或結尾斜線不同）、空字串。 */
	private static function classify(): string {
		$path = self::request_path();
		if ( '' === $path ) {
			return '';
		}
		// 請求的路徑已經解碼，網站網址的路徑也解碼再比（裝在中文名稱的子目錄時）。
		if ( ! self::pretty() ) {
			$home = rawurldecode( self::home_path() );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只判斷是不是登入網址
			$flag = isset( $_GET[ self::$slug ] ) && '' === $_GET[ self::$slug ];
			return $flag && in_array( $path, [ $home, $home . '/', $home . '/index.php' ], true ) ? 'slug' : '';
		}
		$canonical = rawurldecode( self::login_path() );
		if ( $path === $canonical ) {
			return 'slug';
		}
		return strtolower( untrailingslashit( $path ) ) === untrailingslashit( $canonical ) ? 'slug_alias' : '';
	}

	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 只跟固定字串比
	}

	/**
	 * wp-login.php 實際會用的 action：跟 wp-login.php 一樣讀 $_REQUEST（POST 蓋過 GET），key、checkemail 會蓋過 action。
	 * 原字串，不清理、不 unslash（wp-login.php 也是拿這個值去比）。
	 */
	private static function login_action(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- 照 wp-login.php 原樣判斷
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
		if ( isset( $_GET['key'] ) ) {
			$action = 'resetpass';
		}
		if ( isset( $_GET['checkemail'] ) ) {
			$action = 'checkemail';
		}
		// phpcs:enable
		return (string) $action;
	}

	/**
	 * wp_loaded（最後）：新網址載入 wp-login.php；沒登入的人打開 wp-login.php、wp-signup.php、wp-activate.php、wp-admin 時擋下。
	 */
	public static function route(): void {
		if ( ! self::$active ) {
			return;
		}
		if ( 'slug_alias' === self::$kind && in_array( self::method(), [ 'GET', 'HEAD' ], true ) ) {
			self::redirect_to_canonical();
			return;
		}
		if ( 'slug' === self::$kind || 'slug_alias' === self::$kind ) {
			self::serve_login();
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}
		$where = self::blocked_entry();
		if ( '' === $where || self::allowed( $where ) ) {
			return;
		}
		self::block( $where );
	}

	/** wp-login、signup、admin，或空字串（不擋）。 */
	private static function blocked_entry(): string {
		if ( 'wp-login.php' === self::$entry ) {
			return 'wp-login';
		}
		if ( 'wp-signup.php' === self::$entry || 'wp-activate.php' === self::$entry ) {
			return 'signup';
		}
		if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && 'admin-post.php' !== self::$entry ) {
			return 'admin';
		}
		return '';
	}

	private static function allowed( string $where ): bool {
		$context = [
			'where'  => $where,
			'action' => 'wp-login' === $where ? self::login_action() : '',
			'method' => self::method(),
		];
		if ( 'wp-login' === $where ) {
			if ( function_exists( 'wp_is_recovery_mode' ) && wp_is_recovery_mode() ) {
				return true;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 文章密碼表單本來就沒有 nonce
			if ( 'POST' === $context['method'] && 'postpass' === $context['action'] && isset( $_POST['post_password'] ) && is_string( $_POST['post_password'] ) ) {
				return true;
			}
		}
		/**
		 * 讓信任的流程進得了 wp-login.php、wp-signup.php／wp-activate.php 或 wp-admin（沒登入的人）。回傳 true 才放行。
		 *
		 * @param bool  $allow   預設 false。
		 * @param array $context where（wp-login、signup、admin）、action（wp-login.php 實際會用的 action，原字串、來自請求）、method。
		 */
		return true === apply_filters( self::FILTER_ALLOW, false, $context );
	}

	private static function block( string $where ): void {
		// 轉址不能再經過本外掛換網址（會把 wp-login.php 換成新網址）。
		remove_filter( 'wp_redirect', [ self::class, 'filter_url' ], 9999 );
		if ( '' !== self::$redirect ) {
			wp_safe_redirect( home_url( self::$redirect ), 302 );
			exit;
		}
		if ( 'admin' === $where ) {
			// 本站的 wp-login.php（siteurl 的主機；跟 home 不同主機時 wp_safe_redirect 會改轉到 wp-admin，變成繞圈）。
			wp_redirect( self::raw_wp_login_url(), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 自己的網址
			exit;
		}
		self::render_404();
	}

	/** 佈景主題的 404（跟一般找不到的網址一樣），HEAD 只回狀態。 */
	private static function render_404(): void {
		$GLOBALS['pagenow'] = 'index.php';
		status_header( 404 );
		nocache_headers();
		if ( 'HEAD' === self::method() ) {
			exit;
		}
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
			$GLOBALS['wp_query']->set_404();
		}
		$template = get_404_template();
		if ( '' === $template ) {
			$template = get_index_template();
		}
		if ( '' !== $template && is_file( $template ) ) {
			include $template;
			exit;
		}
		wp_die( esc_html__( '找不到這個頁面。', 'ys-admin-menu' ), '', [ 'response' => 404 ] );
	}

	/** 新網址的其他寫法（大小寫、結尾斜線）轉到正式的寫法，查詢字串照帶（重設密碼的 cookie 綁在網址的路徑上，寫法要一致）。 */
	private static function redirect_to_canonical(): void {
		$query = isset( $_SERVER['QUERY_STRING'] ) && is_string( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wp_safe_redirect 會清理
		wp_safe_redirect( self::login_url( null ) . ( '' !== $query ? '?' . $query : '' ), 302 );
		exit;
	}

	/** 載入 wp-login.php（跟真的打開它一樣），然後結束這個請求。 */
	private static function serve_login(): void {
		self::$error_level = error_reporting();
		add_action( 'login_init', [ self::class, 'restore_error_level' ], PHP_INT_MIN );
		self::require_login_screen();
		exit;
	}

	/**
	 * 在函式裡 require wp-login.php：它最上層的變數在真的打開 wp-login.php 時都是全域變數，這裡先宣告成全域，
	 * login_header()、login_footer() 與其他外掛的 hook 才讀得到（清單照 WordPress 7.1 的 wp-login.php；舊版用到的是其中一部分）。
	 */
	private static function require_login_screen(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride -- 跟真的打開 wp-login.php 一樣，這些本來就是全域變數
		global $error, $interim_login, $action, $user_login, $errors, $default_actions, $secure, $http_post, $login_link_separator,
			$redirect_to, $requested_redirect_to, $user, $user_name, $user_email, $secure_cookie, $customize_login, $reauth,
			$rp_cookie, $rp_path, $rp_login, $rp_key, $value, $key, $result, $message, $request_id, $hasher, $expire,
			$lostpassword_redirect, $registration_redirect, $registration_url, $remind_me_link, $remind_interval, $admin_email,
			$admin_email_lifespan, $admin_email_check_interval, $admin_email_help_url, $accessibility_text, $change_link,
			$html_link, $query, $query_component, $rememberme, $rememberme_help_text, $aria_describedby, $has_errors,
			$login_script, $url;
		require ABSPATH . 'wp-login.php';
	}

	/** login_init（最前面）：wp-login.php 重跑 wp-load.php 時改掉的 error_reporting 改回來（WP_DEBUG 的站）。 */
	public static function restore_error_level(): void {
		error_reporting( self::$error_level );
	}

	/* ─────────────────────────── 設定頁 ─────────────────────────── */

	/**
	 * 儲存（由 YSWhiteLabelAdmin::handle_save_settings 在驗過權限與 nonce 之後呼叫）。表單沒有這張卡片的欄位就不動。
	 * 不合法的欄位不存、保留原本的值，回傳錯誤代碼。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 * @return string[]
	 */
	public static function save_settings( array $post ): array {
		if ( ! YSAccess::can_manage() || ! array_key_exists( 'login_url_slug', $post ) ) {
			return [];
		}
		$current  = self::get();
		$errors   = [];
		$slug     = self::normalize_slug_input( $post['login_url_slug'] );
		$redirect = 'redirect' === ( $post['login_url_blocked'] ?? '' ) ? self::normalize_redirect_input( $post['login_url_redirect'] ?? '' ) : '';

		if ( '' !== $slug ) {
			$problem = self::slug_problem( $slug );
			if ( '' === $problem && $slug !== $current['slug'] ) {
				$problem = self::conflict_problem( $slug );
			}
			if ( '' !== $problem ) {
				$errors[] = 'login_url_' . $problem;
				$slug     = $current['slug'];
			}
		}
		if ( '' !== self::redirect_problem( $redirect, $slug ) ) {
			$errors[] = 'login_url_redirect';
			$redirect = '' === self::redirect_problem( $current['redirect'], $slug ) ? $current['redirect'] : '';
		}
		self::store( $slug, $redirect );
		return $errors;
	}

	/** 存起來（空白代稱＝刪掉，回到 wp-login.php）。 */
	public static function store( string $slug, string $redirect ): void {
		if ( '' === $slug ) {
			delete_option( self::OPTION );
			return;
		}
		update_option(
			self::OPTION,
			[
				'slug'     => $slug,
				'redirect' => $redirect,
			],
			true
		);
	}

	/**
	 * 設定頁需要的值（打開設定頁時順便把被改成不自動載入的設定改回來）。
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_view(): array {
		$healed = YSAccess::heal_autoload( self::OPTION );
		$status = self::status();
		$active = 'active' === $status['state'];
		return [
			'slug'     => $status['slug'],
			'redirect' => $status['redirect'],
			'state'    => $status['state'],
			'url'      => '' !== $status['slug'] && '' === self::slug_problem( $status['slug'] ) ? self::login_url( 'login', $status['slug'] ) : '',
			'prefix'   => self::url_prefix(),
			'conflict' => $active ? self::conflict_problem( $status['slug'] ) : '',
			'healed'   => $healed,
			'constant' => self::DISABLE_CONSTANT,
		];
	}

	/** 設定頁欄位前面那一段（新網址去掉代稱），例如 https://example.com/、https://example.com/index.php/、https://example.com/?。 */
	public static function url_prefix(): string {
		if ( ! self::pretty() ) {
			return set_url_scheme( untrailingslashit( (string) get_option( 'home', '' ) ), 'login' ) . '/?';
		}
		$structure = (string) get_option( 'permalink_structure', '' );
		return self::origin( 'login' ) . self::home_path() . ( 0 === strpos( $structure, '/index.php' ) ? '/index.php' : '' ) . '/';
	}
}
