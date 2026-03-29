<?php
declare(strict_types=1);

final class PickingBatchService
{
    /** @var PickingBatchRepository */
    private $repo;

    /** @var array */
    private $mapCfg;

    /** @var array */
    private $cfg;

    public function __construct(PickingBatchRepository $repo, array $mapCfg, array $cfg = [])
    {
        $this->repo   = $repo;
        $this->mapCfg = $mapCfg;
        $this->cfg    = $cfg;
    }

    public function openBatch(array $session, array $body): array
    {
        $carrierKey        = trim((string)($body['carrier_key'] ?? ''));
        $selectionMode    = trim((string)($body['selection_mode'] ?? 'cutoff_cluster'));
        $sessionPickingBatchSize = isset($session['picking_batch_size']) ? (int)$session['picking_batch_size'] : (int)($this->cfg['picking_batch_size'] ?? 3);
        if ($sessionPickingBatchSize < 1) {
            $sessionPickingBatchSize = (int)($this->cfg['picking_batch_size'] ?? 3);
        }
        if ($sessionPickingBatchSize < 1) {
            $sessionPickingBatchSize = 1;
        }

        $targetOrdersCount = (int)($body['target_orders_count'] ?? $sessionPickingBatchSize);

        if ($carrierKey === '') {
            throw new RuntimeException('Missing carrier_key');
        }
        if (!in_array($selectionMode, array('cutoff', 'cutoff_cluster', 'emergency_single'), true)) {
            $selectionMode = 'cutoff';
        }

        if ($selectionMode === 'emergency_single') {
            $targetOrdersCount = 1;
        }

        if ($targetOrdersCount < 1 || $targetOrdersCount > 50) {
            $targetOrdersCount = 10;
        }

        $packageMode = isset($session['package_mode']) ? trim((string)$session['package_mode']) : 'small';
        if (!in_array($packageMode, array('small', 'large'), true)) {
            $packageMode = 'small';
        }

        $workflowMode = isset($session['workflow_mode']) ? trim((string)$session['workflow_mode']) : 'integrated';
        if (!in_array($workflowMode, array('integrated', 'split'), true)) {
            $workflowMode = 'integrated';
        }

        $workMode = isset($session['work_mode']) ? trim((string)$session['work_mode']) : 'picker';
        if (!in_array($workMode, array('picker', 'packer'), true)) {
            $workMode = 'picker';
        }

        if ($workflowMode === 'split' && $workMode !== 'picker') {
            return [
                'status' => 'blocked',
                'next_action' => $this->buildPickingRoleBlockedAction($workMode),
            ];
        }

        $this->repo->beginTransaction();
        try {
            $existing = $this->repo->findOpenBatchForUserForUpdate((int)$session['user_id']);
            if ($existing) {
                $existingBatchId = (int)$existing['id'];
                $this->repo->rollback();
                return $this->getBatchDetail($existingBatchId);
            }

            $basket = null;
            if ($workflowMode === 'split') {
                $basket = $this->repo->findFreeBasketForUpdate($packageMode);
                if (!$basket) {
                    throw new RuntimeException('No free baskets for package_mode: ' . $packageMode);
                }
            }

            $excludedCodes = $this->repo->getOrderCodesInOpenBatches();
            $orders = $this->selectOrdersForBatch($carrierKey, $packageMode, $excludedCodes, $targetOrdersCount, $selectionMode);

            if (empty($orders)) {
                throw new RuntimeException('No available orders for carrier_key: ' . $carrierKey);
            }

            $batchCode = 'BATCH-' . time() . '-' . $session['user_id'];
            $batchId = $this->repo->createBatch(
                $batchCode,
                $carrierKey,
                $packageMode,
                (int)$session['user_id'],
                (int)$session['station_id'],
                $workflowMode,
                $selectionMode,
                $targetOrdersCount
            );

            if ($workflowMode === 'split' && $basket) {
                $this->repo->reserveBasketForBatch((int)$basket['id'], $batchId, (int)$session['user_id']);
                $this->repo->attachBasketToBatch($batchId, (int)$basket['id']);
            }

            foreach ($orders as $order) {
                $batchOrderId = $this->repo->insertBatchOrder($batchId, $order['order_code']);
                $this->insertOrderItems($batchOrderId, $order['order_code']);
            }

            $this->repo->rebuildBatchItems($batchId);

            $this->repo->logEvent(
                $batchId, 'batch_opened', null, null,
                'Batch opened with ' . count($orders) . ' orders',
                [
                    'batch_id'       => $batchId,
                    'carrier_key'    => $carrierKey,
                    'package_mode'   => $packageMode,
                    'selection_mode' => $selectionMode,
                    'orders_count'   => count($orders),
                    'user_id'        => (int)$session['user_id'],
                ],
                (int)$session['user_id']
            );

            $this->repo->commit();
            return $this->getBatchDetail($batchId);

        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    public function currentBatch(array $session): ?array
    {
        $batch = $this->repo->findOpenBatchForUser((int)$session['user_id']);
        if (!$batch) {
            return null;
        }
        return $this->getBatchDetail((int)$batch['id']);
    }

    public function showBatch(int $batchId, array $session): array
    {
        $batch = $this->repo->findBatchById($batchId);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }
        $this->assertBatchOwner($batch, $session);
        return $this->getBatchDetail($batchId);
    }

    public function getBatchOrders(int $batchId, array $session): array
    {
        $batch = $this->repo->findBatchById($batchId);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }
        $this->assertBatchOwner($batch, $session);
        return $this->repo->getBatchOrders($batchId);
    }

