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

function dt_now(): string {
    return date('Y-m-d H:i:s');
}

function dt_day_start(): string {
    return date('Y-m-d 00:00:00');
}

function dt_bind_range(?string $from, ?string $to): array {
    return [
        'from' => $from ?: dt_day_start(),
        'to'   => $to   ?: dt_now(),
    ];
}

try {
    $db = Db::mysql($cfg);

    // DataTables params
    $draw   = (int)($_GET['draw'] ?? 1);
    $start  = (int)($_GET['start'] ?? 0);
    $length = (int)($_GET['length'] ?? 25);
    if ($start < 0) $start = 0;
    if ($length < 1) $length = 25;
    if ($length > 500) $length = 500;

    $search = trim((string)($_GET['search']['value'] ?? ''));
    $dateFrom = norm_dt($_GET['date_from'] ?? null);
    $dateTo   = norm_dt($_GET['date_to'] ?? null);
    $packerFilter = trim((string)($_GET['packer'] ?? ''));

    $range = dt_bind_range($dateFrom, $dateTo);

    // Base WHERE dla pak_orders (tylko spakowane, z poprawnymi czasami i packerem)
    $whereBase = "
        po.status = 50
        AND po.packer IS NOT NULL AND po.packer <> ''
        AND po.pack_started_at IS NOT NULL
        AND po.pack_ended_at IS NOT NULL
        AND po.pack_ended_at >= :from
        AND po.pack_ended_at <= :to
    ";

    $bindBase = [
        ':from' => $range['from'],
        ':to'   => $range['to'],
    ];

    if ($packerFilter !== '') {
        $whereBase .= " AND po.packer LIKE :packer_filter";
        $bindBase[':packer_filter'] = '%' . $packerFilter . '%';
    }

    // Global search DataTables (na poziomie summary -> packer)
    $having = "";
    $bindSearch = [];
    if ($search !== '') {
        $having = " HAVING po.packer LIKE :search ";
        $bindSearch[':search'] = '%' . $search . '%';
    }

    // Sortowanie (whitelist)
    $orderColIdx = (int)($_GET['order'][0]['column'] ?? 1);
    $orderDirRaw = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc'));
    $orderDir = ($orderDirRaw === 'asc') ? 'ASC' : 'DESC';

    $orderMap = [
        0 => 'po.packer',
        1 => 'packed_count',
        2 => 'avg_sec',
        3 => 'total_sec',
        4 => 'first_finish',
        5 => 'last_finish',
        6 => 'force_finish_count',
        7 => 'unlock_count',
    ];
    $orderExpr = $orderMap[$orderColIdx] ?? 'packed_count';

    // Subquery eventów per packer w tym samym zakresie czasu
    // (liczone po pak_events.created_at)
    $eventsSql = "
        SELECT
            e.packer,
            SUM(CASE WHEN e.event_type = 'FINISH' AND e.message LIKE '%force_finish%' THEN 1 ELSE 0 END) AS force_finish_count,
            SUM(CASE WHEN e.event_type = 'UNLOCK' THEN 1 ELSE 0 END) AS unlock_count
        FROM pak_events e
        WHERE e.packer IS NOT NULL AND e.packer <> ''
          AND e.created_at >= :ev_from
          AND e.created_at <= :ev_to
        GROUP BY e.packer
    ";

    // recordsTotal (po filtrach czasu/packer, przed search)
    $sqlTotal = "
        SELECT COUNT(*) FROM (
            SELECT po.packer
            FROM pak_orders po
            WHERE $whereBase
            GROUP BY po.packer
        ) x
    ";
    $stTotal = $db->prepare($sqlTotal);
    $stTotal->execute($bindBase);
    $recordsTotal = (int)$stTotal->fetchColumn();

    // recordsFiltered (po search)
    $sqlFiltered = "
        SELECT COUNT(*) FROM (
            SELECT po.packer
            FROM pak_orders po
            WHERE $whereBase
            GROUP BY po.packer
            $having
        ) y
    ";
    $stFiltered = $db->prepare($sqlFiltered);
    $stFiltered->execute($bindBase + $bindSearch);
    $recordsFiltered = (int)$stFiltered->fetchColumn();

    // Główne dane
    $sql = "
        SELECT
            po.packer,
            COUNT(*) AS packed_count,
            ROUND(AVG(TIMESTAMPDIFF(SECOND, po.pack_started_at, po.pack_ended_at))) AS avg_sec,
            SUM(TIMESTAMPDIFF(SECOND, po.pack_started_at, po.pack_ended_at)) AS total_sec,
            MIN(po.pack_ended_at) AS first_finish,
            MAX(po.pack_ended_at) AS last_finish,
            COALESCE(ev.force_finish_count, 0) AS force_finish_count,
            COALESCE(ev.unlock_count, 0) AS unlock_count
        FROM pak_orders po
        LEFT JOIN (
            $eventsSql
        ) ev ON ev.packer = po.packer
        WHERE $whereBase
        GROUP BY po.packer
        $having
        ORDER BY $orderExpr $orderDir, po.packer ASC
        LIMIT $length OFFSET $start
    ";

    $bind = $bindBase + $bindSearch + [
        ':ev_from' => $range['from'],
        ':ev_to'   => $range['to'],
    ];

    $st = $db->prepare($sql);
    $st->execute($bind);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    Resp::json([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $rows,
    ], 200);

} catch (\Throwable $e) {
    // DataTables też musi dostać JSON
    Resp::json([
        'draw' => (int)($_GET['draw'] ?? 1),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'report_packers_summary error: ' . $e->getMessage(),
    ], 200);
}
