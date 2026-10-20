<?php
/**
 * 白牌設置頁面 template（v2.37.1 / v2.45.39 ADR-047 .ysca-wl-* 獨立樣式；v1.4.0 分頁）
 *
 * 從 YSWhiteLabelAdmin::render_settings() 載入。整頁是一個表單，「儲存白牌設定」一次存全部分頁。
 *
 * 分頁（沒有 JavaScript 時四塊依序全部顯示）：
 *   1. 後台品牌：後台 LOGO（頂層選單圖示＋選單上方 LOGO 區塊）、Footer 文字、後台頁尾、背景色、資料清除
 *   2. Admin Bar：隱藏 WordPress 標誌、各節點後台／前台隱藏、前台 Admin Bar 載入方式與角色
 *   3. 登入頁：自訂 wp-login.php 的開關與文字
 *   4. 登入防護：Cloudflare Turnstile；v1.6.0 加「進階：訪客 IP」（預設收合）
 *
 * 變數（render_settings 提供）：
 *   - $logo_url, $footer_text, $hide_footer, $hide_wp_logo, $admin_bg_color, $purge_on_uninstall, $notice
 *   - v1.4.0：$show_menu_logo, $menu_logo_text, $adminbar, $login_page, $turnstile, $field_errors, $active_tab
 *   - v1.6.0：$ip_view（登入防護分頁的「進階：訪客 IP」，YSLoginSecurityAdmin::ip_view()）
 *
 * @package YangSheep\Ecommerce\Templates
 */

defined( 'ABSPATH' ) || exit;

/** @var string $logo_url */
/** @var string $footer_text */
/** @var string $notice */
/** @var bool   $show_menu_logo */
/** @var string $menu_logo_text */
/** @var array  $adminbar */
/** @var array  $login_page */
/** @var array  $turnstile */
/** @var array  $field_errors */
/** @var string $active_tab */
/** @var array  $ip_view */

$has_logo        = ( '' !== $logo_url );
$settings_action = admin_url( 'admin-post.php' );
$site_name       = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

$tabs = [
	'brand'     => '後台品牌',
	'adminbar'  => 'Admin Bar',
	'login'     => '登入頁',
	'turnstile' => '登入防護',
];

