<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment and sending mode
    |--------------------------------------------------------------------------
    |
    | default_environment: environment of new taxpayers (playground|production).
    | mode: live sends sales with legal effect, verification sends overeni="true".
    |
    */

    'default_environment' => 'playground',

    'mode' => 'live',

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'playground' => 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
        'production' => 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4',
        'allowed_hosts' => [
            'playground' => ['pg.trzbyeet.gov.cz'],
            'production' => ['trzbyeet.gov.cz'],
        ],
    ],

    'response_max_bytes' => 65536,

    'proxy' => null,

    'response_timeout_ms' => 4000,

    /*
    |--------------------------------------------------------------------------
    | Dates
    |--------------------------------------------------------------------------
    */

    'timezone' => 'Europe/Prague',

    'minimum_occurred_at' => [
        'playground' => '2026-07-01 00:00',
        'production' => '2026-11-01 00:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Response signature
    |--------------------------------------------------------------------------
    |
    | response_trust_store: null uses the bundled I.CA certificates.
    |
    */

    'response_trust_store' => null,

    'response_trust_store_max_certificates' => 10,

    'response_signer' => [
        'organization_identifier' => 'NTRCZ-72080043',
        'playground' => [
            'common_name' => 'NEOSTRE - playground prostredi EET',
        ],
        'production' => [
            'common_name' => null,
        ],
    ],

    'response_clock_tolerance_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | Resubmission
    |--------------------------------------------------------------------------
    */

    'lock_store' => null,

    'resubmission' => [
        'connection' => null,
        'queue' => null,
        'backoff' => [60, 300, 900, 3600],
        'min_backoff' => 30,
        'max_ambiguous_attempts' => 3,
    ],

    'verification' => [
        'sequence_number' => 'OVERENI',
    ],

    'retention' => [
        'raw_xml_days' => null,
    ],

];
