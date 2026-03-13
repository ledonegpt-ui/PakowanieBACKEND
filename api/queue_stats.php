<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Lib/Db.php';
require_once __DIR__ . '/../app/Lib/Resp.php';

function norm_dt(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s . ' 00:00:00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) return $s . ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s)) return $s;
    return null;
}

function parse_status_list(string $s): array {
    $s = trim($s);
    if ($s === '') return [];
    $out = [];
    foreach (explode(',', $s) as $p) {
        $p = trim($p);
        if ($p === '' || !preg_match('/^\d+$/', $p)) continue;
        $out[] = (int)$p;
    }
    return array_values(array_unique($out));
}

function build_queue_filter_parts(
    array $statusList,
    string $station,
    string $packer,
    string $q,
    ?string $dateFrom,
    ?string $dateTo,
    bool $stale,
    int $staleMin
): array {
    $where = [];
    $bind = [];

    if ($statusList) {
        $ph = [];
        foreach ($statusList as $i => $v) {
            $k = ':st' . $i;
            $ph[] = $k;
            $bind[$k] = $v;
        }
        $where[] = "status IN (" . implode(',', $ph) . ")";
    }

    if ($station !== '') { $where[] = "station LIKE :station"; $bind[':station'] = '%' . $station . '%'; }
    if ($packer  !== '') { $where[] = "packer LIKE :packer";   $bind[':packer']  = '%' . $packer  . '%'; }

    if ($q !== '') {
        $where[] = "(order_code LIKE :q OR subiekt_doc_no LIKE :q)";
        $bind[':q'] = '%' . $q . '%';
    }

    $dateCol = 'imported_at';
    if (count($statusList) === 1) {
        $s = (int)$statusList[0];
        if ($s === 40) $dateCol = 'pack_started_at';
        elseif ($s === 50 || $s === 60) $dateCol = 'pack_ended_at';
        elseif ($s === 10) $dateCol = 'imported_at';
    }

    if ($dateFrom) { $where[] = "($dateCol IS NOT NULL AND $dateCol >= :df)"; $bind[':df'] = $dateFrom; }
    if ($dateTo)   { $where[] = "($dateCol IS NOT NULL AND $dateCol <= :dt)"; $bind[':dt'] = $dateTo; }

    if ($stale) {
        $where[] = "status = 40
                    AND pack_started_at IS NOT NULL
                    AND TIMESTAMPDIFF(MINUTE, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) >= :stale";
        $bind[':stale'] = $staleMin;
    }

    return [$where, $bind, $dateCol];
}

function build_report_range(?string $dateFrom, ?string $dateTo): array {
    // Domyślnie raport "dziś", jeśli user nie poda zakresu
    if ($dateFrom === null && $dateTo === null) {
        return [date('Y-m-d 00:00:00'), date('Y-m-d H:i:s')];
    }
    return [$dateFrom, $dateTo];
}

function median_seconds(PDO $db, string $whereSql, array $bind): ?int {
    // Lekki median helper (limit bezpieczeństwa)
    $sql = "
      SELECT TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at) AS s
      FROM pak_orders
      $whereSql
      AND status = 50
      AND pack_started_at IS NOT NULL
      AND pack_ended_at IS NOT NULL
      ORDER BY s ASC
      LIMIT 5000
    ";
    $st = $db->prepare($sql);
    $st->execute($bind);
    $vals = [];
    while (($v = $st->fetchColumn()) !== false) {
        $iv = (int)$v;
        if ($iv >= 0) $vals[] = $iv;
    }
    $n = count($vals);
    if ($n === 0) return null;
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) return (int)$vals[$mid];
    return (int)round(($vals[$mid - 1] + $vals[$mid]) / 2);
}

