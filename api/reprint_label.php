<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';
require_once __DIR__ . '/../app/Lib/PakEvents.php';

require_once __DIR__ . '/../app/Lib/ZebraTcpSender.php';
require_once __DIR__ . '/../app/Lib/CupsPrintSender.php';
require_once __DIR__ . '/../app/Lib/PrintLogs.php';
require_once __DIR__ . '/../app/Services/LabelService.php';

@session_start();

function normalize_order_code(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (preg_match('/\*(B\d+|E\d+|\d+)\*/i', $raw, $m)) $raw = (string)$m[1];
    $raw = strtoupper(trim($raw));
    if ($raw === '' || strlen($raw) > 32) return null;
    if (!preg_match('/^[0-9A-Z]+$/', $raw)) return null;
    return $raw;
}

function read_post_value(string $key): string {
    if (isset($_POST[$key])) return (string)$_POST[$key];
    $raw = file_get_contents('php://input');
    if ($raw) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j[$key])) return (string)$j[$key];
    }
    return '';
}

function is_manager(string $packer): bool {
    $packerUp = strtoupper(trim($packer));
    if ($packerUp === 'KIEROWNIK') return true;

    $mgr = (string)getenv('MANAGERS');
    if ($mgr === '') return false;

    $list = array_values(array_filter(array_map('trim', explode(',', $mgr))));
    foreach ($list as $m) {
        if (strtoupper($m) === $packerUp) return true;
    }
    return false;
}

function print_backend_name(): string {
    $b = strtolower(trim((string)getenv('PRINT_BACKEND')));
    return ($b === 'cups') ? 'cups' : 'socket';
}

