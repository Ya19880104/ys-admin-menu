/**
 * YS CART 權限設置 — 前端互動（v2.39.0 BATCH Q）
 *
 * v2.38.2 → v2.39.0 變更：
 *   - Q1：新 user_overrides tab 完整互動（debounced wp/v2/users 搜尋 + 新增/移除 card + 儲存）
 *   - Q2：compact row（td padding 6px、role-cb-group flex）— JS payload 不變
 *   - Q3：hide checkbox 加入 payload（boolean 欄位）+ separator row 模板更新
 *
 * v2.38.2 hotfix 改動（已保留）：
 *   - 不再 fetch /menu-enumeration（rows 改由 PHP 在 render 時直接輸出）
 *   - 不再 fetch /menu-config 預先 hydrate（已存的 saved values 由 PHP 預先填入）
 *   - JS 啟動時直接用 server-rendered rows + 套 wpColorPicker + init Sortable
 *   - 「+ 新增空白標題」 / 「儲存」 / 「移除 row」 邏輯保留
 *
 * v1.3.0（YS Admin Menu）：子選單「移到主選單」「複製到主選單」按鈕、複製區（按鈕或拖進來）、
 *   主選單分頁的複製項（圖示、刪除）；儲存時多送 top_copies（只動頁面上列出的複製項）。
 *
 * Dependencies：sortablejs（CDN 或 enqueue local）+ wp-color-picker（jQuery + WP API）
 *
 * @package YangSheep\Ecommerce
 * @since   2.38.2
 * @updated 2.39.0
 */

