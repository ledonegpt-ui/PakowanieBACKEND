<?php
declare(strict_types=1);

final class Route
{
    public static function match(string $routePath, string $actualPath): ?array
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m): string {
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $routePath
        );

        $regex = '#^' . $pattern . '$#';

        if (!preg_match($regex, $actualPath, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (!is_int($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
