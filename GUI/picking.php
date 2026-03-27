<?php
session_start();
require_once __DIR__ . '/api.php';

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function qty_text($v)
{
    if ($v === null || $v === '') {
        return '';
    }

    $f = (float)$v;
    if (abs($f - round($f)) < 0.0005) {
        return (string)(int)round($f);
    }

    return rtrim(rtrim(number_format($f, 3, '.', ''), '0'), '.');
}

function status_badge_class($status)
{
    $class = 'badge';
    if ($status === 'picked') {
        $class .= ' ok';
    } elseif ($status === 'missing') {
        $class .= ' no';
    } elseif ($status === 'partial') {
        $class .= ' warn';
    }
    return $class;
}

function save_log($label, $payload, $setLastResponse = true)
{
    if (!isset($_SESSION['picking_logs']) || !is_array($_SESSION['picking_logs'])) {
        $_SESSION['picking_logs'] = array();
    }

    if ($setLastResponse) {
        $_SESSION['last_action_response'] = $payload;
    }

    array_unshift($_SESSION['picking_logs'], array(
        'time' => date('Y-m-d H:i:s'),
        'label' => $label,
        'payload' => $payload
    ));

    $_SESSION['picking_logs'] = array_slice($_SESSION['picking_logs'], 0, 20);
}

if (!isset($_SESSION['token'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['picking_logs']) || !is_array($_SESSION['picking_logs'])) {
    $_SESSION['picking_logs'] = array();
}

$meRes = apicall('GET', '/auth/me');
save_log('auth_me_sync', $meRes, false);

if (isset($meRes['data']['auth']['station']) && is_array($meRes['data']['auth']['station'])) {
    $_SESSION['station'] = $meRes['data']['auth']['station'];
}
if (isset($meRes['data']['auth']['user']) && is_array($meRes['data']['auth']['user'])) {
    $_SESSION['user'] = $meRes['data']['auth']['user'];
}

$station = isset($_SESSION['station']) && is_array($_SESSION['station']) ? $_SESSION['station'] : array();
$currentPackageMode = isset($station['package_mode']) ? trim((string)$station['package_mode']) : 'small';
$defaultPackageMode = isset($station['package_mode_default']) ? trim((string)$station['package_mode_default']) : $currentPackageMode;

if (!in_array($currentPackageMode, array('small', 'large'), true)) {
    $currentPackageMode = 'small';
}
if (!in_array($defaultPackageMode, array('small', 'large'), true)) {
    $defaultPackageMode = $currentPackageMode;
}

$currentPickingBatchSize = isset($station['picking_batch_size']) ? (int)$station['picking_batch_size'] : 2;
if ($currentPickingBatchSize < 1) {
    $currentPickingBatchSize = 2;
}

$carrier = isset($_GET['carrier']) ? trim($_GET['carrier']) : '';
if ($carrier !== '') {
    $_SESSION['carrier'] = $carrier;
} elseif (isset($_SESSION['carrier'])) {
    $carrier = $_SESSION['carrier'];
}

if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['batch']);
    unset($_SESSION['last_action_response']);
    $_SESSION['picking_logs'] = array();
    $target = 'picking.php';
    if ($carrier !== '') {
        $target .= '?carrier=' . urlencode($carrier);
    }
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_package_mode') {
    $newPackageMode = isset($_POST['package_mode']) ? trim((string)$_POST['package_mode']) : '';

    $actionRes = apicall('POST', '/stations/package-mode', array(
        'package_mode' => $newPackageMode
    ));
    save_log('change_package_mode mode=' . $newPackageMode, $actionRes);

    if (isset($actionRes['data']['data']['station']) && is_array($actionRes['data']['data']['station'])) {
        $_SESSION['station']['package_mode'] = $actionRes['data']['data']['station']['package_mode'];
        if (isset($actionRes['data']['data']['station']['package_mode_default'])) {
            $_SESSION['station']['package_mode_default'] = $actionRes['data']['data']['station']['package_mode_default'];
        }
    }

    unset($_SESSION['batch']);

    $target = 'picking.php';
    if ($carrier !== '') {
        $target .= '?carrier=' . urlencode($carrier);
    }
    header('Location: ' . $target);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_picking_batch_size') {
    $newPickingBatchSize = isset($_POST['picking_batch_size']) ? (int)$_POST['picking_batch_size'] : 0;

    $actionRes = apicall('POST', '/stations/picking-batch-size', array(
        'picking_batch_size' => $newPickingBatchSize
    ));
    save_log('change_picking_batch_size size=' . $newPickingBatchSize, $actionRes);

    if (isset($actionRes['data']['data']['station']['picking_batch_size'])) {
        $_SESSION['station']['picking_batch_size'] = (int)$actionRes['data']['data']['station']['picking_batch_size'];
    }

    unset($_SESSION['batch']);

    $target = 'picking.php';
    if ($carrier !== '') {
        $target .= '?carrier=' . urlencode($carrier);
    }
    header('Location: ' . $target);
    exit;
}

