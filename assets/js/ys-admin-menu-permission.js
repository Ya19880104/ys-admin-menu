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
    function bootMainTable( tbody ) {
        var table = tbody.closest( 'table' );
        if ( ! table ) {
            return;
        }
        var tab = table.getAttribute( 'data-tab' ) || 'wp_native';
        var saveBtn = document.getElementById( 'ys-ec-save-permissions' );
        var saveStatus = document.getElementById( 'ys-ec-save-status' );
        var addSepBtn = document.getElementById( 'ys-ec-add-separator' );

        // 套用 wpColorPicker（wp_native tab 才有色彩欄）
        if ( 'wp_native' === tab && typeof window.jQuery !== 'undefined' && window.jQuery.fn.wpColorPicker ) {
            try {
                window.jQuery( tbody ).find( '.ys-ec-color-picker' ).wpColorPicker();
            } catch ( e ) { /* swallow */ }
        }
        setupSortable( tbody, tab );

        // 頁面載入時列出的全部選單。儲存時一併送出，伺服器據此分辨「使用者移除的列」與
        // 「這個帳號本來就看不到、所以沒列出來的列」，後者的設定要原樣保留。
        var renderedSlugs = Array.prototype.map.call(
            document.querySelectorAll( '#ys-ec-permission-rows tr[data-slug], #ys-ec-promote-zone tr[data-slug]' ),
            function ( row ) { return row.getAttribute( 'data-slug' ); }
        );

        // v1.3.0：「複製到主選單」。全部選單分頁＝複製區的列；主選單分頁＝標示「複製」的頂層列。
        // 同 renderedSlugs：只動頁面上列出的複製項，伺服器保留沒列出的。
        var copyZone = document.getElementById( 'ys-ec-copy-zone' );
        var renderedCopies = collectCopies( tab );
        if ( 'wp_native' === tab ) {
            setupIconPickers( table );
        }
        syncCopyButtons();

        // 「+ 新增空白標題」 separator — 直接插入一列可即時編輯的分隔列（不用 prompt）
        if ( addSepBtn ) {
            addSepBtn.addEventListener( 'click', function () {
                // 新分隔列 order 取目前最大值 + 10（排在最後，使用者可再拖拉到想要的位置）
                var maxOrder = 0;
                Array.prototype.forEach.call( tbody.querySelectorAll( '.ys-ec-order-input' ), function ( inp ) {
                    var v = parseInt( inp.value || '0', 10 ) || 0;
                    if ( v > maxOrder ) {
                        maxOrder = v;
                    }
                } );
                var temp = document.createElement( 'tbody' );
                temp.innerHTML = renderSeparatorRow( '', maxOrder + 10, tab );
                var newRow = temp.firstElementChild;
                if ( newRow ) {
                    tbody.appendChild( newRow );
                    var titleInput = newRow.querySelector( '.ys-ec-separator-title' );
                    if ( titleInput ) {
                        titleInput.focus();
                    }
                    if ( newRow.scrollIntoView ) {
                        newRow.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                    }
                }
            } );
        }

        // 列上的按鈕（event delegation）。綁在整張表上：提升區、複製區的列也要能按。
        table.addEventListener( 'click', function ( e ) {
            var btn = e.target.closest && e.target.closest( 'button' );
            if ( ! btn || ! table.contains( btn ) ) {
                return;
            }
            var row = btn.closest( 'tr' );
            if ( ! row ) {
                return;
            }

            // 從清單移除（不會真的刪除選單）
            if ( btn.classList.contains( 'ys-ec-delete-row' ) ) {
                if ( window.confirm( '確定要從清單移除？\n（不會真的刪除選單，只是不在管理範圍內）' ) ) {
                    row.parentNode.removeChild( row );
                }
                return;
            }

            // v1.3.0：移到主選單／移回原位＝切換「升頂層」勾選（提升區與勾選框由 change 事件同步）。
            if ( btn.classList.contains( 'ys-ec-move-btn' ) ) {
                var cb = row.querySelector( '.ys-ec-promote-cb' );
                if ( cb ) {
                    cb.checked = ! cb.checked;
                    cb.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                    flashRow( row );
                }
                return;
            }

            // v1.3.0：複製到主選單（原位置保留）。
            if ( btn.classList.contains( 'ys-ec-copy-btn' ) ) {
                if ( addCopyFromRow( row ) ) {
                    setStatus( saveStatus, '已加入複製區，按「儲存設定」後出現在主選單', '#4338ca', 4000 );
                }
                return;
            }

            // v1.3.0：刪除複製項（全部選單分頁的複製區、主選單分頁的複製列）。原子選單不受影響。
            if ( btn.classList.contains( 'ys-ec-copy-remove' ) || btn.classList.contains( 'ys-ec-delete-copy' ) ) {
                if ( window.confirm( '確定刪除這個複製項？\n（只刪除主選單上的複製項，原子選單不受影響；按「儲存設定」後生效）' ) ) {
                    row.parentNode.removeChild( row );
                    syncCopyButtons();
                }
            }
        } );

        // Save via REST POST
        if ( saveBtn ) {
            saveBtn.addEventListener( 'click', function () {
                saveBtn.disabled = true;
                setStatus( saveStatus, '儲存中…', '#6b7280' );

                // 「父選單預設頁」列只提供複製，不是可管理的選單列，不送。
                var rows = collectDataRows( tbody ).filter( function ( row ) {
                    return row.getAttribute( 'data-self-row' ) !== '1';
                } );
                var items = rows.map( function ( row ) {
                    if ( row.getAttribute( 'data-separator' ) === '1' ) {
                        var sepTitleInput = row.querySelector( '.ys-ec-separator-title' );
                        return {
                            separator: true,
                            title:     sepTitleInput ? sepTitleInput.value : '',
                            order:     parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0
                        };
                    }
                    var roleCbs = row.querySelectorAll( '.ys-ec-role-cb:checked' );
                    var roles = Array.prototype.slice.call( roleCbs ).map( function ( cb ) {
                        return cb.dataset.role;
                    } );
                    var colorEl = row.querySelector( '.ys-ec-color-picker' );
                    var titleEl = row.querySelector( '.ys-ec-title-override' );
                    var hideCb  = row.querySelector( '.ys-ec-hide-checkbox' );
                    var selfCb  = row.querySelector( '.ys-ec-self-only' );
                    var promoteCb = row.querySelector( '.ys-ec-promote-cb' );
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
                        hide:           hideCb ? !!hideCb.checked : false,
                        self_only_uid:  selfOnlyUid,
                        promote_to_top: isRowPromoted( row )
                    };
                    if ( iconEl ) {
                        item.icon = iconEl.value.replace( /^\s+|\s+$/g, '' ); // v1.3.0：複製項的圖示
                    }
                    return item;
                } );

                var payload = {};
                if ( 'wp_native' === tab ) {
                    payload.wp_native = { items: items, rendered_slugs: renderedSlugs };
                } else if ( 'ys_cart' === tab ) {
                    payload.ys_cart = { items: items, rendered_slugs: renderedSlugs };
                }
                // v1.3.0：複製到主選單（兩個分頁都送；只動頁面上列出的複製項）。
                var copiesNow = collectCopies( tab );
                var copiesAdded = copiesNow.some( function ( c ) {
                    return ! renderedCopies.some( function ( r ) { return r.slug === c.slug && r.parent_slug === c.parent_slug; } );
                } );
                payload.top_copies = { items: copiesNow, rendered: renderedCopies };

                postMenuConfig( payload )
                    .then( function ( ok ) {
                        if ( ok ) {
                            renderedCopies = copiesNow;
                            if ( copyZone ) {
                                Array.prototype.forEach.call( copyZone.querySelectorAll( '.ys-ec-copy-note--pending' ), function ( note ) {
                                    note.parentNode.removeChild( note );
                                } );
                            }
                            if ( copiesAdded && cfg.nativeTabUrl ) {
                                setStatusWithLink( saveStatus, '已儲存 ✓ 重新整理後複製項會出現在主選單；', '到「主選單（頂層）」排序、改名、換圖示', cfg.nativeTabUrl );
                            } else {
                                setStatus( saveStatus, '已儲存 ✓', '#16a34a', 3000 );
                            }
                        }
                    } )
                    .finally( function () {
                        saveBtn.disabled = false;
                    } );
            } );
        }

        // 匯出 / 匯入設定（全站設定備份還原，不限當前 tab）
        var exportBtn = document.getElementById( 'ys-ec-export-config' );
        var importBtn = document.getElementById( 'ys-ec-import-config' );
        var importFile = document.getElementById( 'ys-ec-import-file' );

        if ( exportBtn ) {
            exportBtn.addEventListener( 'click', function () {
                exportConfig( saveStatus );
            } );
        }
        if ( importBtn && importFile ) {
            importBtn.addEventListener( 'click', function () {
                importFile.value = '';
                importFile.click();
            } );
            importFile.addEventListener( 'change', function () {
                var file = importFile.files && importFile.files[ 0 ];
                if ( file ) {
                    importConfig( file, saveStatus );
                }
            } );
        }
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
    // SortableJS init
    //   - wp_native：單一清單拖拉排序。
    //   - ys_cart：主清單 + 頂部「提升區」兩個 tbody 共 group，可互拖；
    //     拖子選單進提升區＝升頂層、拖回＝取消（與「升頂層」勾選框雙向同步）。
    // ─────────────────────────────────────────────
    function setupSortable( tbody, tab ) {
        if ( typeof window.Sortable === 'undefined' ) {
            console.warn( '[ys-ec-permission] SortableJS not loaded' );
            return;
        }

        var zone = document.getElementById( 'ys-ec-promote-zone' );

        if ( ! zone ) {
            // wp_native：單一清單，維持原行為。
            window.Sortable.create( tbody, {
                handle: '.ys-ec-drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function () { reassignOrder( tbody ); }
            } );
            return;
        }

        // 載入時：把已勾「升頂層」的列移入提升區。
        Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) ).forEach( function ( row ) {
            var cb = row.querySelector( '.ys-ec-promote-cb' );
            if ( cb && cb.checked ) {
                zone.appendChild( row );
            }
        } );
        syncPromoteRows();

        // v1.3.0：複製區（拖進來＝複製到主選單，原列留在原處）。
        var copyZone = document.getElementById( 'ys-ec-copy-zone' );

        var makeOpts = function () {
            return {
                group: {
                    name: 'ys-am-menu',
                    // 拖到複製區時以「複本」方式拉出，原列留在原位（放下後再把原列放回、另建複製項）。
                    pull: function ( to ) {
                        return ( copyZone && to && to.el === copyZone ) ? 'clone' : true;
                    },
                    put: true
                },
                handle: '.ys-ec-drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                filter: '.ys-ec-promote-zone__hint, .ys-ec-copy-zone__hint',
                onMove: function ( evt ) {
                    var dragged = evt.dragged;
                    var related = evt.related;
                    var onHint  = !! ( related && related.classList
                        && ( related.classList.contains( 'ys-ec-promote-zone__hint' ) || related.classList.contains( 'ys-ec-copy-zone__hint' ) ) );
                    // 複製區：只收可以複製、還沒複製過的子選單列（放下後原列回到原位、另建複製項）。
                    if ( copyZone && evt.to === copyZone ) {
                        return canCopyRow( dragged ) ? ( onHint ? 1 : true ) : false;
                    }
                    // 「父選單預設頁」列固定在父選單第一個，只能拖到複製區。
                    if ( dragged.getAttribute( 'data-self-row' ) === '1' ) {
                        return false;
                    }
                    // 只有子選單可提升：非 sub 列不得拖進提升區。
                    if ( evt.to === zone && 'sub' !== dragged.getAttribute( 'data-level' ) ) {
                        return false;
                    }
                    // 說明列固定在各區最上方：拖到說明列上時一律接在它後面。
                    // （以前一律拒絕，提升區裡只有說明列時怎麼拖都放不進去。）
                    if ( onHint ) {
                        return 1;
                    }
                    return true;
                },
                onEnd: function () {
                    syncPromoteRows();
                    reassignOrder( zone );
                    reassignOrder( tbody );
                }
            };
        };
        window.Sortable.create( tbody, makeOpts() );
        window.Sortable.create( zone, makeOpts() );

        if ( copyZone ) {
            window.Sortable.create( copyZone, {
                group: { name: 'ys-am-menu', pull: false, put: true },
                sort: false,
                handle: '.ys-ec-drag-handle', // 複製區的列沒有拖拉把手：只收不出。
                filter: '.ys-ec-copy-zone__hint, .ys-ec-copy-row',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onAdd: function ( evt ) {
                    var row = evt.item;
                    // 原列放回原處：pull 為 clone 時，SortableJS 在原位置留了一個複本（evt.clone）。
                    if ( evt.clone && evt.clone !== row && evt.clone.parentNode ) {
                        evt.clone.parentNode.replaceChild( row, evt.clone );
                    }
                    if ( row.parentNode === copyZone && evt.from ) {
                        evt.from.insertBefore( row, evt.from.children[ evt.oldIndex ] || null );
                    }
                    addCopyFromRow( row );
                    syncPromoteRows();
                }
            } );
        }

        // 「升頂層」勾選框 ↔ 提升區：雙向同步。
        document.addEventListener( 'change', function ( e ) {
            var cb = e.target;
            if ( ! cb || ! cb.classList || ! cb.classList.contains( 'ys-ec-promote-cb' ) ) {
                return;
            }
            var row = cb.closest( 'tr' );
            if ( ! row ) {
                return;
            }
            if ( cb.checked ) {
                zone.appendChild( row );
            } else {
                restoreRowPosition( tbody, row );
            }
            syncPromoteRows();
        } );
    }

    // v1.3.0：取消提升時把列放回原父選單那一組的最後面（以前是接到整張表最後）。
    function restoreRowPosition( tbody, row ) {
        var parent = row.getAttribute( 'data-parent' ) || '';
        var anchor = null;
        if ( '' !== parent ) {
            Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), function ( r ) {
                if ( r === row ) {
                    return;
                }
                var isParentTop = r.getAttribute( 'data-level' ) === 'top' && r.getAttribute( 'data-slug' ) === parent;
                if ( isParentTop || r.getAttribute( 'data-parent' ) === parent ) {
                    anchor = r;
                }
            } );
        }
        if ( anchor ) {
            tbody.insertBefore( row, anchor.nextSibling );
        } else {
            tbody.appendChild( row );
        }
    }

    // 依「是否在提升區」同步每列的 promote 勾選狀態與樣式（v1.3.0：連同「移到主選單」按鈕的文字）。
    function syncPromoteRows() {
        var zone = document.getElementById( 'ys-ec-promote-zone' );
        if ( ! zone ) {
            return;
        }
        var all = document.querySelectorAll( '#ys-ec-permission-rows tr, #ys-ec-promote-zone tr' );
        Array.prototype.forEach.call( all, function ( row ) {
            if ( row.getAttribute( 'data-nodrag' ) === '1' ) {
                return;
            }
            var inZone = !! ( row.closest && row.closest( '#ys-ec-promote-zone' ) );
            var cb = row.querySelector( '.ys-ec-promote-cb' );
            if ( cb ) {
                cb.checked = inZone;
            }
            row.classList.toggle( 'is-promoted', inZone );
            var moveBtn = row.querySelector( '.ys-ec-move-btn' );
            if ( moveBtn ) {
                moveBtn.setAttribute( 'aria-pressed', inZone ? 'true' : 'false' );
                moveBtn.classList.toggle( 'is-active', inZone );
                var label = moveBtn.querySelector( '.ys-ec-row-btn__label' );
                if ( label ) {
                    label.textContent = inZone ? '移回原位' : '移到主選單';
                }
                var icon = moveBtn.querySelector( '.dashicons' );
                if ( icon ) {
                    icon.className = 'dashicons ' + ( inZone ? 'dashicons-undo' : 'dashicons-arrow-up-alt' );
                }
            }
        } );
    }

    // ═════════════════════════════════════════════
    // v1.3.0：複製到主選單
    // ═════════════════════════════════════════════

    // 目前頁面上的複製項：全部選單分頁讀複製區，主選單分頁讀標示「複製」的頂層列。
    function collectCopies( tab ) {
        var selector = ( 'wp_native' === tab )
            ? '#ys-ec-permission-rows tr[data-copy="1"]'
            : '#ys-ec-copy-zone tr.ys-ec-copy-row';
        return Array.prototype.map.call( document.querySelectorAll( selector ), function ( row ) {
            return {
                slug:        row.getAttribute( 'data-copy-slug' ) || '',
                parent_slug: row.getAttribute( 'data-copy-parent' ) || ''
            };
        } ).filter( function ( c ) {
            return '' !== c.slug && '' !== c.parent_slug;
        } );
    }

    function findCopyRow( key ) {
        var zone = document.getElementById( 'ys-ec-copy-zone' );
        if ( ! zone || ! key ) {
            return null;
        }
        var rows = zone.querySelectorAll( 'tr.ys-ec-copy-row' );
        for ( var i = 0; i < rows.length; i++ ) {
            if ( rows[ i ].getAttribute( 'data-copy-key' ) === key ) {
                return rows[ i ];
            }
        }
        return null;
    }

    // 這一列能不能複製：要有選單代稱，而且還沒複製過（同一個子選單只能複製一次）。
    function canCopyRow( row ) {
        var key = row && row.getAttribute ? row.getAttribute( 'data-copy-key' ) : '';
        return !! key && ! findCopyRow( key );
    }

    // 在複製區新增一列（尚未儲存）。回傳是否真的新增了。
    function addCopyFromRow( row ) {
        var zone = document.getElementById( 'ys-ec-copy-zone' );
        if ( ! zone || ! row ) {
            return false;
        }
        var key = row.getAttribute( 'data-copy-key' ) || '';
        var existing = findCopyRow( key );
        if ( existing ) {
            flashRow( existing );
            syncCopyButtons();
            return false;
        }
        if ( ! canCopyRow( row ) ) {
            return false;
        }
        var slug        = row.getAttribute( 'data-copy-slug' ) || '';
        var parent      = row.getAttribute( 'data-copy-parent' ) || '';
        var title       = ( ( row.querySelector( '.menu-line strong' ) || {} ).textContent || slug ).replace( /^\s+|\s+$/g, '' );
        var parentTitle = row.getAttribute( 'data-parent-title' ) || parent;

        var tr = document.createElement( 'tr' );
        tr.className = 'ys-ec-copy-row';
        tr.setAttribute( 'data-nodrag', '1' );
        tr.setAttribute( 'data-copy-key', key );
        tr.setAttribute( 'data-copy-slug', slug );
        tr.setAttribute( 'data-copy-parent', parent );
        tr.innerHTML = '<td class="ys-ec-copy-row__icon"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></td>'
            + '<td colspan="5"><div class="menu-line">'
                + '<strong>' + escapeHtml( title ) + '</strong> '
                + '<code>' + escapeHtml( slug ) + '</code> '
                + '<span class="level-badge level-copy">複製</span> '
                + '<span class="ys-ec-copy-source">原位置：' + escapeHtml( parentTitle ) + ' › ' + escapeHtml( title ) + '</span>'
            + '</div>'
            + '<p class="ys-ec-copy-note ys-ec-copy-note--pending">尚未儲存：按「儲存設定」後才會出現在主選單。</p></td>'
            + '<td class="ys-ec-row-actions"><button type="button" class="ys-ec-row-btn ys-ec-row-btn--danger ys-ec-copy-remove" title="刪除這個複製項（原子選單不受影響）">'
                + '<span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="ys-ec-row-btn__label">刪除複製</span>'
            + '</button></td>';
        zone.appendChild( tr );
        flashRow( tr );
        syncCopyButtons();
        return true;
    }

    // 子選單列的「複製到主選單」按鈕：已複製＝停用並顯示「已複製」。
    function syncCopyButtons() {
        var rows = document.querySelectorAll( '#ys-ec-permission-rows tr[data-copy-key], #ys-ec-promote-zone tr[data-copy-key]' );
        Array.prototype.forEach.call( rows, function ( row ) {
            var btn = row.querySelector( '.ys-ec-copy-btn' );
            if ( ! btn ) {
                return;
            }
            var copied = !! findCopyRow( row.getAttribute( 'data-copy-key' ) );
            btn.disabled = copied;
            btn.classList.toggle( 'is-copied', copied );
            btn.title = copied
                ? '已經複製到主選單（同一個子選單只能複製一次）；要刪除請按上方複製區的「刪除複製」'
                : '複製到主選單：主選單另外多一個連到同一頁的項目，原位置保留';
            var label = btn.querySelector( '.ys-ec-row-btn__label' );
            if ( label ) {
                label.textContent = copied ? '已複製' : '複製到主選單';
            }
            var icon = btn.querySelector( '.dashicons' );
            if ( icon ) {
                icon.className = 'dashicons ' + ( copied ? 'dashicons-yes' : 'dashicons-admin-page' );
            }
        } );
    }

    function flashRow( row ) {
        if ( ! row || ! row.classList ) {
            return;
        }
        row.classList.remove( 'ys-ec-flash' );
        void row.offsetWidth; // 重新觸發動畫
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
    function reassignOrder( listEl ) {
        var i = 0;
        Array.prototype.forEach.call( listEl.querySelectorAll( 'tr' ), function ( row ) {
            if ( row.getAttribute( 'data-nodrag' ) === '1' ) {
                return;
            }
            var inp = row.querySelector( '.ys-ec-order-input' );
            if ( inp ) {
                i += 1;
                inp.value = i * 10;
            }
        } );
    }

    // 儲存時收集全部資料列：提升區（在前）+ 主清單，略過 hint 列。
    function collectDataRows( tbody ) {
        var out = [];
        var zone = document.getElementById( 'ys-ec-promote-zone' );
        if ( zone ) {
            Array.prototype.forEach.call( zone.querySelectorAll( 'tr' ), function ( r ) {
                if ( r.getAttribute( 'data-nodrag' ) !== '1' ) {
                    out.push( r );
                }
            } );
        }
        Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), function ( r ) {
            out.push( r );
        } );
        return out;
    }

    function isRowPromoted( row ) {
        return !! ( row.closest && row.closest( '#ys-ec-promote-zone' ) );
    }

    /**
     * Separator row HTML — 客戶端動態新增用
     * v1.1.0：兩 tab 皆 7 欄（wp_native: drag/order/menu/role/color/hide/del；
     * ys_cart: drag/order/menu/role/升頂層/hide/del）。separator 主 cell colspan = 4。
     */
    function renderSeparatorRow( title, order, tab ) {
        var colspan = 4;
        return '<tr data-separator="1" class="ys-ec-separator-row">'
            + '<td class="ys-ec-drag-handle" title="拖拉以排序">⋮⋮</td>'
            + '<td><input type="number" class="ys-ec-order-input" value="' + escapeAttr( String( order ) )
                + '" min="0" max="9999"></td>'
            + '<td colspan="' + colspan + '" style="background:#f3f4f6;">'
                + '<strong style="color:#1f2937;">— '
                + '<input type="text" class="ys-ec-separator-title" value="' + escapeAttr( title )
                    + '" placeholder="標題文字" style="border:0;background:transparent;font-weight:600;font-size:13px;width:60%;color:#1f2937;">'
                + ' —</strong>'
                + ' <em style="color:#6b7280;font-size:11px;">(分隔列)</em>'
            + '</td>'
            + '<td><button type="button" class="ysca-btn ysca-btn--ghost ysca-btn--sm ys-ec-delete-row" title="從清單移除">×</button></td>'
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

    // v1.3.0：狀態文字後面接一個連結（例如儲存複製項後指向「主選單（頂層）」分頁）。
    function setStatusWithLink( el, text, linkText, href ) {
        if ( ! el ) {
            return;
        }
        el.textContent = text;
        el.style.color = '#16a34a';
        var a = document.createElement( 'a' );
        a.href = href;
        a.textContent = linkText;
        el.appendChild( a );
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
