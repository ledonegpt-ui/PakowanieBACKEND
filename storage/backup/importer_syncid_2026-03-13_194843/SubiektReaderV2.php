<?php
declare(strict_types=1);

final class SubiektReaderV2
{
    /** @var \PDO */
    private $mssql;

    /** @var array */
    private $cfg;

    /** @var callable */
    private $log;

    /** @var array|null */
    private $schema = null;

    /** @var array<string,array<int,string>> */
    private $mssqlColsCache = [];

    public function __construct(\PDO $mssql, array $cfg, callable $logger)
    {
        $this->mssql = $mssql;
        $this->cfg   = $cfg;
        $this->log   = $logger;
    }

    /**
     * Pobiera WSZYSTKIE dokumenty z zakresu dni (SUB_DOC_DAYS_BACK) i filtrów.
     * Bez TOP/limit, bez paczek.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchDocsAll(): array
    {
        $s = $this->getSchema();

        $daysBack = env_int_val('SUB_DOC_DAYS_BACK', (int)($this->cfg['subiekt']['days_back'] ?? 1));
        if ($daysBack < 0) $daysBack = 0;
        if ($daysBack > 30) $daysBack = 30;

        $today = new \DateTimeImmutable('today');
        $from  = $today->modify('-' . $daysBack . ' day')->setTime(0, 0, 0);
        $to    = $today->setTime(23, 59, 59);

        $pattern = (string)env_val('SUB_PODTYTUL_PATTERN', (string)($this->cfg['subiekt']['podtytul_pattern'] ?? '*'));
        if ($pattern === '') $pattern = '*';

        $types = $this->parseIntList((string)env_val('SUB_DOC_TYPES', (string)($this->cfg['subiekt']['doc_types'] ?? '')));

        $magIdStr = trim((string)env_val('SUB_MAG_ID', (string)($this->cfg['subiekt']['mag_id'] ?? '')));
        $magId = ($magIdStr !== '' && ctype_digit($magIdStr)) ? (int)$magIdStr : null;

        $select = [
            "d.dok_Id AS dok_Id",
            "d.{$s['noCol']} AS dok_NrPelny",
            "d.{$s['dateCol']} AS dok_Data",
            "d.{$s['podCol']} AS dok_Podtytul",
        ];
        if (!empty($s['uwCol']))     $select[] = "d.{$s['uwCol']} AS dok_Uwagi";
        if (!empty($s['typCol']))    $select[] = "d.{$s['typCol']} AS dok_Typ";
        // ZMIANA: dodano kwota pobrania i waluta
        if (!empty($s['przelewCol'])) $select[] = "d.{$s['przelewCol']} AS dok_KwPrzelew";
        if (!empty($s['walutaCol']))  $select[] = "d.{$s['walutaCol']} AS dok_Waluta";

        $sql = "SELECT " . implode(", ", $select) . "
                FROM {$s['docTable']} d
                WHERE d.{$s['dateCol']} >= :from AND d.{$s['dateCol']} <= :to
                  AND d.{$s['podCol']} LIKE :pt";

        $bind = [
            ':from'  => $from->format('Y-m-d H:i:s'),
            ':to'    => $to->format('Y-m-d H:i:s'),
            ':pt'    => '%' . $pattern . '%',
        ];

        if ($types && !empty($s['typCol'])) {
            $in = [];
            $i = 0;
            foreach ($types as $t) {
                $ph = ':t' . $i++;
                $in[] = $ph;
                $bind[$ph] = $t;
            }
            $sql .= " AND d.{$s['typCol']} IN (" . implode(',', $in) . ")";
        }

        if ($magId !== null && !empty($s['magDocCol'])) {
            $sql .= " AND d.{$s['magDocCol']} = :magD";
            $bind[':magD'] = $magId;
        }

        if (!empty($s['uwCol'])) {
            $sql .= " AND (d.{$s['uwCol']} IS NULL OR (d.{$s['uwCol']} NOT LIKE :badUwBang AND d.{$s['uwCol']} NOT LIKE :badUwW0))";
            $bind[':badUwBang'] = '%!%';
            $bind[':badUwW0']   = '%W-0%';
        }

        $sql .= " ORDER BY d.dok_Id ASC";

        $st = $this->mssql->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Zwraca mapę: docId => items[]
     * (wewnętrznie chunkuje IN(), żeby nie zabić limitów parametrów MSSQL)
     *
     * @param array<int,int> $docIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public function fetchItemsForDocs(array $docIds): array
    {
        $docIds = array_values(array_unique(array_filter($docIds, function($v){
            return is_int($v) && $v > 0;
        })));
        if (!$docIds) return [];

        $s = $this->getSchema();

        $magIdStr = trim((string)env_val('SUB_MAG_ID', (string)($this->cfg['subiekt']['mag_id'] ?? '')));
        $magId = ($magIdStr !== '' && ctype_digit($magIdStr)) ? (int)$magIdStr : null;

        $out = [];

        foreach (array_chunk($docIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));

            $sel = [
                "p.{$s['posDocCol']} AS dok_Id",
                "p.{$s['obIdCol']}  AS ob_Id",
                "p.{$s['towIdCol']} AS ob_TowId",
                "p.{$s['qtyCol']}   AS ob_Ilosc",
                ($s['priceCol'] ? "p.{$s['priceCol']}" : "NULL") . " AS ob_CenaBrutto",
                "t.{$s['twSymbolCol']} AS tw_Symbol",
                "t.{$s['twNameCol']}   AS tw_Nazwa",
                ($s['twOpisCol'] ? "t.{$s['twOpisCol']}" : "NULL") . " AS tw_Opis",
            ];

            $sql = "SELECT " . implode(", ", $sel) . "
                    FROM {$s['posTable']} p
                    LEFT JOIN {$s['towTable']} t ON p.{$s['towIdCol']} = t.{$s['twIdCol']}
                    WHERE p.{$s['posDocCol']} IN ($ph)";

            $bind = $chunk;

            if ($magId !== null && !empty($s['magPosCol'])) {
                $sql .= " AND p.{$s['magPosCol']} = ?";
                $bind[] = $magId;
            }

            $sql .= " ORDER BY p.{$s['posDocCol']} ASC, p.{$s['obIdCol']} ASC";

            $st = $this->mssql->prepare($sql);
            $st->execute($bind);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $did = (int)$r['dok_Id'];
                if (!isset($out[$did])) $out[$did] = [];
                $out[$did][] = $r;
            }
        }

        return $out;
    }

    /** ---------- schema ----------- */

