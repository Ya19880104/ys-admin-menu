<?php
/**
 * v1.11.0：給其他外掛用的對外介面（例如 YS CART）。
 *
 * 其他外掛要問本外掛「這個帳號直接開網址進不進得去、側欄看不看得到、能不能使用 YS 選單管理、自訂登入頁有沒有開」，
 * 請用這裡的方法，不要直接叫內部類別，也不要讀寫本外掛的 option。使用前先確認：
 *   class_exists( '\YangSheep\AdminMenu\Api\YSAdminMenuApi' )，再看 API_VERSION。
 *
 * 承諾（API_VERSION 1）：
 *   - 方法名稱、參數、回傳型別與意思不改；要改就加新方法、升 API_VERSION，舊方法留著。
 *   - 每個方法自己接住例外，出錯時回比較嚴的答案（false：進不去、看不到、不能管、沒開），不讓呼叫端 fatal。
 *     參數型別不對（例如傳 null 給 string）時 PHP 在進方法之前就拋 TypeError，方法接不到。
 *   - url_allows()、sidebar_allows()：空字串、帳號 0 或認不得的寫法一律回 false（fail closed，比舊方法嚴）。
 *   - 授權「目前這個請求」請用 can_manage()（不帶參數）；帶帳號 ID 的問法只用來顯示或試算，不要拿請求裡的 ID 來授權。
 *   - 只讀：不寫設定、不掛 hook。
 *   - 列上「誰看得到」（access）的格式版本是 ACCESS_FORMAT_VERSION，認得的規則是 ACCESS_FEATURES；格式改版時升版本，
 *     舊格式照樣讀得懂。
 *   - 停用本外掛時，選單設定原樣鏡像到 ys_ec_menu_config（YS CART 在沒有本外掛時讀那一份）；每次存檔也同步一份。
 *
 * 供 YS CART 直接呼叫的五個方法與一個常數照舊可用、簽章不改：YSMenuRouter::url_allows()、YSMenuRouter::get_config()、
 * YSMenuRouter::current_user_can_access_admin_slug()、YSAccess::can_manage()、YSLoginPage::is_enabled()（YS CART 3.0.1 起就在呼叫）、
 * YS_ADMIN_MENU_OWNS_MENU_ROUTING。
 *
 * @package YangSheep\AdminMenu\Api
 * @since   1.11.0
 */

namespace YangSheep\AdminMenu\Api;

use YangSheep\AdminMenu\Access\YSAccess;
use YangSheep\AdminMenu\Login\YSLoginPage;
use YangSheep\AdminMenu\Menu\YSMenuRouter;

defined( 'ABSPATH' ) || exit;

final class YSAdminMenuApi {

	/** 介面版本（1.11.0 起）。 */
	public const API_VERSION = 1;

	/** 列上 access 欄位的格式版本（＝YSMenuRouter::ACCESS_FORMAT_VERSION，YSMenuRouter::sanitize_access() 寫的 v）。 */
	public const ACCESS_FORMAT_VERSION = YSMenuRouter::ACCESS_FORMAT_VERSION;

	/**
	 * 這個版本的 access 認得的規則（YSMenuRouter::sanitize_access() 會保留，判斷時都會用到）：
	 *   - allow.roles、allow.users：「只有」這些角色、帳號（others 是 deny）。
	 *   - deny.roles、deny.users：「除了」這些角色、帳號。
	 *   - others:inherit｜others:deny：名單以外的人照一般規則，或一律擋。
	 *   - super_admin:exempt｜super_admin:apply：管理員不受這一列限制，或也套用（帳號被指定時一律照指定的）。
	 *   - scope:block｜scope:menu_only：側欄、工具列與直接網址都擋，或只藏選單（網址照樣進得去）。
	 * 之後的版本加新的規則時，往後加，不改既有的字。
	 */
	public const ACCESS_FEATURES = [
		'allow.roles',
		'allow.users',
		'deny.roles',
		'deny.users',
		'others:inherit',
		'others:deny',
		'super_admin:exempt',
		'super_admin:apply',
		'scope:block',
		'scope:menu_only',
	];

	private function __construct() {}

	/** 本外掛的版本（YS_ADMIN_MENU_VERSION）。 */
	public static function version(): string {
		return defined( 'YS_ADMIN_MENU_VERSION' ) ? (string) YS_ADMIN_MENU_VERSION : '';
	}

