<?php
declare(strict_types=1);

final class LegacyAuctionPhotoMap
{
    /** @var \PDO */
    private $mysql2;

    /** @var callable|null */
    private $log;

    /** @var array<string,string>|null */
    private $map = null;

    public function __construct(\PDO $mysql2, callable $logger = null)
    {
        $this->mysql2 = $mysql2;
        $this->log = $logger;
    }

    /**
     * Zwraca mapę zdjęć dla przekazanych wierszy Subiekta:
     * key = "{ob_TowId}|{TW_SYMBOL_UPPER}" => image_url
     *
     * @param array<int,array<string,mixed>> $subiektRows
     * @return array<string,string>
     */
    public function buildImageMapForSubiektRows(array $subiektRows): array
    {
        $this->ensureLoaded();

        if (!$this->map || !$subiektRows) return [];

        $out = [];
        foreach ($subiektRows as $r) {
            $towId = isset($r['ob_TowId']) ? (int)$r['ob_TowId'] : 0;
            $sym   = isset($r['tw_Symbol']) ? (string)$r['tw_Symbol'] : '';

            $key = $this->makeKey(($towId > 0 ? (string)$towId : ''), $sym);
            if ($key === null) continue;

            if (isset($this->map[$key])) {
                $out[$key] = $this->map[$key];
            }
        }

        return $out;
    }

    private function ensureLoaded(): void
    {
        if ($this->map !== null) return;

        $this->map = [];

        // Bierzemy tylko aukcje, które mają JEDNOZNACZNE przypisanie aukcja -> produkt
        // oraz tylko te produkty, które mają dokładnie 1 różne zdjęcie w takich aukcjach.
        $sql = "
            SELECT
                TRIM(COALESCE(p.index_subiekt, '')) AS idx,
                TRIM(COALESCE(p.symbol_subiekt, '')) AS sym,
                MIN(TRIM(a.fotka_auckji)) AS image_url
            FROM przypisane p
            JOIN allegro_aukcje a
              ON a.nr_aukcji = p.nr_aukcji
            JOIN (
                SELECT nr_aukcji
                FROM przypisane
                GROUP BY nr_aukcji
                HAVING COUNT(DISTINCT CONCAT(COALESCE(index_subiekt,''),'|',COALESCE(symbol_subiekt,''))) = 1
            ) s
              ON s.nr_aukcji = p.nr_aukcji
            WHERE a.fotka_auckji IS NOT NULL
              AND TRIM(a.fotka_auckji) <> ''
              AND p.index_subiekt IS NOT NULL
              AND TRIM(p.index_subiekt) <> ''
              AND p.symbol_subiekt IS NOT NULL
              AND TRIM(p.symbol_subiekt) <> ''
            GROUP BY
                TRIM(COALESCE(p.index_subiekt, '')),
                TRIM(COALESCE(p.symbol_subiekt, ''))
            HAVING COUNT(DISTINCT TRIM(a.fotka_auckji)) = 1
        ";

        $st = $this->mysql2->query($sql);
        if (!$st) return;

        $n = 0;
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $idx = (string)($r['idx'] ?? '');
            $sym = (string)($r['sym'] ?? '');
            $url = trim((string)($r['image_url'] ?? ''));

            if ($url === '') continue;
            if (strlen($url) > 500) $url = substr($url, 0, 500);

            $key = $this->makeKey($idx, $sym);
            if ($key === null) continue;

            $this->map[$key] = $url;
            $n++;
        }

        if (is_callable($this->log)) {
            call_user_func($this->log, "LEGACY_IMG: loaded uniq product photo map count=" . $n);
        }
    }

    private function makeKey(string $idx, string $sym): ?string
    {
        $idx = trim($idx);
        $sym = strtoupper(trim($sym));

        if ($idx === '' || $sym === '') return null;
        return $idx . '|' . $sym;
    }
}