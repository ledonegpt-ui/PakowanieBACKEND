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
        $targetOrdersCount = (int)($body['target_orders_count'] ?? ($this->cfg['picking_batch_size'] ?? 3));

        if ($carrierKey === '') {
            throw new RuntimeException('Missing carrier_key');
        }
        if ($targetOrdersCount < 1 || $targetOrdersCount > 50) {
            $targetOrdersCount = 10;
        }

        $this->repo->beginTransaction();
        try {
            $existing = $this->repo->findOpenBatchForUserForUpdate((int)$session['user_id']);
            if ($existing) {
                $existingBatchId = (int)$existing['id'];
                $this->repo->rollback();
                return $this->getBatchDetail($existingBatchId);
            }

            $excludedCodes = $this->repo->getOrderCodesInOpenBatches();
            $orders = $this->repo->findAvailableOrdersForGroup($carrierKey, $excludedCodes, $targetOrdersCount, $this->mapCfg);

            if (empty($orders)) {
                throw new RuntimeException('No available orders for carrier_key: ' . $carrierKey);
            }

            $batchCode = 'BATCH-' . time() . '-' . $session['user_id'];
            $batchId = $this->repo->createBatch(
                $batchCode,
                $carrierKey,
                (int)$session['user_id'],
                (int)$session['station_id'],
                (string)($session['workflow_mode'] ?? 'integrated'),
                $targetOrdersCount
            );

            foreach ($orders as $order) {
                $batchOrderId = $this->repo->insertBatchOrder($batchId, $order['order_code']);
                $this->insertOrderItems($batchOrderId, $order['order_code']);
            }

            $this->repo->rebuildBatchItems($batchId);

            $this->repo->logEvent(
                $batchId, 'batch_opened', null, null,
                'Batch opened with ' . count($orders) . ' orders',
                [
                    'batch_id'     => $batchId,
                    'carrier_key'  => $carrierKey,
                    'orders_count' => count($orders),
                    'user_id'      => (int)$session['user_id'],
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
        $batch = $this->repo->findOpenBatchForUser((int)$session['user_id']);
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
                'expected_qty'      => (float)$item['expected_qty'],
                'picked_qty'        => (float)$item['expected_qty'],
                'user_id'           => (int)$session['user_id'],
            ],
            (int)$session['user_id']
        );

        return [
            'order_id'     => $batchOrderId,
            'order_code'   => (string)$batchOrder['order_code'],
            'item_id'      => $itemId,
            'pak_item_id'  => (int)$item['pak_order_item_id'],
            'status'       => 'picked',
            'order_status' => $pendingCount === 0 ? 'picked' : 'assigned',
        ];
    }

    public function markMissing(int $batchOrderId, int $itemId, array $body, array $session): array
    {
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Missing reason for missing item');
        }

        $batch = $this->repo->findOpenBatchForUser((int)$session['user_id']);
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

        $this->repo->logEvent(
            (int)$batch['id'], 'item_missing',
            (int)$batchOrder['id'], (int)$item['id'],
            'Item missing: ' . $item['product_code'],
            [
                'batch_id'          => (int)$batch['id'],
                'batch_order_id'    => $batchOrderId,
                'order_code'        => (string)$batchOrder['order_code'],
                'picking_item_id'   => $itemId,
                'pak_order_item_id' => (int)$item['pak_order_item_id'],
                'reason'            => $reason,
                'user_id'           => (int)$session['user_id'],
            ],
            (int)$session['user_id']
        );

        $this->repo->rebuildBatchItems((int)$batch['id']);
        
        return [
            'order_id'    => $batchOrderId,
            'order_code'  => (string)$batchOrder['order_code'],
            'item_id'     => $itemId,
            'pak_item_id' => (int)$item['pak_order_item_id'],
            'status'      => 'missing',
        ];
    }

    public function dropOrderManual(int $batchOrderId, array $body, array $session): array
    {
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Missing reason for drop');
        }

        $batch = $this->repo->findOpenBatchForUser((int)$session['user_id']);
        if (!$batch) {
            throw new RuntimeException('No open batch for operator');
        }

        $batchOrder = $this->repo->findBatchOrderById((int)$batch['id'], $batchOrderId);
        if (!$batchOrder) {
            throw new RuntimeException('Order not found in batch: ' . $batchOrderId);
        }

        $this->dropOrder((string)$batchOrder['order_code'], $reason, $session, $batch);
        $this->doRefill((int)$batch['id'], $session);

        return [
            'order_id'   => $batchOrderId,
            'order_code' => (string)$batchOrder['order_code'],
            'status'     => 'dropped',
            'reason'     => $reason,
        ];
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

    public function abandonBatch(int $batchId, array $session): array
    {
        $batch = $this->repo->findBatchById($batchId);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }
        $this->assertBatchOwner($batch, $session);

        if ($batch['status'] !== 'open') {
            throw new RuntimeException('Batch is not open');
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

        return ['batch_id' => $batchId, 'status' => 'abandoned'];
    }

    public function closeBatch(int $batchId, array $session): array
    {
        $batch = $this->repo->findBatchById($batchId);
        if (!$batch) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }
        $this->assertBatchOwner($batch, $session);

        if ($batch['status'] !== 'open') {
            throw new RuntimeException('Batch is not open');
        }

        $stats = $this->repo->getBatchStats($batchId);
        if ((int)$stats['assigned_count'] > 0) {
            throw new RuntimeException(
                'Cannot close batch: ' . $stats['assigned_count'] . ' order(s) still assigned (not picked)'
            );
        }

        $this->repo->closeBatch($batchId);

        $this->repo->logEvent(
            $batchId, 'batch_closed', null, null,
            'Batch closed by operator',
            [
                'batch_id'      => $batchId,
                'picked_count'  => (int)$stats['picked_count'],
                'dropped_count' => (int)$stats['dropped_count'],
                'user_id'       => (int)$session['user_id'],
            ],
            (int)$session['user_id']
        );

        return ['batch_id' => $batchId, 'status' => 'completed'];
    }

    private function doRefill(int $batchId, array $session): array
    {
        $this->repo->beginTransaction();
        try {
            $batch = $this->repo->findBatchByIdForUpdate($batchId);
            if (!$batch || $batch['status'] !== 'open') {
                $this->repo->rollback();
                return ['refilled' => 0, 'active_orders' => 0];
            }

            $activeCount = $this->repo->countActiveBatchOrders($batchId);
            $target      = (int)$batch['target_orders_count'];
            $needed      = $target - $activeCount;

            if ($needed <= 0) {
                $this->repo->rollback();
                return ['refilled' => 0, 'active_orders' => $activeCount];
            }

            $allInThisBatch       = $this->repo->getAllOrderCodesInBatch($batchId);
            $activeInOtherBatches = $this->repo->getOrderCodesInOpenBatches();
            $excludedCodes        = array_values(array_unique(array_merge($allInThisBatch, $activeInOtherBatches)));

            $newOrders = $this->repo->findAvailableOrdersForGroup($batch['carrier_key'], $excludedCodes, $needed, $this->mapCfg);

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
                        'added_orders_count'  => count($newOrders),
                        'target_orders_count' => $target,
                        'user_id'             => (int)$session['user_id'],
                    ],
                    (int)$session['user_id']
                );
            }

            $this->repo->commit();
            return ['refilled' => count($newOrders), 'active_orders' => $activeCount + count($newOrders)];

        } catch (Throwable $e) {
            $this->repo->rollback();
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

    private function insertOrderItems(int $batchOrderId, string $orderCode): void
    {
        $items = $this->repo->getOrderItems($orderCode);
        foreach ($items as $item) {
            $this->repo->insertPickingOrderItem(
                $batchOrderId,
                (int)$item['item_id'],
                (string)($item['sku'] ?? ''),
                (string)($item['name'] ?? ''),
                (float)($item['quantity'] ?? 1)
            );
        }
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
        ];
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
