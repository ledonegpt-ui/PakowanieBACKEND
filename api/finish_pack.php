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
    $p = strtoupper(trim($packer));
    if ($p === 'KIEROWNIK') return true;
    $mgr = (string)getenv('MANAGERS');
    if ($mgr === '') return false;
    foreach (array_values(array_filter(array_map('trim', explode(',', $mgr)))) as $m) {
        if (strtoupper($m) === $p) return true;
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

    $orderCode = normalize_order_code(read_post_value('order_code'));
    if ($orderCode === null) {
        Resp::json(['ok'=>false,'error'=>'Brak lub niepoprawny order_code'], 200);
    }

    // do_print=1 lub AUTO_PRINT_ON_FINISH=1
    $doPrint = (read_post_value('do_print') === '1');
    if (function_exists('env_bool')) {
        $doPrint = $doPrint || env_bool('AUTO_PRINT_ON_FINISH', false);
    }

    // skip_print=1 -> ZAWSZE bez druku
    $skipPrint = (read_post_value('skip_print') === '1');
    if ($skipPrint) $doPrint = false;

    // force_finish=1 -> tylko manager
    $forceFinish = (read_post_value('force_finish') === '1');
    if ($forceFinish && !$isMgr) $forceFinish = false;

    // dla bezpieczeństwa: jeśli force_finish, to i tak nie drukujemy (reprint jest osobno)
    if ($forceFinish) $doPrint = false;

    $db = Db::mysql($cfg);

    $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
    $st->execute([':c'=>$orderCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) Resp::json(['ok'=>false,'error'=>'Nie znaleziono zamówienia','order_code'=>$orderCode], 200);

    $status = (int)($row['status'] ?? 0);
    if ($status === 50) Resp::json(['ok'=>false,'error'=>'Zamówienie już spakowane','order'=>$row], 200);
    if ($status !== 40) Resp::json(['ok'=>false,'error'=>'Nie rozpoczęto pakowania (status != 40)','order'=>$row], 200);

    // Normalnie: tylko swoje stanowisko i user.
    // Manager + force_finish: może zakończyć cudze.
    if (!$forceFinish) {
        if ((string)$row['packer'] !== $packer || (string)$row['station'] !== $station) {
            Resp::json(['ok'=>false,'error'=>'Inny pakowacz rozpoczął','order'=>$row], 200);
        }
    }

    $printBackend = print_backend_name();

    $printCount = isset($row['print_count']) ? (int)$row['print_count'] : 0;
    $printInfo  = [
        'attempted'=>false,
        'skipped'=>null,
        'skip_print'=>$skipPrint,
        'force_finish'=>$forceFinish,
        'backend'=>$printBackend
    ];

    // === 1) PRINT (tylko gdy wymagany i tylko raz) ===
    if ($doPrint && $printCount <= 0) {
        $printInfo['attempted'] = true;

        if ($printBackend === 'socket' && $printerIp === '') {
            PakEvents::log($db, $orderCode, 'PRINT_FAIL', $packer, $station, 'no printer_ip');
            Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: brak skonfigurowanej drukarki (printer_ip) dla stanowiska'], 200);
        }

        try {
            if ($printBackend === 'cups') {
                $lab = LabelService::getLabelForPrint($db, $cfg, $orderCode); // PDF lub ZPL
            } else {
                $lab = LabelService::getLabel($db, $cfg, $orderCode); // legacy -> ZPL
            }
        } catch (\Throwable $e) {
            PakEvents::log($db, $orderCode, 'PRINT_FAIL', $packer, $station, 'label: '.$e->getMessage());
            Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: '.$e->getMessage()], 200);
        }

        $payloadFormat = strtoupper((string)($lab['format'] ?? ''));
        if ($payloadFormat === 'TXT') $payloadFormat = 'ZPL';
        $payload = (string)($lab['data'] ?? '');

        if ($payload === '') {
            PakEvents::log($db, $orderCode, 'PRINT_FAIL', $packer, $station, 'empty payload');
            Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: pusta etykieta'], 200);
        }

        $printerTargetForLog = $printerIp;
        $res = null;

        if ($printBackend === 'cups') {
            try {
                $queue = CupsPrintSender::resolveQueueForStation($station, $payloadFormat);
            } catch (\Throwable $e) {
                PakEvents::log($db, $orderCode, 'PRINT_FAIL', $packer, $station, 'cups queue: '.$e->getMessage());
                Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: '.$e->getMessage()], 200);
            }

            $printerTargetForLog = 'cups:' . $queue;
            $res = CupsPrintSender::send($queue, $payload, $payloadFormat, 'PACK-'.$orderCode);

            $printInfo['queue'] = $queue;
            $printInfo['payload_format'] = $payloadFormat;
            if (!empty($res['job_id'])) $printInfo['cups_job_id'] = (string)$res['job_id'];
        } else {
            if ($payloadFormat !== 'ZPL') {
                PakEvents::log($db, $orderCode, 'PRINT_FAIL', $packer, $station, 'format != ZPL');
                Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: etykieta nie jest w ZPL (drukujemy tylko ZPL)'], 200);
            }

            $res = ZebraTcpSender::send($printerIp, $payload, 9100, 2500, 2);
            $printInfo['payload_format'] = 'ZPL';
        }

        PrintLogs::log($db, [
            'type' => 'LABEL',
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
            PakEvents::log($db, $orderCode, 'PRINT_FAIL', $packer, $station, (string)($res['error_message'] ?? 'print fail'));
            Resp::json(['ok'=>false,'error'=>'PRZEWOZÓWKA: błąd druku: '.($res['error_message'] ?? 'unknown'),'print'=>$res], 200);
        }

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
            ? ('finish->print(cups queued' . (!empty($res['job_id']) ? ' '.$res['job_id'] : '') . ')')
            : 'finish->print';
        PakEvents::log($db, $orderCode, 'PRINT_OK', $packer, $station, $okMsg);

        $printInfo['result'] = $res;
    } elseif ($doPrint && $printCount > 0) {
        $printInfo['skipped'] = 'already_printed';
    } elseif ($skipPrint) {
        $printInfo['skipped'] = 'skip_print';
    } elseif ($forceFinish) {
        $printInfo['skipped'] = 'force_finish_no_print';
    }

    // === 2) FINISH ===
    if ($forceFinish) {
        $upd = $db->prepare("
            UPDATE pak_orders
            SET status = 50,
                pack_ended_at = NOW()
            WHERE order_code = :c
              AND status = 40
        ");
        $upd->execute([':c'=>$orderCode]);
    } else {
        $upd = $db->prepare("
            UPDATE pak_orders
            SET status = 50,
                pack_ended_at = NOW()
            WHERE order_code = :c
              AND status = 40
              AND packer = :packer
              AND station = :station
        ");
        $upd->execute([':c'=>$orderCode, ':packer'=>$packer, ':station'=>$station]);
    }

    if ($upd->rowCount() === 1) {
        PakEvents::log($db, $orderCode, 'FINISH', $packer, $station,
            $forceFinish ? 'finish(force_finish)' : ($skipPrint ? 'finish(skip_print)' : 'finish')
        );

        $st2 = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
        $st2->execute([':c'=>$orderCode]);
        Resp::json(['ok'=>true,'order'=>$st2->fetch(PDO::FETCH_ASSOC),'print'=>$printInfo], 200);
    }

    $st3 = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
    $st3->execute([':c'=>$orderCode]);
    Resp::json(['ok'=>false,'error'=>'Nie udało się zakończyć (status zmieniony w trakcie)','order'=>$st3->fetch(PDO::FETCH_ASSOC),'print'=>$printInfo], 200);

} catch (\Throwable $e) {
    Resp::json(['ok'=>false,'error'=>'finish_pack exception: '.$e->getMessage()], 200);
}
