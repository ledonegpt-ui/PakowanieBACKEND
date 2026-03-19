<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Kolejka + Statystyki</title>
  <link rel="stylesheet" href="/assets/css/app.css?v=3" />
  <link rel="stylesheet" href="/assets/css/queue.css?v=5" />
</head>
<body>
  <div class="wrap queuePage">
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
      <div class="toolbarTop">
        <div class="tabs">
          <button class="tab" data-tab="10">NEW</button>
          <button class="tab" data-tab="40">PACKING</button>
          <button class="tab" data-tab="50">PACKED</button>
          <button class="tab" data-tab="60">CANCELLED</button>
          <button class="tab danger" data-tab="stale">Zawieszone</button>
        </div>

        <div class="toolbarRight">
          <label class="chk">
            <input type="checkbox" id="autoRefreshOn" checked />
            auto refresh
          </label>
          <select id="autoRefreshSec" class="selSm">
            <option value="5">5s</option>
            <option value="10" selected>10s</option>
            <option value="15">15s</option>
            <option value="30">30s</option>
            <option value="60">60s</option>
          </select>
          <button id="btnReload" class="btn">Odśwież</button>
          <button id="btnExportCsv" class="btn">CSV</button>
        </div>
      </div>

      <div class="filters">
        <input id="f_station" placeholder="station (filtr)" />
        <input id="f_packer" placeholder="packer (filtr)" />
        <input id="f_q" placeholder="szukaj: order_code lub doc_no" />
        <input id="f_from" type="datetime-local" />
        <input id="f_to" type="datetime-local" />
        <button id="btnToday" class="btn">Dziś</button>
        <button id="btnClearDates" class="btn">Wyczyść daty</button>
      </div>

      <div class="metaBar">
        <div id="msg" class="msg"></div>
        <div class="metaRight">
          <span class="muted">serwer:</span> <span id="serverTime">—</span>
          <span class="dot">•</span>
          <span class="muted">raport:</span> <span id="reportRange">—</span>
        </div>
      </div>

      <!-- KPI -->
      <div class="statsGrid" id="kpiGrid">
        <!-- render JS -->
      </div>

      <!-- Rankingi / live -->
      <div class="statsSplit">
        <section class="miniCard">
          <h3>Top pakowacze (status=PACKED, zakres raportu)</h3>
          <div class="miniTableWrap">
            <table class="miniTbl">
              <thead>
                <tr><th>#</th><th>Pakowacz</th><th>Spak.</th><th>AVG</th><th>Suma</th></tr>
              </thead>
              <tbody id="packersTopBody"></tbody>
            </table>
          </div>
        </section>

        <section class="miniCard">
          <h3>Top stanowiska (status=PACKED, zakres raportu)</h3>
          <div class="miniTableWrap">
            <table class="miniTbl">
              <thead>
                <tr><th>#</th><th>Stanowisko</th><th>Spak.</th><th>AVG</th><th>Suma</th></tr>
              </thead>
              <tbody id="stationsTopBody"></tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="statsSplit">
        <section class="miniCard">
          <h3>Aktywne teraz (PACKING)</h3>
          <div class="miniTableWrap">
            <table class="miniTbl">
              <thead>
                <tr><th>order_code</th><th>doc_no</th><th>packer</th><th>station</th><th>idle</th><th>całość</th></tr>
              </thead>
              <tbody id="activeNowBody"></tbody>
            </table>
          </div>
        </section>

        <section class="miniCard">
          <h3>Zawieszone TOP (po heartbeat)</h3>
          <div class="miniTableWrap">
            <table class="miniTbl">
              <thead>
                <tr><th>order_code</th><th>doc_no</th><th>packer</th><th>station</th><th>idle</th></tr>
              </thead>
              <tbody id="staleTopBody"></tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="tableHeaderRow">
        <h3>Lista dokumentów</h3>
        <div class="muted" id="pageInfo">—</div>
      </div>

      <div class="tableWrap">
        <table class="tbl queueTbl">
          <thead>
            <tr>
              <th>order_code</th>
              <th>doc_no</th>
              <th>status</th>
              <th>station</th>
              <th>packer</th>
              <th>start</th>
              <th>end</th>
              <th>czas</th>
              <th>idle/age</th>
              <th>akcje</th>
            </tr>
          </thead>
          <tbody id="tbody"></tbody>
        </table>
      </div>

      <div class="pager">
        <button id="prev" class="btn">←</button>
        <div id="pagerText" class="muted">—</div>
        <button id="next" class="btn">→</button>
      </div>
    </section>
  </div>

  <script src="/assets/js/queue.js?v=5"></script>
</body>
</html>
