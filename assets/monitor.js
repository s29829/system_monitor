/* System Monitor - options page: tabs, live view, history and ResourceSpace charts.
   Small canvas chart engine of its own (no external libraries, works with the CSP of ResourceSpace). */
(function () {
    'use strict';
    var C = window.SystemMonitorConfig || {};
    var COLORS = ['#2a78d6', '#e8743b', '#2e9d5b', '#8e5bd6', '#d64545', '#b59a00', '#1aa3b8', '#7a7a7a'];

    // ---- formatting ----------------------------------------------------------------------
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v, d) {
        if (v == null || isNaN(v)) { return '–'; }
        return Number(v).toLocaleString(undefined, { maximumFractionDigits: d == null ? 1 : d, minimumFractionDigits: 0 });
    }
    function bytes(v, d) {
        if (v == null || isNaN(v)) { return '–'; }
        var u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'], i = 0;
        v = Number(v);
        while (Math.abs(v) >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return num(v, i === 0 ? 0 : (d == null ? 1 : d)) + ' ' + u[i];
    }
    var FMT = {
        pct: function (v) { return num(v) + '%'; },
        num: function (v) { return num(v); },
        int: function (v) { return num(v, 0); },
        bytes: function (v) { return bytes(v); },
        rate: function (v) { return bytes(v) + '/s'; },
        rps: function (v) { return num(v, 2) + '/s'; }
    };
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function tlabel(t, span) {
        var d = new Date(t * 1000);
        if (span <= 2 * 86400) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
        if (span <= 120 * 86400) { return pad(d.getDate()) + '.' + pad(d.getMonth() + 1); }
        return pad(d.getMonth() + 1) + '.' + d.getFullYear();
    }
    function tfull(t, span) {
        var d = new Date(t * 1000);
        var day = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        return span > 120 * 86400 ? day.slice(0, 7) : (span > 3 * 86400 ? day : day + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()));
    }
    function niceStep(range, n) {
        var raw = range / Math.max(1, n), p = Math.pow(10, Math.floor(Math.log10(raw))), m = raw / p;
        return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 5 ? 5 : 10) * p;
    }

    // ---- chart -----------------------------------------------------------------------------
    // opts: {series: [{label, color, dash, fill}], format: 'pct'|..., max: 100, type: 'line'|'bar', span: seconds}
    function Chart(el, opts) {
        this.el = el;
        this.opts = opts || {};
        this.el.innerHTML = '';
        this.canvas = document.createElement('canvas');
        this.legend = document.createElement('div');
        this.legend.className = 'sm-legend';
        this.tip = document.createElement('div');
        this.tip.className = 'sm-tip';
        this.tip.hidden = true;
        el.appendChild(this.canvas);
        el.appendChild(this.legend);
        el.appendChild(this.tip);
        this.t = [];
        this.ys = [];
        var self = this;
        this.canvas.addEventListener('mousemove', function (e) { self.hover(e); });
        this.canvas.addEventListener('mouseleave', function () { self.tip.hidden = true; self.hoverIdx = null; self.draw(); });
        if (window.ResizeObserver) {
            new ResizeObserver(function () { self.draw(); }).observe(el);
        }
    }
    Chart.prototype.setData = function (t, ys, span) {
        this.t = t || [];
        this.ys = ys || [];
        if (span) { this.opts.span = span; }
        this.renderLegend();
        this.draw();
    };
    Chart.prototype.renderLegend = function () {
        var f = FMT[this.opts.format || 'num'], ys = this.ys;
        this.legend.innerHTML = (this.opts.series || []).map(function (s, i) {
            var last = null, arr = ys[i] || [];
            for (var k = arr.length - 1; k >= 0; k--) { if (arr[k] != null) { last = arr[k]; break; } }
            return '<span><i style="background:' + (s.color || COLORS[i % COLORS.length]) + '"></i>' + esc(s.label) +
                (s.noLast ? '' : ' <b>' + esc(last == null ? '–' : f(last)) + '</b>') + '</span>';
        }).join('');
    };
    Chart.prototype.geom = function () {
        var w = this.el.clientWidth, h = this.opts.height || 190;
        return { w: w, h: h, l: 52, r: 10, t: 10, b: 22 };
    };
    Chart.prototype.draw = function () {
        var g = this.geom();
        if (g.w <= 0) { return; }
        var dpr = window.devicePixelRatio || 1, c = this.canvas;
        c.width = g.w * dpr; c.height = g.h * dpr;
        c.style.width = g.w + 'px'; c.style.height = g.h + 'px';
        var ctx = c.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, g.w, g.h);
        var fg = getComputedStyle(this.el).color || '#888';
        ctx.font = '11px sans-serif';
        var t = this.t, ys = this.ys, n = t.length, opts = this.opts, series = opts.series || [];
        var stacked = opts.type === 'bar' && opts.stacked;
        var max = 0;
        for (var i = 0; i < n; i++) {
            var sum = 0;
            for (var s = 0; s < ys.length; s++) {
                var v = ys[s] && ys[s][i];
                if (v != null) { if (stacked) { sum += v; } else if (v > max) { max = v; } }
            }
            if (stacked && sum > max) { max = sum; }
        }
        if (opts.max && (max <= opts.max || opts.fixedMax)) { max = opts.max; }
        if (max <= 0) { max = opts.format === 'pct' ? 100 : 1; }
        var step = niceStep(max, 4);
        max = Math.ceil(max / step) * step;
        var pw = g.w - g.l - g.r, ph = g.h - g.t - g.b;
        var f = FMT[opts.format || 'num'];
        // grid and y axis
        ctx.strokeStyle = fg; ctx.fillStyle = fg; ctx.globalAlpha = 0.15; ctx.lineWidth = 1;
        for (var y = 0; y <= max + 1e-9; y += step) {
            var py = Math.round(g.t + ph - y / max * ph) + 0.5;
            ctx.beginPath(); ctx.moveTo(g.l, py); ctx.lineTo(g.l + pw, py); ctx.stroke();
        }
        ctx.globalAlpha = 0.75; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
        for (y = 0; y <= max + 1e-9; y += step) {
            ctx.fillText(opts.format === 'pct' ? num(y, 0) + '%' : f(y).replace('/s', ''), g.l - 6, g.t + ph - y / max * ph);
        }
        if (n === 0) {
            ctx.textAlign = 'center'; ctx.fillText('No data yet', g.l + pw / 2, g.t + ph / 2);
            ctx.globalAlpha = 1; return;
        }
        var span = opts.span || (t[n - 1] - t[0]) || 1;
        var xAt = opts.type === 'bar'
            ? function (k) { return g.l + (k + 0.5) * pw / n; }
            : function (k) { return n === 1 ? g.l + pw / 2 : g.l + k / (n - 1) * pw; };
        // x labels
        ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        var every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(pw / 70))));
        for (i = 0; i < n; i += every) { ctx.fillText(tlabel(t[i], span), xAt(i), g.t + ph + 6); }
        ctx.globalAlpha = 1;
        this.xAt = xAt; this.g = g;
        if (opts.type === 'bar') {
            var bw = Math.max(1, pw / n * 0.8 / (stacked ? 1 : ys.length));
            for (i = 0; i < n; i++) {
                var base = 0;
                for (s = 0; s < ys.length; s++) {
                    v = ys[s] && ys[s][i];
                    if (v == null) { continue; }
                    var bh = v / max * ph;
                    ctx.fillStyle = (series[s] && series[s].color) || COLORS[s % COLORS.length];
                    var bx = stacked ? xAt(i) - bw / 2 : xAt(i) - pw / n * 0.4 + s * bw;
                    ctx.fillRect(bx, g.t + ph - base - bh, bw, bh);
                    if (stacked) { base += bh; }
                }
            }
        } else {
            for (s = ys.length - 1; s >= 0; s--) {
                var arr = ys[s] || [], col = (series[s] && series[s].color) || COLORS[s % COLORS.length];
                ctx.strokeStyle = col; ctx.lineWidth = series[s] && series[s].thin ? 1 : 1.8;
                ctx.setLineDash(series[s] && series[s].dash ? [4, 3] : []);
                var started = false, firstX = null, lastX = null;
                ctx.beginPath();
                for (i = 0; i < n; i++) {
                    if (arr[i] == null) { started = false; continue; }
                    var x = xAt(i), yy = g.t + ph - Math.min(arr[i], max) / max * ph;
                    if (!started) { ctx.moveTo(x, yy); started = true; if (firstX === null) { firstX = x; } } else { ctx.lineTo(x, yy); }
                    lastX = x;
                }
                ctx.stroke();
                if (series[s] && series[s].fill && firstX !== null) {
                    ctx.globalAlpha = 0.12; ctx.fillStyle = col; ctx.beginPath(); started = false;
                    for (i = 0; i < n; i++) {
                        if (arr[i] == null) {
                            if (started) { ctx.lineTo(xAt(i - 1), g.t + ph); ctx.closePath(); started = false; }
                            continue;
                        }
                        x = xAt(i); yy = g.t + ph - Math.min(arr[i], max) / max * ph;
                        if (!started) { ctx.moveTo(x, g.t + ph); ctx.lineTo(x, yy); started = true; } else { ctx.lineTo(x, yy); }
                    }
                    if (started) { ctx.lineTo(lastX, g.t + ph); ctx.closePath(); }
                    ctx.fill(); ctx.globalAlpha = 1;
                }
            }
            ctx.setLineDash([]);
        }
        if (this.hoverIdx != null && this.hoverIdx < n) {
            ctx.strokeStyle = fg; ctx.globalAlpha = 0.4;
            ctx.beginPath(); ctx.moveTo(xAt(this.hoverIdx) + 0.5, g.t); ctx.lineTo(xAt(this.hoverIdx) + 0.5, g.t + ph); ctx.stroke();
            ctx.globalAlpha = 1;
        }
    };
    Chart.prototype.hover = function (e) {
        var n = this.t.length;
        if (!n || !this.g) { return; }
        var rect = this.canvas.getBoundingClientRect(), x = e.clientX - rect.left, g = this.g, pw = g.w - g.l - g.r;
        var k = this.opts.type === 'bar' ? Math.floor((x - g.l) / pw * n) : Math.round((x - g.l) / pw * (n - 1));
        k = Math.max(0, Math.min(n - 1, k));
        this.hoverIdx = k;
        this.draw();
        var f = FMT[this.opts.format || 'num'], ys = this.ys, span = this.opts.span || (this.t[n - 1] - this.t[0]);
        var html = '<b>' + esc(tfull(this.t[k], span)) + '</b>';
        (this.opts.series || []).forEach(function (s, i) {
            var v = ys[i] ? ys[i][k] : null;
            html += '<div><i style="background:' + (s.color || COLORS[i % COLORS.length]) + '"></i>' + esc(s.label) + ': ' + esc(v == null ? '–' : f(v)) + '</div>';
        });
        this.tip.innerHTML = html;
        this.tip.hidden = false;
        var tx = this.xAt(k) + 12;
        if (tx + this.tip.offsetWidth > g.w) { tx = this.xAt(k) - this.tip.offsetWidth - 12; }
        this.tip.style.left = Math.max(0, tx) + 'px';
        this.tip.style.top = '8px';
    };

    // ---- data -----------------------------------------------------------------------------
    function get(params) {
        var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch(C.dataUrl + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            });
    }
    function $(id) { return document.getElementById(id); }
    function tile(label, value, sub, level) {
        return '<div class="sm-tile' + (level ? ' sm-' + level : '') + '"><span>' + esc(label) + '</span><b>' + esc(value) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</div>';
    }
    function level(v, warn, crit) { return v == null ? '' : (v >= crit ? 'crit' : (v >= warn ? 'warnv' : '')); }
    function table(head, rows) {
        if (!rows.length) { return '<p class="sm-muted">No data.</p>'; }
        return '<table class="sm-table"><tr>' + head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') + '</tr>' +
            rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</table>';
    }
    function bar(v, max) {
        var p = max > 0 ? Math.round(100 * v / max) : 0;
        return '<span class="sm-bar"><i style="width:' + p + '%"></i></span>';
    }

    // ---- live -----------------------------------------------------------------------------
    var live = { t: [], cpu: [], iow: [], mem: [], cache: [], swap: [], g: {}, timer: null, charts: null };
    function liveCharts() {
        if (live.charts) { return live.charts; }
        var gs = Object.keys(C.groups || {});
        live.charts = {
            cpu: new Chart($('sm-live-cpu'), { format: 'pct', max: 100, span: 600, series: [{ label: 'CPU total', fill: true }, { label: 'I/O wait', dash: true }]
                .concat(gs.map(function (k, i) { return { label: C.groups[k].split(' (')[0], color: COLORS[(i + 2) % COLORS.length], thin: true }; })) }),
            mem: new Chart($('sm-live-mem'), { format: 'pct', max: 100, span: 600, series: [{ label: 'Used', fill: true }, { label: 'Cache', dash: true }, { label: 'Swap', color: COLORS[4] }] })
        };
        return live.charts;
    }
    function liveTick() {
        get({ what: 'live' }).then(function (d) {
            var s = d.sample, ch = liveCharts(), gs = Object.keys(C.groups || {});
            live.t.push(s.t); live.cpu.push(s.cpu); live.iow.push(s.iow); live.mem.push(s.mem); live.swap.push(s.swap);
            live.cache.push(s.mem_total ? Math.round(1000 * s.mem_cache / s.mem_total) / 10 : null);
            gs.forEach(function (k) { (live.g[k] = live.g[k] || []).push(s[k + '_cpu']); });
            while (live.t.length && live.t[0] < s.t - 600) {
                live.t.shift(); live.cpu.shift(); live.iow.shift(); live.mem.shift(); live.swap.shift(); live.cache.shift();
                gs.forEach(function (k) { live.g[k].shift(); });
            }
            ch.cpu.setData(live.t, [live.cpu, live.iow].concat(gs.map(function (k) { return live.g[k]; })));
            ch.mem.setData(live.t, [live.mem, live.cache, live.swap]);
            renderLive(d);
        }).catch(function (e) {
            $('sm-live-tiles').innerHTML = '<p class="sm-err">Live data could not be loaded (' + esc(e.message) + '). Is your session still valid?</p>';
        });
    }
    function renderLive(d) {
        var s = d.sample, f = d.fpm, fs = (d.space || {}).filestore;
        var tiles = [
            tile('CPU', s.cpu == null ? '–' : num(s.cpu) + '%', s.cores ? s.cores + ' cores, load ' + num(s.load1, 2) : '', level(s.cpu, 70, 90)),
            tile('Memory', s.mem == null ? '–' : num(s.mem) + '%', s.mem_total ? bytes(s.mem_used) + ' of ' + bytes(s.mem_total) : '', level(s.mem, 80, 92)),
            tile('Swap', s.swap == null ? '–' : num(s.swap) + '%', '', level(s.swap, 20, 50)),
            tile('Active users', s.u5 == null ? '–' : num(s.u5, 0), 'last 5 min · ' + num(s.ua, 0) + ' in ' + C.activeMinutes + ' min'),
            tile('PHP-FPM workers', f ? f.active + ' / ' + f.total : '–', f ? 'busy / running, queue ' + f.queue : 'not available', f && f.queue > 0 ? 'crit' : ''),
            tile('Requests', s.ng_rps != null ? num(s.ng_rps, 1) + '/s' : (s.fpm_rps != null ? num(s.fpm_rps, 1) + '/s' : '–'), s.ng_conn != null ? s.ng_conn + ' nginx connections' : (s.fpm_rps != null ? 'PHP requests' : 'stub_status not set')),
            tile('Database', s.db_conn == null ? '–' : s.db_conn + ' conn.', s.db_qps != null ? num(s.db_qps, 0) + ' queries/s, ' + s.db_run + ' running' : ''),
            tile('Network', s.net_rx == null ? '–' : '↓ ' + bytes(s.net_rx) + '/s', s.net_tx == null ? '' : '↑ ' + bytes(s.net_tx) + '/s'),
            tile('Disk I/O', s.dk_r == null ? '–' : 'R ' + bytes(s.dk_r) + '/s', s.dk_w == null ? '' : 'W ' + bytes(s.dk_w) + '/s'),
            tile('Filestore disk', s.disk_pct == null ? '–' : num(s.disk_pct) + '%', fs ? bytes(fs.free) + ' free' : '', level(s.disk_pct, 85, 95)),
            tile('Offline jobs', s.jobs == null ? '–' : num(s.jobs, 0), s.jobs_err ? s.jobs_err + ' failed' : 'waiting', s.jobs_err ? 'warnv' : '')
        ];
        $('sm-live-tiles').innerHTML = tiles.join('');
        // processes
        var rows = Object.keys(d.groups || {}).map(function (k) {
            return [esc(d.groups[k]), num(s[k + '_n'], 0), s[k + '_cpu'] == null ? '–' : num(s[k + '_cpu']) + '%', bytes(s[k + '_mem'])];
        });
        $('sm-live-procs').innerHTML = table(['Group', 'Processes', 'CPU', 'Memory'], rows) +
            (d.visible != null ? '<p class="sm-note">' + num(d.visible, 0) + ' processes visible on the server' + (d.uptime ? ', uptime ' + esc(dur(d.uptime)) : '') + '.</p>' : '');
        // services
        var html = '';
        if (f) {
            html += table(['PHP-FPM pool ' + f.pool, ''], [
                ['Process manager', esc(f.pm)], ['Busy / idle / running', f.active + ' / ' + f.idle + ' / ' + f.total],
                ['Highest busy since start', num(f.max_active, 0)], ['Queue (now / highest)', f.queue + ' / ' + f.max_queue],
                ['"Max children reached"', '<span class="' + (f.mcr > 0 ? 'sm-warntext' : '') + '">' + num(f.mcr, 0) + '</span>'],
                ['Slow requests', num(f.slow, 0)], ['Requests since start', num(f.accepted, 0) + ' (' + esc(dur(f.since)) + ')']
            ]);
        } else {
            html += '<p class="sm-muted">PHP-FPM status not available - see System check.</p>';
        }
        if (d.opcache) {
            html += d.opcache.enabled ? table(['OPcache', ''], [['Hit rate', num(d.opcache.hit_rate, 2) + '%'], ['Memory used', num(d.opcache.mem_pct) + '% of ' + bytes(d.opcache.mem)],
                ['Cached scripts', num(d.opcache.scripts, 0) + ' / ' + num(d.opcache.max_keys, 0)], ['Restarts (out of memory / keys)', num(d.opcache.restarts, 0)]]) : '<p class="sm-warntext">OPcache is off.</p>';
        }
        var sp = d.space || {};
        html += table(['Disk', 'Used', 'Free'], Object.keys(sp).map(function (k) {
            var x = sp[k], used = x.total - x.free;
            return [esc(k === 'filestore' ? 'Filestore' : 'System (/)') + '<br><small class="sm-muted">' + esc(x.path) + '</small>', bar(used, x.total) + ' ' + num(100 * used / x.total) + '%', bytes(x.free) + ' of ' + bytes(x.total)];
        }));
        $('sm-live-services').innerHTML = html;
        // users
        var users = d.users || [];
        $('sm-users-note').textContent = users.length + ' in the last ' + C.activeMinutes + ' minutes';
        $('sm-live-users').innerHTML = table(['User', 'Name', 'Group', 'Last activity'], users.map(function (u) {
            return [esc(u.username), esc(u.fullname), esc(u.groupname), esc(u.mins) + ' min ago'];
        }));
    }
    function dur(s) {
        s = Math.round(s);
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
        return d > 0 ? d + 'd ' + h + 'h' : (h > 0 ? h + 'h ' + m + 'min' : m + ' min');
    }
    function startLive() {
        if (live.timer) { return; }
        liveTick();
        live.timer = setInterval(function () {
            // ResourceSpace loads pages with AJAX: stop when this page is no longer shown
            if (!document.body.contains(page)) { stopLive(); return; }
            if (!document.hidden) { liveTick(); }
        }, Math.max(2, C.liveSeconds || 3) * 1000);
    }
    function stopLive() { if (live.timer) { clearInterval(live.timer); live.timer = null; } }

    // ---- history --------------------------------------------------------------------------
    var HIST = [
        { title: 'CPU', format: 'pct', max: 100, keys: [['cpu', 'Average'], ['cpu', 'Maximum', 'x'], ['iow', 'I/O wait']] },
        { title: 'Memory', format: 'pct', max: 100, keys: [['mem', 'Used'], ['mem', 'Maximum', 'x'], ['swap', 'Swap']] },
        { title: 'Load average', format: 'num', keys: [['load1', '1 min'], ['load15', '15 min']] },
        { title: 'Active users', format: 'int', keys: [['ua', 'Active (' + (C.activeMinutes || 15) + ' min)'], ['u5', 'Last 5 min'], ['ua', 'Maximum', 'x']] },
        { title: 'CPU by process group', format: 'pct', group: '_cpu' },
        { title: 'Number of processes', format: 'int', group: '_n' },
        { title: 'Memory by process group (RSS)', format: 'bytes', group: '_mem' },
        { title: 'PHP-FPM workers', format: 'int', keys: [['fpm_act', 'Busy'], ['fpm_act', 'Busy (max)', 'x'], ['fpm_tot', 'Running'], ['fpm_q', 'Queue (max)', 'x'], ['fpm_mcr', 'Max children reached', 'x']] },
        { title: 'Requests per second', format: 'rps', keys: [['ng_rps', 'nginx'], ['fpm_rps', 'PHP-FPM']] },
        { title: 'nginx connections', format: 'int', keys: [['ng_conn', 'Active'], ['ng_conn', 'Maximum', 'x'], ['ng_write', 'Writing']] },
        { title: 'Database', format: 'num', keys: [['db_conn', 'Connections'], ['db_run', 'Running (max)', 'x'], ['db_qps', 'Queries/s']] },
        { title: 'Network', format: 'rate', keys: [['net_tx', 'Sent (downloads)'], ['net_rx', 'Received (uploads)'], ['net_tx', 'Sent (max)', 'x']] },
        { title: 'Disk I/O', format: 'rate', keys: [['dk_r', 'Read'], ['dk_w', 'Write']] },
        { title: 'Filestore disk used', format: 'pct', max: 100, keys: [['disk_pct', 'Used']] },
        { title: 'Offline jobs waiting', format: 'int', keys: [['jobs', 'Waiting'], ['jobs_err', 'Failed']] }
    ];
    var hist = { range: '24h', charts: null };
    function histCharts() {
        if (hist.charts) { return hist.charts; }
        var box = $('sm-hist-charts'), gs = Object.keys(C.groups || {});
        hist.charts = HIST.map(function (def) {
            var keys = def.group ? gs.map(function (k) { return [k + def.group, C.groups[k].split(' (')[0]]; }) : def.keys;
            var div = document.createElement('div');
            div.className = 'sm-box';
            div.innerHTML = '<h3>' + esc(def.title) + '</h3><div class="sm-chart"></div>';
            box.appendChild(div);
            var series = keys.map(function (k, i) { return { label: k[1], dash: k[2] === 'x' && i > 0, fill: i === 0 && !def.group }; });
            return { def: def, keys: keys, chart: new Chart(div.querySelector('.sm-chart'), { format: def.format, max: def.max, series: series }) };
        });
        return hist.charts;
    }
    function loadHistory() {
        var charts = histCharts();
        $('sm-hist-note').textContent = 'Loading…';
        get({ what: 'history', range: hist.range }).then(function (d) {
            var span = d.to - d.from;
            charts.forEach(function (c) {
                c.chart.setData(d.t, c.keys.map(function (k) { return (d[k[2] === 'x' ? 'x' : 'a'][k[0]]) || []; }), span);
            });
            var empty = !d.t.length || !Object.keys(d.a).length;
            $('sm-hist-note').textContent = empty ? 'No samples in this range yet - history builds up from now on.'
                : 'One point = ' + (d.step >= 3600 ? (d.step / 3600) + ' h' : Math.round(d.step / 60) + ' min') + ' (' + (d.source === 'minute' ? 'minute samples' : 'hourly history') + '); dashed = maximum.';
        }).catch(function (e) { $('sm-hist-note').textContent = 'Could not be loaded: ' + e.message; });
    }
    function initRanges() {
        var box = $('sm-ranges');
        box.innerHTML = Object.keys(C.ranges || {}).map(function (k) { return '<button type="button" data-range="' + esc(k) + '">' + esc(C.ranges[k]) + '</button>'; }).join('');
        box.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-range]');
            if (b) { hist.range = b.getAttribute('data-range'); store('range', hist.range); markSeg(box, 'data-range', hist.range); loadHistory(); }
        });
        hist.range = load('range') || '24h';
        markSeg(box, 'data-range', hist.range);
    }

    // ---- ResourceSpace --------------------------------------------------------------------
    var RS = [
        { title: 'Users', format: 'int', type: 'bar', keys: [['users', 'Unique users']], note: { hour: 'highest number of active users per hour (from the samples)', day: 'unique users per day', month: 'unique users per month' } },
        { title: 'Downloads', format: 'int', type: 'bar', keys: [['downloads', 'Downloads']] },
        { title: 'Downloaded data', format: 'bytes', type: 'bar', keys: [['download_bytes', 'Downloaded']], note: { hour: 'not available per hour (ResourceSpace counts it per day)' } },
        { title: 'Uploads (new resources)', format: 'int', type: 'bar', keys: [['uploads', 'New resources']] },
        { title: 'Uploaded data', format: 'bytes', type: 'bar', keys: [['upload_bytes', 'Size of new resources']] },
        { title: 'Views and searches', format: 'int', type: 'bar', keys: [['views', 'Resource views'], ['searches', 'Searches']] },
        { title: 'Logins', format: 'int', type: 'bar', keys: [['logins', 'Successful'], ['failed_logins', 'Failed']], note: { hour: 'only in the Days scale', month: 'only in the Days scale' } }
    ];
    var rs = { scale: 'day', charts: null };
    function rsCharts() {
        if (rs.charts) { return rs.charts; }
        var box = $('sm-rs-charts');
        rs.charts = RS.map(function (def) {
            var div = document.createElement('div');
            div.className = 'sm-box';
            div.innerHTML = '<h3>' + esc(def.title) + ' <small></small></h3><div class="sm-chart"></div>';
            box.appendChild(div);
            var series = def.keys.map(function (k, i) { return { label: k[1], color: def.title === 'Logins' && i === 1 ? COLORS[4] : COLORS[i] }; });
            return { def: def, small: div.querySelector('small'), chart: new Chart(div.querySelector('.sm-chart'), { format: def.format, type: def.type, series: series }) };
        });
        return rs.charts;
    }
    function loadRs() {
        var charts = rsCharts();
        $('sm-rs-note').textContent = 'Loading…';
        get({ what: 'rs', scale: rs.scale }).then(function (d) {
            var s = d.series, span = rs.scale === 'hour' ? 48 * 3600 : (rs.scale === 'day' ? 90 * 86400 : 730 * 86400);
            charts.forEach(function (c) {
                c.chart.setData(s.t, c.def.keys.map(function (k) { return s.series[k[0]] || []; }), span);
                c.small.textContent = (c.def.note && c.def.note[rs.scale]) || '';
            });
            $('sm-rs-note').textContent = 'Updated ' + new Date(s.time * 1000).toLocaleTimeString() + ' (cached for a few minutes).';
            renderRs(d);
        }).catch(function (e) { $('sm-rs-note').textContent = 'Could not be loaded: ' + e.message; });
    }
    function renderRs(d) {
        var o = d.overview, jobs = o.jobs || {}, j = function (st) { return jobs[st] ? jobs[st].n : 0; };
        $('sm-rs-tiles').innerHTML = [
            tile('Resources', num(o.resources, 0), bytes(o.size) + ' (active, pending, archived)'),
            tile('Without preview', num(o.nopreview, 0), 'files without a preview image', o.nopreview > 0 ? 'warnv' : ''),
            tile('Users', num(o.approved, 0), num(o.active30, 0) + ' active in 30 days'),
            tile('Collections', num(o.collections, 0), ''),
            tile('External shares', num(o.shares, 0), o.shares_noexpiry + ' without expiry date', o.shares_noexpiry > 0 ? 'warnv' : ''),
            tile('Offline jobs', num(j(1), 0) + ' waiting', j(5) + ' failed, ' + j(3) + ' running', j(5) > 0 ? 'warnv' : '')
        ].join('');
        var maxT = Math.max.apply(null, (o.types || []).map(function (t) { return t.n; }).concat([1]));
        $('sm-rs-types').innerHTML = table(['Type', 'Resources', '', 'Size'], (o.types || []).map(function (t) {
            return [esc(t.name), num(t.n, 0), bar(t.n, maxT), bytes(t.size)];
        }));
        var maxS = Math.max.apply(null, (o.states || []).map(function (t) { return t.n; }).concat([1]));
        $('sm-rs-states').innerHTML = table(['State', 'Resources', ''], (o.states || []).map(function (t) {
            return [esc(t.name) + ' <small class="sm-muted">(' + t.id + ')</small>', num(t.n, 0), bar(t.n, maxS)];
        }));
        var names = { 0: 'Disabled', 1: 'Waiting', 2: 'Completed', 3: 'Running', 5: 'Failed' };
        $('sm-rs-jobs').innerHTML = table(['Status', 'Jobs', 'Oldest'], Object.keys(jobs).map(function (k) {
            return [esc(names[k] || ('Status ' + k)), num(jobs[k].n, 0), esc(jobs[k].oldest || '')];
        })) + (o.failed && o.failed.length ? '<p><b>Last failed jobs</b></p>' + table(['#', 'Type', 'Started', 'Error'], o.failed.map(function (f) {
            return [esc(f.ref), esc(f.type), esc(f.start_date), esc(f.failure_text)];
        })) : '');
        var db = d.db || { top: [] };
        $('sm-rs-db').innerHTML = '<p>Total <b>' + esc(bytes(db.total)) + '</b></p>' + table(['Table', 'Rows (approx.)', 'Size'], db.top.map(function (t) {
            return [esc(t.name), num(t.rows, 0), bytes(t.size)];
        }));
        var top = d.top || {};
        $('sm-top-res').innerHTML = table(['Resource', 'Downloads'], (top.resources || []).map(function (r) {
            return ['<a href="' + esc(C.viewUrl + r.ref) + '">' + esc(r.ref) + '</a>', num(r.n, 0)];
        }));
        $('sm-top-search').innerHTML = table(['Search', 'Count'], (top.searches || []).map(function (r) { return [esc(r.q), num(r.n, 0)]; }));
        $('sm-top-down').innerHTML = table(['User', 'Downloads'], (top.downloaders || []).map(function (r) { return [esc(r.username) + ' <small class="sm-muted">' + esc(r.fullname) + '</small>', num(r.n, 0)]; }));
        $('sm-top-up').innerHTML = table(['User', 'Resources', 'Size'], (top.uploaders || []).map(function (r) { return [esc(r.username) + ' <small class="sm-muted">' + esc(r.fullname) + '</small>', num(r.n, 0), bytes(r.size)]; }));
    }
    function initScales() {
        var box = $('sm-scales');
        box.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-scale]');
            if (b) { rs.scale = b.getAttribute('data-scale'); store('scale', rs.scale); markSeg(box, 'data-scale', rs.scale); loadRs(); }
        });
        rs.scale = load('scale') || 'day';
        markSeg(box, 'data-scale', rs.scale);
    }

    // ---- tabs -----------------------------------------------------------------------------
    function store(k, v) { try { localStorage.setItem('system_monitor_' + k, v); } catch (e) { /* storage blocked */ } }
    function load(k) { try { return localStorage.getItem('system_monitor_' + k); } catch (e) { return null; } }
    function markSeg(box, attr, val) {
        box.querySelectorAll('button[' + attr + ']').forEach(function (b) { b.classList.toggle('sm-on', b.getAttribute(attr) === val); });
    }
    var loaded = {}, page = null;
    function show(tab) {
        var tabs = document.querySelectorAll('.sm-tab');
        if (!document.getElementById('sm-tab-' + tab)) { tab = 'live'; }
        tabs.forEach(function (t) { t.hidden = t.getAttribute('data-tab') !== tab; });
        markSeg(document.querySelector('.sm-tabs'), 'data-tab', tab);
        store('tab', tab);
        if (tab === 'live') { startLive(); } else { stopLive(); }
        if (tab === 'history' && !loaded.history) { loaded.history = true; loadHistory(); }
        if (tab === 'rs' && !loaded.rs) { loaded.rs = true; loadRs(); }
        if (location.hash !== '#' + tab) { history.replaceState(null, '', '#' + tab); }
    }
    function init() {
        page = document.querySelector('.sm-page');
        if (!page) { return; }
        initRanges();
        initScales();
        document.querySelector('.sm-tabs').addEventListener('click', function (e) {
            var b = e.target.closest('button[data-tab]');
            if (b) { show(b.getAttribute('data-tab')); }
        });
        document.querySelectorAll('[data-sm-goto]').forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-sm-goto')); });
        });
        var reset = document.getElementById('sm-reset');
        if (reset) {
            reset.addEventListener('submit', function (e) {
                if (reset.reset_confirm.value.trim() !== 'RESET') { alert('Type RESET to confirm.'); e.preventDefault(); }
            });
        }
        var clear = document.getElementById('sm-clear');
        if (clear) {
            clear.addEventListener('submit', function (e) { if (!confirm('Delete the collected history?')) { e.preventDefault(); } });
        }
        // auto-refresh of the history every 5 minutes while it is open
        var histTimer = setInterval(function () {
            if (!document.body.contains(page)) { clearInterval(histTimer); return; }
            if (!document.hidden && !document.getElementById('sm-tab-history').hidden) { loadHistory(); }
        }, 300000);
        show((location.hash || '').replace('#', '') || load('tab') || 'live');
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
