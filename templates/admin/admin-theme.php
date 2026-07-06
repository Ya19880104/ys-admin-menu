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
	[
		'key'         => 'opensub_bg',
		'label'       => '展開選單背景色',
		'description' => '手動展開某個主選單時，該選單那一整列的背景色；留空＝使用上方 Hover 背景色。',
	],
	[
		'key'         => 'current_bg',
		'label'       => '運作中選單背景色',
		'description' => '目前所在頁面對應的選單（運作中）那一列的背景色，可設成與「展開選單背景色」不同以資區隔；留空＝使用上方 Hover 背景色。',
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

		<div class="ys-ec-card ysca-card-spaced">
			<h3>
				<span class="dashicons dashicons-tag"></span>
				分組標籤樣式
			</h3>
			<div class="inside">
				<p class="description ysca-description-flush">
					「分組標籤」＝在選單排序頁插入的「帶標題分隔列」，會在側欄顯示為不可點的區塊小標；此區可調整其外觀，且不套用 hover 效果。
				</p>
				<div class="ys-ec-form-group">
					<label for="section_label_color">標籤文字顏色</label>
					<input type="text" id="section_label_color" name="section_label_color" value="<?php echo esc_attr( (string) ( $cfg['section_label_color'] ?? '' ) ); ?>" class="ys-ec-color-picker" data-default-color="">
					<p class="description">留空＝繼承選單文字色並淡化。</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_bg">標籤背景顏色</label>
					<input type="text" id="section_label_bg" name="section_label_bg" value="<?php echo esc_attr( (string) ( $cfg['section_label_bg'] ?? '' ) ); ?>" class="ys-ec-color-picker" data-default-color="">
					<p class="description">留空＝透明。</p>
				</div>
				<div class="ys-ec-form-row ysca-inline-row ysca-inline-row--wide">
				<div class="ys-ec-form-group">
					<label for="section_label_font_size">字級</label>
					<input type="number" id="section_label_font_size" name="section_label_font_size" value="<?php echo esc_attr( (string) ( $cfg['section_label_font_size'] ?? 11 ) ); ?>" min="8" max="24" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_font_weight">粗細</label>
					<input type="number" id="section_label_font_weight" name="section_label_font_weight" value="<?php echo esc_attr( (string) ( $cfg['section_label_font_weight'] ?? 700 ) ); ?>" min="100" max="900" step="100" class="ysca-field--xs">
					<p class="description">100–900</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_padding_top">上留白</label>
					<input type="number" id="section_label_padding_top" name="section_label_padding_top" value="<?php echo esc_attr( (string) ( $cfg['section_label_padding_top'] ?? 7 ) ); ?>" min="0" max="40" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_padding_right">右留白</label>
					<input type="number" id="section_label_padding_right" name="section_label_padding_right" value="<?php echo esc_attr( (string) ( $cfg['section_label_padding_right'] ?? 12 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_padding_bottom">下留白</label>
					<input type="number" id="section_label_padding_bottom" name="section_label_padding_bottom" value="<?php echo esc_attr( (string) ( $cfg['section_label_padding_bottom'] ?? 7 ) ); ?>" min="0" max="40" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_padding_left">左留白</label>
					<input type="number" id="section_label_padding_left" name="section_label_padding_left" value="<?php echo esc_attr( (string) ( $cfg['section_label_padding_left'] ?? 12 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				</div>
				<p class="description ysca-description-flush" style="margin-top:4px;">外距（與相鄰選單項的間隔，上下常用）</p>
				<div class="ys-ec-form-row ysca-inline-row ysca-inline-row--wide">
				<div class="ys-ec-form-group">
					<label for="section_label_margin_top">上外距</label>
					<input type="number" id="section_label_margin_top" name="section_label_margin_top" value="<?php echo esc_attr( (string) ( $cfg['section_label_margin_top'] ?? 10 ) ); ?>" min="0" max="80" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_margin_right">右外距</label>
					<input type="number" id="section_label_margin_right" name="section_label_margin_right" value="<?php echo esc_attr( (string) ( $cfg['section_label_margin_right'] ?? 0 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_margin_bottom">下外距</label>
					<input type="number" id="section_label_margin_bottom" name="section_label_margin_bottom" value="<?php echo esc_attr( (string) ( $cfg['section_label_margin_bottom'] ?? 4 ) ); ?>" min="0" max="80" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="section_label_margin_left">左外距</label>
					<input type="number" id="section_label_margin_left" name="section_label_margin_left" value="<?php echo esc_attr( (string) ( $cfg['section_label_margin_left'] ?? 0 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				</div>
			</div>
		</div>

		<div class="ys-ec-card ysca-card-spaced">
			<h3>
				<span class="dashicons dashicons-menu"></span>
				選單項留白（間距）
			</h3>
			<div class="inside">
				<p class="description ysca-description-flush">
					調整左側一般選單項（非分組標籤）的內距上下左右，套用於啟用皮膚時；預設上下 0、左右 10。
				</p>
				<div class="ys-ec-form-row ysca-inline-row ysca-inline-row--wide">
				<div class="ys-ec-form-group">
					<label for="menu_item_padding_top">上留白</label>
					<input type="number" id="menu_item_padding_top" name="menu_item_padding_top" value="<?php echo esc_attr( (string) ( $cfg['menu_item_padding_top'] ?? 0 ) ); ?>" min="0" max="40" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="menu_item_padding_right">右留白</label>
					<input type="number" id="menu_item_padding_right" name="menu_item_padding_right" value="<?php echo esc_attr( (string) ( $cfg['menu_item_padding_right'] ?? 10 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="menu_item_padding_bottom">下留白</label>
					<input type="number" id="menu_item_padding_bottom" name="menu_item_padding_bottom" value="<?php echo esc_attr( (string) ( $cfg['menu_item_padding_bottom'] ?? 0 ) ); ?>" min="0" max="40" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="menu_item_padding_left">左留白</label>
					<input type="number" id="menu_item_padding_left" name="menu_item_padding_left" value="<?php echo esc_attr( (string) ( $cfg['menu_item_padding_left'] ?? 10 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				</div>
			</div>
		</div>

		<div class="ys-ec-card ysca-card-spaced">
			<h3>
				<span class="dashicons dashicons-editor-ul"></span>
				子選單項（展開後的選項）
			</h3>
			<div class="inside">
				<p class="description ysca-description-flush">
					調整展開子選單內各選項的留白與 hover 背景色；子選單整體「背景色」請用上方「顏色」區的「子選單背景色」。
				</p>
				<div class="ys-ec-form-group">
					<label for="submenu_hover_bg">子選項 hover 背景色</label>
					<input type="text" id="submenu_hover_bg" name="submenu_hover_bg" value="<?php echo esc_attr( (string) ( $cfg['submenu_hover_bg'] ?? '' ) ); ?>" class="ys-ec-color-picker" data-default-color="">
					<p class="description">滑鼠移到子選項時的背景色；留空＝無 hover 效果。</p>
				</div>
				<div class="ys-ec-form-row ysca-inline-row ysca-inline-row--wide">
				<div class="ys-ec-form-group">
					<label for="submenu_item_padding_top">上留白</label>
					<input type="number" id="submenu_item_padding_top" name="submenu_item_padding_top" value="<?php echo esc_attr( (string) ( $cfg['submenu_item_padding_top'] ?? 6 ) ); ?>" min="0" max="40" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="submenu_item_padding_right">右留白</label>
					<input type="number" id="submenu_item_padding_right" name="submenu_item_padding_right" value="<?php echo esc_attr( (string) ( $cfg['submenu_item_padding_right'] ?? 12 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="submenu_item_padding_bottom">下留白</label>
					<input type="number" id="submenu_item_padding_bottom" name="submenu_item_padding_bottom" value="<?php echo esc_attr( (string) ( $cfg['submenu_item_padding_bottom'] ?? 6 ) ); ?>" min="0" max="40" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
				<div class="ys-ec-form-group">
					<label for="submenu_item_padding_left">左留白</label>
					<input type="number" id="submenu_item_padding_left" name="submenu_item_padding_left" value="<?php echo esc_attr( (string) ( $cfg['submenu_item_padding_left'] ?? 12 ) ); ?>" min="0" max="60" step="1" class="ysca-field--xs">
					<p class="description">px</p>
				</div>
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
