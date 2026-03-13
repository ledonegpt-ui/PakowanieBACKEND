<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';

function env_val(string $key, $default = null) {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}

/**
 * Sanityzuje stringi do poprawnego UTF-8.
 * Jeśli string nie jest poprawnym UTF-8, próbujemy WIN1250 -> UTF-8.
 * Na końcu wywalamy niedozwolone sekwencje.
 */
function sanitize_utf8($v) {
    if (is_array($v)) {
        foreach ($v as $k => $vv) $v[$k] = sanitize_utf8($vv);
        return $v;
    }
    if (!is_string($v)) return $v;

    // już poprawny UTF-8?
    if (@preg_match('//u', $v) === 1) return $v;

    $try = @iconv('Windows-1250', 'UTF-8//IGNORE', $v);
    if ($try === false) $try = $v;

    // usuń resztki niepoprawnych sekwencji
    $try2 = @iconv('UTF-8', 'UTF-8//IGNORE', $try);
    return ($try2 === false) ? $try : $try2;
}

function j($v): string {
    $v = sanitize_utf8($v);

    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    // PHP 7.2 ma JSON_INVALID_UTF8_SUBSTITUTE - użyj jeśli istnieje
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;

    $out = json_encode($v, $flags);
    if ($out === false) {
        return "JSON_ENCODE_ERROR: " . json_last_error_msg() . "\n" . var_export($v, true);
    }
    return $out;
}

function hr(string $title): void {
    echo "\n==================== {$title} ====================\n";
}

function showRow($row): void {
    if (!$row) { echo "NULL\n"; return; }
    echo j($row) . "\n";
}

function showRows(array $rows, int $limit = 20): void {
    echo j(array_slice($rows, 0, $limit)) . "\n";
}

function cleanTracking(?string $s): string {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/u', '', $s);
    return $s ?: '';
}

function baselinker_call(string $token, string $method, array $params): array {
    $url = 'https://api.baselinker.com/connector.php';
    $ch = curl_init($url);
    if (!$ch) throw new RuntimeException("curl_init failed");

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

    if ($raw === false) throw new RuntimeException("BL curl error: {$err}");
    if ($code >= 400) throw new RuntimeException("BL HTTP {$code}: " . substr((string)$raw, 0, 200));

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) throw new RuntimeException("BL invalid JSON: " . substr((string)$raw, 0, 200));

    return $json;
}

$arg = $argv[1] ?? '';
if ($arg === '' || $arg === '--help' || $arg === '-h') {
    echo "Użycie:\n";
    echo "  php bin/debug.php 1872879        # EU/Firebird po ID_TRANS\n";
    echo "  php bin/debug.php EU 1872879     # jw.\n";
    echo "  php bin/debug.php B123456        # Baselinker order_id=123456 (B/E)\n";
    echo "  php bin/debug.php BL B123456     # jw.\n";
    exit(0);
}