try {
    $packer    = (string)($_SESSION['packer'] ?? '');
    $station   = (string)($_SESSION['station_name'] ?? ($_SESSION['station'] ?? ''));
    $stationNo = (int)($_SESSION['station_no'] ?? 0);
    $printerIp = (string)($_SESSION['printer_ip'] ?? '');

    if ($packer === '' || $station === '') {
        Resp::json(['ok'=>false,'error'=>'Brak sesji packer/station'], 200);
    }

    $isMgr = is_manager($packer);
    $printBackend = print_backend_name();

    $orderCode = normalize_order_code(read_post_value('order_code'));
    if ($orderCode === null) {
        Resp::json(['ok'=>false,'error'=>'Brak lub niepoprawny order_code'], 200);
    }

    // manager może wskazać inną drukarkę (socket) lub kolejkę (cups), zwykły pakowacz NIE
    $overrideQueue = '';
    if ($isMgr) {
        $overrideIp = trim(read_post_value('printer_ip'));
        if ($overrideIp !== '') $printerIp = $overrideIp;

        $overrideQueue = trim(read_post_value('printer_queue'));
        if ($overrideQueue !== '' && !CupsPrintSender::isValidQueueName($overrideQueue)) {
            Resp::json(['ok'=>false,'error'=>'Niepoprawna nazwa printer_queue'], 200);
        }
    }

    if ($printBackend === 'socket' && $printerIp === '') {
        Resp::json(['ok'=>false,'error'=>'Brak skonfigurowanej drukarki (printer_ip)'], 200);
    }

    $db = Db::mysql($cfg);

    $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
    $st->execute([':c'=>$orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        Resp::json(['ok'=>false,'error'=>'Nie znaleziono zamówienia','order_code'=>$orderCode], 200);
    }

    $status = (int)($row['status'] ?? 0);
    $printCount = isset($row['print_count']) ? (int)$row['print_count'] : 0;

    // Zwykły pakowacz: tylko swoje stanowisko + tylko podczas pakowania + tylko 1 reprint
    if (!$isMgr) {
        if ((string)($row['packer'] ?? '') !== $packer || (string)($row['station'] ?? '') !== $station) {
            Resp::json([
                'ok'=>false,
                'error'=>'Reprint tylko na własnym stanowisku i własnym użytkowniku.',
                'hint'=>'To zamówienie jest przypisane do: '.(($row['packer'] ?? '?').' / '.($row['station'] ?? '?'))
            ], 200);
        }
        if ($status !== 40) {
            Resp::json([
                'ok'=>false,
                'error'=>'Reprint dla pakowacza tylko podczas pakowania (status=40).',
                'hint'=>'Aktualny status: '.$status
            ], 200);
        }
        // “1 reprint”: wymagamy, żeby był już 1 udany druk
        if ($printCount !== 1) {
            Resp::json([
                'ok'=>false,
                'error'=>'Dostępny jest tylko 1 reprint (dla pakowacza).',
                'hint'=>'Aktualny print_count=' . $printCount
            ], 200);
        }
    }

    // Pobierz etykietę (PDF/ZPL dla CUPS, ZPL dla socket)
    try {
        if ($printBackend === 'cups') {
            $lab = LabelService::getLabelForPrint($db, $cfg, $orderCode);
        } else {
            $lab = LabelService::getLabel($db, $cfg, $orderCode);
        }
    } catch (\Throwable $e) {
        PakEvents::log($db, $orderCode, 'REPRINT_FAIL', $packer, $station, 'label: '.$e->getMessage());
        Resp::json([
            'ok'=>false,
            'error'=>'PRZEWOZÓWKA: '.$e->getMessage(),
            'hint'=>implode(' ', [
                'Sprawdź czy importer uzupełnił dane do etykiety:',
                'ALLEGRO(source=U): allegro_parcel_id;',
                'BASELINKER(source=B/E): courier_code + bl_package_id (albo nr_nadania);',
                'INPOST: nr_nadania jako shipment_id (liczba).',
            ]),
        ], 200);
    }

    $payloadFormat = strtoupper((string)($lab['format'] ?? ''));
    if ($payloadFormat === 'TXT') $payloadFormat = 'ZPL';
    $payload = (string)($lab['data'] ?? '');

    if ($payload === '') {
        PakEvents::log($db, $orderCode, 'REPRINT_FAIL', $packer, $station, 'empty payload');
        Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: pusta etykieta'], 200);
    }

    $res = null;
    $printerTargetForLog = $printerIp;
    $usedQueue = null;

    if ($printBackend === 'cups') {
        try {
            if ($isMgr && $overrideQueue !== '') {
                $usedQueue = $overrideQueue;
            } else {
                $usedQueue = CupsPrintSender::resolveQueueForStation($station, $payloadFormat);
            }
        } catch (\Throwable $e) {
            PakEvents::log($db, $orderCode, 'REPRINT_FAIL', $packer, $station, 'cups queue: '.$e->getMessage());
            Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: '.$e->getMessage()], 200);
        }

        $printerTargetForLog = 'cups:' . $usedQueue;
        $res = CupsPrintSender::send($usedQueue, $payload, $payloadFormat, 'REPRINT-'.$orderCode);
    } else {
        if ($payloadFormat !== 'ZPL') {
            PakEvents::log($db, $orderCode, 'REPRINT_FAIL', $packer, $station, 'format != ZPL');
            Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: etykieta nie jest w ZPL (drukujemy tylko ZPL)'], 200);
        }

        $res = ZebraTcpSender::send($printerIp, $payload, 9100, 2500, 2);
    }

    PrintLogs::log($db, [
        'type' => 'LABEL_REPRINT',
        'station_name' => $station,
        'station_no' => $stationNo ?: null,
        'order_code' => $orderCode,
        'printer_ip' => (string)$printerTargetForLog,
        'success' => (bool)($res['ok'] ?? false),
        'duration_ms' => (int)($res['duration_ms'] ?? 0),
        'error_code' => $res['error_code'] ?? null,
        'error_message' => $res['error_message'] ?? null,
        'zpl_bytes' => strlen($payload), // historyczna nazwa kolumny; trzymamy bytes payloadu (PDF/ZPL)
    ]);

    if (empty($res['ok'])) {
        PakEvents::log($db, $orderCode, 'REPRINT_FAIL', $packer, $station, (string)($res['error_message'] ?? 'print fail'));
        Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: błąd druku: '.($res['error_message'] ?? 'unknown'),'print'=>$res], 200);
    }

    // zapisz ponowny druk
    $updP = $db->prepare("
        UPDATE pak_orders
        SET printed_at = NOW(),
            printed_by = :p,
            print_count = print_count + 1
        WHERE order_code = :c
        LIMIT 1
    ");
    $updP->execute([':p'=>$packer, ':c'=>$orderCode]);

    $okMsg = ($printBackend === 'cups')
        ? ('reprint(' . ($isMgr ? 'manager' : 'packer') . ',cups' . (!empty($res['job_id']) ? ' '.$res['job_id'] : '') . ')')
        : ($isMgr ? 'reprint(manager)' : 'reprint(packer)');
    PakEvents::log($db, $orderCode, 'REPRINT_OK', $packer, $station, $okMsg);

    $st2 = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
    $st2->execute([':c'=>$orderCode]);

    $out = [
        'ok'=>true,
        'order'=>$st2->fetch(PDO::FETCH_ASSOC),
        'printer_ip'=>$printerTargetForLog,
        'print'=>$res,
        'backend'=>$printBackend,
        'payload_format'=>$payloadFormat,
    ];
    if ($usedQueue !== null) $out['printer_queue'] = $usedQueue;

    Resp::json($out, 200);

} catch (\Throwable $e) {
    Resp::json(['ok'=>false,'error'=>'reprint_label exception: '.$e->getMessage()], 200);
}
