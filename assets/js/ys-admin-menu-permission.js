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
        initSortable( tbody );

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

        // Delete row（event delegation）
        tbody.addEventListener( 'click', function ( e ) {
            var del = e.target.closest && e.target.closest( '.ys-ec-delete-row' );
            if ( ! del ) {
                return;
            }
            var row = del.closest( 'tr' );
            if ( ! row ) {
                return;
            }
            if ( window.confirm( '確定要從清單移除？\n（不會真的刪除選單，只是不在管理範圍內）' ) ) {
                row.parentNode.removeChild( row );
            }
        } );

        // Save via REST POST
        if ( saveBtn ) {
            saveBtn.addEventListener( 'click', function () {
                saveBtn.disabled = true;
                setStatus( saveStatus, '儲存中…', '#6b7280' );

                var rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) );
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

                    return {
                        slug:           row.getAttribute( 'data-slug' ),
                        order:          parseInt( ( row.querySelector( '.ys-ec-order-input' ) || {} ).value || '0', 10 ) || 0,
                        roles:          roles,
                        color:          colorEl ? colorEl.value : null,
                        title_override: titleEl ? titleEl.value : '',
                        level:          row.getAttribute( 'data-level' ) || 'top',
                        parent_slug:    row.getAttribute( 'data-parent' ) || null,
                        hide:           hideCb ? !!hideCb.checked : false,
                        self_only_uid:  selfOnlyUid,
                        promote_to_top: promoteCb ? !!promoteCb.checked : false
                    };
                } );

                var payload = {};
                if ( 'wp_native' === tab ) {
                    payload.wp_native = { items: items };
                } else if ( 'ys_cart' === tab ) {
                    payload.ys_cart = { items: items };
                }

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
    // SortableJS init（共用：主表 + user override 卡片排序）
    // ─────────────────────────────────────────────
    function initSortable( tbody ) {
        if ( typeof window.Sortable === 'undefined' ) {
            console.warn( '[ys-ec-permission] SortableJS not loaded' );
            return;
        }
        window.Sortable.create( tbody, {
            handle: '.ys-ec-drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function () {
                // 重排後重新指派 order = (i+1)*10
                var rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) );
                rows.forEach( function ( row, i ) {
                    var inp = row.querySelector( '.ys-ec-order-input' );
                    if ( inp ) {
                        inp.value = ( i + 1 ) * 10;
                    }
                } );
            }
        } );
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
