<?php
declare(strict_types=1);

final class ImporterSubiekt
{
    private $mysql;
    private $mssql;
    private $cfg;
    private $log;

    /** @var array<string,array<int,string>> */
    private $mssqlColsCache = [];

    public function __construct(\PDO $mysql, \PDO $mssql, array $cfg, callable $logger)
    {
        $this->mysql = $mysql;
        $this->mssql = $mssql;
        $this->cfg   = $cfg;
        $this->log   = $logger;
    }

    public function run(): array
    {
        $daysBack = (int)env_int('SUB_DOC_DAYS_BACK', 0);
        if ($daysBack < 0) $daysBack = 0;
        if ($daysBack > 30) $daysBack = 30;

        $today = new \DateTimeImmutable('today');
        $from  = $today->modify('-' . $daysBack . ' day')->setTime(0, 0, 0);
        $to    = $today->setTime(23, 59, 59);

        $pattern = (string)env('SUB_PODTYTUL_PATTERN', '*');
        if ($pattern === '') $pattern = '*';

        $types = $this->parseIntList(trim((string)env('SUB_DOC_TYPES', '')));

        $magIdStr = trim((string)env('SUB_MAG_ID', ''));
        $magId = ($magIdStr !== '' && ctype_digit($magIdStr)) ? (int)$magIdStr : null;

        $schema = $this->detectSchema($from, $to, $pattern, $types);
        ($this->log)("SUB: schema docTable={$schema['docTable']} posTable={$schema['posTable']} towTable={$schema['towTable']} posDocCol={$schema['posDocCol']} twOpisCol=" . ($schema['twOpisCol'] ?? 'NULL'));

        $bind = [
            ':from' => $from->format('Y-m-d H:i:s'),
            ':to'   => $to->format('Y-m-d H:i:s'),
            ':pt'   => '%' . $pattern . '%',
        ];

        $select = [
            "d.dok_Id AS dok_Id",
            "d.{$schema['noCol']} AS dok_NrPelny",
            "d.{$schema['dateCol']} AS dok_Data",
            "d.{$schema['podCol']} AS dok_Podtytul",
        ];
        if ($schema['typCol']) $select[] = "d.{$schema['typCol']} AS dok_Typ";
        if ($schema['uwCol'])  $select[] = "d.{$schema['uwCol']} AS dok_Uwagi";

        $sql = "SELECT " . implode(", ", $select) . " FROM {$schema['docTable']} d
                WHERE d.{$schema['dateCol']} >= :from AND d.{$schema['dateCol']} <= :to
                  AND d.{$schema['podCol']} LIKE :pt";

        if ($types && $schema['typCol']) {
            $in = [];
            $i = 0;
            foreach ($types as $t) {
                $ph = ':t' . $i++;
                $in[] = $ph;
                $bind[$ph] = $t;
            }
            $sql .= " AND d.{$schema['typCol']} IN (" . implode(',', $in) . ")";
        }

        if ($magId !== null && $schema['magDocCol']) {
            $sql .= " AND d.{$schema['magDocCol']} = :magD";
            $bind[':magD'] = $magId;
        }

        $sql .= " ORDER BY d.dok_Id ASC";

        $st = $this->mssql->prepare($sql);
        $st->execute($bind);
        $docs = $st->fetchAll(\PDO::FETCH_ASSOC);

        if (!$docs) {
            ($this->log)("SUB: brak dokumentów ({$bind[':from']}..{$bind[':to']})");
            return ['docs'=>0,'orders'=>0,'items'=>0,'skipped'=>0];
        }

        $countDocs = count($docs);
        $countOrders = 0;
        $countItems  = 0;
        $skipped = 0;

        $this->mysql->beginTransaction();
        try {
            foreach ($docs as $d) {
                $orderCode = $this->extractOrderCode((string)($d['dok_Podtytul'] ?? ''));
                if ($orderCode === null) { $skipped++; continue; }

                $map = $this->mapSource($orderCode);

                $docId = (int)$d['dok_Id'];
                $docNo = (string)($d['dok_NrPelny'] ?? '');
                $notes = (string)($d['dok_Uwagi'] ?? '');

                $status = $this->mysqlOrderStatus($orderCode);
                if ($status === null) $status = 10;

                if ($status >= 40) {
                    $this->updateSafeHeader($orderCode, $docId, $docNo, '*' . $orderCode . '*');
                    $countOrders++;
                    continue;
                }

                $this->upsertHeader([
                    'order_code'     => $orderCode,
                    'source'         => $map['source'],
                    'bl_order_id'    => $map['bl_order_id'],
                    'eu_main_id'     => $map['eu_main_id'],
                    'subiekt_doc_id' => $docId,
                    'subiekt_doc_no' => $docNo,
                    'subiekt_hint'   => '*' . $orderCode . '*',
                    'notes'          => $notes,
                ]);
                $countOrders++;

                $items = $this->fetchDocItems(
                    $schema,
                    $docId,
                    $magId
                );

                if (!$items) {
                    ($this->log)("SUB: UWAGA brak pozycji dla dok_Id={$docId} order_code={$orderCode}");
                }

                $this->syncItems($orderCode, $items);
                $countItems += count($items);
            }

            $this->mysql->commit();
        } catch (\Throwable $e) {
            if ($this->mysql->inTransaction()) $this->mysql->rollBack();
            throw $e;
        }

        return ['docs'=>$countDocs,'orders'=>$countOrders,'items'=>$countItems,'skipped'=>$skipped];
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

    private function extractOrderCode(string $podtytul): ?string
    {
        if ($podtytul === '') return null;
        if (!preg_match('/\*(B\d+|E\d+|\d+)\*/i', $podtytul, $m)) return null;
        $code = strtoupper(trim((string)$m[1]));
        if ($code === '' || strlen($code) > 32) return null;
        if (!preg_match('/^[0-9A-Z]+$/', $code)) return null;
        return $code;
    }

    private function mapSource(string $orderCode): array
    {
        if (strpos($orderCode, 'B') === 0) return ['source'=>'B','bl_order_id'=>(int)substr($orderCode,1),'eu_main_id'=>null];
        if (strpos($orderCode, 'E') === 0) return ['source'=>'E','bl_order_id'=>(int)substr($orderCode,1),'eu_main_id'=>null];
        return ['source'=>'U','bl_order_id'=>null,'eu_main_id'=>(int)$orderCode];
    }

    private function mysqlOrderStatus(string $orderCode): ?int
    {
        $st = $this->mysql->prepare("SELECT status FROM pak_orders WHERE order_code=:c LIMIT 1");
        $st->execute([':c'=>$orderCode]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? (int)$r['status'] : null;
    }

    private function updateSafeHeader(string $orderCode, int $docId, string $docNo, string $hint): void
    {
        $st = $this->mysql->prepare("
            UPDATE pak_orders
            SET subiekt_doc_id=:id, subiekt_doc_no=:no, subiekt_hint=:h, updated_at=NOW()
            WHERE order_code=:c
        ");
        $st->execute([':id'=>$docId,':no'=>$docNo,':h'=>$hint,':c'=>$orderCode]);
    }

    private function upsertHeader(array $d): void
    {
        $st = $this->mysql->prepare("
            INSERT INTO pak_orders (
                order_code, source, eu_main_id, bl_order_id,
                subiekt_hint, subiekt_doc_id, subiekt_doc_no, notes, status
            ) VALUES (
                :order_code, :source, :eu_main_id, :bl_order_id,
                :subiekt_hint, :subiekt_doc_id, :subiekt_doc_no, :notes, 10
            )
            ON DUPLICATE KEY UPDATE
                source = VALUES(source),
                eu_main_id = VALUES(eu_main_id),
                bl_order_id = VALUES(bl_order_id),
                subiekt_hint = VALUES(subiekt_hint),
                subiekt_doc_id = VALUES(subiekt_doc_id),
                subiekt_doc_no = VALUES(subiekt_doc_no),
                notes = IF(notes IS NULL OR notes='', VALUES(notes), notes),
                updated_at = NOW()
        ");
        $st->execute([
            ':order_code'=>(string)$d['order_code'],
            ':source'=>(string)$d['source'],
            ':eu_main_id'=>$d['eu_main_id'],
            ':bl_order_id'=>$d['bl_order_id'],
            ':subiekt_hint'=>(string)$d['subiekt_hint'],
            ':subiekt_doc_id'=>(int)$d['subiekt_doc_id'],
            ':subiekt_doc_no'=>(string)$d['subiekt_doc_no'],
            ':notes'=>(string)($d['notes'] ?? ''),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchDocItems(array $s, int $docId, ?int $magId): array
    {
        $sel = [
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
                WHERE p.{$s['posDocCol']} = :doc";

        $bind = [':doc'=>$docId];

        if ($magId !== null && $s['magPosCol']) {
            $sql .= " AND p.{$s['magPosCol']} = :mag";
            $bind[':mag'] = $magId;
        }

        $sql .= " ORDER BY p.{$s['obIdCol']} ASC";

        $st = $this->mssql->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function syncItems(string $orderCode, array $items): void
    {
        $del = $this->mysql->prepare("DELETE FROM pak_order_items WHERE order_code=:c AND line_key LIKE 'SUB-%'");
        $del->execute([':c'=>$orderCode]);

        if (!$items) return;

        $ins = $this->mysql->prepare("
            INSERT INTO pak_order_items (
                order_code, line_key,
                subiekt_tow_id, subiekt_symbol,
                name, subiekt_desc,
                quantity, unit_price_brutto
            ) VALUES (
                :order_code, :line_key,
                :subiekt_tow_id, :subiekt_symbol,
                :name, :subiekt_desc,
                :quantity, :unit_price_brutto
            )
            ON DUPLICATE KEY UPDATE
                subiekt_tow_id = VALUES(subiekt_tow_id),
                subiekt_symbol = VALUES(subiekt_symbol),
                name = VALUES(name),
                subiekt_desc = VALUES(subiekt_desc),
                quantity = VALUES(quantity),
                unit_price_brutto = VALUES(unit_price_brutto),
                updated_at = NOW()
        ");

        foreach ($items as $r) {
            $obId  = (int)$r['ob_Id'];
            $towId = (int)$r['ob_TowId'];
            $qtyF  = (float)$r['ob_Ilosc'];
            $qty   = (int)round($qtyF);
            if ($qty < 1 && $qtyF > 0) $qty = 1;

            $sym   = (string)($r['tw_Symbol'] ?? '');
            $name  = (string)($r['tw_Nazwa'] ?? '');
            $desc  = (string)($r['tw_Opis'] ?? '');
            $desc  = trim(preg_replace('/\s+/u', ' ', $desc));

            $price = ($r['ob_CenaBrutto'] !== null) ? (float)$r['ob_CenaBrutto'] : null;

            $ins->execute([
                ':order_code'=>$orderCode,
                ':line_key'=>'SUB-' . $obId,
                ':subiekt_tow_id'=>$towId,
                ':subiekt_symbol'=>$sym,
                ':name'=>($name !== '' ? $name : $sym),
                ':subiekt_desc'=>$desc,
                ':quantity'=>$qty,
                ':unit_price_brutto'=>$price,
            ]);
        }
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

        $twOpisCol = $this->firstCol($towCols, [
            'tw_Opis',
            'tw_OpisWWW',
            'tw_OpisDodatkowy',
            'tw_OpisKrotki',
            'tw_Uwagi',
            'tw_Opis2',
            'tw_Opis3',
        ]);

        if (!$twIdCol || !$twSymbolCol || !$twNameCol) {
            throw new \RuntimeException("Tabela {$towTable} nie ma tw_Id/tw_Symbol/tw_Nazwa");
        }

        foreach ($docTables as $dt) {
            $docCols = $this->mssqlColumns($dt);
            $dateCol = $this->firstCol($docCols, ['dok_DataWyst','dok_Data','dok_DataWystaw']);
            $noCol   = $this->firstCol($docCols, ['dok_NrPelny','dok_NrPelnyDruk','dok_NrPelnyTekst']);
            $podCol  = $this->firstCol($docCols, ['dok_Podtytul']);
            if (!$dateCol || !$noCol || !$podCol) continue;

            $typCol = $this->firstCol($docCols, ['dok_Typ','dok_Podtyp']);
            $uwCol  = $this->firstCol($docCols, ['dok_Uwagi','dok_Opis']);
            $magDocCol = $this->firstCol($docCols, ['dok_MagId','dok_MagazynId','dok_Mag']);

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
                $posCols = $this->mssqlColumns($pt);

                $obIdCol  = $this->firstCol($posCols, ['ob_Id']);
                $towIdCol = $this->firstCol($posCols, ['ob_TowId']);
                $qtyCol   = $this->firstCol($posCols, ['ob_Ilosc','ob_Il']);
                if (!$obIdCol || !$towIdCol || !$qtyCol) continue;

                $priceCol = $this->firstCol($posCols, ['ob_CenaBrutto','ob_CenaBr']);
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
                            'docTable' => $dt,
                            'posTable' => $pt,
                            'towTable' => $towTable,
                            'dateCol' => $dateCol,
                            'noCol' => $noCol,
                            'podCol' => $podCol,
                            'typCol' => $typCol,
                            'uwCol' => $uwCol,
                            'magDocCol' => $magDocCol,
                            'posDocCol' => $posDocCol,
                            'obIdCol' => $obIdCol,
                            'towIdCol' => $towIdCol,
                            'qtyCol' => $qtyCol,
                            'priceCol' => $priceCol,
                            'magPosCol' => $magPosCol,
                            'twIdCol' => $twIdCol,
                            'twSymbolCol' => $twSymbolCol,
                            'twNameCol' => $twNameCol,
                            'twOpisCol' => $twOpisCol,
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