    public function getBatchProducts(int $batchId, array $session): array
    {
        $batch = $this->repo->findBatchById($batchId);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }
        $this->assertBatchOwner($batch, $session);
        return $this->repo->getBatchItems($batchId);
    }

    public function markPicked(int $batchOrderId, int $itemId, array $session): array
    {
        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findOpenBatchForUserForUpdate((int)$session['user_id']);
            if (!$batch) {
                throw new RuntimeException('No open batch for operator');
            }

            $batchOrder = $this->repo->findBatchOrderById((int)$batch['id'], $batchOrderId);
            if (!$batchOrder) {
                throw new RuntimeException('Order not found in batch: ' . $batchOrderId);
            }
            if ($batchOrder['status'] === 'dropped') {
                throw new RuntimeException('Order already dropped: ' . $batchOrderId);
            }

            $item = $this->repo->findPickingOrderItemById((int)$batchOrder['id'], $itemId);
            if (!$item) {
                throw new RuntimeException('Item not found: ' . $itemId);
            }

            if (in_array((string)$item['status'], array('missing', 'pre_missing'), true)) {
                throw new RuntimeException('Item is blocked by missing flow: ' . $itemId);
            }

            $this->repo->markItemPicked((int)$item['id'], (float)$item['expected_qty'], (int)$session['user_id']);

            $pendingCount = $this->repo->countPendingItemsForBatchOrder((int)$batchOrder['id']);
            if ($pendingCount === 0) {
                $this->repo->markBatchOrderPicked((int)$batchOrder['id']);
            }

            $this->repo->rebuildBatchItems((int)$batch['id']);

            $this->repo->logEvent(
                (int)$batch['id'], 'item_picked',
                (int)$batchOrder['id'], (int)$item['id'],
                'Item picked: ' . $item['product_code'],
                [
                    'batch_id'          => (int)$batch['id'],
                    'batch_order_id'    => $batchOrderId,
                    'order_code'        => (string)$batchOrder['order_code'],
                    'picking_item_id'   => $itemId,
                    'pak_order_item_id' => (int)$item['pak_order_item_id'],
                    'subiekt_tow_id'    => isset($item['subiekt_tow_id']) && $item['subiekt_tow_id'] !== null
                        ? (int)$item['subiekt_tow_id']
                        : null,
                    'product_code'      => (string)$item['product_code'],
                    'uom'               => $item['uom'] !== null ? (string)$item['uom'] : null,
                    'is_unmapped'       => (bool)($item['is_unmapped'] ?? false),
                    'expected_qty'      => (float)$item['expected_qty'],
                    'picked_qty'        => (float)$item['expected_qty'],
                    'user_id'           => (int)$session['user_id'],
                ],
                (int)$session['user_id']
            );

            $this->repo->commit();

            return [
                'order_id'     => $batchOrderId,
                'order_code'   => (string)$batchOrder['order_code'],
                'item_id'      => $itemId,
                'pak_item_id'  => (int)$item['pak_order_item_id'],
                'status'       => 'picked',
                'order_status' => $pendingCount === 0 ? 'picked' : 'assigned',
                'next_action'  => $this->buildResumePickingAction(
                    (int)$batch['id'],
                    $pendingCount === 0
                        ? 'Pozycja zakończona — kontynuuj zbieranie batcha'
                        : 'Kontynuuj zbieranie batcha'
                ),
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }


    public function markMissing(int $batchOrderId, int $itemId, array $body, array $session): array
    {
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Missing reason for missing item');
        }

        $holdType = trim((string)($body['hold_type'] ?? 'other'));
        if (!in_array($holdType, array('production', 'supplier', 'other'), true)) {
            $holdType = 'other';
        }

        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findOpenBatchForUserForUpdate((int)$session['user_id']);
            if (!$batch) {
                throw new RuntimeException('No open batch for operator');
            }

            $batchOrder = $this->repo->findBatchOrderById((int)$batch['id'], $batchOrderId);
            if (!$batchOrder) {
                throw new RuntimeException('Order not found in batch: ' . $batchOrderId);
            }
            if ($batchOrder['status'] === 'dropped') {
                throw new RuntimeException('Order already dropped: ' . $batchOrderId);
            }

            $item = $this->repo->findPickingOrderItemById((int)$batchOrder['id'], $itemId);
            if (!$item) {
                throw new RuntimeException('Item not found: ' . $itemId);
            }

            $this->repo->markItemMissing((int)$item['id'], $reason, (int)$session['user_id']);
            $this->repo->markRemainingItemsPreMissing((int)$batchOrder['id'], (int)$item['id'], (int)$session['user_id']);

            $this->repo->createBacklogHold(
                (string)$batchOrder['order_code'],
                (int)$item['pak_order_item_id'],
                isset($item['subiekt_tow_id']) && $item['subiekt_tow_id'] !== null ? (int)$item['subiekt_tow_id'] : null,
                isset($item['product_code']) && $item['product_code'] !== null && trim((string)$item['product_code']) !== ''
                    ? (string)$item['product_code']
                    : null,
                (string)$item['product_name'],
                (float)$item['expected_qty'],
                $holdType,
                $reason,
                (int)$session['user_id'],
                'draft'
            );

            $this->repo->logEvent(
                (int)$batch['id'], 'item_missing',
                (int)$batchOrder['id'], (int)$item['id'],
                'Item missing -> draft backlog: ' . $item['product_code'],
                [
                    'batch_id'          => (int)$batch['id'],
                    'batch_order_id'    => $batchOrderId,
                    'order_code'        => (string)$batchOrder['order_code'],
                    'picking_item_id'   => $itemId,
                    'pak_order_item_id' => (int)$item['pak_order_item_id'],
                    'subiekt_tow_id'    => isset($item['subiekt_tow_id']) && $item['subiekt_tow_id'] !== null
                        ? (int)$item['subiekt_tow_id']
                        : null,
                    'product_code'      => (string)$item['product_code'],
                    'product_name'      => (string)$item['product_name'],
                    'uom'               => $item['uom'] !== null ? (string)$item['uom'] : null,
                    'is_unmapped'       => (bool)($item['is_unmapped'] ?? false),
                    'expected_qty'      => (float)$item['expected_qty'],
                    'reason'            => $reason,
                    'hold_type'         => $holdType,
                    'backlog_status'    => 'draft',
                    'user_id'           => (int)$session['user_id'],
                    'mail_sent'         => false,
                    'order_status'      => 'assigned',
                    'workflow_state'    => 'missing_review',
                ],
                (int)$session['user_id']
            );

            $this->repo->commit();

            return [
                'order_id'       => $batchOrderId,
                'order_code'     => (string)$batchOrder['order_code'],
                'item_id'        => $itemId,
                'pak_item_id'    => (int)$item['pak_order_item_id'],
                'status'         => 'missing',
                'order_status'   => 'assigned',
                'workflow_state' => 'missing_review',
                'hold_type'      => $holdType,
                'mail_sent'      => false,
                'next_action'    => $this->buildResumePickingAction(
                    (int)$batch['id'],
                    'Brak zapisany — kontynuuj zbieranie batcha'
                ),
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    public function dropOrderManual(int $batchOrderId, array $body, array $session): array
    {
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Missing reason for drop');
        }

        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findOpenBatchForUserForUpdate((int)$session['user_id']);
            if (!$batch) {
                throw new RuntimeException('No open batch for operator');
            }

            $batchOrder = $this->repo->findBatchOrderById((int)$batch['id'], $batchOrderId);
            if (!$batchOrder) {
                throw new RuntimeException('Order not found in batch: ' . $batchOrderId);
            }

            $this->dropOrder((string)$batchOrder['order_code'], $reason, $session, $batch);
            $this->doRefill((int)$batch['id'], $session, false);

            $this->repo->commit();

            return [
                'order_id'    => $batchOrderId,
                'order_code'  => (string)$batchOrder['order_code'],
                'status'      => 'dropped',
                'reason'      => $reason,
                'next_action' => $this->buildResumePickingAction(
                    (int)$batch['id'],
                    'Zamówienie odłożone — kontynuuj zbieranie batcha'
                ),
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }


    public function refillBatch(int $batchId, array $session): array
    {
        $batch = $this->repo->findBatchById($batchId);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }
        $this->assertBatchOwner($batch, $session);
        return $this->doRefill($batchId, $session);
    }

    public function updateSelectionMode(int $batchId, array $body, array $session): array
    {
        $selectionMode = trim((string)($body['selection_mode'] ?? ''));
        if (!in_array($selectionMode, array('cutoff', 'cutoff_cluster', 'emergency_single'), true)) {
            throw new RuntimeException('Invalid selection_mode');
        }

        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findBatchByIdForUpdate($batchId);
            if (!$batch) {
                throw new RuntimeException('Batch not found: ' . $batchId);
            }
            $this->assertBatchOwner($batch, $session);

            if ($batch['status'] !== 'open') {
                throw new RuntimeException('Batch is not open');
            }

            $this->repo->updateBatchSelectionMode($batchId, $selectionMode);

            $this->repo->logEvent(
                $batchId,
                'selection_mode_changed',
                null,
                null,
                'Selection mode changed to ' . $selectionMode,
                array(
                    'batch_id' => $batchId,
                    'selection_mode' => $selectionMode,
                    'user_id' => (int)$session['user_id'],
                ),
                (int)$session['user_id']
            );

            $this->repo->commit();

            return array(
                'batch_id' => $batchId,
                'selection_mode' => $selectionMode,
                'status' => 'updated',
                'next_action' => $this->buildResumePickingAction(
                    $batchId,
                    'Tryb doboru zaktualizowany — kontynuuj zbieranie batcha'
                ),
            );
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    public function abandonBatch(int $batchId, array $session): array
    {
        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findBatchByIdForUpdate($batchId);
            if (!$batch) {
                throw new RuntimeException('Batch not found: ' . $batchId);
            }
            $this->assertBatchOwner($batch, $session);

            if ($batch['status'] !== 'open') {
                throw new RuntimeException('Batch is not open');
            }

            if (!empty($batch['basket_id'])) {
                $this->repo->releaseBasketReservation((int)$batch['basket_id']);
                $this->repo->clearBatchBasket($batchId);
            }

            $this->repo->abandonBatch($batchId);

            $this->repo->logEvent(
                $batchId, 'batch_abandoned', null, null,
                'Batch abandoned by operator',
                [
                    'batch_id' => $batchId,
                    'reason'   => 'manual',
                    'user_id'  => (int)$session['user_id'],
                ],
                (int)$session['user_id']
            );

            $this->repo->commit();

            require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';

            return [
                'batch_id' => $batchId,
                'status' => 'abandoned',
                'next_action' => NextActionResolver::goHome('Batch porzucony — wróć do menu'),
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }


    public function closeBatch(int $batchId, array $session): array
    {
        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findBatchByIdForUpdate($batchId);
            if (!$batch) {
                throw new RuntimeException('Batch not found: ' . $batchId);
            }
            $this->assertBatchOwner($batch, $session);

            if ($batch['status'] !== 'open') {
                throw new RuntimeException('Batch is not open');
            }

            $orders = $this->repo->getBatchOrders($batchId);
            $backlogOrdersCount = 0;
            $activatedDraftHolds = 0;

            foreach ($orders as $order) {
                $hasMissingFlow = false;
                foreach (($order['items'] ?? array()) as $item) {
                    if (in_array((string)$item['status'], array('missing', 'pre_missing'), true)) {
                        $hasMissingFlow = true;
                        break;
                    }
                }

                if (!$hasMissingFlow) {
                    continue;
                }

                $activatedDraftHolds += $this->repo->activateDraftBacklogHoldsForOrder((string)$order['order_code']);

                foreach (($order['items'] ?? array()) as $item) {
                    if ((string)$item['status'] !== 'missing') {
                        continue;
                    }

                    $this->notifyMissingByEmail(
                        $batch,
                        $order,
                        $item,
                        (string)($item['missing_reason'] ?? ''),
                        $session
                    );
                }

                $this->dropOrder(
                    (string)$order['order_code'],
                    'missing_finalize',
                    $session,
                    $batch
                );

                $backlogOrdersCount++;
            }

            $stats = $this->repo->getBatchStats($batchId);
            if ((int)$stats['assigned_count'] > 0) {
                $assignedCount = (int)$stats['assigned_count'];
                $this->repo->rollback();

                return [
                    'batch_id' => $batchId,
                    'status' => 'blocked',
                    'assigned_count' => $assignedCount,
                    'next_action' => $this->buildPickingBlockedModalAction(
                        'batch_has_unpicked_orders',
                        'Nie można zamknąć batcha. Nadal są ' . $assignedCount . ' niepobrane zamówienia.',
                        array(
                            'assigned_count' => $assignedCount,
                        )
                    ),
                ];
            }

            $workflowMode = isset($batch['workflow_mode']) ? trim((string)$batch['workflow_mode']) : 'integrated';
            if ($workflowMode === 'split' && !empty($batch['basket_id'])) {
                if ((int)$stats['picked_count'] > 0) {
                    $this->repo->markBasketPickedReady((int)$batch['basket_id']);
                } else {
                    $this->repo->releaseBasketReservation((int)$batch['basket_id']);
                    $this->repo->clearBatchBasket($batchId);
                }
            }

            $this->repo->closeBatch($batchId);

            $this->repo->logEvent(
                $batchId, 'batch_closed', null, null,
                'Batch closed by operator',
                [
                    'batch_id'              => $batchId,
                    'picked_count'          => (int)$stats['picked_count'],
                    'dropped_count'         => (int)$stats['dropped_count'],
                    'backlog_orders_count'  => $backlogOrdersCount,
                    'activated_draft_holds' => $activatedDraftHolds,
                    'user_id'               => (int)$session['user_id'],
                ],
                (int)$session['user_id']
            );

            $this->repo->commit();

            return [
                'batch_id'              => $batchId,
                'status'                => 'completed',
                'backlog_orders_count'  => $backlogOrdersCount,
                'activated_draft_holds' => $activatedDraftHolds,
                'next_action'           => $this->buildPostCloseBatchAction($batch, $session, $batchId),
            ];
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    public function getBacklogSummary(array $session): array
    {
        return $this->repo->getBacklogSummary();
    }

    public function getBacklogProducts(array $session): array
    {
        return $this->repo->getBacklogProducts();
    }

    public function resolveBacklogByItemKey(string $itemKey, array $session): array
    {
        $this->repo->beginTransaction();
        try {
            $result = $this->repo->resolveBacklogByItemKey($itemKey, (int)$session['user_id']);
            $this->repo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    public function getBacklogOrdersByItemKey(string $itemKey, array $session): array
    {
        return $this->repo->getBacklogOrdersByItemKey($itemKey);
    }

    private function doRefill(int $batchId, array $session, bool $manageTransaction = true): array
    {
        if ($manageTransaction) {
            $this->repo->beginTransaction();
        }

        try {
            $batch = $this->repo->findBatchByIdForUpdate($batchId);
            if (!$batch || $batch['status'] !== 'open') {
                if ($manageTransaction) {
                    $this->repo->rollback();
                }
                return ['refilled' => 0, 'active_orders' => 0];
            }

            $activeCount = $this->repo->countActiveBatchOrders($batchId);
            $target      = (int)$batch['target_orders_count'];
            $needed      = $target - $activeCount;

            if ($needed <= 0) {
                if ($manageTransaction) {
                    $this->repo->rollback();
                }
                return ['refilled' => 0, 'active_orders' => $activeCount];
            }

            $allInThisBatch       = $this->repo->getAllOrderCodesInBatch($batchId);
            $activeInOtherBatches = $this->repo->getOrderCodesInOpenBatches();
            $excludedCodes        = array_values(array_unique(array_merge($allInThisBatch, $activeInOtherBatches)));

            $selectionMode = isset($batch['selection_mode']) && trim((string)$batch['selection_mode']) !== ''
                ? trim((string)$batch['selection_mode'])
                : 'cutoff';

            if ($selectionMode === 'emergency_single') {
                $needed = min($needed, 1);
            }

            $packageMode = isset($batch['package_mode']) ? trim((string)$batch['package_mode']) : '';
            if ($packageMode === '') {
                $packageMode = isset($session['package_mode']) ? trim((string)$session['package_mode']) : 'small';
            }
            if (!in_array($packageMode, array('small', 'large'), true)) {
                $packageMode = 'small';
            }

            $newOrders = $this->selectOrdersForBatch((string)$batch['carrier_key'], $packageMode, $excludedCodes, $needed, $selectionMode);

            foreach ($newOrders as $order) {
                $batchOrderId = $this->repo->insertBatchOrder($batchId, $order['order_code']);
                $this->insertOrderItems($batchOrderId, $order['order_code']);
            }

            $this->repo->rebuildBatchItems($batchId);

            if (!empty($newOrders)) {
                $this->repo->logEvent(
                    $batchId, 'batch_refilled', null, null,
                    'Refilled with ' . count($newOrders) . ' orders',
                    [
                        'batch_id'            => $batchId,
                        'selection_mode'      => $selectionMode,
                        'package_mode'        => $packageMode,
                        'added_orders_count'  => count($newOrders),
                        'target_orders_count' => $target,
                        'user_id'             => (int)$session['user_id'],
                    ],
                    (int)$session['user_id']
                );
            }

            if ($manageTransaction) {
                $this->repo->commit();
            }

            return ['refilled' => count($newOrders), 'active_orders' => $activeCount + count($newOrders)];

        } catch (Throwable $e) {
            if ($manageTransaction) {
                $this->repo->rollback();
            }
            throw $e;
        }
    }

    private function dropOrder(string $orderCode, string $reason, array $session, array $batch): void
    {
        $batchOrder = $this->repo->findBatchOrder((int)$batch['id'], $orderCode);
        if (!$batchOrder || $batchOrder['status'] === 'dropped') {
            return;
        }

        $this->repo->dropBatchOrder((int)$batchOrder['id'], $reason);
        $this->repo->rebuildBatchItems((int)$batch['id']);

        $this->repo->logEvent(
            (int)$batch['id'], 'order_dropped',
            (int)$batchOrder['id'], null,
            'Order dropped: ' . $orderCode,
            [
                'batch_id'   => (int)$batch['id'],
                'order_code' => $orderCode,
                'reason'     => $reason,
                'source'     => strpos($reason, 'missing_item') === 0 ? 'missing' : 'manual',
                'user_id'    => (int)$session['user_id'],
            ],
            (int)$session['user_id']
        );
    }

    public function diagnoseOpenBatchUnavailable(string $carrierKey, string $packageMode): array
    {
        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($this->mapCfg);

        $rows = $this->repo->getOpenOrdersDiagnosticRows();
        if (empty($rows)) {
            return [
                'reason' => 'no_orders',
                'carrier_key' => $carrierKey,
                'package_mode' => $packageMode,
                'counts' => [
                    'status10_total' => 0,
                    'carrier_match_total' => 0,
                    'carrier_package_mode_match' => 0,
                    'available_now' => 0,
                    'backlog_blocked' => 0,
                    'open_batch_blocked' => 0,
                    'wrong_package_mode' => 0,
                ],
            ];
        }

        $orderCodes = array();
        foreach ($rows as $row) {
            $orderCodes[] = (string)$row['order_code'];
        }
        $orderPackageModes = $this->repo->getOrderPackageModes($orderCodes);

        $carrierMatchTotal = 0;
        $carrierPackageModeMatch = 0;
        $availableNow = 0;
        $backlogBlocked = 0;
        $openBatchBlocked = 0;
        $wrongPackageMode = 0;

        foreach ($rows as $row) {
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($row['delivery_method'] ?? ''),
                'carrier_code'    => (string)($row['carrier_code'] ?? ''),
                'courier_code'    => (string)($row['courier_code'] ?? ''),
            ]);

            if ((string)($resolved['menu_group'] ?? '') !== $carrierKey) {
                continue;
            }

            $carrierMatchTotal++;

            $orderCode = (string)$row['order_code'];
            $orderPackageMode = isset($orderPackageModes[$orderCode])
                ? (string)$orderPackageModes[$orderCode]
                : 'unknown';

            if ($orderPackageMode !== $packageMode) {
                $wrongPackageMode++;
                continue;
            }

            $carrierPackageModeMatch++;

            $hasOpenBacklog = (int)($row['has_open_backlog'] ?? 0) === 1;
            $inOpenBatch = (int)($row['in_open_batch'] ?? 0) === 1;

            if ($hasOpenBacklog) {
                $backlogBlocked++;
                continue;
            }

            if ($inOpenBatch) {
                $openBatchBlocked++;
                continue;
            }

            $availableNow++;
        }

        $reason = 'no_available_orders';
        if ($carrierMatchTotal === 0) {
            $reason = 'no_orders_for_carrier';
        } elseif ($carrierPackageModeMatch === 0) {
            $reason = 'no_orders_for_package_mode';
        } elseif ($availableNow > 0) {
            $reason = 'available';
        } elseif ($backlogBlocked > 0 && $openBatchBlocked === 0) {
            $reason = 'only_backlog';
        } elseif ($openBatchBlocked > 0 && $backlogBlocked === 0) {
            $reason = 'all_reserved_in_open_batches';
        } elseif ($backlogBlocked > 0 && $openBatchBlocked > 0) {
            $reason = 'blocked_by_backlog_and_open_batches';
        }

        return [
            'reason' => $reason,
            'carrier_key' => $carrierKey,
            'package_mode' => $packageMode,
            'counts' => [
                'status10_total' => count($rows),
                'carrier_match_total' => $carrierMatchTotal,
                'carrier_package_mode_match' => $carrierPackageModeMatch,
                'available_now' => $availableNow,
                'backlog_blocked' => $backlogBlocked,
                'open_batch_blocked' => $openBatchBlocked,
                'wrong_package_mode' => $wrongPackageMode,
            ],
        ];
    }

    private function selectOrdersForBatch(string $carrierKey, string $packageMode, array $excludedCodes, int $limit, string $selectionMode): array
    {
        if ($limit <= 0) {
            return array();
        }

        if ($selectionMode === 'emergency_single') {
            return $this->repo->findAvailableOrdersForGroupEmergencySingle($carrierKey, $packageMode, $excludedCodes, $this->mapCfg);
        }

        if ($selectionMode === 'cutoff_cluster') {
            return $this->selectOrdersForBatchCutoffCluster($carrierKey, $packageMode, $excludedCodes, $limit);
        }

        return $this->repo->findAvailableOrdersForGroup($carrierKey, $packageMode, $excludedCodes, $limit, $this->mapCfg);
    }

    private function selectOrdersForBatchCutoffCluster(string $carrierKey, string $packageMode, array $excludedCodes, int $limit): array
    {
        $candidateLimit = max($limit * 20, 200);
        $candidates = $this->repo->findAvailableOrdersForGroup($carrierKey, $packageMode, $excludedCodes, $candidateLimit, $this->mapCfg);

        if (empty($candidates)) {
            return array();
        }

        if (count($candidates) <= $limit) {
            return array_slice($candidates, 0, $limit);
        }

        $anchor = $candidates[0];
        $candidateOrderCodes = array();
        foreach ($candidates as $candidate) {
            $candidateOrderCodes[] = (string)$candidate['order_code'];
        }

        $items = $this->repo->getOrderItemsForOrderCodes($candidateOrderCodes);
        $keysByOrderCode = array();

        foreach ($items as $item) {
            $orderCode = (string)($item['order_code'] ?? '');
            if ($orderCode === '') {
                continue;
            }

            if (!isset($keysByOrderCode[$orderCode])) {
                $keysByOrderCode[$orderCode] = array();
            }

            $clusterKey = $this->buildClusterKeyFromOrderItem($item);
            if ($clusterKey === null) {
                continue;
            }

            if (!in_array($clusterKey, $keysByOrderCode[$orderCode], true)) {
                $keysByOrderCode[$orderCode][] = $clusterKey;
            }
        }

        $anchorOrderCode = (string)$anchor['order_code'];
        $anchorKeys = isset($keysByOrderCode[$anchorOrderCode]) ? $keysByOrderCode[$anchorOrderCode] : array();

        $result = array($anchor);
        $usedOrderCodes = array($anchorOrderCode => true);

        foreach ($anchorKeys as $anchorKey) {
            foreach ($candidates as $candidate) {
                $orderCode = (string)$candidate['order_code'];
                if (isset($usedOrderCodes[$orderCode])) {
                    continue;
                }

                $candidateKeys = isset($keysByOrderCode[$orderCode]) ? $keysByOrderCode[$orderCode] : array();
                if (!in_array($anchorKey, $candidateKeys, true)) {
                    continue;
                }

                $result[] = $candidate;
                $usedOrderCodes[$orderCode] = true;

                if (count($result) >= $limit) {
                    return array_slice($result, 0, $limit);
                }
            }
        }

        foreach ($candidates as $candidate) {
            $orderCode = (string)$candidate['order_code'];
            if (isset($usedOrderCodes[$orderCode])) {
                continue;
            }

            $result[] = $candidate;
            $usedOrderCodes[$orderCode] = true;

            if (count($result) >= $limit) {
                break;
            }
        }

        return array_slice($result, 0, $limit);
    }

    private function buildClusterKeyFromOrderItem(array $item): ?string
    {
        $subiektTowId = isset($item['subiekt_tow_id']) ? (int)$item['subiekt_tow_id'] : 0;
        if ($subiektTowId <= 0) {
            return null;
        }

        $uom = isset($item['uom']) && $item['uom'] !== null ? trim((string)$item['uom']) : '';
        return $subiektTowId . '|' . $uom;
    }

    private function insertOrderItems(int $batchOrderId, string $orderCode): void
    {
        // Lista subiekt_tow_id które są automatycznie oznaczane jako zebrane
        // (usługi, transporty itp. — nie ma ich co fizycznie zbierać z półki)
        // Konfiguracja w .env: PICKING_AUTOPICK_TOW_IDS=1295,999,123
        $autoPickIds = $this->getAutoPickTowIds();

        $items = $this->repo->getOrderItems($orderCode);
        foreach ($items as $item) {
            $subiektTowId = isset($item['subiekt_tow_id']) ? (int)$item['subiekt_tow_id'] : 0;
            $subiektTowId = $subiektTowId > 0 ? $subiektTowId : null;

            $uom = isset($item['uom']) ? trim((string)$item['uom']) : '';
            $uom = $uom !== '' ? $uom : null;

            $subiektSymbol = isset($item['subiekt_symbol']) ? trim((string)$item['subiekt_symbol']) : '';
            $subiektSymbol = $subiektSymbol !== '' ? $subiektSymbol : null;

            $subiektDesc = isset($item['subiekt_desc']) ? trim((string)$item['subiekt_desc']) : '';
            $subiektDesc = $subiektDesc !== '' ? $subiektDesc : null;

            $sourceName = isset($item['name']) ? trim((string)$item['name']) : '';
            $sourceName = $sourceName !== '' ? $sourceName : null;

            $isUnmapped = $subiektTowId === null;
            $productCode = $isUnmapped
                ? 'legacy:' . (int)$item['item_id']
                : (string)$subiektTowId;

            $productName = $sourceName !== null ? $sourceName : '';
            if ($productName === '') {
                $productName = $subiektDesc !== null ? $subiektDesc : '';
            }
            if ($productName === '') {
                $productName = $productCode;
            }

            // Pomiń pozycje których nie zbiera się fizycznie (usługi, transport itp.)
            // Są widoczne w packingu (czytanym z pak_order_items) ale nie w pickingu
            if ($subiektTowId !== null && in_array($subiektTowId, $autoPickIds, true)) {
                continue;
            }

            $this->repo->insertPickingOrderItem(
                $batchOrderId,
                (int)$item['item_id'],
                $subiektTowId,
                $subiektSymbol,
                $subiektDesc,
                $sourceName,
                $productCode,
                $productName,
                $uom,
                $isUnmapped,
                (float)($item['quantity'] ?? 1),
                'pending'
            );
        }
    }

    /**
     * Zwraca listę subiekt_tow_id które mają być automatycznie oznaczone
     * jako 'picked' przy tworzeniu batcha pickingu.
     * Konfiguracja przez zmienną środowiskową PICKING_AUTOPICK_TOW_IDS
     * np: PICKING_AUTOPICK_TOW_IDS=1295,999
     *
     * @return int[]
     */
    private function getAutoPickTowIds(): array
    {
        $raw = (string)(getenv('PICKING_AUTOPICK_TOW_IDS') ?: ($_ENV['PICKING_AUTOPICK_TOW_IDS'] ?? ''));
        if ($raw === '') return [];

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }
        return $ids;
    }

    private function buildResumePickingAction(int $batchId, ?string $message = null, array $extra = array()): array
    {
        require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';
        return NextActionResolver::resumePicking($batchId, $message, $extra);
    }

    private function buildPickingBlockedModalAction(string $modal, string $message, array $extra = array()): array
    {
        require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';
        return NextActionResolver::showModal($modal, $message, $extra);
    }

    private function buildPickingRoleBlockedAction(string $currentWorkMode): array
    {
        require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';

        return NextActionResolver::showModal(
            'work_mode_conflict',
            'Ta akcja jest dostępna tylko w trybie picker. Zmień tryb pracy, aby rozpocząć zbieranie.',
            array(
                'current_work_mode' => $currentWorkMode,
                'required_work_mode' => 'picker',
            )
        );
    }

    private function buildPostCloseBatchAction(array $batch, array $session, int $batchId): array
    {
        require_once BASE_PATH . '/app/Modules/Workflow/Services/NextActionResolver.php';

        $workflowMode = isset($batch['workflow_mode']) ? trim((string)$batch['workflow_mode']) : '';
        if ($workflowMode === '') {
            $workflowMode = isset($session['workflow_mode']) ? trim((string)$session['workflow_mode']) : 'integrated';
        }

        if ($workflowMode === 'split') {
            return NextActionResolver::showCarrierQueue('Batch zamknięty — wybierz kuriera do kolejnego zbierania');
        }

        return NextActionResolver::build(
            'open_packing',
            'Batch zamknięty — przejdź do pakowania',
            array(
                'batch_id' => $batchId,
            )
        );
    }

    private function getBatchDetail(int $batchId): array
    {
        $batch    = $this->repo->findBatchById($batchId);
        $stats    = $this->repo->getBatchStats($batchId);
        $orders   = $this->repo->getBatchOrders($batchId);
        $products = $this->repo->getBatchItems($batchId);

        return [
            'batch' => array_merge($batch, [
                'active_orders_count'  => (int)$stats['assigned_count'] + (int)$stats['picked_count'],
                'picked_orders_count'  => (int)$stats['picked_count'],
                'dropped_orders_count' => (int)$stats['dropped_count'],
                'total_orders_count'   => (int)$stats['total'],
            ]),
            'orders'   => $orders,
            'products' => $products,
            'next_action' => $this->buildResumePickingAction($batchId, 'Wznów zbieranie batcha'),
        ];
    }


    private function notifyMissingByEmail(array $batch, array $batchOrder, array $item, string $reason, array $session): bool
    {
        $to = trim((string)(getenv('PICKING_MISSING_EMAIL') ?: 'sklep@ledone.pl'));
        if ($to === '') {
            error_log('[PickingBatchService] Missing PICKING_MISSING_EMAIL configuration');
            return false;
        }

        require_once BASE_PATH . '/app/Support/Mailer.php';

        $operator = trim((string)($session['display_name'] ?? $session['login'] ?? ('user#' . (int)$session['user_id'])));
        $station  = trim((string)($session['station_code'] ?? $session['station_id'] ?? ''));
        $qty      = (float)($item['expected_qty'] ?? 0);

        $subject = 'Brak w pickingu: ' . (string)$batchOrder['order_code'] . ' / ' . (string)$item['product_code'];

        $body = implode(PHP_EOL, [
            'Zgłoszono brak podczas zbierania.',
            '',
            'Zamówienie: ' . (string)$batchOrder['order_code'],
            'Produkt: ' . (string)$item['product_code'],
            'Nazwa: ' . (string)($item['product_name'] ?? ''),
            'Ilość: ' . $qty,
            'Powód: ' . $reason,
            'Batch ID: ' . (int)$batch['id'],
            'Operator: ' . $operator,
            'Stanowisko: ' . $station,
            'User ID: ' . (int)$session['user_id'],
            'Data: ' . date('Y-m-d H:i:s'),
        ]);

        $sent = Mailer::sendPlainText($to, $subject, $body);
        if (!$sent) {
            error_log('[PickingBatchService] SMTP send failed for missing item notification, order=' . (string)$batchOrder['order_code'] . ', product=' . (string)$item['product_code']);
        }

        return $sent;
    }

    private function assertBatchOwner(array $batch, array $session): void
    {
        if ((int)$batch['user_id'] !== (int)$session['user_id']) {
            throw new RuntimeException('Batch does not belong to current operator');
        }
        if ((int)$batch['station_id'] !== (int)$session['station_id']) {
            throw new RuntimeException('Batch does not belong to current station');
        }
    }
}