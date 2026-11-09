<?php
/**
 * v1.10.0：關閉 WordPress 7.2 的隨機文章網址（/random/、?random）。
 *
 * WordPress 7.2 新增「隨機內容轉址」：打開 /random/、/random/category/news/ 這類網址，或在首頁、彙整、搜尋的網址加上
 * ?random，會轉到那個範圍裡隨機的一篇文章。核心預設開啟，關掉的方法只有 filter `wp_enable_random_content_redirect`
 * 回 false：不註冊 /random/ 的網址規則、「設定 › 永久連結」不顯示隨機網址前綴、網址上的 random 參數不再轉址。
 *
 * 選項 ys_admin_menu_random_content（autoload yes）：{ version: 1, mode: 'disable' | 'core' }
 *   - disable（預設；沒存過也是）：在 filter 回 false，關閉隨機文章網址。
 *   - core：照 WordPress 預設開放。但偵測到其他程式也在用 random 這個參數（自己加的查詢變數、網址端點、網址規則或
 *     改寫標籤）時，仍然回 false，免得核心把那些網址轉走（核心在 template_redirect 之前就處理 random，其他程式來不及接手）。
 *
 * 跟其他程式並存：同一個 filter 有別的外掛、佈景主題或 mu-plugin 也在設定時，不互相蓋。本外掛的 callback 掛在最早的
 * 優先順序（PHP_INT_MIN），只決定起始值；其他 callback 照樣在後面執行，結果以它們為準。設定頁列出這些 callback 與
 * 實際結果，管理員看得出為什麼開關沒有作用。
 *
 * 網址規則：核心在產生網址規則時才讀這個 filter，結果改變後要重建規則。只在一般的後台頁面（admin_init，不含 AJAX、
 * WP-CLI）與「後台精簡」頁存檔時比對「上次重建時的結果」（ys_admin_menu_random_rules_state，autoload yes）與現在的結果，
 * 不同才 soft flush 一次並記下來。前台、AJAX、WP-CLI 不重建：那些請求可能少載入外掛或佈景主題（例如 --skip-plugins、
 * 外掛卸載工具），重建出來的規則會少掉它們的網址。規則還沒重建前，/random/ 只會顯示文章列表、不會轉址（轉不轉址是
 * filter 在每個請求即時決定的）。沒有這個功能的 WordPress（7.1 以前）、沒用永久連結的站，什麼都不做、不寫資料庫。
 * 停用本外掛時先拿掉 filter；記錄不是 on（關掉過、或還沒打開過後台頁所以沒有記錄）的話把 rewrite_rules 清空，
 * 讓下一個請求照核心的預設重建。
 *
 * @package YangSheep\AdminMenu\Content
 * @since   1.10.0
 */

namespace YangSheep\AdminMenu\Content;

defined( 'ABSPATH' ) || exit;

final class YSRandomContent {

	/** 設定（mode）。 */
	public const OPTION = 'ys_admin_menu_random_content';

	/** 上次重建網址規則時的結果：on（開放）／off（關閉）。內部用。 */
	public const RULES_STATE = 'ys_admin_menu_random_rules_state';

	/** WordPress 7.2 的開關 filter。 */
	public const FILTER = 'wp_enable_random_content_redirect';

	/** 核心用的網址參數。 */
	public const QUERY_VAR = 'random';

	/** 本外掛在 filter 上的優先順序：最早執行，只決定起始值。 */
	public const PRIORITY = PHP_INT_MIN;

	/** @var array{version:int,mode:string}|null 這個請求讀到的設定。 */
	private static ?array $settings = null;

	/** @var array<int,string>|null 這個請求偵測到的參數衝突（wp_loaded 之後才記住）。 */
	private static ?array $conflicts = null;

	/** 偵測衝突時會跑其他程式的 query_vars callback；它們再問這個 filter 時不重入。 */
	private static bool $detecting = false;

