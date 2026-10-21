<?php
/**
 * 訪客 IP 偵測（v1.6.0）：跟 Wordfence 一樣有多種方式可選，但訪客不能靠自己帶標頭偽造。
 *
 * - 六種方式：自動（預設）、只用 REMOTE_ADDR、Cloudflare（CF-Connecting-IP）、X-Forwarded-For、X-Real-IP、自訂標頭。
 * - 自動不猜標頭：就是 REMOTE_ADDR；打開「信任 Cloudflare」時，從 Cloudflare 範圍來的請求才改用 CF-Connecting-IP。
 * - 標頭模式只看管理員指定的那一個標頭，而且 REMOTE_ADDR 要在管理員明確加入的「信任的代理」清單裡
 *   （打開信任 Cloudflare 時，加上內建的 Cloudflare 範圍）。私有位址不會自動被信任。
 * - X-Forwarded-For 與自訂標頭從右往左讀：跳過信任的代理，遇到不合法的段落就停（結果用 REMOTE_ADDR）。
 * - 帶 CF-Worker 標頭、而且不是本站 zone 的請求（外站 Worker）：不採用 CF-Connecting-IP。
 * - 最後過 filter ys_admin_menu_client_ip，回傳合法而且不一樣的位址時採用（標成「從標頭或 filter 來的」）。
 * - 同一個請求只算一次；reset() 清掉記住的結果。
 *
 * 只負責「這個請求的訪客是誰」與診斷；登入次數限制怎麼用這個結果（共用桶、鍵）在 YSLoginGuard。
 *
 * @package YangSheep\AdminMenu\Security
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Security;

defined( 'ABSPATH' ) || exit;

final class YSClientIp {

	/** wp_options key — 偵測方式（見 SOURCES，預設 auto）。都不自動載入。 */
	public const OPTION_SOURCE = 'ys_admin_menu_ip_source';

	/** wp_options key — 自訂標頭的名稱（例如 True-Client-IP）。 */
	public const OPTION_CUSTOM_HEADER = 'ys_admin_menu_ip_custom_header';

	/** wp_options key — 信任的代理（一行一個 IP 或 CIDR）。 */
	public const OPTION_TRUSTED_PROXIES = 'ys_admin_menu_ip_trusted_proxies';

	/** wp_options key — 信任 Cloudflare 的 IP 範圍（yes／no，預設 no）。 */
	public const OPTION_TRUST_CLOUDFLARE = 'ys_admin_menu_ip_trust_cloudflare';

	/** wp_options key — Turnstile 也使用偵測到的 IP（yes／no，預設 no；不勾＝照 1.5.2 只用 REMOTE_ADDR）。 */
	public const OPTION_TURNSTILE_USE = 'ys_admin_menu_ip_turnstile_use';

	public const SOURCES = [ 'auto', 'remote_addr', 'cf_connecting_ip', 'x_forwarded_for', 'x_real_ip', 'custom' ];

	/** 標頭最多看幾個字元（超過只取最右邊這麼多）、最多看最右邊幾段。 */
	public const HEADER_MAX   = 1024;
	public const SEGMENTS_MAX = 20;

	/** 信任清單最多幾行。 */
	public const TRUSTED_MAX_LINES = 200;

	/** 自訂標頭不能用的名稱（小寫；另外 content- 開頭的也不行）。 */
	private const REJECTED_HEADERS = [ 'host', 'cookie', 'authorization', 'proxy-authorization', 'forwarded' ];

	/**
	 * 內部與保留位址（自己的範圍表，不靠 PHP 各版不同的旗標）。64:ff9b::/96（NAT64）不在表裡：normalize() 先轉回內嵌的 IPv4，
	 * 照那個 IPv4 分類。
	 */
	private const INTERNAL_RANGES = [
		'10.0.0.0/8',
		'172.16.0.0/12',
		'192.168.0.0/16',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'0.0.0.0/8',
		'192.0.0.0/24',
		'198.18.0.0/15',
		'192.0.2.0/24',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'255.255.255.255/32',
		'::1/128',
		'::/128',
		'fc00::/7',
		'fe80::/10',
		'fec0::/10',
		'100::/64',
		'2001:db8::/32',
		'ff00::/8',
	];

	/** Cloudflare 範圍裡唯一允許寬於 /32 的 IPv6 網段（存信任清單時的例外）。 */
	private const WIDE_IPV6_EXCEPTION = '2a06:98c0::/29';

	/** @var null|array{ip:string,from_header:bool,source:string} 這一次請求的結果。 */
	private static ?array $cache = null;

	/** @var null|array<int, array{0:string,1:int}> Cloudflare 範圍（解析過的 CIDR）。 */
	private static ?array $cloudflare = null;

	/** 清掉這一次請求記住的結果（每個請求開始時、設定存檔後）。 */
	public static function reset(): void {
		self::$cache      = null;
		self::$cloudflare = null;
	}

	/* ─────────────────────────── 設定 ─────────────────────────── */

	public static function get_source(): string {
		$value = get_option( self::OPTION_SOURCE, 'auto' );
		return is_string( $value ) && in_array( $value, self::SOURCES, true ) ? $value : 'auto';
	}

	public static function get_custom_header(): string {
		$value = get_option( self::OPTION_CUSTOM_HEADER, '' );
		return is_string( $value ) && '' === self::validate_header_name( $value ) ? $value : '';
	}

	public static function trust_cloudflare(): bool {
		return 'yes' === get_option( self::OPTION_TRUST_CLOUDFLARE, 'no' );
	}

	public static function turnstile_uses_detected_ip(): bool {
		return 'yes' === get_option( self::OPTION_TURNSTILE_USE, 'no' );
	}

	/**
	 * 已存的信任清單（解析過；不合法的行略過，存檔時本來就會擋下）。
	 *
	 * @return array<int, array{0:string,1:int}>
	 */
	public static function get_trusted(): array {
		$raw = get_option( self::OPTION_TRUSTED_PROXIES, '' );
		return self::parse_list( is_string( $raw ) ? $raw : '' )['ranges'];
	}

	/**
	 * 這個請求用的設定。
	 *
	 * @return array{source:string,custom_header:string,trusted:array,trust_cloudflare:bool}
	 */
	public static function settings(): array {
		return [
			'source'           => self::get_source(),
			'custom_header'    => self::get_custom_header(),
			'trusted'          => self::get_trusted(),
			'trust_cloudflare' => self::trust_cloudflare(),
		];
	}

	/* ─────────────────────────── 偵測 ─────────────────────────── */

	/**
	 * 這個請求的訪客 IP（同一個請求只算一次）。
	 *
	 * @return array{ip:string,from_header:bool,source:string}
	 *   ip：正規化過的位址，不合法時是空字串；from_header：是不是從標頭或 filter 取的；source：診斷用。
	 */
	public static function resolve(): array {
		if ( null === self::$cache ) {
			self::$cache = self::resolve_with( self::settings(), $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 每個值在 resolve_with 裡驗證
		}
		return self::$cache;
	}

	/**
	 * 用指定的設定與伺服器變數跑一次偵測（給 resolve()、設定頁的診斷、WP-CLI 用）。
	 *
	 * @param array{source:string,custom_header:string,trusted:array,trust_cloudflare:bool} $settings
	 * @param array<string, mixed>                                                           $server
	 * @return array{ip:string,from_header:bool,source:string,bad_segment?:int}
	 */
	public static function resolve_with( array $settings, array $server, bool $apply_filter = true ): array {
		$remote = self::normalize( self::server_string( $server, 'REMOTE_ADDR' ) );
		if ( '' === $remote ) {
			// 伺服器給不出合法的 REMOTE_ADDR：不讀任何標頭，但照樣經過 filter，整合的人可以用文件寫的 filter 補上（實作審查 A 線 P3-10）。
			$result = [ 'ip' => '', 'from_header' => false, 'source' => 'remote_addr_invalid' ];
			return $apply_filter ? self::apply_ip_filter( $result ) : $result;
		}

		$result = [ 'ip' => $remote, 'from_header' => false, 'source' => 'remote_addr' ];
		$source = in_array( $settings['source'] ?? '', self::SOURCES, true ) ? $settings['source'] : 'auto';

		if ( 'auto' === $source || 'cf_connecting_ip' === $source ) {
			if ( ! empty( $settings['trust_cloudflare'] ) && self::in_cloudflare_ranges( $remote ) && ! self::is_foreign_worker_with( $server ) ) {
				$cf = self::normalize_single( self::server_string( $server, 'HTTP_CF_CONNECTING_IP' ) );
				if ( '' !== $cf ) {
					$result = [ 'ip' => $cf, 'from_header' => true, 'source' => 'cf_connecting_ip' ];
				}
			}
		} elseif ( 'x_forwarded_for' === $source || 'custom' === $source ) {
			$key = 'x_forwarded_for' === $source ? 'HTTP_X_FORWARDED_FOR' : self::header_server_key( (string) ( $settings['custom_header'] ?? '' ) );
			if ( '' !== $key && self::is_trusted_with( $remote, $settings ) && array_key_exists( $key, $server ) ) {
				$scan = self::scan_chain( self::server_string( $server, $key ), $settings );
				if ( '' !== $scan['ip'] ) {
					$result = [ 'ip' => $scan['ip'], 'from_header' => true, 'source' => $source . ':' . $scan['position'] ];
				} else {
					$result['source']      = 'chain_stopped';
					$result['bad_segment'] = $scan['position'];
				}
			}
		} elseif ( 'x_real_ip' === $source ) {
			if ( self::is_trusted_with( $remote, $settings ) ) {
				$real = self::normalize_single( self::server_string( $server, 'HTTP_X_REAL_IP' ) );
				if ( '' !== $real ) {
					$result = [ 'ip' => $real, 'from_header' => true, 'source' => 'x_real_ip' ];
				}
			}
		}

		return $apply_filter ? self::apply_ip_filter( $result ) : $result;
	}

	/**
	 * @param array{ip:string,from_header:bool,source:string,bad_segment?:int} $result
	 * @return array{ip:string,from_header:bool,source:string,bad_segment?:int}
	 */
	private static function apply_ip_filter( array $result ): array {
		/**
		 * 訪客 IP（v1.6.0）。回傳合法、而且跟偵測結果不一樣的位址時採用，標成「從標頭或 filter 來的」，
		 * 登入次數限制照它自己的 IP 鍵計數；不合法就丟掉。REMOTE_ADDR 不合法時也會呼叫（$ip 是空字串）。
		 *
		 * @since 1.6.0
		 *
		 * @param string $ip     偵測到的位址（不合法時是空字串）。
		 * @param array  $result 偵測的細節（ip、from_header、source）。
		 */
		$filtered = apply_filters( 'ys_admin_menu_client_ip', $result['ip'], $result );
		if ( is_string( $filtered ) && $filtered !== $result['ip'] ) {
			$candidate = self::normalize( $filtered );
			if ( '' !== $candidate ) {
				return [ 'ip' => $candidate, 'from_header' => true, 'source' => 'filter' ];
			}
		}
		return $result;
	}

	/**
	 * X-Forwarded-For／自訂標頭：從最右邊往左讀。
	 *
	 * - 超過 1024 字元時只取最右邊 1024 字元，用逗號切段，最多看最右邊 20 段。
	 * - 這一段不合法（空字串、亂碼、被截斷的半段）→ 停下，結果用 REMOTE_ADDR（回傳空的 ip 與第幾段）。
	 * - 這一段在信任裡 → 跳過，繼續往左。第一個不在信任裡的合法位址就是訪客。
	 * - 一路都是信任、而且沒有被打斷才走到最左邊 → 取最左邊那個。
	 *
	 * @param array{trusted:array,trust_cloudflare:bool} $settings
	 * @return array{ip:string,position:int} position：從右邊數第幾段（找到的那一段，或停下的那一段）。
	 */
	public static function scan_chain( string $value, array $settings ): array {
		$cut = strlen( $value ) > self::HEADER_MAX;
		if ( $cut ) {
			$value = substr( $value, -self::HEADER_MAX );
		}
		$parts = explode( ',', $value );
		if ( $cut ) {
			// 被截斷的那一段（最左邊）一律當成不合法：切剩的半段有時剛好也是合法的位址（例如 10.0.0.9 切成 0.0.0.9）。
			$parts[0] = '';
		}
		$parts = array_slice( $parts, -self::SEGMENTS_MAX );

		$position = 0;
		$leftmost = '';
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			++$position;
			$ip = self::normalize( $parts[ $i ] );
			if ( '' === $ip ) {
				return [ 'ip' => '', 'position' => $position ];
			}
			if ( self::is_trusted_with( $ip, $settings ) ) {
				$leftmost = $ip;
				continue;
			}
			return [ 'ip' => $ip, 'position' => $position ];
		}
		return [ 'ip' => $leftmost, 'position' => $position ];
	}

	/* ─────────────────────────── 位址的驗證與正規化 ─────────────────────────── */

	/**
	 * 一個位址的正規化：去掉前後空白與 tab、去掉 port 與中括號、驗證、轉成標準寫法（IPv6 小寫、零壓縮），
	 * IPv4 對應的 IPv6（二進位前綴 ::ffff:0:0/96，十六進位寫法也算）轉回 IPv4。不合法回空字串。
	 *
	 * NAT64 的標準前綴 64:ff9b::/96（RFC 6052）也轉回內嵌的 IPv4（安全審查 B 線 P3-2）：這個前綴保留給 IPv4／IPv6 轉換，
	 * 裡面每個位址都代表一個 IPv4 訪客。只有 IPv6 的主機前面用 NAT64 轉進 IPv4 訪客時，不轉的話所有 IPv4 訪客會落在
	 * 同一個 /64 鍵（1 倍門檻、會逐次加長），一個人錯 10 次就鎖住所有 IPv4 訪客。轉回之後鍵、白名單、信任的代理、
	 * overflow 分桶（/24）、內部位址的分類都照那個 IPv4。營運商自己網段的 NAT64 前綴分辨不出來，寫在已知限制
	 * （用 ys_admin_menu_client_ip filter 換回）。
	 *
	 * @param mixed $value
	 */
	public static function normalize( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value, " \t" );
		if ( '' === $value || strlen( $value ) > 100 ) {
			return '';
		}
		if ( 1 === preg_match( '/^\[([^\]]+)\](?::\d{1,5})?$/', $value, $m ) ) {
			$value = $m[1];
		} elseif ( 1 === preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $value, $m ) ) {
			$value = $m[1];
		}
		if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return ''; // 帶介面代碼（fe80::1%eth0）也在這裡被擋下
		}
		$bin = inet_pton( $value );
		if ( false === $bin ) {
			return '';
		}
		if ( 16 === strlen( $bin ) && ( 0 === strncmp( $bin, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) || 0 === strncmp( $bin, "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ), 12 ) ) ) {
			$bin = substr( $bin, 12 ); // ::ffff:0:0/96（IPv4 對應）與 64:ff9b::/96（NAT64）：內嵌的 IPv4
		}
		$out = inet_ntop( $bin );
		return is_string( $out ) ? $out : '';
	}

	/** 只能是一個位址的標頭（CF-Connecting-IP、X-Real-IP）：有逗號就不收。 */
	private static function normalize_single( string $value ): string {
		return str_contains( $value, ',' ) ? '' : self::normalize( $value );
	}

	/**
	 * 解析一個 IP 或 CIDR。主機位元會被遮掉；IPv4 與 IPv6 的前綴長度各自驗證。
	 *
	 * @return null|array{0:string,1:int} [網段的二進位, 前綴長度]
	 */
	public static function parse_cidr( string $value ): ?array {
		$value = trim( $value, " \t" );
		$parts = explode( '/', $value );
		if ( count( $parts ) > 2 ) {
			return null;
		}
		$ip = self::normalize( $parts[0] );
		if ( '' === $ip || str_contains( $parts[0], '[' ) || 1 === preg_match( '/^\d{1,3}(?:\.\d{1,3}){3}:/', $parts[0] ) ) {
			return null;
		}
		$bin = inet_pton( $ip );
		$max = 8 * strlen( (string) $bin );
		if ( 2 === count( $parts ) ) {
			if ( 1 !== preg_match( '/^\d{1,3}$/', $parts[1] ) ) {
				return null;
			}
			$prefix = (int) $parts[1];
			if ( $prefix > $max ) {
				return null;
			}
		} else {
			$prefix = $max;
		}
		return [ self::mask( (string) $bin, $prefix ), $prefix ];
	}

	/** 二進位位址只留前 $prefix 個位元。 */
	private static function mask( string $bin, int $prefix ): string {
		$out = '';
		$len = strlen( $bin );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits = max( 0, min( 8, $prefix - 8 * $i ) );
			$out .= chr( ord( $bin[ $i ] ) & ( 0xff << ( 8 - $bits ) ) & 0xff );
		}
		return $out;
	}

	/**
	 * 位址在不在網段裡（IPv4 與 IPv6 不互相比）。
	 *
	 * @param array{0:string,1:int} $range
	 */
	public static function in_range( string $ip, array $range ): bool {
		$bin = inet_pton( $ip );
		if ( false === $bin || strlen( $bin ) !== strlen( $range[0] ) ) {
			return false;
		}
		return self::mask( $bin, $range[1] ) === $range[0];
	}

	/**
	 * @param array<int, array{0:string,1:int}> $ranges
	 */
	public static function in_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 解析「一行一個 IP 或 CIDR」的清單（信任的代理、白名單共用）。
	 *
	 * 回傳解析出的網段、不合法的行（第幾行）、太寬而拒收的行、寬但收下要警告的行。
	 * 太寬：IPv4 短於 /8、IPv6 短於 /32（Cloudflare 的 2a06:98c0::/29 例外）。公開位址的 IPv4 寬於 /16 時收下但警告。
	 *
	 * @return array{ranges:array<int, array{0:string,1:int}>, lines:string[], invalid:int[], too_wide:int[], wide:int[], too_many:bool}
	 */
	public static function parse_list( string $raw ): array {
		$out   = [ 'ranges' => [], 'lines' => [], 'invalid' => [], 'too_wide' => [], 'wide' => [], 'too_many' => false ];
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$count = 0;
		foreach ( (array) $lines as $index => $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ++$count > self::TRUSTED_MAX_LINES ) {
				$out['too_many'] = true;
				break;
			}
			$range = self::parse_cidr( $line );
			if ( null === $range ) {
				$out['invalid'][] = $index + 1;
				continue;
			}
			$v6 = 16 === strlen( $range[0] );
			if ( ( ! $v6 && $range[1] < 8 ) || ( $v6 && $range[1] < 32 && self::canonical_cidr( $range ) !== self::WIDE_IPV6_EXCEPTION ) ) {
				$out['too_wide'][] = $index + 1;
				continue;
			}
			if ( ! $v6 && $range[1] < 16 && ! self::is_internal( (string) inet_ntop( $range[0] ) ) ) {
				$out['wide'][] = $index + 1;
			}
			$out['ranges'][] = $range;
			$out['lines'][]  = self::canonical_cidr( $range );
		}
		return $out;
	}

	/**
	 * 網段的標準寫法（單一位址不帶前綴長度）。
	 *
	 * @param array{0:string,1:int} $range
	 */
	public static function canonical_cidr( array $range ): string {
		$ip = (string) inet_ntop( $range[0] );
		return $range[1] === 8 * strlen( $range[0] ) ? $ip : $ip . '/' . $range[1];
	}

	/* ─────────────────────────── 位址的分類 ─────────────────────────── */

	/** 內部或保留位址（只用來決定共用桶與診斷提示，不代表信任）。 */
	public static function is_internal( string $ip ): bool {
		static $ranges = null;
		if ( null === $ranges ) {
			$ranges = array_values( array_filter( array_map( [ self::class, 'parse_cidr' ], self::INTERNAL_RANGES ) ) );
		}
		$ip = self::normalize( $ip );
		return '' !== $ip && self::in_any( $ip, $ranges );
	}

	/**
	 * 內建的 Cloudflare 範圍（加上 filter 覆寫）。
	 *
	 * @return array<int, array{0:string,1:int}>
	 */
	public static function cloudflare_ranges(): array {
		if ( null === self::$cloudflare ) {
			$data = require __DIR__ . '/data/cloudflare-ips.php';
			$list = array_merge( (array) ( $data['ipv4'] ?? [] ), (array) ( $data['ipv6'] ?? [] ) );
			/**
			 * Cloudflare 的 IP 範圍（v1.6.0）。預設是外掛內建、2026-10-20 核對的 15＋7 段。
			 *
			 * @since 1.6.0
			 *
			 * @param string[] $list CIDR 清單。
			 */
			$list             = apply_filters( 'ys_admin_menu_ip_cloudflare_ranges', $list );
			self::$cloudflare = array_values( array_filter( array_map( static fn( $c ) => is_string( $c ) ? self::parse_cidr( $c ) : null, (array) $list ) ) );
		}
		return self::$cloudflare;
	}

	/** 內建清單的核對日期。 */
	public static function cloudflare_checked(): string {
		$data = require __DIR__ . '/data/cloudflare-ips.php';
		return (string) ( $data['checked'] ?? '' );
	}

	public static function in_cloudflare_ranges( string $ip ): bool {
		$ip = self::normalize( $ip );
		return '' !== $ip && self::in_any( $ip, self::cloudflare_ranges() );
	}

	/**
	 * 信任的代理：清單裡的，加上打開信任 Cloudflare 時的 Cloudflare 範圍。內部位址不會自動被信任。
	 *
	 * @param array{trusted:array,trust_cloudflare:bool} $settings
	 */
	public static function is_trusted_with( string $ip, array $settings ): bool {
		if ( self::in_any( $ip, (array) ( $settings['trusted'] ?? [] ) ) ) {
			return true;
		}
		return ! empty( $settings['trust_cloudflare'] ) && self::in_cloudflare_ranges( $ip );
	}

	/** 用已存的設定判斷。 */
	public static function is_trusted_proxy( string $ip ): bool {
		$ip = self::normalize( $ip );
		return '' !== $ip && self::is_trusted_with( $ip, self::settings() );
	}

	/**
	 * 這個請求是不是外站的 Cloudflare Worker：帶 CF-Worker 標頭，而且值不是本站的 zone。
	 * 本站的 zone：本站的主機（home_url、siteurl，加上 filter ys_admin_menu_ip_own_domains）有一個等於這個值，
	 * 或以「. 加這個值」結尾。值的格式不對（不是主機名稱、沒有點、超過 253 字元）一律當成外站。
	 */
	public static function is_foreign_worker(): bool {
		return self::is_foreign_worker_with( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 在裡面驗證
	}

	/**
	 * @param array<string, mixed> $server
	 */
	public static function is_foreign_worker_with( array $server ): bool {
		$zone = strtolower( trim( self::server_string( $server, 'HTTP_CF_WORKER' ) ) );
		if ( '' === $zone ) {
			return false;
		}
		if ( strlen( $zone ) > 253 || ! str_contains( $zone, '.' ) || 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $zone ) ) {
			return true;
		}
		foreach ( self::own_domains() as $host ) {
			if ( $host === $zone || str_ends_with( $host, '.' . $zone ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 本站的主機（小寫）：home_url、siteurl，加上 filter ys_admin_menu_ip_own_domains。只給 CF-Worker 的 zone 規則用。
	 *
	 * @return string[]
	 */
	public static function own_domains(): array {
		$hosts = [];
		foreach ( [ home_url(), site_url() ] as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		/**
		 * 本站自己的網域（v1.6.0），給 CF-Worker 的 zone 規則用：CF-Worker 的值等於其中一個，或是其中一個的上層網域，
		 * 就算本站自己的 Worker。
		 *
		 * @since 1.6.0
		 *
		 * @param string[] $hosts 預設是 home_url 與 siteurl 的主機。
		 */
		$hosts = apply_filters( 'ys_admin_menu_ip_own_domains', $hosts );
		return array_values( array_unique( array_filter( array_map( static fn( $h ) => is_string( $h ) ? strtolower( trim( $h ) ) : '', (array) $hosts ) ) ) );
	}

	/* ─────────────────────────── 鍵 ─────────────────────────── */

	/**
	 * 登入次數限制用的鍵：IPv4 是完整位址；IPv6 是 /64 網段（filter ys_admin_menu_login_guard_ipv6_prefix 可改成 48、56、128）。
	 */
	public static function key( string $ip ): string {
		$ip = self::normalize( $ip );
		if ( '' === $ip ) {
			return '';
		}
		if ( ! str_contains( $ip, ':' ) ) {
			return $ip;
		}
		/**
		 * IPv6 以多大的網段當成一個人（v1.6.0）。只收 48、56、64、128，其他值照 64。
		 *
		 * @since 1.6.0
		 *
		 * @param int $prefix 預設 64。
		 */
		$prefix = (int) apply_filters( 'ys_admin_menu_login_guard_ipv6_prefix', 64 );
		if ( ! in_array( $prefix, [ 48, 56, 64, 128 ], true ) ) {
			$prefix = 64;
		}
		return self::network( $ip, $prefix );
	}

	/**
	 * 位址所在的網段，寫成「網段/前綴長度」（前綴長度等於位址長度時只寫位址）。
	 */
	public static function network( string $ip, int $prefix ): string {
		$ip = self::normalize( $ip );
		if ( '' === $ip ) {
			return '';
		}
		$bin = (string) inet_pton( $ip );
		$max = 8 * strlen( $bin );
		return self::canonical_cidr( [ self::mask( $bin, max( 0, min( $max, $prefix ) ) ), max( 0, min( $max, $prefix ) ) ] );
	}

	/* ─────────────────────────── 自訂標頭 ─────────────────────────── */

	/**
	 * 自訂標頭名稱的檢查。合法回空字串，不合法回原因代碼。
	 */
	public static function validate_header_name( string $name ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,64}$/', $name ) ) {
			return 'format';
		}
		$lower = strtolower( $name );
		if ( in_array( $lower, self::REJECTED_HEADERS, true ) || str_starts_with( $lower, 'content-' ) ) {
			return 'reserved';
		}
		return '';
	}

	/** 自訂標頭名稱對應到 $_SERVER 的鍵：HTTP_ 加大寫、連字號換底線。名稱不合法回空字串。 */
	public static function header_server_key( string $name ): string {
		return '' === $name || '' !== self::validate_header_name( $name ) ? '' : 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
	}

	/* ─────────────────────────── 診斷 ─────────────────────────── */

	/**
	 * 設定頁的診斷：這次請求各來源的值、每種選項會得到什麼、提醒。只反映管理員自己這一次請求。
	 *
	 * @param array<string, mixed>|null $server 預設是這次請求的 $_SERVER。
	 * @return array<string, mixed>
	 */
	public static function diagnose( ?array $server = null ): array {
		$server   = null === $server ? $_SERVER : $server; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 只讀，輸出時跳脫
		$settings = self::settings();
		$remote   = self::normalize( self::server_string( $server, 'REMOTE_ADDR' ) );
		$custom   = $settings['custom_header'];

		$values = [
			'remote_addr'      => self::server_string( $server, 'REMOTE_ADDR' ),
			'cf_connecting_ip' => self::server_string( $server, 'HTTP_CF_CONNECTING_IP' ),
			'cf_worker'        => self::server_string( $server, 'HTTP_CF_WORKER' ),
			'cf_ray'           => self::server_string( $server, 'HTTP_CF_RAY' ),
			'x_forwarded_for'  => self::server_string( $server, 'HTTP_X_FORWARDED_FOR' ),
			'x_real_ip'        => self::server_string( $server, 'HTTP_X_REAL_IP' ),
			'custom'           => '' !== $custom ? self::server_string( $server, self::header_server_key( $custom ) ) : '',
		];

		// 每種選項在「信任 Cloudflare 打開／沒打開」時各會得到什麼（用已存的信任清單）。
		$results = [];
		foreach ( self::SOURCES as $source ) {
			foreach ( [ 'no' => false, 'yes' => true ] as $flag => $trust ) {
				$variant = array_merge( $settings, [ 'source' => $source, 'trust_cloudflare' => $trust ] );
				$r       = self::resolve_with( $variant, $server, false );
				$results[ $source ][ $flag ] = [
					'ip'          => $r['ip'],
					'from_header' => $r['from_header'],
					'source'      => $r['source'],
					'bad_segment' => $r['bad_segment'] ?? 0,
					'reason'      => self::explain( $variant, $server, $remote, $r ),
				];
			}
		}

		$current  = self::resolve_with( $settings, $server, true );
		$hints    = [];
		$internal = '' !== $remote && self::is_internal( $remote );
		$is_cf    = '' !== $remote && self::in_cloudflare_ranges( $remote );
		$proxied  = '' !== $values['x_forwarded_for'] || '' !== $values['x_real_ip'] || ( '' !== $custom && '' !== $values['custom'] );
		if ( $internal && $proxied && ! self::is_trusted_with( $remote, $settings ) ) {
			$hints[] = 'internal_with_proxy_headers';
		}
		if ( $is_cf && ! $settings['trust_cloudflare'] ) {
			$hints[] = 'cloudflare_without_trust';
		}
		if ( in_array( $settings['source'], [ 'x_forwarded_for', 'x_real_ip', 'custom' ], true ) && '' !== $remote && ! self::is_trusted_with( $remote, $settings ) ) {
			$hints[] = 'header_mode_untrusted_remote';
		}
		if ( 'chain_stopped' === $current['source'] ) {
			$hints[] = 'chain_invalid_segment';
		}
		if ( '' !== $values['cf_ray'] && ! $is_cf ) {
			$hints[] = 'cf_ray_not_in_list';
		}
		if ( 'custom' === $settings['source'] ) {
			$hints[] = 'custom_header_warning';
		}

		return [
			'settings'       => [
				'source'           => $settings['source'],
				'custom_header'    => $custom,
				'trust_cloudflare' => $settings['trust_cloudflare'],
			],
			'remote'         => $remote,
			'remote_internal' => $internal,
			'remote_cloudflare' => $is_cf,
			'remote_trusted' => '' !== $remote && self::is_trusted_with( $remote, $settings ),
			'values'         => $values,
			'results'        => $results,
			'current'        => $current,
			'hints'          => $hints,
			'cloudflare_checked' => self::cloudflare_checked(),
		];
	}

	/**
	 * 診斷用：這個選項為什麼得到這個結果（代碼，設定頁換成文字）。
	 *
	 * @param array{source:string,custom_header:string,trusted:array,trust_cloudflare:bool} $settings
	 * @param array<string, mixed>                                                           $server
	 * @param array{ip:string,from_header:bool,source:string}                               $result
	 */
	private static function explain( array $settings, array $server, string $remote, array $result ): string {
		if ( '' === $remote ) {
			return 'invalid_remote';
		}
		if ( $result['from_header'] ) {
			return 'header';
		}
		$trusted = self::is_trusted_with( $remote, $settings );
		switch ( $settings['source'] ) {
			case 'auto':
				if ( empty( $settings['trust_cloudflare'] ) || ! self::in_cloudflare_ranges( $remote ) ) {
					return 'remote';
				}
				return self::is_foreign_worker_with( $server ) ? 'foreign_worker' : 'header_missing';
			case 'cf_connecting_ip':
				if ( empty( $settings['trust_cloudflare'] ) ) {
					return 'cf_not_trusted';
				}
				if ( ! self::in_cloudflare_ranges( $remote ) ) {
					return 'not_cloudflare';
				}
				return self::is_foreign_worker_with( $server ) ? 'foreign_worker' : 'header_missing';
			case 'x_forwarded_for':
			case 'custom':
				if ( 'custom' === $settings['source'] && '' === self::header_server_key( (string) $settings['custom_header'] ) ) {
					return 'no_custom_header';
				}
				if ( ! $trusted ) {
					return 'not_trusted';
				}
				return 'chain_stopped' === $result['source'] ? 'chain_stopped' : 'header_missing';
			case 'x_real_ip':
				return $trusted ? 'header_missing' : 'not_trusted';
		}
		return 'remote';
	}

	/* ─────────────────────────── 小工具 ─────────────────────────── */

	/**
	 * @param array<string, mixed> $server
	 */
	private static function server_string( array $server, string $key ): string {
		if ( '' === $key || ! isset( $server[ $key ] ) || ! is_string( $server[ $key ] ) ) {
			return '';
		}
		return $server[ $key ];
	}
}