$currentRes = apicall('GET', '/picking/batches/current');
save_log('current_batch_sync', $currentRes, false);

$batchData = array();

if (isset($currentRes['data']['picking']['batch']['id'])) {
    $_SESSION['batch'] = $currentRes['data']['picking']['batch']['id'];
    $batchData = $currentRes['data']['picking'];
} else {
    if ($carrier === '') {
        echo 'Brak carrier';
        exit;
    }

    $openRes = apicall('POST', '/picking/batches/open', array(
        'carrier_key' => $carrier
    ));
    save_log('open_batch', $openRes);

    if (!isset($openRes['data']['picking']['batch']['id'])) {
        echo '<pre>';
        print_r($openRes);
        echo '</pre>';
        exit;
    }

    $_SESSION['batch'] = $openRes['data']['picking']['batch']['id'];
    $batchData = $openRes['data']['picking'];
}

$batchId = (int)$_SESSION['batch'];
$currentSelectionMode = 'cutoff_cluster';
if (isset($batchData['batch']['selection_mode']) && trim((string)$batchData['batch']['selection_mode']) !== '') {
    $currentSelectionMode = trim((string)$batchData['batch']['selection_mode']);
}

$batchPackageMode = isset($batchData['batch']['package_mode']) ? trim((string)$batchData['batch']['package_mode']) : $currentPackageMode;
if (!in_array($batchPackageMode, array('small', 'large'), true)) {
    $batchPackageMode = $currentPackageMode;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);

    if ($action === 'close_and_packing') {
        $actionRes = apicall('POST', '/picking/batches/' . $batchId . '/close', array());
        save_log('close batch=' . $batchId . ' -> packing', $actionRes);

        if (isset($actionRes['ok']) && $actionRes['ok']) {
            $target = 'packing.php?batch_id=' . urlencode((string)$batchId);
            if ($carrier !== '') {
                $target .= '&carrier=' . urlencode($carrier);
            }
            header('Location: ' . $target);
            exit;
        }
    }

    if ($action === 'change_selection_mode' && isset($_POST['selection_mode'])) {
        $selectionMode = trim((string)$_POST['selection_mode']);

        $actionRes = apicall('POST', '/picking/batches/' . $batchId . '/selection-mode', array(
            'selection_mode' => $selectionMode
        ));
        save_log('selection_mode batch=' . $batchId . ' mode=' . $selectionMode, $actionRes);
    }

    if ($action === 'refill_batch') {
        $actionRes = apicall('POST', '/picking/batches/' . $batchId . '/refill', array());
        save_log('refill batch=' . $batchId, $actionRes);
    }

    if ($action === 'close_batch') {
        $actionRes = apicall('POST', '/picking/batches/' . $batchId . '/close', array());
        save_log('close batch=' . $batchId, $actionRes);
    }

    if ($action === 'abandon_batch') {
        $actionRes = apicall('POST', '/picking/batches/' . $batchId . '/abandon', array());
        save_log('abandon batch=' . $batchId, $actionRes);
    }

    if ($action === 'pick' && isset($_POST['order_id'], $_POST['item_id'])) {
        $orderId = (int)$_POST['order_id'];
        $itemId = (int)$_POST['item_id'];

        $actionRes = apicall('POST', '/picking/orders/' . $orderId . '/items/' . $itemId . '/picked', array());
        save_log('picked order=' . $orderId . ' item=' . $itemId, $actionRes);
    }

    if ($action === 'missing' && isset($_POST['order_id'], $_POST['item_id'])) {
        $orderId = (int)$_POST['order_id'];
        $itemId = (int)$_POST['item_id'];
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

        $actionRes = apicall('POST', '/picking/orders/' . $orderId . '/items/' . $itemId . '/missing', array(
            'reason' => $reason
        ));
        save_log('missing order=' . $orderId . ' item=' . $itemId . ' reason=' . $reason, $actionRes);
    }

    if ($action === 'drop_order' && isset($_POST['order_id'])) {
        $orderId = (int)$_POST['order_id'];
        $reason = isset($_POST['reason']) ? trim($_POST['reason']) : 'missing_items';

        $dropRes = apicall('POST', '/picking/orders/' . $orderId . '/drop', array(
            'reason' => $reason
        ));
        save_log('drop order=' . $orderId . ' reason=' . $reason, $dropRes);

        if (isset($dropRes['ok']) && $dropRes['ok']) {
            $refillRes = apicall('POST', '/picking/batches/' . $batchId . '/refill', array());
            save_log('refill batch=' . $batchId, $refillRes, false);
        }
    }

    $target = 'picking.php';
    if ($carrier !== '') {
        $target .= '?carrier=' . urlencode($carrier);
    }
    header('Location: ' . $target);
    exit;
}

