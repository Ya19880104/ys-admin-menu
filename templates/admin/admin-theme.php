<?php
/**
 * Native WordPress admin menu theme settings.
 *
 * v1.8.0：版面改用 YS 後台中央樣式的元件（頁首、卡片、欄位、開關、儲存列），欄位與存檔不變。
 *
 * @package YangSheep\Ecommerce\Templates
 */

use YangSheep\AdminMenu\Admin\YSAdminThemeAdmin;
use YangSheep\AdminMenu\Admin\YSMenuPage;

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
		'step'        => '0.05',
		'suffix'      => '',
		'description' => '建議 1.4-1.6。',
	],
];

/** 色彩欄位：標籤、WordPress 色彩挑選器、說明。 */
$ys_am_color = static function ( string $key, string $label, string $value, string $default_color, string $hint ): void {
	?>
	<div class="ysca-field">
		<label class="ysca-field__label" for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
		<div>
			<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="ys-ec-color-picker"
				data-default-color="<?php echo esc_attr( $default_color ); ?>">
		</div>
		<p class="ysca-field__hint"><?php echo esc_html( $hint ); ?></p>
	</div>
	<?php
};

/** 數字欄位（字級、粗細、留白…），放在 .ys-admin-fields 裡一排排列。 */
$ys_am_number = static function ( string $key, string $label, string $value, string $min, string $max, string $step, string $hint ): void {
	?>
	<div class="ysca-field">
		<label class="ysca-field__label" for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
		<input type="number" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>"
			class="ysca-input ys-admin-w-xs">
		<p class="ysca-field__hint"><?php echo esc_html( $hint ); ?></p>
	</div>
	<?php
};

$ys_am_value = static fn( string $key, $fallback ): string => (string) ( $cfg[ $key ] ?? $fallback );

