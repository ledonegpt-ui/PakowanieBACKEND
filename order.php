<?php declare(strict_types=1);
$code = trim((string)($_GET['order_code'] ?? ''));
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Podgląd</title>
  <link rel="stylesheet" href="/assets/css/app.css?v=1" />
  <style>
    .two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media (max-width: 900px){.two{grid-template-columns:1fr}}
    .tbl2{width:100%;border-collapse:collapse}
    .tbl2 td,.tbl2 th{padding:10px;border-bottom:1px solid #2a2f3a;text-align:left}
    .muted{color:#9aa3b2}
    .h{font-size:22px;font-weight:900}
    .pill{display:inline-block;padding:4px 8px;border:1px solid #2a2f3a;border-radius:999px;font-weight:800}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="h">Podgląd zamówienia</div>
      <div class="muted">order_code: <b><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></b></div>
    </div>

    <div class="two">
      <div class="card">
        <div class="h">Pozycje (Subiekt)</div>
        <div id="orderBox" class="muted">Ładowanie...</div>
        <table class="tbl2" id="itemsTbl"></table>
      </div>

      <div class="card" id="events">
        <div class="h">Historia</div>
        <div id="evBox" class="muted">Ładowanie...</div>
        <table class="tbl2" id="evTbl"></table>
      </div>
    </div>
  </div>

<script>
(async()=>{
  const code = <?php echo json_encode($code); ?>;
  if(!code){ document.getElementById('orderBox').textContent = 'Brak order_code'; return; }

  async function j(url){
    const r = await fetch(url); const t = await r.text();
    try { return JSON.parse(t); } catch(e){ throw new Error('non-json'); }
  }

  try{
    const s = await j('/api/scan.php?order_code='+encodeURIComponent(code));
    if(!s.ok){ document.getElementById('orderBox').textContent = s.error||'Błąd'; return; }

    const st = Number(s.status);
    const label = st===10?'NEW':st===40?'PACKING':st===50?'PACKED':st===60?'CANCELLED':String(st);

    document.getElementById('orderBox').innerHTML =
      `<div><span class="pill">${label}</span> <b>${s.order_code}</b></div>
       <div class="muted">${s.subiekt_doc_no||''}</div>
       <div class="muted">packer: ${(s.packer||'')} • station: ${(s.station||'')}</div>
       <div class="muted">start: ${(s.pack_started_at||'')} • end: ${(s.pack_ended_at||'')}</div>
       ${(s.warning?`<div style="color:#fbbf24;font-weight:900;margin-top:8px">${s.warning}</div>`:'')}
      `;

    const tbl = document.getElementById('itemsTbl');
    tbl.innerHTML = '<tr><th>symbol</th><th>qty</th><th>name</th></tr>';
    (s.items||[]).forEach(it=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `<td><b>${it.subiekt_symbol||''}</b></td><td><b>${it.quantity||''}</b></td><td class="muted">${it.name||''}</td>`;
      tbl.appendChild(tr);
    });
    if((s.items||[]).length===0){
      const tr=document.createElement('tr'); tr.innerHTML='<td colspan="3" class="muted">brak pozycji</td>'; tbl.appendChild(tr);
    }
  }catch(e){
    document.getElementById('orderBox').textContent='Błąd serwera';
  }

  try{
    const ev = await j('/api/events.php?order_code='+encodeURIComponent(code));
    if(!ev.ok){ document.getElementById('evBox').textContent = ev.error||'Błąd'; return; }
    document.getElementById('evBox').textContent = '';
    const t = document.getElementById('evTbl');
    t.innerHTML = '<tr><th>czas</th><th>typ</th><th>packer/station</th><th>msg</th></tr>';
    (ev.items||[]).forEach(x=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `<td>${x.created_at||''}</td><td><b>${x.event_type||''}</b></td><td class="muted">${(x.packer||'')} / ${(x.station||'')}</td><td class="muted">${(x.message||'')}</td>`;
      t.appendChild(tr);
    });
    if((ev.items||[]).length===0){
      const tr=document.createElement('tr'); tr.innerHTML='<td colspan="4" class="muted">brak eventów</td>'; t.appendChild(tr);
    }
  }catch(e){
    document.getElementById('evBox').textContent='Błąd serwera';
  }
})();
</script>
</body>
</html>
