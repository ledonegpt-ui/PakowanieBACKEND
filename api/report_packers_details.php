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
    $length = (int)($_GET['length'] ?? 50);
    if ($start < 0) $start = 0;
    if ($length < 1) $length = 50;
    if ($length > 1000) $length = 1000;

    $search = trim((string)($_GET['search']['value'] ?? ''));
    $dateFrom = norm_dt($_GET['date_from'] ?? null);
    $dateTo   = norm_dt($_GET['date_to'] ?? null);

    $packerFilter = trim((string)($_GET['packer'] ?? ''));
    $selectedPacker = trim((string)($_GET['selected_packer'] ?? ''));

    // selected_packer (kliknięty z summary) ma priorytet nad ręcznym polem packer
    $effectivePacker = ($selectedPacker !== '') ? $selectedPacker : $packerFilter;

    $range = dt_bind_range($dateFrom, $dateTo);

    $where = "
        po.status = 50
        AND po.pack_started_at IS NOT NULL
        AND po.pack_ended_at IS NOT NULL
        AND po.pack_ended_at >= :from
        AND po.pack_ended_at <= :to
        AND po.packer IS NOT NULL AND po.packer <> ''
    ";

    $bindBase = [
        ':from' => $range['from'],
        ':to'   => $range['to'],
    ];

    if ($effectivePacker !== '') {
        $where .= " AND po.packer = :packer_exact";
        $bindBase[':packer_exact'] = $effectivePacker;
    }

    $searchSql = "";
    $bindSearch = [];
    if ($search !== '') {
        $searchSql = "
            AND (
                po.order_code LIKE :search
                OR po.subiekt_doc_no LIKE :search
                OR po.packer LIKE :search
                OR po.station LIKE :search
            )
        ";
        $bindSearch[':search'] = '%' . $search . '%';
    }

    // Sortowanie (whitelist)
    $orderColIdx = (int)($_GET['order'][0]['column'] ?? 0);
    $orderDirRaw = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc'));
    $orderDir = ($orderDirRaw === 'asc') ? 'ASC' : 'DESC';

    $orderMap = [
        0 => 'po.pack_ended_at',
        1 => 'po.order_code',
        2 => 'po.subiekt_doc_no',
        3 => 'po.packer',
        4 => 'po.station',
        5 => 'po.pack_started_at',
        6 => 'po.pack_ended_at',
        7 => 'packing_seconds',
        8 => 'force_finish',
    ];
    $orderExpr = $orderMap[$orderColIdx] ?? 'po.pack_ended_at';

    // recordsTotal (po filtrach daty/packer, bez global search)
    $sqlTotal = "SELECT COUNT(*) FROM pak_orders po WHERE $where";
    $stTotal = $db->prepare($sqlTotal);
    $stTotal->execute($bindBase);
    $recordsTotal = (int)$stTotal->fetchColumn();

    // recordsFiltered (z global search)
    $sqlFiltered = "SELECT COUNT(*) FROM pak_orders po WHERE $where $searchSql";
    $stFiltered = $db->prepare($sqlFiltered);
    $stFiltered->execute($bindBase + $bindSearch);
    $recordsFiltered = (int)$stFiltered->fetchColumn();

    // Główne dane
    $sql = "
        SELECT
            po.order_code,
            po.subiekt_doc_no,
            po.packer,
            po.station,
            po.pack_started_at,
            po.pack_ended_at,
            TIMESTAMPDIFF(SECOND, po.pack_started_at, po.pack_ended_at) AS packing_seconds,
            CASE
              WHEN EXISTS (
                SELECT 1
                FROM pak_events e
                WHERE e.order_code = po.order_code
                  AND e.event_type = 'FINISH'
                  AND e.message LIKE '%force_finish%'
              ) THEN 1
              ELSE 0
            END AS force_finish
        FROM pak_orders po
        WHERE $where
        $searchSql
        ORDER BY $orderExpr $orderDir, po.pack_ended_at DESC, po.order_code ASC
        LIMIT $length OFFSET $start
    ";

    $st = $db->prepare($sql);
    $st->execute($bindBase + $bindSearch);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    Resp::json([
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $rows,
    ], 200);

} catch (\Throwable $e) {
    Resp::json([
        'draw' => (int)($_GET['draw'] ?? 1),
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'report_packers_details error: ' . $e->getMessage(),
    ], 200);
}
