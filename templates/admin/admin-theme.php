<?php
/**
 * Native WordPress admin menu theme settings.
 *
 * @package YangSheep\Ecommerce\Templates
 */

defined( 'ABSPATH' ) || exit;

/** @var string $saved_notice */
/** @var string $reset_notice */
/** @var array  $cfg */

$color_fields = [
	[
		'key'         => 'admin_main_bg',
		'label'       => '整個後台主背景色',
		'description' => 'wp-admin 整體工作區（內容區）背景色，套用到所有後台頁面。',
	],
	[
		'key'         => 'menu_bg',
		'label'       => 'MENU 背景色',
		'description' => '左側 WordPress 原生選單背景色。',
	],
	[
		'key'         => 'menu_text_color',
		'label'       => 'MENU 文字顏色',
		'description' => '主選單文字顏色，建議與 MENU 背景形成足夠對比。',
	],
	[
		'key'         => 'menu_icon_color',
		'label'       => 'MENU 圖示顏色',
		'description' => '左側選單 dashicon 圖示顏色。',
	],
	[
		'key'         => 'submenu_bg',
		'label'       => '子選單背景色',
		'description' => '左側子選單與展開選單背景色。',
	],
	[
		'key'         => 'submenu_text_color',
		'label'       => '子選單文字顏色',
		'description' => '左側子選單項目的文字顏色。',
	],
	[
		'key'         => 'admin_bar_bg',
		'label'       => 'ADMIN BAR 背景色',
		'description' => '上方 WordPress admin bar 背景色。',
	],
	[
		'key'         => 'admin_bar_text_color',
		'label'       => 'ADMIN BAR 文字顏色',
		'description' => '上方 WordPress admin bar 文字與圖示顏色。',
	],
	[
		'key'         => 'menu_hover_bg',
		'label'       => 'Hover 背景色',
		'description' => '選單項目 hover、focus 與目前選取狀態背景色。',
	],
	[
		'key'         => 'menu_hover_text_color',
		'label'       => 'Hover 文字顏色',
		'description' => '選單項目 hover、focus 與目前選取狀態文字顏色。',
	],
];

$number_fields = [
	[
		'key'         => 'menu_font_size',
		'label'       => '主選單字級',
		'min'         => '10',
		'max'         => '20',
		'step'        => '1',
		'suffix'      => 'px',
		'description' => '建議 13-15px。',
	],
	[
		'key'         => 'menu_line_height',
		'label'       => '主選單行高',
		'min'         => '1.0',
		'max'         => '2.5',
		'step'        => '0.1',
		'suffix'      => '',
		'description' => '建議 1.4-1.6。',
	],
	[
		'key'         => 'submenu_font_size',
		'label'       => '子選單字級',
		'min'         => '10',
		'max'         => '18',
		'step'        => '1',
		'suffix'      => 'px',
		'description' => '建議 12-14px。',
	],
	[
		'key'         => 'submenu_line_height',
		'label'       => '子選單行高',
		'min'         => '1.0',
		'max'         => '2.5',
		'step'        => '0.1',
		'suffix'      => '',
		'description' => '建議 1.3-1.5。',
	],
];
?>
<div class="wrap ys-ec-admin-theme-page">
	<?php echo \YangSheep\AdminMenu\Admin\YSMenuPage::pagehead_html( 'admin-appearance', '原生選單樣式', '自訂側欄與 admin bar 的配色、字級與行高，含即時預覽與全站皮膚套用。' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pagehead_html 內已逐欄 escape ?>

	<?php if ( ! empty( $saved_notice ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $saved_notice ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $reset_notice ) ) : ?>
		<div class="notice notice-warning is-dismissible">
			<p><?php echo esc_html( $reset_notice ); ?></p>
		</div>
	<?php endif; ?>

	<div class="notice notice-info ysca-notice-spaced">
		<p>
			<strong>說明：</strong>
			本頁設定會套用到 WordPress 原生後台選單。系統只調整顏色、文字大小與捲動條視覺，不會改變原生選單寬度、子選單定位或 WordPress 選單功能。
		</p>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( \YangSheep\AdminMenu\Admin\YSAdminThemeAdmin::NONCE_ACTION, \YangSheep\AdminMenu\Admin\YSAdminThemeAdmin::NONCE_FIELD ); ?>

		<div class="ys-ec-card">
			<h3>
				<span class="dashicons dashicons-admin-settings"></span>
				啟用
			</h3>
			<div class="inside">
				<div class="ys-ec-form-group">
					<label for="enabled">
						<input type="checkbox" id="enabled" name="enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
						<strong>啟用自訂原生選單樣式</strong>
					</label>
					<p class="description">
						預設啟用。關閉後會回到 WordPress 原生外觀；開啟時會套用到所有 wp-admin 頁面。
					</p>
				</div>
			</div>
		</div>

		<div class="ys-ec-card ysca-card-spaced">
			<h3>
				<span class="dashicons dashicons-admin-appearance"></span>
				顏色
			</h3>
			<div class="inside">
				<p class="description ysca-description-flush">
					所有顏色欄位可使用 WordPress 原生 color picker，也可直接輸入 hex。
				</p>

				<?php foreach ( $color_fields as $field ) : ?>
					<?php
					$key   = (string) $field['key'];
					$value = isset( $cfg[ $key ] ) ? (string) $cfg[ $key ] : '';
					?>
					<div class="ys-ec-form-group">
						<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( (string) $field['label'] ); ?></label>
						<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="ys-ec-color-picker"
							data-default-color="<?php echo esc_attr( $value ); ?>">
						<p class="description"><?php echo esc_html( (string) $field['description'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="ys-ec-card ysca-card-spaced">
			<h3>
				<span class="dashicons dashicons-editor-textcolor"></span>
				文字大小
			</h3>
			<div class="inside">
				<p class="description ysca-description-flush">
					此區只調整文字大小與行高，不調整 WordPress 原生選單寬度。
				</p>

				<div class="ys-ec-form-row ysca-inline-row ysca-inline-row--wide">
					<?php foreach ( $number_fields as $field ) : ?>
						<?php
						$key   = (string) $field['key'];
						$value = isset( $cfg[ $key ] ) ? (string) $cfg[ $key ] : '';
						?>
						<div class="ys-ec-form-group">
							<label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( (string) $field['label'] ); ?></label>
							<input type="number" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
								value="<?php echo esc_attr( $value ); ?>"
								min="<?php echo esc_attr( (string) $field['min'] ); ?>"
								max="<?php echo esc_attr( (string) $field['max'] ); ?>"
								step="<?php echo esc_attr( (string) $field['step'] ); ?>"
								class="ysca-field--xs">
							<p class="description">
								<?php echo esc_html( (string) $field['description'] ); ?>
								<?php if ( '' !== $field['suffix'] ) : ?>
									<?php echo esc_html( (string) $field['suffix'] ); ?>
								<?php endif; ?>
							</p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<p class="submit">
			<button type="submit" class="ysca-wl-btn ysca-wl-btn--primary">
				<span class="dashicons dashicons-saved ysca-button-icon"></span>
				儲存設定
			</button>
			<button type="submit" name="ys_ec_admin_theme_reset" value="1" class="ysca-wl-btn ysca-wl-btn--ghost" id="ys-ec-reset-theme"
				data-ys-confirm="確定要將原生選單樣式重設為預設值？">
				<span class="dashicons dashicons-image-rotate ysca-button-icon"></span>
				重設
			</button>
		</p>
	</form>
</div>
