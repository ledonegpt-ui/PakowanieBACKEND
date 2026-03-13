<?php
declare(strict_types=1);

final class FirebirdEUReader
{
    /** @var \PDO */
    private $fb;

    public function __construct(\PDO $fb)
    {
        $this->fb = $fb;
    }

    /**
     * @param array<int,int> $mainIds (ID headerów z podtytułu Subiekta: *1234*)
     * @return array<string,array<string,mixed>> map: order_code => payload
     */
    public function fetchByMainIds(array $mainIds): array
    {
        $mainIds = array_values(array_unique(array_filter($mainIds, function($v){
            return is_int($v) && $v > 0;
        })));
        if (!$mainIds) return [];

        $out = [];

        foreach (array_chunk($mainIds, 150) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));

            // 1) HEADERY po t.ID (główna transakcja)
            // ZMIANA: dodano PKT_ODB_ID, PKT_ODB_NAZWA, PKT_ODB_ULICA, PKT_ODB_KOD_POCZ, PKT_ODB_MIASTO
            $sqlH = "
                SELECT
                    t.ID, t.ALL_FOD_ID,
                    k.KL_LOGIN, k.KL_DK_EMAIL,
                    w.KL_DDW_IMIENAZW, w.KL_DDW_ULICA, w.KL_DDW_MIASTO, w.KL_DDW_KOD_POCZ, w.KL_DDW_TELEFON,
                    w.KOSZT_WYSYLKI, w.FORMA_WYSYLKI,
                    w.NR_NADANIA,
                    w.PKT_ODB_ID,
                    w.PKT_ODB_NAZWA,
                    w.PKT_ODB_ULICA,
                    w.PKT_ODB_KOD_POCZ,
                    w.PKT_ODB_MIASTO,
                    p.FORMA_WPLATY, p.KWOTA_WPLATY,
                    p.KL_DDF_FIRMA, p.KL_DDF_IMIENAZW, p.KL_DDF_ULICA, p.KL_DDF_MIASTO, p.KL_DDF_KOD_POCZ, p.KL_DDF_NIP
                FROM TRANSAKCJE t
                LEFT JOIN TRANS_KLIENCI k ON t.ID_KLIENT = k.ID_KLIENT
                LEFT JOIN TRANS_WYSYLKA w ON t.ID = w.ID_TRANS
                LEFT JOIN TRANS_WPLATA  p ON t.ID = p.ID_TRANS
                WHERE t.ID IN ($ph)
            ";
            $stH = $this->fb->prepare($sqlH);
            $stH->execute($chunk);
            $hdrRows = $stH->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $headers = []; // id => row
            $gids = [];
            $nrList = [];
            foreach ($hdrRows as $r) {
                $id = (int)$r['ID'];
                $headers[$id] = $r;

                $gid = (string)($r['ALL_FOD_ID'] ?? '');
                if ($gid === '') $gid = (string)$id;
                $gids[$gid] = true;

                $nr = $this->cleanTracking((string)($r['NR_NADANIA'] ?? ''));
                if ($nr !== '') $nrList[$nr] = true;
            }
            if (!$headers) continue;

            // 1b) Mapowanie NR_NAD -> PARCEL_ID (Firebird ALLEGRO_ONLINE)
            $allegroMap = $this->fetchAllegroParcelMap(array_keys($nrList));

            // 2) Pozycje po ALL_FOD_ID (partiami)
            $gidList = array_keys($gids);
            $ph2 = implode(',', array_fill(0, count($gidList), '?'));

            $sqlI = "
                SELECT
                    t.ID, t.ALL_FOD_ID,
                    t.NR_AUKCJI, t.TYTUL_AUKCJI, t.KOD,
                    t.ILOSC, t.KWOTA
                FROM TRANSAKCJE t
                WHERE t.ALL_FOD_ID IN ($ph2)
                ORDER BY t.ID ASC
            ";
            $stI = $this->fb->prepare($sqlI);
            $stI->execute($gidList);
            $itemRows = $stI->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $itemsByGid = [];
            foreach ($itemRows as $r) {
                $gid = (string)($r['ALL_FOD_ID'] ?? '');
                if ($gid === '') $gid = (string)$r['ID'];
                if (!isset($itemsByGid[$gid])) $itemsByGid[$gid] = [];
                $itemsByGid[$gid][] = $r;
            }