$ordersRes = apicall('GET', '/picking/batches/' . $batchId . '/orders');
$productsRes = apicall('GET', '/picking/batches/' . $batchId . '/products');

$orders = array();
$products = array();

if (isset($ordersRes['data']['orders']) && is_array($ordersRes['data']['orders'])) {
    $orders = $ordersRes['data']['orders'];
} elseif (isset($batchData['orders']) && is_array($batchData['orders'])) {
    $orders = $batchData['orders'];
}

if (isset($productsRes['data']['products']) && is_array($productsRes['data']['products'])) {
    $products = $productsRes['data']['products'];
} elseif (isset($batchData['products']) && is_array($batchData['products'])) {
    $products = $batchData['products'];
}

$rows = array();
$pendingRowsCount = 0;

foreach ($orders as $order) {
    $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();

    foreach ($items as $item) {
        $row = array(
            'order_id' => isset($order['id']) ? $order['id'] : '',
            'order_code' => isset($order['order_code']) ? $order['order_code'] : '',
            'delivery_method' => isset($order['delivery_method']) ? $order['delivery_method'] : '',
            'item_id' => isset($item['id']) ? $item['id'] : '',
            'pak_order_item_id' => isset($item['pak_order_item_id']) ? $item['pak_order_item_id'] : '',
            'subiekt_tow_id' => isset($item['subiekt_tow_id']) ? $item['subiekt_tow_id'] : '',
            'product_code' => isset($item['product_code']) ? $item['product_code'] : '',
            'product_name' => isset($item['product_name']) ? $item['product_name'] : '',
            'uom' => isset($item['uom']) ? $item['uom'] : '',
            'is_unmapped' => !empty($item['is_unmapped']),
            'expected_qty' => isset($item['expected_qty']) ? $item['expected_qty'] : '',
            'picked_qty' => isset($item['picked_qty']) ? $item['picked_qty'] : '',
            'status' => isset($item['status']) ? $item['status'] : '',
            'missing_reason' => isset($item['missing_reason']) ? $item['missing_reason'] : ''
        );

        if ($row['status'] !== 'picked' && $row['status'] !== 'missing') {
            $pendingRowsCount++;
        }

        $rows[] = $row;
    }
}

