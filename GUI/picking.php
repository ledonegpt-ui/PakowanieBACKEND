<?php
session_start();
require_once __DIR__ . '/api.php';

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);

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

foreach ($orders as $order) {
    $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : array();

    foreach ($items as $item) {
        $rows[] = array(
            'order_id' => isset($order['id']) ? $order['id'] : '',
            'order_code' => isset($order['order_code']) ? $order['order_code'] : '',
            'delivery_method' => isset($order['delivery_method']) ? $order['delivery_method'] : '',
            'item_id' => isset($item['id']) ? $item['id'] : '',
            'product_code' => isset($item['product_code']) ? $item['product_code'] : '',
            'product_name' => isset($item['product_name']) ? $item['product_name'] : '',
            'expected_qty' => isset($item['expected_qty']) ? $item['expected_qty'] : '',
            'picked_qty' => isset($item['picked_qty']) ? $item['picked_qty'] : '',
            'status' => isset($item['status']) ? $item['status'] : '',
            'missing_reason' => isset($item['missing_reason']) ? $item['missing_reason'] : ''
        );
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
.left{width:55%;padding:16px;background:#fff}
.right{width:45%;padding:16px;background:#f0f3f7;border-left:1px solid #d7dce2}
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
.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#fff3cd}
.badge.ok{background:#d1e7dd}
.badge.no{background:#f8d7da}
.small{font-size:12px;color:#666}
pre{white-space:pre-wrap;word-break:break-word;background:#111;color:#f2f2f2;padding:12px;border-radius:6px;overflow:auto}
.log-item{border-bottom:1px solid #ddd;padding:8px 0}
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
            <a class="btn-top gray" href="picking.php?reset=1<?php echo $carrier !== '' ? '&carrier=' . urlencode($carrier) : ''; ?>">Nowy batch</a>
        </div>

        <div class="card">
            <h2>Picking batch #<?php echo h($batchId); ?></h2>
            <div>Carrier: <?php echo h($carrier); ?></div>
            <div>Pozycji do kliknięcia: <?php echo h(count($rows)); ?></div>
            <div>Produktów zbiorczo: <?php echo h(count($products)); ?></div>
        </div>

        <div class="card">
            <h3>Pozycje do oznaczenia</h3>

            <table>
                <tr>
                    <th>Zamówienie</th>
                    <th>Kod</th>
                    <th>Nazwa</th>
                    <th>Ilość</th>
                    <th>Zebrano</th>
                    <th>Status</th>
                    <th>Akcja</th>
                </tr>
                <?php foreach ($rows as $r): ?>
                <?php
                    $statusClass = 'badge';
                    if ($r['status'] === 'picked') $statusClass .= ' ok';
                    if ($r['status'] === 'missing') $statusClass .= ' no';
                    $missingFormId = 'missing_' . $r['order_id'] . '_' . $r['item_id'];
                    $dropFormId = 'drop_' . $r['order_id'];
                ?>
                <tr>
                    <td>
                        <?php echo h($r['order_code']); ?><br>
                        <span class="small"><?php echo h($r['delivery_method']); ?></span>
                    </td>
                    <td><?php echo h($r['product_code']); ?></td>
                    <td><?php echo h($r['product_name']); ?></td>
                    <td><?php echo h($r['expected_qty']); ?></td>
                    <td><?php echo h($r['picked_qty']); ?></td>
                    <td>
                        <span class="<?php echo h($statusClass); ?>"><?php echo h($r['status']); ?></span>
                        <?php if ($r['missing_reason'] !== ''): ?>
                            <div class="small"><?php echo h($r['missing_reason']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <form method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                            <input type="hidden" name="action" value="pick">
                            <input type="hidden" name="order_id" value="<?php echo h($r['order_id']); ?>">
                            <input type="hidden" name="item_id" value="<?php echo h($r['item_id']); ?>">
                            <button type="submit" class="ok">Zebrane</button>
                        </form>

                        <form id="<?php echo h($missingFormId); ?>" method="post" action="picking.php<?php echo $carrier !== '' ? '?carrier=' . urlencode($carrier) : ''; ?>">
                            <input type="hidden" name="action" value="missing">
                            <input type="hidden" name="order_id" value="<?php echo h($r['order_id']); ?>">
                            <input type="hidden" name="item_id" value="<?php echo h($r['item_id']); ?>">
                            <input type="hidden" id="<?php echo h($missingFormId); ?>_reason" name="reason" value="">
                            <button type="button" class="no" onclick="return submitMissing('<?php echo h($missingFormId); ?>');">Brak</button>
                        </form>

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

        <div class="card">
            <h3>Widok per-produkt</h3>
            <table>
                <tr>
                    <th>Kod</th>
                    <th>Nazwa</th>
                    <th>Do zebrania</th>
                    <th>Zebrano</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td><?php echo h(isset($p['product_code']) ? $p['product_code'] : ''); ?></td>
                    <td><?php echo h(isset($p['product_name']) ? $p['product_name'] : ''); ?></td>
                    <td><?php echo h(isset($p['total_expected_qty']) ? $p['total_expected_qty'] : ''); ?></td>
                    <td><?php echo h(isset($p['total_picked_qty']) ? $p['total_picked_qty'] : ''); ?></td>
                    <td><?php echo h(isset($p['status']) ? $p['status'] : ''); ?></td>
                </tr>
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
