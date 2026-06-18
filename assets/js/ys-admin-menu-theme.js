/**
 * 原生選單樣式 — Live Preview + Color Picker（v2.39.0 BATCH R / C9）
 *
 * 由 YSAdminThemeAdmin::render() enqueue。
 *
 * 行為：
 *   1) 初始化所有 .ys-ec-color-picker 為 wp.colorPicker（WP 內建）
 *   2) 監聽所有色彩 / 數字 / 啟用 checkbox 變更
 *   3) 變更後 → 直接 update inline <style id="ys-ec-admin-theme-preview">
 *      → 同頁面立即看到效果（不需要 reload，也不需要 round-trip backend）
 *   4) 「儲存設定」走標準 form POST（PRG），由 PHP 寫入 wp_options
 *
 * 注意：
 *   - 預覽 <style> 與後端 inject 的 <style id="ys-ec-admin-theme"> 共存時，
 *     瀏覽器會以 DOM order 後者覆蓋（preview 在 head 內，admin_head inject
 *     已先 echo，所以 preview 後追加 > 後寫入 = preview 勝出）。
 *   - 儲存後重新整理頁面，後端 inject 會接管，preview 區塊重新初始化。
 *
 * 依賴：jQuery（wp.colorPicker 需要）。
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	// 預覽 <style> 元素 id（與後端 inject 區分）
	var PREVIEW_STYLE_ID = 'ys-ec-admin-theme-preview';

	$( function () {
		initColorPickers();
		bindLivePreview();
		bindResetWithConfirm();

		// 首次載入：若 enabled 已勾，立即套用 preview（讓使用者看到目前儲存值的效果）
		applyPreview();
	} );

	/**
	 * 初始化 wp colorPicker
	 *
	 * change 事件 setTimeout 0 避免 wpColorPicker 內部 race（值尚未寫入 input.val()）
	 */
	function initColorPickers() {
		$( '.ys-ec-color-picker' ).each( function () {
			var $input = $( this );
			$input.wpColorPicker( {
				change: function () {
					setTimeout( applyPreview, 0 );
				},
				clear: function () {
					setTimeout( applyPreview, 0 );
				},
			} );
		} );
	}

	/**
	 * 綁定數字 input + enabled checkbox 的 live update
	 */
	function bindLivePreview() {
		$( document ).on( 'input change', 'input[type="number"]', applyPreview );
		$( document ).on( 'change', 'input[name="enabled"]', applyPreview );
	}

	/**
	 * Reset button：JS 端僅做 UX confirm（confirm 已在 inline onclick）
	 *
	 * 真正的 reset 由 PHP 處理（POST ys_ec_admin_theme_reset → delete_option）。
	 * 此處保留 hook 給未來若需擴充 reset 行為。
	 */
	function bindResetWithConfirm() {
		// no-op — confirm() 已掛在 button onclick
	}

	/**
	 * 從表單讀目前所有值並重組成設定 object
	 *
	 * @returns {Object}
	 */
	function readForm() {
		return {
			enabled: $( 'input[name="enabled"]' ).is( ':checked' ),
			admin_main_bg: getValue( 'admin_main_bg', '#f0f0f1' ),
			menu_bg: getValue( 'menu_bg', '#1d2327' ),
			menu_text_color: getValue( 'menu_text_color', '#f0f0f1' ),
			// v2.39.15 NEW 4 個欄位
			menu_icon_color: getValue( 'menu_icon_color', '#a7aaad' ),
			submenu_bg: getValue( 'submenu_bg', '#1d2327' ),
			submenu_text_color: getValue( 'submenu_text_color', '#bcc0c4' ),
			admin_bar_text_color: getValue( 'admin_bar_text_color', '#f0f0f1' ),
			admin_bar_bg: getValue( 'admin_bar_bg', '#1d2327' ),
			menu_hover_bg: getValue( 'menu_hover_bg', '#2271b1' ),
			menu_hover_text_color: getValue( 'menu_hover_text_color', '#ffffff' ),
			menu_font_size: clamp( parseFloat( $( 'input[name="menu_font_size"]' ).val() ), 10, 20, 14 ),
			menu_line_height: clamp( parseFloat( $( 'input[name="menu_line_height"]' ).val() ), 1.0, 2.5, 1.5 ),
			submenu_font_size: clamp( parseFloat( $( 'input[name="submenu_font_size"]' ).val() ), 10, 18, 13 ),
			submenu_line_height: clamp( parseFloat( $( 'input[name="submenu_line_height"]' ).val() ), 1.0, 2.5, 1.4 ),
		};
	}

	/**
	 * 從 input 取值，若為空白則用 fallback
	 *
	 * @param {string} name
	 * @param {string} fallback
	 * @returns {string}
	 */
	function getValue( name, fallback ) {
		var val = ( $( 'input[name="' + name + '"]' ).val() || '' ).trim();
		return val ? val : fallback;
	}

	/**
	 * Clamp 數值至範圍內，NaN 則 fallback
	 */
	function clamp( v, min, max, fallback ) {
		if ( isNaN( v ) ) {
			return fallback;
		}
		return Math.max( min, Math.min( max, v ) );
	}

	/**
	 * 套用 / 移除 preview <style>
	 *
	 * v2.45.x ADR-045 P1：
	 *   - 預覽 CSS 只輸出 :root CSS variables
	 *   - body.ys-theme-active class toggle 由此處控制
	 *   - companion stylesheet (ys-ec-admin-theme-runtime.css) 已 enqueue，
	 *     會自動消費 variables；preview 改 variable 值即時生效
	 */
	function applyPreview() {
		var cfg = readForm();
		var existing = document.getElementById( PREVIEW_STYLE_ID );

		if ( ! cfg.enabled ) {
			// 取消勾選 → 移除 preview style + body class，回到 WP 預設
			if ( existing && existing.parentNode ) {
				existing.parentNode.removeChild( existing );
			}
			document.body.classList.remove( 'ys-theme-active' );
			return;
		}

		// enabled → 加 body class（companion CSS 才會 match），寫入 variables
		document.body.classList.add( 'ys-theme-active' );

		var css = buildCss( cfg );
		if ( ! existing ) {
			existing = document.createElement( 'style' );
			existing.id = PREVIEW_STYLE_ID;
			document.head.appendChild( existing );
		}
		existing.textContent = css;
	}

	/**
	 * 從設定 cfg 建構 CSS 文字（與 PHP renderer 1:1 對齊）
	 *
	 * v2.45.x ADR-045 P1：只輸出 :root CSS variables，無 cascade override。
	 * 實際樣式由 ys-ec-admin-theme-runtime.css 透過 body.ys-theme-active 消費。
	 *
	 * @param {Object} c
	 * @returns {string}
	 */
	function buildCss( c ) {
		return [
			':root {',
			'    --ys-theme-admin-main-bg: ' + c.admin_main_bg + ';',
			'    --ys-theme-admin-bar-bg: ' + c.admin_bar_bg + ';',
			'    --ys-theme-admin-bar-text: ' + c.admin_bar_text_color + ';',
			'    --ys-theme-menu-bg: ' + c.menu_bg + ';',
			'    --ys-theme-menu-text: ' + c.menu_text_color + ';',
			'    --ys-theme-menu-icon-color: ' + c.menu_icon_color + ';',
			'    --ys-theme-submenu-bg: ' + c.submenu_bg + ';',
			'    --ys-theme-submenu-text: ' + c.submenu_text_color + ';',
			'    --ys-theme-hover-bg: ' + c.menu_hover_bg + ';',
			'    --ys-theme-hover-text: ' + c.menu_hover_text_color + ';',
			'    --ys-theme-menu-font-size: ' + c.menu_font_size + 'px;',
			'    --ys-theme-menu-line-height: ' + c.menu_line_height + ';',
			'    --ys-theme-submenu-font-size: ' + c.submenu_font_size + 'px;',
			'    --ys-theme-submenu-line-height: ' + c.submenu_line_height + ';',
			'}',
		].join( '\n' );
	}

	// v2.39.16 P3-B：把 admin-theme.php inline onclick confirm 搬進 JS module，集中行為。
	// 監聽任何 [data-ys-confirm] 元素的 click → 跳 confirm dialog；使用者按取消 → preventDefault。
	// 範例：<button data-ys-confirm="確定要重設？">重設</button>
	$( document ).on( 'click', '[data-ys-confirm]', function ( e ) {
		var msg = $( this ).attr( 'data-ys-confirm' ) || '確定要執行此操作？';
		if ( ! window.confirm( msg ) ) {
			e.preventDefault();
			return false;
		}
		return true;
	} );

}( jQuery ) );
