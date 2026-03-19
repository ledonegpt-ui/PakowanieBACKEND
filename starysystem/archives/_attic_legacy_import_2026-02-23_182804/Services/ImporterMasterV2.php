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

    public function __construct(
        \PDO $mysql,
        ImportState $state,
        OrderRepositoryV2 $repo,
        SubiektReaderV2 $sub,
        FirebirdEUReader $eu,
        BaselinkerBatchReader $bl,
        array $cfg,
        callable $logger
    ) {
        $this->mysql = $mysql;
        $this->state = $state;
        $this->repo  = $repo;
        $this->sub   = $sub;
        $this->eu    = $eu;
        $this->bl    = $bl;
        $this->cfg   = $cfg;
        $this->log   = $logger;
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

            $this->mysql->beginTransaction();
            try {
                foreach ($refDocs as $d) {
                    $orderCode = (string)$d['order_code'];
                    $docId = (int)$d['dok_Id'];
                    $docNo = (string)($d['dok_NrPelny'] ?? '');
                    $notes = (string)($d['dok_Uwagi'] ?? '');
                    $hint  = '*' . $orderCode . '*';

                    $meta = $this->repo->getMeta($orderCode);

                    $payload = null;
                    if (isset($euMap[$orderCode])) $payload = $euMap[$orderCode];
                    if (isset($blMap[$orderCode])) $payload = $blMap[$orderCode];
                    $h = ($payload && isset($payload['header']) && is_array($payload['header'])) ? $payload['header'] : [];

                    $nr  = (string)($h['nr_nadania'] ?? '');
                    $cc  = $h['courier_code'] ?? null;
                    $cin = $h['courier_inner_number'] ?? null;
                    $pid = $h['bl_package_id'] ?? null;
                    $ap  = (string)($h['allegro_parcel_id'] ?? '');

                    if ($meta !== null) {
                        // FROZEN B: nic nie nadpisuj. Tylko dopnij puste.
                        $this->repo->fillSubiektDocIfEmpty($orderCode, $docId, $docNo, $hint, $notes);
                        $this->repo->fillTrackingIfEmpty($orderCode, $nr, $cc, $cin, $pid);
                        $this->repo->fillAllegroParcelIfEmpty($orderCode, $ap);
                        $knownOrderCodes[$orderCode] = true;
                    $saved++;
                        continue;
                    }

                    if ($payload === null || empty($payload['header'])) {
                        ($this->log)("MASTER: INCOMPLETE missing source payload order={$orderCode} doc={$docId}");
                        $incomplete++;
                        continue;
                    }

                    $subItems = $subItemsMap[$docId] ?? [];
                    if (!$subItems) {
                        ($this->log)("MASTER: INCOMPLETE missing subiekt items order={$orderCode} doc={$docId}");
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
                    ]);

                    $this->repo->syncSourceItems($orderCode, (string)$payload['source'], $payload['items'] ?? []);
                    $this->repo->syncSubiektItems($orderCode, $subItems);

                    $saved++;
                }

                $this->mysql->commit();
            } catch (\Throwable $e) {
                if ($this->mysql->inTransaction()) $this->mysql->rollBack();
                throw $e;
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
