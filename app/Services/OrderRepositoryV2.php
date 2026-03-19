<?php
declare(strict_types=1);

final class OrderRepositoryV2
{
    /** @var \PDO */
    private $mysql;

    public function __construct(\PDO $mysql)
    {
        $this->mysql = $mysql;
    }

    /**
     * @return array<string,bool>
     */
    public function getAllOrderCodesSet(): array
    {
        $out = [];
        $st = $this->mysql->query("SELECT order_code FROM pak_orders");
        if (!$st) return $out;

        while (($v = $st->fetchColumn()) !== false) {
            $c = strtoupper(trim((string)$v));
            if ($c !== '') $out[$c] = true;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getMeta(string $orderCode): ?array
    {
        $st = $this->mysql->prepare("
            SELECT order_code, status,
                   subiekt_doc_id, subiekt_doc_no, subiekt_hint, notes,
                   nr_nadania, courier_code, courier_inner_number, bl_package_id,
                   allegro_parcel_id,
                   pickup_point_id, pickup_point_name, pickup_point_address,
                   cod_amount, cod_currency
            FROM pak_orders
            WHERE order_code = :c
            LIMIT 1
        ");
        $st->execute([':c' => $orderCode]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? $r : null;
    }

    public function fillSubiektDocIfEmpty(string $orderCode, int $docId, string $docNo, string $hint, string $notes): void
    {
        $sql = "
            UPDATE pak_orders
            SET
              subiekt_doc_id = IF(subiekt_doc_id IS NULL OR subiekt_doc_id=0, ?, subiekt_doc_id),
              subiekt_doc_no = IF(subiekt_doc_no IS NULL OR subiekt_doc_no='', ?, subiekt_doc_no),
              subiekt_hint   = IF(subiekt_hint IS NULL OR subiekt_hint='', ?, subiekt_hint),
              notes          = IF(notes IS NULL OR notes='', ?, notes),
              updated_at     = NOW()
            WHERE order_code = ?
        ";
        $st = $this->mysql->prepare($sql);
        $st->execute([$docId, $docNo, $hint, $notes, $orderCode]);
    }

    public function fillTrackingIfEmpty(string $orderCode, string $nr, ?string $courierCode, ?string $inner, $pkgId): void
    {
        $nr = trim($nr);
        $courierCode = ($courierCode !== null && trim($courierCode) !== '') ? trim($courierCode) : null;
        $inner = ($inner !== null && trim($inner) !== '') ? trim($inner) : null;
        $pkgId = ($pkgId !== null && (int)$pkgId > 0) ? (int)$pkgId : null;

        if ($nr === '' && $courierCode === null && $inner === null && $pkgId === null) return;

        $sql = "
            UPDATE pak_orders
            SET
              nr_nadania = IF((nr_nadania IS NULL OR nr_nadania='') AND ? <> '', ?, nr_nadania),
              courier_code = IF((courier_code IS NULL OR courier_code='') AND ? IS NOT NULL, ?, courier_code),
              courier_inner_number = IF((courier_inner_number IS NULL OR courier_inner_number='') AND ? IS NOT NULL, ?, courier_inner_number),
              bl_package_id = IF((bl_package_id IS NULL OR bl_package_id=0) AND ? IS NOT NULL, ?, bl_package_id),
              updated_at = NOW()
            WHERE order_code = ?
        ";
        $st = $this->mysql->prepare($sql);
        $st->execute([
            $nr, $nr,
            $courierCode, $courierCode,
            $inner, $inner,
            $pkgId, $pkgId,
            $orderCode
        ]);
    }

    public function fillAllegroParcelIfEmpty(string $orderCode, string $parcelId): void
    {
        $parcelId = trim($parcelId);
        if ($parcelId === '') return;

        $sql = "
            UPDATE pak_orders
            SET
              allegro_parcel_id = IF(allegro_parcel_id IS NULL OR allegro_parcel_id='', ?, allegro_parcel_id),
              updated_at = NOW()
            WHERE order_code = ?
        ";
        $st = $this->mysql->prepare($sql);
        $st->execute([$parcelId, $orderCode]);
    }

    // ZMIANA: nowa metoda do uzupełniania pickup_point jeśli puste
    public function fillPickupPointIfEmpty(string $orderCode, ?string $pointId, ?string $pointName, ?string $pointAddress): void
    {
        if ($pointId === null && $pointName === null && $pointAddress === null) return;

        $sql = "
            UPDATE pak_orders
            SET
              pickup_point_id      = IF((pickup_point_id IS NULL OR pickup_point_id='') AND ? IS NOT NULL, ?, pickup_point_id),
              pickup_point_name    = IF((pickup_point_name IS NULL OR pickup_point_name='') AND ? IS NOT NULL, ?, pickup_point_name),
              pickup_point_address = IF((pickup_point_address IS NULL OR pickup_point_address='') AND ? IS NOT NULL, ?, pickup_point_address),
              updated_at = NOW()
            WHERE order_code = ?
        ";
        $st = $this->mysql->prepare($sql);
        $st->execute([
            $pointId,      $pointId,
            $pointName,    $pointName,
            $pointAddress, $pointAddress,
            $orderCode
        ]);
    }

    // ZMIANA: nowa metoda do uzupełniania cod jeśli puste
    public function fillCodIfEmpty(string $orderCode, ?float $amount, ?string $currency): void
    {
        if ($amount === null && $currency === null) return;

        $sql = "
            UPDATE pak_orders
            SET
              cod_amount   = IF((cod_amount IS NULL OR cod_amount=0) AND ? IS NOT NULL, ?, cod_amount),
              cod_currency = IF((cod_currency IS NULL OR cod_currency='') AND ? IS NOT NULL, ?, cod_currency),
              updated_at   = NOW()
            WHERE order_code = ?
        ";
        $st = $this->mysql->prepare($sql);
        $st->execute([
            $amount,   $amount,
            $currency, $currency,
            $orderCode
        ]);
    }

    public function insertNewOrFillIfExists(array $data): void
    {
        // ZMIANA: dodano pickup_point_id, pickup_point_name, pickup_point_address
        $st = $this->mysql->prepare("
            INSERT INTO pak_orders (
                order_code, source,
                eu_main_id, bl_order_id, shop_order_id,
                delivery_method, courier_priority, status,
                user_login, delivery_fullname, delivery_address, delivery_city, delivery_postcode,
                phone, email,
                payment_done, payment_method, delivery_price,
                want_invoice, invoice_company, invoice_fullname, invoice_address, invoice_city, invoice_postcode, invoice_nip,
                subiekt_hint, subiekt_doc_id, subiekt_doc_no, notes,
                nr_nadania, courier_code, courier_inner_number, bl_package_id,
                allegro_parcel_id,
                pickup_point_id, pickup_point_name, pickup_point_address,
                cod_amount, cod_currency
            ) VALUES (
                :order_code, :source,
                :eu_main_id, :bl_order_id, :shop_order_id,
                :delivery_method, :courier_priority, 10,
                :user_login, :delivery_fullname, :delivery_address, :delivery_city, :delivery_postcode,
                :phone, :email,
                :payment_done, :payment_method, :delivery_price,
                :want_invoice, :invoice_company, :invoice_fullname, :invoice_address, :invoice_city, :invoice_postcode, :invoice_nip,
                :subiekt_hint, :subiekt_doc_id, :subiekt_doc_no, :notes,
                :nr_nadania, :courier_code, :courier_inner_number, :bl_package_id,
                :allegro_parcel_id,
                :pickup_point_id, :pickup_point_name, :pickup_point_address,
                :cod_amount, :cod_currency
            )
            ON DUPLICATE KEY UPDATE
                subiekt_doc_id = IF(subiekt_doc_id IS NULL OR subiekt_doc_id=0, VALUES(subiekt_doc_id), subiekt_doc_id),
                subiekt_doc_no = IF(subiekt_doc_no IS NULL OR subiekt_doc_no='', VALUES(subiekt_doc_no), subiekt_doc_no),
                subiekt_hint   = IF(subiekt_hint IS NULL OR subiekt_hint='', VALUES(subiekt_hint), subiekt_hint),
                notes          = IF(notes IS NULL OR notes='', VALUES(notes), notes),

                nr_nadania = IF((nr_nadania IS NULL OR nr_nadania='') AND VALUES(nr_nadania) <> '', VALUES(nr_nadania), nr_nadania),
                courier_code = IF((courier_code IS NULL OR courier_code='') AND VALUES(courier_code) IS NOT NULL AND VALUES(courier_code) <> '', VALUES(courier_code), courier_code),
                courier_inner_number = IF((courier_inner_number IS NULL OR courier_inner_number='') AND VALUES(courier_inner_number) IS NOT NULL AND VALUES(courier_inner_number) <> '', VALUES(courier_inner_number), courier_inner_number),
                bl_package_id = IF((bl_package_id IS NULL OR bl_package_id=0) AND VALUES(bl_package_id) IS NOT NULL AND VALUES(bl_package_id) > 0, VALUES(bl_package_id), bl_package_id),

                allegro_parcel_id = IF((allegro_parcel_id IS NULL OR allegro_parcel_id='') AND VALUES(allegro_parcel_id) <> '', VALUES(allegro_parcel_id), allegro_parcel_id),

                pickup_point_id      = IF((pickup_point_id IS NULL OR pickup_point_id='') AND VALUES(pickup_point_id) IS NOT NULL AND VALUES(pickup_point_id) <> '', VALUES(pickup_point_id), pickup_point_id),
                pickup_point_name    = IF((pickup_point_name IS NULL OR pickup_point_name='') AND VALUES(pickup_point_name) IS NOT NULL AND VALUES(pickup_point_name) <> '', VALUES(pickup_point_name), pickup_point_name),
                pickup_point_address = IF((pickup_point_address IS NULL OR pickup_point_address='') AND VALUES(pickup_point_address) IS NOT NULL AND VALUES(pickup_point_address) <> '', VALUES(pickup_point_address), pickup_point_address),

                cod_amount   = IF((cod_amount IS NULL OR cod_amount=0) AND VALUES(cod_amount) IS NOT NULL AND VALUES(cod_amount) > 0, VALUES(cod_amount), cod_amount),
                cod_currency = IF((cod_currency IS NULL OR cod_currency='') AND VALUES(cod_currency) IS NOT NULL AND VALUES(cod_currency) <> '', VALUES(cod_currency), cod_currency),

                updated_at = NOW()
        ");

        $st->execute([
            ':order_code' => (string)$data['order_code'],
            ':source' => (string)$data['source'],
            ':eu_main_id' => $data['eu_main_id'],
            ':bl_order_id' => $data['bl_order_id'],
            ':shop_order_id' => (string)$data['shop_order_id'],

            ':delivery_method' => (string)$data['delivery_method'],
            ':courier_priority' => (int)$data['courier_priority'],
            ':user_login' => (string)$data['user_login'],
            ':delivery_fullname' => (string)$data['delivery_fullname'],
            ':delivery_address' => (string)$data['delivery_address'],
            ':delivery_city' => (string)$data['delivery_city'],
            ':delivery_postcode' => (string)$data['delivery_postcode'],
            ':phone' => (string)$data['phone'],
            ':email' => (string)$data['email'],

            ':payment_done' => $data['payment_done'],
            ':payment_method' => (string)$data['payment_method'],
            ':delivery_price' => $data['delivery_price'],

            ':want_invoice' => (int)$data['want_invoice'],
            ':invoice_company' => (string)$data['invoice_company'],
            ':invoice_fullname' => (string)$data['invoice_fullname'],
            ':invoice_address' => (string)$data['invoice_address'],
            ':invoice_city' => (string)$data['invoice_city'],
            ':invoice_postcode' => (string)$data['invoice_postcode'],
            ':invoice_nip' => (string)$data['invoice_nip'],

            ':subiekt_hint' => (string)$data['subiekt_hint'],
            ':subiekt_doc_id' => (int)$data['subiekt_doc_id'],
            ':subiekt_doc_no' => (string)$data['subiekt_doc_no'],
            ':notes' => (string)$data['notes'],

            ':nr_nadania' => (string)($data['nr_nadania'] ?? ''),
            ':courier_code' => $data['courier_code'] ?? null,
            ':courier_inner_number' => $data['courier_inner_number'] ?? null,
            ':bl_package_id' => $data['bl_package_id'] ?? null,

            ':allegro_parcel_id' => (string)($data['allegro_parcel_id'] ?? ''),

            // NOWE
            ':pickup_point_id'      => $data['pickup_point_id'] ?? null,
            ':pickup_point_name'    => $data['pickup_point_name'] ?? null,
            ':pickup_point_address' => $data['pickup_point_address'] ?? null,

            // NOWE: pobranie
            ':cod_amount'   => $data['cod_amount'] ?? null,
            ':cod_currency' => $data['cod_currency'] ?? null,
        ]);
    }


    public function syncSubiektItems(string $orderCode, array $items, array $imageMap = []): int
    {
        $del = $this->mysql->prepare("DELETE FROM pak_order_items WHERE order_code=:c AND line_key LIKE 'SUB-%'");
        $del->execute([':c'=>$orderCode]);
        if (!$items) return 0;

        $ins = $this->mysql->prepare("
            INSERT INTO pak_order_items (
                order_code, line_key, offer_id,
                subiekt_tow_id, subiekt_symbol,
                name, subiekt_desc,
                quantity, unit_price_brutto,
                image_url
            ) VALUES (
                :order_code, :line_key, :offer_id,
                :subiekt_tow_id, :subiekt_symbol,
                :name, :subiekt_desc,
                :quantity, :unit_price_brutto,
                :image_url
            )
            ON DUPLICATE KEY UPDATE
                offer_id = VALUES(offer_id),
                subiekt_tow_id = VALUES(subiekt_tow_id),
                subiekt_symbol = VALUES(subiekt_symbol),
                name = VALUES(name),
                subiekt_desc = VALUES(subiekt_desc),
                quantity = VALUES(quantity),
                unit_price_brutto = VALUES(unit_price_brutto),
                image_url = IF(
                    (image_url IS NULL OR image_url='') AND VALUES(image_url) IS NOT NULL AND VALUES(image_url) <> '',
                    VALUES(image_url),
                    image_url
                ),
                updated_at = NOW()
        ");

        $n = 0;
        foreach ($items as $r) {
            $obId  = (int)$r['ob_Id'];
            $towId = (int)$r['ob_TowId'];
            $qtyF  = (float)$r['ob_Ilosc'];
            $qty   = (int)round($qtyF);
            if ($qty < 1 && $qtyF > 0) $qty = 1;

            $sym   = (string)($r['tw_Symbol'] ?? '');
            $name  = (string)($r['tw_Nazwa'] ?? '');
            $desc  = (string)($r['tw_Opis'] ?? '');
            $desc  = trim((string)preg_replace('/\s+/u', ' ', $desc));

            $price = ($r['ob_CenaBrutto'] !== null) ? (float)$r['ob_CenaBrutto'] : null;

            $syncIdRaw = trim((string)($r['ob_SyncId'] ?? ''));
            $offerId = ($syncIdRaw !== '') ? preg_replace('/\D+/', '', $syncIdRaw) : null;
            if ($offerId === '') $offerId = null;

            $imgKey = null;
            $symNorm = strtoupper(trim($sym));
            if ($towId > 0 && $symNorm !== '') {
                $imgKey = (string)$towId . '|' . $symNorm;
            }

            $imageUrl = null;
            if ($imgKey !== null && isset($imageMap[$imgKey])) {
                $u = trim((string)$imageMap[$imgKey]);
                if ($u !== '') {
                    if (strlen($u) > 500) $u = substr($u, 0, 500);
                    $imageUrl = $u;
                }
            }

            $ins->execute([
                ':order_code'=>$orderCode,
                ':line_key'=>'SUB-' . $obId,
                ':offer_id'=>$offerId,
                ':subiekt_tow_id'=>$towId,
                ':subiekt_symbol'=>$sym,
                ':name'=>($name !== '' ? $name : $sym),
                ':subiekt_desc'=>$desc,
                ':quantity'=>$qty,
                ':unit_price_brutto'=>$price,
                ':image_url'=>$imageUrl,
            ]);
            $n++;
        }
        return $n;
    }
}