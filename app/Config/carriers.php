<?php
declare(strict_types=1);

return [
    'providers' => [
        'allegro' => [
            'label_source' => 'allegro_api',
            'enabled' => true,
        ],
        'baselinker' => [
            'label_source' => 'baselinker_api',
            'enabled' => true,
        ],
        'inpost_shipx' => [
            'label_source' => 'inpost_shipx_api',
            'enabled' => true,
        ],
        'dpd_contract' => [
            'label_source' => 'dpd_contract_api',
            'enabled' => false,
        ],
    ],

    'rules' => [
        // examples for future manual mapping:
        // [
        //     'match_type' => 'contains',
        //     'match_value' => 'Allegro DPD Pickup',
        //     'provider' => 'allegro',
        //     'service' => 'dpd_pickup',
        //     'requires_size' => false,
        // ],
        // [
        //     'match_type' => 'contains',
        //     'match_value' => 'Paczkomat InPost',
        //     'provider' => 'inpost_shipx',
        //     'service' => 'inpost_paczkomat',
        //     'requires_size' => true,
        // ],
    ],
];