$lastResponse = isset($_SESSION['last_action_response']) ? $_SESSION['last_action_response'] : array();
$logs = isset($_SESSION['picking_logs']) ? $_SESSION['picking_logs'] : array();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Picking</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;font-size:14px;background:#f5f5f5}
.wrap{display:flex;min-height:100vh}
.left{width:60%;padding:16px;background:#fff}
.right{width:40%;padding:16px;background:#f0f3f7;border-left:1px solid #d7dce2}
.topbar{margin-bottom:12px}
.btn-top{display:inline-block;padding:8px 12px;background:#222;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px}
.btn-top.gray{background:#666}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:12px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:8px;vertical-align:top;text-align:left}
th{background:#f7f7f7}
.actions form{display:inline-block;margin:0 6px 0 0}
.actions button{display:inline-block;padding:6px 10px;text-decoration:none;border-radius:4px;color:#fff;border:0;cursor:pointer}
.ok{background:#198754}
.no{background:#dc3545}
.drop{background:#222}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#e9ecef}
.badge.ok{background:#d1e7dd}
.badge.no{background:#f8d7da}
.badge.warn{background:#fff3cd}
.small{font-size:12px;color:#666}
.muted{color:#666}
.breakdown{font-family:monospace;font-size:13px}
.inline-list{margin:6px 0 0 16px;padding:0}
.inline-list li{margin:0 0 4px 0}
pre{white-space:pre-wrap;word-break:break-word;background:#111;color:#f2f2f2;padding:12px;border-radius:6px;overflow:auto}
.log-item{border-bottom:1px solid #ddd;padding:8px 0}
.qty-line{line-height:1.5}
.mode-switch{margin-top:10px;padding:10px;background:#f7f7f7;border-radius:6px}
.mode-switch select{padding:6px 8px}
</style>
<script>
function submitMissing(formId) {
    var reason = prompt('Podaj powód braku:', 'brak na półce');
    if (reason === null) return false;
    reason = reason.trim();
    if (!reason) {
        alert('Powód braku jest wymagany');
        return false;
    }
    document.getElementById(formId + '_reason').value = reason;
    document.getElementById(formId).submit();
    return false;
}

function submitDrop(formId) {
    var reason = prompt('Podaj powód usunięcia całego zamówienia:', 'missing_items');
    if (reason === null) return false;
    reason = reason.trim();
    if (!reason) {
        alert('Powód drop jest wymagany');
        return false;
    }
    document.getElementById(formId + '_reason').value = reason;
    document.getElementById(formId).submit();
    return false;
}
</script>
</head>
<body>

<div class="wrap">

    <div class="left">
        <div class="topbar">
            <a class="btn-top" href="workflow.php">Powrót</a>
            <a class="btn-top gray" href="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">Odśwież</a>
            <a class="btn-top gray" href="picking.php?reset=1<?php echo $carrier !== '' ? '&carrier=' . urlencode($carrier) : ''; ?>">Nowy batch</a>

            <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>" style="display:inline-block;margin-right:8px;">
                <input type="hidden" name="action" value="refill_batch">
                <button type="submit" class="btn-top gray" style="border:0;cursor:pointer;">Refill</button>
            </form>

            <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>" style="display:inline-block;margin-right:8px;" onsubmit="return confirm('Na pewno zamknąć batch?');">
                <input type="hidden" name="action" value="close_batch">
                <button type="submit" class="btn-top" style="border:0;cursor:pointer;background:#198754;">Zamknij batch</button>
            </form>

            <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>" style="display:inline-block;" onsubmit="return confirm('Na pewno porzucić batch?');">
                <input type="hidden" name="action" value="abandon_batch">
                <button type="submit" class="btn-top" style="border:0;cursor:pointer;background:#dc3545;">Porzuć batch</button>
            </form>
        </div>

        <div class="card">
            <h2>Picking batch #<?php echo h($batchId); ?></h2>
            <div>Stanowisko: <?php echo h(isset($_SESSION['station']['station_code']) ? $_SESSION['station']['station_code'] : '—'); ?></div>
            <div>Carrier: <?php echo h($carrier); ?></div>
            <div>Tryb stanowiska (sesja): <strong><?php echo h($currentPackageMode); ?></strong></div>
            <div>Tryb batcha: <strong><?php echo h($batchPackageMode); ?></strong></div>
            <div class="small">Domyślny tryb stanowiska: <?php echo h($defaultPackageMode); ?></div>
            <div>Ile pobrać (sesja): <strong><?php echo h((string)$currentPickingBatchSize); ?></strong></div>
            <div>Tryb doboru: <strong><?php echo h($currentSelectionMode); ?></strong></div>
            <div>Aktywne pozycje order-level: <?php echo h(count($rows)); ?></div>
            <div>Pozycje jeszcze do obsługi: <?php echo h($pendingRowsCount); ?></div>
            <div>Produktów zbiorczo: <?php echo h(count($products)); ?></div>

            <div class="mode-switch">
                <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                    <input type="hidden" name="action" value="change_package_mode">
                    <label for="package_mode"><strong>Tryb stanowiska:</strong></label>
                    <select name="package_mode" id="package_mode">
                        <option value="small" <?php echo $currentPackageMode === 'small' ? 'selected' : ''; ?>>MAŁE</option>
                        <option value="large" <?php echo $currentPackageMode === 'large' ? 'selected' : ''; ?>>DUŻE</option>
                    </select>
                    <button type="submit" class="btn-top gray" style="border:0;cursor:pointer;">Zapisz tryb</button>
                </form>

                <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>" style="margin-top:8px;">
                    <input type="hidden" name="action" value="change_picking_batch_size">
                    <label for="picking_batch_size"><strong>Ile pobrać:</strong></label>
                    <input type="number" name="picking_batch_size" id="picking_batch_size" min="1" max="100" value="<?php echo h((string)$currentPickingBatchSize); ?>" style="width:90px;">
                    <button type="submit" class="btn-top gray" style="border:0;cursor:pointer;">Zapisz ilość</button>
                </form>

                <div class="small" style="margin-top:6px;">Po zmianie ilości ekran odświeży sesję stanowiska. Dla nowo otwieranych batchy backend pobierze dokładnie tyle zamówień, niezależnie od wybranego trybu MAŁE/DUŻE.</div>
            </div>

            <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>" style="margin-top:10px;">
                <input type="hidden" name="action" value="change_selection_mode">
                <select name="selection_mode">
                    <option value="cutoff" <?php echo $currentSelectionMode === 'cutoff' ? 'selected' : ''; ?>>cutoff</option>
                    <option value="cutoff_cluster" <?php echo $currentSelectionMode === 'cutoff_cluster' ? 'selected' : ''; ?>>cutoff_cluster</option>
                    <option value="emergency_single" <?php echo $currentSelectionMode === 'emergency_single' ? 'selected' : ''; ?>>emergency_single</option>
                </select>
                <button type="submit" class="btn-top gray" style="border:0;cursor:pointer;">Zmień tryb</button>
            </form>

            <?php if ($pendingRowsCount === 0 && !empty($orders)): ?>
                <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>" style="margin-top:10px;" onsubmit="return confirm('Zamknąć batch i przejść do packingu?');">
                    <input type="hidden" name="action" value="close_and_packing">
                    <button type="submit" class="btn-top" style="border:0;cursor:pointer;background:#198754;">Zakończono → packing</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Lista produktowa</h3>
            <table>
                <tr>
                    <th>Towar</th>
                    <th>Suma</th>
                    <th>Rozbicie</th>
                    <th>Źródła / akcje</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($products as $p): ?>
                <?php
                    $productStatus = isset($p['status']) ? (string)$p['status'] : '';
                    $productBadge = status_badge_class($productStatus);
                    $uom = isset($p['uom']) && $p['uom'] !== null ? (string)$p['uom'] : '';
                    $subiektTowId = isset($p['subiekt_tow_id']) ? $p['subiekt_tow_id'] : null;
                    $productCode = isset($p['product_code']) ? $p['product_code'] : '';
                    $qtyBreakdown = isset($p['qty_breakdown']) && is_array($p['qty_breakdown']) ? $p['qty_breakdown'] : array();
                    $orderBreakdown = isset($p['order_breakdown']) && is_array($p['order_breakdown']) ? $p['order_breakdown'] : array();
                    $productIsUnmapped = !empty($p['is_unmapped']);
                    $productSourceRows = array();

                    foreach ($rows as $candidate) {
                        $candidateUom = isset($candidate['uom']) && $candidate['uom'] !== null ? (string)$candidate['uom'] : '';
                        $candidateSubiektTowId = isset($candidate['subiekt_tow_id']) && $candidate['subiekt_tow_id'] !== null ? (string)$candidate['subiekt_tow_id'] : '';
                        $candidateProductCode = isset($candidate['product_code']) ? (string)$candidate['product_code'] : '';
                        $candidateIsUnmapped = !empty($candidate['is_unmapped']);

                        $matches = false;

                        if (!$productIsUnmapped && !$candidateIsUnmapped) {
                            $matches = (
                                (string)$subiektTowId !== '' &&
                                $candidateSubiektTowId === (string)$subiektTowId &&
                                $candidateUom === $uom
                            );
                        } else {
                            $matches = ($candidateProductCode === (string)$productCode);
                        }

                        if ($matches) {
                            $productSourceRows[] = $candidate;
                        }
                    }
                ?>
                <tr>
                    <td>
                        <strong><?php echo h(isset($p['product_name']) ? $p['product_name'] : ''); ?></strong>
                        <?php if (isset($p['subiekt_symbol']) && $p['subiekt_symbol'] !== null && $p['subiekt_symbol'] !== ''): ?>
                            <div class="small">symbol: <?php echo h($p['subiekt_symbol']); ?></div>
                        <?php endif; ?>
                        <?php if (isset($p['subiekt_desc']) && $p['subiekt_desc'] !== null && $p['subiekt_desc'] !== ''): ?>
                            <div class="small">opis: <?php echo h($p['subiekt_desc']); ?></div>
                        <?php endif; ?>
                        <div class="small">subiekt_tow_id: <?php echo h($subiektTowId !== null ? $subiektTowId : '—'); ?></div>
                        <div class="small">product_code: <?php echo h($productCode); ?></div>
                        <div class="small">uom: <?php echo h($uom !== '' ? $uom : '—'); ?></div>
                        <?php if ($productIsUnmapped): ?>
                            <div class="small">fallback legacy / is_unmapped</div>
                        <?php endif; ?>
                    </td>
                    <td class="qty-line">
                        <div><strong><?php echo h(qty_text(isset($p['remaining_qty']) ? $p['remaining_qty'] : '')); ?></strong><?php echo $uom !== '' ? ' ' . h($uom) : ''; ?> do zebrania</div>
                        <div class="small">oczekiwane: <?php echo h(qty_text(isset($p['total_expected_qty']) ? $p['total_expected_qty'] : '')); ?></div>
                        <div class="small">zebrane: <?php echo h(qty_text(isset($p['total_picked_qty']) ? $p['total_picked_qty'] : '')); ?></div>
                        <div class="small">braki: <?php echo h(qty_text(isset($p['total_missing_qty']) ? $p['total_missing_qty'] : '')); ?></div>
                    </td>
                    <td>
                        <div class="breakdown"><strong><?php echo h(isset($p['qty_breakdown_label']) ? $p['qty_breakdown_label'] : ''); ?></strong></div>
                        <?php if (!empty($orderBreakdown)): ?>
                            <ul class="inline-list">
                                <?php foreach ($orderBreakdown as $ob): ?>
                                    <li>
                                        <strong><?php echo h(isset($ob['order_code']) ? $ob['order_code'] : ''); ?></strong>
                                        —
                                        <?php echo h(qty_text(isset($ob['qty']) ? $ob['qty'] : '')); ?><?php echo $uom !== '' ? ' ' . h($uom) : ''; ?>
                                        <?php if (isset($ob['status_summary'])): ?>
                                            <span class="small">(<?php echo h((string)$ob['status_summary']); ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($productSourceRows)): ?>
                            <?php $dropShown = array(); ?>
                            <?php foreach ($productSourceRows as $sr): ?>
                                <?php
                                    $sourceMissingFormId = 'missing_prod_' . $sr['order_id'] . '_' . $sr['item_id'];
                                    $sourceDropFormId = 'drop_prod_' . $sr['order_id'] . '_' . md5($productCode . '_' . $sr['item_id']);
                                    $sourceStatus = isset($sr['status']) ? (string)$sr['status'] : '';
                                    $sourceStatusClass = status_badge_class($sourceStatus);
                                    $sourceUom = isset($sr['uom']) && $sr['uom'] !== '' ? (string)$sr['uom'] : '';
                                ?>
                                <div style="padding:8px 0;border-bottom:1px dashed #ddd;">
                                    <div>
                                        <strong><?php echo h($sr['order_code']); ?></strong>
                                        —
                                        <?php echo h(qty_text($sr['expected_qty'])); ?><?php echo $sourceUom !== '' ? ' ' . h($sourceUom) : ''; ?>
                                        <span class="<?php echo h($sourceStatusClass); ?>"><?php echo h($sourceStatus); ?></span>
                                    </div>
                                    <?php if (!empty($sr['missing_reason'])): ?>
                                        <div class="small"><?php echo h($sr['missing_reason']); ?></div>
                                    <?php endif; ?>
                                    <div class="actions" style="margin-top:6px;">
                                        <?php if ($sourceStatus !== 'picked'): ?>
                                        <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                                            <input type="hidden" name="action" value="pick">
                                            <input type="hidden" name="order_id" value="<?php echo h($sr['order_id']); ?>">
                                            <input type="hidden" name="item_id" value="<?php echo h($sr['item_id']); ?>">
                                            <button type="submit" class="ok">Zebrane</button>
                                        </form>
                                        <?php endif; ?>

                                        <?php if ($sourceStatus !== 'missing'): ?>
                                        <form id="<?php echo h($sourceMissingFormId); ?>" method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                                            <input type="hidden" name="action" value="missing">
                                            <input type="hidden" name="order_id" value="<?php echo h($sr['order_id']); ?>">
                                            <input type="hidden" name="item_id" value="<?php echo h($sr['item_id']); ?>">
                                            <input type="hidden" id="<?php echo h($sourceMissingFormId); ?>_reason" name="reason" value="">
                                            <button type="button" class="no" onclick="return submitMissing('<?php echo h($sourceMissingFormId); ?>');">Brak</button>
                                        </form>
                                        <?php endif; ?>

                                        <?php if (!isset($dropShown[(string)$sr['order_id']])): ?>
                                            <?php $dropShown[(string)$sr['order_id']] = true; ?>
                                            <form id="<?php echo h($sourceDropFormId); ?>" method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                                                <input type="hidden" name="action" value="drop_order">
                                                <input type="hidden" name="order_id" value="<?php echo h($sr['order_id']); ?>">
                                                <input type="hidden" id="<?php echo h($sourceDropFormId); ?>_reason" name="reason" value="">
                                                <button type="button" class="drop" onclick="return submitDrop('<?php echo h($sourceDropFormId); ?>');">X</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="muted">brak źródeł operacyjnych</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="<?php echo h($productBadge); ?>"><?php echo h($productStatus); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <?php if (!empty($orders)): ?>
        <div class="card">
            <h3>Order-level rows</h3>
            <table>
                <tr>
                    <th>Order</th>
                    <th>Item</th>
                    <th>Ilość</th>
                    <th>Status</th>
                    <th>Akcje</th>
                </tr>
                <?php foreach ($rows as $r): ?>
                <?php
                    $missingFormId = 'missing_' . $r['order_id'] . '_' . $r['item_id'];
                    $dropFormId = 'drop_' . $r['order_id'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo h($r['order_code']); ?></strong><br>
                        <span class="small"><?php echo h($r['delivery_method']); ?></span>
                    </td>
                    <td>
                        <?php echo h($r['product_name']); ?><br>
                        <span class="small">code: <?php echo h($r['product_code']); ?></span><br>
                        <span class="small">subiekt_tow_id: <?php echo h($r['subiekt_tow_id'] !== '' ? $r['subiekt_tow_id'] : '—'); ?></span><br>
                        <span class="small">uom: <?php echo h($r['uom'] !== '' ? $r['uom'] : '—'); ?></span><br>
                        <?php if ($r['is_unmapped']): ?>
                            <span class="small">fallback legacy / is_unmapped</span><br>
                        <?php endif; ?>
                        <?php if ($r['missing_reason']): ?>
                            <span class="small">reason: <?php echo h($r['missing_reason']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo h(qty_text($r['expected_qty'])); ?></td>
                    <td><span class="<?php echo h(status_badge_class($r['status'])); ?>"><?php echo h($r['status']); ?></span></td>
                    <td class="actions">
                        <?php if ($r['status'] !== 'picked'): ?>
                        <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                            <input type="hidden" name="action" value="pick">
                            <input type="hidden" name="order_id" value="<?php echo h($r['order_id']); ?>">
                            <input type="hidden" name="item_id" value="<?php echo h($r['item_id']); ?>">
                            <button type="submit" class="ok">Zebrane</button>
                        </form>
                        <?php endif; ?>

                        <?php if ($r['status'] !== 'missing'): ?>
                        <form id="<?php echo h($missingFormId); ?>" method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                            <input type="hidden" name="action" value="missing">
                            <input type="hidden" name="order_id" value="<?php echo h($r['order_id']); ?>">
                            <input type="hidden" name="item_id" value="<?php echo h($r['item_id']); ?>">
                            <input type="hidden" id="<?php echo h($missingFormId); ?>_reason" name="reason" value="">
                            <button type="button" class="no" onclick="return submitMissing('<?php echo h($missingFormId); ?>');">Brak</button>
                        </form>
                        <?php endif; ?>

                        <form id="<?php echo h($dropFormId); ?>" method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                            <input type="hidden" name="action" value="drop_order">
                            <input type="hidden" name="order_id" value="<?php echo h($r['order_id']); ?>">
                            <input type="hidden" id="<?php echo h($dropFormId); ?>_reason" name="reason" value="">
                            <button type="button" class="drop" onclick="return submitDrop('<?php echo h($dropFormId); ?>');">X</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="right">
        <div class="card">
            <h3>Ostatnia odpowiedź API</h3>
            <pre><?php echo h(print_r($lastResponse, true)); ?></pre>
        </div>

        <div class="card">
            <h3>Log akcji</h3>
            <?php if (empty($logs)): ?>
                <div class="muted">brak logów</div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-item">
                        <div><strong><?php echo h($log['time']); ?></strong> — <?php echo h($log['label']); ?></div>
                        <pre><?php echo h(print_r($log['payload'], true)); ?></pre>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>
