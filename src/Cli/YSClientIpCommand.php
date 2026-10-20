<?php
/**
 * WP-CLI：訪客 IP 偵測（v1.6.0，設計 §3.2、§4.10）。都不寫入任何設定。
 *
 *   wp ys-admin-menu ip check [--remote-addr=<ip>] [--header="<名稱>: <值>[; <名稱>: <值>]"]
 *   wp ys-admin-menu ip cloudflare-check
 *
 * check 用指定的 REMOTE_ADDR 與標頭跑一次偵測，印出每種選項（信任 Cloudflare 打開／沒打開）會得到什麼，
 * 給管理員在改設定之前先試算。cloudflare-check 只在管理員手動執行時，下載 Cloudflare 公開的 IP 清單跟內建的比對，
 * 只印差異（內建清單隨外掛版本更新，不會自動下載或改信任）。
 *
 * @package YangSheep\AdminMenu\Cli
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Cli;

use YangSheep\AdminMenu\Security\YSClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * 訪客 IP 偵測的試算與 Cloudflare 清單比對（只讀）。
 */
final class YSClientIpCommand {

	/** Cloudflare 公開的 IP 清單。 */
	public const CLOUDFLARE_LISTS = [
		'ipv4' => 'https://www.cloudflare.com/ips-v4',
		'ipv6' => 'https://www.cloudflare.com/ips-v6',
	];

	/**
	 * 用指定的 REMOTE_ADDR 與標頭跑一次偵測，印出每種選項會得到什麼（不寫入任何設定）。
	 *
	 * ## OPTIONS
	 *
	 * [--remote-addr=<ip>]
	 * : 模擬的 REMOTE_ADDR。沒給時用 127.0.0.1。
	 *
	 * [--header=<header>]
	 * : 模擬的標頭，寫成「名稱: 值」，多個用分號隔開，例如 "X-Forwarded-For: 9.9.9.9, 10.0.0.5; CF-Worker: example.com"。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu ip check --remote-addr=10.0.0.2 --header="X-Forwarded-For: 9.9.9.9, 10.0.0.5"
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function check( $args, $assoc_args ): void {
		$server   = self::server( (string) ( $assoc_args['remote-addr'] ?? '127.0.0.1' ), (string) ( $assoc_args['header'] ?? '' ) );
		$settings = YSClientIp::settings();
		$rows     = [];
		foreach ( YSClientIp::SOURCES as $source ) {
			foreach ( [ false, true ] as $trust ) {
				$variant = array_merge( $settings, [ 'source' => $source, 'trust_cloudflare' => $trust ] );
				$r       = YSClientIp::resolve_with( $variant, $server, false );
				$current = $source === $settings['source'] && $trust === $settings['trust_cloudflare'];
				$rows[]  = [
					'選項'           => $source . ( $current ? '（目前的設定）' : '' ),
					'信任 Cloudflare' => $trust ? '是' : '否',
					'偵測到的 IP'     => '' !== $r['ip'] ? $r['ip'] : '（不合法）',
					'從標頭來的'      => $r['from_header'] ? '是' : '否',
					'怎麼來的'        => (string) $r['source'],
				];
			}
		}
		\WP_CLI::line( 'REMOTE_ADDR：' . YSLoginGuardCommand::clean( (string) $server['REMOTE_ADDR'] ) );
		foreach ( $server as $key => $value ) {
			if ( 'REMOTE_ADDR' !== $key ) {
				\WP_CLI::line( $key . '：' . YSLoginGuardCommand::clean( (string) $value ) );
			}
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		\WP_CLI::line( '信任的代理：' . ( [] === $settings['trusted'] ? '（沒有）' : count( $settings['trusted'] ) . ' 段' ) . '。這裡用的是已存的信任清單與自訂標頭名稱；不會改任何設定。' );
	}

	/**
	 * 下載 Cloudflare 公開的 IP 清單，跟外掛內建的比對，只印差異（不改設定、不改信任）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp ys-admin-menu ip cloudflare-check
	 *
	 * @subcommand cloudflare-check
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc_args
	 */
	public function cloudflare_check( $args, $assoc_args ): void {
		$data    = require dirname( __DIR__ ) . '/Security/data/cloudflare-ips.php';
		$builtin = array_merge( (array) ( $data['ipv4'] ?? [] ), (array) ( $data['ipv6'] ?? [] ) );
		$remote  = [];
		foreach ( self::CLOUDFLARE_LISTS as $url ) {
			$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				\WP_CLI::error( '下載失敗：' . $url );
			}
			foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_remote_retrieve_body( $response ) ) as $line ) {
				$range = YSClientIp::parse_cidr( trim( (string) $line ) );
				if ( null !== $range ) {
					$remote[] = YSClientIp::canonical_cidr( $range );
				}
			}
		}
		$builtin = array_values( array_unique( array_filter( array_map( static function ( $c ) {
			$r = is_string( $c ) ? YSClientIp::parse_cidr( $c ) : null;
			return null === $r ? '' : YSClientIp::canonical_cidr( $r );
		}, $builtin ) ) ) );
		if ( [] === $remote ) {
			\WP_CLI::error( '下載到的清單是空的，不比對。' );
		}
		$added   = array_values( array_diff( $remote, $builtin ) );
		$removed = array_values( array_diff( $builtin, $remote ) );
		\WP_CLI::line( '內建清單的核對日期：' . (string) ( $data['checked'] ?? '' ) );
		if ( [] === $added && [] === $removed ) {
			\WP_CLI::success( '內建清單跟 Cloudflare 公開的相同。' );
			return;
		}
		foreach ( $added as $cidr ) {
			\WP_CLI::line( '官方有、內建沒有：' . $cidr );
		}
		foreach ( $removed as $cidr ) {
			\WP_CLI::line( '內建有、官方沒有：' . $cidr );
		}
		\WP_CLI::warning( '清單有差異：請更新外掛；需要立刻調整時可以用 filter ys_admin_menu_ip_cloudflare_ranges。這個指令不會改任何設定。' );
	}

	/**
	 * 模擬的伺服器變數：REMOTE_ADDR 與標頭（名稱只收英數字與連字號，對應到 HTTP_ 加大寫、連字號換底線）。
	 *
	 * @return array<string,string>
	 */
	public static function server( string $remote, string $headers ): array {
		$server = [ 'REMOTE_ADDR' => trim( $remote ) ];
		foreach ( explode( ';', $headers ) as $pair ) {
			$pos = strpos( $pair, ':' );
			if ( false === $pos ) {
				continue;
			}
			$name  = trim( substr( $pair, 0, $pos ) );
			$value = trim( substr( $pair, $pos + 1 ) );
			if ( 1 !== preg_match( '/^[A-Za-z0-9-]{1,64}$/D', $name ) ) {
				continue;
			}
			$server[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] = substr( $value, 0, 2048 );
		}
		return $server;
	}
}
