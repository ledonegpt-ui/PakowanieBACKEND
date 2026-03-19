<?php
declare(strict_types=1);

final class Resp
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    public static function bad(string $msg, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['ok' => false, 'error' => $msg], $extra), $status);
    }
}
