/* System Monitor - side panel and header bar on every page (since 1.01).
   Configuration: window.SystemMonitorWidget (hooks/all.php); values: pages/widget.php.
   The header and the panel stay in place while ResourceSpace loads pages with AJAX, so this runs once
   per full page load. Refreshing stops while the tab is hidden and after the configured idle time. */
(function () {
    'use strict';
    var C = window.SystemMonitorWidget;
    if (!C || (!C.panel && !C.bar) || window.SystemMonitorWidgetStarted) { return; }
    window.SystemMonitorWidgetStarted = true;

    var PCT = { cpu: 1, mem: 1, swap: 1, disk: 1 };      // sparklines on a 0-100 scale
    var STATE = ['OK', 'busy', 'overloaded'];
    var last = null, timer = null, failures = 0, stopped = false, lastInput = Date.now(), menuOpen = false, busy = false;
    var panel = null, bar = null, menu = null;

    // ---- small helpers ---------------------------------------------------------------------
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text != null) { e.textContent = text; }
        return e;
    }
    function store(k, v) {
        try { if (v === undefined) { return window.localStorage.getItem('smw-' + k); } window.localStorage.setItem('smw-' + k, v); } catch (e) { /* private mode */ }
        return null;
    }
    function label(k, i) { return (C.labels[k] || [k, k, ''])[i]; }
    function stateClass(s) { return 'smw-s' + (s == null ? 'n' : s); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function clock(t) { var d = new Date(t * 1000); return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }

    // Background and text colour of the page, so the widgets follow any theme (also dark mode)
    function themeColours(node) {
        var bg = '', fg = getComputedStyle(document.body).color;
        [document.getElementById('CentralSpaceContainer'), document.body, document.documentElement].some(function (e) {
            if (!e) { return false; }
            var c = getComputedStyle(e).backgroundColor;
            if (c && c !== 'transparent' && !/rgba\(.*,\s*0\)$/.test(c)) { bg = c; return true; }
            return false;
        });
        node.style.setProperty('--smw-bg', bg || '#fff');
        node.style.setProperty('--smw-fg', fg || '#222');
    }

    // Sparkline as inline SVG (no external libraries; allowed by the CSP of ResourceSpace)
    var NS = 'http://www.w3.org/2000/svg';
    function spark(values, key, w, h) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('class', 'smw-spark');
        svg.setAttribute('width', w);
        svg.setAttribute('height', h);
        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
        svg.setAttribute('aria-hidden', 'true');
        var pts = (values || []).map(function (v, i) { return [i, v]; }).filter(function (p) { return p[1] != null && !isNaN(p[1]); });
        if (pts.length < 2) { return svg; }
        var n = values.length - 1, max = PCT[key] ? 100 : 0;
        pts.forEach(function (p) { if (p[1] > max) { max = p[1]; } });
        if (max <= 0) { max = 1; }
        var d = pts.map(function (p, i) {
            return (i ? 'L' : 'M') + (p[0] / n * (w - 2) + 1).toFixed(1) + ' ' + (h - 1 - p[1] / max * (h - 2)).toFixed(1);
        }).join(' ');
        var area = document.createElementNS(NS, 'path');
        area.setAttribute('d', d + ' L' + (pts[pts.length - 1][0] / n * (w - 2) + 1).toFixed(1) + ' ' + h + ' L' + (pts[0][0] / n * (w - 2) + 1).toFixed(1) + ' ' + h + ' Z');
        area.setAttribute('class', 'smw-area');
        var line = document.createElementNS(NS, 'path');
        line.setAttribute('d', d);
        line.setAttribute('class', 'smw-line');
        svg.appendChild(area);
        svg.appendChild(line);
        return svg;
    }

    // One row of the panel / drop-down: label, value, detail, sparkline
    function row(k, data) {
        var v = (data && data.v[k]) || { t: '–', s: null, x: '' };
        var r = el('div', 'smw-row ' + stateClass(v.s));
        r.title = label(k, 2);
        var head = el('div', 'smw-rh');
        head.appendChild(el('span', 'smw-l', label(k, 0)));
        var val = el('b', 'smw-v', v.t);
        if (k === 'health' && v.s != null) { val.insertBefore(el('i', 'smw-dot'), val.firstChild); }
        head.appendChild(val);
        r.appendChild(head);
        var foot = el('div', 'smw-rf');
        foot.appendChild(el('small', 'smw-x', v.x || ''));
        if (data && data.spark[k]) { foot.appendChild(spark(data.spark[k], k, 90, 22)); }
        r.appendChild(foot);
        return r;
    }

    function worst(data, keys) {
        var w = null;
        keys.forEach(function (k) { var v = data && data.v[k]; if (v && v.s != null && (w == null || v.s > w)) { w = v.s; } });
        return w;
    }

    // ---- side panel ------------------------------------------------------------------------
    function buildPanel() {
        var P = C.panel;
        panel = el('div', 'smw smw-panel smw-' + (P.side === 'left' ? 'left' : 'right'));
        panel.id = 'smw-panel';
        var handle = el('button', 'smw-handle');
        handle.type = 'button';
        handle.setAttribute('aria-controls', 'smw-panel-body');
        handle.appendChild(el('i', 'smw-dot'));
        handle.appendChild(el('span', 'smw-htext', 'Monitor'));
        var body = el('div', 'smw-body');
        body.id = 'smw-panel-body';
        body.setAttribute('role', 'region');
        body.setAttribute('aria-label', 'System Monitor');
        var head = el('div', 'smw-head');
        head.appendChild(el('b', '', 'System Monitor'));
        head.appendChild(el('small', 'smw-time', ''));
        if (C.adminUrl) {
            var a = el('a', 'smw-open', '↗');
            a.href = C.adminUrl;
            a.title = 'Open System Monitor';
            head.appendChild(a);
        }
        var close = el('button', 'smw-close', '×');
        close.type = 'button';
        close.title = 'Close';
        head.appendChild(close);
        body.appendChild(head);
        body.appendChild(el('div', 'smw-list'));
        panel.appendChild(handle);
        panel.appendChild(body);
        document.body.appendChild(panel);

        var saved = store('open');
        setPanel(saved === null ? !!P.open : saved === '1', false);
        handle.addEventListener('click', function () { setPanel(!panel.classList.contains('smw-on'), true); });
        close.addEventListener('click', function () { setPanel(false, true); });
    }
    function setPanel(open, user) {
        panel.classList.toggle('smw-on', open);
        panel.querySelector('.smw-handle').setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.querySelector('.smw-handle').title = open ? 'Hide System Monitor' : 'Show System Monitor';
        if (user) { store('open', open ? '1' : '0'); }
        if (open) { themeColours(panel); renderPanel(); if (user) { refresh(true); } }
        schedule();
    }
    function renderPanel() {
        if (!panel) { return; }
        var keys = C.panel.metrics;
        var dot = panel.querySelector('.smw-handle .smw-dot');
        dot.className = 'smw-dot ' + stateClass(worst(last, keys));
        if (!panel.classList.contains('smw-on')) { return; }
        var list = panel.querySelector('.smw-list');
        list.textContent = '';
        keys.forEach(function (k) { list.appendChild(row(k, last)); });
        panel.querySelector('.smw-time').textContent = last ? 'updated ' + clock(last.t) : 'loading…';
    }

    // ---- header bar (before the Upload button) -----------------------------------------------
    // Where the bar goes: right before the Upload button.
    //   ResourceSpace 11: <header><div id="header-container"> ... <div class="actions"> icons ... <a class="UploadButton">
    //   ResourceSpace 10: <div id="HeaderNav1"><ul><li class="HeaderLink UploadButton"> ...
    function barPlace() {
        var actions = document.querySelector('#header-container .actions') || document.querySelector('header .actions');
        if (actions) {
            var up = actions.querySelector(':scope > a.UploadButton, :scope > .UploadButton');
            return { parent: actions, before: up || null, tag: 'div', rs: 'rs11' };
        }
        var ul = document.querySelector('#HeaderNav1 > ul');
        if (ul) {
            return { parent: ul, before: ul.querySelector(':scope > li.UploadButton') || ul.firstChild, tag: 'li', rs: 'rs10' };
        }
        return null;
    }
    function buildBar() {
        var place = barPlace();
        if (!place) {
            if (window.console) { console.info('System Monitor: header bar not shown - header markup of this ResourceSpace version not recognised'); }
            return;
        }
        var B = C.bar;
        bar = el(place.tag, 'smw-bar smw-' + place.rs + ' smw-style-' + B.style);
        bar.id = 'smw-bar';
        var a = el('a', 'smw-bar-link');
        a.href = '#';
        a.setAttribute('role', 'button');
        a.setAttribute('aria-haspopup', 'true');
        a.setAttribute('aria-expanded', 'false');
        a.title = 'System Monitor';
        bar.appendChild(a);
        place.parent.insertBefore(bar, place.before);

        menu = el('div', 'smw smw-menu');
        menu.id = 'smw-menu';
        menu.hidden = true;
        menu.setAttribute('role', 'dialog');
        menu.setAttribute('aria-label', 'System Monitor');
        document.body.appendChild(menu);

        a.addEventListener('click', function (e) { e.preventDefault(); setMenu(!menuOpen); });
        document.addEventListener('click', function (e) {
            if (menuOpen && !menu.contains(e.target) && !bar.contains(e.target)) { setMenu(false); }
        });
        document.addEventListener('keydown', function (e) { if (menuOpen && e.key === 'Escape') { setMenu(false); a.focus(); } });
        window.addEventListener('resize', function () { if (menuOpen) { setMenu(false); } });
        // the sticky header of ResourceSpace 11 moves / collapses while scrolling: follow it or close
        window.addEventListener('scroll', function () { if (menuOpen) { placeMenu(); } }, { passive: true });
    }
    function setMenu(open) {
        menuOpen = open;
        menu.hidden = !open;
        bar.querySelector('a').setAttribute('aria-expanded', open ? 'true' : 'false');
        bar.classList.toggle('smw-active', open);
        if (open) {
            placeMenu();
            themeColours(menu);
            renderMenu();
            refresh(true);
        }
        schedule();
    }
    function placeMenu() {
        var r = bar.getBoundingClientRect();
        if (r.height === 0 || r.bottom < 0) { setMenu(false); return; } // header collapsed or scrolled away
        menu.style.top = Math.round(r.bottom + 6) + 'px';
        menu.style.right = Math.max(8, Math.round(window.innerWidth - r.right)) + 'px';
    }
    function renderBar() {
        if (!bar) { return; }
        var B = C.bar, a = bar.querySelector('a');
        a.textContent = '';
        B.bar.forEach(function (k) {
            var v = (last && last.v[k]) || { t: '…', s: null, p: null };
            var item = el('span', 'smw-i ' + stateClass(v.s));
            item.title = label(k, 0) + ': ' + v.t + (v.s != null ? ' (' + STATE[v.s] + ')' : '');
            if (k === 'health') {
                item.appendChild(el('i', 'smw-dot'));
                item.appendChild(el('b', '', v.t));
            } else {
                item.appendChild(el('small', '', label(k, 1)));
                item.appendChild(el('b', '', v.t));
                if (B.style === 'spark' && last && last.spark[k]) {
                    item.appendChild(spark(last.spark[k], k, 38, 16));
                } else if (B.style === 'bar' && v.p != null) {
                    var m = el('i', 'smw-meter'), f = el('i');
                    f.style.width = v.p + '%';
                    m.appendChild(f);
                    item.appendChild(m);
                }
            }
            a.appendChild(item);
        });
        var caret = el('i', 'smw-caret');
        caret.setAttribute('aria-hidden', 'true');
        a.appendChild(caret);
        if (menuOpen) { renderMenu(); }
    }
    function renderMenu() {
        menu.textContent = '';
        var head = el('div', 'smw-head');
        head.appendChild(el('b', '', 'System status'));
        head.appendChild(el('small', 'smw-time', last ? 'updated ' + clock(last.t) : 'loading…'));
        menu.appendChild(head);
        var list = el('div', 'smw-list');
        C.bar.menu.forEach(function (k) { list.appendChild(row(k, last)); });
        menu.appendChild(list);
        if (C.adminUrl) {
            var a = el('a', 'smw-more', 'Open System Monitor →');
            a.href = C.adminUrl;
            menu.appendChild(a);
        }
    }

    // ---- refreshing --------------------------------------------------------------------------
    function seconds() {
        var s = [];
        if (panel && panel.classList.contains('smw-on')) { s.push(C.panel.seconds); }
        if (bar) { s.push(menuOpen ? Math.min(C.bar.seconds, 10) : C.bar.seconds); }
        if (!s.length) { s.push(Math.max(60, C.panel ? C.panel.seconds : 60)); } // only the status dot of the closed panel
        return Math.min.apply(null, s);
    }
    function idle() { return C.idle > 0 && Date.now() - lastInput > C.idle * 1000; }
    function schedule() {
        clearTimeout(timer);
        if (stopped || document.hidden || idle()) { return; }
        var wait = seconds() * 1000 * Math.min(16, Math.pow(2, failures));
        timer = setTimeout(function () { refresh(false); }, wait);
    }
    function refresh(now) {
        if (stopped || busy) { return; }
        if (!now && (document.hidden || idle())) { schedule(); return; }
        busy = true;
        var x = new XMLHttpRequest();
        x.open('GET', C.url + '?_=' + Date.now(), true);
        x.setRequestHeader('Accept', 'application/json');
        x.onload = function () {
            busy = false;
            var data = null;
            try { data = JSON.parse(x.responseText); } catch (e) { data = null; }
            if (x.status === 401 || x.status === 403) { stopped = true; return; } // logged out or no longer allowed
            if (x.status !== 200 || !data || !data.v) { failures++; schedule(); return; }
            failures = 0;
            last = data;
            renderPanel();
            renderBar();
            schedule();
        };
        x.onerror = function () { busy = false; failures++; schedule(); };
        x.send();
    }

    function start() {
        if (C.panel) { buildPanel(); }
        if (C.bar) { buildBar(); }
        if (!panel && !bar) { return; }
        renderPanel();
        renderBar();
        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (ev) {
            document.addEventListener(ev, function () {
                var wasIdle = idle();
                lastInput = Date.now();
                if (wasIdle) { refresh(true); }
            }, { passive: true, capture: true });
        });
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(true); } else { clearTimeout(timer); } });
        refresh(true);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
