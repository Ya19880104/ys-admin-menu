<?php
/**
 * WordPress 原生後台選單樣式渲染器（admin theme）。
 *
 * 將色彩、字級、行高與固定視覺變數注入 :root，由 runtime stylesheet 套用至
 * wp-admin 側欄與 admin bar。JavaScript 不會改動原生選單 DOM。
 *
 * @package YangSheep\AdminMenu\Theme
 */

namespace YangSheep\AdminMenu\Theme;

defined( 'ABSPATH' ) || exit;

class YSAdminThemeRenderer {

	public const OPTION_KEY = 'ys_admin_menu_theme_config';

	/**
	 * 註冊 hook：在所有 wp-admin 頁面套用皮膚。
	 */
	public static function register(): void {
		add_action( 'admin_head', [ self::class, 'output' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_runtime_css' ] );
		add_filter( 'admin_body_class', [ self::class, 'add_body_class' ] );
	}

	/**
	 * Output CSS variables consumed by ys-admin-menu-theme-runtime.css.
	 */
	public static function output(): void {
		$cfg = self::get_config();

		if ( empty( $cfg['enabled'] ) ) {
			return;
		}

		$bg          = self::sanitize_color( $cfg['admin_main_bg'] ?? '', '#f5f7fb' );
		$menu_bg     = self::sanitize_color( $cfg['menu_bg'] ?? '', '#1f2937' );
		$menu_color  = self::sanitize_color( $cfg['menu_text_color'] ?? '', '#f8fafc' );
		$bar_bg      = self::sanitize_color( $cfg['admin_bar_bg'] ?? '', '#111827' );
		$hover_bg    = self::sanitize_color( $cfg['menu_hover_bg'] ?? '', '#2563eb' );
		$hover_color = self::sanitize_color( $cfg['menu_hover_text_color'] ?? '', '#ffffff' );

		$icon_color     = self::sanitize_color( $cfg['menu_icon_color'] ?? '', '#cbd5e1' );
		$submenu_bg     = self::sanitize_color( $cfg['submenu_bg'] ?? '', '#111827' );
		$submenu_color  = self::sanitize_color( $cfg['submenu_text_color'] ?? '', '#b8c4d2' );
		$bar_text_color = self::sanitize_color( $cfg['admin_bar_text_color'] ?? '', '#f8fafc' );

		$m_size = self::clamp_float( $cfg['menu_font_size'] ?? 14, 10.0, 20.0 );
		$m_lh   = self::clamp_float( $cfg['menu_line_height'] ?? 1.5, 1.0, 2.5 );
		$s_size = self::clamp_float( $cfg['submenu_font_size'] ?? 13, 10.0, 18.0 );
		$s_lh   = self::clamp_float( $cfg['submenu_line_height'] ?? 1.4, 1.0, 2.5 );
		$s_fw   = (int) self::clamp_float( $cfg['submenu_font_weight'] ?? 500, 100.0, 900.0 );

		$mp_t = (int) self::clamp_float( $cfg['menu_item_padding_top'] ?? 0, 0.0, 40.0 );
		$mp_r = (int) self::clamp_float( $cfg['menu_item_padding_right'] ?? 10, 0.0, 60.0 );
		$mp_b = (int) self::clamp_float( $cfg['menu_item_padding_bottom'] ?? 0, 0.0, 40.0 );
		$mp_l = (int) self::clamp_float( $cfg['menu_item_padding_left'] ?? 10, 0.0, 60.0 );
		$smp_t = (int) self::clamp_float( $cfg['submenu_item_padding_top'] ?? 6, 0.0, 40.0 );
		$smp_r = (int) self::clamp_float( $cfg['submenu_item_padding_right'] ?? 12, 0.0, 60.0 );
		$smp_b = (int) self::clamp_float( $cfg['submenu_item_padding_bottom'] ?? 6, 0.0, 40.0 );
		$smp_l = (int) self::clamp_float( $cfg['submenu_item_padding_left'] ?? 12, 0.0, 60.0 );
		$sub_hover = self::sanitize_color_or_empty( $cfg['submenu_hover_bg'] ?? '' );
		$opensub_bg = self::sanitize_color_or_empty( $cfg['opensub_bg'] ?? '' );
		$current_bg = self::sanitize_color_or_empty( $cfg['current_bg'] ?? '' );
		?>
<style id="ys-admin-menu-theme">
:root {
    --ys-theme-admin-main-bg: <?php echo esc_attr( $bg ); ?>;
    --ys-theme-admin-bar-bg: <?php echo esc_attr( $bar_bg ); ?>;
    --ys-theme-admin-bar-text: <?php echo esc_attr( $bar_text_color ); ?>;
    --ys-theme-menu-bg: <?php echo esc_attr( $menu_bg ); ?>;
    --ys-theme-menu-text: <?php echo esc_attr( $menu_color ); ?>;
    --ys-theme-menu-icon-color: <?php echo esc_attr( $icon_color ); ?>;
    --ys-theme-submenu-bg: <?php echo esc_attr( $submenu_bg ); ?>;
    --ys-theme-submenu-text: <?php echo esc_attr( $submenu_color ); ?>;
    --ys-theme-hover-bg: <?php echo esc_attr( $hover_bg ); ?>;
    --ys-theme-opensub-bg: <?php echo esc_attr( '' !== $opensub_bg ? $opensub_bg : $hover_bg ); ?>;
    --ys-theme-current-bg: <?php echo esc_attr( '' !== $current_bg ? $current_bg : $hover_bg ); ?>;
    --ys-theme-hover-text: <?php echo esc_attr( $hover_color ); ?>;
    --ys-theme-menu-font-size: <?php echo esc_attr( (string) $m_size ); ?>px;
    --ys-theme-menu-line-height: <?php echo esc_attr( (string) $m_lh ); ?>;
    --ys-theme-submenu-font-size: <?php echo esc_attr( (string) $s_size ); ?>px;
    --ys-theme-submenu-line-height: <?php echo esc_attr( (string) $s_lh ); ?>;
    --ys-theme-submenu-font-weight: <?php echo esc_attr( (string) $s_fw ); ?>;
    --ys-theme-menu-width: 300px;
    --ys-theme-menu-drawer-width: 340px;
    --ys-theme-menu-item-height: 36px;
    --ys-theme-menu-item-radius: 7px;
    --ys-theme-menu-item-gap: 12px;
    --ys-theme-menu-item-x: 10px;
    --ys-theme-menu-item-pt: <?php echo esc_attr( (string) $mp_t ); ?>px;
    --ys-theme-menu-item-pr: <?php echo esc_attr( (string) $mp_r ); ?>px;
    --ys-theme-menu-item-pb: <?php echo esc_attr( (string) $mp_b ); ?>px;
    --ys-theme-menu-item-pl: <?php echo esc_attr( (string) $mp_l ); ?>px;
    --ys-theme-submenu-item-pt: <?php echo esc_attr( (string) $smp_t ); ?>px;
    --ys-theme-submenu-item-pr: <?php echo esc_attr( (string) $smp_r ); ?>px;
    --ys-theme-submenu-item-pb: <?php echo esc_attr( (string) $smp_b ); ?>px;
    --ys-theme-submenu-item-pl: <?php echo esc_attr( (string) $smp_l ); ?>px;
    --ys-theme-submenu-hover-bg: <?php echo esc_attr( '' !== $sub_hover ? $sub_hover : 'transparent' ); ?>;
    --ys-theme-submenu-indent: 42px;
}
</style>
		<?php
	}

	/**
	 * 啟用時為 admin body 加上 runtime class。
	 *
	 * @param string $classes Admin body classes.
	 */
	public static function add_body_class( $classes ): string {
		$base = is_string( $classes ) ? $classes : '';
		$cfg  = self::get_config();

		if ( empty( $cfg['enabled'] ) ) {
			return $base;
		}

		return $base . ' ys-theme-active ';
	}

	/**
	 * Enqueue the companion stylesheet/script on all wp-admin pages.
	 */
	public static function enqueue_runtime_css(): void {
		wp_enqueue_style(
			'ys-admin-menu-theme-runtime',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/ys-admin-menu-theme-runtime.css',
			[ 'admin-menu' ],
			YS_ADMIN_MENU_VERSION
		);

		wp_enqueue_script(
			'ys-admin-menu-theme-runtime',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/js/ys-admin-menu-theme-runtime.js',
			[],
			YS_ADMIN_MENU_VERSION,
			true
		);
	}

	/**
	 * Read saved config with defaults. Missing option means default enabled.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_config(): array {
		$cfg = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $cfg ) ) {
			$cfg = [];
		}

		return wp_parse_args( $cfg, self::default_config() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function default_config(): array {
		return [
			'enabled'               => true,
			'admin_main_bg'         => '#f5f7fb',
			'menu_bg'               => '#1f2937',
			'menu_text_color'       => '#f8fafc',
			'menu_icon_color'       => '#cbd5e1',
			'admin_bar_bg'          => '#111827',
			'admin_bar_text_color'  => '#f8fafc',
			'submenu_bg'            => '#111827',
			'submenu_text_color'    => '#b8c4d2',
			'menu_font_size'        => 15,
			'menu_line_height'      => 1.5,
			'submenu_font_size'     => 14,
			'submenu_line_height'   => 1.45,
			'menu_hover_bg'         => '#2563eb',
			'menu_hover_text_color' => '#ffffff',
			'section_label_color'       => '',
			'section_label_bg'          => '',
			'section_label_font_size'   => 11,
			'section_label_font_weight' => 700,
			'section_label_padding_top'    => 7,
			'section_label_padding_right'  => 12,
			'section_label_padding_bottom' => 7,
			'section_label_padding_left'   => 12,
			'section_label_margin_top'    => 10,
			'section_label_margin_right'  => 0,
			'section_label_margin_bottom' => 4,
			'section_label_margin_left'   => 0,
			'menu_item_padding_top'    => 0,
			'menu_item_padding_right'  => 10,
			'menu_item_padding_bottom' => 0,
			'menu_item_padding_left'   => 10,
			'submenu_item_padding_top'    => 6,
			'submenu_item_padding_right'  => 12,
			'submenu_item_padding_bottom' => 6,
			'submenu_item_padding_left'   => 12,
			'submenu_font_weight'         => 500,
			'submenu_hover_bg'            => '',
			'opensub_bg'                 => '',
			'current_bg'                 => '',
		];
	}

	/**
	 * 分組標籤（帶標題分隔列）樣式，供 YSMenuRouter::output_section_label_css 套用。
	 * 無條件可讀（不依賴皮膚 enabled）。
	 *
	 * @return array{color:string,bg:string,font_size:int,font_weight:int,padding_y:int,padding_x:int}
	 */
	public static function get_section_label_style(): array {
		$cfg = self::get_config();
		return [
			'color'       => self::sanitize_color_or_empty( $cfg['section_label_color'] ?? '' ),
			'bg'          => self::sanitize_color_or_empty( $cfg['section_label_bg'] ?? '' ),
			'font_size'   => (int) self::clamp_float( $cfg['section_label_font_size'] ?? 11, 8.0, 24.0 ),
			'font_weight' => (int) self::clamp_float( $cfg['section_label_font_weight'] ?? 700, 100.0, 900.0 ),
			'padding_top'    => (int) self::clamp_float( $cfg['section_label_padding_top'] ?? 7, 0.0, 40.0 ),
			'padding_right'  => (int) self::clamp_float( $cfg['section_label_padding_right'] ?? 12, 0.0, 60.0 ),
			'padding_bottom' => (int) self::clamp_float( $cfg['section_label_padding_bottom'] ?? 7, 0.0, 40.0 ),
			'padding_left'   => (int) self::clamp_float( $cfg['section_label_padding_left'] ?? 12, 0.0, 60.0 ),
			'margin_top'    => (int) self::clamp_float( $cfg['section_label_margin_top'] ?? 10, 0.0, 80.0 ),
			'margin_right'  => (int) self::clamp_float( $cfg['section_label_margin_right'] ?? 0, 0.0, 60.0 ),
			'margin_bottom' => (int) self::clamp_float( $cfg['section_label_margin_bottom'] ?? 4, 0.0, 80.0 ),
			'margin_left'   => (int) self::clamp_float( $cfg['section_label_margin_left'] ?? 0, 0.0, 60.0 ),
		];
	}

	/**
	 * Sanitize a hex color but allow empty string (empty = inherit / transparent).
	 */
	private static function sanitize_color_or_empty( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		$clean = sanitize_hex_color( trim( $value ) );
		return is_string( $clean ) ? $clean : '';
	}

	private static function sanitize_color( $value, string $default ): string {
		if ( ! is_string( $value ) ) {
			return $default;
		}

		$clean = sanitize_hex_color( $value );
		return $clean ? $clean : $default;
	}

	private static function clamp_float( $value, float $min, float $max ): float {
		$num = is_numeric( $value ) ? (float) $value : $min;
		if ( is_nan( $num ) ) {
			$num = $min;
		}

		return max( $min, min( $max, $num ) );
	}
}
