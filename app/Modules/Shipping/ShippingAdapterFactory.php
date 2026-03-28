<?php
declare(strict_types=1);

final class ShippingAdapterFactory
{
    /**
     * Zwraca adapter na podstawie label_provider z resolvera.
     * label_provider pochodzi z shipping_map.php
     */
    public static function make(string $labelProvider, array $cfg = []): ShippingAdapterInterface
    {
        require_once BASE_PATH . '/app/Modules/Shipping/Contracts/ShippingAdapterInterface.php';

        switch ($labelProvider) {
            case 'dpd_api':
            case 'dpd_contract':
                require_once BASE_PATH . '/app/Modules/Shipping/Adapters/DpdAdapter.php';
                return new DpdAdapter();

            case 'gls_api':
                require_once BASE_PATH . '/app/Modules/Shipping/Adapters/GlsAdapter.php';
                return new GlsAdapter($cfg);

            case 'inpost_shipx':
            case 'inpost_api':
                require_once BASE_PATH . '/app/Modules/Shipping/Adapters/InPostAdapter.php';
                return new InPostAdapter();

            case 'allegro_api':
                require_once BASE_PATH . '/app/Support/AllegroTokenProvider.php';
                require_once BASE_PATH . '/app/Modules/Shipping/Adapters/AllegroAdapter.php';
                return new AllegroAdapter($cfg);

            case 'baselinker_api':
            case 'baselinker':
                require_once BASE_PATH . '/app/Modules/Shipping/Adapters/BaseLinkerAdapter.php';
                return new BaseLinkerAdapter();

            default:
                throw new RuntimeException('Unknown label provider: ' . $labelProvider);
        }
    }
}
