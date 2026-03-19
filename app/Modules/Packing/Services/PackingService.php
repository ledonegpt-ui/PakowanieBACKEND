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
    // OPEN
    // -------------------------------------------------------------------------

    public function openSession(string $orderCode, array $session): array
    {
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

        $this->repo->updateOrderPackingFinished(
            $orderCode,
            (string)($session['user_login'] ?? $session['user_id']),
            (string)($session['station_code'] ?? $session['station_id']),
            (string)($resolved['carrier_code'] ?? $resolved['menu_group'] ?? ''),
            (string)($resolved['label_source'] ?? $resolved['menu_group'] ?? ''),
            (string)($package['tracking_number'] ?? null),
            (string)($resolved['courier_code'] ?? null)
        );

        $this->repo->logEvent(
            $sessionId, 'session_finished',
            'Packing finished for order: ' . $orderCode,
            [
                'session_id'      => $sessionId,
                'order_code'      => $orderCode,
                'tracking_number' => $package['tracking_number'],
                'user_id'         => (int)$session['user_id'],
            ],
            (int)$session['user_id']
        );

        $batchId    = (int)($packingSession['picking_batch_id'] ?? 0);
        $nextOrder  = $batchId ? $this->repo->findNextBatchOrder($batchId, $orderCode) : null;
        $carrierKey = $batchId ? $this->repo->findBatchCarrierKey($batchId) : null;

        return [
            'order_code'      => $orderCode,
            'status'          => 'completed',
            'tracking_number' => $package['tracking_number'],
            'next_order_code' => $nextOrder ? $nextOrder['order_code'] : null,
            'batch_completed' => $nextOrder === null,
            'carrier_key'     => $carrierKey,
        ];
    }

    // -------------------------------------------------------------------------
    // CANCEL
    // -------------------------------------------------------------------------

    public function cancelSession(string $orderCode, array $session): array
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
        $this->repo->cancelSession($sessionId);

        $this->repo->logEvent(
            $sessionId, 'session_cancelled',
            'Packing cancelled for order: ' . $orderCode,
            [
                'session_id' => $sessionId,
                'order_code' => $orderCode,
                'user_id'    => (int)$session['user_id'],
            ],
            (int)$session['user_id']
        );

        return ['order_code' => $orderCode, 'status' => 'cancelled'];
    }

    // -------------------------------------------------------------------------
    // NEXT ORDER (Zadanie 1)
    // -------------------------------------------------------------------------

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
            ];
        }

        return [
            'order_code' => $orderCode,
            'batch_done' => false,
            'batch_id'   => $batchId,
        ];
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function buildSessionDetail(int $sessionId, array $order, array $resolved): array
    {
        $session = $this->repo->findSessionByOrderCode($order['order_code']);
        $items   = $this->repo->getSessionItems($sessionId);
        $package = $this->repo->findPackageBySession($sessionId);
        $label   = $package ? $this->repo->findLabelByPackage((int)$package['id']) : null;

        $items = $this->enrichItemsWithPhotos($items);

        return [
            'session'  => $session,
            'order'    => $order,
            'shipping' => $resolved,
            'items'    => $items,
            'package'  => $package,
            'label'    => $label,
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

            $rows = [];
            foreach ($items as $item) {
                $rows[] = [
                    'ob_TowId'  => $item['subiekt_tow_id'],
                    'tw_Symbol' => $item['subiekt_symbol'] ?? '',
                ];
            }

            $imageMap = $photoMap->buildImageMapForSubiektRows($rows);

            foreach ($items as &$item) {
                $towId  = (string)($item['subiekt_tow_id'] ?? '');
                $symbol = strtoupper(trim((string)($item['subiekt_symbol'] ?? '')));
                $key    = $towId . '|' . $symbol;
                $item['image_url'] = $imageMap[$key] ?? null;
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
