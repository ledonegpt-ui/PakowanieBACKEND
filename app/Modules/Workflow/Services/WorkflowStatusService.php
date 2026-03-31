<?php
declare(strict_types=1);

final class WorkflowStatusService
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function resolveFromSession(array $currentSession): array
    {
        if (empty($currentSession['station_id'])) {
            throw new RuntimeException('Brak aktywnej stacji — wybierz stanowisko');
        }

        require_once BASE_PATH . '/app/Lib/Db.php';
        require_once BASE_PATH . '/app/Modules/Packing/Repositories/PackingRepository.php';
        require_once BASE_PATH . '/app/Modules/Picking/Repositories/PickingBatchRepository.php';
        require_once BASE_PATH . '/app/Modules/Carriers/Repositories/CarriersRepository.php';
        require_once BASE_PATH . '/app/Modules/Carriers/Services/CarriersService.php';
        require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';

        $db           = Db::mysql($this->cfg);
        $packingRepo  = new PackingRepository($db);
        $pickingRepo  = new PickingBatchRepository($db);
        $carriersRepo = new CarriersRepository($db);
        $carriersSvc  = new CarriersService($carriersRepo, $this->cfg['shipping_method_map'] ?? array());

        $userId    = (int)$currentSession['user_id'];
        $stationId = (int)$currentSession['station_id'];

        $workflowMode = isset($currentSession['workflow_mode']) ? trim((string)$currentSession['workflow_mode']) : 'integrated';
        if (!in_array($workflowMode, array('integrated', 'split'), true)) {
            $workflowMode = 'integrated';
        }

        $workMode = isset($currentSession['work_mode']) ? trim((string)$currentSession['work_mode']) : 'picker';
        if (!in_array($workMode, array('picker', 'packer'), true)) {
            $workMode = 'picker';
        }

        if ($workflowMode === 'integrated') {
            $workMode = 'picker';
        }

        // ----------------------------------------------------------------
        // 1. Otwarta sesja packingu
        // ----------------------------------------------------------------
        $openPacking = $packingRepo->findOpenSessionForUser($userId);
        if ($openPacking) {
            $sameStation = (int)$openPacking['station_id'] === $stationId;

            $workflow = array(
                'action'        => 'resume_packing',
                'order_code'    => (string)$openPacking['order_code'],
                'batch_id'      => (int)$openPacking['picking_batch_id'],
                'station_id'    => (int)$openPacking['station_id'],
                'same_station'  => $sameStation,
                'workflow_mode' => $workflowMode,
                'work_mode'     => $workMode,
                'message'       => 'Masz otwartą sesję pakowania: ' . $openPacking['order_code'],
            );

            if ($workflowMode === 'split' && $workMode !== 'packer') {
                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::goHome(
                        'Masz otwartą sesję pakowania, ale aktualnie pracujesz jako picker',
                        array(
                            'workflow_mode' => $workflowMode,
                            'work_mode'     => $workMode,
                        )
                    ),
                );
            }

            if (!$sameStation) {
                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::showModal(
                        'different_station',
                        'Zamówienie jest przypisane do innej stacji',
                        array(
                            'batch_id'      => (int)$openPacking['picking_batch_id'],
                            'order_code'    => (string)$openPacking['order_code'],
                            'workflow_mode' => $workflowMode,
                            'work_mode'     => $workMode,
                            'same_station'  => false,
                        )
                    ),
                );
            }

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::resumePacking(
                    (string)$openPacking['order_code'],
                    (int)$openPacking['picking_batch_id'],
                    'Wznów pakowanie',
                    array(
                        'workflow_mode' => $workflowMode,
                        'work_mode'     => $workMode,
                        'same_station'  => true,
                    )
                ),
            );
        }

        // ----------------------------------------------------------------
        // 2. Otwarty batch pickingu
        // ----------------------------------------------------------------
        $openPicking = $pickingRepo->findOpenBatchForUser($userId);
        if ($openPicking) {
            $workflow = array(
                'action'        => 'resume_picking',
                'batch_id'      => (int)$openPicking['id'],
                'carrier_key'   => (string)$openPicking['carrier_key'],
                'workflow_mode' => $workflowMode,
                'work_mode'     => $workMode,
                'message'       => 'Masz otwarty batch zbierania: ' . $openPicking['batch_code'],
            );

            if ($workflowMode === 'split' && $workMode !== 'picker') {
                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::goHome(
                        'Masz otwarty batch zbierania, ale aktualnie pracujesz jako packer',
                        array(
                            'workflow_mode' => $workflowMode,
                            'work_mode'     => $workMode,
                        )
                    ),
                );
            }

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::resumePicking(
                    (int)$openPicking['id'],
                    'Wznów zbieranie batcha',
                    array(
                        'workflow_mode' => $workflowMode,
                        'work_mode'     => $workMode,
                    )
                ),
            );
        }

        // ----------------------------------------------------------------
        // 3. Batch gotowy do pakowania
        // ----------------------------------------------------------------
        $pendingPackingBatch = $pickingRepo->findCompletedBatchWithPendingPacking($userId);
        if ($pendingPackingBatch) {
            if ($workflowMode === 'split' && $workMode === 'picker') {
                $workflow = array(
                    'action'        => 'start_picking',
                    'workflow_mode' => $workflowMode,
                    'work_mode'     => $workMode,
                    'message'       => 'Masz rolę pickera — wybierz kuriera do kolejnego zbierania',
                );

                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::showCarrierQueue(
                        'Batch jest gotowy do pakowania przez packera. Ty możesz rozpocząć kolejne zbieranie.',
                        array(
                            'workflow_mode' => $workflowMode,
                            'work_mode'     => $workMode,
                        )
                    ),
                );
            }

            $workflow = array(
                'action'        => 'start_packing',
                'batch_id'      => (int)$pendingPackingBatch['id'],
                'workflow_mode' => $workflowMode,
                'work_mode'     => $workMode,
                'message'       => 'Picking zakończony — spakuj zamówienia z batcha ' . $pendingPackingBatch['batch_code'],
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::openPacking(
                    (int)$pendingPackingBatch['id'],
                    null,
                    'Znajdź koszyk do zapakowania',
                    array(
                        'workflow_mode' => $workflowMode,
                        'work_mode'     => $workMode,
                        'same_station'  => true,
                    )
                ),
            );
        }

        // ----------------------------------------------------------------
        // 4. Czysty start bez otwartych sesji
        // ----------------------------------------------------------------
        if ($workflowMode === 'split' && $workMode === 'packer') {
            $workflow = array(
                'action'        => 'start_packing',
                'workflow_mode' => $workflowMode,
                'work_mode'     => $workMode,
                'message'       => 'Pracujesz jako packer — sprawdź gotowe koszyki do pakowania',
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::openPacking(
                    null,
                    null,
                    'Znajdź koszyk do zapakowania',
                    array(
                        'workflow_mode' => $workflowMode,
                        'work_mode'     => $workMode,
                        'same_station'  => null,
                    )
                ),
            );
        }

        if ($workflowMode === 'split' && $workMode === 'picker') {
            $workflow = array(
                'action'        => 'start_picking',
                'workflow_mode' => $workflowMode,
                'work_mode'     => $workMode,
                'message'       => 'Brak otwartych sesji — wybierz kuriera',
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::showCarrierQueue(
                    'Pracujesz jako picker — wybierz kuriera do zbierania',
                    array(
                        'workflow_mode' => $workflowMode,
                        'work_mode'     => $workMode,
                    )
                ),
            );
        }

        $carrierQueue = $carriersSvc->listQueueSummary();
        if (!empty($carrierQueue)) {
            $workflow = array(
                'action'        => 'start_picking',
                'workflow_mode' => $workflowMode,
                'work_mode'     => $workMode,
                'message'       => 'Wybierz kuriera do rozpoczęcia zbierania',
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::showCarrierQueue(
                    'Wybierz kuriera do rozpoczęcia zbierania',
                    array(
                        'workflow_mode' => $workflowMode,
                        'work_mode'     => $workMode,
                    )
                ),
            );
        }

        $noOrdersMessage = $this->resolveNoOrdersMessage($db);

        $workflow = array(
            'action'        => 'go_home',
            'workflow_mode' => $workflowMode,
            'work_mode'     => $workMode,
            'message'       => $noOrdersMessage,
        );

        return array(
            'workflow' => $workflow,
            'next_action' => NextActionResolver::goHome(
                $noOrdersMessage,
                array(
                    'workflow_mode' => $workflowMode,
                    'work_mode'     => $workMode,
                )
            ),
        );
    }

    private function resolveNoOrdersMessage(PDO $db): string
    {
        $totalOpenOrders = (int)$db->query("
            SELECT COUNT(*)
            FROM pak_orders
            WHERE status = 10
        ")->fetchColumn();

        if ($totalOpenOrders <= 0) {
            return 'Brak zamówień do zebrania';
        }

        $ordersInOpenPicking = (int)$db->query("
            SELECT COUNT(DISTINCT pbo.order_code)
            FROM picking_batch_orders pbo
            INNER JOIN picking_batches pb ON pb.id = pbo.batch_id
            WHERE pb.status = 'open'
              AND pbo.status NOT IN ('dropped')
        ")->fetchColumn();

        if ($ordersInOpenPicking >= $totalOpenOrders) {
            return 'Wszystkie zamówienia są już w trakcie zbierania';
        }

        $ordersBlockedByBacklog = (int)$db->query("
            SELECT COUNT(DISTINCT obh.order_code)
            FROM order_backlog_holds obh
            INNER JOIN pak_orders po ON po.order_code = obh.order_code
            WHERE po.status = 10
              AND obh.status = 'open'
        ")->fetchColumn();

        if ($ordersBlockedByBacklog >= $totalOpenOrders) {
            return 'Zamówienia są zablokowane — sprawdź backlog';
        }

        return 'Brak zamówień pasujących do bieżącej konfiguracji';
    }
}