	/**
	 * @return array{version:int,mode:string}
	 */
	public static function defaults(): array {
		return [
			'version' => 1,
			'mode'    => 'disable',
		];
	}

	/**
	 * @param mixed $raw
	 * @return array{version:int,mode:string}
	 */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'version' => 1,
			'mode'    => 'core' === ( $raw['mode'] ?? '' ) ? 'core' : 'disable',
		];
	}

	/**
	 * 已存的設定。沒存過（不在 alloptions 裡）＝預設 disable，不多查資料庫。
	 * 被別的工具改成不自動載入時也當成預設；打開「後台精簡」頁會改回自動載入並重讀（YSDeclutterAdmin::render()）。
	 *
	 * @return array{version:int,mode:string}
	 */
	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		if ( function_exists( 'wp_load_alloptions' ) && ! array_key_exists( self::OPTION, (array) wp_load_alloptions() ) ) {
			self::$settings = self::defaults();
		} else {
			self::$settings = self::sanitize( get_option( self::OPTION, [] ) );
		}
		return self::$settings;
	}

	/** 清掉這個請求記住的設定與偵測結果（存檔後、設定頁修好自動載入後、測試用）。 */
	public static function reset_request_state(): void {
		self::$settings  = null;
		self::$conflicts = null;
		self::$detecting = false;
	}

	/**
	 * 主檔載入時就掛（比任何人讀這個 filter 都早）。只掛一個 filter 與一個 admin_init，設定等真的被問到時才讀。
	 */
	public static function register(): void {
		self::reset_request_state();
		add_filter( self::FILTER, [ self::class, 'filter_enabled' ], self::PRIORITY );
		add_action( 'admin_init', [ self::class, 'maybe_sync_rewrite_rules' ], 99 );
	}

	/**
	 * filter：本外掛決定的起始值。關閉模式一律 false；照 WordPress 模式在有參數衝突時 false，否則原值不動。
	 *
	 * @param mixed $enabled
	 * @return mixed
	 */
	public static function filter_enabled( $enabled ) {
		if ( 'disable' === self::settings()['mode'] ) {
			return false;
		}
		if ( [] !== self::conflicts() ) {
			return false;
		}
		return $enabled;
	}

	/** 這個站的 WordPress 有沒有隨機文章網址（7.2 起）。 */
	public static function supported(): bool {
		return function_exists( 'wp_is_random_content_redirect_enabled' );
	}

	/** 現在的實際結果（所有 callback 跑完）；WordPress 沒有這個功能時是 null。 */
	public static function enabled_now(): ?bool {
		return self::supported() ? (bool) wp_is_random_content_redirect_enabled() : null;
	}

	/**
	 * 其他程式也在用 random 這個參數：
	 *   - query_var：有 query_vars callback 把 random 加進查詢變數（核心 7.2 自己是寫在 WP::$public_query_vars，不經過這個 filter）；
	 *   - rewrite_endpoint：add_rewrite_endpoint() 註冊了查詢變數是 random 的網址端點（例如 /category/x/random/）；
	 *   - rewrite_tag：有人註冊了 %random% 改寫標籤，或改寫標籤對到 random=；
	 *   - rewrite_rule：add_rewrite_rule() 加的規則把網址對到 random（random=… 或只有 random）。
	 * 只影響「照 WordPress」模式。wp_loaded 之後才記住結果（init 還在跑時，其他外掛的規則可能還沒註冊完）。
	 *
	 * @return array<int,string>
	 */
	public static function conflicts(): array {
		if ( null !== self::$conflicts ) {
			return self::$conflicts;
		}
		if ( self::$detecting ) {
			return [];
		}
		self::$detecting = true;
		$found           = [];
		try {
			$vars = apply_filters( 'query_vars', [] );
			if ( is_array( $vars ) && in_array( self::QUERY_VAR, $vars, true ) ) {
				$found[] = 'query_var';
			}
			$rewrite = $GLOBALS['wp_rewrite'] ?? null;
			if ( is_object( $rewrite ) ) {
				foreach ( (array) ( $rewrite->endpoints ?? [] ) as $endpoint ) {
					if ( is_array( $endpoint ) && self::QUERY_VAR === ( $endpoint[2] ?? null ) ) {
						$found[] = 'rewrite_endpoint';
						break;
					}
				}
				if ( in_array( '%' . self::QUERY_VAR . '%', (array) ( $rewrite->rewritecode ?? [] ), true )
					|| in_array( self::QUERY_VAR . '=', (array) ( $rewrite->queryreplace ?? [] ), true ) ) {
					$found[] = 'rewrite_tag';
				}
				$extra = array_merge( (array) ( $rewrite->extra_rules_top ?? [] ), (array) ( $rewrite->extra_rules ?? [] ) );
				foreach ( $extra as $query ) {
					if ( is_string( $query ) && 1 === preg_match( '/(?:^|[?&])' . self::QUERY_VAR . '(?:=|&|$)/', $query ) ) {
						$found[] = 'rewrite_rule';
						break;
					}
				}
			}
		} finally {
			self::$detecting = false;
		}
		if ( function_exists( 'did_action' ) && did_action( 'wp_loaded' ) ) {
			self::$conflicts = $found;
		}
		return $found;
	}

	/** admin_init：一般後台頁面才比對與重建（AJAX、WP-CLI 不做）。 */
	public static function maybe_sync_rewrite_rules(): void {
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::sync_rewrite_rules();
	}

	/**
	 * 實際結果跟上次重建網址規則時不同，就重建一次（soft flush）並記下來。
	 * 沒有記錄、而且結果跟核心預設一樣（開放）時只記下來、不重建：規則本來就是照核心預設產生的。
	 * 記錄被別的工具改成不自動載入時照樣讀得到，並改回自動載入（不會因為讀不到而每次都重建）。
	 */
	public static function sync_rewrite_rules(): void {
		if ( ! self::supported() ) {
			return;
		}
		$rewrite = $GLOBALS['wp_rewrite'] ?? null;
		if ( ! is_object( $rewrite ) || ! method_exists( $rewrite, 'using_permalinks' ) || ! $rewrite->using_permalinks() ) {
			return;
		}
		$now = wp_is_random_content_redirect_enabled() ? 'on' : 'off';
		$all = function_exists( 'wp_load_alloptions' ) ? (array) wp_load_alloptions() : [];
		if ( array_key_exists( self::RULES_STATE, $all ) ) {
			$prev = (string) $all[ self::RULES_STATE ];
		} else {
			$prev = (string) get_option( self::RULES_STATE, '' );
			if ( '' !== $prev && $prev === $now && function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( self::RULES_STATE, true );
			}
		}
		if ( $prev === $now ) {
			return;
		}
		// 先記下來再重建：同時進來的請求看到新值就不會再重建一次。
		update_option( self::RULES_STATE, $now, true );
		if ( '' === $prev && 'on' === $now ) {
			return;
		}
		flush_rewrite_rules( false );
	}

	/**
	 * 「後台精簡」頁存檔（admin-post）。存完立刻比對一次，結果變了就重建網址規則。
	 *
	 * @param array<string,mixed> $raw 已 wp_unslash 的表單值（disable：勾選＝關閉隨機文章網址）。
	 */
	public static function save( array $raw ): void {
		$cfg = self::sanitize( [ 'mode' => empty( $raw['disable'] ) ? 'core' : 'disable' ] );
		update_option( self::OPTION, $cfg, true );
		self::reset_request_state();
		self::maybe_sync_rewrite_rules();
	}

	/**
	 * 停用本外掛：先拿掉 filter（同一個請求裡其他外掛的停用動作若重建規則，就照核心預設產生），再逐站處理：
	 * 記錄不是 on 的站（關掉過，或更新後還沒打開過後台頁、規則可能已經照關閉產生）把 rewrite_rules 清空
	 * （下一個請求照核心預設重建、保留原本的自動載入），刪記錄。
	 *
	 * @param mixed $network_wide
	 */
	public static function deactivate( $network_wide = false ): void {
		remove_filter( self::FILTER, [ self::class, 'filter_enabled' ], self::PRIORITY );
		if ( function_exists( 'is_multisite' ) && is_multisite() && $network_wide && function_exists( 'get_sites' ) ) {
			$args = [ 'fields' => 'ids', 'number' => 0 ];
			if ( function_exists( 'get_current_network_id' ) ) {
				$args['network_id'] = get_current_network_id();
			}
			foreach ( get_sites( $args ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::reset_site_rules();
				restore_current_blog();
			}
			return;
		}
		self::reset_site_rules();
	}

	private static function reset_site_rules(): void {
		$state = get_option( self::RULES_STATE, '' );
		delete_option( self::RULES_STATE );
		if ( 'on' !== $state && self::supported() && ! empty( get_option( 'rewrite_rules' ) ) ) {
			update_option( 'rewrite_rules', '' );
		}
	}

	/**
	 * 設定頁：同一個 filter 上其他程式的 callback（不含本外掛）。
	 *
	 * @return array<int,array{priority:int,label:string}>
	 */
	public static function other_callbacks(): array {
		$hook = $GLOBALS['wp_filter'][ self::FILTER ] ?? null;
		if ( ! is_object( $hook ) || ! is_array( $hook->callbacks ?? null ) ) {
			return [];
		}
		$out = [];
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$fn = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
				if ( is_array( $fn ) && self::class === ( is_object( $fn[0] ?? null ) ? get_class( $fn[0] ) : ( $fn[0] ?? '' ) ) && 'filter_enabled' === ( $fn[1] ?? '' ) ) {
					continue;
				}
				$out[] = [
					'priority' => (int) $priority,
					'label'    => self::describe_callback( $fn ),
				];
			}
		}
		return $out;
	}

	/**
	 * callback 的名稱與定義位置（例如 my_func（plugins/foo/foo.php:12））。核心的 __return_true／__return_false 看不出是誰掛的。
	 *
	 * @param mixed $fn
	 */
	public static function describe_callback( $fn ): string {
		if ( is_string( $fn ) && in_array( $fn, [ '__return_true', '__return_false' ], true ) ) {
			return $fn . '（WordPress 內建函式，看不出是哪個外掛、佈景主題或 mu-plugin 掛的）';
		}
		try {
			if ( is_string( $fn ) && str_contains( $fn, '::' ) ) {
				[ $class, $method ] = explode( '::', $fn, 2 );
				$reflection         = new \ReflectionMethod( $class, $method );
				$name               = $fn;
			} elseif ( is_string( $fn ) ) {
				$reflection = new \ReflectionFunction( $fn );
				$name       = $fn;
			} elseif ( is_array( $fn ) && 2 === count( $fn ) ) {
				$class      = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				$reflection = new \ReflectionMethod( $class, (string) $fn[1] );
				$name       = $class . '::' . (string) $fn[1];
			} elseif ( $fn instanceof \Closure ) {
				$reflection = new \ReflectionFunction( $fn );
				$name       = '匿名函式';
			} elseif ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $fn, '__invoke' );
				$name       = get_class( $fn );
			} else {
				return '（無法辨識的 callback）';
			}
			$file = $reflection->getFileName();
			if ( is_string( $file ) && '' !== $file ) {
				$name .= '（' . self::short_path( $file ) . ':' . (int) $reflection->getStartLine() . '）';
			}
			return $name;
		} catch ( \Throwable $e ) {
			return '（無法辨識的 callback）';
		}
	}

	/** 檔案路徑去掉 wp-content／網站根目錄前綴。 */
	private static function short_path( string $file ): string {
		$file  = str_replace( '\\', '/', $file );
		$bases = [];
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$bases[] = (string) WP_CONTENT_DIR;
		}
		if ( defined( 'ABSPATH' ) ) {
			$bases[] = (string) ABSPATH;
		}
		foreach ( $bases as $base ) {
			$base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
			if ( str_starts_with( $file, $base ) ) {
				return substr( $file, strlen( $base ) );
			}
		}
		return basename( $file );
	}

	/** 隨機網址的前綴（核心 7.2 的「隨機網址前綴」設定；沒有這個功能時用 random）。 */
	public static function random_base(): string {
		$rewrite = $GLOBALS['wp_rewrite'] ?? null;
		if ( is_object( $rewrite ) && method_exists( $rewrite, 'get_random_base' ) ) {
			$base = (string) $rewrite->get_random_base();
			if ( '' !== $base ) {
				return $base;
			}
		}
		return self::QUERY_VAR;
	}

	/**
	 * 前綴上的頁面或文章（照核心 WP_Rewrite::random_rewrite_rules() 的規則：公開可看的頁面；文章網址是 /%postname%/ 時，
	 * 同名的文章也算）。有的話 /random/ 本身顯示那一篇，不轉址。
	 */
	private static function base_post_title( string $base ): string {
		if ( ! function_exists( 'get_page_by_path' ) ) {
			return '';
		}
		$rewrite    = $GLOBALS['wp_rewrite'] ?? null;
		$candidates = [ get_page_by_path( $base ) ];
		if ( is_object( $rewrite ) && method_exists( $rewrite, 'post_permalinks_share_random_base' ) && $rewrite->post_permalinks_share_random_base() ) {
			$candidates[] = get_page_by_path( $base, OBJECT, 'post' );
		}
		foreach ( $candidates as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}
			$viewable = function_exists( 'is_post_publicly_viewable' ) ? is_post_publicly_viewable( $post ) : 'publish' === ( $post->post_status ?? '' );
			if ( $viewable ) {
				return (string) ( $post->post_title ?? $base );
			}
		}
		return '';
	}

	/**
	 * 設定頁顯示用的狀態。
	 *
	 * @return array{supported:bool,wp_version:string,mode:string,enabled:?bool,others:array<int,array{priority:int,label:string}>,conflicts:array<int,string>,base:string,example:string,permalinks:bool,base_title:string}
	 */
	public static function status(): array {
		$rewrite    = $GLOBALS['wp_rewrite'] ?? null;
		$permalinks = is_object( $rewrite ) && method_exists( $rewrite, 'using_permalinks' ) && $rewrite->using_permalinks();
		$base       = self::random_base();
		$enabled    = self::enabled_now();
		return [
			'supported'  => self::supported(),
			'wp_version' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'mode'       => self::settings()['mode'],
			'enabled'    => $enabled,
			'others'     => self::other_callbacks(),
			'conflicts'  => self::conflicts(),
			'base'       => $base,
			'example'    => $permalinks ? home_url( '/' . $base . '/' ) : home_url( '/?' . self::QUERY_VAR . '=1' ),
			'permalinks' => $permalinks,
			'base_title' => true === $enabled && $permalinks ? self::base_post_title( $base ) : '',
		];
	}

	/** 衝突代碼的說明。 */
	public static function conflict_label( string $code ): string {
		$labels = [
			'query_var'        => '有外掛或佈景主題把 random 加成自己的網址參數（query_vars）',
			'rewrite_endpoint' => '有外掛或佈景主題註冊了查詢變數是 random 的網址端點（add_rewrite_endpoint）',
			'rewrite_tag'      => '有外掛或佈景主題註冊了對到 random 的改寫標籤（%random% 或 random=）',
			'rewrite_rule'     => '有外掛或佈景主題加了把網址對到 random 參數的網址規則（add_rewrite_rule）',
		];
		return $labels[ $code ] ?? $code;
	}
}
