<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/api.php';

if (!isset($_SESSION['token']) || $_SESSION['token'] === '') {
    header('Location: /GUI/index.php?redirect=' . urlencode('/panel/'));
    exit;
}

$me = panel_api_call('GET', '/auth/me');
$auth = $me['data']['auth'] ?? [];
$user = $auth['user'] ?? ($_SESSION['user'] ?? []);
$station = $auth['station'] ?? ($_SESSION['station'] ?? []);

$_SESSION['user'] = $user;
$_SESSION['station'] = $station;

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$carrier = trim((string)($_GET['carrier'] ?? ''));

$rows = [];
$apiResponse = null;
$error = '';

if ($q !== '' || $status !== '' || $carrier !== '') {
    $apiResponse = panel_api_call('GET', '/panel/orders', [
        'q' => $q,
        'status' => $status,
        'carrier' => $carrier,
        'limit' => 300,
    ]);

    if (($apiResponse['ok'] ?? false) && isset($apiResponse['data']['orders']) && is_array($apiResponse['data']['orders'])) {
        $rows = $apiResponse['data']['orders'];
    } else {
        $error = 'Brak danych z endpointu /panel/orders albo endpoint zwrócił błąd.';
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Panel zamówień</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.1/css/fixedHeader.dataTables.min.css">

<style>
*{box-sizing:border-box}
body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937}
.top{background:#111827;color:#fff;padding:14px 18px}
.top a{color:#fff;text-decoration:none}
.wrap{max-width:1500px;margin:0 auto;padding:18px}
.card{background:#fff;border:1px solid #d8dee4;border-radius:10px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end}
label{display:block;font-size:12px;color:#6b7280;margin-bottom:6px}
input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}
button,.btn{padding:10px 14px;border:0;border-radius:8px;background:#111827;color:#fff;cursor:pointer;text-decoration:none;display:inline-block}
.btn.gray{background:#475569}
.btn.green{background:#15803d}
.btn.red{background:#b91c1c}
.small{font-size:12px;color:#6b7280}
.err{padding:10px 12px;background:#fee2e2;color:#991b1b;border-radius:8px}
.note{padding:10px 12px;background:#eff6ff;color:#1d4ed8;border-radius:8px}
.badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#e5e7eb;font-size:12px}
.badge.green{background:#dcfce7;color:#166534}
.badge.red{background:#fee2e2;color:#991b1b}
.badge.yellow{background:#fef3c7;color:#92400e}
.actions-inline{display:flex;gap:6px;flex-wrap:wrap}
.dt-wrap table.dataTable thead th,
.dt-wrap table.dataTable tfoot th{font-size:12px}
tfoot input, tfoot select{padding:6px 8px;font-size:12px;border-radius:6px}
pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto}
.modal-backdrop{
  display:none;position:fixed;left:0;top:0;right:0;bottom:0;
  background:rgba(15,23,42,.65);z-index:9998;padding:20px;overflow:auto
}
.modal{
  max-width:1200px;margin:20px auto;background:#fff;border-radius:12px;
  border:1px solid #cbd5e1;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden
}
.modal-head{padding:14px 16px;background:#111827;color:#fff;display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:16px}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
table.slim{width:100%;border-collapse:collapse}
table.slim th,table.slim td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
table.slim th{width:180px;font-size:12px;color:#6b7280;background:#f8fafc}
section.box{border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:16px}
.event{border-bottom:1px solid #e5e7eb;padding:10px 0}
.hidden{display:none}
.modal-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
@media (max-width: 1100px){
  .grid{grid-template-columns:1fr}
  .modal-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="top">
    <strong>Panel zamówień</strong>
    &nbsp; | &nbsp;
    Operator: <?php echo panel_h($user['display_name'] ?? '—'); ?>
    &nbsp; | &nbsp;
    Stanowisko: <?php echo panel_h($station['station_code'] ?? '—'); ?>
    &nbsp; | &nbsp;
    <a href="/GUI/workflow.php">Powrót do GUI</a>
</div>

<div class="wrap">
    <div class="card">
        <form method="get">
            <div class="grid">
                <div>
                    <label>Szukaj: nr zamówienia / telefon / email / klient / miasto</label>
                    <input type="text" name="q" value="<?php echo panel_h($q); ?>" placeholder="np. 501, Kraków, LED, 12345">
                </div>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <option value="">-- wszystkie --</option>
                        <option value="10" <?php echo $status === '10' ? 'selected' : ''; ?>>10 / ready</option>
                        <option value="40" <?php echo $status === '40' ? 'selected' : ''; ?>>40</option>
                        <option value="50" <?php echo $status === '50' ? 'selected' : ''; ?>>50</option>
                        <option value="60" <?php echo $status === '60' ? 'selected' : ''; ?>>60</option>
                    </select>
                </div>
                <div>
                    <label>Carrier</label>
                    <select name="carrier">
                        <option value="">-- wszyscy --</option>
                        <option value="inpost" <?php echo $carrier === 'inpost' ? 'selected' : ''; ?>>InPost</option>
                        <option value="dpd" <?php echo $carrier === 'dpd' ? 'selected' : ''; ?>>DPD</option>
                        <option value="allegro_one" <?php echo $carrier === 'allegro_one' ? 'selected' : ''; ?>>Allegro One</option>
                        <option value="orlen" <?php echo $carrier === 'orlen' ? 'selected' : ''; ?>>ORLEN</option>
                        <option value="dhl" <?php echo $carrier === 'dhl' ? 'selected' : ''; ?>>DHL</option>
                        <option value="gls" <?php echo $carrier === 'gls' ? 'selected' : ''; ?>>GLS</option>
                    </select>
                </div>
                <div>
                    <button type="submit">Szukaj</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($q === '' && $status === '' && $carrier === ''): ?>
        <div class="card">
            <div class="note">Wpisz fragment numeru zamówienia, telefonu, emaila, nazwiska albo miasta. Potem możesz dodatkowo filtrować po kolumnach w tabeli.</div>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="card"><div class="err"><?php echo panel_h($error); ?></div></div>
    <?php endif; ?>

    <div class="card dt-wrap">
        <h3 style="margin-top:0">Wyniki</h3>
        <table id="ordersTable" class="display stripe compact" style="width:100%">
            <thead>
                <tr>
                    <th>Zamówienie</th>
                    <th>Klient</th>
                    <th>Telefon</th>
                    <th>Miasto</th>
                    <th>Carrier</th>
                    <th>Status</th>
                    <th>Package mode</th>
                    <th>Data</th>
                    <th>Akcje</th>
                </tr>
            </thead>
            <tfoot>
                <tr>
                    <th>Zamówienie</th>
                    <th>Klient</th>
                    <th>Telefon</th>
                    <th>Miasto</th>
                    <th>Carrier</th>
                    <th>Status</th>
                    <th>Package mode</th>
                    <th>Data</th>
                    <th>Akcje</th>
                </tr>
            </tfoot>
            <tbody>
            <?php if (!empty($rows)): ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $statusVal = (string)($row['status'] ?? '');
                        $pm = (string)($row['package_mode'] ?? '');
                        $statusClass = 'badge';
                        if ($statusVal === '10') $statusClass .= ' yellow';
                        if ($statusVal === '50') $statusClass .= ' green';
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo panel_h($row['order_code'] ?? ''); ?></strong><br>
                            <span class="small"><?php echo panel_h($row['external_order_no'] ?? ''); ?></span>
                        </td>
                        <td><?php echo panel_h($row['buyer_name'] ?? ''); ?></td>
                        <td><?php echo panel_h($row['phone'] ?? ''); ?></td>
                        <td><?php echo panel_h($row['city'] ?? ''); ?></td>
                        <td><?php echo panel_h($row['carrier_key'] ?? ''); ?><br><span class="small"><?php echo panel_h($row['delivery_method'] ?? ''); ?></span></td>
                        <td><span class="<?php echo panel_h($statusClass); ?>"><?php echo panel_h($statusVal); ?></span></td>
                        <td><span class="badge"><?php echo panel_h($pm); ?></span></td>
                        <td><?php echo panel_h($row['imported_at'] ?? ''); ?></td>
                        <td>
                            <div class="actions-inline">
                                <button type="button" class="btn gray js-open-order" data-order-code="<?php echo panel_h($row['order_code'] ?? ''); ?>">Szczegóły</button>
                                <a class="btn" href="order.php?order_code=<?php echo urlencode((string)($row['order_code'] ?? '')); ?>" target="_blank" rel="noopener">Nowa karta</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="small">Brak wyników.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (isset($_GET['debug']) && $_GET['debug'] === '1' && $apiResponse !== null): ?>
        <div class="card">
            <h3 style="margin-top:0">Debug API</h3>
            <pre><?php echo panel_h(print_r($apiResponse, true)); ?></pre>
        </div>
    <?php endif; ?>
</div>

<div id="orderModalBackdrop" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head">
            <div>
                <strong id="modalTitle">Szczegóły zamówienia</strong>
            </div>
            <div>
                <button type="button" class="btn red" id="modalCloseBtn">Zamknij</button>
            </div>
        </div>
        <div class="modal-body">
            <div id="modalLoading" class="note hidden">Ładowanie danych...</div>
            <div id="modalError" class="err hidden"></div>

            <div id="modalContent" class="hidden">
                <div class="modal-grid">
                    <div>
                        <section class="box">
                            <h3 style="margin-top:0">Dane klienta i dostawy</h3>
                            <table class="slim">
                                <tr><th>Zamówienie</th><td id="m_order_code"></td></tr>
                                <tr><th>Subiekt doc</th><td id="m_subiekt_doc_no"></td></tr>
                                <tr><th>Klient</th><td><input type="text" id="e_delivery_fullname"></td></tr>
                                <tr><th>Telefon</th><td><input type="text" id="e_phone"></td></tr>
                                <tr><th>Email</th><td><input type="text" id="e_email"></td></tr>
                                <tr><th>Adres</th><td><input type="text" id="e_delivery_address"></td></tr>
                                <tr><th>Miasto</th><td><input type="text" id="e_delivery_city"></td></tr>
                                <tr><th>Kod pocztowy</th><td><input type="text" id="e_delivery_postcode"></td></tr>
                                <tr><th>Metoda dostawy</th><td id="m_delivery_method"></td></tr>
                                <tr><th>Carrier</th><td id="m_carrier_key"></td></tr>
                                <tr><th>Status</th><td id="m_status"></td></tr>
                                <tr><th>Package mode</th><td id="m_package_mode"></td></tr>
                                <tr><th>COD</th><td id="m_cod_amount"></td></tr>
                                <tr><th>Zapłacono</th><td id="m_payment_done"></td></tr>
                                <tr><th>Płatność</th><td id="m_payment_method"></td></tr>
                                <tr><th>Koszt dostawy</th><td id="m_delivery_price"></td></tr>
                                <tr><th>Nr nadania</th><td id="m_nr_nadania"></td></tr>
                                <tr><th>Import</th><td id="m_imported_at"></td></tr>
                            </table>

                            <div class="modal-actions">
                                <button type="button" class="btn green" id="btnSaveOrder" disabled>Zapisz zmiany</button>
                                <button type="button" class="btn gray" id="btnReloadOrder">Odśwież dane</button>
                            </div>
                            <div class="small" style="margin-top:8px">Zapis backendem dodamy w następnym kroku: endpoint update + walidacja + audit log.</div>
                        </section>

                        <section class="box">
                            <h3 style="margin-top:0">Picking / batch</h3>
                            <div id="m_batch_box"></div>
                        </section>
                    </div>

                    <div>
                        <section class="box">
                            <h3 style="margin-top:0">Pozycje operacyjne</h3>
                            <div id="m_items_operational"></div>
                        </section>

                        <section class="box">
                            <h3 style="margin-top:0">Pozycje usługowe / finansowe</h3>
                            <div id="m_items_service"></div>
                        </section>

                        <section class="box">
                            <h3 style="margin-top:0">Etykieta / diagnostyka wysyłki</h3>
                            <div id="m_shipping_diag"></div>
                        </section>

                        <section class="box">
                            <h3 style="margin-top:0">Historia pickingu</h3>
                            <div id="m_events"></div>
                        </section>

                        <section class="box">
                            <h3 style="margin-top:0">Ręczne zmiany danych</h3>
                            <div id="m_admin_changes"></div>
                        </section>
                    </div>
                </div>

                <section class="box hidden" id="modalDebugBox">
                    <h3 style="margin-top:0">Debug API</h3>
                    <pre id="m_debug"></pre>
                </section>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.1/js/dataTables.fixedHeader.min.js"></script>
<script>
(function(){
  const tableEl = $('#ordersTable');
  if (tableEl.length) {
    $('#ordersTable tfoot th').each(function (idx) {
      if (idx === 8) {
        $(this).html('');
      } else {
        const title = $(this).text();
        $(this).html('<input type="text" placeholder="Filtruj ' + title + '" />');
      }
    });

    const table = tableEl.DataTable({
      pageLength: 25,
      order: [[7, 'desc']],
      fixedHeader: true,
      language: {
        search: 'Szukaj w tabeli:',
        lengthMenu: 'Pokaż _MENU_',
        info: 'Pozycje _START_–_END_ z _TOTAL_',
        infoEmpty: 'Brak pozycji',
        zeroRecords: 'Brak pasujących wyników',
        paginate: {
          first: 'Pierwsza',
          last: 'Ostatnia',
          next: 'Następna',
          previous: 'Poprzednia'
        }
      }
    });

    table.columns().every(function () {
      const that = this;
      $('input', this.footer()).on('keyup change clear', function () {
        if (that.search() !== this.value) {
          that.search(this.value).draw();
        }
      });
    });
  }

  const backdrop = document.getElementById('orderModalBackdrop');
  const modalCloseBtn = document.getElementById('modalCloseBtn');
  const modalLoading = document.getElementById('modalLoading');
  const modalError = document.getElementById('modalError');
  const modalContent = document.getElementById('modalContent');
  const modalTitle = document.getElementById('modalTitle');
  const btnReloadOrder = document.getElementById('btnReloadOrder');

  let currentOrderCode = '';

  function show(el){ el.classList.remove('hidden'); }
  function hide(el){ el.classList.add('hidden'); }

  function openModal() {
    backdrop.style.display = 'block';
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    backdrop.style.display = 'none';
    document.body.style.overflow = '';
  }

  function esc(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function prettyJson(v) {
    if (!v) return '';
    try {
      const parsed = JSON.parse(v);
      return JSON.stringify(parsed, null, 2);
    } catch (e) {
      return String(v);
    }
  }

  function isServiceItem(item) {
    const hay = ((item.name || '') + ' ' + (item.subiekt_desc || '')).toLowerCase();
    return hay.includes('usługa transportowa') ||
           hay.includes('usluga transportowa') ||
           hay.includes('transport') ||
           hay.includes('shipping') ||
           hay.includes('dostawa');
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value ?? '';
  }

  function setInput(id, value) {
    const el = document.getElementById(id);
    if (el) el.value = value ?? '';
  }

  function renderBatch(batch) {
    const box = document.getElementById('m_batch_box');
    if (!box) return;

    if (!batch || typeof batch !== 'object') {
      box.innerHTML = '<div class="small">Brak informacji o batchu.</div>';
      return;
    }

    box.innerHTML = `
      <table class="slim">
        <tr><th>Batch ID</th><td>${esc(batch.batch_id)}</td></tr>
        <tr><th>Batch code</th><td>${esc(batch.batch_code)}</td></tr>
        <tr><th>Carrier</th><td>${esc(batch.carrier_key)}</td></tr>
        <tr><th>Package mode</th><td>${esc(batch.package_mode)}</td></tr>
        <tr><th>Batch status</th><td>${esc(batch.batch_status)}</td></tr>
        <tr><th>Order status</th><td>${esc(batch.order_status)}</td></tr>
        <tr><th>Selection mode</th><td>${esc(batch.selection_mode)}</td></tr>
        <tr><th>Assigned at</th><td>${esc(batch.assigned_at)}</td></tr>
        <tr><th>Removed at</th><td>${esc(batch.removed_at)}</td></tr>
        <tr><th>Drop reason</th><td>${esc(batch.drop_reason)}</td></tr>
      </table>
    `;
  }

  function renderItems(items) {
    const op = document.getElementById('m_items_operational');
    const srv = document.getElementById('m_items_service');

    if (!Array.isArray(items) || items.length === 0) {
      op.innerHTML = '<div class="small">Brak pozycji.</div>';
      srv.innerHTML = '<div class="small">Brak pozycji usługowych.</div>';
      return;
    }

    const operational = [];
    const service = [];

    items.forEach(item => {
      if (isServiceItem(item)) service.push(item);
      else operational.push(item);
    });

    const renderTable = (rows, serviceMode) => {
      if (!rows.length) {
        return '<div class="small">Brak pozycji.</div>';
      }

      let html = '<table class="slim"><thead><tr>';
      if (serviceMode) {
        html += '<th>item_id</th><th>subiekt_tow_id</th><th>Nazwa</th><th>Opis</th><th>Ilość</th>';
      } else {
        html += '<th>item_id</th><th>subiekt_tow_id</th><th>Symbol</th><th>Nazwa</th><th>Opis</th><th>Ilość</th>';
      }
      html += '</tr></thead><tbody>';

      rows.forEach(item => {
        html += '<tr>';
        html += `<td>${esc(item.item_id)}</td>`;
        html += `<td>${esc(item.subiekt_tow_id)}</td>`;
        if (!serviceMode) html += `<td>${esc(item.subiekt_symbol)}</td>`;
        html += `<td>${esc(item.name)}</td>`;
        html += `<td>${esc(item.subiekt_desc)}</td>`;
        html += `<td>${esc(item.quantity)}</td>`;
        html += '</tr>';
      });

      html += '</tbody></table>';
      return html;
    };

    op.innerHTML = renderTable(operational, false);
    srv.innerHTML = renderTable(service, true);
  }


  function renderAdminChanges(changes) {
    const existing = document.getElementById('m_admin_changes');
    if (!existing) return;

    if (!Array.isArray(changes) || changes.length === 0) {
      existing.innerHTML = '<div class="small">Brak ręcznych zmian.</div>';
      return;
    }

    let html = '';
    changes.forEach(ch => {
      const who = [ch.display_name || '', ch.login || ''].filter(Boolean).join(' / ');
      html += `
        <div class="event">
          <div><strong>${esc(ch.created_at)}</strong> — ${esc(ch.field_name)}</div>
          <div class="small">Zmienił: ${esc(who || ch.changed_by_user_id || '')}</div>
          <div><strong>Było:</strong> ${esc(ch.old_value || '')}</div>
          <div><strong>Jest:</strong> ${esc(ch.new_value || '')}</div>
        </div>
      `;
    });

    existing.innerHTML = html;
  }

  function renderEvents(events) {
    const box = document.getElementById('m_events');
    if (!box) return;

    if (!Array.isArray(events) || events.length === 0) {
      box.innerHTML = '<div class="small">Brak historii pickingu.</div>';
      return;
    }

    let html = '';
    events.forEach(ev => {
      html += `
        <div class="event">
          <div><strong>${esc(ev.created_at)}</strong> — ${esc(ev.event_type)}</div>
          <div>${esc(ev.event_message)}</div>
          ${ev.payload_json ? `<pre>${esc(prettyJson(ev.payload_json))}</pre>` : ''}
        </div>
      `;
    });

    box.innerHTML = html;
  }

  function renderShippingDiag(shipping) {
    const box = document.getElementById('m_shipping_diag');
    if (!box) return;

    if (!shipping || typeof shipping !== 'object') {
      box.innerHTML = '<div class="small">Brak danych diagnostycznych etykiety.</div>';
      return;
    }

    const resolved = shipping.resolved || {};
    const session = shipping.packing_session || null;
    const pkg = shipping.package || null;
    const label = shipping.label || null;
    const latestError = shipping.latest_error || null;
    const events = Array.isArray(shipping.events) ? shipping.events : [];

    let html = '';

    html += '<table class="slim">';
    html += `<tr><th>Label provider</th><td>${esc(resolved.label_provider || '') || '—'}</td></tr>`;
    html += `<tr><th>Shipment type</th><td>${esc(resolved.shipment_type || '') || '—'}</td></tr>`;
    html += `<tr><th>Service code</th><td>${esc(resolved.service_code || '') || '—'}</td></tr>`;
    html += `<tr><th>Requires size</th><td>${resolved.requires_size ? 'tak' : 'nie'}</td></tr>`;
    html += `<tr><th>Resolved size</th><td>${esc(resolved.package_size || '') || '—'}</td></tr>`;
    html += '</table>';

    html += '<div style="height:10px"></div>';

    if (session) {
      html += '<div><strong>Ostatnia sesja pakowania</strong></div>';
      html += '<table class="slim">';
      html += `<tr><th>Session ID</th><td>${esc(session.id)}</td></tr>`;
      html += `<tr><th>Status</th><td>${esc(session.status || '') || '—'}</td></tr>`;
      html += `<tr><th>User ID</th><td>${esc(session.user_id)}</td></tr>`;
      html += `<tr><th>Station ID</th><td>${esc(session.station_id)}</td></tr>`;
      html += `<tr><th>Batch ID</th><td>${esc(session.picking_batch_id)}</td></tr>`;
      html += `<tr><th>Started</th><td>${esc(session.started_at || '') || '—'}</td></tr>`;
      html += `<tr><th>Last seen</th><td>${esc(session.last_seen_at || '') || '—'}</td></tr>`;
      html += '</table>';
      html += '<div style="height:10px"></div>';
    } else {
      html += '<div class="small">Brak sesji pakowania dla tego zamówienia.</div><div style="height:10px"></div>';
    }

    if (pkg) {
      html += '<div><strong>Package</strong></div>';
      html += '<table class="slim">';
      html += `<tr><th>Package ID</th><td>${esc(pkg.id)}</td></tr>`;
      html += `<tr><th>Status</th><td>${esc(pkg.status || '') || '—'}</td></tr>`;
      html += `<tr><th>Service code</th><td>${esc(pkg.service_code || '') || '—'}</td></tr>`;
      html += `<tr><th>Package size code</th><td>${esc(pkg.package_size_code || '') || '—'}</td></tr>`;
      html += `<tr><th>Tracking</th><td>${esc(pkg.tracking_number || '') || '—'}</td></tr>`;
      html += `<tr><th>External shipment ID</th><td>${esc(pkg.external_shipment_id || '') || '—'}</td></tr>`;
      html += '</table>';
      html += '<div style="height:10px"></div>';
    } else {
      html += '<div class="small">Brak package dla sesji.</div><div style="height:10px"></div>';
    }

    if (label) {
      html += '<div><strong>Ostatnia etykieta</strong></div>';
      html += '<table class="slim">';
      html += `<tr><th>Label ID</th><td>${esc(label.id)}</td></tr>`;
      html += `<tr><th>Status</th><td>${esc(label.label_status || '') || '—'}</td></tr>`;
      html += `<tr><th>Format</th><td>${esc(label.label_format || '') || '—'}</td></tr>`;
      html += `<tr><th>File token</th><td>${esc(label.file_token || '') || '—'}</td></tr>`;
      html += `<tr><th>Created</th><td>${esc(label.created_at || '') || '—'}</td></tr>`;
      html += '</table>';
      if (label.raw_response_json) {
        html += `<div class="small" style="margin:8px 0 6px 0">raw_response_json</div><pre>${esc(prettyJson(label.raw_response_json))}</pre>`;
      }
      html += '<div style="height:10px"></div>';
    }

    if (latestError) {
      html += '<div><strong>Ostatni błąd generowania etykiety</strong></div>';
      html += '<table class="slim">';
      html += `<tr><th>Data</th><td>${esc(latestError.created_at || '') || '—'}</td></tr>`;
      html += `<tr><th>Typ</th><td>${esc(latestError.event_type || '') || '—'}</td></tr>`;
      html += `<tr><th>User ID</th><td>${esc(latestError.created_by_user_id || '') || '—'}</td></tr>`;
      html += `<tr><th>Komunikat</th><td>${esc(latestError.event_message || '') || '—'}</td></tr>`;
      html += '</table>';
      if (latestError.payload_json) {
        html += `<div class="small" style="margin:8px 0 6px 0">Payload błędu</div><pre>${esc(prettyJson(latestError.payload_json))}</pre>`;
      }
      html += '<div style="height:10px"></div>';
    } else {
      html += '<div class="small">Brak zdarzenia label_generation_failed.</div><div style="height:10px"></div>';
    }

    if (events.length) {
      html += '<div><strong>Historia zdarzeń etykiety</strong></div>';
      events.forEach(ev => {
        html += `
          <div class="event">
            <div><strong>${esc(ev.created_at || '')}</strong> — ${esc(ev.event_type || '')}</div>
            <div>${esc(ev.event_message || '')}</div>
            ${ev.payload_json ? `<pre>${esc(prettyJson(ev.payload_json))}</pre>` : ''}
          </div>
        `;
      });
    }

    box.innerHTML = html;
  }

  function fillOrder(order, rawResponse) {
    document.getElementById('btnSaveOrder').disabled = false;
    const header = order.header || {};
    modalTitle.textContent = 'Szczegóły zamówienia ' + (header.order_code || currentOrderCode);

    setText('m_order_code', header.order_code || '');
    setText('m_subiekt_doc_no', header.subiekt_doc_no || '');
    setInput('e_delivery_fullname', header.delivery_fullname || '');
    setInput('e_phone', header.phone || '');
    setInput('e_email', header.email || '');
    setInput('e_delivery_address', header.delivery_address || '');
    setInput('e_delivery_city', header.delivery_city || '');
    setInput('e_delivery_postcode', header.delivery_postcode || '');
    setText('m_delivery_method', header.delivery_method || '');
    setText('m_carrier_key', order.carrier_key || '');
    setText('m_status', header.status || '');
    setText('m_package_mode', order.package_mode || '');
    setText('m_cod_amount', ((header.cod_amount || '') + ' ' + (header.cod_currency || '')).trim());
    setText('m_payment_done', header.payment_done || '');
    setText('m_payment_method', header.payment_method || '');
    setText('m_delivery_price', header.delivery_price || '');
    setText('m_nr_nadania', header.nr_nadania || '');
    setText('m_imported_at', header.imported_at || '');

    renderBatch(order.batch || null);
    renderItems(order.items || []);
    renderShippingDiag(order.shipping || null);
    renderEvents(order.picking_events || []);
    renderAdminChanges(order.admin_changes || []);

    const debugBox = document.getElementById('modalDebugBox');
    const debugPre = document.getElementById('m_debug');
    const hasDebug = new URLSearchParams(window.location.search).get('debug') === '1';
    if (hasDebug) {
      debugPre.textContent = JSON.stringify(rawResponse, null, 2);
      show(debugBox);
    } else {
      hide(debugBox);
    }
  }

  async function loadOrder(orderCode) {
    currentOrderCode = orderCode;
    openModal();
    hide(modalError);
    hide(modalContent);
    show(modalLoading);

    try {
      const res = await fetch('order_details.php?order_code=' + encodeURIComponent(orderCode), {
        headers: {
          'Accept': 'application/json'
        },
        credentials: 'same-origin'
      });

      const data = await res.json();

      if (!data || !data.ok || !data.data || !data.data.order) {
        throw new Error((data && data.error) ? data.error : 'Nie udało się pobrać szczegółów zamówienia.');
      }

      fillOrder(data.data.order, data);
      hide(modalLoading);
      show(modalContent);
    } catch (err) {
      hide(modalLoading);
      modalError.textContent = err.message || 'Błąd pobierania danych.';
      show(modalError);
    }
  }

  document.querySelectorAll('.js-open-order').forEach(btn => {
    btn.addEventListener('click', function(){
      const orderCode = this.getAttribute('data-order-code') || '';
      if (!orderCode) return;
      loadOrder(orderCode);
    });
  });

  modalCloseBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', function(e){
    if (e.target === backdrop) closeModal();
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && backdrop.style.display === 'block') {
      closeModal();
    }
  });

  btnReloadOrder.addEventListener('click', function(){
    if (currentOrderCode) loadOrder(currentOrderCode);
  });

  document.getElementById('btnSaveOrder').addEventListener('click', async function(){
    if (!currentOrderCode) {
      alert('Brak order_code');
      return;
    }

    const payload = {
      delivery_fullname: document.getElementById('e_delivery_fullname').value.trim(),
      phone: document.getElementById('e_phone').value.trim(),
      email: document.getElementById('e_email').value.trim(),
      delivery_address: document.getElementById('e_delivery_address').value.trim(),
      delivery_city: document.getElementById('e_delivery_city').value.trim(),
      delivery_postcode: document.getElementById('e_delivery_postcode').value.trim()
    };

    this.disabled = true;

    try {
      const res = await fetch('order_update.php?order_code=' + encodeURIComponent(currentOrderCode), {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
      });

      const data = await res.json();

      if (!data || !data.ok || !data.data || !data.data.order) {
        throw new Error((data && data.error) ? data.error : 'Nie udało się zapisać zmian.');
      }

      fillOrder(data.data.order, data);
      alert('Zmiany zapisane.');
    } catch (err) {
      alert(err.message || 'Błąd zapisu.');
    } finally {
      this.disabled = false;
    }
  });
})();
</script>
</body>
</html>
