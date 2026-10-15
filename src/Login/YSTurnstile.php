<?php
/**
 * Cloudflare Turnstile — wp-login.php 的登入／註冊／忘記密碼防護（v1.4.0）。
 *
 * 生效條件：勾選啟用，而且 Site Key 與 Secret Key 都有填。少一個就不顯示 widget、也不在後端驗證，
 * 避免只填一半就把所有人（包括管理員）擋在外面。
 *
 * 前端：在表單的送出鈕上方放 Turnstile widget（implicit render，Cloudflare 會在表單裡加上
 *       cf-turnstile-response 欄位）。wp_login_form() 產生、送到 wp-login.php 的前台登入表單也會放。
 *
 * 後端：
 *   - 登入：掛 authenticate，priority 5，早於核心 priority 20 的帳密檢查。
 *     驗證沒過時回 WP_Error，並把這一次請求的核心帳密檢查拿掉、最後（PHP_INT_MAX）再強制回同一個錯誤，
 *     等整條 authenticate 跑完、真正的 wp_login_failed 才還原（還在鏈中間時，其他外掛自己觸發的
 *     wp_login_failed 或巢狀 wp_authenticate() 不會讓它提早還原）。
 *     原因：核心 priority 20 的 wp_authenticate_username_password 會忽略前面傳來的 WP_Error，
 *     自己再查一次帳號與密碼，只回 WP_Error 根本擋不住。拿掉之後也不會去算密碼雜湊，
 *     回應內容與時間都不會因為帳號存不存在而不同（不形成帳號列舉的管道）。
 *   - 註冊（registration_errors）、忘記密碼（lostpassword_errors）：驗證沒過時只回本外掛這一個錯誤，
 *     不帶「帳號已存在」「沒有這個帳號」之類的訊息。
 *   - siteverify 一定要跑、結果一定要判：Cloudflare 回 HTTP 200 且 success === true 才算通過。
 *     Cloudflare 有回應且判定失敗時擋下（token 無效、用過、逾時、Secret 錯、internal-error 重試後仍失敗），
 *     兩種模式都一樣。連不到時依模式：寬鬆（預設）＝放行並記錄；嚴格＝擋下並記錄。
 *     「連不到」再分暫時斷線（連線失敗、逾時、HTTP 5xx，重試一次）與站台設定擋住連線
 *     （WP_HTTP_BLOCK_EXTERNAL、http_request_not_executed、其他外掛在 pre_http_request 攔下，不重試），
 *     後者代表 Turnstile 實際上沒有作用，設定頁顯示、後台常駐提醒管理員。
 *   - 格式不對的 token（超過 2048 字元或含控制字元）不送 Cloudflare，直接判失敗：
 *     攻擊者沒辦法用奇怪的 token 讓伺服器端變成「連不到」而被放行。
 *   - 同一個 PHP 請求只驗一次、不分表單（結果在這個請求內沿用，不跨請求快取；每個請求開始時清空，
 *     常駐 worker 也不會沿用前一個請求的結果）：每一次送出都重新呼叫 siteverify，token 一次性、
 *     Cloudflare 會擋重放。
 *   - 只管 wp-login.php 的 POST 送出（login_init 有跑過的請求）。社群登入／SSO 在 wp-login.php 的 GET 回呼、
 *     XML-RPC、REST、WooCommerce 等自己的登入表單不在範圍內。
 *   - 訪客 IP 只用 REMOTE_ADDR，不信任 X-Forwarded-For；站台在反向代理後面時可用
 *     ys_admin_menu_turnstile_remote_ip filter 自行提供。
 *   - 預期網域比對預設關閉；用 ys_admin_menu_turnstile_expected_hostnames filter 回傳網域清單才比對 hostname。
 *
 * Secret Key 不輸出到前端、不寫進記錄、不放進匯出檔。
 * 緊急關閉：wp-config.php 加 define( 'YS_ADMIN_MENU_TURNSTILE_DISABLED', true );
 *
 * @package YangSheep\AdminMenu\Login
 * @since   1.4.0
 */

namespace YangSheep\AdminMenu\Login;

defined( 'ABSPATH' ) || exit;

final class YSTurnstile {

	/** wp_options key — 是否啟用（yes／no，預設 no）。 */
	public const OPTION_ENABLED = 'ys_admin_menu_turnstile_enabled';

	/** wp_options key — Site Key（公開）。 */
	public const OPTION_SITE_KEY = 'ys_admin_menu_turnstile_site_key';

	/** wp_options key — Secret Key（不自動載入、不回填、不匯出）。 */
	public const OPTION_SECRET = 'ys_admin_menu_turnstile_secret_key';

	/** wp_options key — 套用範圍（login／register／lostpassword，預設只有 login）。 */
	public const OPTION_SCOPE = 'ys_admin_menu_turnstile_scope';

	/** wp_options key — 連不到 Cloudflare 時的處理（lenient＝放行並記錄（預設）／strict＝擋下）。 */
	public const OPTION_MODE = 'ys_admin_menu_turnstile_mode';

	/** wp_options key — 最近的事件（連不到 Cloudflare、Secret 錯誤），最多 20 筆，不自動載入。 */
	public const OPTION_LOG = 'ys_admin_menu_turnstile_log';

	/**
	 * wp_options key — 驗證健康狀態：最後一次 siteverify 成功的時間、最後一次「站台設定擋住連線」的時間與代碼。
	 * 後台常駐通知每頁都會讀，所以自動載入。
	 */
	public const OPTION_HEALTH = 'ys_admin_menu_turnstile_health';

	public const MODE_LENIENT = 'lenient';
	public const MODE_STRICT  = 'strict';

	/** 可套用的表單。 */
	public const SCOPES = [ 'login', 'register', 'lostpassword' ];

	/** 緊急關閉用的常數（寫在 wp-config.php）。 */
	public const DISABLE_CONSTANT = 'YS_ADMIN_MENU_TURNSTILE_DISABLED';

	/** 設定頁「測試設定」用的 admin-ajax action（也是 nonce action）。 */
	public const AJAX_TEST_ACTION = 'ys_admin_menu_turnstile_test';

