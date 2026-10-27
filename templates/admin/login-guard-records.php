<?php
/**
 * 白牌設定頁「登入防護」分頁：鎖定與紀錄（v1.6.0，設計 §4.8、§4.13.1、§5.2）。由 whitelabel-settings.php 在主表單結束之後 include，
 * 跟著「登入防護」分頁顯示（data-ys-am-panel-extra；沒有 JS 時照舊全部顯示）。
 *
 * 可用變數：
 *   - $records_view：YSLoginSecurityAdmin::records_view()
 *   - $field_errors：已過白名單的錯誤代碼
 *   - $lg_done：動作做完的代碼（已過白名單）
 *
 * 每個動作各自一張表單、各自的 nonce action（解鎖、全部解鎖、清空紀錄、加入白名單、允許這個來源、現在就開始拒絕），
 * 送到 admin-post.php。輸出一律跳脫；帳號名稱在 records_view 已經清理過。
 *
 * @package YangSheep\AdminMenu
 * @since   1.6.0
 */

use YangSheep\AdminMenu\Admin\YSLoginSecurityAdmin;
use YangSheep\AdminMenu\Security\YSLoginGuardSettings;

defined( 'ABSPATH' ) || exit;

/** @var array  $records_view */
/** @var array  $field_errors */
/** @var string $lg_done */

