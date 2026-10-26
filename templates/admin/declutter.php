<?php
/**
 * v1.7.0：「後台精簡」設定頁（YSDeclutterAdmin::render()）。
 *
 * @package YangSheep\AdminMenu
 */

use YangSheep\AdminMenu\Admin\YSDeclutterAdmin;
use YangSheep\AdminMenu\Admin\YSMenuPage;

defined( 'ABSPATH' ) || exit;

/** @var array{version:int,mode:string,post_types:string[],keep_existing:bool} $comments */
/** @var array<string,string> $types */
/** @var array{version:int,hidden:string[],hide_welcome:bool} $dashboard */
/** @var array{items:array<string,array{title:string,context:string,priority:string}>,updated_at:int} $seen */
/** @var bool $saved */

$ys_am_missing = array_values( array_diff( $dashboard['hidden'], array_keys( $seen['items'] ) ) );
?>
<div class="wrap ys-am-declutter">
	<?php echo YSMenuPage::pagehead_html( 'dashboard', '後台精簡', '關閉留言、隱藏控制台小工具，讓後台只留需要的東西。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pagehead_html 內已逐欄 escape ?>

	<?php if ( ! empty( $healed ) ) : ?>
		<div class="notice notice-warning"><p>這一頁的設定被改成「不自動載入」，本外掛會把它當成沒設定過；已經改回自動載入，設定恢復生效。</p></div>
	<?php endif; ?>
	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p>已儲存。有頁面快取的網站，請清一次快取，訪客才會看到新的設定。</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( YSDeclutterAdmin::POST_ACTION ); ?>">
		<?php wp_nonce_field( YSDeclutterAdmin::NONCE_ACTION ); ?>

		<div class="ys-ec-card ysca-card-spaced">
			<h3><span class="dashicons dashicons-admin-comments"></span> 留言</h3>
			<div class="inside">
				<p class="description">這是功能開關，對所有人生效（包括管理員）。區塊編輯器的「備註」不受影響。</p>
				<fieldset class="ys-am-declutter__modes">
					<legend class="screen-reader-text">留言</legend>
					<label><input type="radio" name="comments[mode]" value="off" <?php checked( 'off', $comments['mode'] ); ?>> 不關閉（預設）</label><br>
					<label><input type="radio" name="comments[mode]" value="everywhere" <?php checked( 'everywhere', $comments['mode'] ); ?>> 全站關閉留言</label><br>
					<label><input type="radio" name="comments[mode]" value="post_types" <?php checked( 'post_types', $comments['mode'] ); ?>> 只關閉下面勾選的文章類型</label>
				</fieldset>
				<div class="ys-am-declutter__types" role="group" aria-label="關閉留言的文章類型">
					<?php if ( [] === $types ) : ?>
						<p class="description">目前沒有支援留言的文章類型。</p>
					<?php endif; ?>
					<?php foreach ( $types as $ys_am_name => $ys_am_label ) : ?>
						<label><input type="checkbox" name="comments[post_types][]" value="<?php echo esc_attr( $ys_am_name ); ?>" <?php checked( in_array( $ys_am_name, $comments['post_types'], true ) ); ?>> <?php echo esc_html( $ys_am_label ); ?> <code><?php echo esc_html( $ys_am_name ); ?></code></label>
					<?php endforeach; ?>
				</div>
				<p><label><input type="checkbox" name="comments[keep_existing]" value="1" <?php checked( $comments['keep_existing'] ); ?>> 已有的留言照樣顯示（只是不能再留言）</label></p>
				<ul class="ys-am-declutter__notes">
					<li>關閉的範圍：不能再留言或 Pingback；沒勾「已有的留言照樣顯示」時，文章上的留言、留言數與留言表單都不顯示，文章編輯畫面也不再有「討論」設定。</li>
					<li>全站關閉時另外拿掉：後台「留言」選單（直接開留言管理頁會顯示已關閉）、上方工具列的留言圖示、控制台的留言數與近期留言、留言 RSS（轉回文章或首頁）、XML-RPC 新增留言、「近期留言」小工具與「最新留言」區塊。「設定 › 討論」只拿掉選單，網址照樣能開（那一頁還有大頭貼設定）。</li>
					<li>WooCommerce 的商品評論也是留言：關閉「商品」類型會連商品評論一起關。</li>
				</ul>
			</div>
		</div>

		<div class="ys-ec-card ysca-card-spaced">
			<h3><span class="dashicons dashicons-dashboard"></span> 控制台小工具</h3>
			<div class="inside">
				<p class="description">
					勾選的小工具，所有人的控制台都不會出現（包括管理員）。
					清單是可以使用 YS 選單管理的人打開控制台時記下的<?php echo $seen['updated_at'] > 0 ? esc_html( '（最後更新：' . wp_date( 'Y-m-d H:i', $seen['updated_at'] ) . '）' ) : ''; ?>；裝了新外掛後，
					<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>">打開一次控制台</a>再回來，清單就會更新。
				</p>
				<?php if ( [] === $seen['items'] && [] === $ys_am_missing ) : ?>
					<div class="notice notice-info inline"><p>還沒有記錄：請先<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>">打開一次控制台</a>，再回到這一頁。</p></div>
				<?php endif; ?>
				<div class="ys-am-declutter__widgets" role="group" aria-label="要隱藏的控制台小工具">
					<?php foreach ( $seen['items'] as $ys_am_id => $ys_am_row ) : ?>
						<label><input type="checkbox" name="dashboard[hidden][]" value="<?php echo esc_attr( $ys_am_id ); ?>" <?php checked( in_array( $ys_am_id, $dashboard['hidden'], true ) ); ?>>
							<?php echo esc_html( $ys_am_row['title'] ); ?>
							<span class="description"><?php echo esc_html( YSDeclutterAdmin::context_label( $ys_am_row['context'] ) ); ?> · <code><?php echo esc_html( $ys_am_id ); ?></code></span></label>
					<?php endforeach; ?>
					<?php foreach ( $ys_am_missing as $ys_am_id ) : ?>
						<label><input type="checkbox" name="dashboard[hidden][]" value="<?php echo esc_attr( $ys_am_id ); ?>" checked>
							<code><?php echo esc_html( $ys_am_id ); ?></code>
							<span class="description">（這次沒有出現在控制台，提供它的外掛可能停用了；保持勾選，之後出現時照樣隱藏）</span></label>
					<?php endforeach; ?>
				</div>
				<p><label><input type="checkbox" name="dashboard[hide_welcome]" value="1" <?php checked( $dashboard['hide_welcome'] ); ?>> 隱藏「歡迎使用 WordPress」面板</label></p>
			</div>
		</div>

		<p><button type="submit" class="button button-primary">儲存</button></p>
	</form>
</div>
