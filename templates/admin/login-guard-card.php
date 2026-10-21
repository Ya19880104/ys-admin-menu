<?php
/**
 * 白牌設定頁「登入防護」分頁：登入次數限制卡片（v1.6.0，設計 §5.2）。由 whitelabel-settings.php 在主表單裡 include。
 *
 * 可用變數：
 *   - $guard_view：YSLoginSecurityAdmin::guard_view()
 *   - $field_errors：存檔時擋下的欄位代碼（已過白名單）
 *
 * 「加入白名單」「現在就開始拒絕」兩個按鈕用 form 屬性指到主表單外面、各自帶 nonce 的表單（login-guard-records.php）。
 * 輸出一律跳脫。
 *
 * @package YangSheep\AdminMenu
 * @since   1.6.0
 */

use YangSheep\AdminMenu\Admin\YSLoginSecurityAdmin;

defined( 'ABSPATH' ) || exit;

/** @var array $guard_view */
/** @var array $field_errors */

$lg        = (array) ( $guard_view ?? [] );
$lg_num    = (array) ( $lg['numbers'] ?? [] );
$lg_status = (array) ( $lg['status'] ?? [] );
$lg_errors = YSLoginSecurityAdmin::guard_error_texts( (array) ( $field_errors ?? [] ) );
$lg_ip     = (string) ( $lg['my_ip'] ?? '' );
?>
<div class="ysca-wl-card" id="ys-am-lg-card">
	<input type="hidden" name="lg_present" value="1">
	<div class="ysca-wl-card__header">
		<h3 class="ysca-wl-card__title">登入次數限制</h3>
	</div>
	<div class="ysca-wl-card__body">
		<?php foreach ( $lg_errors as $lg_error ) : ?>
			<div class="ysca-wl-notice ysca-wl-notice--error"><p><?php echo esc_html( $lg_error ); ?></p></div>
		<?php endforeach; ?>

		<label class="ys-am-toggle-field">
			<span class="ys-am-toggle">
				<input type="checkbox" name="lg_enabled" value="yes" <?php checked( ! empty( $lg['enabled'] ) ); ?>>
				<span class="ys-am-toggle__slider"></span>
			</span>
			<span class="ys-am-toggle-field__text"><strong>啟用登入次數限制</strong>同一個網路位址失敗太多次時，暫時不讓它登入。涵蓋登入頁（含改過的網址）、前台的登入表單、XML-RPC；用應用程式密碼的 REST 請求在鎖定時也擋。已經登入的人不受影響。不涵蓋的登入見下面的「已知限制」。</span>
		</label>

		<?php if ( ! empty( $lg['enabled'] ) && ! empty( $lg['disabled_constant'] ) ) : ?>
			<div class="ysca-wl-notice ysca-wl-notice--error"><p>wp-config.php 定義了 <code>YS_ADMIN_MENU_LOGIN_GUARD_DISABLED</code>，登入次數限制目前不作用。</p></div>
		<?php elseif ( ! empty( $lg['enabled'] ) && empty( $lg['ready'] ) ) : ?>
			<div class="ysca-wl-notice ysca-wl-notice--error"><p>資料表還沒建好或版本不對，目前沒有作用（照常可以登入）。儲存或重新整理這一頁會重試；一直出現時，請確認資料庫帳號有建表的權限。</p></div>
		<?php elseif ( ! empty( $lg['enabled'] ) && in_array( 'shared_bucket', (array) ( $lg_status['reasons'] ?? [] ), true ) ) : ?>
			<div class="ysca-wl-notice ysca-wl-notice--error"><p>最近的登入失敗大多分不出訪客的 IP，目前併在一起算：達到門檻時，從同一條路登入的人會一起暫停。請到下面的 <a href="#ys-am-ip-card">進階：訪客 IP</a> 確認偵測方式。</p></div>
		<?php endif; ?>

		<p class="ys-am-wl-ip-now">
			目前偵測到你的 IP：<strong><code><?php echo esc_html( '' !== $lg_ip ? $lg_ip : '（沒有）' ); ?></code></strong>
			<?php if ( ! empty( $lg['my_ip_listed'] ) ) : ?>
				（已經在白名單裡）
			<?php elseif ( '' !== $lg_ip ) : ?>
				<button type="submit" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" form="ys-am-lg-allowlist-form" data-ys-am-lg-action="allowlist-add">加入白名單</button>
			<?php endif; ?>
		</p>

		<div class="ysca-wl-field ys-am-lg-inline">
			失敗
			<input type="number" name="lg_max_failures" class="ysca-wl-input ysca-wl-input--sm" min="3" max="100" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['max_failures'] ?? 10 ) ); ?>" aria-label="失敗幾次就鎖">
			次（
			<input type="number" name="lg_window" class="ysca-wl-input ysca-wl-input--sm" min="1" max="1440" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['window'] ?? 15 ) ); ?>" aria-label="在多少分鐘之內">
			分鐘內）就鎖
			<input type="number" name="lg_lockout" class="ysca-wl-input ysca-wl-input--sm" min="1" max="10080" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['lockout'] ?? 30 ) ); ?>" aria-label="鎖多少分鐘">
			分鐘
		</div>

		<label class="ys-am-toggle-field">
			<span class="ys-am-toggle">
				<input type="checkbox" name="lg_escalate" value="yes" <?php checked( ! empty( $lg['escalate'] ) ); ?>>
				<span class="ys-am-toggle__slider"></span>
			</span>
			<span class="ys-am-toggle-field__text ys-am-lg-inline">
				<strong>逐次加長</strong>
				<input type="number" name="lg_escalate_period" class="ysca-wl-input ysca-wl-input--sm" min="1" max="720" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['escalate_period'] ?? 24 ) ); ?>" aria-label="計算期間（小時）">
				小時內第
				<input type="number" name="lg_escalate_after" class="ysca-wl-input ysca-wl-input--sm" min="2" max="20" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['escalate_after'] ?? 3 ) ); ?>" aria-label="第幾次被鎖起加長">
				次被鎖起，改鎖
				<input type="number" name="lg_escalated_lockout" class="ysca-wl-input ysca-wl-input--sm" min="1" max="720" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['escalated_lockout'] ?? 24 ) ); ?>" aria-label="加長為幾小時">
				小時（要比上面的「鎖多久」長）
			</span>
		</label>

		<label class="ys-am-toggle-field">
			<span class="ys-am-toggle">
				<input type="checkbox" name="lg_ipv6_aggregate" value="yes" <?php checked( ! empty( $lg['ipv6_aggregate'] ) ); ?>>
				<span class="ys-am-toggle__slider"></span>
			</span>
			<span class="ys-am-toggle-field__text"><strong>IPv6 也以 /48 彙總計算</strong>同一個 /48 網段裡的失敗另外合計，門檻是 5 倍，到了就鎖整個 /48。有些網路業者把一個 /48 分給很多客戶，可能會連累同一家業者的其他人；適合沒有大量 IPv6 正常使用者、或正在被 IPv6 分散攻擊的站。</span>
		</label>

		<div class="ysca-wl-field">
			<label for="ys-am-lg-allowlist" class="ysca-wl-field__label">白名單</label>
			<textarea id="ys-am-lg-allowlist" name="lg_allowlist" rows="3" class="ysca-wl-textarea" spellcheck="false" placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo esc_textarea( (string) ( $lg['allowlist_raw'] ?? '' ) ); ?></textarea>
			<p class="ysca-wl-field__hint">一行一個 IP 或 CIDR，最多 200 行。白名單裡的位址不計數、不鎖：從那裡來的登入沒有這一層保護。整間公司共用一個對外 IP 通常不用加：同一個人打錯又登入成功的那幾次會被原諒。</p>
		</div>

		<div class="ysca-wl-field">
			<label for="ys-am-lg-message" class="ysca-wl-field__label">鎖定時的訊息</label>
			<input type="text" id="ys-am-lg-message" name="lg_message" class="ysca-wl-input" maxlength="200" value="<?php echo esc_attr( (string) ( $lg['message'] ?? '' ) ); ?>" placeholder="登入失敗太多次，這個網路位址暫時不能登入。">
			<p class="ysca-wl-field__hint">留空用內建的文字。後面會自動接「大約 N 分鐘後可以再試。」純文字，最多 200 字；不提帳號，也不提密碼對不對。</p>
		</div>

		<label class="ys-am-toggle-field">
			<span class="ys-am-toggle">
				<input type="checkbox" name="lg_cross_site_reject" value="yes" <?php checked( ! empty( $lg['cross_site_reject'] ) ); ?>>
				<span class="ys-am-toggle__slider"></span>
			</span>
			<span class="ys-am-toggle-field__text"><strong>拒絕從其他網站送來的登入（建議）</strong>別的網站可以讓訪客的瀏覽器替它送出錯誤的登入，把訪客的 IP 鎖住。打開登入次數限制時預設勾選；剛打開的頭 24 小時只記錄、不拒絕，讓你先看到有哪些來源。</span>
		</label>
		<?php if ( ! empty( $lg['observing'] ) ) : ?>
			<p class="ysca-wl-field__hint ys-am-lg-observe">
				觀察中，到 <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $lg['observe_until'] ?? 0 ) ) ); ?> 開始拒絕。
				<button type="submit" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" form="ys-am-lg-observe-form" data-ys-am-lg-action="end-observation">現在就開始拒絕</button>
			</p>
		<?php endif; ?>
		<div class="ysca-wl-field ys-am-wl-subfield">
			<label for="ys-am-lg-origins" class="ysca-wl-field__label">允許跨站登入的來源</label>
			<textarea id="ys-am-lg-origins" name="lg_cross_site_origins" rows="2" class="ysca-wl-textarea" spellcheck="false" placeholder="https://shop.example.com"><?php echo esc_textarea( (string) ( $lg['origins_raw'] ?? '' ) ); ?></textarea>
			<p class="ysca-wl-field__hint">一行一個 <code>https://主機</code>（可以帶埠），最多 50 行；本機開發可以用 <code>http://localhost</code>。前台在另一個網域、用 JWT 或 WPGraphQL 這類方式登入時，要把前台的網址加進來。</p>
			<?php $lg_auto = array_merge( array_map( static fn( $o ) => [ $o, 'YS CART 的 headless 前台設定' ], (array) ( $lg['auto_yscart'] ?? [] ) ), array_map( static fn( $o ) => [ $o, 'WordPress 的 allowed_http_origins' ], (array) ( $lg['auto_core'] ?? [] ) ) ); ?>
			<?php if ( [] !== $lg_auto ) : ?>
				<p class="ysca-wl-field__hint">自動允許（唯讀，要改請到來源那邊）：</p>
				<ul class="ys-am-lg-auto">
					<?php foreach ( $lg_auto as $lg_auto_row ) : ?>
						<li><code><?php echo esc_html( (string) $lg_auto_row[0] ); ?></code>（<?php echo esc_html( (string) $lg_auto_row[1] ); ?>）</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="ys-am-lg-inline">
			<label class="ys-am-toggle-field">
				<span class="ys-am-toggle">
					<input type="checkbox" name="lg_notify" value="yes" <?php checked( ! empty( $lg['notify'] ) ); ?>>
					<span class="ys-am-toggle__slider"></span>
				</span>
				<span class="ys-am-toggle-field__text"><strong>鎖定時寄信通知</strong>同一個位址 24 小時內一封，全站每 24 小時最多十封；信裡只列站上存在的帳號。</span>
			</label>
			<div class="ysca-wl-field ys-am-wl-subfield">
				<label for="ys-am-lg-notify-email" class="ysca-wl-field__label">寄到</label>
				<input type="email" id="ys-am-lg-notify-email" name="lg_notify_email" class="ysca-wl-input" value="<?php echo esc_attr( (string) ( $lg['notify_email'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) ( $lg['admin_email'] ?? '' ) ); ?>">
				<p class="ysca-wl-field__hint">留空寄給網站管理員信箱。</p>
			</div>
		</div>

		<div class="ysca-wl-field ys-am-lg-inline">
			紀錄保留
			<input type="number" name="lg_retention_days" class="ysca-wl-input ysca-wl-input--sm" min="1" max="365" step="1" value="<?php echo esc_attr( (string) (int) ( $lg_num['retention_days'] ?? 30 ) ); ?>" aria-label="紀錄保留幾天">
			天（紀錄裡有 IP 與嘗試的帳號名稱，只有管理員看得到）
		</div>

		<p class="ysca-wl-field__hint">緊急停用：在 wp-config.php 加 <code>define( 'YS_ADMIN_MENU_LOGIN_GUARD_DISABLED', true );</code>，或執行 <code>wp ys-admin-menu login-guard disable</code>。</p>

		<details class="ys-am-lg-limits">
			<summary>已知限制</summary>
			<ul class="ul-disc">
				<li>只涵蓋走 WordPress 登入流程（wp_authenticate）的登入。外掛自己直接比對密碼的登入（例如 YS CART headless 的 token 端點）不在範圍內；社群登入、單一登入不計數。</li>
				<li>YS CART 的前台登入會把錯誤一律顯示成「帳號或密碼錯誤」，被鎖定時也一樣；請看這裡的紀錄。</li>
				<li>本外掛的 Turnstile 只保護 wp-login.php，不保護 XML-RPC、REST 與前台表單。不需要 XML-RPC 時，建議打開上面的「關閉 XML-RPC 登入」。</li>
				<li>IPv6 位址很容易大量取得。攻擊分散在大量 IPv6 網段時，登入次數限制只能把每個網段的猜測速度壓低，沒辦法單獨擋住。請搭配 Cloudflare Turnstile 或 WAF。</li>
				<li>網站在代理或 Cloudflare 後面、但下面的「進階：訪客 IP」沒設好時，所有人會併成同一桶計算。</li>
				<li>用標頭判斷訪客 IP 時（Cloudflare、X-Forwarded-For、X-Real-IP、自訂標頭）：有些伺服器會把名稱帶底線的標頭跟帶連字號的當成同一個。請確認伺服器會丟掉名稱帶底線的標頭（nginx 預設就會）。</li>
			</ul>
		</details>
	</div>
</div>
