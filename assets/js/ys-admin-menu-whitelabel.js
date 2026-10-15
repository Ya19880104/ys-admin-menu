/**
 * YS Admin Menu — 白牌設定頁（v1.4.0）：分頁、媒體庫按鈕、Turnstile「測試設定」。
 *
 * 漸進增強：沒有這支腳本時，四個分頁的內容依序全部顯示，表單照常可以儲存。
 * 整頁是同一個表單，切換分頁不會遺失其他分頁填到一半的內容；儲存後回到同一個分頁。
 */
( function () {
	'use strict';

	var cfg  = window.ysAmWhiteLabel || {};
	var i18n = cfg.i18n || {};

	/* ───────── 分頁（WAI-ARIA tabs：方向鍵、Home、End） ───────── */

	var tablist = document.querySelector( '[data-ys-am-tabs]' );
	var tabInput = document.getElementById( 'ys-am-wl-tab-input' );

	if ( tablist ) {
		var tabs = Array.prototype.slice.call( tablist.querySelectorAll( '[role="tab"]' ) );
		var panels = tabs.map( function ( tab ) {
			return document.getElementById( tab.getAttribute( 'aria-controls' ) );
		} );

		var activate = function ( index, moveFocus ) {
			tabs.forEach( function ( tab, i ) {
				var on = i === index;
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', on ? '0' : '-1' );
				tab.classList.toggle( 'ysca-wl-tab--active', on );
				if ( panels[ i ] ) {
					panels[ i ].hidden = ! on;
				}
			} );

			var key = tabs[ index ].getAttribute( 'data-tab' ) || '';
			if ( tabInput ) {
				tabInput.value = key;
			}
			try {
				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', key );
				url.searchParams.delete( 'ys_ec_wl_saved' );
				url.searchParams.delete( 'ys_am_wl_err' );
				window.history.replaceState( null, '', url.toString() );
			} catch ( e ) {}

			if ( moveFocus ) {
				tabs[ index ].focus();
			}
		};

		var start = 0;
		tabs.forEach( function ( tab, i ) {
			if ( 'true' === tab.getAttribute( 'aria-selected' ) ) {
				start = i;
			}
		} );

		tablist.hidden = false;
		activate( start, false );

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				activate( i, false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;
				if ( 'ArrowRight' === event.key ) {
					next = ( i + 1 ) % tabs.length;
				} else if ( 'ArrowLeft' === event.key ) {
					next = ( i - 1 + tabs.length ) % tabs.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = tabs.length - 1;
				}
				if ( null !== next ) {
					event.preventDefault();
					activate( next, true );
				}
			} );
		} );
	}

	/* ───────── 登入頁的 LOGO／背景圖：媒體庫與清除 ───────── */

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-ys-am-media],[data-ys-am-clear]' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		var isClear = button.hasAttribute( 'data-ys-am-clear' );
		var target  = document.getElementById( button.getAttribute( isClear ? 'data-ys-am-clear' : 'data-ys-am-media' ) );
		if ( ! target ) {
			return;
		}
		if ( isClear ) {
			target.value = '';
			return;
		}
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		var frame = window.wp.media( {
			title: i18n.mediaTitle || '',
			button: { text: i18n.mediaButton || '' },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( attachment && attachment.url ) {
				target.value = attachment.url;
			}
		} );
		frame.open();
	} );

	/* ───────── Turnstile「測試設定」 ───────── */

	var testButton = document.getElementById( 'ys-am-turnstile-test' );
	var testResult = document.getElementById( 'ys-am-turnstile-test-result' );
	var testToken  = '';

	var say = function ( text, ok ) {
		if ( ! testResult ) {
			return;
		}
		testResult.textContent = text || '';
		testResult.classList.toggle( 'is-ok', true === ok );
		testResult.classList.toggle( 'is-bad', false === ok );
	};

	window.ysAmTurnstileTestToken = function ( token ) {
		testToken = typeof token === 'string' ? token : '';
		if ( testButton ) {
			testButton.disabled = '' === testToken;
		}
	};
	window.ysAmTurnstileTestExpired = function () {
		testToken = '';
		if ( testButton ) {
			testButton.disabled = true;
		}
	};
	window.ysAmTurnstileTestError = function () {
		testToken = '';
		if ( testButton ) {
			testButton.disabled = true;
		}
		say( i18n.widgetError, false );
	};

	if ( testButton ) {
		testButton.addEventListener( 'click', function () {
			if ( ! testToken ) {
				say( i18n.needToken, false );
				return;
			}

			var body = new URLSearchParams();
			body.set( 'action', cfg.testAction || '' );
			body.set( 'nonce', cfg.testNonce || '' );
			body.set( 'token', testToken );
			testToken = '';
			testButton.disabled = true;
			say( i18n.testing, null );

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( json ) {
				var message = json && json.data && json.data.message ? json.data.message : i18n.requestError;
				say( message, !! ( json && json.success ) );
			} ).catch( function () {
				say( i18n.requestError, false );
			} ).then( function () {
				// token 只能用一次：測完重新產生一個。
				if ( window.turnstile && typeof window.turnstile.reset === 'function' ) {
					try {
						window.turnstile.reset();
					} catch ( e ) {}
				}
			} );
		} );
	}
}() );
