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

function save_packing_log($label, $payload, $setLastResponse = true)
{
    if (!isset($_SESSION['packing_logs']) || !is_array($_SESSION['packing_logs'])) {
        $_SESSION['packing_logs'] = array();
    }

    if ($setLastResponse) {
        $_SESSION['last_packing_response'] = $payload;
    }

    array_unshift($_SESSION['packing_logs'], array(
        'time' => date('Y-m-d H:i:s'),
        'label' => $label,
        'payload' => $payload
    ));

    $_SESSION['packing_logs'] = array_slice($_SESSION['packing_logs'], 0, 30);
}

function packing_url($orderCode, $batchId, $carrier)
{
    $target = 'packing.php';
    $params = array();

    if ($orderCode !== '') {
        $params[] = 'order_code=' . urlencode($orderCode);
    }
    if ($batchId > 0) {
        $params[] = 'batch_id=' . urlencode((string)$batchId);
    }
    if ($carrier !== '') {
        $params[] = 'carrier=' . urlencode($carrier);
    }

    if (!empty($params)) {
        $target .= '?' . implode('&', $params);
    }

    return $target;
}

if (!isset($_SESSION['token'])) {
    header('Location: index.php');
    exit;
}

if (!isset($_SESSION['packing_logs']) || !is_array($_SESSION['packing_logs'])) {
    $_SESSION['packing_logs'] = array();
}

$carrier   = isset($_GET['carrier']) ? trim((string)$_GET['carrier']) : '';
$batchId   = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$orderCode = isset($_GET['order_code']) ? trim((string)$_GET['order_code']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action        = trim((string)$_POST['action']);
    $postOrderCode = isset($_POST['order_code']) ? trim((string)$_POST['order_code']) : $orderCode;
    $postBatchId   = isset($_POST['batch_id']) ? (int)$_POST['batch_id'] : $batchId;
    $packageSize   = isset($_POST['package_size']) ? strtoupper(trim((string)$_POST['package_size'])) : '';

    if ($action === 'open_order' && $postOrderCode !== '') {
        header('Location: ' . packing_url($postOrderCode, $postBatchId, $carrier));
        exit;
    }

    if ($action === 'cancel' && $postOrderCode !== '') {
        $cancelRes = apicall('POST', '/packing/orders/' . rawurlencode($postOrderCode) . '/cancel', array());
        save_packing_log('cancel order=' . $postOrderCode, $cancelRes);

        header('Location: workflow.php');
        exit;
    }

    if ($action === 'reprint_label' && $postOrderCode !== '') {
        $reprintRes = apicall('POST', '/shipping/orders/' . rawurlencode($postOrderCode) . '/reprint', array());
        save_packing_log('reprint_label order=' . $postOrderCode, $reprintRes);

        header('Location: ' . packing_url($postOrderCode, $postBatchId, $carrier));
        exit;
    }

    if ($action === 'generate_label' && $postOrderCode !== '') {
        $body = array();
        if ($packageSize !== '') {
            $body['size'] = $packageSize;
        }

        $generateRes = apicall('POST', '/shipping/orders/' . rawurlencode($postOrderCode) . '/generate-label', $body);
        save_packing_log('generate_label order=' . $postOrderCode . ' size=' . ($packageSize !== '' ? $packageSize : '-'), $generateRes);

        header('Location: ' . packing_url($postOrderCode, $postBatchId, $carrier));
        exit;
    }

    if ($action === 'finish' && $postOrderCode !== '') {
        $finishRes = apicall('POST', '/packing/orders/' . rawurlencode($postOrderCode) . '/finish', array());
        save_packing_log('finish order=' . $postOrderCode, $finishRes);

        if (isset($finishRes['ok']) && $finishRes['ok']) {
            $packing = isset($finishRes['data']['packing']) && is_array($finishRes['data']['packing'])
                ? $finishRes['data']['packing']
                : array();

            $nextOrderCode = isset($packing['next_order_code']) ? trim((string)$packing['next_order_code']) : '';
            $batchCompleted = !empty($packing['batch_completed']);
            $nextCarrier = isset($packing['carrier_key']) ? trim((string)$packing['carrier_key']) : $carrier;

            if ($nextOrderCode !== '') {
                header('Location: ' . packing_url($nextOrderCode, $postBatchId, $nextCarrier));
                exit;
            }

            if ($batchCompleted) {
                header('Location: workflow.php');
                exit;
            }
        }

        header('Location: ' . packing_url($postOrderCode, $postBatchId, $carrier));
        exit;
    }
}

