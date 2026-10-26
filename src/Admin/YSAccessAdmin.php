<?php
/**
 * v1.7.0：「選單與權限」的「外掛使用權限」分頁（誰能使用 YS Admin Menu；邏輯在 YSAccess）。
 *
 * 表單送 admin-post.php（action＝ys_admin_menu_save_access、自己的 nonce），存完回到這個分頁並帶提示代碼。
 *
 * @package YangSheep\AdminMenu\Admin
 * @since   1.7.0
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSAccessAdmin {

	public const TAB          = 'access';
	public const POST_ACTION  = 'ys_admin_menu_save_access';
	public const NONCE_ACTION = 'ys_admin_menu_save_access';

	/** 列出幾個有 manage_options 的帳號（一般站只有幾位；超過就提示用 WP-CLI）。 */
	private const LIST_LIMIT = 200;

	public static function register(): void {
		add_action( 'admin_post_' . self::POST_ACTION, [ self::class, 'handle_save' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => YSMenuPage::SLUG_PERMISSIONS, 'tab' => self::TAB ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * 分頁內容（YSPermissionAdmin::render() 已經驗過 can_manage()、印好頁首）。
	 *
	 * @param array<string, string> $tabs 分頁 → 名稱（照「選單與權限」其他分頁的順序）。
	 */
	public static function render_tab( array $tabs ): void {
		$saved     = YSAccess::get();
		$effective = YSAccess::effective();
		$me        = (int) get_current_user_id();
		$admins    = get_users(
			[
				'capability' => 'manage_options',
				'fields'     => [ 'ID', 'user_login', 'display_name', 'user_email' ],
				'orderby'    => 'ID',
				'number'     => self::LIST_LIMIT,
			]
		);
		$checked   = 'users' === $saved['mode'] ? $saved['user_ids'] : ( [] !== $saved['user_ids'] ? $saved['user_ids'] : [ $me ] );
		$notes     = isset( $_GET['notes'] ) && is_string( $_GET['notes'] ) ? array_filter( explode( ',', sanitize_text_field( wp_unslash( $_GET['notes'] ) ) ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只顯示提示
		$base_url  = admin_url( 'admin.php?page=' . YSMenuPage::SLUG_PERMISSIONS );
		?>
		<div class="ys-ec-permission-admin ysca-section ys-am-access">
			<nav class="ysca-wl-tabs" role="tablist" aria-label="權限設定分頁">
				<?php foreach ( $tabs as $key => $label ) :
					$is_active = ( self::TAB === $key );
					?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>" class="<?php echo esc_attr( 'ysca-wl-tab' . ( $is_active ? ' ysca-wl-tab--active' : '' ) ); ?>"
						role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( ! empty( $_GET['access_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>已儲存「外掛使用權限」。</p></div>
			<?php endif; ?>
			<?php if ( in_array( 'self_added', $notes, true ) ) : ?>
				<div class="notice notice-info"><p>清單裡一定要有你自己，已自動加上，免得你把自己鎖在外面。</p></div>
			<?php endif; ?>
			<?php if ( in_array( 'dropped', $notes, true ) ) : ?>
				<div class="notice notice-warning"><p>有帳號已經不存在或沒有管理網站的權限，沒有存進清單。</p></div>
			<?php endif; ?>
			<?php if ( $effective['reset_constant'] && 'users' === $saved['mode'] ) : ?>
				<div class="notice notice-warning"><p>wp-config.php 定義了 <code>YS_ADMIN_MENU_ACCESS_RESET</code>：目前所有管理員都能使用 YS 選單管理。救援完成後請拿掉這一行。</p></div>
			<?php elseif ( $effective['fallback'] && 'users' === $saved['mode'] ) : ?>
				<div class="notice notice-warning"><p>清單裡的帳號都已經不存在或沒有管理權限，目前暫時改回所有管理員都能使用。請重新勾選後儲存。</p></div>
			<?php endif; ?>

			<div class="ysca-card ysca-section-card">
				<h3><span class="dashicons dashicons-lock"></span> 誰能使用 YS 選單管理</h3>
				<p class="description">
					這裡只管「YS 選單管理」這幾頁與它們的設定（選單與權限、原生選單樣式、白牌設定、後台精簡），不影響側欄上的其他選單。
					沒被勾選的管理員在側欄看不到「YS 選單管理」，直接開設定頁或呼叫設定 API 也會被擋。
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ys-am-access__form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::POST_ACTION ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<fieldset class="ys-am-access__mode">
						<legend class="screen-reader-text">誰能使用</legend>
						<label><input type="radio" name="mode" value="capability" <?php checked( 'users' !== $saved['mode'] ); ?>> 所有能管理網站的管理員（預設）</label><br>
						<label><input type="radio" name="mode" value="users" <?php checked( 'users' === $saved['mode'] ); ?>> 只有下面勾選的帳號</label>
					</fieldset>
					<div class="ys-am-access__users" role="group" aria-label="可以使用 YS 選單管理的帳號">
						<?php foreach ( $admins as $u ) :
							$uid = (int) $u->ID;
							?>
							<label class="ys-am-access__user">
								<input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( (string) $uid ); ?>" <?php checked( in_array( $uid, $checked, true ) ); ?>>
								<strong><?php echo esc_html( (string) $u->display_name ); ?></strong>
								<span class="description"><?php echo esc_html( (string) $u->user_login . ' · ' . (string) $u->user_email . ' · ID ' . $uid ); ?></span>
								<?php if ( $uid === $me ) : ?><em>（你）</em><?php endif; ?>
							</label>
						<?php endforeach; ?>
						<?php if ( count( $admins ) >= self::LIST_LIMIT ) : ?>
							<p class="description">只列出前 <?php echo esc_html( (string) self::LIST_LIMIT ); ?> 位管理員；其他帳號請用 WP-CLI：<code>wp ys-admin-menu access allow &lt;帳號&gt;</code>。</p>
						<?php endif; ?>
					</div>
					<p class="description">只有勾「只有下面勾選的帳號」時清單才生效；清單裡一定要有你自己（沒勾會自動加上）。</p>
					<p>
						<label><input type="checkbox" name="hide_plugin_row" value="1" <?php checked( $saved['hide_plugin_row'] ); ?>> 在「外掛」清單對其他管理員隱藏 YS Admin Menu，並擋下他們停用、刪除本外掛</label>
					</p>
					<p class="description">
						救援：在主機上執行 <code>wp ys-admin-menu access reset</code>，或在 wp-config.php 加上 <code>define( 'YS_ADMIN_MENU_ACCESS_RESET', true );</code>。
						清單裡的帳號都被刪除或失去管理權限時，也會自動改回所有管理員都能使用。WP-CLI、主機面板與其他外掛管理工具不受這裡限制。
					</p>
					<p><button type="submit" class="button button-primary">儲存</button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/** admin-post：存「外掛使用權限」。 */
	public static function handle_save(): void {
		if ( ! YSAccess::can_manage() ) {
			wp_die( esc_html__( '權限不足。', 'ys-admin-menu' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );
		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- YSAccess::sanitize() 逐欄清理
		$post  = is_array( $post ) ? $post : [];
		$notes = YSAccess::save(
			[
				'mode'            => isset( $post['mode'] ) && is_string( $post['mode'] ) ? $post['mode'] : 'capability',
				'user_ids'        => isset( $post['user_ids'] ) && is_array( $post['user_ids'] ) ? $post['user_ids'] : [],
				'hide_plugin_row' => ! empty( $post['hide_plugin_row'] ),
			],
			(int) get_current_user_id()
		);
		wp_safe_redirect( self::url( array_filter( [ 'access_saved' => '1', 'notes' => implode( ',', $notes ) ] ) ) );
		exit;
	}
}
