<?php
declare(strict_types=1);

final class PackerAuth
{
    /** @var array<string,string>|null */
    private static $map = null;

    /** @return array<string,string> */
    public static function getMap(): array
    {
        if (self::$map !== null) return self::$map;

        $raw = trim((string)env('PACKERS_MAP', ''));
        $out = [];

        if ($raw !== '') {
            foreach (explode(',', $raw) as $part) {
                $part = trim($part);
                if ($part === '') continue;

                $sep = (strpos($part, '=') !== false) ? '=' : (strpos($part, ':') !== false ? ':' : null);
                if ($sep === null) continue;

                [$id, $name] = array_map('trim', explode($sep, $part, 2));
                if ($id === '' || $name === '') continue;

                if (!preg_match('/^\d{1,2}$/', $id)) continue;
                $id = str_pad($id, 2, '0', STR_PAD_LEFT);

                if (mb_strlen($name, 'UTF-8') < 2) continue;

                $out[$id] = $name;
            }
        }

        self::$map = $out;
        return $out;
    }

    public static function packerFromBarcode(string $code): ?string
    {
        $code = trim($code);
        if ($code === '') return null;

        // prefix a0 jak w starym systemie
        if (strncasecmp($code, 'a0', 2) !== 0) return null;

        $id = substr($code, -2); // ostatnie 2 znaki
        if (!preg_match('/^\d{2}$/', $id)) return null;

        $map = self::getMap();
        return $map[$id] ?? null;
    }
}
