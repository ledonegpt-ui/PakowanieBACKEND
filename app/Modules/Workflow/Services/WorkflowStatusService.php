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
        require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';

        $db          = Db::mysql($this->cfg);
        $packingRepo = new PackingRepository($db);
        $pickingRepo = new PickingBatchRepository($db);

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
        // 1. Packing otwarty
        // ----------------------------------------------------------------
        $openPacking = $packingRepo->findOpenSessionForUser($userId);
        if ($openPacking) {
            if ($workflowMode === 'split' && $workMode !== 'packer') {
                $workflow = array(
                    'action' => 'go_home',
                    'message' => 'Masz otwartą sesję pakowania, ale aktualnie pracujesz jako picker',
                );

                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::showModal(
                        'work_mode_conflict',
                        'Masz otwartą sesję pakowania. Zmień tryb pracy na packer, aby ją wznowić.',
                        array(
                            'current_work_mode' => $workMode,
                            'required_work_mode' => 'packer',
                            'order_code' => (string)$openPacking['order_code'],
                            'batch_id' => (int)$openPacking['picking_batch_id'],
                        )
                    ),
                );
            }

            $workflow = array(
                'action'       => 'resume_packing',
                'order_code'   => $openPacking['order_code'],
                'batch_id'     => (int)$openPacking['picking_batch_id'],
                'station_id'   => (int)$openPacking['station_id'],
                'same_station' => (int)$openPacking['station_id'] === $stationId,
                'message'      => 'Masz otwartą sesję pakowania: ' . $openPacking['order_code'],
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::fromLegacyWorkflowAction($workflow),
            );
        }

        // ----------------------------------------------------------------
        // 2. Picking otwarty
        // ----------------------------------------------------------------
        $openPicking = $pickingRepo->findOpenBatchForUser($userId);
        if ($openPicking) {
            if ($workflowMode === 'split' && $workMode !== 'picker') {
                $workflow = array(
                    'action' => 'go_home',
                    'message' => 'Masz otwarty batch zbierania, ale aktualnie pracujesz jako packer',
                );

                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::showModal(
                        'work_mode_conflict',
                        'Masz otwarty batch zbierania. Zmień tryb pracy na picker, aby go wznowić.',
                        array(
                            'current_work_mode' => $workMode,
                            'required_work_mode' => 'picker',
                            'batch_id' => (int)$openPicking['id'],
                            'carrier_key' => (string)$openPicking['carrier_key'],
                        )
                    ),
                );
            }

            $workflow = array(
                'action'      => 'resume_picking',
                'batch_id'    => (int)$openPicking['id'],
                'carrier_key' => $openPicking['carrier_key'],
                'message'     => 'Masz otwarty batch zbierania: ' . $openPicking['batch_code'],
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::fromLegacyWorkflowAction($workflow),
            );
        }

        // ----------------------------------------------------------------
        // 3. Picking zakończony, packing jeszcze nie ruszył
        // ----------------------------------------------------------------
        $pendingPackingBatch = $pickingRepo->findCompletedBatchWithPendingPacking($userId);
        if ($pendingPackingBatch) {
            if ($workflowMode === 'split' && $workMode === 'picker') {
                $workflow = array(
                    'action'  => 'start_picking',
                    'message' => 'Masz rolę pickera — wybierz kuriera do kolejnego zbierania',
                );

                return array(
                    'workflow' => $workflow,
                    'next_action' => NextActionResolver::showCarrierQueue(
                        'Batch jest gotowy do pakowania przez packera. Ty możesz rozpocząć kolejne zbieranie.'
                    ),
                );
            }

            $workflow = array(
                'action'      => 'start_packing',
                'batch_id'    => (int)$pendingPackingBatch['id'],
                'carrier_key' => $pendingPackingBatch['carrier_key'],
                'message'     => 'Picking zakończony — spakuj zamówienia z batcha ' . $pendingPackingBatch['batch_code'],
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::fromLegacyWorkflowAction($workflow),
            );
        }

        // ----------------------------------------------------------------
        // 4. Czysty start bez otwartych sesji
        // ----------------------------------------------------------------
        if ($workflowMode === 'split' && $workMode === 'packer') {
            $workflow = array(
                'action'  => 'start_packing',
                'message' => 'Pracujesz jako packer — sprawdź gotowe koszyki do pakowania',
            );

            return array(
                'workflow' => $workflow,
                'next_action' => NextActionResolver::openPacking('Znajdź koszyk do zapakowania'),
            );
        }

        $workflow = array(
            'action'  => 'start_picking',
            'message' => 'Brak otwartych sesji — wybierz kuriera',
        );

        return array(
            'workflow' => $workflow,
            'next_action' => NextActionResolver::showCarrierQueue(
                $workflowMode === 'split'
                    ? 'Pracujesz jako picker — wybierz kuriera do zbierania'
                    : 'Wybierz kuriera, aby rozpocząć workflow'
            ),
        );
    }
}
