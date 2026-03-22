<?php
declare(strict_types=1);

final class AdminRepository
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─── BATCHES ─────────────────────────────────────────────────────────────

    public function getBatches(string $status, string $carrierKey, int $limit): array
    {
        $where  = array();
        $params = array();

        if ($status !== '') {
            $where[] = 'pb.status = :status';
            $params[':status'] = $status;
        }
        if ($carrierKey !== '') {
            $where[] = 'pb.carrier_key = :carrier_key';
            $params[':carrier_key'] = $carrierKey;
        }

        $sql = "
            SELECT
                pb.id,
                pb.batch_code,
                pb.carrier_key,
                pb.status,
                pb.selection_mode,
                pb.target_orders_count,
                pb.started_at,
                pb.completed_at,
                pb.abandoned_at,
                u.login        AS user_login,
                u.display_name AS user_name,
                st.station_code,
                st.station_name,
                SUM(CASE WHEN pbo.status = 'picked'   THEN 1 ELSE 0 END) AS picked_orders,
                SUM(CASE WHEN pbo.status = 'dropped'  THEN 1 ELSE 0 END) AS dropped_orders,
                SUM(CASE WHEN pbo.status = 'assigned' THEN 1 ELSE 0 END) AS active_orders,
                COUNT(pbo.id) AS total_orders
            FROM picking_batches pb
            LEFT JOIN users u     ON u.id   = pb.user_id
            LEFT JOIN stations st ON st.id  = pb.station_id
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
        ";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY pb.id ORDER BY pb.started_at DESC LIMIT ' . (int)$limit;

        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── OVERVIEW KPI ────────────────────────────────────────────────────────

    public function getOverviewStats(): array
    {
        $st = $this->db->query("
            SELECT
                SUM(CASE WHEN status = '10' THEN 1 ELSE 0 END) AS new_orders,
                SUM(CASE WHEN status = '40' THEN 1 ELSE 0 END) AS packing_orders,
                SUM(CASE WHEN status = '50'
                     AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) AS packed_today,
                SUM(CASE WHEN status = '60'
                     AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS cancelled_7d,
                COUNT(*) AS total_orders
            FROM pak_orders
        ");
        $orders = $st->fetch(PDO::FETCH_ASSOC);

        $st2 = $this->db->query("
            SELECT COUNT(*) AS packed_yesterday
            FROM packing_sessions
            WHERE status = 'completed'
              AND DATE(completed_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ");
        $yesterday = $st2->fetch(PDO::FETCH_ASSOC);

        $st3 = $this->db->query("
            SELECT COUNT(*) AS active_packing_sessions
            FROM packing_sessions
            WHERE status = 'open'
        ");
        $activePacking = $st3->fetch(PDO::FETCH_ASSOC);

        $st4 = $this->db->query("
            SELECT COUNT(*) AS open_batches
            FROM picking_batches
            WHERE status = 'open'
        ");
        $openBatches = $st4->fetch(PDO::FETCH_ASSOC);

        return array(
            'new_orders'              => (int)($orders['new_orders'] ?? 0),
            'packing_orders'          => (int)($orders['packing_orders'] ?? 0),
            'packed_today'            => (int)($orders['packed_today'] ?? 0),
            'packed_yesterday'        => (int)($yesterday['packed_yesterday'] ?? 0),
            'cancelled_7d'            => (int)($orders['cancelled_7d'] ?? 0),
            'active_packing_sessions' => (int)($activePacking['active_packing_sessions'] ?? 0),
            'open_batches'            => (int)($openBatches['open_batches'] ?? 0),
            'total_orders'            => (int)($orders['total_orders'] ?? 0),
        );
    }

    // ─── DAILY PACKING + PICKING ──────────────────────────────────────────────

    public function getDailyStats(int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                DATE(ps.completed_at)                                           AS day,
                COUNT(ps.id)                                                    AS packed_count,
                COUNT(DISTINCT ps.user_id)                                      AS unique_packers,
                AVG(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))      AS avg_pack_seconds,
                MIN(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))      AS min_pack_seconds,
                MAX(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))      AS max_pack_seconds,
                SUM(CASE WHEN ps.status = 'cancelled' THEN 1 ELSE 0 END)        AS cancelled_sessions
            FROM packing_sessions ps
            WHERE ps.status = 'completed'
              AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(ps.completed_at)
            ORDER BY day DESC
        ");
        $st->execute(array(':days' => $days));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return array(
                'day'               => $r['day'],
                'packed_count'      => (int)$r['packed_count'],
                'unique_packers'    => (int)$r['unique_packers'],
                'avg_pack_seconds'  => $r['avg_pack_seconds']  !== null ? (int)round((float)$r['avg_pack_seconds'])  : null,
                'min_pack_seconds'  => $r['min_pack_seconds']  !== null ? (int)$r['min_pack_seconds']  : null,
                'max_pack_seconds'  => $r['max_pack_seconds']  !== null ? (int)$r['max_pack_seconds']  : null,
                'cancelled_sessions'=> (int)$r['cancelled_sessions'],
            );
        }, $rows);
    }

    public function getPickingDailyStats(int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                DATE(pb.started_at)                                                  AS day,
                COUNT(DISTINCT pb.id)                                                AS batches_count,
                SUM(CASE WHEN pbo.status = 'picked'  THEN 1 ELSE 0 END)             AS picked_orders,
                SUM(CASE WHEN pbo.status = 'dropped' THEN 1 ELSE 0 END)             AS dropped_orders,
                COUNT(DISTINCT pb.user_id)                                           AS unique_pickers,
                AVG(TIMESTAMPDIFF(SECOND, pb.started_at, pb.completed_at))          AS avg_batch_seconds
            FROM picking_batches pb
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
            WHERE pb.started_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(pb.started_at)
            ORDER BY day DESC
        ");
        $st->execute(array(':days' => $days));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return array(
                'day'                => $r['day'],
                'batches_count'      => (int)$r['batches_count'],
                'picked_orders'      => (int)$r['picked_orders'],
                'dropped_orders'     => (int)$r['dropped_orders'],
                'unique_pickers'     => (int)$r['unique_pickers'],
                'avg_batch_seconds'  => $r['avg_batch_seconds'] !== null ? (int)round((float)$r['avg_batch_seconds']) : null,
            );
        }, $rows);
    }

    // ─── HOURLY HEATMAP ──────────────────────────────────────────────────────

    public function getHourlyStats(int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                HOUR(ps.completed_at)   AS hour,
                COUNT(*)                AS packed_count
            FROM packing_sessions ps
            WHERE ps.status = 'completed'
              AND ps.completed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY HOUR(ps.completed_at)
            ORDER BY hour ASC
        ");
        $st->execute(array(':days' => $days));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Uzupełnij brakujące godziny zerami
        $byHour = array();
        foreach ($rows as $r) {
            $byHour[(int)$r['hour']] = (int)$r['packed_count'];
        }

        $result = array();
        for ($h = 0; $h <= 23; $h++) {
            $result[] = array('hour' => $h, 'packed_count' => $byHour[$h] ?? 0);
        }
        return $result;
    }

    // ─── CARRIER STATS ───────────────────────────────────────────────────────

    public function getCarrierStats(int $days): array
    {
        // Zamówienia wg kuriera i statusu
        $st = $this->db->prepare("
            SELECT
                COALESCE(NULLIF(po.carrier_code,''), 'unknown')     AS carrier_key,
                po.status,
                COUNT(*)                                             AS cnt
            FROM pak_orders po
            GROUP BY carrier_key, po.status
        ");
        $st->execute();
        $statusRows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Spakowane w zakresie dni wg kuriera z batcha
        $st2 = $this->db->prepare("
            SELECT
                pb.carrier_key,
                COUNT(DISTINCT ps.id) AS packed_in_period
            FROM packing_sessions ps
            JOIN picking_batches pb ON pb.id = ps.picking_batch_id
            WHERE ps.status = 'completed'
              AND ps.completed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY pb.carrier_key
        ");
        $st2->execute(array(':days' => $days));
        $periodRows = $st2->fetchAll(PDO::FETCH_ASSOC);

        $periodByCarrier = array();
        foreach ($periodRows as $r) {
            $periodByCarrier[(string)$r['carrier_key']] = (int)$r['packed_in_period'];
        }

        // Agreguj wg carrier
        $carriers = array();
        foreach ($statusRows as $r) {
            $k = (string)$r['carrier_key'];
            if (!isset($carriers[$k])) {
                $carriers[$k] = array(
                    'carrier_key'      => $k,
                    'total'            => 0,
                    'new'              => 0,
                    'packing'          => 0,
                    'packed'           => 0,
                    'cancelled'        => 0,
                    'packed_in_period' => $periodByCarrier[$k] ?? 0,
                );
            }
            $cnt = (int)$r['cnt'];
            $carriers[$k]['total'] += $cnt;
            switch ($r['status']) {
                case '10': $carriers[$k]['new']       += $cnt; break;
                case '40': $carriers[$k]['packing']   += $cnt; break;
                case '50': $carriers[$k]['packed']    += $cnt; break;
                case '60': $carriers[$k]['cancelled'] += $cnt; break;
            }
        }

        usort($carriers, function ($a, $b) { return $b['total'] - $a['total']; });
        return array_values($carriers);
    }

    // ─── PACKERS STATS ───────────────────────────────────────────────────────

    public function getPackersStats(int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                u.id                                                                    AS user_id,
                u.login,
                u.display_name,
                COUNT(ps.id)                                                            AS packed_count,
                SUM(CASE WHEN ps.status = 'cancelled' THEN 1 ELSE 0 END)               AS cancelled_count,
                AVG(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))             AS avg_pack_seconds,
                MIN(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))             AS min_pack_seconds,
                MAX(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))             AS max_pack_seconds,
                SUM(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))             AS total_pack_seconds
            FROM packing_sessions ps
            JOIN users u ON u.id = ps.user_id
            WHERE ps.status = 'completed'
              AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY u.id
            ORDER BY packed_count DESC
        ");
        $st->execute(array(':days' => $days));
        $packingRows = $st->fetchAll(PDO::FETCH_ASSOC);

        $st2 = $this->db->prepare("
            SELECT
                pb.user_id,
                COUNT(DISTINCT pb.id)                                                   AS batches_count,
                SUM(CASE WHEN pbo.status = 'picked'  THEN 1 ELSE 0 END)                AS picked_orders,
                SUM(CASE WHEN pbo.status = 'dropped' THEN 1 ELSE 0 END)                AS dropped_orders,
                AVG(TIMESTAMPDIFF(SECOND, pb.started_at, pb.completed_at))             AS avg_batch_seconds
            FROM picking_batches pb
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
            WHERE pb.started_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
              AND pb.completed_at IS NOT NULL
            GROUP BY pb.user_id
        ");
        $st2->execute(array(':days' => $days));
        $pickingRows = $st2->fetchAll(PDO::FETCH_ASSOC);

        $pickingByUser = array();
        foreach ($pickingRows as $r) {
            $pickingByUser[(int)$r['user_id']] = $r;
        }

        return array_map(function ($r) use ($pickingByUser) {
            $uid    = (int)$r['user_id'];
            $pick   = $pickingByUser[$uid] ?? array();
            $avgSec = $r['avg_pack_seconds'] !== null ? (int)round((float)$r['avg_pack_seconds']) : null;
            $avgPickSec = isset($pick['avg_batch_seconds']) && $pick['avg_batch_seconds'] !== null
                        ? (int)round((float)$pick['avg_batch_seconds']) : null;

            return array(
                'user_id'           => $uid,
                'login'             => $r['login'],
                'display_name'      => $r['display_name'],
                'packed_count'      => (int)$r['packed_count'],
                'cancelled_count'   => (int)$r['cancelled_count'],
                'avg_pack_seconds'  => $avgSec,
                'avg_pack_label'    => $avgSec  !== null ? self::formatSeconds($avgSec)     : null,
                'min_pack_label'    => $r['min_pack_seconds'] !== null ? self::formatSeconds((int)$r['min_pack_seconds']) : null,
                'max_pack_label'    => $r['max_pack_seconds'] !== null ? self::formatSeconds((int)$r['max_pack_seconds']) : null,
                'total_pack_hours'  => $r['total_pack_seconds'] !== null
                                     ? round((float)$r['total_pack_seconds'] / 3600, 1) : null,
                'batches_count'     => (int)($pick['batches_count']  ?? 0),
                'picked_orders'     => (int)($pick['picked_orders']  ?? 0),
                'dropped_orders'    => (int)($pick['dropped_orders'] ?? 0),
                'avg_batch_seconds' => $avgPickSec,
                'avg_batch_label'   => $avgPickSec !== null ? self::formatSeconds($avgPickSec) : null,
            );
        }, $packingRows);
    }

    // ─── USER DAILY BREAKDOWN ────────────────────────────────────────────────

    public function getUserDailyStats(int $userId, int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                DATE(ps.completed_at)                                               AS day,
                COUNT(ps.id)                                                        AS packed_count,
                AVG(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))         AS avg_pack_seconds,
                SUM(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at))         AS total_pack_seconds
            FROM packing_sessions ps
            WHERE ps.status = 'completed'
              AND ps.user_id = :user_id
              AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(ps.completed_at)
            ORDER BY day DESC
        ");
        $st->execute(array(':user_id' => $userId, ':days' => $days));
        $packing = $st->fetchAll(PDO::FETCH_ASSOC);

        $st2 = $this->db->prepare("
            SELECT
                DATE(pb.started_at)                                                AS day,
                COUNT(DISTINCT pb.id)                                              AS batches_count,
                SUM(CASE WHEN pbo.status = 'picked'  THEN 1 ELSE 0 END)           AS picked_orders,
                SUM(CASE WHEN pbo.status = 'dropped' THEN 1 ELSE 0 END)           AS dropped_orders
            FROM picking_batches pb
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
            WHERE pb.user_id = :user_id
              AND pb.started_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(pb.started_at)
            ORDER BY day DESC
        ");
        $st2->execute(array(':user_id' => $userId, ':days' => $days));
        $picking = $st2->fetchAll(PDO::FETCH_ASSOC);

        // Połącz po dniu
        $byDay = array();
        foreach ($packing as $r) {
            $d = $r['day'];
            $avgSec = $r['avg_pack_seconds'] !== null ? (int)round((float)$r['avg_pack_seconds']) : null;
            $byDay[$d] = array(
                'day'              => $d,
                'packed_count'     => (int)$r['packed_count'],
                'avg_pack_seconds' => $avgSec,
                'avg_pack_label'   => $avgSec !== null ? self::formatSeconds($avgSec) : null,
                'total_pack_hours' => $r['total_pack_seconds'] !== null ? round((float)$r['total_pack_seconds'] / 3600, 1) : 0,
                'batches_count'    => 0,
                'picked_orders'    => 0,
                'dropped_orders'   => 0,
            );
        }
        foreach ($picking as $r) {
            $d = $r['day'];
            if (!isset($byDay[$d])) {
                $byDay[$d] = array('day' => $d, 'packed_count' => 0, 'avg_pack_seconds' => null, 'avg_pack_label' => null, 'total_pack_hours' => 0);
            }
            $byDay[$d]['batches_count'] = (int)$r['batches_count'];
            $byDay[$d]['picked_orders'] = (int)$r['picked_orders'];
            $byDay[$d]['dropped_orders'] = (int)$r['dropped_orders'];
        }

        usort($byDay, function ($a, $b) { return strcmp($b['day'], $a['day']); });
        return array_values($byDay);
    }

    private static function formatSeconds(int $sec): string
    {
        if ($sec < 60) return $sec . 's';
        $m = intdiv($sec, 60);
        $s = $sec % 60;
        return $m . 'm ' . str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 's';
    }
}

    /** @var PDO */
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─── BATCHES ─────────────────────────────────────────────────────────────

    public function getBatches(string $status, string $carrierKey, int $limit): array
    {
        $where = array();
        $params = array();

        if ($status !== '') {
            $where[] = 'pb.status = :status';
            $params[':status'] = $status;
        }

        if ($carrierKey !== '') {
            $where[] = 'pb.carrier_key = :carrier_key';
            $params[':carrier_key'] = $carrierKey;
        }

        $sql = "
            SELECT
                pb.id,
                pb.batch_code,
                pb.carrier_key,
                pb.status,
                pb.selection_mode,
                pb.target_orders_count,
                pb.started_at,
                pb.completed_at,
                pb.abandoned_at,
                u.login        AS user_login,
                u.display_name AS user_name,
                st.station_code,
                st.station_name,
                SUM(CASE WHEN pbo.status = 'picked'   THEN 1 ELSE 0 END) AS picked_orders,
                SUM(CASE WHEN pbo.status = 'dropped'  THEN 1 ELSE 0 END) AS dropped_orders,
                SUM(CASE WHEN pbo.status = 'assigned' THEN 1 ELSE 0 END) AS active_orders,
                COUNT(pbo.id) AS total_orders
            FROM picking_batches pb
            LEFT JOIN users u   ON u.id  = pb.user_id
            LEFT JOIN stations st ON st.id = pb.station_id
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
        ";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= '
            GROUP BY pb.id
            ORDER BY pb.started_at DESC
            LIMIT ' . (int)$limit;

        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── STATS: OVERVIEW (dashboard KPI) ─────────────────────────────────────

    public function getOverviewStats(): array
    {
        // Zamówienia
        $st = $this->db->query("
            SELECT
                SUM(CASE WHEN status = '10' THEN 1 ELSE 0 END) AS new_orders,
                SUM(CASE WHEN status = '40' THEN 1 ELSE 0 END) AS packing_orders,
                SUM(CASE WHEN status = '50'
                     AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) AS packed_today,
                SUM(CASE WHEN status = '60'
                     AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS cancelled_7d
            FROM pak_orders
        ");
        $orders = $st->fetch(PDO::FETCH_ASSOC);

        // Wczorajsze spakowane (do % porównania)
        $st2 = $this->db->query("
            SELECT COUNT(*) AS packed_yesterday
            FROM packing_sessions
            WHERE status = 'completed'
              AND DATE(completed_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ");
        $yesterday = $st2->fetch(PDO::FETCH_ASSOC);

        // Aktywne sesje pakowania
        $st3 = $this->db->query("
            SELECT COUNT(*) AS active_packing_sessions
            FROM packing_sessions
            WHERE status = 'open'
        ");
        $activeSessions = $st3->fetch(PDO::FETCH_ASSOC);

        return array(
            'new_orders'              => (int)($orders['new_orders'] ?? 0),
            'packing_orders'          => (int)($orders['packing_orders'] ?? 0),
            'packed_today'            => (int)($orders['packed_today'] ?? 0),
            'packed_yesterday'        => (int)($yesterday['packed_yesterday'] ?? 0),
            'cancelled_7d'            => (int)($orders['cancelled_7d'] ?? 0),
            'active_packing_sessions' => (int)($activeSessions['active_packing_sessions'] ?? 0),
        );
    }

    // ─── STATS: DAILY ────────────────────────────────────────────────────────

    public function getDailyStats(int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                DATE(ps.completed_at)                    AS day,
                COUNT(ps.id)                             AS packed_count,
                COUNT(DISTINCT ps.user_id)               AS unique_packers,
                AVG(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at)) AS avg_seconds
            FROM packing_sessions ps
            WHERE ps.status = 'completed'
              AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(ps.completed_at)
            ORDER BY day DESC
        ");
        $st->execute(array(':days' => $days));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return array(
                'day'             => $r['day'],
                'packed_count'    => (int)$r['packed_count'],
                'unique_packers'  => (int)$r['unique_packers'],
                'avg_seconds'     => $r['avg_seconds'] !== null ? (int)round((float)$r['avg_seconds']) : null,
            );
        }, $rows);
    }

    // ─── STATS: PICKING DAILY ─────────────────────────────────────────────────

    public function getPickingDailyStats(int $days): array
    {
        $st = $this->db->prepare("
            SELECT
                DATE(pb.started_at)                      AS day,
                COUNT(DISTINCT pb.id)                    AS batches_count,
                SUM(CASE WHEN pbo.status = 'picked' THEN 1 ELSE 0 END) AS picked_orders_count,
                COUNT(DISTINCT pb.user_id)               AS unique_pickers
            FROM picking_batches pb
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
            WHERE pb.started_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(pb.started_at)
            ORDER BY day DESC
        ");
        $st->execute(array(':days' => $days));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return array(
                'day'                 => $r['day'],
                'batches_count'       => (int)$r['batches_count'],
                'picked_orders_count' => (int)$r['picked_orders_count'],
                'unique_pickers'      => (int)$r['unique_pickers'],
            );
        }, $rows);
    }

    // ─── STATS: PACKERS ──────────────────────────────────────────────────────

    public function getPackersStats(int $days): array
    {
        // Statystyki pakowania
        $st = $this->db->prepare("
            SELECT
                u.id           AS user_id,
                u.login,
                u.display_name,
                COUNT(ps.id)   AS packed_count,
                AVG(TIMESTAMPDIFF(SECOND, ps.started_at, ps.completed_at)) AS avg_seconds
            FROM packing_sessions ps
            JOIN users u ON u.id = ps.user_id
            WHERE ps.status = 'completed'
              AND ps.completed_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY u.id
            ORDER BY packed_count DESC
        ");
        $st->execute(array(':days' => $days));
        $packingRows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Statystyki pickingu dla tych samych użytkowników
        $st2 = $this->db->prepare("
            SELECT
                pb.user_id,
                COUNT(DISTINCT pb.id)  AS batches_count,
                SUM(CASE WHEN pbo.status = 'picked' THEN 1 ELSE 0 END) AS picked_orders
            FROM picking_batches pb
            LEFT JOIN picking_batch_orders pbo ON pbo.batch_id = pb.id
            WHERE pb.started_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY pb.user_id
        ");
        $st2->execute(array(':days' => $days));
        $pickingRows = $st2->fetchAll(PDO::FETCH_ASSOC);

        // Indeks pickingu po user_id
        $pickingByUser = array();
        foreach ($pickingRows as $r) {
            $pickingByUser[(int)$r['user_id']] = $r;
        }

        return array_map(function ($r) use ($pickingByUser) {
            $userId = (int)$r['user_id'];
            $picking = $pickingByUser[$userId] ?? array();
            $avgSec = $r['avg_seconds'] !== null ? (int)round((float)$r['avg_seconds']) : null;

            return array(
                'user_id'        => $userId,
                'login'          => $r['login'],
                'display_name'   => $r['display_name'],
                'packed_count'   => (int)$r['packed_count'],
                'avg_seconds'    => $avgSec,
                'avg_time_label' => $avgSec !== null ? self::formatSeconds($avgSec) : null,
                'batches_count'  => (int)($picking['batches_count'] ?? 0),
                'picked_orders'  => (int)($picking['picked_orders'] ?? 0),
            );
        }, $packingRows);
    }

    private static function formatSeconds(int $sec): string
    {
        if ($sec < 60) return $sec . 's';
        $m = intdiv($sec, 60);
        $s = $sec % 60;
        return $m . 'm ' . str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 's';
    }
}
