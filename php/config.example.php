<?php

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '5432',
        'name' => getenv('DB_NAME') ?: 'registrar',
        'user' => getenv('DB_USER') ?: 'registrar',
        'pass' => getenv('DB_PASS') ?: 'registrar',
    ],
    'port' => getenv('PORT') ?: 5000,
];
