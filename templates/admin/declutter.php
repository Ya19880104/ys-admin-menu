<?php
/**
 * v1.7.0：「後台精簡」設定頁（YSDeclutterAdmin::render()）。
 * v1.8.0：版面改用 YS 後台中央樣式的元件（頁首、卡片、單選卡、說明框、儲存列），欄位與存檔不變。
 * v1.10.0：加上「隨機文章網址」卡片（YSRandomContent）。
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
/** @var array{supported:bool,wp_version:string,mode:string,enabled:?bool,others:array<int,array{priority:int,label:string}>,conflicts:array<int,string>,base:string,example:string,permalinks:bool,base_title:string} $random */
/** @var bool $saved */

$ys_am_missing = array_values( array_diff( $dashboard['hidden'], array_keys( $seen['items'] ) ) );

echo YSMenuPage::page_open( '後台精簡', '關閉留言與隨機文章網址、隱藏控制台小工具，讓網站與後台只留需要的東西。', 'ys-am-declutter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_open 內已逐欄 escape
?>

	<?php if ( ! empty( $healed ) ) : ?>
		<div class="notice notice-warning"><p>這一頁的設定被改成「不自動載入」，本外掛會把它當成沒設定過；已經改回自動載入，設定恢復生效。</p></div>
	<?php endif; ?>
	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p>已儲存。有頁面快取的網站，請清一次快取，訪客才會看到新的設定。</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( YSDeclutterAdmin::POST_ACTION ); ?>">
		<?php wp_nonce_field( YSDeclutterAdmin::NONCE_ACTION ); ?>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">留言</h3><p class="ysca-card__sub">這是功能開關，對所有人生效（包括管理員）。區塊編輯器的「備註」不受影響。</p></div></div>
			<div class="ysca-card__body">
				<fieldset class="ys-admin-choice">
					<legend class="screen-reader-text">留言</legend>
					<label><input type="radio" name="comments[mode]" value="off" <?php checked( 'off', $comments['mode'] ); ?>> 不關閉（預設）</label>
					<label><input type="radio" name="comments[mode]" value="everywhere" <?php checked( 'everywhere', $comments['mode'] ); ?>> 全站關閉留言</label>
					<label><input type="radio" name="comments[mode]" value="post_types" <?php checked( 'post_types', $comments['mode'] ); ?>> 只關閉下面勾選的文章類型</label>
				</fieldset>
				<div class="ys-am-declutter__types" role="group" aria-label="關閉留言的文章類型">
					<?php if ( [] === $types ) : ?>
						<p class="ysca-field__hint">目前沒有支援留言的文章類型。</p>
					<?php endif; ?>
					<?php foreach ( $types as $ys_am_name => $ys_am_label ) : ?>
						<label class="ys-admin-check"><input type="checkbox" name="comments[post_types][]" value="<?php echo esc_attr( $ys_am_name ); ?>" <?php checked( in_array( $ys_am_name, $comments['post_types'], true ) ); ?>> <?php echo esc_html( $ys_am_label ); ?> <code><?php echo esc_html( $ys_am_name ); ?></code></label>
					<?php endforeach; ?>
				</div>
				<label class="ys-admin-check"><input type="checkbox" name="comments[keep_existing]" value="1" <?php checked( $comments['keep_existing'] ); ?>> 已有的留言照樣顯示（只是不能再留言）</label>
				<div class="ys-admin-note">
					<ul class="ys-am-declutter__notes">
						<li>關閉的範圍：不能再留言或 Pingback。沒勾「已有的留言照樣顯示」時，前台的留言、留言數與留言表單都不顯示，留言 RSS、「最新留言」區塊與「近期留言」小工具不列關閉範圍的留言，沒有審核權限的人用 REST 讀取也看不到；文章編輯畫面不再有「討論」設定。後台「留言」頁與資料庫裡的留言不動。</li>
						<li>全站關閉時另外拿掉：後台「留言」選單（直接開留言管理頁會顯示已關閉）、上方工具列的留言圖示、控制台的留言數與近期留言、留言 RSS（轉回文章或首頁）、X-Pingback、XML-RPC 的新增留言與 Pingback、「近期留言」小工具；區塊編輯器不能再插入「最新留言」區塊（已經放好的照樣可以移除）。也不送 Pingback 給文章裡連到的網站。「設定 › 討論」只拿掉選單，網址照樣能開（那一頁還有大頭貼設定）。</li>
						<li>WooCommerce 的商品評論也是留言：關閉「商品」類型會連商品評論一起關。</li>
					</ul>
				</div>
			</div>
		</section>

		<?php
		// v1.10.0：隨機文章網址（WordPress 7.2 起）。
		$ys_am_random_on = 'disable' === $random['mode'];
		?>
		<section class="ysca-card" id="ys-am-random">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">隨機文章網址</h3><p class="ysca-card__sub">WordPress 7.2 起，打開 <code>/random/</code>、<code>/random/category/分類/</code> 這類網址，或在首頁、分類、搜尋的網址後面加上 <code>?random</code>，會轉到該範圍裡隨機的一篇文章。WordPress 預設開放；這是功能開關，對所有訪客生效。</p></div></div>
			<div class="ysca-card__body">
				<input type="hidden" name="random[present]" value="1">
				<label class="ys-admin-switch">
					<span class="ysca-toggle">
						<input type="checkbox" name="random[disable]" value="1" <?php checked( $ys_am_random_on ); ?>>
						<span class="slider"></span>
					</span>
					<span class="ys-admin-switch__text"><strong>關閉隨機文章網址（預設開啟）</strong>不產生 <code>/random/</code> 的網址規則，網址上的 <code>random</code> 參數不再轉址，「設定 › 永久連結」也不再顯示隨機網址前綴。關掉這個開關＝照 WordPress 預設開放。</span>
				</label>

				<?php if ( ! $random['supported'] ) : ?>
					<div class="ys-admin-note ys-admin-note--info"><p>這個網站的 WordPress（<?php echo esc_html( $random['wp_version'] ); ?>）還沒有隨機文章網址，這個開關目前沒有作用；升級到 7.2 以後自動照這裡的設定。</p></div>
				<?php elseif ( false === $random['enabled'] ) : ?>
					<div class="ys-admin-note ys-admin-note--success"><p><strong>目前：隨機文章網址已關閉。</strong>打開 <code><?php echo esc_html( $random['example'] ); ?></code> 不會轉到隨機文章。</p></div>
				<?php else : ?>
					<div class="ys-admin-note ys-admin-note--warning"><p><strong>目前：隨機文章網址開放中。</strong>例如打開 <code><?php echo esc_html( $random['example'] ); ?></code> 會轉到一篇隨機文章。</p></div>
				<?php endif; ?>

				<?php if ( $random['supported'] && $ys_am_random_on && true === $random['enabled'] ) : ?>
					<div class="ys-admin-note ys-admin-note--warning"><p>這個開關是開著的，但其他程式在同一個設定（<code><?php echo esc_html( \YangSheep\AdminMenu\Content\YSRandomContent::FILTER ); ?></code>）把隨機文章網址打開了，以它們為準。要關閉，請先拿掉下面列出的程式碼。</p></div>
				<?php endif; ?>
				<?php if ( $random['supported'] && ! $ys_am_random_on && false === $random['enabled'] && [] === $random['conflicts'] && [] !== $random['others'] ) : ?>
					<div class="ys-admin-note ys-admin-note--info"><p>這個開關是關著的（照 WordPress），但其他程式在同一個設定把隨機文章網址關掉了，以它們為準。</p></div>
				<?php endif; ?>
				<?php if ( [] !== $random['others'] ) : ?>
					<div class="ys-admin-note">
						<p>同一個設定（<code><?php echo esc_html( \YangSheep\AdminMenu\Content\YSRandomContent::FILTER ); ?></code>）也有其他程式在設定。本外掛只決定起始值、最先執行，不蓋掉它們：</p>
						<ul class="ys-am-declutter__notes">
							<?php foreach ( $random['others'] as $ys_am_other ) : ?>
								<li><?php echo esc_html( $ys_am_other['label'] ); ?>（優先順序 <?php echo esc_html( (string) $ys_am_other['priority'] ); ?>）</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ( $random['supported'] && [] !== $random['conflicts'] && ( true === $random['enabled'] || ! $ys_am_random_on ) ) : ?>
					<div class="ys-admin-note ys-admin-note--warning">
						<?php if ( true === $random['enabled'] ) : ?>
							<p><strong>偵測到其他程式也在用 <code>random</code> 這個網址參數，但隨機文章網址被其他程式打開了，這些網址可能會被轉到隨機文章：</strong></p>
						<?php else : ?>
							<p><strong>偵測到其他程式也在用 <code>random</code> 這個網址參數，為了避免衝突，隨機文章網址維持關閉：</strong></p>
						<?php endif; ?>
						<ul class="ys-am-declutter__notes">
							<?php foreach ( $random['conflicts'] as $ys_am_code ) : ?>
								<li><?php echo esc_html( \YangSheep\AdminMenu\Content\YSRandomContent::conflict_label( $ys_am_code ) ); ?></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( true === $random['enabled'] ) : ?>
							<p>WordPress 會在那些程式之前處理 <code>random</code> 參數。要避免它們的網址被轉走，請拿掉把隨機文章網址打開的程式碼（見上面列表），或停用、改掉用 <code>random</code> 參數的程式。</p>
						<?php else : ?>
							<p>WordPress 會在那些程式之前處理 <code>random</code> 參數，開放後它們的網址會被轉到隨機文章。停用或改掉那些程式後，才會照 WordPress 開放。</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $random['base_title'] ) : ?>
					<div class="ys-admin-note ys-admin-note--info"><p><code>/<?php echo esc_html( $random['base'] ); ?>/</code> 已經是頁面「<?php echo esc_html( $random['base_title'] ); ?>」的網址，WordPress 會顯示那個頁面，<code>/<?php echo esc_html( $random['base'] ); ?>/</code> 本身不轉址；分類、作者等其他隨機網址照常。</p></div>
				<?php endif; ?>

				<div class="ys-admin-note">
					<ul class="ys-am-declutter__notes">
						<li>結果改變時（存檔、更新本外掛、其他程式改了結果），本外掛在存檔或下一次打開後台頁面時重建一次網址規則，不必再到「設定 › 永久連結」按儲存；重建前 <code>/random/</code> 只顯示文章列表、不會轉址。有頁面快取的網站，請清一次快取。</li>
						<li>其他外掛、佈景主題或 mu-plugin 也在 <code><?php echo esc_html( \YangSheep\AdminMenu\Content\YSRandomContent::FILTER ); ?></code> 設定時，以它們為準，本外掛不蓋掉；上面會列出是哪些程式。</li>
						<li>關掉開關（照 WordPress）時，如果偵測到其他程式也在用 <code>random</code> 參數（自己加的網址參數、網址端點、網址規則或改寫標籤），仍然維持關閉，避免它們的網址被轉到隨機文章。</li>
						<li>停用本外掛後，隨機文章網址回到 WordPress 預設（開放）。</li>
					</ul>
				</div>
			</div>
		</section>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">控制台小工具</h3><p class="ysca-card__sub">勾選的小工具，所有人的控制台都不會出現（包括管理員）。清單在打開這一頁時自動讀取，也會合併各管理員在控制台看過的小工具；裝了新外掛，重新整理這一頁就會出現。</p></div></div>
			<div class="ysca-card__body">
				<?php if ( [] === $seen['items'] && [] === $ys_am_missing ) : ?>
					<div class="ys-admin-note ys-admin-note--info"><p>目前沒有讀到任何控制台小工具。剛裝了外掛的話請重新整理這一頁；仍然是空的，可以<a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>">打開一次控制台</a>再回來。</p></div>
				<?php endif; ?>
				<div class="ys-am-declutter__widgets" role="group" aria-label="要隱藏的控制台小工具">
					<?php foreach ( $seen['items'] as $ys_am_id => $ys_am_row ) : ?>
						<label class="ys-admin-check"><input type="checkbox" name="dashboard[hidden][]" value="<?php echo esc_attr( $ys_am_id ); ?>" <?php checked( in_array( $ys_am_id, $dashboard['hidden'], true ) ); ?>>
							<?php echo esc_html( $ys_am_row['title'] ); ?>
							<span class="ysca-field__hint"><?php echo esc_html( YSDeclutterAdmin::context_label( $ys_am_row['context'] ) ); ?> · <code><?php echo esc_html( $ys_am_id ); ?></code></span></label>
					<?php endforeach; ?>
					<?php foreach ( $ys_am_missing as $ys_am_id ) : ?>
						<label class="ys-admin-check"><input type="checkbox" name="dashboard[hidden][]" value="<?php echo esc_attr( $ys_am_id ); ?>" checked>
							<code><?php echo esc_html( $ys_am_id ); ?></code>
							<span class="ysca-field__hint">（這次沒有出現在控制台，提供它的外掛可能停用了；保持勾選，之後出現時照樣隱藏）</span></label>
					<?php endforeach; ?>
				</div>
				<label class="ys-admin-check"><input type="checkbox" name="dashboard[hide_welcome]" value="1" <?php checked( $dashboard['hide_welcome'] ); ?>> 隱藏「歡迎使用 WordPress」面板</label>
			</div>
		</section>

		<div class="ys-admin-savebar">
			<button type="submit" class="ysca-btn ysca-btn--primary">儲存</button>
		</div>
	</form>
<?php
echo YSMenuPage::page_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定字串
