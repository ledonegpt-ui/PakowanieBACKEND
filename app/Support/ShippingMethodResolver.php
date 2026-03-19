<?php
declare(strict_types=1);

final class ShippingMethodResolver
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function resolve(array $input): array
    {
        $deliveryMethod = trim((string)($input['delivery_method'] ?? ''));
        $carrierCode    = trim((string)($input['carrier_code'] ?? ''));
        $courierCode    = trim((string)($input['courier_code'] ?? ''));

        $haystack = implode(' | ', array_filter([
            $deliveryMethod,
            $carrierCode,
            $courierCode,
        ], function ($v) {
            return $v !== '';
        }));

        $best = null;

        foreach (($this->cfg['rules'] ?? []) as $rule) {
            if (empty($rule['active'])) {
                continue;
            }

            if (!$this->ruleMatches($rule, $haystack)) {
                continue;
            }

            if ($best === null || (int)$rule['priority'] > (int)$best['priority']) {
                $best = $rule;
            }
        }

        if ($best === null) {
            return [
                'matched' => false,
                'menu_group' => 'inne',
                'menu_label' => (string)(($this->cfg['groups']['inne']['label'] ?? 'Inne')),
                'shipment_type' => 'unknown',
                'label_provider' => 'none',
                'label_endpoint' => 'shipping.none',
                'requires_size' => false,
                'matched_rule' => null,
                'source' => [
                    'delivery_method' => $deliveryMethod,
                    'carrier_code' => $carrierCode,
                    'courier_code' => $courierCode,
                ],
            ];
        }

        $groupKey = (string)$best['menu_group'];

        return [
            'matched' => true,
            'menu_group' => $groupKey,
            'menu_label' => (string)(($this->cfg['groups'][$groupKey]['label'] ?? $groupKey)),
            'shipment_type' => (string)$best['shipment_type'],
            'label_provider' => (string)$best['label_provider'],
            'label_endpoint' => (string)$best['label_endpoint'],
            'requires_size' => (bool)$best['requires_size'],
            'matched_rule' => (string)$best['rule_code'],
            'source' => [
                'delivery_method' => $deliveryMethod,
                'carrier_code' => $carrierCode,
                'courier_code' => $courierCode,
            ],
        ];
    }

    private function ruleMatches(array $rule, string $haystack): bool
    {
        $type = (string)($rule['match_type'] ?? '');
        $value = (string)($rule['match_value'] ?? '');

        if ($value === '') {
            return false;
        }

        $matched = false;

        if ($type === 'exact') {
            $matched = (mb_strtolower(trim($haystack), 'UTF-8') === mb_strtolower(trim($value), 'UTF-8'));
        } elseif ($type === 'contains') {
            $matched = (mb_stripos($haystack, $value, 0, 'UTF-8') !== false);
        } elseif ($type === 'regex') {
            $matched = (@preg_match($value, $haystack) === 1);
        }

        if (!$matched) {
            return false;
        }

        if (!empty($rule['must_also_match_any']) && is_array($rule['must_also_match_any'])) {
            $ok = false;
            foreach ($rule['must_also_match_any'] as $extra) {
                $extra = (string)$extra;
                if ($extra !== '' && mb_stripos($haystack, $extra, 0, 'UTF-8') !== false) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
