/**
 * YS Admin Menu 1.7.0：全站關閉留言時，區塊編輯器（文章、網站編輯器、區塊小工具、自訂器）不能再插入「最新留言」區塊。
 *
 * 在區塊註冊時就把 supports.inserter 設成 false（不管這支腳本跟區塊註冊誰先跑都有效）；已經放好的區塊不變成「不支援的區塊」，
 * 照樣可以移除，不保留舊留言時它查不到任何留言。
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.hooks.addFilter ) {
		return;
	}
	wp.hooks.addFilter( 'blocks.registerBlockType', 'ys-admin-menu/no-latest-comments', function ( settings, name ) {
		if ( 'core/latest-comments' !== name || ! settings ) {
			return settings;
		}
		return Object.assign( {}, settings, { supports: Object.assign( {}, settings.supports || {}, { inserter: false } ) } );
	} );
}( window.wp ) );
