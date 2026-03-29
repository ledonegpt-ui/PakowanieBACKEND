<?php
declare(strict_types=1);

final class NextActionResolver
{
    public static function build(string $type, ?string $message = null, array $extra = array()): array
    {
        $payload = array(
            'type' => $type,
            'message' => $message,
        );

        foreach ($extra as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    public static function goHome(?string $message = null): array
    {
        return self::build('go_home', $message);
    }

    public static function showCarrierQueue(?string $message = null): array
    {
        return self::build('show_carrier_queue', $message);
    }

    public static function openPacking(?string $message = null): array
    {
        return self::build('open_packing', $message);
    }

    public static function resumePacking(string $orderCode, int $batchId, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge($extra, array(
            'order_code' => $orderCode,
            'batch_id' => $batchId,
        ));

        return self::build('resume_packing', $message, $extra);
    }

    public static function resumePicking(int $batchId, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge($extra, array(
            'batch_id' => $batchId,
        ));

        return self::build('resume_picking', $message, $extra);
    }

    public static function showModal(string $modal, ?string $message = null, array $extra = array()): array
    {
        $extra = array_merge(array('modal' => $modal), $extra);
        return self::build('show_modal', $message, $extra);
    }

    public static function fromLegacyWorkflowAction(array $workflow): array
    {
        $action = isset($workflow['action']) ? (string)$workflow['action'] : '';

        if ($action === 'resume_packing') {
            return self::resumePacking(
                (string)($workflow['order_code'] ?? ''),
                (int)($workflow['batch_id'] ?? 0),
                isset($workflow['message']) ? (string)$workflow['message'] : null,
                array(
                    'station_id' => isset($workflow['station_id']) ? (int)$workflow['station_id'] : null,
                    'same_station' => isset($workflow['same_station']) ? (bool)$workflow['same_station'] : null,
                    'carrier_key' => isset($workflow['carrier_key']) ? (string)$workflow['carrier_key'] : null,
                )
            );
        }

        if ($action === 'resume_picking') {
            return self::resumePicking(
                (int)($workflow['batch_id'] ?? 0),
                isset($workflow['message']) ? (string)$workflow['message'] : null,
                array(
                    'carrier_key' => isset($workflow['carrier_key']) ? (string)$workflow['carrier_key'] : null,
                )
            );
        }

        if ($action === 'start_packing') {
            return self::openPacking(isset($workflow['message']) ? (string)$workflow['message'] : null);
        }

        if ($action === 'start_picking') {
            return self::showCarrierQueue(isset($workflow['message']) ? (string)$workflow['message'] : null);
        }

        return self::goHome(isset($workflow['message']) ? (string)$workflow['message'] : null);
    }
}
