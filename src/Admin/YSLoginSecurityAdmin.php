<?php
/**
 * 白牌設定頁「登入防護」分頁的新設定（v1.6.0）。
 *
 * - 「登入入口」卡片：關閉 XML-RPC 登入、停用應用程式密碼（兩個獨立開關，YSLoginEntrances）。
 * - 「登入次數限制」卡片（§5.2）：總開關、門檻、逐次加長、/48 彙總、白名單、鎖定訊息、跨站判斷的子開關與允許的來源、通知、保留天數。
 * - 「進階：訪客 IP」卡片：偵測方式、信任的代理、信任 Cloudflare、Turnstile 也使用偵測到的 IP、內網位址逐一計數，以及診斷。
 * - 「鎖定與紀錄」（主表單之外，§4.8）：目前鎖定中、被拒的跨站來源、最近的紀錄；解鎖、全部解鎖、清空紀錄、加入白名單、
 *   允許這個來源、現在就開始拒絕。每個動作都要 manage_options 與自己的 nonce action，目標先驗證格式。
 * - 全後台的健康提醒（§4.10，admin_notices，只給 manage_options）。
 *
 * 存檔由 YSWhiteLabelAdmin::handle_save_settings 在驗過 manage_options 與 nonce 之後呼叫；這裡每個欄位再驗證一次，
 * 不合法的值不存、保留原本的值，回傳錯誤代碼給設定頁顯示。
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Security\YSClientIp;
use YangSheep\AdminMenu\Security\YSLoginEntrances;
use YangSheep\AdminMenu\Security\YSLoginGuard;
use YangSheep\AdminMenu\Security\YSLoginGuardCapacity;
use YangSheep\AdminMenu\Security\YSLoginGuardNotices;
use YangSheep\AdminMenu\Security\YSLoginGuardSchema;
use YangSheep\AdminMenu\Security\YSLoginGuardSettings;
use YangSheep\AdminMenu\Security\YSLoginGuardStore;

defined( 'ABSPATH' ) || exit;

final class YSLoginSecurityAdmin {

	/** 錯誤代碼裡最多列幾個行號（避免網址太長）。 */
	private const MAX_LINE_CODES = 5;

	/** 「鎖定與紀錄」的動作：admin-post action＝nonce action（§4.8、§4.13.1）。 */
	public const ACTION_UNLOCK          = 'ys_admin_menu_login_guard_unlock';
	public const ACTION_UNLOCK_ALL      = 'ys_admin_menu_login_guard_unlock_all';
	public const ACTION_CLEAR           = 'ys_admin_menu_login_guard_clear';
	public const ACTION_ALLOWLIST_ADD   = 'ys_admin_menu_login_guard_allowlist_add';
	public const ACTION_ALLOW_ORIGIN    = 'ys_admin_menu_login_guard_allow_origin';
	public const ACTION_END_OBSERVATION = 'ys_admin_menu_login_guard_end_observation';

	/** 動作做完回到設定頁時顯示的結果（白名單）。 */
	public const DONE_CODES = [ 'unlock', 'unlock_none', 'unlock_all', 'clear', 'allowlist_add', 'allowlist_had', 'allow_origin', 'origin_had', 'end_observation' ];

	/** 最近的紀錄一頁幾筆；被拒的跨站來源看幾天、列幾個；鎖定中最多列幾個。 */
	public const LOG_PER_PAGE = 50;
	public const XSITE_DAYS   = 7;
	public const XSITE_LIST   = 20;
	public const LOCKED_LIST  = 200;

	/** 數字欄位：表單欄位名稱 => 設定名稱（範圍在 YSLoginGuardSettings::RANGES）。 */
	private const NUMBER_FIELDS = [
		'lg_max_failures'      => 'max_failures',
		'lg_window'            => 'window',
		'lg_lockout'           => 'lockout',
		'lg_escalate_after'    => 'escalate_after',
		'lg_escalate_period'   => 'escalate_period',
		'lg_escalated_lockout' => 'escalated_lockout',
		'lg_retention_days'    => 'retention_days',
	];

	/**
	 * 註冊「鎖定與紀錄」的動作與全後台的提醒（YSWhiteLabelAdmin::register 呼叫）。
	 */
	public static function register(): void {
		foreach ( [ self::ACTION_UNLOCK, self::ACTION_UNLOCK_ALL, self::ACTION_CLEAR, self::ACTION_ALLOWLIST_ADD, self::ACTION_ALLOW_ORIGIN, self::ACTION_END_OBSERVATION ] as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle_action' ] );
		}
		add_action( 'admin_notices', [ self::class, 'print_notices' ] );
	}

	/**
	 * 存「登入入口」的兩個開關。整頁只有一張表單，存任何分頁都會送出這兩個勾選，沒勾就是 no。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 */
	public static function save_entrance_settings( array $post ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		update_option( YSLoginEntrances::OPTION_DISABLE_XMLRPC, ! empty( $post['disable_xmlrpc_login'] ) ? 'yes' : 'no', false );
		update_option( YSLoginEntrances::OPTION_DISABLE_APP_PASSWORDS, ! empty( $post['disable_app_passwords'] ) ? 'yes' : 'no', true );
	}

	/**
	 * 「登入入口」卡片需要的值。
	 *
	 * @return array{disable_xmlrpc: bool, disable_app_passwords: bool, app_passwords_supported: bool}
	 */
	public static function entrance_view(): array {
		return [
			'disable_xmlrpc'          => YSLoginEntrances::xmlrpc_login_disabled(),
			'disable_app_passwords'   => YSLoginEntrances::app_passwords_disabled(),
			// 沒有 HTTPS（也不是 local 環境）時，WordPress 本來就不提供應用程式密碼。
			'app_passwords_supported' => function_exists( 'wp_is_application_passwords_supported' ) ? (bool) wp_is_application_passwords_supported() : true,
		];
	}

	/**
	 * 存「訪客 IP」設定。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 * @return string[] 錯誤或提醒代碼。
	 */
	public static function save_ip_settings( array $post ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}
		$codes = [];

		// 信任的代理：有任何一行不合法或太寬就整欄不存（保留原本的值），並列出是第幾行。
		$raw    = isset( $post['ip_trusted_proxies'] ) && is_string( $post['ip_trusted_proxies'] ) ? $post['ip_trusted_proxies'] : '';
		$parsed = YSClientIp::parse_list( $raw );
		if ( $parsed['too_many'] ) {
			$codes[] = 'ip_trusted_too_many';
		}
		foreach ( array_slice( $parsed['invalid'], 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'ip_trusted_invalid_' . (int) $line;
		}
		foreach ( array_slice( $parsed['too_wide'], 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'ip_trusted_wide_' . (int) $line;
		}
		if ( ! $parsed['too_many'] && [] === $parsed['invalid'] && [] === $parsed['too_wide'] ) {
			update_option( YSClientIp::OPTION_TRUSTED_PROXIES, implode( "\n", $parsed['lines'] ), false );
			foreach ( array_slice( $parsed['wide'], 0, self::MAX_LINE_CODES ) as $line ) {
				$codes[] = 'ip_trusted_warn_' . (int) $line;
			}
		}

		$trust = ! empty( $post['ip_trust_cloudflare'] ) ? 'yes' : 'no';
		update_option( YSClientIp::OPTION_TRUST_CLOUDFLARE, $trust, false );

		$header    = isset( $post['ip_custom_header'] ) && is_string( $post['ip_custom_header'] ) ? trim( $post['ip_custom_header'] ) : '';
		$header_ok = '' === $header || '' === YSClientIp::validate_header_name( $header );
		if ( $header_ok ) {
			update_option( YSClientIp::OPTION_CUSTOM_HEADER, $header, false );
		} else {
			$codes[] = 'ip_custom_header';
		}

		$source = isset( $post['ip_source'] ) && is_string( $post['ip_source'] ) && in_array( $post['ip_source'], YSClientIp::SOURCES, true )
			? $post['ip_source']
			: 'auto';
		if ( 'cf_connecting_ip' === $source && 'yes' !== $trust ) {
			$codes[] = 'ip_cf_needs_trust';
		} elseif ( 'custom' === $source && ( '' === $header || ! $header_ok ) ) {
			$codes[] = 'ip_custom_needs_header';
		} else {
			update_option( YSClientIp::OPTION_SOURCE, $source, false );
		}

		update_option( YSClientIp::OPTION_TURNSTILE_USE, ! empty( $post['ip_turnstile_use'] ) ? 'yes' : 'no', false );

		YSClientIp::reset();
		return $codes;
	}

	/**
	 * 「訪客 IP」卡片需要的值：診斷、已存的信任清單原文、Turnstile 勾選。
	 *
	 * @return array<string, mixed>
	 */
	public static function ip_view(): array {
		$raw = get_option( YSClientIp::OPTION_TRUSTED_PROXIES, '' );
		return [
			'diagnose'      => YSClientIp::diagnose(),
			'trusted_raw'   => is_string( $raw ) ? $raw : '',
			'turnstile_use' => YSClientIp::turnstile_uses_detected_ip(),
		];
	}

	/* ─────────────────────────── 登入次數限制：存檔（§4.1、§5.2） ─────────────────────────── */

	/**
	 * 存「登入次數限制」卡片（與「進階：訪客 IP」裡的內網位址逐一計數）。卡片有送出（lg_present）才存，避免舊的表單把設定洗掉。
	 * 每個欄位各自驗證，不合法的不存、保留原本的值，回錯誤代碼。存完照「關到開」寫觀察期的開始時間、總開關打開時建表。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 * @return string[] 錯誤或提醒代碼。
	 */
	public static function save_guard_settings( array $post ): array {
		if ( ! current_user_can( 'manage_options' ) || empty( $post['lg_present'] ) ) {
			return [];
		}
		$codes = [];
		$old   = [ 'enabled' => YSLoginGuardSettings::enabled(), 'cross_site_reject' => YSLoginGuardSettings::cross_site_reject() ];

		$yes = static fn( string $field ): string => ! empty( $post[ $field ] ) ? 'yes' : 'no';
		update_option( YSLoginGuardSettings::OPTION_ENABLED, $yes( 'lg_enabled' ), true );
		update_option( YSLoginGuardSettings::OPTION_ESCALATE, $yes( 'lg_escalate' ), false );
		update_option( YSLoginGuardSettings::OPTION_IPV6_AGGREGATE, $yes( 'lg_ipv6_aggregate' ), false );
		update_option( YSLoginGuardSettings::OPTION_LOCK_PRIVATE, $yes( 'lg_lock_private' ), false );
		update_option( YSLoginGuardSettings::OPTION_CROSS_SITE_REJECT, $yes( 'lg_cross_site_reject' ), false );
		update_option( YSLoginGuardSettings::OPTION_NOTIFY, $yes( 'lg_notify' ), false );

		// 數字：整數、在範圍內才存。
		$numbers = [];
		foreach ( self::NUMBER_FIELDS as $field => $name ) {
			$raw = isset( $post[ $field ] ) && is_scalar( $post[ $field ] ) ? trim( (string) $post[ $field ] ) : '';
			[ , $min, $max ] = YSLoginGuardSettings::RANGES[ $name ];
			if ( 1 === preg_match( '/^\d{1,9}$/', $raw ) && (int) $raw >= $min && (int) $raw <= $max ) {
				$numbers[ $name ] = (int) $raw;
			} else {
				$codes[] = 'lg_range_' . $name;
			}
		}
		$lockout = $numbers['lockout'] ?? YSLoginGuardSettings::int( 'lockout' );
		if ( isset( $numbers['escalated_lockout'] ) && 'yes' === $yes( 'lg_escalate' ) && 60 * $numbers['escalated_lockout'] <= $lockout ) {
			unset( $numbers['escalated_lockout'] );
			$codes[] = 'lg_escalated_short';
		}
		$keys = [
			'max_failures'      => YSLoginGuardSettings::OPTION_MAX_FAILURES,
			'window'            => YSLoginGuardSettings::OPTION_WINDOW,
			'lockout'           => YSLoginGuardSettings::OPTION_LOCKOUT,
			'escalate_after'    => YSLoginGuardSettings::OPTION_ESCALATE_AFTER,
			'escalate_period'   => YSLoginGuardSettings::OPTION_ESCALATE_PERIOD,
			'escalated_lockout' => YSLoginGuardSettings::OPTION_ESCALATED_LOCKOUT,
			'retention_days'    => YSLoginGuardSettings::OPTION_RETENTION_DAYS,
		];
		foreach ( $numbers as $name => $value ) {
			update_option( $keys[ $name ], $value, false );
		}

		// 白名單：跟信任的代理同一套解析；有任何一行不合法或太寬就整欄不存。
		$raw    = isset( $post['lg_allowlist'] ) && is_string( $post['lg_allowlist'] ) ? $post['lg_allowlist'] : '';
		$parsed = YSClientIp::parse_list( $raw );
		if ( $parsed['too_many'] ) {
			$codes[] = 'lg_allow_too_many';
		}
		foreach ( array_slice( $parsed['invalid'], 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'lg_allow_invalid_' . (int) $line;
		}
		foreach ( array_slice( $parsed['too_wide'], 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'lg_allow_wide_' . (int) $line;
		}
		if ( ! $parsed['too_many'] && [] === $parsed['invalid'] && [] === $parsed['too_wide'] ) {
			update_option( YSLoginGuardSettings::OPTION_ALLOWLIST, implode( "\n", array_values( array_unique( $parsed['lines'] ) ) ), false );
			foreach ( array_slice( $parsed['wide'], 0, self::MAX_LINE_CODES ) as $line ) {
				$codes[] = 'lg_allow_warn_' . (int) $line;
			}
		}

		// 允許跨站登入的來源：https://主機[:埠]（本機可以 http://localhost），最多 50 行；有不合法的就整欄不存。
		$raw     = isset( $post['lg_cross_site_origins'] ) && is_string( $post['lg_cross_site_origins'] ) ? $post['lg_cross_site_origins'] : '';
		$origins = [];
		$bad     = [];
		$count   = 0;
		foreach ( (array) preg_split( '/\r\n|\r|\n/', $raw ) as $index => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			++$count;
			$origin = YSLoginGuardSettings::valid_origin( $line );
			if ( '' === $origin ) {
				$bad[] = $index + 1;
			} else {
				$origins[ $origin ] = true;
			}
		}
		if ( $count > YSLoginGuardSettings::ORIGINS_MAX ) {
			$codes[] = 'lg_origin_too_many';
		}
		foreach ( array_slice( $bad, 0, self::MAX_LINE_CODES ) as $line ) {
			$codes[] = 'lg_origin_invalid_' . (int) $line;
		}
		if ( [] === $bad && $count <= YSLoginGuardSettings::ORIGINS_MAX ) {
			update_option( YSLoginGuardSettings::OPTION_CROSS_ORIGINS, implode( "\n", array_keys( $origins ) ), false );
		}

		// 鎖定時的訊息：純文字，最多 200 字。
		$message = isset( $post['lg_message'] ) && is_string( $post['lg_message'] ) ? sanitize_text_field( $post['lg_message'] ) : '';
		$message = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) );
		update_option( YSLoginGuardSettings::OPTION_MESSAGE, function_exists( 'mb_substr' ) ? mb_substr( $message, 0, YSLoginGuardSettings::MESSAGE_MAX ) : substr( $message, 0, YSLoginGuardSettings::MESSAGE_MAX ), false );

		// 通知信箱：留空＝網站管理員信箱；不合法的不存。
		$email = isset( $post['lg_notify_email'] ) && is_string( $post['lg_notify_email'] ) ? trim( $post['lg_notify_email'] ) : '';
		if ( '' === $email || false !== is_email( $email ) ) {
			update_option( YSLoginGuardSettings::OPTION_NOTIFY_EMAIL, $email, false );
		} else {
			$codes[] = 'lg_email';
		}

		$new = [ 'enabled' => YSLoginGuardSettings::enabled(), 'cross_site_reject' => YSLoginGuardSettings::cross_site_reject() ];
		YSLoginGuard::settings_changed( $old, $new );
		return $codes;
	}

	/* ─────────────────────────── 登入次數限制：設定頁要用的值 ─────────────────────────── */

	/**
	 * 「登入次數限制」卡片需要的值。打開設定頁時也順手清理（距上次超過一小時才做，§4.7.3 第 2 項）。
	 *
	 * @return array<string, mixed>
	 */
	public static function guard_view(): array {
		$now = time();
		YSLoginGuardCapacity::cleanup_if_due( $now );
		$ip       = (string) YSClientIp::resolve()['ip'];
		$since    = YSLoginGuardSettings::cross_site_since();
		$observe  = YSLoginGuardSettings::enabled() && YSLoginGuardSettings::cross_site_reject() && YSLoginGuard::observing( $now );
		$allow    = get_option( YSLoginGuardSettings::OPTION_ALLOWLIST, '' );
		$origins  = get_option( YSLoginGuardSettings::OPTION_CROSS_ORIGINS, '' );
		$auto     = YSLoginGuard::auto_origins();
		$status   = YSLoginGuardSchema::is_ready() ? YSLoginGuard::status() : [ 'effective' => false, 'reasons' => [ 'table_not_ready' ], 'stats' => [] ];
		$numbers  = [];
		foreach ( self::NUMBER_FIELDS as $field => $name ) {
			$numbers[ $name ] = YSLoginGuardSettings::int( $name );
		}
		return [
			'enabled'           => YSLoginGuardSettings::enabled(),
			'disabled_constant' => YSLoginGuardSettings::disabled_by_constant(),
			'ready'             => YSLoginGuardSchema::is_ready(),
			'status'            => $status,
			'numbers'           => $numbers,
			'escalate'          => YSLoginGuardSettings::escalate(),
			'ipv6_aggregate'    => YSLoginGuardSettings::ipv6_aggregate(),
			'lock_private'      => 'yes' === get_option( YSLoginGuardSettings::OPTION_LOCK_PRIVATE, 'no' ),
			'cross_site_reject' => YSLoginGuardSettings::cross_site_reject(),
			'observing'         => $observe,
			'observe_until'     => $observe ? $since + YSLoginGuardSettings::OBSERVE_SECONDS : 0,
			'allowlist_raw'     => is_string( $allow ) ? $allow : '',
			'origins_raw'       => is_string( $origins ) ? $origins : '',
			'auto_yscart'       => $auto['yscart'],
			'auto_core'         => $auto['core'],
			'message'           => (string) get_option( YSLoginGuardSettings::OPTION_MESSAGE, '' ),
			'notify'            => YSLoginGuardSettings::notify(),
			'notify_email'      => (string) get_option( YSLoginGuardSettings::OPTION_NOTIFY_EMAIL, '' ),
			'admin_email'       => (string) get_option( 'admin_email', '' ),
			'my_ip'             => $ip,
			'my_ip_listed'      => '' !== $ip && YSClientIp::in_any( $ip, YSLoginGuardSettings::allowlist() ),
		];
	}

	/**
	 * 「鎖定與紀錄」需要的值：目前鎖定中、最近 7 天被拒的跨站來源、最近的紀錄（可依 IP 或鍵篩選，一頁 50 筆）。
	 *
	 * @param array<string, mixed> $get 已 wp_unslash 的 $_GET（只讀 lg_ip、lg_page）。
	 * @return array<string, mixed>
	 */
	public static function records_view( array $get ): array {
		$now = time();
		if ( ! current_user_can( 'manage_options' ) || ! YSLoginGuardSchema::is_ready() ) {
			return [ 'ready' => false ];
		}
		$filter = isset( $get['lg_ip'] ) && is_string( $get['lg_ip'] ) ? self::clean_filter( $get['lg_ip'] ) : '';
		$page   = isset( $get['lg_page'] ) && is_scalar( $get['lg_page'] ) && 1 === preg_match( '/^\d{1,6}$/', (string) $get['lg_page'] ) ? max( 1, (int) $get['lg_page'] ) : 1;
		$locked = [];
		foreach ( YSLoginGuardStore::list_rows( $now, true, '', self::LOCKED_LIST ) as $row ) {
			$key      = (string) $row['ip_key'];
			$locked[] = [
				'key'      => $key,
				'label'    => YSLoginGuardNotices::describe_key( $key ),
				'shared'   => YSLoginGuardNotices::is_shared( $key ),
				'last_ip'  => YSClientIp::normalize( (string) $row['last_ip'] ),
				'until'    => (int) $row['locked_until'],
				'failures' => (int) $row['total_failures'],
				'accounts' => YSLoginGuardStore::acct_count( $key ),
				'blocked'  => (int) $row['blocked_count'],
				'username' => YSLoginGuardStore::sanitize_username( (string) $row['last_username'] ),
				'source'   => (string) preg_replace( '/[^a-z0-9_-]/', '', (string) $row['last_source'] ),
			];
		}
		$logs = YSLoginGuardStore::recent_logs( $filter, $now - YSLoginGuardSettings::retention_seconds(), $page, self::LOG_PER_PAGE );
		foreach ( $logs['rows'] as &$log ) {
			$log['username'] = YSLoginGuardStore::sanitize_username( (string) $log['username'] );
			$log['ip']       = YSClientIp::normalize( (string) $log['ip'] );
		}
		unset( $log );
		return [
			'ready'   => true,
			'now'     => $now,
			'locked'  => $locked,
			'xsite'   => YSLoginGuardStore::xsite_sources( $now - self::XSITE_DAYS * 86400, self::XSITE_LIST ),
			'logs'    => $logs['rows'],
			'total'   => $logs['total'],
			'page'    => $page,
			'pages'   => max( 1, (int) ceil( $logs['total'] / self::LOG_PER_PAGE ) ),
			'filter'  => $filter,
		];
	}

	/** 篩選參數：先驗證成 IP 或鍵的格式，不合法就不篩（§4.8、審查 P3-13）。 */
	public static function clean_filter( string $raw ): string {
		$raw = trim( $raw );
		$ip  = YSClientIp::normalize( $raw );
		if ( '' !== $ip ) {
			return $ip;
		}
		$key = strtolower( $raw );
		return YSLoginGuardStore::is_valid_key( $key ) ? $key : '';
	}

	/* ─────────────────────────── 「鎖定與紀錄」的動作（§4.8） ─────────────────────────── */

	/**
	 * admin-post 的動作：manage_options、這個動作自己的 nonce，目標先驗證格式。做完回到設定頁的「鎖定與紀錄」。
	 */
	public static function handle_action(): void {
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只用來決定要驗哪一個 nonce
		$known  = [ self::ACTION_UNLOCK, self::ACTION_UNLOCK_ALL, self::ACTION_CLEAR, self::ACTION_ALLOWLIST_ADD, self::ACTION_ALLOW_ORIGIN, self::ACTION_END_OBSERVATION ];
		if ( ! in_array( $action, $known, true ) ) {
			wp_die( '不認得的動作。' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '權限不足。' );
		}
		check_admin_referer( $action );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下面逐欄驗證
		$post = is_array( $post ) ? $post : [];
		self::redirect( self::run_action( $action, $post, time() ) );
	}

	/**
	 * 真正做事的部分（權限與 nonce 已經在 handle_action 驗過；這裡再看一次權限）。
	 *
	 * @param array<string, mixed> $post
	 * @return array{done?:string,error?:string}
	 */
	public static function run_action( string $action, array $post, int $now ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ 'error' => 'lg_bad_target' ];
		}
		if ( self::ACTION_END_OBSERVATION === $action ) {
			YSLoginGuard::end_observation();
			return [ 'done' => 'end_observation' ];
		}
		if ( self::ACTION_ALLOWLIST_ADD === $action ) {
			return self::allowlist_add();
		}
		if ( self::ACTION_ALLOW_ORIGIN === $action ) {
			return self::allow_origin( isset( $post['lg_origin'] ) && is_string( $post['lg_origin'] ) ? $post['lg_origin'] : '' );
		}
		if ( ! YSLoginGuardSchema::is_ready() ) {
			return [ 'error' => 'lg_not_ready' ];
		}
		if ( self::ACTION_UNLOCK === $action ) {
			$key = isset( $post['lg_key'] ) && is_string( $post['lg_key'] ) ? strtolower( trim( $post['lg_key'] ) ) : '';
			if ( ! YSLoginGuardStore::is_valid_key( $key ) ) {
				return [ 'error' => 'lg_bad_target' ];
			}
			$n = YSLoginGuardStore::unlock( $key );
			YSLoginGuardStore::log_event( YSLoginGuardStore::EVENT_UNLOCK, [ 'ip_key' => $key, 'code' => 'admin' ], $now );
			return [ 'done' => $n > 0 ? 'unlock' : 'unlock_none' ];
		}
		if ( self::ACTION_UNLOCK_ALL === $action ) {
			$n = YSLoginGuardStore::unlock_all( $now );
			YSLoginGuardStore::log_event( YSLoginGuardStore::EVENT_UNLOCK, [ 'code' => 'admin_all_' . $n ], $now );
			return [ 'done' => 'unlock_all' ];
		}
		if ( self::ACTION_CLEAR === $action ) {
			if ( 'yes' !== ( $post['lg_confirm'] ?? '' ) ) {
				return [ 'error' => 'lg_clear_confirm' ];
			}
			YSLoginGuardStore::clear_records( $now );
			YSLoginGuardStore::log_event( YSLoginGuardStore::EVENT_CLEAR, [ 'code' => 'admin' ], $now );
			return [ 'done' => 'clear' ];
		}
		return [ 'error' => 'lg_bad_target' ];
	}

	/**
	 * 「加入目前的 IP」：加的是這一次請求偵測到的位址（不收表單送來的值，避免被引導加進別的位址）；IPv6 加它的 /64。
	 *
	 * @return array{done?:string,error?:string}
	 */
	private static function allowlist_add(): array {
		$ip = (string) YSClientIp::resolve()['ip'];
		if ( '' === $ip ) {
			return [ 'error' => 'lg_no_ip' ];
		}
		$entry = str_contains( $ip, ':' ) ? YSClientIp::network( $ip, 64 ) : $ip;
		if ( YSClientIp::in_any( $ip, YSLoginGuardSettings::allowlist() ) ) {
			return [ 'done' => 'allowlist_had' ];
		}
		$raw   = get_option( YSLoginGuardSettings::OPTION_ALLOWLIST, '' );
		$lines = YSClientIp::parse_list( is_string( $raw ) ? $raw : '' )['lines'];
		if ( count( $lines ) >= YSClientIp::TRUSTED_MAX_LINES ) {
			return [ 'error' => 'lg_allow_full' ];
		}
		$lines[] = $entry;
		update_option( YSLoginGuardSettings::OPTION_ALLOWLIST, implode( "\n", array_values( array_unique( $lines ) ) ), false );
		return [ 'done' => 'allowlist_add' ];
	}

	/**
	 * 「允許這個來源」：再正規化、驗證一次才寫進設定（§4.13.1）。
	 *
	 * @return array{done?:string,error?:string}
	 */
	private static function allow_origin( string $raw ): array {
		$origin = YSLoginGuardSettings::valid_origin( $raw );
		if ( '' === $origin ) {
			return [ 'error' => 'lg_bad_target' ];
		}
		$list = YSLoginGuardSettings::cross_site_origins();
		if ( in_array( $origin, $list, true ) ) {
			return [ 'done' => 'origin_had' ];
		}
		if ( count( $list ) >= YSLoginGuardSettings::ORIGINS_MAX ) {
			return [ 'error' => 'lg_origin_full' ];
		}
		$list[] = $origin;
		update_option( YSLoginGuardSettings::OPTION_CROSS_ORIGINS, implode( "\n", $list ), false );
		return [ 'done' => 'allow_origin' ];
	}

	/**
	 * @param array{done?:string,error?:string} $result
	 */
	private static function redirect( array $result ): void {
		$args = [ 'page' => YSMenuPage::SLUG_SETTINGS, 'tab' => 'turnstile' ];
		if ( isset( $result['done'] ) ) {
			$args['ys_am_lg_done'] = $result['done'];
		}
		if ( isset( $result['error'] ) ) {
			$args['ys_am_wl_err'] = $result['error'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#ys-am-lg-records' );
		exit;
	}

	/* ─────────────────────────── 給人看的結果與錯誤 ─────────────────────────── */

	/**
	 * 存檔時擋下的登入次數限制欄位（設定頁卡片裡顯示）。
	 *
	 * @param string[] $codes 已過白名單的代碼。
	 * @return string[]
	 */
	public static function guard_error_texts( array $codes ): array {
		$names = [
			'max_failures'      => '失敗幾次就鎖（3～100）',
			'window'            => '在多久之內（1～1440 分鐘）',
			'lockout'           => '鎖多久（1～10080 分鐘）',
			'escalate_after'    => '第幾次被鎖起加長（2～20）',
			'escalate_period'   => '逐次加長的計算期間（1～720 小時）',
			'escalated_lockout' => '加長為幾小時（1～720 小時）',
			'retention_days'    => '紀錄保留天數（1～365）',
		];
		$lines = [ 'allow_invalid' => [], 'allow_wide' => [], 'allow_warn' => [], 'origin_invalid' => [] ];
		$out   = [];
		foreach ( $codes as $code ) {
			if ( 1 === preg_match( '/^lg_range_([a-z_]+)$/', (string) $code, $m ) && isset( $names[ $m[1] ] ) ) {
				$out[] = '「' . $names[ $m[1] ] . '」不在範圍內，已保留原本的值。';
			} elseif ( 1 === preg_match( '/^lg_(allow_invalid|allow_wide|allow_warn|origin_invalid)_(\d{1,3})$/', (string) $code, $m ) ) {
				$lines[ $m[1] ][] = $m[2];
			}
		}
		$texts = [
			'allow_invalid'  => '白名單第 %s 行不是合法的 IP 或 CIDR，白名單沒有儲存（保留原本的值）。',
			'allow_wide'     => '白名單第 %s 行的網段太寬（IPv4 要 /8 以上、IPv6 要 /32 以上），白名單沒有儲存。',
			'allow_warn'     => '白名單已儲存，但第 %s 行是寬於 /16 的公開網段：那整段的位址都不會被計數。確定都是你們的再留著。',
			'origin_invalid' => '允許跨站登入的來源第 %s 行不是 https://主機[:埠]（本機可以用 http://localhost），沒有儲存（保留原本的值）。',
		];
		foreach ( $texts as $kind => $text ) {
			if ( [] !== $lines[ $kind ] ) {
				$out[] = sprintf( $text, implode( '、', $lines[ $kind ] ) );
			}
		}
		$fixed = [
			'lg_allow_too_many'  => '白名單超過 200 行，沒有儲存。',
			'lg_origin_too_many' => '允許跨站登入的來源超過 50 行，沒有儲存。',
			'lg_escalated_short' => '逐次加長的時間要比「鎖多久」長，加長的時間沒有儲存。',
			'lg_email'           => '通知信箱不是合法的 email，沒有儲存。',
		];
		foreach ( $fixed as $code => $text ) {
			if ( in_array( $code, $codes, true ) ) {
				$out[] = $text;
			}
		}
		return $out;
	}

	/**
	 * 「鎖定與紀錄」動作的錯誤（記錄區上方顯示）。
	 *
	 * @param string[] $codes 已過白名單的代碼。
	 * @return string[]
	 */
	public static function action_error_texts( array $codes ): array {
		$fixed = [
			'lg_clear_confirm' => '要清空紀錄，請先勾「我確定要清空紀錄」。',
			'lg_not_ready'     => '資料表還沒建好，這個動作沒有做。',
			'lg_bad_target'    => '要處理的對象格式不對，沒有做。',
			'lg_no_ip'         => '偵測不到你目前的 IP，沒有加進白名單。',
			'lg_allow_full'    => '白名單已經 200 行，沒有再加。',
			'lg_origin_full'   => '允許跨站登入的來源已經 50 行，沒有再加。',
		];
		$out = [];
		foreach ( $fixed as $code => $text ) {
			if ( in_array( $code, $codes, true ) ) {
				$out[] = $text;
			}
		}
		return $out;
	}

	/** 動作做完的說明（不認得的代碼回空字串）。 */
	public static function done_text( string $code ): string {
		$texts = [
			'unlock'          => '已經解鎖。',
			'unlock_none'     => '這個對象目前沒有紀錄，不用解鎖。',
			'unlock_all'      => '已經全部解鎖。',
			'clear'           => '已經清空紀錄（鎖定與計數沒有動）。',
			'allowlist_add'   => '已經把你目前的 IP 加進白名單。',
			'allowlist_had'   => '你目前的 IP 已經在白名單裡。',
			'allow_origin'    => '已經允許這個來源。',
			'origin_had'      => '這個來源已經在允許清單裡。',
			'end_observation' => '已經開始拒絕從其他網站送來的登入。',
		];
		return $texts[ $code ] ?? '';
	}

	/* ─────────────────────────── 全後台的提醒（§4.10） ─────────────────────────── */

	/**
	 * admin_notices：只給 manage_options；總開關打開時才有。文字一律跳脫。
	 */
	public static function print_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$classes = [ 'error' => 'notice-error', 'warning' => 'notice-warning', 'info' => 'notice-info' ];
		foreach ( YSLoginGuardNotices::collect( time() ) as $notice ) {
			echo '<div class="notice ' . esc_attr( $classes[ $notice['level'] ] ?? 'notice-info' ) . '" data-ys-am-lg-notice="' . esc_attr( $notice['code'] ) . '"><p><strong>' . esc_html__( '登入防護', 'ys-admin-menu' ) . '：</strong>' . esc_html( $notice['text'] );
			if ( [] !== $notice['items'] ) {
				echo '</p><ul class="ul-disc">';
				foreach ( $notice['items'] as $item ) {
					echo '<li>' . esc_html( (string) $item ) . '</li>';
				}
				echo '</ul><p>';
			} else {
				echo ' ';
			}
			echo '<a href="' . esc_url( YSLoginGuardNotices::settings_url() ) . '">' . esc_html__( '前往設定', 'ys-admin-menu' ) . '</a></p></div>';
		}
	}

	/**
	 * 設定頁看得懂的錯誤與提醒代碼（白名單；行號只收 1～999）。
	 *
	 * @param string[] $codes
	 * @return string[]
	 */
	public static function filter_codes( array $codes ): array {
		$fixed = [
			'ip_trusted_too_many', 'ip_custom_header', 'ip_cf_needs_trust', 'ip_custom_needs_header',
			'lg_allow_too_many', 'lg_origin_too_many', 'lg_escalated_short', 'lg_email', 'lg_clear_confirm', 'lg_not_ready', 'lg_bad_target', 'lg_no_ip', 'lg_allow_full', 'lg_origin_full',
		];
		return array_values(
			array_filter(
				$codes,
				static fn( $c ) => is_string( $c ) && (
					in_array( $c, $fixed, true )
					|| 1 === preg_match( '/^ip_trusted_(invalid|wide|warn)_[1-9]\d{0,2}$/', $c )
					|| 1 === preg_match( '/^lg_allow_(invalid|wide|warn)_[1-9]\d{0,2}$/', $c )
					|| 1 === preg_match( '/^lg_origin_invalid_[1-9]\d{0,2}$/', $c )
					|| 1 === preg_match( '/^lg_range_(max_failures|window|lockout|escalate_after|escalate_period|escalated_lockout|retention_days)$/', $c )
				)
			)
		);
	}
}
