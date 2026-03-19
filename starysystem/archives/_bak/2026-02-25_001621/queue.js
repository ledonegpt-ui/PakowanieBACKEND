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
    btnReload: $('btnReload'),

    msg: $('msg'),
    tbody: $('tbody'),
    prev: $('prev'),
    next: $('next'),
    pageInfo: $('pageInfo'),
  };

  const state = {
    role: 'packer',
    tab: '10',
    limit: 200,
    offset: 0,
    stale_min: 30,
    total: null,
    auto_refresh_ms: 20000
  };

  let isLoading = false;
  let autoRefreshTimer = null;

  async function apiJson(url, opts) {
    const res = await fetch(url, opts);
    const txt = await res.text();
    let data = null;
    try { data = JSON.parse(txt); } catch (_) {}
    if (!data) throw new Error('non-json');
    return data;
  }

  function esc(v) {
    return String(v == null ? '' : v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
  }

  function fmtHMS(sec) {
    if (sec == null) return '';
    sec = Math.max(0, Number(sec));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = Math.floor(sec % 60);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
  }

  function fmtAge(sec) {
    if (sec == null) return '';
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
    if (st === 50) return 'ok';
    if (st === 40) return 'warn';
    if (st === 60) return 'bad';
    return '';
  }

  async function refreshSession() {
    const s = await apiJson('/api/session.php', { method: 'GET' });
    if (!s.ok) return false;

    state.role = s.role || 'packer';
    el.who.textContent = `${s.packer} • ${state.role}`;
    el.btnLogoutTop.classList.remove('hidden');
    el.loginCard.classList.add('hidden');
    el.queueCard.classList.remove('hidden');
    startAutoRefresh();
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
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ packer_code }).toString()
    });

    if (!data.ok) {
      el.loginMsg.style.color = 'var(--bad)';
      el.loginMsg.textContent = data.error || 'Błąd logowania';
      return;
    }

    await refreshSession();
    state.offset = 0;
    load(true);
  }

  async function logoutNow() {
    stopAutoRefresh();
    try { await apiJson('/api/logout.php', { method: 'POST' }); } catch (_) {}

    el.btnLogoutTop.classList.add('hidden');
    el.queueCard.classList.add('hidden');
    el.loginCard.classList.remove('hidden');
    el.packer_code.value = '';
    el.packer_code.focus();
  }

  function buildUrl() {
    const p = new URLSearchParams();

    if (state.tab === 'stale') {
      p.set('status', '40');
      p.set('stale', '1');
    } else {
      p.set('status', state.tab);
    }

    if (el.f_station.value.trim()) p.set('station', el.f_station.value.trim());
    if (el.f_packer.value.trim())  p.set('packer', el.f_packer.value.trim());
    if (el.f_q.value.trim())       p.set('q', el.f_q.value.trim());
    if (el.f_from.value) p.set('date_from', el.f_from.value);
    if (el.f_to.value)   p.set('date_to', el.f_to.value);

    p.set('limit', String(state.limit));
    p.set('offset', String(state.offset));

    return '/api/queue.php?' + p.toString();
  }

  async function unlock(order_code) {
    const reason = prompt('Powód unlock (opcjonalnie):') || '';
    const data = await apiJson('/api/unlock_pack.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ order_code, reason }).toString()
    });

    if (!data.ok) alert(data.error || 'Błąd unlock');
    load(true);
  }

  function render(rows) {
    el.tbody.innerHTML = '';

    rows.forEach(r => {
      const st = Number(r.status);
      const stale = (state.tab === 'stale') ||
          (st === 40 && r.age_seconds != null && Number(r.age_seconds) >= state.stale_min * 60);

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
        <td class="${statusClass(st)}">${esc(statusLabel(st))}</td>
        <td>${esc(r.station || '')}</td>
        <td>${esc(r.packer || '')}</td>
        <td>${esc(r.pack_started_at || '')}</td>
        <td>${esc(r.pack_ended_at || '')}</td>
        <td>${esc(fmtHMS(r.packing_seconds))}</td>
        <td>${esc(fmtAge(r.age_seconds))}</td>
      `;

      const td = document.createElement('td');
      td.appendChild(actions);
      tr.appendChild(td);

      el.tbody.appendChild(tr);
    });

    const totalTxt = (state.total != null) ? ` • total ${state.total}` : '';
    el.pageInfo.textContent = `offset ${state.offset} • limit ${state.limit}${totalTxt}`;
  }

  async function load(force) {
    if (isLoading && !force) return;

    isLoading = true;
    el.msg.textContent = 'Ładowanie...';

    try {
      const data = await apiJson(buildUrl(), { method: 'GET' });
      if (!data.ok) {
        el.msg.textContent = data.error || 'Błąd';
        return;
      }

      state.stale_min = Number(data.stale_min || 30);
      state.total = (typeof data.total !== 'undefined') ? Number(data.total) : null;

      render(data.items || []);
      el.msg.textContent = '';
    } catch (_) {
      el.msg.textContent = 'Błąd serwera';
    } finally {
      isLoading = false;
    }
  }

  function startAutoRefresh() {
    stopAutoRefresh();
    autoRefreshTimer = setInterval(() => {
      if (document.hidden) return;
      if (el.queueCard.classList.contains('hidden')) return;
      load(false);
    }, state.auto_refresh_ms);
  }

  function stopAutoRefresh() {
    if (autoRefreshTimer) {
      clearInterval(autoRefreshTimer);
      autoRefreshTimer = null;
    }
  }

  function triggerReload() {
    state.offset = 0;
    load(true);
  }

  // events
  el.btnLogin.onclick = () => doLogin();
  el.packer_code.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') doLogin();
  });

  el.btnLogoutTop.onclick = () => logoutNow();

  el.btnReload.onclick = () => { state.offset = 0; load(true); };
  el.prev.onclick = () => {
    state.offset = Math.max(0, state.offset - state.limit);
    load(true);
  };
  el.next.onclick = () => {
    state.offset = state.offset + state.limit;
    load(true);
  };

  [el.f_station, el.f_packer, el.f_q, el.f_from, el.f_to].forEach(inp => {
    if (!inp) return;
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') triggerReload();
    });
    inp.addEventListener('change', () => {
      if (inp.type === 'datetime-local') triggerReload();
    });
  });

  el.tabs.forEach(t => {
    t.onclick = () => {
      el.tabs.forEach(x => x.classList.remove('active'));
      t.classList.add('active');
      state.tab = t.dataset.tab;
      state.offset = 0;
      load(true);
    };
  });
  if (el.tabs[0]) el.tabs[0].classList.add('active');

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !el.queueCard.classList.contains('hidden')) {
      load(false);
    }
  });

  window.addEventListener('beforeunload', stopAutoRefresh);

  (async () => {
    const ok = await refreshSession();
    if (ok) load(true);
    else el.packer_code.focus();
  })();
})();