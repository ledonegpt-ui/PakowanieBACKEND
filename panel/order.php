<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/api.php';

if (!isset($_SESSION['token']) || $_SESSION['token'] === '') {
    header('Location: /GUI/index.php?redirect=' . urlencode('/panel/'));
    exit;
}

$orderCode = trim((string)($_GET['order_code'] ?? ''));
if ($orderCode === '') {
    header('Location: index.php');
    exit;
}

$apiResponse = panel_api_call('GET', '/panel/orders/' . rawurlencode($orderCode));
$data = $apiResponse['data']['order'] ?? null;

function panel_pretty_json($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return (string)json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $value;
    }

    return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Szczegóły zamówienia <?php echo panel_h($orderCode); ?></title>
<style>
body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937}
.top{background:#111827;color:#fff;padding:14px 18px}
.top a{color:#fff;text-decoration:none}
.wrap{max-width:1280px;margin:0 auto;padding:18px}
.card{background:#fff;border:1px solid #d8dee4;border-radius:10px;padding:16px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.kv{display:grid;grid-template-columns:220px 1fr;gap:8px 12px}
.small{font-size:12px;color:#6b7280}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:bold;background:#e5e7eb;color:#111827}
.badge.ok{background:#dcfce7;color:#166534}
.badge.fail{background:#fee2e2;color:#991b1b}
.badge.warn{background:#fef3c7;color:#92400e}
pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto}
h2,h3{margin-top:0}
.note{padding:10px 12px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
</style>
</head>
<body>
<div class="top">
    <strong>Szczegóły zamówienia</strong>
    &nbsp; | &nbsp;
    <a href="index.php">← Powrót do listy</a>
</div>

<div class="wrap">
<?php if (is_array($data)): ?>
    <?php
    $header = is_array($data['header'] ?? null) ? $data['header'] : array();
    $shipping = is_array($data['shipping'] ?? null) ? $data['shipping'] : array();
    $resolved = is_array($shipping['resolved'] ?? null) ? $shipping['resolved'] : array();
    $packingSession = is_array($shipping['packing_session'] ?? null) ? $shipping['packing_session'] : array();
    $package = is_array($shipping['package'] ?? null) ? $shipping['package'] : array();
    $label = is_array($shipping['label'] ?? null) ? $shipping['label'] : array();
    $latestError = is_array($shipping['latest_error'] ?? null) ? $shipping['latest_error'] : array();
    $events = is_array($shipping['events'] ?? null) ? $shipping['events'] : array();

    $labelStatus = (string)($label['label_status'] ?? '');
    $packageStatus = (string)($package['status'] ?? '');
    ?>
    <div class="card">
        <h2><?php echo panel_h($orderCode); ?></h2>
        <div class="note">
            Ten ekran diagnozuje problem z etykietą. Nie generuje etykiety i nie drukuje. Po poprawieniu danych zamówienia
            właściwe generowanie i wydruk wykonujesz z apki na stanowisku.
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h3>Podstawowe dane</h3>
            <div class="kv">
                <div>Status zamówienia</div><div><span class="badge"><?php echo panel_h((string)($header['status'] ?? '-')); ?></span></div>
                <div>Odbiorca</div><div><?php echo panel_h((string)($header['delivery_fullname'] ?? '-')); ?></div>
                <div>Adres</div><div><?php echo panel_h(trim((string)($header['delivery_address'] ?? '') . ', ' . (string)($header['delivery_postcode'] ?? '') . ' ' . (string)($header['delivery_city'] ?? ''))); ?></div>
                <div>Telefon</div><div><?php echo panel_h((string)($header['phone'] ?? '-')); ?></div>
                <div>Email</div><div><?php echo panel_h((string)($header['email'] ?? '-')); ?></div>
                <div>Delivery method</div><div><?php echo panel_h((string)($header['delivery_method'] ?? '-')); ?></div>
                <div>Carrier code</div><div><?php echo panel_h((string)($header['carrier_code'] ?? '-')); ?></div>
                <div>Courier code</div><div><?php echo panel_h((string)($header['courier_code'] ?? '-')); ?></div>
                <div>Tracking</div><div><?php echo panel_h((string)($header['tracking_number'] ?? $header['nr_nadania'] ?? '-')); ?></div>
            </div>
        </div>

        <div class="card">
            <h3>Resolve wysyłki</h3>
            <div class="kv">
                <div>Label provider</div><div><?php echo panel_h((string)($resolved['label_provider'] ?? '-')); ?></div>
                <div>Menu group</div><div><?php echo panel_h((string)($resolved['menu_group'] ?? '-')); ?></div>
                <div>Shipment type</div><div><?php echo panel_h((string)($resolved['shipment_type'] ?? '-')); ?></div>
                <div>Service code</div><div><?php echo panel_h((string)($resolved['service_code'] ?? '-')); ?></div>
                <div>Requires size</div><div><?php echo !empty($resolved['requires_size']) ? 'tak' : 'nie'; ?></div>
                <div>Package size</div><div><?php echo panel_h((string)($resolved['package_size'] ?? '-')); ?></div>
                <div>Label source</div><div><?php echo panel_h((string)($resolved['label_source'] ?? '-')); ?></div>
                <div>Package mode</div><div><?php echo panel_h((string)($data['package_mode'] ?? '-')); ?></div>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <h3>Sesja pakowania</h3>
            <?php if (!empty($packingSession)): ?>
                <div class="kv">
                    <div>ID sesji</div><div><?php echo panel_h((string)($packingSession['id'] ?? '')); ?></div>
                    <div>Status</div><div><span class="badge"><?php echo panel_h((string)($packingSession['status'] ?? '-')); ?></span></div>
                    <div>User ID</div><div><?php echo panel_h((string)($packingSession['user_id'] ?? '-')); ?></div>
                    <div>Station ID</div><div><?php echo panel_h((string)($packingSession['station_id'] ?? '-')); ?></div>
                    <div>Picking batch ID</div><div><?php echo panel_h((string)($packingSession['picking_batch_id'] ?? '-')); ?></div>
                    <div>Started</div><div><?php echo panel_h((string)($packingSession['started_at'] ?? '-')); ?></div>
                    <div>Last seen</div><div><?php echo panel_h((string)($packingSession['last_seen_at'] ?? '-')); ?></div>
                    <div>Completed</div><div><?php echo panel_h((string)($packingSession['completed_at'] ?? '-')); ?></div>
                </div>
            <?php else: ?>
                <div class="small">Brak sesji pakowania dla tego zamówienia.</div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3>Package / etykieta</h3>
            <div class="kv">
                <div>Package ID</div><div><?php echo panel_h((string)($package['id'] ?? '-')); ?></div>
                <div>Package status</div><div><span class="badge <?php echo ($packageStatus === 'ok' ? 'ok' : ($packageStatus !== '' ? 'warn' : '')); ?>"><?php echo panel_h($packageStatus !== '' ? $packageStatus : '-'); ?></span></div>
                <div>Service code</div><div><?php echo panel_h((string)($package['service_code'] ?? '-')); ?></div>
                <div>Package size code</div><div><?php echo panel_h((string)($package['package_size_code'] ?? '-')); ?></div>
                <div>Tracking</div><div><?php echo panel_h((string)($package['tracking_number'] ?? '-')); ?></div>
                <div>External shipment ID</div><div><?php echo panel_h((string)($package['external_shipment_id'] ?? '-')); ?></div>
                <div>Label status</div><div><span class="badge <?php echo ($labelStatus === 'ok' ? 'ok' : ($labelStatus !== '' ? 'fail' : '')); ?>"><?php echo panel_h($labelStatus !== '' ? $labelStatus : '-'); ?></span></div>
                <div>Label format</div><div><?php echo panel_h((string)($label['label_format'] ?? '-')); ?></div>
                <div>File token</div><div><?php echo panel_h((string)($label['file_token'] ?? '-')); ?></div>
                <div>Utworzono etykietę</div><div><?php echo panel_h((string)($label['created_at'] ?? '-')); ?></div>
            </div>
            <?php if (!empty($label['raw_response_json'])): ?>
                <div class="small" style="margin-top:12px;margin-bottom:6px">Ostatnia odpowiedź providera zapisana przy etykiecie</div>
                <pre><?php echo panel_h(panel_pretty_json($label['raw_response_json'])); ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3>Ostatni błąd generowania etykiety</h3>
        <?php if (!empty($latestError)): ?>
            <div class="kv">
                <div>Typ</div><div><span class="badge fail"><?php echo panel_h((string)($latestError['event_type'] ?? '-')); ?></span></div>
                <div>Data</div><div><?php echo panel_h((string)($latestError['created_at'] ?? '-')); ?></div>
                <div>User ID</div><div><?php echo panel_h((string)($latestError['created_by_user_id'] ?? '-')); ?></div>
                <div>Komunikat</div><div><?php echo panel_h((string)($latestError['event_message'] ?? '-')); ?></div>
            </div>
            <?php if (!empty($latestError['payload_json'])): ?>
                <div class="small" style="margin-top:12px;margin-bottom:6px">Payload błędu</div>
                <pre><?php echo panel_h(panel_pretty_json($latestError['payload_json'])); ?></pre>
            <?php endif; ?>
        <?php else: ?>
            <div class="small">Brak zapisanego zdarzenia <code>label_generation_failed</code>.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Historia zdarzeń etykiety</h3>
        <?php if (!empty($events)): ?>
            <pre><?php echo panel_h(panel_pretty_json($events)); ?></pre>
        <?php else: ?>
            <div class="small">Brak zdarzeń etykiety dla tej sesji.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Pełne dane debug</h3>
        <pre><?php echo panel_h(print_r($data, true)); ?></pre>
    </div>
<?php else: ?>
    <div class="card">
        <pre><?php echo panel_h(print_r($apiResponse, true)); ?></pre>
    </div>
<?php endif; ?>
</div>
</body>
</html>