echo YSMenuPage::page_open( '原生選單樣式', '自訂側欄與 admin bar 的配色、字級與行高，含即時預覽與全站皮膚套用。', 'ys-ec-admin-theme-page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_open 內已逐欄 escape
?>

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

	<div class="ys-admin-note ys-admin-note--info">
		<p>
			<strong>說明：</strong>
			本頁設定會套用到 WordPress 原生後台選單。啟用後側欄改成自訂配色，桌機寬版的側欄寬度是 300px，子選單改成點右側箭頭收放；側欄收成圖示列時（按了「收合選單」或視窗較窄）維持 WordPress 原生的飛出子選單。停用就完全回到 WordPress 原生外觀與操作。
		</p>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( YSAdminThemeAdmin::NONCE_ACTION, YSAdminThemeAdmin::NONCE_FIELD ); ?>

		<section class="ysca-card">
			<div class="ysca-card__head"><h3 class="ysca-card__title">啟用</h3></div>
			<div class="ysca-card__body">
				<label class="ys-admin-switch" for="enabled">
					<span class="ysca-toggle">
						<input type="checkbox" id="enabled" name="enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
						<span class="slider"></span>
					</span>
					<span class="ys-admin-switch__text"><strong>啟用自訂原生選單樣式</strong>預設啟用。關閉後會回到 WordPress 原生外觀；開啟時會套用到所有 wp-admin 頁面。</span>
				</label>
			</div>
		</section>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">顏色</h3><p class="ysca-card__sub">所有顏色欄位可使用 WordPress 原生 color picker，也可直接輸入 hex。</p></div></div>
			<div class="ysca-card__body ys-admin-grid-2">
				<?php
				foreach ( $color_fields as $field ) {
					$key   = (string) $field['key'];
					$value = isset( $cfg[ $key ] ) ? (string) $cfg[ $key ] : '';
					$ys_am_color( $key, (string) $field['label'], $value, $value, (string) $field['description'] );
				}
				?>
			</div>
		</section>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">文字大小（主選單）</h3><p class="ysca-card__sub">此區調整主選單的文字大小與行高；子選單項的字級／粗細／行高請至下方「子選單項」區塊調整。</p></div></div>
			<div class="ysca-card__body">
				<div class="ys-admin-fields">
					<?php
					foreach ( $number_fields as $field ) {
						$key  = (string) $field['key'];
						$hint = (string) $field['description'] . ( '' !== $field['suffix'] ? ' ' . (string) $field['suffix'] : '' );
						$ys_am_number( $key, (string) $field['label'], isset( $cfg[ $key ] ) ? (string) $cfg[ $key ] : '', (string) $field['min'], (string) $field['max'], (string) $field['step'], $hint );
					}
					?>
				</div>
			</div>
		</section>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">分組標籤樣式</h3><p class="ysca-card__sub">「分組標籤」＝在選單排序頁插入的「帶標題分隔列」，會在側欄顯示為不可點的區塊小標；此區可調整其外觀，且不套用 hover 效果。</p></div></div>
			<div class="ysca-card__body">
				<div class="ys-admin-grid-2">
					<?php
					$ys_am_color( 'section_label_color', '標籤文字顏色', $ys_am_value( 'section_label_color', '' ), '', '留空＝繼承選單文字色並淡化。' );
					$ys_am_color( 'section_label_bg', '標籤背景顏色', $ys_am_value( 'section_label_bg', '' ), '', '留空＝透明。' );
					?>
				</div>
				<div class="ys-admin-fields">
					<?php
					$ys_am_number( 'section_label_font_size', '字級', $ys_am_value( 'section_label_font_size', 11 ), '8', '24', '1', 'px' );
					$ys_am_number( 'section_label_font_weight', '粗細', $ys_am_value( 'section_label_font_weight', 700 ), '100', '900', '100', '100–900' );
					$ys_am_number( 'section_label_padding_top', '上留白', $ys_am_value( 'section_label_padding_top', 7 ), '0', '40', '1', 'px' );
					$ys_am_number( 'section_label_padding_right', '右留白', $ys_am_value( 'section_label_padding_right', 12 ), '0', '60', '1', 'px' );
					$ys_am_number( 'section_label_padding_bottom', '下留白', $ys_am_value( 'section_label_padding_bottom', 7 ), '0', '40', '1', 'px' );
					$ys_am_number( 'section_label_padding_left', '左留白', $ys_am_value( 'section_label_padding_left', 12 ), '0', '60', '1', 'px' );
					?>
				</div>
				<p class="ysca-field__hint">外距（與相鄰選單項的間隔，上下常用）</p>
				<div class="ys-admin-fields">
					<?php
					$ys_am_number( 'section_label_margin_top', '上外距', $ys_am_value( 'section_label_margin_top', 10 ), '0', '80', '1', 'px' );
					$ys_am_number( 'section_label_margin_right', '右外距', $ys_am_value( 'section_label_margin_right', 0 ), '0', '60', '1', 'px' );
					$ys_am_number( 'section_label_margin_bottom', '下外距', $ys_am_value( 'section_label_margin_bottom', 4 ), '0', '80', '1', 'px' );
					$ys_am_number( 'section_label_margin_left', '左外距', $ys_am_value( 'section_label_margin_left', 0 ), '0', '60', '1', 'px' );
					?>
				</div>
			</div>
		</section>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">選單項留白（間距）</h3><p class="ysca-card__sub">調整左側一般選單項（非分組標籤）的內距上下左右，套用於啟用皮膚時；預設上下 0、左右 10。</p></div></div>
			<div class="ysca-card__body">
				<div class="ys-admin-fields">
					<?php
					$ys_am_number( 'menu_item_padding_top', '上留白', $ys_am_value( 'menu_item_padding_top', 0 ), '0', '40', '1', 'px' );
					$ys_am_number( 'menu_item_padding_right', '右留白', $ys_am_value( 'menu_item_padding_right', 10 ), '0', '60', '1', 'px' );
					$ys_am_number( 'menu_item_padding_bottom', '下留白', $ys_am_value( 'menu_item_padding_bottom', 0 ), '0', '40', '1', 'px' );
					$ys_am_number( 'menu_item_padding_left', '左留白', $ys_am_value( 'menu_item_padding_left', 10 ), '0', '60', '1', 'px' );
					?>
				</div>
			</div>
		</section>

		<section class="ysca-card">
			<div class="ysca-card__head"><div><h3 class="ysca-card__title">子選單項（展開後的選項）</h3><p class="ysca-card__sub">調整展開子選單內各選項的文字大小、粗細、行高、留白與 hover 背景色；子選單整體「背景色」與「文字顏色」請用上方「顏色」區。</p></div></div>
			<div class="ysca-card__body">
				<?php $ys_am_color( 'submenu_hover_bg', '子選項 hover 背景色', $ys_am_value( 'submenu_hover_bg', '' ), '', '滑鼠移到子選項時的背景色；留空＝無 hover 效果。' ); ?>
				<div class="ys-admin-fields">
					<?php
					$ys_am_number( 'submenu_font_size', '字級', $ys_am_value( 'submenu_font_size', 14 ), '10', '18', '1', 'px，建議 12-14。' );
					$ys_am_number( 'submenu_font_weight', '粗細', $ys_am_value( 'submenu_font_weight', 500 ), '100', '900', '100', '100–900，預設 500。' );
					$ys_am_number( 'submenu_line_height', '行高', $ys_am_value( 'submenu_line_height', 1.45 ), '1.0', '2.5', '0.05', '建議 1.3-1.5。' );
					$ys_am_number( 'submenu_indent', '面板左縮排', $ys_am_value( 'submenu_indent', 42 ), '0', '80', '1', 'px，YS CART 選單抽屜裡子選單面板的左縮排（預設 42）。WordPress 側欄展開時（含手機抽屜），子選單文字固定對齊上一層名稱，不用這個值。' );
					?>
				</div>
				<div class="ys-admin-fields">
					<?php
					$ys_am_number( 'submenu_item_padding_top', '上留白', $ys_am_value( 'submenu_item_padding_top', 6 ), '0', '40', '1', 'px' );
					$ys_am_number( 'submenu_item_padding_right', '右留白', $ys_am_value( 'submenu_item_padding_right', 12 ), '0', '60', '1', 'px' );
					$ys_am_number( 'submenu_item_padding_bottom', '下留白', $ys_am_value( 'submenu_item_padding_bottom', 6 ), '0', '40', '1', 'px' );
					$ys_am_number( 'submenu_item_padding_left', '左留白', $ys_am_value( 'submenu_item_padding_left', 12 ), '0', '60', '1', 'px。側欄展開時子選單文字固定對齊上一層名稱，左邊不用這個值。' );
					?>
				</div>
			</div>
		</section>

		<div class="ys-admin-savebar">
			<button type="submit" class="ysca-btn ysca-btn--primary">儲存設定</button>
			<button type="submit" name="ys_ec_admin_theme_reset" value="1" class="ysca-btn" id="ys-ec-reset-theme"
				data-ys-confirm="確定要將原生選單樣式重設為預設值？">重設</button>
		</div>
	</form>
<?php
echo YSMenuPage::page_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定字串
