<?php
declare(strict_types=1);

final class NextActionResolver
{
    private const DEFAULT_MESSAGES = array(
        'go_home'            => 'Wróć do menu',
        'show_carrier_queue' => 'Wybierz kuriera do rozpoczęcia zbierania',
        'resume_picking'     => 'Wznów zbieranie batcha',
        'open_packing'       => 'Znajdź koszyk do zapakowania',
        'resume_packing'     => 'Wznów pakowanie',
        'show_modal'         => 'Wymagana jest akcja operatora',
    );

    public static function build(string $type, ?string $message = null, array $extra = array()): array
    {
        $payload = array(
            'type'          => $type,
            'batch_id'      => null,
            'order_code'    => null,
            'basket_no'     => null,
            'workflow_mode' => null,
            'work_mode'     => null,
            'same_station'  => null,
            'modal'         => null,
            'message'       => self::normalizeMessage($type, $message),
        );

        foreach ($extra as $key => $value) {
            $payload[$key] = $value;
        }

        if (!isset($payload['message']) || $payload['message'] === null || $payload['message'] === '') {
            $payload['message'] = self::normalizeMessage($type, null);
        }

        return $payload;
    }

    public static function goHome(?string $message = null, array $extra = array()): array
    {
        return self::build('go_home', $message, $extra);
    }

    public static function showCarrierQueue(?string $message = null, array $extra = array()): array
    {
        return self::build('show_carrier_queue', $message, $extra);
    }

    public static function openPacking(?int $batchId = null, ?string $orderCode = null, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge(array(
            'batch_id'   => $batchId,
            'order_code' => $orderCode,
        ), $extra);

        return self::build('open_packing', $message, $extra);
    }

    public static function resumePacking(string $orderCode, int $batchId, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge(array(
            'order_code' => $orderCode,
            'batch_id'   => $batchId,
        ), $extra);

        return self::build('resume_packing', $message, $extra);
    }

    public static function resumePicking(int $batchId, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge(array(
            'batch_id' => $batchId,
        ), $extra);

        return self::build('resume_picking', $message, $extra);
    }

    public static function showModal(string $modal, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge(array(
            'modal' => $modal,
        ), $extra);

        return self::build('show_modal', $message, $extra);
    }

    public static function fromLegacyWorkflowAction(array $workflow): array
    {
        $action = isset($workflow['action']) ? (string)$workflow['action'] : '';

        $common = array(
            'workflow_mode' => isset($workflow['workflow_mode']) ? (string)$workflow['workflow_mode'] : null,
            'work_mode'     => isset($workflow['work_mode']) ? (string)$workflow['work_mode'] : null,
            'same_station'  => array_key_exists('same_station', $workflow) ? (is_null($workflow['same_station']) ? null : (bool)$workflow['same_station']) : null,
            'basket_no'     => isset($workflow['basket_no']) ? (int)$workflow['basket_no'] : null,
        );

        if ($action === 'resume_packing') {
            return self::resumePacking(
                isset($workflow['order_code']) ? (string)$workflow['order_code'] : '',
                isset($workflow['batch_id']) ? (int)$workflow['batch_id'] : 0,
                isset($workflow['message']) ? (string)$workflow['message'] : null,
                $common
            );
        }

        if ($action === 'resume_picking') {
            return self::resumePicking(
                isset($workflow['batch_id']) ? (int)$workflow['batch_id'] : 0,
                isset($workflow['message']) ? (string)$workflow['message'] : null,
                $common
            );
        }

        if ($action === 'start_packing') {
            return self::openPacking(
                isset($workflow['batch_id']) ? (int)$workflow['batch_id'] : null,
                isset($workflow['order_code']) ? (string)$workflow['order_code'] : null,
                isset($workflow['message']) ? (string)$workflow['message'] : null,
                $common
            );
        }

        if ($action === 'start_picking') {
            return self::showCarrierQueue(
                isset($workflow['message']) ? (string)$workflow['message'] : null,
                $common
            );
        }

        return self::goHome(
            isset($workflow['message']) ? (string)$workflow['message'] : null,
            $common
        );
    }

    private static function normalizeMessage(string $type, ?string $message): string
    {
        if ($message !== null && trim($message) !== '') {
            return $message;
        }

        return isset(self::DEFAULT_MESSAGES[$type])
            ? self::DEFAULT_MESSAGES[$type]
            : 'Wróć do menu';
    }
}
