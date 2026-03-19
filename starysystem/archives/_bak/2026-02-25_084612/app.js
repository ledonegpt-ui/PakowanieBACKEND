(() => {
  const $ = (id) => document.getElementById(id);

  const el = {
    statusbar: $('statusbar'),

    loginCard: $('loginCard'),
    station_no: $('station_no'),
    packer_code: $('packer_code'),
    btnLogin: $('btnLogin'),
    loginMsg: $('loginMsg'),

    scanCard: $('scanCard'),
    scanInput: $('scanInput'),
    hMain: $('hMain'),
    hSub: $('hSub'),
    timerValue: $('timerValue'),
    orderMsg: $('orderMsg'),
    items: $('items'),
    btnLogoutTop: $('btnLogoutTop'),
    btnFinish: $('btnFinish'),
    btnReprint: $('btnReprint'),
    btnClear: $('btnClear'),
  };

  const FINISH_CODES = new Set(['FINISH','END','ZAKONCZ','ZAKOŃCZ','SPAKOWANE','PACKED']);
  const CLEAR_CODES  = new Set(['CLEAR','RESET','WYCZYSC','WYCZYŚĆ']);
  const CANCEL_CODES = new Set(['CANCEL','ANULUJ','BRAK','NOSTOCK','NO_STOCK']);
  const LOGOUT_CODES = new Set(['LOGOUT','WYLOGUJ','EXIT']);

  // SAFETY: przy skanowaniu innego zamówienia w trakcie pakowania wymagamy 2x skanu w 8s
  const NEXT_SCAN_CONFIRM_MS = 8000;

  const state = {
    ctx: null,
    order: null,
    orderItems: [],
    timer: { start: null, end: null, t: null },
    pendingNext: null, // { code, at }
  };

  function setStatus(t){ el.statusbar.textContent = t || ''; }
  function flash(ok){
    el.scanCard.classList.remove('flash-ok','flash-bad');
    void el.scanCard.offsetWidth;
    el.scanCard.classList.add(ok ? 'flash-ok' : 'flash-bad');
  }
  function beep(ok){
    try{
      const ctx=new (window.AudioContext||window.webkitAudioContext)();
      const o=ctx.createOscillator(); const g=ctx.createGain();
      o.type='sine'; o.frequency.value=ok?880:220; g.gain.value=0.08;
      o.connect(g); g.connect(ctx.destination); o.start();
      setTimeout(()=>{ o.stop(); ctx.close(); }, ok?90:170);
    }catch(_){}
  }

  function parseMysqlLocal(dt){
    if(!dt) return null;
    const m=String(dt).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
    if(!m) return null;
    return new Date(+m[1], +m[2]-1, +m[3], +m[4], +m[5], +m[6]);
  }
  function fmtHMS(ms){
    if(!isFinite(ms)||ms<0) ms=0;
    const sec=Math.floor(ms/1000);
    const h=Math.floor(sec/3600);
    const m=Math.floor((sec%3600)/60);
    const s=sec%60;
    const pad=(n)=>String(n).padStart(2,'0');
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
  }
  function stopTimer(){ if(state.timer.t) clearInterval(state.timer.t); state.timer.t=null; }
  function startTimer(startDt,endDt){
    stopTimer(); state.timer.start=startDt; state.timer.end=endDt;
    const tick=()=>{
      if(!state.timer.start){ el.timerValue.textContent='00:00:00'; return; }
      const now = state.timer.end ? state.timer.end : new Date();
      el.timerValue.textContent = fmtHMS(now - state.timer.start);
    };
    tick(); state.timer.t=setInterval(tick, 250);
  }

  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, (c)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  function safeImageUrl(v){
    const s = String(v ?? '').trim();
    if (!s) return '';
    if (/^https?:\/\//i.test(s)) return s;
    return '';
  }

  function attachProductImage(box, imageUrl, altText){
    if (!box || !imageUrl) return;

    const img = document.createElement('img');
    img.src = imageUrl;
    img.alt = altText || '';
    img.loading = 'lazy';
    img.decoding = 'async';
    // przy hotlinkach bywa pomocne
    img.referrerPolicy = 'no-referrer';

    // bez zmian w CSS pliku – styl lokalnie
    img.style.width = '100%';
    img.style.height = '100%';
    img.style.objectFit = 'contain';
    img.style.display = 'block';
    img.style.borderRadius = '10px';

    img.onerror = () => {
      // jak obrazek nie wstanie, zostaje sam placeholder .img
      if (img.parentNode) img.parentNode.removeChild(img);
    };

    box.appendChild(img);
  }

  function isCurrentPackingMine(){
    if(!state.order || !state.ctx) return false;
    if(Number(state.order.status) !== 40) return false;
    return state.order.packer === state.ctx.packer && state.order.station === state.ctx.station_name;
  }

  function setOrderView(o, items, warning){
    state.order = o || null;
    state.orderItems = Array.isArray(items) ? items : [];

    if(!o){
      el.hMain.textContent='—'; el.hSub.textContent='—';
      el.orderMsg.textContent=''; el.items.innerHTML='';
      el.btnFinish.disabled=true;
      startTimer(null,null);
      // btnReprint: NIGDY nie disabled, żeby nie znikał w CSS
      return;
    }

    const status = Number(o.status);
    const statusLabel = status===50?'PACKED':status===40?'PACKING':status===60?'CANCELLED':'READY';

    el.hMain.textContent = `${o.order_code} • ${statusLabel}`;
    el.hSub.textContent = `${o.subiekt_doc_no || ''}`.trim() || '—';

    el.orderMsg.style.color = 'var(--muted)';
    el.orderMsg.textContent = '';

    if(status===50){
      el.orderMsg.style.color='var(--bad)';
      el.orderMsg.textContent = `SPAKOWANE: ${o.packer||'?'} • ${o.pack_ended_at||''}`;
    } else if(status===60){
      el.orderMsg.style.color='var(--bad)';
      el.orderMsg.textContent = `ANULOWANE: zeskanuj kod kierownika (a0...) aby wznowic`;
    } else if(status===40){
      if(state.ctx && (o.packer!==state.ctx.packer || o.station!==state.ctx.station_name)){
        el.orderMsg.style.color='var(--bad)';
        el.orderMsg.textContent = `W TRAKCIE na innym stanowisku: ${o.packer||'?'} • ${o.station||'?'} • ${o.pack_started_at||''}`;
      } else if(isCurrentPackingMine()){
        el.orderMsg.textContent = `FINISH zakonczy • CANCEL anuluje • LOGOUT wyloguje`;
      }
    }

    if(warning && status!==50 && status!==60 && !(status===40 && state.ctx && (o.packer!==state.ctx.packer || o.station!==state.ctx.station_name))){
      el.orderMsg.style.color='var(--warn)';
      el.orderMsg.textContent = warning;
    }

    el.items.innerHTML = '';
    state.orderItems.forEach(it=>{
      const qty = Number(it.quantity || 0);
      const desc = String(it.subiekt_desc || '').trim();
      const imageUrl = safeImageUrl(it.image_url);

      const div = document.createElement('div');
      div.className = 'item';
      div.innerHTML = `
        <div class="img"></div>
        <div class="sym">${escapeHtml(it.subiekt_symbol || '')}</div>
        <div class="qty ${qty>1 ? 'multi':''}">${qty}</div>
        <div class="meta">
          <div class="name">${escapeHtml(it.name || '')}</div>
          <div class="desc">${escapeHtml(desc)}</div>
        </div>
      `;

      // Dołącz zdjęcie jeśli jest URL
      if (imageUrl) {
        const imgBox = div.querySelector('.img');
        attachProductImage(imgBox, imageUrl, String(it.name || it.subiekt_symbol || ''));
      }

      el.items.appendChild(div);
    });

    startTimer(parseMysqlLocal(o.pack_started_at), parseMysqlLocal(o.pack_ended_at));

    // AWARYJNY przycisk tylko gdy jest PACKING (status=40). Manager może na cudzym.
    el.btnFinish.disabled = !(status === 40);
  }

  async function apiJson(url, opts){
    const res = await fetch(url, opts);
    const txt = await res.text();
    let data=null; try{ data=JSON.parse(txt); }catch(_){}
    if(!data) throw new Error('Błąd serwera (nie-JSON)');
    return data;
  }

  async function logoutNow(){
    try{ await apiJson('/api/logout.php', {method:'POST'}); }catch(_){}
    state.ctx=null; setStatus(''); setOrderView(null,[], '');
    el.scanCard.classList.add('hidden');
    el.loginCard.classList.remove('hidden');
    if(el.btnLogoutTop) el.btnLogoutTop.classList.add('hidden');
    el.packer_code.value=''; el.packer_code.focus();
  }

  async function refreshSession(){
    try{
      const s = await apiJson('/api/session.php', {method:'GET'});
      if(!s.ok || !s.has_station) return false;
      state.ctx = s;
      setStatus(`Zalogowano: ${s.packer} • ${s.station_name} • drukarka: ${s.printer_ip || '-'}`);
      el.loginCard.classList.add('hidden');
      el.scanCard.classList.remove('hidden');
      if(el.btnLogoutTop) el.btnLogoutTop.classList.remove('hidden');
      el.scanInput.focus();
      return true;
    }catch(_){ return false; }
  }

  async function doLogin(){
    el.loginMsg.textContent='';
    const station_no = String(el.station_no.value || '').trim();
    const packer_code = String(el.packer_code.value || '').trim();
    if(!station_no){ el.loginMsg.style.color='var(--bad)'; el.loginMsg.textContent='Wybierz stanowisko'; return; }
    if(!packer_code){ el.loginMsg.style.color='var(--bad)'; el.loginMsg.textContent='Zeskanuj kod pakowacza'; return; }

    const data = await apiJson('/api/login.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({station_no, packer_code}).toString()
    });

    if(!data.ok){
      el.loginMsg.style.color='var(--bad)';
      el.loginMsg.textContent=data.error || 'Błąd logowania';
      beep(false);
      return;
    }

    state.ctx = data;
    setStatus(`Zalogowano: ${data.packer} • ${data.station_name} • drukarka: ${data.printer_ip || '-'}`);
    el.loginCard.classList.add('hidden');
    el.scanCard.classList.remove('hidden');
    if(el.btnLogoutTop) el.btnLogoutTop.classList.remove('hidden');
    el.packer_code.value=''; el.scanInput.value=''; el.scanInput.focus();
    beep(true);
  }

  // NORMALNY FINISH (druk)
  async function doFinishCurrent(){
    if(!state.order) return false;
    const data = await apiJson('/api/finish_pack.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({order_code: state.order.order_code, do_print:'1'}).toString()
    });
    if(data.ok){ flash(true); beep(true); setOrderView(data.order, state.orderItems, ''); return true; }
    flash(false); beep(false); setOrderView(data.order||state.order, state.orderItems, data.error||'Błąd finish'); return false;
  }

  // AWARYJNY FINISH (bez druku, manager może force)
  async function doFinishEmergency(){
    if(!state.order) return false;
    const data = await apiJson('/api/finish_pack.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        order_code: state.order.order_code,
        do_print:'0',
        skip_print:'1',
        force_finish:'1'
      }).toString()
    });
    if(data.ok){ flash(true); beep(true); setOrderView(data.order, state.orderItems, ''); return true; }
    flash(false); beep(false); setOrderView(data.order||state.order, state.orderItems, data.error||'Błąd finish'); return false;
  }

  async function doCancelCurrent(reason){
    if(!state.order) return false;
    const payload=new URLSearchParams({order_code: state.order.order_code});
    if(reason) payload.set('reason', reason);
    const data = await apiJson('/api/cancel_pack.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: payload.toString()
    });
    if(data.ok){ flash(true); beep(true); setOrderView(data.order, state.orderItems, ''); return true; }
    flash(false); beep(false); setOrderView(data.order||state.order, state.orderItems, data.error||'Błąd cancel'); return false;
  }

  async function doStartIfNeeded(scanData){
    if(!Array.isArray(scanData.items) || scanData.items.length===0) return;
    if(Number(scanData.status) >= 40) return;

    const started = await apiJson('/api/start_pack.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({order_code: scanData.order_code}).toString()
    });

    if(started.ok){
      flash(true); beep(true);
      setOrderView(started.order, scanData.items, scanData.warning||'');
    } else {
      flash(false); beep(false);
      setOrderView(started.order||scanData, scanData.items, started.error || scanData.warning || '');
    }
  }

  async function doReprintCurrent(){
    if(!state.order){
      flash(false); beep(false);
      el.orderMsg.style.color='var(--bad)';
      el.orderMsg.textContent = 'Brak zamówienia do reprintu';
      return false;
    }

    const data = await apiJson('/api/reprint_label.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({order_code: state.order.order_code}).toString()
    });

    if(data.ok){
      flash(true); beep(true);
      setOrderView(data.order||state.order, state.orderItems, 'OK: wydrukowano ponownie etykietę');
      return true;
    }

    flash(false); beep(false);
    setOrderView(data.order||state.order, state.orderItems, (data.error||'Błąd reprint') + (data.hint?(' • '+data.hint):''));
    return false;
  }

  async function doScan(){
    const raw = String(el.scanInput.value||'').trim();
    el.scanInput.value=''; el.scanInput.focus();
    if(!raw) return;

    const up = raw.toUpperCase();
    const cmd = up.split(':')[0];
    const reason = raw.includes(':') ? raw.split(':').slice(1).join(':').trim() : '';

    // Manager badge on CANCELLED order: any a0... code triggers REOPEN attempt
    if (up.startsWith('A0') && state.order && Number(state.order.status) === 60) {
      try {
        const data = await apiJson('/api/reopen_order.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({ order_code: state.order.order_code, manager_code: raw }).toString()
        });
        if (!data.ok) {
          flash(false); beep(false);
          el.orderMsg.style.color='var(--bad)';
          el.orderMsg.textContent = data.error || 'REOPEN error';
          return;
        }
        const scan = await apiJson('/api/scan.php?log=1&order_code=' + encodeURIComponent(state.order.order_code), {method:'GET'});
        if (scan.ok) {
          setOrderView(scan, scan.items || [], scan.warning || '');
          await doStartIfNeeded(scan);
        }
        flash(true); beep(true);
        return;
      } catch (_) {
        flash(false); beep(false);
        el.orderMsg.style.color='var(--bad)';
        el.orderMsg.textContent = 'REOPEN server error';
        return;
      }
    }

    if(LOGOUT_CODES.has(cmd)){ await logoutNow(); return; }

    if(FINISH_CODES.has(cmd)){
      if(!state.order || !isCurrentPackingMine()){
        flash(false); beep(false);
        el.orderMsg.style.color='var(--bad)';
        el.orderMsg.textContent='Nie mozna zakonczyc (brak PACKING na tym stanowisku)';
        return;
      }
      await doFinishCurrent(); return;
    }

    if(CANCEL_CODES.has(cmd)){
      if(!state.order){
        flash(false); beep(false);
        el.orderMsg.style.color='var(--bad)';
        el.orderMsg.textContent='Brak zamowienia do anulowania';
        return;
      }
      const st = Number(state.order.status);
      if(st >= 40 && !isCurrentPackingMine() && st !== 60){
        flash(false); beep(false);
        el.orderMsg.style.color='var(--bad)';
        el.orderMsg.textContent='Nie mozna anulowac (inne stanowisko / juz spakowane)';
        return;
      }
      await doCancelCurrent(reason); return;
    }

    if(CLEAR_CODES.has(cmd)){ setOrderView(null, [], ''); flash(true); beep(true); return; }

    // SAFETY: przejście na inne zamówienie wymaga 2x skanu (gdy aktualne jest PACKING u nas)
    if(isCurrentPackingMine() && state.order && up !== String(state.order.order_code).toUpperCase()){
      const now = Date.now();
      if(state.pendingNext && state.pendingNext.code === up && (now - state.pendingNext.at) < NEXT_SCAN_CONFIRM_MS){
        state.pendingNext = null;
        const ok = await doFinishCurrent();
        if(!ok) return;
      } else {
        state.pendingNext = { code: up, at: now };
        flash(false); beep(false);
        el.orderMsg.style.color='var(--warn)';
        el.orderMsg.textContent='Masz otwarte zamówienie. Zeskanuj TEN SAM kod jeszcze raz, żeby zakończyć poprzednie i przejść dalej. (albo użyj FINISH)';
        return;
      }
    } else {
      state.pendingNext = null;
    }

    el.orderMsg.style.color='var(--muted)';
    el.orderMsg.textContent='Szukam...';
    el.items.innerHTML='';
    el.btnFinish.disabled=true;

    try{
      const data = await apiJson('/api/scan.php?log=1&order_code='+encodeURIComponent(raw), {method:'GET'});
      if(!data.ok){
        flash(false); beep(false);
        el.orderMsg.style.color='var(--bad)';
        el.orderMsg.textContent=data.error || 'Blad skanu';
        setOrderView(null, [], '');
        return;
      }
      setOrderView(data, data.items || [], data.warning || '');
      await doStartIfNeeded(data);
    }catch(_){
      flash(false); beep(false);
      el.orderMsg.style.color='var(--bad)';
      el.orderMsg.textContent='Blad serwera';
      setOrderView(null, [], '');
    }
  }

  el.btnLogin.addEventListener('click', doLogin);
  el.packer_code.addEventListener('keydown', (e)=>{ if(e.key==='Enter') doLogin(); });
  el.scanInput.addEventListener('keydown', (e)=>{ if(e.key==='Enter') doScan(); });
  if(el.btnLogoutTop) el.btnLogoutTop.addEventListener('click', logoutNow);

  // AWARYJNY: zawsze bez druku
  el.btnFinish.addEventListener('click', async ()=>{ if(state.order && Number(state.order.status)===40) await doFinishEmergency(); });

  // REPRINT: zawsze widoczny, backend rozstrzyga uprawnienia
  if(el.btnReprint){
    el.btnReprint.addEventListener('click', async ()=>{ await doReprintCurrent(); });
  }

  el.btnClear.addEventListener('click', ()=>setOrderView(null,[], ''));

  (async()=>{ const ok = await refreshSession(); if(!ok) el.packer_code.focus(); })();
})();