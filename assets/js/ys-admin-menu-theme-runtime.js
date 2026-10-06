/**
 * 原生選單樣式 runtime — 子選單可點收放。
 *
 * 只在「原生選單樣式」啟用時載入。
 *
 * 側欄展開時（桌機寬版、手機抽屜）：點頂層選單列「右側箭頭區」收合／展開該選單的
 * 子項清單（不導航）；點選單名稱／圖示區維持 WordPress 原生行為（進入該頁）。
 * 收放狀態存於 localStorage，重新整理後保留。
 *
 * 側欄收成圖示列時（使用者按了「收合選單」，或視窗寬度落在 WordPress 自動收合的
 * 範圍）：完全不介入，交還 WordPress 原生的圖示列與飛出子選單。
 *
 * 漸進增強：載入後於 <body> 加上 .ys-am-js，CSS 才交由 .ys-am-open 控制顯示；
 * 若本檔未載入，CSS fallback 仍讓當前頁子選單常駐展開（不會消失）。
 */
( function () {
	'use strict';

	var KEY  = 'ysAdminMenuSubmenuOpen';
	var menu = document.getElementById( 'adminmenu' );
	if ( ! menu ) {
		return;
	}

	// 圖示列寬度是 36px，展開時至少 160px；與 CSS 的 container query 用同一個門檻。
	var COLLAPSED_MAX_WIDTH = 100;

	document.body.classList.add( 'ys-am-js' );

	var saved = {};
	try {
		saved = JSON.parse( localStorage.getItem( KEY ) || '{}' ) || {};
	} catch ( e ) {
		saved = {};
	}

	function isCollapsed() {
		var width = menu.getBoundingClientRect().width;
		return width > 0 && width < COLLAPSED_MAX_WIDTH;
	}

	function isOpen( li ) {
		var slug = li.id || '';
		if ( Object.prototype.hasOwnProperty.call( saved, slug ) ) {
			return !! saved[ slug ];
		}
		// 預設：當前頁所屬選單展開，其餘收合。
		return li.classList.contains( 'wp-has-current-submenu' );
	}

	function apply() {
		var items = menu.querySelectorAll( 'li.menu-top.wp-has-submenu' );
		Array.prototype.forEach.call( items, function ( li ) {
			li.classList.toggle( 'ys-am-open', isOpen( li ) );
		} );
	}

	apply();

	// 點頂層選單列最右側箭頭區（約 44px）→ 收放子選單；其餘區域維持原生導航。
	// 觸控環境（'ontouchstart' in window，含觸控螢幕筆電／桌機）WP common.js 會對
	// a.wp-has-submenu 綁 click 攔截（wp-mobile-hover）：第一次點擊 preventDefault
	// 只加 .opensub 並以 adjustSubmenu 注入負 margin（畫面跳一下、不導航），點第二次
	// 才放行。本監聽器在 capture 階段先執行，名稱區點擊以 stopPropagation 阻斷該攔截
	//（不呼叫 preventDefault，預設導航照常進行）。
	menu.addEventListener( 'click', function ( event ) {
		var a = ( event.target && event.target.closest ) ? event.target.closest( 'a.menu-top' ) : null;
		if ( ! a ) {
			return;
		}
		var li = a.parentNode;
		if ( ! li || ! li.classList || ! li.classList.contains( 'wp-has-submenu' ) ) {
			return;
		}
		if ( isCollapsed() ) {
			return; // 圖示列模式不介入：點圖示進頁面、滑過顯示飛出子選單，都由 WordPress 處理。
		}
		var rect = a.getBoundingClientRect();
		if ( event.clientX < rect.right - 44 ) {
			event.stopPropagation(); // 名稱／圖示區 → 放行原生導航，僅阻斷觸控首擊攔截。
			return;
		}
		event.preventDefault();
		event.stopPropagation();

		var slug = li.id || '';
		saved[ slug ] = ! isOpen( li );
		try {
			localStorage.setItem( KEY, JSON.stringify( saved ) );
		} catch ( err ) {}
		apply();
	}, true );
}() );