$scope_labels = [
	'login'        => '登入',
	'register'     => '註冊（站台開放註冊時才有這個表單）',
	'lostpassword' => '忘記密碼',
];
$log_labels = [
	'context' => [
		'login'        => '登入',
		'register'     => '註冊',
		'lostpassword' => '忘記密碼',
		'test'         => '測試',
	],
	'outcome' => [
		'allowed' => '已放行',
		'blocked' => '已擋下',
	],
	'reason'  => [
		'unreachable'    => '連不到 Cloudflare',
		'config_blocked' => '站台設定擋住了對 Cloudflare 的連線',
		'secret_invalid' => 'Secret Key 不正確',
	],
];
?>
<div class="ysca-wl-page ysca-whitelabel-settings">

	<?php if ( '' !== $notice ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--success">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( in_array( 'site_key_format', $field_errors, true ) ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--error">
			<p>Turnstile Site Key 格式不正確（只能有英文、數字、底線、連字號，10～100 個字），這個欄位沒有儲存。</p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( 'secret_format', $field_errors, true ) ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--error">
			<p>Turnstile Secret Key 格式不正確（只能有英文、數字、底線、連字號，10～100 個字），這個欄位沒有儲存，仍沿用原本的值。</p>
		</div>
	<?php endif; ?>
	<?php
	// v1.6.0：「進階：訪客 IP」存檔時擋下的欄位（行號只收數字）。
	$ip_lines = [ 'invalid' => [], 'wide' => [], 'warn' => [] ];
	foreach ( $field_errors as $code ) {
		if ( 1 === preg_match( '/^ip_trusted_(invalid|wide|warn)_(\d+)$/', (string) $code, $m ) ) {
			$ip_lines[ $m[1] ][] = (int) $m[2];
		}
	}
	?>
	<?php if ( [] !== $ip_lines['invalid'] || [] !== $ip_lines['wide'] || in_array( 'ip_trusted_too_many', $field_errors, true ) ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--error">
			<p>「信任的代理」沒有儲存，仍沿用原本的清單：
				<?php if ( [] !== $ip_lines['invalid'] ) : ?>第 <?php echo esc_html( implode( '、', $ip_lines['invalid'] ) ); ?> 行不是合法的 IP 或 CIDR。<?php endif; ?>
				<?php if ( [] !== $ip_lines['wide'] ) : ?>第 <?php echo esc_html( implode( '、', $ip_lines['wide'] ) ); ?> 行的網段太寬（IPv4 至少要 /8、IPv6 至少要 /32）。<?php endif; ?>
				<?php if ( in_array( 'ip_trusted_too_many', $field_errors, true ) ) : ?>超過 200 行。<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>
	<?php if ( [] !== $ip_lines['warn'] ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--warning">
			<p>「信任的代理」已儲存，但第 <?php echo esc_html( implode( '、', $ip_lines['warn'] ) ); ?> 行是寬於 /16 的公開網段：網段裡任何一台主機都能帶標頭指定訪客 IP。確定那整段都是你們的代理再留著。</p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( 'ip_custom_header', $field_errors, true ) ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--error">
			<p>自訂標頭的名稱不能用（只能有英文、數字、連字號，最多 64 個字；不能用 Host、Cookie、Authorization、Proxy-Authorization、Forwarded 與 Content- 開頭的標頭），這個欄位沒有儲存。</p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( 'ip_cf_needs_trust', $field_errors, true ) ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--error">
			<p>偵測方式選了 Cloudflare，但沒有勾「信任 Cloudflare 的 IP 範圍」，偵測方式沒有儲存。請先確認下面的條件、勾選信任，再選 Cloudflare。</p>
		</div>
	<?php endif; ?>
	<?php if ( in_array( 'ip_custom_needs_header', $field_errors, true ) ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--error">
			<p>偵測方式選了「自訂標頭」，但沒有填合法的標頭名稱，偵測方式沒有儲存。</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $settings_action ); ?>" class="ysca-wl-section" id="ys-am-wl-form">
		<input type="hidden" name="action" value="<?php echo esc_attr( \YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::ADMIN_POST_ACTION ); ?>">
		<?php wp_nonce_field( \YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::NONCE_ACTION ); ?>
		<input type="hidden" name="ys_am_tab" id="ys-am-wl-tab-input" value="<?php echo esc_attr( $active_tab ); ?>">

		<div class="ysca-wl-tabs ys-am-wl-tabs" role="tablist" aria-label="白牌設定分頁" data-ys-am-tabs hidden>
			<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
				<button type="button" role="tab" class="ysca-wl-tab"
					id="ys-am-wl-tab-<?php echo esc_attr( $tab_key ); ?>"
					aria-controls="ys-am-wl-panel-<?php echo esc_attr( $tab_key ); ?>"
					aria-selected="<?php echo $tab_key === $active_tab ? 'true' : 'false'; ?>"
					tabindex="<?php echo $tab_key === $active_tab ? '0' : '-1'; ?>"
					data-tab="<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( $tab_label ); ?></button>
			<?php endforeach; ?>
		</div>

		<?php /* ═══════════════════════ 分頁 1：後台品牌 ═══════════════════════ */ ?>
		<section class="ys-am-wl-panel" id="ys-am-wl-panel-brand" role="tabpanel" aria-labelledby="ys-am-wl-tab-brand" data-panel="brand">

			<div class="ysca-wl-notice ysca-wl-notice--info">
				<p>
					<strong>說明：</strong>
					LOGO 會作為「YS 選單管理」頂層選單的圖示，也可以放在左側選單最上方；Footer 文字會取代 wp-admin 所有頁面右下角的頁尾文字。
					未設定時，頂層選單使用預設圖示，頁尾維持 WordPress 預設。
				</p>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">後台 LOGO</h3>
				</div>
				<div class="ysca-wl-card__body">
					<p class="ysca-wl-field__hint">
						上傳的 LOGO 會作為「YS 選單管理」頂層選單的圖示，取代預設 dashicon。
					</p>

					<div class="ysca-wl-field">
						<div class="ysca-wl-whitelabel-logo-row">
							<div class="ysca-wl-whitelabel-logo-preview">
								<img id="ys-ec-logo-preview"
									src="<?php echo esc_attr( $logo_url ); ?>"
									alt=""
									class="ysca-wl-whitelabel-logo-img" width="180" <?php echo $has_logo ? '' : 'hidden'; ?>>
								<p id="ys-ec-logo-empty" class="ysca-wl-field__hint" <?php echo $has_logo ? 'hidden' : ''; ?>>
									（尚未上傳 LOGO）
								</p>
							</div>
							<div class="ysca-wl-field">
								<label for="ys-ec-whitelabel-logo-url" class="screen-reader-text">後台 LOGO URL</label>
								<input type="text" id="ys-ec-whitelabel-logo-url" name="logo_url"
									value="<?php echo esc_attr( $logo_url ); ?>"
									class="ysca-wl-input"
									placeholder="https://example.com/logo.png">
								<div class="ysca-wl-actions">
									<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost" id="ys-ec-upload-logo">
										從媒體庫選擇
									</button>
									<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost" id="ys-ec-remove-logo">
										移除 LOGO
									</button>
								</div>
								<p class="ysca-wl-field__hint">建議尺寸：寬度 ≤ 180px、高度 ≤ 80px（PNG 含透明度尤佳）。</p>
							</div>
						</div>
					</div>

					<div class="ysca-wl-field ys-am-wl-subfield">
						<label class="ys-am-toggle-field">
							<span class="ys-am-toggle">
								<input type="checkbox" name="show_menu_logo" value="yes" <?php checked( $show_menu_logo ); ?>>
								<span class="ys-am-toggle__slider"></span>
							</span>
							<span class="ys-am-toggle-field__text"><strong>在左側選單最上方顯示 LOGO 區塊</strong>第一個選單項之前多一塊品牌區，點了回到控制台；側欄收成圖示列時只留小圖。和上面的頂層選單圖示是兩回事，可以同時使用。</span>
						</label>
						<div class="ysca-wl-field" style="margin-top:12px;">
							<label for="ys-am-menu-logo-text" class="ysca-wl-field__label">LOGO 旁的站名文字（選填）</label>
							<input type="text" id="ys-am-menu-logo-text" name="menu_logo_text" maxlength="60"
								value="<?php echo esc_attr( $menu_logo_text ); ?>"
								class="ysca-wl-input"
								placeholder="<?php echo esc_attr( '例如：' . $site_name ); ?>">
							<p class="ysca-wl-field__hint">留空只顯示 LOGO；沒有上傳 LOGO、也沒有填這裡時，改顯示網站標題。顏色跟著側欄（啟用「原生選單樣式」時用它的側欄配色）。</p>
						</div>
					</div>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">後台 Footer 文字</h3>
				</div>
				<div class="ysca-wl-card__body">
					<p class="ysca-wl-field__hint">
						顯示於 wp-admin 所有頁面右下角的頁尾，例如版權聲明、品牌標語。
					</p>

					<div class="ysca-wl-field">
						<label for="ys-ec-whitelabel-footer-text" class="ysca-wl-field__label">Footer HTML 內容</label>
						<textarea id="ys-ec-whitelabel-footer-text" name="footer_text"
							rows="4" class="ysca-wl-textarea"
							placeholder="例如：&copy; 2026 Your Company Name. All rights reserved."><?php echo esc_textarea( $footer_text ); ?></textarea>
						<p class="ysca-wl-field__hint">
							允許基本 HTML（&lt;a&gt;、&lt;strong&gt;、&lt;br&gt; 等）。留空則完全不顯示 Footer。
						</p>
					</div>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">後台頁尾</h3>
				</div>
				<div class="ysca-wl-card__body">
					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="hide_footer" value="yes" <?php checked( $hide_footer ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>完全隱藏後台頁尾</strong>移除「感謝使用 WordPress」與右側版本號。</span>
					</label>
					<p class="ysca-wl-field__hint">勾選後整個 wp-admin 頁尾列都不顯示；此時上方「Footer 文字」不會出現。</p>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">介面外觀</h3>
				</div>
				<div class="ysca-wl-card__body">
					<div class="ysca-wl-field">
						<label for="ys-am-admin-bg-color" class="ysca-wl-field__label">整個後台背景色</label>
						<br>
						<input type="text" id="ys-am-admin-bg-color" name="admin_bg_color"
							value="<?php echo esc_attr( $admin_bg_color ); ?>"
							class="ys-am-color-field" data-default-color=""
							placeholder="#f0f0f1">
						<p class="ysca-wl-field__hint">設定後立即套用到整個 wp-admin 背景（不需啟用主題皮膚）。留空則恢復 WordPress 預設色。</p>
					</div>
					<p class="ysca-wl-field__hint">「隱藏左上角 WordPress LOGO」移到「Admin Bar」分頁。</p>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">資料清除</h3>
				</div>
				<div class="ysca-wl-card__body">
					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="purge_on_uninstall" value="yes" <?php checked( $purge_on_uninstall ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>刪除外掛時一併清除所有設定</strong>預設不勾＝刪除外掛會保留所有設定、重新安裝即沿用；勾選後刪除外掛才會一併清除。</span>
					</label>
					<p class="ysca-wl-field__hint">建議保持不勾，避免誤刪外掛時遺失所有選單與樣式設定。</p>
				</div>
			</div>
		</section>

		<?php /* ═══════════════════════ 分頁 2：Admin Bar ═══════════════════════ */ ?>
		<section class="ys-am-wl-panel" id="ys-am-wl-panel-adminbar" role="tabpanel" aria-labelledby="ys-am-wl-tab-adminbar" data-panel="adminbar">

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">Admin Bar 標誌與選單</h3>
				</div>
				<div class="ysca-wl-card__body">
					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="hide_wp_logo" value="yes" id="ys-am-hide-wp-logo" <?php checked( $hide_wp_logo ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>隱藏左上角 WordPress LOGO</strong>含關於、官網、文件等子選單，前後台皆生效。</span>
					</label>
					<p class="ysca-wl-field__hint">勾選後 admin bar 左上角的 WordPress 標誌會完全移除（不論下表怎麼勾）。</p>

					<div class="ys-am-wl-table-wrap">
						<table class="ysca-wl-table ys-am-wl-nodes">
							<caption class="screen-reader-text">Admin Bar 頂層節點：後台與前台各自要不要隱藏</caption>
							<thead>
								<tr>
									<th scope="col">節點</th>
									<th scope="col">後台隱藏</th>
									<th scope="col">前台隱藏</th>
									<th scope="col">偵測到</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( (array) ( $adminbar['nodes'] ?? [] ) as $node_id => $node ) : ?>
									<?php
									$node_id    = (string) $node_id;
									$node_label = (string) ( $node['label'] ?? $node_id );
									?>
									<tr data-node="<?php echo esc_attr( $node_id ); ?>">
										<th scope="row">
											<span class="ys-am-wl-node-label"><?php echo esc_html( $node_label ); ?></span>
											<code><?php echo esc_html( $node_id ); ?></code>
											<?php if ( 'wp-logo' === $node_id ) : ?>
												<span class="ysca-wl-field__hint ys-am-wl-node-note">上方開關開著時前後台都會隱藏。</span>
											<?php elseif ( 'my-account' === $node_id ) : ?>
												<span class="ysca-wl-field__hint ys-am-wl-node-note">藏起來後工具列上就沒有登出連結。</span>
											<?php endif; ?>
										</th>
										<td>
											<input type="checkbox" name="adminbar_hide_admin[]" value="<?php echo esc_attr( $node_id ); ?>"
												aria-label="<?php echo esc_attr( '後台隱藏：' . $node_label ); ?>" <?php checked( ! empty( $node['hide_admin'] ) ); ?>>
										</td>
										<td>
											<input type="checkbox" name="adminbar_hide_front[]" value="<?php echo esc_attr( $node_id ); ?>"
												aria-label="<?php echo esc_attr( '前台隱藏：' . $node_label ); ?>" <?php checked( ! empty( $node['hide_front'] ) ); ?>>
										</td>
										<td>
											<?php if ( ! empty( $node['seen_admin'] ) ) : ?>
												<span class="ysca-wl-badge">後台</span>
											<?php endif; ?>
											<?php if ( ! empty( $node['seen_front'] ) ) : ?>
												<span class="ysca-wl-badge">前台</span>
											<?php endif; ?>
											<?php if ( empty( $node['seen_admin'] ) && empty( $node['seen_front'] ) ) : ?>
												<span class="ysca-wl-field__hint">尚未偵測到</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<p class="ysca-wl-field__hint"><strong>隱藏不是停用功能：</strong>只是讓工具列上看不到，功能本身照常可以用（例如直接輸入網址仍然打得開）。真正要禁止的功能請調整角色權限。</p>
					<p class="ysca-wl-field__hint">「前台隱藏」只在下方選「載入，並套用前台隱藏清單」時生效。外掛加的節點：後台在打開這一頁時偵測；前台要用管理員帳號瀏覽過前台才偵測得到。手機版的選單按鈕不能隱藏（藏了就打不開後台選單）。</p>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">前台 Admin Bar</h3>
				</div>
				<div class="ysca-wl-card__body">
					<div class="ysca-wl-field">
						<label for="ys-am-frontend-adminbar" class="ysca-wl-field__label">前台的 Admin Bar</label>
						<select id="ys-am-frontend-adminbar" name="frontend_adminbar" class="ysca-wl-input">
							<option value="default" <?php selected( $adminbar['mode'] ?? 'default', 'default' ); ?>>照 WordPress 預設（依各帳號「瀏覽網站時顯示工具列」的設定）</option>
							<option value="hide" <?php selected( $adminbar['mode'] ?? 'default', 'hide' ); ?>>完全不載入（前台不輸出工具列，也不載入它的 CSS／JS）</option>
							<option value="filtered" <?php selected( $adminbar['mode'] ?? 'default', 'filtered' ); ?>>載入，並套用上方的「前台隱藏」清單</option>
						</select>
					</div>

					<fieldset class="ysca-wl-field ys-am-wl-fieldset">
						<legend class="ysca-wl-field__label">只讓這些角色在前台看到 Admin Bar</legend>
						<div class="ys-am-wl-checks">
							<?php foreach ( (array) ( $adminbar['role_names'] ?? [] ) as $role_slug => $role_name ) : ?>
								<label class="ys-am-wl-check">
									<input type="checkbox" name="frontend_adminbar_roles[]" value="<?php echo esc_attr( (string) $role_slug ); ?>"
										<?php checked( in_array( (string) $role_slug, (array) ( $adminbar['roles'] ?? [] ), true ) ); ?>>
									<?php echo esc_html( (string) $role_name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="ysca-wl-field__hint">都不勾＝不限制。選「完全不載入」時這裡沒有作用。只影響前台；後台的工具列不受影響。</p>
					</fieldset>
				</div>
			</div>
		</section>

		<?php /* ═══════════════════════ 分頁 3：登入頁 ═══════════════════════ */ ?>
		<section class="ys-am-wl-panel" id="ys-am-wl-panel-login" role="tabpanel" aria-labelledby="ys-am-wl-tab-login" data-panel="login">

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">自訂登入頁</h3>
				</div>
				<div class="ysca-wl-card__body">
					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="login_enabled" value="yes" <?php checked( ! empty( $login_page['enabled'] ) ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>啟用自訂登入頁</strong>wp-login.php 改成 YS CRM 登入頁的樣子：接近黑的深藍底、左側品牌區、右側白色大圓角登入卡片（膠囊形欄位與按鈕），手機上改成單欄。只改外觀與文字，登入流程、帳號密碼的驗證都不變；登入網址被其他外掛改掉（例如 WPS Hide Login）也照樣套用。</span>
					</label>
					<p class="ysca-wl-field__hint">
						檢視方式：用無痕視窗開啟 <a href="<?php echo esc_url( (string) ( $login_page['login_url'] ?? wp_login_url() ) ); ?>" target="_blank" rel="noopener noreferrer">登入頁</a>（已登入時打開登入頁會直接轉到後台）。忘記密碼、註冊、設定新密碼的畫面也套用同一套配色。
					</p>

					<div class="ysca-page-settings-grid ys-am-wl-grid">
						<div class="ysca-wl-field">
							<label for="ys-am-login-brand" class="ysca-wl-field__label">品牌名稱</label>
							<input type="text" id="ys-am-login-brand" name="login_brand" maxlength="120" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $login_page['brand'] ?? '' ) ); ?>"
								placeholder="<?php echo esc_attr( (string) ( $login_page['brand_default'] ?? '' ) ); ?>">
							<p class="ysca-wl-field__hint">左側的大標，手機上在卡片上方那一行。留空用網站標題。</p>
						</div>
						<div class="ysca-wl-field">
							<label for="ys-am-login-tagline" class="ysca-wl-field__label">標語</label>
							<input type="text" id="ys-am-login-tagline" name="login_tagline" maxlength="120" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $login_page['tagline'] ?? '' ) ); ?>"
								placeholder="例如：客戶・報價・工作・帳務 一站管理">
							<p class="ysca-wl-field__hint">左側大標下面那一行。留空不顯示；手機上不顯示。</p>
						</div>
						<div class="ysca-wl-field">
							<label for="ys-am-login-title" class="ysca-wl-field__label">標題</label>
							<input type="text" id="ys-am-login-title" name="login_title" maxlength="120" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $login_page['title'] ?? '' ) ); ?>"
								placeholder="<?php echo esc_attr( (string) ( $login_page['title_default'] ?? '登入' ) ); ?>">
							<p class="ysca-wl-field__hint">卡片上的標題。留空用「<?php echo esc_html( (string) ( $login_page['title_default'] ?? '登入' ) ); ?>」。</p>
						</div>
						<div class="ysca-wl-field">
							<label for="ys-am-login-subtitle" class="ysca-wl-field__label">副標</label>
							<input type="text" id="ys-am-login-subtitle" name="login_subtitle" maxlength="120" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $login_page['subtitle'] ?? '' ) ); ?>"
								placeholder="<?php echo esc_attr( (string) ( $login_page['subtitle_default'] ?? '' ) ); ?>">
							<p class="ysca-wl-field__hint">標題下面那一行。清空就不顯示。</p>
						</div>
						<div class="ysca-wl-field">
							<label for="ys-am-login-logo" class="ysca-wl-field__label">登入頁 LOGO</label>
							<input type="text" id="ys-am-login-logo" name="login_logo_url" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $login_page['logo_url'] ?? '' ) ); ?>"
								placeholder="https://example.com/logo.png">
							<div class="ysca-wl-actions">
								<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" data-ys-am-media="ys-am-login-logo">從媒體庫選擇</button>
								<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" data-ys-am-clear="ys-am-login-logo">清除</button>
							</div>
							<p class="ysca-wl-field__hint">留空沿用「後台品牌」分頁的後台 LOGO。只接受 http／https 網址。都沒有 LOGO 時，寬螢幕的左上角改放下方「樣式」填的左上字標；字標也沒填，就放網站標題（字距拉開；和品牌名稱一樣就不重複放）。</p>
						</div>
						<div class="ysca-wl-field">
							<label for="ys-am-login-bg" class="ysca-wl-field__label">背景圖</label>
							<input type="text" id="ys-am-login-bg" name="login_bg_url" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $login_page['bg_url'] ?? '' ) ); ?>"
								placeholder="https://example.com/background.jpg">
							<div class="ysca-wl-actions">
								<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" data-ys-am-media="ys-am-login-bg">從媒體庫選擇</button>
								<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" data-ys-am-clear="ys-am-login-bg">清除</button>
							</div>
							<p class="ysca-wl-field__hint">留空用下方「樣式」選的背景（預設是接近黑的深藍漸層，寬螢幕左邊兩團淡藍光暈）。圖片上會疊一層跟背景同色系的顏色，文字才看得清楚。</p>
						</div>
					</div>

					<div class="ysca-wl-field">
						<label for="ys-am-login-footer" class="ysca-wl-field__label">頁尾文字</label>
						<textarea id="ys-am-login-footer" name="login_footer_text" rows="3" class="ysca-wl-textarea"
							placeholder="例如：由 ○○ 公司開發與維護"><?php echo esc_textarea( (string) ( $login_page['footer'] ?? '' ) ); ?></textarea>
						<p class="ysca-wl-field__hint">可以用 &lt;a&gt;、&lt;br&gt;、&lt;strong&gt;、&lt;em&gt;、&lt;span&gt;，其他標籤與 style、onclick 之類的屬性會被拿掉。留空不顯示。</p>
					</div>

					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="login_back_link" value="yes" <?php checked( ! empty( $login_page['back_link'] ) ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>顯示「← 返回首頁」連結</strong>卡片下方回到網站首頁的連結。</span>
					</label>

					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="login_language_switcher" value="yes" <?php checked( ! empty( $login_page['language_switcher'] ) ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>顯示語言切換</strong>WordPress 放在登入頁最下方的語言選單。預設不顯示，登入頁用網站的語言；網站裝了其他語言、要讓人在登入前切換時再打開。</span>
					</label>
				</div>
			</div>

			<?php
			// v1.6.0：主色、背景、左上字標。
			$login_style  = (array) ( $login_page['style'] ?? [] );
			$style_colors = (array) ( $login_style['colors'] ?? [] );
			$style_notes  = (array) ( $login_style['notes'] ?? [] );
			$style_bg     = (string) ( $login_style['background'] ?? '' );
			?>
			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">樣式</h3>
				</div>
				<div class="ysca-wl-card__body">
					<p class="ysca-wl-field__hint">都不設定時，登入頁維持原本的樣子。</p>
					<div class="ysca-wl-field" style="margin-top:18px;">
						<label for="ys-am-login-primary" class="ysca-wl-field__label">主色</label>
						<input type="text" id="ys-am-login-primary" name="login_primary_color"
							value="<?php echo esc_attr( (string) ( $login_style['primary'] ?? '' ) ); ?>"
							class="ys-am-color-field" data-default-color=""
							placeholder="<?php echo esc_attr( (string) ( $login_style['primary_default'] ?? '#0072bd' ) ); ?>">
						<p class="ysca-wl-field__hint">登入鈕、表單裡的連結與焦點框的顏色。留空用 CRM 藍（#0072BD）。對比不夠時會自動調整：按鈕文字改用深色、連結與焦點框調深。</p>
						<?php if ( ! empty( $style_colors ) ) : ?>
							<p class="ysca-wl-field__hint">
								實際用色：按鈕 <?php echo esc_html( strtoupper( (string) ( $style_colors['button'] ?? '' ) ) ); ?>（文字 <?php echo esc_html( strtoupper( (string) ( $style_colors['button_text'] ?? '' ) ) ); ?>）、連結 <?php echo esc_html( strtoupper( (string) ( $style_colors['link'] ?? '' ) ) ); ?>、焦點框 <?php echo esc_html( strtoupper( (string) ( $style_colors['focus'] ?? '' ) ) ); ?>。
							</p>
						<?php endif; ?>
					</div>
					<fieldset class="ysca-wl-field ys-am-wl-fieldset">
						<legend class="ysca-wl-field__label">背景</legend>
						<label class="ys-am-wl-radio">
							<input type="radio" name="login_background" value="" <?php checked( '', $style_bg ); ?>>
							<span><strong>預設（深藍）</strong>接近黑的深藍漸層。</span>
						</label>
						<label class="ys-am-wl-radio">
							<input type="radio" name="login_background" value="light" <?php checked( 'light', $style_bg ); ?>>
							<span><strong>淺色</strong>淺灰藍的底、深色字。</span>
						</label>
						<label class="ys-am-wl-radio">
							<input type="radio" name="login_background" value="custom" <?php checked( 'custom', $style_bg ); ?>>
							<span><strong>自訂顏色</strong>用下面選的顏色當底，字的深淺依底色自動決定；沒選顏色時照預設。</span>
						</label>
						<input type="text" id="ys-am-login-background-color" name="login_background_color"
							value="<?php echo esc_attr( (string) ( $login_style['background_color'] ?? '' ) ); ?>"
							class="ys-am-color-field" data-default-color=""
							placeholder="#f5f8fb" aria-label="自訂背景顏色">
						<p class="ysca-wl-field__hint">有背景圖時，圖上疊的那一層跟著背景換。</p>
					</fieldset>
					<div class="ysca-wl-field" style="margin-top:18px;">
						<label for="ys-am-login-wordmark" class="ysca-wl-field__label">左上字標</label>
						<input type="text" id="ys-am-login-wordmark" name="login_wordmark" maxlength="40" class="ysca-wl-input"
							value="<?php echo esc_attr( (string) ( $login_style['wordmark'] ?? '' ) ); ?>"
							placeholder="例如：YANGSHEEP">
						<p class="ysca-wl-field__hint">寬螢幕左上角的文字。有 LOGO 時放 LOGO；留空時放網站標題（和品牌名稱一樣就不放）。手機上不顯示。</p>
					</div>
					<?php foreach ( $style_notes as $style_note ) : ?>
						<p class="ysca-wl-field__hint"><?php echo esc_html( (string) $style_note ); ?></p>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<?php /* ═══════════════════════ 分頁 4：登入防護（Turnstile） ═══════════════════════ */ ?>
		<section class="ys-am-wl-panel" id="ys-am-wl-panel-turnstile" role="tabpanel" aria-labelledby="ys-am-wl-tab-turnstile" data-panel="turnstile">

			<?php $ts_status = (array) ( $turnstile['status'] ?? [] ); ?>
			<?php if ( ! empty( $ts_status['active'] ) && empty( $ts_status['effective'] ) ) : ?>
				<div class="ysca-wl-notice ysca-wl-notice--error">
					<p><strong>已啟用，但</strong><?php echo esc_html( (string) ( $ts_status['config_problem'] ?? '' ) ); ?></p>
					<p><?php echo 'strict' === ( $turnstile['mode'] ?? '' ) ? '目前是嚴格模式：所有人（包括管理員）都登不進來。' : '目前是寬鬆模式：登入會被放行，只靠帳號密碼保護。'; ?>修好連線後，用下方「測試設定」驗證一次，這個提醒就會消失。</p>
				</div>
			<?php elseif ( ! empty( $ts_status['active'] ) ) : ?>
				<div class="ysca-wl-notice ysca-wl-notice--success">
					<p>
						<strong>已生效：</strong>
						<?php
						$active_scopes = [];
						foreach ( (array) ( $turnstile['scope'] ?? [] ) as $scope_key ) {
							$active_scopes[] = explode( '（', (string) ( $scope_labels[ $scope_key ] ?? $scope_key ) )[0];
						}
						echo esc_html( implode( '、', $active_scopes ) );
						?>
						表單會顯示 Turnstile，送出時伺服器端向 Cloudflare 驗證。連不到 Cloudflare 時：<?php echo 'strict' === ( $turnstile['mode'] ?? '' ) ? '擋下（嚴格）' : '放行並記錄（寬鬆）'; ?>。
					</p>
				</div>
			<?php else : ?>
				<div class="ysca-wl-notice ysca-wl-notice--info">
					<p><strong>目前沒有生效</strong>（登入頁不顯示 Turnstile，也不驗證）：</p>
					<?php foreach ( (array) ( $ts_status['reasons'] ?? [] ) as $reason ) : ?>
						<p>・<?php echo esc_html( (string) $reason ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">Cloudflare Turnstile</h3>
				</div>
				<div class="ysca-wl-card__body">
					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="turnstile_enabled" value="yes" <?php checked( ! empty( $turnstile['enabled'] ) ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>啟用 Turnstile</strong>Site Key 與 Secret Key 都填好才會生效；少一個就不顯示、也不擋登入。建議先存好兩把 Key、用下方「測試設定」確認通過，再打開這個開關。</span>
					</label>
					<p class="ysca-wl-field__hint"><strong>一個客戶站一個 widget：</strong>在 Cloudflare 為這個站建立自己的 Turnstile widget，並把網域白名單設成這個站的網域；不要多個站共用同一組金鑰。（需要額外比對網域時，可用 <code>ys_admin_menu_turnstile_expected_hostnames</code> filter 回傳網域清單，預設不比對。）</p>
					<p class="ysca-wl-field__hint">
						最後一次驗證成功：
						<?php
						$ts_last_ok = (int) ( $ts_status['last_ok'] ?? 0 );
						echo esc_html( $ts_last_ok > 0 ? wp_date( 'Y-m-d H:i:s', $ts_last_ok ) : '尚無紀錄' );
						?>
						（登入、註冊、忘記密碼或下方「測試設定」驗證成功時更新，最多每 5 分鐘記一次。）
					</p>

					<div class="ysca-page-settings-grid ys-am-wl-grid">
						<div class="ysca-wl-field">
							<label for="ys-am-turnstile-site-key" class="ysca-wl-field__label">Site Key（公開金鑰）</label>
							<input type="text" id="ys-am-turnstile-site-key" name="turnstile_site_key" class="ysca-wl-input"
								value="<?php echo esc_attr( (string) ( $turnstile['site_key'] ?? '' ) ); ?>"
								autocomplete="off" spellcheck="false" maxlength="100" placeholder="0x4AAAAAAA…">
							<p class="ysca-wl-field__hint">Cloudflare 後台 → Turnstile → 這個 widget 的 Site Key。</p>
						</div>
						<div class="ysca-wl-field">
							<label for="ys-am-turnstile-secret" class="ysca-wl-field__label">Secret Key（私密金鑰）</label>
							<input type="password" id="ys-am-turnstile-secret" name="turnstile_secret_key" class="ysca-wl-input"
								value="" autocomplete="new-password" spellcheck="false" maxlength="100"
								placeholder="<?php echo ! empty( $turnstile['has_secret'] ) ? '已設定（留白＝保留）' : '尚未設定'; ?>"
								aria-describedby="ys-am-turnstile-secret-hint">
							<p class="ysca-wl-field__hint" id="ys-am-turnstile-secret-hint">
								目前：<strong><?php echo ! empty( $turnstile['has_secret'] ) ? '已設定' : '未設定'; ?></strong>。留白＝保留已存的值；Secret 不會顯示在這裡，也不會放進匯出檔。
							</p>
							<label class="ys-am-wl-check">
								<input type="checkbox" name="turnstile_secret_clear" value="1">
								清除已儲存的 Secret Key（會忽略上面同時輸入的值）
							</label>
						</div>
					</div>

					<fieldset class="ysca-wl-field ys-am-wl-fieldset">
						<legend class="ysca-wl-field__label">套用範圍</legend>
						<div class="ys-am-wl-checks">
							<?php foreach ( $scope_labels as $scope_key => $scope_label ) : ?>
								<label class="ys-am-wl-check">
									<input type="checkbox" name="turnstile_scope[]" value="<?php echo esc_attr( $scope_key ); ?>"
										<?php checked( in_array( $scope_key, (array) ( $turnstile['scope'] ?? [] ), true ) ); ?>>
									<?php echo esc_html( $scope_label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="ysca-wl-field__hint">只涵蓋 wp-login.php 的表單送出（包括送到 wp-login.php 的前台 wp_login_form()）。XML-RPC、REST API、WooCommerce「我的帳號」等其他前台自訂登入表單，以及社群登入／SSO 的 GET 回呼，不在範圍內。</p>
						<p class="ysca-wl-field__hint">隱私提醒：啟用後，登入頁與前台使用 wp_login_form() 的頁面都會載入 Cloudflare 的腳本（challenges.cloudflare.com）。</p>
					</fieldset>

					<fieldset class="ysca-wl-field ys-am-wl-fieldset">
						<legend class="ysca-wl-field__label">伺服器連不到 Cloudflare 時</legend>
						<label class="ys-am-wl-radio">
							<input type="radio" name="turnstile_mode" value="lenient" <?php checked( 'strict' !== ( $turnstile['mode'] ?? 'lenient' ) ); ?>>
							<span><strong>寬鬆（預設）：放行並記錄。</strong>Cloudflare 停擺或主機暫時連不出去時，大家仍登得進來（管理員不會被鎖在外面）；代價是那段時間只靠帳號密碼保護，事件會記在下方。</span>
						</label>
						<label class="ys-am-wl-radio">
							<input type="radio" name="turnstile_mode" value="strict" <?php checked( 'strict' === ( $turnstile['mode'] ?? 'lenient' ) ); ?>>
							<span><strong>嚴格：擋下。</strong>連不到就不讓登入，安全性較高；但 Cloudflare 或主機對外連線出問題時，所有人（包括管理員）都登不進來，只能用下方的緊急關閉。</span>
						</label>
						<p class="ysca-wl-field__hint">「連不到」指連線失敗、逾時或 Cloudflare 回 5xx。Cloudflare 有回應且判定失敗（沒完成驗證、token 無效或用過、Secret Key 錯誤，以及重試後仍是 internal-error）時，兩種模式都會擋下。</p>
						<p class="ysca-wl-field__hint">站台設定擋住了對 Cloudflare 的連線（WP_HTTP_BLOCK_EXTERNAL、主機防火牆，或攔截 HTTP 請求的外掛）時，Turnstile 實際上沒有作用：寬鬆模式等於只靠帳號密碼、嚴格模式所有人都登不進來。這種情況上方與後台都會出現提醒。</p>
					</fieldset>

					<div class="ysca-wl-notice ys-am-wl-emergency">
						<p><strong>被擋在外面時：</strong>在 wp-config.php 加一行 <code>define( 'YS_ADMIN_MENU_TURNSTILE_DISABLED', true );</code>，或用 WP-CLI 執行 <code>wp option update ys_admin_menu_turnstile_enabled no</code>，Turnstile 就會暫停。</p>
						<?php if ( ! empty( $turnstile['constant_off'] ) ) : ?>
							<p><strong>目前 wp-config.php 已經定義了這個常數，Turnstile 暫停中。</strong></p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">測試設定</h3>
				</div>
				<div class="ysca-wl-card__body">
					<?php if ( ! empty( $turnstile['can_test'] ) ) : ?>
						<p class="ysca-wl-field__hint">用已儲存的 Site Key 與 Secret Key，在這個網域跑一次完整驗證（widget → 伺服器 → Cloudflare）。改過 Key 請先儲存再測。不會影響登入，也不會記錄。</p>
						<div class="ys-am-wl-test-widget">
							<div class="cf-turnstile"
								data-sitekey="<?php echo esc_attr( (string) $turnstile['site_key'] ); ?>"
								data-action="settings-test"
								data-response-field="false"
								data-callback="ysAmTurnstileTestToken"
								data-expired-callback="ysAmTurnstileTestExpired"
								data-error-callback="ysAmTurnstileTestError"></div>
						</div>
						<div class="ysca-wl-actions">
							<button type="button" class="ysca-wl-btn" id="ys-am-turnstile-test" disabled>驗證測試</button>
						</div>
						<p class="ys-am-wl-test-result" id="ys-am-turnstile-test-result" role="status" aria-live="polite"></p>
					<?php else : ?>
						<p class="ysca-wl-field__hint">先填好 Site Key 與 Secret Key 並儲存，這裡就會出現測試用的 widget。</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="ysca-wl-card">
				<div class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">最近事件</h3>
				</div>
				<div class="ysca-wl-card__body">
					<p class="ysca-wl-field__hint">只記錄「連不到 Cloudflare」「站台設定擋住了對 Cloudflare 的連線」與「Secret Key 不正確」，同一個原因每分鐘最多記一次，最多 20 筆；一般的驗證失敗（例如機器人沒完成驗證）不記錄。不記錄帳號、IP 與 Secret。</p>
					<?php if ( empty( $turnstile['log'] ) ) : ?>
						<p class="ysca-wl-field__hint">目前沒有紀錄。</p>
					<?php else : ?>
						<div class="ys-am-wl-table-wrap">
							<table class="ysca-wl-table ys-am-wl-log">
								<thead>
									<tr>
										<th scope="col">時間</th>
										<th scope="col">表單</th>
										<th scope="col">結果</th>
										<th scope="col">原因</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( (array) $turnstile['log'] as $row ) : ?>
										<tr>
											<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $row['time'] ) ); ?></td>
											<td><?php echo esc_html( $log_labels['context'][ $row['context'] ] ?? '—' ); ?></td>
											<td><?php echo esc_html( $log_labels['outcome'][ $row['outcome'] ] ?? '—' ); ?></td>
											<td><?php echo esc_html( $log_labels['reason'][ $row['reason'] ] ?? '—' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php
			// v1.6.0：進階：訪客 IP（預設收合；設定不是預設值、有提醒或存檔錯誤時展開）。
			$ip_diag     = (array) ( $ip_view['diagnose'] ?? [] );
			$ip_settings = (array) ( $ip_diag['settings'] ?? [] );
			$ip_values   = (array) ( $ip_diag['values'] ?? [] );
			$ip_results  = (array) ( $ip_diag['results'] ?? [] );
			$ip_hints    = (array) ( $ip_diag['hints'] ?? [] );
			$ip_current  = (array) ( $ip_diag['current'] ?? [] );
			$ip_source   = (string) ( $ip_settings['source'] ?? 'auto' );
			$ip_trust_cf = ! empty( $ip_settings['trust_cloudflare'] );
			$ip_custom   = (string) ( $ip_settings['custom_header'] ?? '' );
			$ip_remote   = (string) ( $ip_diag['remote'] ?? '' );
			$ip_errors   = array_filter( $field_errors, static fn( $c ) => 0 === strpos( (string) $c, 'ip_' ) );
			$ip_open     = [] !== $ip_errors || [] !== array_diff( $ip_hints, [ 'custom_header_warning' ] ) || 'auto' !== $ip_source || $ip_trust_cf || ! empty( $ip_view['turnstile_use'] );

			$ip_sources = [
				'auto'             => [ '自動', '用 REMOTE_ADDR。打開「信任 Cloudflare」時，從 Cloudflare 範圍來的請求改用 CF-Connecting-IP。不會讀其他標頭：站在其他代理後面時，請選對應的選項並信任那個代理。' ],
				'remote_addr'      => [ '只用 REMOTE_ADDR', '網站前面沒有代理、CDN、負載平衡器時最安全。有的話，所有訪客看起來會是同一個 IP。' ],
				'cf_connecting_ip' => [ 'Cloudflare（CF-Connecting-IP）', '要先打開下面的「信任 Cloudflare」。REMOTE_ADDR 在 Cloudflare 範圍時才採用。' ],
				'x_forwarded_for'  => [ 'X-Forwarded-For', 'REMOTE_ADDR 要在「信任的代理」清單裡；從右往左讀，跳過信任的代理。' ],
				'x_real_ip'        => [ 'X-Real-IP', 'REMOTE_ADDR 要在「信任的代理」清單裡；只收一個位址。' ],
				'custom'           => [ '自訂標頭', 'REMOTE_ADDR 要在「信任的代理」清單裡；照 X-Forwarded-For 的規則解析。只有前面的代理一定會改寫這個標頭時才安全。' ],
			];
			$ip_reason_text = [
				'invalid_remote'   => 'REMOTE_ADDR 不是合法的位址',
				'header'           => '從標頭取得',
				'remote'           => '用 REMOTE_ADDR',
				'foreign_worker'   => '用 REMOTE_ADDR：這是別的網站的 Cloudflare Worker 送來的',
				'header_missing'   => '用 REMOTE_ADDR：沒有這個標頭，或標頭的值不合法',
				'cf_not_trusted'   => '用 REMOTE_ADDR：沒有打開「信任 Cloudflare」',
				'not_cloudflare'   => '用 REMOTE_ADDR：REMOTE_ADDR 不是 Cloudflare 的位址',
				'no_custom_header' => '用 REMOTE_ADDR：還沒填自訂標頭的名稱',
				'not_trusted'      => '用 REMOTE_ADDR：REMOTE_ADDR 不在「信任的代理」清單裡，標頭沒有採用',
				'chain_stopped'    => '用 REMOTE_ADDR：標頭裡有不合法的段落，讀到那裡就停下',
			];
			$ip_explain = static function ( array $r ) use ( $ip_reason_text ): string {
				$ip     = '' !== (string) ( $r['ip'] ?? '' ) ? (string) $r['ip'] : '（沒有）';
				$reason = (string) ( $ip_reason_text[ (string) ( $r['reason'] ?? '' ) ] ?? '' );
				if ( 'chain_stopped' === ( $r['reason'] ?? '' ) && (int) ( $r['bad_segment'] ?? 0 ) > 0 ) {
					$reason .= '（從右邊數第 ' . (int) $r['bad_segment'] . ' 段）';
				} elseif ( 'header' === ( $r['reason'] ?? '' ) && 1 === preg_match( '/:(\d+)$/', (string) ( $r['source'] ?? '' ), $sm ) ) {
					$reason .= '（從右邊數第 ' . (int) $sm[1] . ' 個）';
				}
				return $ip . ( '' !== $reason ? '（' . $reason . '）' : '' );
			};
			$ip_value_text = static function ( string $v ): string {
				if ( '' === $v ) {
					return '沒有';
				}
				return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $v, 0, 200, '…' ) : substr( $v, 0, 200 );
			};
			$ip_flag = $ip_trust_cf ? 'yes' : 'no';
			?>
			<details class="ysca-wl-card ys-am-wl-details" id="ys-am-ip-card"<?php echo $ip_open ? ' open' : ''; ?>>
				<summary class="ysca-wl-card__header">
					<h3 class="ysca-wl-card__title">進階：訪客 IP</h3>
				</summary>
				<div class="ysca-wl-card__body">
					<p class="ysca-wl-field__hint">判斷訪客的 IP 用。網站前面沒有代理或 CDN 時，用「自動」就好；在 Cloudflare、負載平衡器或其他代理後面時，要選對應的方式並信任那個代理，不然所有訪客看起來會是同一個 IP。訪客自己帶的標頭不會被採用。</p>
					<p class="ys-am-wl-ip-now">
						用目前的設定，偵測到你的 IP 是
						<strong><code><?php echo esc_html( '' !== (string) ( $ip_current['ip'] ?? '' ) ? (string) $ip_current['ip'] : '（沒有）' ); ?></code></strong>。
						<?php if ( 'filter' === ( $ip_current['source'] ?? '' ) ) : ?>
							（有外掛用 <code>ys_admin_menu_client_ip</code> filter 改過）
						<?php endif; ?>
					</p>

					<div class="ys-am-wl-table-wrap">
						<table class="ysca-wl-table ys-am-wl-ip-table">
							<thead>
								<tr>
									<th scope="col">選項</th>
									<th scope="col">這次請求讀到的值</th>
									<th scope="col">選這個選項會得到</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $ip_sources as $ip_key => $ip_label ) : ?>
									<?php
									$ip_row_values = [];
									if ( in_array( $ip_key, [ 'auto', 'remote_addr' ], true ) ) {
										$ip_row_values[] = 'REMOTE_ADDR：' . $ip_value_text( (string) ( $ip_values['remote_addr'] ?? '' ) );
									}
									if ( in_array( $ip_key, [ 'auto', 'cf_connecting_ip' ], true ) ) {
										$ip_row_values[] = 'CF-Connecting-IP：' . $ip_value_text( (string) ( $ip_values['cf_connecting_ip'] ?? '' ) );
										$ip_row_values[] = 'CF-Worker：' . $ip_value_text( (string) ( $ip_values['cf_worker'] ?? '' ) );
										$ip_row_values[] = 'CF-Ray：' . $ip_value_text( (string) ( $ip_values['cf_ray'] ?? '' ) );
									}
									if ( 'x_forwarded_for' === $ip_key ) {
										$ip_row_values[] = $ip_value_text( (string) ( $ip_values['x_forwarded_for'] ?? '' ) );
									}
									if ( 'x_real_ip' === $ip_key ) {
										$ip_row_values[] = $ip_value_text( (string) ( $ip_values['x_real_ip'] ?? '' ) );
									}
									if ( 'custom' === $ip_key ) {
										$ip_row_values[] = '' !== $ip_custom ? $ip_custom . '：' . $ip_value_text( (string) ( $ip_values['custom'] ?? '' ) ) : '還沒填標頭名稱';
									}
									?>
									<tr<?php echo $ip_key === $ip_source ? ' class="is-current"' : ''; ?>>
										<th scope="row"><?php echo esc_html( $ip_label[0] ); ?><?php echo $ip_key === $ip_source ? '（目前）' : ''; ?></th>
										<td>
											<?php foreach ( $ip_row_values as $ip_line ) : ?>
												<code class="ys-am-wl-ip-value"><?php echo esc_html( $ip_line ); ?></code>
											<?php endforeach; ?>
										</td>
										<td><?php echo esc_html( $ip_explain( (array) ( $ip_results[ $ip_key ][ $ip_flag ] ?? [] ) ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="ysca-wl-field__hint">這張表只反映你自己這一次的請求。值超過 200 個字只顯示前面。</p>

					<?php foreach ( $ip_hints as $ip_hint ) : ?>
						<?php if ( 'internal_with_proxy_headers' === $ip_hint ) : ?>
							<div class="ysca-wl-notice ysca-wl-notice--info"><p>REMOTE_ADDR（<?php echo esc_html( $ip_remote ); ?>）是內部位址，又帶有代理的標頭，網站可能在代理後面。請選對應的偵測方式，並把那台代理加進「信任的代理」。</p></div>
						<?php elseif ( 'cloudflare_without_trust' === $ip_hint ) : ?>
							<div class="ysca-wl-notice ysca-wl-notice--info"><p>REMOTE_ADDR（<?php echo esc_html( $ip_remote ); ?>）是 Cloudflare 的位址：網站放在 Cloudflare 後面，但還沒打開「信任 Cloudflare」，所有訪客看起來會是 Cloudflare 的 IP。打開前請看下面的說明。</p></div>
						<?php elseif ( 'header_mode_untrusted_remote' === $ip_hint ) : ?>
							<div class="ysca-wl-notice ysca-wl-notice--info"><p>選了用標頭判斷，但 REMOTE_ADDR（<?php echo esc_html( $ip_remote ); ?>）不在「信任的代理」清單裡，所以標頭沒有採用。</p></div>
						<?php elseif ( 'chain_invalid_segment' === $ip_hint ) : ?>
							<div class="ysca-wl-notice ysca-wl-notice--info"><p>標頭裡有不合法的段落（從右邊數第 <?php echo esc_html( (string) (int) ( $ip_current['bad_segment'] ?? 0 ) ); ?> 段），讀到那裡就停下，用 REMOTE_ADDR。前面的代理可能沒有清掉訪客自己帶的標頭。</p></div>
						<?php elseif ( 'cf_ray_not_in_list' === $ip_hint ) : ?>
							<div class="ysca-wl-notice ysca-wl-notice--info"><p>請求帶有 CF-Ray，但 REMOTE_ADDR 不在內建的 Cloudflare 範圍裡：可能是前面還有別的代理，或內建清單（<?php echo esc_html( (string) ( $ip_diag['cloudflare_checked'] ?? '' ) ); ?> 核對）過期了。信任關係不會因此改變。</p></div>
						<?php endif; ?>
					<?php endforeach; ?>

					<fieldset class="ysca-wl-field ys-am-wl-fieldset">
						<legend class="ysca-wl-field__label">偵測方式</legend>
						<?php foreach ( $ip_sources as $ip_key => $ip_label ) : ?>
							<label class="ys-am-wl-radio">
								<input type="radio" name="ip_source" value="<?php echo esc_attr( $ip_key ); ?>" <?php checked( $ip_key, $ip_source ); ?>
									data-ys-am-ip-preview-no="<?php echo esc_attr( $ip_explain( (array) ( $ip_results[ $ip_key ]['no'] ?? [] ) ) ); ?>"
									data-ys-am-ip-preview-yes="<?php echo esc_attr( $ip_explain( (array) ( $ip_results[ $ip_key ]['yes'] ?? [] ) ) ); ?>">
								<span><strong><?php echo esc_html( $ip_label[0] ); ?>：</strong><?php echo esc_html( $ip_label[1] ); ?></span>
							</label>
						<?php endforeach; ?>
						<div class="ysca-wl-field ys-am-wl-subfield">
							<label for="ys-am-ip-custom-header" class="ysca-wl-field__label">自訂標頭的名稱</label>
							<input type="text" id="ys-am-ip-custom-header" name="ip_custom_header" class="ysca-wl-input" maxlength="64"
								value="<?php echo esc_attr( $ip_custom ); ?>" placeholder="True-Client-IP" spellcheck="false" autocomplete="off">
							<p class="ysca-wl-field__hint">這個標頭訪客自己也能帶。只有前面的代理一定會改寫它時才安全。例如 True-Client-IP 在非 Enterprise 的 Cloudflare 上，沒開對應的 Managed Transform 就會被偽造。</p>
						</div>
						<p class="ysca-wl-field__hint ys-am-wl-ip-preview" id="ys-am-ip-preview" role="status" aria-live="polite"></p>
					</fieldset>

					<div class="ysca-wl-field" style="margin-top:18px;">
						<label for="ys-am-ip-trusted" class="ysca-wl-field__label">信任的代理</label>
						<textarea id="ys-am-ip-trusted" name="ip_trusted_proxies" rows="4" class="ysca-wl-textarea" spellcheck="false"
							placeholder="10.0.0.2&#10;10.0.0.0/24&#10;2001:db8:1::/48"><?php echo esc_textarea( (string) ( $ip_view['trusted_raw'] ?? '' ) ); ?></textarea>
						<p class="ysca-wl-field__hint">一行一個 IP 或 CIDR，最多 200 行。只有 REMOTE_ADDR 在這份清單裡，才會看標頭。私有位址不會自動被信任。</p>
						<?php if ( in_array( 'internal_with_proxy_headers', $ip_hints, true ) && '' !== $ip_remote ) : ?>
							<div class="ysca-wl-actions">
								<button type="button" class="ysca-wl-btn ysca-wl-btn--ghost ysca-wl-btn--sm" data-ys-am-ip-trust="<?php echo esc_attr( $ip_remote ); ?>">信任這個代理（<?php echo esc_html( $ip_remote ); ?>）</button>
							</div>
						<?php endif; ?>
						<p class="ysca-wl-field__hint">只有這個位址真的是反向代理、而且一定會改寫那個標頭時才信任。路由器的 NAT 出口不是代理：信任它又選了標頭模式，內網任何人都能自帶標頭偽造 IP。</p>
					</div>

					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="ip_trust_cloudflare" value="yes" id="ys-am-ip-trust-cf" <?php checked( $ip_trust_cf ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>信任 Cloudflare 的 IP 範圍</strong>（內建清單，<?php echo esc_html( (string) ( $ip_diag['cloudflare_checked'] ?? '' ) ); ?> 核對）放在 Cloudflare 後面的站建議打開。Authenticated Origin Pulls（自有憑證）或 Tunnel 最安全；沒有的話，別的網站的 Cloudflare Worker 送來的請求不會採用 CF-Connecting-IP，但 Cloudflare 沒有保證 Worker 拿不掉它的識別標頭。</span>
					</label>

					<label class="ys-am-toggle-field">
						<span class="ys-am-toggle">
							<input type="checkbox" name="ip_turnstile_use" value="yes" <?php checked( ! empty( $ip_view['turnstile_use'] ) ); ?>>
							<span class="ys-am-toggle__slider"></span>
						</span>
						<span class="ys-am-toggle-field__text"><strong>Turnstile 也使用偵測到的 IP</strong>不勾＝照舊只把 REMOTE_ADDR 送給 Cloudflare 驗證。網站在代理後面、而且上面的偵測方式已經設好時再勾。</span>
					</label>
				</div>
			</details>
		</section>

		<div class="ysca-wl-actions">
			<button type="submit" class="ysca-wl-btn ysca-wl-btn--primary">儲存白牌設定</button>
		</div>
	</form>
</div>

<script>
jQuery(function ($) {
	var frame;
	$('#ys-ec-upload-logo').on('click', function (e) {
		e.preventDefault();
		if (frame) {
			frame.open();
			return;
		}
		frame = wp.media({
			title: '選擇白牌 LOGO',
			button: { text: '使用此圖片' },
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			if (attachment && attachment.url) {
				$('#ys-ec-whitelabel-logo-url').val(attachment.url);
				$('#ys-ec-logo-preview').attr('src', attachment.url).prop('hidden', false);
				$('#ys-ec-logo-empty').prop('hidden', true);
			}
		});
		frame.open();
	});

	$('#ys-ec-remove-logo').on('click', function (e) {
		e.preventDefault();
		$('#ys-ec-whitelabel-logo-url').val('');
		$('#ys-ec-logo-preview').attr('src', '').prop('hidden', true);
		$('#ys-ec-logo-empty').prop('hidden', false);
	});

	// 監聽 input 變動 — 同步預覽
	$('#ys-ec-whitelabel-logo-url').on('change input', function () {
		var url = $(this).val().trim();
		if (url) {
			$('#ys-ec-logo-preview').attr('src', url).prop('hidden', false);
			$('#ys-ec-logo-empty').prop('hidden', true);
		} else {
			$('#ys-ec-logo-preview').attr('src', '').prop('hidden', true);
			$('#ys-ec-logo-empty').prop('hidden', false);
		}
	});

	// 後台背景色 — WP 內建 color picker
	if ($.fn.wpColorPicker) {
		$('.ys-am-color-field').wpColorPicker();
	}
});
</script>
