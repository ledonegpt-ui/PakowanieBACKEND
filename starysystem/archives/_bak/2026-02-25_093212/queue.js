(() => {
  const $ = (id) => document.getElementById(id);

  const el = {
    who: $('who'),
    btnLogoutTop: $('btnLogoutTop'),

    loginCard: $('loginCard'),
    packer_code: $('packer_code'),
    btnLogin: $('btnLogin'),
    loginMsg: $('loginMsg'),

    queueCard: $('queueCard'),
    tabs: Array.from(document.querySelectorAll('.tab')),

    f_station: $('f_station'),
    f_packer: $('f_packer'),
    f_q: $('f_q'),
    f_from: $('f_from'),
    f_to: $('f_to'),
    btnToday: $('btnToday'),
    btnClearDates: $('btnClearDates'),

    btnReload: $('btnReload'),
    btnExportCsv: $('btnExportCsv'),
    autoRefreshOn: $('autoRefreshOn'),
    autoRefreshSec: $('autoRefreshSec'),

    msg: $('msg'),
    serverTime: $('serverTime'),
    reportRange: $('reportRange'),

    kpiGrid: $('kpiGrid'),
    packersTopBody: $('packersTopBody'),
    stationsTopBody: $('stationsTopBody'),
    activeNowBody: $('activeNowBody'),
    staleTopBody: $('staleTopBody'),

    tbody: $('tbody'),
    prev: $('prev'),
    next: $('next'),
    pageInfo: $('pageInfo'),
    pagerText: $('pagerText'),
  };

  const state = {
    role: 'packer',
    tab: '10',
    limit: 200,
    offset: 0,
    stale_min: 30,
    total: 0,
    rows: [],
    stats: null,
    loading: false,
    autoTimer: null,
    lastReloadAt: 0
  };

  async function apiJson(url, opts) {
    const res = await fetch(url, opts);
    const txt = await res.text();
    let data = null;
    try { data = JSON.parse(txt); } catch (_) {}
    if (!data) throw new Error('non-json');
    return data;
  }

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'
  }[c]));

  function fmtHMS(sec) {
    if (sec == null || sec === '') return '';
    sec = Math.max(0, Number(sec));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);
    const pad = (n) => String(n).padStart(2,'0');
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
  }

  function fmtAge(sec) {
    if (sec == null || sec === '') return '';
    sec = Math.max(0, Number(sec));
    const m = Math.floor(sec / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    return `${h}h ${m % 60}m`;
  }

  function statusLabel(st) {
    st = Number(st);
    if (st === 10) return 'NEW';
    if (st === 40) return 'PACKING';
    if (st === 50) return 'PACKED';
    if (st === 60) return 'CANCELLED';
    return String(st);
  }

  function statusClass(st) {
    st = Number(st);
    if (st === 10) return 's10';
    if (st === 40) return 's40';
    if (st === 50) return 's50';
    if (st === 60) return 's60';
    return '';
  }

  function buildParams(includePagination = true) {
    const p = new URLSearchParams();

    if (state.tab === 'stale') { p.set('status', '40'); p.set('stale', '1'); }
    else p.set('status', state.tab);

    const station = el.f_station.value.trim();
    const packer = el.f_packer.value.trim();
    const q = el.f_q.value.trim();

    if (station) p.set('station', station);
    if (packer)  p.set('packer', packer);
    if (q)       p.set('q', q);

    if (el.f_from.value) p.set('date_from', el.f_from.value);
    if (el.f_to.value)   p.set('date_to', el.f_to.value);

    if (includePagination) {
      p.set('limit', String(state.limit));
      p.set('offset', String(state.offset));
    }

    return p;
  }

  function buildQueueUrl() {
    return '/api/queue.php?' + buildParams(true).toString();
  }

  function buildStatsUrl() {
    const p = buildParams(false);
    p.set('stale_limit', '8');
    p.set('active_limit', '8');
    return '/api/queue_stats.php?' + p.toString();
  }

  function setMsg(text, cls = '') {
    el.msg.className = 'msg' + (cls ? (' ' + cls) : '');
    el.msg.textContent = text || '';
  }

  async function refreshSession() {
    const s = await apiJson('/api/session.php', { method:'GET' });
    if (!s.ok) return false;

    state.role = s.role || 'packer';
    el.who.textContent = `${s.packer} • ${state.role}`;
    el.btnLogoutTop.classList.remove('hidden');
    el.loginCard.classList.add('hidden');
    el.queueCard.classList.remove('hidden');
    return true;
  }

  async function doLogin() {
    el.loginMsg.textContent = '';
    const packer_code = el.packer_code.value.trim();
    if (!packer_code) {
      el.loginMsg.style.color = 'var(--bad)';
      el.loginMsg.textContent = 'Zeskanuj kod usera';
      return;
    }

    const data = await apiJson('/api/login_queue.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ packer_code }).toString()
    });

    if (!data.ok) {
      el.loginMsg.style.color='var(--bad)';
      el.loginMsg.textContent = data.error || 'Błąd logowania';
      return;
    }

    await refreshSession();
    state.offset = 0;
    await loadAll({force:true});
  }

  async function logoutNow() {
    stopAutoRefresh();
    try { await apiJson('/api/logout.php', { method:'POST' }); } catch(_){}
    el.btnLogoutTop.classList.add('hidden');
    el.queueCard.classList.add('hidden');
    el.loginCard.classList.remove('hidden');
    el.packer_code.value = '';
    el.packer_code.focus();
  }

  async function unlock(order_code) {
    const reason = prompt('Powód unlock (opcjonalnie):') || '';
    const data = await apiJson('/api/unlock_pack.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({ order_code, reason }).toString()
    });
    if (!data.ok) alert(data.error || 'Błąd unlock');
    await loadAll({force:true});
  }

  function renderKpis(stats) {
    const live = stats?.summary_live || {};
    const filt = stats?.summary_filtered || {};
    const perf = stats?.performance || {};
    const ev   = stats?.events || {};

    const cards = [
      {label:'LIVE NEW', value: live.new_count ?? 0, cls:'neutral'},
      {label:'LIVE PACKING', value: live.packing_count ?? 0, cls:'info'},
      {label:`LIVE ZAWIESZONE (${state.stale_min}m+)`, value: live.stale_count ?? 0, cls:'danger'},
      {label:'PACKED dziś', value: live.packed_today ?? 0, cls:'ok'},
      {label:'CANCELLED dziś', value: live.cancelled_today ?? 0, cls:'danger'},
      {label:'Aktywni pakowacze', value: live.active_packers ?? 0, cls:'info'},
      {label:'Aktywne stanowiska', value: live.active_stations ?? 0, cls:'info'},
      {label:'Wynik filtra (lista)', value: filt.matching_total ?? 0, cls:'neutral'},
      {label:'AVG pakowania', value: perf.avg_packing_seconds != null ? fmtHMS(perf.avg_packing_seconds) : '—', cls:'ok'},
      {label:'Mediana pakowania', value: perf.median_packing_seconds != null ? fmtHMS(perf.median_packing_seconds) : '—', cls:'ok'},
      {label:'Unlock (raport)', value: ev.unlock_count ?? 0, cls:'warn'},
      {label:'Force finish (raport)', value: ev.force_finish_count ?? 0, cls:'warn'},
      {label:'PRINT FAIL (raport)', value: ev.print_fail_count ?? 0, cls:'danger'},
      {label:'REOPEN (raport)', value: ev.reopen_count ?? 0, cls:'warn'},
    ];

    el.kpiGrid.innerHTML = cards.map(c => `
      <div class="kpi ${esc(c.cls)}">
        <div class="kpiLabel">${esc(c.label)}</div>
        <div class="kpiValue">${esc(c.value)}</div>
      </div>
    `).join('');
  }

  function renderMiniRows(tbody, rowsHtml) {
    tbody.innerHTML = rowsHtml || `<tr><td colspan="99" class="muted center">brak danych</td></tr>`;
  }

  function renderPackersTop(rows) {
    const html = (rows || []).map((r, i) => `
      <tr>
        <td>${i+1}</td>
        <td>${esc(r.packer)}</td>
        <td>${Number(r.packed || 0)}</td>
        <td>${fmtHMS(r.avg_seconds)}</td>
        <td>${fmtHMS(r.sum_seconds)}</td>
      </tr>
    `).join('');
    renderMiniRows(el.packersTopBody, html);
  }

  function renderStationsTop(rows) {
    const html = (rows || []).map((r, i) => `
      <tr>
        <td>${i+1}</td>
        <td>${esc(r.station)}</td>
        <td>${Number(r.packed || 0)}</td>
        <td>${fmtHMS(r.avg_seconds)}</td>
        <td>${fmtHMS(r.sum_seconds)}</td>
      </tr>
    `).join('');
    renderMiniRows(el.stationsTopBody, html);
  }

  function renderActiveNow(rows) {
    const html = (rows || []).map((r) => `
      <tr class="${Number(r.age_seconds||0) >= state.stale_min*60 ? 'row-stale':''}">
        <td><b>${esc(r.order_code)}</b></td>
        <td>${esc(r.subiekt_doc_no || '')}</td>
        <td>${esc(r.packer || '')}</td>
        <td>${esc(r.station || '')}</td>
        <td>${fmtAge(r.age_seconds)}</td>
        <td>${fmtHMS(r.total_packing_seconds)}</td>
      </tr>
    `).join('');
    renderMiniRows(el.activeNowBody, html);
  }

  function renderStaleTop(rows) {
    const html = (rows || []).map((r) => `
      <tr class="row-stale">
        <td><b>${esc(r.order_code)}</b></td>
        <td>${esc(r.subiekt_doc_no || '')}</td>
        <td>${esc(r.packer || '')}</td>
        <td>${esc(r.station || '')}</td>
        <td>${fmtAge(r.age_seconds)}</td>
      </tr>
    `).join('');
    renderMiniRows(el.staleTopBody, html);
  }

  function renderStats(stats) {
    state.stats = stats || null;
    state.stale_min = Number(stats?.stale_min || state.stale_min || 30);

    renderKpis(stats);
    renderPackersTop(stats?.packers_top || []);
    renderStationsTop(stats?.stations_top || []);
    renderActiveNow(stats?.active_now || []);
    renderStaleTop(stats?.stale_top || []);

    el.serverTime.textContent = stats?.server_time || '—';

    const rr = stats?.report_range || null;
    if (!rr) {
      el.reportRange.textContent = '—';
    } else {
      const mark = rr.default_today ? 'dziś (domyślnie)' : 'wg filtrów';
      el.reportRange.textContent = `${rr.from || '—'} → ${rr.to || '—'} • ${mark}`;
    }
  }

  function renderQueue(rows) {
    state.rows = Array.isArray(rows) ? rows : [];
    el.tbody.innerHTML = '';

    state.rows.forEach(r => {
      const st = Number(r.status);
      const stale = (state.tab === 'stale') || (st === 40 && r.age_seconds != null && Number(r.age_seconds) >= state.stale_min * 60);

      const tr = document.createElement('tr');
      if (stale) tr.classList.add('row-stale');

      const actions = document.createElement('div');
      actions.className = 'actions';

      const a1 = document.createElement('a');
      a1.className = 'link';
      a1.href = '/order.php?order_code=' + encodeURIComponent(r.order_code);
      a1.textContent = 'Podgląd';
      a1.target = '_blank';
      actions.appendChild(a1);

      const a2 = document.createElement('a');
      a2.className = 'link';
      a2.href = '/order.php?order_code=' + encodeURIComponent(r.order_code) + '#events';
      a2.textContent = 'Historia';
      a2.target = '_blank';
      actions.appendChild(a2);

      if (state.role === 'manager' && st === 40) {
        const b = document.createElement('button');
        b.className = 'btnSm danger';
        b.textContent = 'UNLOCK';
        b.onclick = () => unlock(r.order_code);
        actions.appendChild(b);
      }

      tr.innerHTML = `
        <td><b>${esc(r.order_code)}</b></td>
        <td>${esc(r.subiekt_doc_no || '')}</td>
        <td><span class="pill ${statusClass(st)}">${esc(statusLabel(st))}</span></td>
        <td>${esc(r.station || '')}</td>
        <td>${esc(r.packer || '')}</td>
        <td>${esc(r.pack_started_at || '')}</td>
        <td>${esc(r.pack_ended_at || '')}</td>
        <td>${fmtHMS(r.packing_seconds)}</td>
        <td>${fmtAge(r.age_seconds)}</td>
      `;

      const td = document.createElement('td');
      td.appendChild(actions);
      tr.appendChild(td);

      el.tbody.appendChild(tr);
    });
  }

  function renderPager() {
    const total = Number(state.total || 0);
    const from = total === 0 ? 0 : (state.offset + 1);
    const to = Math.min(state.offset + state.limit, total);
    const pageNo = Math.floor(state.offset / state.limit) + 1;
    const pages = Math.max(1, Math.ceil(total / state.limit));

    el.pageInfo.textContent = `offset ${state.offset} • limit ${state.limit} • total ${total}`;
    el.pagerText.textContent = `strona ${pageNo}/${pages} • rekordy ${from}-${to}`;

    el.prev.disabled = (state.offset <= 0);
    el.next.disabled = (state.offset + state.limit >= total);
  }

  async function loadQueue() {
    const data = await apiJson(buildQueueUrl(), { method:'GET' });
    if (!data.ok) throw new Error(data.error || 'queue error');

    state.stale_min = Number(data.stale_min || state.stale_min || 30);
    state.total = Number(data.total || 0);

    renderQueue(data.items || []);
    renderPager();
  }

  async function loadStats() {
    const data = await apiJson(buildStatsUrl(), { method:'GET' });
    if (!data.ok) throw new Error(data.error || 'stats error');
    renderStats(data);
  }

  async function loadAll(opts = {}) {
    if (state.loading && !opts.force) return;
    state.loading = true;
    setMsg('Ładowanie...');
    try {
      await Promise.all([loadQueue(), loadStats()]);
      setMsg('');
      state.lastReloadAt = Date.now();
    } catch (e) {
      console.error(e);
      setMsg('Błąd serwera / API');
    } finally {
      state.loading = false;
    }
  }

  function stopAutoRefresh() {
    if (state.autoTimer) clearInterval(state.autoTimer);
    state.autoTimer = null;
  }

  function startAutoRefresh() {
    stopAutoRefresh();
    if (!el.autoRefreshOn.checked) return;

    let sec = Number(el.autoRefreshSec.value || 10);
    if (!Number.isFinite(sec) || sec < 3) sec = 10;

    state.autoTimer = setInterval(() => {
      if (document.hidden) return;
      loadAll();
    }, sec * 1000);
  }

  function setTodayRange() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth()+1).padStart(2,'0');
    const d = String(now.getDate()).padStart(2,'0');
    el.f_from.value = `${y}-${m}-${d}T00:00`;
    // do teraz (bez sekund)
    const hh = String(now.getHours()).padStart(2,'0');
    const mm = String(now.getMinutes()).padStart(2,'0');
    el.f_to.value = `${y}-${m}-${d}T${hh}:${mm}`;
  }

  function clearDateRange() {
    el.f_from.value = '';
    el.f_to.value = '';
  }

  function toCsvValue(v) {
    const s = String(v ?? '');
    if (/[",;\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
    return s;
  }

  function exportCurrentCsv() {
    const rows = state.rows || [];
    if (!rows.length) {
      alert('Brak danych do eksportu CSV (załaduj listę).');
      return;
    }

    const headers = [
      'order_code','subiekt_doc_no','status','station','packer',
      'pack_started_at','pack_ended_at','packing_seconds','age_seconds'
    ];

    const lines = [];
    lines.push(headers.join(';'));
    rows.forEach(r => {
      lines.push([
        r.order_code,
        r.subiekt_doc_no,
        statusLabel(r.status),
        r.station,
        r.packer,
        r.pack_started_at,
        r.pack_ended_at,
        r.packing_seconds,
        r.age_seconds
      ].map(toCsvValue).join(';'));
    });

    const blob = new Blob(["\uFEFF" + lines.join('\n')], { type:'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const tab = state.tab === 'stale' ? 'stale' : state.tab;
    const dt = new Date();
    const stamp = dt.getFullYear()
      + String(dt.getMonth()+1).padStart(2,'0')
      + String(dt.getDate()).padStart(2,'0')
      + '_'
      + String(dt.getHours()).padStart(2,'0')
      + String(dt.getMinutes()).padStart(2,'0');
    a.href = url;
    a.download = `queue_${tab}_${stamp}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  function bindFilterEnterReload(input) {
    if (!input) return;
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        state.offset = 0;
        loadAll({force:true});
      }
    });
  }

  // Events
  el.btnLogin.onclick = () => doLogin();
  el.packer_code.addEventListener('keydown', (e) => { if (e.key === 'Enter') doLogin(); });
  el.btnLogoutTop.onclick = () => logoutNow();

  el.btnReload.onclick = () => { state.offset = 0; loadAll({force:true}); };
  el.prev.onclick = () => { state.offset = Math.max(0, state.offset - state.limit); loadAll({force:true}); };
  el.next.onclick = () => { state.offset = state.offset + state.limit; loadAll({force:true}); };

  if (el.btnExportCsv) el.btnExportCsv.onclick = exportCurrentCsv;

  if (el.btnToday) el.btnToday.onclick = () => { setTodayRange(); state.offset = 0; loadAll({force:true}); };
  if (el.btnClearDates) el.btnClearDates.onclick = () => { clearDateRange(); state.offset = 0; loadAll({force:true}); };

  [el.f_station, el.f_packer, el.f_q, el.f_from, el.f_to].forEach(bindFilterEnterReload);
  [el.f_from, el.f_to].forEach(inp => inp && inp.addEventListener('change', () => { state.offset = 0; loadAll({force:true}); }));

  if (el.autoRefreshOn) el.autoRefreshOn.addEventListener('change', startAutoRefresh);
  if (el.autoRefreshSec) el.autoRefreshSec.addEventListener('change', startAutoRefresh);

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && el.autoRefreshOn && el.autoRefreshOn.checked) {
      loadAll();
    }
  });

  el.tabs.forEach(t => {
    t.onclick = () => {
      el.tabs.forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      state.tab = t.dataset.tab;
      state.offset = 0;
      loadAll({force:true});
    };
  });
  if (el.tabs[0]) el.tabs[0].classList.add('active');

  (async () => {
    try {
      const ok = await refreshSession();
      if (ok) {
        startAutoRefresh();
        await loadAll({force:true});
      } else {
        el.packer_code.focus();
      }
    } catch (_) {
      el.packer_code.focus();
    }
  })();
})();
