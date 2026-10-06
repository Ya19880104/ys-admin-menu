<?php
/**
 * 上方工具列與選單規則同步。
 *
 * 選單被隱藏（或不在這個帳號的白名單裡）時，工具列上指向同一頁的連結也一併拿掉：
 * 例如隱藏「留言」之後，工具列的留言圖示點了只會得到 404，不如不顯示。
 *
 * 只處理「連到 wp-admin 選單頁」的節點。站名、帳號、登出這類結構性節點，以及編輯畫面
 * （post.php 等沒有對應選單項的頁面）不動。
 *
 * @package YangSheep\AdminMenu\Menu
 * @since   1.2.4
 */

namespace YangSheep\AdminMenu\Menu;

defined( 'ABSPATH' ) || exit;

final class YSAdminBarFilter {

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
		add_action( 'wp_before_admin_bar_render', [ self::class, 'prune' ], 999 );
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
}
