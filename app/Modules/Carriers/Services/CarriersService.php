<?php
declare(strict_types=1);

final class CarriersService
{
    /** @var CarriersRepository */
    private $repo;

    /** @var array */
    private $mapCfg;

    public function __construct(CarriersRepository $repo, array $mapCfg)
    {
        $this->repo = $repo;
        $this->mapCfg = $mapCfg;
    }

    public function listQueueSummary(): array
    {
        require_once __DIR__ . '/../../../Support/ShippingMethodResolver.php';

        $resolver = new ShippingMethodResolver($this->mapCfg);
        $rows = $this->repo->openOrdersForCarrierMenu();

        $groups = [];
        foreach (($this->mapCfg['groups'] ?? []) as $groupKey => $groupDef) {
            $groups[$groupKey] = [
                'group_key' => $groupKey,
                'label' => (string)($groupDef['label'] ?? $groupKey),
                'sort' => (int)($groupDef['sort'] ?? 999),
                'orders_count' => 0,
                'sample_methods_map' => [],
            ];
        }

        foreach ($rows as $row) {
            $resolved = $resolver->resolve($row);
            $groupKey = (string)$resolved['menu_group'];

            if (!isset($groups[$groupKey])) {
                $groupKey = 'inne';
            }

            $groups[$groupKey]['orders_count']++;

            $method = trim((string)($row['delivery_method'] ?? ''));
            if ($method === '') {
                $method = trim((string)($row['courier_code'] ?? ''));
            }
            if ($method === '') {
                $method = trim((string)($row['carrier_code'] ?? ''));
            }
            if ($method === '') {
                $method = 'unknown';
            }

            if (!isset($groups[$groupKey]['sample_methods_map'][$method])) {
                $groups[$groupKey]['sample_methods_map'][$method] = 0;
            }
            $groups[$groupKey]['sample_methods_map'][$method]++;
        }

        $out = [];
        foreach ($groups as $group) {
            if ((int)$group['orders_count'] <= 0) {
                continue;
            }

            arsort($group['sample_methods_map']);

            $samples = [];
            $i = 0;
            foreach ($group['sample_methods_map'] as $method => $count) {
                $samples[] = [
                    'method' => $method,
                    'count' => $count,
                ];
                $i++;
                if ($i >= 5) {
                    break;
                }
            }

            $out[] = [
                'group_key' => $group['group_key'],
                'label' => $group['label'],
                'orders_count' => (int)$group['orders_count'],
                'sample_methods' => $samples,
                '_sort' => $group['sort'],
            ];
        }

        usort($out, function (array $a, array $b): int {
            if ($a['orders_count'] === $b['orders_count']) {
                if ($a['_sort'] === $b['_sort']) {
                    return strcmp($a['label'], $b['label']);
                }
                return $a['_sort'] <=> $b['_sort'];
            }
            return $b['orders_count'] <=> $a['orders_count'];
        });

        foreach ($out as &$row) {
            unset($row['_sort']);
        }
        unset($row);

        return $out;
    }
}
