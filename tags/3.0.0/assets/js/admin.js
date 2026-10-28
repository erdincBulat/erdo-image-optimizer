/* Erdo Image Optimizer — admin UI (3.0). jQuery, no build step. */
jQuery(function($) {
    var V = window.erdo_vars || {};
    var S = ( V.i18n && V.i18n.s ) || {};

    /** English source string → translation from PHP (__()); falls back to the source. */
    function t(str) {
        return S[str] || str;
    }

    /** printf-lite: %d / %s in order, or %1$d / %2$s by position. */
    function fmt(str) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(str || '').replace(/%(\d)\$[ds]|%[ds]/g, function(m, pos) {
            return pos ? args[pos - 1] : args[i++];
        });
    }

    function esc(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Singular / plural by count: tn(t('%d image'), t('%d images'), n). */
    function tn(one, many, n) {
        return +n === 1 ? one : many;
    }

    function num(n) {
        return Number(n || 0).toLocaleString();
    }

    function bytes(b) {
        b = Number(b || 0);
        if ( b < 1024 ) return b + ' B';
        var u = ['KB', 'MB', 'GB'], i = -1;
        do { b /= 1024; i++; } while ( b >= 1024 && i < u.length - 1 );
        return ( b >= 100 ? Math.round(b) : b.toFixed(1) ) + ' ' + u[i];
    }

    function post(action, data) {
        return $.post(V.ajax_url, $.extend({ action: action, nonce: V.nonce }, data || {}));
    }

    function errMsg(res) {
        return ( res && res.data && res.data.message ) || t('Something went wrong. Please try again.');
    }

    function store(key, val) {
        try {
            if ( val === undefined ) return window.localStorage.getItem(key);
            window.localStorage.setItem(key, val);
        } catch (e) {}
        return null;
    }

    /* =======================================================================
       DIALOG — every bulk action uses the same window:
       preview → confirm → progress → result (+ undo)
    ======================================================================= */
    var Dlg = (function() {
        var $wrap = $('#erdo-dialog-wrap'), $box = $('#erdo-dialog'), opts = {}, lastFocus = null;

        function render() {
            var o = opts, h = '';
            h += '<div class="erdo-dialog-h">';
            if ( o.icon ) h += '<span class="erdo-bub is-' + esc(o.tone || 'info') + '"><span class="dashicons dashicons-' + esc(o.icon) + '" aria-hidden="true"></span></span>';
            h += '<div><h2 id="erdo-dialog-title">' + esc(o.title) + '</h2>';
            if ( o.sub ) h += '<p class="erdo-muted erdo-small erdo-flush">' + esc(o.sub) + '</p>';
            h += '</div></div>';
            if ( o.steps ) {
                h += '<ol class="erdo-steps">';
                $.each(o.steps, function(i, s) {
                    h += '<li class="' + ( i < o.step ? 'is-done' : ( i === o.step ? 'is-active' : '' ) ) + '">' + esc(s) + '</li>';
                });
                h += '</ol>';
            }
            h += '<div class="erdo-dialog-b">' + ( o.body || '' ) + '</div>';
            h += '<div class="erdo-dialog-f">';
            $.each(o.actions || [], function(i, a) {
                var cls = a.link ? 'button-link' : ( 'button' + ( a.primary ? ' button-primary' : '' ) + ( a.danger ? ' erdo-btn-danger' : '' ) );
                h += '<button type="button" class="' + cls + '" data-i="' + i + '"' + ( a.disabled ? ' disabled' : '' ) + '>' + esc(a.label) + '</button>';
            });
            h += '</div>';
            $box.html(h).toggleClass('is-wide', !! o.wide);
        }

        $box.on('click', '.erdo-dialog-f [data-i]', function() {
            var a = ( opts.actions || [] )[ $(this).data('i') ];
            if ( ! a ) return;
            if ( a.click ) a.click($(this)); else close();
        });

        function open(o) {
            if ( $wrap.prop('hidden') ) lastFocus = document.activeElement;
            opts = o;
            render();
            $wrap.prop('hidden', false);
            $('body').addClass('erdo-modal-open');
            setTimeout(function() {
                var $f = $box.find('input:not([type=hidden]), select, textarea').filter(':visible').first();
                ( $f.length ? $f : $box.find('.button-primary:enabled').first() ).trigger('focus');
                if ( ! $box.find(':focus').length ) $box.trigger('focus');
            }, 20);
        }

        function update(o) {
            opts = $.extend(opts, o);
            render();
        }

        function close() {
            if ( opts.busy ) return;
            $wrap.prop('hidden', true);
            if ( ! $('#erdo-drawer-overlay').is(':visible') ) $('body').removeClass('erdo-modal-open');
            if ( opts.onClose ) opts.onClose();
            opts = {};
            if ( lastFocus && document.contains(lastFocus) ) lastFocus.focus();
        }

        $wrap.on('mousedown', function(e) { if ( e.target === this ) close(); });

        return { open: open, update: update, close: close, isOpen: function() { return ! $wrap.prop('hidden'); }, $box: $box, opts: function() { return opts; } };
    })();

    function kv(rows) {
        var h = '<dl class="erdo-kv">';
        $.each(rows, function(_, r) { h += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; });
        return h + '</dl>';
    }

    function progress(done, total, note) {
        var pct = total ? Math.round(done / total * 100) : 0;
        return '<div class="erdo-prog" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' + pct + '"><i style="width:' + pct + '%"></i></div>' +
            '<p class="erdo-prog-text"><span>' + esc(fmt(t('%1$d of %2$d'), done, total)) + '</span>' + ( note ? '<span class="erdo-muted">' + esc(note) + '</span>' : '' ) + '</p>';
    }

    /** Keeps Tab inside an open modal (drawer or dialog). */
    function trapFocus(e, $root) {
        if ( e.key !== 'Tab' ) return;
        var $f = $root.find('a[href], button:enabled, input:enabled:not([type=hidden]), select:enabled, textarea:enabled, [tabindex="0"]').filter(':visible');
        if ( ! $f.length ) return;
        var first = $f[0], last = $f[$f.length - 1];
        if ( e.shiftKey && document.activeElement === first ) { last.focus(); e.preventDefault(); }
        else if ( ! e.shiftKey && document.activeElement === last ) { first.focus(); e.preventDefault(); }
    }

    /* =======================================================================
       FIRST-RUN SETUP WIZARD
    ======================================================================= */
    if ( $('#erdo-setup').length ) {
        var step = 1;
        var $next = $('#erdo-setup-next'), $back = $('#erdo-setup-back');

        function goStep(n) {
            step = n;
            $('.erdo-wiz-pane').each(function() { $(this).prop('hidden', +$(this).data('step') !== n); });
            $('.erdo-wiz-steps li').each(function() {
                var s = +$(this).data('step');
                $(this).toggleClass('is-active', s === n).toggleClass('is-done', s < n);
            });
            $back.prop('hidden', n !== 2);
            $next.text(n === 2 ? t('Use these settings and scan') : t('Continue'));
            $next.prop('hidden', n === 3);
            $('#erdo-setup-skip').prop('hidden', n === 3);
        }

        var icons = { ok: 'yes-alt', bad: 'dismiss', warn: 'warning', info: 'info-outline' };
        post('erdo_setup_check').done(function(res) {
            if ( ! res || ! res.success ) { $('#erdo-setup-checks').html('<li>' + esc(errMsg(res)) + '</li>'); return; }
            var h = '';
            $.each(res.data.checks, function(_, c) {
                h += '<li class="is-' + esc(c.state) + '"><span class="dashicons dashicons-' + icons[c.state] + '" aria-hidden="true"></span>' +
                    '<div><b>' + esc(c.title) + '</b><p>' + c.text + '</p></div><span class="erdo-pill is-' + esc(c.state) + '">' + esc(c.badge) + '</span></li>';
            });
            $('#erdo-setup-checks').html(h);
            $('#erdo-setup-rec').html($.map(res.data.rec, function(r) { return '<li>' + r + '</li>'; }).join(''));
            $next.prop('disabled', false).trigger('focus');
        }).fail(function() { $('#erdo-setup-checks').html('<li>' + esc(t('Something went wrong. Please try again.')) + '</li>'); });

        function scan() {
            post('erdo_usage_build_step').done(function(res) {
                var d = res && res.success ? res.data : null;
                if ( ! d ) { setTimeout(scan, 3000); return; }
                var pct = d.total ? Math.round(d.done / d.total * 100) : 100;
                $('#erdo-setup-prog').css('width', ( d.status === 'ready' ? 100 : Math.min(pct, 95) ) + '%');
                $('#erdo-setup-scan-text').text(fmt(t('Finding where each image is used: %1$d / %2$d posts checked.'), d.done, d.total));
                if ( d.status === 'ready' ) { window.location.href = V.page_url; return; }
                scan();
            }).fail(function() { setTimeout(scan, 3000); });
        }

        $next.on('click', function() {
            if ( step === 1 ) { goStep(2); return; }
            $next.prop('disabled', true);
            post('erdo_setup_apply', { apply: 1 }).done(function() { goStep(3); scan(); });
        });
        $back.on('click', function() { goStep(1); });
        $('#erdo-setup-skip').on('click', function() {
            post('erdo_setup_apply', { apply: 0 }).always(function() { window.location.href = V.page_url; });
        });
        return;
    }

    if ( ! $('#erdo-app').length ) return;

    /* =======================================================================
       STATE
    ======================================================================= */
    var CACHE_KEY = 'erdo_lib_v4';     // bump when the item shape changes
    var PAGE_SIZE = 200;               // cards rendered per "Show more"
    var items = [], byId = {}, loaded = false, loading = false, stats = null, libVersion = null;
    var filter = { cat: '', issue: '', q: '', sort: 'score' };
    var shown = PAGE_SIZE, selected = {}, drawerList = [], drawerId = 0;
    var ISSUE_LABELS = {
        alt: t('No alt'), weakalt: t('Weak alt'), filename: t('Weak file name'),
        format: t('No WebP'), oversized: t('Oversized'), context: t('Not indexable')
    };

    function setItems(list) {
        items = list;
        byId = {};
        $.each(items, function(_, it) { byId[it.id] = it; });
    }

    function saveCache() {
        try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ version: libVersion, items: items })); } catch (e) {}
    }

    function readCache() {
        try { return JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null'); } catch (e) { return null; }
    }

    function clearCache() {
        try { sessionStorage.removeItem(CACHE_KEY); } catch (e) {}
    }

    /* =======================================================================
       TABS
    ======================================================================= */
    function showTab(tab) {
        $('#erdo-app').attr('data-tab', tab);
        $('.erdo-panel').each(function() { $(this).prop('hidden', $(this).data('panel') !== tab); });
        $('.erdo-nav-link').each(function() {
            var on = $(this).data('tab') === tab;
            $(this).toggleClass('is-active', on);
            on ? $(this).attr('aria-current', 'page') : $(this).removeAttr('aria-current');
        });
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            window.history.replaceState(null, '', url.toString());
        } catch (e) {}
        $('#erdo-selbar').toggleClass('hidden', tab !== 'library' || ! selectedIds().length);
        if ( tab === 'ai' ) loadAiList();
        if ( tab === 'cleanup' && $('#erdo-cl-tab-q').attr('aria-selected') === 'true' ) loadQuarantine();
    }

    $('.erdo-nav').on('click', '.erdo-nav-link', function(e) {
        e.preventDefault();
        showTab($(this).data('tab'));
    });

    /* =======================================================================
       STATS + LIBRARY LOADING
    ======================================================================= */
    /** accept=true: the change was already applied locally (refreshItems), so take the new version without reloading. */
    function loadStats(accept) {
        return post('erdo_get_stats').done(function(res) {
            if ( ! res || ! res.success ) return;
            stats = res.data;
            var cache = readCache();
            if ( accept === true && loaded ) {
                libVersion = stats.library_version;
                saveCache();
            } else if ( libVersion === null && cache && +cache.version === +stats.library_version && cache.items ) {
                libVersion = stats.library_version;
                setItems(cache.items);
                loaded = true;
                renderAll();
            } else if ( libVersion === null || +libVersion !== +stats.library_version ) {
                libVersion = stats.library_version;
                loadAll();
            }
            $('#erdo-count-ai').text(num(stats.ai_pending));
            $('#erdo-count-q').text(num(stats.quarantine));
            renderOverview();
            renderActivity(stats.activity);
        });
    }

    function loadAll() {
        if ( loading ) return;
        loading = true;
        loaded = false;
        var page = 1, acc = [];
        $('#erdo-lib-loading').removeClass('hidden');
        $('#erdo-retry-wrap').addClass('hidden');

        (function next() {
            post('erdo_load_images', { page: page }).done(function(res) {
                if ( ! res || ! res.success ) return fail();
                acc = acc.concat(res.data.items);
                $('#erdo-lib-loading-text').text(fmt(t('%1$d / %2$d images scanned'), Math.min(acc.length, res.data.found), res.data.found));
                if ( page < res.data.max_pages ) {
                    page++;
                    if ( page % 5 === 0 ) { setItems(acc); renderAll(); }
                    next();
                    return;
                }
                setItems(acc);
                loaded = true;
                loading = false;
                $('#erdo-lib-loading').addClass('hidden');
                saveCache();
                renderAll();
            }).fail(fail);
        })();

        function fail() {
            loading = false;
            $('#erdo-lib-loading').addClass('hidden');
            $('#erdo-retry-wrap').removeClass('hidden');
        }
    }

    $('#erdo-retry-btn').on('click', loadAll);

    /** Re-reads a few images after a change (score, name, quarantine…). */
    function refreshItems(ids) {
        ids = $.grep($.makeArray(ids), function(id) { return id; });
        if ( ! ids.length ) return $.Deferred().resolve().promise();
        if ( ids.length > 100 ) { // too many to patch one by one: reload everything
            clearCache();
            libVersion = null;
            return loadStats();
        }
        return post('erdo_load_images', { ids: ids }).done(function(res) {
            if ( ! res || ! res.success ) return;
            $.each(res.data.items, function(_, it) {
                if ( byId[it.id] ) {
                    items[ items.indexOf(byId[it.id]) ] = it;
                } else {
                    items.unshift(it);
                }
                byId[it.id] = it;
            });
            $.each(res.data.gone, function(_, id) {
                if ( byId[id] ) { items.splice(items.indexOf(byId[id]), 1); delete byId[id]; delete selected[id]; }
            });
            renderAll();
            loadStats(true);
        });
    }

    /* =======================================================================
       RENDERING
    ======================================================================= */
    function catOf(it) { return it.cat; }

    function matches(it) {
        if ( filter.cat && catOf(it) !== filter.cat ) return false;
        if ( filter.issue && it.issues.indexOf(filter.issue) < 0 ) return false;
        if ( filter.q ) {
            var hay = ( it.file + ' ' + it.title + ' ' + it.alt ).toLowerCase();
            if ( hay.indexOf(filter.q) < 0 ) return false;
        }
        return true;
    }

    function sorted(list) {
        var f = {
            score: function(a, b) { return ( a.cat === 'gray' ) - ( b.cat === 'gray' ) || a.score - b.score || b.id - a.id; },
            'new': function(a, b) { return b.id - a.id; },
            size: function(a, b) { return b.bytes - a.bytes; },
            name: function(a, b) { return a.file.localeCompare(b.file); }
        }[filter.sort];
        return list.slice().sort(f);
    }

    function filtered() {
        return sorted($.grep(items, matches));
    }

    function scoreBadge(it) {
        return '<span class="erdo-score is-' + esc(it.cat) + '">' + ( it.cat === 'gray' ? '—' : it.score ) + '</span>';
    }

    function tileHtml(it) {
        var sel = !! selected[it.id];
        var tags = '<span class="erdo-tag' + ( it.modern ? ' is-fmt' : '' ) + '">' + esc(it.fmt) + '</span>';
        $.each(it.issues.slice(0, 2), function(_, k) { tags += '<span class="erdo-tag">' + esc(ISSUE_LABELS[k] || k) + '</span>'; });
        return '<div class="erdo-tile' + ( sel ? ' is-selected' : '' ) + '" data-id="' + it.id + '" tabindex="0" role="button" aria-label="' +
            esc(it.file + ' — ' + ( it.cat === 'gray' ? t('Unused') : fmt(t('score %d'), it.score) )) + '">' +
            '<div class="erdo-thumb"><img src="' + esc(it.thumb) + '" alt="" loading="lazy">' +
            '<button type="button" class="erdo-check" role="checkbox" aria-checked="' + sel + '" aria-label="' + esc(t('Select image')) + '" tabindex="-1"><span class="dashicons dashicons-yes" aria-hidden="true"></span></button>' +
            scoreBadge(it) + '</div>' +
            '<div class="erdo-tmeta"><div class="erdo-tname" title="' + esc(it.file) + '">' + esc(it.file) + '</div><div class="erdo-tags">' + tags + '</div></div></div>';
    }

    function rowHtml(it) {
        var sel = !! selected[it.id];
        var alt = it.issues.indexOf('alt') > -1 ? '<span class="erdo-pill is-red">' + esc(t('Missing')) + '</span>' : esc(it.alt);
        return '<tr class="erdo-row' + ( sel ? ' is-selected' : '' ) + '" data-id="' + it.id + '">' +
            '<td class="erdo-col-check"><input type="checkbox" class="erdo-row-check"' + ( sel ? ' checked' : '' ) + ' aria-label="' + esc(t('Select image')) + '"></td>' +
            '<td class="erdo-col-thumb"><img src="' + esc(it.thumb) + '" alt="" loading="lazy"></td>' +
            '<td><button type="button" class="button-link erdo-open">' + esc(it.file) + '</button></td>' +
            '<td class="erdo-col-alt">' + alt + '</td><td>' + scoreBadge(it) + '</td>' +
            '<td>' + esc(it.fmt) + '</td><td>' + ( it.bytes ? esc(bytes(it.bytes)) : '—' ) + '</td></tr>';
    }

    function renderLibrary() {
        var list = filtered(), view = store('erdo_view') || 'grid', page = list.slice(0, shown);
        if ( view === 'list' ) {
            $('#erdo-list tbody').html($.map(page, rowHtml).join(''));
        } else {
            $('#erdo-grid').html($.map(page, tileHtml).join(''));
        }
        $('#erdo-grid').toggleClass('hidden', view !== 'grid' || ! list.length);
        $('#erdo-list').toggleClass('hidden', view !== 'list' || ! list.length);
        $('#erdo-empty').toggleClass('hidden', ! loaded || list.length > 0);
        $('#erdo-more-wrap').toggleClass('hidden', list.length <= shown);
        $('#erdo-more-btn').text(fmt(t('Show more (%d left)'), list.length - shown));
    }

    function renderCounts() {
        var c = { '': items.length, red: 0, orange: 0, green: 0, gray: 0 };
        $.each(items, function(_, it) { c[it.cat]++; });
        $('.erdo-chip-n').each(function() { $(this).text(num(c[ $(this).data('n') ])); });
        $('#erdo-count-library').text(loaded ? num(items.length) : '…');
        $('#erdo-count-cleanup, #erdo-count-unused').text(loaded ? num(c.gray) : '…');
        return c;
    }

    function unusedItems() {
        return $.grep(items, function(it) { return it.cat === 'gray'; });
    }

    function indexReady() {
        return $('#erdo-index-notice').data('status') === 'ready';
    }

    function renderCleanup() {
        var list = unusedItems(), size = 0;
        $.each(list, function(_, it) { size += it.bytes; });
        $('#erdo-unused-grid').html($.map(list.slice(0, 400), tileHtml).join(''));
        $('#erdo-unused-empty').toggleClass('hidden', ! loaded || list.length > 0);
        $('#erdo-unused-summary').text(loaded ? fmt(tn(t('%1$d image · %2$s'), t('%1$d images · %2$s'), list.length), list.length, bytes(size)) : '…');
        var ready = indexReady();
        $('#erdo-quarantine-all').prop('disabled', ! loaded || ! list.length || ! ready)
            .attr('title', ready ? '' : t('Available when the usage index is ready.'));
        $('#erdo-quarantine-all-text').text(list.length ? fmt(t('Move %d to quarantine…'), list.length) : t('Move to quarantine…'));
    }

    function renderAll() {
        $('#erdo-lib-loading').toggleClass('hidden', loaded && ! loading);
        renderCounts();
        renderLibrary();
        renderCleanup();
        renderOverview();
        updateSelBar();
    }

    /* =======================================================================
       OVERVIEW
    ======================================================================= */
    function renderOverview() {
        if ( stats ) {
            var health = Math.round(( stats.seo_score + stats.opt_score ) / 2);
            var tone = health >= 80 ? 'green' : ( health >= 50 ? 'orange' : 'red' );
            $('#erdo-health-ring').css('--v', health).attr('data-tone', tone);
            $('#erdo-health-score').text(health);
            $('#erdo-health-label').text({ green: t('Image health: good'), orange: t('Image health: fair'), red: t('Image health: poor') }[tone]);
            $('#erdo-m-seo').text(stats.seo_score);
            $('#erdo-m-seo-bar').css('width', stats.seo_score + '%');
            $('#erdo-m-cov').text(stats.opt_score + '%');
            $('#erdo-m-cov-bar').css('width', stats.opt_score + '%');
            $('#erdo-m-cov-sub').text(fmt(t('%1$d of %2$d images'), stats.optimized, stats.total));
            $('#erdo-m-saved').text(stats.total_saved);
            $('#erdo-m-unused-sub').text(fmt(t('%d in quarantine'), stats.quarantine));
        }
        if ( ! loaded ) return;

        var used = $.grep(items, function(it) { return it.cat !== 'gray'; });
        var low = $.grep(used, function(it) { return it.score < 50; }).length;
        $('#erdo-m-seo-sub').text(fmt(tn(t('%d image below 50'), t('%d images below 50'), low), low));
        $('#erdo-m-unused').text(num(unusedItems().length));

        var by = function(k, pool) { return $.grep(pool || items, function(it) { return it.issues.indexOf(k) > -1; }); };
        var tasks = [];
        var alt = by('alt', used), names = by('filename', used), conv = by('format'), big = by('oversized', used), unused = unusedItems();
        var ai = V.ai && V.ai.ready;

        var weak = by('weakalt', used), altAll = alt.concat(weak);
        if ( altAll.length ) {
            var altActs = [];
            // AI also rewrites weak alt texts; file names only fill empty ones (never overwrite).
            if ( ai ) altActs.push({ label: t('Suggest with AI'), primary: true, icon: 'lightbulb', run: function() { flowAi(ids(altAll)); } });
            if ( alt.length ) altActs.push({ label: t('From file names'), primary: ! ai, run: function() { flowAlt(ids(alt)); } });
            altActs.push({ label: t('Review'), run: function() { goFilter(alt.length ? 'alt' : 'weakalt'); } });
            tasks.push({ n: altAll.length, tone: 'red', title: weak.length && ! alt.length ? t('Images with weak alt text') : ( weak.length ? t('Images with missing or weak alt text') : t('Images missing alt text') ),
                text: t('Alt text is 40% of the score and what screen readers read aloud.'), actions: altActs });
        }
        if ( names.length ) tasks.push({ n: names.length, tone: 'orange', title: t('File names to improve'), text: $.map(names.slice(0, 3), function(it) { return it.file; }).join(', ') + ( names.length > 3 ? ', …' : '' ),
            actions: [ { label: t('Preview new names'), primary: true, run: function() { flowRename(ids(names), 'fix'); } } ] });
        if ( conv.length ) tasks.push({ n: conv.length, tone: 'blue', title: t('Images without a WebP/AVIF copy'), text: t('Originals stay untouched; copies are kept only when they are smaller.'),
            actions: [ { label: t('Convert in background'), primary: true, run: function() { flowConvert(ids(conv)); } } ] });
        if ( big.length ) tasks.push({ n: big.length, tone: 'orange', title: t('Oversized images'), text: t('Larger than 2560 px or heavier than 250 KB when served.'),
            actions: [ { label: t('Review'), run: function() { goFilter('oversized'); } } ] });
        if ( unused.length ) tasks.push({ n: unused.length, tone: 'gray', title: t('Unused images'), text: indexReady() ? t('Not found in content, settings, widgets or terms.') : t('Available when the usage index is ready.'),
            actions: [ { label: t('Review and quarantine'), run: function() { showTab('cleanup'); } } ] });

        var $t = $('#erdo-tasks').empty();
        if ( ! tasks.length ) {
            $t.html('<p class="erdo-all-clear"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' + esc(t('Nothing to fix right now. New uploads are checked automatically.')) + '</p>');
            return;
        }
        $.each(tasks, function(ti, k) {
            var $row = $('<div class="erdo-task"><div class="erdo-task-n is-' + k.tone + '">' + num(k.n) + '</div><div class="erdo-task-t"><b>' + esc(k.title) + '</b><span>' + esc(k.text) + '</span></div><div class="erdo-task-a"></div></div>');
            $.each(k.actions, function(_, a) {
                // Only the most important task gets a primary button.
                $('<button type="button" class="button' + ( a.primary && ti === 0 ? ' button-primary' : '' ) + '"></button>')
                    .html(( a.icon ? '<span class="dashicons dashicons-' + a.icon + '" aria-hidden="true"></span>' : '' ) + esc(a.label))
                    .on('click', a.run).appendTo($row.find('.erdo-task-a'));
            });
            $t.append($row);
        });
    }

    function ids(list) {
        return $.map(list, function(it) { return it.id; });
    }

    function goFilter(issue) {
        filter.issue = issue;
        filter.cat = '';
        $('#erdo-issue').val(issue);
        $('.erdo-chip').attr('aria-pressed', function() { return $(this).data('cat') === '' ? 'true' : 'false'; });
        shown = PAGE_SIZE;
        renderLibrary();
        showTab('library');
    }

    function renderActivity(list) {
        var $ul = $('#erdo-activity').empty();
        if ( ! list || ! list.length ) {
            $ul.html('<li class="erdo-muted">' + esc(t('Bulk actions you run here will show up in this list.')) + '</li>');
            return;
        }
        $.each(list, function(_, e) {
            var $li = $('<li></li>').append($('<span></span>').text(e.text)).append(' <span class="erdo-faint">· ' + esc(e.when) + '</span>');
            if ( e.undo ) {
                var label = e.undo.kind === 'quarantine' ? t('Open quarantine') : t('Undo');
                $('<button type="button" class="button-link"></button>').text(label).on('click', function() { undoActivity(e.undo, $(this)); }).appendTo($li.append(' '));
            }
            $ul.append($li);
        });
    }

    function undoActivity(u, $btn) {
        if ( u.kind === 'quarantine' ) { showTab('cleanup'); $('#erdo-cl-tab-q').trigger('click'); return; }
        $btn.prop('disabled', true);
        var req = u.kind === 'rename' ? post('erdo_rename_undo', { batch: u.batch }) : post('erdo_queue_undo');
        req.done(function(res) {
            if ( ! res || ! res.success ) { Dlg.open({ icon: 'warning', tone: 'danger', title: t('Could not undo'), body: '<p>' + esc(errMsg(res)) + '</p>', actions: [ { label: t('Close'), primary: true } ] }); return; }
            clearCache();
            libVersion = null;
            loadStats();
        });
    }

    $('#erdo-run-setup').on('click', function() {
        window.location.href = V.page_url + '&tab=setup';
    });

    /* =======================================================================
       LIBRARY CONTROLS
    ======================================================================= */
    var searchTimer;
    $('#erdo-search').on('input search', function() {
        var v = $.trim($(this).val()).toLowerCase();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { filter.q = v; shown = PAGE_SIZE; renderLibrary(); }, 150);
    });
    $('#erdo-issue').on('change', function() { filter.issue = $(this).val(); shown = PAGE_SIZE; renderLibrary(); });
    $('#erdo-sort').on('change', function() { filter.sort = $(this).val(); renderLibrary(); });
    $('.erdo-chips').on('click', '.erdo-chip', function() {
        filter.cat = $(this).data('cat');
        $('.erdo-chip').attr('aria-pressed', 'false');
        $(this).attr('aria-pressed', 'true');
        shown = PAGE_SIZE;
        renderLibrary();
    });
    $('#erdo-more-btn').on('click', function() { shown += PAGE_SIZE; renderLibrary(); });

    function applyView(view) {
        store('erdo_view', view);
        $('[data-view]').attr('aria-pressed', function() { return $(this).data('view') === view ? 'true' : 'false'; });
        $('.erdo-size-seg').toggleClass('hidden', view !== 'grid');
        renderLibrary();
    }
    $('[data-view]').on('click', function() { applyView($(this).data('view')); });

    function applySize(size) {
        store('erdo_grid_size', size);
        $('.erdo-grid').attr('data-size', size);
        $('[data-size]').filter('button').attr('aria-pressed', function() { return $(this).data('size') === size ? 'true' : 'false'; });
    }
    $('.erdo-size-seg').on('click', 'button', function() { applySize($(this).data('size')); });
    applySize(store('erdo_grid_size') || 'medium');
    $('[data-view]').attr('aria-pressed', function() { return $(this).data('view') === ( store('erdo_view') || 'grid' ) ? 'true' : 'false'; });
    $('.erdo-size-seg').toggleClass('hidden', ( store('erdo_view') || 'grid' ) !== 'grid');

    // "More" dropdown: closes on outside click (same pattern as every dropdown here).
    $('#erdo-tools-btn').on('click', function(e) {
        e.stopPropagation();
        var open = ! $('#erdo-tools-dropdown').hasClass('open');
        $('#erdo-tools-dropdown').toggleClass('open', open);
        $(this).attr('aria-expanded', open);
    });
    $(document).on('click', function() {
        $('#erdo-tools-dropdown').removeClass('open');
        $('#erdo-tools-btn').attr('aria-expanded', 'false');
    });

    /* =======================================================================
       SELECTION
    ======================================================================= */
    function selectedIds() {
        return $.map(selected, function(v, id) { return +id; });
    }

    function setSelected(id, on) {
        on ? ( selected[id] = true ) : delete selected[id];
        $('.erdo-tile[data-id="' + id + '"]').toggleClass('is-selected', on).find('.erdo-check').attr('aria-checked', on);
        $('.erdo-row[data-id="' + id + '"]').toggleClass('is-selected', on).find('.erdo-row-check').prop('checked', on);
        updateSelBar();
    }

    function clearSelection() {
        $.each(selectedIds(), function(_, id) { setSelected(id, false); });
    }

    function updateSelBar() {
        var n = selectedIds().length;
        $('#erdo-sel-count').text(fmt(t('%d selected'), n));
        $('#erdo-selbar').toggleClass('hidden', n === 0 || $('#erdo-app').attr('data-tab') !== 'library');
    }

    $('#erdo-sel-clear').on('click', clearSelection);
    $('#erdo-sel-rename').on('click', function() { flowRename(selectedIds(), 'pattern'); });
    $('#erdo-sel-convert').on('click', function() { flowConvert(selectedIds()); });
    $('#erdo-sel-quarantine').on('click', function() { flowQuarantine(selectedIds()); });
    $('#erdo-sel-ai').on('click', function() { flowAi(selectedIds()); });

    // Tile: checkbox, Ctrl/Cmd+click or any click while something is selected toggles; otherwise opens details.
    $(document).on('click', '.erdo-tile', function(e) {
        var id = +$(this).data('id');
        var inLibrary = !! $(this).closest('#erdo-grid').length;
        if ( inLibrary && ( $(e.target).closest('.erdo-check').length || e.ctrlKey || e.metaKey || selectedIds().length ) ) {
            setSelected(id, ! selected[id]);
            return;
        }
        openDrawer(id, inLibrary ? ids(filtered()) : ids(unusedItems()));
    });
    $(document).on('keydown', '.erdo-tile', function(e) {
        if ( e.key === 'Enter' ) { e.preventDefault(); $(this).trigger('click'); }
        if ( e.key === ' ' && $(this).closest('#erdo-grid').length ) { e.preventDefault(); setSelected(+$(this).data('id'), ! selected[ $(this).data('id') ]); }
    });
    $('#erdo-list').on('change', '.erdo-row-check', function() {
        setSelected(+$(this).closest('tr').data('id'), this.checked);
    });
    $('#erdo-list').on('click', '.erdo-open', function() {
        openDrawer(+$(this).closest('tr').data('id'), ids(filtered()));
    });

    /* =======================================================================
       DETAIL DRAWER
    ======================================================================= */
    var $ov = $('#erdo-drawer-overlay'), $dr = $('#erdo-drawer'), drawerReturn = null;

    function openDrawer(id, list) {
        if ( $ov.prop('hidden') ) drawerReturn = document.activeElement;
        drawerId = id;
        drawerList = list || [];
        $dr.html('<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Loading...')) + '</p>');
        $ov.prop('hidden', false);
        $('body').addClass('erdo-modal-open');
        $dr.trigger('focus');
        loadDetail(id);
    }

    function loadDetail(id) {
        return $.post(V.ajax_url, { action: 'erdo_get_detail', id: id, nonce: V.nonce }).done(function(html) {
            if ( typeof html !== 'string' ) { $dr.html('<p class="erdo-loading-row">' + esc(errMsg(html)) + '</p>'); return; }
            if ( id !== drawerId ) return;
            $dr.html(html);
            var i = drawerList.indexOf(id);
            $('#erdo-dr-prev').prop('disabled', i <= 0);
            $('#erdo-dr-next').prop('disabled', i < 0 || i >= drawerList.length - 1);
        });
    }

    function closeDrawer() {
        $ov.prop('hidden', true);
        $dr.empty();
        $('body').removeClass('erdo-modal-open');
        if ( drawerReturn && document.contains(drawerReturn) ) drawerReturn.focus();
    }

    function stepDrawer(dir) {
        var i = drawerList.indexOf(drawerId) + dir;
        if ( i < 0 || i >= drawerList.length ) return;
        drawerId = drawerList[i];
        $dr.find('.erdo-dr-b').css('opacity', 0.5);
        loadDetail(drawerId);
    }

    $dr.on('click', '#erdo-dr-close, #erdo-dr-cancel', closeDrawer);
    $dr.on('click', '#erdo-dr-prev', function() { stepDrawer(-1); });
    $dr.on('click', '#erdo-dr-next', function() { stepDrawer(1); });
    $ov.on('mousedown', function(e) { if ( e.target === this ) closeDrawer(); });

    $(document).on('keydown', function(e) {
        if ( Dlg.isOpen() ) {
            if ( e.key === 'Escape' ) Dlg.close();
            trapFocus(e, Dlg.$box);
            return;
        }
        if ( $('#erdo-ba-overlay').length ) {
            if ( e.key === 'Escape' ) closeBeforeAfter();
            return;
        }
        if ( ! $ov.prop('hidden') ) {
            var typing = $(e.target).is('input, textarea, select');
            if ( e.key === 'Escape' ) closeDrawer();
            else if ( ! typing && e.key === 'ArrowLeft' ) stepDrawer(-1);
            else if ( ! typing && e.key === 'ArrowRight' ) stepDrawer(1);
            trapFocus(e, $dr);
        }
    });

    function drawerStatus(msg) {
        $('#erdo-dr-status').text(msg || '');
    }

    /** After any change in the drawer: refresh the card, then the panel. */
    function afterChange(id, msg) {
        return refreshItems([id]).always(function() {
            if ( ! $ov.prop('hidden') && drawerId === id ) {
                loadDetail(id).done(function() { drawerStatus(msg); });
            }
        });
    }

    $dr.on('submit', '#erdo-audit-form', function(e) {
        e.preventDefault();
        var id = +$(this).data('id'), $btn = $('#erdo-save-btn').prop('disabled', true);
        drawerStatus(t('Saving...'));
        $.post(V.ajax_url, $(this).serialize() + '&action=erdo_save_data&nonce=' + encodeURIComponent(V.nonce)).done(function(res) {
            if ( ! res || ! res.success ) { drawerStatus(errMsg(res)); $btn.prop('disabled', false); return; }
            afterChange(id, t('Saved.'));
        }).fail(function() { drawerStatus(t('Something went wrong. Please try again.')); $btn.prop('disabled', false); });
    });

    // Fill alt/title from the page title or the file name.
    $dr.on('click', '.erdo-magic-btn', function() {
        var source = $(this).data('source'), val = '';
        if ( source === 'parent' ) {
            val = $('#erdo-parent-title').val();
        } else {
            val = $('#erdo-current-filename').val().replace(/\.[^/.]+$/, '').replace(/[-_]+/g, ' ').replace(/\s+/g, ' ').trim();
            val = val.charAt(0).toUpperCase() + val.slice(1);
        }
        if ( val ) $('#erdo-input-' + $(this).data('target')).val(val).trigger('focus');
    });

    $dr.on('input', '#erdo-input-filename', function() {
        var val = $(this).val();
        var clean = val.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9\-]/g, '').replace(/-+/g, '-').replace(/^-|-$/g, '');
        $('#erdo-filename-preview-val').text(clean);
        $('#erdo-filename-preview').prop('hidden', ! clean || clean === val);
    });

    $dr.on('click', '.webp-convert-btn', function() {
        var id = +$(this).data('id'), $b = $(this).prop('disabled', true);
        drawerStatus(t('Converting...'));
        post('erdo_convert_webp', { id: id }).done(function(res) {
            if ( res && res.success ) afterChange(id, t('Copy created.')); else { drawerStatus(errMsg(res)); $b.prop('disabled', false); }
        });
    });

    $dr.on('click', '.erdo-restore-btn', function() {
        var id = +$(this).data('id');
        Dlg.open({ icon: 'image-rotate', title: t('Remove the WebP/AVIF copies?'), body: '<p>' + esc(t('The original stays exactly as it is. You can create the copies again at any time.')) + '</p>',
            actions: [ { label: t('Cancel') }, { label: t('Remove copies'), primary: true, click: function() {
                Dlg.close();
                post('erdo_restore_original', { id: id }).done(function() { afterChange(id, t('Copies removed.')); });
            } } ] });
    });

    $dr.on('click', '.erdo-delete-btn', function() {
        quarantineOne(+$(this).data('id'));
    });

    $dr.on('click', '.erdo-resize-btn', function() {
        var id = +$(this).data('id'), w = +$('#erdo-w-' + id).val(), h = +$('#erdo-h-' + id).val();
        if ( ! w || ! h ) { drawerStatus(t('Enter a width and a height.')); return; }
        drawerStatus(t('Resizing...'));
        post('erdo_manual_resize', { id: id, w: w, h: h }).done(function(res) {
            if ( res && res.success ) afterChange(id, t('Resized. The original is kept.')); else drawerStatus(t('Enter a size smaller than the current one.'));
        });
    });

    $dr.on('click', '.erdo-restore-size-btn', function() {
        var id = +$(this).data('id');
        post('erdo_restore_size', { id: id }).done(function(res) {
            afterChange(id, res && res.success ? t('Original size restored.') : errMsg(res));
        });
    });

    $dr.on('click', '.erdo-undo-rename-btn', function() {
        var id = +$(this).data('id');
        post('erdo_rename_undo_last', { id: id }).done(function(res) {
            afterChange(id, res && res.success ? t('Renaming undone.') : errMsg(res));
        });
    });

    // AI box inside the drawer
    $dr.on('click', '.erdo-ai-suggest-btn', function() {
        var id = +$(this).data('id');
        $(this).prop('disabled', true).html('<span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Looking at the image…')));
        post('erdo_ai_suggest', { id: id }).done(function(res) {
            loadDetail(id).done(function() { drawerStatus(res && res.success ? '' : errMsg(res)); });
            loadStats();
        }).fail(function() { loadDetail(id).done(function() { drawerStatus(t('Something went wrong. Please try again.')); }); });
    });

    $dr.on('click', '.erdo-ai-accept-btn', function() {
        var $box = $(this).closest('.erdo-ai-box'), id = +$box.data('id');
        var data = { id: id, fields: [], edits: {} };
        $box.find('.erdo-ai-field:checked').each(function() { data.fields.push($(this).val()); });
        $box.find('.erdo-ai-value').each(function() { data.edits[ $(this).data('field') ] = $(this).val(); });
        if ( ! data.fields.length ) return;
        $box.find('button').prop('disabled', true);
        post('erdo_ai_accept', data).done(function(res) {
            if ( ! res || ! res.success ) { drawerStatus(errMsg(res)); $box.find('button').prop('disabled', false); return; }
            afterChange(id, t('Suggestion applied.'));
        });
    });

    $dr.on('click', '.erdo-ai-reject-btn', function() {
        var id = +$(this).closest('.erdo-ai-box').data('id');
        post('erdo_ai_reject', { id: id }).done(function() { loadDetail(id); loadStats(); });
    });

    /* Before / after slider: drag is bound to the container (line and handle have pointer-events:none). */
    $dr.on('click', '.erdo-before-after-btn', function() {
        var cur = $(this).data('current'), orig = $(this).data('original');
        var h = '<div class="erdo-ba-overlay" id="erdo-ba-overlay" role="dialog" aria-modal="true" aria-label="' + esc(t('Before and after')) + '"><div class="erdo-ba-modal">' +
            '<div class="erdo-ba-header"><h3>' + esc(t('Before and after')) + '</h3><button type="button" class="button-link" id="erdo-ba-close" aria-label="' + esc(t('Close')) + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div>' +
            '<div class="erdo-ba-container" id="erdo-ba-container">' +
            '<div class="erdo-ba-img erdo-ba-after"><img src="' + esc(cur) + '" alt=""></div>' +
            '<div class="erdo-ba-img erdo-ba-before"><img src="' + esc(orig) + '" alt=""></div>' +
            '<span class="erdo-ba-label erdo-ba-label-before">' + esc(t('Original')) + '</span>' +
            '<span class="erdo-ba-label erdo-ba-label-after">' + esc(t('Served copy')) + '</span>' +
            '<div class="erdo-ba-slider" id="erdo-ba-slider"><div class="erdo-ba-handle"></div></div></div></div></div>';
        $('body').append(h);
        initBeforeAfter();
        $('#erdo-ba-close').trigger('focus');
    });

    function initBeforeAfter() {
        var $c = $('#erdo-ba-container'), $before = $c.find('.erdo-ba-before'), $slider = $('#erdo-ba-slider'), dragging = false;
        function moveTo(x) {
            var r = $c[0].getBoundingClientRect(), pct = Math.min(100, Math.max(0, ( x - r.left ) / r.width * 100));
            $before.css('clip-path', 'inset(0 ' + ( 100 - pct ) + '% 0 0)');
            $slider.css('left', pct + '%');
        }
        moveTo($c[0].getBoundingClientRect().left + $c[0].getBoundingClientRect().width / 2);
        $c.on('mousedown touchstart', function(e) {
            dragging = true;
            moveTo(e.originalEvent.touches ? e.originalEvent.touches[0].clientX : e.clientX);
            e.preventDefault();
        });
        $(document).on('mousemove.ba touchmove.ba', function(e) {
            if ( dragging ) moveTo(e.originalEvent.touches ? e.originalEvent.touches[0].clientX : e.clientX);
        });
        $(document).on('mouseup.ba touchend.ba', function() { dragging = false; });
        $('#erdo-ba-close').on('click', closeBeforeAfter);
        $('#erdo-ba-overlay').on('mousedown', function(e) { if ( e.target === this ) closeBeforeAfter(); });
    }

    function closeBeforeAfter() {
        $('#erdo-ba-overlay').remove();
        $(document).off('.ba');
        $dr.find('.erdo-before-after-btn').trigger('focus');
    }

    /* =======================================================================
       FLOWS
    ======================================================================= */

    /* ---- Quarantine: one image (calm confirm, reversible) ---- */
    function quarantineOne(id) {
        var it = byId[id] || { file: '' };
        Dlg.open({ icon: 'archive', title: t('Move this image to quarantine?'), sub: it.file,
            body: '<p>' + esc(t('It disappears from your site and media library, but nothing is deleted. You can restore it in one click from Cleanup → Quarantine.')) + '</p>',
            actions: [ { label: t('Cancel') }, { label: t('Move to quarantine'), primary: true, click: function() {
                Dlg.update({ busy: true, actions: [ { label: t('Moving…'), primary: true, disabled: true } ] });
                post('erdo_delete_image', { id: id }).done(function(res) {
                    Dlg.update({ busy: false });
                    if ( ! res || ! res.success ) {
                        Dlg.update({ icon: 'warning', tone: 'danger', title: t('Could not move the image'), body: '<p>' + esc(errMsg(res)) + '</p>', actions: [ { label: t('Close'), primary: true } ] });
                        return;
                    }
                    closeDrawer();
                    refreshItems([id]);
                    if ( res.data.in_use ) {
                        Dlg.update({ icon: 'warning', tone: 'warn', title: t('Moved to quarantine'), body: '<p>' + esc(t('This image was still used on your site. Restore it from Cleanup → Quarantine if a page looks broken.')) + '</p>', actions: [ { label: t('Close'), primary: true } ] });
                    } else {
                        Dlg.close();
                    }
                });
            } } ] });
    }

    /* ---- Quarantine: many unused images ---- */
    function flowQuarantine(only) {
        var steps = [ t('Preview'), t('Move'), t('Done') ];
        var title = t('Move unused images to quarantine');
        function start(list) {
            var size = 0;
            $.each(list, function(_, id) { size += byId[id] ? byId[id].bytes : 0; });
            Dlg.open({ icon: 'archive', title: fmt(tn(t('Move %d image to quarantine?'), t('Move %d images to quarantine?'), list.length), list.length), steps: steps, step: 0,
                body: '<p>' + esc(t('They disappear from your site and media library, but nothing is deleted. You can restore any of them in one click from Cleanup → Quarantine.')) + '</p>' +
                    kv([ [ t('Images'), num(list.length) ], [ t('Space in the library'), bytes(size) ] ]) +
                    '<p class="erdo-muted erdo-small">' + esc(t('Each image is checked again first: if it is used, or its file name still appears anywhere on the site, it is skipped. Images used only in theme files or on other websites cannot be detected.')) + '</p>',
                actions: [ { label: t('Cancel') }, { label: t('Move to quarantine'), primary: true, click: function() { run(list); } } ] });
        }
        function run(list) {
            var total = list.length, done = 0, moved = 0, skipped = 0, failed = 0, stop = false, queue = list.slice();
            Dlg.update({ busy: true, step: 1, title: t('Moving images to quarantine'), body: progress(0, total), actions: [ { label: t('Stop'), click: function() { stop = true; } } ] });
            (function next() {
                if ( stop || ! queue.length ) return finish();
                var id = queue.shift();
                post('erdo_quarantine_unused', { id: id }).done(function(res) {
                    if ( res && res.success ) moved++;
                    else if ( res && res.data && /in_use|maybe_in_use|already/.test(res.data.code || '') ) skipped++;
                    else failed++;
                }).fail(function() { failed++; }).always(function() {
                    done++;
                    Dlg.$box.find('.erdo-dialog-b').html(progress(done, total));
                    next();
                });
            })();
            function finish() {
                Dlg.update({ busy: false, step: 2, icon: 'yes-alt', tone: 'success', title: fmt(tn(t('Done — %d image moved to quarantine'), t('Done — %d images moved to quarantine'), moved), moved),
                    body: kv([ [ t('Moved'), num(moved) ], [ t('Skipped (still in use)'), num(skipped) ], [ t('Failed'), num(failed) ] ]),
                    actions: [ { label: t('Close') }, { label: t('Open quarantine'), primary: true, click: function() { Dlg.close(); showTab('cleanup'); $('#erdo-cl-tab-q').trigger('click'); } } ] });
                clearSelection();
                refreshItems(list);
            }
        }
        if ( only && only.length ) { start(only); return; }
        Dlg.open({ icon: 'archive', title: title, steps: steps, step: 0, body: '<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Checking…')) + '</p>', actions: [ { label: t('Cancel') } ] });
        post('erdo_get_unused_list').done(function(res) {
            if ( ! res || ! res.success ) { Dlg.update({ body: '<p>' + esc(errMsg(res)) + '</p>' }); return; }
            if ( ! res.data.length ) { Dlg.update({ body: '<p>' + esc(t('No unused images found.')) + '</p>', actions: [ { label: t('Close'), primary: true } ] }); return; }
            start($.map(res.data, Number));
        });
    }
    $('#erdo-quarantine-all').on('click', function() { flowQuarantine(null); });

    /* ---- Rename (preview → apply in chunks of 25 under one batch → undo) ---- */
    function flowRename(list, mode) {
        var steps = [ t('Preview'), t('Rename'), t('Done') ];
        var body = '';
        if ( mode === 'pattern' ) {
            body += '<div class="erdo-rn-fields">' +
                '<label>' + esc(t('Pattern')) + '<input type="text" id="erdo-rn-pattern" value="{keyword}-{n}"></label>' +
                '<label>' + esc(t('Keyword')) + '<input type="text" id="erdo-rn-keyword" placeholder="' + esc(t('Page focus keyword')) + '"></label>' +
                '<button type="button" class="button" id="erdo-rn-preview">' + esc(t('Preview')) + '</button></div>' +
                '<p class="erdo-muted erdo-small">' + esc(t('Tokens: {keyword} {n} {parent} {title} {original} {alt}. An empty keyword uses the focus keyword (Yoast, Rank Math, AIOSEO, SEOPress) or the title of the page the image is on.')) + '</p>';
        }
        body += '<div id="erdo-rn-table"></div><p class="erdo-muted erdo-small">' + esc(t('All sizes and WebP/AVIF copies move with the file, links on the site are updated and old URLs redirect (301). You can undo this afterwards.')) + '</p>';
        Dlg.open({ icon: 'tag', wide: true, title: mode === 'pattern' ? fmt(tn(t('Rename %d image'), t('Rename %d images'), list.length), list.length) : t('Fix file names'), sub: t('Nothing has changed yet.'), steps: steps, step: 0, body: body,
            actions: [ { label: t('Cancel') }, { label: t('Rename'), primary: true, disabled: true, click: apply } ] });
        preview();

        function preview() {
            $('#erdo-rn-table').html('<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Checking…')) + '</p>');
            post('erdo_rename_preview', { ids: list, pattern: mode === 'pattern' ? $('#erdo-rn-pattern').val() : '', keyword: mode === 'pattern' ? $('#erdo-rn-keyword').val() : '' }).done(function(res) {
                if ( ! res || ! res.success ) { $('#erdo-rn-table').html('<p>' + esc(errMsg(res)) + '</p>'); return; }
                var rows = res.data.rows, ok = 0;
                if ( ! rows.length ) { $('#erdo-rn-table').html('<p class="erdo-all-clear"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' + esc(t('All file names are already clean.')) + '</p>'); return; }
                var h = '<div class="erdo-rn-scroll"><table class="erdo-table erdo-table-flat"><thead><tr><th>' + esc(t('Current')) + '</th><th>' + esc(t('New name')) + '</th></tr></thead><tbody>';
                $.each(rows, function(_, r) {
                    h += '<tr data-id="' + parseInt(r.id, 10) + '"><td class="erdo-muted">' + esc(r.old) + '</td><td>';
                    if ( r.error ) {
                        h += '<span class="erdo-pill is-red">' + esc(r.error) + '</span>';
                    } else {
                        ok++;
                        h += '<span class="erdo-fn erdo-fn-sm"><input type="text" class="erdo-rn-stem" value="' + esc(r.stem) + '" aria-label="' + esc(t('New name') + ' — ' + r.old) + '"><span>' + esc(r['new'].slice(r.stem.length)) + '</span></span>';
                    }
                    h += '</td></tr>';
                });
                $('#erdo-rn-table').html(h + '</tbody></table></div>');
                Dlg.$box.find('.erdo-dialog-f [data-i="1"]').prop('disabled', ! ok).text(fmt(tn(t('Rename %d image'), t('Rename %d images'), ok), ok));
            });
        }

        Dlg.$box.off('.rn').on('click.rn', '#erdo-rn-preview', preview).on('keydown.rn', '#erdo-rn-pattern, #erdo-rn-keyword', function(e) {
            if ( e.key === 'Enter' ) { e.preventDefault(); preview(); }
        });

        function apply() {
            var todo = [];
            $('#erdo-rn-table tr[data-id]').each(function() {
                var $in = $(this).find('.erdo-rn-stem');
                if ( $in.length ) todo.push([ +$(this).data('id'), $.trim($in.val()) ]);
            });
            var total = todo.length, done = 0, renamed = 0, batch = '', errors = [], touched = $.map(todo, function(x) { return x[0]; });
            Dlg.update({ busy: true, step: 1, sub: '', body: progress(0, total), actions: [ { label: t('Renaming…'), primary: true, disabled: true } ] });
            (function next() {
                var chunk = todo.splice(0, 25);
                if ( ! chunk.length ) return finish();
                var data = { batch: batch, items: {} };
                $.each(chunk, function(_, it) { data.items[ it[0] ] = it[1]; });
                post('erdo_rename_apply', data).done(function(res) {
                    if ( ! res || ! res.success ) { errors.push(errMsg(res)); return finish(); }
                    batch = res.data.batch;
                    $.each(res.data.results, function(_, r) { r.error ? errors.push(r.error) : renamed++; });
                    done += chunk.length;
                    Dlg.$box.find('.erdo-dialog-b').html(progress(done, total));
                    next();
                }).fail(function() { errors.push(t('Something went wrong. Please try again.')); finish(); });
            })();
            function finish() {
                var acts = [ { label: t('Close'), primary: true } ];
                if ( batch && renamed ) acts.unshift({ label: t('Undo'), click: function($b) {
                    $b.prop('disabled', true);
                    post('erdo_rename_undo', { batch: batch }).done(function(res) {
                        Dlg.update({ icon: 'undo', tone: 'info', title: res && res.success ? fmt(tn(t('Renaming undone: %d image restored.'), t('Renaming undone: %d images restored.'), res.data.restored), res.data.restored) : errMsg(res), body: '', actions: [ { label: t('Close'), primary: true } ] });
                        refreshItems(touched);
                    });
                } });
                Dlg.update({ busy: false, step: 2, icon: 'yes-alt', tone: 'success', title: fmt(tn(t('Done — %d image renamed'), t('Done — %d images renamed'), renamed), renamed),
                    body: kv([ [ t('Renamed'), num(renamed) ], [ t('Skipped'), num(total - renamed) ] ]) + ( errors.length ? '<p class="erdo-muted erdo-small">' + esc(errors.slice(0, 3).join(' · ')) + '</p>' : '' ),
                    actions: acts });
                clearSelection();
                refreshItems(touched);
            }
        }
    }

    /* ---- Convert (background queue; the open window helps it along) ---- */
    var qTimer = null;
    function flowConvert(only) {
        var steps = [ t('Preview'), t('Convert'), t('Done') ];
        function start(count) {
            if ( ! count ) {
                Dlg.open({ icon: 'yes-alt', tone: 'success', title: t('Nothing to convert'), body: '<p>' + esc(t('All images already have WebP/AVIF copies.')) + '</p>', actions: [ { label: t('Close'), primary: true } ] });
                return;
            }
            Dlg.open({ icon: 'update', title: fmt(tn(t('Create %1$s copies for %2$d image?'), t('Create %1$s copies for %2$d images?'), count), V.format === 'BOTH' ? 'AVIF + WebP' : V.format, count), steps: steps, step: 0,
                body: '<p>' + esc(t('Your originals are not changed. A copy is kept only when it is smaller than the original, and browsers that cannot show it get the original.')) + '</p>' +
                    '<p class="erdo-muted erdo-small">' + esc(t('The conversion runs on the server. You can close this window or leave the page while it runs.')) + '</p>',
                actions: [ { label: t('Cancel') }, { label: t('Start converting'), primary: true, click: function() {
                    Dlg.update({ busy: true, actions: [ { label: t('Starting…'), primary: true, disabled: true } ] });
                    post('erdo_start_queue', only && only.length ? { ids: only } : {}).done(function(res) {
                        Dlg.update({ busy: false });
                        if ( ! res || ! res.success ) { Dlg.update({ body: '<p>' + esc(errMsg(res)) + '</p>', actions: [ { label: t('Close'), primary: true } ] }); return; }
                        clearSelection();
                        showQueue(res.data, true);
                    });
                } } ] });
        }
        if ( only && only.length ) { start(only.length); return; }
        post('erdo_queue_status', { pending: 1 }).done(function(res) { start(res && res.success ? res.data.pending : 0); });
    }

    /** Shows queue progress in the dialog (if open) and the notice; polls while running. */
    function showQueue(st, inDialog) {
        clearTimeout(qTimer);
        var running = st.running;
        $('#erdo-queue-notice').toggleClass('hidden', ! running);
        $('#erdo-queue-text').text(fmt(t('Creating WebP/AVIF copies in the background: %1$d of %2$d'), st.done, st.total));
        var dlgIsQueue = Dlg.isOpen() && Dlg.opts().queue;
        if ( inDialog || dlgIsQueue ) {
            if ( running ) {
                var o = { queue: true, icon: 'update', title: fmt(tn(t('Converting %d image'), t('Converting %d images'), st.total), st.total), steps: [ t('Preview'), t('Convert'), t('Done') ], step: 1,
                    body: progress(st.done, st.total, st.skipped ? fmt(t('%d skipped (copy was larger)'), st.skipped) : '') + '<p class="erdo-muted erdo-small">' + esc(t('You can close this window or leave the page; conversion keeps running.')) + '</p>',
                    actions: [ { label: t('Stop'), click: function() { post('erdo_cancel_queue').done(function() { Dlg.close(); $('#erdo-queue-notice').addClass('hidden'); clearTimeout(qTimer); }); } },
                        { label: t('Run in background'), primary: true } ] };
                inDialog && ! dlgIsQueue ? Dlg.open(o) : Dlg.update(o);
            } else if ( st.status === 'done' ) {
                var made = st.done - st.skipped - st.errors;
                var acts = [ { label: t('Close'), primary: true } ];
                if ( st.can_undo ) acts.unshift({ label: t('Undo (remove copies)'), click: function($b) {
                    $b.prop('disabled', true);
                    post('erdo_queue_undo').done(function(res) {
                        Dlg.update({ queue: false, icon: 'undo', tone: 'info', title: fmt(tn(t('Copies removed for %d image.'), t('Copies removed for %d images.'), res && res.success ? res.data.reverted : 0), res && res.success ? res.data.reverted : 0), body: '', steps: null, actions: [ { label: t('Close'), primary: true } ] });
                        clearCache(); libVersion = null; loadStats();
                    });
                } });
                Dlg.update({ queue: false, step: 2, icon: 'yes-alt', tone: 'success', title: fmt(tn(t('Done — %d image converted'), t('Done — %d images converted'), made), made),
                    body: kv([ [ t('Copies kept'), num(made) ], [ t('Skipped (copy was larger)'), num(st.skipped) ], [ t('Failed'), num(st.errors) ] ]), actions: acts });
            }
        }
        if ( running ) {
            qTimer = setTimeout(function() {
                post('erdo_queue_status', { assist: 1 }).done(function(res) {
                    if ( ! res || ! res.success ) return;
                    if ( ! res.data.running ) { clearCache(); libVersion = null; loadStats(); }
                    showQueue(res.data, false);
                });
            }, 1500);
        }
    }
    $('#erdo-queue-details').on('click', function() {
        post('erdo_queue_status').done(function(res) { if ( res && res.success ) showQueue(res.data, true); });
    });
    if ( V.queue && V.queue.running ) showQueue(V.queue, false);

    /* ---- Alt text from file names ---- */
    function flowAlt(only) {
        var steps = [ t('Preview'), t('Apply'), t('Done') ], data = only && only.length ? { ids: only } : {};
        Dlg.open({ icon: 'format-image', title: t('Alt text from file names'), steps: steps, step: 0, body: '<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Checking…')) + '</p>', actions: [ { label: t('Cancel') } ] });
        post('erdo_bulk_alt_text', $.extend({ dry: 1 }, data)).done(function(res) {
            if ( ! res || ! res.success ) { Dlg.update({ body: '<p>' + esc(errMsg(res)) + '</p>' }); return; }
            var d = res.data;
            Dlg.update({ body: '<p>' + esc(t('Images without alt text get one made from their file name, for example "red-tulip-field.jpg" becomes "Red tulip field". Camera-style names such as IMG_1234 are skipped.')) + '</p>' +
                    kv([ [ t('Images without alt text'), num(d.total) ], [ t('Can get alt text from the name'), num(d.can) ] ]) +
                    ( V.ai && V.ai.ready ? '<p class="erdo-muted erdo-small">' + esc(t('For better descriptions, use AI suggestions instead: they look at the image itself.')) + '</p>' : '' ),
                actions: [ { label: t('Cancel') }, { label: fmt(tn(t('Add alt text to %d image'), t('Add alt text to %d images'), d.can), d.can), primary: true, disabled: ! d.can, click: function() {
                    Dlg.update({ busy: true, step: 1, actions: [ { label: t('Working…'), primary: true, disabled: true } ] });
                    post('erdo_bulk_alt_text', data).done(function(r2) {
                        Dlg.update({ busy: false, step: 2, icon: 'yes-alt', tone: 'success', title: fmt(tn(t('Done — %d image updated'), t('Done — %d images updated'), r2 && r2.success ? r2.data.updated : 0), r2 && r2.success ? r2.data.updated : 0), body: '', actions: [ { label: t('Close'), primary: true } ] });
                        clearCache(); libVersion = null; loadStats();
                    });
                } } ] });
        });
    }
    $('#erdo-bulk-alt-btn').on('click', function() { flowAlt(null); });

    /* ---- AI suggestions (one image at a time, stoppable, waits on rate limits) ---- */
    function flowAi(only) {
        var st = V.ai || {}, steps = [ t('Preview'), t('Suggest'), t('Done') ];
        if ( ! st.ready ) {
            Dlg.open({ icon: 'lightbulb', title: t('Set up AI suggestions'), body: '<p>' + esc(st.message || '') + '</p>',
                actions: st.setup_url ? [ { label: t('Cancel') }, { label: t('Open Connectors'), primary: true, click: function() { window.location.href = st.setup_url; } } ] : [ { label: t('Close'), primary: true } ] });
            return;
        }
        var list = [];
        function estimate(n, low, high) {
            return '<p>' + esc(fmt(t('%1$d images. Each image is sent once, resized to 1024 px. Estimated provider cost: about $%2$s–$%3$s with a small vision model.'), n, low, high)) + '</p>' +
                '<p class="erdo-muted erdo-small">' + esc(t('Nothing is saved until you review it in the AI Suggestions tab.')) + '</p>';
        }
        function ready(n, low, high) {
            Dlg.update({ body: ( only ? '' : scopeHtml() ) + ( n ? estimate(n, low, high) : '<p>' + esc(t('No images need suggestions right now.')) + '</p>' ),
                actions: [ { label: t('Cancel') }, { label: t('Generate suggestions'), primary: true, disabled: ! n, click: run } ] });
        }
        function scopeHtml() {
            return '<label class="erdo-block-label">' + esc(t('Images')) + ' <select id="erdo-ai-scope"><option value="missing">' + esc(t('Images missing alt text')) + '</option><option value="weak">' + esc(t('Missing or weak alt text')) + '</option></select></label>';
        }
        function loadCandidates() {
            var scope = $('#erdo-ai-scope').val() || 'missing';
            post('erdo_ai_candidates', { scope: scope }).done(function(res) {
                if ( ! res || ! res.success ) { Dlg.update({ body: '<p>' + esc(errMsg(res)) + '</p>' }); return; }
                list = res.data.ids;
                ready(list.length, res.data.low, res.data.high);
                $('#erdo-ai-scope').val(scope);
            });
        }
        Dlg.open({ icon: 'lightbulb', title: t('AI suggestions'), steps: steps, step: 0, body: '<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Checking…')) + '</p>', actions: [ { label: t('Cancel') } ] });
        Dlg.$box.off('.ai').on('change.ai', '#erdo-ai-scope', loadCandidates);
        if ( only && only.length ) {
            list = only.slice();
            ready(list.length, ( Math.max(0.01, list.length * 0.00015) ).toFixed(2), ( Math.max(0.02, list.length * 0.0004) ).toFixed(2));
        } else {
            loadCandidates();
        }

        function run() {
            var queue = list.slice(), total = queue.length, done = 0, ok = 0, failed = 0, wait = 0, stop = false, last = '';
            Dlg.update({ busy: true, step: 1, title: t('Generating suggestions'), body: progress(0, total), actions: [ { label: t('Stop'), click: function() { stop = true; } } ] });
            (function next() {
                if ( stop || ! queue.length ) return finish();
                post('erdo_ai_suggest', { id: queue[0] }).done(function(res) {
                    if ( res && ! res.success && res.data && res.data.retry && wait < 5 ) {
                        wait++;
                        Dlg.$box.find('.erdo-dialog-b').html(progress(done, total, res.data.message));
                        setTimeout(next, 5000 * wait);
                        return;
                    }
                    wait = 0;
                    queue.shift();
                    done++;
                    if ( res && res.success ) ok++; else { failed++; last = errMsg(res); }
                    Dlg.$box.find('.erdo-dialog-b').html(progress(done, total));
                    next();
                }).fail(function() { queue.shift(); done++; failed++; next(); });
            })();
            function finish() {
                Dlg.update({ busy: false, step: 2, icon: 'yes-alt', tone: 'success', title: fmt(tn(t('Done — %d suggestion ready for review'), t('Done — %d suggestions ready for review'), ok), ok),
                    body: kv([ [ t('Ready for review'), num(ok) ], [ t('Failed'), num(failed) ] ]) + ( last ? '<p class="erdo-muted erdo-small">' + esc(last) + '</p>' : '' ),
                    actions: [ { label: t('Close') }, { label: t('Review suggestions'), primary: true, disabled: ! ok, click: function() { Dlg.close(); showTab('ai'); } } ] });
                clearSelection();
                loadStats();
            }
        }
    }
    $('#erdo-ai-bulk-btn').on('click', function() { flowAi(null); });

    /* ---- Small tools ---- */
    $('#erdo-export-csv-btn').on('click', function() {
        post('erdo_export_csv').done(function(res) {
            if ( ! res || ! res.success ) return;
            var url = URL.createObjectURL(new Blob([ res.data.csv ], { type: 'text/csv;charset=utf-8;' }));
            var a = document.createElement('a');
            a.href = url;
            a.download = 'erdo-seo-report.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });
    });

    $('#erdo-scan-thumbnails-btn').on('click', function() {
        Dlg.open({ icon: 'format-aside', title: t('Posts without a featured image'), body: '<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Checking…')) + '</p>', actions: [ { label: t('Close'), primary: true } ] });
        post('erdo_scan_thumbnails').done(function(res) {
            if ( ! res || ! res.success ) { Dlg.update({ body: '<p>' + esc(errMsg(res)) + '</p>' }); return; }
            if ( ! res.data.length ) { Dlg.update({ body: '<p class="erdo-all-clear"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' + esc(t('Every published post has a featured image.')) + '</p>' }); return; }
            var h = '<p>' + esc(fmt(tn(t('%d published post has no featured image:'), t('%d published posts have no featured image (first 50 shown):'), res.data.length), res.data.length)) + '</p><ul class="erdo-link-list">';
            $.each(res.data, function(_, p) { h += '<li><a href="' + esc(p.link) + '" target="_blank" rel="noopener">' + esc(p.title || '#' + p.id) + '</a></li>'; });
            Dlg.update({ body: h + '</ul>' });
        });
    });

    $('#erdo-refresh-all-btn').on('click', function() {
        Dlg.open({ icon: 'update', title: t('Recalculate all scores?'), body: '<p>' + esc(t('Clears the cached results and checks every image again. Nothing on your site changes.')) + '</p>',
            actions: [ { label: t('Cancel') }, { label: t('Recalculate'), primary: true, click: function() {
                Dlg.close();
                post('erdo_refresh_all').done(function() { clearCache(); libVersion = null; loadStats(); });
            } } ] });
    });

    $('#erdo-upload-btn').on('click', function(e) {
        e.preventDefault();
        if ( ! window.wp || ! wp.media ) return;
        var frame = wp.media({ title: t('Upload images'), button: { text: t('Done') }, multiple: true, library: { type: 'image' } });
        frame.on('close', function() { loadStats(); });
        frame.open();
    });

    /* ---- Right-click menu on tiles ---- */
    var $ctx = null;
    function closeCtx() { if ( $ctx ) { $ctx.remove(); $ctx = null; } }
    $(document).on('contextmenu', '.erdo-tile', function(e) {
        e.preventDefault();
        closeCtx();
        var id = +$(this).data('id'), it = byId[id] || {};
        var h = '<div class="erdo-ctx-menu" role="menu">' +
            '<button type="button" role="menuitem" data-a="detail"><span class="dashicons dashicons-visibility" aria-hidden="true"></span>' + esc(t('Open details')) + '</button>';
        if ( ! it.modern ) h += '<button type="button" role="menuitem" data-a="convert"><span class="dashicons dashicons-update" aria-hidden="true"></span>' + esc(t('Create WebP/AVIF copy')) + '</button>';
        h += '<button type="button" role="menuitem" data-a="rename"><span class="dashicons dashicons-tag" aria-hidden="true"></span>' + esc(t('Fix file name…')) + '</button>' +
            '<div class="erdo-dropdown-sep"></div><button type="button" role="menuitem" data-a="quarantine"><span class="dashicons dashicons-archive" aria-hidden="true"></span>' + esc(t('Quarantine…')) + '</button></div>';
        $ctx = $(h).css({ top: e.pageY, left: e.pageX }).data('id', id).appendTo('body');
        $ctx.find('button').first().trigger('focus');
    });
    $(document).on('click', function(e) { if ( ! $(e.target).closest('.erdo-ctx-menu').length ) closeCtx(); });
    $(document).on('keydown', function(e) { if ( e.key === 'Escape' ) closeCtx(); });
    $(document).on('click', '.erdo-ctx-menu button', function() {
        var a = $(this).data('a'), id = $ctx.data('id');
        closeCtx();
        if ( a === 'detail' ) openDrawer(id, ids(filtered()));
        else if ( a === 'convert' ) post('erdo_convert_webp', { id: id }).done(function() { refreshItems([id]); });
        else if ( a === 'rename' ) flowRename([id], 'fix');
        else if ( a === 'quarantine' ) quarantineOne(id);
    });

    /* =======================================================================
       CLEANUP TAB
    ======================================================================= */
    $('.erdo-subnav').on('click', '[role=tab]', function() {
        var q = this.id === 'erdo-cl-tab-q';
        $('#erdo-cl-tab-unused').attr('aria-selected', ! q);
        $('#erdo-cl-tab-q').attr('aria-selected', q);
        $('#erdo-cl-unused').prop('hidden', q);
        $('#erdo-cl-q').prop('hidden', ! q);
        if ( q ) loadQuarantine();
    });

    function loadQuarantine() {
        var $tb = $('#erdo-q-table tbody');
        $tb.html('<tr><td colspan="3" class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span></td></tr>');
        post('erdo_quarantine_list').done(function(res) {
            if ( ! res || ! res.success ) { $tb.empty(); return; }
            var rows = res.data.rows;
            $('#erdo-count-q').text(num(rows.length));
            $('#erdo-q-table').toggleClass('hidden', ! rows.length);
            $('#erdo-q-empty').toggleClass('hidden', rows.length > 0);
            $('#erdo-q-restore-all').prop('hidden', rows.length < 2).prop('disabled', false);
            $tb.html($.map(rows, function(r) {
                var note = r.in_use ? '<span class="erdo-pill is-orange">' + esc(t('Was in use')) + '</span>' : ( r.parent ? esc(fmt(t('Was attached to "%s"'), r.parent)) : '' );
                return '<tr data-id="' + r.id + '"><td><b>' + esc(r.file) + '</b>' + ( note ? '<br><span class="erdo-muted erdo-small">' + note + '</span>' : '' ) + '</td><td>' + esc(r.when) + '</td>' +
                    '<td class="erdo-right"><button type="button" class="button erdo-q-restore"><span class="dashicons dashicons-undo" aria-hidden="true"></span>' + esc(t('Restore')) + '</button> ' +
                    '<button type="button" class="button-link erdo-link-danger erdo-q-purge">' + esc(t('Delete permanently')) + '</button></td></tr>';
            }).join(''));
        });
    }

    $('#erdo-q-table').on('click', '.erdo-q-restore', function() {
        var $tr = $(this).closest('tr'), id = +$tr.data('id');
        $(this).prop('disabled', true);
        post('erdo_quarantine_restore', { id: id }).done(function(res) {
            if ( ! res || ! res.success ) {
                Dlg.open({ icon: 'warning', tone: 'danger', title: t('Could not restore the image'), body: '<p>' + esc(errMsg(res)) + '</p>', actions: [ { label: t('Close'), primary: true } ] });
                loadQuarantine();
                return;
            }
            loadQuarantine();
            refreshItems([id]);
        });
    });

    $('#erdo-q-restore-all').on('click', function() {
        var queue = $('#erdo-q-table tbody tr[data-id]').map(function() { return +$(this).data('id'); }).get(), done = [];
        $(this).prop('disabled', true);
        (function next() {
            var id = queue.shift();
            if ( ! id ) { loadQuarantine(); refreshItems(done); return; }
            post('erdo_quarantine_restore', { id: id }).done(function(res) { if ( res && res.success ) done.push(id); }).always(next);
        })();
    });

    $('#erdo-q-table').on('click', '.erdo-q-purge', function() {
        var $tr = $(this).closest('tr'), id = +$tr.data('id'), file = $tr.find('b').text();
        Dlg.open({ icon: 'trash', tone: 'danger', title: t('Delete this image permanently?'), sub: file,
            body: '<p>' + esc(t('The image and all its sizes and copies are deleted from the server. This cannot be undone.')) + '</p>',
            actions: [ { label: t('Cancel') }, { label: t('Delete permanently'), danger: true, click: function() {
                Dlg.close();
                post('erdo_quarantine_purge', { id: id }).done(function(res) {
                    if ( ! res || ! res.success ) Dlg.open({ icon: 'warning', tone: 'danger', title: t('Could not delete the image'), body: '<p>' + esc(errMsg(res)) + '</p>', actions: [ { label: t('Close'), primary: true } ] });
                    loadQuarantine();
                    loadStats();
                });
            } } ] });
    });

    /* =======================================================================
       AI SUGGESTIONS TAB
    ======================================================================= */
    function loadAiList() {
        var $list = $('#erdo-ai-list');
        $list.html('<p class="erdo-loading-row"><span class="dashicons dashicons-update erdo-spin" aria-hidden="true"></span> ' + esc(t('Loading...')) + '</p>');
        post('erdo_ai_list').done(function(res) {
            if ( ! res || ! res.success ) { $list.html('<p class="erdo-loading-row">' + esc(errMsg(res)) + '</p>'); return; }
            var rows = res.data.rows;
            $('#erdo-count-ai').text(num(rows.length));
            $('#erdo-ai-title').text(rows.length ? fmt(tn(t('%d suggestion waiting for review'), t('%d suggestions waiting for review'), rows.length), rows.length) : t('No suggestions waiting for review'));
            if ( ! rows.length ) {
                $list.html('<p class="erdo-empty">' + esc(V.ai && V.ai.ready ? t('Use “Suggest for more images” or the Suggest button in an image’s details to get suggestions.') : t('Connect an AI provider to get suggestions.')) + '</p>');
                countAiFields();
                return;
            }
            $list.html($.map(rows, aiRowHtml).join(''));
            countAiFields();
        });
    }

    function aiField(key, label, current, value, checked, extra) {
        return '<label class="erdo-cmp-check"><input type="checkbox" class="erdo-ai-f" value="' + key + '"' + ( checked ? ' checked' : '' ) + '><span class="screen-reader-text">' + esc(fmt(t('Apply %s'), label)) + '</span></label>' +
            '<span class="erdo-cmp-label">' + esc(label) + '</span><span class="erdo-cmp-cur">' + current + '</span>' +
            '<span class="erdo-cmp-new">' + value + ( extra ? '<small class="erdo-muted">' + esc(extra) + '</small>' : '' ) + '</span>';
    }

    function aiRowHtml(r) {
        var h = '<div class="erdo-ai-item" data-id="' + r.id + '"><img class="erdo-ai-thumb" src="' + esc(r.thumb) + '" alt="" loading="lazy"><div class="erdo-ai-main">' +
            '<div class="erdo-ai-name"><b>' + esc(r.filename) + '</b>' + ( r.used_on ? ' <span class="erdo-faint erdo-small">· ' + esc(r.used_on) + '</span>' : '' ) + '</div>';
        if ( r.issue ) h += '<p class="erdo-ai-issue">' + esc(r.issue) + '</p>';
        h += '<div class="erdo-cmp"><span></span><span class="erdo-cmp-hd">' + esc(t('Field')) + '</span><span class="erdo-cmp-hd erdo-cmp-cur">' + esc(t('Current')) + '</span><span class="erdo-cmp-hd">' + esc(t('Suggested')) + '</span>';
        var cur = r.current_alt ? esc(r.current_alt) : '<span class="erdo-pill is-red">' + esc(t('Missing')) + '</span>';
        var val = r.decorative ? '<em>' + esc(t('Decorative image: alt stays empty on purpose.')) + '</em>' : '<input type="text" class="erdo-ai-v" data-field="alt" value="' + esc(r.alt) + '" aria-label="' + esc(t('Suggested alt text')) + '">';
        h += aiField('alt', t('Alt text'), cur, val, true);
        if ( r.stem ) h += aiField('filename', t('File name'), esc(r.current_stem + '.' + r.ext), '<input type="text" class="erdo-ai-v" data-field="filename" value="' + esc(r.stem) + '" aria-label="' + esc(t('Suggested file name')) + '">', r.weak_filename, t('Links are updated and the old URL redirects (301).'));
        if ( r.title ) h += aiField('title', t('Title'), esc(r.current_title), '<input type="text" class="erdo-ai-v" data-field="title" value="' + esc(r.title) + '" aria-label="' + esc(t('Suggested title')) + '">', r.weak_title);
        h += '</div></div><div class="erdo-ai-acts"><button type="button" class="button button-primary erdo-ai-row-accept"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' + esc(t('Accept')) + '</button>' +
            '<button type="button" class="button erdo-ai-row-reject">' + esc(t('Dismiss')) + '</button></div></div>';
        return h;
    }

    /** After a row is accepted or dismissed: update the counts, or reload when nothing is left. */
    function aiRowGone() {
        var left = $('#erdo-ai-list .erdo-ai-item').length;
        if ( ! left ) { loadAiList(); return; }
        $('#erdo-count-ai').text(num(left));
        $('#erdo-ai-title').text(fmt(tn(t('%d suggestion waiting for review'), t('%d suggestions waiting for review'), left), left));
        countAiFields();
    }

    function countAiFields() {
        var n = $('#erdo-ai-list .erdo-ai-f:checked').length;
        $('#erdo-ai-accept-all').prop('disabled', ! n).find('span:last').text(fmt(t('Accept checked (%d fields)'), n));
    }
    $('#erdo-ai-list').on('change', '.erdo-ai-f', countAiFields);

    function acceptRow($item) {
        var id = +$item.data('id'), data = { id: id, fields: [], edits: {} };
        $item.find('.erdo-ai-f:checked').each(function() { data.fields.push($(this).val()); });
        $item.find('.erdo-ai-v').each(function() { data.edits[ $(this).data('field') ] = $(this).val(); });
        if ( ! data.fields.length ) return $.Deferred().resolve().promise();
        $item.find('button, input').prop('disabled', true);
        return post('erdo_ai_accept', data).done(function(res) {
            if ( res && res.success ) {
                $item.slideUp(150, function() { $item.remove(); aiRowGone(); });
                refreshItems([id]);
            } else {
                $item.find('button, input').prop('disabled', false);
                $item.find('.erdo-ai-acts').append('<p class="erdo-ai-issue">' + esc(errMsg(res)) + '</p>');
            }
        });
    }

    $('#erdo-ai-list').on('click', '.erdo-ai-row-accept', function() { acceptRow($(this).closest('.erdo-ai-item')); });
    $('#erdo-ai-list').on('click', '.erdo-ai-row-reject', function() {
        var $item = $(this).closest('.erdo-ai-item');
        post('erdo_ai_reject', { id: $item.data('id') }).done(function() {
            $item.slideUp(150, function() { $item.remove(); aiRowGone(); });
        });
    });
    $('#erdo-ai-accept-all').on('click', function() {
        var queue = $('#erdo-ai-list .erdo-ai-item').toArray();
        $(this).prop('disabled', true);
        (function next() {
            var el = queue.shift();
            if ( ! el ) { loadAiList(); loadStats(); return; }
            acceptRow($(el)).always(next);
        })();
    });

    /* =======================================================================
       SETTINGS TAB
    ======================================================================= */
    var $form = $('#erdo-settings-form');
    $form.on('click', '.erdo-set-nav button', function() {
        var sec = $(this).data('section');
        $form.find('.erdo-set-nav button').attr('aria-pressed', function() { return $(this).data('section') === sec ? 'true' : 'false'; });
        $form.find('.erdo-set-section').each(function() { $(this).prop('hidden', $(this).data('section') !== sec); });
    });
    $form.on('input change', 'input, select', function() {
        $('#erdo-savebar').prop('hidden', false);
        $('#erdo-save-status').text(t('Unsaved changes'));
    });
    $form.on('input', '#erdo-set-quality', function() { $('#erdo-quality-out').text(this.value); });
    $('#erdo-settings-discard').on('click', function() {
        $form[0].reset();
        $('#erdo-quality-out').text($('#erdo-set-quality').val());
        $('#erdo-savebar').prop('hidden', true);
    });
    $form.on('submit', function(e) {
        e.preventDefault();
        var $b = $('#erdo-settings-save').prop('disabled', true);
        $('#erdo-save-status').text(t('Saving...'));
        $.post(V.ajax_url, $form.serialize() + '&action=erdo_save_settings&nonce=' + encodeURIComponent(V.nonce)).done(function(res) {
            $b.prop('disabled', false);
            if ( ! res || ! res.success ) { $('#erdo-save-status').text(errMsg(res)); return; }
            // New baseline for "Discard".
            $form.find('input, select').each(function() {
                if ( this.type === 'checkbox' || this.type === 'radio' ) this.defaultChecked = this.checked;
                else if ( this.tagName === 'SELECT' ) $(this).find('option').each(function() { this.defaultSelected = this.selected; });
                else this.defaultValue = this.value;
            });
            var f = $form.find('[name=erdo_convert_format]:checked').val();
            if ( f ) V.format = f.toUpperCase();
            $('#erdo-save-status').text(t('Saved.'));
            setTimeout(function() { $('#erdo-savebar').prop('hidden', true); }, 1500);
            clearCache();
            libVersion = null;
            loadStats();
        }).fail(function() { $b.prop('disabled', false); $('#erdo-save-status').text(t('Something went wrong. Please try again.')); });
    });

    /* =======================================================================
       USAGE INDEX (first run / after upgrade)
    ======================================================================= */
    (function buildIndex() {
        var $n = $('#erdo-index-notice');
        if ( $n.data('status') === 'ready' ) return;
        (function step() {
            post('erdo_usage_build_step').done(function(res) {
                if ( ! res || ! res.success ) { setTimeout(step, 5000); return; }
                var d = res.data;
                if ( d.status === 'ready' ) {
                    $n.addClass('hidden').data('status', 'ready');
                    clearCache();
                    libVersion = null;
                    loadStats();
                    return;
                }
                $('#erdo-index-text').text(fmt(t('Building the usage index: %1$d / %2$d posts checked. Unused-image cleanup becomes available when it finishes.'), d.done, d.total));
                step();
            }).fail(function() { setTimeout(step, 5000); });
        })();
    })();

    /* =======================================================================
       START
    ======================================================================= */
    renderActivity(V.activity);
    var initial = readCache();
    if ( initial && initial.items ) {
        setItems(initial.items);
        loaded = true;
        renderAll();
    }
    showTab($('#erdo-app').attr('data-tab') || 'overview');
    loadStats();
});
