<?php
declare(strict_types=1);

final class PakEvents
{
    public static function log(\PDO $db, string $orderCode, string $type, ?string $packer, ?string $station, string $message = ''): void
    {
        try {
            $st = $db->prepare("
                INSERT INTO pak_events (order_code, event_type, packer, station, message)
                VALUES (:c, :t, :p, :s, :m)
            ");
            $st->execute([
                ':c' => $orderCode,
                ':t' => $type,
                ':p' => ($packer !== '' ? $packer : null),
                ':s' => ($station !== '' ? $station : null),
                ':m' => ($message !== '' ? mb_substr($message, 0, 255, 'UTF-8') : null),
            ]);
        } catch (\Throwable $e) {
            // event log nie może wywalać API
        }
    }
}
