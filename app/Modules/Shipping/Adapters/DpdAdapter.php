<?php
declare(strict_types=1);

final class DpdAdapter implements ShippingAdapterInterface
{
    const WSDL = 'https://dpdservices.dpd.com.pl/DPDPackageObjServicesService/DPDPackageObjServices?wsdl';

    public function generateLabel(
        array $order,
        array $package,
        array $resolved,
        array $providerCfg
    ): array {
        $login     = (string)($providerCfg['login']     ?? '');
        $masterFid = (int)  ($providerCfg['master_fid'] ?? 0);
        $password  = (string)($providerCfg['password']  ?? '');

        if (!$login || !$masterFid || !$password) {
            throw new \RuntimeException('DPD: brak credentials w config_json');
        }

        $client = new \SoapClient(self::WSDL, [
            'exceptions' => true,
            'encoding'   => 'UTF-8',
            'trace'      => false,
        ]);

        $postcode     = str_replace('-', '', (string)($order['delivery_postcode'] ?? ''));
        $phone        = (int)preg_replace('/\D/', '', (string)($order['phone'] ?? '48000000000'));
        $codAmount    = (float)($order['cod_amount'] ?? 0);
        $shipmentType = $resolved['shipment_type'] ?? '';

        // Usługi
        $services = new \stdClass();
        if ($codAmount > 0) {
            $cod           = new \stdClass();
            $cod->amount   = number_format($codAmount, 2, '.', '');
            $cod->currency = (string)($order['cod_currency'] ?? 'PLN');
            $services->COD = $cod;
        }
        if (in_array($shipmentType, ['dpd_pickup', 'dpd_pudo', 'dpd_automat'], true)) {
            $pudoId = (string)($order['nr_nadania'] ?? '');
            if ($pudoId) {
                $pickup       = new \stdClass();
                $pickup->pudo = $pudoId;
                $services->DpdPickup = $pickup;
            }
        }

        // Paczka
        $parcel           = new \stdClass();
        $parcel->weight   = 1;
        $parcel->sizeX    = 20;
        $parcel->sizeY    = 20;
        $parcel->sizeZ    = 20;
        $parcel->content  = 'Towar';
        $parcel->reference = 'LED-ONE ' . $order['order_code'];

        // Odbiorca
        $receiver              = new \stdClass();
        $receiver->name        = (string)($order['delivery_fullname'] ?? '');
        $receiver->address     = (string)($order['delivery_address']  ?? '');
        $receiver->city        = (string)($order['delivery_city']     ?? '');
        $receiver->postalCode  = $postcode;
        $receiver->countryCode = 'PL';
        $receiver->phone       = $phone;
        $receiver->email       = (string)($order['email'] ?? '');

        // Nadawca
        $sender              = new \stdClass();
        $sender->fid         = $masterFid;
        $sender->name        = 'Led-One';
        $sender->address     = 'Jasnogórska 183';
        $sender->city        = 'Biala';
        $sender->postalCode  = '42125';
        $sender->countryCode = 'PL';
        $sender->phone       = 534123383;
        $sender->email       = 'bok@led-one.com.pl';

        // OpenUML
        $packages           = new \stdClass();
        $packages->parcels  = $parcel;
        $packages->payerType = 'SENDER';
        $packages->receiver = $receiver;
        $packages->sender   = $sender;
        $packages->services = $services;

        $openUML           = new \stdClass();
        $openUML->packages = $packages;

        // Auth
        $auth            = new \stdClass();
        $auth->login     = $login;
        $auth->masterFid = $masterFid;
        $auth->password  = $password;

        $existingWaybill = trim((string)($order['tracking_number'] ?? $order['nr_nadania'] ?? ''));
        if ($existingWaybill !== '') {
            return $this->fetchExistingLabelByWaybill($client, $auth, $order, $existingWaybill);
        }

        // Generuj numer
        $result = $client->generatePackagesNumbersV4([
            'openUMLFeV3'               => $openUML,
            'pkgNumsGenerationPolicyV1' => 'STOP_ON_FIRST_ERROR',
            'langCode'                  => 'PL',
            'authDataV1'                => $auth,
        ]);

        $return = $result->return ?? null;
        if (!$return) {
            throw new \RuntimeException('DPD: brak return w response');
        }
        if ((string)($return->Status ?? '') !== 'OK') {
            throw new \RuntimeException('DPD error: ' . ($return->Status ?? 'unknown'));
        }

        $waybill = (string)($return->Packages->Package->Parcels->Parcel->Waybill ?? '');
        if (!$waybill) {
            throw new \RuntimeException('DPD: brak waybill w response');
        }

        return $this->fetchExistingLabelByWaybill($client, $auth, $order, $waybill);
    }

    private function fetchExistingLabelByWaybill(\SoapClient $client, \stdClass $auth, array $order, string $waybill): array
    {
        $parcelDSP          = new \stdClass();
        $parcelDSP->waybill = $waybill;

        $packageDSP          = new \stdClass();
        $packageDSP->parcels = $parcelDSP;

        $session              = new \stdClass();
        $session->packages    = $packageDSP;
        $session->sessionType = 'DOMESTIC';

        $dpdParams          = new \stdClass();
        $dpdParams->policy  = 'IGNORE_ERRORS';
        $dpdParams->session = $session;

        $labelResult = $client->generateSpedLabelsV1([
            'dpdServicesParamsV1'   => $dpdParams,
            'outputDocFormatV1'     => 'PDF',
            'outputDocPageFormatV1' => 'LBL_PRINTER',
            'authDataV1'            => $auth,
        ]);

        $fileName = null;
        $docData  = $labelResult->return->documentData ?? null;

        if ($docData) {
            $pdfBytes = base64_decode((string)$docData);
            if ($pdfBytes && strlen($pdfBytes) > 100) {
                $dir = BASE_PATH . '/storage/labels/dpd';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $fileName = 'dpd_' . $order['order_code'] . '_' . $waybill . '.pdf';
                file_put_contents($dir . '/' . $fileName, $pdfBytes);
            }
        }

        return [
            'tracking_number'      => $waybill,
            'external_shipment_id' => null,
            'label_format'         => 'pdf',
            'label_status'         => $fileName ? 'ok' : 'pending',
            'file_path'            => $fileName,
            'file_token'           => $fileName,
            'raw_response'         => ['waybill' => $waybill, 'status' => 'OK'],
        ];
    }
}
