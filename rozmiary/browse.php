<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/helpers.php';

$boot = sizes_bootstrap();
$db   = $boot['db'];

$flash        = sizes_get_flash();
$currentPage  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage      = 50;
$search       = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$filterStatus = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

[$items, $total] = sizes_fetch_all_items($db, $currentPage, $perPage, $search, $filterStatus);
$totalPages = max(1, (int)ceil($total / $perPage));

function build_url(array $overrides = []): string
{
    global $currentPage, $search, $filterStatus;

    $params = array_merge([
            'page'   => $currentPage,
            'q'      => $search,
            'status' => $filterStatus,
    ], $overrides);

    $filtered = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $filtered[$k] = $v;
        }
    }

    $qs = http_build_query($filtered);
    return 'browse.php' . ($qs ? '?' . $qs : '');
}

function sizes_badge_class(?string $status): string
{
    if ($status === 'small') return 'badge-small';
    if ($status === 'large') return 'badge-large';
    if ($status === 'other') return 'badge-other';
    return 'badge-none';
}

function sizes_badge_label(?string $status): string
{
    if ($status === 'small') return 'MAŁA';
    if ($status === 'large') return 'DUŻA';
    if ($status === 'other') return 'INNE';
    return 'brak';
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>Przeglądaj produkty – Rozmiary</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f8; color: #1f2937;
        }
        .wrap { max-width: 1300px; margin: 0 auto; }
        .panel {
            background: #fff; border: 1px solid #dbe2ea;
            border-radius: 10px; padding: 16px; margin-bottom: 16px;
        }
        h1 { margin: 0 0 4px; font-size: 26px; }
        .sub { color: #6b7280; font-size: 14px; margin-bottom: 16px; }
        a.back {
            display: inline-block; margin-bottom: 14px;
            color: #2563eb; text-decoration: none; font-size: 14px;
        }
        a.back:hover { text-decoration: underline; }

        .flash {
            padding: 12px 14px; border-radius: 10px;
            margin-bottom: 16px; border: 1px solid transparent;
        }
        .flash.success { background: #ecfdf5; border-color: #86efac; color: #166534; }
        .flash.error   { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
        .flash.info    { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }

        .toolbar {
            display: flex; flex-wrap: wrap; gap: 10px;
            align-items: center; margin-bottom: 16px;
        }
        .toolbar input[type=text] {
            height: 38px; border-radius: 8px; border: 1px solid #cbd5e1;
            padding: 0 12px; font-size: 14px; width: 260px;
        }
        .toolbar select, .toolbar button {
            height: 38px; border-radius: 8px; border: 1px solid #cbd5e1;
            padding: 0 12px; font-size: 14px; cursor: pointer; background: #fff;
        }
        .toolbar button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        a.clear-link { font-size: 13px; color: #6b7280; text-decoration: none; align-self: center; }
        a.clear-link:hover { text-decoration: underline; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead th {
            background: #f1f5f9; text-align: left;
            padding: 10px 12px; border-bottom: 2px solid #dbe2ea;
            white-space: nowrap;
        }
        tbody tr:hover { background: #f8fafc; }
        td {
            padding: 9px 12px; border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        td.symbol { white-space: nowrap; font-weight: 600; }
        td.name   { font-weight: 600; min-width: 180px; }
        td.desc   {
            color: #374151; max-width: 340px;
            white-space: pre-wrap; word-break: break-word;
        }
        td.size-cell { white-space: nowrap; }

        .badge {
            display: inline-block; padding: 4px 10px;
            border-radius: 999px; font-weight: bold; font-size: 13px;
        }
        .badge-small { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .badge-large { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .badge-other { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .badge-none  { background: #fefce8; color: #713f12; border: 1px solid #fde68a; }

        .btn-edit {
            background: #2563eb; color: #fff; border: none;
            padding: 6px 14px; border-radius: 7px; font-size: 13px;
            cursor: pointer; white-space: nowrap;
        }
        .btn-edit:hover { background: #1d4ed8; }

        .pagination {
            display: flex; flex-wrap: wrap; gap: 6px;
            align-items: center; margin-top: 16px;
        }
        .pagination a, .pagination span {
            display: inline-block; padding: 6px 12px;
            border: 1px solid #cbd5e1; border-radius: 7px;
            font-size: 13px; text-decoration: none; color: #1f2937; background: #fff;
        }
        .pagination a:hover { background: #f1f5f9; }
        .pagination span.active { background: #2563eb; color: #fff; border-color: #2563eb; }
        .pagination span.dots   { border: none; background: transparent; color: #6b7280; }
        .total-info { font-size: 13px; color: #6b7280; margin-top: 10px; }

        .modal-backdrop {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            background: #fff; border-radius: 14px; padding: 28px 28px 24px;
            width: 100%; max-width: 540px;
            box-shadow: 0 20px 60px rgba(0,0,0,.25); position: relative;
        }
        .modal h2 { margin: 0 0 18px; font-size: 20px; }
        .modal .close-btn {
            position: absolute; top: 16px; right: 18px;
            background: none; border: none; font-size: 22px;
            cursor: pointer; color: #6b7280; line-height: 1;
        }
        .modal .close-btn:hover { color: #111; }
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 600;
            color: #374151; margin-bottom: 5px;
        }
        .form-group input[type=text],
        .form-group textarea {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 8px;
            padding: 8px 12px; font-size: 14px; font-family: inherit; resize: vertical;
        }
        .form-group input[type=text]:focus,
        .form-group textarea:focus {
            outline: none; border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.15);
        }
        .size-buttons { display: flex; gap: 10px; margin-top: 6px; }
        .size-buttons button {
            flex: 1; height: 44px; border-radius: 9px;
            font-size: 15px; font-weight: bold; cursor: pointer;
            border: 2px solid transparent;
        }
        .size-buttons .btn-s { background: #16a34a; color: #fff; border-color: #15803d; }
        .size-buttons .btn-l { background: #dc2626; color: #fff; border-color: #b91c1c; }
        .size-buttons .btn-o { background: #6b7280; color: #fff; border-color: #4b5563; }
        .size-buttons button.selected { box-shadow: 0 0 0 4px rgba(0,0,0,.3); }
        .modal-footer {
            display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px;
        }
        .modal-footer button {
            height: 40px; padding: 0 20px; border-radius: 8px;
            font-size: 14px; cursor: pointer; border: 1px solid #cbd5e1; background: #fff;
        }
        .modal-footer .btn-save { background: #2563eb; color: #fff; border-color: #2563eb; }
        .modal-footer .btn-save:hover { background: #1d4ed8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="panel">
        <a class="back" href="/rozmiary/">← Wróć do listy roboczej</a>
        <h1>Przeglądaj produkty</h1>
        <div class="sub">Wszystkie produkty w lokalnej bazie danych.</div>

        <?php if ($flash !== null): ?>
            <div class="flash <?php echo sizes_h($flash['type']); ?>">
                <?php echo sizes_h($flash['message']); ?>
            </div>
        <?php endif; ?>

        <form method="get" action="browse.php" class="toolbar">
            <input
                    type="text"
                    name="q"
                    placeholder="Szukaj po symbolu lub nazwie…"
                    value="<?php echo sizes_h($search); ?>"
            >
            <select name="status">
                <option value="">Wszystkie rozmiary</option>
                <option value="small" <?php echo $filterStatus === 'small' ? 'selected' : ''; ?>>MAŁA</option>
                <option value="large" <?php echo $filterStatus === 'large' ? 'selected' : ''; ?>>DUŻA</option>
                <option value="other" <?php echo $filterStatus === 'other' ? 'selected' : ''; ?>>INNE</option>
                <option value="none"  <?php echo $filterStatus === 'none'  ? 'selected' : ''; ?>>Nieoznaczone</option>
            </select>
            <button type="submit" class="primary">Szukaj</button>
            <?php if ($search !== '' || $filterStatus !== ''): ?>
                <a href="browse.php" class="clear-link">✕ Wyczyść</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Symbol</th>
                    <th>Nazwa</th>
                    <th>Opis</th>
                    <th>Rozmiar</th>
                    <th>Akcja</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center;padding:30px;color:#6b7280;">
                            Brak wyników.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="symbol"><?php echo sizes_h($item['subiekt_symbol']); ?></td>
                            <td class="name"><?php echo sizes_h($item['name']); ?></td>
                            <td class="desc"><?php echo sizes_h($item['subiekt_desc'] !== null ? $item['subiekt_desc'] : '—'); ?></td>
                            <td class="size-cell">
                                <span class="badge <?php echo sizes_badge_class($item['size_status']); ?>">
                                    <?php echo sizes_badge_label($item['size_status']); ?>
                                </span>
                            </td>
                            <td>
                                <button
                                        class="btn-edit"
                                        data-id="<?php echo (int)$item['id']; ?>"
                                        data-name="<?php echo sizes_h($item['name']); ?>"
                                        data-desc="<?php echo sizes_h($item['subiekt_desc'] !== null ? $item['subiekt_desc'] : ''); ?>"
                                        data-status="<?php echo sizes_h($item['size_status'] !== null ? $item['size_status'] : ''); ?>"
                                >Edytuj</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="total-info">
            Wyniki: <?php echo number_format($total, 0, ',', ' '); ?> produktów
            (strona <?php echo $currentPage; ?> z <?php echo $totalPages; ?>)
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($currentPage > 1): ?>
                    <a href="<?php echo sizes_h(build_url(['page' => $currentPage - 1])); ?>">‹ Poprzednia</a>
                <?php endif; ?>

                <?php
                $window = 2;
                $pages  = [];
                for ($p = 1; $p <= $totalPages; $p++) {
                    if ($p === 1 || $p === $totalPages || abs($p - $currentPage) <= $window) {
                        $pages[] = $p;
                    }
                }
                $prev = null;
                foreach ($pages as $p):
                    if ($prev !== null && $p - $prev > 1): ?>
                        <span class="dots">…</span>
                    <?php endif;
                    if ($p === $currentPage): ?>
                        <span class="active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo sizes_h(build_url(['page' => $p])); ?>"><?php echo $p; ?></a>
                    <?php endif;
                    $prev = $p;
                endforeach; ?>

                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?php echo sizes_h(build_url(['page' => $currentPage + 1])); ?>">Następna ›</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL -->
<div class="modal-backdrop" id="modalBackdrop">
    <div class="modal" role="dialog" aria-modal="true">
        <button class="close-btn" onclick="closeModal()" title="Zamknij">×</button>
        <h2>Edytuj produkt</h2>

        <form method="post" action="handle.php?action=update_item" id="editForm">
            <input type="hidden" name="id" id="editId">
            <input type="hidden" name="_redirect_page"   value="<?php echo (int)$currentPage; ?>">
            <input type="hidden" name="_redirect_q"      value="<?php echo sizes_h($search); ?>">
            <input type="hidden" name="_redirect_status" value="<?php echo sizes_h($filterStatus); ?>">

            <div class="form-group">
                <label for="editName">Nazwa</label>
                <input type="text" id="editName" name="name" required maxlength="255">
            </div>

            <div class="form-group">
                <label for="editDesc">Opis</label>
                <textarea id="editDesc" name="subiekt_desc" rows="4"></textarea>
            </div>

            <div class="form-group">
                <label>Rozmiar</label>
                <div class="size-buttons">
                    <button type="button" class="btn-s" data-value="small" onclick="selectSize('small')">MAŁA</button>
                    <button type="button" class="btn-l" data-value="large" onclick="selectSize('large')">DUŻA</button>
                    <button type="button" class="btn-o" data-value="other" onclick="selectSize('other')">INNE</button>
                </div>
                <input type="hidden" name="size_status" id="editSize">
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal()">Anuluj</button>
                <button type="submit" class="btn-save">Zapisz zmiany</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var editBtns = document.querySelectorAll('.btn-edit');
        for (var i = 0; i < editBtns.length; i++) {
            editBtns[i].addEventListener('click', function() {
                var btn = this;
                var id     = btn.getAttribute('data-id');
                var name   = btn.getAttribute('data-name');
                var desc   = btn.getAttribute('data-desc');
                var status = btn.getAttribute('data-status');

                document.getElementById('editId').value   = id;
                document.getElementById('editName').value = name;
                document.getElementById('editDesc').value = desc;
                document.getElementById('editSize').value = status;

                var sizeBtns = document.querySelectorAll('.size-buttons button');
                for (var j = 0; j < sizeBtns.length; j++) {
                    if (sizeBtns[j].getAttribute('data-value') === status) {
                        sizeBtns[j].classList.add('selected');
                    } else {
                        sizeBtns[j].classList.remove('selected');
                    }
                }

                document.getElementById('modalBackdrop').classList.add('open');
                document.getElementById('editName').focus();
            });
        }

        document.getElementById('modalBackdrop').addEventListener('click', function(e) {
            if (e.target === this) { closeModal(); }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeModal(); }
        });
    });

    function closeModal() {
        document.getElementById('modalBackdrop').classList.remove('open');
    }

    function selectSize(value) {
        document.getElementById('editSize').value = value;
        var btns = document.querySelectorAll('.size-buttons button');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('data-value') === value) {
                btns[i].classList.add('selected');
            } else {
                btns[i].classList.remove('selected');
            }
        }
    }
</script>
</body>
</html>