try {
    // Try detect mode
    $mode = '';
    $val  = '';

    if (preg_match('/^\d+$/', $arg)) {
        $mode = 'EU';
        $val  = $arg;
    } elseif (preg_match('/^[BE]\d+$/', strtoupper($arg))) {
        $mode = 'BL';
        $val  = strtoupper($arg);
    } else {
        $mode = strtoupper((string)$argv[1]);
        $val  = (string)($argv[2] ?? '');
        if ($val === '') throw new RuntimeException("Brak drugiego argumentu.");
    }

    if ($mode === 'EU') {
        $id = (int)$val;
        hr("CONNECT Firebird");
        $fb = Db::firebird($cfg);
        echo "fb ok\n";

        hr("1) HEADER JOIN (t.ID = {$id}) + NR_NADANIA z TRANS_WYSYLKA");
        $sqlH = "
            SELECT
                t.ID, t.ALL_FOD_ID, t.GRUPA_UKRYJ, t.GRUPA_IDS,
                t.NR_AUKCJI, t.TYTUL_AUKCJI, t.KOD, t.ILOSC, t.KWOTA,
                k.KL_LOGIN, k.KL_DK_EMAIL,
                w.ID_TRANS, w.FORMA_WYSYLKI, w.KOSZT_WYSYLKI, w.NR_NADANIA,
                w.DATA_WYSYLKI, w.GABARYT, w.WAGA,
                w.KL_DDW_IMIENAZW, w.KL_DDW_ULICA, w.KL_DDW_MIASTO, w.KL_DDW_KOD_POCZ, w.KL_DDW_TELEFON,
                w.PKT_ODB_NAZWA, w.PKT_ODB_ID,
                p.FORMA_WPLATY, p.KWOTA_WPLATY,
                p.KL_DDF_FIRMA, p.KL_DDF_IMIENAZW, p.KL_DDF_ULICA, p.KL_DDF_MIASTO, p.KL_DDF_KOD_POCZ, p.KL_DDF_NIP
            FROM TRANSAKCJE t
            LEFT JOIN TRANS_KLIENCI k ON t.ID_KLIENT = k.ID_KLIENT
            LEFT JOIN TRANS_WYSYLKA w ON t.ID = w.ID_TRANS
            LEFT JOIN TRANS_WPLATA  p ON t.ID = p.ID_TRANS
            WHERE t.ID = ?
        ";
        $st = $fb->prepare($sqlH);
        $st->execute([$id]);
        $h = $st->fetch(PDO::FETCH_ASSOC);

        if (!$h) {
            echo "Brak rekordu TRANSAKCJE.ID={$id}\n";
            exit(0);
        }

        $h['NR_NADANIA'] = cleanTracking((string)($h['NR_NADANIA'] ?? ''));
        showRow($h);

        $gid = (string)($h['ALL_FOD_ID'] ?? '');
        if ($gid === '') $gid = (string)$id;

        hr("2) TRANS_WYSYLKA RAW (WHERE ID_TRANS = {$id})");
        $st = $fb->prepare("SELECT * FROM TRANS_WYSYLKA WHERE ID_TRANS = ?");
        $st->execute([$id]);
        $w = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($w as &$wr) $wr['NR_NADANIA'] = cleanTracking((string)($wr['NR_NADANIA'] ?? ''));
        showRows($w, 50);

        hr("3) GRUPA: wszystkie TRANSAKCJE po ALL_FOD_ID={$gid} + join do TRANS_WYSYLKA");
        $sqlG = "
            SELECT
                t.ID, t.ALL_FOD_ID, t.GRUPA_UKRYJ, t.NR_AUKCJI, t.ILOSC, t.KWOTA,
                w.ID_TRANS, w.NR_NADANIA, w.FORMA_WYSYLKI
            FROM TRANSAKCJE t
            LEFT JOIN TRANS_WYSYLKA w ON t.ID = w.ID_TRANS
            WHERE t.ALL_FOD_ID = ?
            ORDER BY t.ID ASC
        ";
        $st = $fb->prepare($sqlG);
        $st->execute([$gid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) $r['NR_NADANIA'] = cleanTracking((string)($r['NR_NADANIA'] ?? ''));
        showRows($rows, 200);

        hr("4) PODSUMOWANIE: header vs grupa");
        echo "Header ID={$id} NR_NADANIA='" . cleanTracking((string)($h['NR_NADANIA'] ?? '')) . "'\n";

        $idsWith = [];
        foreach ($rows as $r) {
            if (!empty($r['NR_NADANIA'])) {
                $idsWith[] = [
                    'ID' => (int)$r['ID'],
                    'GRUPA_UKRYJ' => (string)$r['GRUPA_UKRYJ'],
                    'NR_NADANIA' => (string)$r['NR_NADANIA'],
                ];
            }
        }
        echo "W grupie ALL_FOD_ID={$gid} NR_NADANIA mają:\n";
        echo j($idsWith) . "\n";
        exit(0);
    }

    if ($mode === 'BL') {
        $code = strtoupper($val);
        if (!preg_match('/^[BE]\d+$/', $code)) throw new RuntimeException("BL: podaj B123 lub E123");
        $oid = (int)substr($code, 1);

        $token = (string)env_val('BASELINKER_TOKEN', '');
        if ($token === '') throw new RuntimeException("Brak BASELINKER_TOKEN w ENV");

        hr("BASELINKER getOrders order_id={$oid}");
        $r1 = baselinker_call($token, 'getOrders', ['order_id'=>$oid]);
        echo j($r1) . "\n";

        hr("BASELINKER getOrderPackages order_id={$oid}");
        $r2 = baselinker_call($token, 'getOrderPackages', ['order_id'=>$oid]);
        echo j($r2) . "\n";
        exit(0);
    }

    throw new RuntimeException("Nieznany tryb: {$mode}");

} catch (Throwable $e) {
    fwrite(STDERR, "DEBUG: ERROR " . $e->getMessage() . "\n");
    exit(1);
}
