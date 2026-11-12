/**
 * YS Admin Menu v1.11.0：「選單與權限」存檔的基準，與「送出之後又改過」的判斷。
 *
 * 不碰畫面，只管資料（ys-admin-menu-permission.js 用它；測試也直接載入它）：
 *   - 基準＝上一次「真的存成功」時送出的內容。「不在側欄上的頁面規則」那一區存檔時只送跟基準不同的列（改過的、移除的）。
 *   - 每一次修改呼叫 touch()；按儲存時 begin( 送出當下的快照 )；回應回來 done( sent, ok )：
 *       成功：基準換成「送出當下的快照」，不是回應回來時畫面上的樣子。送出之後又改、又刪的照樣算沒存（回 false），
 *             下一次存檔照樣送、離開頁面照樣提醒。
 *       失敗：基準不動，原本沒存的照樣沒存（回 false）。
 *   - 快照的格式：{ 鍵: 那一列設定的 JSON 字串 }。
 */
( function ( root ) {
	'use strict';

	/** 兩份快照的差異：changed＝現在有、跟基準不同的鍵；deleted＝基準有、現在沒有的鍵（都照快照裡的順序）。 */
	function diff( base, now ) {
		base = base || {};
		now  = now || {};
		var changed = [];
		var deleted = [];
		Object.keys( now ).forEach( function ( key ) {
			if ( base[ key ] !== now[ key ] ) {
				changed.push( key );
			}
		} );
		Object.keys( base ).forEach( function ( key ) {
			if ( ! Object.prototype.hasOwnProperty.call( now, key ) ) {
				deleted.push( key );
			}
		} );
		return { changed: changed, deleted: deleted };
	}

	function create( initial ) {
		var base    = initial || {};
		var seq     = 0;
		var pending = null;
		return {
			/** 有一次修改（勾選、輸入、拖拉、移除列…）。 */
			touch: function () {
				seq++;
			},
			/** 目前的基準（上一次真的存成功時送出的快照）。 */
			baseline: function () {
				return base;
			},
			/** 按下儲存：記下送出當下的快照與修改次數。回傳的物件交給 done()。 */
			begin: function ( snapshot ) {
				pending = { seq: seq, snapshot: snapshot || {} };
				return pending;
			},
			/** 回應回來：成功時換基準；回 true＝送出之後沒有再改（可以標成已儲存），false＝還有沒存的修改。 */
			done: function ( sent, ok ) {
				if ( ! sent || sent !== pending ) {
					return false;
				}
				pending = null;
				if ( ! ok ) {
					return false;
				}
				base = sent.snapshot;
				return seq === sent.seq;
			},
			/** 正在等回應。 */
			saving: function () {
				return null !== pending;
			}
		};
	}

	var api = { create: create, diff: diff };
	root.YSAdminMenuSaveState = api;
	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = api;
	}
}( typeof window !== 'undefined' ? window : this ) );