	/** 驗證沒過時回給使用者的錯誤代碼。 */
	public const ERROR_CODE = 'ys_admin_menu_turnstile_failed';

	/** authenticate 的優先序：要早於核心 priority 20 的帳密檢查。 */
	public const AUTH_PRIORITY = 5;

	private const SCRIPT_HANDLE  = 'ys-admin-menu-turnstile';
	private const SCRIPT_URL     = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	private const VERIFY_URL     = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	private const RESPONSE_FIELD = 'cf-turnstile-response';
	private const TIMEOUT        = 5;
	private const MAX_ATTEMPTS   = 2;
	private const KEY_PATTERN    = '/^[A-Za-z0-9_\-]{10,100}$/';
	private const TOKEN_MAX      = 2048;
	private const LOG_MAX        = 20;

	/** 同一個原因的事件，多久內只寫一次紀錄與 error_log（秒）。 */
	private const LOG_THROTTLE = 60;

	/** 驗證成功時，多久才更新一次「最後成功時間」（秒；避免每次登入都寫資料庫）。 */
	private const HEALTH_OK_INTERVAL = 300;

	/** 核心 priority 20 的帳密檢查（驗證沒過時這一次請求暫時拿掉）。 */
	private const CORE_PASSWORD_FILTERS = [ 'wp_authenticate_username_password', 'wp_authenticate_email_password' ];

	/** 這一次登入請求的驗證失敗（PHP_INT_MAX 的 enforce_login_failure 會回傳它）。 */
	private static ?\WP_Error $login_failure = null;

	/** @var string[] 暫時拿掉的核心 filter，wp_login_failed 時放回。 */
	private static array $removed_filters = [];

	/**
	 * 這一次請求的驗證結果（null＝還沒驗）。每個請求一個，不分表單。
	 *
	 * 只在同一個請求內沿用、不跨請求快取：同一個請求的 token 是固定的，其他外掛巢狀呼叫
	 * wp_authenticate()、或註冊後由外掛自動 wp_signon() 時，不會再送一次 siteverify（Cloudflare 會把
	 * 第二次當成重放而擋下正常使用者），而沒過的結果也會沿用、不會在第二次被「放行」。
	 * 每個請求開始時（login_init → boot_login_screen）一律清空：常駐 worker（FrankenPHP worker、
	 * RoadRunner、Swoole）裡 static 會跨請求保留，不清空的話前一個請求的「通過」會被沿用。
	 */
	private static ?bool $request_verdict = null;

	/**
	 * 註冊 hook。前台、後台、wp-login.php 都會呼叫；讀設定一律延後到真的用得到的時候。
	 */
	public static function register(): void {
		if ( is_admin() ) {
			add_action( 'wp_ajax_' . self::AJAX_TEST_ACTION, [ self::class, 'ajax_test' ] );
			// 站台設定擋住了對 Cloudflare 的連線時（Turnstile 實際上沒有作用），在後台常駐提醒管理員。
			add_action( 'admin_notices', [ self::class, 'print_config_problem_notice' ] );
		}

		// wp-login.php：login_init 在 wp_signon 之前，掛在這裡就來得及。其他請求完全不讀 Turnstile 設定。
		add_action( 'login_init', [ self::class, 'boot_login_screen' ], 1 );

		// 前台 wp_login_form()：表單送到 wp-login.php，一樣會被後端驗證，所以也要放 widget。
		add_filter( 'login_form_middle', [ self::class, 'filter_login_form_middle' ], 10, 2 );
	}

	/**
	 * wp-login.php 開始時：先把這個請求的狀態歸零，生效才掛表單 widget 與後端驗證。
	 */
	public static function boot_login_screen(): void {
		self::reset_request_state();

		if ( ! self::is_active() ) {
			return;
		}

		$scope = self::get_scope();

		if ( in_array( 'login', $scope, true ) ) {
			add_action( 'login_form', [ self::class, 'render_login_widget' ] );
			add_filter( 'authenticate', [ self::class, 'authenticate' ], self::AUTH_PRIORITY, 3 );
			add_filter( 'shake_error_codes', [ self::class, 'add_shake_code' ] );
		}
		if ( in_array( 'register', $scope, true ) ) {
			add_action( 'register_form', [ self::class, 'render_register_widget' ] );
			add_filter( 'registration_errors', [ self::class, 'registration_errors' ], 99, 3 );
		}
		if ( in_array( 'lostpassword', $scope, true ) ) {
			add_action( 'lostpassword_form', [ self::class, 'render_lostpassword_widget' ] );
			add_filter( 'lostpassword_errors', [ self::class, 'lostpassword_errors' ], 99, 2 );
		}

		if ( ! empty( $scope ) ) {
			add_action( 'login_head', [ self::class, 'print_default_login_fit_css' ] );
		}
	}

	/**
	 * 每個請求開始時清空本類別的請求內狀態（login_init 每個請求只觸發一次）。
	 *
	 * 一般 PHP 每個請求本來就是新的行程，這裡什麼都不會做；常駐 worker 裡 static 與 hook 會跨請求保留，
	 * 所以清掉前一個請求的驗證結果與失敗狀態。前一個請求若留下被暫停的核心帳密檢查（例如沒有觸發
	 * wp_login_failed 就結束），先把它放回去，再清空紀錄。
	 */
	private static function reset_request_state(): void {
		foreach ( self::$removed_filters as $callback ) {
			if ( false === has_filter( 'authenticate', $callback ) ) {
				add_filter( 'authenticate', $callback, 20, 3 );
			}
		}
		remove_filter( 'authenticate', [ self::class, 'enforce_login_failure' ], PHP_INT_MAX );
		remove_action( 'wp_login_failed', [ self::class, 'restore_password_check' ], 0 );

		self::$removed_filters = [];
		self::$login_failure   = null;
		self::$request_verdict = null;
	}

	/* ─────────────────────────── 設定讀取 ─────────────────────────── */

	public static function is_enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	public static function get_site_key(): string {
		return self::clean_key( get_option( self::OPTION_SITE_KEY, '' ) );
	}

