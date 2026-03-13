<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Kolejka</title>
  <link rel="stylesheet" href="/assets/css/app.css?v=3" />
  <link rel="stylesheet" href="/assets/css/queue.css?v=2" />
</head>
<body>
  <div class="wrap">
    <header class="top">
      <button id="btnLogoutTop" class="btnSm hidden">Wyloguj</button>
      <div class="brand">KOLEJKA</div>
      <div class="statusbar" id="who">—</div>
    </header>

    <section class="card" id="loginCard">
      <h1>Login</h1>
      <div class="row">
        <label>Kod usera (scan)</label>
        <input id="packer_code" autocomplete="off" placeholder="Zeskanuj kod (a0..)" />
      </div>
      <button id="btnLogin" class="btn primary">Zaloguj</button>
      <div class="msg" id="loginMsg"></div>
    </section>

    <section class="card hidden" id="queueCard">
      <div class="tabs">
        <button class="tab" data-tab="10">NEW</button>
        <button class="tab" data-tab="40">PACKING</button>
        <button class="tab" data-tab="50">PACKED</button>
        <button class="tab" data-tab="60">CANCELLED</button>
        <button class="tab danger" data-tab="stale">Zawieszone</button>
      </div>

      <div class="filters">
        <input id="f_station" placeholder="station (filtr)" />
        <input id="f_packer" placeholder="packer (filtr)" />
        <input id="f_q" placeholder="szukaj: order_code lub doc_no" />
        <input id="f_from" type="datetime-local" />
        <input id="f_to" type="datetime-local" />
        <button id="btnReload" class="btn">Odśwież</button>
      </div>

      <div class="msg" id="msg"></div>

      <div class="tableWrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>order_code</th><th>doc_no</th><th>status</th><th>station</th><th>packer</th>
              <th>start</th><th>end</th><th>czas</th><th>age</th><th>akcje</th>
            </tr>
          </thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>

      <div class="pager">
        <button id="prev" class="btn">←</button>
        <div id="pageInfo" class="muted">—</div>
        <button id="next" class="btn">→</button>
      </div>
    </section>
  </div>

  <script src="/assets/js/queue.js?v=2"></script>
</body>
</html>
