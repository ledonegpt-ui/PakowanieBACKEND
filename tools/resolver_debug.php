<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);
$cfg = require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Lib/Db.php';
require_once __DIR__ . '/app/Support/ShippingMethodResolver.php';

$db = Db::mysql($cfg);
$mapCfg = require __DIR__ . '/app/Config/shipping_map.php';
$resolver = new ShippingMethodResolver($mapCfg);

// Pobierz wszystkie unikalne kombinacje
$rows = $db->query("
    SELECT delivery_method, carrier_code, courier_code, COUNT(*) as orders_count
    FROM pak_orders
    WHERE delivery_method IS NOT NULL
    GROUP BY delivery_method, carrier_code, courier_code
    ORDER BY delivery_method
")->fetchAll(PDO::FETCH_ASSOC);

// Przepuść każdą przez resolver
$results = [];
foreach ($rows as $row) {
    $resolved = $resolver->resolve([
        'delivery_method' => (string)($row['delivery_method'] ?? ''),
        'carrier_code'    => (string)($row['carrier_code'] ?? ''),
        'courier_code'    => (string)($row['courier_code'] ?? ''),
    ]);
    $results[] = [
        'delivery_method' => $row['delivery_method'],
        'carrier_code'    => $row['carrier_code'],
        'courier_code'    => $row['courier_code'],
        'orders_count'    => (int)$row['orders_count'],
        'matched'         => $resolved['matched'],
        'menu_group'      => $resolved['menu_group'],
        'menu_label'      => $resolved['menu_label'],
        'shipment_type'   => $resolved['shipment_type'],
        'label_provider'  => $resolved['label_provider'],
        'matched_rule'    => $resolved['matched_rule'] ?? '—',
        'requires_size'   => $resolved['requires_size'],
    ];
}

// Statystyki
$total_orders = array_sum(array_column($results, 'orders_count'));
$unmatched = array_filter($results, fn($r) => !$r['matched']);
$unmatched_orders = array_sum(array_column($unmatched, 'orders_count'));

// Kolory per provider
$providerColors = [
    'dpd_api'        => '#d62728',
    'allegro_api'    => '#ff7f0e',
    'inpost_shipx'   => '#f5c518',
    'gls_api'        => '#2ca02c',
    'baselinker_api' => '#1f77b4',
    'dhl_api'        => '#ffd700',
    'orlen_api'      => '#e377c2',
    'none'           => '#aaaaaa',
];

$groupColors = [
    'dpd'           => '#e8f0fe',
    'allegro_one'   => '#fff3e0',
    'inpost'        => '#fff9c4',
    'gls'           => '#e8f5e9',
    'erli'          => '#e3f2fd',
    'dhl'           => '#fce4ec',
    'orlen'         => '#f3e5f5',
    'odbior_osobisty' => '#f5f5f5',
    'inne'          => '#ffebee',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Resolver Debug — LED-ONE</title>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<style>
  :root {
    --bg: #0f1117;
    --surface: #1a1d27;
    --surface2: #22263a;
    --border: #2e3350;
    --text: #e8eaf6;
    --muted: #7986cb;
    --accent: #5c6bc0;
    --green: #66bb6a;
    --red: #ef5350;
    --yellow: #ffa726;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 13px;
    padding: 24px;
  }
  h1 {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -0.5px;
    margin-bottom: 4px;
  }
  .subtitle { color: var(--muted); margin-bottom: 20px; font-size: 12px; }

  .stats {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
    flex-wrap: wrap;
  }
  .stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px 20px;
    min-width: 160px;
  }
  .stat-value { font-size: 28px; font-weight: 700; color: #fff; }
  .stat-label { font-size: 11px; color: var(--muted); margin-top: 2px; }
  .stat.warn .stat-value { color: var(--red); }
  .stat.ok .stat-value { color: var(--green); }

  .filters {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
    align-items: center;
  }
  .filters label { color: var(--muted); font-size: 11px; }
  .filters select, .filters input {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 6px 10px;
    border-radius: 6px;
    font-family: inherit;
    font-size: 12px;
  }
  .btn-reset {
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--muted);
    padding: 6px 14px;
    border-radius: 6px;
    cursor: pointer;
    font-family: inherit;
    font-size: 12px;
    transition: all .15s;
  }
  .btn-reset:hover { border-color: var(--accent); color: #fff; }

  /* DataTables overrides */
  .dataTables_wrapper { color: var(--text); }
  .dataTables_wrapper .dataTables_length,
  .dataTables_wrapper .dataTables_filter,
  .dataTables_wrapper .dataTables_info,
  .dataTables_wrapper .dataTables_paginate { color: var(--muted); margin-bottom: 10px; }
  .dataTables_wrapper .dataTables_filter input {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 4px 8px;
    border-radius: 4px;
    font-family: inherit;
  }
  .dataTables_wrapper .dataTables_length select {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 2px 4px;
    font-family: inherit;
  }

  table.dataTable {
    background: var(--surface);
    border-collapse: collapse;
    width: 100% !important;
  }
  table.dataTable thead th {
    background: var(--surface2);
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    border-bottom: 2px solid var(--border);
    padding: 10px 12px;
    white-space: nowrap;
  }
  table.dataTable tbody td {
    border-bottom: 1px solid var(--border);
    padding: 8px 12px;
    vertical-align: middle;
  }
  table.dataTable tbody tr:hover td { background: var(--surface2); }
  table.dataTable tbody tr.odd td { background: rgba(255,255,255,.02); }

  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
  }
  .badge-group {
    background: rgba(92,107,192,.2);
    border: 1px solid rgba(92,107,192,.4);
    color: #9fa8da;
  }
  .badge-provider {
    font-size: 10px;
    padding: 2px 6px;
  }
  .badge-dpd      { background: rgba(214,39,40,.15);  border: 1px solid rgba(214,39,40,.3);  color: #ff6b6b; }
  .badge-allegro  { background: rgba(255,127,14,.15); border: 1px solid rgba(255,127,14,.3); color: #ffab40; }
  .badge-inpost   { background: rgba(245,197,24,.15); border: 1px solid rgba(245,197,24,.3); color: #ffd740; }
  .badge-gls      { background: rgba(44,160,44,.15);  border: 1px solid rgba(44,160,44,.3);  color: #69f0ae; }
  .badge-bl       { background: rgba(31,119,180,.15); border: 1px solid rgba(31,119,180,.3); color: #64b5f6; }
  .badge-dhl      { background: rgba(255,215,0,.15);  border: 1px solid rgba(255,215,0,.3);  color: #ffd740; }
  .badge-orlen    { background: rgba(227,119,194,.15);border: 1px solid rgba(227,119,194,.3);color: #f48fb1; }
  .badge-none     { background: rgba(150,150,150,.15);border: 1px solid rgba(150,150,150,.3);color: #bbb; }
  .badge-unmatched{ background: rgba(239,83,80,.2);   border: 1px solid rgba(239,83,80,.5);  color: #ef5350; font-weight:700; }

  .count { color: var(--muted); font-size: 12px; }
  .rule-name { color: #7986cb; font-size: 11px; }
  .delivery-method { max-width: 320px; }
  .size-required { color: var(--yellow); font-size: 11px; }

  .paginate_button {
    background: var(--surface2) !important;
    border: 1px solid var(--border) !important;
    color: var(--muted) !important;
    border-radius: 4px !important;
    margin: 2px !important;
    padding: 4px 10px !important;
    cursor: pointer;
  }
  .paginate_button.current {
    background: var(--accent) !important;
    color: #fff !important;
    border-color: var(--accent) !important;
  }
  .paginate_button:hover {
    background: var(--accent) !important;
    color: #fff !important;
  }
</style>
</head>
<body>

<h1>🚚 Resolver Debug</h1>
<div class="subtitle">LED-ONE — symulacja dopasowania metod dostawy do providerów etykiet · <?= date('Y-m-d H:i:s') ?></div>

<div class="stats">
  <div class="stat">
    <div class="stat-value"><?= count($results) ?></div>
    <div class="stat-label">unikalnych kombinacji</div>
  </div>
  <div class="stat">
    <div class="stat-value"><?= number_format($total_orders) ?></div>
    <div class="stat-label">zamówień łącznie</div>
  </div>
  <?php if (count($unmatched) > 0): ?>
  <div class="stat warn">
    <div class="stat-value"><?= count($unmatched) ?></div>
    <div class="stat-label">niezamatchowanych kombinacji (<?= $unmatched_orders ?> zamówień)</div>
  </div>
  <?php else: ?>
  <div class="stat ok">
    <div class="stat-value">0</div>
    <div class="stat-label">niezamatchowanych — wszystko OK</div>
  </div>
  <?php endif; ?>
</div>

<div class="filters">
  <label>Filtr grupy:</label>
  <select id="filterGroup">
    <option value="">— wszystkie —</option>
    <?php
    $groups = array_unique(array_column($results, 'menu_group'));
    sort($groups);
    foreach ($groups as $g): ?>
      <option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option>
    <?php endforeach; ?>
  </select>

  <label>Provider:</label>
  <select id="filterProvider">
    <option value="">— wszystkie —</option>
    <?php
    $providers = array_unique(array_column($results, 'label_provider'));
    sort($providers);
    foreach ($providers as $p): ?>
      <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
    <?php endforeach; ?>
  </select>

  <label>
    <input type="checkbox" id="filterUnmatched"> tylko niezamatchowane
  </label>

  <button class="btn-reset" onclick="resetFilters()">reset filtrów</button>
</div>

<table id="resolverTable" class="dataTable" style="width:100%">
  <thead>
    <tr>
      <th>delivery_method</th>
      <th>courier_code</th>
      <th>Zamówień</th>
      <th>Przycisk (menu_group)</th>
      <th>shipment_type</th>
      <th>label_provider</th>
      <th>matched_rule</th>
      <th>rozmiar?</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($results as $r):
    $providerClass = match(true) {
        str_contains($r['label_provider'], 'dpd')        => 'badge-dpd',
        str_contains($r['label_provider'], 'allegro')    => 'badge-allegro',
        str_contains($r['label_provider'], 'inpost')     => 'badge-inpost',
        str_contains($r['label_provider'], 'gls')        => 'badge-gls',
        str_contains($r['label_provider'], 'baselinker') => 'badge-bl',
        str_contains($r['label_provider'], 'dhl')        => 'badge-dhl',
        str_contains($r['label_provider'], 'orlen')      => 'badge-orlen',
        $r['label_provider'] === 'none'                  => 'badge-none',
        !$r['matched']                                   => 'badge-unmatched',
        default                                          => 'badge-none',
    };
  ?>
  <tr data-group="<?= htmlspecialchars($r['menu_group']) ?>"
      data-provider="<?= htmlspecialchars($r['label_provider']) ?>"
      data-matched="<?= $r['matched'] ? '1' : '0' ?>">
    <td class="delivery-method"><?= htmlspecialchars($r['delivery_method']) ?></td>
    <td class="rule-name"><?= htmlspecialchars((string)$r['courier_code']) ?></td>
    <td class="count"><?= $r['orders_count'] ?></td>
    <td>
      <?php if (!$r['matched']): ?>
        <span class="badge badge-unmatched">⚠ INNE (fallback)</span>
      <?php else: ?>
        <span class="badge badge-group"><?= htmlspecialchars($r['menu_label']) ?></span>
      <?php endif; ?>
    </td>
    <td class="rule-name"><?= htmlspecialchars($r['shipment_type']) ?></td>
    <td><span class="badge badge-provider <?= $providerClass ?>"><?= htmlspecialchars($r['label_provider']) ?></span></td>
    <td class="rule-name"><?= htmlspecialchars($r['matched_rule']) ?></td>
    <td><?= $r['requires_size'] ? '<span class="size-required">📦 tak</span>' : '' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
var table;
$(document).ready(function() {
  table = $('#resolverTable').DataTable({
    pageLength: 50,
    order: [[2, 'desc']],
    columnDefs: [
      { orderable: false, targets: [7] }
    ],
    language: {
      search: 'Szukaj:',
      lengthMenu: 'Pokaż _MENU_ wierszy',
      info: 'Wyniki _START_–_END_ z _TOTAL_',
      paginate: { next: '›', previous: '‹' }
    }
  });

  $('#filterGroup').on('change', applyFilters);
  $('#filterProvider').on('change', applyFilters);
  $('#filterUnmatched').on('change', applyFilters);
});

function applyFilters() {
  var group    = $('#filterGroup').val();
  var provider = $('#filterProvider').val();
  var unmatched = $('#filterUnmatched').is(':checked');

  $.fn.dataTable.ext.search = [];
  $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
    var row = table.row(dataIndex).node();
    var rGroup    = $(row).data('group');
    var rProvider = $(row).data('provider');
    var rMatched  = $(row).data('matched');

    if (group    && rGroup    !== group)    return false;
    if (provider && rProvider !== provider) return false;
    if (unmatched && rMatched !== 0 && rMatched !== '0') return false;
    return true;
  });
  table.draw();
}

function resetFilters() {
  $('#filterGroup').val('');
  $('#filterProvider').val('');
  $('#filterUnmatched').prop('checked', false);
  $.fn.dataTable.ext.search = [];
  table.draw();
}
</script>
</body>
</html>