	/**
	 * 本外掛是不是負責後台選單的路由（側欄與直接網址的規則）。外掛主檔載入時就宣告 YS_ADMIN_MENU_OWNS_MENU_ROUTING；
	 * 這個方法讀它，結果跟直接看常數一樣。
	 */
	public static function owns_menu_routing(): bool {
		try {
			return defined( 'YS_ADMIN_MENU_OWNS_MENU_ROUTING' ) && true === constant( 'YS_ADMIN_MENU_OWNS_MENU_ROUTING' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * 某個帳號直接開這一頁的網址，本外掛會不會放行（跟本外掛擋網址用同一個判斷，照目前已存的設定）。
	 *
	 * v1.11.0：先換成擋網址用的頁面寫法（guard_page_of()），所以這些寫法都可以：外掛頁的代稱 X、admin.php?page=X、
	 * wp-admin 底下的檔名（可帶參數，例如 edit.php?post_type=X）、edit-tags.php?…（參數順序不拘；不帶 taxonomy 是標籤），前面帶網站網址、
	 * /wp-admin/ 或 ./ 也可以，&amp; 先還原。空字串、帳號 0 或認不得的寫法一律回 false。
	 *
	 * @param string $page    頁面的寫法（見上）。
	 * @param int    $user_id 帳號 ID；0 或負數回 false。
	 * @return bool true＝進得去；出錯時回 false。
	 */
	public static function url_allows( string $page, int $user_id ): bool {
		try {
			$page = self::guard_page_of( $page );
			if ( '' === $page || $user_id <= 0 ) {
				return false;
			}
			return YSMenuRouter::url_allows( $page, YSMenuRouter::get_config(), $user_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * 某個帳號在側欄看不看得到這個選單（本外掛的規則：隱藏、角色、僅限本人、使用者覆寫、誰看得到；只藏選單也算看不到）。
	 *
	 * @param string      $slug    選單的 slug（$menu[2]／$submenu[…][2] 的寫法）；空字串回 false。
	 * @param int         $user_id 帳號 ID；0 或負數回 false。
	 * @param string|null $parent  子選單在側欄上的上層；頂層給 null。
	 * @return bool true＝看得到；出錯時回 false。
	 */
	public static function sidebar_allows( string $slug, int $user_id, ?string $parent = null ): bool {
		try {
			if ( '' === trim( $slug ) || $user_id <= 0 ) {
				return false;
			}
			return YSMenuRouter::current_user_can_access_admin_slug( $slug, $user_id, $parent );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * v1.11.0：url_allows() 收到的頁面寫法 → 擋網址用的寫法（YSMenuRouter::url_allows() 認的那一種，跟真的請求算出來的一樣）。
	 *
	 *   - 前面的網站網址、/wp-admin/、./ 拿掉，&amp; 先還原，# 後面不看。
	 *   - wp-admin 底下的檔名（tools.php、ADMIN.PHP 這種大寫也算）：帶 page= 的是外掛頁 X；edit-tags.php 照 taxonomy（不帶是標籤）
	 *     與 post_type 換成 YSMenuRouter::taxonomy_page_key()；edit.php、post-new.php 帶 post_type（post 以外）時加上 ?post_type=；
	 *     其他參數不看，跟擋網址時一樣。
	 *   - 沒有 .php 檔名的是外掛頁的代稱 X（選單的 slug；帶 & 的照 WordPress 組網址的方式取 page 的值）。
	 *   - 認不得（空字串、page 是空的、只有參數、wp-admin 以外的路徑帶參數、javascript: 這種其他通訊協定）回空字串。
	 */
	private static function guard_page_of( string $page ): string {
		$page = trim( function_exists( 'wp_specialchars_decode' ) ? (string) wp_specialchars_decode( $page ) : str_replace( '&amp;', '&', $page ) );
		$page = (string) preg_replace( '#^https?://[^/?\#]*#i', '', $page );
		if ( '' === $page || 1 === preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $page ) ) {
			return '';
		}
		$page = (string) preg_replace( '/#.*$/s', '', $page );
		// 網站裝在子目錄（/blog/wp-admin/…）也一樣：取 wp-admin/ 後面的部分（只看 ? 之前的路徑）。
		if ( 1 === preg_match( '#^(?:[^?]*/)?wp-admin/+(.*)$#is', $page, $m ) ) {
			$page = $m[1];
		}
		$page = (string) preg_replace( '#^/*(?:\./)?#', '', $page );
		if ( '' === $page ) {
			return '';
		}
		$parts = explode( '?', $page, 2 );
		$file  = $parts[0];
		$query = $parts[1] ?? '';
		if ( 1 === preg_match( '/^[a-z0-9_\-]+\.php$/i', $file ) ) {
			$file = strtolower( $file );
			$args = [];
			if ( '' !== $query ) {
				wp_parse_str( $query, $args );
			}
			if ( array_key_exists( 'page', $args ) ) {
				return is_string( $args['page'] ) ? trim( str_replace( '\\', '/', $args['page'] ), '/' ) : '';
			}
			$post_type = is_string( $args['post_type'] ?? null ) ? sanitize_key( $args['post_type'] ) : '';
			if ( 'edit-tags.php' === $file ) {
				$taxonomy = is_string( $args['taxonomy'] ?? null ) ? sanitize_key( $args['taxonomy'] ) : '';
				return YSMenuRouter::taxonomy_page_key( '' !== $taxonomy ? $taxonomy : 'post_tag', $post_type );
			}
			if ( '' !== $post_type && 'post' !== $post_type && in_array( $file, [ 'edit.php', 'post-new.php' ], true ) ) {
				return $file . '?post_type=' . $post_type;
			}
			return $file;
		}
		if ( '' !== $query ) {
			return '';
		}
		if ( 1 === preg_match( '#^[A-Za-z0-9_\-./]+$#', $file ) ) {
			return trim( $file, '/' );
		}
		$args = [];
		wp_parse_str( 'page=' . $file, $args );
		return is_string( $args['page'] ?? null ) ? trim( str_replace( '\\', '/', $args['page'] ), '/' ) : '';
	}

	/**
	 * 能不能使用 YS 選單管理（設定頁、設定 API；照「外掛使用權限」）。
	 *
	 * @param int|null $user_id null＝目前登入的人。
	 * @return bool 出錯時回 false。
	 */
	public static function can_manage( ?int $user_id = null ): bool {
		try {
			return null === $user_id ? YSAccess::can_manage() : YSAccess::user_can_manage( $user_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * 本外掛的自訂登入頁有沒有開（開著時 wp-login.php 的外觀由本外掛負責）。
	 *
	 * @return bool 出錯時回 false。
	 */
	public static function login_page_enabled(): bool {
		try {
			return YSLoginPage::is_enabled();
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
