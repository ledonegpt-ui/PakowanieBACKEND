<?php
declare(strict_types=1);

class GlsAdapter implements ShippingAdapterInterface
{
    public function generateLabel(
        array $order,
        array $package,
        array $resolved,
        array $providerCfg
    ): array {
        $wsdl     = (string)($providerCfg['wsdl']     ?? '');
        $username = (string)($providerCfg['username'] ?? '');
        $password = (string)($providerCfg['password'] ?? '');
        $orderCode = (string)($order['order_code'] ?? 'unknown');

        try {
            $hClient = new SoapClient($wsdl, [
                'exceptions' => true,
                'trace'      => true,
                'encoding'   => 'UTF-8',
            ]);

            // 1. Login
            $oCredit                = new stdClass();
            $oCredit->user_name     = $username;
            $oCredit->user_password = $password;
            $oLoginResult           = $hClient->adeLogin($oCredit);
            $szSession              = $oLoginResult->return->session;

            $rawResponse    = [];
            $parcelId       = null;
            $trackingNumber = null;

            try {
                if (!empty($package['external_shipment_id'])) {
                    // Przypadek A — przesyłka już istnieje
                    $parcelId       = $package['external_shipment_id'];
                    $trackingNumber = $parcelId;
                } else {
                    // Przypadek B — nowa przesyłka
                    $insertResult   = $this->createParcel($hClient, $szSession, $order);
                    $rawResponse    = (array)$insertResult;
                    // API zwraca cID { int id }
                    $parcelId       = (string)($insertResult->return->id ?? '');
                    $trackingNumber = $parcelId;
                }

                // 2. Pobierz etykietę ZPL
                $oInput          = new stdClass();
                $oInput->session = $szSession;
                $oInput->id      = (int)$parcelId;
                $oInput->mode    = 'roll_160x100_zebra';

                $oLabelResult = $hClient->adePreparingBox_GetConsignLabels($oInput);
                $zplData      = base64_decode($oLabelResult->return->labels);

                // 3. Zapisz na dysk
                $filename = $this->saveLabel($zplData, $orderCode);

            } finally {
                // Logout — zawsze
                $oSess          = new stdClass();
                $oSess->session = $szSession;
                $hClient->adeLogout($oSess);
            }

            return [
                'tracking_number'      => (string)$trackingNumber,
                'external_shipment_id' => (string)$parcelId,
                'label_format'         => 'zpl',
                'label_status'         => 'ok',
                'file_token'           => $filename,
                'file_path'            => $filename,
                'raw_response'         => $rawResponse,
            ];

        } catch (\SoapFault $e) {
            return [
                'tracking_number'      => '',
                'external_shipment_id' => '',
                'label_format'         => 'zpl',
                'label_status'         => 'error',
                'file_token'           => '',
                'file_path'            => '',
                'raw_response'         => [
                    'error'     => $e->getMessage(),
                    'faultcode' => $e->faultcode ?? '',
                ],
            ];
        }
    }

    private function createParcel(SoapClient $hClient, string $session, array $order): object
    {
        $isCod = stripos($order['delivery_method'] ?? '', 'pobranie') !== false;
        $codAmount = 0.0;
        if ($isCod) {
            $codAmount = !empty($order['cod_amount'])
                ? (float)$order['cod_amount']
                : (float)($order['delivery_price'] ?? 0.0);
        }

        // cServicesBool
        $srvBool             = new stdClass();
        $srvBool->cod        = $isCod;
        $srvBool->cod_amount = $isCod ? $codAmount : 0.0;
        $srvBool->exw        = false;
        $srvBool->rod        = false;
        $srvBool->pod        = false;
        $srvBool->exc        = false;
        $srvBool->ident      = false;
        $srvBool->daw        = false;
        $srvBool->ps         = false;
        $srvBool->pr         = false;
        $srvBool->s10        = false;
        $srvBool->s12        = false;
        $srvBool->sat        = false;
        $srvBool->ow         = false;
        $srvBool->srs        = false;
        $srvBool->sds        = false;
        $srvBool->cdx        = false;
        $srvBool->ado        = false;

        // cConsign
        $consign             = new stdClass();
        $consign->rname1     = (string)($order['delivery_fullname'] ?? '');
        $consign->rname2     = '';
        $consign->rname3     = '';
        $consign->rcountry   = 'PL';
        $consign->rzipcode   = (string)($order['delivery_postcode'] ?? '');
        $consign->rcity      = (string)($order['delivery_city']     ?? '');
        $consign->rstreet    = (string)($order['delivery_address']  ?? '');
        $consign->rphone     = (string)($order['phone']             ?? '');
        $consign->rcontact   = (string)($order['delivery_fullname'] ?? '');
        $consign->references = (string)($order['order_code']        ?? '');
        $consign->notes      = '';
        $consign->quantity   = 1;
        $consign->weight     = 1.0;
        $consign->date       = date('Y-m-d');
        $consign->srv_bool   = $srvBool;

        // Wrapper — adePreparingBox_Insert { session, consign_prep_data }
        $params                    = new stdClass();
        $params->session           = $session;
        $params->consign_prep_data = $consign;

        return $hClient->adePreparingBox_Insert($params);
    }

    private function saveLabel(string $zplData, string $orderCode): string
    {
        $dir = defined('BASE_PATH') ? BASE_PATH . '/storage/labels' : sys_get_temp_dir();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'gls_' . $orderCode . '_' . date('Ymd_His') . '.zpl';
        file_put_contents($dir . '/' . $filename, $zplData);

        return $filename;
    }
}