    private function getSchema(): array
    {
        if ($this->schema !== null) return $this->schema;

        $cachePath = __DIR__ . '/../../storage/cache/subiekt_schema.php';
        if (!is_dir(dirname($cachePath))) {
            @mkdir(dirname($cachePath), 0775, true);
        }

        // cache on by default
        $useCache = (string)env_val('SUB_SCHEMA_CACHE', '1');
        if ($useCache !== '0' && is_file($cachePath)) {
            $data = @include $cachePath;
            if (is_array($data) && isset($data['docTable'], $data['posTable'], $data['towTable'])) {
                $this->schema = $data;
                ($this->log)("SUBR: schema loaded from cache");
                return $this->schema;
            }
        }

        $daysBack = env_int_val('SUB_DOC_DAYS_BACK', (int)($this->cfg['subiekt']['days_back'] ?? 1));
        if ($daysBack < 0) $daysBack = 0;
        if ($daysBack > 30) $daysBack = 30;

        $today = new \DateTimeImmutable('today');
        $from  = $today->modify('-' . $daysBack . ' day')->setTime(0, 0, 0);
        $to    = $today->setTime(23, 59, 59);

        $pattern = (string)env_val('SUB_PODTYTUL_PATTERN', (string)($this->cfg['subiekt']['podtytul_pattern'] ?? '*'));
        if ($pattern === '') $pattern = '*';

        $types = $this->parseIntList((string)env_val('SUB_DOC_TYPES', (string)($this->cfg['subiekt']['doc_types'] ?? '')));

        $this->schema = $this->detectSchema($from, $to, $pattern, $types);

        // save cache
        if ($useCache !== '0') {
            $export = var_export($this->schema, true);
            @file_put_contents($cachePath, "<?php\nreturn {$export};\n");
        }

        ($this->log)("SUBR: schema docTable={$this->schema['docTable']} posTable={$this->schema['posTable']} towTable={$this->schema['towTable']} posDocCol={$this->schema['posDocCol']} twOpisCol=" . ($this->schema['twOpisCol'] ?? 'NULL') . " przelewCol=" . ($this->schema['przelewCol'] ?? 'NULL'));
        return $this->schema;
    }

