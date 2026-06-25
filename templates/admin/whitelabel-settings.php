<?php
/**
 * 白牌設置頁面 template（v2.37.1 / v2.45.39 ADR-047 .ysca-wl-* 獨立樣式）
 *
 * 從 YSWhiteLabelAdmin::render_settings() 載入。
 *
 * 區塊：
 *   1. 後台 LOGO（上傳 / 預覽 / 移除）— 作為頂層選單圖示
 *   2. Footer 文字 — 取代 wp-admin 頁尾
 *
 * 變數：
 *   - $logo_url    string  目前 LOGO URL
 *   - $footer_text string  目前 Footer HTML
 *   - $notice      string  PRG 後 success message
 *
 * v2.45.39 ADR-047：全 markup 改 `.ysca-wl-*`、由 YSWhiteLabelAssets 載入獨立 CSS。
 *
 * @package YangSheep\Ecommerce\Templates
 */

defined( 'ABSPATH' ) || exit;

/** @var string $logo_url */
/** @var string $footer_text */
/** @var string $notice */

$has_logo = ( '' !== $logo_url );
$settings_action = admin_url( 'admin-post.php' );
?>
<div class="ysca-wl-page ysca-whitelabel-settings">

	<?php if ( '' !== $notice ) : ?>
		<div class="ysca-wl-notice ysca-wl-notice--success">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
	<?php endif; ?>

	<div class="ysca-wl-notice ysca-wl-notice--info">
		<p>
			<strong>說明：</strong>
			LOGO 會作為「YS 選單管理」頂層選單的圖示；Footer 文字會取代 wp-admin 所有頁面右下角的頁尾文字。
			未設定時，頂層選單使用預設圖示，頁尾維持 WordPress 預設。
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( $settings_action ); ?>" class="ysca-wl-section">
		<input type="hidden" name="action" value="<?php echo esc_attr( \YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::ADMIN_POST_ACTION ); ?>">
		<?php wp_nonce_field( \YangSheep\AdminMenu\Admin\YSWhiteLabelAdmin::NONCE_ACTION ); ?>

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
				<label class="ys-am-toggle-field">
					<span class="ys-am-toggle">
						<input type="checkbox" name="hide_wp_logo" value="yes" <?php checked( $hide_wp_logo ); ?>>
						<span class="ys-am-toggle__slider"></span>
					</span>
					<span class="ys-am-toggle-field__text"><strong>隱藏左上角 WordPress LOGO</strong>含關於、官網、文件等子選單，前後台皆生效。</span>
				</label>
				<p class="ysca-wl-field__hint">勾選後 admin bar 左上角的 WordPress 標誌會完全移除。</p>

				<div class="ysca-wl-field" style="margin-top:16px;">
					<label for="ys-am-admin-bg-color" class="ysca-wl-field__label">整個後台背景色</label>
					<br>
					<input type="text" id="ys-am-admin-bg-color" name="admin_bg_color"
						value="<?php echo esc_attr( $admin_bg_color ); ?>"
						class="ys-am-color-field" data-default-color=""
						placeholder="#f0f0f1">
					<p class="ysca-wl-field__hint">設定後立即套用到整個 wp-admin 背景（不需啟用主題皮膚）。留空則恢復 WordPress 預設色。</p>
				</div>
			</div>
		</div>

		<div class="ysca-wl-actions">
			<button type="submit" class="ysca-wl-btn ysca-wl-btn--primary">儲存白牌設置</button>
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