$batchData = array();
$batchOrders = array();

if ($batchId > 0) {
    $batchRes = apicall('GET', '/picking/batches/' . $batchId);
    save_packing_log('batch_detail batch=' . $batchId, $batchRes, false);

    if (isset($batchRes['data']['picking']) && is_array($batchRes['data']['picking'])) {
        $batchData = $batchRes['data']['picking'];
        if (isset($batchData['orders']) && is_array($batchData['orders'])) {
            $batchOrders = $batchData['orders'];
        }
    }
}

if ($orderCode === '' && !empty($batchOrders)) {
    foreach ($batchOrders as $orderRow) {
        if (!empty($orderRow['order_code'])) {
            $orderCode = (string)$orderRow['order_code'];
            break;
        }
    }
}

$packingData = array();
$openRes = array();
$showRes = array();

if ($orderCode !== '') {
    $openRes = apicall('POST', '/packing/orders/' . rawurlencode($orderCode) . '/open', array());
    save_packing_log('open order=' . $orderCode, $openRes, false);

    if (isset($openRes['ok']) && $openRes['ok'] && isset($openRes['data']['packing']) && is_array($openRes['data']['packing'])) {
        $packingData = $openRes['data']['packing'];
    } else {
        $showRes = apicall('GET', '/packing/orders/' . rawurlencode($orderCode));
        save_packing_log('show order=' . $orderCode, $showRes, false);

        if (isset($showRes['ok']) && $showRes['ok'] && isset($showRes['data']['packing']) && is_array($showRes['data']['packing'])) {
            $packingData = $showRes['data']['packing'];
        }
    }
}

$sessionData = isset($packingData['session']) && is_array($packingData['session']) ? $packingData['session'] : array();
$orderData   = isset($packingData['order']) && is_array($packingData['order']) ? $packingData['order'] : array();
$shipping    = isset($packingData['shipping']) && is_array($packingData['shipping']) ? $packingData['shipping'] : array();
$items       = isset($packingData['items']) && is_array($packingData['items']) ? $packingData['items'] : array();
$package     = isset($packingData['package']) && is_array($packingData['package']) ? $packingData['package'] : array();
$label       = isset($packingData['label']) && is_array($packingData['label']) ? $packingData['label'] : array();

$lastResponse = isset($_SESSION['last_packing_response']) ? $_SESSION['last_packing_response'] : array();
$logs = isset($_SESSION['packing_logs']) ? $_SESSION['packing_logs'] : array();

$canFinish = !empty($package) && !empty($label);
$packingOpenFailed = ($orderCode !== '' && empty($packingData));