    private function parseIntList(string $csv): array
    {
        if (trim($csv) === '') return [];
        $out = [];
        foreach (explode(',', $csv) as $p) {
            $p = trim($p);
            if ($p === '' || !ctype_digit($p)) continue;
            $out[] = (int)$p;
        }
        return array_values(array_unique($out));
    }

    /** autodetekcja tabel/kolumn + wybór tw_Opis jeśli istnieje */
    private function detectSchema(\DateTimeImmutable $from, \DateTimeImmutable $to, string $pattern, array $types): array
    {
        $docTables = $this->mssql->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME LIKE 'dok%'
            GROUP BY TABLE_NAME
            HAVING SUM(CASE WHEN COLUMN_NAME='dok_Id' THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME='dok_Podtytul' THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME IN ('dok_DataWyst','dok_Data','dok_DataWystaw') THEN 1 ELSE 0 END) > 0
            ORDER BY TABLE_NAME ASC
        ")->fetchAll(\PDO::FETCH_COLUMN);

        $posTables = $this->mssql->query("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME LIKE 'dok%'
            GROUP BY TABLE_NAME
            HAVING SUM(CASE WHEN COLUMN_NAME='ob_Id' THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME='ob_TowId' THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME IN ('ob_Ilosc','ob_Il') THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME IN ('ob_DokMagId','ob_DokHanId','ob_DokId','ob_DokumentId') THEN 1 ELSE 0 END) > 0
            ORDER BY TABLE_NAME ASC
        ")->fetchAll(\PDO::FETCH_COLUMN);

        if (!$docTables) throw new \RuntimeException('Brak tabeli dokumentów dok_*');
        if (!$posTables) throw new \RuntimeException('Brak tabeli pozycji dok_*');

        $towTable = $this->mssqlTableExists('tw__Towar') ? 'tw__Towar' : $this->detectTowTableAny();
        if (!$towTable) throw new \RuntimeException('Brak tabeli towarów (tw__Towar lub podobna)');

        $towCols = $this->mssqlColumns($towTable);
        $twIdCol = $this->firstCol($towCols, ['tw_Id']);
        $twSymbolCol = $this->firstCol($towCols, ['tw_Symbol']);
        $twNameCol = $this->firstCol($towCols, ['tw_Nazwa']);
        $twOpisCol = $this->firstCol($towCols, ['tw_Opis','tw_OpisWWW','tw_OpisDodatkowy','tw_OpisKrotki','tw_Uwagi','tw_Opis2','tw_Opis3']);

        if (!$twIdCol || !$twSymbolCol || !$twNameCol) {
            throw new \RuntimeException("Tabela {$towTable} nie ma tw_Id/tw_Symbol/tw_Nazwa");
        }

        foreach ($docTables as $dt) {
            $dt = (string)$dt;
            $docCols = $this->mssqlColumns($dt);
            $dateCol = $this->firstCol($docCols, ['dok_DataWyst','dok_Data','dok_DataWystaw']);
            $noCol   = $this->firstCol($docCols, ['dok_NrPelny','dok_NrPelnyDruk','dok_NrPelnyTekst']);
            $podCol  = $this->firstCol($docCols, ['dok_Podtytul']);
            if (!$dateCol || !$noCol || !$podCol) continue;

            $typCol    = $this->firstCol($docCols, ['dok_Typ','dok_Podtyp']);
            $uwCol     = $this->firstCol($docCols, ['dok_Uwagi','dok_Opis']);
            $magDocCol = $this->firstCol($docCols, ['dok_MagId','dok_MagazynId','dok_Mag']);
            // ZMIANA: autodetekcja kolumn kwoty pobrania i waluty
            $przelewCol = $this->firstCol($docCols, ['dok_KwPrzelew']);
            $walutaCol  = $this->firstCol($docCols, ['dok_Waluta']);

            // próbka dokumentu w zakresie
            $bind = [
                ':from' => $from->format('Y-m-d H:i:s'),
                ':to'   => $to->format('Y-m-d H:i:s'),
                ':pt'   => '%' . $pattern . '%',
            ];
            $sql = "SELECT TOP 1 dok_Id FROM {$dt}
                    WHERE {$dateCol} >= :from AND {$dateCol} <= :to
                      AND {$podCol} LIKE :pt";
            if ($types && $typCol) {
                $in = [];
                $i = 0;
                foreach ($types as $t) { $ph=':t'.$i++; $in[]=$ph; $bind[$ph]=$t; }
                $sql .= " AND {$typCol} IN (" . implode(',', $in) . ")";
            }
            $sql .= " ORDER BY {$dateCol} DESC";

            $st = $this->mssql->prepare($sql);
            $st->execute($bind);
            $sampleDocId = (int)($st->fetchColumn() ?: 0);
            if ($sampleDocId < 1) continue;

            foreach ($posTables as $pt) {
                $pt = (string)$pt;
                $posCols = $this->mssqlColumns($pt);

                $obIdCol  = $this->firstCol($posCols, ['ob_Id']);
                $towIdCol = $this->firstCol($posCols, ['ob_TowId']);
                $qtyCol   = $this->firstCol($posCols, ['ob_Ilosc','ob_Il']);
                if (!$obIdCol || !$towIdCol || !$qtyCol) continue;

                $priceCol  = $this->firstCol($posCols, ['ob_CenaBrutto','ob_CenaBr']);
                $magPosCol = $this->firstCol($posCols, ['ob_MagId','ob_MagazynId','ob_Mag']);

                $posDocCandidates = [];
                foreach (['ob_DokMagId','ob_DokHanId','ob_DokId','ob_DokumentId'] as $c) {
                    if (in_array($c, $posCols, true)) $posDocCandidates[] = $c;
                }

                foreach ($posDocCandidates as $posDocCol) {
                    $chk = $this->mssql->prepare("SELECT TOP 1 {$obIdCol} FROM {$pt} WHERE {$posDocCol} = :id");
                    $chk->execute([':id' => $sampleDocId]);
                    $one = $chk->fetchColumn();
                    if ($one !== false && $one !== null) {
                        return [
                            'docTable'   => $dt,
                            'posTable'   => $pt,
                            'towTable'   => $towTable,
                            'dateCol'    => $dateCol,
                            'noCol'      => $noCol,
                            'podCol'     => $podCol,
                            'typCol'     => $typCol,
                            'uwCol'      => $uwCol,
                            'magDocCol'  => $magDocCol,
                            // ZMIANA: nowe kolumny w schemacie
                            'przelewCol' => $przelewCol,
                            'walutaCol'  => $walutaCol,
                            'posDocCol'  => $posDocCol,
                            'obIdCol'    => $obIdCol,
                            'towIdCol'   => $towIdCol,
                            'qtyCol'     => $qtyCol,
                            'priceCol'   => $priceCol,
                            'magPosCol'  => $magPosCol,
                            'twIdCol'    => $twIdCol,
                            'twSymbolCol'=> $twSymbolCol,
                            'twNameCol'  => $twNameCol,
                            'twOpisCol'  => $twOpisCol,
                        ];
                    }
                }
            }
        }

        throw new \RuntimeException('Nie umiem dopasować pozycji do dokumentów (ob_Dok*)');
    }

    private function mssqlTableExists(string $table): bool
    {
        $st = $this->mssql->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME=:t");
        $st->execute([':t'=>$table]);
        return (bool)$st->fetchColumn();
    }

    private function detectTowTableAny(): ?string
    {
        $st = $this->mssql->query("
            SELECT TOP 50 TABLE_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME LIKE 'tw%'
            GROUP BY TABLE_NAME
            HAVING SUM(CASE WHEN COLUMN_NAME='tw_Id' THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME='tw_Symbol' THEN 1 ELSE 0 END) > 0
               AND SUM(CASE WHEN COLUMN_NAME='tw_Nazwa' THEN 1 ELSE 0 END) > 0
            ORDER BY TABLE_NAME ASC
        ");
        $t = $st ? $st->fetchColumn() : false;
        return $t ? (string)$t : null;
    }

    private function mssqlColumns(string $table): array
    {
        if (isset($this->mssqlColsCache[$table])) return $this->mssqlColsCache[$table];

        $st = $this->mssql->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME=:t");
        $st->execute([':t'=>$table]);

        $cols = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $cols[] = (string)$r['COLUMN_NAME'];
        $this->mssqlColsCache[$table] = $cols;
        return $cols;
    }

    private function firstCol(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
        return null;
    }
}