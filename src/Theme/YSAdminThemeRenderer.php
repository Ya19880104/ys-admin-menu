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
    --ys-theme-hover-text: <?php echo esc_attr( $hover_color ); ?>;
    --ys-theme-menu-font-size: <?php echo esc_attr( (string) $m_size ); ?>px;
    --ys-theme-menu-line-height: <?php echo esc_attr( (string) $m_lh ); ?>;
    --ys-theme-submenu-font-size: <?php echo esc_attr( (string) $s_size ); ?>px;
    --ys-theme-submenu-line-height: <?php echo esc_attr( (string) $s_lh ); ?>;
    --ys-theme-menu-width: 300px;
    --ys-theme-menu-drawer-width: 340px;
    --ys-theme-menu-item-height: 36px;
    --ys-theme-menu-item-radius: 7px;
    --ys-theme-menu-item-gap: 12px;
    --ys-theme-menu-item-x: 10px;
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
		];
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