	/**
	 * Secret Key 只在伺服器端讀，不提供 public getter。
	 */
	private static function get_secret(): string {
		return self::clean_key( get_option( self::OPTION_SECRET, '' ) );
	}

	public static function has_secret(): bool {
		return '' !== self::get_secret();
	}

	/**
	 * @return string[] 生效的套用範圍（依 SCOPES 的順序）。
	 */
	public static function get_scope(): array {
		$raw = get_option( self::OPTION_SCOPE, [ 'login' ] );
		if ( ! is_array( $raw ) ) {
			return [ 'login' ];
		}
		return array_values( array_intersect( self::SCOPES, array_filter( $raw, 'is_string' ) ) );
	}

	public static function get_mode(): string {
		return self::MODE_STRICT === get_option( self::OPTION_MODE, self::MODE_LENIENT ) ? self::MODE_STRICT : self::MODE_LENIENT;
	}

	public static function is_disabled_by_constant(): bool {
		return defined( self::DISABLE_CONSTANT ) && (bool) constant( self::DISABLE_CONSTANT );
	}

	/**
	 * 是否真的生效：啟用＋Site Key＋Secret Key 都有，而且沒有被 wp-config.php 的常數暫停。
	 */
	public static function is_active(): bool {
		return ! self::is_disabled_by_constant()
			&& self::is_enabled()
			&& '' !== self::get_site_key()
			&& self::has_secret();
	}

	public static function is_valid_key_format( string $value ): bool {
		return 1 === preg_match( self::KEY_PATTERN, $value );
	}

	/**
	 * @param mixed $value
	 */
	private static function clean_key( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return self::is_valid_key_format( $value ) ? $value : '';
	}

	/**
	 * 設定頁顯示用的狀態：是否生效、實際上有沒有作用、原因、最後一次驗證成功的時間。
	 *
	 * active    設定上已生效（開關、兩把 Key、套用範圍都有）。
	 * effective 實際上有作用：active，而且站台設定沒有擋住對 Cloudflare 的連線。
	 *           連線被擋時，寬鬆模式每次都會「連不到 → 放行」，等於只靠帳號密碼；嚴格模式則所有人都登不進來。
	 *
	 * @return array{active:bool,effective:bool,reasons:string[],config_problem:string,last_ok:int}
	 */
	public static function status(): array {
		$reasons = [];
		if ( self::is_disabled_by_constant() ) {
			$reasons[] = __( 'wp-config.php 定義了 YS_ADMIN_MENU_TURNSTILE_DISABLED，Turnstile 暫停中。', 'ys-admin-menu' );
		}
		if ( ! self::is_enabled() ) {
			$reasons[] = __( '還沒有勾選「啟用 Turnstile」。', 'ys-admin-menu' );
		}
		if ( '' === self::get_site_key() ) {
			$reasons[] = __( '還沒有填 Site Key。', 'ys-admin-menu' );
		}
		if ( ! self::has_secret() ) {
			$reasons[] = __( '還沒有填 Secret Key。', 'ys-admin-menu' );
		}
		if ( self::is_active() && empty( self::get_scope() ) ) {
			$reasons[] = __( '沒有勾選任何套用範圍，所以哪個表單都不會驗證。', 'ys-admin-menu' );
		}

		$active  = self::is_active() && ! empty( self::get_scope() );
		$problem = self::config_problem();
		if ( $active && '' !== $problem ) {
			$reasons[] = $problem;
		}

		$health = self::get_health();
		return [
			'active'         => $active,
			'effective'      => $active && '' === $problem,
			'reasons'        => $reasons,
			'config_problem' => $problem,
			'last_ok'        => $health['last_ok'],
		];
	}

	/**
	 * 站台設定是不是擋住了對 Cloudflare 的連線（永久性的設定問題，不是暫時斷線）。
	 *
	 * 兩種來源：
	 *   - 現在就看得出來的：WP_HTTP_BLOCK_EXTERNAL 開著、WP_ACCESSIBLE_HOSTS 沒放行 challenges.cloudflare.com。
	 *   - 驗證時記下來的：被 WordPress 拒絕送出（http_request_not_executed），或被其他外掛在 pre_http_request
	 *     攔下來回錯誤；而且之後還沒有成功過。
	 *
	 * @return string 給管理員看的說明；空字串＝沒有問題。
	 */
	public static function config_problem(): string {
		if ( self::is_blocked_by_wp_config() ) {
			return __( '目前實際沒有作用：wp-config.php 的 WP_HTTP_BLOCK_EXTERNAL 擋住了對外連線，而 WP_ACCESSIBLE_HOSTS 沒有放行 challenges.cloudflare.com，伺服器無法向 Cloudflare 驗證。', 'ys-admin-menu' );
		}
		$health = self::get_health();
		if ( $health['config_active'] ) {
			return sprintf(
				/* translators: %s: error code. */
				__( '目前實際沒有作用：最近一次驗證時，對 Cloudflare 的連線被站台設定或其他外掛攔下來（%s），伺服器無法向 Cloudflare 驗證。請檢查防火牆、對外連線限制或攔截 HTTP 請求的外掛。', 'ys-admin-menu' ),
				'' !== $health['config_code'] ? $health['config_code'] : 'blocked'
			);
		}
		return '';
	}

	/**
	 * WP_HTTP_BLOCK_EXTERNAL 有沒有擋住 siteverify（WordPress 自己的判斷）。
	 */
	private static function is_blocked_by_wp_config(): bool {
		if ( ! function_exists( '_wp_http_get_object' ) ) {
			return false;
		}
		$http = _wp_http_get_object();
		return is_object( $http ) && method_exists( $http, 'block_request' ) && (bool) $http->block_request( self::VERIFY_URL );
	}

