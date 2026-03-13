<?php
declare(strict_types=1);

final class ApiResponse
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = [], int $status = 200): void
    {
        self::json([
            'ok' => true,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json([
            'ok' => false,
            'error' => $message,
            'details' => $extra,
        ], $status);
    }
}
