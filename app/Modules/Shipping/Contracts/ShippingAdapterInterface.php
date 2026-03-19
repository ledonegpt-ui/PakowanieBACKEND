<?php
declare(strict_types=1);

interface ShippingAdapterInterface
{
    /**
     * Generuje etykietę dla zamówienia.
     *
     * @param array $order        — rekord z pak_orders
     * @param array $package      — rekord z packages
     * @param array $resolved     — wynik ShippingMethodResolver
     * @param array $providerCfg  — config_json z shipping_providers
     * @return array {
     *   tracking_number: string,
     *   external_shipment_id: string|null,
     *   label_format: string,       // pdf|zpl|png
     *   label_status: string,       // ok|error
     *   file_path: string|null,
     *   file_token: string|null,
     *   raw_response: array
     * }
     */
    public function generateLabel(
        array $order,
        array $package,
        array $resolved,
        array $providerCfg
    ): array;
}
