<?php

return [
    'central_path' => database_path('data-migrations'),
    'tenant_path' => database_path('data-migrations/tenant'),
    'extra_central_paths' => [],
    'extra_tenant_paths' => [],
    'extra_paths' => [],

    'table' => 'data_migrations',
    'connection' => null,

    'lock' => [
        'enabled' => true,
        'ttl' => 1800,
    ],

    'checksum' => [
        'enabled' => true,
        'fail_on_drift' => true,
    ],

    'tenant_adapter' => null,
];
