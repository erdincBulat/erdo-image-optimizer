jQuery(document).ready(function($) {
    var currentPage      = 1;
    var totalPages       = 1;
    var totalFound       = 0;
    var searchQuery      = '';
    var isLoading        = false;
    var autoLoading      = false;
    var gridRendered     = false;          // cache'den mi yoksa fresh mi?
    var libraryVersion   = 0;             // sunucudan gelen versiyon
    var counts           = { red: 0, orange: 0, green: 0, gray: 0 };

    var CACHE_KEY = 'erdo_grid_v3'; // v3: 0–100 puan rozeti + seçim kutusu

    /* -------------------------------------------------------
       sessionStorage CACHE YARDIMCILARI
    ------------------------------------------------------- */
    function getGridCache() {
        try {
            var raw = sessionStorage.getItem(CACHE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch(e) { return null; }
    }

    function saveGridCache(version) {
        if ( searchQuery ) return; // Arama sonuçlarını cache'leme
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify({
                version : version,
                html    : {
                    red    : $('#tab-red    .erdo-grid').html(),
                    orange : $('#tab-orange .erdo-grid').html(),
                    green  : $('#tab-green  .erdo-grid').html(),
                    gray   : $('#tab-gray   .erdo-grid').html(),
                },
                counts  : $.extend({}, counts),
            }));
        } catch(e) {} // Quota aşılırsa sessizce geç
    }

    function clearGridCache() {
        try { sessionStorage.removeItem(CACHE_KEY); } catch(e) {}
    }

    function renderFromCache(cache) {
        $('#tab-red    .erdo-grid').html(cache.html.red);
        $('#tab-orange .erdo-grid').html(cache.html.orange);
        $('#tab-green  .erdo-grid').html(cache.html.green);
        $('#tab-gray   .erdo-grid').html(cache.html.gray);
        $('.erdo-card.erdo-selected').removeClass('erdo-selected').find('.erdo-card-check').attr('aria-checked', 'false');
        counts = $.extend({}, cache.counts);
        updateCounts();
        $('#stat-unused-count').text(counts.gray);
        $('#erdo-loading-notice').hide();
        $('#erdo-load-more-wrap').addClass('hidden');
        gridRendered = true;
    }

    /* -------------------------------------------------------
       TAB SWITCHING
    ------------------------------------------------------- */
    $('.tab-btn').on('click', function() {
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.erdo-content').removeClass('active');
        var target = $(this).data('target');
        $('#' + target).addClass('active');

        if ( target === 'tab-gray' ) {
            $('#erdo-bulk-webp-btn').addClass('hidden');
            $('#erdo-bulk-delete-btn').removeClass('hidden');
        } else if ( target === 'tab-quarantine' ) {
            $('#erdo-bulk-webp-btn, #erdo-bulk-delete-btn').addClass('hidden');
            loadQuarantine();
        } else if ( target === 'tab-ai' ) {
            $('#erdo-bulk-webp-btn, #erdo-bulk-delete-btn').addClass('hidden');
            loadAiList();
        } else {
            $('#erdo-bulk-webp-btn').removeClass('hidden');
            $('#erdo-bulk-delete-btn').addClass('hidden');
        }
    });

    /* -------------------------------------------------------
       STATS
    ------------------------------------------------------- */
    function loadStats() {
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_stats', nonce: erdo_vars.nonce }, function(res) {
            if ( ! res.success ) return;
            var d = res.data;
            $('#stat-total-count').text( d.total );
            $('#stat-opt-score').text( d.opt_score + '%' );
            $('#stat-seo-score').text( d.seo_score + '%' );
            $('#stat-total-size').text( d.total > 0 ? d.total + ' images' : 'No images' );
            $('#stat-total-saved').text( d.total_saved );
            updateChart( 'chart-opt-ring', d.opt_score, '#2563eb' );
            updateChart( 'chart-seo-ring', d.seo_score, '#059669' );

            // ── Cache doğrulama ──────────────────────────────────────
            libraryVersion = d.library_version || 0;
            var cache = getGridCache();

            if ( ! gridRendered && ! isLoading ) {
                // İlk açılış: cache var ve güncel mi?
                if ( cache && parseInt(cache.version) === libraryVersion ) {
                    renderFromCache(cache);   // ✓ anında render
                } else {
                    clearGridCache();
                    loadImages(true);          // ✗ fresh yükle
                }
            } else if ( gridRendered && cache ) {
                // Arka planda versiyon değişmiş mi kontrol et
                if ( parseInt(cache.version) !== libraryVersion ) {
                    // Kütüphane değişmiş — sessizce yenile
                    clearGridCache();
                    loadImages(true);
                }
            }
        });
    }

    function updateChart(id, percent, color) {
        var pct = parseInt( percent, 10 ) || 0;
        $('#' + id).css(
            'background',
            'conic-gradient(' + color + ' 0% ' + pct + '%, #eef2f6 ' + pct + '% 100%)'
        );
        $('#' + id + ' span').text( pct + '%' );
    }

    /* -------------------------------------------------------
       LOADING NOTICE
    ------------------------------------------------------- */
    function setLoadingNotice(loaded, total) {
        var $notice = $('#erdo-loading-notice');
        var $text   = $('#erdo-loading-text');
        if ( loaded >= total && total > 0 ) {
            // Tamamlandı — notu gizle
            $notice.fadeOut(300);
        } else {
            $notice.show();
            if ( total > 0 ) {
                $text.text( loaded + ' / ' + total + ' images scanned' );
            } else {
                $text.text( 'Loading library...' );
            }
        }
    }

    /* -------------------------------------------------------
       LOAD IMAGES — Auto-load tüm sayfaları
    ------------------------------------------------------- */
    function loadImages(reset) {
        if ( isLoading ) return;
        isLoading = true;

        if ( reset ) {
            currentPage = 1;
            totalPages  = 1;
            totalFound  = 0;
            autoLoading = true;
            $('.erdo-grid').html('');
            $('#erdo-load-more-wrap').addClass('hidden');
            counts = { red: 0, orange: 0, green: 0, gray: 0 };
            updateCounts();
            setLoadingNotice(0, 0);
        }

        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_load_images', page: currentPage, search: searchQuery, nonce: erdo_vars.nonce },
            function(res) {
                if ( res.success ) {
                    $('#tab-red    .erdo-grid').append( res.data.html.red );
                    $('#tab-orange .erdo-grid').append( res.data.html.orange );
                    $('#tab-green  .erdo-grid').append( res.data.html.green );
                    $('#tab-gray   .erdo-grid').append( res.data.html.gray );

                    counts.red    += res.data.counts.red;
                    counts.orange += res.data.counts.orange;
                    counts.green  += res.data.counts.green;
                    counts.gray   += res.data.counts.gray;
                    updateCounts();
                    $('#stat-unused-count').text( counts.gray );

                    totalPages = res.data.max_pages || 1;
                    totalFound = res.data.found    || 0;
                    var loadedSoFar = currentPage * 100; // batch = 100
                    setLoadingNotice( Math.min(loadedSoFar, totalFound), totalFound );

                    if ( currentPage < totalPages && res.data.found > 0 ) {
                        // Bir sonraki batch'i otomatik yükle
                        currentPage++;
                        isLoading = false;
                        loadImages(false);
                        return;
                    } else {
                        // Tümü yüklendi — cache'e kaydet
                        autoLoading = false;
                        gridRendered = true;
                        setLoadingNotice(totalFound, totalFound);
                        $('#erdo-load-more-wrap').addClass('hidden');
                        showEmptyStates();
                        saveGridCache(libraryVersion);
                    }
                }
                isLoading = false;
            }
        ).fail(function() {
            autoLoading = false;
            isLoading   = false;
            $('#erdo-loading-notice').hide();
            $('#erdo-load-more-wrap').removeClass('hidden');
        });
    }

    function updateCounts() {
        $('#count-red').text( counts.red );
        $('#count-orange').text( counts.orange );
        $('#count-green').text( counts.green );
        $('#count-gray').text( counts.gray );
    }

    var emptyMessages = {
        'tab-red'   : 'No critical images — great start!',
        'tab-orange': 'No images with moderate issues.',
        'tab-green' : 'No fully-optimized images yet.',
        'tab-gray'  : 'No unused images found in your library.'
    };

    function showEmptyStates() {
        $.each(['tab-red','tab-orange','tab-green','tab-gray'], function(_, tab) {
            var $grid = $('#' + tab + ' .erdo-grid');
            if ( $grid.children('.erdo-card').length === 0 && $grid.children('.erdo-empty-state').length === 0 ) {
                $grid.append('<div class="erdo-empty-state"><p>' + emptyMessages[tab] + '</p></div>');
            }
        });
    }

    // Sayfa açılışı: önce cache'e bak
    var initialCache = getGridCache();
    if ( initialCache ) {
        // Cache var — anında göster, ardından stats ile doğrula
        renderFromCache(initialCache);
    }
    // Stats her zaman yüklenir (doğrulama + dashboard güncelleme)
    // loadImages() yalnızca stats gelince, cache geçersizse çalışır
    loadStats();

    /* -------------------------------------------------------
       LOAD MORE + SEARCH
    ------------------------------------------------------- */
    $('#erdo-load-more-btn').on('click', function() {
        loadImages(false);
    });

    var searchTimer;
    $('#erdo-search-input').on('keyup', function(e) {
        clearTimeout(searchTimer);
        var val = $(this).val();
        if ( e.key === 'Enter' || val === '' ) {
            searchQuery = val;
            loadImages(true);
            return;
        }
        searchTimer = setTimeout(function() {
            if ( searchQuery !== val ) {
                searchQuery = val;
                loadImages(true);
            }
        }, 600);
    });

    // Arama kutusunu temizle
    $('#erdo-search-input').on('search', function() {
        searchQuery = '';
        loadImages(true);
    });

    /* -------------------------------------------------------
       TOOLTIP HOTSPOTS
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-hotspot', function(e) {
        e.stopPropagation();
        var $content = $(this).next('.erdo-help-content');
        $('.erdo-help-content').not($content).hide();
        $content.fadeToggle(150);
    });

    $(document).on('click', function() {
        $('.erdo-help-content').hide();
    });

    /* -------------------------------------------------------
       OPEN IMAGE MODAL
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-card', function(e) {
        // Seçim: onay kutusu, Ctrl/Cmd+tık ya da seçim varken herhangi bir tık
        if ( $(e.target).closest('.erdo-card-check').length || e.ctrlKey || e.metaKey || selectedIds().length ) {
            toggleSelect($(this));
            return;
        }
        var id = $(this).data('id');
        openModal('<p style="text-align:center;padding:60px 20px;">Analyzing image...</p>');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce },
            function(res) { $('#erdo-modal-content').html(res); }
        );
    });

    /* -------------------------------------------------------
       SAVE IMAGE DATA
       (Görsel değişti → cache geçersiz)
    ------------------------------------------------------- */
    $(document).on('click', '#erdo-save-btn', function() {
        var btn  = $(this);
        var data = $('#erdo-audit-form').serialize() + '&action=erdo_save_data&nonce=' + erdo_vars.nonce;
        btn.text('Saving...').prop('disabled', true);

        $.post(erdo_vars.ajax_url, data, function(res) {
            if ( res.success ) {
                closeModal();
                var d    = res.data;
                var card = $('.erdo-card[data-id="' + d.id + '"]');
                card.removeClass('border-red border-orange border-green border-gray');
                card.addClass('border-' + ( d.is_used === false ? 'gray' : d.status ));
                card.find('.erdo-card-info').text(d.filename);
                card.find('.erdo-score-badge').text(d.score)
                    .removeClass('erdo-score-red erdo-score-orange erdo-score-green erdo-score-gray')
                    .addClass('erdo-score-' + ( d.is_used === false ? 'gray' : d.status ));
                clearGridCache(); // Kart değişti, cache'i güncelle
                saveGridCache(libraryVersion);
                loadStats();
            } else {
                alert('Error saving data. Please try again.');
                btn.text('Save Changes').prop('disabled', false);
            }
        });
    });

    /* -------------------------------------------------------
       CONVERT TO WEBP / AVIF
       Dönüşüm JSON yanıt döndürür; başarı durumunda modal
       erdo_get_detail ile yeniden yüklenir.
    ------------------------------------------------------- */
    $(document).on('click', '.webp-convert-btn', function() {
        var id      = $(this).data('id');
        var btn     = $(this);
        var origTxt = btn.text();
        btn.html('<span class="erdo-spinner"></span> Converting...').prop('disabled', true);

        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_convert_webp', id: id, nonce: erdo_vars.nonce },
            function(res) {
                if ( res && res.success ) {
                    // Dönüşüm başarılı — güncel audit raporu getir
                    $.post(
                        erdo_vars.ajax_url,
                        { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce },
                        function(html) {
                            $('#erdo-modal-content').html(html);
                            loadStats();
                        }
                    );
                } else {
                    var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Conversion failed.';
                    btn.text( origTxt ).prop('disabled', false).attr('title', msg);
                }
            }
        ).fail(function() {
            btn.text('Error').prop('disabled', false);
        });
    });

    /* -------------------------------------------------------
       RESTORE ORIGINAL
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-restore-btn', function() {
        if ( ! confirm('Restore the original image? The optimized version will be replaced.') ) return;
        var id  = $(this).data('id');
        var btn = $(this);
        btn.text('Restoring...').prop('disabled', true);
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_restore_original', id: id, nonce: erdo_vars.nonce },
            function(res) {
                if ( res && res.success ) {
                    $.post(
                        erdo_vars.ajax_url,
                        { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce },
                        function(html) { $('#erdo-modal-content').html(html); loadStats(); }
                    );
                } else {
                    var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Restore failed.';
                    alert(msg);
                    btn.text('Restore Original').prop('disabled', false);
                }
            }
        ).fail(function() { btn.text('Error').prop('disabled', false); });
    });

    /* -------------------------------------------------------
       DELETE IMAGE
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-delete-btn', function() {
        if ( ! confirm('Move this image to Quarantine? It disappears from your site but can be restored later from the Quarantine tab.') ) return;
        var id = $(this).data('id');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_delete_image', id: id, nonce: erdo_vars.nonce },
            function( res ) {
                if ( res && res.success && res.data.in_use ) {
                    alert('Moved to Quarantine. Note: this image was still used on your site — restore it if a page looks broken.');
                }
                clearGridCache(); location.reload();
            }
        );
    });

    /* -------------------------------------------------------
       QUARANTINE TAB
    ------------------------------------------------------- */
    function loadQuarantine() {
        var $grid = $('#erdo-quarantine-grid');
        $grid.html('<p style="padding:20px;">Loading...</p>');
        $.post(erdo_vars.ajax_url, { action: 'erdo_quarantine_list', nonce: erdo_vars.nonce }, function( res ) {
            if ( ! res || ! res.success ) { $grid.html(''); return; }
            $('#count-quarantine').text( res.data.count );
            $grid.html( res.data.html || '<p style="padding:20px;">Quarantine is empty.</p>' );
        });
    }

    $(document).on('click', '.erdo-q-restore, .erdo-q-purge', function( e ) {
        e.stopPropagation();
        var purge = $(this).hasClass('erdo-q-purge');
        if ( purge && ! confirm('Delete this image and all its files permanently? This cannot be undone.') ) return;
        var $btn = $(this).prop('disabled', true);
        $.post(erdo_vars.ajax_url, {
            action: purge ? 'erdo_quarantine_purge' : 'erdo_quarantine_restore',
            id: $btn.data('id'),
            nonce: erdo_vars.nonce
        }, function( res ) {
            if ( ! res || ! res.success ) {
                alert( ( res && res.data && res.data.message ) || 'Failed.' );
                $btn.prop('disabled', false);
                return;
            }
            clearGridCache();
            loadQuarantine();
            loadImages(true);
        });
    });

    /* -------------------------------------------------------
       USAGE INDEX BUILD (first run / after upgrade)
    ------------------------------------------------------- */
    (function buildIndex() {
        var $notice = $('#erdo-index-notice');
        if ( ! $notice.length || $notice.data('status') === 'ready' ) return;
        function step() {
            $.post(erdo_vars.ajax_url, { action: 'erdo_usage_build_step', nonce: erdo_vars.nonce }, function( res ) {
                if ( ! res || ! res.success ) { setTimeout(step, 5000); return; }
                var d = res.data;
                if ( d.status === 'ready' ) {
                    $notice.addClass('hidden').data('status', 'ready');
                    clearGridCache();
                    loadImages(true);
                    return;
                }
                $('#erdo-index-text').text('Building the usage index: ' + d.done + ' / ' + d.total + ' posts scanned. Unused-image cleanup becomes available when it finishes.');
                step();
            }).fail(function() { setTimeout(step, 5000); });
        }
        step();
    })();

    /* -------------------------------------------------------
       MAGIC FILL BUTTONS
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-magic-btn', function() {
        var target = $(this).data('target');
        var source = $(this).data('source');
        var val    = '';

        if ( source === 'parent' ) {
            val = $('#erdo-parent-title').val();
        } else if ( source === 'filename' ) {
            val = $('#erdo-current-filename').val()
                .replace(/\.[^/.]+$/, '')
                .replace(/[-_]/g, ' ')
                .replace(/\w\S*/g, function(txt) {
                    return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();
                });
        }

        if ( val ) {
            var $input = $('#erdo-input-' + target);
            $input.val(val).css('background-color', '#e5fdf4');
            setTimeout(function() { $input.css('background-color', ''); }, 500);
        }
    });

    /* -------------------------------------------------------
       MANUAL RESIZE
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-resize-btn', function(e) {
        e.preventDefault();
        var btn = $(this);
        var id  = btn.data('id');
        var w   = $('#erdo-w-' + id).val();
        var h   = $('#erdo-h-' + id).val();

        if ( ! w || ! h ) { alert('Please enter width and height.'); return; }

        btn.text('Resizing...').prop('disabled', true);
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_manual_resize', id: id, w: w, h: h, nonce: erdo_vars.nonce },
            function(res) {
                if ( res.success ) {
                    $.post(
                        erdo_vars.ajax_url,
                        { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce },
                        function(html) { $('#erdo-modal-content').html(html); }
                    );
                } else {
                    alert('Resize failed. Please try again.');
                    btn.text('Resize').prop('disabled', false);
                }
            }
        );
    });

    /* -------------------------------------------------------
       REFRESH ALL AUDITS
    ------------------------------------------------------- */
    $('#erdo-refresh-all-btn').on('click', function() {
        if ( ! confirm('Clear all audit caches and re-scan? This may take a moment.') ) return;
        var btn = $(this);
        btn.prop('disabled', true).text('Refreshing...');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_refresh_all', nonce: erdo_vars.nonce },
            function() { clearGridCache(); location.reload(); }
        );
    });

    /* -------------------------------------------------------
       ADD NEW IMAGE (WP Media)
    ------------------------------------------------------- */
    $('#erdo-upload-btn').on('click', function(e) {
        e.preventDefault();
        var frame = wp.media({ title: 'Add New Image', button: { text: 'Add' }, multiple: true });
        frame.on('close', function() { location.reload(); });
        frame.open();
    });

    /* -------------------------------------------------------
       MODAL OPEN / CLOSE
    ------------------------------------------------------- */
    function openModal(html) {
        if ( html ) $('#erdo-modal-content').html(html);
        $('#erdo-modal-overlay').removeClass('hidden');
    }

    function closeModal() {
        $('#erdo-modal-overlay').addClass('hidden');
    }

    $('#erdo-close-btn').on('click', closeModal);

    $('#erdo-modal-overlay').on('click', function(e) {
        if ( e.target === this ) closeModal();
    });

    $(document).on('keydown', function(e) {
        if ( e.key === 'Escape' ) closeModal();
    });

    /* -------------------------------------------------------
       SETTINGS MODAL
    ------------------------------------------------------- */
    $('#erdo-settings-btn').on('click', function() {
        openModal('<p style="text-align:center;padding:60px 20px;">Loading settings...</p>');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_get_settings', nonce: erdo_vars.nonce },
            function(res) { $('#erdo-modal-content').html(res); }
        );
    });

    $(document).on('click', '#erdo-save-settings-btn', function() {
        var btn  = $(this);
        var data = $('#erdo-settings-form').serialize() + '&action=erdo_save_settings&nonce=' + erdo_vars.nonce;
        btn.text('Saving...').prop('disabled', true);
        $.post(erdo_vars.ajax_url, data, function(res) {
            if ( res.success ) {
                btn.text('Saved!');
                setTimeout(function() { closeModal(); location.reload(); }, 800);
            } else {
                btn.text('Save Settings').prop('disabled', false);
            }
        });
    });

    /* -------------------------------------------------------
       TOOLS DROPDOWN
    ------------------------------------------------------- */
    $('#erdo-tools-btn').on('click', function(e) {
        e.stopPropagation();
        var $drop = $('#erdo-tools-dropdown');
        var open  = $drop.hasClass('open');
        $drop.toggleClass('open', ! open);
        $(this).closest('.erdo-dropdown-wrap').toggleClass('erdo-tools-open', ! open);
    });

    $(document).on('click', function() {
        $('#erdo-tools-dropdown').removeClass('open');
        $('.erdo-dropdown-wrap').removeClass('erdo-tools-open');
    });

    $(document).on('click', '.erdo-dropdown-item', function() {
        $('#erdo-tools-dropdown').removeClass('open');
        $('.erdo-dropdown-wrap').removeClass('erdo-tools-open');
    });

    /* -------------------------------------------------------
       BACKGROUND QUEUE
    ------------------------------------------------------- */
    var queuePollTimer = null;

    function pollQueueStatus() {
        $.post(erdo_vars.ajax_url, { action: 'erdo_queue_status', nonce: erdo_vars.nonce }, function(res) {
            if ( ! res.success ) return;
            var d = res.data;
            var $box    = $('#erdo-queue-status-box');
            var $start  = $('#erdo-start-queue-btn');
            var $cancel = $('#erdo-cancel-queue-btn');
            if ( d.running ) {
                $box.show().html('<strong>Background convert running:</strong> ' + d.done + ' / ' + d.total + ' (' + d.percent + '%)' + (d.skipped > 0 ? ' &mdash; ' + d.skipped + ' already optimized' : '') + (d.errors > 0 ? ' &mdash; <span style="color:#dc2626">' + d.errors + ' errors</span>' : ''));
                $start.hide(); $cancel.show();
                queuePollTimer = setTimeout(pollQueueStatus, 5000);
            } else if ( d.status === 'done' ) {
                $box.show().html('<strong style="color:#059669;">Done!</strong> ' + d.done + ' images processed' + (d.skipped > 0 ? ', ' + d.skipped + ' already optimized' : '') + (d.errors > 0 ? ', ' + d.errors + ' failed' : '') + '.');
                $start.show(); $cancel.hide(); clearTimeout(queuePollTimer); loadStats();
            } else {
                $box.hide(); $start.show(); $cancel.hide(); clearTimeout(queuePollTimer);
            }
        });
    }

    $(document).on('click', '#erdo-start-queue-btn', function() {
        if ( ! confirm('Start background bulk conversion via WP-Cron? Safe to close this tab.') ) return;
        var btn = $(this);
        btn.prop('disabled', true).text('Starting...');
        $.post(erdo_vars.ajax_url, { action: 'erdo_start_queue', nonce: erdo_vars.nonce }, function(res) {
            btn.prop('disabled', false).text('Start Background Convert');
            if ( res.success ) { pollQueueStatus(); }
            else { alert( (res.data && res.data.message) ? res.data.message : 'Failed.' ); }
        });
    });

    $(document).on('click', '#erdo-cancel-queue-btn', function() {
        $.post(erdo_vars.ajax_url, { action: 'erdo_cancel_queue', nonce: erdo_vars.nonce }, function() {
            clearTimeout(queuePollTimer);
            $('#erdo-queue-status-box').hide();
            $('#erdo-start-queue-btn').show();
            $('#erdo-cancel-queue-btn').hide();
        });
    });

    /* -------------------------------------------------------
       .HTACCESS BUTTONS
    ------------------------------------------------------- */
    $(document).on('click', '#erdo-htaccess-write-btn', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Writing...');
        $.post(erdo_vars.ajax_url, { action: 'erdo_write_htaccess', nonce: erdo_vars.nonce }, function(res) {
            btn.prop('disabled', false).text('Write .htaccess Rules');
            if ( res.success ) { btn.hide(); $('#erdo-htaccess-remove-btn').show(); $('#erdo-htaccess-status').text('Active').css('color','#059669'); }
        });
    });

    $(document).on('click', '#erdo-htaccess-remove-btn', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('Removing...');
        $.post(erdo_vars.ajax_url, { action: 'erdo_remove_htaccess', nonce: erdo_vars.nonce }, function(res) {
            btn.prop('disabled', false).text('Remove .htaccess Rules');
            if ( res.success ) { btn.hide(); $('#erdo-htaccess-write-btn').show(); $('#erdo-htaccess-status').text(''); }
        });
    });

    /* -------------------------------------------------------
       SCAN MISSING THUMBNAILS
    ------------------------------------------------------- */
    $('#erdo-scan-thumbnails-btn').on('click', function() {
        openModal('<p style="text-align:center;padding:60px 20px;">Scanning posts...</p>');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_scan_thumbnails', nonce: erdo_vars.nonce },
            function(res) {
                if ( ! res.success ) {
                    $('#erdo-modal-content').html('<p style="padding:20px;color:red;">Error scanning posts.</p>');
                    return;
                }
                var html = '<div class="modal-header"><h2>Missing Featured Images</h2></div>';
                if ( res.data.length === 0 ) {
                    html += '<div style="padding:40px;text-align:center;color:#00a32a;">';
                    html += '<span class="dashicons dashicons-yes-alt" style="font-size:56px;width:56px;height:56px;display:block;margin:0 auto 16px;"></span>';
                    html += '<h3>All posts have featured images!</h3></div>';
                } else {
                    html += '<p style="padding:0 24px;color:#d63638;">Found <strong>' + res.data.length + '</strong> posts without a featured image:</p>';
                    html += '<ul style="padding:0 24px 24px;max-height:420px;overflow-y:auto;margin:0;">';
                    $.each(res.data, function(i, item) {
                        html += '<li style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px 14px;margin-bottom:8px;';
                        html += 'display:flex;justify-content:space-between;align-items:center;border-radius:4px;">';
                        html += '<strong>' + $('<div>').text(item.title).html() + '</strong>';
                        html += '<a href="' + $('<div>').text(item.link).html() + '" target="_blank" class="button button-small">';
                        html += 'Edit Post <span class="dashicons dashicons-external" style="font-size:12px;line-height:24px;"></span></a>';
                        html += '</li>';
                    });
                    html += '</ul>';
                }
                $('#erdo-modal-content').html(html);
            }
        );
    });

    /* -------------------------------------------------------
       BULK CONVERT
    ------------------------------------------------------- */
    $('#erdo-bulk-webp-btn').on('click', function() {
        if ( ! confirm('Convert all eligible images? This may take a while depending on your library size.') ) return;
        var btn = $(this);
        btn.prop('disabled', true).text('Scanning...');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_get_bulk_list', nonce: erdo_vars.nonce },
            function(res) {
                if ( res.success && res.data.length > 0 ) {
                    runBulkProcess(res.data, 'erdo_bulk_step', btn);
                } else {
                    alert('No images found to convert.');
                    btn.prop('disabled', false).text('Bulk Convert');
                }
            }
        );
    });

    /* -------------------------------------------------------
       BULK DELETE (UNUSED) — Safety gate
    ------------------------------------------------------- */
    $('#erdo-bulk-delete-btn').on('click', function() {
        $('#erdo-safety-modal-overlay').removeClass('hidden');
        $('#erdo-safety-input').val('').trigger('focus');
        $('#erdo-safety-confirm-btn').prop('disabled', false);
    });

    $('#erdo-safety-cancel-btn').on('click', function() {
        $('#erdo-safety-modal-overlay').addClass('hidden');
    });

    $('#erdo-safety-input').on('input', function() {
        $('#erdo-safety-confirm-btn').prop('disabled', $(this).val() !== 'DELETE');
    });

    $('#erdo-safety-confirm-btn').on('click', function() {
        $('#erdo-safety-modal-overlay').addClass('hidden');
        var btn = $('#erdo-bulk-delete-btn');
        btn.prop('disabled', true).text('Scanning...');
        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_get_unused_list', nonce: erdo_vars.nonce },
            function(res) {
                if ( ! res.success ) {
                    alert( ( res.data && res.data.message ) || 'Could not list unused images.' );
                    btn.prop('disabled', false).text('Quarantine All Unused');
                } else if ( res.data.length > 0 ) {
                    // Sunucu her görseli yeniden denetler (indeks + dosya adı); kullanımda olabilecekler atlanır.
                    runBulkProcess(res.data, 'erdo_quarantine_unused', btn);
                } else {
                    alert('No unused images found.');
                    btn.prop('disabled', false).text('Quarantine All Unused');
                }
            }
        );
    });

    /* -------------------------------------------------------
       BULK PROCESS ENGINE
    ------------------------------------------------------- */
    function runBulkProcess(ids, action, btn) {
        var total   = ids.length;
        var current = 0;
        var $wrapper = $('#erdo-bulk-progress-wrapper');
        $wrapper.removeClass('hidden');
        $('#erdo-progress-total').text(total);

        function processNext() {
            if ( ids.length === 0 ) {
                btn.text('Done!');
                clearGridCache();
                setTimeout(function() {
                    $wrapper.addClass('hidden');
                    location.reload();
                }, 1000);
                return;
            }

            var currentId = ids.shift();
            current++;
            $('#erdo-progress-current').text(current);
            $('#erdo-progress-fill').css('width', Math.round( (current / total) * 100 ) + '%');

            $.post(
                erdo_vars.ajax_url,
                { action: action, id: currentId, nonce: erdo_vars.nonce },
                function() { processNext(); }
            ).fail(function() { processNext(); });
        }

        processNext();
    }

    /* -------------------------------------------------------
       GRID BOYUTU SEÇİCİ
    ------------------------------------------------------- */
    var savedSize = localStorage.getItem('erdo_grid_size') || 'medium';
    $('.erdo-main-content').attr('data-grid-size', savedSize);
    $('.erdo-grid-size-btn[data-size="' + savedSize + '"]').addClass('active').siblings().removeClass('active');

    $(document).on('click', '.erdo-grid-size-btn', function() {
        var size = $(this).data('size');
        $('.erdo-main-content').attr('data-grid-size', size);
        $(this).addClass('active').siblings().removeClass('active');
        localStorage.setItem('erdo_grid_size', size);
    });

    /* -------------------------------------------------------
       ALT TEXT BOŞ FİLTRESİ
    ------------------------------------------------------- */
    var altFilterActive = false;

    $('#erdo-alt-filter-btn').on('click', function() {
        altFilterActive = ! altFilterActive;
        $(this).toggleClass('erdo-filter-active', altFilterActive);

        // Alt text durumu her kartın data-has-alt özniteliğinde sunucu
        // tarafından zaten hesaplanmış geliyor (bulk_analyze() checklist'i) —
        // kart başına ayrı AJAX isteği atmaya gerek yok, anında filtrelenir.
        $('.erdo-card').each(function() {
            var $card = $(this);
            if ( ! altFilterActive ) {
                $card.removeClass('erdo-hidden-filter');
                return;
            }
            var hasAlt = $card.data('has-alt') == 1;
            $card.toggleClass('erdo-hidden-filter', hasAlt);
        });
    });

    /* -------------------------------------------------------
       BULK RENAME — Tüm kötü dosya adlarını düzelt
    ------------------------------------------------------- */
    // Önce önizleme: yalnızca adı değişecek görseller listelenir, sonra uygula/geri al.
    $('#erdo-bulk-rename-btn').on('click', function() {
        openRenameModal([], 'fix');
    });

    /* -------------------------------------------------------
       BULK ALT TEXT — Dosya adından alt text üret
    ------------------------------------------------------- */
    $('#erdo-bulk-alt-btn').on('click', function() {
        if ( ! confirm('Auto-generate alt text from filename for all images missing alt text?') ) return;
        var btn = $(this);
        btn.html('<span class="erdo-spinner"></span> Generating...').prop('disabled', true);

        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_bulk_alt_text', nonce: erdo_vars.nonce },
            function(res) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-format-image"></span> Auto Alt Text');
                if ( res.success ) {
                    alert(res.data.updated + ' images updated.');
                    clearGridCache();
                    loadImages(true);
                }
            }
        );
    });

    /* -------------------------------------------------------
       CSV EXPORT
    ------------------------------------------------------- */
    $('#erdo-export-csv-btn').on('click', function() {
        var btn = $(this);
        btn.html('<span class="erdo-spinner"></span> Exporting...').prop('disabled', true);

        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_export_csv', nonce: erdo_vars.nonce },
            function(res) {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export CSV');
                if ( res.success ) {
                    var blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8;' });
                    var url  = URL.createObjectURL(blob);
                    var a    = document.createElement('a');
                    a.href     = url;
                    a.download = 'erdo-seo-audit.csv';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            }
        );
    });

    /* -------------------------------------------------------
       TEK GÖRSEL RENAME (modal butonu)
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-rename-btn', function() {
        var id  = $(this).data('id');
        var btn = $(this);
        btn.html('<span class="erdo-spinner"></span> Renaming...').prop('disabled', true);

        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_rename_file', id: id, nonce: erdo_vars.nonce },
            function(res) {
                if ( res.success ) {
                    // Modalı yenile
                    $.post(erdo_vars.ajax_url, { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce }, function(html) {
                        $('#erdo-modal-content').html(html);
                    });
                    // Kartı güncelle
                    var card = $('.erdo-card[data-id="' + id + '"]');
                    card.find('.erdo-card-info').text(res.data.new_name);
                    clearGridCache();
                    loadStats();
                } else {
                    btn.html('&#9998; Fix Filename').prop('disabled', false);
                    alert(res.data.message || 'Rename failed.');
                }
            }
        );
    });

    /* -------------------------------------------------------
       CDN UPLOAD (modal butonu)
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-cdn-upload-btn', function() {
        var id  = $(this).data('id');
        var btn = $(this);
        btn.html('<span class="erdo-spinner"></span> Uploading...').prop('disabled', true);

        $.post(
            erdo_vars.ajax_url,
            { action: 'erdo_cdn_upload', id: id, nonce: erdo_vars.nonce },
            function(res) {
                if ( res.success ) {
                    btn.html('&#9729; Upload to CDN <span style="display:block;font-size:10px;color:#059669;">&#10003; On CDN</span>').prop('disabled', false);
                } else {
                    btn.html('&#9729; Upload to CDN').prop('disabled', false);
                    alert(res.data.message || 'CDN upload failed.');
                }
            }
        );
    });

    /* -------------------------------------------------------
       FILENAME PREVIEW (modal — filename input'unu izle)
    ------------------------------------------------------- */
    $(document).on('input', '#erdo-input-filename', function() {
        var val = $(this).val();
        if ( ! val ) {
            $('#erdo-filename-preview').hide();
            return;
        }
        var preview = val.toLowerCase()
                        .replace(/\s+/g, '-')
                        .replace(/[^a-z0-9\-]/g, '')
                        .replace(/-+/g, '-')
                        .replace(/^-|-$/g, '');
        if ( preview && preview !== val ) {
            $('#erdo-filename-preview-val').text(preview);
            $('#erdo-filename-preview').show();
        } else {
            $('#erdo-filename-preview').hide();
        }
    });

    /* -------------------------------------------------------
       BEFORE / AFTER SLIDER (modal butonu)
    ------------------------------------------------------- */
    $(document).on('click', '.erdo-before-after-btn', function() {
        var current  = $(this).data('current');
        var original = $(this).data('original');

        var html = '<div class="erdo-ba-overlay" id="erdo-ba-overlay">';
        html += '<div class="erdo-ba-modal">';
        html += '<div class="erdo-ba-header">';
        html += '<h3>Before &amp; After</h3>';
        html += '<button class="erdo-ba-close" id="erdo-ba-close">&times;</button>';
        html += '</div>';
        html += '<div class="erdo-ba-container" id="erdo-ba-container">';
        html += '<div class="erdo-ba-img erdo-ba-after"><img src="' + current  + '" alt="Optimized"></div>';
        html += '<div class="erdo-ba-img erdo-ba-before"><img src="' + original + '" alt="Original"></div>';
        html += '<span class="erdo-ba-label erdo-ba-label-before">ORIGINAL</span>';
        html += '<span class="erdo-ba-label erdo-ba-label-after">OPTIMIZED</span>';
        html += '<div class="erdo-ba-slider" id="erdo-ba-slider"><div class="erdo-ba-handle"></div></div>';
        html += '</div>';
        html += '</div></div>';

        $('body').append(html);
        initBeforeAfterSlider();
    });

    function initBeforeAfterSlider() {
        var $container = $('#erdo-ba-container');
        var $before    = $container.find('.erdo-ba-before');
        var $slider    = $('#erdo-ba-slider');
        var dragging   = false;

        function moveTo(clientX) {
            var rect = $container[0].getBoundingClientRect();
            var pct  = Math.min(100, Math.max(0, ((clientX - rect.left) / rect.width) * 100));
            $before.css('clip-path', 'inset(0 ' + (100 - pct) + '% 0 0)');
            $slider.css('left', pct + '%');
        }

        moveTo($container[0].getBoundingClientRect().left + $container[0].getBoundingClientRect().width / 2);

        $container.on('mousedown touchstart', function(e) {
            dragging = true;
            var clientX = e.originalEvent.touches ? e.originalEvent.touches[0].clientX : e.clientX;
            moveTo(clientX);
            e.preventDefault();
        });

        $(document).on('mousemove.ba touchmove.ba', function(e) {
            if ( ! dragging ) return;
            var clientX = e.originalEvent.touches ? e.originalEvent.touches[0].clientX : e.clientX;
            moveTo(clientX);
        });

        $(document).on('mouseup.ba touchend.ba', function() { dragging = false; });

        $('#erdo-ba-close').on('click', function() {
            $('#erdo-ba-overlay').remove();
            $(document).off('.ba');
        });

        $('#erdo-ba-overlay').on('click', function(e) {
            if ( e.target === this ) {
                $('#erdo-ba-overlay').remove();
                $(document).off('.ba');
            }
        });
    }

    /* -------------------------------------------------------
       SAĞ TIK CONTEXT MENU
    ------------------------------------------------------- */
    var $ctxMenu = null;

    function removeContextMenu() {
        if ( $ctxMenu ) { $ctxMenu.remove(); $ctxMenu = null; }
    }

    $(document).on('contextmenu', '.erdo-card', function(e) {
        e.preventDefault();
        removeContextMenu();
        var id   = $(this).data('id');
        var mime = $(this).find('.erdo-format-badge').text().toLowerCase();
        var canConvert = (mime !== 'webp' && mime !== 'avif');

        var html = '<div class="erdo-ctx-menu" id="erdo-ctx-menu">';
        html += '<button class="erdo-ctx-item" data-action="detail" data-id="' + id + '"><span class="dashicons dashicons-visibility"></span> View Details</button>';
        if ( canConvert ) {
            html += '<button class="erdo-ctx-item" data-action="convert" data-id="' + id + '"><span class="dashicons dashicons-update"></span> Convert</button>';
        }
        html += '<button class="erdo-ctx-item" data-action="rename" data-id="' + id + '"><span class="dashicons dashicons-tag"></span> Fix Filename</button>';
        html += '<div class="erdo-ctx-sep"></div>';
        html += '<button class="erdo-ctx-item erdo-ctx-danger" data-action="delete" data-id="' + id + '"><span class="dashicons dashicons-archive"></span> Move to Quarantine</button>';
        html += '</div>';

        $ctxMenu = $(html);
        $('body').append($ctxMenu);
        $ctxMenu.css({ top: e.pageY, left: e.pageX });
    });

    $(document).on('click', function(e) {
        if ( ! $(e.target).closest('#erdo-ctx-menu').length ) removeContextMenu();
    });

    $(document).on('click', '.erdo-ctx-item', function() {
        var action = $(this).data('action');
        var id     = $(this).data('id');
        removeContextMenu();

        if ( 'detail' === action ) {
            openModal('<p style="text-align:center;padding:60px 20px;">Analyzing image...</p>');
            $.post(erdo_vars.ajax_url, { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce },
                function(res) { $('#erdo-modal-content').html(res); });

        } else if ( 'convert' === action ) {
            $.post(erdo_vars.ajax_url, { action: 'erdo_convert_webp', id: id, nonce: erdo_vars.nonce },
                function(res) {
                    if ( res && res.success ) { clearGridCache(); loadImages(true); }
                    else { alert('Conversion failed.'); }
                });

        } else if ( 'rename' === action ) {
            $.post(erdo_vars.ajax_url, { action: 'erdo_rename_file', id: id, nonce: erdo_vars.nonce },
                function(res) {
                    if ( res.success ) {
                        clearGridCache(); loadImages(true);
                    } else {
                        alert(res.data.message || 'Already clean or could not rename.');
                    }
                });

        } else if ( 'delete' === action ) {
            if ( ! confirm('Move this image to Quarantine? You can restore it later.') ) return;
            $.post(erdo_vars.ajax_url, { action: 'erdo_delete_image', id: id, nonce: erdo_vars.nonce },
                function() { clearGridCache(); location.reload(); });
        }
    });

    /* -------------------------------------------------------
       ÇOKLU SEÇİM — kart köşesindeki kutu ya da Ctrl/Cmd+tık
    ------------------------------------------------------- */
    var i18n = erdo_vars.i18n || {};

    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(str || '').replace(/%(\d)\$d|%d/g, function(m, pos) {
            return pos ? args[pos - 1] : args[i++];
        });
    }

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function selectedIds() {
        return $('.erdo-card.erdo-selected').map(function() { return $(this).data('id'); }).get();
    }

    function updateSelectionBar() {
        var n = selectedIds().length;
        $('#erdo-selection-count').text(fmt(i18n.selected, n));
        $('#erdo-selection-bar').toggleClass('hidden', n === 0);
        $('body').toggleClass('erdo-selecting', n > 0);
    }

    function toggleSelect($card) {
        var on = ! $card.hasClass('erdo-selected');
        $card.toggleClass('erdo-selected', on);
        $card.find('.erdo-card-check').attr('aria-checked', on ? 'true' : 'false');
        updateSelectionBar();
    }

    function clearSelection() {
        $('.erdo-card.erdo-selected').removeClass('erdo-selected').find('.erdo-card-check').attr('aria-checked', 'false');
        updateSelectionBar();
    }

    $('#erdo-selection-clear').on('click', clearSelection);
    $('#erdo-selection-rename').on('click', function() {
        openRenameModal(selectedIds(), 'pattern');
    });

    /* -------------------------------------------------------
       YENİDEN ADLANDIRMA PENCERESİ
       mode 'fix'    : tüm kütüphanede adı değişecek olanlar (desen yok)
       mode 'pattern': seçili görseller + desen ({keyword}-{n} …)
       Akış: önizleme → (adları düzenle) → uygula (25'lik parçalar) → sonuç + geri al
    ------------------------------------------------------- */
    var renameIds  = [];
    var renameMode = 'fix';

    function openRenameModal(ids, mode) {
        renameIds  = ids;
        renameMode = mode;
        var html = '<div class="modal-header"><h2>' + esc(mode === 'fix' ? i18n.fixTitle : i18n.renameTitle) + '</h2></div>';
        html += '<div class="erdo-rename-body">';
        if ( mode === 'pattern' ) {
            html += '<div class="erdo-rn-fields">';
            html += '<label>' + esc(i18n.pattern) + '<input type="text" id="erdo-rn-pattern" class="erdo-input" value="{keyword}-{n}"></label>';
            html += '<label>' + esc(i18n.keyword) + '<input type="text" id="erdo-rn-keyword" class="erdo-input"></label>';
            html += '<button type="button" class="button" id="erdo-rn-preview">' + esc(i18n.preview) + '</button>';
            html += '</div>';
            html += '<p class="erdo-rn-hint">' + esc(i18n.tokens) + '<br>' + esc(i18n.keywordHint) + '</p>';
        }
        html += '<p class="erdo-rn-safety"><span class="dashicons dashicons-shield"></span> ' + esc(i18n.safety) + '</p>';
        html += '<div id="erdo-rn-table"></div>';
        html += '<div class="erdo-rn-actions"><button type="button" class="button button-primary" id="erdo-rn-apply" disabled></button><span id="erdo-rn-status" role="status"></span></div>';
        html += '</div>';
        openModal(html);
        loadRenamePreview();
    }

    function loadRenamePreview() {
        $('#erdo-rn-table').html('<p class="erdo-rn-loading"><span class="erdo-spinner"></span> ' + esc(i18n.loading) + '</p>');
        $('#erdo-rn-apply').prop('disabled', true).text(fmt(i18n.apply, 0));
        $.post(erdo_vars.ajax_url, {
            action  : 'erdo_rename_preview',
            nonce   : erdo_vars.nonce,
            ids     : renameIds,
            pattern : renameMode === 'pattern' ? $('#erdo-rn-pattern').val() : '',
            keyword : renameMode === 'pattern' ? $('#erdo-rn-keyword').val() : ''
        }, function(res) {
            if ( ! res || ! res.success ) {
                $('#erdo-rn-table').html('<p class="erdo-rn-error">' + esc(i18n.error) + '</p>');
                return;
            }
            renderRenameRows(res.data.rows);
        }).fail(function() {
            $('#erdo-rn-table').html('<p class="erdo-rn-error">' + esc(i18n.error) + '</p>');
        });
    }

    function renderRenameRows(rows) {
        if ( ! rows.length ) {
            $('#erdo-rn-table').html('<p class="erdo-rn-empty">' + esc(i18n.nothing) + '</p>');
            return;
        }
        var ok = 0;
        var html = '<table class="erdo-rn-list"><thead><tr><th>' + esc(i18n.current) + '</th><th>' + esc(i18n.newName) + '</th></tr></thead><tbody>';
        $.each(rows, function(_, r) {
            html += '<tr data-id="' + parseInt(r.id, 10) + '"' + ( r.error ? ' class="erdo-rn-row-error"' : '' ) + '>';
            html += '<td class="erdo-rn-old">' + esc(r.old) + '</td><td>';
            if ( r.error ) {
                html += '<span class="erdo-rn-error">' + esc(r.error) + '</span>';
            } else {
                ok++;
                var ext = r['new'].slice(r.stem.length);
                html += '<input type="text" class="erdo-input erdo-rn-stem" value="' + esc(r.stem) + '" aria-label="' + esc(i18n.newName) + ' — ' + esc(r.old) + '"><span class="erdo-rn-ext">' + esc(ext) + '</span>';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        $('#erdo-rn-table').html(html);
        $('#erdo-rn-apply').prop('disabled', ok === 0).text(fmt(i18n.apply, ok));
    }

    $(document).on('click', '#erdo-rn-preview', loadRenamePreview);
    $(document).on('keydown', '#erdo-rn-pattern, #erdo-rn-keyword', function(e) {
        if ( e.key === 'Enter' ) { e.preventDefault(); loadRenamePreview(); }
    });

    $(document).on('click', '#erdo-rn-apply', function() {
        var items = [];
        $('#erdo-rn-table tr[data-id]').each(function() {
            var $in = $(this).find('.erdo-rn-stem');
            if ( $in.length ) items.push([ $(this).data('id'), $.trim($in.val()) ]);
        });
        if ( ! items.length ) return;

        var total = items.length, done = 0, renamed = 0, batch = '';
        $('#erdo-rn-apply, #erdo-rn-preview').prop('disabled', true);
        $('#erdo-rn-table input').prop('disabled', true);

        function next() {
            var chunk = items.splice(0, 25);
            if ( ! chunk.length ) return finish();
            var data = { action: 'erdo_rename_apply', nonce: erdo_vars.nonce, batch: batch, items: {} };
            $.each(chunk, function(_, it) { data.items[it[0]] = it[1]; });
            $('#erdo-rn-status').text(fmt(i18n.renaming, done, total));
            $.post(erdo_vars.ajax_url, data, function(res) {
                if ( ! res || ! res.success ) return finish(true);
                batch = res.data.batch;
                $.each(res.data.results, function(_, r) {
                    var $row = $('#erdo-rn-table tr[data-id="' + parseInt(r.id, 10) + '"]');
                    if ( r.error ) {
                        $row.addClass('erdo-rn-row-error').find('td:last').html('<span class="erdo-rn-error">' + esc(r.error) + '</span>');
                    } else {
                        renamed++;
                        $row.addClass('erdo-rn-row-done').find('td:last').text(r.filename);
                    }
                });
                done += chunk.length;
                next();
            }).fail(function() { finish(true); });
        }

        function finish(failed) {
            var msg = fmt(i18n.done, renamed, total - renamed) + ( failed ? ' ' + i18n.error : '' );
            var $status = $('#erdo-rn-status').text(msg);
            if ( batch && renamed ) {
                $('<button type="button" class="button erdo-rn-undo"></button>').text(i18n.undo).attr('data-batch', batch).appendTo($status.parent());
            }
            $('#erdo-rn-apply').remove();
            clearSelection();
            clearGridCache();
            loadImages(true);
            loadStats();
        }

        next();
    });

    $(document).on('click', '.erdo-rn-undo', function() {
        var $btn = $(this).prop('disabled', true);
        $.post(erdo_vars.ajax_url, { action: 'erdo_rename_undo', nonce: erdo_vars.nonce, batch: $btn.data('batch') }, function(res) {
            $btn.remove();
            $('#erdo-rn-status').text(res && res.success ? fmt(i18n.undone, res.data.restored) : ( res && res.data && res.data.message ) || i18n.error);
            clearGridCache();
            loadImages(true);
            loadStats();
        });
    });

    // Detay paneli: tek görselin son yeniden adlandırmasını geri al
    $(document).on('click', '.erdo-undo-rename-btn', function() {
        var id = $(this).data('id');
        $(this).prop('disabled', true);
        $.post(erdo_vars.ajax_url, { action: 'erdo_rename_undo_last', nonce: erdo_vars.nonce, id: id }, function(res) {
            if ( ! res || ! res.success ) {
                alert(( res && res.data && res.data.message ) || i18n.error);
                return;
            }
            $('.erdo-card[data-id="' + id + '"] .erdo-card-info').text(res.data.filename);
            $.post(erdo_vars.ajax_url, { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce }, function(html) {
                $('#erdo-modal-content').html(html);
            });
            clearGridCache();
        });
    });

    /* -------------------------------------------------------
       AI ÖNERİLERİ (Faz 5)
       Öneri hiçbir zaman otomatik kaydedilmez: detay kutusunda ya da
       "AI Suggestions" sekmesinde incelenip kabul edilir.
    ------------------------------------------------------- */
    function reloadDetail(id) {
        $.post(erdo_vars.ajax_url, { action: 'erdo_get_detail', id: id, nonce: erdo_vars.nonce }, function(html) {
            $('#erdo-modal-content').html(html);
        });
    }

    function setAiCount(n) {
        $('#count-ai').text(n);
    }

    // Detay: öner / yeniden dene
    $(document).on('click', '.erdo-ai-suggest-btn', function() {
        var $btn = $(this), id = $btn.data('id');
        $btn.prop('disabled', true).html('<span class="erdo-spinner"></span> ' + esc(i18n.aiWorking));
        $.post(erdo_vars.ajax_url, { action: 'erdo_ai_suggest', nonce: erdo_vars.nonce, id: id }, function(res) {
            if ( res && res.success ) {
                setAiCount(parseInt($('#count-ai').text(), 10) + 1 || 1);
                reloadDetail(id);
            } else {
                alert(( res && res.data && res.data.message ) || i18n.error);
                reloadDetail(id);
            }
        }).fail(function() { alert(i18n.error); reloadDetail(id); });
    });

    // Detay: seçili alanları kabul et
    $(document).on('click', '.erdo-ai-accept-btn', function() {
        var $box = $(this).closest('.erdo-ai-box'), id = $box.data('id');
        var data = { action: 'erdo_ai_accept', nonce: erdo_vars.nonce, id: id, fields: [], edits: {} };
        $box.find('.erdo-ai-field:checked').each(function() { data.fields.push($(this).val()); });
        $box.find('.erdo-ai-value').each(function() { data.edits[$(this).data('field')] = $(this).val(); });
        if ( ! data.fields.length ) return;
        $box.find('button').prop('disabled', true);
        $.post(erdo_vars.ajax_url, data, function(res) {
            if ( ! res || ! res.success ) {
                alert(( res && res.data && res.data.message ) || i18n.error);
                $box.find('button').prop('disabled', false);
                return;
            }
            var $card = $('.erdo-card[data-id="' + id + '"]');
            if ( res.data.filename ) $card.find('.erdo-card-info').text(res.data.filename);
            if ( res.data.audit ) $card.find('.erdo-score-badge').text(res.data.audit.score);
            setAiCount(Math.max(0, parseInt($('#count-ai').text(), 10) - 1));
            clearGridCache();
            loadStats();
            reloadDetail(id);
        });
    });

    $(document).on('click', '.erdo-ai-reject-btn', function() {
        var id = $(this).closest('.erdo-ai-box').data('id');
        $.post(erdo_vars.ajax_url, { action: 'erdo_ai_reject', nonce: erdo_vars.nonce, id: id }, function() {
            setAiCount(Math.max(0, parseInt($('#count-ai').text(), 10) - 1));
            reloadDetail(id);
        });
    });

    // Sekme: inceleme listesi
    function loadAiList() {
        var $list = $('#erdo-ai-list');
        $list.html('<p class="erdo-rn-loading"><span class="erdo-spinner"></span></p>');
        $.post(erdo_vars.ajax_url, { action: 'erdo_ai_list', nonce: erdo_vars.nonce }, function(res) {
            if ( ! res || ! res.success ) { $list.html('<p class="erdo-rn-error">' + esc(i18n.error) + '</p>'); return; }
            var rows = res.data.rows;
            setAiCount(rows.length);
            $('#erdo-ai-accept-all').text(i18n.aiAcceptAll + ' (' + rows.length + ')').prop('disabled', ! rows.length);
            if ( ! rows.length ) { $list.html('<p class="erdo-rn-empty">' + esc(i18n.aiEmpty) + '</p>'); return; }
            var html = '';
            $.each(rows, function(_, r) {
                html += '<div class="erdo-ai-item" data-id="' + parseInt(r.id, 10) + '">';
                html += '<img src="' + esc(r.thumb) + '" alt="" loading="lazy">';
                html += '<div class="erdo-ai-item-body"><div class="erdo-ai-item-name">' + esc(r.filename) + '</div>';
                if ( r.current_alt ) html += '<div class="erdo-ai-item-current">' + esc(i18n.aiCurrent) + ': ' + esc(r.current_alt) + '</div>';
                if ( r.issue ) html += '<div class="erdo-ai-issue">' + esc(r.issue) + '</div>';
                html += r.decorative
                    ? '<em class="erdo-ai-decorative">' + esc(i18n.aiDecorative) + '</em>'
                    : '<input type="text" class="erdo-input erdo-ai-item-alt" value="' + esc(r.alt) + '" aria-label="Alt — ' + esc(r.filename) + '">';
                html += '</div><div class="erdo-ai-item-actions">';
                html += '<button type="button" class="button button-primary erdo-ai-item-accept">' + esc(i18n.aiAccept) + '</button>';
                html += '<button type="button" class="button erdo-ai-item-reject">' + esc(i18n.aiReject) + '</button>';
                html += '</div></div>';
            });
            $list.html(html);
        });
    }

    function acceptItem($item) {
        var id = $item.data('id'), $alt = $item.find('.erdo-ai-item-alt');
        var data = { action: 'erdo_ai_accept', nonce: erdo_vars.nonce, id: id, fields: ['alt'], edits: {} };
        if ( $alt.length ) data.edits.alt = $alt.val();
        $item.find('button, input').prop('disabled', true);
        return $.post(erdo_vars.ajax_url, data).done(function(res) {
            if ( res && res.success ) {
                $item.slideUp(150, function() { $item.remove(); });
                setAiCount(Math.max(0, parseInt($('#count-ai').text(), 10) - 1));
            } else {
                $item.find('button, input').prop('disabled', false);
            }
        });
    }

    $(document).on('click', '.erdo-ai-item-accept', function() {
        acceptItem($(this).closest('.erdo-ai-item')).always(function() { clearGridCache(); loadStats(); });
    });

    $(document).on('click', '.erdo-ai-item-reject', function() {
        var $item = $(this).closest('.erdo-ai-item');
        $.post(erdo_vars.ajax_url, { action: 'erdo_ai_reject', nonce: erdo_vars.nonce, id: $item.data('id') }, function() {
            $item.slideUp(150, function() { $item.remove(); });
            setAiCount(Math.max(0, parseInt($('#count-ai').text(), 10) - 1));
        });
    });

    $('#erdo-ai-accept-all').on('click', function() {
        var $items = $('#erdo-ai-list .erdo-ai-item').toArray(), $btn = $(this).prop('disabled', true);
        (function next() {
            var el = $items.shift();
            if ( ! el ) { clearGridCache(); loadStats(); loadAiList(); return; }
            acceptItem($(el)).always(next);
        })();
    });

    // Toplu üretim: kapsam seç → tahmin → başlat (tek tek, durdurulabilir, hız sınırında bekler)
    var aiRun = null;

    $('#erdo-ai-bulk-btn').on('click', function() {
        var st = erdo_vars.ai || {};
        var html = '<div class="modal-header"><h2>' + esc(i18n.aiStart) + '</h2></div><div class="erdo-rename-body">';
        if ( ! st.ready ) {
            html += '<p class="erdo-rn-safety">' + esc(st.message || '') + '</p>';
            if ( st.reason === 'provider' ) html += '<a class="button button-primary" href="' + esc(st.setup_url) + '">Settings &gt; Connectors</a>';
            openModal(html + '</div>');
            return;
        }
        html += '<div class="erdo-rn-fields"><label><select id="erdo-ai-scope" class="erdo-input">';
        html += '<option value="missing">' + esc(i18n.aiMissingAlt) + '</option><option value="weak">' + esc(i18n.aiWeakAlt) + '</option></select></label></div>';
        html += '<p class="erdo-rn-safety" id="erdo-ai-estimate"><span class="erdo-spinner"></span></p>';
        html += '<div class="erdo-rn-actions"><button type="button" class="button button-primary" id="erdo-ai-run" disabled>' + esc(i18n.aiStart) + '</button>';
        html += '<button type="button" class="button hidden" id="erdo-ai-stop">' + esc(i18n.aiStop) + '</button><span id="erdo-ai-status" role="status"></span></div></div>';
        openModal(html);
        loadAiEstimate();
    });

    var aiIds = [];
    function loadAiEstimate() {
        $('#erdo-ai-run').prop('disabled', true);
        $.post(erdo_vars.ajax_url, { action: 'erdo_ai_candidates', nonce: erdo_vars.nonce, scope: $('#erdo-ai-scope').val() }, function(res) {
            if ( ! res || ! res.success ) { $('#erdo-ai-estimate').text(i18n.error); return; }
            aiIds = res.data.ids;
            $('#erdo-ai-estimate').text(aiIds.length ? fmt(i18n.aiEstimate, aiIds.length).replace('%2$s', res.data.low).replace('%3$s', res.data.high) : i18n.aiNone);
            $('#erdo-ai-run').prop('disabled', ! aiIds.length);
        });
    }
    $(document).on('change', '#erdo-ai-scope', loadAiEstimate);

    $(document).on('click', '#erdo-ai-run', function() {
        var queue = aiIds.slice(), total = queue.length, done = 0, ok = 0, failed = 0, wait = 0;
        aiRun = { stop: false };
        var run = aiRun;
        $('#erdo-ai-run, #erdo-ai-scope').prop('disabled', true);
        $('#erdo-ai-stop').removeClass('hidden');

        (function next() {
            if ( run.stop || ! queue.length ) {
                $('#erdo-ai-stop').addClass('hidden');
                $('#erdo-ai-status').text(fmt(i18n.aiDone, ok, failed));
                setAiCount(parseInt($('#count-ai').text(), 10) + ok);
                return;
            }
            $('#erdo-ai-status').text(fmt(i18n.aiProgress, done, total));
            var id = queue[0];
            $.post(erdo_vars.ajax_url, { action: 'erdo_ai_suggest', nonce: erdo_vars.nonce, id: id }, function(res) {
                if ( res && ! res.success && res.data && res.data.retry && wait < 5 ) {
                    // Hız sınırı: artan beklemeyle aynı görseli yeniden dene.
                    wait++;
                    $('#erdo-ai-status').text(res.data.message);
                    setTimeout(next, 5000 * wait);
                    return;
                }
                wait = 0;
                queue.shift();
                done++;
                if ( res && res.success ) { ok++; } else { failed++; }
                next();
            }).fail(function() { queue.shift(); done++; failed++; next(); });
        })();
    });

    $(document).on('click', '#erdo-ai-stop', function() {
        if ( aiRun ) aiRun.stop = true;
        $(this).prop('disabled', true);
    });

});  // jQuery ready END
