<?php
declare(strict_types=1);

final class HeartbeatController
{
    public function heartbeat(array $params = []): void
    {
        global $currentSession, $cfg;

        try {
            require_once BASE_PATH . '/app/Lib/Db.php';

            $db     = Db::mysql($cfg);
            $userId = (int)$currentSession['user_id'];
            $now    = date('Y-m-d H:i:s');
            $result = [];

            // picking — otwarty batch operatora
            $st = $db->prepare("
                SELECT id FROM picking_batches
                WHERE user_id = :user_id
                  AND status = 'open'
                LIMIT 1
            ");
            $st->execute([':user_id' => $userId]);
            $pickingBatch = $st->fetch(PDO::FETCH_ASSOC);

            if ($pickingBatch) {
                $st = $db->prepare("
                    UPDATE picking_batches
                    SET last_seen_at = NOW(), updated_at = NOW()
                    WHERE id = :id
                ");
                $st->execute([':id' => (int)$pickingBatch['id']]);
                $result['picking_batch_id'] = (int)$pickingBatch['id'];
            }

            // packing — otwarta sesja operatora
            $st = $db->prepare("
                SELECT id FROM packing_sessions
                WHERE user_id = :user_id
                  AND status = 'open'
                LIMIT 1
            ");
            $st->execute([':user_id' => $userId]);
            $packingSession = $st->fetch(PDO::FETCH_ASSOC);

            if ($packingSession) {
                $st = $db->prepare("
                    UPDATE packing_sessions
                    SET last_seen_at = NOW(), updated_at = NOW()
                    WHERE id = :id
                ");
                $st->execute([':id' => (int)$packingSession['id']]);
                $result['packing_session_id'] = (int)$packingSession['id'];
            }

            if (empty($result)) {
                ApiResponse::ok(['heartbeat' => ['status' => 'no_active_session', 'ts' => $now]]);
                return;
            }

            $result['ts'] = $now;
            ApiResponse::ok(['heartbeat' => $result]);

        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}
