// Statistics page: loads /admin/api/stats and draws KPI cards and SVG charts (no libraries, no inline styles: the
// admin CSP forbids them). All numbers come from the server; this file only formats and draws.
(function () {
  'use strict';

  var LOCALE = 'pt-PT', TZ = 'Europe/Lisbon';
  var root = document.getElementById('stats');
  if (!root) return;

  var qs = new URLSearchParams(location.search);
  var state = { week: qs.get('week') || '', month: qs.get('month') || '', year: qs.get('year') || '' };

  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var num = function (n) { return n === null || n === undefined ? '–' : Number(n).toLocaleString(LOCALE); };
  var dec = function (n) { return n === null || n === undefined ? '–' : Number(n).toLocaleString(LOCALE, { maximumFractionDigits: 1 }); };
  var dateOf = function (ymd) { return new Date(ymd + 'T12:00:00Z'); };
  var dayFmt = new Intl.DateTimeFormat(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
  var shortFmt = new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'short', timeZone: 'UTC' });
  var monthFmt = new Intl.DateTimeFormat(LOCALE, { month: 'long', year: 'numeric', timeZone: 'UTC' });
  var weekdayFmt = new Intl.DateTimeFormat(LOCALE, { weekday: 'long', timeZone: 'UTC' });
  var clock = function (iso) { return new Date(iso).toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit', timeZone: TZ }); };
  var cap = function (s) { return s.charAt(0).toUpperCase() + s.slice(1); };

  function delta(cur, prev) {
    if (cur === null || cur === undefined || prev === null || prev === undefined) return '<span class="delta none">sem comparação</span>';
    var d = cur - prev;
    var pct = prev !== 0 ? ' (' + (d >= 0 ? '+' : '−') + Math.abs(Math.round(1000 * d / prev) / 10).toLocaleString(LOCALE) + '%)' : '';
    if (d === 0) return '<span class="delta">= igual à semana anterior</span>';
    return '<span class="delta">' + (d > 0 ? '▲ +' : '▼ −') + num(Math.abs(d)) + pct + ' vs semana anterior</span>';
  }

  function api(params) {
    return fetch('/admin/api/stats?' + new URLSearchParams(params), { cache: 'no-store', credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { location.href = '/admin/entrar'; throw new Error('login'); }
      if (!r.ok) throw new Error(r.status);
      return r.json();
    });
  }

  function url() {
    var p = new URLSearchParams();
    ['week', 'month', 'year'].forEach(function (k) { if (state[k]) p.set(k, state[k]); });
    history.replaceState(null, '', location.pathname + (p.toString() ? '?' + p : ''));
  }

  // ------------------------------------------------------------------ skeleton
  root.innerHTML =
    '<section class="s-sec" aria-labelledby="h-week"><div class="s-head"><h3 id="h-week">Semana</h3><div class="s-ctl" id="weekCtl"></div></div><div id="weekBody"><div class="hint">A carregar…</div></div></section>' +
    '<section class="s-sec" aria-labelledby="h-month"><div class="s-head"><h3 id="h-month">Mês</h3><div class="s-ctl"><label class="sr" for="monthIn">Mês</label><input type="month" id="monthIn"></div></div><div id="monthBody"><div class="hint">A carregar…</div></div></section>' +
    '<section class="s-sec" aria-labelledby="h-year"><div class="s-head"><h3 id="h-year">Ano, domingo após domingo</h3><div class="s-ctl"><label class="sr" for="yearIn">Ano</label><select id="yearIn"></select></div></div><div id="yearBody"><div class="hint">A carregar…</div></div></section>';

  var tip = document.createElement('div');
  tip.className = 's-tip';
  tip.hidden = true;
  document.body.appendChild(tip);

  function showTip(html, ev) {
    tip.innerHTML = html;
    tip.hidden = false;
    var w = tip.offsetWidth, h = tip.offsetHeight;
    var x = Math.min(Math.max(ev.clientX + 14, 8), window.innerWidth - w - 8);
    var y = Math.min(Math.max(ev.clientY - h - 10, 8), window.innerHeight - h - 8);
    tip.style.left = (x + window.scrollX) + 'px';
    tip.style.top = (y + window.scrollY) + 'px';
  }
  function hideTip() { tip.hidden = true; }

  // ------------------------------------------------------------------ week
  function meetingCard(m) {
    var late;
    if (m.partial) late = 'n/d <span class="muted">(o contador começou depois do início)</span>';
    else if (m.late.pct === null) late = m.late.total === 0 ? '–' : 'n/d';
    else late = dec(m.late.pct) + '% <span class="muted">(' + num(m.late.late) + ' de ' + num(m.late.total) + ' chegadas · tolerância ' + (m.late.grace === null ? '?' : m.late.grace) + ' min)</span>';
    var dev = m.devotional.peak === null ? '–' : num(m.devotional.peak) + ' <span class="muted">às ' + clock(m.devotional.at) + '</span>';
    var c = m.categories;
    var hasReport = [c.voluntarios, c.visitantes, c.kids, c.auditorio, c.decisoes].some(function (v) { return v !== null; });
    var report = hasReport
      ? '<div class="s-cats"><span>Voluntários <b>' + num(c.voluntarios) + '</b></span><span>Visitantes <b>' + num(c.visitantes) + '</b></span><span>Hillsong Kids <b>' + num(m.hillsongKids) + '</b></span><span>Decisões <b>' + num(c.decisoes) + '</b></span></div>'
      : '<div class="muted">Relatório por preencher (botão do relatório no cartão da reunião).</div>';
    return '<article class="s-card"><h4>' + esc(m.name) + ' <span class="muted">· ' + cap(weekdayFmt.format(dateOf(m.day))) + ' ' + shortFmt.format(dateOf(m.day)) + ' · ' + m.time + '</span></h4>' +
      '<div class="s-big">' + num(m.shown) + ' <span class="muted s-src">' + (m.onSite !== null ? 'no local' : 'assistência (' + esc(m.assistenciaSource) + ')') + '</span></div>' +
      (m.onSite !== null ? '<div class="muted">Assistência + crianças no Kids + crianças no auditório + adultos fora das portas</div>' : '') +
      delta(m.shown, m.previous ? m.previous.shown : null) +
      '<dl class="s-kv"><dt>Atrasos</dt><dd>' + late + '</dd><dt>Devocional</dt><dd>' + dev + '</dd>' +
      (m.onSite !== null ? '<dt>Assistência</dt><dd>' + num(m.assistencia) + ' <span class="muted">(' + esc(m.assistenciaSource) + ')</span></dd>' : '') +
      (m.previous ? '<dt>Semana anterior</dt><dd>' + num(m.previous.shown) + (m.previous.latePct !== null ? ' · atrasos ' + dec(m.previous.latePct) + '%' : '') + '</dd>' : '') +
      '</dl>' + report + '</article>';
  }

  // "2 de 3" when a sum only covers some of the meetings; empty when it is complete
  function covers(n, of) { return n < of ? ' <span class="s-warn">(só ' + n + ' de ' + of + ' reuniões)</span>' : ''; }

  function totalCard(w) {
    var t = w.sundayTotal, p = w.prevSundayTotal;
    if (!t.meetings) return '';
    var keys = ['voluntarios', 'visitantes', 'hillsongKids', 'decisoes'];
    var anyCat = keys.some(function (k) { return t[k] !== null; });
    var cats = !anyCat ? '' : keys.map(function (k) {
      var label = { voluntarios: 'Voluntários', visitantes: 'Visitantes', hillsongKids: 'Hillsong Kids', decisoes: 'Decisões' }[k];
      return '<span>' + label + ' <b>' + num(t[k]) + '</b>' + (p[k] !== null && t[k] !== null ? ' <span class="muted">(' + num(p[k]) + ')</span>' : '') + '</span>';
    }).join('');
    var comparable = p.meetings && p.withData === p.meetings && t.withData === t.meetings && p.reports === t.reports;
    return '<article class="s-card s-total"><h4>Total do domingo <span class="muted">· soma das ' + t.meetings + ' reuniões · ' + shortFmt.format(dateOf(w.sunday)) + '</span></h4>' +
      '<div class="s-big">' + num(t.shown) + ' <span class="muted s-src">' + (t.reports === t.meetings ? 'no local' : 'no local / assistência') + '</span>' + covers(t.withData, t.meetings) + '</div>' +
      '<div class="muted">' + (t.reports === t.meetings ? 'Assistência + crianças + adultos fora das portas, como nos cartões.' : 'Como nos cartões: no local nas reuniões com relatório (' + t.reports + ' de ' + t.meetings + '), só a assistência nas outras.') + '</div>' +
      delta(t.shown, comparable ? p.shown : null) +
      (cats ? '<div class="s-cats">' + cats + '</div>' + (t.reports < t.meetings ? '<div class="s-warn">Relatórios guardados: ' + t.reports + ' de ' + t.meetings + '; as categorias só somam esses.</div>' : '')
            : '<div class="muted">Voluntários, visitantes, Hillsong Kids e decisões aparecem quando os relatórios das reuniões estiverem guardados.</div>') +
      '<div class="muted">' + (cats ? 'Entre parênteses, o domingo anterior (só quando está completo). ' : '') + 'A soma conta duas vezes quem esteve em mais de uma reunião.</div></article>';
  }

  function exitBlock(w) {
    if (!w.exit.length && !w.prevExit.length) return '';
    var prev = {};
    w.prevExit.forEach(function (e) { prev[e.ref] = e; });
    var one = function (e) {
      if (!e) return '–';
      if (e.state === 'reached') return e.minutes + ' min';
      if (e.state === 'not_reached') return 'não atingiu em 3 h';
      return 'sem dados';
    };
    var rows = w.exit.map(function (e) {
      return '<tr><th scope="row">Depois das ' + e.ref + '</th><td>' + one(e) + '</td><td class="muted">' + one(prev[e.ref]) + '</td></tr>';
    }).join('');
    return '<article class="s-card"><h4>Velocidade de saída <span class="muted">· minutos até ficarem ' + (w.exit[0] ? w.exit[0].threshold : w.settings.exit_threshold) + ' pessoas ou menos</span></h4>' +
      '<table class="s-table"><thead><tr><th></th><th>Este domingo</th><th>Domingo anterior</th></tr></thead><tbody>' + rows + '</tbody></table>' +
      '<div class="muted">O saldo é uma estimativa; mede-se até chegar perto de vazio, não até zero exato.</div></article>';
  }

  // chart width follows the page (so text stays readable on phones); drawn once per load
  function chartW(max) { return Math.min(max, Math.max(300, (root.clientWidth || max) - 32)); }

  function lineChart(s, w) {
    var W = chartW(640), H = Math.round(W * 0.42), M = { l: 44, r: 14, t: 16, b: 34 };
    var x0 = -60, x1 = s.durationMin + 60;
    if (s.previous && !s.previous.length) s.previous = null;      // last week had no readings for this meeting
    var all = s.current.concat(s.previous || []);
    if (!all.length) return '<div class="muted">Sem leituras do contador para esta reunião.</div>';
    var lo = Math.min(0, Math.min.apply(null, all.map(function (p) { return p[1]; })));
    var hi = Math.max(4, Math.max.apply(null, all.map(function (p) { return p[1]; })));
    var step = Math.max(1, Math.pow(10, Math.floor(Math.log10(hi / 4))));
    step = [1, 2, 5, 10].map(function (m) { return m * step; }).find(function (v) { return v >= hi / 4; });
    var ymin = Math.floor(lo / step) * step, ymax = Math.ceil(hi / step) * step;
    var X = function (m) { return M.l + (m - x0) / (x1 - x0) * (W - M.l - M.r); };
    var Y = function (v) { return H - M.b - (v - ymin) / (ymax - ymin) * (H - M.t - M.b); };
    var hm = s.time.split(':'), startMin = (+hm[0]) * 60 + (+hm[1]);
    var label = function (m) { var t = ((startMin + m) % 1440 + 1440) % 1440; return String(Math.floor(t / 60)).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0'); };

    var g = '';
    for (var v = ymin; v <= ymax + 1e-9; v += step) g += '<line class="grid" x1="' + M.l + '" x2="' + (W - M.r) + '" y1="' + Y(v) + '" y2="' + Y(v) + '"/><text class="tick" x="' + (M.l - 6) + '" y="' + (Y(v) + 4) + '" text-anchor="end">' + num(v) + '</text>';
    var tickStep = W < 450 ? 60 : 30;     // fewer clock labels on a phone
    for (var m = Math.ceil(x0 / tickStep) * tickStep; m <= x1; m += tickStep) g += '<text class="tick" x="' + X(m) + '" y="' + (H - 12) + '" text-anchor="middle">' + label(m) + '</text>';
    var path = function (pts) { return pts.map(function (p, i) { return (i ? 'L' : 'M') + X(p[0]).toFixed(1) + ',' + Y(p[1]).toFixed(1); }).join(''); };
    var marks = '<line class="mark" x1="' + X(0) + '" x2="' + X(0) + '" y1="' + M.t + '" y2="' + (H - M.b) + '"/><text class="marklbl" x="' + (X(0) + 4) + '" y="' + (M.t + 10) + '">início</text>';
    marks += '<line class="mark" x1="' + X(s.durationMin) + '" x2="' + X(s.durationMin) + '" y1="' + M.t + '" y2="' + (H - M.b) + '"/><text class="marklbl" x="' + (X(s.durationMin) + 4) + '" y="' + (M.t + 10) + '">fim</text>';
    if (s.devotionalMin !== null && s.devotionalMin >= x0 && s.devotionalMin <= x1) marks += '<line class="mark dev" x1="' + X(s.devotionalMin) + '" x2="' + X(s.devotionalMin) + '" y1="' + M.t + '" y2="' + (H - M.b) + '"/><text class="marklbl" x="' + (X(s.devotionalMin) + 4) + '" y="' + (M.t + 22) + '">devocional</text>';

    var lines = (s.previous ? '<path class="ln prev" d="' + path(s.previous) + '"/>' : '') + '<path class="ln cur" d="' + path(s.current) + '"/>';
    var id = 'c' + s.time.replace(':', '');
    var legend = '<div class="s-legend"><span><i class="sw cur"></i>Esta semana</span>' + (s.previous ? '<span><i class="sw prev"></i>Semana anterior</span>' : '<span class="muted">sem semana anterior para comparar</span>') + '</div>';
    var svg = '<svg class="s-chart" id="' + id + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Pessoas dentro durante a reunião das ' + s.time + ': esta semana' + (s.previous ? ' e semana anterior' : '') + '">' + g + marks + lines + '<g class="hov" hidden><line class="mark" y1="' + M.t + '" y2="' + (H - M.b) + '"/><circle class="dot cur" r="4"/>' + (s.previous ? '<circle class="dot prev" r="4"/>' : '') + '</g><rect class="hit" x="' + M.l + '" y="' + M.t + '" width="' + (W - M.l - M.r) + '" height="' + (H - M.t - M.b) + '"/></svg>';

    // table view: every 10 minutes
    var at = function (pts, minute) { var best = null; (pts || []).forEach(function (p) { if (best === null || Math.abs(p[0] - minute) < Math.abs(best[0] - minute)) best = p; }); return best && Math.abs(best[0] - minute) <= 5 ? best[1] : null; };
    var rows = '';
    for (var mm = x0; mm <= x1; mm += 10) rows += '<tr><th scope="row">' + label(mm) + '</th><td>' + num(at(s.current, mm)) + '</td><td>' + (s.previous ? num(at(s.previous, mm)) : '–') + '</td></tr>';
    var table = '<details class="s-tablewrap"><summary>Ver como tabela</summary><table class="s-table"><thead><tr><th>Hora</th><th>Esta semana</th><th>Semana anterior</th></tr></thead><tbody>' + rows + '</tbody></table></details>';

    var wrap = document.createElement('div');
    wrap.className = 's-chartbox';
    wrap.innerHTML = '<h4>Progressão · ' + esc(s.name) + '</h4>' + legend + '<div class="s-chartwrap">' + svg + '</div>' + table;
    var el = wrap.querySelector('svg'), hov = wrap.querySelector('.hov'), hit = wrap.querySelector('.hit');
    var nearest = function (pts, m) { var b = null; (pts || []).forEach(function (p) { if (b === null || Math.abs(p[0] - m) < Math.abs(b[0] - m)) b = p; }); return b; };
    hit.addEventListener('pointermove', function (ev) {
      var r = el.getBoundingClientRect();
      var m = x0 + ((ev.clientX - r.left) / r.width * W - M.l) / (W - M.l - M.r) * (x1 - x0);
      var c = nearest(s.current, m), p = nearest(s.previous, m);
      if (!c) return;
      hov.removeAttribute('hidden');
      var ln = hov.querySelector('line'); ln.setAttribute('x1', X(c[0])); ln.setAttribute('x2', X(c[0]));
      var dc = hov.querySelector('.dot.cur'); dc.setAttribute('cx', X(c[0])); dc.setAttribute('cy', Y(c[1]));
      if (p) { var dp = hov.querySelector('.dot.prev'); dp.setAttribute('cx', X(p[0])); dp.setAttribute('cy', Y(p[1])); }
      showTip('<b>' + label(c[0]) + '</b><div><i class="sw cur"></i>Esta semana <b>' + num(c[1]) + '</b></div>' + (p ? '<div><i class="sw prev"></i>Semana anterior <b>' + num(p[1]) + '</b></div>' : ''), ev);
    });
    hit.addEventListener('pointerleave', function () { hov.setAttribute('hidden', ''); hideTip(); });
    return wrap;
  }

  function renderWeek(w) {
    state.week = w.sunday;
    $('weekCtl').innerHTML = '<button type="button" id="wPrev">◀ Anterior</button><strong>' + shortFmt.format(dateOf(w.sunday)) + ' – ' + shortFmt.format(new Date(dateOf(w.sunday).getTime() + 6 * 864e5)) + '</strong>' +
      '<button type="button" id="wCur"' + (w.isCurrent ? ' disabled' : '') + '>Esta semana</button><button type="button" id="wNext">Seguinte ▶</button>';
    $('wPrev').onclick = function () { state.week = w.prev; url(); loadWeek(); };
    $('wNext').onclick = function () { state.week = w.next; url(); loadWeek(); };
    $('wCur').onclick = function () { state.week = ''; url(); loadWeek(); };
    var body = $('weekBody');
    if (!w.meetings.length) { body.innerHTML = '<div class="hint">Sem reuniões com dados nesta semana.</div>'; return; }
    body.innerHTML = '<div class="s-cards">' + totalCard(w) + w.meetings.map(meetingCard).join('') + exitBlock(w) + '</div><div id="progress" class="s-charts"></div>';
    var box = $('progress');
    if (w.progression.length) {
      var h = document.createElement('h4'); h.className = 's-sub'; h.textContent = 'Progressão por reunião (domingo)'; box.appendChild(h);
      w.progression.forEach(function (s) { box.appendChild(lineChart(s, w)); });
    }
  }
  function $(id) { return document.getElementById(id); }

  function loadWeek() {
    var p = { part: 'week' };
    if (state.week) p.week = state.week;
    $('weekBody').innerHTML = '<div class="hint">A carregar…</div>';
    api(p).then(renderWeek).catch(function (e) { if (e.message !== 'login') $('weekBody').innerHTML = '<div class="hint">Não foi possível carregar a semana.</div>'; });
  }

  // ------------------------------------------------------------------ month
  function renderMonth(d) {
    var m = d.month, pv = d.previous;
    state.month = m.month;
    $('monthIn').value = m.month;
    var prevBySlot = {};
    pv.slots.forEach(function (s) { prevBySlot[s.dow + ' ' + s.time] = s; });
    var mon = cap(monthFmt.format(dateOf(m.month + '-15'))), pmon = cap(monthFmt.format(dateOf(pv.month + '-15')));
    if (!m.slots.length) { $('monthBody').innerHTML = '<div class="hint">Sem reuniões com dados em ' + esc(mon) + '.</div>'; return; }
    var rows = m.slots.map(function (s) {
      var p = prevBySlot[s.dow + ' ' + s.time];
      return '<tr><th scope="row">' + esc(cap(weekdayFmt.format(dateOf('2026-10-' + (11 + s.dow)))) + ' ' + s.time) + '</th><td>' + s.meetings + '</td><td>' + dec(s.shown) + '</td><td class="muted">' + (p ? dec(p.shown) : '–') +
        '</td><td>' + (s.latePct === null ? '–' : dec(s.latePct) + '%') + '</td><td>' + (s.devotionalPeak === null ? '–' : dec(s.devotionalPeak)) + '</td></tr>';
    }).join('');
    var exit = m.exit.length ? '<table class="s-table"><thead><tr><th>Velocidade de saída (média)</th><th>' + esc(mon) + '</th><th>' + esc(pmon) + '</th></tr></thead><tbody>' + m.exit.map(function (e) {
      var pe = (pv.exit.find(function (x) { return x.ref === e.ref; }) || {});
      var f = function (x) { return x && x.minutes !== null && x.minutes !== undefined ? dec(x.minutes) + ' min' + (x.notReached ? ' (+' + x.notReached + ' sem chegar)' : '') : (x && x.notReached ? 'não atingiu' : '–'); };
      return '<tr><th scope="row">Depois das ' + e.ref + '</th><td>' + f(e) + '</td><td class="muted">' + f(pe) + '</td></tr>';
    }).join('') + '</tbody></table>' : '';
    $('monthBody').innerHTML = '<div class="s-cards"><article class="s-card"><h4>' + esc(mon) + ' <span class="muted">· médias por reunião</span></h4><div class="s-tw"><table class="s-table stackable"><thead><tr><th>Reunião</th><th>Nº</th><th>Média (como nos cartões)</th><th>' + esc(pmon) + '</th><th>Atrasos</th><th>Devocional</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<div class="muted">Atrasos: só reuniões com o contador a funcionar desde antes do início.</div></article>' +
      '<article class="s-card"><h4>Domingos de ' + esc(mon) + '</h4><div class="s-big">' + dec(m.sundayShown) + ' <span class="muted s-src">média por domingo, como nos cartões (soma das reuniões) · ' + m.sundaysUsed + ' de ' + m.sundays + ' domingo(s) completos</span></div><div class="muted">' + esc(pmon) + ': ' + dec(pv.sundayShown) + (m.sundayShown !== null && pv.sundayShown !== null ? ' · diferença ' + (m.sundayShown - pv.sundayShown >= 0 ? '+' : '−') + dec(Math.abs(m.sundayShown - pv.sundayShown)) : '') + '</div>' +
      (m.sundaysUsedOnSite < m.sundaysUsed ? '<div class="muted">Só ' + m.sundaysUsedOnSite + ' de ' + m.sundaysUsed + ' domingo(s) têm todos os relatórios; nos outros conta a assistência do contador.</div>' : '') + exit + '</article></div>';
    if (window.stackTables) window.stackTables($('monthBody'));
  }
  function loadMonth() {
    var p = { part: 'month' };
    if (state.month) p.month = state.month;
    api(p).then(renderMonth).catch(function (e) { if (e.message !== 'login') $('monthBody').innerHTML = '<div class="hint">Não foi possível carregar o mês.</div>'; });
  }

  // ------------------------------------------------------------------ year
  var CATS = [
    ['shown', 'Total do domingo (como nos cartões)', 'c1'],
    ['voluntarios', 'Voluntários', 'c3'],
    ['visitantes', 'Visitantes', 'c2'],
    ['hillsongKids', 'Hillsong Kids', 'c4'],
    ['decisoes', 'Decisões', 'c5'],
  ];

  function barChart(year, key, title, cls, big) {
    var W = chartW(big ? 900 : 460), H = Math.round(W * (big ? 0.29 : 0.37)), M = { l: 40, r: 8, t: 14, b: big ? 28 : 24 };    // the small charts use a narrower box so their text stays readable when scaled
    var d = year.sundays;
    var vals = d.map(function (s) { return s[key]; });
    var hi = Math.max(4, Math.max.apply(null, vals.map(function (v) { return v === null ? 0 : v; })));
    var step = Math.max(1, Math.pow(10, Math.floor(Math.log10(hi / 4))));
    step = [1, 2, 5, 10].map(function (m) { return m * step; }).find(function (v) { return v >= hi / 4; });
    var ymax = Math.ceil(hi / step) * step;
    var n = d.length, slot = (W - M.l - M.r) / n, bw = Math.min(24, Math.max(3, slot - 3));
    var Y = function (v) { return H - M.b - v / ymax * (H - M.t - M.b); };
    var g = '';
    for (var v = 0; v <= ymax + 1e-9; v += step) g += '<line class="grid" x1="' + M.l + '" x2="' + (W - M.r) + '" y1="' + Y(v) + '" y2="' + Y(v) + '"/><text class="tick" x="' + (M.l - 6) + '" y="' + (Y(v) + 4) + '" text-anchor="end">' + num(v) + '</text>';
    var lastMonth = -1, bars = '';
    d.forEach(function (s, i) {
      var cx = M.l + slot * i + slot / 2;
      var mo = +s.day.slice(5, 7);
      if (mo !== lastMonth) { lastMonth = mo; g += '<text class="tick" x="' + (cx - slot / 2) + '" y="' + (H - 8) + '" text-anchor="start">' + new Intl.DateTimeFormat(LOCALE, { month: 'short', timeZone: 'UTC' }).format(dateOf(s.day)) + '</text>'; }
      var v = s[key];
      if (v !== null && v > 0) bars += '<rect class="bar ' + cls + '" x="' + (cx - bw / 2).toFixed(1) + '" y="' + Y(v).toFixed(1) + '" width="' + bw.toFixed(1) + '" height="' + (H - M.b - Y(v)).toFixed(1) + '" rx="2"/>';
    });
    var tableRows = d.filter(function (s) { return s[key] !== null; }).map(function (s) { return '<tr><th scope="row">' + shortFmt.format(dateOf(s.day)) + '</th><td>' + num(s[key]) + '</td></tr>'; }).join('') || '<tr><td colspan="2" class="muted">Ainda sem dados.</td></tr>';
    var wrap = document.createElement('div');
    wrap.className = 's-chartbox' + (big ? ' big' : '');
    wrap.innerHTML = '<h4>' + esc(title) + '</h4><div class="s-chartwrap"><svg class="s-chart" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(title) + ' por domingo em ' + year.year + '">' + g + bars +
      '<line class="mark hline" y1="' + M.t + '" y2="' + (H - M.b) + '" hidden/><rect class="hit" x="' + M.l + '" y="' + M.t + '" width="' + (W - M.l - M.r) + '" height="' + (H - M.t - M.b) + '"/></svg></div>' +
      '<details class="s-tablewrap"><summary>Ver como tabela</summary><table class="s-table"><thead><tr><th>Domingo</th><th>' + esc(title) + '</th></tr></thead><tbody>' + tableRows + '</tbody></table></details>';
    var el = wrap.querySelector('svg'), hit = wrap.querySelector('.hit'), vline = wrap.querySelector('.hline');
    hit.addEventListener('pointermove', function (ev) {
      var r = el.getBoundingClientRect();
      var i = Math.min(n - 1, Math.max(0, Math.floor(((ev.clientX - r.left) / r.width * W - M.l) / slot)));
      var s = d[i];
      var cx = M.l + slot * i + slot / 2;
      vline.setAttribute('x1', cx); vline.setAttribute('x2', cx); vline.removeAttribute('hidden');
      showTip('<b>' + cap(dayFmt.format(dateOf(s.day))) + '</b>' + CATS.map(function (c) { return '<div><i class="sw ' + c[2] + '"></i>' + esc(c[1].replace(' (como nos cartões)', '')) + ' <b>' + num(s[c[0]]) + '</b></div>'; }).join('') + '<div class="muted">' + s.meetings + ' reunião(ões)' + (s.reports < s.meetings ? ' · ' + s.reports + ' relatório(s)' : '') + '</div>', ev);
    });
    hit.addEventListener('pointerleave', function () { vline.setAttribute('hidden', ''); hideTip(); });
    return wrap;
  }

  function renderYear(y) {
    state.year = String(y.year);
    $('yearIn').value = state.year;
    var withData = y.sundays.filter(function (s) { return s.meetings > 0; }).length;
    var box = $('yearBody');
    box.innerHTML = '<div class="hint">' + withData + ' domingo(s) com dados em ' + y.year + '. As categorias vêm dos relatórios guardados por quem tem sessão iniciada; sem relatório só há a assistência do contador.</div><div class="s-charts" id="yearCharts"></div>';
    var cont = $('yearCharts');
    CATS.forEach(function (c, i) { cont.appendChild(barChart(y, c[0], c[1], c[2], i === 0)); });
  }
  function loadYear() {
    var p = { part: 'year' };
    if (state.year) p.year = state.year;
    api(p).then(renderYear).catch(function (e) { if (e.message !== 'login') $('yearBody').innerHTML = '<div class="hint">Não foi possível carregar o ano.</div>'; });
  }

  // ------------------------------------------------------------------ controls and start
  var thisYear = new Date().getFullYear();
  for (var yr = thisYear; yr >= 2026; yr--) $('yearIn').insertAdjacentHTML('beforeend', '<option value="' + yr + '">' + yr + '</option>');
  $('yearIn').value = state.year || String(thisYear);
  $('yearIn').addEventListener('change', function () { state.year = this.value; url(); loadYear(); });
  $('monthIn').addEventListener('change', function () { if (this.value) { state.month = this.value; url(); loadMonth(); } });
  loadWeek(); loadMonth(); loadYear();
})();
