/**
 * v1.7.0：全站關閉留言時，區塊編輯器取消「最新留言」區塊（YSCommentsDisabler::editor_assets()）。
 */
( function ( wp ) {
    if ( ! wp || ! wp.blocks || ! wp.domReady ) {
        return;
    }
    wp.domReady( function () {
        if ( wp.blocks.getBlockType( 'core/latest-comments' ) ) {
            wp.blocks.unregisterBlockType( 'core/latest-comments' );
        }
    } );
}( window.wp ) );
