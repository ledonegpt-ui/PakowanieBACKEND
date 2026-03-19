<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

$cfg = require BASE_PATH . '/app/bootstrap.php';
require BASE_PATH . '/app/Lib/Db.php';

$db = Db::mysql($cfg);
$now = date('Y-m-d H:i:s');
$threshold = 5; // minuty

// -------------------------------------------------------------------------
// PICKING — porzuć otwarte batche bez heartbeatu > 2 minuty
// -------------------------------------------------------------------------

$st = $db->prepare("
    SELECT id, batch_code, user_id
    FROM picking_batches
    WHERE status = 'open'
      AND (
          last_seen_at IS NULL AND TIMESTAMPDIFF(MINUTE, started_at, NOW()) > :threshold
          OR
          last_seen_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) > :threshold2
      )
");
$st->execute([':threshold' => $threshold, ':threshold2' => $threshold]);
$staleBatches = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($staleBatches as $batch) {
    $db->beginTransaction();
    try {
        $upd = $db->prepare("
            UPDATE picking_batches
            SET status = 'abandoned',
                abandoned_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':id' => (int)$batch['id']]);

        $log = $db->prepare("
            INSERT INTO picking_events
                (batch_id, event_type, event_message, payload_json, created_at)
            VALUES
                (:batch_id, 'batch_abandoned', 'Batch abandoned due to heartbeat timeout',
                 :payload, NOW())
        ");
        $log->execute([
            ':batch_id' => (int)$batch['id'],
            ':payload'  => json_encode([
                'batch_id'          => (int)$batch['id'],
                'batch_code'        => $batch['batch_code'],
                'user_id'           => (int)$batch['user_id'],
                'reason'            => 'heartbeat_timeout',
                'threshold_minutes' => $threshold,
            ]),
        ]);

        $db->commit();
        echo '[' . $now . '] picking batch abandoned: ' . $batch['batch_code'] . ' (user=' . $batch['user_id'] . ')' . PHP_EOL;

    } catch (Throwable $e) {
        $db->rollBack();
        echo '[' . $now . '] ERROR abandoning picking batch ' . $batch['id'] . ': ' . $e->getMessage() . PHP_EOL;
    }
}

// -------------------------------------------------------------------------
// PACKING — porzuć otwarte sesje bez heartbeatu > 2 minuty
// -------------------------------------------------------------------------

$st = $db->prepare("
    SELECT id, session_code, user_id, order_code
    FROM packing_sessions
    WHERE status = 'open'
      AND (
          last_seen_at IS NULL AND TIMESTAMPDIFF(MINUTE, started_at, NOW()) > :threshold
          OR
          last_seen_at IS NOT NULL AND TIMESTAMPDIFF(MINUTE, last_seen_at, NOW()) > :threshold2
      )
");
$st->execute([':threshold' => $threshold, ':threshold2' => $threshold]);
$staleSessions = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($staleSessions as $session) {
    $db->beginTransaction();
    try {
        $upd = $db->prepare("
            UPDATE packing_sessions
            SET status = 'abandoned',
                cancelled_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");
        $upd->execute([':id' => (int)$session['id']]);

        $log = $db->prepare("
            INSERT INTO packing_events
                (session_id, event_type, event_message, payload_json, created_at)
            VALUES
                (:session_id, 'session_abandoned', 'Session abandoned due to heartbeat timeout',
                 :payload, NOW())
        ");
        $log->execute([
            ':session_id' => (int)$session['id'],
            ':payload'    => json_encode([
                'session_id'        => (int)$session['id'],
                'session_code'      => $session['session_code'],
                'order_code'        => $session['order_code'],
                'user_id'           => (int)$session['user_id'],
                'reason'            => 'heartbeat_timeout',
                'threshold_minutes' => $threshold,
            ]),
        ]);

        $db->commit();
        echo '[' . $now . '] packing session abandoned: ' . $session['session_code'] . ' order=' . $session['order_code'] . PHP_EOL;

    } catch (Throwable $e) {
        $db->rollBack();
        echo '[' . $now . '] ERROR abandoning packing session ' . $session['id'] . ': ' . $e->getMessage() . PHP_EOL;
    }
}

if (empty($staleBatches) && empty($staleSessions)) {
    echo '[' . $now . '] nothing to abandon' . PHP_EOL;
}
