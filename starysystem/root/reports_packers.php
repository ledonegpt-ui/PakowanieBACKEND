<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Raport magazynierów</title>

  <link rel="stylesheet" href="/assets/css/app.css?v=3" />

  <!-- DataTables CDN (jeśli wolisz lokalnie, podmień ścieżki) -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css" />

  <style>
    body { background:#111; color:#eee; margin:0; font-family:Arial, sans-serif; }
    .wrap { max-width: 1500px; margin: 0 auto; padding: 16px; }
    .top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .brand { font-size: 22px; font-weight: 700; }
    .who { color:#bbb; font-size: 13px; }

    .card {
      background:#1a1a1a;
      border:1px solid #2b2b2b;
      border-radius:10px;
      padding:14px;
      margin-bottom:14px;
    }

    .filters {
      display:grid;
      grid-template-columns: 1fr 1fr 1fr auto auto auto;
      gap:10px;
      align-items:end;
    }

    .row { display:flex; flex-direction:column; gap:6px; }
    .row label { font-size:12px; color:#aaa; }
    .row input {
      background:#111;
      color:#eee;
      border:1px solid #333;
      border-radius:8px;
      padding:8px 10px;
      min-height: 36px;
    }

    .btn {
      background:#2a2a2a;
      color:#eee;
      border:1px solid #444;
      border-radius:8px;
      padding:9px 12px;
      cursor:pointer;
      min-height: 36px;
    }
    .btn:hover { background:#333; }
    .btn.primary { background:#164b9b; border-color:#2b66c4; }
    .btn.primary:hover { background:#1b5abb; }

    .muted { color:#aaa; font-size:12px; }

    .grid2 {
      display:grid;
      grid-template-columns: 1fr;
      gap:14px;
    }

    .tableTitle {
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:8px;
      margin-bottom:8px;
    }

    .chip {
      display:inline-block;
      border:1px solid #555;
      background:#222;
      border-radius:999px;
      padding:4px 8px;
      font-size:12px;
      color:#ddd;
    }

    .chip strong { color:#fff; }

    .dangerText { color:#ff8f8f; }
    .okText { color:#9be59b; }

    /* DataTables ciemniej */
    table.dataTable {
      color:#eee;
      border-color:#333 !important;
    }
    table.dataTable thead th, table.dataTable thead td {
      border-bottom:1px solid #444 !important;
      color:#ddd;
    }
    table.dataTable tbody tr {
      background:#1a1a1a !important;
    }
    table.dataTable tbody tr:hover {
      background:#222 !important;
    }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
      background:#111;
      color:#eee;
      border:1px solid #333;
      border-radius:6px;
      padding:4px 6px;
    }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
      color:#bbb !important;
      margin-top:8px;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button {
      color:#ddd !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background:#2a2a2a !important;
      border:1px solid #444 !important;
      color:#fff !important;
    }

    .summary-row-active {
      outline: 1px solid #2b66c4;
      background: #15253f !important;
    }

    @media (max-width: 1100px) {
      .filters {
        grid-template-columns: 1fr 1fr;
      }
    }
    @media (max-width: 700px) {
      .filters {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
<div class="wrap">
  <header class="top">
    <div class="brand">Raport magazynierów</div>
    <div class="who" id="who">—</div>
  </header>

  <section class="card">
    <div class="filters">
      <div class="row">
        <label>Od (pack_ended_at)</label>
        <input id="date_from" type="datetime-local" />
      </div>
      <div class="row">
        <label>Do (pack_ended_at)</label>
        <input id="date_to" type="datetime-local" />
      </div>
      <div class="row">
        <label>Magazynier (opcjonalnie)</label>
        <input id="packer_filter" placeholder="np. JANEK" />
      </div>
      <button id="btnReload" class="btn primary">Odśwież</button>
      <button id="btnClearPacker" class="btn">Wyczyść wybór magazyniera</button>
      <button id="btnSetToday" class="btn">Dziś</button>
    </div>
    <div class="muted" style="margin-top:10px;">
      Kliknij wiersz w podsumowaniu, aby zobaczyć szczegóły „co spakował”.
    </div>
  </section>

  <section class="card">
    <div class="tableTitle">
      <div><strong>Podsumowanie magazynierów</strong></div>
      <div id="summaryHint" class="muted">—</div>
    </div>
    <table id="tblSummary" class="display" style="width:100%">
      <thead>
      <tr>
        <th>Magazynier</th>
        <th>Spakowane</th>
        <th>Śr. czas</th>
        <th>Suma czasu</th>
        <th>Pierwsze zakończenie</th>
        <th>Ostatnie zakończenie</th>
        <th>Force finish</th>
        <th>Unlock</th>
      </tr>
      </thead>
    </table>
  </section>

  <section class="card">
    <div class="tableTitle">
      <div>
        <strong>Szczegóły</strong>
        <span id="selectedPackerChip" class="chip" style="display:none;"></span>
      </div>
      <div id="detailsHint" class="muted">Lista spakowanych zamówień</div>
    </div>
    <table id="tblDetails" class="display" style="width:100%">
      <thead>
      <tr>
        <th>Zakończono</th>
        <th>order_code</th>
        <th>doc_no</th>
        <th>Magazynier</th>
        <th>Stanowisko</th>
        <th>Start</th>
        <th>Koniec</th>
        <th>Czas</th>
        <th>Force finish</th>
        <th>Podgląd</th>
      </tr>
      </thead>
    </table>
  </section>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
(function () {
  var selectedPacker = '';
  var summaryTable = null;
  var detailsTable = null;

  function pad(n) { return String(n).padStart(2, '0'); }

  function toLocalInputValue(d) {
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) +
      'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function setDefaultRangeToday() {
    var now = new Date();
    var from = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
    document.getElementById('date_from').value = toLocalInputValue(from);
    document.getElementById('date_to').value = toLocalInputValue(now);
  }

  function fmtHMS(sec) {
    if (sec == null || sec === '') return '';
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    return pad(h) + ':' + pad(m) + ':' + pad(s);
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getFilters() {
    return {
      date_from: document.getElementById('date_from').value || '',
      date_to: document.getElementById('date_to').value || '',
      packer: document.getElementById('packer_filter').value.trim() || '',
      selected_packer: selectedPacker || ''
    };
  }

  function updateSelectedChip() {
    var chip = document.getElementById('selectedPackerChip');
    if (!selectedPacker) {
      chip.style.display = 'none';
      chip.innerHTML = '';
      return;
    }
    chip.style.display = 'inline-block';
    chip.innerHTML = 'Wybrany: <strong>' + esc(selectedPacker) + '</strong>';
  }

  function reloadBoth() {
    if (summaryTable) summaryTable.ajax.reload();
    if (detailsTable) detailsTable.ajax.reload();
    updateSelectedChip();
  }

  function initSessionInfo() {
    fetch('/api/session.php', { method: 'GET' })
      .then(function (r) { return r.json(); })
      .then(function (s) {
        var who = document.getElementById('who');
        if (!s || !s.ok) {
          who.textContent = 'Brak sesji';
          return;
        }
        who.textContent = (s.packer || '-') + ' • ' + (s.role || 'packer');
      })
      .catch(function () {
        document.getElementById('who').textContent = 'Brak sesji';
      });
  }

  function initTables() {
    summaryTable = $('#tblSummary').DataTable({
      processing: true,
      serverSide: true,
      pageLength: 25,
      order: [[1, 'desc']],
      searching: true,
      ajax: {
        url: '/api/report_packers_summary.php',
        type: 'GET',
        data: function (d) {
          var f = getFilters();
          d.date_from = f.date_from;
          d.date_to = f.date_to;
          d.packer = f.packer; // dodatkowy filtr (manualny)
        }
      },
      columns: [
        { data: 'packer' },
        { data: 'packed_count' },
        {
          data: 'avg_sec',
          render: function (v) { return fmtHMS(v); }
        },
        {
          data: 'total_sec',
          render: function (v) { return fmtHMS(v); }
        },
        { data: 'first_finish' },
        { data: 'last_finish' },
        {
          data: 'force_finish_count',
          render: function (v) {
            var n = parseInt(v || 0, 10);
            return n > 0 ? '<span class="dangerText">' + n + '</span>' : '0';
          }
        },
        {
          data: 'unlock_count',
          render: function (v) {
            var n = parseInt(v || 0, 10);
            return n > 0 ? '<span class="dangerText">' + n + '</span>' : '0';
          }
        }
      ],
      language: {
        processing: 'Ładowanie...',
        search: 'Szukaj:',
        lengthMenu: 'Pokaż _MENU_',
        info: 'Pozycje _START_–_END_ z _TOTAL_',
        infoEmpty: 'Brak danych',
        zeroRecords: 'Brak wyników',
        paginate: { first: 'Pierwsza', last: 'Ostatnia', next: '→', previous: '←' }
      },
      rowCallback: function (row, data) {
        if (selectedPacker && data && data.packer === selectedPacker) {
          row.classList.add('summary-row-active');
        } else {
          row.classList.remove('summary-row-active');
        }
      }
    });

    $('#tblSummary tbody').on('click', 'tr', function () {
      var data = summaryTable.row(this).data();
      if (!data || !data.packer) return;

      if (selectedPacker === data.packer) {
        selectedPacker = '';
      } else {
        selectedPacker = data.packer;
      }

      updateSelectedChip();
      summaryTable.rows().invalidate().draw(false);
      detailsTable.ajax.reload();
    });

    detailsTable = $('#tblDetails').DataTable({
      processing: true,
      serverSide: true,
      pageLength: 50,
      order: [[0, 'desc']],
      searching: true,
      ajax: {
        url: '/api/report_packers_details.php',
        type: 'GET',
        data: function (d) {
          var f = getFilters();
          d.date_from = f.date_from;
          d.date_to = f.date_to;
          d.packer = f.packer;
          d.selected_packer = f.selected_packer; // kliknięty z summary ma priorytet na backendzie
        }
      },
      columns: [
        { data: 'pack_ended_at' },
        { data: 'order_code' },
        { data: 'subiekt_doc_no' },
        { data: 'packer' },
        { data: 'station' },
        { data: 'pack_started_at' },
        { data: 'pack_ended_at' },
        {
          data: 'packing_seconds',
          render: function (v) { return fmtHMS(v); }
        },
        {
          data: 'force_finish',
          render: function (v) {
            return String(v) === '1'
              ? '<span class="dangerText">TAK</span>'
              : '<span class="okText">nie</span>';
          }
        },
        {
          data: null,
          orderable: false,
          searchable: false,
          render: function (row) {
            var oc = row && row.order_code ? row.order_code : '';
            return '<a href="/order.php?order_code=' + encodeURIComponent(oc) + '" target="_blank">Podgląd</a>';
          }
        }
      ],
      language: {
        processing: 'Ładowanie...',
        search: 'Szukaj:',
        lengthMenu: 'Pokaż _MENU_',
        info: 'Pozycje _START_–_END_ z _TOTAL_',
        infoEmpty: 'Brak danych',
        zeroRecords: 'Brak wyników',
        paginate: { first: 'Pierwsza', last: 'Ostatnia', next: '→', previous: '←' }
      }
    });
  }

  document.getElementById('btnReload').addEventListener('click', function () {
    reloadBoth();
  });

  document.getElementById('btnClearPacker').addEventListener('click', function () {
    selectedPacker = '';
    document.getElementById('packer_filter').value = '';
    updateSelectedChip();
    reloadBoth();
  });

  document.getElementById('btnSetToday').addEventListener('click', function () {
    setDefaultRangeToday();
    reloadBoth();
  });

  ['date_from', 'date_to', 'packer_filter'].forEach(function (id) {
    var el = document.getElementById(id);
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') reloadBoth();
    });
    el.addEventListener('change', function () {
      if (id !== 'packer_filter') reloadBoth();
    });
  });

  setDefaultRangeToday();
  initSessionInfo();
  initTables();
  updateSelectedChip();
})();
</script>
</body>
</html>