(function () {
    'use strict';

    var cfg = window.ysPermissionAdmin || {};
    if ( ! cfg.restUrl ) {
        return; // bootstrap 沒 inject — 非權限設置頁
    }

    // ─────────────────────────────────────────────
    // 路由：依當前頁面 DOM 結構決定走哪一個 controller
    // ─────────────────────────────────────────────
    var rowsTbody = document.getElementById( 'ys-ec-permission-rows' );
    var userList  = document.getElementById( 'ys-ec-user-overrides-list' );

    bootstrap();

    /**
     * v2.39.0：保留 v2.38.2 baseline test 的 bootstrap() 名稱（避免破壞 regression）
     * 內部分流到對應 controller。
     */
    function bootstrap() {
        if ( rowsTbody ) {
            bootMainTable( rowsTbody );
        } else if ( userList ) {
            bootUserOverrides( userList );
        }
    }

    // ═════════════════════════════════════════════
    // CONTROLLER A：wp_native / ys_cart 主表
    // ═════════════════════════════════════════════
    // v1.3.2：有沒有未儲存的變更（離開頁面時提醒）。
    var isDirty = false;
    function markDirty() { isDirty = true; }
    function markClean() { isDirty = false; }
    window.addEventListener( 'beforeunload', function ( e ) {
        if ( ! isDirty ) {
            return undefined;
        }
        e.preventDefault();
        e.returnValue = '';
        return '';
    } );

    function bootMainTable( tbody ) {
        var table = tbody.closest( 'table' );
        if ( ! table ) {
            return;
        }
        var tab = table.getAttribute( 'data-tab' ) || 'wp_native';
        var saveBtn = document.getElementById( 'ys-ec-save-permissions' );
        var saveStatus = document.getElementById( 'ys-ec-save-status' );
        var addSepBtn = document.getElementById( 'ys-ec-add-separator' );

        if ( typeof window.jQuery !== 'undefined' && window.jQuery.fn.wpColorPicker ) {
            try {
                window.jQuery( tbody ).find( '.ys-ec-color-picker' ).wpColorPicker();
            } catch ( e ) { /* swallow */ }
        }
        setupSortable( tbody, tab );
        setupIconPickers( table );

        // 頁面載入時列出的選單／複製列。儲存時一併送出，伺服器據此分辨「使用者移除的列」與
        // 「這個帳號本來就看不到、所以沒列出來的列」，後者的設定原樣保留。
        var renderedSlugs = rowSlugs( tbody );
        var renderedTopKeys = topRowKeys( tbody );
        var renderedCopies = collectCopies( tab ); // 載入時的複製項（{slug,parent_slug}）。
        var renderedCustom = customRowUrls( tbody ); // v1.7.0：載入時的自訂連結網址。
        syncCopyButtons();
        if ( 'wp_native' !== tab ) {
            addApplyChildrenButtons( tbody );
        }
        bootPreview( tbody );
        markClean();

        // v1.7.0：新增自訂連結（主選單分頁）。
        var addCustomBtn = document.getElementById( 'ys-ec-add-custom-link' );
        if ( addCustomBtn ) {
            addCustomBtn.addEventListener( 'click', function () {
                var row = buildCustomRow( nextOrder( tbody ) );
                tbody.appendChild( row );
                if ( typeof window.jQuery !== 'undefined' && window.jQuery.fn.wpColorPicker ) {
                    try { window.jQuery( row ).find( '.ys-ec-color-picker' ).wpColorPicker(); } catch ( e ) { /* swallow */ }
                }
                renderIconPreview( row.querySelector( '.ys-ec-icon-field' ) );
                var titleInput = row.querySelector( '.ys-ec-custom-title' );
                if ( titleInput ) { titleInput.focus(); }
                if ( row.scrollIntoView ) { row.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
                markDirty();
            } );
        }
        // v1.7.0：新增子選單標題（全部選單分頁）：拖到某個選單的子選單之間。
        var addSubheadBtn = document.getElementById( 'ys-ec-add-subhead' );
        if ( addSubheadBtn ) {
            addSubheadBtn.addEventListener( 'click', function () {
                var temp = document.createElement( 'tbody' );
                temp.innerHTML = renderSeparatorRow( '', nextOrder( tbody ), tab, true );
                var newRow = temp.firstElementChild;
                if ( newRow ) {
                    tbody.appendChild( newRow );
                    var titleInput = newRow.querySelector( '.ys-ec-separator-title' );
                    if ( titleInput ) { titleInput.focus(); }
                    if ( newRow.scrollIntoView ) { newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
                    markDirty();
                }
            } );
        }

        // 「+ 新增空白標題」 separator — 直接插入一列可即時編輯的分隔列。
        if ( addSepBtn ) {
            addSepBtn.addEventListener( 'click', function () {
                var maxOrder = 0;
                Array.prototype.forEach.call( tbody.querySelectorAll( '.ys-ec-order-input' ), function ( inp ) {
                    var v = parseInt( inp.value || '0', 10 ) || 0;
                    if ( v > maxOrder ) { maxOrder = v; }
                } );
                var temp = document.createElement( 'tbody' );
                temp.innerHTML = renderSeparatorRow( '', maxOrder + 10, tab );
                var newRow = temp.firstElementChild;
                if ( newRow ) {
                    tbody.appendChild( newRow );
                    var titleInput = newRow.querySelector( '.ys-ec-separator-title' );
                    if ( titleInput ) { titleInput.focus(); }
                    if ( newRow.scrollIntoView ) { newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } ); }
                    markDirty();
                }
            } );
        }

        // 列上的按鈕（event delegation）。
        table.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest && e.target.closest( 'button' );
            if ( ! btn || ! table.contains( btn ) ) {
                return;
            }
            var row = btn.closest( 'tr' );
            if ( ! row ) {
                return;
            }

            if ( btn.classList.contains( 'ys-ec-delete-row' ) ) {
                var coreNote = row.getAttribute( 'data-core-rules' ) === '1' ? '\n這一列有 YS CART 寫入的可見規則，會一起刪掉。' : '';
                if ( window.confirm( '確定要從清單移除？\n（不會真的刪除選單，只是不在管理範圍內）' + coreNote ) ) {
                    row.parentNode.removeChild( row );
                    markDirty();
                }
                return;
            }

            // v1.3.2：複製到主選單——當下在主列表插入一列頂層複製列（原父選單那組之後）。
            if ( btn.classList.contains( 'ys-ec-copy-btn' ) ) {
                var copied = copyRowFromSource( tbody, row );
                if ( copied ) {
                    markDirty();
                    setStatus( saveStatus, '已複製到主選單（在下方清單中，可拖拉排序）；按「儲存設定」生效', '#0f766e', 5000 );
                }
                return;
            }

            // v1.3.2：移到主選單——把子選單列換成主列表裡的頂層列（原位置移除）。
            if ( btn.classList.contains( 'ys-ec-move-btn' ) ) {
                if ( moveRowToTop( tbody, row ) ) {
                    markDirty();
                    setStatus( saveStatus, '已移到主選單（在下方清單中，可拖拉排序）；按「儲存設定」生效', '#4338ca', 5000 );
                }
                return;
            }

            // v1.3.2：移回原位——把已移到主選單的頂層列換回原本的子選單列。
            if ( btn.classList.contains( 'ys-ec-move-back-btn' ) ) {
                if ( moveRowBackToSub( tbody, row ) ) {
                    markDirty();
                }
                return;
            }

            // 刪除複製項（原子選單不受影響）。
            if ( btn.classList.contains( 'ys-ec-copy-remove' ) || btn.classList.contains( 'ys-ec-delete-copy' ) ) {
                if ( window.confirm( '確定刪除這個複製項？\n（只刪除主選單上的複製項，原子選單不受影響；按「儲存設定」後生效）' ) ) {
                    row.parentNode.removeChild( row );
                    syncCopyButtons();
                    markDirty();
                }
            }
        } );

        // 任何輸入／勾選都算有未儲存變更。
        table.addEventListener( 'input', markDirty );
        table.addEventListener( 'change', markDirty );

        if ( saveBtn ) {
            saveBtn.addEventListener( 'click', function () {
                // v1.7.0：自訂連結要有名稱與正確的網址，也不能跟主選單上已有的項目重複。
                var bad = invalidCustomRow( tbody );
                if ( bad ) {
                    setStatus( saveStatus, bad.dup
                        ? '這個自訂連結的網址已經在主選單上（或跟另一個自訂連結重複），不用另外加；請改網址或移除這一列'
                        : '自訂連結要填名稱，網址要是 https://… 或 wp-admin 底下的頁面（例：edit.php?post_status=draft）', '#dc2626' );
                    flashRow( bad.row );
                    var focus = bad.row.querySelector( '.ys-ec-custom-title' );
                    if ( bad.dup || ( focus && '' !== trimStr( focus.value ) ) ) { focus = bad.row.querySelector( '.ys-ec-custom-url' ); }
                    if ( focus ) { focus.focus(); }
                    return;
                }
                saveBtn.disabled = true;
                setStatus( saveStatus, '儲存中…', '#6b7280' );

                var payload = ( 'wp_native' === tab )
                    ? buildWpNativePayload( tbody, renderedSlugs, renderedCustom )
                    : buildYsCartPayload( tbody, renderedSlugs, renderedTopKeys, renderedCopies );

                postMenuConfig( payload )
                    .then( function ( ok ) {
                        if ( ok ) {
                            // 自訂連結的網址改過的列，代稱與網址換成新的（下次儲存時 rendered 清單才對）。
                            Array.prototype.forEach.call( tbody.querySelectorAll( 'tr[data-custom="1"]' ), function ( row ) {
                                var url = trimStr( ( row.querySelector( '.ys-ec-custom-url' ) || {} ).value );
                                row.setAttribute( 'data-slug', customMenuKey( url ) );
                                row.setAttribute( 'data-custom-url', url );
                            } );
                            renderedSlugs   = rowSlugs( tbody );
                            renderedTopKeys = topRowKeys( tbody );
                            renderedCopies  = collectCopies( tab );
                            renderedCustom  = customRowUrls( tbody );
                            markClean();
                            setStatus( saveStatus, '已儲存 ✓', '#16a34a', 3000 );
                        }
                    } )
                    .finally( function () {
                        saveBtn.disabled = false;
                    } );
            } );
        }

        // 匯出 / 匯入設定。
        var exportBtn = document.getElementById( 'ys-ec-export-config' );
        var importBtn = document.getElementById( 'ys-ec-import-config' );
        var importFile = document.getElementById( 'ys-ec-import-file' );
        if ( exportBtn ) {
            exportBtn.addEventListener( 'click', function () { exportConfig( saveStatus ); } );
        }
        if ( importBtn && importFile ) {
            importBtn.addEventListener( 'click', function () { importFile.value = ''; importFile.click(); } );
            importFile.addEventListener( 'change', function () {
                var file = importFile.files && importFile.files[ 0 ];
                if ( file ) { importConfig( file, saveStatus ); }
            } );
        }
    }

    // ── 收集目前列的 slug / 頂層複製鍵（rendered 清單用） ──
    function rowSlugs( tbody ) {
        return Array.prototype.map.call(
            tbody.querySelectorAll( 'tr[data-slug]' ),
            function ( r ) { return r.getAttribute( 'data-slug' ); }
        );
    }
    function topRowKeys( tbody ) {
        return Array.prototype.map.call(
            tbody.querySelectorAll( 'tr[data-copy="1"], tr[data-promoted="1"]' ),
            function ( r ) { return r.getAttribute( 'data-copy-key' ); }
        ).filter( function ( k ) { return !! k; } );
    }

    // ── 建立 payload ──
    function readRowItem( row ) {
        var who     = readAccess( row );
        var colorEl = row.querySelector( '.ys-ec-color-picker' );
        var titleEl = row.querySelector( '.ys-ec-title-override' );
        var hideCb  = row.querySelector( '.ys-ec-hide-checkbox' );
        var selfCb  = row.querySelector( '.ys-ec-self-only' );
        var existingSelfUid = parseInt( row.getAttribute( 'data-self-uid' ) || '0', 10 ) || 0;
        var selfOnlyUid = ( selfCb && selfCb.checked ) ? ( existingSelfUid || ( cfg.currentUserId | 0 ) ) : 0;
        var iconEl = row.querySelector( '.ys-ec-icon-input' );
        var newTabCb = row.querySelector( '.ys-ec-newtab' );
        var moveEl = row.querySelector( '.ys-ec-display-parent' );
        var item = {
            slug:           row.getAttribute( 'data-custom' ) === '1' ? customMenuKey( ( row.querySelector( '.ys-ec-custom-url' ) || {} ).value ) : row.getAttribute( 'data-slug' ),
            order:          parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0,
            roles:          who.roles,
            color:          colorEl ? colorEl.value : null,
            title_override: titleEl ? titleEl.value : '',
            level:          row.getAttribute( 'data-level' ) || 'top',
            parent_slug:    row.getAttribute( 'data-parent' ) || null,
            hide:           hideCb ? !! hideCb.checked : false,
            self_only_uid:  selfOnlyUid,
            promote_to_top: false
        };
        if ( iconEl ) {
            item.icon = iconEl.value.replace( /^\s+|\s+$/g, '' );
        }
        // v1.7.0：逐項目權限（只在舊欄位表達不了時才有）、開新分頁、換到別的上層顯示。
        if ( who.access ) {
            item.access = who.access;
        }
        if ( newTabCb && newTabCb.checked ) {
            item.target = '_blank';
        }
        if ( moveEl && moveEl.value ) {
            item.display_parent = moveEl.value;
        }
        return item;
    }

    function trimStr( s ) {
        return String( s == null ? '' : s ).replace( /^\s+|\s+$/g, '' );
    }

    // v1.7.0：自訂連結的網址（跟 PHP YSMenuRouter::sanitize_custom_url() 同一套：http(s) 網址，或 wp-admin 底下的 .php 頁面）。
    // http(s) 網址也不收 ^ ` { }：伺服器端 esc_url_raw() 會拿掉它們，存下的網址就跟這一列對不上。
    var CUSTOM_URL_RE = /^(https?:\/\/[^\s'"<>\\\/?#^`{}]+[^\s'"<>\\^`{}]*|[a-z0-9_\-]+\.php(\?[^\s'"<>\\#]*)?(#[^\s'"<>\\]*)?)$/i;

    // v1.7.0：自訂連結的選單代稱（跟 PHP YSMenuRouter::custom_menu_key() 一樣）：http(s) 網址照原樣，wp-admin 底下的頁面前面加 ./
    // （跟複製項一樣，這一列的規則只管這個連結，不會套到原本那一頁）。
    function customMenuKey( url ) {
        url = trimStr( url );
        if ( '' === url ) { return ''; }
        return /^https?:\/\//i.test( url ) ? url : './' + url;
    }

    // v1.7.0：自訂連結列：名稱與網址都要填、網址格式要對、不能跟主選單上已有的項目（或另一個自訂連結）重複；
    // 有問題回傳 { row, dup }（不送出）。
    function invalidCustomRow( tbody ) {
        var taken = {};
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr[data-slug]' ), function ( r ) {
            if ( r.getAttribute( 'data-custom' ) !== '1' && r.getAttribute( 'data-slug' ) ) {
                taken[ r.getAttribute( 'data-slug' ) ] = true;
            }
        } );
        var rows = tbody.querySelectorAll( 'tr[data-custom="1"]' );
        for ( var i = 0; i < rows.length; i++ ) {
            var title = trimStr( ( rows[ i ].querySelector( '.ys-ec-custom-title' ) || {} ).value );
            var url   = trimStr( ( rows[ i ].querySelector( '.ys-ec-custom-url' ) || {} ).value );
            if ( '' === title || ! CUSTOM_URL_RE.test( url ) ) {
                return { row: rows[ i ], dup: false };
            }
            var key = customMenuKey( url );
            if ( taken[ url ] || taken[ key ] ) {
                return { row: rows[ i ], dup: true };
            }
            taken[ key ] = true;
        }
        return null;
    }

    // 頁面載入（或上次儲存）時列出的自訂連結網址（伺服器據此分辨「刪掉的」與「這個帳號看不到、沒列出來的」）。
    function customRowUrls( tbody ) {
        return Array.prototype.map.call( tbody.querySelectorAll( 'tr[data-custom="1"]' ), function ( row ) {
            return row.getAttribute( 'data-custom-url' ) || '';
        } ).filter( function ( u ) { return '' !== u; } );
    }

    function buildWpNativePayload( tbody, renderedSlugs, renderedCustom ) {
        var items = [];
        var custom = [];
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
            if ( row.getAttribute( 'data-separator' ) === '1' ) {
                var sepTitleInput = row.querySelector( '.ys-ec-separator-title' );
                items.push( {
                    separator: true,
                    title:     sepTitleInput ? sepTitleInput.value : '',
                    order:     parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0
                } );
                return;
            }
            if ( row.getAttribute( 'data-custom' ) === '1' ) {
                var item = readRowItem( row );
                custom.push( {
                    url:   trimStr( ( row.querySelector( '.ys-ec-custom-url' ) || {} ).value ),
                    title: trimStr( ( row.querySelector( '.ys-ec-custom-title' ) || {} ).value )
                } );
                item.title_override = '';
                items.push( item );
                return;
            }
            if ( ! row.getAttribute( 'data-slug' ) ) {
                return;
            }
            items.push( readRowItem( row ) );
        } );
        var copies = collectCopies( 'wp_native' );
        var newItems = document.getElementById( 'ys-ec-new-items-visibility' );
        var renameTitles = document.getElementById( 'ys-ec-rename-page-titles' );
        return {
            wp_native:    { items: items, rendered_slugs: renderedSlugs },
            top_copies:   { items: copies, rendered: copies },
            // v1.7.0：自訂連結（只動頁面載入時列出的）與主選單分頁的預設行為。
            custom_items: { items: custom, rendered: renderedCustom || [] },
            defaults:     {
                new_items_visibility: newItems ? newItems.value : 'show',
                rename_page_titles:   renameTitles ? !! renameTitles.checked : false
            }
        };
    }

    function buildYsCartPayload( tbody, renderedSlugs, renderedTopKeys, renderedCopies ) {
        var ysItems = [];
        var topCopies = [];
        var topRows = [];

        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
            // 分隔列 → ys_cart.items。
            if ( row.getAttribute( 'data-separator' ) === '1' ) {
                var sepTitleInput = row.querySelector( '.ys-ec-separator-title' );
                var sep = {
                    separator: true,
                    title:     sepTitleInput ? sepTitleInput.value : '',
                    order:     parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0
                };
                // v1.7.0：子選單標題：記下它夾在哪個上層的子選單之間（依目前位置判斷）。
                if ( row.getAttribute( 'data-subhead' ) === '1' ) {
                    sep.subhead = true;
                    sep.parent_slug = subheadParent( row );
                }
                ysItems.push( sep );
                return;
            }
            // 父選單預設頁列：只提供複製，不是可管理的選單列。
            if ( row.getAttribute( 'data-self-row' ) === '1' ) {
                return;
            }
            var isCopy = row.getAttribute( 'data-copy' ) === '1';
            var isPromoted = row.getAttribute( 'data-promoted' ) === '1';

            if ( isCopy ) {
                topCopies.push( {
                    slug:        row.getAttribute( 'data-copy-slug' ) || '',
                    parent_slug: row.getAttribute( 'data-copy-parent' ) || ''
                } );
                topRows.push( topRowSettings( row ) );
                return;
            }
            if ( isPromoted ) {
                // 已移到主選單：以子選單身分寫回 ys_cart.items（promote_to_top），位置／圖示寫回 top_rows。
                var item = readRowItem( row );
                item.level = 'sub';
                item.promote_to_top = true;
                ysItems.push( item );
                topRows.push( topRowSettings( row ) );
                return;
            }
            // 一般頂層／子選單列。
            if ( row.getAttribute( 'data-slug' ) ) {
                ysItems.push( readRowItem( row ) );
            }
        } );

        return {
            ys_cart:    { items: ysItems, rendered_slugs: renderedSlugs },
            top_copies: { items: topCopies, rendered: renderedCopies || [] },
            top_rows:   { items: topRows, rendered: renderedTopKeys }
        };
    }

    // 一個頂層複製／提升列的設定（寫回 wp_native.items 的 ./ 或原生代稱列）。
    function topRowSettings( row ) {
        var item = readRowItem( row );
        var out = {
            slug:           row.getAttribute( 'data-copy-key' ) || '',
            after:          computeAfter( row ),
            title_override: item.title_override,
            icon:           item.icon || '',
            roles:          item.roles,
            hide:           item.hide,
            self_only_uid:  item.self_only_uid
        };
        if ( item.access ) {
            out.access = item.access;
        }
        if ( item.target ) {
            out.target = item.target;
        }
        return out;
    }

    // v1.7.0：子選單標題夾在哪個上層的子選單之間：下一列是子選單＝它的上層；不然看上一列（子選單＝它的上層，頂層＝它自己）。
    function subheadParent( row ) {
        var real = function ( r ) {
            return r && r.getAttribute( 'data-separator' ) !== '1' && r.getAttribute( 'data-copy' ) !== '1' && r.getAttribute( 'data-promoted' ) !== '1';
        };
        var next = row.nextElementSibling;
        while ( next && ! real( next ) ) { next = next.nextElementSibling; }
        if ( next && ( next.getAttribute( 'data-level' ) === 'sub' || next.getAttribute( 'data-self-row' ) === '1' ) ) {
            return next.getAttribute( 'data-parent' ) || '';
        }
        var prev = row.previousElementSibling;
        while ( prev && ! real( prev ) ) { prev = prev.previousElementSibling; }
        if ( ! prev ) {
            return '';
        }
        if ( prev.getAttribute( 'data-level' ) === 'top' ) {
            return prev.getAttribute( 'data-slug' ) || '';
        }
        return prev.getAttribute( 'data-parent' ) || '';
    }

    // 這個頂層列前面最靠近的「頂層錨點」代稱（真實頂層 data-slug，或另一個複製／提升列的 data-copy-key）；
    // 沒有就回空字串＝排在最前面。據此存 after，重新載入與側欄順序一致。
    function computeAfter( row ) {
        var prev = row.previousElementSibling;
        while ( prev ) {
            if ( prev.getAttribute( 'data-separator' ) !== '1' && prev.getAttribute( 'data-self-row' ) !== '1' ) {
                if ( prev.getAttribute( 'data-copy' ) === '1' || prev.getAttribute( 'data-promoted' ) === '1' ) {
                    return prev.getAttribute( 'data-copy-key' ) || '';
                }
                if ( prev.getAttribute( 'data-level' ) === 'top' && prev.getAttribute( 'data-slug' ) ) {
                    return prev.getAttribute( 'data-slug' );
                }
                // 子選單列：沿用它的父選單作為錨點。
                if ( prev.getAttribute( 'data-level' ) === 'sub' && prev.getAttribute( 'data-parent' ) ) {
                    return prev.getAttribute( 'data-parent' );
                }
            }
            prev = prev.previousElementSibling;
        }
        return '';
    }

    // ═════════════════════════════════════════════
    // 匯出 / 匯入設定
    // ═════════════════════════════════════════════
    function exportConfig( statusEl ) {
        setStatus( statusEl, '匯出中…', '#6b7280' );
        fetch( cfg.restUrl + '/export', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }
        } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( bundle ) {
                if ( ! bundle || bundle._type !== 'ys-admin-menu-export' ) {
                    throw new Error( '伺服器回應格式不符' );
                }
                var blob = new Blob( [ JSON.stringify( bundle, null, 2 ) ], { type: 'application/json' } );
                var url  = URL.createObjectURL( blob );
                var a    = document.createElement( 'a' );
                var stamp = new Date().toISOString().slice( 0, 10 ).replace( /-/g, '' );
                a.href = url;
                a.download = 'ys-admin-menu-settings-' + stamp + '.json';
                document.body.appendChild( a );
                a.click();
                document.body.removeChild( a );
                setTimeout( function () { URL.revokeObjectURL( url ); }, 1000 );
                setStatus( statusEl, '已匯出 ✓', '#16a34a', 3000 );
            } )
            .catch( function ( err ) {
                setStatus( statusEl, '匯出失敗：' + err.message, '#dc2626' );
            } );
    }

    function importConfig( file, statusEl ) {
        var reader = new FileReader();
        reader.onload = function () {
            var parsed;
            try {
                parsed = JSON.parse( String( reader.result ) );
            } catch ( e ) {
                setStatus( statusEl, '匯入失敗：檔案不是有效的 JSON', '#dc2626' );
                return;
            }
            if ( ! parsed || typeof parsed !== 'object' || parsed._type !== 'ys-admin-menu-export' ) {
                setStatus( statusEl, '匯入失敗：這不是本外掛的設定匯出檔', '#dc2626' );
                return;
            }
            if ( ! window.confirm( '匯入將以此檔案的內容「覆蓋」目前全部的選單、權限與樣式設定，確定要繼續嗎？\n（建議先按「匯出設定」備份目前設定）' ) ) {
                return;
            }
            setStatus( statusEl, '匯入中…', '#6b7280' );
            fetch( cfg.restUrl + '/import', {
                method:      'POST',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce':   cfg.nonce,
                    'Content-Type': 'application/json',
                    'Accept':       'application/json'
                },
                body: JSON.stringify( parsed )
            } )
                .then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
                .then( function ( res ) {
                    if ( res.ok && res.data && res.data.success ) {
                        // v1.7.0：設定裡指到這個站上沒有的帳號時，先提示再重新載入。
                        if ( Array.isArray( res.data.warnings ) && res.data.warnings.length ) {
                            window.alert( '已匯入，但請注意：\n' + res.data.warnings.join( '\n' ) );
                        }
                        setStatus( statusEl, '已匯入 ✓ 重新載入中…', '#16a34a' );
                        setTimeout( function () { window.location.reload(); }, 800 );
                    } else {
                        var msg = ( res.data && ( res.data.error || res.data.message ) ) || '未知錯誤';
                        setStatus( statusEl, '匯入失敗：' + msg, '#dc2626' );
                    }
                } )
                .catch( function ( err ) {
                    setStatus( statusEl, '匯入失敗：' + err.message, '#dc2626' );
                } );
        };
        reader.onerror = function () {
            setStatus( statusEl, '匯入失敗：無法讀取檔案', '#dc2626' );
        };
        reader.readAsText( file );
    }

    // ─────────────────────────────────────────────
    // v1.3.2：SortableJS — 單一主列表拖拉排序。複製／已移到主選單的列是主列表裡的頂層列，
    // 和一般列一起拖拉；放到哪個頂層選單後面，儲存時以 after 記錄，重新載入與側欄順序一致。
    // ─────────────────────────────────────────────
    function setupSortable( tbody, tab ) {
        if ( typeof window.Sortable === 'undefined' ) {
            console.warn( '[ys-ec-permission] SortableJS not loaded' );
            return;
        }
        window.Sortable.create( tbody, {
            handle: '.ys-ec-drag-handle',
            draggable: 'tr',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function () {
                reassignOrder( tbody );
                markDirty();
            }
        } );
    }

    // ── 複製／移到主選單／移回原位（當下在主列表動作，不重新載入、不換分頁） ──

    // 複製到主選單：原子選單保留，主列表插入一列頂層複製列（原父選單那組之後）。
    function copyRowFromSource( tbody, source ) {
        var key = source.getAttribute( 'data-copy-key' ) || '';
        if ( '' === key ) {
            return false;
        }
        if ( findTopRowByKey( tbody, key ) ) {
            flashRow( findTopRowByKey( tbody, key ) );
            syncCopyButtons();
            return false;
        }
        var parent = source.getAttribute( 'data-copy-parent' ) || source.getAttribute( 'data-parent' ) || '';
        var tr = buildTopRow( 'copy', {
            key:          key,
            copySlug:     source.getAttribute( 'data-copy-slug' ) || source.getAttribute( 'data-slug' ) || '',
            copyParent:   parent,
            parent:       parent,
            parentTitle:  source.getAttribute( 'data-parent-title' ) || parent,
            title:        rowTitle( source ),
            titleOverride: '',
            icon:         '',
            defaultIcon:  '',
            roles:        [],
            hide:         false,
            selfUid:      0,
            newTab:       false
        } );
        insertAfterGroup( tbody, parent, tr );
        renderIconPreview( tr.querySelector( '.ys-ec-icon-field' ) );
        flashRow( tr );
        syncCopyButtons();
        return true;
    }

    // 移到主選單：子選單列換成主列表裡的頂層列（原位置移除）。
    function moveRowToTop( tbody, sub ) {
        var promoteKey = sub.getAttribute( 'data-promote-key' ) || '';
        if ( '' === promoteKey ) {
            return false;
        }
        var parent = sub.getAttribute( 'data-parent' ) || '';
        var tr = buildTopRow( 'promoted', {
            key:          promoteKey,
            subSlug:      sub.getAttribute( 'data-slug' ) || '',
            parent:       parent,
            parentTitle:  sub.getAttribute( 'data-parent-title' ) || parent,
            title:        rowTitle( sub ),
            titleOverride: ( sub.querySelector( '.ys-ec-title-override' ) || {} ).value || '',
            icon:         '',
            defaultIcon:  '',
            roles:        checkedRoles( sub ),
            panelHtml:    panelHtmlOf( sub ), // v1.7.0：「誰看得到」面板原樣帶過去。
            newTab:       !! ( sub.querySelector( '.ys-ec-newtab' ) || {} ).checked,
            hide:         !! ( sub.querySelector( '.ys-ec-hide-checkbox' ) || {} ).checked,
            selfUid:      ( sub.querySelector( '.ys-ec-self-only' ) || {} ).checked ? ( parseInt( sub.getAttribute( 'data-self-uid' ) || '0', 10 ) || ( cfg.currentUserId | 0 ) ) : 0
        } );
        insertAfterGroup( tbody, parent, tr );
        sub.parentNode.removeChild( sub );
        renderIconPreview( tr.querySelector( '.ys-ec-icon-field' ) );
        flashRow( tr );
        syncCopyButtons();
        return true;
    }

    // 移回原位：已移到主選單的頂層列換回子選單列，放回原父選單那組。
    function moveRowBackToSub( tbody, top ) {
        var parent = top.getAttribute( 'data-parent' ) || '';
        var slug   = top.getAttribute( 'data-slug' ) || '';
        var tr = buildSubRow( {
            slug:         slug,
            parent:       parent,
            parentTitle:  top.getAttribute( 'data-parent-title' ) || parent,
            title:        rowTitle( top ),
            titleOverride: ( top.querySelector( '.ys-ec-title-override' ) || {} ).value || '',
            roles:        checkedRoles( top ),
            panelHtml:    panelHtmlOf( top ),
            newTab:       !! ( top.querySelector( '.ys-ec-newtab' ) || {} ).checked,
            hide:         !! ( top.querySelector( '.ys-ec-hide-checkbox' ) || {} ).checked,
            selfUid:      ( top.querySelector( '.ys-ec-self-only' ) || {} ).checked ? ( parseInt( top.getAttribute( 'data-self-uid' ) || '0', 10 ) || ( cfg.currentUserId | 0 ) ) : 0,
            copyKey:      copyKeyFromPromote( top.getAttribute( 'data-copy-key' ) || '' ),
            promoteKey:   top.getAttribute( 'data-copy-key' ) || '',
            order:        0
        } );
        insertAfterGroup( tbody, parent, tr );
        top.parentNode.removeChild( top );
        flashRow( tr );
        syncCopyButtons();
        return true;
    }

    // 複製代稱（./…）↔ 提升代稱（原生網址）：移回原位時子選單列的複製鍵＝提升鍵加 ./。
    function copyKeyFromPromote( promoteKey ) {
        if ( '' === promoteKey ) {
            return '';
        }
        return /^[a-z][a-z0-9+.\-]*:\/\//i.test( promoteKey ) ? promoteKey : ( './' + promoteKey );
    }

    function rowTitle( row ) {
        return ( ( row.querySelector( '.menu-line strong' ) || {} ).textContent || '' ).replace( /^\s+|\s+$/g, '' );
    }
    function checkedRoles( row ) {
        return Array.prototype.slice.call( row.querySelectorAll( '.ys-ec-role-cb:checked' ) ).map( function ( c ) { return c.dataset.role; } );
    }

    // v1.7.0：面板裡的文字跳脫。新增的列一律用「誰看得到」面板（accessPickerHtml()，列出站上全部角色；已存但站上已經沒有的
    // 角色也列出並保持勾選，不會因為表上看不到就在儲存時被清掉）。只有角色的面板（本外掛自己的頁面）只會由 PHP 畫出來。
    function escHtml( s ) {
        return String( s ).replace( /[&<>"']/g, function ( ch ) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ ch ];
        } );
    }
    function roleSummary( labels ) {
        if ( ! labels.length ) { return '不限角色'; }
        if ( labels.length <= 2 ) { return labels.join( '、' ); }
        return labels[0] + ' 等 ' + labels.length + ' 個角色';
    }
    // 勾選改變時更新摘要；打開一個面板時收起其他面板。
    document.addEventListener( 'change', function ( e ) {
        var cb = e.target;
        if ( ! cb || ! cb.classList ) { return; }
        // v1.7.0：「誰看得到」面板：模式、角色、也套用到管理員、只藏選單。
        var accessBox = cb.closest && cb.closest( '.ys-ec-access' );
        if ( accessBox && ( cb.classList.contains( 'ys-ec-role-cb' ) || cb.classList.contains( 'ys-ec-access-mode' )
            || cb.classList.contains( 'ys-ec-access-admins' ) || cb.classList.contains( 'ys-ec-access-menu-only' ) ) ) {
            accessBox.setAttribute( 'data-touched', '1' );
            updateAccessSummary( accessBox );
            return;
        }
        if ( ! cb.classList.contains( 'ys-ec-role-cb' ) ) { return; }
        var box = cb.closest( '.ys-ec-roles' );
        if ( ! box ) { return; }
        var labels = Array.prototype.slice.call( box.querySelectorAll( '.ys-ec-role-cb:checked' ) ).map( function ( c ) {
            return ( c.parentNode.textContent || '' ).replace( /（角色已不存在）$/, '' ).replace( /^\s+|\s+$/g, '' );
        } );
        var sum = box.querySelector( '.ys-ec-roles__sum' );
        if ( sum ) { sum.textContent = roleSummary( labels ); }
    } );

    // ─────────────────────────────────────────────
    // v1.7.0：「誰看得到」面板（與 PHP access_picker_html() 同一套）
    //   不限／只有／除了 × 角色＋帳號，加上「也套用到管理員」「只藏選單」。存檔時只在舊欄位表達不了時才送 access：
    //   「只有這些角色」（沒有帳號、不含管理員、不是只藏選單）照舊存 roles，1.6.0 也看得懂。
    // ─────────────────────────────────────────────
    var accessSeq = 0;
    // 複製項、自訂連結的面板說明（與 PHP YSPermissionAdmin::LINK_ONLY_NOTE 相同）。
    var LINK_ONLY_NOTE = '這一列只管側欄上的這個連結；直接開網址照那一頁原本的設定。';

    function parseJson( s ) {
        if ( ! s ) { return null; }
        try { return JSON.parse( s ); } catch ( e ) { return null; }
    }

    function accessState( box ) {
        var modeEl = box.querySelector( '.ys-ec-access-mode:checked' );
        return {
            mode:     modeEl ? modeEl.value : ( box.getAttribute( 'data-mode' ) || 'any' ),
            roles:    Array.prototype.slice.call( box.querySelectorAll( '.ys-ec-role-cb:checked' ) ).map( function ( c ) { return c.dataset.role; } ),
            users:    Array.prototype.slice.call( box.querySelectorAll( '.ys-ec-user-chip' ) ).map( function ( chip ) {
                return { id: parseInt( chip.getAttribute( 'data-uid' ) || '0', 10 ) || 0, name: chip.getAttribute( 'data-name' ) || '' };
            } ).filter( function ( u ) { return u.id > 0; } ),
            admins:   !! ( box.querySelector( '.ys-ec-access-admins' ) || {} ).checked,
            menuOnly: !! ( box.querySelector( '.ys-ec-access-menu-only' ) || {} ).checked
        };
    }

    // 這一列要存的 roles 與 access（access＝null 表示不寫）。
    function readAccess( row ) {
        var box = row.querySelector( '.ys-ec-roles' );
        if ( ! box ) {
            return { roles: [], access: null };
        }
        if ( ! box.classList.contains( 'ys-ec-access' ) ) {
            return { roles: checkedRoles( row ), access: null }; // 本外掛自己的頁面：只有角色。
        }
        if ( box.getAttribute( 'data-complex' ) === '1' && box.getAttribute( 'data-touched' ) !== '1' ) {
            var original = parseJson( box.getAttribute( 'data-access' ) );
            if ( original ) {
                return { roles: [], access: original }; // 複合規則沒動過：原樣送回。
            }
        }
        var s = accessState( box );
        if ( 'any' === s.mode ) {
            return { roles: [], access: null };
        }
        var ids = s.users.map( function ( u ) { return u.id; } );
        if ( 'only' === s.mode && s.roles.length && ! ids.length && ! s.admins && ! s.menuOnly ) {
            return { roles: s.roles, access: null };
        }
        var side = { roles: s.roles, users: ids };
        var none = { roles: [], users: [] };
        return {
            roles:  [],
            access: {
                v:           1,
                allow:       'only' === s.mode ? side : none,
                deny:        'only' === s.mode ? none : side,
                others:      'only' === s.mode ? 'deny' : 'inherit',
                super_admin: s.admins ? 'apply' : 'exempt',
                scope:       s.menuOnly ? 'menu_only' : 'block'
            }
        };
    }

    function accessSummary( mode, labels, admins, menuOnly ) {
        if ( 'any' === mode ) {
            return '不限';
        }
        var text;
        if ( ! labels.length ) {
            text = 'only' === mode ? ( admins ? '沒有人' : '只有管理員' ) : '不限';
        } else {
            var list = labels.length <= 2 ? labels.join( '、' ) : labels[0] + ' 等 ' + labels.length + ' 項';
            text = ( 'only' === mode ? '只有 ' : '除了 ' ) + list;
        }
        if ( admins && ! ( 'only' === mode && ! labels.length ) ) {
            text += '（含管理員）';
        }
        if ( menuOnly ) {
            text += '・只藏選單';
        }
        return text;
    }

    function updateAccessSummary( box ) {
        var s = accessState( box );
        var labels = Array.prototype.slice.call( box.querySelectorAll( '.ys-ec-role-cb:checked' ) ).map( function ( c ) {
            return ( c.parentNode.textContent || '' ).replace( /（角色已不存在）$/, '' ).replace( /^\s+|\s+$/g, '' );
        } );
        s.users.forEach( function ( u ) { labels.push( u.name || ( '#' + u.id ) ); } );
        box.setAttribute( 'data-mode', s.mode );
        var sum = box.querySelector( '.ys-ec-roles__sum' );
        if ( sum ) {
            sum.textContent = accessSummary( s.mode, labels, s.admins, s.menuOnly );
        }
    }

    // 新面板（JS 新增的列）。access 有帳號時名稱未知，顯示 #ID。
    // linkOnly：複製項、自訂連結（規則只管側欄上的這個連結，直接開網址照那一頁原本的設定），不提供「只藏選單」。
    function accessPickerHtml( roles, access, linkOnly ) {
        var mode = 'any', picked = roles || [], users = [], admins = false, menuOnly = false;
        if ( access ) {
            mode = 'deny' === access.others ? 'only' : 'except';
            var side = 'only' === mode ? access.allow : access.deny;
            picked = ( side && side.roles ) || [];
            users = ( side && side.users ) || [];
            admins = 'apply' === access.super_admin;
            menuOnly = ! linkOnly && 'menu_only' === access.scope;
        } else if ( picked.length ) {
            mode = 'only';
        }
        var opts = Array.isArray( cfg.roles ) && cfg.roles.length ? cfg.roles : [ [ 'administrator', '管理員' ], [ 'editor', '編輯' ] ];
        var known = {};
        var labels = [];
        var boxes = opts.map( function ( o ) {
            var key = String( o[0] ); // 純數字的角色鍵在 JSON 裡可能是數字，存下的是字串。
            known[ key ] = true;
            var on = picked.indexOf( key ) >= 0;
            if ( on ) { labels.push( o[1] ); }
            return '<label><input type="checkbox" class="ys-ec-role-cb" data-role="' + escHtml( key ) + '"' + ( on ? ' checked' : '' ) + '> ' + escHtml( o[1] ) + '</label>';
        } ).join( '' );
        picked.forEach( function ( r ) {
            if ( 'string' !== typeof r || '' === r || known[ r ] ) { return; }
            labels.push( r );
            boxes += '<label class="ys-ec-roles__missing" title="站上已經沒有這個角色；取消勾選後儲存就會移除"><input type="checkbox" class="ys-ec-role-cb" data-role="' + escHtml( r ) + '" checked> ' + escHtml( r + '（角色已不存在）' ) + '</label>';
        } );
        var chips = users.map( function ( id ) {
            labels.push( '#' + ( id | 0 ) );
            return userChipHtml( id | 0, '#' + ( id | 0 ) );
        } ).join( '' );
        var name = 'ys-ec-access-mode-js-' + ( ++accessSeq );
        var modes = [ [ 'any', '不限' ], [ 'only', '只有' ], [ 'except', '除了' ] ].map( function ( m ) {
            return '<label><input type="radio" class="ys-ec-access-mode" name="' + name + '" value="' + m[0] + '"' + ( mode === m[0] ? ' checked' : '' ) + '> ' + m[1] + '</label>';
        } ).join( '' );
        return '<details class="ys-ec-roles ys-ec-access" data-mode="' + mode + '">'
            + '<summary class="ys-ec-roles__sum" title="誰看得到這個選單">' + escHtml( accessSummary( mode, labels, admins, menuOnly ) ) + '</summary>'
            + '<div class="ys-ec-roles__panel">'
            + '<div class="ys-ec-access__modes" role="radiogroup" aria-label="誰看得到">' + modes + '</div>'
            + '<div class="ys-ec-access__roles">' + boxes + '</div>'
            + '<div class="ys-ec-access__users"><span class="ys-ec-access__chips">' + chips + '</span>'
            + '<input type="search" class="ys-ec-access-user-search" placeholder="加帳號：輸入名稱或 email" autocomplete="off" aria-label="加帳號">'
            + '<ul class="ys-ec-access__results" hidden></ul></div>'
            + '<label class="ys-ec-access__opt"><input type="checkbox" class="ys-ec-access-admins"' + ( admins ? ' checked' : '' ) + '> 也套用到管理員</label>'
            + ( linkOnly
                ? '<p class="ys-ec-access__note">' + escHtml( LINK_ONLY_NOTE ) + '</p>'
                : '<label class="ys-ec-access__opt"><input type="checkbox" class="ys-ec-access-menu-only"' + ( menuOnly ? ' checked' : '' ) + '> 只藏選單（網址照樣進得去）</label>' )
            + '</div></details>';
    }

    function userChipHtml( id, name ) {
        return '<span class="ys-ec-user-chip" data-uid="' + ( id | 0 ) + '" data-name="' + escHtml( name ) + '">' + escHtml( name )
            + '<button type="button" class="ys-ec-user-chip__x" aria-label="' + escHtml( '移除 ' + name ) + '">×</button></span>';
    }

    // 一列的面板（「誰看得到」或角色面板）原樣取出，移到主選單／移回原位、套用到底下的子選單時帶過去。
    function panelHtmlOf( row ) {
        var box = row.querySelector( '.ys-ec-roles' );
        if ( ! box ) {
            return '';
        }
        box.open = false;
        // outerHTML 帶的是 checked「屬性」（頁面載入時的勾選）：畫面上改過、還沒存的勾選先寫回屬性，帶過去的才是現在的設定。
        Array.prototype.forEach.call( box.querySelectorAll( 'input[type="checkbox"], input[type="radio"]' ), function ( input ) {
            if ( input.checked ) {
                input.setAttribute( 'checked', 'checked' );
            } else {
                input.removeAttribute( 'checked' );
            }
        } );
        return box.outerHTML;
    }

    // 複製過來的面板換一組單選鈕 name（跟原本那一列分開）。
    function freshRadioNames( html ) {
        var name = 'ys-ec-access-mode-js-' + ( ++accessSeq );
        return String( html ).replace( /name="ys-ec-access-mode-[^"]*"/g, 'name="' + name + '"' );
    }

    // 「開新分頁」與「顯示在」（JS 新增的列）。
    function rowOptionsHtml( newTab, level ) {
        var html = '<div class="ys-ec-row-options"><label class="ys-ec-newtab-label" title="點這個選單時在新分頁打開">'
            + '<input type="checkbox" class="ys-ec-newtab"' + ( newTab ? ' checked' : '' ) + '> 開新分頁</label>';
        if ( 'sub' === level ) {
            html += '<label class="ys-ec-display-parent-field" title="把這個子選單放到別的頂層選單底下顯示（連結與權限不變）">顯示在 '
                + '<select class="ys-ec-display-parent"><option value="">原本的位置</option></select></label>';
        }
        return html + '</div>';
    }

    // 帳號搜尋（wp/v2/users）。
    function searchUsers( q ) {
        var url = ( cfg.usersUrl || '/wp-json/wp/v2/users' )
            + ( ( cfg.usersUrl || '' ).indexOf( '?' ) >= 0 ? '&' : '?' )
            + 'search=' + encodeURIComponent( q ) + '&context=edit&per_page=10';
        return fetch( url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' }
        } ).then( function ( r ) { return r.ok ? r.json() : []; } ).catch( function () { return []; } );
    }

    function userDisplayName( u ) {
        var login = u.slug || u.username || ( 'user-' + ( u.id | 0 ) );
        return u.name && u.name !== login ? u.name + '（' + login + '）' : login;
    }

    function renderUserResults( list, users, onPick ) {
        list.innerHTML = '';
        if ( ! users || ! users.length ) {
            var none = document.createElement( 'li' );
            none.className = 'no-results';
            none.textContent = '找不到符合的帳號';
            list.appendChild( none );
            list.hidden = false;
            return;
        }
        users.forEach( function ( u ) {
            var li = document.createElement( 'li' );
            li.setAttribute( 'role', 'option' );
            li.tabIndex = 0;
            li.textContent = userDisplayName( u ) + ( u.email ? '　' + u.email : '' ) + '　#' + ( u.id | 0 );
            var pick = function () { onPick( { id: u.id | 0, name: userDisplayName( u ) } ); };
            li.addEventListener( 'click', pick );
            li.addEventListener( 'keydown', function ( e ) {
                if ( 'Enter' === e.key ) { e.preventDefault(); pick(); }
            } );
            list.appendChild( li );
        } );
        list.hidden = false;
    }

    // 面板裡的帳號搜尋（每個面板一個計時器）。
    document.addEventListener( 'input', function ( e ) {
        var input = e.target;
        if ( ! input || ! input.classList || ! input.classList.contains( 'ys-ec-access-user-search' ) ) { return; }
        var box = input.closest( '.ys-ec-access' );
        var list = box ? box.querySelector( '.ys-ec-access__results' ) : null;
        if ( ! list ) { return; }
        clearTimeout( input._ysTimer );
        var q = trimStr( input.value );
        if ( q.length < 2 ) {
            list.hidden = true;
            list.innerHTML = '';
            return;
        }
        input._ysTimer = setTimeout( function () {
            searchUsers( q ).then( function ( users ) {
                renderUserResults( list, users, function ( u ) {
                    var chips = box.querySelector( '.ys-ec-access__chips' );
                    if ( chips && ! chips.querySelector( '.ys-ec-user-chip[data-uid="' + u.id + '"]' ) ) {
                        chips.insertAdjacentHTML( 'beforeend', userChipHtml( u.id, u.name ) );
                    }
                    // 選了帳號但還是「不限」時，自動改成「只有」。
                    var any = box.querySelector( '.ys-ec-access-mode[value="any"]' );
                    if ( any && any.checked ) {
                        var only = box.querySelector( '.ys-ec-access-mode[value="only"]' );
                        if ( only ) { only.checked = true; }
                    }
                    input.value = '';
                    list.hidden = true;
                    list.innerHTML = '';
                    box.setAttribute( 'data-touched', '1' );
                    updateAccessSummary( box );
                    markDirty();
                } );
            } );
        }, 300 );
    } );

    // 移除帳號、套用到底下的子選單。
    document.addEventListener( 'click', function ( e ) {
        var x = e.target && e.target.closest && e.target.closest( '.ys-ec-user-chip__x' );
        if ( x ) {
            var box = x.closest( '.ys-ec-access' );
            var chip = x.closest( '.ys-ec-user-chip' );
            if ( chip ) { chip.parentNode.removeChild( chip ); }
            if ( box ) {
                box.setAttribute( 'data-touched', '1' );
                updateAccessSummary( box );
            }
            markDirty();
            return;
        }
        var apply = e.target && e.target.closest && e.target.closest( '.ys-ec-access-apply-children' );
        if ( apply ) {
            applyToChildren( apply.closest( 'tr' ) );
        }
    } );

    // 全部選單分頁：有子選單的頂層列，面板裡加「套用到底下的子選單」。
    function addApplyChildrenButtons( tbody ) {
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr[data-level="top"][data-slug]' ), function ( row ) {
            var box = row.querySelector( '.ys-ec-access .ys-ec-roles__panel' );
            if ( ! box || ! childRows( tbody, row.getAttribute( 'data-slug' ) ).length ) { return; }
            box.insertAdjacentHTML( 'beforeend', '<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--ghost ys-ec-access-apply-children">套用到底下的子選單</button>' );
        } );
    }

    function childRows( tbody, parent ) {
        return Array.prototype.filter.call( tbody.querySelectorAll( 'tr[data-level="sub"]' ), function ( r ) {
            return r.getAttribute( 'data-parent' ) === parent && r.getAttribute( 'data-promoted' ) !== '1' && r.getAttribute( 'data-copy' ) !== '1';
        } );
    }

    function applyToChildren( row ) {
        var tbody = row ? row.closest( 'tbody' ) : null;
        var source = row ? row.querySelector( '.ys-ec-access' ) : null;
        if ( ! tbody || ! source ) { return; }
        var kids = childRows( tbody, row.getAttribute( 'data-slug' ) );
        var html = panelHtmlOf( row ).replace( /<button type="button" class="ysca-btn ysca-btn--sm ysca-btn--ghost ys-ec-access-apply-children">[^<]*<\/button>/, '' );
        kids.forEach( function ( kid ) {
            var group = kid.querySelector( '.role-cb-group' );
            if ( ! group || ! group.querySelector( '.ys-ec-access' ) ) { return; } // 本外掛自己的頁面只有角色，不套。
            group.innerHTML = freshRadioNames( html );
            var box = group.querySelector( '.ys-ec-access' );
            if ( box ) {
                box.setAttribute( 'data-touched', '1' );
                box.removeAttribute( 'data-complex' );
            }
            flashRow( kid );
        } );
        source.open = false;
        markDirty();
        setStatus( document.getElementById( 'ys-ec-save-status' ), '已套用到 ' + kids.length + ' 個子選單；按「儲存設定」生效', '#0f766e', 5000 );
    }

    // 「顯示在」下拉選單：第一次打開時才填入全部頂層選單（每一列都放完整清單太大）。
    function fillDisplayParent( e ) {
        var sel = e.target && e.target.closest && e.target.closest( '.ys-ec-display-parent' );
        if ( ! sel || sel.getAttribute( 'data-filled' ) === '1' ) { return; }
        sel.setAttribute( 'data-filled', '1' );
        var row = sel.closest( 'tr' );
        var own = row ? ( row.getAttribute( 'data-slug' ) || '' ) : '';
        var parent = row ? ( row.getAttribute( 'data-parent' ) || '' ) : '';
        var current = sel.value;
        ( Array.isArray( cfg.tops ) ? cfg.tops : [] ).forEach( function ( t ) {
            if ( ! t || t[0] === own || t[0] === parent || t[0] === current ) { return; }
            var opt = document.createElement( 'option' );
            opt.value = t[0];
            opt.textContent = t[1];
            sel.appendChild( opt );
        } );
    }
    document.addEventListener( 'mousedown', fillDisplayParent, true );
    document.addEventListener( 'focusin', fillDisplayParent );
    document.addEventListener( 'keydown', fillDisplayParent, true );

    // 自訂連結列（主選單分頁；與 PHP render_item_row() 的自訂連結列對齊）。
    function buildCustomRow( order ) {
        var html = '<tr data-slug="" data-level="top" data-self-uid="0" data-custom="1" data-custom-url="" data-default-icon="dashicons-admin-links">'
            + '<td class="ys-ec-drag-handle" title="拖拉以排序">&#x205E;&#x205E;</td>'
            + '<td><input type="number" class="ys-ec-order-input" value="' + ( order | 0 ) + '" min="0" max="9999"></td>'
            + '<td><div class="menu-line"><strong>自訂連結</strong> <span class="level-badge level-custom">自訂連結</span>'
                + '<span class="ys-ec-preview-badges" aria-live="polite"></span></div>'
                + '<div class="ys-ec-custom-fields"><input type="text" class="ys-ec-custom-title" value="" placeholder="連結名稱（必填）" aria-label="連結名稱">'
                + '<input type="text" class="ys-ec-custom-url" value="" placeholder="網址：https://… 或 edit.php?post_status=draft" aria-label="連結網址" spellcheck="false"></div>'
                + '<div class="ys-ec-icon-field"><button type="button" class="ys-ec-icon-btn" aria-expanded="false" title="選擇圖示"><span class="ys-ec-icon-preview" aria-hidden="true"></span><span class="screen-reader-text">選擇圖示</span></button>'
                + '<input type="text" class="ys-ec-icon-input" value="" placeholder="圖示：留空用連結圖示（dashicons-… 或圖片網址）" spellcheck="false"></div>'
                + rowOptionsHtml( false, null ) + '</td>'
            + '<td><div class="role-cb-group">' + accessPickerHtml( [], null, true ) + '</div>'
                + '<label class="ys-ec-self-only-label" title="勾選後此選單只有目前登入的帳號看得到（含其他管理員）"><input type="checkbox" class="ys-ec-self-only"> 僅限本人</label></td>'
            + '<td><input type="text" class="ys-ec-color-picker" value="" data-default-color="" placeholder="#0073aa" maxlength="7"></td>'
            + '<td class="ys-ec-hide-cb"><input type="checkbox" class="ys-ec-hide-checkbox" title="對管理員以外的帳號隱藏這個連結（那一頁本身不受影響）"></td>'
            + '<td class="ys-ec-row-actions"><button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-delete-row" title="從清單移除">×</button></td>'
            + '</tr>';
        return parseRow( html );
    }

    // 新增列用的順序值：目前最大的＋10。
    function nextOrder( tbody ) {
        var max = 0;
        Array.prototype.forEach.call( tbody.querySelectorAll( '.ys-ec-order-input' ), function ( inp ) {
            var v = parseInt( inp.value || '0', 10 ) || 0;
            if ( v > max ) { max = v; }
        } );
        return max + 10;
    }

    // ─────────────────────────────────────────────
    // v1.7.0：以某位使用者試算（照已儲存的設定，不切換身分）
    // ─────────────────────────────────────────────
    function previewRows( tbody ) {
        var out = [];
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), function ( row ) {
            if ( row.getAttribute( 'data-separator' ) === '1' || row.getAttribute( 'data-self-row' ) === '1' ) { return; }
            var d = null;
            if ( row.getAttribute( 'data-copy' ) === '1' ) {
                d = { kind: 'copy', slug: row.getAttribute( 'data-copy-slug' ) || '', parent: row.getAttribute( 'data-copy-parent' ) || '' };
            } else if ( row.getAttribute( 'data-promoted' ) === '1' ) {
                d = { kind: 'top', slug: row.getAttribute( 'data-copy-key' ) || '' };
            } else if ( row.getAttribute( 'data-custom' ) === '1' ) {
                d = { kind: 'top', slug: row.getAttribute( 'data-slug' ) || '' };
            } else if ( row.getAttribute( 'data-slug' ) ) {
                d = row.getAttribute( 'data-level' ) === 'sub'
                    ? { kind: 'sub', slug: row.getAttribute( 'data-slug' ), parent: row.getAttribute( 'data-parent' ) || '' }
                    : { kind: 'top', slug: row.getAttribute( 'data-slug' ) };
            }
            if ( ! d || '' === d.slug ) { return; }
            d.cap = row.getAttribute( 'data-cap' ) || '';
            d.i = out.length;
            out.push( { row: row, data: d } );
        } );
        return out;
    }

    function paintPreview( row, result ) {
        var holder = row.querySelector( '.ys-ec-preview-badges' );
        if ( ! holder ) {
            var line = row.querySelector( '.menu-line' );
            if ( ! line ) { return; }
            holder = document.createElement( 'span' );
            holder.className = 'ys-ec-preview-badges';
            line.appendChild( holder );
        }
        var badge = function ( ok, label ) {
            return '<span class="ys-ec-pv ' + ( ok ? 'ys-ec-pv--ok' : 'ys-ec-pv--no' ) + '">' + ( ok ? '✓ ' : '✕ ' ) + label + '</span>';
        };
        holder.innerHTML = badge( !! result.sidebar, result.sidebar ? '側欄看得到' : '側欄看不到' )
            + ( null === result.url || undefined === result.url ? '' : badge( !! result.url, result.url ? '網址進得去' : '網址進不去' ) );
    }

    function clearPreview( tbody ) {
        Array.prototype.forEach.call( tbody.querySelectorAll( '.ys-ec-preview-badges' ), function ( h ) { h.innerHTML = ''; } );
    }

    function bootPreview( tbody ) {
        var input = document.getElementById( 'ys-ec-preview-search' );
        var results = document.getElementById( 'ys-ec-preview-results' );
        var status = document.getElementById( 'ys-ec-preview-status' );
        var clear = document.getElementById( 'ys-ec-preview-clear' );
        if ( ! input || ! results ) { return; }
        var timer = null;
        input.addEventListener( 'input', function () {
            var q = trimStr( input.value );
            clearTimeout( timer );
            if ( q.length < 2 ) {
                results.hidden = true;
                results.innerHTML = '';
                return;
            }
            timer = setTimeout( function () {
                searchUsers( q ).then( function ( users ) { renderUserResults( results, users, run ); } );
            }, 300 );
        } );
        if ( clear ) {
            clear.addEventListener( 'click', function () {
                clearPreview( tbody );
                input.value = '';
                clear.hidden = true;
                setStatus( status, '', '' );
            } );
        }
        function run( u ) {
            input.value = u.name;
            results.hidden = true;
            results.innerHTML = '';
            setStatus( status, '試算中…', '#6b7280' );
            var rows = previewRows( tbody );
            fetch( cfg.restUrl + '/preview', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify( { user_id: u.id, rows: rows.map( function ( r ) { return r.data; } ) } )
            } )
                .then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
                .then( function ( res ) {
                    if ( ! res.ok || ! res.data || ! res.data.success ) {
                        setStatus( status, '試算失敗：' + ( ( res.data && ( res.data.error || res.data.message ) ) || '未知錯誤' ), '#dc2626' );
                        return;
                    }
                    clearPreview( tbody );
                    ( res.data.rows || [] ).forEach( function ( result ) {
                        var hit = rows[ result.i | 0 ];
                        if ( hit && null !== result.i ) { paintPreview( hit.row, result ); }
                    } );
                    if ( clear ) { clear.hidden = false; }
                    setStatus( status, '以「' + u.name + '」試算' + ( isDirty ? '（照已儲存的設定，表上還有沒存的變更）' : '' ), '#0f766e' );
                } )
                .catch( function ( err ) {
                    setStatus( status, '試算失敗：' + err.message, '#dc2626' );
                } );
        }
    }
    document.addEventListener( 'toggle', function ( e ) {
        var box = e.target;
        if ( ! box || ! box.classList || ! box.classList.contains( 'ys-ec-roles' ) || ! box.open ) { return; }
        Array.prototype.forEach.call( document.querySelectorAll( '.ys-ec-roles[open]' ), function ( other ) {
            if ( other !== box ) { other.open = false; }
        } );
        positionPanel( box );
    }, true );

    // v1.7.0：表格外框有捲軸（overflow），往下展開的面板在最後幾列會被切掉、外框多出垂直捲軸。打開時看外框（與視窗）裡
    // 還有多少空間：下面放不下而上面比較寬就往上開，兩邊都放不下就限高；右邊超出外框就往左移。面板留在 details 裡用
    // absolute 定位（新版 Chrome 的 details 內容有 containment，position:fixed 會變成相對 details，不能用）。
    function positionPanel( box ) {
        var sum = box.querySelector( '.ys-ec-roles__sum' );
        var panel = box.querySelector( '.ys-ec-roles__panel' );
        if ( ! sum || ! panel ) { return; }
        panel.style.top = '';
        panel.style.bottom = '';
        panel.style.left = '';
        panel.style.maxHeight = '';
        var wrap = box.closest( '.ysca-table-wrap' ) || document.documentElement;
        var w = wrap.getBoundingClientRect();
        var view = {
            top:    Math.max( 0, w.top ),
            bottom: Math.min( window.innerHeight, w.bottom ),
            left:   Math.max( 0, w.left ),
            right:  Math.min( document.documentElement.clientWidth, w.right )
        };
        var s = sum.getBoundingClientRect();
        var h = panel.offsetHeight;
        var pw = panel.offsetWidth;
        var below = view.bottom - s.bottom - 8;
        var above = s.top - view.top - 8;
        if ( h > below && above > below ) {
            panel.style.top = 'auto';
            panel.style.bottom = 'calc(100% + 4px)';
            if ( h > above ) { panel.style.maxHeight = Math.max( 160, above ) + 'px'; }
        } else if ( h > below ) {
            panel.style.maxHeight = Math.max( 160, below ) + 'px';
        }
        var overflow = s.left + pw - ( view.right - 8 );
        if ( overflow > 0 ) {
            panel.style.left = -Math.min( overflow, Math.max( 0, s.left - view.left ) ) + 'px';
        }
    }
    window.addEventListener( 'resize', function () {
        Array.prototype.forEach.call( document.querySelectorAll( '.ys-ec-roles[open]' ), function ( box ) { box.open = false; } );
    } );
    // 鍵盤：Esc 收起面板、焦點回到摘要；用 Tab 離開面板時也收起。
    document.addEventListener( 'keydown', function ( e ) {
        if ( 'Escape' !== e.key ) { return; }
        var box = e.target && e.target.closest && e.target.closest( '.ys-ec-roles[open]' );
        if ( ! box ) { return; }
        box.open = false;
        var sum = box.querySelector( '.ys-ec-roles__sum' );
        if ( sum ) { sum.focus(); }
    } );
    document.addEventListener( 'focusout', function ( e ) {
        var box = e.target && e.target.closest && e.target.closest( '.ys-ec-roles[open]' );
        if ( box && e.relatedTarget && ! box.contains( e.relatedTarget ) ) {
            box.open = false;
        }
    } );
    document.addEventListener( 'click', function ( e ) {
        // v1.7.0：點了面板裡的搜尋結果後，那一項已經從畫面拿掉（isConnected＝false），不算點在面板外面。
        if ( e.target && ( false === e.target.isConnected || ( e.target.closest && e.target.closest( '.ys-ec-roles' ) ) ) ) { return; }
        Array.prototype.forEach.call( document.querySelectorAll( '.ys-ec-roles[open]' ), function ( box ) { box.open = false; } );
    } );

    function parseRow( html ) {
        var temp = document.createElement( 'tbody' );
        temp.innerHTML = html.replace( /^\s+|\s+$/g, '' );
        return temp.firstElementChild;
    }

    // 頂層複製／提升列（與 PHP render_top_copy_row 對齊）。
    function buildTopRow( kind, d ) {
        var isCopy = 'copy' === kind;
        var code   = isCopy ? d.copySlug : d.subSlug;
        var cls    = isCopy ? 'ys-ec-copy-top-row' : 'ys-ec-promoted-top-row';
        var badge  = isCopy ? '複製' : '已移到主選單';
        var badgeCls = isCopy ? 'level-copy' : 'level-promoted';
        var attrs = ' data-row-kind="' + kind + '"'
            + ' data-copy-key="' + escapeAttr( d.key ) + '"'
            + ' data-after=""'
            + ' data-default-icon="' + escapeAttr( d.defaultIcon || '' ) + '"'
            + ' data-self-uid="' + ( d.selfUid | 0 ) + '"'
            + ' data-parent="' + escapeAttr( d.parent || '' ) + '"'
            + ' data-parent-title="' + escapeAttr( d.parentTitle || '' ) + '"';
        if ( isCopy ) {
            attrs += ' data-copy="1" data-copy-slug="' + escapeAttr( d.copySlug ) + '" data-copy-parent="' + escapeAttr( d.copyParent ) + '"';
        } else {
            attrs += ' data-promoted="1" data-slug="' + escapeAttr( d.subSlug ) + '"';
        }
        var actions = isCopy
            ? '<button type="button" class="ys-ec-row-btn ys-ec-row-btn--danger ys-ec-delete-copy" title="刪除這個複製項（原子選單不受影響）"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="ys-ec-row-btn__label">刪除複製</span></button>'
            : '<button type="button" class="ys-ec-row-btn ys-ec-move-back-btn" title="移回原位：把這個項目放回原本的子選單位置"><span class="dashicons dashicons-undo" aria-hidden="true"></span><span class="ys-ec-row-btn__label">移回原位</span></button>';
        var html = '<tr class="' + cls + '"' + attrs + '>'
            + '<td class="ys-ec-drag-handle" title="拖拉以排序（放到想要的頂層選單後面）">&#x205E;&#x205E;</td>'
            + '<td class="ysca-text-center"><span class="ys-ec-order-drag ysca-text-muted" title="拖拉左側把手調整位置">&#x21C5;</span></td>'
            + '<td><div class="menu-line"><strong>' + escapeHtml( d.title ) + '</strong> <code>' + escapeHtml( code ) + '</code> '
                + '<span class="level-badge ' + badgeCls + '">' + badge + '</span> '
                + '<span class="ys-ec-copy-source">原位置：' + escapeHtml( d.parentTitle || '' ) + ' › ' + escapeHtml( d.title ) + '</span>'
                + '<span class="ys-ec-preview-badges" aria-live="polite"></span></div>'
                + '<input type="text" class="title-override-inline ys-ec-title-override" value="' + escapeAttr( d.titleOverride || '' ) + '" placeholder="自訂顯示名稱（留空保留原名）">'
                + '<div class="ys-ec-icon-field"><button type="button" class="ys-ec-icon-btn" aria-expanded="false" title="選擇圖示"><span class="ys-ec-icon-preview" aria-hidden="true"></span><span class="screen-reader-text">選擇圖示</span></button>'
                + '<input type="text" class="ys-ec-icon-input" value="' + escapeAttr( d.icon || '' ) + '" placeholder="圖示：留空沿用原父選單（dashicons-… 或圖片網址）" spellcheck="false"></div>'
                + rowOptionsHtml( !! d.newTab, null ) + '</td>'
            + '<td><div class="role-cb-group">' + ( d.panelHtml ? freshRadioNames( d.panelHtml ) : accessPickerHtml( d.roles || [], null, isCopy ) ) + '</div>'
                + '<label class="ys-ec-self-only-label"><input type="checkbox" class="ys-ec-self-only"' + ( ( d.selfUid | 0 ) > 0 ? ' checked' : '' ) + '> 僅限本人</label></td>'
            + '<td class="ys-ec-promote-cell ysca-text-center"><span class="ysca-text-muted" aria-hidden="true">—</span></td>'
            + '<td class="ys-ec-hide-cb"><input type="checkbox" class="ys-ec-hide-checkbox"' + ( d.hide ? ' checked' : '' ) + '></td>'
            + '<td class="ys-ec-row-actions">' + actions + '</td>'
            + '</tr>';
        return parseRow( html );
    }

    // 子選單列（與 PHP render_item_row 的 ys_cart 子列對齊；移回原位時重建）。
    function buildSubRow( d ) {
        var copyAttrs = d.copyKey
            ? ' data-copy-key="' + escapeAttr( d.copyKey ) + '" data-copy-slug="' + escapeAttr( d.slug ) + '" data-copy-parent="' + escapeAttr( d.parent ) + '"'
            : '';
        if ( d.promoteKey ) {
            copyAttrs += ' data-promote-key="' + escapeAttr( d.promoteKey ) + '"';
        }
        var html = '<tr data-slug="' + escapeAttr( d.slug ) + '" data-level="sub" data-self-uid="' + ( d.selfUid | 0 ) + '"'
            + ' data-parent="' + escapeAttr( d.parent ) + '" data-parent-title="' + escapeAttr( d.parentTitle || '' ) + '"' + copyAttrs + '>'
            + '<td class="ys-ec-drag-handle" title="拖拉以排序">&#x205E;&#x205E;</td>'
            + '<td><input type="number" class="ys-ec-order-input" value="' + escapeAttr( String( d.order || 0 ) ) + '" min="0" max="9999"></td>'
            + '<td><div class="menu-line"><span class="menu-indent">&#8627;</span> <strong>' + escapeHtml( d.title ) + '</strong> <code>' + escapeHtml( d.slug ) + '</code> <span class="level-badge level-sub">子頁</span>'
                + '<span class="ys-ec-preview-badges" aria-live="polite"></span></div>'
                + '<input type="text" class="title-override-inline ys-ec-title-override" value="' + escapeAttr( d.titleOverride || '' ) + '" placeholder="自訂顯示名稱（留空保留原名）">'
                + rowOptionsHtml( !! d.newTab, 'sub' ) + '</td>'
            + '<td><div class="role-cb-group">' + ( d.panelHtml ? freshRadioNames( d.panelHtml ) : accessPickerHtml( d.roles || [], null ) ) + '</div>'
                + '<label class="ys-ec-self-only-label"><input type="checkbox" class="ys-ec-self-only"' + ( ( d.selfUid | 0 ) > 0 ? ' checked' : '' ) + '> 僅限本人</label></td>'
            + '<td class="ys-ec-promote-cell ysca-text-center"></td>'
            + '<td class="ys-ec-hide-cb"><input type="checkbox" class="ys-ec-hide-checkbox"' + ( d.hide ? ' checked' : '' ) + '></td>'
            + '<td class="ys-ec-row-actions">'
                + '<button type="button" class="ys-ec-row-btn ys-ec-move-btn" title="移到主選單：變成 wp-admin 頂層選單，原位置移除"><span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span><span class="ys-ec-row-btn__label">移到主選單</span></button>'
                + ( d.copyKey ? '<button type="button" class="ys-ec-row-btn ys-ec-copy-btn" title="複製到主選單：主選單另外多一個連到同一頁的項目，原位置保留"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="ys-ec-row-btn__label">複製到主選單</span></button>' : '' )
            + '</td></tr>';
        return parseRow( html );
    }

    // 這一列屬於哪一個頂層群組（頂層＝自己 slug；子選單／父選單預設頁＝父；複製／提升列＝自己的代稱）。
    function groupOf( row ) {
        if ( row.getAttribute( 'data-copy' ) === '1' || row.getAttribute( 'data-promoted' ) === '1' ) {
            return row.getAttribute( 'data-copy-key' ) || '';
        }
        if ( row.getAttribute( 'data-level' ) === 'top' ) {
            return row.getAttribute( 'data-slug' ) || '';
        }
        return row.getAttribute( 'data-parent' ) || '';
    }

    // 把列插到某個頂層群組的最後一列之後；找不到就接在最後。
    function insertAfterGroup( tbody, anchor, tr ) {
        var rows = tbody.children;
        var ref = null;
        for ( var i = 0; i < rows.length; i++ ) {
            if ( groupOf( rows[ i ] ) === anchor ) {
                ref = rows[ i ];
            }
        }
        if ( ref ) {
            tbody.insertBefore( tr, ref.nextSibling );
        } else {
            tbody.appendChild( tr );
        }
    }

    function findTopRowByKey( tbody, key ) {
        if ( ! key ) {
            return null;
        }
        var rows = tbody.querySelectorAll( 'tr[data-copy="1"], tr[data-promoted="1"]' );
        for ( var i = 0; i < rows.length; i++ ) {
            if ( rows[ i ].getAttribute( 'data-copy-key' ) === key ) {
                return rows[ i ];
            }
        }
        return null;
    }

    // 目前頁面上的複製項（頂層複製列）。
    function collectCopies( tab ) {
        var tbody = document.getElementById( 'ys-ec-permission-rows' );
        if ( ! tbody ) {
            return [];
        }
        return Array.prototype.map.call( tbody.querySelectorAll( 'tr[data-copy="1"]' ), function ( row ) {
            return {
                slug:        row.getAttribute( 'data-copy-slug' ) || '',
                parent_slug: row.getAttribute( 'data-copy-parent' ) || ''
            };
        } ).filter( function ( c ) {
            return '' !== c.slug && '' !== c.parent_slug;
        } );
    }

    // 子選單／父選單預設頁列的「複製到主選單」按鈕：已複製＝停用並顯示「已複製」。
    function syncCopyButtons() {
        var tbody = document.getElementById( 'ys-ec-permission-rows' );
        if ( ! tbody ) {
            return;
        }
        var existing = {};
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr[data-copy="1"], tr[data-promoted="1"]' ), function ( r ) {
            var k = r.getAttribute( 'data-copy-key' );
            if ( k ) { existing[ k ] = true; }
        } );
        Array.prototype.forEach.call( tbody.querySelectorAll( '.ys-ec-copy-btn' ), function ( btn ) {
            var row = btn.closest( 'tr' );
            if ( ! row ) { return; }
            var copied = !! existing[ row.getAttribute( 'data-copy-key' ) || '' ];
            btn.disabled = copied;
            btn.classList.toggle( 'is-copied', copied );
            var label = btn.querySelector( '.ys-ec-row-btn__label' );
            if ( label ) { label.textContent = copied ? '已複製' : '複製到主選單'; }
            var icon = btn.querySelector( '.dashicons' );
            if ( icon ) { icon.className = 'dashicons ' + ( copied ? 'dashicons-yes' : 'dashicons-admin-page' ); }
            btn.title = copied
                ? '已經複製到主選單；要刪除請按該複製列的「刪除複製」'
                : '複製到主選單：主選單另外多一個連到同一頁的項目，原位置保留';
        } );
    }

    function flashRow( row ) {
        if ( ! row || ! row.classList ) {
            return;
        }
        row.classList.remove( 'ys-ec-flash' );
        void row.offsetWidth;
        row.classList.add( 'ys-ec-flash' );
        setTimeout( function () { row.classList.remove( 'ys-ec-flash' ); }, 1600 );
    }

    // ─────────────────────────────────────────────
    // v1.3.0：複製項的圖示（主選單分頁）。常用 dashicons 一鍵選，也可以直接貼 dashicons-… 或圖片網址。
    // ─────────────────────────────────────────────
    var ICONS = [
        'admin-home', 'admin-site', 'dashboard', 'admin-post', 'admin-page', 'admin-media', 'admin-comments',
        'admin-appearance', 'admin-plugins', 'admin-users', 'admin-tools', 'admin-settings', 'admin-generic',
        'admin-links', 'admin-customizer', 'edit', 'edit-page', 'text-page', 'media-document', 'format-image',
        'images-alt2', 'format-gallery', 'video-alt3', 'playlist-audio', 'category', 'tag', 'list-view',
        'clipboard', 'calendar-alt', 'clock', 'location', 'flag', 'star-filled', 'heart', 'email-alt', 'phone',
        'groups', 'businessman', 'id', 'cart', 'products', 'store', 'money-alt', 'chart-bar', 'chart-line',
        'chart-pie', 'analytics', 'search', 'filter', 'shield', 'lock', 'visibility', 'bell', 'megaphone',
        'lightbulb', 'book', 'portfolio', 'screenoptions', 'layout', 'grid-view', 'database', 'cloud',
        'download', 'upload', 'share', 'art', 'translation', 'feedback', 'forms', 'awards', 'info', 'sos'
    ];
    var iconPanel = null;
    var iconPanelField = null;

    function setupIconPickers( table ) {
        Array.prototype.forEach.call( table.querySelectorAll( '.ys-ec-icon-field' ), function ( field ) {
            renderIconPreview( field );
        } );
        table.addEventListener( 'input', function ( e ) {
            if ( e.target && e.target.classList && e.target.classList.contains( 'ys-ec-icon-input' ) ) {
                renderIconPreview( e.target.closest( '.ys-ec-icon-field' ) );
            }
        } );
        table.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest && e.target.closest( '.ys-ec-icon-btn' );
            if ( ! btn ) {
                return;
            }
            var field = btn.closest( '.ys-ec-icon-field' );
            if ( iconPanel && ! iconPanel.hidden && iconPanelField === field ) {
                closeIconPanel();
            } else {
                openIconPanel( field, btn );
            }
        } );
        document.addEventListener( 'click', function ( e ) {
            if ( ! iconPanel || iconPanel.hidden ) {
                return;
            }
            if ( iconPanel.contains( e.target ) || ( e.target.closest && e.target.closest( '.ys-ec-icon-btn' ) ) ) {
                return;
            }
            closeIconPanel();
        } );
        document.addEventListener( 'keydown', function ( e ) {
            if ( 'Escape' === e.key && iconPanel && ! iconPanel.hidden ) {
                var btn = iconPanelField && iconPanelField.querySelector( '.ys-ec-icon-btn' );
                closeIconPanel();
                if ( btn ) {
                    btn.focus();
                }
            }
        } );
    }

    function iconValueFor( field ) {
        var input = field.querySelector( '.ys-ec-icon-input' );
        var row = field.closest( 'tr' );
        var value = input ? input.value.replace( /^\s+|\s+$/g, '' ) : '';
        return value || ( row && row.getAttribute( 'data-default-icon' ) ) || 'dashicons-admin-generic';
    }

    function renderIconPreview( field ) {
        if ( ! field ) {
            return;
        }
        var preview = field.querySelector( '.ys-ec-icon-preview' );
        if ( ! preview ) {
            return;
        }
        var value = iconValueFor( field );
        preview.style.backgroundImage = '';
        if ( /^dashicons-[a-z0-9-]+$/.test( value ) ) {
            preview.className = 'ys-ec-icon-preview dashicons ' + value;
        } else if ( /^(https?:\/\/|data:image\/svg\+xml;base64,)/i.test( value ) ) {
            preview.className = 'ys-ec-icon-preview is-image';
            preview.style.backgroundImage = 'url("' + value.replace( /["\\\s]/g, '' ) + '")';
        } else {
            preview.className = 'ys-ec-icon-preview is-none';
        }
    }

    function ensureIconPanel() {
        if ( iconPanel ) {
            return iconPanel;
        }
        iconPanel = document.createElement( 'div' );
        iconPanel.className = 'ys-ec-icon-picker';
        iconPanel.setAttribute( 'role', 'dialog' );
        iconPanel.setAttribute( 'aria-label', '選擇圖示' );
        iconPanel.hidden = true;
        var html = '<div class="ys-ec-icon-picker__grid">';
        ICONS.forEach( function ( name ) {
            html += '<button type="button" class="ys-ec-icon-picker__item" data-icon="dashicons-' + name + '" title="dashicons-' + name + '">'
                + '<span class="dashicons dashicons-' + name + '" aria-hidden="true"></span>'
                + '<span class="screen-reader-text">dashicons-' + name + '</span></button>';
        } );
        html += '</div><div class="ys-ec-icon-picker__foot">'
            + '<button type="button" class="ys-ec-icon-picker__reset">沿用原父選單圖示</button>'
            + '<span>也可以在欄位直接貼 dashicons-… 或圖片網址</span></div>';
        iconPanel.innerHTML = html;
        iconPanel.addEventListener( 'click', function ( e ) {
            var item = e.target.closest && e.target.closest( '.ys-ec-icon-picker__item, .ys-ec-icon-picker__reset' );
            if ( ! item || ! iconPanelField ) {
                return;
            }
            var input = iconPanelField.querySelector( '.ys-ec-icon-input' );
            if ( input ) {
                input.value = item.classList.contains( 'ys-ec-icon-picker__reset' ) ? '' : ( item.getAttribute( 'data-icon' ) || '' );
                input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            }
            renderIconPreview( iconPanelField );
            var btn = iconPanelField.querySelector( '.ys-ec-icon-btn' );
            closeIconPanel();
            if ( btn ) {
                btn.focus();
            }
        } );
        document.body.appendChild( iconPanel );
        return iconPanel;
    }

    function openIconPanel( field, btn ) {
        var panel = ensureIconPanel();
        if ( iconPanelField && iconPanelField !== field ) {
            var prev = iconPanelField.querySelector( '.ys-ec-icon-btn' );
            if ( prev ) {
                prev.setAttribute( 'aria-expanded', 'false' );
            }
        }
        iconPanelField = field;
        var current = iconValueFor( field );
        Array.prototype.forEach.call( panel.querySelectorAll( '.ys-ec-icon-picker__item' ), function ( item ) {
            item.classList.toggle( 'is-selected', item.getAttribute( 'data-icon' ) === current );
        } );
        panel.hidden = false;
        var rect = btn.getBoundingClientRect();
        var width = panel.offsetWidth || 320;
        var left = Math.max( 8, Math.min( rect.left + window.pageXOffset, window.pageXOffset + document.documentElement.clientWidth - width - 8 ) );
        panel.style.top = ( rect.bottom + window.pageYOffset + 6 ) + 'px';
        panel.style.left = left + 'px';
        btn.setAttribute( 'aria-expanded', 'true' );
        var selected = panel.querySelector( '.ys-ec-icon-picker__item.is-selected' ) || panel.querySelector( '.ys-ec-icon-picker__item' );
        if ( selected ) {
            selected.focus();
        }
    }

    function closeIconPanel() {
        if ( ! iconPanel ) {
            return;
        }
        iconPanel.hidden = true;
        if ( iconPanelField ) {
            var btn = iconPanelField.querySelector( '.ys-ec-icon-btn' );
            if ( btn ) {
                btn.setAttribute( 'aria-expanded', 'false' );
            }
        }
        iconPanelField = null;
    }

    // 重新指派某清單內各列 order = (i+1)*10（跳過 hint / 非資料列）。
    // 重新指派各列 order = (i+1)*10（拖曳後；跳過複製／提升列，它們的位置用 after 記錄）。
    function reassignOrder( listEl ) {
        var i = 0;
        Array.prototype.forEach.call( listEl.querySelectorAll( 'tr' ), function ( row ) {
            if ( row.getAttribute( 'data-copy' ) === '1' || row.getAttribute( 'data-promoted' ) === '1' ) {
                return;
            }
            var inp = row.querySelector( '.ys-ec-order-input' );
            if ( inp ) {
                i += 1;
                inp.value = i * 10;
            }
        } );
    }

    // Separator row HTML — 客戶端動態新增用（7 欄；separator 主 cell colspan = 4）。
    // v1.7.0：subhead＝子選單標題（全部選單分頁「新增子選單標題」）。
    function renderSeparatorRow( title, order, tab, subhead ) {
        var colspan = 4;
        return '<tr data-separator="1" class="ys-ec-separator-row ysca-separator-row' + ( subhead ? ' ys-ec-subhead-row" data-subhead="1"' : '"' ) + '>'
            + '<td class="ys-ec-drag-handle" title="拖拉以排序">&#x205E;&#x205E;</td>'
            + '<td><input type="number" class="ys-ec-order-input" value="' + escapeAttr( String( order ) )
                + '" min="0" max="9999"></td>'
            + '<td colspan="' + colspan + '" class="ysca-separator-cell">'
                + '<strong class="ysca-separator-title">&mdash; '
                + '<input type="text" class="ys-ec-separator-title ysca-separator-input" value="' + escapeAttr( title )
                    + '" placeholder="標題文字">'
                + ' &mdash;</strong>'
                + ( subhead
                    ? ' <em class="ysca-separator-note ysca-text-muted">(子選單標題：拖到某個選單的子選單之間；後面沒有子選單時不顯示)</em>'
                    : ' <em class="ysca-separator-note ysca-text-muted">(分隔列)</em>' )
            + '</td>'
            + '<td class="ys-ec-row-actions"><button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-delete-row" title="從清單移除">×</button></td>'
            + '</tr>';
    }

    // ═════════════════════════════════════════════
    // CONTROLLER B：user_overrides tab（v2.39.0 BATCH Q1）
    // ═════════════════════════════════════════════
    function bootUserOverrides( list ) {
        var saveBtn       = document.getElementById( 'ys-ec-save-permissions' );
        var saveStatus    = document.getElementById( 'ys-ec-save-status' );
        var searchInput   = document.getElementById( 'ys-ec-user-search' );
        var resultsBox    = document.getElementById( 'ys-ec-user-search-results' );
        var emptyHint     = document.getElementById( 'ys-ec-empty-overrides' );
        var template      = document.getElementById( 'ys-ec-user-override-card-template' );

        if ( ! template || ! searchInput || ! resultsBox ) {
            return;
        }

        // ─── User search autocomplete (debounced) ───
        var searchTimer = null;
        searchInput.addEventListener( 'input', function () {
            var q = searchInput.value.replace( /^\s+|\s+$/g, '' );
            if ( searchTimer ) {
                clearTimeout( searchTimer );
            }
            if ( q.length < 2 ) {
                hideResults();
                return;
            }
            searchTimer = setTimeout( function () {
                fetchUsers( q ).then( renderResults ).catch( function () {
                    renderResults( [] );
                } );
            }, 300 );
        } );

        // 點擊外部時收起下拉
        document.addEventListener( 'click', function ( e ) {
            if ( ! resultsBox.contains( e.target ) && e.target !== searchInput ) {
                hideResults();
            }
        } );

        function fetchUsers( q ) {
            var url = ( cfg.usersUrl || '/wp-json/wp/v2/users' )
                + ( ( cfg.usersUrl || '' ).indexOf( '?' ) >= 0 ? '&' : '?' )
                + 'search=' + encodeURIComponent( q )
                + '&context=edit&per_page=10';
            return fetch( url, {
                method:      'GET',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': cfg.nonce,
                    'Accept':     'application/json'
                }
            } ).then( function ( r ) {
                if ( ! r.ok ) {
                    return [];
                }
                return r.json();
            } );
        }

        function renderResults( users ) {
            resultsBox.innerHTML = '';
            if ( ! users || ! users.length ) {
                var li = document.createElement( 'li' );
                li.className = 'no-results';
                li.textContent = '找不到符合的使用者';
                resultsBox.appendChild( li );
                resultsBox.hidden = false;
                return;
            }
            users.forEach( function ( u ) {
                var li = document.createElement( 'li' );
                var login = u.slug || u.username || ( 'user-' + u.id );
                var email = u.email || u.user_email || '';
                li.innerHTML = '<strong>' + escapeHtml( login ) + '</strong> '
                    + '<code style="font-size:10px;color:#9ca3af;">' + escapeHtml( email ) + '</code> '
                    + '<span style="color:#9ca3af;font-size:10px;">#' + ( u.id | 0 ) + '</span>';
                li.dataset.userId    = String( u.id | 0 );
                li.dataset.userLogin = login;
                li.dataset.userEmail = email;
                li.addEventListener( 'click', function () {
                    addUserCard( {
                        user_id:    u.id | 0,
                        user_login: login,
                        user_email: email
                    } );
                    searchInput.value = '';
                    hideResults();
                } );
                resultsBox.appendChild( li );
            } );
            resultsBox.hidden = false;
        }

        function hideResults() {
            resultsBox.hidden = true;
            resultsBox.innerHTML = '';
        }

        // ─── 新增 user override card ───
        function addUserCard( user ) {
            // 已存在就不重複加
            var existing = list.querySelector( '.ys-ec-user-override-card[data-user-id="' + ( user.user_id | 0 ) + '"]' );
            if ( existing ) {
                existing.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                existing.classList.add( 'highlight' );
                setTimeout( function () { existing.classList.remove( 'highlight' ); }, 1500 );
                return;
            }

            // 移除空狀態
            if ( emptyHint && emptyHint.parentNode ) {
                emptyHint.parentNode.removeChild( emptyHint );
                emptyHint = null;
            }

            // Clone template + replace placeholders
            var html = template.innerHTML
                .replace( /\{\{user_id\}\}/g, String( user.user_id | 0 ) )
                .replace( /\{\{user_login\}\}/g, escapeHtml( user.user_login || '' ) )
                .replace( /\{\{user_email\}\}/g, escapeHtml( user.user_email || '' ) );

            var temp = document.createElement( 'div' );
            temp.innerHTML = html.replace( /^\s+|\s+$/g, '' );
            var card = temp.firstElementChild;
            if ( card ) {
                list.appendChild( card );
            }
        }

        // ─── 移除 card + slug count update（event delegation） ───
        list.addEventListener( 'click', function ( e ) {
            var rm = e.target.closest && e.target.closest( '.ys-ec-remove-override' );
            if ( rm ) {
                var card = rm.closest( '.ys-ec-user-override-card' );
                if ( card && window.confirm( '確定移除此使用者覆寫？\n（儲存後該使用者將回到預設權限）' ) ) {
                    card.parentNode.removeChild( card );
                }
                return;
            }
        } );

        list.addEventListener( 'change', function ( e ) {
            // 即時更新 picked-count
            var cb = e.target;
            if ( cb && cb.classList && cb.classList.contains( 'visible-slug' ) ) {
                var card = cb.closest( '.ys-ec-user-override-card' );
                if ( card ) {
                    var checked = card.querySelectorAll( '.visible-slug:checked' ).length;
                    var counter = card.querySelector( '.picked-count' );
                    if ( counter ) {
                        counter.textContent = String( checked );
                    }
                }
            }
        } );

        // ─── Save ───
        if ( saveBtn ) {
            saveBtn.addEventListener( 'click', function () {
                saveBtn.disabled = true;
                setStatus( saveStatus, '儲存中…', '#6b7280' );

                var cards = list.querySelectorAll( '.ys-ec-user-override-card' );
                var items = Array.prototype.slice.call( cards ).map( function ( card ) {
                    var userId = parseInt( card.getAttribute( 'data-user-id' ) || '0', 10 ) || 0;
                    var ysCartOnlyCb = card.querySelector( '.ys-cart-only' );
                    var slugCbs = card.querySelectorAll( '.visible-slug:checked' );
                    var slugs = Array.prototype.slice.call( slugCbs ).map( function ( cb ) {
                        return cb.value;
                    } );
                    var login = ( card.querySelector( '.card-header strong' ) || {} ).textContent || '';
                    var email = ( card.querySelector( '.card-header code' ) || {} ).textContent || '';
                    return {
                        user_id:       userId,
                        user_login:    login,
                        user_email:    email,
                        visible_slugs: slugs,
                        ys_cart_only:  ysCartOnlyCb ? !!ysCartOnlyCb.checked : false
                    };
                } );

                var payload = { user_overrides: { items: items } };

                postMenuConfig( payload )
                    .then( function ( ok ) {
                        if ( ok ) {
                            setStatus( saveStatus, '已儲存 ✓', '#16a34a', 3000 );
                        }
                    } )
                    .finally( function () {
                        saveBtn.disabled = false;
                    } );
            } );
        }
    }

    // ═════════════════════════════════════════════
    // 共用：POST /menu-config + 狀態提示
    // ═════════════════════════════════════════════
    function postMenuConfig( payload ) {
        // v1.5.0：帶回開頁當下的選單搬家標記（隱藏欄位）；遷移之前打開的頁面，伺服器會拒絕並請你重新整理。
        var movesToken = document.getElementById( 'ys-admin-menu-moves-token' );
        payload.moves_token = movesToken ? movesToken.value : '';
        return fetch( cfg.restUrl + '/menu-config', {
            method:      'POST',
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce':   cfg.nonce,
                'Content-Type': 'application/json',
                'Accept':       'application/json'
            },
            body: JSON.stringify( payload )
        } )
            .then( function ( r ) { return r.json().then( function ( data ) { return { ok: r.ok, data: data }; } ); } )
            .then( function ( res ) {
                if ( res.ok && res.data && res.data.success ) {
                    return true;
                }
                var msg = ( res.data && ( res.data.error || res.data.message ) ) || '未知錯誤';
                setStatus( document.getElementById( 'ys-ec-save-status' ), '錯誤：' + msg, '#dc2626' );
                return false;
            } )
            .catch( function ( err ) {
                setStatus( document.getElementById( 'ys-ec-save-status' ), '網路錯誤：' + err.message, '#dc2626' );
                return false;
            } );
    }

    function setStatus( el, text, color, autoClearMs ) {
        if ( ! el ) {
            return;
        }
        el.textContent = text;
        el.style.color = color;
        if ( autoClearMs && autoClearMs > 0 ) {
            setTimeout( function () {
                if ( el.textContent === text ) {
                    el.textContent = '';
                }
            }, autoClearMs );
        }
    }


    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────
    function escapeHtml( s ) {
        if ( s == null ) {
            return '';
        }
        return String( s ).replace( /[<>&"']/g, function ( c ) {
            return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[ c ];
        } );
    }

    function escapeAttr( s ) {
        return escapeHtml( s );
    }
}());
