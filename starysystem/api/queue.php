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
        if ($p === '') continue;
        if (!preg_match('/^\d+$/', $p)) continue;
        $out[] = (int)$p;
    }

    return array_values(array_unique($out));
}

try {
    $db = Db::mysql($cfg);

    $statusList = parse_status_list((string)($_GET['status'] ?? ''));
    $station    = trim((string)($_GET['station'] ?? ''));
    $packer     = trim((string)($_GET['packer'] ?? ''));
    $q          = trim((string)($_GET['q'] ?? ''));
    $dateFrom   = norm_dt($_GET['date_from'] ?? null);
    $dateTo     = norm_dt($_GET['date_to'] ?? null);
    $stale      = ((string)($_GET['stale'] ?? '') === '1');

    $limit  = (int)($_GET['limit'] ?? 200);
    $offset = (int)($_GET['offset'] ?? 0);

    if ($limit < 1) $limit = 200;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $staleMin = env_int('PACKING_STALE_MIN', 30);
    if ($staleMin < 1) $staleMin = 30;

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

    if ($station !== '') {
        $where[] = "station LIKE :station";
        $bind[':station'] = '%' . $station . '%';
    }

    if ($packer !== '') {
        $where[] = "packer LIKE :packer";
        $bind[':packer'] = '%' . $packer . '%';
    }

    if ($q !== '') {
        $where[] = "(order_code LIKE :q OR subiekt_doc_no LIKE :q)";
        $bind[':q'] = '%' . $q . '%';
    }

    // zakres czasu zależny od statusu (domyślnie imported_at)
    $dateCol = 'imported_at';
    if (count($statusList) === 1) {
        $s = (int)$statusList[0];
        if ($s === 40) $dateCol = 'pack_started_at';
        elseif ($s === 50 || $s === 60) $dateCol = 'pack_ended_at';
        elseif ($s === 10) $dateCol = 'imported_at';
    }

    if ($dateFrom !== null) {
        $where[] = "($dateCol IS NOT NULL AND $dateCol >= :df)";
        $bind[':df'] = $dateFrom;
    }

    if ($dateTo !== null) {
        $where[] = "($dateCol IS NOT NULL AND $dateCol <= :dt)";
        $bind[':dt'] = $dateTo;
    }

    // "zawieszone" = status=40 i czas od OSTATNIEGO heartbeat (lub startu jeśli brak) >= staleMin
    if ($stale) {
        $where[] = "status = 40
                    AND pack_started_at IS NOT NULL
                    AND TIMESTAMPDIFF(MINUTE, COALESCE(pack_heartbeat_at, pack_started_at), NOW()) >= :stale";
        $bind[':stale'] = $staleMin;
    }

    $w = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    // sortowanie wg statusu
    $orderBy = "ORDER BY imported_at DESC";
    if (count($statusList) === 1) {
        $s = (int)$statusList[0];
        if ($s === 10) $orderBy = "ORDER BY imported_at ASC";
        elseif ($s === 40) $orderBy = "ORDER BY pack_started_at ASC";
        elseif ($s === 50 || $s === 60) $orderBy = "ORDER BY pack_ended_at DESC";
    }

    // total (do paginacji/UI)
    $sqlCount = "SELECT COUNT(*) FROM pak_orders $w";
    $stCount = $db->prepare($sqlCount);
    $stCount->execute($bind);
    $total = (int)$stCount->fetchColumn();

    $sql = "
      SELECT
        order_code,
        subiekt_doc_no,
        status,
        station,
        packer,
        imported_at,
        pack_started_at,
        pack_heartbeat_at,
        pack_ended_at,
        CASE
          WHEN pack_started_at IS NOT NULL AND pack_ended_at IS NOT NULL
          THEN TIMESTAMPDIFF(SECOND, pack_started_at, pack_ended_at)
          ELSE NULL
        END AS packing_seconds,
        CASE
          WHEN status = 40 AND pack_started_at IS NOT NULL
          THEN TIMESTAMPDIFF(SECOND, COALESCE(pack_heartbeat_at, pack_started_at), NOW())
          ELSE NULL
        END AS age_seconds
      FROM pak_orders
      $w
      $orderBy
      LIMIT $limit OFFSET $offset
    ";

    $st = $db->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    Resp::json([
        'ok'        => true,
        'items'     => $rows,
        'limit'     => $limit,
        'offset'    => $offset,
        'total'     => $total,
        'stale_min' => $staleMin,
    ], 200);

} catch (\Throwable $e) {
    Resp::bad('queue error: ' . $e->getMessage(), 500);
}
