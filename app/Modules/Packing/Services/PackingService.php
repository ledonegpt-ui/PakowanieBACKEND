<?php
declare(strict_types=1);

final class PackingService
{
    /** @var PackingRepository */
    private $repo;

    /** @var array */
    private $mapCfg;

    /** @var array */
    private $cfg;

    public function __construct(PackingRepository $repo, array $mapCfg, array $cfg)
    {
        $this->repo   = $repo;
        $this->mapCfg = $mapCfg;
        $this->cfg    = $cfg;
    }

    // -------------------------------------------------------------------------
    // CURRENT SESSION
    // -------------------------------------------------------------------------

    public function getCurrentSession(array $session): array
    {
        $openSession = $this->repo->findOpenSessionWithDetails((int)$session['user_id']);

        if (!$openSession) {
            return [
                'has_open_session' => false,
                'session'          => null,
                'order'            => null,
                'items'            => [],
                'package'          => null,
                'label'            => null,
                'basket'           => null,
                'shipping'         => null,
            ];
        }

        $orderCode = (string)$openSession['order_code'];
        $order = $this->repo->findOrder($orderCode);

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($this->mapCfg);
        $resolved = $resolver->resolve([
            'delivery_method' => (string)($order['delivery_method'] ?? ''),
            'carrier_code'    => (string)($order['carrier_code'] ?? ''),
            'courier_code'    => (string)($order['courier_code'] ?? ''),
        ]);

        $sessionId = (int)$openSession['id'];
        $package = $this->repo->findPackageBySession($sessionId);
        $label = $package ? $this->repo->findLabelByPackage((int)$package['id']) : null;

        $basket = null;
        if (!empty($openSession['basket_id'])) {
            $basket = [
                'basket_id'     => (int)$openSession['basket_id'],
                'basket_no'     => !empty($openSession['basket_no']) ? (int)$openSession['basket_no'] : null,
                'basket_status' => (string)($openSession['basket_status'] ?? ''),
                'package_mode'  => (string)($openSession['package_mode'] ?? ''),
            ];
        }

        return [
            'has_open_session' => true,
            'session'          => [
                'id'               => $sessionId,
                'session_code'     => (string)$openSession['session_code'],
                'order_code'       => $orderCode,
                'picking_batch_id' => (int)($openSession['picking_batch_id'] ?? 0),
                'user_id'          => (int)$openSession['user_id'],
                'station_id'       => (int)$openSession['station_id'],
                'status'           => (string)$openSession['status'],
                'started_at'       => (string)$openSession['started_at'],
                'completed_at'     => $openSession['completed_at'],
                'cancelled_at'     => $openSession['cancelled_at'],
                'last_seen_at'     => $openSession['last_seen_at'],
            ],
            'order'   => $order,
            'items'   => $this->repo->findOrderItems($orderCode),
            'package' => $package,
            'label'   => $label,
            'basket'  => $basket,
            'shipping' => [
                'matched'        => $resolved['matched'] ?? false,
                'menu_group'     => $resolved['menu_group'] ?? null,
                'menu_label'     => $resolved['menu_label'] ?? null,
                'shipment_type'  => $resolved['shipment_type'] ?? null,
                'label_provider' => $resolved['label_provider'] ?? null,
                'requires_size'  => $resolved['requires_size'] ?? false,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // OPEN
    // -------------------------------------------------------------------------

    public function openSession(string $orderCode, array $session): array
    {
        $workflowMode = isset($session['workflow_mode']) ? trim((string)$session['workflow_mode']) : 'integrated';
        if (!in_array($workflowMode, ['integrated', 'split'], true)) {
            $workflowMode = 'integrated';
        }

        $workMode = isset($session['work_mode']) ? trim((string)$session['work_mode']) : 'picker';
        if (!in_array($workMode, ['picker', 'packer'], true)) {
            $workMode = 'picker';
        }

        if ($workflowMode === 'split' && $workMode !== 'packer') {
            throw new RuntimeException('Current user is not in packer mode');
        }

        $this->repo->beginTransaction();
        try {
            $existing = $this->repo->findOpenSessionForUserForUpdate((int)$session['user_id']);
            if ($existing) {
                throw new RuntimeException(
                    'Operator already has an open packing session: ' . $existing['session_code']
                );
            }

            $orderSession = $this->repo->findOpenSessionForOrder($orderCode);
            if ($orderSession) {
                throw new RuntimeException('Order is already being packed: ' . $orderCode);
            }

            $order = $this->repo->findOrder($orderCode);
            if (!$order) {
                throw new RuntimeException('Order not found: ' . $orderCode);
            }

            $pickingDone = $this->repo->findCompletedPickingBatchOrder($orderCode);
            if (!$pickingDone) {
                throw new RuntimeException('Order has not been picked yet: ' . $orderCode);
            }

            $batchBasket = null;
            $batchIdForPacking = (int)($pickingDone['batch_id'] ?? 0);
            if ($batchIdForPacking > 0) {
                $batchBasket = $this->repo->findBatchBasket($batchIdForPacking);
                if ($batchBasket && (string)($batchBasket['workflow_mode'] ?? '') === 'split' && !empty($batchBasket['basket_id'])) {
                    $this->repo->markBasketPackingInProgress((int)$batchBasket['basket_id']);
                }
            }

            $sessionCode = 'PACK-' . time() . '-' . $session['user_id'];
            $sessionId = $this->repo->createSession(
                $sessionCode,
                $orderCode,
                (int)$pickingDone['batch_id'],
                (int)$session['user_id'],
                (int)$session['station_id']
            );

            $items = $this->repo->findOrderItems($orderCode);
            foreach ($items as $item) {
                $offerId = isset($item['offer_id']) ? trim((string)$item['offer_id']) : '';
                $offerId = $offerId !== '' ? $offerId : null;

                $subiektTowId = isset($item['subiekt_tow_id']) ? (int)$item['subiekt_tow_id'] : 0;
                $subiektTowId = $subiektTowId > 0 ? $subiektTowId : null;

                $subiektSymbol = isset($item['subiekt_symbol']) ? trim((string)$item['subiekt_symbol']) : '';
                $subiektSymbol = $subiektSymbol !== '' ? $subiektSymbol : null;

                $subiektDesc = isset($item['subiekt_desc']) ? trim((string)$item['subiekt_desc']) : '';
                $subiektDesc = $subiektDesc !== '' ? $subiektDesc : null;

                $sourceName = isset($item['name']) ? trim((string)$item['name']) : '';
                $sourceName = $sourceName !== '' ? $sourceName : null;

                $uom = isset($item['uom']) ? trim((string)$item['uom']) : '';
                $uom = $uom !== '' ? $uom : null;

                $isUnmapped = $subiektTowId === null;
                $productCode = $isUnmapped
                    ? 'legacy:' . (int)$item['item_id']
                    : (string)$subiektTowId;

                $productName = $sourceName !== null ? $sourceName : '';
                if ($productName === '') $productName = $subiektDesc !== null ? $subiektDesc : '';
                if ($productName === '') $productName = $productCode;

                $this->repo->insertSessionItem(
                    $sessionId, (int)$item['item_id'], $offerId, $subiektTowId,
                    $subiektSymbol, $subiektDesc, $sourceName, $productCode,
                    $productName, $uom, $isUnmapped, (float)($item['quantity'] ?? 1)
                );
            }

            $this->repo->updateOrderPackingStarted($orderCode);

            require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
            $resolver = new ShippingMethodResolver($this->mapCfg);
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($order['delivery_method'] ?? ''),
                'carrier_code'    => (string)($order['carrier_code'] ?? ''),
                'courier_code'    => (string)($order['courier_code'] ?? ''),
            ]);

            $this->repo->logEvent(
                $sessionId, 'session_opened',
                'Packing session opened for order: ' . $orderCode,
                [
                    'session_id'  => $sessionId,
                    'order_code'  => $orderCode,
                    'user_id'     => (int)$session['user_id'],
                    'station_id'  => (int)$session['station_id'],
                    'carrier_key' => $resolved['menu_group'] ?? null,
                ],
                (int)$session['user_id']
            );

            $this->repo->commit();
            return $this->buildSessionDetail($sessionId, $order, $resolved);

        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // SHOW
    // -------------------------------------------------------------------------

    public function showSession(string $orderCode, array $session): array
    {
        $packingSession = $this->repo->findSessionByOrderCode($orderCode);
        if (!$packingSession) {
            throw new RuntimeException('No packing session for order: ' . $orderCode);
        }
        $this->assertSessionOwner($packingSession, $session);

        $order = $this->repo->findOrder($orderCode);

        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($this->mapCfg);
        $resolved = $resolver->resolve([
            'delivery_method' => (string)($order['delivery_method'] ?? ''),
            'carrier_code'    => (string)($order['carrier_code'] ?? ''),
            'courier_code'    => (string)($order['courier_code'] ?? ''),
        ]);

        return $this->buildSessionDetail((int)$packingSession['id'], $order, $resolved);
    }

    // -------------------------------------------------------------------------
    // FINISH
    // -------------------------------------------------------------------------

    public function finishSession(string $orderCode, array $session): array
    {
        $packingSession = $this->repo->findSessionByOrderCode($orderCode);
        if (!$packingSession) {
            throw new RuntimeException('No packing session for order: ' . $orderCode);
        }
        $this->assertSessionOwner($packingSession, $session);

        if ($packingSession['status'] !== 'open') {
            throw new RuntimeException('Packing session is not open');
        }

        $sessionId = (int)$packingSession['id'];

        $package = $this->repo->findPackageBySession($sessionId);
        if (!$package) {
            throw new RuntimeException('No package found — generate label first');
        }

        $label = $this->repo->findLabelByPackage((int)$package['id']);
        if (!$label) {
            throw new RuntimeException('No valid label found — generate label first');
        }

        $order = $this->repo->findOrder($orderCode);
        require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
        $resolver = new ShippingMethodResolver($this->mapCfg);
        $resolved = $resolver->resolve([
            'delivery_method' => (string)($order['delivery_method'] ?? ''),
            'carrier_code'    => (string)($order['carrier_code'] ?? ''),
            'courier_code'    => (string)($order['courier_code'] ?? ''),
        ]);

        $this->repo->markAllItemsPacked($sessionId);
        $this->repo->closeSession($sessionId);

        require_once BASE_PATH . '/app/Services/FinishOrderSyncService.php';
        $finishSync = new FinishOrderSyncService($this->cfg);
        $operatorLabel = $finishSync->resolveOperatorLabel($session);

        $this->repo->updateOrderPackingFinished(
            $orderCode,
            $operatorLabel,
            (string)($session['station_code'] ?? $session['station_id']),
            (string)($resolved['carrier_code'] ?? $resolved['menu_group'] ?? ''),
            (string)($resolved['label_source'] ?? $resolved['menu_group'] ?? ''),
            (string)($package['tracking_number'] ?? null),
            (string)($resolved['courier_code'] ?? null)
        );

        $syncResult = $finishSync->syncAfterFinish(
            $orderCode,
            $session,
            isset($package['tracking_number']) ? (string)$package['tracking_number'] : null
        );

        $this->repo->logEvent(
            $sessionId, 'session_finished',
            'Packing finished for order: ' . $orderCode,
            [
                'session_id'      => $sessionId,
                'order_code'      => $orderCode,
                'tracking_number' => $package['tracking_number'],
                'user_id'         => (int)$session['user_id'],
                'sync_result'     => $syncResult,
            ],
            (int)$session['user_id']
        );

        $batchId    = (int)($packingSession['picking_batch_id'] ?? 0);
        $nextOrder  = $batchId ? $this->repo->findNextBatchOrder($batchId, $orderCode) : null;
        $carrierKey = $batchId ? $this->repo->findBatchCarrierKey($batchId) : null;

        $batchBasket = $batchId ? $this->repo->findBatchBasket($batchId) : null;
        if ($batchBasket && (string)($batchBasket['workflow_mode'] ?? '') === 'split' && !empty($batchBasket['basket_id']) && $nextOrder === null) {
            $this->repo->releaseBasketAfterPacking((int)$batchBasket['basket_id'], $batchId);
        }

        $nextOrderCode = $nextOrder ? $nextOrder['order_code'] : null;
        $batchCompleted = $nextOrder === null;

        return [
            'order_code'      => $orderCode,
            'status'          => 'completed',
            'tracking_number' => $package['tracking_number'],
            'next_order_code' => $nextOrderCode,
            'batch_completed' => $batchCompleted,
            'carrier_key'     => $carrierKey,
            'basket_no'       => $batchBasket && !empty($batchBasket['basket_no']) ? (int)$batchBasket['basket_no'] : null,
            'next_action'     => $this->buildPostPackingAction($nextOrderCode, $batchCompleted, $session),
        ];
    }

    // -------------------------------------------------------------------------
    // CANCEL
    // -------------------------------------------------------------------------

    public function cancelSession(string $orderCode, array $session, ?string $reason = null): array
    {
        $packingSession = $this->repo->findSessionByOrderCode($orderCode);
        if (!$packingSession) {
            throw new RuntimeException('No packing session for order: ' . $orderCode);
        }

        $isAdmin = $this->repo->userHasAnyRole((int)$session['user_id'], ['admin', 'superadmin']);

        if (!$isAdmin) {
            $this->assertSessionOwner($packingSession, $session);
        }

        if ($packingSession['status'] !== 'open') {
            throw new RuntimeException('Packing session is not open');
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        $sessionId = (int)$packingSession['id'];
        $this->repo->cancelSession($sessionId);

        $message = 'Packing cancelled for order: ' . $orderCode;
        if ($reason !== null) {
            $message .= ' | reason: ' . $reason;
        }

        $this->repo->logEvent(
            $sessionId,
            'session_cancelled',
            $message,
            [
                'session_id'             => $sessionId,
                'order_code'             => $orderCode,
                'user_id'                => (int)$session['user_id'],
                'reason'                 => $reason,
                'cancelled_by_admin'     => $isAdmin,
                'original_session_user'  => (int)($packingSession['user_id'] ?? 0),
                'original_session_station'=> (int)($packingSession['station_id'] ?? 0),
            ],
            (int)$session['user_id']
        );

        return [
            'order_code'         => $orderCode,
            'status'             => 'cancelled',
            'reason'             => $reason,
            'cancelled_by_admin' => $isAdmin,
        ];
    }

    // -------------------------------------------------------------------------
    // NEXT ORDER (Zadanie 1)
    // -------------------------------------------------------------------------

    public function getNextReadyBatch(array $session): array
    {
        $workflowMode = isset($session['workflow_mode']) ? trim((string)$session['workflow_mode']) : 'integrated';
        if (!in_array($workflowMode, ['integrated', 'split'], true)) {
            $workflowMode = 'integrated';
        }

        $workMode = isset($session['work_mode']) ? trim((string)$session['work_mode']) : 'picker';
        if (!in_array($workMode, ['picker', 'packer'], true)) {
            $workMode = 'picker';
        }

        if ($workflowMode === 'split' && $workMode !== 'packer') {
            throw new RuntimeException('Current user is not in packer mode');
        }

        $packageMode = isset($session['package_mode']) ? trim((string)$session['package_mode']) : 'small';
        if (!in_array($packageMode, ['small', 'large'], true)) {
            $packageMode = 'small';
        }

        $batch = $this->repo->findNextReadyBatchForPacking($packageMode);
        if (!$batch) {
            return [
                'batch_id' => null,
                'basket_id' => null,
                'basket_no' => null,
                'package_mode' => $packageMode,
                'ready' => false,
                'reason' => 'no_ready_baskets',
            ];
        }

        return [
            'batch_id' => (int)$batch['batch_id'],
            'basket_id' => (int)$batch['basket_id'],
            'basket_no' => (int)$batch['basket_no'],
            'package_mode' => (string)$batch['package_mode'],
            'ready' => true,
            'reason' => null,
        ];
    }

    public function openNextReadyBatch(array $session): array
    {
        // Jesli user ma juz otwarta sesje packingu - zwroc ja zamiast szukac nowego koszyka
        $existingSession = $this->repo->findOpenSessionWithDetails((int)$session['user_id']);
        if ($existingSession) {
            $orderCode = (string)$existingSession['order_code'];
            $order = $this->repo->findOrder($orderCode);

            require_once BASE_PATH . '/app/Support/ShippingMethodResolver.php';
            $resolver = new ShippingMethodResolver($this->mapCfg);
            $resolved = $resolver->resolve([
                'delivery_method' => (string)($order['delivery_method'] ?? ''),
                'carrier_code'    => (string)($order['carrier_code'] ?? ''),
                'courier_code'    => (string)($order['courier_code'] ?? ''),
            ]);

            $sessionId = (int)$existingSession['id'];
            $package = $this->repo->findPackageBySession($sessionId);
            $label = $package ? $this->repo->findLabelByPackage((int)$package['id']) : null;
            $items = $this->repo->findOrderItems($orderCode);

            $basket = null;
            if (!empty($existingSession['basket_id'])) {
                $basket = [
                    'basket_id'    => (int)$existingSession['basket_id'],
                    'basket_no'    => !empty($existingSession['basket_no']) ? (int)$existingSession['basket_no'] : null,
                    'basket_status'=> (string)($existingSession['basket_status'] ?? ''),
                    'package_mode' => (string)($existingSession['package_mode'] ?? ''),
                ];
            }

            return [
                'ready'             => true,
                'resumed'           => true,
                'auto_loaded_batch' => true,
                'batch_id'          => (int)($existingSession['picking_batch_id'] ?? 0),
                'basket_id'         => $basket ? $basket['basket_id'] : null,
                'basket_no'         => $basket ? $basket['basket_no'] : null,
                'order_code'        => $orderCode,
                'session'           => [
                    'id'               => $sessionId,
                    'session_code'     => (string)$existingSession['session_code'],
                    'order_code'       => $orderCode,
                    'picking_batch_id' => (int)($existingSession['picking_batch_id'] ?? 0),
                    'user_id'          => (int)$existingSession['user_id'],
                    'station_id'       => (int)$existingSession['station_id'],
                    'status'           => 'open',
                    'started_at'       => (string)$existingSession['started_at'],
                    'completed_at'     => null,
                    'cancelled_at'     => null,
                    'last_seen_at'     => $existingSession['last_seen_at'],
                ],
                'order'   => $order,
                'items'   => $items,
                'package' => $package,
                'label'   => $label,
                'basket'  => $basket,
                'shipping' => [
                    'matched'        => $resolved['matched'] ?? false,
                    'menu_group'     => $resolved['menu_group'] ?? null,
                    'menu_label'     => $resolved['menu_label'] ?? null,
                    'shipment_type'  => $resolved['shipment_type'] ?? null,
                    'label_provider' => $resolved['label_provider'] ?? null,
                    'requires_size'  => $resolved['requires_size'] ?? false,
                ],
            ];
        }

        $nextBatch = $this->getNextReadyBatch($session);
        if (empty($nextBatch['ready']) || empty($nextBatch['batch_id'])) {
            return [
                'ready' => false,
                'reason' => $nextBatch['reason'] ?? 'no_ready_baskets',
                'batch_id' => null,
                'basket_id' => null,
                'basket_no' => null,
                'order_code' => null,
            ];
        }

        $batchId = (int)$nextBatch['batch_id'];
        $orderCode = $this->repo->findNextOrderToPack($batchId);

        if ($orderCode === null || $orderCode === '') {
            return [
                'ready' => false,
                'reason' => 'no_orders_in_ready_batch',
                'batch_id' => $batchId,
                'basket_id' => (int)$nextBatch['basket_id'],
                'basket_no' => (int)$nextBatch['basket_no'],
                'order_code' => null,
            ];
        }

        $result = $this->openSession($orderCode, $session);
        $result['auto_loaded_batch'] = true;
        $result['batch_id'] = $batchId;
        $result['basket_id'] = (int)$nextBatch['basket_id'];
        $result['basket_no'] = (int)$nextBatch['basket_no'];

        return $result;
    }

    public function getNextOrder(int $batchId, array $session): array
    {
        if (!$this->repo->batchExists($batchId)) {
            throw new RuntimeException('Batch not found: ' . $batchId);
        }

        $orderCode = $this->repo->findNextOrderToPack($batchId);

        if ($orderCode === null) {
            return [
                'order_code' => null,
                'batch_done' => true,
                'batch_id'   => $batchId,
                'next_action' => $this->buildPostPackingAction(null, true, $session),
            ];
        }

        return [
            'order_code' => $orderCode,
            'batch_done' => false,
            'batch_id'   => $batchId,
            'next_action' => $this->buildPostPackingAction($orderCode, false, $session),
        ];
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function buildPostPackingAction(?string $nextOrderCode, bool $batchCompleted, array $session): array
    {
        $workflowMode = isset($session['workflow_mode']) ? (string)$session['workflow_mode'] : 'integrated';
        $workMode = isset($session['work_mode']) ? (string)$session['work_mode'] : 'picker';

        if ($nextOrderCode !== null && $nextOrderCode !== '') {
            return [
                'type' => 'open_next_order',
                'order_code' => $nextOrderCode,
                'batch_completed' => false,
                'workflow_mode' => $workflowMode,
                'work_mode' => $workMode,
            ];
        }

        return [
            'type' => 'go_home',
            'order_code' => null,
            'batch_completed' => $batchCompleted,
            'workflow_mode' => $workflowMode,
            'work_mode' => $workMode,
        ];
    }

    private function buildSessionDetail(int $sessionId, array $order, array $resolved): array
    {
        $session = $this->repo->findSessionByOrderCode($order['order_code']);
        $items   = $this->repo->getSessionItems($sessionId);
        $package = $this->repo->findPackageBySession($sessionId);
        $label   = $package ? $this->repo->findLabelByPackage((int)$package['id']) : null;

        $basket = null;
        $batchId = $session && isset($session['picking_batch_id']) ? (int)$session['picking_batch_id'] : 0;
        if ($batchId > 0) {
            $batchBasket = $this->repo->findBatchBasket($batchId);
            if ($batchBasket && !empty($batchBasket['basket_id'])) {
                $basket = [
                    'basket_id' => (int)$batchBasket['basket_id'],
                    'basket_no' => !empty($batchBasket['basket_no']) ? (int)$batchBasket['basket_no'] : null,
                    'basket_status' => (string)($batchBasket['basket_status'] ?? ''),
                    'package_mode' => (string)($batchBasket['package_mode'] ?? ''),
                ];
            }
        }

        $items = $this->enrichItemsWithPhotos($items);

        return [
            'session'  => $session,
            'order'    => $order,
            'shipping' => $resolved,
            'items'    => $items,
            'package'  => $package,
            'label'    => $label,
            'basket'   => $basket,
        ];
    }

    private function enrichItemsWithPhotos(array $items): array
    {
        if (empty($items)) {
            return $items;
        }

        try {
            if (empty($this->cfg['mysql2']['host']) || empty($this->cfg['mysql2']['db'])) {
                return $this->setNullImageUrls($items);
            }

            require_once BASE_PATH . '/app/Services/LegacyAuctionPhotoMap.php';

            $mysql2   = \Db::mysql2($this->cfg);
            $photoMap = new \LegacyAuctionPhotoMap($mysql2);

            // --- 1. Zbierz unikalne offer_id i subiekt rows ---
            $offerIds    = [];
            $subiektRows = [];

            foreach ($items as $item) {
                $offerId = isset($item['offer_id']) ? trim((string)$item['offer_id']) : '';
                if ($offerId !== '') {
                    $offerIds[$offerId] = $offerId;
                } else {
                    $subiektRows[] = [
                        'ob_TowId'  => $item['subiekt_tow_id'],
                        'tw_Symbol' => $item['subiekt_symbol'] ?? '',
                    ];
                }
            }

            // --- 2. Pobierz mapy zdjęć ---
            // Zdjęcia zestawów po offer_id (nr_aukcji) — jeden URL na całą grupę
            $offerImageMap = !empty($offerIds)
                ? $photoMap->buildImageMapForOfferIds(array_values($offerIds))
                : [];

            // Zdjęcia pojedynczych produktów po subiekt_tow_id|symbol
            $subiektImageMap = !empty($subiektRows)
                ? $photoMap->buildImageMapForSubiektRows($subiektRows)
                : [];

            // --- 3. Przypisz zdjęcia do pozycji ---
            foreach ($items as &$item) {
                $offerId = isset($item['offer_id']) ? trim((string)$item['offer_id']) : '';

                if ($offerId !== '') {
                    // Pozycja należy do zestawu — zdjęcie zestawu z offer_id
                    $item['image_url'] = $offerImageMap[$offerId] ?? null;
                } else {
                    // Pojedyncza pozycja — zdjęcie produktu
                    $towId  = (string)($item['subiekt_tow_id'] ?? '');
                    $symbol = strtoupper(trim((string)($item['subiekt_symbol'] ?? '')));
                    $key    = $towId . '|' . $symbol;
                    $item['image_url'] = $subiektImageMap[$key] ?? null;
                }
            }
            unset($item);

        } catch (\Throwable $e) {
            return $this->setNullImageUrls($items);
        }

        return $items;
    }

    private function setNullImageUrls(array $items): array
    {
        foreach ($items as &$item) {
            $item['image_url'] = null;
        }
        unset($item);
        return $items;
    }

    private function assertSessionOwner(array $packingSession, array $session): void
    {
        if ((int)$packingSession['user_id'] !== (int)$session['user_id']) {
            throw new RuntimeException('Session does not belong to current operator');
        }
        if ((int)$packingSession['station_id'] !== (int)$session['station_id']) {
            throw new RuntimeException('Session does not belong to current station');
        }
    }
}