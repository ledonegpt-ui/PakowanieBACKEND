<?php
declare(strict_types=1);

final class ImporterMasterV2
{
    private $mysql;
    private $state;
    private $repo;
    private $sub;
    private $eu;
    private $bl;
    private $cfg;
    private $log;
    private $legacyImg;

    public function __construct(
        \PDO $mysql,
        ImportState $state,
        OrderRepositoryV2 $repo,
        SubiektReaderV2 $sub,
        FirebirdEUReader $eu,
        BaselinkerBatchReader $bl,
        array $cfg,
        callable $logger,
        ?LegacyAuctionPhotoMap $legacyImg = null
    ) {
        $this->mysql = $mysql;
        $this->state = $state;
        $this->repo  = $repo;
        $this->sub   = $sub;
        $this->eu    = $eu;
        $this->bl    = $bl;
        $this->cfg   = $cfg;
        $this->log   = $logger;
        $this->legacyImg = $legacyImg;
    }

    public function run(): array
    {
        $lockName = (string)env_val('IMPORT_LOCK_NAME', (string)($this->cfg['import']['lock_name'] ?? 'import_orders'));
        if (!$this->acquireLock($lockName, 1)) {
            ($this->log)("MASTER: lock busy, skip");
            return ['docs'=>0,'uniq'=>0,'saved'=>0,'skipped'=>0,'incomplete'=>0];
        }

        try {
            $docs = $this->sub->fetchDocsAll();
            if (!$docs) return ['docs'=>0,'uniq'=>0,'saved'=>0,'skipped'=>0,'incomplete'=>0];

            $knownOrderCodes = $this->repo->getAllOrderCodesSet();
            $skippedKnown = 0;
            $filteredUwagi = 0;
            ($this->log)("MASTER: preload known order_code count=" . count($knownOrderCodes));

            $byCode = [];
            $skipped = 0;
            foreach ($docs as $d) {
                $dokId = (int)$d['dok_Id'];
                $code = $this->extractOrderCode((string)($d['dok_Podtytul'] ?? ''));
                if ($code === null) { $skipped++; continue; }

                if (!isset($byCode[$code]) || (int)$byCode[$code]['dok_Id'] < $dokId) {
                    $d['order_code'] = $code;
                    $byCode[$code] = $d;
                }
            }
            if (!$byCode) return ['docs'=>count($docs),'uniq'=>0,'saved'=>0,'skipped'=>$skipped,'incomplete'=>0];

            $refDocs = array_values($byCode);
            $uniqAll = count($refDocs);

            if ($refDocs) {
                $tmp = [];
                foreach ($refDocs as $d) {
                    $oc = strtoupper(trim((string)($d['order_code'] ?? '')));
                    if ($oc === '') { $skipped++; continue; }
                    if (isset($knownOrderCodes[$oc])) { $skippedKnown++; continue; }
                    $d['order_code'] = $oc;
                    $tmp[] = $d;
                }
                $refDocs = $tmp;
            }

            ($this->log)("MASTER: prefilter docs=" . count($docs) . " uniq_all=" . $uniqAll . " known_skip=" . $skippedKnown . " uwagi_skip=" . $filteredUwagi . " to_fetch=" . count($refDocs));

            $docIds = [];
            $codesNumeric = [];
            $codesBL = [];
            foreach ($refDocs as $d) {
                $docIds[] = (int)$d['dok_Id'];
                $code = (string)$d['order_code'];
                if (preg_match('/^[BE]\d+$/', $code)) $codesBL[] = $code;
                elseif (ctype_digit($code)) $codesNumeric[] = (int)$code;
            }

            $subItemsMap = $this->sub->fetchItemsForDocs($docIds);
            $euMap = $this->eu->fetchByMainIds($codesNumeric);
            $blMap = $this->bl->fetchByOrderCodes($codesBL);

            $saved = 0;
            $incomplete = 0;

            foreach ($refDocs as $d) {
                try {
                    $this->mysql->beginTransaction();

                    $orderCode = (string)$d['order_code'];
                    $docId = (int)$d['dok_Id'];
                    $docNo = (string)($d['dok_NrPelny'] ?? '');
                    $notes = (string)($d['dok_Uwagi'] ?? '');
                    $hint  = '*' . $orderCode . '*';

                    $rawPrzelew = $d['dok_KwPrzelew'] ?? null;
                    $rawWaluta  = trim((string)($d['dok_Waluta'] ?? ''));

                    $meta = $this->repo->getMeta($orderCode);

                    $payload = null;
                    if (isset($euMap[$orderCode])) $payload = $euMap[$orderCode];
                    if (isset($blMap[$orderCode])) $payload = $blMap[$orderCode];
                    $h = ($payload && isset($payload['header']) && is_array($payload['header'])) ? $payload['header'] : [];

                    // COD: po przypisaniu $h
                    $isCod = (stripos((string)($h['delivery_method'] ?? ''), 'obranie') !== false);
                    $codAmount   = ($isCod && $rawPrzelew !== null && (float)$rawPrzelew > 0) ? round((float)$rawPrzelew, 2) : null;
                    $codCurrency = ($codAmount !== null && $rawWaluta !== '') ? strtoupper($rawWaluta) : null;

                    $nr  = (string)($h['nr_nadania'] ?? '');
                    $cc  = $h['courier_code'] ?? null;
                    $cin = $h['courier_inner_number'] ?? null;
                    $pid = $h['bl_package_id'] ?? null;
                    $ap  = (string)($h['allegro_parcel_id'] ?? '');

                    $ppId   = $h['pickup_point_id']      ?? null;
                    $ppName = $h['pickup_point_name']     ?? null;
                    $ppAddr = $h['pickup_point_address']  ?? null;

                    if ($meta !== null) {
                        // FROZEN B: nic nie nadpisuj. Tylko dopnij puste.
                        $this->repo->fillSubiektDocIfEmpty($orderCode, $docId, $docNo, $hint, $notes);
                        $this->repo->fillTrackingIfEmpty($orderCode, $nr, $cc, $cin, $pid);
                        $this->repo->fillAllegroParcelIfEmpty($orderCode, $ap);
                        $this->repo->fillPickupPointIfEmpty($orderCode, $ppId, $ppName, $ppAddr);
                        $this->repo->fillCodIfEmpty($orderCode, $codAmount, $codCurrency);
                        $knownOrderCodes[$orderCode] = true;
                        $this->mysql->commit();
                        $saved++;
                        continue;
                    }

                    if ($payload === null || empty($payload['header'])) {
                        ($this->log)("MASTER: INCOMPLETE missing source payload order={$orderCode} doc={$docId}");
                        $this->mysql->rollBack();
                        $incomplete++;
                        continue;
                    }

                    $subItems = $subItemsMap[$docId] ?? [];
                    if (!$subItems) {
                        ($this->log)("MASTER: INCOMPLETE missing subiekt items order={$orderCode} doc={$docId}");
                        $this->mysql->rollBack();
                        $incomplete++;
                        continue;
                    }

                    $this->repo->insertNewOrFillIfExists([
                        'order_code' => $orderCode,
                        'source' => (string)$payload['source'],
                        'eu_main_id' => $payload['eu_main_id'],
                        'bl_order_id' => $payload['bl_order_id'],
                        'shop_order_id' => (string)$payload['shop_order_id'],

                        'delivery_method' => (string)($h['delivery_method'] ?? ''),
                        'courier_priority' => 100,
                        'user_login' => (string)($h['user_login'] ?? ''),
                        'delivery_fullname' => (string)($h['delivery_fullname'] ?? ''),
                        'delivery_address' => (string)($h['delivery_address'] ?? ''),
                        'delivery_city' => (string)($h['delivery_city'] ?? ''),
                        'delivery_postcode' => (string)($h['delivery_postcode'] ?? ''),
                        'phone' => (string)($h['phone'] ?? ''),
                        'email' => (string)($h['email'] ?? ''),

                        'payment_done' => $h['payment_done'] ?? null,
                        'payment_method' => (string)($h['payment_method'] ?? ''),
                        'delivery_price' => $h['delivery_price'] ?? null,

                        'want_invoice' => (int)($h['want_invoice'] ?? 0),
                        'invoice_company' => (string)($h['invoice_company'] ?? ''),
                        'invoice_fullname' => (string)($h['invoice_fullname'] ?? ''),
                        'invoice_address' => (string)($h['invoice_address'] ?? ''),
                        'invoice_city' => (string)($h['invoice_city'] ?? ''),
                        'invoice_postcode' => (string)($h['invoice_postcode'] ?? ''),
                        'invoice_nip' => (string)($h['invoice_nip'] ?? ''),

                        'subiekt_hint' => $hint,
                        'subiekt_doc_id' => $docId,
                        'subiekt_doc_no' => $docNo,
                        'notes' => $notes,

                        'nr_nadania' => $nr,
                        'courier_code' => $cc,
                        'courier_inner_number' => $cin,
                        'bl_package_id' => $pid,
                        'allegro_parcel_id' => $ap,

                        'pickup_point_id'      => $ppId,
                        'pickup_point_name'    => $ppName,
                        'pickup_point_address' => $ppAddr,

                        'cod_amount'   => $codAmount,
                        'cod_currency' => $codCurrency,
                    ]);

                    $subImgMap = [];
                    if ($this->legacyImg !== null) {
                        try {
                            $subImgMap = $this->legacyImg->buildImageMapForSubiektRows($subItems);
                        } catch (\Throwable $e) {
                            ($this->log)("MASTER: legacy image map error order={$orderCode}: " . $e->getMessage());
                            $subImgMap = [];
                        }
                    }

                    $this->repo->syncSubiektItems($orderCode, $subItems, $subImgMap);

                    $this->mysql->commit();
                    $saved++;

                } catch (\Throwable $e) {
                    if ($this->mysql->inTransaction()) $this->mysql->rollBack();
                    ($this->log)("MASTER: ERROR order={$orderCode}: " . $e->getMessage());
                }
            }

            return [
                'docs' => count($docs),
                'uniq' => (int)($uniqAll ?? count($refDocs)),
                'saved' => $saved,
                'skipped' => $skipped,
                'incomplete' => $incomplete,
            ];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /**
     * Importuje pojedyncze zamówienie na żądanie (np. ze skanera).
     * Logika identyczna jak pętla w run(), ale dla jednego kodu.
     *
     * @param string $rawCode  np. "12345", "*B456*", "*1235*" — normalizuje sam
     * @return array{status:string, order_code?:string, doc_no?:string, message:string, delivery_fullname?:string, delivery_city?:string, items_count?:int}
     */
    public function runSingle(string $rawCode): array
    {
        // Normalizacja: strip gwiazdki, uppercase, trim
        $code = strtoupper(trim(trim($rawCode), '*'));
        $code = strtoupper(trim($code));

        if ($code === '' || strlen($code) > 32) {
            return ['status' => 'error', 'message' => 'Nieprawidłowy kod zamówienia'];
        }
        if (!preg_match('/^[0-9A-Z]+$/', $code)) {
            return ['status' => 'error', 'message' => 'Nieprawidłowy format kodu: ' . $code];
        }

        ($this->log)("SINGLE: start order={$code}");

        // 1) Szukaj dokumentu w Subiekcie (bez limitu dat)
        $docs = $this->sub->fetchDocByOrderCode($code);
        if (!$docs) {
            return ['status' => 'error', 'message' => "Nie znaleziono dokumentu w Subiekcie dla kodu: {$code}"];
        }

        // Wybierz dokument z największym dok_Id (najnowszy)
        $d = null;
        foreach ($docs as $row) {
            $oc = $this->extractOrderCode((string)($row['dok_Podtytul'] ?? ''));
            if ($oc !== $code) continue;
            if ($d === null || (int)$row['dok_Id'] > (int)$d['dok_Id']) {
                $row['order_code'] = $code;
                $d = $row;
            }
        }

        if ($d === null) {
            return ['status' => 'error', 'message' => "Dokument znaleziony, ale kod nie pasuje wzorcowi: {$code}"];
        }

        $docId  = (int)$d['dok_Id'];
        $docNo  = (string)($d['dok_NrPelny'] ?? '');
        $notes  = (string)($d['dok_Uwagi'] ?? '');
        $hint   = '*' . $code . '*';

        $rawPrzelew = $d['dok_KwPrzelew'] ?? null;
        $rawWaluta  = trim((string)($d['dok_Waluta'] ?? ''));

        // 2) Pobierz payload (EU lub BL)
        $euMap = [];
        $blMap = [];
        if (preg_match('/^[BE]\d+$/', $code)) {
            $blMap = $this->bl->fetchByOrderCodes([$code]);
        } elseif (ctype_digit($code)) {
            $euMap = $this->eu->fetchByMainIds([(int)$code]);
        }

        $payload = null;
        if (isset($euMap[$code])) $payload = $euMap[$code];
        if (isset($blMap[$code])) $payload = $blMap[$code];
        $h = ($payload && isset($payload['header']) && is_array($payload['header'])) ? $payload['header'] : [];

        // COD
        $isCod       = (stripos((string)($h['delivery_method'] ?? ''), 'obranie') !== false);
        $codAmount   = ($isCod && $rawPrzelew !== null && (float)$rawPrzelew > 0) ? round((float)$rawPrzelew, 2) : null;
        $codCurrency = ($codAmount !== null && $rawWaluta !== '') ? strtoupper($rawWaluta) : null;

        $nr    = (string)($h['nr_nadania'] ?? '');
        $cc    = $h['courier_code'] ?? null;
        $cin   = $h['courier_inner_number'] ?? null;
        $pid   = $h['bl_package_id'] ?? null;
        $ap    = (string)($h['allegro_parcel_id'] ?? '');
        $ppId  = $h['pickup_point_id'] ?? null;
        $ppName = $h['pickup_point_name'] ?? null;
        $ppAddr = $h['pickup_point_address'] ?? null;

        try {
            $this->mysql->beginTransaction();

            $meta = $this->repo->getMeta($code);

            if ($meta !== null) {
                // Zamówienie już istnieje — uzupełnij puste pola
                $this->repo->fillSubiektDocIfEmpty($code, $docId, $docNo, $hint, $notes);
                $this->repo->fillTrackingIfEmpty($code, $nr, $cc, $cin, $pid);
                $this->repo->fillAllegroParcelIfEmpty($code, $ap);
                $this->repo->fillPickupPointIfEmpty($code, $ppId, $ppName, $ppAddr);
                $this->repo->fillCodIfEmpty($code, $codAmount, $codCurrency);
                $this->mysql->commit();
                ($this->log)("SINGLE: updated (existing) order={$code}");
                return [
                    'status'            => 'updated',
                    'order_code'        => $code,
                    'doc_no'            => $docNo,
                    'delivery_fullname' => (string)($meta['delivery_fullname'] ?? ''),
                    'delivery_city'     => (string)($meta['delivery_city'] ?? ''),
                    'message'           => "Zamówienie już istnieje, uzupełniono puste pola: {$code}",
                ];
            }

            // Nowe zamówienie — wymaga pełnych danych
            if ($payload === null || empty($payload['header'])) {
                $this->mysql->rollBack();
                ($this->log)("SINGLE: INCOMPLETE missing payload order={$code}");
                return ['status' => 'incomplete', 'order_code' => $code, 'message' => "Brak danych źródłowych (EU/BL) dla: {$code}"];
            }

            $subItemsMap = $this->sub->fetchItemsForDocs([$docId]);
            $subItems    = $subItemsMap[$docId] ?? [];
            if (!$subItems) {
                $this->mysql->rollBack();
                ($this->log)("SINGLE: INCOMPLETE missing items order={$code}");
                return ['status' => 'incomplete', 'order_code' => $code, 'message' => "Brak pozycji w Subiekcie dla: {$code}"];
            }

            $this->repo->insertNewOrFillIfExists([
                'order_code'           => $code,
                'source'               => (string)$payload['source'],
                'eu_main_id'           => $payload['eu_main_id'],
                'bl_order_id'          => $payload['bl_order_id'],
                'shop_order_id'        => (string)$payload['shop_order_id'],
                'delivery_method'      => (string)($h['delivery_method'] ?? ''),
                'courier_priority'     => 100,
                'user_login'           => (string)($h['user_login'] ?? ''),
                'delivery_fullname'    => (string)($h['delivery_fullname'] ?? ''),
                'delivery_address'     => (string)($h['delivery_address'] ?? ''),
                'delivery_city'        => (string)($h['delivery_city'] ?? ''),
                'delivery_postcode'    => (string)($h['delivery_postcode'] ?? ''),
                'phone'                => (string)($h['phone'] ?? ''),
                'email'                => (string)($h['email'] ?? ''),
                'payment_done'         => $h['payment_done'] ?? null,
                'payment_method'       => (string)($h['payment_method'] ?? ''),
                'delivery_price'       => $h['delivery_price'] ?? null,
                'want_invoice'         => (int)($h['want_invoice'] ?? 0),
                'invoice_company'      => (string)($h['invoice_company'] ?? ''),
                'invoice_fullname'     => (string)($h['invoice_fullname'] ?? ''),
                'invoice_address'      => (string)($h['invoice_address'] ?? ''),
                'invoice_city'         => (string)($h['invoice_city'] ?? ''),
                'invoice_postcode'     => (string)($h['invoice_postcode'] ?? ''),
                'invoice_nip'          => (string)($h['invoice_nip'] ?? ''),
                'subiekt_hint'         => $hint,
                'subiekt_doc_id'       => $docId,
                'subiekt_doc_no'       => $docNo,
                'notes'                => $notes,
                'nr_nadania'           => $nr,
                'courier_code'         => $cc,
                'courier_inner_number' => $cin,
                'bl_package_id'        => $pid,
                'allegro_parcel_id'    => $ap,
                'pickup_point_id'      => $ppId,
                'pickup_point_name'    => $ppName,
                'pickup_point_address' => $ppAddr,
                'cod_amount'           => $codAmount,
                'cod_currency'         => $codCurrency,
            ]);

            $subImgMap = [];
            if ($this->legacyImg !== null) {
                try {
                    $subImgMap = $this->legacyImg->buildImageMapForSubiektRows($subItems);
                } catch (\Throwable $e) {
                    ($this->log)("SINGLE: legacy image map error order={$code}: " . $e->getMessage());
                }
            }

            $this->repo->syncSubiektItems($code, $subItems, $subImgMap);
            $this->mysql->commit();

            ($this->log)("SINGLE: saved new order={$code} doc={$docNo} items=" . count($subItems));
            return [
                'status'            => 'saved',
                'order_code'        => $code,
                'doc_no'            => $docNo,
                'delivery_fullname' => (string)($h['delivery_fullname'] ?? ''),
                'delivery_city'     => (string)($h['delivery_city'] ?? ''),
                'items_count'       => count($subItems),
                'message'           => "Zamówienie zaimportowane: {$code}",
            ];

        } catch (\Throwable $e) {
            if ($this->mysql->inTransaction()) $this->mysql->rollBack();
            ($this->log)("SINGLE: ERROR order={$code}: " . $e->getMessage());
            return ['status' => 'error', 'order_code' => $code, 'message' => 'Błąd: ' . $e->getMessage()];
        }
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

    private function acquireLock(string $name, int $timeoutSec): bool
    {
        $st = $this->mysql->prepare("SELECT GET_LOCK(:n, :t) AS l");
        $st->execute([':n'=>$name, ':t'=>$timeoutSec]);
        $v = $st->fetchColumn();
        return (string)$v === '1';
    }

    private function releaseLock(string $name): void
    {
        $st = $this->mysql->prepare("SELECT RELEASE_LOCK(:n)");
        $st->execute([':n'=>$name]);
    }
}