$lr         = (array) ( $records_view ?? [] );
$lr_post    = admin_url( 'admin-post.php' );
$lr_errors  = YSLoginSecurityAdmin::action_error_texts( (array) ( $field_errors ?? [] ) );
$lr_done    = YSLoginSecurityAdmin::done_text( (string) ( $lg_done ?? '' ) );
$lr_events  = [ 'fail' => '失敗', 'lockout' => '鎖定', 'unlock' => '解鎖', 'clear' => '清空', 'omitted' => '省略', 'xsite' => '跨站' ];
// 每張表單自己的 nonce。不用 wp_nonce_field()：它每張都印 id="_wpnonce"，一頁七張會有重複的 id（實作審查 A 線 P3-11）。
$lr_form    = static function ( string $action, string $id ) use ( $lr_post ): void {
	echo '<form method="post" action="' . esc_url( $lr_post ) . '" id="' . esc_attr( $id ) . '" class="ys-am-lg-form">';
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	wp_referer_field();
};
?>
<section class="ys-am-lg-records" id="ys-am-lg-records" data-ys-am-panel-extra="turnstile" aria-label="鎖定與紀錄">
	<?php /* 主表單裡兩個按鈕（加入白名單、現在就開始拒絕）用 form 屬性指到這兩張表單。 */ ?>
	<?php $lr_form( YSLoginSecurityAdmin::ACTION_ALLOWLIST_ADD, 'ys-am-lg-allowlist-form' ); ?></form>
	<?php $lr_form( YSLoginSecurityAdmin::ACTION_END_OBSERVATION, 'ys-am-lg-observe-form' ); ?></form>

	<div class="ysca-card">
		<div class="ysca-card__head">
			<h3 class="ysca-card__title">鎖定與紀錄</h3>
		</div>
		<div class="ysca-card__body">
			<?php if ( '' !== $lr_done ) : ?>
				<div class="ys-admin-note ys-admin-note--success"><p><?php echo esc_html( $lr_done ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $lr_errors as $lr_error ) : ?>
				<div class="ys-admin-note ys-admin-note--error"><p><?php echo esc_html( $lr_error ); ?></p></div>
			<?php endforeach; ?>

			<?php if ( empty( $lr['ready'] ) ) : ?>
				<p class="ysca-field__hint">登入次數限制的資料表還沒建立：打開「啟用登入次數限制」並儲存之後，這裡會列出鎖定中的位址與紀錄。</p>
			<?php else : ?>

				<h4 class="ys-am-lg-subtitle">目前鎖定中</h4>
				<?php if ( [] === (array) ( $lr['locked'] ?? [] ) ) : ?>
					<p class="ysca-field__hint">目前沒有鎖定中的位址。</p>
				<?php else : ?>
					<?php $lr_form( YSLoginSecurityAdmin::ACTION_UNLOCK, 'ys-am-lg-unlock-form' ); ?>
						<div class="ys-admin-table-frame">
							<table class="ysca-table ys-admin-table--compact ys-am-lg-table">
								<thead>
									<tr>
										<th scope="col">位址或網段</th>
										<th scope="col">鎖到</th>
										<th scope="col">累計失敗</th>
										<th scope="col">不同的帳號</th>
										<th scope="col">鎖定期間被擋</th>
										<th scope="col">最後嘗試的帳號</th>
										<th scope="col">來源</th>
										<th scope="col"><span class="screen-reader-text">動作</span></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( (array) $lr['locked'] as $lr_row ) : ?>
										<tr>
											<td>
												<?php echo esc_html( (string) $lr_row['label'] ); ?>
												<?php if ( empty( $lr_row['shared'] ) && '' !== (string) $lr_row['last_ip'] && (string) $lr_row['last_ip'] !== (string) $lr_row['key'] ) : ?>
													<br><small>最後一次：<code><?php echo esc_html( (string) $lr_row['last_ip'] ); ?></code></small>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $lr_row['until'] ) ); ?></td>
											<td><?php echo esc_html( (string) (int) $lr_row['failures'] ); ?></td>
											<td><?php echo esc_html( (string) (int) $lr_row['accounts'] ); ?></td>
											<td><?php echo esc_html( (string) (int) $lr_row['blocked'] ); ?></td>
											<td><?php echo esc_html( (string) $lr_row['username'] ); ?></td>
											<td><?php echo esc_html( (string) $lr_row['source'] ); ?></td>
											<td><button type="submit" class="ysca-btn ysca-btn--sm" name="lg_key" value="<?php echo esc_attr( (string) $lr_row['key'] ); ?>" data-ys-am-lg-action="unlock">解鎖</button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</form>
					<?php $lr_form( YSLoginSecurityAdmin::ACTION_UNLOCK_ALL, 'ys-am-lg-unlock-all-form' ); ?>
						<div class="ys-admin-actions"><button type="submit" class="ysca-btn ysca-btn--sm" data-ys-am-lg-action="unlock-all">全部解鎖</button></div>
					</form>
				<?php endif; ?>

				<h4 class="ys-am-lg-subtitle">最近 <?php echo esc_html( (string) YSLoginSecurityAdmin::XSITE_DAYS ); ?> 天被拒的跨站登入來源</h4>
				<?php if ( [] === (array) ( $lr['xsite'] ?? [] ) ) : ?>
					<p class="ysca-field__hint">沒有。</p>
				<?php else : ?>
					<p class="ysca-field__hint">只允許你確定是自己前台的網址。來源是請求自己帶的，任何人都能送任意的值；誤加了別人的網址，那個網址就能替訪客送出錯誤的登入、鎖住訪客的 IP（不會讓人登進任何帳號）。</p>
					<?php $lr_form( YSLoginSecurityAdmin::ACTION_ALLOW_ORIGIN, 'ys-am-lg-origin-form' ); ?>
						<div class="ys-admin-table-frame">
							<table class="ysca-table ys-admin-table--compact ys-am-lg-table">
								<thead>
									<tr>
										<th scope="col">來源</th>
										<th scope="col">入口</th>
										<th scope="col">次數</th>
										<th scope="col">最後一次</th>
										<th scope="col"><span class="screen-reader-text">動作</span></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( (array) $lr['xsite'] as $lr_src ) : ?>
										<?php $lr_origin = (string) $lr_src['origin']; ?>
										<tr>
											<td><?php if ( '' !== $lr_origin ) : ?><code><?php echo esc_html( $lr_origin ); ?></code><?php else : ?>（沒有帶來源）<?php endif; ?></td>
											<td><?php echo esc_html( (string) $lr_src['entries'] ); ?></td>
											<td><?php echo esc_html( (string) (int) $lr_src['count'] ); ?><?php echo (int) $lr_src['observed'] > 0 ? esc_html( '（觀察中 ' . (int) $lr_src['observed'] . '）' ) : ''; ?></td>
											<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $lr_src['last'] ) ); ?></td>
											<td>
												<?php // 存的時候截到 255 個字：剛好 255 個字的可能被截過，截完還像合法主機也不給按鈕（設計 §4.13.1；實作審查 A 線 P3-6）。 ?>
												<?php if ( '' !== $lr_origin && strlen( $lr_origin ) < 255 && $lr_origin === YSLoginGuardSettings::valid_origin( $lr_origin ) ) : ?>
													<button type="submit" class="ysca-btn ysca-btn--sm" name="lg_origin" value="<?php echo esc_attr( $lr_origin ); ?>" data-ys-am-lg-action="allow-origin">允許這個來源</button>
												<?php else : ?>
													<small>要允許請手動填</small>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</form>
				<?php endif; ?>

				<h4 class="ys-am-lg-subtitle">最近的紀錄</h4>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ys-am-lg-filter">
					<input type="hidden" name="page" value="<?php echo esc_attr( \YangSheep\AdminMenu\Admin\YSMenuPage::SLUG_SETTINGS ); ?>">
					<input type="hidden" name="tab" value="turnstile">
					<label for="ys-am-lg-filter-ip">只看這個 IP 或鍵</label>
					<input type="text" id="ys-am-lg-filter-ip" name="lg_ip" class="ysca-input ys-admin-w-md" value="<?php echo esc_attr( (string) ( $lr['filter'] ?? '' ) ); ?>" spellcheck="false">
					<button type="submit" class="ysca-btn ysca-btn--sm">篩選</button>
				</form>
				<?php if ( [] === (array) ( $lr['logs'] ?? [] ) ) : ?>
					<p class="ysca-field__hint">沒有紀錄。</p>
				<?php else : ?>
					<div class="ys-admin-table-frame">
						<table class="ysca-table ys-admin-table--compact ys-am-lg-table">
							<thead>
								<tr>
									<th scope="col">時間</th>
									<th scope="col">事件</th>
									<th scope="col">IP</th>
									<th scope="col">帳號</th>
									<th scope="col">來源</th>
									<th scope="col">說明</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( (array) $lr['logs'] as $lr_log ) : ?>
									<?php
									$lr_event = (string) $lr_log['event'];
									$lr_where = '' !== (string) $lr_log['ip'] ? (string) $lr_log['ip'] : (string) $lr_log['ip_key']; // 跨站、清空這類事件沒有 IP
									?>
									<tr>
										<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $lr_log['created_at'] ) ); ?></td>
										<td><?php echo esc_html( $lr_events[ $lr_event ] ?? $lr_event ); ?></td>
										<td><?php if ( '' !== $lr_where ) : ?><code><?php echo esc_html( $lr_where ); ?></code><?php else : ?>—<?php endif; ?></td>
										<td><?php echo esc_html( (string) $lr_log['username'] ); ?></td>
										<td><?php echo esc_html( (string) $lr_log['source'] ); ?></td>
										<td><?php echo esc_html( 'xsite' === $lr_event ? (string) $lr_log['origin'] . '（' . (string) $lr_log['code'] . '）' : (string) $lr_log['code'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php if ( (int) ( $lr['pages'] ?? 1 ) > 1 ) : ?>
						<?php
						// 第一頁、最後一頁，加上目前這一頁前後各 5 頁（紀錄最多 5 萬筆、一頁 50 筆時也到得了每一頁；實作審查 A 線 P3-11）。
						$lr_pages = (int) $lr['pages'];
						$lr_cur   = (int) $lr['page'];
						$lr_list  = array_values( array_unique( array_merge( [ 1 ], range( max( 1, min( $lr_cur, $lr_pages ) - 5 ), min( $lr_pages, max( 1, $lr_cur ) + 5 ) ), [ $lr_pages ] ) ) );
						sort( $lr_list );
						$lr_prev  = 0;
						?>
						<p class="ys-am-lg-pages">
							<?php foreach ( $lr_list as $lr_p ) : ?>
								<?php if ( $lr_prev > 0 && $lr_p > $lr_prev + 1 ) : ?>
									<span aria-hidden="true">…</span>
								<?php endif; ?>
								<?php if ( $lr_p === $lr_cur ) : ?>
									<strong><?php echo esc_html( (string) $lr_p ); ?></strong>
								<?php else : ?>
									<a href="<?php echo esc_url( add_query_arg( [ 'page' => \YangSheep\AdminMenu\Admin\YSMenuPage::SLUG_SETTINGS, 'tab' => 'turnstile', 'lg_ip' => (string) ( $lr['filter'] ?? '' ), 'lg_page' => $lr_p ], admin_url( 'admin.php' ) ) . '#ys-am-lg-records' ); ?>"><?php echo esc_html( (string) $lr_p ); ?></a>
								<?php endif; ?>
								<?php $lr_prev = $lr_p; ?>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<?php $lr_form( YSLoginSecurityAdmin::ACTION_CLEAR, 'ys-am-lg-clear-form' ); ?>
					<p class="ysca-field__hint">清空紀錄會刪掉上面的紀錄、被拒的跨站來源，與「鍵＋帳號」的對照（原諒用）；鎖定與計數不會動。</p>
					<label><input type="checkbox" name="lg_confirm" value="yes"> 我確定要清空紀錄</label>
					<div class="ys-admin-actions"><button type="submit" class="ysca-btn ysca-btn--sm" data-ys-am-lg-action="clear">清空紀錄</button></div>
				</form>
			<?php endif; ?>
		</div>
	</div>
</section>
