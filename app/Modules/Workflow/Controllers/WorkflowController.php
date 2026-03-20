<?php
declare(strict_types=1);

final class WorkflowController
{
    public function status(array $params = []): void
    {
        global $currentSession, $cfg;
        try {
            // Walidacja sesji — stacja musi być wybrana
            if (empty($currentSession['station_id'])) {
                ApiResponse::error('Brak aktywnej stacji — wybierz stanowisko', 400);
                return;
            }

            require_once BASE_PATH . '/app/Lib/Db.php';
            require_once BASE_PATH . '/app/Modules/Packing/Repositories/PackingRepository.php';
            require_once BASE_PATH . '/app/Modules/Picking/Repositories/PickingBatchRepository.php';

            $db          = Db::mysql($cfg);
            $packingRepo = new PackingRepository($db);
            $pickingRepo = new PickingBatchRepository($db);

            $userId    = (int)$currentSession['user_id'];
            $stationId = (int)$currentSession['station_id'];

            // ----------------------------------------------------------------
            // 1. Packing otwarty — najwyższy priorytet
            // ----------------------------------------------------------------
            $openPacking = $packingRepo->findOpenSessionForUser($userId);
            if ($openPacking) {
                // Sesja może być z innej stacji (ten sam user, dwa stanowiska)
                // Informujemy GUI — niech samo zdecyduje jak obsłużyć
                ApiResponse::ok([
                    'workflow' => [
                        'action'     => 'resume_packing',
                        'order_code' => $openPacking['order_code'],
                        'batch_id'   => (int)$openPacking['picking_batch_id'],
                        'station_id' => (int)$openPacking['station_id'],
                        'same_station' => (int)$openPacking['station_id'] === $stationId,
                        'message'    => 'Masz otwartą sesję pakowania: ' . $openPacking['order_code'],
                    ]
                ]);
                return;
            }

            // ----------------------------------------------------------------
            // 2. Picking otwarty — wróć do zbierania
            // ----------------------------------------------------------------
            $openPicking = $pickingRepo->findOpenBatchForUser($userId);
            if ($openPicking) {
                ApiResponse::ok([
                    'workflow' => [
                        'action'      => 'resume_picking',
                        'batch_id'    => (int)$openPicking['id'],
                        'carrier_key' => $openPicking['carrier_key'],
                        'message'     => 'Masz otwarty batch zbierania: ' . $openPicking['batch_code'],
                    ]
                ]);
                return;
            }

            // ----------------------------------------------------------------
            // 3. Picking zakończony, packing jeszcze nie ruszył
            //    (batch 'completed' ale są zamówienia bez packing_session completed)
            // ----------------------------------------------------------------
            $pendingPackingBatch = $pickingRepo->findCompletedBatchWithPendingPacking($userId);
            if ($pendingPackingBatch) {
                ApiResponse::ok([
                    'workflow' => [
                        'action'   => 'start_packing',
                        'batch_id' => (int)$pendingPackingBatch['id'],
                        'carrier_key' => $pendingPackingBatch['carrier_key'],
                        'message'  => 'Picking zakończony — spakuj zamówienia z batcha ' . $pendingPackingBatch['batch_code'],
                    ]
                ]);
                return;
            }

            // ----------------------------------------------------------------
            // 4. Czysto — zacznij nowe zbieranie
            // ----------------------------------------------------------------
            ApiResponse::ok([
                'workflow' => [
                    'action'  => 'start_picking',
                    'message' => 'Brak otwartych sesji — wybierz kuriera',
                ]
            ]);

        } catch (Throwable $e) {
            ApiResponse::error($e->getMessage(), 400);
        }
    }
}