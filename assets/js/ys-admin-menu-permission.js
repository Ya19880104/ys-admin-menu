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
        syncCopyButtons();
        markClean();

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
                if ( window.confirm( '確定要從清單移除？\n（不會真的刪除選單，只是不在管理範圍內）' ) ) {
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
                saveBtn.disabled = true;
                setStatus( saveStatus, '儲存中…', '#6b7280' );

                var payload = ( 'wp_native' === tab )
                    ? buildWpNativePayload( tbody, renderedSlugs )
                    : buildYsCartPayload( tbody, renderedSlugs, renderedTopKeys, renderedCopies );

                postMenuConfig( payload )
                    .then( function ( ok ) {
                        if ( ok ) {
                            renderedSlugs   = rowSlugs( tbody );
                            renderedTopKeys = topRowKeys( tbody );
                            renderedCopies  = collectCopies( tab );
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
        var roleCbs = row.querySelectorAll( '.ys-ec-role-cb:checked' );
        var roles = Array.prototype.slice.call( roleCbs ).map( function ( cb ) { return cb.dataset.role; } );
        var colorEl = row.querySelector( '.ys-ec-color-picker' );
        var titleEl = row.querySelector( '.ys-ec-title-override' );
        var hideCb  = row.querySelector( '.ys-ec-hide-checkbox' );
        var selfCb  = row.querySelector( '.ys-ec-self-only' );
        var existingSelfUid = parseInt( row.getAttribute( 'data-self-uid' ) || '0', 10 ) || 0;
        var selfOnlyUid = ( selfCb && selfCb.checked ) ? ( existingSelfUid || ( cfg.currentUserId | 0 ) ) : 0;
        var iconEl = row.querySelector( '.ys-ec-icon-input' );
        var item = {
            slug:           row.getAttribute( 'data-slug' ),
            order:          parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0,
            roles:          roles,
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
        return item;
    }

    function buildWpNativePayload( tbody, renderedSlugs ) {
        var items = [];
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
            if ( ! row.getAttribute( 'data-slug' ) ) {
                return;
            }
            items.push( readRowItem( row ) );
        } );
        var copies = collectCopies( 'wp_native' );
        return {
            wp_native:  { items: items, rendered_slugs: renderedSlugs },
            top_copies: { items: copies, rendered: copies }
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
                ysItems.push( {
                    separator: true,
                    title:     sepTitleInput ? sepTitleInput.value : '',
                    order:     parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0
                } );
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
        return {
            slug:           row.getAttribute( 'data-copy-key' ) || '',
            after:          computeAfter( row ),
            title_override: item.title_override,
            icon:           item.icon || '',
            roles:          item.roles,
            hide:           item.hide,
            self_only_uid:  item.self_only_uid
        };
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
            selfUid:      0
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

    function roleCheckboxesHtml( roles ) {
        var opts = [ [ 'administrator', 'Admin' ], [ 'shop_manager', 'SM' ], [ 'editor', 'Editor' ] ];
        return opts.map( function ( o ) {
            var checked = roles.indexOf( o[0] ) >= 0 ? ' checked' : '';
            return '<label><input type="checkbox" class="ys-ec-role-cb" data-role="' + o[0] + '"' + checked + '> ' + o[1] + '</label>';
        } ).join( '' );
    }

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
                + '<span class="ys-ec-copy-source">原位置：' + escapeHtml( d.parentTitle || '' ) + ' › ' + escapeHtml( d.title ) + '</span></div>'
                + '<input type="text" class="title-override-inline ys-ec-title-override" value="' + escapeAttr( d.titleOverride || '' ) + '" placeholder="自訂顯示名稱（留空保留原名）">'
                + '<div class="ys-ec-icon-field"><button type="button" class="ys-ec-icon-btn" aria-expanded="false" title="選擇圖示"><span class="ys-ec-icon-preview" aria-hidden="true"></span><span class="screen-reader-text">選擇圖示</span></button>'
                + '<input type="text" class="ys-ec-icon-input" value="' + escapeAttr( d.icon || '' ) + '" placeholder="圖示：留空沿用原父選單（dashicons-… 或圖片網址）" spellcheck="false"></div></td>'
            + '<td><div class="role-cb-group">' + roleCheckboxesHtml( d.roles || [] ) + '</div>'
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
            + '<td><div class="menu-line"><span class="menu-indent">&#8627;</span> <strong>' + escapeHtml( d.title ) + '</strong> <code>' + escapeHtml( d.slug ) + '</code> <span class="level-badge level-sub">子頁</span></div>'
                + '<input type="text" class="title-override-inline ys-ec-title-override" value="' + escapeAttr( d.titleOverride || '' ) + '" placeholder="自訂顯示名稱（留空保留原名）"></td>'
            + '<td><div class="role-cb-group">' + roleCheckboxesHtml( d.roles || [] ) + '</div>'
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
    function renderSeparatorRow( title, order, tab ) {
        var colspan = 4;
        return '<tr data-separator="1" class="ys-ec-separator-row ysca-separator-row">'
            + '<td class="ys-ec-drag-handle" title="拖拉以排序">&#x205E;&#x205E;</td>'
            + '<td><input type="number" class="ys-ec-order-input" value="' + escapeAttr( String( order ) )
                + '" min="0" max="9999"></td>'
            + '<td colspan="' + colspan + '" class="ysca-separator-cell">'
                + '<strong class="ysca-separator-title">&mdash; '
                + '<input type="text" class="ys-ec-separator-title ysca-separator-input" value="' + escapeAttr( title )
                    + '" placeholder="標題文字">'
                + ' &mdash;</strong>'
                + ' <em class="ysca-separator-note ysca-text-muted">(分隔列)</em>'
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