            foreach ($chunk as $mid) {
                if (!isset($headers[$mid])) continue;
                $h = $headers[$mid];

                $gid = (string)($h['ALL_FOD_ID'] ?? '');
                if ($gid === '') $gid = (string)$mid;

                // tracking TYLKO z headera
                $nrNad = $this->cleanTracking((string)($h['NR_NADANIA'] ?? ''));
                $parcelId = ($nrNad !== '') ? (string)($allegroMap[$nrNad] ?? '') : '';

                // ZMIANA: pickup point z kolumn PKT_ODB_*
                $pickupPointId   = $this->clean((string)($h['PKT_ODB_ID'] ?? ''));
                $pickupPointName = $this->clean($this->fb_to_utf((string)($h['PKT_ODB_NAZWA'] ?? '')));
                // Składamy adres z 3 pól: ulica, kod, miasto
                $pickupPointAddress = $this->buildPointAddress(
                    $this->clean($this->fb_to_utf((string)($h['PKT_ODB_ULICA']   ?? ''))),
                    $this->clean((string)($h['PKT_ODB_KOD_POCZ'] ?? '')),
                    $this->clean($this->fb_to_utf((string)($h['PKT_ODB_MIASTO']  ?? '')))
                );

                // items: bierz wiersze bez spacji w NR_AUKCJI
                $items = [];
                $rows = $itemsByGid[$gid] ?? [];
                foreach ($rows as $r) {
                    $nr = (string)($r['NR_AUKCJI'] ?? '');
                    if ($nr === '' || strpos($nr, ' ') !== false) continue;

                    $qty = (int)$r['ILOSC'];
                    if ($qty < 1) $qty = 1;

                    $kwota = (float)$r['KWOTA'];
                    $unit = round($kwota / $qty, 2);

                    $items[] = [
                        'line_key' => 'EU-' . (int)$r['ID'],
                        'offer_id' => $nr,
                        'sku'      => $this->clean((string)$r['KOD']),
                        'name'     => $this->clean($this->fb_to_utf((string)$r['TYTUL_AUKCJI'])),
                        'quantity' => $qty,
                        'unit_price_brutto' => $unit,
                    ];
                }

                $invNip = $this->clean($this->fb_to_utf((string)$h['KL_DDF_NIP']));

                $orderCode = (string)$mid;
                $out[$orderCode] = [
                    'source' => 'U',
                    'order_code' => $orderCode,
                    'eu_main_id' => (int)$mid,
                    'bl_order_id' => null,
                    'shop_order_id' => $gid,
                    'header' => [
                        'delivery_method' => $this->clean($this->fb_to_utf((string)$h['FORMA_WYSYLKI'])),
                        'user_login' => $this->clean($this->fb_to_utf((string)$h['KL_LOGIN'])),
                        'delivery_fullname' => $this->clean($this->fb_to_utf((string)$h['KL_DDW_IMIENAZW'])),
                        'delivery_address' => $this->clean($this->fb_to_utf((string)$h['KL_DDW_ULICA'])),
                        'delivery_city' => $this->clean($this->fb_to_utf((string)$h['KL_DDW_MIASTO'])),
                        'delivery_postcode' => $this->clean((string)$h['KL_DDW_KOD_POCZ']),
                        'phone' => $this->cleanPhone((string)$h['KL_DDW_TELEFON']),
                        'email' => $this->clean((string)$h['KL_DK_EMAIL']),
                        'payment_done' => ($h['KWOTA_WPLATY'] !== null) ? (float)$h['KWOTA_WPLATY'] : null,
                        'payment_method' => $this->clean($this->fb_to_utf((string)$h['FORMA_WPLATY'])),
                        'delivery_price' => ($h['KOSZT_WYSYLKI'] !== null) ? (float)$h['KOSZT_WYSYLKI'] : null,
                        'want_invoice' => ($invNip !== '') ? 1 : 0,
                        'invoice_company' => $this->clean($this->fb_to_utf((string)$h['KL_DDF_FIRMA'])),
                        'invoice_fullname' => $this->clean($this->fb_to_utf((string)$h['KL_DDF_IMIENAZW'])),
                        'invoice_address' => $this->clean($this->fb_to_utf((string)$h['KL_DDF_ULICA'])),
                        'invoice_city' => $this->clean($this->fb_to_utf((string)$h['KL_DDF_MIASTO'])),
                        'invoice_postcode' => $this->clean((string)$h['KL_DDF_KOD_POCZ']),
                        'invoice_nip' => $invNip,

                        'nr_nadania' => $nrNad,
                        'courier_code' => null,
                        'courier_inner_number' => null,
                        'bl_package_id' => null,
                        'allegro_parcel_id' => $parcelId,

                        // NOWE: dane punktu odbioru / paczkomatu
                        'pickup_point_id'      => ($pickupPointId !== '' ? $pickupPointId : null),
                        'pickup_point_name'    => ($pickupPointName !== '' ? $pickupPointName : null),
                        'pickup_point_address' => ($pickupPointAddress !== '' ? $pickupPointAddress : null),
                    ],
                    'items' => $items,
                ];
            }
        }

        return $out;
    }

    /**
     * Składa adres punktu odbioru z 3 pól Firebird.
     * Zwraca pusty string jeśli wszystkie pola puste.
     */
    private function buildPointAddress(string $ulica, string $kod, string $miasto): string
    {
        $parts = array_filter([$ulica, trim($kod . ' ' . $miasto)], function(string $v) {
            return $v !== '';
        });
        return implode(', ', $parts);
    }

    /**
     * @param array<int,string> $nrList
     * @return array<string,string> map: NR_NAD -> PARCEL_ID
     */
    private function fetchAllegroParcelMap(array $nrList): array
    {
        $nrList = array_values(array_unique(array_filter($nrList, function($v){
            return is_string($v) && $v !== '';
        })));
        if (!$nrList) return [];

        $out = [];
        foreach (array_chunk($nrList, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT NR_NAD, PARCEL_ID FROM ALLEGRO_ONLINE WHERE NR_NAD IN ($ph)";
            $st = $this->fb->prepare($sql);
            $st->execute($chunk);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $nr = $this->cleanTracking((string)($r['NR_NAD'] ?? ''));
                $pid = trim((string)($r['PARCEL_ID'] ?? ''));
                if ($nr !== '' && $pid !== '') $out[$nr] = $pid;
            }
        }
        return $out;
    }

    private function fb_to_utf(?string $s): string
    {
        if ($s === null) return '';
        $out = @iconv('Windows-1250', 'UTF-8//IGNORE', $s);
        return $out === false ? $s : $out;
    }

    private function clean(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s ? $s : '';
    }

    private function cleanPhone(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/[^\d+]/', '', $s);
        $s = ltrim((string)$s, '+');
        return $s === '' ? '' : ('+' . $s);
    }

    private function cleanTracking(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', '', $s);
        return $s ? $s : '';
    }
}