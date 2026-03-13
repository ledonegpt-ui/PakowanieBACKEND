<?php
declare(strict_types=1);

final class PrintLogs
{
    public static function log(\PDO $db, array $row): void
    {
        $st = $db->prepare("
            INSERT INTO print_logs
            (type, station_name, station_no, order_code, printer_ip, success, duration_ms, error_code, error_message, zpl_bytes)
            VALUES
            (:type, :station_name, :station_no, :order_code, :printer_ip, :success, :duration_ms, :error_code, :error_message, :zpl_bytes)
        ");

        $st->execute([
            ':type'          => (string)($row['type'] ?? ''),
            ':station_name'  => (string)($row['station_name'] ?? ''),
            ':station_no'    => array_key_exists('station_no', $row) ? (int)$row['station_no'] : null,
            ':order_code'    => array_key_exists('order_code', $row) ? ($row['order_code'] !== null ? (string)$row['order_code'] : null) : null,
            ':printer_ip'    => (string)($row['printer_ip'] ?? ''),
            ':success'       => !empty($row['success']) ? 1 : 0,
            ':duration_ms'   => (int)($row['duration_ms'] ?? 0),
            ':error_code'    => array_key_exists('error_code', $row) ? ($row['error_code'] !== null ? (string)$row['error_code'] : null) : null,
            ':error_message' => array_key_exists('error_message', $row) ? ($row['error_message'] !== null ? (string)$row['error_message'] : null) : null,
            ':zpl_bytes'     => array_key_exists('zpl_bytes', $row) ? (int)$row['zpl_bytes'] : null,
        ]);
    }
}
