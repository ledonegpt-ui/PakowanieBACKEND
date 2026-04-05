<?php
declare(strict_types=1);

final class PanelOrdersService
{
    /** @var PanelOrdersRepository */
    private $repo;

    /** @var array */
    private $mapCfg;

    public function __construct(PanelOrdersRepository $repo, array $mapCfg)
    {
        $this->repo = $repo;
        $this->mapCfg = $mapCfg;
    }

    public function search(array $query): array
    {
        $q = trim((string)($query['q'] ?? ''));
        $status = trim((string)($query['status'] ?? ''));
        $carrier = trim((string)($query['carrier'] ?? ''));
        $limit = (int)($query['limit'] ?? 100);

        return array(
            'orders' => $this->repo->searchOrders($q, $status, $carrier, $limit, $this->mapCfg),
            'filters' => array(
                'q' => $q,
                'status' => $status,
                'carrier' => $carrier,
                'limit' => max(1, min(200, $limit)),
            ),
        );
    }

    public function detail(string $orderCode): array
    {
        $orderCode = trim($orderCode);
        if ($orderCode === '') {
            throw new RuntimeException('Missing orderCode');
        }

        $detail = $this->repo->getOrderDetail($orderCode, $this->mapCfg);
        if (!$detail) {
            throw new RuntimeException('Order not found');
        }

        return array('order' => $detail);
    }

    public function update(string $orderCode, array $body, array $currentSession): array
    {
        $orderCode = trim($orderCode);
        if ($orderCode === '') {
            throw new RuntimeException('Missing orderCode');
        }

        $allowedFields = array(
            'delivery_fullname',
            'delivery_address',
            'delivery_city',
            'delivery_postcode',
            'phone',
            'email',
        );

        $clean = array();

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $clean[$field] = trim((string)$body[$field]);
        }

        $this->validateEditableFields($clean);

        $changedByUserId = (int)($currentSession['user_id'] ?? 0);
        if ($changedByUserId <= 0) {
            throw new RuntimeException('Missing session user');
        }

        $update = $this->repo->updateEditableFields($orderCode, $clean, $changedByUserId);
        $detail = $this->repo->getOrderDetail($orderCode, $this->mapCfg);

        if (!$detail) {
            throw new RuntimeException('Order not found after update');
        }

        return array(
            'updated' => (bool)$update['updated'],
            'changes' => $update['changes'],
            'order' => $detail,
        );
    }

    public function deleteForce(string $orderCode, array $body, array $currentSession): array
    {
        $orderCode = trim($orderCode);
        if ($orderCode === '') {
            throw new RuntimeException('Missing orderCode');
        }

        $changedByUserId = (int)($currentSession['user_id'] ?? 0);
        if ($changedByUserId <= 0) {
            throw new RuntimeException('Missing session user');
        }

        $reason = trim((string)($body['reason'] ?? ''));
        $summary = $this->repo->forceDeleteOrder($orderCode, $changedByUserId, $reason);

        return array(
            'deleted' => true,
            'summary' => $summary,
        );
    }

    private function validateEditableFields(array $data): void
    {
        if (isset($data['delivery_fullname']) && $data['delivery_fullname'] === '') {
            throw new RuntimeException('delivery_fullname cannot be empty');
        }

        if (isset($data['delivery_address']) && $data['delivery_address'] === '') {
            throw new RuntimeException('delivery_address cannot be empty');
        }

        if (isset($data['delivery_city']) && $data['delivery_city'] === '') {
            throw new RuntimeException('delivery_city cannot be empty');
        }

        if (isset($data['delivery_postcode']) && $data['delivery_postcode'] !== '') {
            if (!preg_match('/^[0-9]{2}-[0-9]{3}$/', $data['delivery_postcode'])) {
                throw new RuntimeException('delivery_postcode must be in format NN-NNN');
            }
        }

        if (isset($data['email']) && $data['email'] !== '') {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email');
            }
        }

        if (isset($data['phone']) && $data['phone'] !== '') {
            $digits = preg_replace('/\D+/', '', $data['phone']);
            if (strlen($digits) < 9) {
                throw new RuntimeException('Invalid phone');
            }
        }
    }
}
