<?php
declare(strict_types=1);

require_once __DIR__ . '/InpostShipx.php';
require_once __DIR__ . '/AllegroClient.php';
require_once __DIR__ . '/PdfToZpl.php';

final class LabelService
{
    /**
     * Legacy: zwraca ZPL (dla starego backendu socket / kompatybilności).
     * Jeśli przewoźnik zwróci PDF, konwertuje PDF->ZPL.
     */
    public static function getLabel(\PDO $db, array $cfg, string $orderCode): array
    {
        $res = self::getLabelForPrint($db, $cfg, $orderCode);

        $fmt = strtoupper(trim((string)($res['format'] ?? '')));
        $data = (string)($res['data'] ?? '');

        if ($data === '') {
            throw new \RuntimeException('LABEL: pusty payload');
        }

        if ($fmt === 'ZPL' || $fmt === 'TXT') {
            return ['format' => 'ZPL', 'data' => $data];
        }

        if ($fmt === 'PDF') {
            $dpi = (int)(getenv('LABEL_DPI') ?: 203);
            if ($dpi < 100) $dpi = 203;
            $zpl = PdfToZpl::pdfBinaryToZpl($data, $dpi);
            return ['format' => 'ZPL', 'data' => $zpl];
        }

        throw new \RuntimeException("LABEL: nieobsługiwany format={$fmt}");
    }

    /**
     * Nowe API do druku: zwraca natywny payload do wydruku (PDF lub ZPL).
     * CUPS może drukować PDF (queue *_pdf) lub ZPL RAW (queue *_raw).
     *
     * @return array{format:string,data:string}
     */
    public static function getLabelForPrint(\PDO $db, array $cfg, string $orderCode): array
    {
        $st = $db->prepare("SELECT * FROM pak_orders WHERE order_code = :c LIMIT 1");
        $st->execute([':c'=>$orderCode]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Nie znaleziono zamówienia');

        $source = strtoupper(trim((string)($row['source'] ?? '')));

        $nrNadania   = trim((string)($row['nr_nadania'] ?? ''));
        $courierCode = trim((string)($row['courier_code'] ?? ''));
        $blPackageId = $row['bl_package_id'] ?? null;
        $blPackageId = (is_numeric($blPackageId) && (int)$blPackageId > 0) ? (int)$blPackageId : null;

        $allegroParcelId = trim((string)($row['allegro_parcel_id'] ?? ''));

        // 1) BASELINKER (B/E) -> bywa PDF albo ZPL
        if ($source === 'B' || $source === 'E') {
            if ($courierCode === '') throw new \RuntimeException("BASELINKER: brak courier_code dla {$orderCode}");
            if ($blPackageId === null && $nrNadania === '') throw new \RuntimeException("BASELINKER: brak bl_package_id i nr_nadania dla {$orderCode}");

            $token = trim((string)(getenv('BASELINKER_TOKEN') ?: ($cfg['baselinker']['token'] ?? '')));
            if ($token === '') throw new \RuntimeException('BASELINKER: brak BASELINKER_TOKEN');

            $params = ['courier_code' => strtolower($courierCode)];
            if ($blPackageId !== null) $params['package_id'] = $blPackageId;
            else $params['package_number'] = $nrNadania;

            $resp = self::baselinkerCall($token, 'getLabel', $params);

            if (($resp['status'] ?? '') !== 'SUCCESS') {
                $em = (string)($resp['error_message'] ?? 'unknown error');
                $ec = (string)($resp['error_code'] ?? '');
                throw new \RuntimeException("BASELINKER getLabel: {$em} {$ec}");
            }

            $ext = strtolower((string)($resp['extension'] ?? ''));
            $b64 = (string)($resp['label'] ?? '');
            $bin = base64_decode($b64, true);
            if ($bin === false || $bin === '') throw new \RuntimeException('BASELINKER getLabel: niepoprawna etykieta (base64)');

            if ($ext === 'zpl' || $ext === 'txt') {
                return ['format' => 'ZPL', 'data' => (string)$bin];
            }
            if ($ext === 'pdf') {
                return ['format' => 'PDF', 'data' => (string)$bin];
            }

            // extension bywa puste -> sniff payload
            $trim = ltrim((string)$bin);
            if (strncmp($trim, '^XA', 3) === 0) {
                return ['format' => 'ZPL', 'data' => (string)$bin];
            }
            if (strncmp($trim, '%PDF', 4) === 0) {
                return ['format' => 'PDF', 'data' => (string)$bin];
            }

            throw new \RuntimeException("BASELINKER getLabel: nieobsługiwany extension={$ext}");
        }

        // 2) EU / ALLEGRO (U) -> może zwrócić ZPL albo PDF
        if ($source === 'U' && $allegroParcelId !== '') {
            $raw = AllegroClient::getShipmentLabelRaw($allegroParcelId, 'A6');
            $bin = (string)$raw['data'];

            $trim = ltrim($bin);
            if (strncmp($trim, '^XA', 3) === 0) {
                return ['format' => 'ZPL', 'data' => $bin];
            }
            if (strncmp($trim, '%PDF', 4) === 0) {
                return ['format' => 'PDF', 'data' => $bin];
            }

            $head = substr($trim, 0, 40);
            throw new \RuntimeException("ALLEGRO: nieznany format labela (CT=".(string)$raw['content_type'].") head=" . str_replace("\n","\\n",$head));
        }

        // 3) INPOST (ShipX) -> ZPL
        $carrier = strtoupper($courierCode);
        if ($carrier === 'INPOST') {
            if ($nrNadania === '' || !ctype_digit($nrNadania)) {
                throw new \RuntimeException('INPOST: nr_nadania musi zawierać shipment_id (liczba) albo trzeba wygenerować przesyłkę');
            }
            $res = InpostShipx::getLabelZpl($cfg, (int)$nrNadania);
            if (empty($res['ok'])) throw new \RuntimeException('INPOST: ' . (string)($res['error'] ?? 'unknown'));
            return ['format'=>'ZPL', 'data'=>(string)$res['data']];
        }

        throw new \RuntimeException("Brak obsługi etykiety dla source={$source}");
    }

    private static function baselinkerCall(string $token, string $method, array $params): array
    {
        $url = 'https://api.baselinker.com/connector.php';
        $ch = curl_init($url);
        if (!$ch) throw new \RuntimeException('BASELINKER: curl_init failed');

        $post = http_build_query([
            'method' => $method,
            'parameters' => json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['X-BLToken: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) throw new \RuntimeException('BASELINKER: cURL error: ' . $err);
        if ($code >= 400) throw new \RuntimeException('BASELINKER: HTTP ' . $code . ' ' . substr((string)$raw, 0, 200));

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) throw new \RuntimeException('BASELINKER: invalid JSON ' . substr((string)$raw, 0, 200));
        return $json;
    }
}
