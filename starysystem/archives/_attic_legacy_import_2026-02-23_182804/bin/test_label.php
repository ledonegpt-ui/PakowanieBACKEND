<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Services/LabelService.php';

function baselinkerCallDebug(string $token, string $method, array $params): array
{
    $url = 'https://api.baselinker.com/connector.php';
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException('BASELINKER: curl_init failed');

    $post = http_build_query([
        'method' => $method,
        'parameters' => json_encode($params, JSON_UNESCAPED_UNICODE),
    ]);

    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['X-BLToken: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('BASELINKER: cURL error: ' . $err);
    if ($code >= 400) throw new RuntimeException('BASELINKER: HTTP ' . $code . ' ' . substr((string)$raw, 0, 500));

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException('BASELINKER: invalid JSON ' . substr((string)$raw, 0, 500));

    return $json;
}

function detectBinType(string $bin): array
{
    $trim = ltrim($bin);
    if (strncmp($trim, '%PDF', 4) === 0) return ['ext' => 'pdf', 'kind' => 'PDF'];
    if (strncmp($trim, '^XA', 3) === 0 || strpos($trim, '^FO') !== false || strpos($trim, '^GFA') !== false) {
        return ['ext' => 'zpl', 'kind' => 'ZPL'];
    }
    return ['ext' => 'bin', 'kind' => 'BIN'];
}

$orderCode = $argv[1] ?? '';
if ($orderCode === '') {
    echo "Użycie: php bin/test_label.php 1870409\n";
    exit(1);
}

$db = Db::mysql($cfg);

$st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
$st->execute([':c' => $orderCode]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    throw new RuntimeException("Nie znaleziono zamówienia: {$orderCode}");
}

$source = strtoupper(trim((string)($row['source'] ?? '')));
$courierCode = trim((string)($row['courier_code'] ?? ''));
$nrNadania = trim((string)($row['nr_nadania'] ?? ''));
$blPackageId = $row['bl_package_id'] ?? null;
$blPackageId = (is_numeric($blPackageId) && (int)$blPackageId > 0) ? (int)$blPackageId : null;

$dir = __DIR__ . '/../storage/tmp/label_debug';
@mkdir($dir, 0775, true);

$meta = [
    'order_code' => $orderCode,
    'source' => $source,
    'courier_code' => $courierCode,
    'nr_nadania' => $nrNadania,
    'bl_package_id' => $blPackageId,
    'time' => date('c'),
];

$rawSaved = null;
$rawInfo = null;

// --- DEBUG RAW BASELINKER (tylko B/E) ---
if ($source === 'B' || $source === 'E') {
    $token = trim((string)(getenv('BASELINKER_TOKEN') ?: ($cfg['baselinker']['token'] ?? '')));
    if ($token === '') {
        throw new RuntimeException('BASELINKER: brak BASELINKER_TOKEN');
    }
    if ($courierCode === '') {
        throw new RuntimeException("BASELINKER: brak courier_code dla {$orderCode}");
    }
    if ($blPackageId === null && $nrNadania === '') {
        throw new RuntimeException("BASELINKER: brak bl_package_id i nr_nadania dla {$orderCode}");
    }

    $params = ['courier_code' => strtolower($courierCode)];
    if ($blPackageId !== null) $params['package_id'] = $blPackageId;
    else $params['package_number'] = $nrNadania;

    $resp = baselinkerCallDebug($token, 'getLabel', $params);

    $respMeta = $resp;
    $b64 = (string)($respMeta['label'] ?? '');
    unset($respMeta['label']); // nie zapisujemy ogromnego base64 do meta
    $respMeta['label_b64_len'] = strlen($b64);

    if (($resp['status'] ?? '') !== 'SUCCESS') {
        $meta['baselinker'] = [
            'params' => $params,
            'response' => $respMeta,
            'error' => 'status != SUCCESS',
        ];
        file_put_contents($dir . '/label_' . $orderCode . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        throw new RuntimeException('BASELINKER getLabel error: ' . (string)($resp['error_message'] ?? 'unknown'));
    }

    $ext = strtolower(trim((string)($resp['extension'] ?? '')));
    if ($ext === '') $ext = 'bin';

    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        throw new RuntimeException('BASELINKER getLabel: niepoprawna etykieta base64');
    }

    $det = detectBinType($bin);

    // jeśli extension jest dziwne/puste, dobierz sensowne z sygnatury
    $rawExt = $ext;
    if ($rawExt === 'bin' || $rawExt === 'txt') {
        $rawExt = $det['ext'];
    }

    $rawPath = $dir . '/bl_raw_' . $orderCode . '.' . $rawExt;
    file_put_contents($rawPath, $bin);

    $rawSaved = $rawPath;
    $rawInfo = [
        'params' => $params,
        'response' => $respMeta,
        'detected_kind' => $det['kind'],
        'detected_ext' => $det['ext'],
        'raw_bytes' => strlen($bin),
        'head_hex' => strtoupper(bin2hex(substr($bin, 0, 24))),
        'head_text' => preg_replace('/[^\x20-\x7E]/', '.', substr($bin, 0, 80)),
    ];

    $meta['baselinker'] = $rawInfo;
}

// --- FINAL (to co daje LabelService / idzie na drukarkę) ---
$res = LabelService::getLabel($db, $cfg, $orderCode);
$finalBin = (string)$res['data'];
$finalFmt = strtoupper(trim((string)($res['format'] ?? '')));
$finalDet = detectBinType($finalBin);

$finalExt = strtolower($finalFmt);
if ($finalExt === '' || $finalExt === 'zpl') $finalExt = $finalDet['ext'] ?: 'zpl';
$finalPath = $dir . '/final_' . $orderCode . '.' . $finalExt;
file_put_contents($finalPath, $finalBin);

$meta['final'] = [
    'format' => $finalFmt,
    'detected_kind' => $finalDet['kind'],
    'detected_ext' => $finalDet['ext'],
    'bytes' => strlen($finalBin),
    'head_hex' => strtoupper(bin2hex(substr($finalBin, 0, 24))),
    'head_text' => preg_replace('/[^\x20-\x7E]/', '.', substr($finalBin, 0, 80)),
    'path' => $finalPath,
];

if ($rawSaved !== null) {
    $meta['baselinker']['path'] = $rawSaved;
}

$metaPath = $dir . '/label_' . $orderCode . '.meta.json';
file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "OK order={$orderCode}\n";
if ($rawSaved !== null) echo "RAW_BL saved={$rawSaved}\n";
echo "FINAL saved={$finalPath}\n";
echo "META saved={$metaPath}\n";