try {
    $db = Db::mysql($cfg);

    $statusList = parse_status_list((string)($_GET['status'] ?? ''));
    $station = trim((string)($_GET['station'] ?? ''));
    $packer  = trim((string)($_GET['packer'] ?? ''));
    $q       = trim((string)($_GET['q'] ?? ''));
    $dateFrom = norm_dt($_GET['date_from'] ?? null);
    $dateTo   = norm_dt($_GET['date_to'] ?? null);
    $stale    = (string)($_GET['stale'] ?? '') === '1';

    $staleLimit = (int)($_GET['stale_limit'] ?? 8);
    $activeLimit = (int)($_GET['active_limit'] ?? 8);
    if ($staleLimit < 1) $staleLimit = 8;
    if ($staleLimit > 30) $staleLimit = 30;
    if ($activeLimit < 1) $activeLimit = 8;
    if ($activeLimit > 30) $activeLimit = 30;

    $staleMin = env_int('PACKING_STALE_MIN', 30);
    if ($staleMin < 1) $staleMin = 30;

    // ---- Summary LIVE (bez q i bez dat; opcjonalnie filtr station/packer)
    $liveWhere = [];
    $liveBind = [];
    if ($station !== '') { $liveWhere[] = "station LIKE :l_station"; $liveBind[':l_station'] = '%' . $station . '%'; }
    if ($packer  !== '') { $liveWhere[] = "packer LIKE :l_packer";   $liveBind[':l_packer']  = '%' . $packer  . '%'; }
    $liveW = $liveWhere ? ('WHERE ' . implode(' AND ', $liveWhere)) : '';

    $sqlLive = "
      SELECT
        SUM(status = 10) AS new_count,
        SUM(status = 40) AS packing_count,
        SUM(status = 50) AS packed_total,
        SUM(status = 60) AS cancelled_total,
        SUM(
          status = 40
          AND pack_started_at IS NOT NULL
          AND TIMESTAMPDIFF(MINUTE, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) >= {$staleMin}
        ) AS stale_count,
        SUM(status = 50 AND DATE(pack_ended_at) = CURDATE()) AS packed_today,
        SUM(status = 60 AND DATE(pack_ended_at) = CURDATE()) AS cancelled_today,
        COUNT(DISTINCT CASE WHEN status = 40 AND packer IS NOT NULL AND packer <> '' THEN packer END) AS active_packers,
        COUNT(DISTINCT CASE WHEN status = 40 AND station IS NOT NULL AND station <> '' THEN station END) AS active_stations
      FROM pak_orders
      $liveW
    ";
    $st = $db->prepare($sqlLive);
    $st->execute($liveBind);
    $live = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // ---- Summary FILTERED (dokładnie jak queue)
    [$where, $bind] = build_queue_filter_parts($statusList, $station, $packer, $q, $dateFrom, $dateTo, $stale, $staleMin);

    $w = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sqlFiltered = "
      SELECT
        COUNT(*) AS matching_total,
        SUM(status = 10) AS matching_new,
        SUM(status = 40) AS matching_packing,
        SUM(status = 50) AS matching_packed,
        SUM(status = 60) AS matching_cancelled,
        SUM(
          status = 40
          AND pack_started_at IS NOT NULL
          AND TIMESTAMPDIFF(MINUTE, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) >= {$staleMin}
        ) AS matching_stale
      FROM pak_orders
      $w
    ";
    $st = $db->prepare($sqlFiltered);
    $st->execute($bind);
    $filtered = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // ---- Raport wydajności (domyślnie dziś), bazuje na pack_ended_at
    [$reportFrom, $reportTo] = build_report_range($dateFrom, $dateTo);

    $perfWhere = ["pack_ended_at IS NOT NULL"];
    $perfBind = [];

    if ($reportFrom !== null) { $perfWhere[] = "pack_ended_at >= :pf"; $perfBind[':pf'] = $reportFrom; }
    if ($reportTo   !== null) { $perfWhere[] = "pack_ended_at <= :pt"; $perfBind[':pt'] = $reportTo; }

    if ($station !== '') { $perfWhere[] = "station LIKE :ps"; $perfBind[':ps'] = '%' . $station . '%'; }
    if ($packer  !== '') { $perfWhere[] = "packer LIKE :pp";  $perfBind[':pp'] = '%' . $packer  . '%'; }

    $perfW = 'WHERE ' . implode(' AND ', $perfWhere);

    $sqlPerf = "
      SELECT
        SUM(status = 50) AS packed_count,
        SUM(status = 60) AS cancelled_count,
        AVG(CASE WHEN status = 50 AND pack_started_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at) END) AS avg_packing_seconds,
        SUM(CASE WHEN status = 50 AND pack_started_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at) ELSE 0 END) AS sum_packing_seconds
      FROM pak_orders
      $perfW
    ";
    $st = $db->prepare($sqlPerf);
    $st->execute($perfBind);
    $perf = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $median = median_seconds($db, $perfW, $perfBind);

    // ---- Top pakowacze (status=50)
    $sqlPackers = "
      SELECT
        COALESCE(NULLIF(TRIM(packer), ''), '(brak)') AS packer,
        COUNT(*) AS packed,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at))) AS avg_seconds,
        SUM(TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at)) AS sum_seconds
      FROM pak_orders
      $perfW
        AND status = 50
        AND pack_started_at IS NOT NULL
      GROUP BY COALESCE(NULLIF(TRIM(packer), ''), '(brak)')
      ORDER BY packed DESC, avg_seconds ASC
      LIMIT 15
    ";
    $st = $db->prepare($sqlPackers);
    $st->execute($perfBind);
    $packersTop = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ---- Top stanowiska (status=50)
    $sqlStations = "
      SELECT
        COALESCE(NULLIF(TRIM(station), ''), '(brak)') AS station,
        COUNT(*) AS packed,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at))) AS avg_seconds,
        SUM(TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at)) AS sum_seconds
      FROM pak_orders
      $perfW
        AND status = 50
        AND pack_started_at IS NOT NULL
      GROUP BY COALESCE(NULLIF(TRIM(station), ''), '(brak)')
      ORDER BY packed DESC, avg_seconds ASC
      LIMIT 15
    ";
    $st = $db->prepare($sqlStations);
    $st->execute($perfBind);
    $stationsTop = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ---- Aktywne PACKING teraz (live)
    $activeWhere = ["status = 40", "pack_started_at IS NOT NULL"];
    $activeBind = [];
    if ($station !== '') { $activeWhere[] = "station LIKE :as"; $activeBind[':as'] = '%' . $station . '%'; }
    if ($packer  !== '') { $activeWhere[] = "packer LIKE :ap";  $activeBind[':ap'] = '%' . $packer  . '%'; }

    $activeW = 'WHERE ' . implode(' AND ', $activeWhere);
    $sqlActive = "
      SELECT
        order_code, subiekt_doc_no, packer, station, pack_started_at, pack_heartbeat_at,
        TIMESTAMPDIFF(SECOND, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) AS age_seconds,
        TIMESTAMPDIFF(SECOND, pack_started_at, NOW()) AS total_packing_seconds
      FROM pak_orders
      $activeW
      ORDER BY age_seconds DESC, pack_started_at ASC
      LIMIT {$activeLimit}
    ";
    $st = $db->prepare($sqlActive);
    $st->execute($activeBind);
    $activeNow = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ---- Zawieszone TOP (live)
    $staleWhere = [
        "status = 40",
        "pack_started_at IS NOT NULL",
        "TIMESTAMPDIFF(MINUTE, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) >= {$staleMin}"
    ];
    $staleBind = [];
    if ($station !== '') { $staleWhere[] = "station LIKE :ss"; $staleBind[':ss'] = '%' . $station . '%'; }
    if ($packer  !== '') { $staleWhere[] = "packer LIKE :sp";  $staleBind[':sp'] = '%' . $packer  . '%'; }

    $staleW = 'WHERE ' . implode(' AND ', $staleWhere);
    $sqlStaleTop = "
      SELECT
        order_code, subiekt_doc_no, packer, station, pack_started_at, pack_heartbeat_at,
        TIMESTAMPDIFF(SECOND, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) AS age_seconds
      FROM pak_orders
      $staleW
      ORDER BY age_seconds DESC, pack_started_at ASC
      LIMIT {$staleLimit}
    ";
    $st = $db->prepare($sqlStaleTop);
    $st->execute($staleBind);
    $staleTop = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ---- Eventy managera (domyślnie dziś / zakres raportu)
    $evWhere = ["1=1"];
    $evBind = [];
    if ($reportFrom !== null) { $evWhere[] = "created_at >= :ef"; $evBind[':ef'] = $reportFrom; }
    if ($reportTo   !== null) { $evWhere[] = "created_at <= :et"; $evBind[':et'] = $reportTo; }
    if ($station !== '') { $evWhere[] = "station LIKE :es"; $evBind[':es'] = '%' . $station . '%'; }
    if ($packer  !== '') { $evWhere[] = "packer LIKE :ep";  $evBind[':ep'] = '%' . $packer  . '%'; }

    $evW = 'WHERE ' . implode(' AND ', $evWhere);
    $sqlEvents = "
      SELECT
        SUM(event_type = 'UNLOCK') AS unlock_count,
        SUM(event_type = 'START') AS start_count,
        SUM(event_type = 'FINISH') AS finish_count,
        SUM(event_type = 'FINISH' AND message LIKE '%force_finish%') AS force_finish_count,
        SUM(event_type = 'CANCEL') AS cancel_count,
        SUM(event_type = 'REOPEN') AS reopen_count,
        SUM(event_type = 'PRINT_FAIL') AS print_fail_count,
        SUM(event_type = 'PRINT_OK') AS print_ok_count
      FROM pak_events
      $evW
    ";
    $st = $db->prepare($sqlEvents);
    $st->execute($evBind);
    $events = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    Resp::json([
        'ok' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'stale_min' => $staleMin,
        'report_range' => [
            'from' => $reportFrom,
            'to' => $reportTo,
            'default_today' => ($dateFrom === null && $dateTo === null),
        ],
        'summary_live' => [
            'new_count'        => (int)($live['new_count'] ?? 0),
            'packing_count'    => (int)($live['packing_count'] ?? 0),
            'packed_total'     => (int)($live['packed_total'] ?? 0),
            'cancelled_total'  => (int)($live['cancelled_total'] ?? 0),
            'stale_count'      => (int)($live['stale_count'] ?? 0),
            'packed_today'     => (int)($live['packed_today'] ?? 0),
            'cancelled_today'  => (int)($live['cancelled_today'] ?? 0),
            'active_packers'   => (int)($live['active_packers'] ?? 0),
            'active_stations'  => (int)($live['active_stations'] ?? 0),
        ],
        'summary_filtered' => [
            'matching_total'      => (int)($filtered['matching_total'] ?? 0),
            'matching_new'        => (int)($filtered['matching_new'] ?? 0),
            'matching_packing'    => (int)($filtered['matching_packing'] ?? 0),
            'matching_packed'     => (int)($filtered['matching_packed'] ?? 0),
            'matching_cancelled'  => (int)($filtered['matching_cancelled'] ?? 0),
            'matching_stale'      => (int)($filtered['matching_stale'] ?? 0),
        ],
        'performance' => [
            'packed_count'        => (int)($perf['packed_count'] ?? 0),
            'cancelled_count'     => (int)($perf['cancelled_count'] ?? 0),
            'avg_packing_seconds' => isset($perf['avg_packing_seconds']) && $perf['avg_packing_seconds'] !== null ? (int)round((float)$perf['avg_packing_seconds']) : null,
            'sum_packing_seconds' => (int)($perf['sum_packing_seconds'] ?? 0),
            'median_packing_seconds' => $median,
        ],
        'packers_top' => $packersTop,
        'stations_top' => $stationsTop,
        'active_now' => $activeNow,
        'stale_top' => $staleTop,
        'events' => [
            'unlock_count'      => (int)($events['unlock_count'] ?? 0),
            'start_count'       => (int)($events['start_count'] ?? 0),
            'finish_count'      => (int)($events['finish_count'] ?? 0),
            'force_finish_count'=> (int)($events['force_finish_count'] ?? 0),
            'cancel_count'      => (int)($events['cancel_count'] ?? 0),
            'reopen_count'      => (int)($events['reopen_count'] ?? 0),
            'print_fail_count'  => (int)($events['print_fail_count'] ?? 0),
            'print_ok_count'    => (int)($events['print_ok_count'] ?? 0),
        ],
    ], 200);

} catch (\Throwable $e) {
    Resp::bad('queue_stats error: ' . $e->getMessage(), 500);
}