$itemGroups = array();
foreach ($items as $item) {
    $offerId = isset($item['offer_id']) && $item['offer_id'] !== null ? trim((string)$item['offer_id']) : '';
    $groupKey = $offerId !== '' ? 'offer:' . $offerId : 'item:' . (isset($item['id']) ? (string)$item['id'] : md5(json_encode($item)));

    if (!isset($itemGroups[$groupKey])) {
        $itemGroups[$groupKey] = array(
            'offer_id' => $offerId !== '' ? $offerId : null,
            'items' => array(),
            'total_qty' => 0.0,
        );
    }

    $itemGroups[$groupKey]['items'][] = $item;
    $itemGroups[$groupKey]['total_qty'] += (float)(isset($item['expected_qty']) ? $item['expected_qty'] : 0);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Packing</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;font-size:14px;background:#f5f5f5}
.wrap{display:flex;min-height:100vh}
.left{width:68%;padding:16px;background:#fff}
.right{width:32%;padding:16px;background:#f0f3f7;border-left:1px solid #d7dce2}
.topbar{margin-bottom:12px}
.btn-top{display:inline-block;padding:8px 12px;background:#222;color:#fff;text-decoration:none;border-radius:4px;margin-right:8px;border:0;cursor:pointer}
.btn-top.gray{background:#666}
.btn-top.green{background:#198754}
.btn-top.red{background:#dc3545}
.btn-top.orange{background:#fd7e14}
.btn-top.blue{background:#0d6efd}
.btn-top:disabled{opacity:.55;cursor:not-allowed}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px;margin-bottom:12px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border:1px solid #ddd;padding:8px;vertical-align:top;text-align:left}
th{background:#f7f7f7}
.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#e9ecef}
.badge.ok{background:#d1e7dd}
.badge.no{background:#f8d7da}
.badge.warn{background:#fff3cd}
.small{font-size:12px;color:#666}
.alert{padding:10px 12px;border-radius:6px;margin-top:10px}
.alert.warn{background:#fff3cd;border:1px solid #f0d98a}
.alert.ok{background:#d1e7dd;border:1px solid #a7d7be}
.order-nav a{display:inline-block;margin:0 8px 8px 0;padding:6px 10px;background:#eef2f7;border-radius:4px;text-decoration:none;color:#222}
.order-nav a.active{background:#cfe2ff}
.product-main{font-weight:bold}
.meta{margin-top:4px}
.meta div{margin-bottom:2px}
.group-row{background:#f7f7f7}
.inline-form{display:inline-block;margin-right:8px;margin-top:8px}
pre{white-space:pre-wrap;word-break:break-word;background:#111;color:#f2f2f2;padding:12px;border-radius:6px;overflow:auto}
.log-item{border-bottom:1px solid #ddd;padding:8px 0}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
select{padding:7px 10px;border:1px solid #ccc;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">
    <div class="left">
        <div class="topbar">
            <a class="btn-top gray" href="workflow.php">Powrót</a>
            <?php if ($batchId > 0): ?>
                <a class="btn-top gray" href="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">Powrót do pickingu</a>
            <?php endif; ?>
            <?php if ($orderCode !== ''): ?>
                <a class="btn-top gray" href="<?php echo h(packing_url($orderCode, $batchId, $carrier)); ?>">Odśwież</a>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Packing</h2>
            <div>Batch: <?php echo h($batchId > 0 ? $batchId : '—'); ?></div>
            <div>Zamówienie: <strong><?php echo h($orderCode !== '' ? $orderCode : '—'); ?></strong></div>
            <div>Status sesji:
                <span class="badge"><?php echo h(isset($sessionData['status']) ? $sessionData['status'] : 'brak'); ?></span>
            </div>
            <div>Carrier group: <?php echo h(isset($shipping['menu_group']) ? $shipping['menu_group'] : '—'); ?></div>
            <div>Metoda dostawy: <?php echo h(isset($orderData['delivery_method']) ? $orderData['delivery_method'] : '—'); ?></div>
            <div>Kurier: <?php echo h(isset($orderData['courier_code']) && $orderData['courier_code'] !== '' ? $orderData['courier_code'] : '—'); ?></div>

            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="order_code" value="<?php echo h($orderCode); ?>">
                <input type="hidden" name="batch_id" value="<?php echo h($batchId); ?>">

                <div class="inline-form">
                    <label class="small" for="package_size">Rozmiar paczki</label><br>
                    <select name="package_size" id="package_size">
                        <option value="">— wybierz —</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                    </select>
                </div>

                <div class="inline-form">
                    <button type="submit" name="action" value="generate_label" class="btn-top orange">Generuj etykietę + drukuj</button>
                </div>

                <div class="inline-form">
                    <button type="submit" name="action" value="finish" class="btn-top green" <?php echo $canFinish ? '' : 'disabled'; ?>>Zakończ pakowanie</button>
                </div>
            </form>

            <form method="post" style="margin-top:8px;">
                <input type="hidden" name="order_code" value="<?php echo h($orderCode); ?>">
                <input type="hidden" name="batch_id" value="<?php echo h($batchId); ?>">

                <div class="inline-form">
                    <button type="submit" name="action" value="reprint_label" class="btn-top blue" <?php echo !empty($label) ? '' : 'disabled'; ?>>Reprint etykiety</button>
                </div>

                <div class="inline-form">
                    <button type="submit" name="action" value="cancel" class="btn-top red" onclick="return confirm('Anulować sesję packingu?');">Anuluj</button>
                </div>
            </form>

            <?php if ($canFinish): ?>
                <div class="alert ok">Etykieta i paczka są dostępne — można zakończyć pakowanie i przejść dalej.</div>
            <?php else: ?>
                <div class="alert warn">Najpierw wygeneruj etykietę. W Twoim systemie ten krok od razu próbuje drukować przez ZebraPrinter.</div>
            <?php endif; ?>
        </div>

        <?php if (!empty($batchOrders)): ?>
        <div class="card">
            <h3>Zamówienia w batchu</h3>
            <div class="order-nav">
                <?php foreach ($batchOrders as $bo): ?>
                    <?php
                        $boCode = isset($bo['order_code']) ? (string)$bo['order_code'] : '';
                        $active = $boCode === $orderCode ? 'active' : '';
                    ?>
                    <?php if ($boCode !== ''): ?>
                        <a class="<?php echo h($active); ?>" href="<?php echo h(packing_url($boCode, $batchId, $carrier)); ?>">
                            <?php echo h($boCode); ?>
                            <?php if (!empty($bo['status'])): ?>
                                <span class="small">(<?php echo h($bo['status']); ?>)</span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($packingOpenFailed): ?>
        <div class="card">
            <h3>Diagnostyka otwarcia packingu</h3>
            <pre><?php print_r($openRes); ?></pre>
            <pre><?php print_r($showRes); ?></pre>
        </div>
        <?php endif; ?>

        <div class="grid2">
            <div class="card">
                <h3>Klient / dostawa</h3>
                <table>
                    <tr><th>Pole</th><th>Wartość</th></tr>
                    <tr><td>Odbiorca</td><td><?php echo h(isset($orderData['delivery_fullname']) ? $orderData['delivery_fullname'] : '—'); ?></td></tr>
                    <tr><td>Miasto</td><td><?php echo h(isset($orderData['delivery_city']) ? $orderData['delivery_city'] : '—'); ?></td></tr>
                    <tr><td>Kod pocztowy</td><td><?php echo h(isset($orderData['delivery_postcode']) ? $orderData['delivery_postcode'] : '—'); ?></td></tr>
                    <tr><td>Adres</td><td><?php echo h(isset($orderData['delivery_address']) ? $orderData['delivery_address'] : '—'); ?></td></tr>
                    <tr><td>Telefon</td><td><?php echo h(isset($orderData['phone']) ? $orderData['phone'] : '—'); ?></td></tr>
                    <tr><td>Email</td><td><?php echo h(isset($orderData['email']) ? $orderData['email'] : '—'); ?></td></tr>
                    <tr><td>Punkt odbioru</td><td><?php echo h(isset($orderData['pickup_point_name']) ? $orderData['pickup_point_name'] : '—'); ?></td></tr>
                    <tr><td>ID punktu</td><td><?php echo h(isset($orderData['pickup_point_id']) ? $orderData['pickup_point_id'] : '—'); ?></td></tr>
                    <tr><td>Adres punktu</td><td><?php echo h(isset($orderData['pickup_point_address']) ? $orderData['pickup_point_address'] : '—'); ?></td></tr>
                </table>
            </div>

            <div class="card">
                <h3>Wysyłka / etykieta</h3>
                <table>
                    <tr><th>Pole</th><th>Wartość</th></tr>
                    <tr><td>menu_group</td><td><?php echo h(isset($shipping['menu_group']) ? $shipping['menu_group'] : '—'); ?></td></tr>
                    <tr><td>carrier_code</td><td><?php echo h(isset($shipping['carrier_code']) ? $shipping['carrier_code'] : '—'); ?></td></tr>
                    <tr><td>label_source</td><td><?php echo h(isset($shipping['label_source']) ? $shipping['label_source'] : '—'); ?></td></tr>
                    <tr><td>tracking_number</td><td><?php echo h(isset($package['tracking_number']) ? $package['tracking_number'] : '—'); ?></td></tr>
                    <tr><td>package_id</td><td><?php echo h(isset($package['id']) ? $package['id'] : '—'); ?></td></tr>
                    <tr><td>label_id</td><td><?php echo h(isset($label['id']) ? $label['id'] : '—'); ?></td></tr>
                    <tr><td>label_status</td><td><?php echo h(isset($label['label_status']) ? $label['label_status'] : (isset($label['status']) ? $label['status'] : '—')); ?></td></tr>
                    <tr><td>label_format</td><td><?php echo h(isset($label['label_format']) ? $label['label_format'] : '—'); ?></td></tr>
                    <tr><td>file_token</td><td><?php echo h(isset($label['file_token']) ? $label['file_token'] : '—'); ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Pozycje do spakowania (grupowane po offer_id)</h3>
            <table>
                <tr>
                    <th>Grupa</th>
                    <th>Produkt</th>
                    <th>Identyfikacja</th>
                    <th>Ilość</th>
                </tr>
                <?php foreach ($itemGroups as $group): ?>
                    <tr class="group-row">
                        <td colspan="4">
                            <strong><?php echo h($group['offer_id'] !== null ? 'offer_id: ' . $group['offer_id'] : 'brak offer_id'); ?></strong>
                            <span class="small"> | pozycji: <?php echo h((string)count($group['items'])); ?> | suma qty: <?php echo h(qty_text($group['total_qty'])); ?></span>
                        </td>
                    </tr>
                    <?php foreach ($group['items'] as $item): ?>
                    <?php
                        $uom = isset($item['uom']) && $item['uom'] !== null ? (string)$item['uom'] : '';
                        $subiektTowId = isset($item['subiekt_tow_id']) && $item['subiekt_tow_id'] !== null ? (string)$item['subiekt_tow_id'] : '';
                        $subiektSymbol = isset($item['subiekt_symbol']) && $item['subiekt_symbol'] !== null ? (string)$item['subiekt_symbol'] : '';
                        $subiektDesc = isset($item['subiekt_desc']) && $item['subiekt_desc'] !== null ? (string)$item['subiekt_desc'] : '';
                        $sourceName = isset($item['source_name']) && $item['source_name'] !== null ? (string)$item['source_name'] : '';
                        $productCode = isset($item['product_code']) ? (string)$item['product_code'] : '';
                        $productName = isset($item['product_name']) ? (string)$item['product_name'] : '';
                        $offerId = isset($item['offer_id']) && $item['offer_id'] !== null ? (string)$item['offer_id'] : '';
                    ?>
                    <tr>
                        <td><?php echo h($offerId !== '' ? $offerId : '—'); ?></td>
                        <td>
                            <div class="product-main"><?php echo h($productName !== '' ? $productName : '—'); ?></div>
                            <div class="meta">
                                <?php if ($subiektSymbol !== ''): ?>
                                    <div class="small">symbol: <?php echo h($subiektSymbol); ?></div>
                                <?php endif; ?>
                                <?php if ($subiektDesc !== ''): ?>
                                    <div class="small">opis: <?php echo h($subiektDesc); ?></div>
                                <?php endif; ?>
                                <?php if ($sourceName !== '' && $sourceName !== $productName): ?>
                                    <div class="small">źródło: <?php echo h($sourceName); ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="small">subiekt_tow_id: <?php echo h($subiektTowId !== '' ? $subiektTowId : '—'); ?></div>
                            <div class="small">product_code: <?php echo h($productCode !== '' ? $productCode : '—'); ?></div>
                            <div class="small">pak_order_item_id: <?php echo h(isset($item['pak_order_item_id']) ? $item['pak_order_item_id'] : '—'); ?></div>
                        </td>
                        <td>
                            <strong><?php echo h(qty_text(isset($item['expected_qty']) ? $item['expected_qty'] : '')); ?><?php echo $uom !== '' ? ' ' . h($uom) : ''; ?></strong><br>
                            <span class="small">spakowane: <?php echo h(qty_text(isset($item['packed_qty']) ? $item['packed_qty'] : '0')); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div class="right">
        <div class="card">
            <h3>Ostatnia odpowiedź API</h3>
            <pre><?php print_r($lastResponse); ?></pre>
        </div>

        <div class="card">
            <h3>Historia akcji</h3>
            <?php foreach ($logs as $log): ?>
                <div class="log-item">
                    <div><strong><?php echo h($log['label']); ?></strong></div>
                    <div class="small"><?php echo h($log['time']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>