	/**
	 * @return array{last_ok:int,last_config_error:int,config_code:string,config_active:bool}
	 */
	private static function get_health(): array {
		$raw  = get_option( self::OPTION_HEALTH, [] );
		$raw  = is_array( $raw ) ? $raw : [];
		$code = isset( $raw['config_code'] ) && is_string( $raw['config_code'] ) && 1 === preg_match( '/^[a-z0-9_\-]{1,60}$/', $raw['config_code'] ) ? $raw['config_code'] : '';
		return [
			'last_ok'           => (int) ( $raw['last_ok'] ?? 0 ),
			'last_config_error' => (int) ( $raw['last_config_error'] ?? 0 ),
			'config_code'       => $code,
			// 「站台設定擋住連線」之後還沒有成功過。
			'config_active'     => ! empty( $raw['config_active'] ),
		];
	}

	/**
	 * 記下驗證成功：清掉「設定擋住」（立刻寫，提醒馬上消失）；否則最多每 5 分鐘更新一次最後成功時間。
	 */
	private static function note_success(): void {
		$health = self::get_health();
		$now    = time();
		if ( ! $health['config_active'] && $now - $health['last_ok'] < self::HEALTH_OK_INTERVAL ) {
			return;
		}
		$health['last_ok']       = $now;
		$health['config_active'] = false;
		update_option( self::OPTION_HEALTH, $health, true );
	}

	/**
	 * 記下「站台設定擋住了連線」（已經記著、而且是一分鐘內記的就不再寫）。
	 */
	private static function note_config_error( string $code ): void {
		$health = self::get_health();
		$now    = time();
		if ( $health['config_active'] && $now - $health['last_config_error'] < self::LOG_THROTTLE ) {
			return;
		}
		$health['last_config_error'] = $now;
		$health['config_code']       = 1 === preg_match( '/^[a-z0-9_\-]{1,60}$/', $code ) ? $code : 'blocked';
		$health['config_active']     = true;
		update_option( self::OPTION_HEALTH, $health, true );
	}

