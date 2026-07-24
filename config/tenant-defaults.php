<?php

return [
    'slug' => env('TENANT_DEFAULT_SLUG', 'alex'),
    'name' => env('TENANT_DEFAULT_NAME', 'Alex'),

    // Valori usati solo come fallback di bootstrap dal TenantSeeder. Placeholder
    // generici: i dati fiscali/di contatto reali dell'azienda vanno impostati via
    // .env locale (non committato), mai hardcoded qui.
    'legal_name' => env('TENANT_DEFAULT_LEGAL_NAME', 'Ragione Sociale S.r.l.'),
    'vat_number' => env('TENANT_DEFAULT_VAT_NUMBER', '00000000000'),
    'tax_code' => env('TENANT_DEFAULT_TAX_CODE', '00000000000'),
    'sdi' => env('TENANT_DEFAULT_SDI', '0000000'),
    'street' => env('TENANT_DEFAULT_STREET', 'Via Roma, 1'),
    'postal_code' => env('TENANT_DEFAULT_POSTAL_CODE', '00100'),
    'city' => env('TENANT_DEFAULT_CITY', 'Roma'),
    'province' => env('TENANT_DEFAULT_PROVINCE', 'RM'),
    'phone' => env('TENANT_DEFAULT_PHONE', '0000000000'),
    'fax' => env('TENANT_DEFAULT_FAX', '0000000000'),
    'email' => env('TENANT_DEFAULT_EMAIL', 'info@example.com'),
];
