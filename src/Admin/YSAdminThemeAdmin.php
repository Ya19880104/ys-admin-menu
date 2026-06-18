<?php
/**
 * Native WordPress admin menu theme settings page.
 *
 * @package YangSheep\AdminMenu\Admin
 */

namespace YangSheep\AdminMenu\Admin;

use YangSheep\AdminMenu\Theme\YSAdminThemeRenderer;

defined( 'ABSPATH' ) || exit;

class YSAdminThemeAdmin {

	public const PAGE_SLUG = 'ys-admin-menu-theme';
	public const NONCE_ACTION = 'ys_admin_menu_save_theme';
	public const NONCE_FIELD = 'ys_admin_menu_theme_nonce';

	private const LEGACY_NATIVE_MENU_WIDTH_OPTION = 'ys_ec_native_menu_width';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
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

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足', 'ys-admin-menu' ) );
		}

		$saved_notice = '';
		$reset_notice = '';

		if ( isset( $_POST['ys_ec_admin_theme_reset'] ) && isset( $_POST[ self::NONCE_FIELD ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
			if ( wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				delete_option( YSAdminThemeRenderer::OPTION_KEY );
				delete_option( self::LEGACY_NATIVE_MENU_WIDTH_OPTION );
				$reset_notice = '原生選單樣式已重設為預設值。';
			}
		} elseif ( isset( $_POST[ self::NONCE_FIELD ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
			if ( wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				self::save();
				$saved_notice = '原生選單樣式已儲存。';
			} else {
				$saved_notice = '安全驗證失敗，請重新整理頁面後再試一次。';
			}
		}

		$cfg = wp_parse_args(
			(array) get_option( YSAdminThemeRenderer::OPTION_KEY, [] ),
			self::defaults()
		);

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script(
			'ys-admin-menu-theme',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/js/ys-admin-menu-theme.js',
			[ 'wp-color-picker', 'jquery' ],
			YS_ADMIN_MENU_VERSION,
			true
		);
		wp_enqueue_style(
			'ys-admin-menu-theme-page',
			YS_ADMIN_MENU_PLUGIN_URL . 'assets/css/ys-admin-menu-theme.css',
			[ 'wp-color-picker', 'ys-admin-menu-ds-components' ],
			YS_ADMIN_MENU_VERSION
		);

		wp_localize_script( 'ys-admin-menu-theme', 'ysEcAdminTheme', [
			'defaults' => self::defaults(),
		] );

		$template = YS_ADMIN_MENU_PLUGIN_DIR . 'templates/admin/admin-theme.php';
		if ( file_exists( $template ) ) {
			include $template;
			return;
		}

		echo '<div class="wrap"><div class="notice notice-error"><p>原生選單樣式模板不存在。</p></div></div>';
	}

	private static function save(): void {
		$defaults = self::defaults();
		$cfg      = $defaults;

		$cfg['enabled'] = isset( $_POST['enabled'] );

		$color_keys = [
			'admin_main_bg',
			'menu_bg',
			'menu_text_color',
			'menu_icon_color',
			'admin_bar_bg',
			'admin_bar_text_color',
			'submenu_bg',
			'submenu_text_color',
			'menu_hover_bg',
			'menu_hover_text_color',
		];

		foreach ( $color_keys as $key ) {
			$raw   = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
			$clean = sanitize_hex_color( $raw );
			if ( $clean ) {
				$cfg[ $key ] = $clean;
			}
		}

		$cfg['menu_font_size']      = self::post_float( 'menu_font_size', (float) $defaults['menu_font_size'], 10.0, 20.0 );
		$cfg['submenu_font_size']   = self::post_float( 'submenu_font_size', (float) $defaults['submenu_font_size'], 10.0, 18.0 );
		$cfg['menu_line_height']    = self::post_float( 'menu_line_height', (float) $defaults['menu_line_height'], 1.0, 2.5 );
		$cfg['submenu_line_height'] = self::post_float( 'submenu_line_height', (float) $defaults['submenu_line_height'], 1.0, 2.5 );

		update_option( YSAdminThemeRenderer::OPTION_KEY, $cfg, true );

		// Remove stale layout option. Native menu width is intentionally no
		// longer configurable because it affects submenu geometry site-wide.
		delete_option( self::LEGACY_NATIVE_MENU_WIDTH_OPTION );
	}

	private static function post_float( string $key, float $fallback, float $min, float $max ): float {
		$value = isset( $_POST[ $key ] ) ? (float) $_POST[ $key ] : $fallback;
		if ( is_nan( $value ) ) {
			$value = $fallback;
		}

		return max( $min, min( $max, $value ) );
	}
}