	/**
	 * admin_notices：Turnstile 已啟用但實際上沒有作用（站台設定擋住了連線）時，常駐提醒管理員。
	 */
	public static function print_config_problem_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// 先看便宜的：設定常數與（自動載入的）健康狀態。沒有問題就結束，不去讀不自動載入的 Secret，
		// 每個後台頁不會因此多一次資料庫查詢。
		$problem = self::config_problem();
		if ( '' === $problem ) {
			return;
		}
		if ( ! self::is_active() || empty( self::get_scope() ) ) {
			return;
		}
		$url  = admin_url( 'admin.php?page=ys-admin-menu-whitelabel&tab=turnstile' );
		$mode = self::MODE_STRICT === self::get_mode()
			? __( '目前是嚴格模式：所有人（包括管理員）都登不進來。', 'ys-admin-menu' )
			: __( '目前是寬鬆模式：登入會被放行，只靠帳號密碼保護。', 'ys-admin-menu' );
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s %3$s <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Cloudflare Turnstile：', 'ys-admin-menu' ),
			esc_html( $problem ),
			esc_html( $mode ),
			esc_url( $url ),
			esc_html__( '前往登入防護設定', 'ys-admin-menu' )
		);
	}

	/* ─────────────────────────── 前端 widget ─────────────────────────── */

	public static function render_login_widget(): void {
		echo self::widget_html( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget_html 內已逐欄 escape
	}

	public static function render_register_widget(): void {
		echo self::widget_html( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget_html 內已逐欄 escape
	}

	public static function render_lostpassword_widget(): void {
		echo self::widget_html( 'lostpassword' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget_html 內已逐欄 escape
	}

	/**
	 * 前台 wp_login_form()：表單一樣送到 wp-login.php，要帶 token 才登得進去。
	 *
	 * @param string               $content 既有的 login_form_middle 內容。
	 * @param array<string, mixed> $args    wp_login_form() 參數。
	 * @return string
	 */
	public static function filter_login_form_middle( $content, $args = [] ) {
		$content = is_string( $content ) ? $content : '';
		if ( ! self::is_active() || ! in_array( 'login', self::get_scope(), true ) ) {
			return $content;
		}
		return $content . self::widget_html( 'login' );
	}

	private static function widget_html( string $action ): string {
		self::enqueue_script();

		// 自訂登入頁是深藍底，用深色 widget；其他地方跟著訪客的系統設定。
		$theme = ( did_action( 'login_init' ) && YSLoginPage::is_enabled() ) ? 'dark' : 'auto';

		return sprintf(
			'<div class="ys-am-turnstile"><div class="cf-turnstile" data-sitekey="%1$s" data-action="%2$s" data-theme="%3$s" data-size="flexible"></div></div>',
			esc_attr( self::get_site_key() ),
			esc_attr( $action ),
			esc_attr( $theme )
		);
	}

	/**
	 * 載入 Cloudflare 的 api.js（版本參數給 null：Cloudflare 要求原樣載入，不加 ?ver）。
	 */
	public static function enqueue_script(): void {
		if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			self::SCRIPT_URL,
			[],
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Cloudflare 要求原樣載入
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
	}

	/**
	 * WordPress 預設登入頁的表單內寬只有 270px，Turnstile 至少 300px：只有沒啟用自訂登入頁時才補這一行。
	 */
	public static function print_default_login_fit_css(): void {
		if ( YSLoginPage::is_enabled() ) {
			return;
		}
		echo '<style id="ys-admin-menu-turnstile-fit">.login form .ys-am-turnstile{margin:0 -15px 16px;}</style>' . "\n";
	}

	/* ─────────────────────────── 後端驗證 ─────────────────────────── */

	/**
	 * authenticate（priority 5）：wp-login.php 送出帳號或密碼時先驗 Turnstile。
	 *
	 * @param null|\WP_User|\WP_Error $user
	 * @param string                  $username
	 * @param string                  $password
	 * @return null|\WP_User|\WP_Error
	 */
	public static function authenticate(
		$user,
		$username = '',
		#[\SensitiveParameter]
		$password = ''
	) {
		if ( ! self::is_login_submission( $username, $password ) ) {
			return $user;
		}

		// 同一個請求只驗一次：其他外掛巢狀呼叫 wp_authenticate() 時沿用同一個結果。
		if ( self::request_verdict( 'login' ) ) {
			return $user;
		}

		$error = self::failure_error();
		self::stop_password_check( $error );
		return $error;
	}

	/**
	 * 這一次請求的驗證結果：第一次（不論哪個表單）才真的向 Cloudflare 驗證，之後同一個請求一律沿用。
	 * 事件紀錄的「表單」記第一次驗證時的那一個。
	 */
	private static function request_verdict( string $context ): bool {
		if ( null === self::$request_verdict ) {
			self::$request_verdict = self::decide( self::verify_request(), $context );
		}
		return self::$request_verdict;
	}

	/**
	 * 只管 wp-login.php 真的送出帳號或密碼的請求（POST）。
	 *
	 * - 打開登入頁（GET）時 wp-login.php 也會用空帳密呼叫 wp_signon()，那種不驗。
	 * - 只接受 POST：社群登入／SSO 外掛會在 wp-login.php 的 GET 回呼（例如 ?loginSocial=）裡自己帶帳號登入，
	 *   那不是登入表單送出，不該被要求 Turnstile。WordPress 的登入表單只從 POST 讀帳密。
	 *
	 * @param mixed $username
	 * @param mixed $password
	 */
	private static function is_login_submission(
		$username,
		#[\SensitiveParameter]
		$password
	): bool {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 只和固定字串比較
			return false;
		}
		if ( ! did_action( 'login_init' ) ) {
			return false;
		}
		$has_user = is_string( $username ) && '' !== trim( $username );
		$has_pass = is_string( $password ) && '' !== $password;
		return $has_user || $has_pass;
	}

	/**
	 * 驗證沒過：這一次請求不再跑核心的帳密檢查，最後一律回同一個錯誤。
	 */
	private static function stop_password_check( \WP_Error $error ): void {
		self::$login_failure = $error;

		foreach ( self::CORE_PASSWORD_FILTERS as $callback ) {
			if ( 20 === has_filter( 'authenticate', $callback ) ) {
				remove_filter( 'authenticate', $callback, 20 );
				self::$removed_filters[] = $callback;
			}
		}

		add_filter( 'authenticate', [ self::class, 'enforce_login_failure' ], PHP_INT_MAX );
		add_action( 'wp_login_failed', [ self::class, 'restore_password_check' ], 0 );
	}

	/**
	 * authenticate 的最後一關：本次請求 Turnstile 沒過，就算中間有外掛回了 WP_User 也一律擋下。
	 *
	 * @param null|\WP_User|\WP_Error $user
	 * @return null|\WP_User|\WP_Error
	 */
	public static function enforce_login_failure( $user ) {
		return self::$login_failure instanceof \WP_Error ? self::$login_failure : $user;
	}

	/**
	 * wp_login_failed（authenticate 跑完之後）：把暫時拿掉的核心 filter 放回去。
	 *
	 * 還在 authenticate 鏈中間就不還原：其他外掛自己觸發 wp_login_failed、或巢狀呼叫 wp_authenticate()
	 * （巢狀那次結束時也會觸發 wp_login_failed）時，若在這裡還原，外層鏈接下來的核心 priority 20
	 * 又會查一次密碼、PHP_INT_MAX 的強制關卡也不見了，不帶 token 加正確密碼就能登入。
	 * 這時保持原狀，等整條 authenticate 跑完、真正的 wp_login_failed 再還原。
	 */
	public static function restore_password_check(): void {
		if ( doing_filter( 'authenticate' ) ) {
			return;
		}
		remove_action( 'wp_login_failed', [ self::class, 'restore_password_check' ], 0 );
		remove_filter( 'authenticate', [ self::class, 'enforce_login_failure' ], PHP_INT_MAX );
		foreach ( self::$removed_filters as $callback ) {
			add_filter( 'authenticate', $callback, 20, 3 );
		}
		self::$removed_filters = [];
		self::$login_failure   = null;
	}

	/**
	 * registration_errors（priority 99）：驗證沒過只回本外掛的錯誤，不帶帳號／信箱是否已存在。
	 *
	 * @param \WP_Error $errors
	 * @param string    $sanitized_user_login
	 * @param string    $user_email
	 * @return \WP_Error
	 */
	public static function registration_errors( $errors, $sanitized_user_login = '', $user_email = '' ) {
		if ( ! did_action( 'login_init' ) ) {
			return $errors;
		}
		return self::request_verdict( 'register' ) ? $errors : self::failure_error();
	}

	/**
	 * lostpassword_errors（priority 99）：驗證沒過只回本外掛的錯誤，不帶「沒有這個帳號」。
	 *
	 * @param \WP_Error      $errors
	 * @param \WP_User|false $user_data
	 * @return \WP_Error
	 */
	public static function lostpassword_errors( $errors, $user_data = false ) {
		if ( ! did_action( 'login_init' ) ) {
			return $errors;
		}
		return self::request_verdict( 'lostpassword' ) ? $errors : self::failure_error();
	}

	/**
	 * @param mixed $codes
	 * @return mixed
	 */
	public static function add_shake_code( $codes ) {
		$codes   = is_array( $codes ) ? $codes : [];
		$codes[] = self::ERROR_CODE;
		return $codes;
	}

	/**
	 * 讀這次 POST 的 token 並驗證。
	 *
	 * @return array{status:string,codes:string[]}
	 */
	private static function verify_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Turnstile token 本身就是一次性的驗證憑證。
		$raw   = $_POST[ self::RESPONSE_FIELD ] ?? '';
		$token = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
		return self::verify_token( $token, self::remote_ip() );
	}

	/**
	 * 向 Cloudflare siteverify 驗證一個 token。
	 *
	 * 回傳 status：
	 *   pass        Cloudflare 回 HTTP 200 且 success === true（有設定預期網域時，hostname 也要在清單裡）
	 *   fail        Cloudflare 有回應但判定失敗（含 internal-error 重試後仍失敗）、回應格式不對、HTTP 4xx、
	 *               hostname 不符，或 token 格式在本機就不對 —— 兩種模式都擋下
	 *   unreachable 連不到 Cloudflare，kind 再分兩種（都依模式：寬鬆放行並記錄、嚴格擋下）：
	 *               transient 暫時性：連線失敗、逾時、HTTP 5xx —— 用同一個 idempotency_key 重試一次
	 *               config    站台設定擋住了連線：WP_HTTP_BLOCK_EXTERNAL 沒放行、http_request_not_executed、
	 *                         其他外掛在 pre_http_request 攔下並回錯誤 —— 不重試；記下來，設定頁與後台提醒
	 *                         「目前實際沒有作用」
	 *
	 * 空 token 一樣送 Cloudflare：Cloudflare 連得到時會回失敗（擋下）；整個 Cloudflare 停擺、
	 * 瀏覽器也載不到 widget 時才會走到 unreachable，寬鬆模式才放得進來。
	 *
	 * @return array{status:string,codes:string[],kind?:string,hostname?:string}
	 */
	public static function verify_token( string $token, string $remote_ip = '' ): array {
		if ( strlen( $token ) > self::TOKEN_MAX || ( '' !== $token && 1 !== preg_match( '/^[\x21-\x7E]+$/', $token ) ) ) {
			return [
				'status' => 'fail',
				'codes'  => [ 'invalid-input-response' ],
			];
		}

		$secret = self::get_secret();
		if ( '' === $secret ) {
			return [
				'status' => 'fail',
				'codes'  => [ 'missing-input-secret' ],
			];
		}

		// WordPress 自己就會拒絕送出（WP_HTTP_BLOCK_EXTERNAL 沒放行 Cloudflare）：設定問題，不用試。
		if ( self::is_blocked_by_wp_config() ) {
			return [
				'status' => 'unreachable',
				'kind'   => 'config',
				'codes'  => [ 'wp-http-block-external' ],
			];
		}

		$body = [
			'secret'          => $secret,
			'response'        => $token,
			// 重試時帶同一個 idempotency_key，Cloudflare 才不會把同一個 token 的重試當成重放。
			'idempotency_key' => wp_generate_uuid4(),
		];
		if ( '' !== $remote_ip ) {
			$body['remoteip'] = $remote_ip;
		}

		$result = [
			'status' => 'unreachable',
			'kind'   => 'transient',
			'codes'  => [],
		];
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$result = self::call_siteverify( $body );
			// 只有暫時性的斷線與 Cloudflare 的 internal-error 才重試；設定擋住的不重試（重試也一樣）。
			$retry = 'retry' === $result['status']
				|| ( 'unreachable' === $result['status'] && 'transient' === ( $result['kind'] ?? '' ) );
			if ( ! $retry ) {
				break;
			}
		}

		// Cloudflare 回 internal-error、重試後仍一樣：Cloudflare 有回應，算失敗（不是連不到），寬鬆模式也擋下。
		if ( 'retry' === $result['status'] ) {
			$result['status'] = 'fail';
		}

		if ( 'pass' === $result['status'] ) {
			$result = self::check_hostname( $result );
		}

		return $result;
	}

	/**
	 * 預期網域比對（預設不比對）。
	 *
	 * 建議的做法是一個客戶站一個 Turnstile widget、在 Cloudflare 設好網域白名單、不要多站共用同一組金鑰，
	 * 這樣 token 本身就只會在自己的網域產生。需要再加一道檢查時，用這個 filter 回傳網域清單，
	 * siteverify 回的 hostname 不在清單裡就判失敗。
	 *
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function check_hostname( array $result ): array {
		/**
		 * Turnstile 預期的網域（小寫比對）。預設空陣列＝不比對 hostname。
		 *
		 * @since 1.4.0
		 *
		 * @param string[] $hostnames 例如 [ 'example.com', 'www.example.com' ]。
		 */
		$expected = apply_filters( 'ys_admin_menu_turnstile_expected_hostnames', [] );
		$allowed  = [];
		foreach ( is_array( $expected ) ? $expected : [] as $host ) {
			if ( is_string( $host ) && '' !== trim( $host ) ) {
				$allowed[] = strtolower( trim( $host ) );
			}
		}
		if ( empty( $allowed ) || in_array( (string) ( $result['hostname'] ?? '' ), $allowed, true ) ) {
			return $result;
		}
		return [
			'status' => 'fail',
			'codes'  => [ 'hostname-mismatch' ],
		];
	}

	/**
	 * @param array<string, string> $body
	 * @return array{status:string,codes:string[]}
	 */
	private static function call_siteverify( array $body ): array {
		// 看這次請求有沒有被其他外掛（或站台設定）在 pre_http_request 攔下並回錯誤：
		// 那是設定問題，不是 Cloudflare 暫時連不到。只觀察、不改變結果。
		$intercepted = false;
		$observer    = static function ( $pre, $args = [], $url = '' ) use ( &$intercepted ) {
			if ( self::VERIFY_URL === $url && $pre instanceof \WP_Error ) {
				$intercepted = true;
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $observer, PHP_INT_MAX, 3 );

		$response = wp_remote_post(
			self::VERIFY_URL,
			[
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => 8192,
				'headers'             => [ 'Accept' => 'application/json' ],
				'body'                => $body,
			]
		);

		remove_filter( 'pre_http_request', $observer, PHP_INT_MAX );

		if ( is_wp_error( $response ) ) {
			$error_code = (string) $response->get_error_code();
			if ( $intercepted || 'http_request_not_executed' === $error_code ) {
				return [
					'status' => 'unreachable',
					'kind'   => 'config',
					'codes'  => [ $intercepted ? 'pre-http-request-blocked' : 'http-request-not-executed' ],
				];
			}
			return [
				'status' => 'unreachable',
				'kind'   => 'transient',
				'codes'  => [ 'connection-failed' ],
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return [
				'status' => 'unreachable',
				'kind'   => 'transient',
				'codes'  => [ 'http-' . $code ],
			];
		}

		// 4xx 一樣讀內容：Secret Key 錯誤時 Cloudflare 回 HTTP 400＋invalid-input-secret。
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'success', $data ) ) {
			return [
				'status' => 'fail',
				'codes'  => [ 200 === $code ? 'bad-response' : 'http-' . $code ],
			];
		}

		// 只有 HTTP 200 而且 success 是布林 true 才算通過。
		if ( 200 === $code && true === $data['success'] ) {
			return [
				'status'   => 'pass',
				'codes'    => [],
				'hostname' => isset( $data['hostname'] ) && is_string( $data['hostname'] ) ? strtolower( $data['hostname'] ) : '',
			];
		}

		$codes = self::clean_codes( $data['error-codes'] ?? [] );
		if ( 200 !== $code && empty( $codes ) ) {
			$codes = [ 'http-' . $code ];
		}
		if ( in_array( 'internal-error', $codes, true ) ) {
			return [
				'status' => 'retry',
				'codes'  => $codes,
			];
		}

		return [
			'status' => 'fail',
			'codes'  => $codes,
		];
	}

	/**
	 * @param mixed $codes
	 * @return string[]
	 */
	private static function clean_codes( $codes ): array {
		$out = [];
		foreach ( is_array( $codes ) ? $codes : [] as $code ) {
			if ( is_string( $code ) && 1 === preg_match( '/^[a-z0-9\-]{1,40}$/', $code ) ) {
				$out[] = $code;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * 依驗證結果與模式決定放不放行；連不到 Cloudflare、站台設定擋住連線、Secret 錯誤會記錄下來。
	 *
	 * @param array{status:string,codes:string[],kind?:string} $result
	 */
	private static function decide( array $result, string $context ): bool {
		$status = (string) ( $result['status'] ?? 'fail' );

		if ( 'pass' === $status ) {
			self::note_success();
			return true;
		}

		if ( 'unreachable' === $status ) {
			$allow  = self::MODE_STRICT !== self::get_mode();
			$config = 'config' === ( $result['kind'] ?? '' );
			if ( $config ) {
				self::note_config_error( (string) ( $result['codes'][0] ?? 'blocked' ) );
			}
			self::log_event( $context, $allow ? 'allowed' : 'blocked', $config ? 'config_blocked' : 'unreachable' );
			return $allow;
		}

		$codes = (array) ( $result['codes'] ?? [] );
		if ( array_intersect( [ 'missing-input-secret', 'invalid-input-secret' ], $codes ) ) {
			self::log_event( $context, 'blocked', 'secret_invalid' );
		}

		return false;
	}

	/**
	 * 送給 siteverify 的訪客 IP：只用 REMOTE_ADDR。
	 */
	private static function remote_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下一行以 FILTER_VALIDATE_IP 驗證
			: '';
		$ip = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		/**
		 * 送給 Cloudflare siteverify 的訪客 IP。
		 *
		 * 預設只用 REMOTE_ADDR，不信任 X-Forwarded-For 之類的標頭（訪客可以自己帶）。
		 * 站台在可信任的反向代理後面時，可以用這個 filter 換成代理提供的 IP；回傳空字串＝不送 remoteip。
		 *
		 * @since 1.4.0
		 *
		 * @param string $ip REMOTE_ADDR（不是合法 IP 時為空字串）。
		 */
		$filtered = apply_filters( 'ys_admin_menu_turnstile_remote_ip', $ip );

		return ( is_string( $filtered ) && false !== filter_var( $filtered, FILTER_VALIDATE_IP ) ) ? $filtered : '';
	}

	/**
	 * 給使用者看的錯誤：不說明原因細節，也不因帳號存不存在而不同。
	 */
	private static function failure_error(): \WP_Error {
		return new \WP_Error(
			self::ERROR_CODE,
			'<strong>' . esc_html__( '錯誤', 'ys-admin-menu' ) . '</strong>：' . esc_html__( '人機驗證沒有通過，請重新完成驗證後再送出。', 'ys-admin-menu' )
		);
	}

	/* ─────────────────────────── 記錄 ─────────────────────────── */

	/**
	 * 記錄一筆事件（只記時間、表單、結果、原因；不記 Secret、token、帳號、IP）。
	 *
	 * 同一個原因每分鐘最多寫一次（option 與 error_log 都是）：Cloudflare 連不到時有人狂打登入，
	 * 不會每一次都寫資料庫與紀錄檔。
	 */
	private static function log_event( string $context, string $outcome, string $reason ): void {
		$throttle_key = 'ys_admin_menu_ts_log_' . $reason;
		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, self::LOG_THROTTLE );

		$log = get_option( self::OPTION_LOG, [] );
		$log = is_array( $log ) ? $log : [];
		array_unshift(
			$log,
			[
				'time'    => time(),
				'context' => $context,
				'outcome' => $outcome,
				'reason'  => $reason,
			]
		);
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_MAX ), false );

		error_log( sprintf( '[YS Admin Menu] Turnstile %1$s: %2$s (%3$s, %4$s mode)', $context, $outcome, $reason, self::get_mode() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * @return array<int, array{time:int,context:string,outcome:string,reason:string}>
	 */
	public static function get_log(): array {
		$log = get_option( self::OPTION_LOG, [] );
		$out = [];
		foreach ( is_array( $log ) ? $log : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = [
				'time'    => (int) ( $row['time'] ?? 0 ),
				'context' => in_array( $row['context'] ?? '', array_merge( self::SCOPES, [ 'test' ] ), true ) ? (string) $row['context'] : '',
				'outcome' => in_array( $row['outcome'] ?? '', [ 'allowed', 'blocked' ], true ) ? (string) $row['outcome'] : '',
				'reason'  => in_array( $row['reason'] ?? '', [ 'unreachable', 'config_blocked', 'secret_invalid' ], true ) ? (string) $row['reason'] : '',
			];
		}
		return $out;
	}

	/* ─────────────────────────── 設定頁 ─────────────────────────── */

	/**
	 * 把驗證結果換成設定頁「測試設定」看得懂的說明（不含 Secret）。
	 *
	 * @param array{status:string,codes:string[]} $result
	 */
	public static function describe_result( array $result ): string {
		$codes = (array) ( $result['codes'] ?? [] );

		if ( 'pass' === $result['status'] ) {
			return __( '驗證成功：Site Key、Secret Key 與這個網域都設定正確。', 'ys-admin-menu' );
		}
		if ( 'unreachable' === $result['status'] && 'config' === ( $result['kind'] ?? '' ) ) {
			return __( '對 Cloudflare 的連線被站台設定擋下來了（WP_HTTP_BLOCK_EXTERNAL、主機防火牆，或攔截 HTTP 請求的外掛）。這種情況下 Turnstile 實際上沒有作用：寬鬆模式只靠帳號密碼、嚴格模式所有人都登不進來。請放行 challenges.cloudflare.com。', 'ys-admin-menu' );
		}
		if ( 'unreachable' === $result['status'] ) {
			return __( '伺服器連不到 Cloudflare（siteverify）。寬鬆模式下，這時候登入會被放行並記錄；嚴格模式會擋下。請檢查主機能否連到 challenges.cloudflare.com。', 'ys-admin-menu' );
		}
		if ( in_array( 'hostname-mismatch', $codes, true ) ) {
			return __( '驗證結果的網域不在 ys_admin_menu_turnstile_expected_hostnames 設定的清單裡。', 'ys-admin-menu' );
		}
		if ( array_intersect( [ 'missing-input-secret', 'invalid-input-secret' ], $codes ) ) {
			return __( 'Secret Key 不正確。請到 Cloudflare 後台複製這個 widget 的 Secret Key，重新貼上後儲存。', 'ys-admin-menu' );
		}
		if ( in_array( 'timeout-or-duplicate', $codes, true ) ) {
			return __( '這個驗證結果已經用過或逾時了，請重新完成上方的驗證再測一次。', 'ys-admin-menu' );
		}
		if ( array_intersect( [ 'missing-input-response', 'invalid-input-response' ], $codes ) ) {
			return __( 'Token 無效：請先完成上方的驗證再按測試。一直失敗的話，請確認 Site Key 與 Secret Key 是同一個 widget 的。', 'ys-admin-menu' );
		}
		/* translators: %s: Cloudflare error codes. */
		return sprintf( __( '驗證沒有通過（Cloudflare 回報：%s）。', 'ys-admin-menu' ), '' !== implode( ', ', $codes ) ? implode( ', ', $codes ) : 'unknown' );
	}

	/**
	 * admin-ajax：設定頁「測試設定」。用已儲存的 Site Key／Secret Key 驗證設定頁上 widget 產生的 token。
	 */
	public static function ajax_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( '權限不足。', 'ys-admin-menu' ) ], 403 );
		}
		check_ajax_referer( self::AJAX_TEST_ACTION, 'nonce' );

		if ( '' === self::get_site_key() || ! self::has_secret() ) {
			wp_send_json_error( [ 'message' => __( '請先填好 Site Key 與 Secret Key 並儲存，再測試。', 'ys-admin-menu' ) ], 400 );
		}

		$raw    = $_POST['token'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 上面 check_ajax_referer 已驗證
		$token  = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
		$result = self::verify_token( $token, self::remote_ip() );

		// 測試成功也算一次「最後成功時間」；被設定擋住也記下來，設定頁與後台提醒就會出現。（不寫事件紀錄。）
		if ( 'pass' === $result['status'] ) {
			self::note_success();
		} elseif ( 'unreachable' === $result['status'] && 'config' === ( $result['kind'] ?? '' ) ) {
			self::note_config_error( (string) ( $result['codes'][0] ?? 'blocked' ) );
		}

		$payload = [
			'status'  => $result['status'],
			'message' => self::describe_result( $result ),
		];
		if ( 'pass' === $result['status'] ) {
			wp_send_json_success( $payload );
		}
		wp_send_json_error( $payload, 200 );
	}

	/**
	 * 儲存設定（由 YSWhiteLabelAdmin::handle_save_settings 在驗過權限與 nonce 之後呼叫）。
	 *
	 * Secret Key：輸入框留白＝保留已存的值；勾「清除」＝刪除（會忽略同時輸入的新值）。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 * @return string[] 欄位錯誤代碼（site_key_format／secret_format），格式不對的值不會存。
	 */
	public static function save_settings( array $post ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}

		$errors = [];

		update_option( self::OPTION_ENABLED, ! empty( $post['turnstile_enabled'] ) ? 'yes' : 'no', true );

		$site_key = isset( $post['turnstile_site_key'] ) && is_string( $post['turnstile_site_key'] ) ? trim( $post['turnstile_site_key'] ) : '';
		if ( '' === $site_key || self::is_valid_key_format( $site_key ) ) {
			update_option( self::OPTION_SITE_KEY, $site_key, true );
		} else {
			$errors[] = 'site_key_format';
		}

		if ( ! empty( $post['turnstile_secret_clear'] ) ) {
			delete_option( self::OPTION_SECRET );
		} else {
			$secret = isset( $post['turnstile_secret_key'] ) && is_string( $post['turnstile_secret_key'] ) ? trim( $post['turnstile_secret_key'] ) : '';
			if ( '' !== $secret ) {
				if ( self::is_valid_key_format( $secret ) ) {
					update_option( self::OPTION_SECRET, $secret, false );
				} else {
					$errors[] = 'secret_format';
				}
			}
		}

		$scope = [];
		if ( isset( $post['turnstile_scope'] ) && is_array( $post['turnstile_scope'] ) ) {
			$scope = array_values( array_intersect( self::SCOPES, array_filter( $post['turnstile_scope'], 'is_string' ) ) );
		}
		update_option( self::OPTION_SCOPE, $scope, true );

		$mode = ( isset( $post['turnstile_mode'] ) && self::MODE_STRICT === $post['turnstile_mode'] ) ? self::MODE_STRICT : self::MODE_LENIENT;
		update_option( self::OPTION_MODE, $mode, true );

		return $errors;
	}

	/**
	 * 設定頁需要的值（不含 Secret 本身，只有「有沒有設定」）。
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_view(): array {
		return [
			'enabled'      => self::is_enabled(),
			'site_key'     => self::get_site_key(),
			'has_secret'   => self::has_secret(),
			'scope'        => self::get_scope(),
			'mode'         => self::get_mode(),
			'status'       => self::status(),
			'log'          => self::get_log(),
			'constant_off' => self::is_disabled_by_constant(),
			'can_test'     => '' !== self::get_site_key() && self::has_secret(),
		];
	}
}
